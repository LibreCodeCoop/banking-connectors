<?php
// SPDX-FileCopyrightText: 2026 LibreCode Coop and contributors
// SPDX-License-Identifier: AGPL-3.0-or-later
declare(strict_types=1);
require dirname(__DIR__) . '/Domain/BankEntry.php';
require dirname(__DIR__) . '/Domain/MatchingEngine.php';
require dirname(__DIR__) . '/Connectors/Sicoob/SicoobMovementDetails.php';
require dirname(__DIR__) . '/Connectors/Sicoob/SicoobStatementMapper.php';
use Modules\BankingConnectors\Domain\BankEntry;
use Modules\BankingConnectors\Domain\MatchingEngine;
use Modules\BankingConnectors\Connectors\Sicoob\SicoobStatementMapper;
function check(bool $pass, string $name): void {
    if (!$pass) { throw new RuntimeException('FAIL ' . $name); }
    echo 'PASS ' . $name . PHP_EOL;
}
$secret = str_repeat('test-only-hmac-key-', 3);
$mapper = new SicoobStatementMapper();
$fixture = ['resultado' => ['transacoes' => [
    ['transactionId' => 'bank-01', 'tipo' => 'DEBITO', 'valor' => '100.00', 'data' => '2026-11-11T10:25:00'],
    ['transactionId' => 'bank-02', 'tipo' => 'CREDITO', 'valor' => '1.234,56', 'data' => '2026-11-12'],
    ['transactionId' => 'bank-02', 'tipo' => 'CREDITO', 'valor' => '1.234,56', 'data' => '2026-11-12'],
    ['transactionId' => 'bank-03', 'tipo' => 'transfer?', 'valor' => '99.99', 'data' => '2026-11-13'],
    ['transactionId' => 'bank-04', 'tipo' => 'DEBITO', 'valor' => '100.1', 'data' => '2026-11-13'],
    ['tipo' => 'DEBITO', 'valor' => '100.00', 'data' => '2026-11-13'],
]]];
$result = $mapper->map($fixture, 1, 9);
check(count($result['entries']) === 2 && count($result['rejected']) === 4, 'Sicoob maps valid rows and quarantines invalid ones');
check($result['entries'][0]->date === '2026-11-11' &&
    $result['entries'][0]->amountCents === 10000 &&
    $result['entries'][0]->direction === 'debit', 'Sicoob ISO datetime and debit map');
check($result['entries'][1]->amountCents === 123456 &&
    $result['entries'][1]->direction === 'credit', 'exact cent conversion and credit map');
check(array_column($result['rejected'], 'reason') === [
    'duplicate_bank_id_in_response', 'unknown_direction', 'invalid_amount', 'missing_stable_bank_id',
], 'explicit safe rejection reasons');
$one = $result['entries'][0];
$otherAccount = new BankEntry(1, 5, 'sicoob', 'bank-01', '2026-11-11', 'debit', 10000);
$otherCompany = new BankEntry(2, 9, 'sicoob', 'bank-01', '2026-11-11', 'debit', 10000);
$otherProvider = new BankEntry(1, 9, 'c6', 'bank-01', '2026-11-11', 'debit', 10000);
check($one->fingerprint($secret) !== $otherAccount->fingerprint($secret) &&
    $one->fingerprint($secret) !== $otherCompany->fingerprint($secret) &&
    $one->fingerprint($secret) !== $otherProvider->fingerprint($secret), 'fingerprint scoped to provider, company and account');
check($one->fingerprint($secret) === $one->fingerprint($secret), 'stable across repeated polling');
$engine = new MatchingEngine();
$good = ['id' => 101, 'company_id' => 1, 'account_id' => 9, 'type' => 'expense', 'date' => '2026-11-11', 'currency' => 'BRL', 'amount_cents' => 10000];
$wrongAccount = array_replace($good, ['id' => 102, 'account_id' => 5]);
$wrongCompany = array_replace($good, ['id' => 103, 'company_id' => 2]);
$wrongDirection = array_replace($good, ['id' => 104, 'type' => 'income']);
$wrongCurrency = array_replace($good, ['id' => 105, 'currency' => 'USD']);
$otherMonth = array_replace($good, ['id' => 106, 'date' => '2026-10-11']);
$oneResult = $engine->evaluate($one, [$good, $wrongAccount, $wrongCompany, $wrongDirection, $wrongCurrency, $otherMonth], $secret);
check($oneResult['status'] === 'suggested' && $oneResult['candidate_ids'] === [101], 'suggestion isolated by tenant, account, sign, date, currency');
check($engine->evaluate($one, [$good, array_replace($good, ['id' => 111])], $secret)['status'] === 'needs_review', 'ambiguous candidates require review');
check($engine->evaluate($one, [$wrongAccount, $wrongCompany, $wrongDirection], $secret)['status'] === 'unmatched', 'cross-account and cross-tenant cannot match');
check($engine->evaluate($one, [array_replace($good, ['banking_connectors_fingerprint' => $one->fingerprint($secret)])], $secret)['status'] === 'already_linked', 'explicit link is idempotent');
check($engine->evaluate($one, [array_replace($good, ['banking_connectors_fingerprint' => $one->fingerprint($secret), 'amount_cents' => 9000])], $secret)['status'] === 'needs_review', 'changed linked amount blocked');
check($engine->evaluate($one, [array_replace($good, ['banking_connectors_fingerprint' => $one->fingerprint($secret)]), array_replace($good, ['id' => 201, 'banking_connectors_fingerprint' => $one->fingerprint($secret)])], $secret)['status'] === 'needs_review', 'duplicate explicit links rejected');
$noId = new BankEntry(1, 9, 'sicoob', null, '2026-11-11', 'debit', 10000);
check($engine->evaluate($noId, [$good], $secret)['status'] === 'needs_review', 'missing bank ID blocks automatic reconciliation');
check($engine->evaluate($one, [], $secret)['status'] === 'unmatched', 'no candidate does not create income or expense');
try {
    $mapper->map(['resultado' => ['mensagens' => []]], 1, 9);
    throw new RuntimeException('FAIL error treated as empty statement');
} catch (InvalidArgumentException $e) {
    check($e->getMessage() === 'missing_transaction_list', 'error never treated as empty statement');
}
echo 'Synthetic contract tests finished with zero accounting writes.' . PHP_EOL;
