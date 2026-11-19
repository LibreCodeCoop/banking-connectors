<?php
// SPDX-FileCopyrightText: 2026 LibreCode Coop and contributors
// SPDX-License-Identifier: AGPL-3.0-or-later
declare(strict_types=1);

namespace Modules\BankingConnectors\Infrastructure;

use DomainException;
use PDO;
use RuntimeException;
use Throwable;

/**
 * Uses the existing database connection as the ownership of a named lock.
 * No lease table, lock daemon, Redis service or background infrastructure.
 * A dead worker releases the lock when its MySQL connection disconnects.
 */
final class MysqlSyncLock
{
    public function __construct(private readonly PDO $db) {}

    public function run(int $connectionId, callable $operation): mixed
    {
        if ($connectionId < 1) {
            throw new DomainException('invalid_bank_connection');
        }
        if ($this->db->getAttribute(PDO::ATTR_DRIVER_NAME) !== 'mysql') {
            throw new RuntimeException('bank_lock_requires_mysql');
        }
        $key = 'lcc_banking_connectors_conn_' . $connectionId;
        $stmt = $this->db->prepare('SELECT GET_LOCK(:lock_name, 0)');
        $stmt->execute([':lock_name' => $key]);
        if ((string) $stmt->fetchColumn() !== '1') {
            throw new DomainException('banking_connectors_already_running');
        }
        try {
            return $operation();
        } finally {
            // No secret or raw provider response is emitted on unlock failures.
            $release = $this->db->prepare('SELECT RELEASE_LOCK(:lock_name)');
            $release->execute([':lock_name' => $key]);
            if ((string) $release->fetchColumn() !== '1') {
                throw new RuntimeException('banking_connectors_lock_release_failed');
            }
        }
    }
}
