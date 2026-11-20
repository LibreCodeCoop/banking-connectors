<?php
// SPDX-FileCopyrightText: 2026 LibreCode Coop and contributors
// SPDX-License-Identifier: AGPL-3.0-or-later
declare(strict_types=1);

namespace Modules\BankingConnectors\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Modules\BankingConnectors\Application\EnrichBankMovement;
use Throwable;
use Illuminate\Support\Facades\DB;

/** One explicit read-only API operation, never triggered during statement sync. */
final class EnrichBankMovementJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;
    public int $tries=1;
    public int $timeout=45;

    public function __construct(
        public readonly int $companyId,
        public readonly int $accountId,
        public readonly int $entryId,
    ) {
        if ($companyId<1 || $accountId<1 || $entryId<1) {
            throw new \InvalidArgumentException('invalid_bank_enrichment_job');
        }
        $this->onQueue('default');
    }

    public function handle(EnrichBankMovement $enricher): void
    {
        // Reauthorize the tenant, account and connection at execution time.
        // Never log personal data or raw bank responses on failure.
        try {
            $enricher->run($this->companyId,$this->accountId,$this->entryId);
        } catch (Throwable) {
            // Record only a fixed status, without raw bank responses or PII.
            DB::table('lcc_banking_connectors_entries')
                ->where('company_id',$this->companyId)->where('account_id',$this->accountId)
                ->where('id',$this->entryId)->where('bank_details_enrichment_state','queued')
                ->update(['bank_details_enrichment_state'=>'failed',
                          'bank_details_enrichment_checked_at'=>now()]);
        }
    }
}
