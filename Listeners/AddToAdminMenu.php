<?php
// SPDX-FileCopyrightText: 2026 LibreCode Coop and contributors
// SPDX-License-Identifier: AGPL-3.0-or-later
declare(strict_types=1);

namespace Modules\BankingConnectors\Listeners;

use App\Events\Menu\AdminCreated;
use App\Traits\Modules;

/**
 * Native Akaunting navigation entry for authorized accounting users.
 * Routing and controllers still enforce full per-company ACL.
 */
class AddToAdminMenu
{
    use Modules;

    public function handle(AdminCreated $event): void
    {
        if (!$this->moduleIsEnabled('banking-connectors')) {
            return;
        }
        $user = auth()->user();
        if ($user === null ||
            !$user->can('read-banking-accounts') ||
            !$user->can('read-banking-transactions')) {
            return;
        }
        $event->menu->route(
            'banking-connectors.review.index',
            trans('banking-connectors::general.name'),
            [],
            47,
            [
                'icon' => 'account_balance',
                'search_keywords' => 'Sicoob banco extrato movimentacoes conciliacao',
            ]
        );
    }
}
