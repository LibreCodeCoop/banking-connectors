<?php
// SPDX-FileCopyrightText: 2026 LibreCode Coop and contributors
// SPDX-License-Identifier: AGPL-3.0-or-later
declare(strict_types=1);
namespace Modules\BankingConnectors\Domain;

use DateTimeImmutable;
use InvalidArgumentException;

/**
 * Converts authorized native Akaunting transaction API rows to candidate input.
 * Rejects missing or ambiguous monetary values; does not write anything.
 */
final class AkauntingTransactionMapper
{
    /** @return array<string,mixed> */
    public function map(array $row): array
    {
        $id = filter_var($row['id'] ?? null, FILTER_VALIDATE_INT);
        $companyId = filter_var($row['company_id'] ?? null, FILTER_VALIDATE_INT);
        $accountId = filter_var($row['account_id'] ?? null, FILTER_VALIDATE_INT);
        if (!$id || !$companyId || !$accountId || $id < 1 || $companyId < 1 || $accountId < 1) {
            throw new InvalidArgumentException('invalid_native_identity');
        }
        if (!in_array($row['type'] ?? null, ['income', 'expense'], true)) {
            throw new InvalidArgumentException('unsupported_native_type');
        }
        $dateText = $row['paid_at'] ?? null;
        if (!is_string($dateText) || !preg_match('/^\d{4}-\d{2}-\d{2}(?:T| |$)/', $dateText)) {
            throw new InvalidArgumentException('invalid_native_date');
        }
        $date = substr($dateText, 0, 10);
        $parsed = DateTimeImmutable::createFromFormat('!Y-m-d', $date);
        if (!$parsed || $parsed->format('Y-m-d') !== $date) {
            throw new InvalidArgumentException('invalid_native_date');
        }
        $currency = strtoupper((string) ($row['currency_code'] ?? ''));
        if (!preg_match('/^[A-Z]{3}$/', $currency)) {
            throw new InvalidArgumentException('invalid_native_currency');
        }
        $amount = $row['amount'] ?? null;
        if (is_int($amount) || is_float($amount)) {
            if (!is_finite((float) $amount) || $amount < 0 || (float) $amount > 100000000000000) {
                throw new InvalidArgumentException('invalid_native_amount');
            }
            $roundedCents = round((float) $amount * 100);
            if (abs((float) $amount * 100 - $roundedCents) > 0.00001) {
                throw new InvalidArgumentException('native_amount_has_fractional_cent');
            }
            $amountCents = (int) $roundedCents;
        } elseif (is_string($amount) && preg_match('/^\d+(?:\.\d{1,2})?$/', $amount)) {
            [$major, $minor] = array_pad(explode('.', $amount, 2), 2, '0');
            if (strlen($major) > 14) {
                throw new InvalidArgumentException('invalid_native_amount');
            }
            $amountCents = (int) $major * 100 + (int) str_pad($minor, 2, '0');
        } else {
            throw new InvalidArgumentException('invalid_native_amount');
        }
        if ($amountCents < 1) {
            throw new InvalidArgumentException('invalid_native_amount');
        }
        return [
            'id' => (int) $id,
            'company_id' => (int) $companyId,
            'account_id' => (int) $accountId,
            'type' => $row['type'],
            'date' => $date,
            'currency' => $currency,
            'amount_cents' => $amountCents,
            'banking_connectors_fingerprint' => $row['banking_connectors_fingerprint'] ?? null,
        ];
    }
}
