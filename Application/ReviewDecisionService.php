<?php
// SPDX-FileCopyrightText: 2026 LibreCode Coop and contributors
// SPDX-License-Identifier: AGPL-3.0-or-later
declare(strict_types=1);

namespace Modules\BankingConnectors\Application;

use DateTimeImmutable;
use DateTimeZone;
use DomainException;
use InvalidArgumentException;
use Modules\BankingConnectors\Domain\BankEntry;
use Modules\BankingConnectors\Domain\MatchingEngine;
use Modules\BankingConnectors\Infrastructure\NativeTransactionReader;
use PDO;
use PDOException;
use Throwable;

/**
 * Audited review of bank statements. It ONLY mutates Banking Connectors-owned tables.
 *
 * This is not an accounting reconciliation and does not post, mark paid,
 * mutate, or attach any native Akaunting transaction/document.
 * The caller MUST enforce Akaunting user, company and write permissions.
 */
final class ReviewDecisionService
{
    private readonly string $entries;
    private readonly string $events;
    private readonly string $transactions;
    private readonly string $categories;

    public function __construct(
        private readonly PDO $db,
        private readonly NativeTransactionReader $native,
        private readonly MatchingEngine $matching,
        string $prefix = '',
    ) {
        if (strlen($prefix) > 40 || !preg_match('/^[A-Za-z0-9_]*$/D', $prefix)) {
            throw new InvalidArgumentException('invalid_table_prefix');
        }
        $this->entries = $prefix . 'lcc_banking_connectors_entries';
        $this->events = $prefix . 'lcc_banking_connectors_review_events';
        $this->transactions = $prefix . 'transactions';
        $this->categories = $prefix . 'categories';
        $this->db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    }

    /**
     * action: accept => manual Banking Connectors-only suggested match;
     *         dismiss => ignore without deleting bank evidence;
     *         reopen => retract a prior decision, retaining the audit trail.
     *
     * @return array{event_id:int,state:string,idempotent:bool}
     */
    public function decide(
        int $companyId,
        int $accountId,
        int $connectionId,
        int $entryId,
        int $actorId,
        string $action,
        ?int $nativeTransactionId,
        string $requestKey,
        int $searchDays = 3,
    ): array {
        if (!in_array($searchDays, [3,14,45,90], true)) {
            throw new InvalidArgumentException('invalid_manual_lookup_days');
        }
        if (min($companyId, $accountId, $connectionId, $entryId, $actorId) < 1
            || !in_array($action, ['accept', 'dismiss', 'reopen'], true)
            || !preg_match('/^[a-f0-9]{32}$/D', $requestKey)
            || ($action === 'accept' && ($nativeTransactionId === null || $nativeTransactionId < 1))
            || ($action !== 'accept' && $nativeTransactionId !== null)) {
            throw new InvalidArgumentException('invalid_review_request');
        }
        if ($this->db->inTransaction()) {
            throw new DomainException('nested_review_transaction_not_allowed');
        }
        $this->db->beginTransaction();
        try {
            $existing = $this->findByRequestKey($companyId, $requestKey);
            if ($existing !== false) {
                if ((int) $existing['account_id'] !== $accountId ||
                    (int) $existing['connection_id'] !== $connectionId ||
                    (int) $existing['entry_id'] !== $entryId ||
                    (int) $existing['actor_id'] !== $actorId ||
                    (string) $existing['operation'] !== $action ||
                    ($existing['native_transaction_id'] === null ? null :
                        (int) $existing['native_transaction_id']) !== $nativeTransactionId) {
                    throw new DomainException('idempotency_key_reused');
                }
                $this->db->commit();
                return ['event_id' => (int) $existing['id'],
                    'state' => self::resultState($action), 'idempotent' => true];
            }

            $query = "SELECT id, company_id, account_id, connection_id, provider,
                             movement_date, direction, amount_cents, currency,
                             state, linked_transaction_id, category_id, category_source,
                             source_fingerprint, content_fingerprint
                      FROM {$this->entries}
                      WHERE id = :id AND connection_id = :connection_id
                        AND company_id = :company_id AND account_id = :account_id";
            if ($this->db->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql') {
                $query .= ' FOR UPDATE';
            }
            $stmt = $this->db->prepare($query);
            $stmt->execute([
                ':id' => $entryId, ':connection_id' => $connectionId,
                ':company_id' => $companyId, ':account_id' => $accountId,
            ]);
            $entry = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!is_array($entry)) {
                throw new DomainException('entry_not_accessible');
            }
            $current = (string) $entry['state'];

            if ($action === 'reopen') {
                if (!in_array($current, ['reviewed', 'dismissed'], true)) {
                    throw new DomainException('entry_not_reopenable');
                }
                $newState = 'pending';
                $newLink = null;
            } else {
                if ($current === 'conflict') {
                    throw new DomainException('bank_revision_conflict');
                }
                if ($current !== 'pending') {
                    throw new DomainException('entry_already_reviewed');
                }
                $newState = $action === 'accept' ? 'reviewed' : 'dismissed';
                $newLink = $action === 'accept' ? $nativeTransactionId : null;
            }

            if ($action === 'accept') {
                $movement = new BankEntry(
                    companyId: $companyId,
                    accountId: $accountId,
                    provider: (string) $entry['provider'],
                    externalId: null,
                    date: (string) $entry['movement_date'],
                    direction: (string) $entry['direction'],
                    amountCents: (int) $entry['amount_cents'],
                    currency: (string) $entry['currency'],
                );
                $near = $this->native->near($companyId, $accountId, $movement->date, $searchDays);
                if ($near['truncated'] || $near['invalid']) {
                    throw new DomainException('native_candidates_incomplete');
                }
                $suggestions = $this->matching->suggestByFacts($movement, $near['rows'], $searchDays);
                if (!in_array($nativeTransactionId, $suggestions['candidate_ids'], true)) {
                    throw new DomainException('native_transaction_not_eligible');
                }
            }

            $category = $entry['category_id'] === null ? null : (int) $entry['category_id'];
            $categorySource = $entry['category_source'] === null
                ? null : (string) $entry['category_source'];
            if ($action === 'accept' && $category === null && $categorySource === null) {
                // The user has explicitly chosen an eligible native record.
                // Its existing category is a fact, not an ML prediction. Copy
                // only an active, same-company, correct-direction category.
                $inherited = $this->nativeCategory(
                    $companyId, $accountId, (int) $nativeTransactionId,
                    (string) $entry['direction']
                );
                if ($inherited !== null) {
                    $category = $inherited;
                    $categorySource = 'linked_native';
                }
            } elseif ($action === 'reopen' && $categorySource === 'linked_native') {
                // Removing the explicit link must also remove its derived
                // category. Never erase manual classifications or rule choices.
                $category = null;
                $categorySource = null;
            }

            $now = (new DateTimeImmutable('now', new DateTimeZone('UTC')))
                ->format('Y-m-d H:i:s');
            $update = $this->db->prepare(
                "UPDATE {$this->entries}
                 SET state = :state, linked_transaction_id = :native_id,
                     category_id = :category_id, category_source = :category_source,
                     updated_at = :updated_at
                 WHERE id = :id AND company_id = :company_id
                   AND account_id = :account_id AND connection_id = :connection_id
                   AND state = :previous_state"
            );
            $update->execute([
                ':state' => $newState, ':native_id' => $newLink,
                ':category_id' => $category, ':category_source' => $categorySource,
                ':updated_at' => $now, ':id' => $entryId,
                ':company_id' => $companyId, ':account_id' => $accountId,
                ':connection_id' => $connectionId, ':previous_state' => $current,
            ]);
            if ($update->rowCount() !== 1) {
                throw new DomainException('concurrent_review_conflict');
            }
            $audit = $this->db->prepare(
                "INSERT INTO {$this->events}
                 (entry_id, connection_id, company_id, account_id, actor_id,
                  operation, native_transaction_id, request_key,
                  source_fingerprint, content_fingerprint, occurred_at)
                 VALUES
                 (:entry_id, :connection_id, :company_id, :account_id, :actor_id,
                  :operation, :native_id, :request_key,
                  :source_fingerprint, :content_fingerprint, :occurred_at)"
            );
            $audit->execute([
                ':entry_id' => $entryId, ':connection_id' => $connectionId,
                ':company_id' => $companyId, ':account_id' => $accountId,
                ':actor_id' => $actorId, ':operation' => $action,
                ':native_id' => $nativeTransactionId, ':request_key' => $requestKey,
                ':source_fingerprint' => (string) $entry['source_fingerprint'],
                ':content_fingerprint' => (string) $entry['content_fingerprint'],
                ':occurred_at' => $now,
            ]);
            $eventId = (int) $this->db->lastInsertId();
            $this->db->commit();
            return ['event_id' => $eventId, 'state' => $newState, 'idempotent' => false];
        } catch (Throwable $error) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            if ($error instanceof PDOException &&
                in_array((string) $error->getCode(), ['23000', '23505'], true)) {
                throw new DomainException('native_match_or_audit_uniqueness_conflict');
            }
            throw $error;
        }
    }

    private function nativeCategory(int $company, int $account, int $nativeId, string $direction): ?int
    {
        if (!in_array($direction, ['credit', 'debit'], true)) return null;
        $type = $direction === 'credit' ? 'income' : 'expense';
        // A transfer leg may be associated, but must not be classified as an
        // operating income or expense by inheriting a malformed category.
        $stmt = $this->db->prepare(
            "SELECT c.id FROM {$this->transactions} t
             JOIN {$this->categories} c ON c.id = t.category_id
               AND c.company_id = t.company_id AND c.enabled = 1
               AND c.deleted_at IS NULL AND c.type = :category_type
             WHERE t.id = :native AND t.company_id = :company
               AND t.account_id = :account AND t.type = :transaction_type
               AND t.deleted_at IS NULL"
        );
        $stmt->execute([
            ':category_type' => $type, ':transaction_type' => $type,
            ':native' => $nativeId,
            ':company' => $company, ':account' => $account,
        ]);
        $value = $stmt->fetchColumn();
        return $value === false ? null : (int) $value;
    }

    /** @return array<int,array<string,mixed>> */
    public function history(int $companyId, int $accountId, int $entryId): array
    {
        if (min($companyId, $accountId, $entryId) < 1) {
            throw new InvalidArgumentException('invalid_review_scope');
        }
        // A matching bank entry must exist; otherwise don't reveal whether
        // another company has an audit trail for the same integer ID.
        $scope = $this->db->prepare(
            "SELECT id FROM {$this->entries}
             WHERE id = :id AND company_id = :company_id AND account_id = :account_id"
        );
        $scope->execute([
            ':id' => $entryId, ':company_id' => $companyId, ':account_id' => $accountId,
        ]);
        if ($scope->fetchColumn() === false) {
            throw new DomainException('entry_not_accessible');
        }
        $query = $this->db->prepare(
            "SELECT id, actor_id, operation, native_transaction_id, occurred_at
             FROM {$this->events}
             WHERE entry_id = :entry_id AND company_id = :company_id
               AND account_id = :account_id ORDER BY id ASC"
        );
        $query->execute([
            ':entry_id' => $entryId, ':company_id' => $companyId,
            ':account_id' => $accountId,
        ]);
        return $query->fetchAll(PDO::FETCH_ASSOC);
    }

    /** @return array<string,mixed>|false */
    private function findByRequestKey(int $company, string $key): array|false
    {
        $query = $this->db->prepare(
            "SELECT id, company_id, account_id, connection_id,
                    entry_id, actor_id, operation, native_transaction_id
             FROM {$this->events}
             WHERE company_id = :company_id AND request_key = :request_key"
        );
        $query->execute([':company_id' => $company, ':request_key' => $key]);
        return $query->fetch(PDO::FETCH_ASSOC);
    }

    private static function resultState(string $action): string
    {
        return match ($action) {
            'accept' => 'reviewed',
            'dismiss' => 'dismissed',
            'reopen' => 'pending',
        };
    }
}
