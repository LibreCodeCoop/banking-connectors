<?php
// SPDX-FileCopyrightText: 2026 LibreCode Coop and contributors
// SPDX-License-Identifier: AGPL-3.0-or-later
declare(strict_types=1);

namespace Modules\BankingConnectors\Domain;

use InvalidArgumentException;

/**
 * Framework-independent identity for one financial-account connection.
 * Provider modules must never receive an Eloquent model, HTTP request,
 * arbitrary Vault path or unrestricted Akaunting database handle.
 *
 * The source adapter assembles this identity from a native account already
 * scoped by company and ID on the server, never from browser-supplied values.
 */
final class ConnectionContext
{
    public function __construct(
        public readonly int $companyId,
        public readonly int $accountId,
        public readonly string $providerId,
        public readonly string $currency,
        public readonly string $accountNumber,
        public readonly string $credentialReference,
    ) {
        if ($companyId < 1 || $accountId < 1 ||
            !preg_match('/^[a-z][a-z0-9_-]{1,47}$/D', $providerId) ||
            !preg_match('/^[A-Z]{3}$/D', $currency) ||
            strlen($accountNumber) > 80 ||
            !preg_match('/^[A-Za-z0-9.\/ -]{1,80}$/D', $accountNumber) ||
            $credentialReference !== "{$providerId}/company-{$companyId}/account-{$accountId}") {
            throw new InvalidArgumentException('invalid_bank_connection_context');
        }
    }
}
