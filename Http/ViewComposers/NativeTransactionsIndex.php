<?php
// SPDX-FileCopyrightText: 2026 LibreCode Coop and contributors
// SPDX-License-Identifier: AGPL-3.0-or-later
declare(strict_types=1);

namespace Modules\BankingConnectors\Http\ViewComposers;

use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;

/**
 * Augment the existing native Banking > Transactions screen without changing
 * the Akaunting core controllers, models or tables.
 *
 * The versioned module view retains the native transaction layout and adds
 * a bounded bank-feed review filter. Every join is company/account scoped.
 */
final class NativeTransactionsIndex
{
    private const FILTERS=['all','needs_action','uncategorized','unlinked','conflict','imported','posted','matched','ignored'];

    public function compose(View $view): void
    {
        if (!auth()->check() || !auth()->user()->can('read-banking-transactions') ||
            !auth()->user()->can('read-banking-accounts')) return;
        $company=function_exists('company_id')?(int)company_id():0;
        if ($company<1 || !Route::has('banking-connectors.transactions.review')) return;

        $filter=(string)request()->query('bank_feed','all');
        if (!in_array($filter,self::FILTERS,true)) $filter='all';
        $account=filter_var(request()->query('bank_account_id'),FILTER_VALIDATE_INT);
        $account=$account!==false&&$account>0?(int)$account:null;

        $query=$this->query($company,$account);
        $counts=[
            'all'=>(clone $query)->count(),
            'needs_action'=>(clone $query)->where(function ($q): void { $this->whereNeedsAction($q); })->count(),
            'uncategorized'=>(clone $query)->where(function ($q): void { $this->whereUncategorized($q); })->count(),
            'unlinked'=>(clone $query)->where('e.state','pending')->whereNull('e.linked_transaction_id')->count(),
            'conflict'=>(clone $query)->where('e.state','conflict')->count(),
            'imported'=>(clone $query)->count(),
            'posted'=>(clone $query)->where('p.outcome','created')->count(),
            'matched'=>(clone $query)->where('e.state','reviewed')->whereNull('p.id')->count(),
            'ignored'=>(clone $query)->where('e.state','dismissed')->count(),
        ];
        $rows=collect();
        if ($filter!=='all') {
            $selected=$this->filter($query,$filter);
            $rows=$selected->orderByDesc('e.movement_date')->orderByDesc('e.id')
                ->select(['e.id','e.account_id','e.connection_id','e.company_id',
                    'e.bank_description','e.amount_cents','e.currency','e.direction',
                    'e.movement_date','e.state','e.category_id','e.category_source',
                    'e.linked_transaction_id','e.publication_issue_code',
                    'e.publication_failures','a.name as account_name',
                    'p.outcome as publication_outcome',
                    'ncat.name as native_category_name','ncat.code as native_category_code',
                    't.category_id as native_category_id','t.document_id','t.reconciled',
                    't.parent_id','t.split_id','t.deleted_at as native_deleted_at'])
                ->paginate(25)
                ->appends(request()->only('bank_feed','bank_account_id'));
        }
        $categories=DB::table('categories')->where('company_id',$company)
            ->whereIn('type',['income','expense'])->where('enabled',1)
            ->whereNull('deleted_at')->orderBy('name')->get(['id','name','type']);
        $accounts=DB::table('lcc_banking_connectors_connections as c')
            ->join('accounts as a',static function($join): void {
                $join->on('a.id','=','c.account_id')->on('a.company_id','=','c.company_id');
            })
            ->where('c.company_id',$company)->where('a.type','bank')
            ->whereNull('a.deleted_at')->distinct()->orderBy('a.name')
            ->get(['a.id','a.name']);
        $view->with([
            'bankFeedFilter'=>$filter,
            'bankFeedAccount'=>$account,
            'bankFeedCounts'=>$counts,
            'bankFeedRows'=>$rows,
            'bankFeedCategories'=>$categories,
            'bankFeedAccounts'=>$accounts,
            'bankFeedCanClassify'=>auth()->user()->can('update-banking-transactions') &&
                auth()->user()->can('update-banking-accounts'),
        ]);
        $view->setPath(__DIR__.'/../../Resources/views/native-transactions-index.blade.php');
    }

    private function query(int $company,?int $account)
    {
        return DB::table('lcc_banking_connectors_entries as e')
            ->join('lcc_banking_connectors_connections as c',static function($join):void {
                $join->on('c.id','=','e.connection_id')
                    ->on('c.company_id','=','e.company_id')
                    ->on('c.account_id','=','e.account_id');
            })
            ->join('accounts as a',static function($join):void {
                $join->on('a.id','=','e.account_id')->on('a.company_id','=','e.company_id');
            })
            ->leftJoin('lcc_banking_connectors_native_publications as p',static function($join):void {
                $join->on('p.entry_id','=','e.id')->on('p.company_id','=','e.company_id');
            })
            ->leftJoin('transactions as t',static function($join):void {
                $join->on('t.id','=','e.linked_transaction_id')
                    ->on('t.company_id','=','e.company_id')
                    ->on('t.account_id','=','e.account_id');
            })
            ->leftJoin('categories as ncat',static function($join):void {
                $join->on('ncat.id','=','t.category_id')->on('ncat.company_id','=','e.company_id');
            })
            ->where('e.company_id',$company)
            ->where('a.type','bank')->whereNull('a.deleted_at')
            ->when($account!==null,static fn($q)=>$q->where('e.account_id',$account));
    }

    private function whereUncategorized($q): void
    {
        $q->where(function ($w): void {
            $w->where(function($a):void {
                $a->whereNull('e.category_id')->where('e.state','pending');
            })->orWhere(function($a):void {
                $a->where('p.outcome','created')
                    ->whereIn('ncat.code',[
                        'banking_connectors_pending_income',
                        'banking_connectors_pending_expense'
                    ]);
            });
        });
    }

    private function whereNeedsAction($q): void
    {
        $q->where(function($w):void {
            $w->whereIn('e.state',['pending','conflict'])
                ->orWhere(function ($a):void { $this->whereUncategorized($a); });
        });
    }

    private function filter($query,string $name)
    {
        return match($name) {
            'needs_action'=>$query->where(function($q):void { $this->whereNeedsAction($q); }),
            'uncategorized'=>$query->where(function($q):void { $this->whereUncategorized($q); }),
            'unlinked'=>$query->where('e.state','pending')->whereNull('e.linked_transaction_id'),
            'conflict'=>$query->where('e.state','conflict'),
            'imported'=>$query,
            'posted'=>$query->where('p.outcome','created'),
            'matched'=>$query->where('e.state','reviewed')->whereNull('p.id'),
            'ignored'=>$query->where('e.state','dismissed'),
            default=>$query,
        };
    }
}
