<?php
// SPDX-FileCopyrightText: 2026 LibreCode Coop and contributors
// SPDX-License-Identifier: AGPL-3.0-or-later
declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Modules\BankingConnectors\Http\Controllers\ReviewController;
use Modules\BankingConnectors\Http\Controllers\ConnectionController;
use Modules\BankingConnectors\Http\Controllers\ClassificationController;

Route::admin('banking-connectors', static function (): void {
    // Read-only preview and explicitly authorized review metadata.
    // The POST never writes to Akaunting's native financial records.
    Route::get('/', [ReviewController::class, 'index'])->name('review.index');
    Route::post('review-decisions', [ReviewController::class, 'decide'])->name('review.decide');
    Route::post('connections', [ConnectionController::class, 'create'])->name('connections.create');
    Route::post('connections/{connectionId}/state', [ConnectionController::class, 'state'])->name('connections.state');
    Route::post('connections/{connectionId}/request-sync', [ConnectionController::class, 'request'])->name('connections.request');
    Route::post('connections/{connectionId}/native-publishing', [ConnectionController::class, 'nativePublishing'])->name('connections.native-publishing');
});

// Review of bank exceptions from the native transactions workspace.
Route::admin('banking-connectors', static function (): void {
    Route::get('bank-feed/accounts/{accountId}', [ReviewController::class, 'reviewAccount'])
        ->whereNumber('accountId')->name('review');
}, [
    'prefix'=>'banking/transactions',
    'as'=>'banking-connectors.transactions.',
    'namespace'=>null,
]);

// Native account-scoped entry point. Preserve the existing index route for
// old bookmarks and API clients; all account ID checks are server-side.
Route::admin('banking-connectors', static function (): void {
    Route::get('accounts/{accountId}/integration', [ReviewController::class, 'account'])
        ->whereNumber('accountId')
        ->name('show');
    Route::post('accounts/{accountId}/integration/rules', [ClassificationController::class, 'createRule'])
        ->whereNumber('accountId')->name('rule.create');
    Route::post('accounts/{accountId}/integration/rules/{ruleId}/toggle', [ClassificationController::class, 'toggleRule'])
        ->whereNumber('accountId')->whereNumber('ruleId')->name('rule.toggle');
    Route::post('accounts/{accountId}/integration/entries/{entryId}/category', [ClassificationController::class, 'classify'])
        ->whereNumber('accountId')->whereNumber('entryId')->name('category.update');
}, [
    'prefix' => 'banking',
    'as' => 'banking-connectors.account.',
    'namespace' => null,
]);
