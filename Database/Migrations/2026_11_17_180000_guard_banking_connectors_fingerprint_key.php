<?php
// SPDX-FileCopyrightText: 2026 LibreCode Coop and contributors
// SPDX-License-Identifier: AGPL-3.0-or-later
declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Pin each connection to the HMAC fingerprint key used for its source IDs.
 * This is a one-way key commitment, NEVER the secret itself.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('lcc_banking_connectors_connections', static function (Blueprint $table): void {
            $table->char('fingerprint_key_commitment', 64)->nullable();
        });
    }

    public function down(): void
    {
        // Do not silently erase the provenance of persisted bank identifiers.
    }
};
