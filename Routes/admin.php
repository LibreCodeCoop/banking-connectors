<?php
// SPDX-FileCopyrightText: 2026 LibreCode Coop and contributors
// SPDX-License-Identifier: AGPL-3.0-or-later
declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Modules\BankingConnectors\Http\Controllers\ReviewController;
use Modules\BankingConnectors\Http\Controllers\ConnectionController;

Route::admin('banking-connectors', static function (): void {
    // Read-only preview and explicitly authorized review metadata.
    // The POST never writes to Akaunting's native financial records.
    Route::get('/', [ReviewController::class, 'index'])->name('review.index');
    Route::post('review-decisions', [ReviewController::class, 'decide'])->name('review.decide');
    Route::post('connections', [ConnectionController::class, 'create'])->name('connections.create');
    Route::post('connections/{connectionId}/state', [ConnectionController::class, 'state'])->name('connections.state');
    Route::post('connections/{connectionId}/request-sync', [ConnectionController::class, 'request'])->name('connections.request');
});
