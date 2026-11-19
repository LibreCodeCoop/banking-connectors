<?php
// SPDX-FileCopyrightText: 2026 LibreCode Coop and contributors
// SPDX-License-Identifier: AGPL-3.0-or-later
declare(strict_types=1);

namespace Modules\BankingConnectors\Domain;

use InvalidArgumentException;
use Modules\BankingConnectors\Contracts\BankStatementProvider;

/**
 * Session is in-memory only. HMAC fingerprint key is never persisted, sent
 * to HTTP clients or included in logs. Separate platform adapters can use
 * the same banking source session contract.
 */
final class ConnectorSession
{
    public function __construct(
        public readonly BankStatementProvider $statements,
        public readonly string $fingerprintKey,
        public readonly ?int $certificateValidUntil = null,
    ) {
        if (strlen($fingerprintKey) < 32 ||
            ($certificateValidUntil !== null && $certificateValidUntil <= 0)) {
            throw new InvalidArgumentException('invalid_bank_connector_session');
        }
    }
}
