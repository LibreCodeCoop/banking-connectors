<?php
// SPDX-FileCopyrightText: 2026 LibreCode Coop and contributors
// SPDX-License-Identifier: AGPL-3.0-or-later
declare(strict_types=1);

namespace Modules\BankingConnectors\Application;

use DateTimeImmutable;
use DateTimeZone;
use DomainException;
use InvalidArgumentException;
use Modules\BankingConnectors\Contracts\BankStatementProvider;
use Modules\BankingConnectors\Infrastructure\PdoEntryStage;
use Modules\BankingConnectors\Contracts\BankTransportFailure;
use PDO;
use RuntimeException;
use Throwable;

/**
 * Advances a bank statement cursor only after each fetched window is fully
 * and durably staged. NEVER writes native Akaunting financial records.
 *
 * The provider is injected by a trusted caller; no bank credentials or
 * certificates are accepted from an HTTP request or persisted here.
 */
final class ReadOnlySynchronizer
{
    private readonly string $connectionsTable;
    private readonly string $entriesTable;

    public function __construct(
        private readonly PDO $db,
        private readonly PdoEntryStage $stage,
        string $prefix = '',
    ) {
        if (strlen($prefix) > 40 || !preg_match('/^[A-Za-z0-9_]*$/D', $prefix)) {
            throw new InvalidArgumentException('invalid_table_prefix');
        }
        $this->connectionsTable = $prefix . 'lcc_banking_connectors_connections';
        $this->entriesTable = $prefix . 'lcc_banking_connectors_entries';
        $this->db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    }

    /**
     * `from` and `until` are inclusive dates. To avoid silently skipping
     * transactions, a non-first run cannot begin after the day following the
     * last completed date. Replays and backfills are safe and idempotent.
     *
     * @return array{windows:int,inserted:int,duplicates:int,conflicts:int,through:string}
     */
    public function synchronize(
        int $connectionId,
        int $companyId,
        int $accountId,
        string $providerName,
        BankStatementProvider $provider,
        string $stableHmacKey,
        DateTimeImmutable $from,
        DateTimeImmutable $until,
    ): array {
        if ($connectionId < 1 || $companyId < 1 || $accountId < 1
            || !preg_match('/^[a-z0-9_-]{2,48}$/', $providerName)
            || strlen($stableHmacKey) < 32) {
            throw new InvalidArgumentException('invalid_sync_configuration');
        }
        foreach ([$from, $until] as $date) {
            if ($date->format('H:i:s') !== '00:00:00') {
                throw new InvalidArgumentException('date_only_required');
            }
        }
        if ($from > $until || (int) $from->diff($until)->format('%a') > 365) {
            throw new InvalidArgumentException('invalid_sync_window');
        }

        $connection = $this->assertActiveConnection(
            $connectionId, $companyId, $accountId, $providerName
        );
        $this->bindOrVerifyFingerprintKey(
            $connectionId, $companyId, $accountId, $providerName, $stableHmacKey
        );
        $previousCursor = $connection['cursor_date'];
        if ($previousCursor !== null) {
            $cursor = DateTimeImmutable::createFromFormat('!Y-m-d', $previousCursor);
            if (!$cursor || $cursor->format('Y-m-d') !== $previousCursor) {
                throw new RuntimeException('invalid_existing_cursor');
            }
            if ($from > $cursor->modify('+1 day')) {
                throw new DomainException('sync_window_would_skip_days');
            }
        }

        $summary = [
            'windows' => 0, 'inserted' => 0, 'duplicates' => 0,
            'conflicts' => 0, 'through' => (string) ($previousCursor ?? ''),
        ];
        $day = $from;
        while ($day <= $until) {
            $end = $day->modify('+27 days');
            if ($end > $until) {
                $end = $until;
            }
            try {
                // Recheck before EACH external call: a paused connection must
                // never continue a multi-window backfill.
                $this->assertActiveConnection(
                    $connectionId, $companyId, $accountId, $providerName
                );
                $entries = $provider->fetch($companyId, $accountId, $day, $end);
                if (!is_array($entries)) {
                    throw new RuntimeException('provider_response_not_array');
                }
                $counts = $this->stage->ingest(
                    $connectionId, $companyId, $accountId, $providerName,
                    $entries, $stableHmacKey
                );
                // An interrupted update only loses cursor progress, never
                // banking evidence; the next run safely replays the window.
                $this->recordCompletedWindow(
                    $connectionId, $companyId, $accountId, $providerName,
                    $end->format('Y-m-d')
                );
            } catch (Throwable $error) {
                $this->recordSafeError(
                    $connectionId, $companyId, $accountId, $providerName,
                    $error
                );
                // Exception messages may contain provider secrets; never log
                // or expose raw transport errors to Web/API/MCP.
                if ($error instanceof BankTransportFailure) {
                    // Only non-sensitive status and bounded Retry-After survive.
                    throw $error;
                }
                throw new RuntimeException('banking_connectors_window_failed');
            }
            ++$summary['windows'];
            foreach (['inserted', 'duplicates', 'conflicts'] as $key) {
                $summary[$key] += $counts[$key];
            }
            $summary['through'] = $end->format('Y-m-d');
            $day = $end->modify('+1 day');
        }

        return $summary;
    }

    /**
     * Prevent silent duplication if the fingerprint HMAC key is accidentally
     * rotated. Existing unbound staged rows require explicit verification of
     * the original key before any further sync can proceed.
     */
    private function bindOrVerifyFingerprintKey(
        int $id, int $company, int $account, string $provider, string $secret
    ): void {
        $commitment = hash_hmac('sha256', 'banking-connectors-stable-key-binding-v1', $secret);
        $bind = $this->db->prepare(
            "UPDATE {$this->connectionsTable}
             SET fingerprint_key_commitment = :commitment
             WHERE id = :id AND company_id = :company_id
               AND account_id = :account_id AND provider = :provider
               AND state IN ('read_only', 'enabled')
               AND fingerprint_key_commitment IS NULL
               AND NOT EXISTS (
                   SELECT 1 FROM {$this->entriesTable}
                   WHERE connection_id = :entries_connection_id
               )"
        );
        $bind->execute([
            ':commitment' => $commitment,
            ':id' => $id, ':company_id' => $company,
            ':account_id' => $account, ':provider' => $provider,
            ':entries_connection_id' => $id,
        ]);
        $query = $this->db->prepare(
            "SELECT fingerprint_key_commitment FROM {$this->connectionsTable}
             WHERE id = :id AND company_id = :company_id
               AND account_id = :account_id AND provider = :provider"
        );
        $query->execute([
            ':id' => $id, ':company_id' => $company,
            ':account_id' => $account, ':provider' => $provider,
        ]);
        $value = $query->fetchColumn();
        if ($value === false || $value === null) {
            throw new DomainException('fingerprint_key_binding_requires_migration');
        }
        if (!hash_equals((string) $value, $commitment)) {
            throw new DomainException('fingerprint_key_changed_requires_migration');
        }
    }

    /** @return array{cursor_date:?string} */
    private function assertActiveConnection(
        int $id, int $company, int $account, string $provider,
    ): array {
        $query = $this->db->prepare(
            "SELECT cursor_date, state FROM {$this->connectionsTable}
             WHERE id = :id AND company_id = :company_id
               AND account_id = :account_id AND provider = :provider"
        );
        $query->execute([
            ':id' => $id, ':company_id' => $company,
            ':account_id' => $account, ':provider' => $provider,
        ]);
        $row = $query->fetch(PDO::FETCH_ASSOC);
        if (!is_array($row) || !in_array($row['state'], ['read_only', 'enabled'], true)) {
            throw new DomainException('connection_not_accessible');
        }
        return [
            'cursor_date' => $row['cursor_date'] === null
                ? null : (string) $row['cursor_date'],
        ];
    }

    private function recordCompletedWindow(
        int $id, int $company, int $account, string $provider, string $date
    ): void {
        $now = (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format('Y-m-d H:i:s');
        $statement = $this->db->prepare(
            "UPDATE {$this->connectionsTable}
             SET cursor_date = CASE
                 WHEN cursor_date IS NULL OR cursor_date < :end_date
                 THEN :end_value ELSE cursor_date END,
                 last_success_at = :last_success_at,
                 last_error_code = NULL, updated_at = :updated_at
             WHERE id = :id AND company_id = :company_id
               AND account_id = :account_id AND provider = :provider
               AND state IN ('read_only', 'enabled')"
        );
        $statement->execute([
            ':end_date' => $date, ':end_value' => $date,
            ':last_success_at' => $now, ':updated_at' => $now,
            ':id' => $id, ':company_id' => $company,
            ':account_id' => $account, ':provider' => $provider,
        ]);
        // On MySQL, a repeat executed within the same second may affect zero
        // physical rows even when the scoped connection remains active.
        if ($statement->rowCount() === 0) {
            $this->assertActiveConnection($id, $company, $account, $provider);
        }
    }

    private function recordSafeError(
        int $id, int $company, int $account, string $provider, Throwable $e,
    ): void {
        $known = [
            'bank_statement_requires_review',
            'bank_statement_duplicate_identity',
            'bank_statement_outside_requested_window',
            'bank_binding_scope_mismatch',
            'connection_not_accessible',
            'entry_scope_mismatch',
            'missing_stable_bank_identity',
            'bank_api_network_failed',
            'bank_api_unauthorized',
            'bank_api_forbidden',
            'bank_api_rate_limited',
            'bank_api_temporarily_unavailable',
            'bank_api_request_failed',
            'bank_api_response_invalid',
        ];
        $code = in_array($e->getMessage(), $known, true)
            ? $e->getMessage() : 'bank_read_or_stage_failed';
        $now = (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format('Y-m-d H:i:s');
        try {
            $statement = $this->db->prepare(
                "UPDATE {$this->connectionsTable}
                 SET last_error_code = :error_code, updated_at = :updated_at
                 WHERE id = :id AND company_id = :company_id
                   AND account_id = :account_id AND provider = :provider"
            );
            $statement->execute([
                ':error_code' => $code, ':updated_at' => $now,
                ':id' => $id, ':company_id' => $company,
                ':account_id' => $account, ':provider' => $provider,
            ]);
        } catch (Throwable) {
            // Original failure determines the retry. Do not write raw errors.
        }
    }
}
