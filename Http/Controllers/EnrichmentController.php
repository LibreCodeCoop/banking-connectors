<?php
// SPDX-FileCopyrightText: 2026 LibreCode Coop and contributors
// SPDX-License-Identifier: AGPL-3.0-or-later
declare(strict_types=1);

namespace Modules\BankingConnectors\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;
use Modules\BankingConnectors\Jobs\EnrichBankMovementJob;
use Throwable;

final class EnrichmentController extends Controller
{
    public function request(Request $request): RedirectResponse
    {
        abort_unless(auth()->check() && auth()->id(),401);
        abort_unless(auth()->user()->can('read-banking-accounts') &&
            auth()->user()->can('read-banking-transactions') &&
            auth()->user()->can('update-banking-transactions'),403);
        $company=function_exists('company_id')?(int)company_id():0;
        $account=filter_var($request->route('accountId'),FILTER_VALIDATE_INT);
        $entry=filter_var($request->route('entryId'),FILTER_VALIDATE_INT);
        abort_unless($company>0 && $account!==false && $account>0 &&
            $entry!==false && $entry>0,404);
        $record=DB::table('lcc_banking_connectors_entries as e')
            ->join('lcc_banking_connectors_connections as c',static function($join):void {
                $join->on('c.id','=','e.connection_id')
                    ->on('c.company_id','=','e.company_id')
                    ->on('c.account_id','=','e.account_id');
            })
            ->join('accounts as a',static function($join):void {
                $join->on('a.id','=','e.account_id')->on('a.company_id','=','e.company_id');
            })
            ->where('e.id',(int)$entry)->where('e.company_id',$company)
            ->where('e.account_id',(int)$account)->where('e.provider','sicoob')
            ->where('e.direction','credit')->where('e.state','!=','conflict')
            ->whereIn('c.state',['read_only','enabled'])
            ->whereColumn('c.provider','e.provider')
            ->where('a.type','bank')->where('a.enabled',1)->whereNull('a.deleted_at')
            ->first(['e.bank_details','e.bank_details_enrichment_state','e.bank_details_enrichment_checked_at']);
        abort_unless($record!==null,404);
        $details=json_decode((string)$record->bank_details,true);
        if (!is_array($details)) $details=[];
        $fields=$details['fields']??$details;
        $e2e=is_array($fields)?($fields['pix_end_to_end_id']??null):null;
        abort_unless(is_string($e2e) && preg_match('/^E[0-9]{8}[A-Za-z0-9]{23}$/D',$e2e),422);
        $pixUrl=getenv('BANKING_CONNECTORS_SICOOB_PIX_API_URL');
        abort_unless(is_string($pixUrl) && $pixUrl!=='' &&
            is_string(config('queue.default')) &&
            !in_array(config('queue.default'),['sync','null'],true),503);
        if ($record->bank_details_enrichment_state==='queued' && $record->bank_details_enrichment_checked_at &&
            now()->diffInMinutes($record->bank_details_enrichment_checked_at)<10) {
            return back()->with('success',trans('banking-connectors::general.bank_enrichment_queued'));
        }
        $limitKey='bank-enrich:'.$company.':'.$account.':'.$entry;
        abort_if(RateLimiter::tooManyAttempts($limitKey,2),429);
        RateLimiter::hit($limitKey,300);
        DB::table('lcc_banking_connectors_entries')->where('company_id',$company)
            ->where('account_id',(int)$account)->where('id',(int)$entry)
            ->update(['bank_details_enrichment_state'=>'queued','bank_details_enrichment_checked_at'=>now()]);
        try {
            Bus::dispatch(new EnrichBankMovementJob($company,(int)$account,(int)$entry));
        } catch (Throwable) {
            DB::table('lcc_banking_connectors_entries')->where('company_id',$company)
                ->where('account_id',(int)$account)->where('id',(int)$entry)
                ->update(['bank_details_enrichment_state'=>'failed','bank_details_enrichment_checked_at'=>now()]);
            return back()->withErrors(['enrichment'=>trans('banking-connectors::general.bank_enrichment_failed')]);
        }
        return back()->with('success',trans('banking-connectors::general.bank_enrichment_queued'));
    }
}
