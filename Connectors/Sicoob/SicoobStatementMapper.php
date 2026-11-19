<?php
// SPDX-FileCopyrightText: 2026 LibreCode Coop and contributors
// SPDX-License-Identifier: AGPL-3.0-or-later
declare(strict_types=1);
namespace Modules\BankingConnectors\Connectors\Sicoob;
use DateTimeImmutable;
use InvalidArgumentException;
use Modules\BankingConnectors\Domain\BankEntry;

/** Stateless parser of Sicoob Conta Corrente v4 statements; no network or writes. */
final class SicoobStatementMapper
{
    /** @return array{entries:array<int,BankEntry>,rejected:array<int,array{index:int,reason:string}>} */
    public function map(array $response, int $companyId, int $accountId): array
    {
        $list = $response['resultado']['transacoes'] ?? null;
        if (!is_array($list) || !array_is_list($list)) {
            throw new InvalidArgumentException('missing_transaction_list');
        }
        $seen = [];
        $entries = [];
        $rejected = [];
        foreach ($list as $index => $row) {
            try {
                if (!is_array($row)) {
                    throw new InvalidArgumentException('invalid_record');
                }
                $id = $row['transactionId'] ?? null;
                if (!is_string($id) || trim($id) === '') {
                    throw new InvalidArgumentException('missing_stable_bank_id');
                }
                if (isset($seen[$id])) {
                    throw new InvalidArgumentException('duplicate_bank_id_in_response');
                }
                $direction = match (strtoupper(trim((string) ($row['tipo'] ?? '')))) {
                    'C', 'CR', 'CREDITO', 'CRÉDITO' => 'credit',
                    'D', 'DB', 'DEBITO', 'DÉBITO' => 'debit',
                    default => throw new InvalidArgumentException('unknown_direction'),
                };
                $entry = new BankEntry(
                    companyId: $companyId,
                    accountId: $accountId,
                    provider: 'sicoob',
                    externalId: $id,
                    date: self::parseDate($row['data'] ?? null),
                    direction: $direction,
                    amountCents: self::parseMoney($row['valor'] ?? null),
                    description: self::safeDescription($row['descricao'] ?? null),
                );
                $seen[$id] = true;
                $entries[] = $entry;
            } catch (InvalidArgumentException $e) {
                $rejected[] = ['index' => (int) $index, 'reason' => $e->getMessage()];
            }
        }
        return ['entries' => $entries, 'rejected' => $rejected];
    }
    private static function safeDescription(mixed $description): ?string
    {
        if (!is_string($description) || !preg_match('//u', $description)) {
            return null;
        }
        $description = trim(preg_replace('/[\x00-\x1F\x7F]+/u', ' ', $description) ?? '');
        // Never truncate through UTF-8 byte boundaries or disclose raw payloads.
        return $description !== '' && strlen($description) <= 240 ? $description : null;
    }

    private static function parseDate(mixed $input): string
    {
        if (!is_string($input)) {
            throw new InvalidArgumentException('invalid_date');
        }
        foreach ([
            '!Y-m-d' => 'Y-m-d', '!d/m/Y' => 'd/m/Y',
            '!Y-m-d\TH:i' => 'Y-m-d\TH:i',
            '!Y-m-d\TH:i:s' => 'Y-m-d\TH:i:s',
        ] as $pattern => $expected) {
            $date = DateTimeImmutable::createFromFormat($pattern, $input);
            if ($date && $date->format($expected) === $input) {
                return $date->format('Y-m-d');
            }
        }
        throw new InvalidArgumentException('invalid_date');
    }
    private static function parseMoney(mixed $amount): int
    {
        if (!is_string($amount)) {
            throw new InvalidArgumentException('invalid_amount');
        }
        $amount = trim($amount);
        if (preg_match('/^[0-9]{1,3}(?:\.[0-9]{3})+,[0-9]{2}$/', $amount)) {
            $amount = str_replace(',', '.', str_replace('.', '', $amount));
        } elseif (preg_match('/^[0-9]+,[0-9]{2}$/', $amount)) {
            $amount = str_replace(',', '.', $amount);
        } elseif (!preg_match('/^[0-9]+\.[0-9]{2}$/', $amount)) {
            throw new InvalidArgumentException('invalid_amount');
        }
        [$major, $fraction] = explode('.', $amount);
        if (strlen($major) > 14) {
            throw new InvalidArgumentException('amount_overflow');
        }
        $cents = (int) $major * 100 + (int) $fraction;
        if ($cents <= 0) {
            throw new InvalidArgumentException('invalid_amount');
        }
        return $cents;
    }
}
