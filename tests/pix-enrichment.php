<?php
// SPDX-FileCopyrightText: 2026 LibreCode Coop and contributors
// SPDX-License-Identifier: AGPL-3.0-or-later
declare(strict_types=1);
require dirname(__DIR__).'/Domain/BankEntry.php';
require dirname(__DIR__).'/Connectors/Sicoob/SicoobMovementDetails.php';
require dirname(__DIR__).'/Connectors/Sicoob/SicoobPixEnrichment.php';
require dirname(__DIR__).'/Contracts/BankTransportFailure.php';
require dirname(__DIR__).'/Connectors/Sicoob/SicoobHttpFailure.php';
require dirname(__DIR__).'/Connectors/Sicoob/SicoobHttpClient.php';

use Modules\BankingConnectors\Domain\BankEntry;
use Modules\BankingConnectors\Connectors\Sicoob\SicoobPixEnrichment;
use Modules\BankingConnectors\Connectors\Sicoob\SicoobHttpClient;
use Modules\BankingConnectors\Connectors\Sicoob\SicoobMovementDetails;

function passPix(bool $condition,string $name):void {
    if (!$condition) throw new RuntimeException('FAIL '.$name);
    echo 'PASS '.$name.PHP_EOL;
}
function rejectPix(callable $callback,string $expected):void {
    try { $callback(); } catch (DomainException $error) {
        passPix($error->getMessage()===$expected,$expected);return;
    }
    throw new RuntimeException('FAIL expected '.$expected);
}
$e2e='E12345678'.str_repeat('A',23);
$entry=new BankEntry(1,9,'sicoob',null,'2026-11-20','credit',1250,
    'BRL','Pix recebido',['pix_end_to_end_id'=>$e2e]);
$pix=['endToEndId'=>$e2e,'valor'=>'12.50','horario'=>'2026-11-20T15:20:00.000Z',
    'txid'=>'synthetic-txid-1','infoPagador'=>'Pagamento dos serviços','pagador'=>['nome'=>'Pessoa Teste','cpf'=>'12345678901'],
    'some_secret'=>'never-keep'];
$details=SicoobPixEnrichment::verify($entry,$pix);
passPix($details['pix_end_to_end_id']===$e2e && $details['pix_txid']==='synthetic-txid-1',
    'Pix unique identifiers mapped');
passPix($details['sender_name']==='Pessoa Teste' &&
    $details['sender_document_masked']==='123.***.***-01', 'Pix payer scoped and masked');
passPix(($details['payer_message']??null)==='Pagamento dos serviços',
    'Pix payer message available when supplied');
passPix(!str_contains(json_encode($details),'never-keep') &&
    !str_contains(json_encode($details),'12345678901'), 'Pix payload not persisted wholesale');
passPix(str_starts_with(SicoobMovementDetails::definitions()['pix_end_to_end_id']['label'],'banking-connectors::general.'),
    'Sicoob advertises details via provider-owned schema');
rejectPix(static fn()=>SicoobPixEnrichment::verify($entry,array_replace($pix,['endToEndId'=>'E12345678'.str_repeat('B',23)])),'pix_identity_mismatch');
rejectPix(static fn()=>SicoobPixEnrichment::verify($entry,array_replace($pix,['valor'=>'12.51'])),'pix_financial_facts_mismatch');
rejectPix(static fn()=>SicoobPixEnrichment::verify($entry,array_replace($pix,['horario'=>'2026-11-27T15:20:00Z'])),'pix_date_mismatch');
rejectPix(static fn()=>SicoobPixEnrichment::verify($entry,array_replace($pix,['horario'=>'tomorrow'])),'pix_time_invalid');
$debit=new BankEntry(1,9,'sicoob',null,'2026-11-20','debit',1250,'BRL',null,['pix_end_to_end_id'=>$e2e]);
rejectPix(static fn()=>SicoobPixEnrichment::verify($debit,$pix),'pix_enrichment_not_supported');
$tokenFile=tempnam(sys_get_temp_dir(),'pix-sandbox-');
if (!$tokenFile) throw new RuntimeException('test setup failed');
file_put_contents($tokenFile,'synthetic-sandbox-token');
try {
    $calls=0;
    $client=new SicoobHttpClient([
        'MODE'=>'sandbox','CLIENT_ID'=>'synthetic','ACCOUNT_NUMBER'=>'123456',
        'API_BASE_URL'=>'https://sandbox.sicoob.com.br/conta-corrente/v4',
        'AUTH_URL'=>'https://sandbox.sicoob.com.br/token',
        'PIX_API_BASE_URL'=>'https://sandbox.sicoob.com.br/pix/api/v2',
        'SANDBOX_TOKEN_FILE'=>$tokenFile,
    ],static function(string $method,string $url,array $headers) use(&$calls,$e2e,$pix):array {
        ++$calls;
        passPix($method==='GET' && str_ends_with($url,'/pix/'.$e2e),'Pix endpoint is read only');
        passPix(in_array('Authorization: Bearer synthetic-sandbox-token',$headers,true),'Pix token from existing sandbox mount');
        return ['status'=>200,'body'=>json_encode($pix,JSON_THROW_ON_ERROR)];
    });
    passPix($client->receivedPix($e2e)['endToEndId']===$e2e && $calls===1,
        'single authorized Pix lookup');
    try { $client->receivedPix('../evil');throw new RuntimeException('invalid E2E accepted'); }
    catch(InvalidArgumentException) {passPix($calls===1,'invalid E2E rejected before network');}
    $missing=new SicoobHttpClient([
        'MODE'=>'sandbox','CLIENT_ID'=>'synthetic','ACCOUNT_NUMBER'=>'123456',
        'SANDBOX_TOKEN_FILE'=>$tokenFile,
    ],static function() use (&$calls):array {++$calls;return ['status'=>200,'body'=>'{}'];});
    rejectPix(static fn()=>$missing->receivedPix($e2e),'pix_enrichment_not_configured');
    passPix($calls===1,'Pix feature defaults off');
} finally { @unlink($tokenFile); }
// A production Pix lookup must not reuse a current-account-only token.
$paths=[];
foreach (['cert','key','pass'] as $part) {
    $path=tempnam(sys_get_temp_dir(),'pix-test-'.$part);
    if (!$path) throw new RuntimeException('test setup failed');
    file_put_contents($path,'synthetic');
    $paths[]=$path;
}
try {
    $scopes=[];
    $getRequests=0;
    $client=new SicoobHttpClient([
        'MODE'=>'production','CLIENT_ID'=>'synthetic','ACCOUNT_NUMBER'=>'123456',
        'API_BASE_URL'=>'https://api.sicoob.com.br/conta-corrente/v4',
        'PIX_API_BASE_URL'=>'https://api.sicoob.com.br/pix/api/v2',
        'AUTH_URL'=>'https://auth.sicoob.com.br/token',
        'TLS_CERT_PATH'=>$paths[0],'TLS_KEY_PATH'=>$paths[1],
        'TLS_KEY_PASSWORD_FILE'=>$paths[2],
    ],static function(string $method,string $url,array $headers,?array $form) use(&$scopes,&$getRequests,$e2e,$pix):array {
        if ($method==='POST') {
            $scope=$form['scope']??'';
            $scopes[]=$scope;
            return ['status'=>200,'body'=>json_encode([
                'access_token'=>'synthetic-'.$scope,'expires_in'=>600,
            ],JSON_THROW_ON_ERROR)];
        }
        ++$getRequests;
        if (str_contains($url,'/pix/')) {
            passPix(in_array('Authorization: Bearer synthetic-pix.read',$headers,true),
                'Pix receives an independently scoped token');
            return ['status'=>200,'body'=>json_encode($pix,JSON_THROW_ON_ERROR)];
        }
        passPix(in_array('Authorization: Bearer synthetic-cco_consulta',$headers,true),
            'statement uses its original read-only scope');
        return ['status'=>200,'body'=>'{"resultado":{"transacoes":[]}}'];
    });
    $client->statement(2026,11,20,20);
    $client->receivedPix($e2e);
    $client->receivedPix($e2e);
    passPix($scopes===['cco_consulta','pix.read'] && $getRequests===3,
        'production OAuth scopes cached independently and reused safely');
} finally {
    foreach ($paths as $path) @unlink($path);
}
