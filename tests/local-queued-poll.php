<?php
// SPDX-FileCopyrightText: 2026 LibreCode Coop and contributors
// SPDX-License-Identifier: AGPL-3.0-or-later
declare(strict_types=1);

// Negative-only queued refresh test. The job MUST NOT call Sicoob when an
// execution is stale, lacks an authorized request or crosses companies.
if (getenv('BANKING_CONNECTORS_ISOLATED_LOCAL') !== 'yes') {
    throw new RuntimeException('Not an isolated Akaunting banking test');
}
$root = getenv('BANKING_CONNECTORS_APP_ROOT') ?: '';
if (!is_file($root . '/bootstrap/app.php')) {
    throw new RuntimeException('Isolated Akaunting bootstrap missing');
}
require $root . '/vendor/autoload.php';
$app = require $root . '/bootstrap/app.php';
$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Modules\BankingConnectors\Jobs\PollQueuedConnection;

$db = DB::connection();
if ($db->getDatabaseName() !== 'akaunting_synthetic' ||
    $db->getTablePrefix() !== 'ak_' ||
    config('app.env') !== 'local') {
    throw new RuntimeException('Unexpected synthetic database identity');
}
$hostValues = array_merge(
    (array) config('database.connections.mysql.host', []),
    (array) config('database.connections.mysql.read.host', []),
    (array) config('database.connections.mysql.write.host', []),
);
$hostValues = array_values(array_filter($hostValues, 'is_string'));
if ($hostValues === [] || count(array_unique($hostValues)) !== 1 ||
    $hostValues[0] !== 'banksync-local-db') {
    throw new RuntimeException('Unexpected synthetic database host');
}

$connection = DB::table('lcc_banking_connectors_connections')
    ->where('company_id', 2)->first(['id', 'company_id']);
if (!$connection) throw new RuntimeException('Synthetic connection missing');

$before = (int) DB::table('lcc_banking_connectors_entries')->count();
$validKey = str_repeat('a', 32);
try {
    new PollQueuedConnection(0, (int) $connection->id, $validKey);
    throw new RuntimeException('Accepted invalid job company');
} catch (InvalidArgumentException $error) {
    if ($error->getMessage() !== 'invalid_banking_sync_job') throw $error;
}
try {
    new PollQueuedConnection(2, (int) $connection->id, 'invalid');
    throw new RuntimeException('Accepted invalid job request key');
} catch (InvalidArgumentException $error) {
    if ($error->getMessage() !== 'invalid_banking_sync_job') throw $error;
}

// PollConnection may never run for a forged/stale queued task.
Artisan::shouldReceive('call')->never();
(new PollQueuedConnection(3, (int) $connection->id, $validKey))->handle();
(new PollQueuedConnection(2, (int) $connection->id, $validKey))->handle();
(new PollQueuedConnection(2, 999999999, $validKey))->handle();
$after = (int) DB::table('lcc_banking_connectors_entries')->count();
if ($after !== $before) throw new RuntimeException('A rejected queued job changed the bank stage');
echo "PASS queued refresh rejects invalid scope, old request keys and foreign company\n";
echo "PASS no bank command or financial database change on rejected jobs\n";
