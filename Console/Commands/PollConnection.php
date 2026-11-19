<?php
// SPDX-FileCopyrightText: 2026 LibreCode Coop and contributors
// SPDX-License-Identifier: AGPL-3.0-or-later
declare(strict_types=1);

namespace Modules\BankingConnectors\Console\Commands;

use DateTimeImmutable;
use DateTimeZone;
use DomainException;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Modules\BankingConnectors\Application\ReadOnlySynchronizer;
use Modules\BankingConnectors\Application\ConnectionControl;
use Modules\BankingConnectors\Infrastructure\MysqlSyncLock;
use Modules\BankingConnectors\Application\BankConnectorRegistry;
use Modules\BankingConnectors\Domain\ConnectionContext;
use Modules\BankingConnectors\Contracts\BankTransportFailure;
use Throwable;

/**
 * Explicit, server-side statement-only sync.
 * The existing Akaunting source and financial tables are NEVER modified.
 *
 * Credentials must have been rendered by OpenBao Agent (or equivalent
 * trusted secret delivery) into a private mount. No Vault auth code here.
 */
final class PollConnection extends Command
{
    protected $signature = 'banking-connectors:poll
        {connection : ID of an existing company-scoped bank connection}
        {--start= : Required ISO date on the first read; optional for replays}
        {--through= : Final ISO date (defaults to today in Sao Paulo)}
        {--check-credentials : Validate scoped secret mount only; do not call bank}
        {--expected-provider= : Optional fixed provider ID used by compatibility aliases}';

    protected $description = 'Read verified bank statements into staging using registered bank connectors';

    public function handle(ReadOnlySynchronizer $synchronizer, ConnectionControl $controls, BankConnectorRegistry $registry): int
    {
        $record = null;
        $recordFailure = false;
        try {
            $id = filter_var($this->argument('connection'), FILTER_VALIDATE_INT);
            if (!$id || $id < 1) {
                throw new DomainException('invalid_bank_connection');
            }
            $record = DB::table('lcc_banking_connectors_connections as c')
                ->join('accounts as a', static function ($join): void {
                    $join->on('a.id', '=', 'c.account_id')
                        ->on('a.company_id', '=', 'c.company_id');
                })
                ->where('c.id', (int) $id)
                ->whereIn('c.state', ['read_only', 'enabled'])
                ->whereNull('a.deleted_at')
                ->first([
                    'c.id', 'c.company_id', 'c.account_id', 'c.provider', 'c.credential_reference',
                    'c.cursor_date', 'c.poll_request_key',
                    'a.currency_code', 'a.number', 'a.type',
                ]);
            if ($record === null || (string) $record->type !== 'bank') {
                throw new DomainException('bank_connection_not_available');
            }
            $expected = $this->option('expected-provider');
            if ($expected !== null && $expected !== '' && $expected !== $record->provider) {
                throw new DomainException('bank_connection_not_available');
            }
            $recordFailure = !$this->option('check-credentials');
            // The registry resolves only trusted, explicitly installed
            // connector modules. Database rows cannot supply PHP class names.
            $scope = new ConnectionContext(
                (int) $record->company_id, (int) $record->account_id,
                (string) $record->provider, (string) $record->currency_code,
                (string) $record->number, (string) $record->credential_reference
            );
            $session = $registry->get($scope->providerId)->open($scope);
            if (!$this->option('check-credentials') && $session->certificateValidUntil !== null) {
                $controls->credentialObserved(
                    (int) $record->company_id, (int) $record->id,
                    $session->certificateValidUntil
                );
            }
            if ($this->option('check-credentials')) {
                $this->line('bank_connector_credentials_ready (no bank request)');
                return self::SUCCESS;
            }

            $today = new DateTimeImmutable('today', new DateTimeZone('America/Sao_Paulo'));
            $through = $this->parseDate($this->option('through')) ?? $today;
            if ($through > $today) {
                throw new DomainException('future_statement_date_not_allowed');
            }
            $explicitStart = $this->parseDate($this->option('start'));
            $cursor = is_string($record->cursor_date) && $record->cursor_date !== ''
                ? $this->parseDate($record->cursor_date) : null;
            if ($explicitStart === null && $cursor === null) {
                throw new DomainException('initial_statement_date_required');
            }
            $from = $explicitStart ?? $cursor->modify('-2 days');
            if ($from > $through) {
                throw new DomainException('invalid_statement_date_range');
            }

            // One MySQL-owned, session-bound lock per connection. A duplicate
            // worker is rejected before opening the bank network connection.
            $lock = new MysqlSyncLock(DB::connection()->getPdo());
            $counts = $lock->run((int) $id, function () use (
                $record, $session, $synchronizer, $controls, $from, $through
            ): array {
                $counts = $synchronizer->synchronize(
                    (int) $record->id,
                    (int) $record->company_id,
                    (int) $record->account_id,
                    (string) $record->provider,
                    $session->statements,
                    $session->fingerprintKey,
                    $from,
                    $through,
                );
                $controls->complete(
                    (int) $record->company_id, (int) $record->id,
                    is_string($record->poll_request_key) ? $record->poll_request_key : null,
                );
                return $counts;
            });

            $this->line(json_encode([
                'status' => 'ok', 'connection_id' => (int) $id,
                'period_start' => $from->format('Y-m-d'), 'through' => $counts['through'],
                'inserted' => $counts['inserted'], 'duplicates' => $counts['duplicates'],
                'conflicts' => $counts['conflicts'], 'windows' => $counts['windows'],
                'akaunting_financial_writes' => 0,
            ], JSON_THROW_ON_ERROR));
            return self::SUCCESS;
        } catch (Throwable $error) {
            // The diagnostic mode never mutates state. A concurrent worker
            // taking the named lock is also not a provider failure.
            if ($recordFailure && $record !== null &&
                $error->getMessage() !== 'banking_connectors_already_running') {
                try {
                    $controls->fail(
                        (int) $record->company_id, (int) $record->id,
                        $error->getMessage(),
                        $error instanceof BankTransportFailure ? $error->retryDelaySeconds() : null
                    );
                } catch (Throwable) {
                    // Database might be unavailable; retain original error.
                }
            }
            // Never send bank responses, certificate paths or transport
            // exception details to logs, shell histories or API responses.
            $safe = [
                'banking_connectors_already_running',
                'bank_connection_not_available',
                'bank_credentials_not_configured',
                'initial_statement_date_required',
                'future_statement_date_not_allowed',
                'invalid_statement_date_range',
                'bank_credential_scope_mismatch',
                'bank_fingerprint_key_invalid',
                'bank_account_number_not_verified',
                'bank_mtls_certificate_invalid',
                'bank_mtls_certificate_expired',
                'bank_mtls_key_invalid',
                'bank_mtls_key_mismatch',
                'bank_secret_mount_unavailable',
                'bank_secret_file_unavailable',
                'bank_secret_permissions_unsafe',
                'fingerprint_key_changed_requires_migration',
                'fingerprint_key_binding_requires_migration',
                'bank_api_network_failed',
                'bank_api_unauthorized',
                'bank_api_forbidden',
                'bank_api_rate_limited',
                'bank_api_temporarily_unavailable',
                'bank_api_request_failed',
                'bank_api_response_invalid',
            ];
            $this->error(in_array($error->getMessage(), $safe, true)
                ? $error->getMessage() : 'banking_connectors_poll_failed');
            return self::FAILURE;
        }
    }

    private function parseDate(mixed $value): ?DateTimeImmutable
    {
        if ($value === null || $value === '') {
            return null;
        }
        if (!is_string($value)) {
            throw new DomainException('invalid_statement_date_range');
        }
        $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value);
        if (!$date || $date->format('Y-m-d') !== $value) {
            throw new DomainException('invalid_statement_date_range');
        }
        return $date;
    }
}
