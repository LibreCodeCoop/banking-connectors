<?php
// SPDX-FileCopyrightText: 2026 LibreCode Coop and contributors
// SPDX-License-Identifier: AGPL-3.0-or-later
declare(strict_types=1);

namespace Modules\BankingConnectors\Contracts;

/**
 * Optional bank-neutral retry metadata for transport failures.
 * Exception messages must be fixed, safe error codes (no response bodies).
 */
interface BankTransportFailure
{
    public function retryDelaySeconds(): ?int;
}
