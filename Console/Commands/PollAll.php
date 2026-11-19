<?php
// SPDX-FileCopyrightText: 2026 LibreCode Coop and contributors
// SPDX-License-Identifier: AGPL-3.0-or-later
declare(strict_types=1);

namespace Modules\BankingConnectors\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Throwable;
use Modules\BankingConnectors\Application\BankConnectorRegistry;

/**
 * Scheduler entry point, opt-in. Does not choose an initial backfill date
 * or enable a connection. One existing active Sicoob connection per job.
 */
final class PollAll extends Command
{
    protected $signature = 'banking-connectors:poll-all
        {--max=20 : Maximum connections evaluated in one invocation, 1..100}
        {--check-eligible : Count eligible connections without accessing bank}
        {--pending-only : Process only explicitly requested manual refreshes}
        {--provider= : Optional registered bank connector ID}';

    protected $description = 'Refresh eligible connections through installed registered bank connectors';

    public function handle(BankConnectorRegistry $registry): int
    {
        try {
            $max = filter_var($this->option('max'), FILTER_VALIDATE_INT);
            if (!$max || $max < 1 || $max > 100) {
                $this->error('invalid_bank_poll_limit');
                return self::FAILURE;
            }
            $ids = $registry->ids();
            $selected = $this->option('provider');
            if ($selected !== null && $selected !== '') {
                $registry->get((string) $selected);
                $ids = [(string) $selected];
            }
            $pendingOnly = (bool) $this->option('pending-only');
            $connections = DB::table('lcc_banking_connectors_connections as c')
                ->join('accounts as a', static function ($join): void {
                    $join->on('a.id', '=', 'c.account_id')
                        ->on('a.company_id', '=', 'c.company_id');
                })
                ->whereIn('c.provider', $ids)
                ->where(function ($query) use ($pendingOnly): void {
                    if ($pendingOnly) {
                        $query->whereIn('c.state', ['read_only', 'enabled'])
                            ->whereNotNull('c.poll_requested_at');
                        return;
                    }
                    $query->where(function ($enabled): void {
                        $enabled->where('c.state', 'enabled')
                            ->whereNotNull('c.cursor_date');
                    })->orWhere(function ($requested): void {
                        $requested->whereIn('c.state', ['read_only', 'enabled'])
                            ->whereNotNull('c.poll_requested_at');
                    });
                })
                ->where(function ($query): void {
                    $query->whereNull('c.next_retry_at')
                        ->orWhere('c.next_retry_at', '<=', now());
                })
                ->whereNotNull('c.credential_reference')
                ->where('a.currency_code', 'BRL')
                ->whereNull('a.deleted_at')
                // Oldest attempted connections first; failing accounts must
                // not starve other accounts when --max is smaller than the
                // total number of enabled connections.
                ->orderBy('c.updated_at')
                ->orderBy('c.id')
                ->limit((int) $max)
                ->get(['c.id', 'c.poll_requested_start']);

            if ($this->option('check-eligible')) {
                $this->line(json_encode([
                    'status' => 'ok', 'eligible_connections' => $connections->count(),
                    'network_calls' => 0, 'financial_writes' => 0,
                ], JSON_THROW_ON_ERROR));
                return self::SUCCESS;
            }

            $ok = 0;
            $failed = 0;
            foreach ($connections as $connection) {
                // Each child performs its own bank-source and account
                // verification and holds its DB advisory lock during fetch.
                $args = ['connection' => (string) $connection->id, '--no-ansi' => true];
                if (is_string($connection->poll_requested_start)
                    && $connection->poll_requested_start !== '') {
                    $args['--start'] = $connection->poll_requested_start;
                }
                $exit = Artisan::call('banking-connectors:poll', $args);
                if ($exit === self::SUCCESS) {
                    ++$ok;
                } else {
                    ++$failed;
                }
            }
            $this->line(json_encode([
                'status' => $failed === 0 ? 'ok' : 'partial_failure',
                'connections_ok' => $ok,
                'connections_failed' => $failed,
                'financial_writes' => 0,
            ], JSON_THROW_ON_ERROR));
            return $failed === 0 ? self::SUCCESS : self::FAILURE;
        } catch (Throwable) {
            $this->error('banking_connectors_batch_failed');
            return self::FAILURE;
        }
    }
}
