<?php
// SPDX-FileCopyrightText: 2026 LibreCode Coop and contributors
// SPDX-License-Identifier: AGPL-3.0-or-later
declare(strict_types=1);

namespace Modules\BankingConnectors\Application;

use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;
use PDO;

/**
 * Pure read-only, credential-free snapshot for command line health monitors.
 * Only numerical connection IDs and fixed error codes are returned.
 */
final class HealthSnapshot
{
    public function __construct(
        private readonly PDO $db,
        private readonly string $prefix = '',
    ) {
        if (!preg_match('/^[A-Za-z0-9_]{0,40}$/D', $prefix)) {
            throw new InvalidArgumentException('invalid_table_prefix');
        }
    }

    /** @return array<string,mixed> */
    public function inspect(int $maxStaleHours = 24, int $maxQueueMinutes = 60, ?int $companyId = null): array
    {
        if ($maxStaleHours < 1 || $maxStaleHours > 168 ||
            $maxQueueMinutes < 15 || $maxQueueMinutes > 10080 ||
            ($companyId !== null && $companyId < 1)) {
            throw new InvalidArgumentException('invalid_bank_health_threshold');
        }

        $result = [
            'status' => 'ok',
            'enabled_connections' => 0,
            'pending_requests' => 0,
            'retrying_connections' => 0,
            'stale_connections' => 0,
            'overdue_requests' => 0,
            'repeated_failures' => 0,
            'expiring_certificates' => 0,
            'expired_certificates' => 0,
            'unverified_credentials' => 0,
            'issues' => [],
            'observation' => 'last_recorded_sync',
        ];
        $now = time();
        $sql = "SELECT id, company_id, provider, state, last_success_at, poll_requested_at,
                       next_retry_at, failure_count, last_error_code,
                       certificate_valid_to, credentials_checked_at
                FROM {$this->prefix}lcc_banking_connectors_connections
                WHERE state IN ('read_only', 'enabled')";
        if ($companyId !== null) {
            $sql .= ' AND company_id = :company_id';
        }
        $sql .= ' ORDER BY company_id, id';
        $query = $this->db->prepare($sql);
        $query->execute($companyId === null ? [] : [':company_id' => $companyId]);
        foreach ($query->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $company = (int) $row['company_id'];
            $connection = (int) $row['id'];
            $id = ['company_id' => $company, 'connection_id' => $connection];

            if ($row['state'] === 'enabled') {
                ++$result['enabled_connections'];
                if ($row['provider'] === 'sicoob') {
                    $expires = self::utcTimestamp($row['certificate_valid_to']);
                    $checked = self::utcTimestamp($row['credentials_checked_at']);
                    if ($expires !== null && $expires <= $now) {
                        ++$result['expired_certificates'];
                        $result['issues'][] = $id + ['code' => 'bank_certificate_expired'];
                    } elseif ($expires !== null && $expires - $now < 30 * 86400) {
                        ++$result['expiring_certificates'];
                        $result['issues'][] = $id + ['code' => 'bank_certificate_expiring'];
                    }
                    if ($checked === null || $now - $checked >= 48 * 3600) {
                        ++$result['unverified_credentials'];
                        $result['issues'][] = $id + ['code' => 'bank_credential_probe_stale'];
                    }
                }
                $last = self::utcTimestamp($row['last_success_at']);
                if ($last === null || $now - $last > $maxStaleHours * 3600) {
                    ++$result['stale_connections'];
                    $result['issues'][] = $id + ['code' => 'banking_connectors_stale'];
                }
            }
            if ($row['poll_requested_at'] !== null) {
                ++$result['pending_requests'];
                $requested = self::utcTimestamp($row['poll_requested_at']);
                if ($requested === null || $now - $requested > $maxQueueMinutes * 60) {
                    ++$result['overdue_requests'];
                    $result['issues'][] = $id + ['code' => 'banking_connectors_request_overdue'];
                }
            }
            if ($row['next_retry_at'] !== null &&
                (int) $row['failure_count'] > 0) {
                ++$result['retrying_connections'];
            }
            if ((int) $row['failure_count'] >= 3) {
                ++$result['repeated_failures'];
                // Do not leak raw provider errors. The code stored in the DB
                // is a known sanitized value from the Banking Connectors worker.
                $result['issues'][] = $id + ['code' => 'banking_connectors_repeated_failures'];
            }
            if (in_array($row['last_error_code'], [
                'bank_api_forbidden', 'bank_api_unauthorized',
                'bank_mtls_certificate_expired',
                'bank_account_number_not_verified',
                'fingerprint_key_changed_requires_migration',
                'fingerprint_key_binding_requires_migration',
            ], true)) {
                $result['issues'][] = $id + ['code' => 'banking_connectors_intervention_required'];
            }
        }
        if ($result['issues'] !== []) {
            $result['status'] = 'attention';
        } elseif ($result['enabled_connections'] === 0 && $result['pending_requests'] === 0) {
            // No active connection is not the same as a successful bank sync.
            $result['status'] = 'idle';
        }
        return $result;
    }

    private static function utcTimestamp(mixed $value): ?int
    {
        if (!is_string($value) || $value === '') {
            return null;
        }
        $date = DateTimeImmutable::createFromFormat(
            '!Y-m-d H:i:s', $value, new DateTimeZone('UTC')
        );
        if (!$date || $date->format('Y-m-d H:i:s') !== $value) {
            return null;
        }
        return $date->getTimestamp();
    }
}
