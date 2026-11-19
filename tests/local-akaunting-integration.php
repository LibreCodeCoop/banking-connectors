<?php
// SPDX-FileCopyrightText: 2026 LibreCode Coop and contributors
// SPDX-License-Identifier: AGPL-3.0-or-later
declare(strict_types=1);

/**
 * One-time/read-repeat integration fixture for the dedicated dedicated localhost
 * Akaunting installation. NEVER RUN THIS SCRIPT AGAINST SHARED DATABASES.
 */
if (getenv('BANKING_CONNECTORS_ISOLATED_LOCAL') !== 'yes') {
    throw new RuntimeException('Isolated local assertion missing');
}
$root = getenv('BANKING_CONNECTORS_APP_ROOT') ?: '';
if ($root === '' || !is_file($root . '/bootstrap/app.php')) {
    throw new RuntimeException('Missing isolated Akaunting root');
}
require $root . '/vendor/autoload.php';
$app = require $root . '/bootstrap/app.php';
$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use Illuminate\Support\Facades\DB;
use Modules\BankingConnectors\Application\ReviewPreview;
use Modules\BankingConnectors\Domain\BankEntry;
use Modules\BankingConnectors\Infrastructure\PdoEntryStage;

$conn = DB::connection();
$hostValues = array_merge(
    (array) config('database.connections.mysql.host', []),
    (array) config('database.connections.mysql.read.host', []),
    (array) config('database.connections.mysql.write.host', []),
);
$hostValues = array_values(array_filter($hostValues, is_string(...)));
if ($conn->getDatabaseName() !== 'akaunting_synthetic' ||
    $conn->getTablePrefix() !== 'ak_' ||
    $hostValues === [] || count(array_unique($hostValues)) !== 1 ||
    $hostValues[0] !== 'banksync-local-db' ||
    config('app.env') !== 'local') {
    throw new RuntimeException('Refusing to touch any database other than isolated localhost fixture');
}
$companyId = (int) DB::table('companies')->orderBy('id')->value('id');
$company = DB::table('companies')->where('id', $companyId)->first(['id']);
if (!$company) {
    throw new RuntimeException('Synthetic company not installed');
}

$account = DB::table('accounts')
    ->where('company_id', $companyId)
    ->where('name', 'Sicoob - Teste de Conciliação')->first(['id', 'company_id']);
if (!$account) {
    $accountId = DB::table('accounts')->insertGetId([
        'company_id' => $companyId,
        'type' => 'bank',
        'name' => 'Sicoob - Teste de Conciliação',
        'number' => 'SINTETICO-LOCAL-00001',
        'currency_code' => 'BRL',
        'opening_balance' => 0,
        'bank_name' => 'Sicoob (somente teste)',
        'enabled' => 1,
        'created_from' => 'banking-connectors-local-test',
        'created_at' => now(),
        'updated_at' => now(),
    ]);
} else {
    $accountId = (int) $account->id;
}

$fixture = [
    ['BANK-SUGGESTED', 'income', 10.00, '2026-11-17 11:00:00'],
    ['BANK-AMBIGUOUS-A', 'expense', 20.00, '2026-11-15 11:00:00'],
    ['BANK-AMBIGUOUS-B', 'expense', 20.00, '2026-11-15 10:00:00'],
];
foreach ($fixture as [$number, $type, $amount, $date]) {
    $exists = DB::table('transactions')->where('company_id', $companyId)
        ->where('account_id', $accountId)->where('number', $number)->exists();
    if ($exists) {
        continue;
    }
    DB::table('transactions')->insert([
        'company_id' => $companyId,
        'type' => $type,
        'number' => $number,
        'paid_at' => $date,
        'amount' => $amount,
        'currency_code' => 'BRL',
        'currency_rate' => 1,
        'account_id' => $accountId,
        'category_id' => 1,
        'payment_method' => 'offline',
        'parent_id' => 0,
        'created_from' => 'banking-connectors-local-test',
        'created_at' => now(),
        'updated_at' => now(),
    ]);
}

$credentialReference = "sicoob/company-{$companyId}/account-{$accountId}";
$connection = DB::table('lcc_banking_connectors_connections')->where('company_id', $companyId)
    ->where('account_id', $accountId)->where('provider', 'sicoob')
    ->first(['id', 'credential_reference']);
if (!$connection) {
    $connectionId = DB::table('lcc_banking_connectors_connections')->insertGetId([
        'company_id' => $companyId, 'account_id' => $accountId,
        'provider' => 'sicoob', 'credential_reference' => $credentialReference,
        'state' => 'read_only',
        'created_at' => now(), 'updated_at' => now(),
    ]);
} else {
    $connectionId = (int) $connection->id;
    if ($connection->credential_reference === null) {
        // Repair only the test-owned binding. Never overwrite configured
        // credentials, and never update a non-synthetic installation.
        DB::table('lcc_banking_connectors_connections')
            ->where('id', $connectionId)->where('company_id', $companyId)
            ->whereNull('credential_reference')
            ->update(['credential_reference' => $credentialReference]);
    } elseif ($connection->credential_reference !== $credentialReference) {
        throw new RuntimeException('Unexpected synthetic credential binding');
    }
}

$baseline = DB::table('transactions')->where('company_id', $companyId)
    ->where('account_id', $accountId)->count();
$events = [
    new BankEntry($companyId, $accountId, 'sicoob', 'LOCAL-CR-0001', '2026-11-17', 'credit', 1000),
    new BankEntry($companyId, $accountId, 'sicoob', 'LOCAL-DB-0002', '2026-11-15', 'debit', 2000),
    new BankEntry($companyId, $accountId, 'sicoob', 'LOCAL-DB-0003', '2026-11-12', 'debit', 3333),
    new BankEntry($companyId, $accountId, 'sicoob', 'LOCAL-CR-0004', '2026-11-13', 'credit', 4444),
];
$stage = app(PdoEntryStage::class);
$key = str_repeat('local-fixture-only-not-production-', 2);
$first = $stage->ingest($connectionId, $companyId, $accountId, 'sicoob', $events, $key);
$repeat = $stage->ingest($connectionId, $companyId, $accountId, 'sicoob', $events, $key);
$revision = $stage->ingest($connectionId, $companyId, $accountId, 'sicoob', [
    new BankEntry($companyId, $accountId, 'sicoob', 'LOCAL-CR-0004', '2026-11-13', 'credit', 4445),
], $key);

if ($first['inserted'] + $first['duplicates'] + $first['conflicts'] !== 4 ||
    $repeat['duplicates'] + $repeat['conflicts'] !== 4 ||
    $revision['conflicts'] !== 1) {
    throw new RuntimeException('Local stage idempotency or conflict quarantine failed');
}
$report = app(ReviewPreview::class)->forConnection($companyId, $accountId, $connectionId);
if ($report['summary'] !== ['suggested' => 1, 'needs_review' => 2, 'unmatched' => 1]) {
    throw new RuntimeException('Unexpected preview result: ' . json_encode($report['summary']));
}
$after = DB::table('transactions')->where('company_id', $companyId)
    ->where('account_id', $accountId)->count();
if ($baseline !== $after) {
    throw new RuntimeException('Preview unexpectedly modified native accounting transactions');
}

try {
    app(ReviewPreview::class)->forConnection($companyId + 1, $accountId, $connectionId);
    throw new RuntimeException('Cross-company preview was allowed');
} catch (DomainException $e) {
    if ($e->getMessage() !== 'connection_not_accessible') {
        throw $e;
    }
}
echo json_encode([
    'environment' => 'isolated_local_akaunting',
    'company_id' => $companyId,
    'account_id' => $accountId,
    'connection_id' => $connectionId,
    'staged_entries' => count($report['items']),
    'suggested' => $report['summary']['suggested'],
    'needs_review' => $report['summary']['needs_review'],
    'unmatched' => $report['summary']['unmatched'],
    'repeated_poll_is_idempotent' => true,
    'foreign_company_denied' => true,
    'native_financial_writes_during_preview' => 0,
], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL;
