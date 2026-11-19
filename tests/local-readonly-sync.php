<?php
// SPDX-FileCopyrightText: 2026 LibreCode Coop and contributors
// SPDX-License-Identifier: AGPL-3.0-or-later
declare(strict_types=1);

// Test module cursor against actual Akaunting 3 prefixed tables in local-test only.
if (getenv('BANKING_CONNECTORS_ISOLATED_LOCAL') !== 'yes') { throw new RuntimeException('Isolated only'); }
$root = getenv('BANKING_CONNECTORS_APP_ROOT') ?: '';
if ($root === '' || !is_file($root . '/bootstrap/app.php')) { throw new RuntimeException('Missing test root'); }
require $root . '/vendor/autoload.php';
$app = require $root . '/bootstrap/app.php';
$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use Illuminate\Support\Facades\DB;
use Modules\BankingConnectors\Application\ReadOnlySynchronizer;
use Modules\BankingConnectors\Contracts\BankStatementProvider;
use Modules\BankingConnectors\Domain\BankEntry;

$db = DB::connection();
$hosts = array_merge(
    (array) config('database.connections.mysql.host', []),
    (array) config('database.connections.mysql.read.host', []),
    (array) config('database.connections.mysql.write.host', []),
);
$hosts = array_values(array_filter($hosts, is_string(...)));
if ($db->getDatabaseName() !== 'akaunting_synthetic' || $db->getTablePrefix() !== 'ak_' ||
    $hosts === [] || count(array_unique($hosts)) !== 1 ||
    $hosts[0] !== 'banksync-local-db' || config('app.env') !== 'local') {
    throw new RuntimeException('Refusing any environment other than dedicated localhost fixture');
}
$companyId = 2;
$account = DB::table('accounts')->where('company_id', $companyId)
    ->where('name', 'ZZ LOCAL CURSOR TEST')->first(['id']);
if (!$account) {
    $accountId = DB::table('accounts')->insertGetId([
        'company_id' => $companyId, 'type' => 'bank',
        'name' => 'ZZ LOCAL CURSOR TEST', 'number' => 'SYNTHETIC-NO-REAL-ACCOUNT',
        'currency_code' => 'BRL', 'opening_balance' => 0,
        'enabled' => 1, 'created_from' => 'banking-connectors-local-only',
        'created_at' => now(), 'updated_at' => now(),
    ]);
} else {
    $accountId = (int) $account->id;
}
$connection = DB::table('lcc_banking_connectors_connections')
    ->where('company_id', $companyId)->where('account_id', $accountId)
    ->where('provider', 'synthetic')->first(['id']);
if (!$connection) {
    $connectionId = DB::table('lcc_banking_connectors_connections')->insertGetId([
        'company_id' => $companyId, 'account_id' => $accountId,
        'provider' => 'synthetic', 'state' => 'read_only',
        'created_at' => now(), 'updated_at' => now(),
    ]);
} else {
    $connectionId = (int) $connection->id;
}
$provider = new class($companyId, $accountId) implements BankStatementProvider {
    public function __construct(private int $company, private int $account) {}
    public function fetch(int $companyId, int $accountId, DateTimeImmutable $from, DateTimeImmutable $until): array
    {
        if ($companyId !== $this->company || $accountId !== $this->account) {
            throw new DomainException('scope_mismatch');
        }
        return [new BankEntry(
            companyId: $companyId, accountId: $accountId,
            provider: 'synthetic', externalId: 'CURSOR-local-test-20261117',
            date: '2026-11-17', direction: 'credit', amountCents: 99,
        )];
    }
};
$secret = str_repeat('local-only-stable-hmac-key-', 2);
// This isolated test fixture predates the key-binding migration. Adopt its
// key ONLY after confirming every persisted source fingerprint against the
// known synthetic source ID. Production migrations never perform this step.
$prior = DB::table('lcc_banking_connectors_entries')->where('connection_id', $connectionId)
    ->get(['source_fingerprint']);
if ($prior->isNotEmpty()) {
    $expected = (new BankEntry(
        $companyId, $accountId, 'synthetic', 'CURSOR-local-test-20261117',
        '2026-11-17', 'credit', 99
    ))->fingerprint($secret);
    if ($prior->count() !== 1 || !hash_equals($expected, $prior->first()->source_fingerprint)) {
        throw new RuntimeException('Cannot bind unverified synthetic staged bank IDs');
    }
    DB::table('lcc_banking_connectors_connections')
        ->where('id', $connectionId)->whereNull('fingerprint_key_commitment')
        ->update([
            'fingerprint_key_commitment' => hash_hmac(
                'sha256', 'banking-connectors-stable-key-binding-v1', $secret
            ),
        ]);
}
$before = (int) DB::table('transactions')
    ->where('company_id', $companyId)->where('account_id', $accountId)->count();
$sync = app(ReadOnlySynchronizer::class);
$first = $sync->synchronize(
    $connectionId, $companyId, $accountId, 'synthetic',
    $provider, $secret, new DateTimeImmutable('2026-11-17'), new DateTimeImmutable('2026-11-17')
);
$repeat = $sync->synchronize(
    $connectionId, $companyId, $accountId, 'synthetic',
    $provider, $secret, new DateTimeImmutable('2026-11-17'), new DateTimeImmutable('2026-11-17')
);
if ($first['inserted'] + $first['duplicates'] !== 1 ||
    $repeat['duplicates'] !== 1 || $repeat['inserted'] !== 0) {
    throw new RuntimeException('Local prefixed cursor is not idempotent');
}
$through = DB::table('lcc_banking_connectors_connections')
    ->where('id', $connectionId)->value('cursor_date');
$after = (int) DB::table('transactions')
    ->where('company_id', $companyId)->where('account_id', $accountId)->count();
if ((string) $through !== '2026-11-17' || $before !== $after) {
    throw new RuntimeException('Invalid local cursor or native financial mutation');
}
echo json_encode([
    'environment' => 'isolated_local',
    'native_table_prefix' => $db->getTablePrefix(),
    'first_poll' => $first, 'second_poll' => $repeat,
    'native_financial_writes' => $after - $before,
    'cursor' => (string) $through,
], JSON_PRETTY_PRINT) . PHP_EOL;
