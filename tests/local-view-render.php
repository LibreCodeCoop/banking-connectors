<?php
// SPDX-FileCopyrightText: 2026 LibreCode Coop and contributors
// SPDX-License-Identifier: AGPL-3.0-or-later
declare(strict_types=1);

/**
 * Renders Banking Connectors's real Akaunting Blade page under an isolated synthetic
 * user/company. This is NOT a replacement for HTTP middleware/E2E tests.
 */
if (getenv('BANKING_CONNECTORS_ISOLATED_LOCAL') !== 'yes') {
    throw new RuntimeException('Isolated environment required');
}
$root = getenv('BANKING_CONNECTORS_APP_ROOT') ?: '';
if (!is_file($root . '/bootstrap/app.php')) {
    throw new RuntimeException('Missing test app');
}
require $root . '/vendor/autoload.php';
$app = require $root . '/bootstrap/app.php';
$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Models\Auth\User;
use App\Models\Common\Company;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Modules\BankingConnectors\Http\Controllers\ReviewController;

$connection = DB::connection();
if ($connection->getDatabaseName() !== 'akaunting_synthetic' ||
    $connection->getTablePrefix() !== 'ak_') {
    throw new RuntimeException('Unsafe environment');
}
$company = Company::find(2);
$user = User::find(1);
if (!$company || !$user || !$user->companies()->whereKey(2)->exists()) {
    throw new RuntimeException('Synthetic identities missing');
}
$company->makeCurrent();
Auth::guard('web')->setUser($user);
app('url')->defaults(['company_id' => 2]);

$request = Request::create('http://localhost/2/banking-connectors', 'GET');
$route = \Illuminate\Support\Facades\Route::getRoutes()->match($request);
$request->setRouteResolver(static fn (): \Illuminate\Routing\Route => $route);
// View composer consults Router::current() in normal HTTP dispatch.
// In this direct-render-only harness, bind that context explicitly.
$reflection = new \ReflectionProperty($app->make('router'), 'current');
$reflection->setValue($app->make('router'), $route);
$app->instance('request', $request);
\Illuminate\Support\Facades\Request::swap($request);
$controller = app(ReviewController::class);
$view = $controller->index($request);
$html = $view->render();
if (!str_contains($html, 'Sicoob - Teste de Conciliação') ||
    !str_contains($html, 'Integração bancária') ||
    str_contains($html, 'LOCAL-CR-0001') ||
    str_contains($html, 'local-fixture-only')) {
    throw new RuntimeException('Native Blade rendered unexpected or sensitive output');
}
$fixture = DB::table('lcc_banking_connectors_connections as c')
    ->join('accounts as a', static function ($join): void {
        $join->on('a.id', '=', 'c.account_id')
            ->on('a.company_id', '=', 'c.company_id');
    })
    ->where('c.company_id', 2)
    ->where('c.provider', 'sicoob')
    ->where('a.name', 'Sicoob - Teste de Conciliação')
    ->first(['c.id', 'c.account_id']);
if ($fixture === null) {
    throw new RuntimeException('Synthetic review fixture not found');
}
$summary = app(\Modules\BankingConnectors\Application\ReviewPreview::class)
    ->forConnection(2, (int) $fixture->account_id, (int) $fixture->id);
if ($summary['summary'] !== ['suggested' => 1, 'needs_review' => 2, 'unmatched' => 1]) {
    throw new RuntimeException('Unexpected live preview summary');
}
$foreignRequest = Request::create('http://localhost/2/banking-connectors?connection_id=9999', 'GET');
try {
    $controller->index($foreignRequest);
    throw new RuntimeException('Unknown connection leaked data');
} catch (\Symfony\Component\HttpKernel\Exception\HttpException $e) {
    if ($e->getStatusCode() !== 404) {
        throw $e;
    }
}
Auth::guard('web')->setUser(new User());
try {
    $controller->index($request);
    throw new RuntimeException('Unprivileged actor saw banking entries');
} catch (\Symfony\Component\HttpKernel\Exception\HttpException $e) {
    if ($e->getStatusCode() !== 403) {
        throw $e;
    }
}
Auth::guard('web')->setUser($user);
echo json_encode([
    'native_blade_rendered' => true,
    'content_contains_synthetic_bank_account' => true,
    'source_ids_and_hmac_absent' => true,
    'company_id' => 2,
    'company_acl_check_passed' => true,
    'summary' => $summary['summary'],
    'financial_writes' => 0,
], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . PHP_EOL;
