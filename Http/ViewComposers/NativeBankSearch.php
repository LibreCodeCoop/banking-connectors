<?php
// SPDX-FileCopyrightText: 2026 LibreCode Coop and contributors
// SPDX-License-Identifier: AGPL-3.0-or-later
declare(strict_types=1);

namespace Modules\BankingConnectors\Http\ViewComposers;

use App\Events\Common\SearchStringApplying;
use App\Models\Banking\Transaction;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Facades\DB;

/**
 * Akaunting-native search dropdown integration. The custom search tokens
 * are intentionally NOT registered as nonexistent Transaction DB columns:
 * the native search manager strips unknown tokens, while this listener
 * applies a company-scoped EXISTS predicate before native pagination.
 */
final class NativeBankSearch
{
    public const MOVEMENT_FILTERS = [
        'needs_action','uncategorized','unlinked','conflict','imported',
        'posted','matched','ignored',
    ];

    public const TRANSACTION_FILTERS = [
        'linked','posted','uncategorized','unlinked','connected',
    ];

    public static function scoped(): bool
    {
        if (!request()->routeIs('transactions.index') ||
            !auth()->check() ||
            !auth()->user()->can('read-banking-transactions') ||
            !auth()->user()->can('read-banking-accounts')) return false;
        $company=function_exists('company_id')?(int)company_id():0;
        if ($company<1) return false;
        // Other companies may have disabled this module. Never attach bank
        // controls or apply bank search predicates in that company.
        $flag=request()->attributes->get('banking_connectors.module_enabled');
        if ($flag===null) {
            $flag=DB::table('modules')->where('company_id',$company)
                ->where('alias','banking-connectors')->where('enabled',1)->exists();
            request()->attributes->set('banking_connectors.module_enabled',$flag);
        }
        return $flag===true;
    }

    private static function token(string $name): ?string
    {
        $search=request()->query('search','');
        if (!is_string($search)) return null;
        if (preg_match('/(?:^|\s)' . preg_quote($name,'/') . ':(\S+)(?=\s|$)/D',
            trim($search),$match)) {
            return trim((string)$match[1],'"\'');
        }
        return null;
    }

    public static function isBankTab(): bool
    {
        if (request()->has('bank_feed')) {
            return is_string(request()->query('bank_feed'));
        }
        return self::token('bank_movement') !== null;
    }

    public static function movementFilter(): string
    {
        $value=self::token('bank_movement');
        if ($value===null && request()->has('bank_feed')) {
            $value=request()->query('bank_feed');
        }
        if ($value==='all') return 'imported'; // Historical bookmark.
        return is_string($value) && in_array($value,self::MOVEMENT_FILTERS,true)
            ? $value : 'needs_action';
    }

    public static function accountFilter(): ?int
    {
        $raw=self::token('account_id');
        if ($raw===null) $raw=request()->query('bank_account_id');
        if ($raw===null || $raw==='') return null;
        // Unknown account values fail closed rather than revealing other
        // company data or converting array/multiple values into SQL.
        if (!is_string($raw) && !is_int($raw)) return 0;
        $id=filter_var($raw,FILTER_VALIDATE_INT,
            ['options'=>['min_range'=>1]]);
        return $id===false ? 0 : (int)$id;
    }

    public function extendSearch(View $view): void
    {
        if (!self::scoped() ||
            ($view->getData()['model']??null)!==Transaction::class) return;

        $filters=$view->getData()['filters']??[];
        if (!is_array($filters)) return;
        $bankTab=self::isBankTab();
        $values=[];
        foreach ($bankTab ? self::MOVEMENT_FILTERS : self::TRANSACTION_FILTERS as $status) {
            $values[$status]=trans('banking-connectors::general.native_filter_'.
                ($bankTab?'movement_':'transaction_').$status);
        }
        $filter=[
            'key'=>$bankTab?'bank_movement':'bank_status',
            'value'=>trans('banking-connectors::general.native_search_filter_'.($bankTab?'movement':'transaction')),
            'type'=>'select',
            'url'=>'',
            'values'=>$values,
            'value_option_fields'=>[],
            'operator'=>'=',
            'multiple'=>false,
        ];
        if ($bankTab) {
            // Native bank tab uses the very same Akaunting Vue search field,
            // with valid company/account and source-status criteria only.
            $filters=array_values(array_filter($filters,
                static fn($f)=>($f['key']??null)==='account_id'));
            array_unshift($filters,$filter);
        } else {
            $filters[]=$filter;
        }
        $view->with('filters',$filters);
    }

    public function applyNativeSearch(SearchStringApplying $event): void
    {
        if (!self::scoped() || self::isBankTab() ||
            !$event->query instanceof Builder ||
            !$event->query->getModel() instanceof Transaction) return;

        $status=self::token('bank_status');
        if ($status===null) return;
        $builder=$event->query;
        if (!in_array($status,self::TRANSACTION_FILTERS,true)) {
            $builder->whereRaw('1=0');
            return;
        }
        $company=(int)company_id();
        $joined=static function (QueryBuilder $q) use ($company): void {
            $q->selectRaw('1')
                ->from('lcc_banking_connectors_entries as e')
                ->where('e.company_id',$company)
                ->whereColumn('e.company_id','transactions.company_id')
                ->whereColumn('e.account_id','transactions.account_id')
                ->whereColumn('e.linked_transaction_id','transactions.id')
                ->where('e.state','reviewed');
        };
        $connected=static function(QueryBuilder $q) use($company):void {
            $q->selectRaw('1')->from('lcc_banking_connectors_connections as c')
                ->where('c.company_id',$company)
                ->whereColumn('c.company_id','transactions.company_id')
                ->whereColumn('c.account_id','transactions.account_id');
        };
        match($status) {
            'linked'=>$builder->whereExists($joined),
            'unlinked'=>$builder->whereExists($connected)->whereNotExists($joined),
            'connected'=>$builder->whereExists($connected),
            'posted'=>$builder->whereExists(static function(QueryBuilder $q)use($company):void {
                $q->selectRaw('1')
                    ->from('lcc_banking_connectors_native_publications as p')
                    ->where('p.company_id',$company)
                    ->whereColumn('p.company_id','transactions.company_id')
                    ->whereColumn('p.account_id','transactions.account_id')
                    ->whereColumn('p.native_transaction_id','transactions.id')
                    ->where('p.outcome','created');
            }),
            'uncategorized'=>$builder->whereExists(static function(QueryBuilder $q)use($company):void {
                $q->selectRaw('1')
                    ->from('categories as cat')
                    ->where('cat.company_id',$company)
                    ->whereColumn('cat.company_id','transactions.company_id')
                    ->whereColumn('cat.id','transactions.category_id')
                    ->whereIn('cat.code',[
                        'banking_connectors_pending_income',
                        'banking_connectors_pending_expense',
                    ]);
            }),
        };
    }
}
