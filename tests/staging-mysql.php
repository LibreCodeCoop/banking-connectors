<?php
// SPDX-FileCopyrightText: 2026 LibreCode Coop and contributors
// SPDX-License-Identifier: AGPL-3.0-or-later
declare(strict_types=1);

/** Disposable MySQL-only staging test; never connect this script to Akaunting. */
require dirname(__DIR__) . '/Domain/BankEntry.php';
require dirname(__DIR__) . '/Infrastructure/PdoEntryStage.php';
require dirname(__DIR__) . '/Infrastructure/MysqlSyncLock.php';

use Modules\BankingConnectors\Domain\BankEntry;
use Modules\BankingConnectors\Infrastructure\PdoEntryStage;
use Modules\BankingConnectors\Infrastructure\MysqlSyncLock;

$password = getenv('BANKING_CONNECTORS_TEST_DB_PASSWORD') ?: '';
if ($password === '' || getenv('BANKING_CONNECTORS_TEST_DB_ISOLATED') !== 'yes') {
    throw new RuntimeException('This test must use a disposable synthetic database.');
}
$db = new PDO('mysql:host=127.0.0.1;port=3306;dbname=banking_connectors_test;charset=utf8mb4',
    'root', $password, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$existing = (int) $db->query(
    "SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = 'banking_connectors_test'"
)->fetchColumn();
if ($existing !== 0) {
    throw new RuntimeException('Refusing a preexisting or nonempty test database.');
}

$db->exec("CREATE TABLE lcc_banking_connectors_connections (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    company_id INT UNSIGNED NOT NULL,
    account_id INT UNSIGNED NOT NULL,
    provider VARCHAR(48) NOT NULL,
    credential_reference VARCHAR(160) NULL,
    state VARCHAR(24) NOT NULL DEFAULT 'paused',
    cursor_date DATE NULL, last_success_at TIMESTAMP NULL,
    last_error_code VARCHAR(72) NULL,
    created_at TIMESTAMP NULL, updated_at TIMESTAMP NULL,
    UNIQUE KEY lcc_bc_conn_binding_unique (company_id,account_id,provider)
) ENGINE=InnoDB");
$db->exec("CREATE TABLE lcc_banking_connectors_entries (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    connection_id BIGINT UNSIGNED NOT NULL,
    company_id INT UNSIGNED NOT NULL,
    account_id INT UNSIGNED NOT NULL,
    provider VARCHAR(48) NOT NULL,
    source_fingerprint CHAR(64) NOT NULL,
    content_fingerprint CHAR(64) NOT NULL,
    movement_date DATE NOT NULL,
    direction VARCHAR(6) NOT NULL,
    amount_cents BIGINT UNSIGNED NOT NULL,
    currency CHAR(3) NOT NULL,
    state VARCHAR(24) NOT NULL DEFAULT 'pending',
    linked_transaction_id BIGINT UNSIGNED NULL,
    conflict_count INT UNSIGNED NOT NULL DEFAULT 0,
    first_seen_at TIMESTAMP NOT NULL,
    last_seen_at TIMESTAMP NOT NULL,
    created_at TIMESTAMP NULL, updated_at TIMESTAMP NULL,
    UNIQUE KEY lcc_bc_entry_source_unique (connection_id,source_fingerprint),
    KEY lcc_bc_entry_scope (company_id,account_id,state,id),
    CONSTRAINT lcc_bc_entry_connection_fk FOREIGN KEY (connection_id)
        REFERENCES lcc_banking_connectors_connections(id) ON DELETE RESTRICT
) ENGINE=InnoDB");
$db->exec("INSERT INTO lcc_banking_connectors_connections
    (company_id,account_id,provider,state)
    VALUES (1,9,'sicoob','read_only'),(2,9,'sicoob','read_only')");

$secret = str_repeat('synthetic-mysql-test-only-', 2);
$stage = new PdoEntryStage($db);
$one = new BankEntry(1, 9, 'sicoob', 'my-sample-0001', '2026-11-15', 'credit', 1499);
$two = new BankEntry(1, 9, 'sicoob', 'my-sample-0002', '2026-11-15', 'credit', 1499);
$initial = $stage->ingest(1, 1, 9, 'sicoob', [$one, $two], $secret);
if ($initial !== ['inserted'=>2, 'duplicates'=>0, 'conflicts'=>0]) {
    throw new RuntimeException('MySQL insert failed');
}
echo "PASS MySQL inserts two distinct bank IDs with same financial fields\n";

$repeated = $stage->ingest(1, 1, 9, 'sicoob', [$one, $two], $secret);
if ($repeated !== ['inserted'=>0, 'duplicates'=>2, 'conflicts'=>0]) {
    throw new RuntimeException('MySQL unique constraint/idempotency failed');
}
echo "PASS MySQL unique key and idempotency\n";

$altered = new BankEntry(1, 9, 'sicoob', 'my-sample-0001', '2026-11-15', 'credit', 1500);
$conflicted = $stage->ingest(1, 1, 9, 'sicoob', [$altered], $secret);
if ($conflicted !== ['inserted'=>0, 'duplicates'=>0, 'conflicts'=>1]) {
    throw new RuntimeException('MySQL conflict quarantine failed');
}
$queue = $stage->listUnresolved(1, 9, 1, 10);
if (count($queue) !== 2 || count(array_filter($queue, fn($r) => $r['state'] === 'conflict')) !== 1) {
    throw new RuntimeException('MySQL review queue failed');
}
echo "PASS MySQL detects changed movement and exposes scoped review queue\n";

$other = new BankEntry(2, 9, 'sicoob', 'my-sample-0001', '2026-11-15', 'credit', 1499);
$separate = $stage->ingest(2, 2, 9, 'sicoob', [$other], $secret);
if ($separate['inserted'] !== 1 || count($stage->listUnresolved(1, 9, 1, 10)) !== 2) {
    throw new RuntimeException('MySQL cross-tenant bank IDs crossed');
}
echo "PASS MySQL same bank ID can exist safely in another tenant\n";
$otherSession = new PDO(
    'mysql:host=127.0.0.1;port=3306;dbname=banking_connectors_test;charset=utf8mb4',
    'root', $password, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
);
$primaryLock = new MysqlSyncLock($db);
$secondaryLock = new MysqlSyncLock($otherSession);
$ranSecondWorker = false;
$confirmed = $primaryLock->run(1, static function () use (
    $secondaryLock, &$ranSecondWorker
): string {
    try {
        $secondaryLock->run(1, static function () use (&$ranSecondWorker): void {
            $ranSecondWorker = true;
        });
        throw new RuntimeException('FAIL second worker passed same-connection lock');
    } catch (DomainException $error) {
        if ($error->getMessage() !== 'banking_connectors_already_running') {
            throw $error;
        }
        echo "PASS second concurrent worker cannot enter the same bank connection\n";
    }
    return 'first_worker_held_lock';
});
if ($confirmed !== 'first_worker_held_lock' || $ranSecondWorker) {
    throw new RuntimeException('MySQL named lock failed to isolate workers');
}
$secondResult = $secondaryLock->run(1, static fn(): string => 'safe_to_resume');
if ($secondResult !== 'safe_to_resume') {
    throw new RuntimeException('MySQL lock was not released after completed job');
}
echo "PASS a second worker resumes after the first releases its lock\n";
try {
    $primaryLock->run(1, static fn() => throw new RuntimeException('synthetic-worker-exception'));
} catch (RuntimeException $error) {
    if ($error->getMessage() !== 'synthetic-worker-exception') {
        throw $error;
    }
}
if ($secondaryLock->run(1, static fn() => true) !== true) {
    throw new RuntimeException('Lock leak after worker exception');
}
echo "PASS failed worker releases lock for retries\n";
$otherBank = $primaryLock->run(1, static fn() => $secondaryLock->run(2,
    static fn(): bool => true));
if ($otherBank !== true) {
    throw new RuntimeException('Unrelated bank account was blocked');
}
echo "PASS independent bank connections do not serialize each other\n";
echo "MySQL staging and advisory locking tests passed on a disposable database.\n";
