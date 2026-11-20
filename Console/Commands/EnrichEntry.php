<?php
// SPDX-FileCopyrightText: 2026 LibreCode Coop and contributors
// SPDX-License-Identifier: AGPL-3.0-or-later
declare(strict_types=1);

namespace Modules\BankingConnectors\Console\Commands;

use Illuminate\Console\Command;
use Modules\BankingConnectors\Application\EnrichBankMovement;
use Throwable;

/** Single-entry operator command; never invoked by scheduled statement polls. */
final class EnrichEntry extends Command
{
    protected $signature='banking-connectors:enrich-entry
        {entry : Internal movement identifier}
        {--company= : Verified company identifier}
        {--account= : Verified bank account identifier}';

    protected $description='Request read-only Pix details for one existing Sicoob bank movement';

    public function handle(EnrichBankMovement $enricher): int
    {
        try {
            $ids=[];
            foreach ([$this->option('company'),$this->option('account'),$this->argument('entry')] as $raw) {
                $id=filter_var($raw,FILTER_VALIDATE_INT,['options'=>['min_range'=>1]]);
                if ($id===false || $id===null) throw new \DomainException('bank_enrichment_scope_invalid');
                $ids[]=(int)$id;
            }
            $enricher->run(...$ids);
            $this->line('bank_enrichment_completed');
            return self::SUCCESS;
        } catch (Throwable) {
            // No bank details, raw HTTP errors, personal data or secret paths.
            $this->error('bank_enrichment_unavailable_or_failed');
            return self::FAILURE;
        }
    }
}
