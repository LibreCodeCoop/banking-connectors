<?php
// SPDX-FileCopyrightText: 2026 LibreCode Coop and contributors
// SPDX-License-Identifier: AGPL-3.0-or-later
declare(strict_types=1);

namespace Modules\BankingConnectors\Http\ViewComposers;

use App\Models\Banking\Transaction;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;

/**
 * Add to native x-table.actions, not a second hover menu. Lookups are
 * performed once for the current native result page, not once per row.
 */
final class NativeTransactionActions
{
    private const REQUEST_MAP='banking_connectors.native_action_map';

    public function prepare(View $view): void
    {
        if (!NativeBankSearch::scoped()) return;
        if (NativeBankSearch::isBankTab()) {
            // Akaunting setParentData() silently replaces the nested
            // transactions prop with its parent native ledger collection.
            // Overwrite the view dataset *after* that inheritance; never
            // reinterpret native financial rows as source movements.
            $sourceRows=request()->attributes->get('banking_connectors.native_source_rows');
            if (!$sourceRows instanceof \Illuminate\Pagination\LengthAwarePaginator) {
                throw new \LogicException('bank_source_rows_missing');
            }
            $view->with('transactions',$sourceRows);
            $view->with('hideBulkAction',true);
            $view->with('bankFeedRendering',true);
            $view->setPath(__DIR__.'/../../Resources/views/native-transaction-rows.blade.php');
            return;
        }
        $transactions=$view->getData()['transactions']??null;
        if (!is_iterable($transactions)) return;
        $company=(int)company_id();
        $ids=[]; $accounts=[];
        foreach ($transactions as $item) {
            if (!$item instanceof Transaction ||
                (int)$item->company_id!==$company) continue;
            $ids[]=(int)$item->id;
            $accounts[]=(int)$item->account_id;
        }
        $ids=array_values(array_unique($ids));
        $accounts=array_values(array_unique($accounts));
        if ($ids===[] || $accounts===[]) return;

        $connected=DB::table('lcc_banking_connectors_connections as c')
            ->join('accounts as a',static function($join):void {
                $join->on('a.id','=','c.account_id')
                    ->on('a.company_id','=','c.company_id');
            })
            ->where('c.company_id',$company)
            ->where('a.type','bank')->where('a.enabled',1)
            ->whereNull('a.deleted_at')
            ->whereIn('c.account_id',$accounts)
            ->pluck('c.account_id')->mapWithKeys(
                static fn($id)=>[(int)$id=>true]
            )->all();
        if ($connected===[]) return;

        $links=DB::table('lcc_banking_connectors_entries as e')
            ->join('lcc_banking_connectors_connections as c',static function($join):void {
                $join->on('c.id','=','e.connection_id')
                    ->on('c.company_id','=','e.company_id')
                    ->on('c.account_id','=','e.account_id');
            })
            ->where('e.company_id',$company)->where('e.state','reviewed')
            ->whereIn('e.linked_transaction_id',$ids)
            ->whereIn('e.account_id',array_keys($connected))
            ->orderByDesc('e.id')->get([
                'e.id','e.account_id','e.linked_transaction_id',
                'e.bank_description','e.publication_issue_code',
            ])->unique('linked_transaction_id')
            ->keyBy('linked_transaction_id')->all();

        request()->attributes->set(self::REQUEST_MAP,[
            'connected'=>$connected,'linked'=>$links,
        ]);
        $view->with('bankingNativeLinked',$links);
        $view->with('bankingNativeConnected',$connected);
        $view->with('bankFeedRendering',false);
        $view->setPath(__DIR__.'/../../Resources/views/native-transaction-rows.blade.php');
    }

    /**
     * The native hover menu accepts the same action schema on both row
     * types. Unposted source records never receive financial PDF/delete
     * links: there is no native document on which to perform that action.
     */
    public static function bankActions(object $row): array
    {
        $account=(int)$row->account_id;
        $company=(int)company_id();
        $review=route('banking-connectors.transactions.review',[
            'company_id'=>$company,'accountId'=>$account,
            'filter'=>$row->state==='pending'?'pending':'all',
        ]);
        $actions=[
            [
                'title'=>trans('banking-connectors::general.native_action_view_entry'),
                'icon'=>'visibility',
                'url'=>route('transactions.index',[
                    'company_id'=>$company,
                    'search'=>'bank_movement:imported account_id:'.$account,
                    'bank_entry_id'=>(int)$row->id,
                ]),
                'permission'=>'read-banking-transactions',
            ],
            [
                'title'=>trans('banking-connectors::general.native_resolve'),
                'icon'=>'fact_check','url'=>$review,
                'permission'=>'read-banking-transactions',
            ],
        ];
        $native=(int)($row->native_transaction_id??0);
        if ($native>0) {
            $actions[]=[
                'title'=>trans('banking-connectors::general.open_native_transaction'),
                'icon'=>'receipt_long',
                'url'=>route('transactions.show',[
                    'company_id'=>$company,'transaction'=>$native,
                ]),
                'permission'=>'read-banking-transactions',
            ];
            $actions[]=['type'=>'divider'];
            foreach ([
                'print'=>['general.print','print'],
                'pdf'=>['general.download_pdf','picture_as_pdf'],
            ] as $path=>$label) {
                $route='transactions.'.$path;
                if (!Route::has($route)) continue;
                $actions[]=[
                    'title'=>trans($label[0]),'icon'=>$label[1],
                    'url'=>route($route,[
                        'company_id'=>$company,'transaction'=>$native,
                    ]),
                    'permission'=>'read-banking-transactions',
                    'attributes'=>['target'=>'_blank','rel'=>'noopener noreferrer'],
                ];
            }
        }
        if (($row->publication_issue_code??null)==='possible_internal_transfer' &&
            auth()->user()->can('create-banking-transactions') &&
            Route::has('transfers.create')) {
            $actions[]=[
                'title'=>trans('banking-connectors::general.native_create_transfer'),
                'icon'=>'swap_horiz',
                'url'=>route('transfers.create',['company_id'=>$company]),
                'permission'=>'create-banking-transactions',
            ];
        }
        if (auth()->user()->can('update-banking-accounts') &&
            Route::has('banking-connectors.account.show')) {
            $actions[]=['type'=>'divider'];
            $actions[]=[
                'title'=>trans('banking-connectors::general.native_action_account_settings'),
                'icon'=>'settings',
                'url'=>route('banking-connectors.account.show',[
                    'company_id'=>$company,'accountId'=>$account,
                ]),
                'permission'=>'update-banking-accounts',
            ];
        }
        return $actions;
    }

    public function append(View $view): void
    {
        if (!NativeBankSearch::scoped() ||
            !Route::has('banking-connectors.transactions.review')) return;
        $model=$view->getData()['model']??null;
        if (!$model instanceof Transaction ||
            (int)$model->company_id !== (int)company_id() ||
            $model->isRecurringTransaction()) return;

        $map=request()->attributes->get(self::REQUEST_MAP);
        if (!is_array($map) ||
            empty($map['connected'][(int)$model->account_id])) return;
        $actions=$view->getData()['actions']??[];
        if (!is_array($actions)) return;
        $account=(int)$model->account_id;
        $company=(int)company_id();
        $link=$map['linked'][(int)$model->id]??null;
        $actions[]=['type'=>'divider'];
        if ($link !== null && (int)$link->account_id===$account) {
            $actions[]=[
                'title'=>trans('banking-connectors::general.native_action_view_entry'),
                'icon'=>'account_balance',
                'url'=>route('transactions.index',[
                    'company_id'=>$company,
                    'search'=>'bank_movement:imported account_id:'.$account,
                    'bank_entry_id'=>(int)$link->id,
                ]),
                'permission'=>'read-banking-transactions',
                'attributes'=>'id="banking-connectors-line-action-view-'.(int)$model->id.'"',
            ];
        }
        $actions[]=[
            'title'=>trans('banking-connectors::general.native_action_review_bank'),
            'icon'=>'fact_check',
            'url'=>route('transactions.index',[
                'company_id'=>$company,
                'search'=>'bank_movement:unlinked account_id:'.$account,
            ]),
            'permission'=>'read-banking-transactions',
            'attributes'=>'id="banking-connectors-line-action-review-'.(int)$model->id.'"',
        ];
        $view->with('actions',$actions);
    }
}
