<?php
// SPDX-FileCopyrightText: 2026 LibreCode Coop and contributors
// SPDX-License-Identifier: AGPL-3.0-or-later
declare(strict_types=1);

/**
 * Disposable MySQL worker interruption fixture. One process begins a staging
 * transaction while holding GET_LOCK then is killed by the test orchestrator.
 * The recovery process proves uncommitted rows are rolled back and the same
 * company/account lock is released after the socket disappears.
 */
require dirname(__DIR__) . '/Infrastructure/MysqlSyncLock.php';

use Modules\BankingConnectors\Infrastructure\MysqlSyncLock;

$mode = $argv[1] ?? '';
if (!in_array($mode, ['hold', 'recover'], true) ||
    getenv('BANKING_CONNECTORS_TEST_DB_ISOLATED') !== 'yes' ||
    getenv('BANKING_CONNECTORS_TEST_DB_PASSWORD') === false) {
    throw new RuntimeException('Disposable crash test only');
}
$db = new PDO(
    'mysql:host=127.0.0.1;port=3306;dbname=banking_connectors_test;charset=utf8mb4',
    'root',
    getenv('BANKING_CONNECTORS_TEST_DB_PASSWORD'),
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
);
$lock = new MysqlSyncLock($db);
$source = str_repeat('9', 64);
$data = str_repeat('8', 64);
$insert = function () use ($db, $source, $data): void {
    $stmt = $db->prepare(
        "INSERT INTO lcc_banking_connectors_entries
         (connection_id,company_id,account_id,provider,source_fingerprint,
          content_fingerprint,movement_date,direction,amount_cents,currency,
          state,first_seen_at,last_seen_at,created_at,updated_at)
         VALUES (1,1,9,'sicoob',:src,:content,'2026-11-17','credit',100,'BRL',
                 'pending',UTC_TIMESTAMP(),UTC_TIMESTAMP(),UTC_TIMESTAMP(),UTC_TIMESTAMP())"
    );
    $stmt->execute([':src' => $source, ':content' => $data]);
};
if ($mode === 'hold') {
    $lock->run(1, function () use ($db, $insert): void {
        $db->beginTransaction();
        $insert();
        echo "LOCK_HELD_UNCOMMITTED_STAGING\n";
        flush();
        sleep(90);
        // The orchestrator must kill this worker before the commit.
        throw new RuntimeException('FAIL worker was not interrupted');
    });
    exit(1);
}
$lock->run(1, function () use ($db, $source, $insert): void {
    $stmt = $db->prepare(
        'SELECT COUNT(*) FROM lcc_banking_connectors_entries WHERE source_fingerprint=:src'
    );
    $stmt->execute([':src' => $source]);
    if ((int) $stmt->fetchColumn() !== 0) {
        throw new RuntimeException('FAIL aborted uncommitted bank row persisted');
    }
    echo "PASS killed worker left no uncommitted bank movements\n";
    $db->beginTransaction();
    $insert();
    $db->commit();
});
$stmt = $db->prepare(
    'SELECT COUNT(*) FROM lcc_banking_connectors_entries WHERE source_fingerprint=:src'
);
$stmt->execute([':src' => $source]);
if ((int) $stmt->fetchColumn() !== 1) {
    throw new RuntimeException('FAIL recovery worker did not stage movement');
}
echo "PASS bank connection lock released and recovery worker staged exactly once\n";
