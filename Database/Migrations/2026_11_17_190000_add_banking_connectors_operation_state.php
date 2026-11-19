<?php
// SPDX-FileCopyrightText: 2026 LibreCode Coop and contributors
// SPDX-License-Identifier: AGPL-3.0-or-later
declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Banking Connectors-only columns; never changes native Akaunting financial tables. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('lcc_banking_connectors_connections', static function (Blueprint $table): void {
            $table->timestamp('poll_requested_at')->nullable();
            $table->date('poll_requested_start')->nullable();
            $table->char('poll_request_key', 32)->nullable();
            $table->timestamp('next_retry_at')->nullable();
            $table->unsignedInteger('failure_count')->default(0);
            $table->timestamp('last_attempt_at')->nullable();
        });
    }

    public function down(): void
    {
        // Preserve queued requests and failure records during rollback.
    }
};
