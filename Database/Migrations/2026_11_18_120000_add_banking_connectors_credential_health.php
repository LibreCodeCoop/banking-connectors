<?php
// SPDX-FileCopyrightText: 2026 LibreCode Coop and contributors
// SPDX-License-Identifier: AGPL-3.0-or-later
declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Non-secret metadata only. Additive migration; never deletes bank evidence. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('lcc_banking_connectors_connections', static function (Blueprint $table): void {
            $table->timestamp('certificate_valid_to')->nullable();
            $table->timestamp('credentials_checked_at')->nullable();
        });
    }

    public function down(): void
    {
        // Keep certificate audit metadata when rolling back module code.
    }
};
