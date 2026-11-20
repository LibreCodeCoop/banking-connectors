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
        Schema::table('lcc_banking_connectors_entries', function (Blueprint $table): void {
            $table->string('publication_issue_code',72)->nullable();
            $table->timestamp('last_publication_attempt_at')->nullable();
            $table->unsignedInteger('publication_failures')->default(0);
            $table->index(['company_id','state','publication_failures','id'],
                'lcc_bc_publish_pending_idx');
        });
    }

    public function down(): void
    {
        // Never destroy retained bank publishing and incident evidence.
    }
};
