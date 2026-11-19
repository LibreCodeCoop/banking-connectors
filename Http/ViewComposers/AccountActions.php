<?php
// SPDX-FileCopyrightText: 2026 LibreCode Coop and contributors
// SPDX-License-Identifier: AGPL-3.0-or-later
declare(strict_types=1);

namespace Modules\BankingConnectors\Http\ViewComposers;

use App\Traits\Modules;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;

/**
 * Add per-account links through the native Akaunting card-actions Blade stack.
 *
 * No core override and no global navigation item. Account eligibility is
 * derived exclusively from the authenticated company and the account data,
 * not from browser-submitted account or company identifiers.
 */
final class AccountActions
{
    use Modules;

    public function compose(View $view): void
    {
        if (! auth()->check() || ! $this->moduleIsEnabled('banking-connectors')) {
            return;
        }

        $user = auth()->user();
        if (! $user->can('read-banking-accounts') ||
            ! $user->can('read-banking-transactions')) {
            return;
        }

        $companyId = function_exists('company_id') ? (int) company_id() : 0;
        if ($companyId < 1 ||
            ! Route::has('banking-connectors.account.show') ||
            ! DB::table('modules')
                ->where('company_id', $companyId)
                ->where('alias', 'banking-connectors')
                ->exists()) {
            return;
        }

        $visible = $view->getData()['accounts'] ?? [];
        if (! is_iterable($visible)) {
            return;
        }

        $eligible = [];
        foreach ($visible as $account) {
            if (! is_object($account)) {
                continue;
            }
            $id = (int) ($account->id ?? 0);
            if ($id < 1 || (int) ($account->company_id ?? 0) !== $companyId ||
                ($account->type ?? null) !== 'bank' ||
                ! (bool) ($account->enabled ?? false) ||
                ($account->deleted_at ?? null) !== null) {
                continue;
            }
            $eligible[$id] = true;
        }

        if ($eligible === []) {
            return;
        }

        // One bounded query for all displayed cards, even for many accounts.
        // The join rechecks the native account and tenant ownership.
        $connected = DB::table('lcc_banking_connectors_connections as c')
            ->join('accounts as a', static function ($join): void {
                $join->on('a.id', '=', 'c.account_id')
                    ->on('a.company_id', '=', 'c.company_id');
            })
            ->where('c.company_id', $companyId)
            ->where('a.company_id', $companyId)
            ->where('a.type', 'bank')
            ->where('a.enabled', 1)
            ->whereNull('a.deleted_at')
            ->whereIn('c.account_id', array_keys($eligible))
            ->distinct()
            ->pluck('c.account_id')
            ->mapWithKeys(static fn ($id) => [(int) $id => true])
            ->all();

        $canManage = $user->can('update-banking-accounts');
        $factory = app('view');
        foreach (array_keys($eligible) as $accountId) {
            if (! isset($connected[$accountId]) && ! $canManage) {
                continue;
            }

            $label = isset($connected[$accountId])
                ? trans('banking-connectors::general.account_integration')
                : trans('banking-connectors::general.connect_bank');

            $factory->startPush(
                'accounts_card_actions_' . $accountId . '_link_end',
                view('banking-connectors::partials.account-action', [
                    'url' => route('banking-connectors.account.show', [
                        'company_id' => $companyId,
                        'accountId' => $accountId,
                    ]),
                    'accountId' => $accountId,
                    'label' => $label,
                ])->render()
            );
        }
    }
}
