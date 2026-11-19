<?php
// SPDX-FileCopyrightText: 2026 LibreCode Coop and contributors
// SPDX-License-Identifier: AGPL-3.0-or-later
declare(strict_types=1);

namespace Modules\BankingConnectors\Http\Controllers;

use DomainException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use InvalidArgumentException;
use Modules\BankingConnectors\Application\ReviewDecisionService;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;
use Modules\BankingConnectors\Application\ReviewPreview;
use Modules\BankingConnectors\Application\BankConnectorRegistry;

/**
 * Native admin-only read surface. Do not trust company/account/connection
 * supplied by the browser; derive company from current Akaunting context.
 */
final class ReviewController extends Controller
{
    public function __construct(
        private readonly ReviewPreview $preview,
        private readonly ReviewDecisionService $decisions,
        private readonly BankConnectorRegistry $registry,
    ) {}

    public function index(Request $request): View
    {
        abort_unless(auth()->check(), 401);
        abort_unless(
            auth()->user()->can('read-banking-transactions') &&
            auth()->user()->can('read-banking-accounts'),
            403
        );
        // Akaunting's IdentifyCompany middleware already checks membership,
        // establishes the current company and removes the route parameter.
        // Never accept a company override from a query string.
        $companyId = function_exists('company_id') ? (int) company_id() : 0;
        abort_unless($companyId > 0, 404);

        $connections = DB::table('lcc_banking_connectors_connections as c')
            ->join('accounts as a', static function ($join): void {
                $join->on('a.id', '=', 'c.account_id')
                    ->on('a.company_id', '=', 'c.company_id');
            })
            ->where('c.company_id', $companyId)
            ->whereNull('a.deleted_at')
            ->orderBy('a.name')
            ->get([
                'c.id', 'c.account_id', 'c.provider', 'c.state',
                'c.last_success_at', 'c.last_error_code', 'c.cursor_date',
                'c.poll_requested_at', 'c.next_retry_at', 'c.failure_count',
                'c.fingerprint_key_commitment',
                'a.name as account_name', 'a.currency_code',
            ]);

        $canManage = auth()->user()->can('update-banking-accounts');
        $availableAccounts = $canManage
            ? DB::table('accounts')
                ->where('company_id', $companyId)
                ->where('type', 'bank')
                ->where('enabled', 1)
                ->whereNull('deleted_at')
                ->orderBy('name')->get(['id', 'name'])
            : collect();

        $selected = null;
        $queryId = $request->query('connection_id');
        if ($queryId !== null) {
            $connectionId = filter_var($queryId, FILTER_VALIDATE_INT);
            abort_unless($connectionId !== false && $connectionId > 0, 422);
            $selected = $connections->firstWhere('id', (int) $connectionId);
            abort_unless($selected !== null, 404);
        } else {
            $selected = $connections->first();
        }

        $report = ['items' => [], 'summary' => [
            'suggested' => 0, 'needs_review' => 0, 'unmatched' => 0,
        ]];
        if ($selected !== null) {
            $report = $this->preview->forConnection(
                $companyId, (int) $selected->account_id, (int) $selected->id
            );
        }

        return view('banking-connectors::review', [
            'canReview' => auth()->user()->can('update-banking-transactions'),
            'canManage' => $canManage,
            'canRequest' => $canManage && auth()->user()->can('update-banking-transactions'),
            'availableAccounts' => $availableAccounts,
            'availableProviders' => $this->registry->ids(),
            'connections' => $connections,
            'selected' => $selected,
            'items' => $report['items'],
            'summary' => $report['summary'],
        ]);
    }

    public function decide(Request $request): RedirectResponse|JsonResponse
    {
        abort_unless(auth()->check(), 401);
        abort_unless(
            auth()->user()->can('update-banking-transactions') &&
            auth()->user()->can('read-banking-accounts'),
            403
        );
        $companyId = function_exists('company_id') ? (int) company_id() : 0;
        abort_unless($companyId > 0, 404);
        $actorId = (int) auth()->id();
        abort_unless($actorId > 0, 403);

        $values = $request->validate([
            'connection_id' => 'required|integer|min:1',
            'entry_id' => 'required|integer|min:1',
            'operation' => 'required|in:accept,dismiss,reopen',
            'native_transaction_id' => 'nullable|integer|min:1',
            'request_key' => ['required', 'regex:/^[a-f0-9]{32}$/D'],
        ]);
        $connectionId = (int) $values['connection_id'];
        $connection = DB::table('lcc_banking_connectors_connections as c')
            ->join('accounts as a', static function ($join): void {
                $join->on('a.id', '=', 'c.account_id')
                    ->on('a.company_id', '=', 'c.company_id');
            })
            ->where('c.id', $connectionId)
            ->where('c.company_id', $companyId)
            ->whereNull('a.deleted_at')
            ->first(['c.account_id']);
        abort_unless($connection !== null, 404);
        $nativeId = isset($values['native_transaction_id'])
            ? (int) $values['native_transaction_id'] : null;
        try {
            $decision = $this->decisions->decide(
                $companyId,
                (int) $connection->account_id,
                $connectionId,
                (int) $values['entry_id'],
                $actorId,
                (string) $values['operation'],
                $nativeId,
                (string) $values['request_key'],
            );
        } catch (DomainException|InvalidArgumentException $error) {
            // Safe, fixed domain codes only: never return provider payloads.
            if ($request->expectsJson()) {
                return response()->json(['error' => $error->getMessage()], 409);
            }
            return back()->withErrors(['review' => $error->getMessage()]);
        }
        if ($request->expectsJson()) {
            return response()->json(['state' => $decision['state'],
                'idempotent' => $decision['idempotent']], 200);
        }
        return redirect()->route('banking-connectors.review.index', [
            'company_id' => $companyId,
            'connection_id' => $connectionId,
        ])->with('success', trans('banking-connectors::general.review_saved'));
    }
}
