<?php
// SPDX-FileCopyrightText: 2026 LibreCode Coop and contributors
// SPDX-License-Identifier: AGPL-3.0-or-later
declare(strict_types=1);

namespace Modules\BankingConnectors\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Modules\BankingConnectors\Application\ConnectionControl;
use Modules\BankingConnectors\Connectors\Sicoob\SicoobCredentialResolver;
use Throwable;

/**
 * Optional daily certificate probe: local OpenBao Agent files only.
 *
 * No bank calls, no payment operations, no certificate bytes in results,
 * no changes to native Akaunting financial tables. Reuses the exact same
 * account/tenant binding rules as the statement poller.
 */
final class ProbeSicoobCredentials extends Command
{
    protected $signature = 'banking-connectors:sicoob-credential-probe
        {--max=100 : Maximum connections (1..100)}
        {--connection= : Optional company-scoped connection ID for diagnosis}';

    protected $description = 'Verify Sicoob mTLS files and record non-secret expiry metadata';

    public function handle(
        ConnectionControl $control,
        SicoobCredentialResolver $resolver,
    ): int {
        try {
            $limit = filter_var($this->option('max'), FILTER_VALIDATE_INT);
            if (!$limit || $limit < 1 || $limit > 100) {
                $this->error('invalid_bank_probe_limit');
                return self::FAILURE;
            }
            $query = DB::table('lcc_banking_connectors_connections as c')
                ->join('accounts as a', static function ($join): void {
                    $join->on('a.id', '=', 'c.account_id')
                        ->on('a.company_id', '=', 'c.company_id');
                })
                ->where('c.provider', 'sicoob')
                ->whereIn('c.state', ['read_only', 'enabled'])
                ->where('a.type', 'bank')
                ->where('a.currency_code', 'BRL')
                ->where('a.enabled', 1)
                ->whereNull('a.deleted_at')
                ->orderBy('c.id')->limit((int) $limit);
            $selected = $this->option('connection');
            if ($selected !== null && $selected !== '') {
                $id = filter_var($selected, FILTER_VALIDATE_INT);
                if (!$id || $id < 1) {
                    $this->error('invalid_bank_connection');
                    return self::FAILURE;
                }
                $query->where('c.id', (int) $id);
            }
            $records = $query->get([
                'c.id', 'c.company_id', 'c.account_id',
                'c.credential_reference', 'a.number',
            ]);
            $checked = 0;
            $valid = 0;
            $invalid = 0;
            $expiring = 0;
            foreach ($records as $record) {
                ++$checked;
                try {
                    $result = $resolver->verify($record);
                    $control->credentialObserved(
                        (int) $record->company_id, (int) $record->id,
                        $result['valid_to']
                    );
                    ++$valid;
                    if ($result['valid_to'] < time() + 30 * 86400) {
                        ++$expiring;
                    }
                } catch (Throwable $error) {
                    ++$invalid;
                    $control->credentialRejected(
                        (int) $record->company_id, (int) $record->id,
                        $error->getMessage()
                    );
                }
            }
            $this->line(json_encode([
                'status' => $invalid > 0 ? 'attention' : 'ok',
                'checked' => $checked, 'valid' => $valid,
                'invalid' => $invalid, 'expiring_soon' => $expiring,
                'bank_network_requests' => 0, 'financial_writes' => 0,
            ], JSON_THROW_ON_ERROR));
            return $invalid > 0 ? self::FAILURE : self::SUCCESS;
        } catch (Throwable) {
            $this->error('bank_credential_probe_failed');
            return self::FAILURE;
        }
    }
}
