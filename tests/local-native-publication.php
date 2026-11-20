<?php
// SPDX-FileCopyrightText: 2026 LibreCode Coop and contributors
// SPDX-License-Identifier: AGPL-3.0-or-later
declare(strict_types=1);

/**
 * Rollback-only native Akaunting posting test. The outer transaction is NEVER
 * committed; individual service transactions use Laravel savepoints. All bank
 * movements, categories, transaction numbers and native writes are synthetic.
 */
if (getenv('BANKING_CONNECTORS_ISOLATED_LOCAL')!=='yes') {
    throw new RuntimeException('isolated_environment_required');
}
$root=getenv('BANKING_CONNECTORS_APP_ROOT')?:'';
if (!is_file($root.'/bootstrap/app.php')) throw new RuntimeException('synthetic_app_missing');
require $root.'/vendor/autoload.php';
$app=require $root.'/bootstrap/app.php';
$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use Illuminate\Support\Facades\DB;
use App\Models\Common\Company;
use Modules\BankingConnectors\Application\NativePublisher;
use Modules\BankingConnectors\Domain\BankEntry;
use Modules\BankingConnectors\Infrastructure\PdoEntryStage;

$conn=DB::connection();
$hosts=array_values(array_filter(array_merge(
    (array)config('database.connections.mysql.host',[]),
    (array)config('database.connections.mysql.read.host',[]),
    (array)config('database.connections.mysql.write.host',[]),
), 'is_string'));
if (config('app.env')!=='local' ||
    $conn->getDatabaseName()!=='akaunting_synthetic' ||
    $conn->getTablePrefix()!=='ak_' ||
    $hosts===[]||count(array_unique($hosts))!==1||$hosts[0]!=='banksync-local-db') {
    throw new RuntimeException('refusing_non_synthetic_database');
}
\Illuminate\Support\Carbon::setTestNow('2026-11-20 12:00:00');
$company=2;
$baseTransactions=DB::table('transactions')->where('company_id',$company)->count();
$oldCompany=Company::getCurrent();
$conn->beginTransaction();
try {
    $account=DB::table('accounts')->insertGetId([
        'company_id'=>$company,'type'=>'bank',
        'name'=>'SYNTHETIC BANK FEED POSTING TEST',
        'number'=>'LOCAL-ONLY-SYNTHETIC-BANK-FEED',
        'currency_code'=>'BRL','opening_balance'=>0,
        'enabled'=>1,'created_from'=>'banking-connectors-local-test',
        'created_at'=>now(),'updated_at'=>now(),
    ]);
    $id=DB::table('lcc_banking_connectors_connections')->insertGetId([
        'company_id'=>$company,'account_id'=>$account,'provider'=>'sicoob',
        'credential_reference'=>'local/synthetic/native-publication',
        'state'=>'enabled','native_post_enabled'=>1,
        'native_post_after_entry_id'=>0,'native_post_activated_at'=>now(),
        'created_at'=>now(),'updated_at'=>now(),
    ]);
    DB::table('documents')->insert([
        'company_id'=>$company,'type'=>'invoice',
        'document_number'=>'SYNTHETIC-OPEN-INVOICE-BC-TEST',
        'status'=>'sent','issued_at'=>'2026-11-18 12:00:00',
        'due_at'=>'2026-11-22 12:00:00','amount'=>50.00,
        'currency_code'=>'BRL','currency_rate'=>1,
        'contact_id'=>1,'contact_name'=>'Synthetic Customer',
        'category_id'=>1,'created_from'=>'banking-connectors-local-test',
        'created_at'=>now(),'updated_at'=>now(),
    ]);
    DB::table('transactions')->insert([
        'company_id'=>$company,'account_id'=>$account,
        'type'=>'income','number'=>'SYNTHETIC-EXISTING-NATIVE',
        'paid_at'=>'2026-11-17 12:00:00','amount'=>10,
        'currency_code'=>'BRL','currency_rate'=>1,
        'category_id'=>1,'payment_method'=>'offline',
        'parent_id'=>0,'created_from'=>'banking-connectors-local-test',
        'created_at'=>now(),'updated_at'=>now(),
    ]);
    $secret=str_repeat('native-only-test-bank-key-',3);
    $rows=[
        new BankEntry($company,$account,'sicoob','BC-SYNTHETIC-NEW-2026-1120',
            '2026-11-20','credit',12345,'BRL','PIX RECEBIDO SINTETICO LOCAL'),
        new BankEntry($company,$account,'sicoob','BC-SYNTHETIC-EXISTING-2026-1120',
            '2026-11-17','credit',1000,'BRL','PIX RECEBIDO EXISTENTE'),
        new BankEntry($company,$account,'sicoob','BC-SYNTHETIC-TRANSFER-2026-1120',
            '2026-11-20','debit',9000,'BRL','TRANSFERENCIA ENTRE CONTAS PROPRIAS'),
        new BankEntry($company,$account,'sicoob','BC-SYNTHETIC-INVOICE-2026-1120',
            '2026-11-20','credit',5000,'BRL','PAGAMENTO REFERENTE A FATURA'),

    ];
    // The domain's PdoEntryStage is intentionally non-nestable. For this
    // rollback-only real-Akaunting test, insert already-validated synthetic
    // bank rows within the one outer transaction instead.
    foreach ($rows as $row) {
        DB::table('lcc_banking_connectors_entries')->insert([
            'connection_id'=>$id,'company_id'=>$company,'account_id'=>$account,
            'provider'=>'sicoob','source_fingerprint'=>$row->fingerprint($secret),
            'content_fingerprint'=>hash_hmac('sha256',
                $row->date.'|'.$row->direction.'|'.$row->amountCents,$secret),
            'movement_date'=>$row->date,'direction'=>$row->direction,
            'amount_cents'=>$row->amountCents,'currency'=>$row->currency,
            'bank_description'=>$row->description,'state'=>'pending',
            'conflict_count'=>0,'first_seen_at'=>now(),'last_seen_at'=>now(),
            'created_at'=>now(),'updated_at'=>now(),
        ]);
    }
    // Production activation fences off every already-staged movement.
    $afterExisting=(int)DB::table('lcc_banking_connectors_entries')
        ->where('company_id',$company)->where('connection_id',$id)->max('id');
    DB::table('lcc_banking_connectors_connections')
        ->where('id',$id)->where('company_id',$company)
        ->update(['native_post_after_entry_id'=>$afterExisting]);
    $old=app(NativePublisher::class)->run($company,$id,false,100);
    if ($old['evaluated']!==0 || $old['created']!==0) {
        throw new RuntimeException('activation_retroactively_posted_legacy_bank_movements');
    }
    DB::table('lcc_banking_connectors_connections')
        ->where('id',$id)->where('company_id',$company)
        ->update(['native_post_after_entry_id'=>0]);
    echo "PASS activation watermark excludes historical bank imports from native posting\n";
    $before=DB::table('transactions')->where('company_id',$company)->count();
    $dry=app(NativePublisher::class)->run($company,$id,true,100);
    if ($dry['created']!==1 || $dry['held']<3 ||
        DB::table('transactions')->where('company_id',$company)->count()!==$before) {
        throw new RuntimeException('dry_run_did_not_abstain_or_touched_native_finance '.json_encode($dry));
    }
    echo "PASS dry-run holds existing native payments, possible transfers and unpaid invoices; zero financial writes\n";
    $result=app(NativePublisher::class)->run($company,$id,false,100);
    if ($result['created']!==1 || $result['held']<3 || $result['errors']!==0) {
        throw new RuntimeException('native_publication_failed '.json_encode($result));
    }
    $fingerprint=$rows[0]->fingerprint($secret);
    $source=DB::table('lcc_banking_connectors_entries')
        ->where('company_id',$company)->where('connection_id',$id)
        ->where('source_fingerprint',$fingerprint)->first();
    $native=DB::table('transactions')->where('id',$source->linked_transaction_id)
        ->where('company_id',$company)->first();
    $provisional=DB::table('categories')->where('id',$native->category_id)
        ->where('company_id',$company)->first();
    $publication=DB::table('lcc_banking_connectors_native_publications')
        ->where('company_id',$company)->where('entry_id',$source->id)->first();
    if ($source->state!=='reviewed'||$native===null || $provisional===null ||
        $native->type!=='income'||$native->account_id!=$account ||
        (float)$native->amount!==123.45 ||
        $native->created_from!=='banking-connectors::bank-feed' ||
        $provisional->code!=='banking_connectors_pending_income' ||
        !$publication || $publication->outcome!=='created') {
        throw new RuntimeException('native_domain_write_or_binding_incorrect');
    }
    echo "PASS actual native Akaunting CreateTransaction job, company/account, cents, provisional category and audit binding\n";
    $replay=app(NativePublisher::class)->run($company,$id,false,100);
    if ($replay['created']!==0 ||
        DB::table('transactions')->where('company_id',$company)->count()!==$baseTransactions+2 ||
        DB::table('lcc_banking_connectors_native_publications')
            ->where('company_id',$company)->where('entry_id',$source->id)->count()!==1) {
        throw new RuntimeException('replay_created_duplicate_native_transaction');
    }
    echo "PASS repeated native publisher invocation creates no duplicate\n";
    try {
        app(NativePublisher::class)->run($company+1,$id,false,100);
        throw new RuntimeException('foreign_company_published_native_data');
    } catch (DomainException $e) {
        if ($e->getMessage()!=='bank_connection_not_accessible') throw $e;
    }
    echo "PASS foreign-company publish is denied before financial access\n";
} finally {
    if ($conn->transactionLevel()>0) $conn->rollBack();
    if ($oldCompany) $oldCompany->makeCurrent();
    else Company::forgetCurrent();
    \Illuminate\Support\Carbon::setTestNow();
}
if (DB::table('transactions')->where('company_id',$company)->count()!==$baseTransactions) {
    throw new RuntimeException('rollback_only_native_test_leaked_financial_writes');
}
echo "PASS all synthetic financial, publication and category changes rolled back\n";
