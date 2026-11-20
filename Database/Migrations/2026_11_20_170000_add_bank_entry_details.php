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
        Schema::table('lcc_banking_connectors_entries',static function(Blueprint $table):void {
            // Explicitly allowlisted details, not the raw API response.
            $table->text('bank_details')->nullable();
            $table->string('bank_details_enrichment_state', 20)->nullable();
            $table->timestamp('bank_details_enrichment_checked_at')->nullable();
        });
    }

    public function down(): void
    {
        // Preserve imported bank evidence and audit history on rollback.
    }
};
