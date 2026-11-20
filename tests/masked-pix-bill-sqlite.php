<?php
// SPDX-FileCopyrightText: 2026 LibreCode Coop and contributors
// SPDX-License-Identifier: AGPL-3.0-or-later
declare(strict_types=1);

require dirname(__DIR__).'/Connectors/Sicoob/SicoobMaskedPixCpf.php';
require dirname(__DIR__).'/Application/MaskedPixBillAdvisor.php';

use Modules\BankingConnectors\Application\MaskedPixBillAdvisor;
use Modules\BankingConnectors\Connectors\Sicoob\SicoobMaskedPixCpf;

function checkMasked(bool $value,string $name):void {
    if (!$value) throw new RuntimeException('FAIL '.$name);
    echo 'PASS '.$name.PHP_EOL;
}

// Only precisely recognized debit-Pix recipient masks are accepted.
$reference='PAGAMENTO PIX|@***.982.247-**|@';
$masked=SicoobMaskedPixCpf::fromStatement('sicoob','debit','PIX enviado',$reference);
checkMasked($masked!==null && $masked->matches('529.982.247-25') &&
    $masked->visibleDigits()===6 && $masked->visibleMatchPercent('52998224725')===100,
    'six visible CPF digits fully match the contact without exposing the CPF');
checkMasked(!$masked->matches('529.982.248-06'),
    'a different visible digit rejects the contact');
checkMasked(!$masked->matches('529.982.247-24'),
    'malformed full CPF check digits cannot support automatic suggestions');
foreach([
    ['sicoob','credit','PIX recebido',$reference],
    ['other','debit','PIX enviado',$reference],
    ['sicoob','debit','Transferencia',$reference],
    ['sicoob','debit','PIX enviado','PAGAMENTO PIX 982247'],
    ['sicoob','debit','PIX enviado','PAGAMENTO PIX|@***.982.247-**|@***.982.247-**'],
    ['sicoob','debit','PIX enviado','RECEBIMENTO PIX|@***.982.247-**|@'],
    ['sicoob','debit','PIX enviado','PAGAMENTO PIX|@***.982.247-**.5|@'],
] as [$provider,$dir,$description,$complement]) {
    checkMasked(SicoobMaskedPixCpf::fromStatement($provider,$dir,$description,$complement)===null,
        'untrusted or ambiguous masked bank recipient format rejected');
}

$db=new PDO('sqlite::memory:',null,null,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
$db->exec("CREATE TABLE contacts(id INTEGER PRIMARY KEY,company_id INTEGER,type TEXT,enabled INTEGER,deleted_at TEXT,tax_number TEXT)");
$db->exec("CREATE TABLE documents(id INTEGER PRIMARY KEY,company_id INTEGER,type TEXT,status TEXT,deleted_at TEXT,
 currency_code TEXT,amount NUMERIC,contact_id INTEGER,contact_tax_number TEXT,document_number TEXT,category_id INTEGER)");
$db->exec("CREATE TABLE transactions(id INTEGER PRIMARY KEY,company_id INTEGER,account_id INTEGER,document_id INTEGER,
 type TEXT,amount NUMERIC,currency_code TEXT,paid_at TEXT,deleted_at TEXT)");
$db->exec("CREATE TABLE lcc_banking_connectors_entries(
 id INTEGER PRIMARY KEY,company_id INTEGER,account_id INTEGER,provider TEXT,
 direction TEXT,currency TEXT,state TEXT,amount_cents INTEGER,movement_date TEXT,
 bank_description TEXT,bank_details TEXT,linked_transaction_id INTEGER)");
$bankDetails=json_encode(['schema_version'=>1,'provider'=>'sicoob','fields'=>['complement'=>$reference]],JSON_THROW_ON_ERROR);
$stage=$db->prepare('INSERT INTO lcc_banking_connectors_entries
(id,company_id,account_id,provider,direction,currency,state,amount_cents,movement_date,bank_description,bank_details,linked_transaction_id)
VALUES(900,1,9,?,?,?,?,?,?,?,?,NULL)');
$stage->execute(['sicoob','debit','BRL','pending',414836,'2026-11-11','PIX enviado',$bankDetails]);
$db->exec("INSERT INTO contacts VALUES
 (1,1,'vendor',1,NULL,'529.982.247-25'),
 (2,1,'vendor',1,NULL,'168.995.350-09'),
 (3,2,'vendor',1,NULL,'529.982.247-25')");
$db->exec("INSERT INTO documents VALUES
 (10,1,'bill','paid',NULL,'BRL',4148.36,1,'529.982.247-25','DOC-10',11),
 (11,1,'bill','paid',NULL,'BRL',4148.36,2,'168.995.350-09','DOC-11',12),
 (12,2,'bill','paid',NULL,'BRL',4148.36,3,'529.982.247-25','DOC-12',13),
 (13,1,'bill','sent',NULL,'BRL',4148.36,1,'529.982.247-25','DOC-13',11)");
$db->exec("INSERT INTO transactions VALUES
 (50,1,9,10,'expense',4148.36,'BRL','2026-11-11 08:00:00',NULL),
 (51,1,9,11,'expense',4148.36,'BRL','2026-11-11 08:00:00',NULL),
 (52,2,9,12,'expense',4148.36,'BRL','2026-11-11 08:00:00',NULL)");
$advisor=new MaskedPixBillAdvisor($db);
$bank=['id'=>900,'provider'=>'sicoob','direction'=>'debit','description'=>'PIX enviado',
    'date'=>'2026-11-11','amount_cents'=>414836,'currency'=>'BRL',
    'stage_state'=>'pending','bank_details'=>['complement'=>['value'=>$reference]]];
$original=$db->query('SELECT * FROM transactions ORDER BY id')->fetchAll(PDO::FETCH_ASSOC);
$link=$advisor->suggest(1,9,$bank);
checkMasked($link!==null && $link['document_id']===10 && $link['native_transaction_id']===50 &&
    $link['visible_digits']===6 && $link['matching_visible_percent']===100 &&
    $link['status']==='provisional',
    'automatic provisional document-to-bank association uses masked CPF, amount and native payment');
checkMasked($advisor->suggest(2,9,$bank)===null,
    'other company cannot associate another company banking entry');
checkMasked($advisor->suggest(1,10,$bank)===null,
    'another bank account cannot reuse a movement and its payment');
$credit=$bank;$credit['direction']='credit';
checkMasked($advisor->suggest(1,9,$credit)===null,'outgoing Pix mask cannot auto-link an incoming invoice');
$wrongAmount=$bank;$wrongAmount['amount_cents']++;
checkMasked($advisor->suggest(1,9,$wrongAmount)===null,'one cent difference is rejected');
$changedStage=$bank;$changedStage['description']='PIX enviado alterado';
checkMasked($advisor->suggest(1,9,$changedStage)===null,
    'altered preview data is not accepted as bank-origin evidence');
$wrongDate=$bank;$wrongDate['date']='2026-09-01';
checkMasked($advisor->suggest(1,9,$wrongDate)===null,'distant payment dates prevent stale association');
$wrongBank=$bank;$wrongBank['provider']='other';
checkMasked($advisor->suggest(1,9,$wrongBank)===null,'provider-specific evidence cannot apply to unrelated banks');
$wrongStatus=$bank;$wrongStatus['stage_state']='reviewed';
checkMasked($advisor->suggest(1,9,$wrongStatus)===null,'already reviewed entries cannot acquire a new implicit relationship');
$db->exec("UPDATE documents SET contact_tax_number='529.982.246-44' WHERE id=10");
checkMasked($advisor->suggest(1,9,$bank)===null,
    'contradictory document and live contact identities prevent association');
$db->exec("UPDATE documents SET contact_tax_number='529.982.247-25' WHERE id=10");
$db->exec("INSERT INTO documents VALUES
 (15,1,'bill','paid',NULL,'BRL',4148.36,1,'529.982.247-25','DOC-15',11)");
$db->exec("INSERT INTO transactions VALUES
 (55,1,9,15,'expense',4148.36,'BRL','2026-11-11 08:00:00',NULL)");
checkMasked($advisor->suggest(1,9,$bank)===null,
    'multiple paid bills to same masked recipient and amount abstain');
$db->exec('DELETE FROM transactions WHERE id=55');
$db->exec('DELETE FROM documents WHERE id=15');
$linkedStage=$db->prepare('INSERT INTO lcc_banking_connectors_entries
 (id,company_id,account_id,linked_transaction_id) VALUES(800,1,9,50)');
$linkedStage->execute();
checkMasked($advisor->suggest(1,9,$bank)===null,
    'native payment already linked to another bank entry cannot be proposed again');
$db->exec('DELETE FROM lcc_banking_connectors_entries WHERE id=800');
$db->exec("INSERT INTO contacts VALUES (4,1,'vendor',1,NULL,'529.982.247-25')");
$shared=$advisor->suggest(1,9,$bank);
checkMasked($shared!==null && $shared['compatible_contacts']===2 &&
    $shared['status']==='provisional',
    'colliding contact masks remain explicitly provisional even with exact amount');
checkMasked($db->query('SELECT * FROM transactions ORDER BY id')->fetchAll(PDO::FETCH_ASSOC)===$original,
    'automatic proposed relationship does not post, reconcile or modify financial transactions');
