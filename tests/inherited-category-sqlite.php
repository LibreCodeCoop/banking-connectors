<?php
// SPDX-FileCopyrightText: 2026 LibreCode Coop and contributors
// SPDX-License-Identifier: AGPL-3.0-or-later
declare(strict_types=1);

require dirname(__DIR__).'/Domain/BankEntry.php';
require dirname(__DIR__).'/Domain/CategoryRuleEngine.php';
require dirname(__DIR__).'/Domain/MatchingEngine.php';
require dirname(__DIR__).'/Domain/AkauntingTransactionMapper.php';
require dirname(__DIR__).'/Infrastructure/PdoEntryStage.php';
require dirname(__DIR__).'/Infrastructure/NativeTransactionReader.php';
require dirname(__DIR__).'/Application/ReviewPreview.php';
require __DIR__.'/Support/SqliteStageFixture.php';
use Modules\BankingConnectors\Domain\BankEntry;
use Modules\BankingConnectors\Domain\MatchingEngine;
use Modules\BankingConnectors\Domain\AkauntingTransactionMapper;
use Modules\BankingConnectors\Infrastructure\PdoEntryStage;
use Modules\BankingConnectors\Infrastructure\NativeTransactionReader;
use Modules\BankingConnectors\Application\ReviewPreview;
use Modules\BankingConnectors\Tests\Support\SqliteStageFixture;
$db=new PDO('sqlite::memory:',null,null,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
SqliteStageFixture::install($db);
SqliteStageFixture::connection($db,1,1,9,'sicoob');
$db->exec("INSERT INTO categories(id,company_id,name,type,enabled)
 VALUES (11,1,'Production payout','expense',1)");
$stage=new PdoEntryStage($db);
$secret=str_repeat('review-category-fixture-',2);
$movement=new BankEntry(1,9,'sicoob','source-review','2026-11-11','debit',414836,'BRL','PIX sent');
$stage->ingest(1,1,9,'sicoob',[$movement],$secret);
$id=(int)$db->query('SELECT id FROM lcc_banking_connectors_entries')->fetchColumn();
$db->exec("UPDATE lcc_banking_connectors_entries SET state='reviewed',
 linked_transaction_id=200,category_id=11,category_source='linked_native' WHERE id=$id");
$preview=new ReviewPreview($stage,
 new NativeTransactionReader($db,new AkauntingTransactionMapper()),new MatchingEngine());
$one=$preview->forConnection(1,9,1,50,'pending',3,[],$id);
if(count($one['items'])!==1 || $one['items'][0]['category_id']!==11 ||
 $one['items'][0]['category_source']!=='linked_native'){
 throw new RuntimeException('FAIL linked category missing from dedicated review');
}
echo "PASS native-inherited category remains visible in individual review\n";
$stage->ingest(1,1,9,'sicoob',[$movement],$secret);
$after=$db->query("SELECT state,linked_transaction_id,category_id,category_source
 FROM lcc_banking_connectors_entries WHERE id=$id")->fetch(PDO::FETCH_ASSOC);
if($after['state']!=='reviewed' || (int)$after['linked_transaction_id']!==200 ||
 (int)$after['category_id']!==11 || $after['category_source']!=='linked_native'){
 throw new RuntimeException('FAIL bank replay erased reviewed category or association');
}
echo "PASS replay cannot erase reviewed native association and inherited category\n";
