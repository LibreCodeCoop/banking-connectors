<?php
// SPDX-FileCopyrightText: 2026 LibreCode Coop and contributors
// SPDX-License-Identifier: AGPL-3.0-or-later
declare(strict_types=1);

require dirname(__DIR__) . '/Domain/BankEntry.php';
require dirname(__DIR__) . '/Domain/CategoryRuleEngine.php';
require dirname(__DIR__) . '/Infrastructure/PdoEntryStage.php';
require __DIR__ . '/Support/SqliteStageFixture.php';

use Modules\BankingConnectors\Domain\BankEntry;
use Modules\BankingConnectors\Infrastructure\PdoEntryStage;
use Modules\BankingConnectors\Tests\Support\SqliteStageFixture;

$db=new PDO('sqlite::memory:');
SqliteStageFixture::install($db);
SqliteStageFixture::connection($db,1,1,9,'sicoob');
SqliteStageFixture::connection($db,2,2,9,'sicoob');
$stage=new PdoEntryStage($db);
$secret=str_repeat('isolated-test-key-',3);
for ($n=1;$n<=55;$n++) {
    $stage->ingest(1,1,9,'sicoob',[new BankEntry(
        companyId:1,accountId:9,provider:'sicoob',externalId:(string)$n,
        date:'2026-11-19',direction:'credit',amountCents:100+$n,
        currency:'BRL',description:'Test '.$n,
    )],$secret);
}
$target=$db->query("SELECT id FROM lcc_banking_connectors_entries WHERE bank_description='Test 1'")->fetchColumn();
$assert=static function(bool $ok,string $name):void {
    if (!$ok) throw new RuntimeException('FAIL '.$name);
    echo 'PASS '.$name.PHP_EOL;
};
$assert(count($stage->listForReview(1,9,1,'all',50))===50,'general review remains paginated');
$result=$stage->findForReview(1,9,1,(int)$target);
$assert(count($result)===1 && $result[0]['bank_description']==='Test 1','exact entry outside first page remains accessible');
$assert($stage->findForReview(2,9,2,(int)$target)===[],'cross-company entry lookup rejected');
try {
    $stage->findForReview(1,10,1,(int)$target);
    throw new RuntimeException('FAIL wrong account entry lookup');
} catch (DomainException $e) {
    $assert($e->getMessage()==='connection_not_accessible','wrong account entry lookup rejected');
}
$assert($stage->findForReview(1,9,1,9999)===[],'missing entry returns empty');
