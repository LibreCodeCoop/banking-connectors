<?php
// SPDX-FileCopyrightText: 2026 LibreCode Coop and contributors
// SPDX-License-Identifier: AGPL-3.0-or-later
declare(strict_types=1);

namespace Modules\BankingConnectors\Console\Commands;

use DateTimeImmutable;
use DomainException;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Modules\BankingConnectors\Application\BankConnectorRegistry;
use Modules\BankingConnectors\Domain\ConnectionContext;
use Modules\BankingConnectors\Infrastructure\PdoEntryStage;
use Throwable;

/** Backfill metadata only. Never starts the normal financial publisher. */
final class BackfillStatementDetails extends Command
{
    protected $signature='banking-connectors:backfill-details
        {connection : Existing connection identifier}
        {--start= : Inclusive ISO start date}
        {--through= : Inclusive ISO end date}
        {--apply : Write verified metadata (default dry-run)}';
    protected $description='Read statement details for existing entries without financial writes';

    public function handle(BankConnectorRegistry $registry,PdoEntryStage $stage): int
    {
        try {
            $id=filter_var($this->argument('connection'),FILTER_VALIDATE_INT,['options'=>['min_range'=>1]]);
            if ($id===false || $id===null) throw new DomainException('invalid_backfill_connection');
            $from=$this->date($this->option('start'));
            $until=$this->date($this->option('through'));
            if ($from>$until || $from->diff($until)->days>35 ||
                $until>new DateTimeImmutable('today',new \DateTimeZone('America/Sao_Paulo'))) {
                throw new DomainException('invalid_backfill_interval');
            }
            $row=DB::table('lcc_banking_connectors_connections as c')
                ->join('accounts as a',static function($join):void {
                    $join->on('a.id','=','c.account_id')->on('a.company_id','=','c.company_id');
                })->where('c.id',(int)$id)->where('c.provider','sicoob')
                ->whereIn('c.state',['enabled','read_only'])->where('a.type','bank')
                ->whereNull('a.deleted_at')->first(['c.company_id','c.account_id','c.provider',
                    'c.credential_reference','a.currency_code','a.number']);
            if (!$row) throw new DomainException('bank_connection_not_available');
            $company=(int)$row->company_id;
            $account=(int)$row->account_id;
            $scope=new ConnectionContext($company,$account,(string)$row->provider,
                (string)$row->currency_code,(string)$row->number,(string)$row->credential_reference);
            $session=$registry->get($scope->providerId)->open($scope);
            $entries=$session->statements->fetch($company,$account,$from,$until);
            // Hard cap for an operator-selected short interval.
            if (count($entries)>500) throw new DomainException('backfill_limit_exceeded');
            $counts=['updated'=>0,'would_update'=>0,'unchanged'=>0,'no_details'=>0,'missing'=>0,'conflict'=>0];
            foreach ($entries as $entry) {
                $result=$stage->backfillStatementDetails((int)$id,$company,$account,
                    $entry,$session->fingerprintKey,(bool)$this->option('apply'));
                ++$counts[$result];
            }
            $this->line(json_encode([
                'status'=>'ok','mode'=>$this->option('apply')?'apply':'dry_run',
                'examined'=>count($entries),'result'=>$counts,
                'native_financial_writes'=>0,
            ],JSON_THROW_ON_ERROR));
            return $counts['conflict']===0?self::SUCCESS:self::FAILURE;
        } catch (Throwable) {
            $this->error('bank_statement_backfill_failed');
            return self::FAILURE;
        }
    }

    private function date(mixed $value): DateTimeImmutable
    {
        if (!is_string($value)) throw new DomainException('invalid_backfill_interval');
        $date=DateTimeImmutable::createFromFormat('!Y-m-d',$value);
        if (!$date || $date->format('Y-m-d')!==$value) throw new DomainException('invalid_backfill_interval');
        return $date;
    }
}
