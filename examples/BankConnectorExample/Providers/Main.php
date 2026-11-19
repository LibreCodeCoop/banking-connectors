<?php
// SPDX-FileCopyrightText: 2026 LibreCode Coop and contributors
// SPDX-License-Identifier: AGPL-3.0-or-later
declare(strict_types=1);

namespace Modules\BankConnectorExample\Providers;

use Illuminate\Support\ServiceProvider;
use Modules\BankingConnectors\Application\BankConnectorRegistry;
use Modules\BankConnectorExample\Connectors\ExampleConnector;

/**
 * Illustrates an independently installed Akaunting module exposing a bank
 * connector; there is NO edit to Banking Connectors's Main provider or scheduler.
 */
final class Main extends ServiceProvider
{
    public function boot(): void
    {
        $this->app->make(BankConnectorRegistry::class)
            ->register(new ExampleConnector());
    }
}
