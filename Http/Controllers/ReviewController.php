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
use Modules\BankingConnectors\Application\CategoryWorkflow;
use Modules\BankingConnectors\Application\CategoryLearningAdvisor;
use Modules\BankingConnectors\Application\InvoiceMatchAdvisor;
use Modules\BankingConnectors\Http\ReturnLocation;

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
        private readonly CategoryWorkflow $classification,
        private readonly CategoryLearningAdvisor $learning,
        private readonly InvoiceMatchAdvisor $invoices,
    ) {}

    public function index(Request $request): View
    {
        return $this->renderPage($request, null);
    }

    public function account(Request $request): View
    {
        // Akaunting's company middleware may remove company_id after the
        // route action parameters were resolved. Never rely on positional
        // integer arguments when a route has both company_id and accountId.
        $accountId = filter_var($request->route('accountId'), FILTER_VALIDATE_INT);
        abort_unless($accountId !== false && $accountId > 0, 404);
        return $this->renderPage($request, (int) $accountId);
    }

    public function reviewAccount(Request $request): View
    {
        $accountId=filter_var($request->route('accountId'), FILTER_VALIDATE_INT);
        abort_unless($accountId!==false && $accountId>0,404);
        return $this->renderPage($request,(int)$accountId,'review');
    }

    public function reviewEntry(Request $request): View
    {
        $accountId=filter_var($request->route('accountId'), FILTER_VALIDATE_INT);
        $entryId=filter_var($request->route('entryId'), FILTER_VALIDATE_INT);
        abort_unless($accountId!==false && $accountId>0 && $entryId!==false && $entryId>0,404);
        return $this->renderPage($request,(int)$accountId,'review',(int)$entryId);
    }

    private function renderPage(Request $request, ?int $accountId, ?string $forcedTab=null, ?int $entryId=null): View
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
        $tab = $entryId !== null ? 'review' : ($forcedTab ?? (string) $request->query('tab', $accountId === null ? 'review' : 'settings'));
        abort_unless(in_array($tab, ['review','settings','rules'], true), 404);
        $filter = (string) $request->query('filter', 'pending');
        abort_unless(in_array($filter, ['pending','reviewed','dismissed','all'], true), 422);
        $searchDays = filter_var($request->query('search_days', 3), FILTER_VALIDATE_INT);
        abort_unless(in_array($searchDays, [3,14,45,90], true), 422);

        $accountContext = null;
        if ($accountId !== null) {
            $accountContext = DB::table('accounts')
                ->where('id', $accountId)
                ->where('company_id', $companyId)
                ->where('type', 'bank')
                ->where('enabled', 1)
                ->whereNull('deleted_at')
                ->first(['id', 'name']);
            abort_unless($accountContext !== null, 404);
        }

        $connections = DB::table('lcc_banking_connectors_connections as c')
            ->join('accounts as a', static function ($join): void {
                $join->on('a.id', '=', 'c.account_id')
                    ->on('a.company_id', '=', 'c.company_id');
            })
            ->where('c.company_id', $companyId)
            ->when($accountContext !== null, static function ($query) use ($accountId): void {
                $query->where('c.account_id', $accountId);
            })
            ->whereNull('a.deleted_at')
            ->orderBy('a.name')
            ->get([
                'c.id', 'c.account_id', 'c.provider', 'c.state',
                'c.last_success_at', 'c.last_error_code', 'c.cursor_date',
                'c.poll_requested_at', 'c.next_retry_at', 'c.failure_count',
                'c.fingerprint_key_commitment', 'c.certificate_valid_to',
                'c.credentials_checked_at', 'c.native_post_enabled',
                'a.name as account_name', 'a.currency_code',
            ]);

        $canManage = auth()->user()->can('update-banking-accounts');
        $availableAccounts = $canManage
            ? DB::table('accounts')
                ->where('company_id', $companyId)
                ->where('type', 'bank')
                ->where('enabled', 1)
                ->whereNull('deleted_at')
                ->when($accountContext !== null, static function ($query) use ($accountId): void {
                    $query->where('id', $accountId);
                })
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
        if ($entryId !== null) {
            // A bank account can have more than one connector. Resolve the
            // exact source connection, not the first account connection.
            $entryConnection = DB::table('lcc_banking_connectors_entries')
                ->where('id', $entryId)->where('company_id', $companyId)
                ->where('account_id', $accountId)->value('connection_id');
            abort_unless($entryConnection !== null, 404);
            $selected = $connections->firstWhere('id', (int)$entryConnection);
            abort_unless($selected !== null, 404);
        }
        // An unconnected bank card must open the connection setup directly.
        if ($accountContext !== null && $selected === null && !$request->has('tab')) {
            $tab = 'settings';
        }

        $categories = DB::table('categories')
            ->where('company_id', $companyId)->where('enabled', 1)
            ->whereNull('deleted_at')->whereIn('type', ['income','expense'])
            ->orderBy('name')->get(['id','name','type']);
        $categoryNames = $categories->pluck('name','id')->all();
        $rules = $selected !== null
            ? $this->classification->rules($companyId, (int)$selected->account_id)
            : [];
        $report = ['items' => [], 'summary' => [
            'suggested' => 0, 'needs_review' => 0, 'unmatched' => 0,
        ]];
        $stateCounts = ['pending'=>0,'reviewed'=>0,'dismissed'=>0];
        $learningStats = ['total_labels'=>0,'ready'=>false];
        if ($selected !== null) {
            $account = (int)$selected->account_id;
            $report = $this->preview->forConnection(
                $companyId, $account, (int)$selected->id, 50, $filter, (int)$searchDays, $rules, $entryId
            );
            if ($entryId !== null) abort_unless(count($report['items'])===1,404);
            $rawCounts = DB::table('lcc_banking_connectors_entries')
                ->where('company_id',$companyId)->where('account_id',$account)
                ->where('connection_id',(int)$selected->id)
                ->selectRaw('state, count(*) as total')->groupBy('state')->get();
            foreach ($rawCounts as $row) {
                $key = $row->state === 'conflict' ? 'pending' : $row->state;
                if (array_key_exists($key,$stateCounts)) $stateCounts[$key] += (int)$row->total;
            }
            $ids = [];
            foreach ($report['items'] as $item) {
                if ($item['linked_transaction_id'] !== null) $ids[]=$item['linked_transaction_id'];
                foreach ($item['candidate_ids'] as $candidate) $ids[]=$candidate;
            }
            $nativeById = $ids === [] ? collect() : DB::table('transactions')
                ->where('company_id',$companyId)->where('account_id',$account)
                ->whereNull('deleted_at')->whereIn('id',array_values(array_unique($ids)))
                ->get(['id','type','description','category_id','reconciled','document_id','parent_id','split_id'])
                ->keyBy('id');
            foreach ($report['items'] as &$item) {
                $native = $item['linked_transaction_id'] !== null
                    ? $nativeById->get($item['linked_transaction_id']) : null;
                $item['native_category_id'] = $native?->category_id !== null
                    ? (int)$native->category_id : null;
                $item['native_category_name'] = $item['native_category_id'] !== null
                    ? ($categoryNames[$item['native_category_id']] ?? null) : null;
                $item['native_locked'] = $native === null ||
                    (int)($native->reconciled ?? 0)!==0 ||
                    $native->document_id !== null ||
                    (int)($native->parent_id ?? 0)!==0 ||
                    $native->split_id !== null;
                $item['native_exists'] = $native !== null;
                foreach ($item['candidate_details'] as &$candidate) {
                    $match=$nativeById->get($candidate['id']);
                    $candidate['description'] = $match?->description;
                    $candidate['category_name'] = $match?->category_id !== null
                        ? ($categoryNames[(int)$match->category_id] ?? null) : null;
                    $validCategory=$match?->category_id !== null
                        ? $categories->firstWhere('id',(int)$match->category_id) : null;
                    $candidate['category_id']=$validCategory !== null &&
                        $match?->type===($item['direction']==='credit'?'income':'expense') &&
                        $validCategory->type===($item['direction']==='credit'?'income':'expense')
                            ? (int)$validCategory->id : null;
                }
                unset($candidate);
                $item['native_candidate_category_suggestion']=null;
                if ($item['stage_state']==='pending' && $item['category_id']===null &&
                    count($item['candidate_details'])===1) {
                    $item['native_candidate_category_suggestion']=
                        $item['candidate_details'][0]['category_id'];
                }
            }
            unset($item);
            // The model is read-only and learns only from human audit events.
            $contactRights = [];
            if (auth()->user()->can('read-sales-customers')) $contactRights[]='customer';
            if (auth()->user()->can('read-purchases-vendors')) $contactRights[]='vendor';
            $learned = $this->learning->advise(
                $companyId, $account, $report['items'], $contactRights
            );
            $learningStats = ['total_labels'=>$learned['total_labels'],'ready'=>$learned['ready']];
            foreach ($report['items'] as &$item) {
                $item['learning_suggestion'] = $learned['suggestions'][$item['id']] ?? null;
                $item['possible_counterparty'] = $learned['contacts'][$item['id']] ?? null;
            }
            unset($item);
            // Bills are not bank permissions. Show document provenance only
            // with explicit native purchase access and only on one entry.
            if ($entryId !== null && $report['items'] !== [] &&
                auth()->user()->can('read-purchases-bills')) {
                $candidateDocs=[];
                foreach ($report['items'][0]['candidate_details'] as $candidate) {
                    $native=$nativeById->get($candidate['id']);
                    if ($native?->document_id !== null) $candidateDocs[]=(int)$native->document_id;
                }
                $paidBills=$candidateDocs===[] ? collect() : DB::table('documents')
                    ->where('company_id',$companyId)->whereIn('id',array_unique($candidateDocs))
                    ->where('type','bill')->where('status','paid')->whereNull('deleted_at')
                    ->get(['id','document_number'])->keyBy('id');
                foreach ($report['items'][0]['candidate_details'] as &$candidate) {
                    $native=$nativeById->get($candidate['id']);
                    $bill=$native?->document_id!==null
                        ? $paidBills->get((int)$native->document_id) : null;
                    $candidate['paid_bill']=$bill===null ? null : [
                        'id'=>(int)$bill->id,'number'=>(string)$bill->document_number,
                    ];
                }
                unset($candidate);
            }
            // Invoice visibility is a separate Akaunting permission, not
            // implied by the right to read bank movements.
            if ($entryId !== null && $report['items'] !== []) {
                $item=&$report['items'][0];
                $item['invoice_candidates']=[];
                $item['invoice_category_suggestion']=null;
                if ($item['stage_state']==='pending' &&
                    auth()->user()->can('read-sales-invoices')) {
                    $item['invoice_candidates']=$this->invoices->suggest($companyId,$item);
                    $candidates=$item['invoice_candidates'];
                    if (count($candidates)===1 && $candidates[0]['reason']==='payer_and_amount' &&
                        $candidates[0]['category_id']!==null &&
                        $categories->where('type','income')->contains('id',$candidates[0]['category_id'])) {
                        $item['invoice_category_suggestion']=$candidates[0]['category_id'];
                    }
                }
                unset($item);
            }
        }

        $returnSource = $entryId !== null
            ? ReturnLocation::previous($request, $companyId)
            : ReturnLocation::current($companyId);
        return view($entryId !== null ? 'banking-connectors::entry' : 'banking-connectors::review', [
            'returnSource' => $returnSource,
            'returnUrl' => $returnSource ?? route('transactions.index', ['company_id'=>$companyId]),
            'singleEntryId' => $entryId,
            'accountContext' => $accountContext,
            'tab' => $tab,
            'filter' => $filter,
            'searchDays' => (int)$searchDays,
            'rules' => $rules,
            'categories' => $categories,
            'categoryNames' => $categoryNames,
            'stateCounts' => $stateCounts,
            'learningStats' => $learningStats,
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
            'search_days' => 'nullable|in:3,14,45,90',
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
                (int) ($values['search_days'] ?? 3),
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
        $returnEntry=filter_var($request->input('return_entry_id'),FILTER_VALIDATE_INT);
        $backSource=ReturnLocation::forCompany($request->input('from'),$companyId);
        if ($returnEntry !== false && $returnEntry > 0 && $returnEntry === (int)$values['entry_id']) {
            return redirect()->route('banking-connectors.transactions.entry', [
                'company_id'=>$companyId,'accountId'=>(int)$connection->account_id,
                'entryId'=>$returnEntry,
                ...($backSource !== null ? ['from'=>$backSource] : []),
            ])->with('success',trans('banking-connectors::general.review_saved'));
        }
        return redirect()->route('banking-connectors.transactions.review', [
            'company_id' => $companyId,
            'accountId' => (int) $connection->account_id,
            'filter' => match ((string)$values['operation']) {
                'accept' => 'reviewed',
                'dismiss' => 'dismissed',
                default => 'pending',
            },
        ])->with('success', trans('banking-connectors::general.review_saved'));
    }
}
