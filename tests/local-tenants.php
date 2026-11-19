<?php
// SPDX-FileCopyrightText: 2026 LibreCode Coop and contributors
// SPDX-License-Identifier: AGPL-3.0-or-later
declare(strict_types=1);

// Creates synthetic second-company and restricted-user test fixtures ONLY.
if (getenv('BANKING_CONNECTORS_ISOLATED_LOCAL') !== 'yes') {
    throw new RuntimeException('Not an isolated local-test session');
}
$root = getenv('BANKING_CONNECTORS_APP_ROOT') ?: '';
require $root . '/vendor/autoload.php';
$app = require $root . '/bootstrap/app.php';
$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Models\Common\Company;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Modules\BankingConnectors\Domain\BankEntry;
use Modules\BankingConnectors\Infrastructure\PdoEntryStage;

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
    throw new RuntimeException('Refusing any non-synthetic database');
}
$secondPassword = getenv('BANKING_CONNECTORS_SECOND_PASSWORD') ?: '';
$restrictedPassword = getenv('BANKING_CONNECTORS_RESTRICTED_PASSWORD') ?: '';
if (strlen($secondPassword) < 24 || strlen($restrictedPassword) < 24) {
    throw new RuntimeException('Missing synthetic test passwords');
}
$third = DB::table('companies')->where('domain', 'banking-connectors-local-company3.invalid')->first(['id']);
if (!$third) {
    $thirdId = DB::table('companies')->insertGetId([
        'domain' => 'banking-connectors-local-company3.invalid', 'enabled' => 1,
        'created_from' => 'banking-connectors-local-test',
        'created_at' => now(), 'updated_at' => now(),
    ]);
    // Canonical seeds, but only against our database; no Akaunting source edited.
    Artisan::call('company:seed', ['company' => $thirdId]);
} else {
    $thirdId = (int) $third->id;
}
// The target company has its own settings. This fixture inserts only
// synthetic defaults when native seeds did not populate them, avoiding
// copying any other company's financial IDs or external-service settings.
if (!DB::table('settings')->where('company_id', $thirdId)->exists()) {
    $defaultAccount = (string) DB::table('accounts')->where('company_id', $thirdId)
        ->orderBy('id')->value('id');
    $incomeCat = (string) DB::table('categories')->where('company_id', $thirdId)
        ->where('type', 'income')->orderBy('id')->value('id');
    $expenseCat = (string) DB::table('categories')->where('company_id', $thirdId)
        ->where('type', 'expense')->orderBy('id')->value('id');
    $defaults = [
        'company.name' => 'Empresa 3 QA Banking Connectors',
        'company.email' => 'company3@localhost.invalid',
        'wizard.completed' => '1',
        'default.locale' => 'pt-BR',
        'default.account' => $defaultAccount,
        'default.income_category' => $incomeCat,
        'default.expense_category' => $expenseCat,
        'invoice.title' => 'Fatura',
        'offline-payments.methods' => '[]',
    ];
    foreach ($defaults as $key => $value) {
        DB::table('settings')->insert([
            'company_id' => $thirdId, 'key' => $key, 'value' => $value,
        ]);
    }
}

function syntheticUser(string $email, string $password, int $company, int $role): int
{
    $existing = DB::table('users')->where('email', $email)->first(['id']);
    if ($existing) {
        return (int) $existing->id;
    }
    $id = DB::table('users')->insertGetId([
        'name' => 'Synthetic test account',
        'email' => $email,
        'password' => Hash::make($password),
        'locale' => 'pt-BR', 'landing_page' => 'dashboard',
        'enabled' => 1, 'created_from' => 'banking-connectors-local-test',
        'created_at' => now(), 'updated_at' => now(),
    ]);
    DB::table('user_companies')->insert([
        'user_id' => $id, 'company_id' => $company,
    ]);
    DB::table('user_roles')->insert([
        'user_id' => $id, 'role_id' => $role,
    ]);
    return $id;
}
$company3AdminId = syntheticUser(
    'banking-connectors-company3@localhost.invalid', $secondPassword, $thirdId, 1
);
$restrictedId = syntheticUser(
    'banking-connectors-restricted@localhost.invalid', $restrictedPassword, 2, 3
);
$thirdAccount = DB::table('accounts')->where('company_id', $thirdId)
    ->where('name', 'SICOOB - Empresa 3 sintética')->first(['id']);
if (!$thirdAccount) {
    $accountId = DB::table('accounts')->insertGetId([
        'company_id' => $thirdId, 'type' => 'bank',
        'name' => 'SICOOB - Empresa 3 sintética',
        'number' => 'LOCAL-OTHER-COMPANY-NO-REAL-ACCOUNT',
        'currency_code' => 'BRL', 'opening_balance' => 0,
        'enabled' => 1, 'created_from' => 'banking-connectors-local-test',
        'created_at' => now(), 'updated_at' => now(),
    ]);
} else {
    $accountId = (int) $thirdAccount->id;
}
$conn = DB::table('lcc_banking_connectors_connections')
    ->where('company_id', $thirdId)->where('account_id', $accountId)
    ->where('provider', 'sicoob')->first(['id']);
if (!$conn) {
    $connectionId = DB::table('lcc_banking_connectors_connections')->insertGetId([
        'company_id' => $thirdId, 'account_id' => $accountId,
        'provider' => 'sicoob', 'state' => 'read_only',
        'created_at' => now(), 'updated_at' => now(),
    ]);
} else {
    $connectionId = (int) $conn->id;
}
$entry = new BankEntry($thirdId, $accountId, 'sicoob',
    'LOCAL-CR-0001', '2026-11-17', 'credit', 1000);
$counts = app(PdoEntryStage::class)->ingest(
    $connectionId, $thirdId, $accountId, 'sicoob', [$entry],
    str_repeat('local-fixture-only-not-production-', 2)
);
if ($counts['inserted'] + $counts['duplicates'] !== 1) {
    throw new RuntimeException('Unable to stage second-company fixture');
}
echo json_encode([
    'isolated' => true,
    'other_company_id' => $thirdId,
    'other_company_admin_id' => $company3AdminId,
    'restricted_company2_user_id' => $restrictedId,
    'other_company_staged_rows' => 1,
], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . PHP_EOL;
