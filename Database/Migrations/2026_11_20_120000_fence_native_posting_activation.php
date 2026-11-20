<?php
// SPDX-FileCopyrightText: 2026 LibreCode Coop and contributors
// SPDX-License-Identifier: AGPL-3.0-or-later
declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('lcc_banking_connectors_connections', function(Blueprint $table): void {
            // Activation fences: publishing cannot sweep an old statement
            // when a company first turns on automatic native financial writes.
            $table->unsignedBigInteger('native_post_after_entry_id')->nullable();
            $table->timestamp('native_post_activated_at')->nullable();
        });
    }

    public function down(): void
    {
        // Source-to-native policy boundaries are financial audit evidence;
        // never remove them during automated module rollback.
    }
};
