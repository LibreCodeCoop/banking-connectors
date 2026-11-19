<?php
// SPDX-FileCopyrightText: 2026 LibreCode Coop and contributors
// SPDX-License-Identifier: AGPL-3.0-or-later
declare(strict_types=1);

require dirname(__DIR__) . '/Application/ConnectionControl.php';
require __DIR__ . '/Support/SqliteStageFixture.php';

use Modules\BankingConnectors\Application\ConnectionControl;
use Modules\BankingConnectors\Tests\Support\SqliteStageFixture;

function checkControl(bool $ok, string $description): void
{
    if (!$ok) {
        throw new RuntimeException('FAIL ' . $description);
    }
    echo 'PASS ' . $description . PHP_EOL;
}

$db = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
SqliteStageFixture::install($db);
$db->exec("INSERT INTO accounts VALUES
(9,1,'synthetic company1 bank','bank','BRL',1,NULL),
(10,2,'synthetic company2 bank','bank','BRL',1,NULL),
(11,1,'synthetic disabled','bank','BRL',0,NULL),
(12,1,'synthetic cash','cash','BRL',1,NULL),
(13,1,'synthetic foreign currency','bank','USD',1,NULL)");
$manager = new ConnectionControl($db);
$first = $manager->create(1, 9);
checkControl($first['created'] && $first['id'] > 0, 'creates paused connection scoped to native bank account');
$repeat = $manager->create(1, 9);
checkControl($first['id'] === $repeat['id'] && !$repeat['created'],
    'repeated account connection does not duplicate');
$row = $db->query("SELECT * FROM lcc_banking_connectors_connections WHERE id={$first['id']}")
    ->fetch(PDO::FETCH_ASSOC);
checkControl($row['state'] === 'paused' &&
    $row['credential_reference'] === 'sicoob/company-1/account-9',
    'only deterministic credential reference stored, no secret');
foreach ([10,11,12,13] as $badId) {
    try {
        $manager->create(1, $badId);
        throw new RuntimeException('FAIL unauthorized or incompatible bank account permitted');
    } catch (DomainException $e) {
        checkControl($e->getMessage() === 'bank_account_not_available',
            "foreign, disabled, non-bank or non-BRL account {$badId} denied");
    }
}
try {
    $manager->transition(2, $first['id'], 'enabled');
    throw new RuntimeException('FAIL foreign company changed state');
} catch (DomainException $e) {
    checkControl($e->getMessage() === 'bank_connection_not_accessible',
        'foreign-company state mutation rejected');
}
try {
    $manager->transition(1, $first['id'], 'enabled');
    throw new RuntimeException('FAIL scheduler enabled without verified first poll');
} catch (DomainException $e) {
    checkControl($e->getMessage() === 'automatic_sync_requires_verified_first_read',
        'automatic sync requires verified cursor, key and last success');
}
$manager->transition(1, $first['id'], 'read_only');
checkControl($db->query("SELECT state FROM lcc_banking_connectors_connections WHERE id={$first['id']}")
    ->fetchColumn() === 'read_only', 'manual-only mode can be enabled safely');
$manager->credentialObserved(1, $first['id'], time() + 60 * 86400);
$expiry = $db->query("SELECT certificate_valid_to,credentials_checked_at
    FROM lcc_banking_connectors_connections WHERE id={$first['id']}")->fetch(PDO::FETCH_ASSOC);
checkControl($expiry['certificate_valid_to'] !== null &&
    $expiry['credentials_checked_at'] !== null,
    'valid certificate expiry captured without copying cert or key');
$manager->credentialRejected(1, $first['id'], 'bank_mtls_certificate_expired');
checkControl($db->query("SELECT last_error_code FROM lcc_banking_connectors_connections
    WHERE id={$first['id']}")->fetchColumn() === 'bank_mtls_certificate_expired',
    'expired certificate retains a safe monitoring status');
$manager->credentialObserved(1, $first['id'], time() + 60 * 86400);
checkControl($db->query("SELECT last_error_code FROM lcc_banking_connectors_connections
    WHERE id={$first['id']}")->fetchColumn() === null,
    'successful credential re-probe clears a resolved certificate error');
$key = str_repeat('a', 32);
$queued = $manager->request(1, $first['id'], '2026-11-10', $key);
checkControl($queued === ['queued' => true, 'idempotent' => false],
    'first manual sync is queued without contacting bank');
$replayed = $manager->request(1, $first['id'], '2026-11-10', $key);
checkControl($replayed === ['queued' => true, 'idempotent' => true],
    'same request-key and period replay is idempotent');
try {
    $manager->request(1, $first['id'], '2026-11-10', str_repeat('b', 32));
    throw new RuntimeException('FAIL competing request accepted');
} catch (DomainException $e) {
    checkControl($e->getMessage() === 'banking_connectors_already_requested',
        'second manual request cannot overwrite pending request');
}
$manager->fail(1, $first['id'], 'bank_api_rate_limited', 1800);
$backoff = $db->query("SELECT failure_count,next_retry_at,poll_requested_at,last_error_code
    FROM lcc_banking_connectors_connections WHERE id={$first['id']}")->fetch(PDO::FETCH_ASSOC);
checkControl((int) $backoff['failure_count'] === 1 &&
    $backoff['next_retry_at'] !== null && $backoff['poll_requested_at'] !== null &&
    $backoff['last_error_code'] === 'bank_api_rate_limited' &&
    strtotime((string) $backoff['next_retry_at'] . ' UTC') - time() >= 1750,
    'HTTP 429 preserves pending request, safe code and respects Retry-After');
$manager->fail(1, $first['id'], 'bank_api_forbidden');
$failureRow = $db->query("SELECT failure_count,last_error_code FROM lcc_banking_connectors_connections WHERE id={$first['id']}")
    ->fetch(PDO::FETCH_ASSOC);
checkControl((int) $failureRow['failure_count'] === 2 &&
    $failureRow['last_error_code'] === 'bank_api_forbidden',
    'second failure replaces stale error rather than misreporting old HTTP 429');
$manager->complete(1, $first['id'], $key);
$after = $db->query("SELECT poll_requested_at,poll_request_key,failure_count,next_retry_at
    FROM lcc_banking_connectors_connections WHERE id={$first['id']}")->fetch(PDO::FETCH_ASSOC);
checkControl($after['poll_requested_at'] === null &&
    $after['poll_request_key'] === null && (int) $after['failure_count'] === 0 &&
    $after['next_retry_at'] === null,
    'successful poll clears queued request and retry state');

$db->exec("UPDATE lcc_banking_connectors_connections SET cursor_date='2026-11-10',
    fingerprint_key_commitment='" . str_repeat('f', 64) . "',
    last_success_at='2026-11-10 12:00:00',last_error_code=NULL
    WHERE id={$first['id']}");
$manager->transition(1, $first['id'], 'enabled');
checkControl($db->query("SELECT state FROM lcc_banking_connectors_connections WHERE id={$first['id']}")
    ->fetchColumn() === 'enabled', 'enabled state is accepted only after verified first poll');
try {
    $manager->request(1, $first['id'], '2026-11-15', str_repeat('c', 32));
    throw new RuntimeException('FAIL skip-ahead backfill accepted');
} catch (DomainException $e) {
    checkControl($e->getMessage() === 'sync_window_would_skip_days',
        'manual start cannot skip unprocessed days');
}
$manager->request(1, $first['id'], null, str_repeat('d', 32));
$manager->transition(1, $first['id'], 'paused');
$paused = $db->query("SELECT state,poll_requested_at FROM lcc_banking_connectors_connections
    WHERE id={$first['id']}")->fetch(PDO::FETCH_ASSOC);
checkControl($paused['state'] === 'paused' && $paused['poll_requested_at'] === null,
    'pausing cancels outstanding manual synchronization');
try {
    $manager->request(1, $first['id'], null, str_repeat('e', 32));
    throw new RuntimeException('FAIL paused account accepted manual sync');
} catch (DomainException $e) {
    checkControl($e->getMessage() === 'connection_not_ready_for_polling',
        'paused account cannot request bank traffic');
}
$usd = $manager->create(1, 13, 'usdbank');
$usdRow = $db->query('SELECT * FROM lcc_banking_connectors_connections WHERE id=' .
    (int) $usd['id'])->fetch(PDO::FETCH_ASSOC);
checkControl($usd['created'] && $usdRow['provider'] === 'usdbank' &&
    $usdRow['credential_reference'] === 'usdbank/company-1/account-13',
    'new connector can create paused USD account independently of Sicoob');
$manager->transition(1, (int) $usd['id'], 'read_only');
$usdRequest = $manager->request(1, (int) $usd['id'], '2026-11-10',
    str_repeat('1', 32));
checkControl($usdRequest['queued'] && !$usdRequest['idempotent'],
    'new connector uses the same durable manual sync queue');
try {
    $manager->transition(2, (int) $usd['id'], 'paused');
    throw new RuntimeException('FAIL cross-tenant connector mutation succeeded');
} catch (DomainException $error) {
    checkControl($error->getMessage() === 'bank_connection_not_accessible',
        'generic connection controls reject another company');
}
checkControl((int) $db->query('SELECT COUNT(*) FROM lcc_banking_connectors_entries')
    ->fetchColumn() === 0, 'connection controls do not create financial transactions');
echo 'Connection control tests passed on disposable SQLite.' . PHP_EOL;
