<?php
// SPDX-FileCopyrightText: 2026 LibreCode Coop and contributors
// SPDX-License-Identifier: AGPL-3.0-or-later
declare(strict_types=1);

namespace Modules\BankingConnectors\Http\Controllers;

use DomainException;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Bus;
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

    public function state(Request $request, int $connectionId): JsonResponse|RedirectResponse
    {
        $company = $this->authorizedCompany(false);
        $values = $request->validate([
            'state' => 'required|in:paused,read_only,enabled',
        ]);
        return $this->respond($request, function () use ($company, $connectionId, $values) {
            return ['state' => $this->controls->transition(
                $company, $connectionId, (string) $values['state']
            )];
        }, $company, $connectionId, 'connection_saved', 200);
    }

    public function request(Request $request, int $connectionId): JsonResponse|RedirectResponse
    {
        $company = $this->authorizedCompany(true);
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
        return redirect()->route('banking-connectors.review.index', [
            'company_id' => $company,
            'connection_id' => $connection ?? ($result['id'] ?? null),
        ])->with('success', trans('banking-connectors::general.' . $successKey));
    }
}
