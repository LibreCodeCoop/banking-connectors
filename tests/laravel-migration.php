<?php
// SPDX-FileCopyrightText: 2026 LibreCode Coop and contributors
// SPDX-License-Identifier: AGPL-3.0-or-later
declare(strict_types=1);

/**
 * Execute the actual Laravel migration against a private in-memory SQLite DB.
 * Mount only a read-only Akaunting framework/vendor tree, never app secrets.
 */
$autoload = getenv('BANKING_CONNECTORS_FRAMEWORK_AUTOLOAD') ?: '';
if ($autoload === '' || !is_file($autoload)) {
    fwrite(STDERR, "Framework autoload path required for isolated migration test\n");
    exit(2);
}
require $autoload;
require dirname(__DIR__) . '/Domain/BankEntry.php';
require dirname(__DIR__) . '/Domain/CategoryRuleEngine.php';
require dirname(__DIR__) . '/Infrastructure/PdoEntryStage.php';

use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Support\Facades\Facade;
use Illuminate\Support\Facades\Schema;
use Modules\BankingConnectors\Domain\BankEntry;
use Modules\BankingConnectors\Infrastructure\PdoEntryStage;

$capsule = new Capsule();
$capsule->addConnection(['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '']);
$capsule->setAsGlobal();
$capsule->bootEloquent();
Facade::setFacadeApplication($capsule->getContainer());
$capsule->getContainer()->instance('db.schema', $capsule->schema());

$pdo = $capsule->getConnection()->getPdo();
$pdo->exec('PRAGMA foreign_keys = ON');
$migration = require dirname(__DIR__) . '/Database/Migrations/2026_11_17_140000_create_banking_connectors_staging.php';
$migration->up();
// A prior-version installation can already contain bank evidence before
// these additive migrations. It must survive upgrading the module.
$capsule->getConnection()->table('lcc_banking_connectors_connections')->insert([
    'id' => 1, 'company_id' => 1, 'account_id' => 9,
    'provider' => 'sicoob', 'state' => 'read_only',
]);
$upgradeSecret = str_repeat('upgrade-test-only-no-production-', 2);
$oldMovement = new BankEntry(
    1, 9, 'sicoob', 'pre-upgrade-bank-id', '2026-11-16', 'credit', 111
);
$oldFingerprint = $oldMovement->fingerprint($upgradeSecret);
// Simulate a movement inserted by the pre-upgrade version of the module,
// before category columns and rule tables existed.
$oldContent = hash_hmac('sha256',
    'normalized-entry-v1|2026-11-16|credit|111|BRL', $upgradeSecret);
$pdo->prepare('INSERT INTO lcc_banking_connectors_entries
    (connection_id,company_id,account_id,provider,source_fingerprint,
     content_fingerprint,movement_date,direction,amount_cents,currency,state,
     conflict_count,first_seen_at,last_seen_at,created_at,updated_at)
    VALUES (1,1,9,?, ?,?,"2026-11-16","credit",111,"BRL","pending",0,
            "2026-11-16 11:00:00","2026-11-16 11:00:00",
            "2026-11-16 11:00:00","2026-11-16 11:00:00")')
    ->execute(['sicoob',$oldFingerprint,$oldContent]);
$reviewMigration = require dirname(__DIR__) . '/Database/Migrations/2026_11_17_170000_create_banking_connectors_review_events.php';
$reviewMigration->up();
$keyMigration = require dirname(__DIR__) . '/Database/Migrations/2026_11_17_180000_guard_banking_connectors_fingerprint_key.php';
$keyMigration->up();
$opsMigration = require dirname(__DIR__) . '/Database/Migrations/2026_11_17_190000_add_banking_connectors_operation_state.php';
$opsMigration->up();
$healthMigration = require dirname(__DIR__) . '/Database/Migrations/2026_11_18_120000_add_banking_connectors_credential_health.php';
$healthMigration->up();
$categoryMigration = require dirname(__DIR__) . '/Database/Migrations/2026_11_19_150000_add_banking_connectors_classification.php';
$categoryMigration->up();
$nativePublishMigration = require dirname(__DIR__) . '/Database/Migrations/2026_11_20_110000_add_native_bank_publication.php';
$nativePublishMigration->up();
$nativeIssueMigration = require dirname(__DIR__) . '/Database/Migrations/2026_11_20_113000_add_native_publication_issue_state.php';
$nativeIssueMigration->up();
$activationMigration = require dirname(__DIR__) . '/Database/Migrations/2026_11_20_120000_fence_native_posting_activation.php';
$activationMigration->up();
// Akaunting owns this native table; create only its minimal shape in the
// disposable in-memory migration fixture, never in the module migration.
$pdo->exec('CREATE TABLE categories (id INTEGER PRIMARY KEY,company_id INT,
    type VARCHAR(20),enabled INT,deleted_at DATETIME)');
foreach (['lcc_banking_connectors_connections', 'lcc_banking_connectors_entries', 'lcc_banking_connectors_review_events',
         'lcc_banking_connectors_category_rules','lcc_banking_connectors_category_events',
         'lcc_banking_connectors_native_publications'] as $table) {
    if (!Schema::hasTable($table)) {
        throw new RuntimeException('Migration did not create ' . $table);
    }
}
if (!Schema::hasColumn('lcc_banking_connectors_connections', 'poll_requested_at') ||
    !Schema::hasColumn('lcc_banking_connectors_connections', 'next_retry_at')) {
    throw new RuntimeException('Operation state migration incomplete');
}
if (!Schema::hasColumn('lcc_banking_connectors_connections','native_post_after_entry_id') ||
    !Schema::hasColumn('lcc_banking_connectors_connections','native_post_activated_at')) {
    throw new RuntimeException('Native posting activation fences missing');
}
if (!Schema::hasColumn('lcc_banking_connectors_entries', 'publication_issue_code') ||
    !Schema::hasColumn('lcc_banking_connectors_entries', 'publication_failures')) {
    throw new RuntimeException('Native publishing incident schema missing after upgrade');
}
if (!Schema::hasColumn('lcc_banking_connectors_connections', 'native_post_enabled') ||
    $capsule->getConnection()->table('lcc_banking_connectors_connections')
        ->where('id',1)->value('native_post_enabled') != 0) {
    throw new RuntimeException('Native publishing must remain disabled for upgrades');
}
if (!Schema::hasColumn('lcc_banking_connectors_connections', 'certificate_valid_to') ||
    !Schema::hasColumn('lcc_banking_connectors_connections', 'credentials_checked_at')) {
    throw new RuntimeException('Credential health migration incomplete');
}
if (!Schema::hasColumn('lcc_banking_connectors_connections', 'fingerprint_key_commitment')) {
    throw new RuntimeException('Key commitment migration did not add its guard');
}
$legacy = $capsule->getConnection()->table('lcc_banking_connectors_entries')
    ->where('source_fingerprint', $oldFingerprint)->first();
if ($legacy === null ||
    $capsule->getConnection()->table('lcc_banking_connectors_entries')->count() !== 1 ||
    $capsule->getConnection()->table('lcc_banking_connectors_connections')
        ->where('id', 1)->value('poll_requested_at') !== null) {
    throw new RuntimeException('Existing staged bank movements lost across schema upgrade');
}
echo 'PASS existing bank entries and connection survived additive migration upgrade' . PHP_EOL;
echo 'PASS all real Laravel migrations applied with review audit, operations and HMAC key guard' . PHP_EOL;

$store = new PdoEntryStage($pdo);
$secret = str_repeat('non-production-migration-key-', 2);
$movement = new BankEntry(1, 9, 'sicoob', 'test-laravel-0001', '2026-11-17', 'credit', 123);
$first = $store->ingest(1, 1, 9, 'sicoob', [$movement], $secret);
$second = $store->ingest(1, 1, 9, 'sicoob', [$movement], $secret);
if ($first['inserted'] !== 1 || $second['duplicates'] !== 1 ||
    $capsule->getConnection()->table('lcc_banking_connectors_entries')->count() !== 2) {
    throw new RuntimeException('Staging incompatible with actual Laravel SQLite migration');
}
echo 'PASS PDO storage works against the actual Laravel migration schema' . PHP_EOL;

$activationMigration->down();
$nativeIssueMigration->down();
$nativePublishMigration->down();
$categoryMigration->down();
$healthMigration->down();
$opsMigration->down();
$keyMigration->down();
$reviewMigration->down();
$migration->down();
if (!Schema::hasTable('lcc_banking_connectors_entries') ||
    !Schema::hasTable('lcc_banking_connectors_review_events')) {
    throw new RuntimeException('Migration rollback removed bank evidence unexpectedly');
}
echo 'PASS automatic rollback preserves banking evidence' . PHP_EOL;
echo 'Laravel migration smoke passed; isolated, no Akaunting production writes.' . PHP_EOL;
