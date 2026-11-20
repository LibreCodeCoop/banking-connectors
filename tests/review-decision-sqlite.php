<?php
// SPDX-FileCopyrightText: 2026 LibreCode Coop and contributors
// SPDX-License-Identifier: AGPL-3.0-or-later
declare(strict_types=1);

require dirname(__DIR__) . '/Domain/BankEntry.php';
require dirname(__DIR__) . '/Domain/AkauntingTransactionMapper.php';
require dirname(__DIR__) . '/Domain/MatchingEngine.php';
require dirname(__DIR__) . '/Infrastructure/NativeTransactionReader.php';
require dirname(__DIR__) . '/Domain/CategoryRuleEngine.php';
require dirname(__DIR__) . '/Infrastructure/PdoEntryStage.php';
require dirname(__DIR__) . '/Application/ReviewDecisionService.php';
require __DIR__ . '/Support/SqliteStageFixture.php';

use Modules\BankingConnectors\Application\ReviewDecisionService;
use Modules\BankingConnectors\Domain\AkauntingTransactionMapper;
use Modules\BankingConnectors\Domain\BankEntry;
use Modules\BankingConnectors\Domain\MatchingEngine;
use Modules\BankingConnectors\Infrastructure\NativeTransactionReader;
use Modules\BankingConnectors\Infrastructure\PdoEntryStage;
use Modules\BankingConnectors\Tests\Support\SqliteStageFixture;

function checkReview(bool $yes, string $message): void {
    if (!$yes) {
        throw new RuntimeException('FAIL ' . $message);
    }
    echo 'PASS ' . $message . PHP_EOL;
}
$db = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
SqliteStageFixture::install($db);
SqliteStageFixture::installReview($db);
SqliteStageFixture::connection($db, 1, 1, 9, 'sicoob');
SqliteStageFixture::connection($db, 2, 2, 9, 'sicoob');
$db->exec("CREATE TABLE transactions (
    id INTEGER PRIMARY KEY, company_id INTEGER NOT NULL,
    account_id INTEGER NOT NULL, paid_at DATETIME NOT NULL,
    type VARCHAR(24) NOT NULL, amount NUMERIC NOT NULL,
    currency_code VARCHAR(3) NOT NULL, parent_id INTEGER NOT NULL DEFAULT 0,
    split_id INTEGER NULL, reference VARCHAR(255) NULL, deleted_at DATETIME NULL
)");
$stmt = $db->prepare('INSERT INTO transactions
    (id,company_id,account_id,paid_at,type,amount,currency_code)
    VALUES(?,?,?,?,?,?,?)');
foreach ([
    [101,1,9,'2026-11-17 10:00:00','income','10.00','BRL'],
    [102,1,9,'2026-11-17 10:30:00','income','10.00','BRL'],
    [103,1,9,'2026-11-17 09:00:00','expense','25.00','BRL'],
    [104,1,10,'2026-11-17 11:00:00','income','10.00','BRL'],
    [105,2,9,'2026-11-17 10:00:00','income','10.00','BRL'],
] as $row) {
    $stmt->execute($row);
}
$stage = new PdoEntryStage($db);
$secret = str_repeat('stable-review-fixture-key-', 2);
$stage->ingest(1, 1, 9, 'sicoob', [
    new BankEntry(1, 9, 'sicoob', 'source-A', '2026-11-17', 'credit', 1000),
    new BankEntry(1, 9, 'sicoob', 'source-B', '2026-11-17', 'credit', 1000),
    new BankEntry(1, 9, 'sicoob', 'source-C', '2026-11-17', 'debit', 3300),
    new BankEntry(1, 9, 'sicoob', 'source-D', '2026-11-17', 'debit', 2500),
], $secret);
$entries = $db->query("SELECT id, source_fingerprint FROM lcc_banking_connectors_entries ORDER BY id")
    ->fetchAll(PDO::FETCH_ASSOC);
[$a, $b, $c, $d] = array_map(static fn(array $r): int => (int) $r['id'], $entries);

$reader = new NativeTransactionReader($db, new AkauntingTransactionMapper());
$review = new ReviewDecisionService($db, $reader, new MatchingEngine());
$before = $db->query('SELECT id,amount,type FROM transactions ORDER BY id')
    ->fetchAll(PDO::FETCH_ASSOC);
$key1 = str_repeat('a', 32);
$key2 = str_repeat('b', 32);
$key3 = str_repeat('c', 32);
$key4 = str_repeat('d', 32);
$key5 = str_repeat('e', 32);
$key6 = str_repeat('f', 32);

$accept = $review->decide(1, 9, 1, $a, 71, 'accept', 101, $key1);
checkReview($accept['state'] === 'reviewed' && !$accept['idempotent'],
    'manual decision records a candidate in module-owned staging only');
$replay = $review->decide(1, 9, 1, $a, 71, 'accept', 101, $key1);
checkReview($replay['idempotent'] && $replay['event_id'] === $accept['event_id'],
    'network replay does not create a second decision event');
try {
    $review->decide(1, 9, 1, $a, 71, 'accept', 102, $key1);
    throw new RuntimeException('FAIL reused request id with changed native target');
} catch (DomainException $e) {
    checkReview($e->getMessage() === 'idempotency_key_reused',
        'same request key cannot silently accept a different transaction');
}
try {
    $review->decide(2, 9, 1, $a, 71, 'accept', 101, $key2);
    throw new RuntimeException('FAIL other tenant linked movement');
} catch (DomainException $e) {
    checkReview($e->getMessage() === 'entry_not_accessible',
        'foreign-company staged entry cannot be reviewed');
}
try {
    $review->decide(1, 9, 1, $b, 71, 'accept', 101, $key2);
    throw new RuntimeException('FAIL two bank events linked to one native transaction');
} catch (DomainException $e) {
    checkReview($e->getMessage() === 'native_match_or_audit_uniqueness_conflict',
        'unique constraint forbids linking two bank rows to same native transaction');
}
checkReview($db->query("SELECT state FROM lcc_banking_connectors_entries WHERE id=$b")->fetchColumn() === 'pending',
    'rejected duplicate target leaves original pending entry intact');
$another = $review->decide(1, 9, 1, $b, 72, 'accept', 102, $key3);
checkReview($another['state'] === 'reviewed',
    'operator may resolve ambiguous equal-value events by choosing different native IDs');
try {
    $review->decide(1, 9, 1, $c, 72, 'accept', 103, $key4);
    throw new RuntimeException('FAIL mismatched amount accepted');
} catch (DomainException $e) {
    checkReview($e->getMessage() === 'native_transaction_not_eligible',
        'same-bank-account transaction with mismatched amount is not eligible');
}
try {
    $review->decide(1, 9, 1, $d, 72, 'accept', 104, $key4);
    throw new RuntimeException('FAIL cross-account match accepted');
} catch (DomainException $e) {
    checkReview($e->getMessage() === 'native_transaction_not_eligible',
        'native transaction from a different account is not eligible');
}
$dismiss = $review->decide(1, 9, 1, $c, 72, 'dismiss', null, $key4);
checkReview($dismiss['state'] === 'dismissed', 'dismissal preserves bank evidence');
$reopen = $review->decide(1, 9, 1, $c, 72, 'reopen', null, $key5);
checkReview($reopen['state'] === 'pending', 'reopening restores manual review queue');
$history = $review->history(1, 9, $c);
checkReview(count($history) === 2 &&
    array_column($history, 'operation') === ['dismiss', 'reopen'] &&
    !array_key_exists('source_fingerprint', $history[0]),
    'append-only audit history retains both decisions without bank IDs');
try {
    $review->history(2, 9, $c);
    throw new RuntimeException('FAIL other tenant read audit');
} catch (DomainException $e) {
    checkReview($e->getMessage() === 'entry_not_accessible',
        'other company cannot inspect review history');
}
$reopenedAccepted = $review->decide(1, 9, 1, $a, 71, 'reopen', null, $key6);
checkReview($reopenedAccepted['state'] === 'pending' &&
    $db->query("SELECT linked_transaction_id FROM lcc_banking_connectors_entries WHERE id=$a")->fetchColumn() === null,
    'reopening a match frees native candidate while preserving audit');
$after = $db->query('SELECT id,amount,type FROM transactions ORDER BY id')
    ->fetchAll(PDO::FETCH_ASSOC);
checkReview($before === $after, 'native accounting transactions never modified by audit decisions');
checkReview((int) $db->query('SELECT COUNT(*) FROM lcc_banking_connectors_review_events')->fetchColumn() === 5,
    'one immutable event per successful business decision');
checkReview(!$db->inTransaction(), 'all success and rejection paths leave no open transaction');
// Transfer legs already posted by Akaunting may be linked to their bank
// source without creating any income or expense for the same economic event.
$stmt->execute([106,1,9,'2026-11-17 11:40:00','expense-transfer','75.00','BRL']);
$transfer=new BankEntry(1,9,'sicoob','transfer-leg-source',
    '2026-11-17','debit',7500,'BRL');
$stage->ingest(1,1,9,'sicoob',[$transfer],$secret);
$fp=$transfer->fingerprint($secret);
$lookup=$db->prepare('SELECT id FROM lcc_banking_connectors_entries WHERE source_fingerprint=?');
$lookup->execute([$fp]);
$transferId=(int)$lookup->fetchColumn();
$accepted=$review->decide(1,9,1,$transferId,72,'accept',106,str_repeat('9',32));
checkReview($accepted['state']==='reviewed' &&
    (int)$db->query('SELECT linked_transaction_id FROM lcc_banking_connectors_entries WHERE id='.$transferId)->fetchColumn()===106,
    'native transfer leg is manually linked instead of duplicated as expense');
echo 'Review decision tests passed in isolated SQLite; no Akaunting writes.' . PHP_EOL;
