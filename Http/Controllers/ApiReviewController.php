<?php
// SPDX-FileCopyrightText: 2026 LibreCode Coop and contributors
// SPDX-License-Identifier: AGPL-3.0-or-later
declare(strict_types=1);

namespace Modules\BankingConnectors\Http\Controllers;

use DomainException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use Modules\BankingConnectors\Application\ReviewDecisionService;
use Modules\BankingConnectors\Application\ReviewPreview;

/**
 * JSON interface sharing the exact same read and review domain services
 * as the native Web UI. The native API middleware is responsible for
 * authentication, read-api permission and company identification.
 */
final class ApiReviewController extends Controller
{
    public function __construct(
        private readonly ReviewPreview $preview,
        private readonly ReviewDecisionService $decisions,
    ) {}

    public function connections(): JsonResponse
    {
        $companyId = $this->authorizedCompany(false);
        $records = $this->connectionQuery($companyId)
            ->orderBy('c.id')->get([
                'c.id', 'c.account_id', 'c.provider', 'c.state',
                'c.cursor_date', 'c.last_success_at', 'c.last_error_code',
                'a.name as account_name', 'a.currency_code',
            ])->map(static fn(object $c): array => [
                'id' => (int) $c->id,
                'account_id' => (int) $c->account_id,
                'provider' => $c->provider,
                'state' => $c->state,
                'cursor_date' => $c->cursor_date,
                'last_success_at' => $c->last_success_at,
                'last_error_code' => $c->last_error_code,
                'account_name' => $c->account_name,
                'currency_code' => $c->currency_code,
            ])->all();

        return response()->json(['data' => array_values($records)]);
    }

    public function preview(Request $request): JsonResponse
    {
        $companyId = $this->authorizedCompany(false);
        $values = $request->validate([
            'connection_id' => 'required|integer|min:1',
        ]);
        $connection = $this->connectionQuery($companyId)
            ->where('c.id', (int) $values['connection_id'])
            ->first(['c.id', 'c.account_id', 'c.provider', 'c.state']);
        abort_unless($connection !== null, 404);

        $result = $this->preview->forConnection(
            $companyId, (int) $connection->account_id, (int) $connection->id
        );
        return response()->json(['data' => [
            'connection_id' => (int) $connection->id,
            'account_id' => (int) $connection->account_id,
            'provider' => $connection->provider,
            'items' => $result['items'],
            'summary' => $result['summary'],
        ]]);
    }

    public function decide(Request $request): JsonResponse
    {
        $companyId = $this->authorizedCompany(true);
        $values = $request->validate([
            'connection_id' => 'required|integer|min:1',
            'entry_id' => 'required|integer|min:1',
            'operation' => 'required|in:accept,dismiss,reopen',
            'native_transaction_id' => 'nullable|integer|min:1',
            'request_key' => ['required', 'regex:/^[a-f0-9]{32}$/D'],
        ]);
        $connection = $this->connectionQuery($companyId)
            ->where('c.id', (int) $values['connection_id'])
            ->first(['c.id', 'c.account_id']);
        abort_unless($connection !== null, 404);
        try {
            $result = $this->decisions->decide(
                $companyId, (int) $connection->account_id,
                (int) $connection->id, (int) $values['entry_id'],
                (int) auth()->id(), (string) $values['operation'],
                isset($values['native_transaction_id'])
                    ? (int) $values['native_transaction_id'] : null,
                (string) $values['request_key'],
            );
        } catch (DomainException|InvalidArgumentException $error) {
            return response()->json(['error' => $error->getMessage()], 409);
        }
        return response()->json(['data' => $result]);
    }

    private function authorizedCompany(bool $write): int
    {
        abort_unless(auth()->check() && auth()->id() !== null, 401);
        abort_unless(
            auth()->user()->can('read-banking-transactions') &&
            auth()->user()->can('read-banking-accounts') &&
            (!$write || auth()->user()->can('update-banking-transactions')),
            403
        );
        $companyId = function_exists('company_id') ? (int) company_id() : 0;
        abort_unless($companyId > 0, 404);
        return $companyId;
    }

    private function connectionQuery(int $companyId): \Illuminate\Database\Query\Builder
    {
        return DB::table('lcc_banking_connectors_connections as c')
            ->join('accounts as a', static function ($join): void {
                $join->on('a.id', '=', 'c.account_id')
                    ->on('a.company_id', '=', 'c.company_id');
            })
            ->where('c.company_id', $companyId)
            ->whereNull('a.deleted_at');
    }
}
