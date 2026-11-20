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
            ])->unique('linked_transaction_id')
            ->keyBy('linked_transaction_id')->all();

        request()->attributes->set(self::REQUEST_MAP,[
            'connected'=>$connected,'linked'=>$links,
        ]);
        $view->with('bankingNativeLinked',$links);
        $view->setPath(__DIR__.'/../../Resources/views/native-transaction-rows.blade.php');
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
