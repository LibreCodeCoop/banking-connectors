<?php
// SPDX-FileCopyrightText: 2026 LibreCode Coop and contributors
// SPDX-License-Identifier: AGPL-3.0-or-later
declare(strict_types=1);
require dirname(__DIR__).'/Domain/BankEntry.php';
require dirname(__DIR__).'/Domain/CategoryRuleEngine.php';
require dirname(__DIR__).'/Infrastructure/PdoEntryStage.php';
require __DIR__.'/Support/SqliteStageFixture.php';
use Modules\BankingConnectors\Domain\BankEntry;
use Modules\BankingConnectors\Infrastructure\PdoEntryStage;
use Modules\BankingConnectors\Tests\Support\SqliteStageFixture;
$verify=static function(bool $valid,string $message):void {
 if (!$valid) throw new RuntimeException('FAIL '.$message);
 echo 'PASS '.$message.PHP_EOL;
};
$db=new PDO('sqlite::memory:');
SqliteStageFixture::install($db);
SqliteStageFixture::connection($db,1,1,9,'sicoob');
SqliteStageFixture::connection($db,2,2,9,'sicoob');
$stage=new PdoEntryStage($db);
$key=str_repeat('synthetic-only-fingerprint-key-',2);
$old=new BankEntry(1,9,'sicoob','source-001','2026-11-11','credit',3100,'BRL','PIX recebido');
$verify($stage->ingest(1,1,9,'sicoob',[$old],$key)['inserted']===1,'old staged source created');
$updated=new BankEntry(1,9,'sicoob','source-001','2026-11-11','credit',3100,'BRL','PIX recebido',
 ['document_number'=>'123456','batch_date'=>'2026-11-11','complement'=>'Synthetic extra detail']);
$before=$db->query('SELECT state,amount_cents,content_fingerprint,linked_transaction_id,bank_details FROM lcc_banking_connectors_entries')->fetch(PDO::FETCH_ASSOC);
$verify($stage->backfillStatementDetails(1,1,9,$updated,$key) === 'would_update','dry-run identifies update');
$verify($db->query('SELECT bank_details FROM lcc_banking_connectors_entries')->fetchColumn()===null,'dry-run makes no change');
$verify($stage->backfillStatementDetails(1,1,9,$updated,$key,true)==='updated','apply adds only metadata');
$after=$db->query('SELECT state,amount_cents,content_fingerprint,linked_transaction_id,bank_details FROM lcc_banking_connectors_entries')->fetch(PDO::FETCH_ASSOC);
$verify(($decoded=json_decode($after['bank_details'],true))['fields']['complement']==='Synthetic extra detail','details preserved');
foreach(['state','amount_cents','content_fingerprint','linked_transaction_id'] as $column) $verify($before[$column]===$after[$column],'financial identity unchanged: '.$column);
$verify($stage->backfillStatementDetails(1,1,9,$updated,$key,true)==='unchanged','replay idempotent');
$wrongAmount=new BankEntry(1,9,'sicoob','source-001','2026-11-11','credit',3101,'BRL','PIX recebido',['complement'=>'wrong']);
$verify($stage->backfillStatementDetails(1,1,9,$wrongAmount,$key,true)==='conflict','changed financial amount rejected');
$missing=new BankEntry(1,9,'sicoob','missing','2026-11-11','credit',3100,'BRL','PIX recebido',['complement'=>'unknown']);
$verify($stage->backfillStatementDetails(1,1,9,$missing,$key,true)==='missing','unknown source cannot create row');
$foreign=new BankEntry(2,9,'sicoob','source-001','2026-11-11','credit',3100,'BRL','PIX recebido',['complement'=>'other']);
$verify($stage->backfillStatementDetails(2,2,9,$foreign,$key,true)==='missing','company isolation enforced');
$verify($db->query('SELECT count(*) FROM lcc_banking_connectors_entries')->fetchColumn()==1,'no new accounting rows');
