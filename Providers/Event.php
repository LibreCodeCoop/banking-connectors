<?php
// SPDX-FileCopyrightText: 2026 LibreCode Coop and contributors
// SPDX-License-Identifier: AGPL-3.0-or-later
declare(strict_types=1);

namespace Modules\BankingConnectors\Providers;

use Illuminate\Foundation\Support\Providers\EventServiceProvider;
use Modules\BankingConnectors\Listeners\AddToAdminMenu;

/** Register module events without modifying Akaunting core templates. */
final class Event extends EventServiceProvider
{
    protected $listen = [
        \App\Events\Menu\AdminCreated::class => [
            AddToAdminMenu::class,
        ],
    ];

    public function shouldDiscoverEvents(): bool
    {
        return false;
    }
}
