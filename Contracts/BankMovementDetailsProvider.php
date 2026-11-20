<?php
// SPDX-FileCopyrightText: 2026 LibreCode Coop and contributors
// SPDX-License-Identifier: AGPL-3.0-or-later
declare(strict_types=1);

namespace Modules\BankingConnectors\Contracts;

/** Optional provider-specific, presentation-only schema. No transport or finance writes. */
interface BankMovementDetailsProvider
{
    /** @return array<string,array{label:string,group:string}> */
    public function detailDefinitions(): array;
}
