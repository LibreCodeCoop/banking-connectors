<?php
// SPDX-FileCopyrightText: 2026 LibreCode Coop and contributors
// SPDX-License-Identifier: AGPL-3.0-or-later
declare(strict_types=1);
require dirname(__DIR__) . '/Domain/BankEntry.php';
require dirname(__DIR__) . '/Contracts/BankStatementProvider.php';
require dirname(__DIR__) . '/Connectors/Sicoob/SicoobStatementMapper.php';
require dirname(__DIR__) . '/Connectors/Sicoob/SicoobProvider.php';
use Modules\BankingConnectors\Connectors\Sicoob\SicoobStatementMapper;
use Modules\BankingConnectors\Connectors\Sicoob\SicoobProvider;
function checkIt(bool $condition, string $name): void
{
    if (!$condition) {
        throw new RuntimeException('FAIL ' . $name);
    }
    echo 'PASS ' . $name . PHP_EOL;
}
$calls = [];
$reader = static function (int $year, int $month, int $start, int $end) use (&$calls): array {
    $calls[] = [$year, $month, $start, $end];
    return ['resultado' => ['transacoes' => [[
        'transactionId' => 'bank-' . $month,
        'tipo' => $month === 10 ? 'CREDITO' : 'DEBITO',
        'valor' => '20.00',
        'data' => sprintf('%04d-%02d-%02d', $year, $month, $start),
    ]]]];
};
$provider = new SicoobProvider($reader, new SicoobStatementMapper(), 1, 9);
$result = $provider->fetch(1, 9, new DateTimeImmutable('2026-10-31'), new DateTimeImmutable('2026-11-02'));
checkIt(count($result) === 2 &&
    $calls === [[2026, 10, 31, 31], [2026, 11, 1, 2]], 'splits crossing-month statement into exact bank windows');
try {
    $provider->fetch(2, 9, new DateTimeImmutable('2026-11-01'), new DateTimeImmutable('2026-11-02'));
    throw new RuntimeException('FAIL unrelated company was allowed');
} catch (DomainException $e) {
    checkIt($e->getMessage() === 'bank_binding_scope_mismatch' && count($calls) === 2, 'tenant scope rejected before bank network call');
}
try {
    $provider->fetch(1, 5, new DateTimeImmutable('2026-11-01'), new DateTimeImmutable('2026-11-02'));
    throw new RuntimeException('FAIL unrelated account was allowed');
} catch (DomainException $e) {
    checkIt($e->getMessage() === 'bank_binding_scope_mismatch' && count($calls) === 2, 'bank account scope rejected before bank network call');
}
try {
    $provider->fetch(1, 9, new DateTimeImmutable('2026-11-17'), new DateTimeImmutable('2026-11-16'));
    throw new RuntimeException('FAIL reverse window');
} catch (InvalidArgumentException $e) {
    checkIt($e->getMessage() === 'invalid_polling_window', 'reverse window rejected');
}
$duplicatedReader = static fn(int $year, int $month, int $start, int $end): array => [
    'resultado' => ['transacoes' => [[
        'transactionId' => 'same-across-months', 'tipo' => 'DEBITO',
        'valor' => '20.00',
        'data' => sprintf('%04d-%02d-%02d', $year, $month, $start),
    ]]]
];
$duplicate = new SicoobProvider($duplicatedReader, new SicoobStatementMapper(), 1, 9);
try {
    $duplicate->fetch(1, 9, new DateTimeImmutable('2026-10-31'), new DateTimeImmutable('2026-11-01'));
    throw new RuntimeException('FAIL duplicate bank identity across windows');
} catch (RuntimeException $e) {
    checkIt($e->getMessage() === 'bank_statement_duplicate_identity', 'cross-window bank ID collision triggers investigation');
}
$malformed = new SicoobProvider(
    static fn(int $y, int $m, int $a, int $b): array => ['resultado' => ['transacoes' => [['tipo' => 'UNKNOWN']]]],
    new SicoobStatementMapper(),
    1,
    9
);
try {
    $malformed->fetch(1, 9, new DateTimeImmutable('2026-11-01'), new DateTimeImmutable('2026-11-01'));
    throw new RuntimeException('FAIL partial bank response silently accepted');
} catch (RuntimeException $e) {
    checkIt($e->getMessage() === 'bank_statement_requires_review', 'invalid bank statement cannot be silently imported');
}
$outOfRange = new SicoobProvider(
    static fn(int $y, int $m, int $a, int $b): array => [
        'resultado' => ['transacoes' => [[
            'transactionId' => 'outside-requested-dates', 'tipo' => 'C',
            'valor' => '2.00', 'data' => '2026-10-31',
        ]]],
    ],
    new SicoobStatementMapper(), 1, 9
);
try {
    $outOfRange->fetch(1, 9, new DateTimeImmutable('2026-11-01'),
        new DateTimeImmutable('2026-11-03'));
    throw new RuntimeException('FAIL out-of-window bank entry was accepted');
} catch (RuntimeException $e) {
    checkIt($e->getMessage() === 'bank_statement_outside_requested_window',
        'bank ignoring date window cannot advance synchronization cursor');
}
echo 'Provider tests finished; mock bank reader, no accounting writes.' . PHP_EOL;
