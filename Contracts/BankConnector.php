<?php
// SPDX-FileCopyrightText: 2026 LibreCode Coop and contributors
// SPDX-License-Identifier: AGPL-3.0-or-later
declare(strict_types=1);

namespace Modules\BankingConnectors\Contracts;

use Modules\BankingConnectors\Domain\ConnectorSession;
use Modules\BankingConnectors\Domain\ConnectionContext;

/**
 * One institution-specific implementation. No Laravel, HTTP or Akaunting
 * financial-table dependencies are needed in a new bank connector.
 *
 * Register a trusted instance via BankConnectorRegistry in an installed
 * Akaunting module's ServiceProvider; never auto-load arbitrary PHP files.
 */
interface BankConnector
{
    /** Stable lower-case provider identifier; stored in historical bank rows. */
    public function id(): string;

    /**
     * Validate tenant/account binding and read bank credentials via a
     * purpose-scoped secret provider. Must not contact the bank until the
     * returned statement provider's fetch() is explicitly called.
     *
     * @param object $connection Trusted native bank account + connection row.
     */
    public function open(ConnectionContext $connection): ConnectorSession;
}
