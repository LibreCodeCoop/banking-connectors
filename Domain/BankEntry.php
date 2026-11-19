<?php
// SPDX-FileCopyrightText: 2026 LibreCode Coop and contributors
// SPDX-License-Identifier: AGPL-3.0-or-later
declare(strict_types=1);

namespace Modules\BankingConnectors\Domain;

use DateTimeImmutable;
use InvalidArgumentException;

/** One confirmed, settled transaction from a specific bank account. */
final class BankEntry
{
    public function __construct(
        public readonly int $companyId,
        public readonly int $accountId,
        public readonly string $provider,
        public readonly ?string $externalId,
        public readonly string $date,
        public readonly string $direction,
        public readonly int $amountCents,
        public readonly string $currency = 'BRL',
    ) {
        if ($companyId < 1 || $accountId < 1) {
            throw new InvalidArgumentException('invalid_scope');
        }
        if (!preg_match('/^[a-z0-9_-]{2,48}$/', $provider)) {
            throw new InvalidArgumentException('invalid_provider');
        }
        if ($externalId !== null &&
            ($externalId === '' || strlen($externalId) > 128 || preg_match('/[\x00-\x1F\x7F]/', $externalId))) {
            throw new InvalidArgumentException('invalid_bank_id');
        }
        $d = DateTimeImmutable::createFromFormat('!Y-m-d', $date);
        if (!$d || $d->format('Y-m-d') !== $date) {
            throw new InvalidArgumentException('invalid_date');
        }
        if (!in_array($direction, ['credit', 'debit'], true)) {
            throw new InvalidArgumentException('unknown_direction');
        }
        if ($amountCents <= 0) {
            throw new InvalidArgumentException('invalid_amount');
        }
        if (!preg_match('/^[A-Z]{3}$/', $currency)) {
            throw new InvalidArgumentException('invalid_currency');
        }
    }

    /** Returns null when the bank has not supplied a dependable unique ID. */
    public function fingerprint(string $secret): ?string
    {
        if ($this->externalId === null) {
            return null;
        }
        if (strlen($secret) < 32) {
            throw new InvalidArgumentException('fingerprint_key_too_short');
        }

        return hash_hmac('sha256', implode('|', [
            $this->provider,
            (string) $this->companyId,
            (string) $this->accountId,
            $this->externalId,
        ]), $secret);
    }
}
