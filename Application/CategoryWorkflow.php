<?php
// SPDX-FileCopyrightText: 2026 LibreCode Coop and contributors
// SPDX-License-Identifier: AGPL-3.0-or-later
declare(strict_types=1);

namespace Modules\BankingConnectors\Application;

use DateTimeImmutable;
use DateTimeZone;
use DomainException;
use InvalidArgumentException;
use Modules\BankingConnectors\Domain\AkauntingTransactionMapper;
use Modules\BankingConnectors\Domain\CategoryRuleEngine;
use PDO;
use PDOException;
use Throwable;

/**
 * Bank classification rules and explicit, audited manual category decisions.
 *
 * Rules write only module staging; updating native Akaunting category requires
 * a separate user-confirmed flag and only changes category_id/updated_at.
 * No payments, invoices, taxes, amounts or dates are ever modified here.
 */
final class CategoryWorkflow
{
    private readonly string $rules;
    private readonly string $entries;
    private readonly string $events;
    private readonly string $categories;
    private readonly string $accounts;
    private readonly string $transactions;

    public function __construct(private readonly PDO $db, string $prefix = '')
    {
        if (!preg_match('/^[A-Za-z0-9_]{0,40}$/D', $prefix)) {
            throw new InvalidArgumentException('invalid_table_prefix');
        }
        $this->rules = $prefix . 'lcc_banking_connectors_category_rules';
        $this->entries = $prefix . 'lcc_banking_connectors_entries';
        $this->events = $prefix . 'lcc_banking_connectors_category_events';
        $this->categories = $prefix . 'categories';
        $this->accounts = $prefix . 'accounts';
        $this->transactions = $prefix . 'transactions';
        $this->db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    }

    /** @return array<int,array<string,mixed>> */
    public function rules(int $company, int $account): array
    {
        $this->checkScope($company, $account);
        $q = $this->db->prepare(
            "SELECT r.id, r.direction, r.description_contains, r.category_id,
                    r.priority, r.enabled, c.name AS category_name
             FROM {$this->rules} r
             JOIN {$this->categories} c ON c.id=r.category_id AND c.company_id=r.company_id
             WHERE r.company_id=:company AND r.account_id=:account
             ORDER BY r.priority ASC, r.id ASC LIMIT 101"
        );
        $q->execute([':company' => $company, ':account' => $account]);
        $rows = $q->fetchAll(PDO::FETCH_ASSOC);
        if (count($rows) > 100) {
            throw new DomainException('too_many_category_rules');
        }
        return $rows;
    }

    public function createRule(
        int $company, int $account, int $actor, string $direction,
        string $descriptionContains, int $category, int $priority = 100
    ): int {
        $this->checkScope($company, $account);
        if ($actor < 1 || !in_array($direction, ['credit','debit'], true) ||
            $priority < 1 || $priority > 999 || $category < 1) {
            throw new InvalidArgumentException('invalid_category_rule');
        }
        $needle = CategoryRuleEngine::needle($descriptionContains);
        $this->assertCategory($company, $category, $direction);
        $this->assertAccount($company, $account);

        $rules = $this->rules($company, $account);
        if (count($rules) >= 100) {
            throw new DomainException('too_many_category_rules');
        }
        foreach ($rules as $rule) {
            if ((string) $rule['direction'] === $direction &&
                CategoryRuleEngine::normalize((string) $rule['description_contains']) === $needle) {
                throw new DomainException('duplicate_category_rule');
            }
        }

        $q = $this->db->prepare(
            "INSERT INTO {$this->rules}
            (company_id,account_id,direction,description_contains,category_id,priority,
             enabled,created_by,created_at,updated_at)
            VALUES (:company,:account,:direction,:needle,:category,:priority,
                    1,:actor,:now,:updated)"
        );
        $now = self::utc();
        try {
            $q->execute([
                ':company'=>$company, ':account'=>$account, ':direction'=>$direction,
                ':needle'=>$needle, ':category'=>$category, ':priority'=>$priority,
                ':actor'=>$actor, ':now'=>$now, ':updated'=>$now,
            ]);
        } catch (PDOException $e) {
            if (in_array((string)$e->getCode(),['23000','23505'],true)) {
                throw new DomainException('duplicate_category_rule');
            }
            throw $e;
        }
        return (int) $this->db->lastInsertId();
    }

    public function enableRule(int $company, int $account, int $ruleId, bool $enabled): void
    {
        $this->checkScope($company, $account);
        if ($ruleId < 1) throw new InvalidArgumentException('invalid_category_rule');
        $this->assertAccount($company, $account);
        $q = $this->db->prepare(
            "SELECT id,direction,category_id FROM {$this->rules}
             WHERE id=:id AND company_id=:company AND account_id=:account"
        );
        $q->execute([':id'=>$ruleId, ':company'=>$company, ':account'=>$account]);
        $row = $q->fetch(PDO::FETCH_ASSOC);
        if (!is_array($row)) throw new DomainException('category_rule_not_accessible');
        if ($enabled) {
            $this->assertCategory($company,(int)$row['category_id'],(string)$row['direction']);
        }
        $write = $this->db->prepare(
            "UPDATE {$this->rules} SET enabled=:enabled,updated_at=:now
             WHERE id=:id AND company_id=:company AND account_id=:account"
        );
        $write->execute([':enabled'=>(int)$enabled,':now'=>self::utc(),
            ':id'=>$ruleId,':company'=>$company,':account'=>$account]);
    }

    /**
     * A manual classification always wins over suggested rules. Native change
     * requires an existing manually linked and non-reconciled transaction.
     * @return array{event_id:int,category_id:int|null,applied_to_native:bool,idempotent:bool}
     */
    public function classify(
        int $company, int $account, int $entryId, int $actor,
        ?int $category, bool $applyNative, ?int $expectedNativeCategory,
        string $requestKey
    ): array {
        $this->checkScope($company, $account);
        if ($entryId < 1 || $actor < 1 ||
            ($category !== null && $category < 1) ||
            ($applyNative && $category === null) ||
            !preg_match('/^[a-f0-9]{32}$/D', $requestKey)) {
            throw new InvalidArgumentException('invalid_category_decision');
        }
        if ($this->db->inTransaction()) {
            throw new DomainException('nested_category_decision_not_allowed');
        }
        $this->db->beginTransaction();
        try {
            $q = $this->db->prepare(
                "SELECT id,account_id,entry_id,actor_id,new_category_id,native_transaction_id
                 FROM {$this->events}
                 WHERE company_id=:company AND request_key=:key"
            );
            $q->execute([':company'=>$company, ':key'=>$requestKey]);
            $event = $q->fetch(PDO::FETCH_ASSOC);
            if (is_array($event)) {
                if ((int)$event['account_id']!==$account ||
                    (int)$event['entry_id']!==$entryId ||
                    (int)$event['actor_id']!==$actor ||
                    ($event['new_category_id'] === null ? null : (int)$event['new_category_id'])!==$category ||
                    ($event['native_transaction_id'] !== null) !== $applyNative) {
                    throw new DomainException('idempotency_key_reused');
                }
                $this->db->commit();
                return ['event_id'=>(int)$event['id'],'category_id'=>$category,
                    'applied_to_native'=>$applyNative,'idempotent'=>true];
            }

            $sql = "SELECT id, company_id, account_id, movement_date, amount_cents,
                           direction,currency,state,linked_transaction_id,category_id
                    FROM {$this->entries}
                    WHERE id=:id AND company_id=:company AND account_id=:account";
            if ($this->db->getAttribute(PDO::ATTR_DRIVER_NAME)==='mysql') $sql .= ' FOR UPDATE';
            $s = $this->db->prepare($sql);
            $s->execute([':id'=>$entryId,':company'=>$company,':account'=>$account]);
            $entry = $s->fetch(PDO::FETCH_ASSOC);
            if (!is_array($entry) ||
                !in_array($entry['state'],['pending','reviewed'], true)) {
                throw new DomainException('category_entry_not_accessible');
            }
            $this->assertAccount($company,$account);
            if ($category!==null) $this->assertCategory($company,$category,(string)$entry['direction']);

            $nativeId = null;
            if ($applyNative) {
                if ($entry['state']!=='reviewed' || $entry['linked_transaction_id']===null) {
                    throw new DomainException('native_category_requires_reviewed_link');
                }
                $nativeId = (int) $entry['linked_transaction_id'];
                $native = $this->lockNative($company,$account,$nativeId);
                if ((int)($native['reconciled']??0)!==0 ||
                    $native['document_id']!==null ||
                    (int)($native['parent_id']??0)!==0 ||
                    $native['split_id']!==null) {
                    throw new DomainException('native_category_is_locked');
                }
                $currentNativeCategory = $native['category_id']===null
                    ? null : (int)$native['category_id'];
                if ($currentNativeCategory!==$expectedNativeCategory) {
                    throw new DomainException('native_category_changed_since_preview');
                }
                $mapped = (new AkauntingTransactionMapper())->map($native);
                if ((int)$mapped['amount_cents']!==(int)$entry['amount_cents'] ||
                    $mapped['currency']!==$entry['currency'] ||
                    $mapped['type']!==($entry['direction']==='credit'?'income':'expense')) {
                    throw new DomainException('native_transaction_financial_facts_changed');
                }
                if ($currentNativeCategory !== $category) {
                    $update = $this->db->prepare(
                        "UPDATE {$this->transactions}
                         SET category_id=:category,updated_at=:now
                         WHERE id=:id AND company_id=:company AND account_id=:account
                         AND reconciled=0 AND document_id IS NULL AND deleted_at IS NULL"
                    );
                    $update->execute([':category'=>$category,':now'=>self::utc(),
                        ':id'=>$nativeId,':company'=>$company,':account'=>$account]);
                    if ($update->rowCount()!==1) {
                        throw new DomainException('native_category_concurrent_update');
                    }
                }
            }
            $up = $this->db->prepare(
                "UPDATE {$this->entries}
                 SET category_id=:category,category_source='manual',
                     category_rule_id=NULL,updated_at=:now
                 WHERE id=:id AND company_id=:company AND account_id=:account"
            );
            $up->execute([':category'=>$category,':now'=>self::utc(),
                ':id'=>$entryId,':company'=>$company,':account'=>$account]);
            $audit = $this->db->prepare(
                "INSERT INTO {$this->events}
                (company_id,account_id,entry_id,actor_id,operation,old_category_id,
                 new_category_id,native_transaction_id,request_key,occurred_at)
                VALUES (:company,:account,:entry,:actor,:operation,:old,:new,:native,:key,:now)"
            );
            $audit->execute([
                ':company'=>$company,':account'=>$account,':entry'=>$entryId,
                ':actor'=>$actor,':operation'=>$applyNative?'apply_native':'assign',
                ':old'=>$entry['category_id'],':new'=>$category,':native'=>$nativeId,
                ':key'=>$requestKey,':now'=>self::utc(),
            ]);
            $eventId=(int)$this->db->lastInsertId();
            $this->db->commit();
            return ['event_id'=>$eventId,'category_id'=>$category,
                'applied_to_native'=>$applyNative,'idempotent'=>false];
        } catch (Throwable $e) {
            if ($this->db->inTransaction()) $this->db->rollBack();
            if ($e instanceof PDOException &&
                in_array((string)$e->getCode(), ['23000','23505'],true)) {
                throw new DomainException('category_unique_constraint_conflict');
            }
            throw $e;
        }
    }

    private function lockNative(int $company,int $account,int $id): array
    {
        $sql = "SELECT id,company_id,account_id,type,paid_at,amount,currency_code,
                       category_id,reconciled,document_id,parent_id,split_id,deleted_at
                FROM {$this->transactions}
                WHERE id=:id AND company_id=:company AND account_id=:account
                  AND type IN ('income','expense') AND deleted_at IS NULL";
        if ($this->db->getAttribute(PDO::ATTR_DRIVER_NAME)==='mysql') $sql .= ' FOR UPDATE';
        $q=$this->db->prepare($sql);
        $q->execute([':id'=>$id,':company'=>$company,':account'=>$account]);
        $row=$q->fetch(PDO::FETCH_ASSOC);
        if (!is_array($row)) throw new DomainException('native_transaction_not_accessible');
        return $row;
    }

    private function assertCategory(int $company,int $category,string $direction): void
    {
        $type=match ($direction) {
            'credit'=>'income',
            'debit'=>'expense',
            default=>throw new InvalidArgumentException('invalid_category_direction')
        };
        $q=$this->db->prepare(
            "SELECT id FROM {$this->categories}
             WHERE id=:id AND company_id=:company AND type=:type
               AND enabled=1 AND deleted_at IS NULL"
        );
        $q->execute([':id'=>$category,':company'=>$company,':type'=>$type]);
        if ($q->fetchColumn()===false) throw new DomainException('category_not_accessible');
    }

    private function assertAccount(int $company,int $account): void
    {
        $q=$this->db->prepare(
            "SELECT id FROM {$this->accounts}
             WHERE id=:id AND company_id=:company AND type='bank'
               AND enabled=1 AND deleted_at IS NULL"
        );
        $q->execute([':id'=>$account,':company'=>$company]);
        if ($q->fetchColumn()===false) throw new DomainException('bank_account_not_accessible');
    }

    private function checkScope(int $company,int $account): void
    {
        if ($company < 1 || $account < 1) {
            throw new InvalidArgumentException('invalid_category_scope');
        }
    }

    private static function utc(): string
    {
        return (new DateTimeImmutable('now',new DateTimeZone('UTC')))
            ->format('Y-m-d H:i:s');
    }
}
