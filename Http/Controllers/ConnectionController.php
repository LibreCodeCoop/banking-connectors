<?php
// SPDX-FileCopyrightText: 2026 LibreCode Coop and contributors
// SPDX-License-Identifier: AGPL-3.0-or-later
declare(strict_types=1);

namespace Modules\BankingConnectors\Http\Controllers;

use DomainException;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use InvalidArgumentException;
use Modules\BankingConnectors\Application\ConnectionControl;
use Modules\BankingConnectors\Application\BankConnectorRegistry;
use Modules\BankingConnectors\Jobs\PollQueuedConnection;
use Throwable;

/**
 * Admin-only controls for Banking Connectors metadata. No bank network request or
 * native financial write can be triggered synchronously from this controller.
 */
final class ConnectionController extends Controller
{
    public function __construct(
        private readonly ConnectionControl $controls,
        private readonly BankConnectorRegistry $registry,
    ) {}

    public function create(Request $request): JsonResponse|RedirectResponse
    {
        $company = $this->authorizedCompany(false);
        $values = $request->validate([
            'account_id' => 'required|integer|min:1',
            'provider' => ['nullable', 'regex:/^[a-z][a-z0-9_-]{1,47}$/D'],
        ]);
        $provider = (string) ($values['provider'] ?? 'sicoob');
        abort_unless($this->registry->has($provider), 422);
        return $this->respond($request, function () use ($company, $values, $provider) {
            return $this->controls->create($company, (int) $values['account_id'], $provider);
        }, $company, null, 'connection_saved', 201);
    }

    public function state(Request $request): JsonResponse|RedirectResponse
    {
        $company = $this->authorizedCompany(false);
        $connectionId = filter_var($request->route('connectionId'), FILTER_VALIDATE_INT);
        abort_unless($connectionId !== false && $connectionId > 0, 404);
        $values = $request->validate([
            'state' => 'required|in:paused,read_only,enabled',
        ]);
        return $this->respond($request, function () use ($company, $connectionId, $values) {
            return ['state' => $this->controls->transition(
                $company, $connectionId, (string) $values['state']
            )];
        }, $company, $connectionId, 'connection_saved', 200);
    }

    public function request(Request $request): JsonResponse|RedirectResponse
    {
        $company = $this->authorizedCompany(true);
        $connectionId = filter_var($request->route('connectionId'), FILTER_VALIDATE_INT);
        abort_unless($connectionId !== false && $connectionId > 0, 404);
        $values = $request->validate([
            'start_date' => 'nullable|date_format:Y-m-d',
            'request_key' => ['required', 'regex:/^[a-f0-9]{32}$/D'],
        ]);
        return $this->respond($request, function () use ($company, $connectionId, $values) {
            $key = (string) $values['request_key'];
            $result = $this->controls->request(
                $company,
                $connectionId,
                isset($values['start_date']) ? (string) $values['start_date'] : null,
                $key
            );
            // Never perform an external bank request from a web PHP process.
            // With an asynchronous queue, schedule the exact persisted request
            // immediately. In sync/testing mode, preserve the pending request
            // for the normal CLI poller; never execute it inside this request.
            $result['async_dispatch'] = 'not_configured';
            if (!$result['idempotent'] && config('queue.default') !== 'sync') {
                try {
                    Bus::dispatch(new PollQueuedConnection($company, $connectionId, $key));
                    $result['async_dispatch'] = 'submitted';
                } catch (Throwable) {
                    // The persisted request remains eligible for the
                    // scheduler; do not leak Redis/credentials to the client.
                    $result['async_dispatch'] = 'scheduler_fallback';
                }
            }
            return $result;
        }, $company, $connectionId, 'sync_queued', 202);
    }

    /**
     * Opt in to native financial creation only for a previously validated
     * and enabled bank connection. This is a separate authorization from
     * permitting read-only bank statement polling.
     */
    public function nativePublishing(Request $request): JsonResponse|RedirectResponse
    {
        $company=$this->authorizedCompany(true);
        abort_unless(auth()->user()->can('create-banking-transactions'),403);
        $connectionId=filter_var($request->route('connectionId'),FILTER_VALIDATE_INT);
        abort_unless($connectionId!==false && $connectionId>0,404);
        $values=$request->validate(['enabled'=>'required|in:0,1']);
        return $this->respond($request,function () use($company,$connectionId,$values): array {
            $enabled=(string)$values['enabled']==='1';
            return DB::transaction(function () use ($company,$connectionId,$enabled): array {
                $binding=DB::table('lcc_banking_connectors_connections as c')
                    ->join('accounts as a',static function($join):void {
                        $join->on('a.company_id','=','c.company_id')
                            ->on('a.id','=','c.account_id');
                    })
                    ->where('c.id',$connectionId)->where('c.company_id',$company)
                    ->where('a.type','bank')->where('a.enabled',1)
                    ->whereNull('a.deleted_at')
                    ->lockForUpdate()
                    ->first(['c.id','c.account_id','c.state','c.fingerprint_key_commitment',
                        'c.last_success_at','c.last_error_code','c.native_post_enabled',
                        'c.native_post_after_entry_id','c.native_post_activated_at',
                        'a.currency_code']);
                if (!$binding) throw new DomainException('bank_connection_not_accessible');
                if ($enabled && ($binding->state!=='enabled' ||
                    $binding->currency_code!=='BRL' ||
                    $binding->last_success_at===null ||
                    $binding->fingerprint_key_commitment===null ||
                    $binding->last_error_code!==null)) {
                    throw new DomainException('native_publication_requires_valid_bank');
                }
                $newlyEnabled=$enabled && !(bool)$binding->native_post_enabled;
                $changes=['native_post_enabled'=>$enabled,'updated_at'=>now()];
                if ($newlyEnabled) {
                    // Atomic opt-in fence: existing bank history remains
                    // reviewable but cannot silently become native accounting.
                    $changes['native_post_after_entry_id']=(int)(
                        DB::table('lcc_banking_connectors_entries')
                            ->where('company_id',$company)
                            ->where('account_id',(int)$binding->account_id)
                            ->where('connection_id',$connectionId)
                            ->max('id') ?? 0
                    );
                    $changes['native_post_activated_at']=\Illuminate\Support\Carbon::now('UTC');
                }
                DB::table('lcc_banking_connectors_connections')
                    ->where('id',$connectionId)->where('company_id',$company)
                    ->update($changes);
                return ['native_post_enabled'=>$enabled];
            });
        },$company,(int)$connectionId,'connection_saved',200);
    }

    private function authorizedCompany(bool $poll): int
    {
        abort_unless(auth()->check() && auth()->id() !== null, 401);
        abort_unless(
            auth()->user()->can('update-banking-accounts') &&
            auth()->user()->can('read-banking-transactions') &&
            (!$poll || auth()->user()->can('update-banking-transactions')),
            403
        );
        $company = function_exists('company_id') ? (int) company_id() : 0;
        abort_unless($company > 0, 404);
        return $company;
    }

    /** @param \Closure():array<string,mixed> $operation */
    private function respond(
        Request $request, \Closure $operation, int $company,
        ?int $connection, string $successKey, int $status,
    ): JsonResponse|RedirectResponse {
        try {
            $result = $operation();
        } catch (DomainException|InvalidArgumentException $error) {
            // Domain error messages are fixed safe codes, never from provider
            // transport errors or credential values.
            if ($request->expectsJson()) {
                $status = in_array($error->getMessage(), [
                    'bank_connection_not_accessible',
                    'bank_account_not_available',
                ], true) ? 404 : 409;
                return response()->json(['error' => $error->getMessage()], $status);
            }
            return back()->withErrors(['banking-connectors' => $error->getMessage()]);
        }
        if ($request->expectsJson()) {
            return response()->json(['data' => $result], $status);
        }
        // Resolve the destination account from the persisted, tenant-scoped
        // connection, never from a query parameter supplied by the browser.
        $connectionId = $connection ?? ($result['id'] ?? null);
        $accountId = $connectionId !== null
            ? DB::table('lcc_banking_connectors_connections')
                ->where('id', (int) $connectionId)
                ->where('company_id', $company)
                ->value('account_id')
            : null;
        if ($accountId !== null) {
            return redirect()->route('banking-connectors.account.show', [
                'company_id' => $company,
                'accountId' => (int) $accountId,
            ])->with('success', trans('banking-connectors::general.' . $successKey));
        }
        return redirect()->route('banking-connectors.review.index', [
            'company_id' => $company,
        ])->with('success', trans('banking-connectors::general.' . $successKey));
    }
}
