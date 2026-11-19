<?php
// SPDX-FileCopyrightText: 2026 LibreCode Coop and contributors
// SPDX-License-Identifier: AGPL-3.0-or-later
declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Only Banking Connectors-owned tables and indexes. No Akaunting ledger schema changes.
 * These events are immutable evidence; automatic rollback intentionally retains
 * them. Production adoption still requires isolated upgrade/restore testing.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('lcc_banking_connectors_entries', static function (Blueprint $table): void {
            // NULL is allowed for pending entries. One confirmed native match
            // cannot be claimed by two staged entries in the same company/account.
            $table->unique(
                ['company_id', 'account_id', 'linked_transaction_id'],
                'lcc_bc_entry_native_link_unique'
            );
        });

        Schema::create('lcc_banking_connectors_review_events', static function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('connection_id');
            $table->unsignedBigInteger('entry_id');
            $table->unsignedInteger('company_id');
            $table->unsignedInteger('account_id');
            $table->unsignedInteger('actor_id');
            $table->string('operation', 16);
            $table->unsignedInteger('native_transaction_id')->nullable();
            $table->char('request_key', 32);
            $table->char('source_fingerprint', 64);
            $table->char('content_fingerprint', 64);
            $table->timestamp('occurred_at');

            $table->foreign('entry_id', 'lcc_bc_review_entry_fk')
                ->references('id')->on('lcc_banking_connectors_entries')->restrictOnDelete();
            $table->unique(['company_id', 'request_key'], 'lcc_bc_review_idempotency');
            $table->index(['company_id', 'account_id', 'entry_id', 'id'], 'lcc_bc_review_scope');
        });
    }

    public function down(): void
    {
        // Audit evidence must not be silently dropped on module rollback.
    }
};
