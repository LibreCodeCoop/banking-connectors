<?php
// SPDX-FileCopyrightText: 2026 LibreCode Coop and contributors
// SPDX-License-Identifier: AGPL-3.0-or-later
declare(strict_types=1);

require dirname(__DIR__) . '/Domain/BankEntry.php';
require dirname(__DIR__) . '/Contracts/BankStatementProvider.php';
require dirname(__DIR__) . '/Connectors/Sicoob/SicoobMovementDetails.php';
require dirname(__DIR__) . '/Connectors/Sicoob/SicoobStatementMapper.php';
require dirname(__DIR__) . '/Connectors/Sicoob/SicoobProvider.php';
require dirname(__DIR__) . '/Contracts/BankTransportFailure.php';
require dirname(__DIR__) . '/Connectors/Sicoob/SicoobHttpFailure.php';
require dirname(__DIR__) . '/Domain/CategoryRuleEngine.php';
require dirname(__DIR__) . '/Infrastructure/PdoEntryStage.php';
require dirname(__DIR__) . '/Application/ReadOnlySynchronizer.php';
require __DIR__ . '/Support/SqliteStageFixture.php';

use Modules\BankingConnectors\Application\ReadOnlySynchronizer;
use Modules\BankingConnectors\Contracts\BankStatementProvider;
use Modules\BankingConnectors\Domain\BankEntry;
use Modules\BankingConnectors\Infrastructure\PdoEntryStage;
use Modules\BankingConnectors\Connectors\Sicoob\SicoobProvider;
use Modules\BankingConnectors\Connectors\Sicoob\SicoobHttpFailure;
use Modules\BankingConnectors\Connectors\Sicoob\SicoobStatementMapper;
use Modules\BankingConnectors\Tests\Support\SqliteStageFixture;

function checkSync(bool $condition, string $name): void {
    if (!$condition) {
        throw new RuntimeException('FAIL ' . $name);
    }
    echo 'PASS ' . $name . PHP_EOL;
}
$database = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
SqliteStageFixture::install($database);
SqliteStageFixture::connection($database, 1, 1, 9, 'sicoob');
SqliteStageFixture::connection($database, 2, 2, 9, 'sicoob');
SqliteStageFixture::connection($database, 3, 1, 7, 'sicoob', 'paused');
$stage = new PdoEntryStage($database);
$sync = new ReadOnlySynchronizer($database, $stage);
$secret = str_repeat('stable-synthetic-secret-for-tests-', 2);

$calls = [];
$reader = static function (int $year, int $month, int $start, int $end) use (&$calls): array {
    $calls[] = sprintf('%04d-%02d-%02d..%02d', $year, $month, $start, $end);
    return ['resultado' => ['transacoes' => [[
        'transactionId' => sprintf('sicoob-event-%04d-%02d-%02d', $year, $month, $start),
        'tipo' => $start % 2 ? 'C' : 'D',
        'valor' => '1.00',
        'data' => sprintf('%04d-%02d-%02d', $year, $month, $start),
    ]]]];
};
$provider = new SicoobProvider($reader, new SicoobStatementMapper(), 1, 9);
$begin = new DateTimeImmutable('2026-10-29');
$finish = new DateTimeImmutable('2026-12-03');
$first = $sync->synchronize(1, 1, 9, 'sicoob', $provider, $secret, $begin, $finish);
checkSync($first === [
    'windows' => 2, 'inserted' => 4, 'duplicates' => 0,
    'conflicts' => 0, 'through' => '2026-12-03',
], 'splits 36-day period into two staging windows and advances cursor');
checkSync($calls === [
    '2026-10-29..31', '2026-11-01..25', '2026-11-26..30', '2026-12-01..03',
], 'Sicoob API calls stay inside individual months');
checkSync($database->query("SELECT cursor_date FROM lcc_banking_connectors_connections WHERE id=1")->fetchColumn() === '2026-12-03',
    'persisted cursor tracks last fully fetched day');

$again = $sync->synchronize(1, 1, 9, 'sicoob', $provider, $secret, $begin, $finish);
checkSync($again['inserted'] === 0 && $again['duplicates'] === 4 && $again['windows'] === 2,
    'overlapping backfill is idempotent even with same-second timestamps');
checkSync((int) $database->query('SELECT COUNT(*) FROM lcc_banking_connectors_entries')->fetchColumn() === 4,
    'replay retains original four movements');
$binding = (string) $database->query(
    'SELECT fingerprint_key_commitment FROM lcc_banking_connectors_connections WHERE id=1'
)->fetchColumn();
checkSync(strlen($binding) === 64 && $binding !== $secret,
    'connection stores only a one-way fingerprint-key commitment');
$beforeRotation = count($calls);
try {
    $sync->synchronize(1, 1, 9, 'sicoob', $provider,
        str_repeat('different-key-invalid-rotation-', 2), $begin, $finish);
    throw new RuntimeException('FAIL accidental fingerprint key rotation accepted');
} catch (DomainException $e) {
    checkSync($e->getMessage() === 'fingerprint_key_changed_requires_migration' &&
        count($calls) === $beforeRotation,
        'accidental key rotation is denied before any bank call');
}
SqliteStageFixture::connection($database, 4, 1, 8, 'sicoob');
$stage->ingest(4, 1, 8, 'sicoob', [
    new BankEntry(1, 8, 'sicoob', 'preexisting-identity', '2026-11-16', 'credit', 1700),
], $secret);
$unbound = new SicoobProvider($reader, new SicoobStatementMapper(), 1, 8);
try {
    $sync->synchronize(4, 1, 8, 'sicoob', $unbound, $secret,
        new DateTimeImmutable('2026-11-16'), new DateTimeImmutable('2026-11-16'));
    throw new RuntimeException('FAIL unbound existing IDs accepted');
} catch (DomainException $e) {
    checkSync($e->getMessage() === 'fingerprint_key_binding_requires_migration',
        'legacy staged rows cannot silently adopt an unverified key');
}

try {
    $sync->synchronize(1, 2, 9, 'sicoob', $provider, $secret, $begin, $finish);
    throw new RuntimeException('FAIL unrelated company was not denied');
} catch (DomainException $e) {
    checkSync($e->getMessage() === 'connection_not_accessible', 'wrong tenant denied before network fetch');
}
try {
    $sync->synchronize(3, 1, 7, 'sicoob',
        new SicoobProvider($reader, new SicoobStatementMapper(), 1, 7),
        $secret, $begin, $finish);
    throw new RuntimeException('FAIL paused connection was not denied');
} catch (DomainException $e) {
    checkSync($e->getMessage() === 'connection_not_accessible', 'paused connection denied');
}
try {
    $sync->synchronize(1, 1, 9, 'sicoob', $provider, $secret,
        new DateTimeImmutable('2026-12-06'), new DateTimeImmutable('2026-12-07'));
    throw new RuntimeException('FAIL gap accepted');
} catch (DomainException $e) {
    checkSync($e->getMessage() === 'sync_window_would_skip_days', 'cursor cannot skip unprocessed days');
}

$attempts = 0;
$flaky = new SicoobProvider(
    static function (int $year, int $month, int $start, int $end) use (&$attempts): array {
        ++$attempts;
        if ($attempts === 2) {
            throw new RuntimeException('secret-should-never-be-stored');
        }
        return ['resultado' => ['transacoes' => [[
            'transactionId' => "failure-fixture-$month-$start", 'tipo' => 'C',
            'valor' => '2.00', 'data' => sprintf('%04d-%02d-%02d', $year, $month, $start),
        ]]]];
    },
    new SicoobStatementMapper(), 2, 9
);
try {
    $sync->synchronize(2, 2, 9, 'sicoob', $flaky, $secret,
        new DateTimeImmutable('2026-10-30'), new DateTimeImmutable('2026-12-01'));
    throw new RuntimeException('FAIL provider failure was not handled');
} catch (RuntimeException $e) {
    checkSync($e->getMessage() === 'banking_connectors_window_failed',
        'remote provider failure reported via a stable safe code');
}
checkSync($database->query('SELECT cursor_date FROM lcc_banking_connectors_connections WHERE id=2')->fetchColumn() === false ||
    $database->query('SELECT cursor_date FROM lcc_banking_connectors_connections WHERE id=2')->fetchColumn() === null,
    'partially fetched window does not advance cursor');
checkSync($database->query('SELECT last_error_code FROM lcc_banking_connectors_connections WHERE id=2')->fetchColumn() === 'bank_read_or_stage_failed',
    'raw exception message not stored in status');

$fixed = new SicoobProvider(
    static fn(int $year, int $month, int $start, int $end): array =>
        ['resultado' => ['transacoes' => [[
            'transactionId' => "failure-fixture-$month-$start", 'tipo' => 'C',
            'valor' => '2.00', 'data' => sprintf('%04d-%02d-%02d', $year, $month, $start),
        ]]]],
    new SicoobStatementMapper(), 2, 9
);
$retry = $sync->synchronize(2, 2, 9, 'sicoob', $fixed, $secret,
    new DateTimeImmutable('2026-10-30'), new DateTimeImmutable('2026-12-01'));
checkSync($retry['windows'] === 2 && $retry['through'] === '2026-12-01' &&
    $retry['inserted'] > 0 && $retry['conflicts'] === 0,
    'successful retry advances cursor with complete window');
checkSync($database->query('SELECT last_error_code FROM lcc_banking_connectors_connections WHERE id=2')->fetchColumn() === null,
    'successful fetch clears only safe error status');

$bad = new SicoobProvider(
    static fn(int $year, int $month, int $start, int $end): array =>
        ['resultado' => ['transacoes' => [['tipo' => 'INVALID', 'valor' => '1.00']]]],
    new SicoobStatementMapper(), 1, 9
);
try {
    $sync->synchronize(1, 1, 9, 'sicoob', $bad, $secret,
        new DateTimeImmutable('2026-12-03'), new DateTimeImmutable('2026-12-04'));
    throw new RuntimeException('FAIL invalid statement accepted');
} catch (RuntimeException $e) {
    checkSync($e->getMessage() === 'banking_connectors_window_failed', 'incomplete bank response cannot advance state');
}
checkSync($database->query('SELECT cursor_date FROM lcc_banking_connectors_connections WHERE id=1')->fetchColumn() === '2026-12-03',
    'incomplete bank statement leaves cursor at last safe date');
checkSync($database->query('SELECT last_error_code FROM lcc_banking_connectors_connections WHERE id=1')->fetchColumn() === 'bank_statement_requires_review',
    'rejected bank payload has a safe status visible to review');
SqliteStageFixture::connection($database, 5, 2, 10, 'sicoob');
$rateLimited = new SicoobProvider(
    static function (): array {
        throw new SicoobHttpFailure(429, '120');
    },
    new SicoobStatementMapper(), 2, 10
);
try {
    $sync->synchronize(5, 2, 10, 'sicoob', $rateLimited, $secret,
        new DateTimeImmutable('2026-11-01'), new DateTimeImmutable('2026-11-03'));
    throw new RuntimeException('FAIL bank 429 hidden by success');
} catch (SicoobHttpFailure $e) {
    checkSync($e->safeCode === 'bank_api_rate_limited' && $e->retryAfterSeconds === 120,
        'bank 429 passes sanitized retry metadata through synchronization boundary');
}
checkSync($database->query('SELECT last_error_code FROM lcc_banking_connectors_connections WHERE id=5')->fetchColumn()
    === 'bank_api_rate_limited' &&
    $database->query('SELECT cursor_date FROM lcc_banking_connectors_connections WHERE id=5')->fetchColumn() === null,
    'bank rate-limit failure does not advance cursor and persists safe error');
echo 'Read-only cursor tests finished; disposable SQLite, zero accounting writes.' . PHP_EOL;
