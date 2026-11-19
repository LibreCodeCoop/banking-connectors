<?php
// SPDX-FileCopyrightText: 2026 LibreCode Coop and contributors
// SPDX-License-Identifier: AGPL-3.0-or-later
declare(strict_types=1);

require dirname(__DIR__) . '/Domain/BankEntry.php';
require dirname(__DIR__) . '/Domain/CategoryRuleEngine.php';
require dirname(__DIR__) . '/Domain/AkauntingTransactionMapper.php';
require dirname(__DIR__) . '/Domain/MatchingEngine.php';
require dirname(__DIR__) . '/Infrastructure/NativeTransactionReader.php';
require dirname(__DIR__) . '/Infrastructure/PdoEntryStage.php';
require dirname(__DIR__) . '/Application/ReviewDecisionService.php';
require dirname(__DIR__) . '/Application/CategoryWorkflow.php';
require __DIR__ . '/Support/SqliteStageFixture.php';

use Modules\BankingConnectors\Application\CategoryWorkflow;
use Modules\BankingConnectors\Application\ReviewDecisionService;
use Modules\BankingConnectors\Domain\AkauntingTransactionMapper;
use Modules\BankingConnectors\Domain\BankEntry;
use Modules\BankingConnectors\Domain\CategoryRuleEngine;
use Modules\BankingConnectors\Domain\MatchingEngine;
use Modules\BankingConnectors\Infrastructure\NativeTransactionReader;
use Modules\BankingConnectors\Infrastructure\PdoEntryStage;
use Modules\BankingConnectors\Tests\Support\SqliteStageFixture;

function verdict(bool $ok,string $message): void {
    if (!$ok) throw new RuntimeException('FAIL '.$message);
    echo 'PASS '.$message.PHP_EOL;
}

$db=new PDO('sqlite::memory:',null,null,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
SqliteStageFixture::install($db);
SqliteStageFixture::installReview($db);
$db->exec("INSERT INTO accounts (id,company_id,type,enabled)
    VALUES (9,1,'bank',1),(10,1,'bank',1),(11,2,'bank',1)");
$db->exec("INSERT INTO categories (id,company_id,name,type,enabled)
    VALUES (11,1,'Vendas','income',1),(12,1,'Tarifas','expense',1),
           (13,1,'Outras receitas','income',1),(14,1,'Administrativo','expense',1),
           (99,2,'Categoria alheia','expense',1)");
SqliteStageFixture::connection($db,1,1,9,'sicoob');
SqliteStageFixture::connection($db,2,2,11,'sicoob');
$db->exec("CREATE TABLE transactions (
    id INTEGER PRIMARY KEY,company_id INTEGER NOT NULL,account_id INTEGER NOT NULL,
    type VARCHAR(24) NOT NULL,paid_at DATETIME NOT NULL,amount NUMERIC NOT NULL,
    currency_code CHAR(3) NOT NULL,category_id INTEGER NULL,
    reconciled INTEGER NOT NULL DEFAULT 0,document_id INTEGER NULL,
    parent_id INTEGER NOT NULL DEFAULT 0,split_id INTEGER NULL,
    deleted_at DATETIME NULL,updated_at DATETIME NULL
)");
$db->exec("INSERT INTO transactions
    (id,company_id,account_id,type,paid_at,amount,currency_code,category_id,reconciled)
    VALUES (101,1,9,'income','2026-11-19 10:00:00',10.00,'BRL',13,0),
           (102,1,9,'expense','2026-11-19 10:00:00',2.50,'BRL',14,1),
           (103,2,11,'income','2026-11-19 10:00:00',10.00,'BRL',99,0),
           (104,1,10,'income','2026-11-19 10:00:00',10.00,'BRL',13,0),
           (105,1,9,'income','2026-11-19 10:00:00',9.99,'BRL',13,0)");

$category=new CategoryWorkflow($db);
$rule=$category->createRule(1,9,7,'debit','TARIFA MANUTENÇÃO',12,20);
verdict($rule>0,'category rules can be added to one authorized bank account');
$needle=CategoryRuleEngine::needle('TARIFA MANUTENÇÃO');
verdict($needle==='tarifa manutencao','accent-insensitive bank description matching');
try { $category->createRule(1,9,7,'debit','tarifa manutencao',12,20); throw new RuntimeException('FAIL duplicate rule'); }
catch (DomainException $e) { verdict($e->getMessage()==='duplicate_category_rule','rule duplicates denied after normalization'); }
try { $category->createRule(1,9,7,'debit','TARIFA EXTERNA',99,20); throw new RuntimeException('FAIL foreign category'); }
catch (DomainException $e) { verdict($e->getMessage()==='category_not_accessible','other-company category rejected'); }
try { $category->createRule(1,9,7,'credit','TARIFA ERRADA',12,20); throw new RuntimeException('FAIL opposite type'); }
catch (DomainException $e) { verdict($e->getMessage()==='category_not_accessible','opposite-direction category rejected'); }

$stage=new PdoEntryStage($db);
$secret=str_repeat('synthetic-classification-only-',2);
$a=new BankEntry(1,9,'sicoob','fee1','2026-11-19','debit',250,'BRL','Tarifa manutencao mensal');
$b=new BankEntry(1,9,'sicoob','income1','2026-11-19','credit',1000,'BRL','PIX cliente');
$stage->ingest(1,1,9,'sicoob',[$a,$b],$secret);
$rows=$stage->listUnresolved(1,9,1);
$fee=array_values(array_filter($rows,static fn($r)=>$r['direction']==='debit'))[0];
$income=array_values(array_filter($rows,static fn($r)=>$r['direction']==='credit'))[0];
verdict((int)$fee['category_id']===12 && $fee['category_source']==='rule' &&
        (int)$fee['category_rule_id']===$rule,
        'bank fee classified automatically during read-only import');
verdict($fee['bank_description']==='Tarifa manutencao mensal',
        'bank description retained without raw provider payload');
verdict($income['category_id']===null,'unrelated Pix does not inherit unrelated expense rule');

$before=$db->query('SELECT id,type,amount,category_id FROM transactions ORDER BY id')
    ->fetchAll(PDO::FETCH_ASSOC);
$category->classify(1,9,(int)$fee['id'],7,14,false,null,str_repeat('a',32));
$stage->ingest(1,1,9,'sicoob',[$a],$secret);
$fee2=$db->query("SELECT category_id,category_source FROM lcc_banking_connectors_entries
    WHERE id=".$fee['id'])->fetch(PDO::FETCH_ASSOC);
verdict((int)$fee2['category_id']===14 && $fee2['category_source']==='manual',
    'manual categorization is preserved through bank replay');
$category->enableRule(1,9,$rule,false);
$otherFee=new BankEntry(1,9,'sicoob','fee2','2026-11-19','debit',350,'BRL','TARIFA MANUTENÇÃO');
$stage->ingest(1,1,9,'sicoob',[$otherFee],$secret);
$other=$db->query("SELECT category_id FROM lcc_banking_connectors_entries
    WHERE source_fingerprint='".$otherFee->fingerprint($secret)."'")->fetchColumn();
verdict($other===null,'disabled category rule no longer auto-classifies new movements');

$reader=new NativeTransactionReader($db,new AkauntingTransactionMapper());
$review=new ReviewDecisionService($db,$reader,new MatchingEngine());
$review->decide(1,9,1,(int)$income['id'],7,'accept',101,str_repeat('b',32));
$saved=$category->classify(1,9,(int)$income['id'],7,11,true,13,str_repeat('c',32));
verdict($saved['applied_to_native'] && !$saved['idempotent'],'explicit confirmed native category update succeeds');
$updated=$db->query('SELECT type,amount,category_id FROM transactions WHERE id=101')->fetch(PDO::FETCH_ASSOC);
verdict((int)$updated['category_id']===11 && $updated['amount']==10 &&
    $updated['type']==='income','native change modifies category only');
$replay=$category->classify(1,9,(int)$income['id'],7,11,true,13,str_repeat('c',32));
verdict($replay['idempotent'] && $replay['event_id']===$saved['event_id'],
    'category update replay is idempotent');
try {$category->classify(1,9,(int)$income['id'],7,13,true,13,str_repeat('d',32));throw new RuntimeException('FAIL stale category');}
catch (DomainException $e) {verdict($e->getMessage()==='native_category_changed_since_preview','stale native category edit rejected');}
try {$category->classify(1,9,(int)$fee['id'],7,12,true,null,str_repeat('e',32));throw new RuntimeException('FAIL unreviewed apply');}
catch (DomainException $e){verdict($e->getMessage()==='native_category_requires_reviewed_link','unreviewed bank entry cannot update Akaunting');}
try {$category->classify(2,11,(int)$income['id'],7,99,false,null,str_repeat('f',32));throw new RuntimeException('FAIL cross tenant');}
catch (DomainException $e){verdict($e->getMessage()==='category_entry_not_accessible','cross-company classification rejected');}
$after=$db->query('SELECT id,type,amount,category_id FROM transactions ORDER BY id')->fetchAll(PDO::FETCH_ASSOC);
verdict(count($before)===count($after) && (int)$after[0]['category_id']===11 &&
    $before[0]['type']===$after[0]['type'] && $before[0]['amount']===$after[0]['amount'],
    'native ledger count, money and direction unchanged');
$audits=$db->query('SELECT COUNT(*) FROM lcc_banking_connectors_category_events')->fetchColumn();
verdict((int)$audits===2,'manual rule classification and native edit are auditable');
echo "Category workflow safe classification checks passed.\n";
