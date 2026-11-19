<?php
// SPDX-FileCopyrightText: 2026 LibreCode Coop and contributors
// SPDX-License-Identifier: AGPL-3.0-or-later
declare(strict_types=1);

// Positive queued-worker routing test. Stages only temporary synthetic request
// metadata inside a DB transaction and mocks the bank command before network.
if (getenv('BANKING_CONNECTORS_ISOLATED_LOCAL') !== 'yes') {
    throw new RuntimeException('Not the isolated local banking fixture');
}
$root = getenv('BANKING_CONNECTORS_APP_ROOT') ?: '';
if (!is_file($root . '/bootstrap/app.php')) {
    throw new RuntimeException('Missing synthetic Akaunting application');
}
require $root . '/vendor/autoload.php';
$app = require $root . '/bootstrap/app.php';
$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Modules\BankingConnectors\Jobs\PollQueuedConnection;

$db = DB::connection();
if ($db->getDatabaseName() !== 'akaunting_synthetic' ||
    $db->getTablePrefix() !== 'ak_' || config('app.env') !== 'local') {
    throw new RuntimeException('Not the synthetic financial DB');
}
$hosts = array_merge(
    (array) config('database.connections.mysql.host', []),
    (array) config('database.connections.mysql.read.host', []),
    (array) config('database.connections.mysql.write.host', []),
);
$hosts = array_values(array_filter($hosts, 'is_string'));
if ($hosts === [] || count(array_unique($hosts)) !== 1 ||
    $hosts[0] !== 'banksync-local-db') {
    throw new RuntimeException('Unexpected DB origin');
}
$record = DB::table('lcc_banking_connectors_connections')
    ->where('company_id', 2)->orderBy('id')->first(['id']);
if (!$record) throw new RuntimeException('Missing synthetic bank connection');
$id = (int) $record->id;
$key = str_repeat('b', 32);
$baseCount = (int) DB::table('lcc_banking_connectors_entries')->count();

$db->beginTransaction();
try {
    DB::table('lcc_banking_connectors_connections')
        ->where('id', $id)->where('company_id', 2)
        ->update([
            'state' => 'read_only',
            'poll_requested_at' => now(),
            'poll_requested_start' => '2026-11-16',
            'poll_request_key' => $key,
            'next_retry_at' => null,
        ]);
    Artisan::shouldReceive('call')
        ->once()
        ->with('banking-connectors:poll', [
            'connection' => (string) $id,
            '--no-ansi' => true,
            '--start' => '2026-11-16',
        ])
        ->andReturn(0);
    (new PollQueuedConnection(2, $id, $key))->handle();
} finally {
    $db->rollBack();
}
if ((int) DB::table('lcc_banking_connectors_entries')->count() !== $baseCount) {
    throw new RuntimeException('Positive routing test modified financial staging');
}
echo "PASS queued worker forwards only company-scoped, persisted request to CLI\n";
echo "PASS positive queue test made no real bank call and rolled back its fixture\n";
