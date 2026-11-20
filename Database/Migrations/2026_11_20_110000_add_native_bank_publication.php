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
        Schema::table('lcc_banking_connectors_connections', function (Blueprint $table): void {
            // Opt-in per bank connection. Existing production connections keep
            // their statement-only behavior until explicitly approved.
            $table->boolean('native_post_enabled')->default(false);
        });

        Schema::create('lcc_banking_connectors_native_publications', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->unsignedInteger('company_id');
            $table->unsignedInteger('account_id');
            $table->unsignedBigInteger('connection_id');
            $table->unsignedBigInteger('entry_id');
            $table->unsignedBigInteger('native_transaction_id');
            $table->string('outcome', 24); // created, matched
            $table->string('reason_code', 72);
            $table->timestamp('occurred_at');
            $table->unique(['company_id', 'entry_id'], 'lcc_bc_pub_entry_unique');
            $table->unique(['company_id', 'account_id', 'native_transaction_id'], 'lcc_bc_pub_native_unique');
            $table->index(['company_id', 'account_id', 'outcome'], 'lcc_bc_pub_scope');
        });
    }

    public function down(): void
    {
        // Financial source IDs and native transaction links are immutable
        // audit data. Never silently discard them during module rollback.
    }
};
