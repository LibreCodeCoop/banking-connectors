<?php
// SPDX-FileCopyrightText: 2026 LibreCode Coop and contributors
// SPDX-License-Identifier: AGPL-3.0-or-later
declare(strict_types=1);
namespace Modules\BankingConnectors\Contracts;
use DateTimeImmutable;
use Modules\BankingConnectors\Domain\BankEntry;

/** Read-only adapter; credential lifecycle stays within provider transport. */
interface BankStatementProvider
{
    /** @return array<int,BankEntry> */
    public function fetch(int $companyId, int $accountId, DateTimeImmutable $from, DateTimeImmutable $until): array;
}
