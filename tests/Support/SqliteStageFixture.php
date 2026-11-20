<?php
// SPDX-FileCopyrightText: 2026 LibreCode Coop and contributors
// SPDX-License-Identifier: AGPL-3.0-or-later
declare(strict_types=1);

namespace Modules\BankingConnectors\Tests\Support;

use PDO;

/**
 * Disposable SQLite schema for PDO store tests.
 * Mirrors the production Laravel migration; not used to create real tables.
 */
final class SqliteStageFixture
{
    public static function install(PDO $db): void
    {
        $db->exec('PRAGMA foreign_keys = ON');
        $db->exec("CREATE TABLE lcc_banking_connectors_connections (
            id INTEGER PRIMARY KEY, company_id INTEGER NOT NULL,
            account_id INTEGER NOT NULL, provider VARCHAR(48) NOT NULL,
            credential_reference VARCHAR(160), fingerprint_key_commitment CHAR(64),
            state VARCHAR(24) NOT NULL DEFAULT 'paused',
            cursor_date DATE, last_success_at DATETIME,
            last_error_code VARCHAR(72), created_at DATETIME, updated_at DATETIME,
            poll_requested_at DATETIME, poll_requested_start DATE,
            poll_request_key CHAR(32), next_retry_at DATETIME,
            failure_count INTEGER NOT NULL DEFAULT 0, last_attempt_at DATETIME,
            certificate_valid_to DATETIME, credentials_checked_at DATETIME,
            native_post_enabled INTEGER NOT NULL DEFAULT 0,
            UNIQUE(company_id, account_id, provider)
        )");
        $db->exec("CREATE TABLE lcc_banking_connectors_entries (
            id INTEGER PRIMARY KEY AUTOINCREMENT, connection_id INTEGER NOT NULL,
            company_id INTEGER NOT NULL, account_id INTEGER NOT NULL,
            provider VARCHAR(48) NOT NULL, source_fingerprint CHAR(64) NOT NULL,
            content_fingerprint CHAR(64) NOT NULL, movement_date DATE NOT NULL,
            direction VARCHAR(6) NOT NULL, amount_cents BIGINT NOT NULL,
            currency CHAR(3) NOT NULL, bank_description VARCHAR(240),
            category_id INTEGER NULL, category_source VARCHAR(16) NULL,
            category_rule_id BIGINT NULL,
            publication_issue_code VARCHAR(72) NULL,
            last_publication_attempt_at DATETIME NULL,
            publication_failures INTEGER NOT NULL DEFAULT 0,
            state VARCHAR(24) NOT NULL DEFAULT 'pending',
            linked_transaction_id BIGINT, conflict_count INTEGER NOT NULL DEFAULT 0,
            first_seen_at DATETIME NOT NULL, last_seen_at DATETIME NOT NULL,
            created_at DATETIME, updated_at DATETIME,
            UNIQUE(connection_id, source_fingerprint),
            FOREIGN KEY(connection_id) REFERENCES lcc_banking_connectors_connections(id)
        )");
        $db->exec("CREATE TABLE lcc_banking_connectors_category_rules (
            id INTEGER PRIMARY KEY AUTOINCREMENT, company_id INTEGER NOT NULL,
            account_id INTEGER NOT NULL, direction VARCHAR(6) NOT NULL,
            description_contains VARCHAR(120) NOT NULL, category_id INTEGER NOT NULL,
            priority INTEGER NOT NULL DEFAULT 100, enabled INTEGER NOT NULL DEFAULT 1,
            created_by INTEGER NOT NULL, created_at DATETIME, updated_at DATETIME
        )");
        $db->exec("CREATE TABLE categories (
            id INTEGER PRIMARY KEY, company_id INTEGER NOT NULL,
            name VARCHAR(128), type VARCHAR(20), enabled INTEGER NOT NULL DEFAULT 1,
            deleted_at DATETIME NULL
        )");
        $db->exec("CREATE TABLE accounts (
            id INTEGER PRIMARY KEY, company_id INTEGER NOT NULL,
            name TEXT NULL, type VARCHAR(20), currency_code TEXT NULL,
            enabled INTEGER NOT NULL DEFAULT 1, deleted_at DATETIME NULL
        )");
        $db->exec("CREATE TABLE lcc_banking_connectors_native_publications (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            company_id INT NOT NULL, account_id INT NOT NULL,
            connection_id INT NOT NULL, entry_id INT NOT NULL,
            native_transaction_id INT NOT NULL,
            outcome VARCHAR(24) NOT NULL, reason_code VARCHAR(72) NOT NULL,
            occurred_at DATETIME NOT NULL,
            UNIQUE(company_id,entry_id),
            UNIQUE(company_id,account_id,native_transaction_id)
        )");
        $db->exec('CREATE INDEX lcc_bc_entry_scope
            ON lcc_banking_connectors_entries(company_id, account_id, state, id)');
    }

    public static function installReview(PDO $db): void
    {
        $db->exec("CREATE TABLE lcc_banking_connectors_category_events (
            id INTEGER PRIMARY KEY AUTOINCREMENT, company_id INTEGER NOT NULL,
            account_id INTEGER NOT NULL, entry_id BIGINT NOT NULL,
            actor_id INTEGER NOT NULL, operation VARCHAR(20) NOT NULL,
            old_category_id INTEGER NULL, new_category_id INTEGER NULL,
            native_transaction_id INTEGER NULL, request_key CHAR(32) NOT NULL,
            occurred_at DATETIME NOT NULL, UNIQUE(company_id,request_key)
        )");
        // Mirrors the review migration's uniqueness and immutable audit table.
        $db->exec('CREATE UNIQUE INDEX lcc_bc_entry_native_link_unique
            ON lcc_banking_connectors_entries(company_id, account_id, linked_transaction_id)');
        $db->exec("CREATE TABLE lcc_banking_connectors_review_events (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            entry_id BIGINT NOT NULL,
            connection_id BIGINT NOT NULL,
            company_id INT NOT NULL,
            account_id INT NOT NULL,
            actor_id INT NOT NULL,
            operation VARCHAR(16) NOT NULL,
            native_transaction_id INT NULL,
            request_key CHAR(32) NOT NULL,
            source_fingerprint CHAR(64) NOT NULL,
            content_fingerprint CHAR(64) NOT NULL,
            occurred_at DATETIME NOT NULL,
            UNIQUE(company_id,request_key),
            FOREIGN KEY(entry_id) REFERENCES lcc_banking_connectors_entries(id)
        )");
    }

    public static function connection(
        PDO $db, int $id, int $companyId, int $accountId,
        string $provider, string $state = 'read_only',
    ): void {
        $stmt = $db->prepare(
            'INSERT INTO lcc_banking_connectors_connections
             (id, company_id, account_id, provider, state)
             VALUES (:id, :company_id, :account_id, :provider, :state)'
        );
        $stmt->execute([
            ':id' => $id, ':company_id' => $companyId,
            ':account_id' => $accountId, ':provider' => $provider,
            ':state' => $state,
        ]);
    }
}
