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
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;

/**
 * Non-blocking, company-scoped manual bank refresh.
 *
 * The HTTP caller only stores the review request and dispatches this job.
 * This worker uses the same verified CLI service as scheduled polling.
 * Failures and bank backoff are recorded by PollConnection, not retried blindly.
 */
final class PollQueuedConnection implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 1;
    // Stay below the default Redis retry_after (90 s) and worker timeout.
    // A longer backfill resumes safely from the last committed bank window.
    public int $timeout = 75;

    public function __construct(
        public readonly int $companyId,
        public readonly int $connectionId,
        public readonly string $requestKey,
    ) {
        if ($companyId < 1 || $connectionId < 1 ||
            !preg_match('/^[a-f0-9]{32}$/D', $requestKey)) {
            throw new \InvalidArgumentException('invalid_banking_sync_job');
        }
        $this->onQueue('default');
    }

    public function handle(): void
    {
        // Re-read the authoritative state. A paused connection, another
        // company's connection, an old request or a completed/replaced
        // request must NEVER cause a bank network call.
        $row = DB::table('lcc_banking_connectors_connections')
            ->where('id', $this->connectionId)
            ->where('company_id', $this->companyId)
            ->whereIn('state', ['read_only', 'enabled'])
            ->where('poll_request_key', $this->requestKey)
            ->whereNotNull('poll_requested_at')
            ->where(function ($query): void {
                $query->whereNull('next_retry_at')
                    ->orWhere('next_retry_at', '<=', now());
            })
            ->first(['id', 'poll_requested_start']);

        if ($row === null) {
            return;
        }

        $args = ['connection' => (string) $this->connectionId, '--no-ansi' => true];
        if (is_string($row->poll_requested_start) && $row->poll_requested_start !== '') {
            $args['--start'] = $row->poll_requested_start;
        }
        // Error handling belongs to PollConnection, which persists a retry
        // window and sanitizes logs. This job never logs bank data.
        Artisan::call('banking-connectors:poll', $args);
    }
}
