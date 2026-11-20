<?php
// SPDX-FileCopyrightText: 2026 LibreCode Coop and contributors
// SPDX-License-Identifier: AGPL-3.0-or-later
declare(strict_types=1);

require dirname(__DIR__) . '/Domain/CategoryRuleEngine.php';
require dirname(__DIR__) . '/Domain/TokenCategoryLearner.php';
require dirname(__DIR__) . '/Application/CategoryLearningAdvisor.php';
require __DIR__ . '/Support/SqliteStageFixture.php';

use Modules\BankingConnectors\Application\CategoryLearningAdvisor;
use Modules\BankingConnectors\Domain\TokenCategoryLearner;
use Modules\BankingConnectors\Tests\Support\SqliteStageFixture;

function verifyLearning(bool $ok,string $context): void {
    if (!$ok) throw new RuntimeException('FAIL '.$context);
    echo 'PASS '.$context.PHP_EOL;
}

$db=new PDO('sqlite::memory:',null,null,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
SqliteStageFixture::install($db);
SqliteStageFixture::installReview($db);
$db->exec("INSERT INTO accounts (id,company_id,name,type,enabled)
    VALUES (9,1,'Account A','bank',1),(10,2,'Account B','bank',1)");
$db->exec("INSERT INTO categories (id,company_id,name,type,enabled) VALUES
    (12,1,'Transport','expense',1),(14,1,'Tax payment','expense',1),
    (19,2,'Different company category','expense',1)");
SqliteStageFixture::connection($db,1,1,9,'sicoob');
SqliteStageFixture::connection($db,2,2,10,'sicoob');
$db->exec("CREATE TABLE contacts (
    id INTEGER PRIMARY KEY,company_id INTEGER NOT NULL,name TEXT NOT NULL,
    type VARCHAR(16) NOT NULL,category_id INTEGER NULL,
    enabled INTEGER NOT NULL DEFAULT 1,deleted_at DATETIME NULL
)");
$db->exec("INSERT INTO contacts VALUES
    (10,1,'Cooperativa Transportes São José','vendor',12,1,NULL),
    (11,1,'Cooperativa de Serviços Digitais','customer',NULL,1,NULL),
    (12,2,'Empresa Não Visível','vendor',19,1,NULL),
    (13,1,'Companhia Desativada','vendor',12,0,NULL)");

$db->exec("CREATE TABLE transactions (
    id INTEGER PRIMARY KEY, company_id INTEGER NOT NULL,
    account_id INTEGER NOT NULL, category_id INTEGER NULL,
    type VARCHAR(24) NOT NULL DEFAULT 'expense', deleted_at DATETIME NULL
)");
$advisor=new CategoryLearningAdvisor($db);
$row=['id'=>987,'description'=>'PIX pagamento rodoviaria transporte frota',
    'direction'=>'debit','stage_state'=>'pending','category_source'=>null];
$cold=$advisor->advise(1,9,[$row]);
verifyLearning(!$cold['ready'] && $cold['total_labels']===0 &&
    $cold['suggestions']===[], 'no learned suggestions without human examples');
verifyLearning((new TokenCategoryLearner())->suggest(
    [['direction'=>'debit','description'=>'DARF tributo federal',
      'category_id'=>14]],'debit','DARF tributo federal')===null,
    'machine learning abstains on one training example');
$monoclass=array_fill(0,20,[
    'direction'=>'debit','description'=>'DARF imposto tributo federal',
    'category_id'=>14,
]);
verifyLearning((new TokenCategoryLearner())->suggest(
    $monoclass,'debit','DARF imposto tributo federal')===null,
    'one-category history cannot claim accurate discrimination');

$put=$db->prepare("INSERT INTO lcc_banking_connectors_entries
    (id,connection_id,company_id,account_id,provider,source_fingerprint,
    content_fingerprint,movement_date,direction,amount_cents,currency,
    bank_description,category_source,category_id,state,conflict_count,
    first_seen_at,last_seen_at) VALUES
    (:id,:connection,:company,:account,'sicoob',:source,:content,
    '2026-11-19','debit',1900,'BRL',:description,'manual',:category,'pending',0,
    '2026-11-19 11:00:00','2026-11-19 11:00:00')");
$audit=$db->prepare("INSERT INTO lcc_banking_connectors_category_events
    (company_id,account_id,entry_id,actor_id,operation,old_category_id,
     new_category_id,native_transaction_id,request_key,occurred_at)
     VALUES (:company,:account,:entry,7,'assign',NULL,:category,NULL,:key,
     '2026-11-19 11:00:00')");
for ($i=1;$i<=16;$i++) {
    $category=$i<=8?12:14;
    $desc=$category===12
        ? 'PIX viagem rodoviaria transporte frota viagens rota ' . $i
        : 'PIX DARF imposto tributo receita federal arrecadacao ' . $i;
    $put->execute([
        ':id'=>$i,':connection'=>1,':company'=>1,':account'=>9,
        ':source'=>hash('sha256','synthetic-bank-id-'.$i),
        ':content'=>hash('sha256','synthetic-bank-content-'.$i),
        ':description'=>$desc,':category'=>$category,
    ]);
    $audit->execute([
        ':company'=>1,':account'=>9,':entry'=>$i,':category'=>$category,
        ':key'=>md5('manual-classification-'.$i),
    ]);
}
$learn=$advisor->advise(1,9,[
    $row,
    ['id'=>988,'description'=>'Pagamento DARF imposto tributo federal',
        'direction'=>'debit','stage_state'=>'pending','category_source'=>null],
    ['id'=>989,'description'=>'PIX entre contas sem detalhes',
        'direction'=>'debit','stage_state'=>'pending','category_source'=>null],
    ['id'=>990,'description'=>'DARF receita federal',
        'direction'=>'credit','stage_state'=>'pending','category_source'=>null],
]);
verifyLearning($learn['total_labels']===16 && $learn['ready'],
    'human-reviewed labels become available as model training data');
verifyLearning(($learn['suggestions'][987]['category_id']??null)===12 &&
    $learn['suggestions'][987]['class_examples']===8,
    'Bayesian model generalizes travel wording from synthetic confirmations');
verifyLearning(($learn['suggestions'][988]['category_id']??null)===14,
    'Bayesian model distinguishes tax category from transport');
verifyLearning(!isset($learn['suggestions'][989]) &&
    !isset($learn['suggestions'][990]),
    'unknown words and opposite directions abstain');
verifyLearning(TokenCategoryLearner::tokens('PIX 12/11/2026 123.456.789-00')
    ===[], 'date, identifiers and generic PIX markers are not model features');

$contact=$advisor->advise(1,9,[
    ['id'=>991,'description'=>'PIX pago COOPERATIVA TRANSPORTES SAO JOSE',
        'direction'=>'debit','stage_state'=>'pending','category_source'=>null],
    ['id'=>992,'description'=>'PIX pago EMPRESA NAO VISIVEL',
        'direction'=>'debit','stage_state'=>'pending','category_source'=>null],
    ['id'=>993,'description'=>'PIX recebido COOPERATIVA DE SERVICOS DIGITAIS',
        'direction'=>'credit','stage_state'=>'pending','category_source'=>null],
], ['customer','vendor']);
verifyLearning(($contact['contacts'][991]['id']??null)===10 &&
    ($contact['contacts'][991]['type']??null)==='vendor',
    'exact unique legal name suggests company-owned vendor only');
verifyLearning(($contact['contacts'][993]['id']??null)===11 &&
    ($contact['contacts'][993]['type']??null)==='customer',
    'credit direction only suggests a customer');
verifyLearning(!isset($contact['contacts'][992]),
    'other-company contact is inaccessible even with exact name');
$withoutContactPermission=$advisor->advise(1,9,[[
    'id'=>991,'description'=>'PIX pago COOPERATIVA TRANSPORTES SAO JOSE',
    'direction'=>'debit','stage_state'=>'pending','category_source'=>null
]]);
verifyLearning($withoutContactPermission['contacts']===[],
    'bank read permission alone never discloses customer or vendor identities');
$customersOnly=$advisor->advise(1,9,[[
    'id'=>991,'description'=>'PIX pago COOPERATIVA TRANSPORTES SAO JOSE',
    'direction'=>'debit','stage_state'=>'pending','category_source'=>null
]], ['customer']);
verifyLearning($customersOnly['contacts']===[],
    'read-customers permission cannot reveal vendor identities');

$db->exec("INSERT INTO contacts VALUES
    (15,1,'Cooperativa Transportes Sao Jose','vendor',12,1,NULL)");
$ambiguous=$advisor->advise(1,9,[[
    'id'=>991,'description'=>'PIX pago COOPERATIVA TRANSPORTES SAO JOSE',
    'direction'=>'debit','stage_state'=>'pending','category_source'=>null
]], ['vendor']);
verifyLearning($ambiguous['contacts']===[],
    'ambiguous full-name counterparty matches are withheld');

$db->exec("UPDATE lcc_banking_connectors_entries
    SET category_source='manual' WHERE id=1");
$audit->execute([':company'=>1,':account'=>9,':entry'=>1,
    ':category'=>14,':key'=>md5('new-human-correction')]);
$after=$advisor->advise(1,9,[$row]);
verifyLearning($after['total_labels']===16,
    'latest manual correction replaces, never duplicates, training evidence');
$foreign=$advisor->advise(2,10,[$row]);
verifyLearning($foreign['suggestions']===[] && $foreign['total_labels']===0,
    'training data cannot cross company/account scopes');
$otherAccount=$advisor->advise(1,10,[$row]);
verifyLearning($otherAccount['suggestions']===[] && $otherAccount['total_labels']===0,
    'labels cannot be reused for another bank account');
// The Sicoob statement may include a validated payer name in provider
// metadata while the generic Pix description contains no identifying facts.
$db->exec("INSERT INTO lcc_banking_connectors_entries
    (id,connection_id,company_id,account_id,provider,source_fingerprint,
    content_fingerprint,movement_date,direction,amount_cents,currency,
    bank_description,bank_details,state,conflict_count,first_seen_at,last_seen_at)
    VALUES (998,1,1,9,'sicoob','synthetic-payer-source',
    'synthetic-payer-content','2026-11-19','credit',1900,'BRL',
    'PIX recebido','{\"schema_version\":1,\"provider\":\"sicoob\",\"fields\":{\"sender_name\":\"Cooperativa de Serviços Digitais\"}}',
    'pending',0,'2026-11-19 11:00:00','2026-11-19 11:00:00')");
$metadata=$advisor->advise(1,9,[[
    'id'=>998,'description'=>'PIX recebido','direction'=>'credit',
    'stage_state'=>'pending','category_source'=>null,
]],['customer']);
verifyLearning(($metadata['contacts'][998]['id']??null)===11,
    'provider-confirmed sender name suggests unique scoped customer');
$noPermission=$advisor->advise(1,9,[[
    'id'=>998,'description'=>'PIX recebido','direction'=>'credit',
    'stage_state'=>'pending','category_source'=>null,
]]);
verifyLearning($noPermission['contacts']===[],
    'provider metadata does not override native customer ACL');
$crossCompany=$advisor->advise(2,10,[[
    'id'=>998,'description'=>'PIX recebido','direction'=>'credit',
    'stage_state'=>'pending','category_source'=>null,
]],['customer']);
verifyLearning($crossCompany['contacts']===[],
    'provider metadata is never read across companies');
// A user-reviewed link to a real categorized native transaction is also
// trustworthy training evidence. A mere value/date suggestion is not.
$db->exec("INSERT INTO lcc_banking_connectors_entries
  (id,connection_id,company_id,account_id,provider,source_fingerprint,
   content_fingerprint,movement_date,direction,amount_cents,currency,
   bank_description,category_source,category_id,state,linked_transaction_id,
   conflict_count,first_seen_at,last_seen_at)
  VALUES (999,1,1,9,'sicoob','verified-link-source','verified-link-content',
   '2026-11-19','debit',2500,'BRL',
   'PIX pagamento transporte rodoviario frota','linked_native',12,'reviewed',150,
   0,'2026-11-19 11:00:00','2026-11-19 11:00:00')");
$db->exec("INSERT INTO transactions(id,company_id,account_id,category_id)
 VALUES (150,1,9,12),(151,2,9,19)");
$db->exec("INSERT INTO lcc_banking_connectors_review_events
 (entry_id,connection_id,company_id,account_id,actor_id,operation,
  native_transaction_id,request_key,source_fingerprint,content_fingerprint,occurred_at)
 VALUES (999,1,1,9,7,'accept',150,'verified-training-review-event',
 'verified-link-source','verified-link-content','2026-11-19 11:00:00')");
$verified=$advisor->advise(1,9,[$row]);
verifyLearning($verified['total_labels']===17,
 'explicitly accepted native link adds one verified training example');
$db->exec('UPDATE transactions SET category_id=14 WHERE id=150');
$stale=$advisor->advise(1,9,[$row]);
verifyLearning($stale['total_labels']===16,
 'native category changed after link makes the inherited label stale');
$db->exec('UPDATE transactions SET category_id=12 WHERE id=150');
$db->exec("UPDATE lcc_banking_connectors_entries SET state='pending' WHERE id=999");
$reopened=$advisor->advise(1,9,[$row]);
verifyLearning($reopened['total_labels']===16,
 'reopened link never remains in the training dataset');
$db->exec("UPDATE lcc_banking_connectors_entries SET state='reviewed' WHERE id=999");
$db->exec("DELETE FROM lcc_banking_connectors_review_events WHERE entry_id=999");
$unproven=$advisor->advise(1,9,[$row]);
verifyLearning($unproven['total_labels']===16,
 'category_source flag alone is not proof of human-confirmed link');
$anotherTenant=$advisor->advise(2,10,[$row]);
verifyLearning($anotherTenant['total_labels']===0,
 'native label provenance cannot leak across company or account');
echo "Scoped learning and counterparty suggestions are read-only and passed.\n";
