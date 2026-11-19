<?php
// SPDX-FileCopyrightText: 2026 LibreCode Coop and contributors
// SPDX-License-Identifier: AGPL-3.0-or-later
declare(strict_types=1);

namespace Modules\BankingConnectors\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Routing\Controller;
use Modules\BankingConnectors\Application\HealthSnapshot;

/**
 * Native-authenticated, company-scoped, credential-free monitoring endpoint.
 *
 * A monitor reads the last known state; one scrape NEVER calls the bank,
 * opens OpenBao, renews a token or schedules jobs. A failing bank can never
 * be DDoS'd by frequent health polling.
 *
 * No client names, account numbers, secrets or exception details leave the
 * tenant boundary. Authenticate a dedicated service account with native
 * Akaunting API permissions instead of adding a Zabbix dependency.
 */
final class ApiHealthController extends Controller
{
    public function __construct(private readonly HealthSnapshot $health) {}

    public function show(): JsonResponse
    {
        abort_unless(auth()->check() && auth()->id() !== null, 401);
        abort_unless(
            auth()->user()->can('read-banking-accounts')
            && auth()->user()->can('read-banking-transactions'),
            403
        );

        $companyId = function_exists('company_id') ? (int) company_id() : 0;
        abort_unless($companyId > 0, 404);

        $snapshot = $this->health->inspect(companyId: $companyId);
        return response()->json([
            'service' => 'banking-connectors',
            'schema_version' => 1,
            'company_id' => $companyId,
            'checked_at' => gmdate('Y-m-d\TH:i:s\Z'),
            'probe_type' => 'last_observed_state',
            'state' => $snapshot['status'],
            'data' => $snapshot,
        ])
            ->header('Cache-Control', 'private, no-store')
            ->header('X-Content-Type-Options', 'nosniff');
    }
}
