<?php
// SPDX-FileCopyrightText: 2026 LibreCode Coop and contributors
// SPDX-License-Identifier: AGPL-3.0-or-later
declare(strict_types=1);

namespace Modules\BankingConnectors\Console\Commands;

use Illuminate\Console\Command;
use Modules\BankingConnectors\Application\HealthSnapshot;
use Throwable;

/**
 * Zabbix/cron-safe machine-readable health check with no network traffic.
 * It cannot read Sicoob credentials, mutate the bank or edit financial rows.
 */
final class BankHealth extends Command
{
    protected $signature = 'banking-connectors:health
        {--max-stale-hours=24 : Allowed age of last success for enabled bank connections}
        {--max-queue-minutes=60 : Maximum age of manually queued requests}
        {--strict : Nonzero exit when attention is required}';

    protected $description = 'Read Banking Connectors health without contacting a bank or modifying accounts';

    public function handle(HealthSnapshot $health): int
    {
        try {
            $hours = filter_var($this->option('max-stale-hours'), FILTER_VALIDATE_INT);
            $minutes = filter_var($this->option('max-queue-minutes'), FILTER_VALIDATE_INT);
            $report = $health->inspect(
                $hours === false ? -1 : (int) $hours,
                $minutes === false ? -1 : (int) $minutes
            );
            $this->line(json_encode($report, JSON_THROW_ON_ERROR));
            return $this->option('strict') && $report['status'] === 'attention'
                ? self::FAILURE : self::SUCCESS;
        } catch (Throwable) {
            $this->error('banking_connectors_health_unavailable');
            return self::FAILURE;
        }
    }
}
