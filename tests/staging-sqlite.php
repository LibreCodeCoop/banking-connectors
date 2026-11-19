<?php
// SPDX-FileCopyrightText: 2026 LibreCode Coop and contributors
// SPDX-License-Identifier: AGPL-3.0-or-later
declare(strict_types=1);

require dirname(__DIR__) . '/Domain/BankEntry.php';
require dirname(__DIR__) . '/Domain/CategoryRuleEngine.php';
require dirname(__DIR__) . '/Infrastructure/PdoEntryStage.php';
require __DIR__ . '/Support/SqliteStageFixture.php';

use Modules\BankingConnectors\Domain\BankEntry;
use Modules\BankingConnectors\Infrastructure\PdoEntryStage;
use Modules\BankingConnectors\Tests\Support\SqliteStageFixture;

function assertStage(bool $ok, string $description): void {
    if (!$ok) {
        throw new RuntimeException('FAIL ' . $description);
    }
    echo 'PASS ' . $description . PHP_EOL;
}

$path = tempnam(sys_get_temp_dir(), 'lcc-bank-stage-');
if (!is_string($path)) {
    throw new RuntimeException('Unable to allocate isolated database');
}
$secret = str_repeat('test-not-a-production-key-', 2);
try {
    $db = new PDO('sqlite:' . $path, null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    SqliteStageFixture::install($db);
    SqliteStageFixture::connection($db, 1, 1, 9, 'sicoob');
    SqliteStageFixture::connection($db, 2, 2, 9, 'sicoob');
    SqliteStageFixture::connection($db, 3, 1, 5, 'sicoob', 'paused');
    $store = new PdoEntryStage($db);
    $entry1 = new BankEntry(1, 9, 'sicoob', 'btx-100', '2026-11-12', 'credit', 100);
    $entry2 = new BankEntry(1, 9, 'sicoob', 'btx-200', '2026-11-12', 'credit', 100);
    $entry3 = new BankEntry(1, 9, 'sicoob', 'btx-300', '2026-11-15', 'debit', 3300);

    $first = $store->ingest(1, 1, 9, 'sicoob', [$entry1, $entry2], $secret);
    assertStage($first === ['inserted'=>2, 'duplicates'=>0, 'conflicts'=>0],
        'two equal-value bank events with distinct IDs are retained');
    assertStage((int)$db->query('SELECT COUNT(*) FROM lcc_banking_connectors_entries')->fetchColumn() === 2,
        'two durable rows recorded');

    $reopened = new PDO('sqlite:' . $path, null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    $store2 = new PdoEntryStage($reopened);
    $repeat = $store2->ingest(1, 1, 9, 'sicoob', [$entry1, $entry2], $secret);
    assertStage($repeat === ['inserted'=>0, 'duplicates'=>2, 'conflicts'=>0],
        'same bank events idempotent across distinct connections');

    $changed = new BankEntry(1, 9, 'sicoob', 'btx-100', '2026-11-12', 'credit', 110);
    $conflict = $store2->ingest(1, 1, 9, 'sicoob', [$changed], $secret);
    assertStage($conflict === ['inserted'=>0, 'duplicates'=>0, 'conflicts'=>1],
        'reused source ID with changed financial amount is quarantined');
    $entry = $db->query("SELECT amount_cents, state, conflict_count, linked_transaction_id
                         FROM lcc_banking_connectors_entries WHERE amount_cents=100 LIMIT 1")->fetch(PDO::FETCH_ASSOC);
    assertStage($entry !== false && (int)$entry['amount_cents'] === 100
        && $entry['state'] === 'conflict' && (int)$entry['conflict_count'] === 1
        && $entry['linked_transaction_id'] === null,
        'conflict preserves original amount and has no accounting link');

    $repeatOriginal = $store2->ingest(1, 1, 9, 'sicoob', [$entry1], $secret);
    assertStage($repeatOriginal['conflicts'] === 1 && $repeatOriginal['duplicates'] === 0,
        'conflict is not silently cleared when old value appears again');

    $notInCompany = new BankEntry(2, 9, 'sicoob', 'btx-400', '2026-11-13', 'credit', 77);
    try {
        $store2->ingest(1, 2, 9, 'sicoob', [$notInCompany], $secret);
        throw new RuntimeException('FAIL cross-company connection access');
    } catch (DomainException $e) {
        assertStage($e->getMessage() === 'connection_not_accessible',
            'wrong company cannot access a connection');
    }
    try {
        $store2->ingest(3, 1, 5, 'sicoob',
            [new BankEntry(1, 5, 'sicoob', 'bank-0005', '2026-11-11', 'debit', 70)], $secret);
        throw new RuntimeException('FAIL paused connection accepted');
    } catch (DomainException $e) {
        assertStage($e->getMessage() === 'connection_not_accessible',
            'paused connection cannot ingest');

    }
    try {
        $store2->ingest(1, 1, 9, 'sicoob', [
            $entry3,
            new BankEntry(2, 9, 'sicoob', 'invalid-cross-tenant', '2026-11-16', 'credit', 100),
        ], $secret);
        throw new RuntimeException('FAIL partially accepted mixed-company batch');
    } catch (DomainException $e) {
        assertStage($e->getMessage() === 'entry_scope_mismatch',
            'mixed-tenant batch rejects before writing any row');
    }
    assertStage((int)$db->query('SELECT COUNT(*) FROM lcc_banking_connectors_entries')->fetchColumn() === 2,
        'rejected batch did not leave partial records');

    try {
        $store2->ingest(1, 1, 9, 'sicoob', [
            new BankEntry(1, 9, 'sicoob', null, '2026-11-13', 'debit', 44),
        ], $secret);
        throw new RuntimeException('FAIL missing bank ID was accepted');
    } catch (DomainException $e) {
        assertStage($e->getMessage() === 'missing_stable_bank_identity',
            'missing source ID cannot enter automated staging');
    }

    $db->exec("CREATE TRIGGER synthetic_stage_failure
        BEFORE INSERT ON lcc_banking_connectors_entries
        WHEN NEW.amount_cents = 4444
        BEGIN SELECT RAISE(FAIL, 'synthetic failure'); END");
    try {
        $store2->ingest(1, 1, 9, 'sicoob', [
            $entry3,
            new BankEntry(1, 9, 'sicoob', 'btx-error', '2026-11-16', 'debit', 4444),
        ], $secret);
        throw new RuntimeException('FAIL database failure did not roll back batch');
    } catch (PDOException $e) {
        assertStage($e->getCode() === 'HY000' || $e->getCode() === '23000',
            'database failure aborts entire staging transaction');
    } finally {
        $db->exec('DROP TRIGGER synthetic_stage_failure');
    }
    assertStage((int)$db->query('SELECT COUNT(*) FROM lcc_banking_connectors_entries')->fetchColumn() === 2,
        'database failure leaves no partially staged bank records');

    try {
        $store2->ingest(1, 1, 9, 'sicoob', [$entry3, $entry3], $secret);
        throw new RuntimeException('FAIL duplicate source IDs within a batch accepted');
    } catch (DomainException $e) {
        assertStage($e->getMessage() === 'duplicate_bank_identity_in_batch',
            'same source ID repeated in one batch is rejected');
    }

    $new = $store2->ingest(1, 1, 9, 'sicoob', [$entry3], $secret);
    assertStage($new['inserted'] === 1, 'valid later batch can be ingested');
    $queue = $store2->listUnresolved(1, 9, 1, 100);
    assertStage(count($queue) === 3 && count(array_filter($queue, fn($r) => $r['state'] === 'conflict')) === 1,
        'company-scoped review queue exposes pending and conflicted entries');
    foreach ($queue as $item) {
        assertStage(!isset($item['source_fingerprint']) &&
            !isset($item['content_fingerprint']) &&
            !isset($item['credential_reference']),
            'review items never disclose fingerprints or secret references');
    }
    try {
        $store2->listUnresolved(2, 9, 1, 10);
        throw new RuntimeException('FAIL foreign company queue disclosed');
    } catch (DomainException $e) {
        assertStage($e->getMessage() === 'connection_not_accessible',
            'other company cannot read review queue');
    }
    assertStage(!$db->inTransaction() && !$reopened->inTransaction(),
        'no open transaction after staging and negative tests');
    echo 'SQLite staging passed; isolated temporary database, zero financial writes.' . PHP_EOL;
} finally {
    unset($store2, $store, $db, $reopened);
    if (is_file($path)) {
        unlink($path);
    }
}
