<?php
// SPDX-FileCopyrightText: 2026 LibreCode Coop and contributors
// SPDX-License-Identifier: AGPL-3.0-or-later
declare(strict_types=1);

namespace Modules\BankingConnectors\Contracts;

use Modules\BankingConnectors\Domain\ConnectionContext;
use Modules\BankingConnectors\Domain\BankEntry;

/** Optional, explicit read-only enrichment; never run implicitly by polling. */
interface BankMovementEnricher
{
    /** @return array<string,string> Additional verified and allowlisted fields. */
    public function enrich(ConnectionContext $scope, BankEntry $entry): array;
}
