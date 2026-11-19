<?php
// SPDX-FileCopyrightText: 2026 LibreCode Coop and contributors
// SPDX-License-Identifier: AGPL-3.0-or-later
declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Modules\BankingConnectors\Http\Controllers\ApiReviewController;
use Modules\BankingConnectors\Http\Controllers\ApiHealthController;

// Independent from admin/session routes. Akaunting's native API middleware
// authenticates each request, checks read-api and establishes its company.
Route::group([
    'prefix' => trim((string) config('api.prefix', 'api'), '/') . '/v1/banking-connectors',
    'middleware' => config('api.middleware', ['api']),
    'as' => 'api.banking-connectors.',
], static function (): void {
    Route::get('health', [ApiHealthController::class, 'show'])->name('health.show');
    Route::get('connections', [ApiReviewController::class, 'connections'])
        ->name('connections.index');
    Route::get('preview', [ApiReviewController::class, 'preview'])
        ->name('preview.show');
    Route::post('review-decisions', [ApiReviewController::class, 'decide'])
        ->name('review.decide');
});
