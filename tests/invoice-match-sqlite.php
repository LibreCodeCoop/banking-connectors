<?php
// SPDX-FileCopyrightText: 2026 LibreCode Coop and contributors
// SPDX-License-Identifier: AGPL-3.0-or-later
declare(strict_types=1);
require dirname(__DIR__).'/Domain/CategoryRuleEngine.php';
require dirname(__DIR__).'/Application/InvoiceMatchAdvisor.php';
use Modules\BankingConnectors\Application\InvoiceMatchAdvisor;
function invoiceCheck(bool $value,string $message):void {
 if (!$value) throw new RuntimeException('FAIL '.$message);
 echo 'PASS '.$message.PHP_EOL;
}
$db=new PDO('sqlite::memory:',null,null,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
$db->exec("CREATE TABLE documents (
 id INTEGER PRIMARY KEY,company_id INTEGER NOT NULL,type TEXT NOT NULL,
 status TEXT NOT NULL,deleted_at DATETIME,currency_code TEXT NOT NULL,
 amount NUMERIC NOT NULL,issued_at DATETIME NOT NULL,
 document_number TEXT NOT NULL,contact_name TEXT NOT NULL,category_id INTEGER
)");
$insert=$db->prepare('INSERT INTO documents (id,company_id,type,status,deleted_at,currency_code,amount,issued_at,document_number,contact_name,category_id)
 VALUES(?,?,?,?,?,?,?,?,?,?,?)');
foreach([
 [10,1,'invoice','sent',null,'BRL','250.00','2026-11-01','FT-0010','Cooperativa Serviços Digitais',99],
 [11,2,'invoice','sent',null,'BRL','250.00','2026-11-01','FT-0011','Empresa de Outra Cooperativa',22],
 [12,1,'invoice','draft',null,'BRL','250.00','2026-11-01','FT-0012','Cooperativa Serviços Digitais',99],
 [13,1,'invoice','partial',null,'BRL','250.00','2026-11-01','FT-0013','Cooperativa Serviços Digitais',99],
 [14,1,'invoice','sent',null,'BRL','250.00','2026-11-01','FT-0014','Outro Cliente',98],
 [15,1,'invoice','paid',null,'BRL','250.00','2026-11-01','FT-0015','Cooperativa Serviços Digitais',99],
 [16,1,'bill','sent',null,'BRL','250.00','2026-11-01','FT-0016','Cooperativa Serviços Digitais',99],
 [17,1,'invoice','sent',null,'USD','250.00','2026-11-01','FT-0017','Cooperativa Serviços Digitais',99],
] as $row)$insert->execute($row);
$advisor=new InvoiceMatchAdvisor($db);
$movement=['date'=>'2026-11-20','direction'=>'credit','amount_cents'=>25000,'currency'=>'BRL',
 'bank_details'=>[
  'sender_name'=>['value'=>'Cooperativa Servicos Digitais'],
 ]];
$matched=$advisor->suggest(1,$movement);
invoiceCheck(count($matched)===1 && $matched[0]['id']===10 &&
 $matched[0]['reason']==='payer_and_amount' && $matched[0]['category_id']===99,
 'verified unique payer and amount suggest open invoice category');
invoiceCheck($advisor->suggest(2,$movement)!==[] && $advisor->suggest(2,$movement)[0]['id']===11,
 'other company obtains only its own document');
$noPayer=$movement;$noPayer['bank_details']=[];
$weak=$advisor->suggest(1,$noPayer);
invoiceCheck(count($weak)===2 && $weak[0]['reason']==='amount_only' &&
 $weak[1]['reason']==='amount_only',
 'identical value alone yields only explicitly weak suggestions');
$noPayer['bank_details']=['payer_message'=>['value'=>'Referente a FT-0014']];
$byNumber=$advisor->suggest(1,$noPayer);
invoiceCheck(count($byNumber)===1 && $byNumber[0]['id']===14 &&
 $byNumber[0]['reason']==='invoice_number_and_amount',
 'exact invoice token in payer message is a review candidate');
$noPayer['bank_details']=['payer_message'=>['value'=>'Referente a FT-00140']];
invoiceCheck(count($advisor->suggest(1,$noPayer))===2,
 'invoice number prefix is not accepted as an exact match');
$duplicate=[18,1,'invoice','sent',null,'BRL','250.00','2026-11-01',
 'FT-0018','Cooperativa Serviços Digitais',99];$insert->execute($duplicate);
invoiceCheck(count($advisor->suggest(1,$movement))===2,
 'two invoices for same verified payer remain ambiguous');
$db->exec("UPDATE documents SET status='cancelled' WHERE id=18");
$debit=$movement;$debit['direction']='debit';
invoiceCheck($advisor->suggest(1,$debit)===[], 'Pix sent cannot become a paid invoice');
$currency=$movement;$currency['currency']='USD';
invoiceCheck($advisor->suggest(1,$currency)===[], 'currency scope cannot be overridden');
$future=$movement;$future['date']='2026-09-01';
invoiceCheck($advisor->suggest(1,$future)===[], 'invoice issued after bank date is not suggested');
for($i=100;$i<160;$i++) {
 $insert->execute([$i,1,'invoice','sent',null,'BRL','250.00',
     '2026-11-01','FT-'.$i,'Other Client',null]);
}
invoiceCheck($advisor->suggest(1,$movement)===[],
 'truncated document query never certifies uniqueness');
