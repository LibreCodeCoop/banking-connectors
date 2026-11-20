<?php
// SPDX-FileCopyrightText: 2026 LibreCode Coop and contributors
// SPDX-License-Identifier: AGPL-3.0-or-later
declare(strict_types=1);
require dirname(__DIR__).'/Domain/BankEntry.php';
require dirname(__DIR__).'/Domain/CategoryRuleEngine.php';
require dirname(__DIR__).'/Connectors/Sicoob/SicoobMovementDetails.php';
require dirname(__DIR__).'/Connectors/Sicoob/SicoobStatementMapper.php';
require dirname(__DIR__).'/Infrastructure/PdoEntryStage.php';
require __DIR__.'/Support/SqliteStageFixture.php';

use Modules\BankingConnectors\Connectors\Sicoob\SicoobStatementMapper;
use Modules\BankingConnectors\Infrastructure\PdoEntryStage;
use Modules\BankingConnectors\Tests\Support\SqliteStageFixture;
function verifyDetails(bool $condition,string $message):void {
    if (!$condition) throw new RuntimeException('FAIL '.$message);
    echo 'PASS '.$message.PHP_EOL;
}
$db=new PDO('sqlite::memory:');
SqliteStageFixture::install($db);
SqliteStageFixture::connection($db,1,1,9,'sicoob');
SqliteStageFixture::connection($db,2,2,9,'sicoob');
$stage=new PdoEntryStage($db);
$mapper=new SicoobStatementMapper();
$raw=['transactionId'=>'synthetic-1','data'=>'2026-11-20','tipo'=>'C','valor'=>'12.34',
    'descricao'=>'PIX recebido','endToEndId'=>'E12345678901234567890123456789012',
    'nomeRemetente'=>'Pessoa Exemplo','instituicaoOrigem'=>'Instituicao Teste',
    'descInfComplementar'=>'Informacao extra de extrato','dataLote'=>'2026-11-20',
    'access_token'=>'DO-NOT-STORE','cpfCnpjPagador'=>'11111111111',
];
$mapped=$mapper->map(['resultado'=>['transacoes'=>[$raw]]],1,9);
verifyDetails(count($mapped['entries'])===1,'one mapped source movement');
$bank=$mapped['entries'][0];
verifyDetails($bank->details['pix_end_to_end_id']===$raw['endToEndId']
    && $bank->details['sender_name']==='Pessoa Exemplo','Pix identifiers and institution details mapped');
verifyDetails(($bank->details['complement']??null)==='Informacao extra de extrato' &&
    ($bank->details['batch_date']??null)==='2026-11-20',
    'actual Sicoob v4 statement keys are captured');
verifyDetails(($bank->details['sender_document_masked']??null)==='111.***.***-11',
    'payer document masked before persistence');
verifyDetails(!str_contains(json_encode($bank->details),'DO-NOT-STORE')
    && !str_contains(json_encode($bank->details),'11111111111'),'sensitive/unrecognized fields rejected');
$secret=str_repeat('local-test-key-',3);
verifyDetails($stage->ingest(1,1,9,'sicoob',[$bank],$secret)['inserted']===1,'details persisted on first import');
$entry=$stage->listForReview(1,9,1,'all')[0];
verifyDetails(json_decode($entry['bank_details'],true)['fields']['origin_institution']==='Instituicao Teste','details loaded from scoped stage');
verifyDetails($stage->listForReview(2,9,2,'all')===[],'details not visible to foreign company');
$without=$mapper->map(['resultado'=>['transacoes'=>[array_diff_key($raw,array_flip(['endToEndId','nomeRemetente','instituicaoOrigem','cpfCnpjPagador']))]]],1,9)['entries'][0];
verifyDetails($stage->ingest(1,1,9,'sicoob',[$without],$secret)['duplicates']===1,'repeated polling stays idempotent');
verifyDetails($stage->listForReview(1,9,1,'all')[0]['bank_details']===$entry['bank_details'],
    'missing detail fields do not erase imported metadata');
$updated=$mapper->map(['resultado'=>['transacoes'=>[array_replace($raw,['identificacao'=>'Identificador complementar'])]]],1,9)['entries'][0];
verifyDetails($stage->ingest(1,1,9,'sicoob',[$updated],$secret)['duplicates']===1,'repeated import updates detailed metadata safely');
verifyDetails(json_decode($stage->listForReview(1,9,1,'all')[0]['bank_details'],true)['fields']['identification']==='Identificador complementar',
    'new details available after replay without duplicate movement');
$stored=$stage->findForReview(1,9,1,(int)$entry['id'])[0];
$expectedFingerprint=(string)$db->query('SELECT content_fingerprint FROM lcc_banking_connectors_entries WHERE id=1')->fetchColumn();
$stage->enrichDetails(1,9,1,(int)$entry['id'],$expectedFingerprint,[
    'pix_txid'=>'synthetic-additional-txid',
]);
$enriched=$stage->findForReview(1,9,1,(int)$entry['id'])[0];
verifyDetails(json_decode($enriched['bank_details'],true)['fields']['pix_txid']==='synthetic-additional-txid'
    && $enriched['bank_details_enrichment_state']==='complete','explicit enrichment persists only after validation');
verifyDetails($enriched['amount_cents']===$stored['amount_cents'] &&
    $enriched['state']===$stored['state'] &&
    $expectedFingerprint===(string)$db->query('SELECT content_fingerprint FROM lcc_banking_connectors_entries WHERE id=1')->fetchColumn(),
    'Pix enrichment preserves accounting facts and reconciliation state');
try {
    $stage->enrichDetails(2,9,2,(int)$entry['id'],$expectedFingerprint,['pix_txid'=>'wrong-tenant']);
    throw new RuntimeException('FAIL cross-company enrichment accepted');
} catch (DomainException $e) {
    verifyDetails($e->getMessage()==='bank_enrichment_stale_entry','cross-company enrichment rejected');
}
try {
    $stage->enrichDetails(1,9,1,(int)$entry['id'],str_repeat('0',64),['pix_txid'=>'outdated']);
    throw new RuntimeException('FAIL stale fingerprint accepted');
} catch (DomainException $e) {
    verifyDetails($e->getMessage()==='bank_enrichment_stale_entry','stale financial fingerprint rejected');
}
verifyDetails($stage->findForReview(1,9,1,(int)$entry['id'])[0]['bank_details']===$enriched['bank_details'],
    'negative cases did not alter the movement');
