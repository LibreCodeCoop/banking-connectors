<?php
// SPDX-FileCopyrightText: 2026 LibreCode Coop and contributors
// SPDX-License-Identifier: AGPL-3.0-or-later
declare(strict_types=1);

/**
 * Optional read-only live contract check; run only in POC test Docker container.
 * Does not expose credentials or transaction contents in output.
 */
require dirname(__DIR__) . '/Domain/BankEntry.php';
require dirname(__DIR__) . '/Contracts/BankStatementProvider.php';
require dirname(__DIR__) . '/Connectors/Sicoob/SicoobStatementMapper.php';
require dirname(__DIR__) . '/Connectors/Sicoob/SicoobProvider.php';
require dirname(__DIR__) . '/Contracts/BankTransportFailure.php';
require dirname(__DIR__) . '/Connectors/Sicoob/SicoobHttpFailure.php';
require dirname(__DIR__) . '/Connectors/Sicoob/SicoobHttpClient.php';
require dirname(__DIR__) . '/Domain/CategoryRuleEngine.php';
require dirname(__DIR__) . '/Infrastructure/PdoEntryStage.php';
require __DIR__ . '/Support/SqliteStageFixture.php';

$base = getenv('BANKING_CONNECTORS_POC_BASE');
if ($base === false || !is_dir($base)) {
    fwrite(STDERR, "BANKING_CONNECTORS_POC_BASE must point to the existing Sicoob POC mount\n");
    exit(2);
}
// Use the maintained module client; the POC supplies only existing credentials.
$configPath = $base . '/secrets/config.production.env';
if (!is_readable($configPath)) {
    fwrite(STDERR, "Production POC config unavailable\n");
    exit(2);
}
$config = [];
foreach (file($configPath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
    if (str_starts_with(trim($line), '#') || !str_contains($line, '=')) {
        continue;
    }
    [$k, $v] = explode('=', $line, 2);
    $config[trim($k)] = trim($v);
}
if (($config['MODE'] ?? null) !== 'production') {
    throw new RuntimeException('Live check is production read-only only');
}
$client = new \Modules\BankingConnectors\Connectors\Sicoob\SicoobHttpClient($config);
try {
    $provider = new \Modules\BankingConnectors\Connectors\Sicoob\SicoobProvider(
        \Closure::fromCallable([$client, 'statement']),
        new \Modules\BankingConnectors\Connectors\Sicoob\SicoobStatementMapper(),
        1,
        9,
    );
    // Crossing-month statement tests the provider contract and Sicoob transport.
    $entries = $provider->fetch(1, 9, new \DateTimeImmutable('2026-10-31'), new \DateTimeImmutable('2026-11-17'));
    $secret = str_repeat('live-contract-ephemeral-only-', 2);
    $ids = [];
    foreach ($entries as $entry) {
        $ids[] = $entry->fingerprint($secret);
    }
    // All stage writes are in an ephemeral SQLite in-memory database.
    // Akaunting production is not connected or modified by this process.
    $stageDb = new \PDO('sqlite::memory:', null, null, [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION]);
    \Modules\BankingConnectors\Tests\Support\SqliteStageFixture::install($stageDb);
    \Modules\BankingConnectors\Tests\Support\SqliteStageFixture::connection($stageDb, 1, 1, 9, 'sicoob');
    $stage = new \Modules\BankingConnectors\Infrastructure\PdoEntryStage($stageDb);
    $initial = $stage->ingest(1, 1, 9, 'sicoob', $entries, $secret);
    $repeat = $stage->ingest(1, 1, 9, 'sicoob', $entries, $secret);
    $stagedCount = (int)$stageDb->query('SELECT COUNT(*) FROM lcc_banking_connectors_entries')->fetchColumn();
    if ($initial['inserted'] !== count($entries) || $repeat['duplicates'] !== count($entries) ||
        $initial['conflicts'] !== 0 || $repeat['inserted'] !== 0 || $stagedCount !== count($entries)) {
        throw new \RuntimeException('unexpected_staging_result');
    }
    echo json_encode([
        'read_only' => true,
        'period' => '2026-10-31..2026-11-17',
        'accepted' => count($entries),
        'rejected' => 0,
        'unique_bank_identities' => count(array_unique($ids)),
        'ephemeral_stage_inserted' => $initial['inserted'],
        'repeat_stage_duplicates' => $repeat['duplicates'],
        'ephemeral_stage_records' => $stagedCount,
        'akaunting_writes' => 0,
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL;
} catch (Throwable $e) {
    // Do not disclose error bodies or configuration on failure.
    fwrite(STDERR, "Live bank contract failed: " . get_class($e) . "\n");
    exit(1);
}
