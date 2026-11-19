<?php
// SPDX-FileCopyrightText: 2026 LibreCode Coop and contributors
// SPDX-License-Identifier: AGPL-3.0-or-later
declare(strict_types=1);

namespace Modules\BankingConnectors\Application;

use DateTimeImmutable;
use DateTimeZone;
use DomainException;
use InvalidArgumentException;
use PDO;

/**
 * Company/account-scoped connection control; no bank credentials or
 * native Akaunting financial rows are written by this service.
 *
 * The caller MUST authenticate, check native permissions and obtain the
 * current company from Akaunting's company identification middleware.
 */
final class ConnectionControl
{
    private readonly string $connections;
    private readonly string $accounts;

    public function __construct(private readonly PDO $db, string $prefix = '')
    {
        if (!preg_match('/^[A-Za-z0-9_]{0,40}$/D', $prefix)) {
            throw new InvalidArgumentException('invalid_table_prefix');
        }
        $this->connections = $prefix . 'lcc_banking_connectors_connections';
        $this->accounts = $prefix . 'accounts';
        $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    }

    /**
     * Creates a paused bank-provider binding. Credentials remain outside Akaunting.
     * Repeated creation for the same scoped account is safe.
     *
     * @return array{id:int,created:bool}
     */
    public function create(int $companyId, int $accountId, string $provider = 'sicoob'): array
    {
        $this->validateScope($companyId, $accountId);
        if (!preg_match('/^[a-z][a-z0-9_-]{1,47}$/D', $provider)) {
            throw new InvalidArgumentException('invalid_bank_connector_id');
        }
        $now = $this->utcNow();
        $reference = "{$provider}/company-{$companyId}/account-{$accountId}";
        $this->db->beginTransaction();
        try {
            $this->assertBankAccount($companyId, $accountId, $provider);
            $existing = $this->connectionByAccount($companyId, $accountId, $provider);
            if ($existing !== false) {
                $this->db->commit();
                return ['id' => (int) $existing['id'], 'created' => false];
            }
            $insert = $this->db->prepare(
                "INSERT INTO {$this->connections}
                 (company_id, account_id, provider, credential_reference,
                  state, failure_count, created_at, updated_at)
                 VALUES (:company_id, :account_id, :provider, :reference,
                         'paused', 0, :created_at, :updated_at)"
            );
            $insert->execute([
                ':company_id' => $companyId, ':account_id' => $accountId,
                ':provider' => $provider, ':reference' => $reference,
                ':created_at' => $now, ':updated_at' => $now,
            ]);
            $id = (int) $this->db->lastInsertId();
            $this->db->commit();
            return ['id' => $id, 'created' => true];
        } catch (\Throwable $e) {
            $this->db->rollBack();
            throw $e;
        }
    }

    public function transition(int $companyId, int $connectionId, string $state): string
    {
        if ($companyId < 1 || $connectionId < 1 ||
            !in_array($state, ['paused', 'read_only', 'enabled'], true)) {
            throw new InvalidArgumentException('invalid_connection_state');
        }
        $this->db->beginTransaction();
        try {
            $row = $this->connection($companyId, $connectionId, true);
            $this->assertBankAccount($companyId, (int) $row['account_id'], (string) $row['provider']);
            if ($state === 'enabled' &&
                ($row['cursor_date'] === null ||
                 $row['fingerprint_key_commitment'] === null ||
                 $row['credential_reference'] === null ||
                 $row['last_success_at'] === null ||
                 $row['last_error_code'] !== null)) {
                throw new DomainException('automatic_sync_requires_verified_first_read');
            }
            $now = $this->utcNow();
            $updates = $state === 'paused'
                ? "poll_requested_at = NULL, poll_requested_start = NULL,
                    poll_request_key = NULL, next_retry_at = NULL,"
                : '';
            $statement = $this->db->prepare(
                "UPDATE {$this->connections}
                 SET state = :state, {$updates} updated_at = :updated_at
                 WHERE id = :id AND company_id = :company_id"
            );
            $statement->execute([
                ':state' => $state, ':updated_at' => $now,
                ':id' => $connectionId, ':company_id' => $companyId,
            ]);
            $this->db->commit();
            return $state;
        } catch (\Throwable $e) {
            $this->db->rollBack();
            throw $e;
        }
    }

    /**
     * Records a manual request, consumed only by the server-side poller.
     * No bank network call is made in this method or its HTTP controller.
     *
     * @return array{queued:bool,idempotent:bool}
     */
    public function request(
        int $companyId, int $connectionId, ?string $start, string $key
    ): array {
        if ($companyId < 1 || $connectionId < 1 ||
            !preg_match('/^[a-f0-9]{32}$/D', $key)) {
            throw new InvalidArgumentException('invalid_poll_request');
        }
        $parsedStart = $start === null || $start === ''
            ? null : $this->date($start);
        $today = new DateTimeImmutable('today', new DateTimeZone('America/Sao_Paulo'));
        if ($parsedStart !== null && $parsedStart > $today) {
            throw new InvalidArgumentException('future_statement_date_not_allowed');
        }
        $this->db->beginTransaction();
        try {
            $row = $this->connection($companyId, $connectionId, true);
            $this->assertBankAccount($companyId, (int) $row['account_id'], (string) $row['provider']);
            if (!in_array($row['state'], ['read_only', 'enabled'], true) ||
                $row['credential_reference'] !== "{$row['provider']}/company-{$companyId}/account-{$row['account_id']}") {
                throw new DomainException('connection_not_ready_for_polling');
            }
            if ($row['poll_requested_at'] !== null) {
                if (hash_equals((string) $row['poll_request_key'], $key) &&
                    ($row['poll_requested_start'] ?? null) === $start) {
                    $this->db->commit();
                    return ['queued' => true, 'idempotent' => true];
                }
                throw new DomainException('banking_connectors_already_requested');
            }
            $cursor = $row['cursor_date'] === null
                ? null : $this->date((string) $row['cursor_date']);
            if ($cursor === null && $parsedStart === null) {
                throw new DomainException('initial_statement_date_required');
            }
            if ($cursor !== null && $parsedStart !== null &&
                $parsedStart > $cursor->modify('+1 day')) {
                throw new DomainException('sync_window_would_skip_days');
            }
            if ($parsedStart !== null && $parsedStart < $today->modify('-365 days')) {
                throw new DomainException('request_start_exceeds_history_limit');
            }
            $stmt = $this->db->prepare(
                "UPDATE {$this->connections}
                 SET poll_requested_at = :requested_at,
                     poll_requested_start = :requested_start,
                     poll_request_key = :request_key,
                     next_retry_at = NULL, failure_count = 0,
                     updated_at = :updated_at
                 WHERE id = :id AND company_id = :company_id
                   AND poll_requested_at IS NULL"
            );
            $stmt->execute([
                ':requested_at' => $this->utcNow(),
                ':requested_start' => $parsedStart?->format('Y-m-d'),
                ':request_key' => $key,
                ':updated_at' => $this->utcNow(),
                ':id' => $connectionId, ':company_id' => $companyId,
            ]);
            if ($stmt->rowCount() !== 1) {
                throw new DomainException('banking_connectors_already_requested');
            }
            $this->db->commit();
            return ['queued' => true, 'idempotent' => false];
        } catch (\Throwable $e) {
            $this->db->rollBack();
            throw $e;
        }
    }

    /**
     * Persist only certificate validity metadata from local mTLS verification.
     * Does not mark bank transport as healthy, and never stores PEM material.
     */
    public function credentialObserved(int $companyId, int $connectionId, int $validUntil): void
    {
        if ($validUntil <= time() || $companyId < 1 || $connectionId < 1) {
            throw new InvalidArgumentException('invalid_bank_certificate_expiration');
        }
        $stmt = $this->db->prepare(
            "UPDATE {$this->connections}
             SET certificate_valid_to = :valid_to,
                 credentials_checked_at = :checked,
                 last_error_code = CASE
                    WHEN last_error_code IN (
                        'bank_mtls_certificate_invalid', 'bank_mtls_certificate_expired',
                        'bank_mtls_key_invalid', 'bank_mtls_key_mismatch',
                        'bank_credentials_not_configured', 'bank_secret_file_unavailable'
                    ) THEN NULL ELSE last_error_code END
             WHERE id = :id AND company_id = :company_id
               AND state IN ('read_only', 'enabled')"
        );
        $stmt->execute([
            ':valid_to' => gmdate('Y-m-d H:i:s', $validUntil),
            ':checked' => $this->utcNow(),
            ':id' => $connectionId, ':company_id' => $companyId,
        ]);
        if ($stmt->rowCount() === 0) {
            $this->connection($companyId, $connectionId, false);
        }
    }

    /** Safe local check; does not modify financial records or retry schedules. */
    public function credentialRejected(int $companyId, int $connectionId, string $code): void
    {
        if (!in_array($code, [
            'bank_mtls_certificate_invalid', 'bank_mtls_certificate_expired',
            'bank_mtls_key_invalid', 'bank_mtls_key_mismatch',
            'bank_credentials_not_configured', 'bank_secret_file_unavailable',
            'bank_secret_mount_unavailable', 'bank_secret_permissions_unsafe',
            'bank_account_number_not_verified', 'bank_credential_scope_mismatch',
        ], true)) {
            $code = 'bank_credentials_not_configured';
        }
        $stmt = $this->db->prepare(
            "UPDATE {$this->connections}
             SET credentials_checked_at = :checked, last_error_code = :error_code
             WHERE id = :id AND company_id = :company_id
               AND state IN ('read_only', 'enabled')"
        );
        $stmt->execute([
            ':checked' => $this->utcNow(), ':error_code' => $code,
            ':id' => $connectionId, ':company_id' => $companyId,
        ]);
    }

    /** Called only by the poll worker after its advisory lock is acquired. */
    public function complete(int $companyId, int $connectionId, ?string $requestKey): void
    {
        $this->updateWorkerStatus($companyId, $connectionId, $requestKey, true);
    }

    /** Bounded backoff avoids hammering Sicoob after error or HTTP throttling. */
    public function fail(
        int $companyId, int $connectionId,
        ?string $safeCode = null, ?int $retryAfterSeconds = null
    ): void {
        $this->updateWorkerStatus($companyId, $connectionId, null, false, $safeCode, $retryAfterSeconds);
    }

    private function updateWorkerStatus(
        int $company, int $id, ?string $requestKey, bool $completed,
        ?string $safeCode = null, ?int $retryAfterSeconds = null
    ): void {
        $row = $this->connection($company, $id, false);
        $now = $this->utcNow();
        if ($completed) {
            $stmt = $this->db->prepare(
                "UPDATE {$this->connections}
                 SET poll_requested_at = NULL, poll_requested_start = NULL,
                     poll_request_key = NULL, next_retry_at = NULL,
                     failure_count = 0, last_attempt_at = :attempt, updated_at = :updated
                 WHERE id = :id AND company_id = :company_id
                   AND (poll_request_key IS NULL OR poll_request_key = :request_key)"
            );
            $stmt->execute([
                ':attempt' => $now, ':updated' => $now, ':id' => $id,
                ':company_id' => $company, ':request_key' => $requestKey,
            ]);
        } else {
            $failures = min(12, (int) ($row['failure_count'] ?? 0) + 1);
            $seconds = min(3600, 30 * (2 ** min($failures, 7)));
            if ($retryAfterSeconds !== null) {
                $seconds = max($seconds, min(3600, max(1, $retryAfterSeconds)));
            }
            $retry = (new DateTimeImmutable($now, new DateTimeZone('UTC')))
                ->modify("+{$seconds} seconds")->format('Y-m-d H:i:s');
            $stmt = $this->db->prepare(
                "UPDATE {$this->connections}
                 SET failure_count = :failure_count, next_retry_at = :next_retry,
                     last_error_code = :error_code,
                     last_attempt_at = :attempt, updated_at = :updated
                 WHERE id = :id AND company_id = :company_id"
            );
            $stmt->execute([
                ':failure_count' => $failures, ':next_retry' => $retry,
                ':error_code' => $this->safeFailureCode($safeCode, $row),
                ':attempt' => $now, ':updated' => $now,
                ':id' => $id, ':company_id' => $company,
            ]);
        }
    }

    /** @param array<string,mixed> $row */
    private function safeFailureCode(?string $code, array $row): string
    {
        $known = [
            'bank_api_network_failed', 'bank_api_unauthorized',
            'bank_api_forbidden', 'bank_api_rate_limited',
            'bank_api_temporarily_unavailable', 'bank_api_request_failed',
            'bank_api_response_invalid', 'bank_mtls_certificate_invalid',
            'bank_mtls_certificate_expired', 'bank_mtls_key_invalid',
            'bank_mtls_key_mismatch', 'bank_credentials_not_configured',
            'bank_account_number_not_verified', 'bank_credential_scope_mismatch',
            'banking_connectors_window_failed', 'bank_read_or_stage_failed',
            'bank_secret_mount_unavailable', 'banking_connectors_poll_failed',
            'bank_statement_requires_review', 'bank_statement_duplicate_identity',
            'bank_statement_outside_requested_window', 'connection_not_accessible',
            'fingerprint_key_changed_requires_migration',
            'fingerprint_key_binding_requires_migration',
            'bank_secret_file_unavailable', 'bank_secret_permissions_unsafe',
            'bank_secret_file_invalid', 'bank_client_identity_invalid',
        ];
        $safe = is_string($code) && in_array($code, $known, true)
            ? $code : 'banking_connectors_poll_failed';
        if ($safe === 'banking_connectors_window_failed'
            && is_string($row['last_error_code'] ?? null)
            && in_array($row['last_error_code'], $known, true)) {
            return $row['last_error_code'];
        }
        return $safe;
    }

    /** @return array<string,mixed> */
    private function connection(int $companyId, int $id, bool $lock): array
    {
        $sql = "SELECT * FROM {$this->connections}
                WHERE id = :id AND company_id = :company_id";
        if ($lock && $this->db->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql') {
            $sql .= ' FOR UPDATE';
        }
        $query = $this->db->prepare($sql);
        $query->execute([':id' => $id, ':company_id' => $companyId]);
        $row = $query->fetch(PDO::FETCH_ASSOC);
        if (!is_array($row)) {
            throw new DomainException('bank_connection_not_accessible');
        }
        return $row;
    }

    /** @return array<string,mixed>|false */
    private function connectionByAccount(int $company, int $account, string $provider): array|false
    {
        $query = $this->db->prepare(
            "SELECT id FROM {$this->connections}
             WHERE company_id = :company_id AND account_id = :account_id
               AND provider = :provider"
        );
        $query->execute([':company_id' => $company, ':account_id' => $account, ':provider' => $provider]);
        return $query->fetch(PDO::FETCH_ASSOC);
    }

    private function assertBankAccount(int $company, int $account, string $provider): void
    {
        $stmt = $this->db->prepare(
            "SELECT id FROM {$this->accounts}
             WHERE id = :account_id AND company_id = :company_id
               AND type = 'bank' AND currency_code IS NOT NULL
               AND deleted_at IS NULL AND enabled = 1" .
            ($provider === 'sicoob' ? " AND currency_code = 'BRL'" : '')
        );
        $stmt->execute([':account_id' => $account, ':company_id' => $company]);
        if ($stmt->fetchColumn() === false) {
            throw new DomainException('bank_account_not_available');
        }
    }

    private function validateScope(int $company, int $account): void
    {
        if ($company < 1 || $account < 1) {
            throw new InvalidArgumentException('invalid_bank_account_scope');
        }
    }

    private function date(string $date): DateTimeImmutable
    {
        $result = DateTimeImmutable::createFromFormat('!Y-m-d', $date);
        if (!$result || $result->format('Y-m-d') !== $date) {
            throw new InvalidArgumentException('invalid_statement_date');
        }
        return $result;
    }

    private function utcNow(): string
    {
        return (new DateTimeImmutable('now', new DateTimeZone('UTC')))
            ->format('Y-m-d H:i:s');
    }
}
