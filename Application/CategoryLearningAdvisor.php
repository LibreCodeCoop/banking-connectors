<?php
// SPDX-FileCopyrightText: 2026 LibreCode Coop and contributors
// SPDX-License-Identifier: AGPL-3.0-or-later
declare(strict_types=1);

namespace Modules\BankingConnectors\Application;

use InvalidArgumentException;
use Modules\BankingConnectors\Domain\CategoryRuleEngine;
use Modules\BankingConnectors\Domain\TokenCategoryLearner;
use PDO;

/**
 * Scoped read-only advisor.
 *
 * No model downloads, LLM requests, exported training rows, opaque model
 * serialization, or mutation of Akaunting accounting/contact records.
 * Each preview learns from the *latest* explicit human category decision for
 * each movement; disabling/relabeling is reflected on the next request.
 */
final class CategoryLearningAdvisor
{
    private readonly string $events;
    private readonly string $entries;
    private readonly string $categories;
    private readonly string $contacts;

    public function __construct(private readonly PDO $db, string $prefix = '')
    {
        if (!preg_match('/^[A-Za-z0-9_]{0,40}$/D', $prefix)) {
            throw new InvalidArgumentException('invalid_table_prefix');
        }
        $this->events = $prefix . 'lcc_banking_connectors_category_events';
        $this->entries = $prefix . 'lcc_banking_connectors_entries';
        $this->categories = $prefix . 'categories';
        $this->contacts = $prefix . 'contacts';
        $this->db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    }

    /**
     * @param array<int,array<string,mixed>> $items Already company/account scoped by caller.
     * @return array{total_labels:int,ready:bool,suggestions:array<int,array<string,mixed>>,contacts:array<int,array<string,mixed>>}
     */
    public function advise(
        int $company, int $account, array $items, array $permittedContactTypes = []
    ): array {
        // Bank-reading permission does not imply permission to view customers
        // or vendors. The caller must explicitly grant each native ACL.
        $permittedContactTypes = array_values(array_unique($permittedContactTypes));
        if (array_diff($permittedContactTypes, ['customer','vendor']) !== []) {
            throw new InvalidArgumentException('invalid_contact_read_scope');
        }
        if ($company < 1 || $account < 1 || count($items) > 100) {
            throw new InvalidArgumentException('invalid_learning_scope');
        }
        $training = $this->labeledExamples($company, $account);
        $overLimit = count($training) > TokenCategoryLearner::MAX_EXAMPLES;
        if ($overLimit) $training = [];
        $samples = array_values(array_filter(
            array_map(static fn(array $row): array => [
                'direction' => (string)$row['direction'],
                'category_id' => (int)$row['category_id'],
                'description' => trim((string)$row['bank_description'].' '.self::detailEvidence($row['bank_details']??null,(string)$row['provider'])),
            ], $training),
            static fn(array $row): bool =>
                count(TokenCategoryLearner::tokens($row['description']))
                >= TokenCategoryLearner::MIN_MATCHED_TOKENS
        ));
        $total = count($samples);
        $classCounts = [];
        foreach ($samples as $sample) {
            $direction = $sample['direction'];
            $category = $sample['category_id'];
            $classCounts[$direction][$category] = ($classCounts[$direction][$category] ?? 0) + 1;
        }
        $ready = false;
        foreach ($classCounts as $counts) {
            if (array_sum($counts) >= TokenCategoryLearner::MIN_TOTAL &&
                count(array_filter($counts,
                    static fn(int $count): bool => $count >= TokenCategoryLearner::MIN_CLASS
                )) >= 2) {
                $ready = true;
                break;
            }
        }
        $learner = new TokenCategoryLearner();
        $contacts = $this->availableContacts($company, $permittedContactTypes);
        $suggestions = [];
        $counterparties = [];
        $details=$this->detailsForItems($company,$account,$items);

        foreach ($items as $item) {
            $id = (int)($item['id'] ?? 0);
            $description = $item['description'] ?? null;
            $direction = $item['direction'] ?? null;
            if ($id < 1 || !in_array($direction, ['credit','debit'], true) ||
                ($description !== null && !is_string($description))) {
                continue;
            }
            if (($item['stage_state'] ?? null) === 'pending') {
                $evidence=trim((string)$description.' '.($details[$id]??''));
                // Never override manual classifications or explicit rules.
                if (($item['category_source'] ?? null) === null && !$overLimit) {
                    $suggestion = $learner->suggest($samples, $direction, $evidence);
                    if ($suggestion !== null) {
                        $suggestions[$id] = $suggestion;
                    }
                }
                // A unique full contact name is only a *possible* counterparty,
                // never proof of who paid, of tax status or of invoice settlement.
                $candidate = $this->matchCounterparty($contacts, $direction, $evidence);
                if ($candidate !== null) $counterparties[$id] = $candidate;
            }
        }
        return [
            'total_labels' => $overLimit ? TokenCategoryLearner::MAX_EXAMPLES + 1 : $total,
            'ready' => !$overLimit && $ready,
            'suggestions' => $suggestions,
            'contacts' => $counterparties,
        ];
    }

    /**
     * Extract only approved provider labels. The model never sees raw banking
     * JSON, document identifiers, amounts, account numbers or credentials.
     */
    private static function detailEvidence(mixed $serialized,string $provider): string
    {
        if (!is_string($serialized) || strlen($serialized)>4096) return '';
        $decoded=json_decode($serialized,true);
        if (!is_array($decoded)) return '';
        if (isset($decoded['schema_version'])) {
            if ($decoded['schema_version']!==1 ||
                ($decoded['provider']??null)!==$provider ||
                !is_array($decoded['fields']??null)) return '';
            $decoded=$decoded['fields'];
        }
        $fragments=[];
        foreach (['complement','payer_message','sender_name','recipient_name'] as $key) {
            $value=$decoded[$key]??null;
            if (!is_string($value) || strlen($value)>240 ||
                !preg_match('//u',$value) ||
                preg_match('/[\x00-\x1F\x7F]/',$value)) continue;
            $fragments[]=trim($value);
        }
        return implode(' ',$fragments);
    }

    /** @param array<int,array<string,mixed>> $items @return array<int,string> */
    private function detailsForItems(int $company,int $account,array $items): array
    {
        $ids=array_values(array_unique(array_filter(array_map(
            static fn(array $row)=>(int)($row['id']??0),$items),
            static fn(int $id)=>$id>0
        )));
        if (!$ids) return [];
        $placeholders=implode(',',array_fill(0,count($ids),'?'));
        $query=$this->db->prepare("SELECT id,provider,bank_details FROM {$this->entries}
            WHERE company_id=? AND account_id=? AND id IN ({$placeholders})");
        $query->execute(array_merge([$company,$account],$ids));
        $result=[];
        foreach ($query->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $result[(int)$row['id']]=self::detailEvidence($row['bank_details'],(string)$row['provider']);
        }
        return $result;
    }

    /** @return list<array<string,mixed>> */
    private function labeledExamples(int $company, int $account): array
    {
        // The *last* event may erase a category. Never resuscitate older
        // labels after manual clearing or category deletion.
        $sql = "SELECT e.id,e.provider,e.direction,e.bank_description,e.bank_details,a.new_category_id AS category_id
            FROM {$this->entries} e
            JOIN {$this->events} a ON a.id=(
                SELECT MAX(a2.id) FROM {$this->events} a2
                WHERE a2.entry_id=e.id AND a2.company_id=e.company_id
                  AND a2.account_id=e.account_id
            ) AND a.company_id=e.company_id AND a.account_id=e.account_id
            JOIN {$this->categories} c ON c.id=a.new_category_id
                AND c.company_id=e.company_id AND c.enabled=1 AND c.deleted_at IS NULL
                AND ((e.direction='credit' AND c.type='income')
                  OR (e.direction='debit' AND c.type='expense'))
            WHERE e.company_id=:company AND e.account_id=:account
              AND e.category_source='manual'
              AND e.state IN ('pending','reviewed')
              AND e.bank_description IS NOT NULL AND e.bank_description <> ''
              AND a.new_category_id IS NOT NULL
            ORDER BY a.id DESC LIMIT :limit";
        $stmt=$this->db->prepare($sql);
        $stmt->bindValue(':company',$company,PDO::PARAM_INT);
        $stmt->bindValue(':account',$account,PDO::PARAM_INT);
        $stmt->bindValue(':limit',TokenCategoryLearner::MAX_EXAMPLES+1,PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /** @return list<array{id:int,type:string,name:string,category_id:int|null}> */
    private function availableContacts(int $company, array $permittedTypes): array
    {
        if ($permittedTypes === []) return [];
        $placeholders=implode(',',array_fill(0,count($permittedTypes),'?'));
        $stmt=$this->db->prepare(
            "SELECT id,type,name,category_id FROM {$this->contacts}
             WHERE company_id=? AND type IN ({$placeholders})
                 AND enabled=1 AND deleted_at IS NULL
             ORDER BY id LIMIT 1001"
        );
        $stmt->execute(array_merge([$company], $permittedTypes));
        $rows=$stmt->fetchAll(PDO::FETCH_ASSOC);
        if (count($rows)>1000) return []; // don't produce a partial/unsafe match
        return array_map(static fn(array $r): array => [
            'id'=>(int)$r['id'],
            'type'=>(string)$r['type'],
            'name'=>(string)$r['name'],
            'category_id'=>$r['category_id']===null?null:(int)$r['category_id'],
        ],$rows);
    }

    /**
     * Requires a unique complete name embedded in the transaction description.
     * No fuzzy party identity inference and no CNPJ extraction from free text.
     * @param list<array{id:int,type:string,name:string,category_id:int|null}> $contacts
     * @return array{id:int,type:string,name:string}|null
     */
    private function matchCounterparty(array $contacts, string $direction, ?string $description): ?array
    {
        if ($description === null || $description === '') return null;
        $normalized=$this->nameTokens($description);
        $targetType=$direction==='credit'?'customer':'vendor';
        $matches=[];
        foreach ($contacts as $contact) {
            if ($contact['type']!==$targetType) continue;
            $name=$this->nameTokens($contact['name']);
            if (strlen($name)<10 || count(explode(' ',$name))<2) continue;
            if (str_contains(' '.$normalized.' ',' '.$name.' ')) {
                $matches[]=['id'=>$contact['id'],'type'=>$contact['type'],'name'=>$contact['name']];
                if (count($matches)>1) return null;
            }
        }
        return $matches[0] ?? null;
    }

    private function nameTokens(string $text): string
    {
        if (!preg_match('//u',$text)) return '';
        $clean=CategoryRuleEngine::normalize($text);
        $clean=preg_replace('/[^a-z0-9 ]/',' ',$clean) ?? '';
        return trim(preg_replace('/\s+/',' ',$clean) ?? '');
    }
}
