<?php
// SPDX-FileCopyrightText: 2026 LibreCode Coop and contributors
// SPDX-License-Identifier: AGPL-3.0-or-later
declare(strict_types=1);

namespace Modules\BankingConnectors\Application;

use DomainException;
use Illuminate\Support\Facades\DB;
use Modules\BankingConnectors\Contracts\BankMovementEnricher;
use Modules\BankingConnectors\Domain\BankEntry;
use Modules\BankingConnectors\Domain\ConnectionContext;
use Modules\BankingConnectors\Infrastructure\PdoEntryStage;

/** One explicit and company-scoped read-only enrichment request. */
final class EnrichBankMovement
{
    public function __construct(
        private readonly BankConnectorRegistry $providers,
        private readonly PdoEntryStage $stage,
    ) {}

    /** @return array{state:string} */
    public function run(int $company,int $account,int $entryId): array
    {
        if ($company<1 || $account<1 || $entryId<1) throw new DomainException('bank_enrichment_not_available');
        $record=DB::table('lcc_banking_connectors_entries as e')
            ->join('lcc_banking_connectors_connections as c',static function($join):void {
                $join->on('c.id','=','e.connection_id')
                    ->on('c.company_id','=','e.company_id')
                    ->on('c.account_id','=','e.account_id');
            })
            ->join('accounts as a',static function($join):void {
                $join->on('a.id','=','e.account_id')->on('a.company_id','=','e.company_id');
            })
            ->where('e.id',$entryId)->where('e.company_id',$company)->where('e.account_id',$account)
            ->whereColumn('c.provider','e.provider')
            ->where('a.type','bank')->where('a.enabled',1)->whereNull('a.deleted_at')
            ->whereIn('c.state',['read_only','enabled'])
            ->where('e.state','!=','conflict')
            ->first(['e.id','e.company_id','e.account_id','e.connection_id','e.provider','e.content_fingerprint',
                'e.movement_date','e.direction','e.amount_cents','e.currency','e.bank_description','e.bank_details',
                'c.credential_reference','a.currency_code','a.number']);
        if ($record===null || $record->provider!=='sicoob') throw new DomainException('bank_enrichment_not_available');
        $connector=$this->providers->get((string)$record->provider);
        if (!$connector instanceof BankMovementEnricher) throw new DomainException('bank_enrichment_not_supported');
        $envelope=json_decode((string)$record->bank_details,true);
        if (!is_array($envelope)) $envelope=[];
        $fields=$envelope['fields']??$envelope;
        if (isset($envelope['schema_version']) &&
            ($envelope['schema_version']!==1 || ($envelope['provider']??null)!==$record->provider)) {
            throw new DomainException('bank_enrichment_metadata_invalid');
        }
        if (!is_array($fields)) throw new DomainException('bank_enrichment_metadata_invalid');
        $verifiedFields=[];
        foreach ($fields as $key=>$value) {
            if (is_string($key) && is_string($value)) $verifiedFields[$key]=$value;
        }
        $bank=new BankEntry($company,$account,(string)$record->provider,null,
            (string)$record->movement_date,(string)$record->direction,(int)$record->amount_cents,
            (string)$record->currency,(string)($record->bank_description??''),$verifiedFields);
        $scope=new ConnectionContext($company,$account,(string)$record->provider,
            (string)$record->currency_code,(string)$record->number,(string)$record->credential_reference);
        $details=$connector->enrich($scope,$bank);
        $this->stage->enrichDetails($company,$account,(int)$record->connection_id,$entryId,
            (string)$record->content_fingerprint,$details);
        return ['state'=>'enriched'];
    }
}
