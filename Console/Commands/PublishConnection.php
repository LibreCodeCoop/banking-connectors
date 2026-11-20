<?php
// SPDX-FileCopyrightText: 2026 LibreCode Coop and contributors
// SPDX-License-Identifier: AGPL-3.0-or-later
declare(strict_types=1);

namespace Modules\BankingConnectors\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Modules\BankingConnectors\Application\NativePublisher;
use Throwable;

final class PublishConnection extends Command
{
    protected $signature='banking-connectors:publish
        {connection : Existing bank connection ID}
        {--dry-run : Assess matches without changing native or staging data}
        {--max=50 : Maximum bank movements per invocation, 1..100}';
    protected $description='Safely post unmatched bank movements using Akaunting native services';

    public function handle(NativePublisher $publisher): int
    {
        try {
            $id=filter_var($this->argument('connection'),FILTER_VALIDATE_INT);
            $max=filter_var($this->option('max'),FILTER_VALIDATE_INT);
            if (!$id || $id<1 || !$max || $max<1 || $max>100) {
                throw new \DomainException('invalid_native_publishing_scope');
            }
            $company=DB::table('lcc_banking_connectors_connections')
                ->where('id',(int)$id)->value('company_id');
            if ($company===null) throw new \DomainException('bank_connection_not_accessible');
            $summary=$publisher->run((int)$company,(int)$id,
                (bool)$this->option('dry-run'),(int)$max);
            $this->line(json_encode($summary,JSON_THROW_ON_ERROR));
            return $summary['errors']>0 ? self::FAILURE : self::SUCCESS;
        } catch (Throwable $e) {
            $safe=['native_publication_not_enabled','invalid_native_publishing_scope',
                'bank_connection_not_accessible','native_publication_currency_unsupported',
                'multiple_active_bank_feeds_require_review','bank_company_not_found',
                'native_posting_activation_boundary_missing'];
            $this->error(in_array($e->getMessage(),$safe,true) ?
                $e->getMessage() : 'native_publication_failed');
            return self::FAILURE;
        }
    }
}
