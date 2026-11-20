<?php
// SPDX-FileCopyrightText: 2026 LibreCode Coop and contributors
// SPDX-License-Identifier: AGPL-3.0-or-later
declare(strict_types=1);
require dirname(__DIR__) . '/Domain/AkauntingTransactionMapper.php';
use Modules\BankingConnectors\Domain\AkauntingTransactionMapper;
function checkNative(bool $ok, string $label): void
{
    if (!$ok) { throw new RuntimeException('FAIL ' . $label); }
    echo 'PASS ' . $label . PHP_EOL;
}
$mapper = new AkauntingTransactionMapper();
$row = [
    'id' => 101, 'company_id' => 1, 'account_id' => 9,
    'type' => 'expense', 'paid_at' => '2026-11-11T11:00:00-03:00',
    'currency_code' => 'BRL', 'amount' => 100.10,
];
$mapped = $mapper->map($row);
checkNative($mapped['date'] === '2026-11-11' &&
    $mapped['amount_cents'] === 10010 && $mapped['type'] === 'expense',
    'native API expense amount and timezone date normalize');
$mappedIncome = $mapper->map(array_replace($row, ['type' => 'income', 'amount' => '1.23']));
$mappedTransfer=$mapper->map(array_replace($row,['type'=>'expense-transfer']));
checkNative($mappedTransfer['type']==='expense-transfer',
    'native transfer legs are eligible for linking instead of duplicated expenses');
checkNative($mappedIncome['amount_cents'] === 123, 'integer cents from decimal string');
$invalid = [
    [array_replace($row, ['account_id' => 0]), 'invalid_native_identity'],
    [array_replace($row, ['type' => 'transfer']), 'unsupported_native_type'],
    [array_replace($row, ['currency_code' => '']), 'invalid_native_currency'],
    [array_replace($row, ['paid_at' => '2026-02-30']), 'invalid_native_date'],
    [array_replace($row, ['amount' => 100.005]), 'native_amount_has_fractional_cent'],
    [array_replace($row, ['amount' => -10]), 'invalid_native_amount'],
];
foreach ($invalid as [$payload, $expected]) {
    try {
        $mapper->map($payload);
        throw new RuntimeException('FAIL unsafe transaction accepted: ' . $expected);
    } catch (InvalidArgumentException $e) {
        checkNative($e->getMessage() === $expected, 'native rejected ' . $expected);
    }
}
echo 'Native Akaunting API mapper tests passed; synthetic data only.' . PHP_EOL;
