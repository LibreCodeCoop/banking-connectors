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
        Schema::create('lcc_banking_connectors_connections', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->unsignedInteger('company_id');
            $table->unsignedInteger('account_id');
            $table->string('provider', 48);
            // The credential reference is never a token, password or certificate.
            $table->string('credential_reference', 160)->nullable();
            $table->string('state', 24)->default('paused');
            $table->date('cursor_date')->nullable();
            $table->timestamp('last_success_at')->nullable();
            $table->string('last_error_code', 72)->nullable();
            $table->timestamps();

            $table->unique(['company_id', 'account_id', 'provider'], 'lcc_bc_conn_binding_unique');
            $table->index(['company_id', 'state'], 'lcc_bc_conn_scope');
        });

        Schema::create('lcc_banking_connectors_entries', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('connection_id');
            $table->unsignedInteger('company_id');
            $table->unsignedInteger('account_id');
            $table->string('provider', 48);
            // HMAC of provider, company, account and stable bank transaction ID.
            $table->char('source_fingerprint', 64);
            // HMAC of immutable normalized financial fields; detects bank revisions.
            $table->char('content_fingerprint', 64);
            $table->date('movement_date');
            $table->string('direction', 6);
            $table->unsignedBigInteger('amount_cents');
            $table->char('currency', 3);
            $table->string('state', 24)->default('pending');
            $table->unsignedBigInteger('linked_transaction_id')->nullable();
            $table->unsignedInteger('conflict_count')->default(0);
            $table->timestamp('first_seen_at');
            $table->timestamp('last_seen_at');
            $table->timestamps();

            $table->foreign('connection_id', 'lcc_bc_entry_connection_fk')
                ->references('id')->on('lcc_banking_connectors_connections')
                ->restrictOnDelete();
            $table->unique(['connection_id', 'source_fingerprint'], 'lcc_bc_entry_source_unique');
            $table->index(['company_id', 'account_id', 'state', 'id'], 'lcc_bc_entry_scope');
        });
    }

    public function down(): void
    {
        // Never automatically destroy bank evidence or accounting links.
        // An explicit, audited export/purge is required for uninstallation.
    }
};
