<?php
// SPDX-FileCopyrightText: 2026 LibreCode Coop and contributors
// SPDX-License-Identifier: AGPL-3.0-or-later
declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Additive, scoped review metadata; native financial tables are untouched. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('lcc_banking_connectors_entries', static function (Blueprint $table): void {
            // Whitelisted description, never a raw bank response or source ID.
            $table->string('bank_description', 240)->nullable();
            $table->unsignedInteger('category_id')->nullable();
            $table->string('category_source', 16)->nullable();
            $table->unsignedBigInteger('category_rule_id')->nullable();
        });

        Schema::create('lcc_banking_connectors_category_rules', static function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->unsignedInteger('company_id');
            $table->unsignedInteger('account_id');
            $table->string('direction', 6);
            $table->string('description_contains', 120);
            $table->unsignedInteger('category_id');
            $table->unsignedInteger('priority')->default(100);
            $table->boolean('enabled')->default(true);
            $table->unsignedInteger('created_by');
            $table->timestamps();
            $table->index(['company_id', 'account_id', 'enabled', 'priority'], 'lcc_bc_category_rules_scope');
            $table->unique(['company_id','account_id','direction','description_contains'],
                'lcc_bc_category_rule_unique');
        });

        Schema::create('lcc_banking_connectors_category_events', static function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->unsignedInteger('company_id');
            $table->unsignedInteger('account_id');
            $table->unsignedBigInteger('entry_id');
            $table->unsignedInteger('actor_id');
            $table->string('operation', 20);
            $table->unsignedInteger('old_category_id')->nullable();
            $table->unsignedInteger('new_category_id')->nullable();
            $table->unsignedInteger('native_transaction_id')->nullable();
            $table->char('request_key', 32);
            $table->timestamp('occurred_at');
            $table->unique(['company_id', 'request_key'], 'lcc_bc_cat_event_idempotency');
            $table->index(['company_id', 'account_id', 'entry_id', 'id'], 'lcc_bc_cat_event_scope');
        });
    }

    public function down(): void
    {
        // Audit, source descriptions and manual classifications are retained.
        // Rollback of program code must not erase financial evidence.
    }
};
