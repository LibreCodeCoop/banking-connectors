<?php
// SPDX-FileCopyrightText: 2026 LibreCode Coop and contributors
// SPDX-License-Identifier: AGPL-3.0-or-later
declare(strict_types=1);

namespace Modules\BankingConnectors\Http\ViewComposers;

use DateTimeImmutable;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;

/**
 * Source rows are projected onto the *same* native Akaunting Transactions
 * table component. They never become artificial rows in the financial ledger.
 */
final class NativeTransactionsIndex
{
    public function compose(View $view): void
    {
        if (!NativeBankSearch::scoped() ||
            !Route::has('banking-connectors.transactions.review')) return;

        $active=NativeBankSearch::isBankTab();
        $filter=NativeBankSearch::movementFilter();
        // A pinned bank tab also starts with the normal native search
        // chip selected ("Bank statement status = Needs action"). Only
        // mutate this request's displayed search string, not user settings.
        if ($active) {
            $search=request()->query('search','');
            if (is_string($search) &&
                !preg_match('/(?:^|\s)bank_movement:/',$search)) {
                request()->query->set('search',
                    trim('bank_movement:'.$filter.' '.$search));
            }
        }
        $account=NativeBankSearch::accountFilter();
        $rows=collect();
        if ($active) {
            $company=(int)company_id();
            $query=$this->filter($this->query($company,$account),$filter);
            $this->applyNativeFilters($query);

            // An existing native row links back to a single bank-source item.
            // Every query path retains the company/account joins and ACL.
            $requested=request()->query('bank_entry_id');
            if ($requested!==null) {
                $id=filter_var($requested,FILTER_VALIDATE_INT,
                    ['options'=>['min_range'=>1]]);
                abort_unless($id!==false && is_scalar($requested),422);
                $query->where('e.id',(int)$id);
            }

            [$sort,$order]=$this->sort();
            $rows=$query->orderBy($sort,$order)
                ->orderByDesc('e.id')
                ->select([
                    'e.id','e.account_id','e.connection_id','e.company_id',
                    'e.bank_description','e.amount_cents','e.currency','e.direction',
                    'e.movement_date','e.state','e.category_id','e.category_source',
                    'e.linked_transaction_id','e.publication_issue_code',
                    'e.publication_failures','a.name as account_name',
                    'p.outcome as publication_outcome',
                    'bcat.name as source_category_name',
                    'ncat.name as native_category_name',
                    'ncat.code as native_category_code',
                    't.id as native_transaction_id',
                    't.category_id as native_category_id','t.document_id',
                    't.reconciled','t.parent_id','t.split_id',
                    't.deleted_at as native_deleted_at',
                    'ct.name as native_contact_name',
                    'd.document_number as native_document_number',
                ])
                ->paginate(list_limit())
                ->appends(request()->only('search','sort','order','direction',
                    'bank_feed','bank_account_id','bank_entry_id'));
            // Akaunting's setParentData() overrides nested component props
            // with the parent ledger collection. Restore the actual scoped
            // source rows in the transaction-component view composer.
            request()->attributes->set('banking_connectors.native_source_rows',$rows);
        }

        $view->with([
            'bankFeedHasConnections'=>DB::table('lcc_banking_connectors_connections')
                ->where('company_id',(int)company_id())->exists(),
            'bankFeedActive'=>$active,
            'bankFeedFilter'=>$filter,
            'bankFeedAccount'=>$account,
            'bankFeedRows'=>$rows,
            'bankFeedCanClassify'=>auth()->user()->can('update-banking-transactions') &&
                auth()->user()->can('update-banking-accounts'),
        ]);
        $view->setPath(__DIR__.'/../../Resources/views/native-transactions-content.blade.php');
    }

    private function query(int $company,?int $account): Builder
    {
        return DB::table('lcc_banking_connectors_entries as e')
            ->join('lcc_banking_connectors_connections as c',static function($join):void {
                $join->on('c.id','=','e.connection_id')
                    ->on('c.company_id','=','e.company_id')
                    ->on('c.account_id','=','e.account_id');
            })
            ->join('accounts as a',static function($join):void {
                $join->on('a.id','=','e.account_id')
                    ->on('a.company_id','=','e.company_id');
            })
            ->leftJoin('lcc_banking_connectors_native_publications as p',
                static function($join):void {
                    $join->on('p.entry_id','=','e.id')
                        ->on('p.company_id','=','e.company_id');
                })
            ->leftJoin('transactions as t',static function($join):void {
                $join->on('t.id','=','e.linked_transaction_id')
                    ->on('t.company_id','=','e.company_id')
                    ->on('t.account_id','=','e.account_id')
                    ->whereNull('t.deleted_at');
            })
            ->leftJoin('categories as ncat',static function($join):void {
                $join->on('ncat.id','=','t.category_id')
                    ->on('ncat.company_id','=','e.company_id');
            })
            ->leftJoin('categories as bcat',static function($join):void {
                $join->on('bcat.id','=','e.category_id')
                    ->on('bcat.company_id','=','e.company_id');
            })
            ->leftJoin('contacts as ct',static function($join):void {
                $join->on('ct.id','=','t.contact_id')
                    ->on('ct.company_id','=','e.company_id')
                    ->whereNull('ct.deleted_at');
            })
            ->leftJoin('documents as d',static function($join):void {
                $join->on('d.id','=','t.document_id')
                    ->on('d.company_id','=','e.company_id')
                    ->whereNull('d.deleted_at');
            })
            ->where('e.company_id',$company)
            ->where('a.type','bank')->whereNull('a.deleted_at')
            ->when($account!==null,static fn($q)=>$q->where('e.account_id',$account));
    }

    /**
     * Apply actual native Akaunting search tokens to source data. Silently
     * displaying an unsupported native filter is misleading. Every token
     * offered in the native dropdown here is mapped to a real predicate.
     */
    private function applyNativeFilters(Builder $query): void
    {
        $search=request()->query('search','');
        if (!is_string($search) || $search==='') return;
        preg_match_all('/(?:^|\s)(type|account_id|category_id|contact_id|currency_code|paid_at)(>=|<=|>|<|:|=)("[^"]*"|\S+)/',
            $search,$matches,PREG_SET_ORDER);
        foreach ($matches as $match) {
            $column=$match[1];
            $operator=$match[2]===':'?'=':$match[2];
            $value=trim($match[3],'"\'');
            if ($column==='type') {
                if ($operator!=='=' || !in_array($value,['income','expense'],true)) {
                    $query->whereRaw('1=0'); continue;
                }
                $query->where('e.direction',$value==='income'?'credit':'debit');
                continue;
            }
            if ($column==='paid_at') {
                $date=DateTimeImmutable::createFromFormat('!Y-m-d',$value);
                if (!$date || $date->format('Y-m-d')!==$value) {
                    $query->whereRaw('1=0'); continue;
                }
                $query->whereDate('e.movement_date',$operator,$value);
                continue;
            }
            if ($column==='currency_code') {
                $values=explode(',',$value);
                if ($operator!=='=' || count($values)>20 ||
                    array_filter($values,static fn($v)=>preg_match('/^[A-Z]{3}$/D',$v)!==1)) {
                    $query->whereRaw('1=0'); continue;
                }
                $query->whereIn('e.currency',$values);
                continue;
            }
            $values=explode(',',$value);
            if ($operator!=='=' || count($values)>30 ||
                array_filter($values,static fn($v)=>!ctype_digit($v) ||
                    (int)$v<1 || (string)(int)$v!==$v)) {
                $query->whereRaw('1=0'); continue;
            }
            $ids=array_map('intval',$values);
            if ($column==='category_id') {
                // Never use raw SQL aliases here: Akaunting prefixes table
                // aliases for multi-tenant installations.
                $query->where(static function($q)use($ids):void {
                    $q->whereIn('e.category_id',$ids)
                        ->orWhere(static function($q)use($ids):void {
                            $q->whereNull('e.category_id')
                                ->whereIn('t.category_id',$ids);
                        });
                });
                continue;
            }
            $field=match($column) {
                'account_id'=>'e.account_id',
                'contact_id'=>'t.contact_id',
                default=>null,
            };
            if ($field===null) {
                $query->whereRaw('1=0'); continue;
            }
            $query->whereIn($field,$ids);
        }
    }

    /** Native sort links are mapped, never concatenated into SQL. */
    private function sort(): array
    {
        $requested=request()->query('sort','paid_at');
        $direction=request()->query('order',request()->query('direction','desc'));
        $sorts=[
            'paid_at'=>'e.movement_date',
            'number'=>'e.id',
            'type'=>'e.direction',
            'category.name'=>'bcat.name',
            'account.name'=>'a.name',
            'contact.name'=>'ct.name',
            'document.document_number'=>'d.document_number',
            'amount'=>'e.amount_cents',
        ];
        $sort=is_string($requested)?($sorts[$requested]??'e.movement_date'):'e.movement_date';
        $order=is_string($direction)&&strtolower($direction)==='asc'?'asc':'desc';
        return [$sort,$order];
    }

    private function whereUncategorized(Builder $query): void
    {
        $query->where(static function ($q): void {
            $q->where(static function($a):void {
                $a->whereNull('e.category_id')->where('e.state','pending');
            })->orWhere(static function($a):void {
                $a->where('p.outcome','created')
                    ->whereIn('ncat.code',[
                        'banking_connectors_pending_income',
                        'banking_connectors_pending_expense',
                    ]);
            });
        });
    }

    private function whereNeedsAction(Builder $query): void
    {
        $query->where(function($w):void {
            $w->whereIn('e.state',['pending','conflict'])
                ->orWhere(function($a):void { $this->whereUncategorized($a); });
        });
    }

    private function filter(Builder $query,string $name): Builder
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
