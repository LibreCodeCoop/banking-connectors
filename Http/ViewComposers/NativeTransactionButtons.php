<?php
// SPDX-FileCopyrightText: 2026 LibreCode Coop and contributors
// SPDX-License-Identifier: AGPL-3.0-or-later
declare(strict_types=1);

namespace Modules\BankingConnectors\Http\ViewComposers;

use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\DB;

final class NativeTransactionButtons
{
    public function compose(View $view): void
    {
        if (!NativeBankSearch::scoped() ||
            !auth()->user()->can('update-banking-accounts') ||
            !auth()->user()->can('update-banking-transactions')) return;
        $company=(int)company_id();
        $connections=DB::table('lcc_banking_connectors_connections as c')
            ->join('accounts as a',static function ($join):void {
                $join->on('a.id','=','c.account_id')->on('a.company_id','=','c.company_id');
            })
            ->where('c.company_id',$company)->where('a.type','bank')
            ->where('a.enabled',1)->whereNull('a.deleted_at')
            ->whereIn('c.state',['read_only','enabled'])
            ->orderBy('a.name')->get(['c.id','c.poll_requested_at','a.name']);
        $view->with('bankSyncConnections',$connections);
        $view->setPath(__DIR__.'/../../Resources/views/native-transaction-buttons.blade.php');
    }
}
