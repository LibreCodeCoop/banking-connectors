<?php
// SPDX-FileCopyrightText: 2026 LibreCode Coop and contributors
// SPDX-License-Identifier: AGPL-3.0-or-later
declare(strict_types=1);

namespace Modules\BankingConnectors\Infrastructure;

use DateTimeImmutable;
use DateTimeZone;
use DomainException;
use InvalidArgumentException;
use Modules\BankingConnectors\Domain\BankEntry;
use Modules\BankingConnectors\Domain\CategoryRuleEngine;
use PDO;
use PDOException;
use RuntimeException;
use Throwable;

/**
 * Atomic staging into module-owned tables. No Akaunting accounting writes.
 *
 * This is a low-level persistence boundary, not an authorization endpoint.
 * The Web/API/MCP layer must enforce native Akaunting ACL before calling it.
 */
final class PdoEntryStage
{
    private readonly string $connectionsTable;
    private readonly string $entriesTable;

    public function __construct(private readonly PDO $db, string $tablePrefix = '')
    {
        // Akaunting's Laravel migrations honor DB_PREFIX, while raw PDO does not.
        // Strict identifier validation is mandatory before constructing SQL.
        if (strlen($tablePrefix) > 40 || !preg_match('/^[A-Za-z0-9_]*$/D', $tablePrefix)) {
            throw new InvalidArgumentException('invalid_table_prefix');
        }
        $this->connectionsTable = $tablePrefix . 'lcc_banking_connectors_connections';
        $this->entriesTable = $tablePrefix . 'lcc_banking_connectors_entries';
        $this->db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    }

    /**
     * @param array<int,BankEntry> $entries
     * @return array{inserted:int,duplicates:int,conflicts:int}
     */
    public function ingest(
        int $connectionId,
        int $companyId,
        int $accountId,
        string $provider,
        array $entries,
        string $hmacSecret,
    ): array {
        if ($companyId < 1 || $accountId < 1 || $connectionId < 1) {
            throw new InvalidArgumentException('invalid_scope');
        }
        if (count($entries) > 500) {
            throw new InvalidArgumentException('batch_too_large');
        }
        if (strlen($hmacSecret) < 32) {
            throw new InvalidArgumentException('fingerprint_key_too_short');
        }

        // Validate the complete batch before beginning to mutate persistent state.
        $prepared = [];
        foreach ($entries as $entry) {
            if (!$entry instanceof BankEntry) {
                throw new InvalidArgumentException('invalid_bank_entry');
            }
            if ($entry->companyId !== $companyId || $entry->accountId !== $accountId ||
                $entry->provider !== $provider) {
                throw new DomainException('entry_scope_mismatch');
            }
            $fingerprint = $entry->fingerprint($hmacSecret);
            if ($fingerprint === null) {
                throw new DomainException('missing_stable_bank_identity');
            }
            if (isset($prepared[$fingerprint])) {
                throw new DomainException('duplicate_bank_identity_in_batch');
            }
            $prepared[$fingerprint] = [
                'entry' => $entry,
                'fingerprint' => $fingerprint,
                'content' => self::contentFingerprint($entry, $hmacSecret),
            ];
        }

        if ($this->db->inTransaction()) {
            throw new RuntimeException('nested_transaction_not_supported');
        }

        $counts = ['inserted' => 0, 'duplicates' => 0, 'conflicts' => 0];
        $now = (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format('Y-m-d H:i:s');

        $this->db->beginTransaction();
        try {
            $this->assertConnection($connectionId, $companyId, $accountId, $provider, true);
            $rules = $this->activeRules($companyId, $accountId);
            $ruleEngine = new CategoryRuleEngine();
            foreach ($prepared as $payload) {
                /** @var BankEntry $entry */
                $entry = $payload['entry'];
                $fingerprint = $payload['fingerprint'];
                $content = $payload['content'];
                $suggested = $ruleEngine->suggest($entry->description, $entry->direction, $rules);

                try {
                    $insert = $this->db->prepare(
                        "INSERT INTO {$this->entriesTable}
                        (connection_id, company_id, account_id, provider,
                         source_fingerprint, content_fingerprint, movement_date,
                         direction, amount_cents, currency, bank_description,
                         category_id, category_source, category_rule_id, state, conflict_count,
                         first_seen_at, last_seen_at, created_at, updated_at)
                        VALUES
                        (:connection_id, :company_id, :account_id, :provider,
                         :source_key, :content_key, :movement_date,
                         :direction, :amount_cents, :currency, :bank_description,
                         :category_id, :category_source, :rule_id, :state, 0,
                         :first_seen_at, :last_seen_at, :created_at, :updated_at)"
                    );
                    $insert->execute([
                        ':connection_id' => $connectionId,
                        ':company_id' => $companyId,
                        ':account_id' => $accountId,
                        ':provider' => $provider,
                        ':source_key' => $fingerprint,
                        ':content_key' => $content,
                        ':movement_date' => $entry->date,
                        ':direction' => $entry->direction,
                        ':amount_cents' => $entry->amountCents,
                        ':currency' => $entry->currency,
                        ':bank_description' => $entry->description,
                        ':category_id' => $suggested['category_id'] ?? null,
                        ':category_source' => $suggested === null ? null : 'rule',
                        ':rule_id' => $suggested['rule_id'] ?? null,
                        ':state' => 'pending',
                        ':first_seen_at' => $now,
                        ':last_seen_at' => $now,
                        ':created_at' => $now,
                        ':updated_at' => $now,
                    ]);
                    ++$counts['inserted'];
                    continue;
                } catch (PDOException $e) {
                    // Only an existing unique source key can be treated as a
                    // duplicate. Other SQL integrity errors must propagate.
                    if (!$this->isSourceUniqueViolation($e)) {
                        throw $e;
                    }
                }

                $query = "SELECT id, content_fingerprint, state
                          FROM {$this->entriesTable}
                          WHERE connection_id = :connection_id
                            AND company_id = :company_id
                            AND account_id = :account_id
                            AND source_fingerprint = :source_key";
                // A locking read sees the latest committed row under MySQL
                // repeatable-read, including after a concurrent unique collision.
                if ($this->db->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql') {
                    $query .= ' FOR UPDATE';
                }
                $select = $this->db->prepare($query);
                $select->execute([
                    ':connection_id' => $connectionId,
                    ':company_id' => $companyId,
                    ':account_id' => $accountId,
                    ':source_key' => $fingerprint,
                ]);
                $existing = $select->fetch(PDO::FETCH_ASSOC);
                if (!is_array($existing)) {
                    throw new RuntimeException('source_integrity_error');
                }

                if (!hash_equals((string) $existing['content_fingerprint'], $content)) {
                    $update = $this->db->prepare(
                        "UPDATE {$this->entriesTable}
                         SET state = 'conflict',
                             conflict_count = conflict_count + 1,
                             last_seen_at = :observed, updated_at = :updated
                         WHERE id = :id AND company_id = :company_id"
                    );
                    $update->execute([
                        ':observed' => $now, ':updated' => $now,
                        ':id' => $existing['id'], ':company_id' => $companyId,
                    ]);
                    ++$counts['conflicts'];
                    continue;
                }
                if ($existing['state'] === 'conflict') {
                    // A prior conflict requires a deliberate human decision.
                    ++$counts['conflicts'];
                    continue;
                }

                $update = $this->db->prepare(
                    "UPDATE {$this->entriesTable}
                     SET last_seen_at = :observed, updated_at = :updated,
                         bank_description = CASE
                           WHEN :description IS NOT NULL THEN :new_description
                           ELSE bank_description END,
                         category_id = CASE
                           WHEN state='pending' AND (category_source IS NULL OR category_source='rule')
                           THEN :category ELSE category_id END,
                         category_rule_id = CASE
                           WHEN state='pending' AND (category_source IS NULL OR category_source='rule')
                           THEN :rule_id ELSE category_rule_id END,
                         category_source = CASE
                           WHEN state='pending' AND (category_source IS NULL OR category_source='rule')
                           THEN :source ELSE category_source END
                     WHERE id = :id AND company_id = :company_id"
                );
                $update->execute([
                    ':observed' => $now, ':updated' => $now,
                    ':description' => $entry->description,
                    ':new_description' => $entry->description,
                    ':category' => $suggested['category_id'] ?? null,
                    ':rule_id' => $suggested['rule_id'] ?? null,
                    ':source' => $suggested === null ? null : 'rule',
                    ':id' => $existing['id'], ':company_id' => $companyId,
                ]);
                ++$counts['duplicates'];
            }
            $this->db->commit();
            return $counts;
        } catch (Throwable $error) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            throw $error;
        }
    }

    /**
     * This data may be shown only after the caller checks native Akaunting
     * company and banking-account ACL. No raw source IDs or HMACs are exposed.
     *
     * @return array<int,array<string,mixed>>
     */
    public function listUnresolved(int $companyId, int $accountId, int $connectionId, int $limit = 50): array
    {
        if ($limit < 1 || $limit > 200) {
            throw new InvalidArgumentException('invalid_page_limit');
        }
        $this->assertConnection($connectionId, $companyId, $accountId, null, false);
        $stmt = $this->db->prepare(
            "SELECT id, provider, movement_date, direction, amount_cents, currency,
                    bank_description, category_id, category_source, category_rule_id,
                    state, linked_transaction_id, conflict_count, first_seen_at, last_seen_at
             FROM {$this->entriesTable}
             WHERE connection_id = :connection_id AND company_id = :company_id
               AND account_id = :account_id
               AND state IN ('pending', 'conflict')
             ORDER BY movement_date DESC, id DESC LIMIT :limit"
        );
        $stmt->bindValue(':connection_id', $connectionId, PDO::PARAM_INT);
        $stmt->bindValue(':company_id', $companyId, PDO::PARAM_INT);
        $stmt->bindValue(':account_id', $accountId, PDO::PARAM_INT);
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /** @return array<int,array<string,mixed>> */
    /**
     * List recent staged movements across review states for correction without
     * changing the legacy pending-only JSON API contract.
     * @return array<int,array<string,mixed>>
     */
    public function listForReview(int $company, int $account, int $connection, string $filter, int $limit=50): array
    {
        if (!in_array($filter,['pending','reviewed','dismissed','all'],true) ||
            $limit<1 || $limit>100) {
            throw new InvalidArgumentException('invalid_review_filter');
        }
        $this->assertConnection($connection,$company,$account,null,false);
        $states=match($filter) {
            'pending'=>"'pending','conflict'",
            'reviewed'=>"'reviewed'",
            'dismissed'=>"'dismissed'",
            default=>"'pending','conflict','reviewed','dismissed'",
        };
        $q=$this->db->prepare(
            "SELECT id,provider,movement_date,direction,amount_cents,currency,
                    bank_description,category_id,category_source,category_rule_id,
                    state,linked_transaction_id,conflict_count,first_seen_at,last_seen_at
             FROM {$this->entriesTable}
             WHERE company_id=:company AND account_id=:account AND connection_id=:connection
               AND state IN ({$states})
             ORDER BY movement_date DESC,id DESC LIMIT :limit"
        );
        $q->bindValue(':company',$company,PDO::PARAM_INT);
        $q->bindValue(':account',$account,PDO::PARAM_INT);
        $q->bindValue(':connection',$connection,PDO::PARAM_INT);
        $q->bindValue(':limit',$limit,PDO::PARAM_INT);
        $q->execute();
        return $q->fetchAll(PDO::FETCH_ASSOC);
    }

    /** Fetch exactly one staged entry after verifying connection and tenant ownership. */
    public function findForReview(int $company, int $account, int $connection, int $entry): array
    {
        if ($entry < 1) throw new InvalidArgumentException('invalid_entry_id');
        $this->assertConnection($connection, $company, $account, null, false);
        $q = $this->db->prepare(
            "SELECT id,provider,movement_date,direction,amount_cents,currency,
                    bank_description,category_id,category_source,category_rule_id,
                    state,linked_transaction_id,conflict_count,first_seen_at,last_seen_at
             FROM {$this->entriesTable}
             WHERE id=:entry AND company_id=:company AND account_id=:account
               AND connection_id=:connection LIMIT 1"
        );
        foreach ([':entry'=>$entry,':company'=>$company,':account'=>$account,':connection'=>$connection] as $key=>$value) {
            $q->bindValue($key,$value,PDO::PARAM_INT);
        }
        $q->execute();
        $row=$q->fetch(PDO::FETCH_ASSOC);
        return is_array($row) ? [$row] : [];
    }

    private function activeRules(int $company, int $account): array
    {
        $rules = preg_replace('/lcc_banking_connectors_connections$/', 'lcc_banking_connectors_category_rules', $this->connectionsTable);
        $cats = preg_replace('/lcc_banking_connectors_connections$/', 'categories', $this->connectionsTable);
        if (!is_string($rules) || !is_string($cats)) {
            throw new RuntimeException('invalid_category_rule_table');
        }
        $q = $this->db->prepare(
            "SELECT r.id,r.direction,r.description_contains,r.category_id,r.priority,r.enabled
             FROM {$rules} r JOIN {$cats} c
                  ON c.id=r.category_id AND c.company_id=r.company_id
             WHERE r.company_id=:company AND r.account_id=:account
               AND r.enabled=1 AND c.enabled=1 AND c.deleted_at IS NULL
               AND ((r.direction='credit' AND c.type='income')
                OR (r.direction='debit' AND c.type='expense'))
             ORDER BY r.priority ASC,r.id ASC LIMIT 101"
        );
        $q->execute([':company'=>$company,':account'=>$account]);
        $rows = $q->fetchAll(PDO::FETCH_ASSOC);
        if (count($rows)>100) {
            // Invalid rule configuration must not silently degrade ingestion.
            throw new DomainException('too_many_active_category_rules');
        }
        return $rows;
    }

    private function assertConnection(
        int $connectionId, int $companyId, int $accountId,
        ?string $provider, bool $requiresEnabled,
    ): void {
        $sql = "SELECT id, provider, state FROM {$this->connectionsTable}
                WHERE id = :id AND company_id = :company_id AND account_id = :account_id";
        $statement = $this->db->prepare($sql);
        $statement->execute([
            ':id' => $connectionId,
            ':company_id' => $companyId,
            ':account_id' => $accountId,
        ]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);
        if (!is_array($row) || ($provider !== null && $provider !== $row['provider']) ||
            ($requiresEnabled && !in_array($row['state'], ['read_only', 'enabled'], true))) {
            // Avoid revealing whether another company/account owns this connection.
            throw new DomainException('connection_not_accessible');
        }
    }

    private function isSourceUniqueViolation(PDOException $error): bool
    {
        $details = $error->errorInfo;
        $driver = $this->db->getAttribute(PDO::ATTR_DRIVER_NAME);
        $message = is_string($details[2] ?? null) ? $details[2] : '';

        return match ($driver) {
            'sqlite' => (string) $error->getCode() === '23000'
                && str_starts_with($message, 'UNIQUE constraint failed:')
                && str_contains($message, $this->entriesTable . '.connection_id')
                && str_contains($message, $this->entriesTable . '.source_fingerprint'),
            'mysql' => (int) ($details[1] ?? -1) === 1062
                && str_contains($message, 'lcc_bc_entry_source_unique'),
            default => false,
        };
    }

    private static function contentFingerprint(BankEntry $entry, string $secret): string
    {
        $canonical = implode('|', [
            'normalized-entry-v1',
            $entry->date,
            $entry->direction,
            (string) $entry->amountCents,
            $entry->currency,
        ]);
        return hash_hmac('sha256', $canonical, $secret);
    }
}
