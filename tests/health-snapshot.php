<?php
// SPDX-FileCopyrightText: 2026 LibreCode Coop and contributors
// SPDX-License-Identifier: AGPL-3.0-or-later
declare(strict_types=1);

require dirname(__DIR__) . '/Application/HealthSnapshot.php';
require __DIR__ . '/Support/SqliteStageFixture.php';

use Modules\BankingConnectors\Application\HealthSnapshot;
use Modules\BankingConnectors\Tests\Support\SqliteStageFixture;

function healthCheck(bool $condition, string $label): void
{
    if (!$condition) {
        throw new RuntimeException('FAIL ' . $label);
    }
    echo 'PASS ' . $label . PHP_EOL;
}
$db = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
SqliteStageFixture::install($db);
SqliteStageFixture::connection($db, 1, 2, 9, 'sicoob', 'enabled');
SqliteStageFixture::connection($db, 2, 2, 10, 'sicoob', 'read_only');
SqliteStageFixture::connection($db, 3, 3, 11, 'sicoob', 'paused');
$old = gmdate('Y-m-d H:i:s', time() - 172800);
$queued = gmdate('Y-m-d H:i:s', time() - 7200);
$recent = gmdate('Y-m-d H:i:s', time() - 120);
$farFuture = gmdate('Y-m-d H:i:s', time() + 70 * 86400);
$db->prepare(
    'UPDATE lcc_banking_connectors_connections
     SET certificate_valid_to = :valid_to, credentials_checked_at = :checked
     WHERE id = 1'
)->execute([':valid_to' => $farFuture, ':checked' => $recent]);
$stmt = $db->prepare(
    'UPDATE lcc_banking_connectors_connections
     SET last_success_at=:last_success, poll_requested_at=:queue,
         next_retry_at=:next_retry, failure_count=3,
         last_error_code=:code WHERE id=1'
);
$stmt->execute([
    ':last_success' => $old, ':queue' => $queued,
    ':next_retry' => gmdate('Y-m-d H:i:s', time() + 900),
    ':code' => 'bank_api_forbidden',
]);
$stmt = $db->prepare(
    'UPDATE lcc_banking_connectors_connections SET poll_requested_at=:queue WHERE id=2'
);
$stmt->execute([':queue' => $recent]);
$db->exec("UPDATE lcc_banking_connectors_connections
  SET last_error_code='bank_api_forbidden',failure_count=9 WHERE id=3");
$monitor = new HealthSnapshot($db);
$result = $monitor->inspect(24, 60);
healthCheck($result['status'] === 'attention' &&
    $result['enabled_connections'] === 1 &&
    $result['pending_requests'] === 2 &&
    $result['retrying_connections'] === 1 &&
    $result['stale_connections'] === 1 &&
    $result['overdue_requests'] === 1 &&
    $result['repeated_failures'] === 1,
    'enabled stale connection, overdue request, repeated errors and retry are detected');
$codes = array_column($result['issues'], 'code');
foreach (['banking_connectors_stale', 'banking_connectors_request_overdue',
    'banking_connectors_repeated_failures', 'banking_connectors_intervention_required'] as $code) {
    healthCheck(in_array($code, $codes, true), "health emits stable alert code {$code}");
}
healthCheck(count($result['issues']) === 4,
    'paused connection does not generate noisy repeated alarms');
$serialized = json_encode($result, JSON_THROW_ON_ERROR);
healthCheck(!str_contains($serialized, 'client-key') &&
    !str_contains($serialized, 'fingerprint-key') &&
    !str_contains($serialized, 'bank_account_number'),
    'health output contains only safe codes and numerical company/connection IDs');
$stmt = $db->prepare(
    'UPDATE lcc_banking_connectors_connections SET last_success_at=:recent,
       poll_requested_at=NULL,next_retry_at=NULL,failure_count=0,
       last_error_code=NULL WHERE id IN (1,2)'
);
$stmt->execute([':recent' => $recent]);
$result = $monitor->inspect();
healthCheck($result['status'] === 'ok' && $result['issues'] === [],
    'healthy active connections do not trigger alerts');
$db->prepare(
    'UPDATE lcc_banking_connectors_connections
     SET certificate_valid_to = :valid_to, credentials_checked_at = :checked
     WHERE id = 1'
)->execute([
    ':valid_to' => gmdate('Y-m-d H:i:s', time() + 18 * 86400),
    ':checked' => gmdate('Y-m-d H:i:s', time() - 49 * 3600),
]);
$warning = $monitor->inspect();
$warningCodes = array_column($warning['issues'], 'code');
healthCheck($warning['status'] === 'attention' &&
    $warning['expiring_certificates'] === 1 &&
    $warning['unverified_credentials'] === 1 &&
    in_array('bank_certificate_expiring', $warningCodes, true) &&
    in_array('bank_credential_probe_stale', $warningCodes, true),
    'cached certificate expiry and stale local probe are monitorable');
$db->prepare('UPDATE lcc_banking_connectors_connections
    SET certificate_valid_to = :valid_to, credentials_checked_at = :checked
    WHERE id = 1'
)->execute([
    ':valid_to' => gmdate('Y-m-d H:i:s', time() - 86400),
    ':checked' => $recent,
]);
$expired = $monitor->inspect();
healthCheck($expired['expired_certificates'] === 1 &&
    in_array('bank_certificate_expired',
        array_column($expired['issues'], 'code'), true),
    'expired cached certificate produces explicit health alert');
$company3Only = $monitor->inspect(companyId: 3);
healthCheck($company3Only['status'] === 'idle' &&
    $company3Only['enabled_connections'] === 0 &&
    $company3Only['pending_requests'] === 0 &&
    $company3Only['issues'] === [],
    'monitor filters by company without cross-tenant state');
try {
    $monitor->inspect(companyId: 0);
    throw new RuntimeException('FAIL invalid company ID accepted');
} catch (InvalidArgumentException $error) {
    healthCheck($error->getMessage() === 'invalid_bank_health_threshold',
        'health requires a positive company identifier');
}
foreach ([[0, 60], [24, 1], [169, 60]] as [$hours, $minutes]) {
    try {
        $monitor->inspect($hours, $minutes);
        throw new RuntimeException('FAIL invalid monitor limit accepted');
    } catch (InvalidArgumentException $e) {
        healthCheck($e->getMessage() === 'invalid_bank_health_threshold',
            'invalid monitoring threshold rejected');
    }
}
echo 'Read-only bank health monitoring contract passed in disposable SQLite.' . PHP_EOL;
