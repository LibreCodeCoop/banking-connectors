<?php
// SPDX-FileCopyrightText: 2026 LibreCode Coop and contributors
// SPDX-License-Identifier: AGPL-3.0-or-later
declare(strict_types=1);

// Read-only inspection of native middleware behavior in isolated localhost.
if (getenv('BANKING_CONNECTORS_ISOLATED_LOCAL') !== 'yes') { throw new RuntimeException('Isolated only'); }
$root = getenv('BANKING_CONNECTORS_APP_ROOT') ?: '';
require $root . '/vendor/autoload.php';
$app = require $root . '/bootstrap/app.php';
$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();
$db = \Illuminate\Support\Facades\DB::connection();
if ($db->getDatabaseName() !== 'akaunting_synthetic' || $db->getTablePrefix() !== 'ak_') {
    throw new RuntimeException('Unexpected environment');
}
$company = \App\Models\Common\Company::find(2);
$company->makeCurrent();
$actor = \App\Models\Auth\User::find(1);
\Illuminate\Support\Facades\Auth::guard('web')->setUser($actor);
$middleware = new \App\Http\Middleware\RedirectIfHitModuleSubscription();
$ctx = new class() { use \App\Traits\Modules; };
$limit = $ctx->getModulesLimitOfSubscription();
print_r([
    'module_limit_action' => $limit->action_status,
    'module_limit_message_code' => str_contains((string) $limit->message, 'Not able') ? 'subscription_denied' : 'ok',
    'wizard_completed' => (string) setting('wizard.completed', 0),
    'can_read_admin' => $actor->can('read-admin-panel'),
    'can_read_banking_transactions' => $actor->can('read-banking-transactions'),
    'can_read_banking_accounts' => $actor->can('read-banking-accounts'),
    'installed_modules' => array_map(static fn($m) => $m->get('alias'), module()->all()),
]);
$req = \Illuminate\Http\Request::create('http://localhost/2/banking-connectors', 'GET');
$res = $middleware->handle($req, static fn($req) => new \Illuminate\Http\Response('passed', 200));
echo 'subscription_middleware_result_status=' . $res->getStatusCode() . PHP_EOL;
echo 'subscription_middleware_redirect_path=' . (parse_url((string) $res->headers->get('Location'), PHP_URL_PATH) ?: 'none') . PHP_EOL;
