<?php
// SPDX-FileCopyrightText: 2026 LibreCode Coop and contributors
// SPDX-License-Identifier: AGPL-3.0-or-later
declare(strict_types=1);

/** Temporary learning labels in disposable synthetic Akaunting only. */
if (getenv('BANKING_CONNECTORS_ISOLATED_LOCAL') !== 'yes') {
    throw new RuntimeException('isolated_environment_required');
}
$action = $argv[1] ?? '';
if (!in_array($action, ['seed','cleanup'], true)) {
    throw new InvalidArgumentException('invalid_fixture_action');
}
$root = getenv('BANKING_CONNECTORS_APP_ROOT') ?: '';
if (!is_file($root.'/bootstrap/app.php')) throw new RuntimeException('local_app_missing');
require $root.'/vendor/autoload.php';
$app=require $root.'/bootstrap/app.php';
$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use Illuminate\Support\Facades\DB;

$connection=DB::connection();
$hostValues = array_merge(
    (array)config('database.connections.mysql.host',[]),
    (array)config('database.connections.mysql.read.host',[]),
    (array)config('database.connections.mysql.write.host',[]),
);
$hostValues=array_values(array_filter($hostValues,is_string(...)));
if ($connection->getDatabaseName() !== 'akaunting_synthetic' ||
    $connection->getTablePrefix() !== 'ak_' ||
    $hostValues === [] || count(array_unique($hostValues)) !== 1 ||
    $hostValues[0] !== 'banksync-local-db' ||
    config('app.env') !== 'local') {
    throw new RuntimeException('unsafe_synthetic_fixture_database');
}
$company=2;
$account=3;
$link=DB::table('lcc_banking_connectors_connections')
    ->where('company_id',$company)->where('account_id',$account)->first(['id']);
if (!$link) throw new RuntimeException('synthetic_bank_connection_missing');
$connectionId=(int)$link->id;
$sourceKeys=[];
for ($i=1;$i<=17;$i++) $sourceKeys[]=hash('sha256','bc-categorization-ml-synthetic-'.$i);

DB::transaction(static function () use ($company,$account,$connectionId,$sourceKeys,$action): void {
    $rows=DB::table('lcc_banking_connectors_entries')
        ->where('company_id',$company)->where('account_id',$account)
        ->where('connection_id',$connectionId)->whereIn('source_fingerprint',$sourceKeys)
        ->pluck('id')->all();
    if ($rows !== []) {
        DB::table('lcc_banking_connectors_category_events')
            ->where('company_id',$company)->where('account_id',$account)
            ->whereIn('entry_id',$rows)->delete();
        DB::table('lcc_banking_connectors_entries')
            ->where('company_id',$company)->where('account_id',$account)
            ->whereIn('id',$rows)->delete();
    }
    $codes=['banking_ml_test_transport','banking_ml_test_tax'];
    if ($action==='cleanup') {
        DB::table('categories')->where('company_id',$company)
            ->where('created_from','banking-connectors-local-ml-test')
            ->whereIn('code',$codes)->delete();
        return;
    }
    $categories=[];
    foreach ($codes as $i=>$code) {
        $categories[]=DB::table('categories')->insertGetId([
            'company_id'=>$company,'code'=>$code,
            'name'=>$i===0?'BC ML Transport Test':'BC ML Tax Test',
            'type'=>'expense','color'=>'#64748B','enabled'=>1,
            'created_from'=>'banking-connectors-local-ml-test',
            'created_at'=>now(),'updated_at'=>now()
        ]);
    }
    foreach ($sourceKeys as $i=>$fingerprint) {
        $n=$i+1;
        $type=$n<=8?0:1;
        $description=$n===17
            ? 'PIX rodoviaria transporte frota'
            : ($type===0
                ? "PIX viagem rodoviaria transporte frota viagem local {$n}"
                : "PIX DARF imposto tributo receita federal pagamento {$n}");
        $id=DB::table('lcc_banking_connectors_entries')->insertGetId([
            'connection_id'=>$connectionId,'company_id'=>$company,
            'account_id'=>$account,'provider'=>'sicoob',
            'source_fingerprint'=>$fingerprint,
            'content_fingerprint'=>hash('sha256','bc-ml-only-facts-'.$n),
            'movement_date'=>'2026-11-18',
            'direction'=>'debit','amount_cents'=>1700+$n,
            'currency'=>'BRL','bank_description'=>$description,
            'category_id'=>$n===17?null:$categories[$type],
            'category_source'=>$n===17?null:'manual',
            'state'=>'pending','conflict_count'=>0,
            'first_seen_at'=>now(),'last_seen_at'=>now(),
            'created_at'=>now(),'updated_at'=>now(),
        ]);
        if ($n!==17) {
            DB::table('lcc_banking_connectors_category_events')->insert([
                'company_id'=>$company,'account_id'=>$account,'entry_id'=>$id,
                'actor_id'=>1,'operation'=>'assign',
                'old_category_id'=>null,'new_category_id'=>$categories[$type],
                'request_key'=>md5('bc-training-fixture-event-'.$n),
                'occurred_at'=>now(),
            ]);
        }
    }
});
$count = $action === 'seed'
    ? DB::table('lcc_banking_connectors_category_events')
        ->where('company_id', $company)->where('account_id', $account)->count()
    : 0;
$advisorCount = $action === 'seed'
    ? app(\Modules\BankingConnectors\Application\CategoryLearningAdvisor::class)
        ->advise($company, $account, [])['total_labels']
    : 0;
echo json_encode(['action'=>$action,'status'=>'ok',
    'sample_events'=>$count,'advisor_samples'=>$advisorCount,
    'native_financial_writes'=>0]).PHP_EOL;
