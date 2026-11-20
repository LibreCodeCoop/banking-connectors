<?php
// SPDX-FileCopyrightText: 2026 LibreCode Coop and contributors
// SPDX-License-Identifier: AGPL-3.0-or-later
declare(strict_types=1);

namespace Modules\BankingConnectors\Application;

use InvalidArgumentException;
use Modules\BankingConnectors\Domain\BankEntry;
use Modules\BankingConnectors\Domain\CategoryRuleEngine;
use Modules\BankingConnectors\Domain\MatchingEngine;
use Modules\BankingConnectors\Infrastructure\NativeTransactionReader;
use Modules\BankingConnectors\Infrastructure\PdoEntryStage;

/**
 * Financial facts and human-reviewed classifications, never a payment confirmation.
 */
final class ReviewPreview
{
    public function __construct(
        private readonly PdoEntryStage $stage,
        private readonly NativeTransactionReader $native,
        private readonly MatchingEngine $matching,
    ) {}

    /**
     * @param array<int,array<string,mixed>> $rules
     * @return array{items:array<int,array<string,mixed>>,summary:array<string,int>}
     */
    public function forConnection(
        int $companyId,
        int $accountId,
        int $connectionId,
        int $limit = 50,
        string $filter = 'pending',
        int $searchDays = 3,
        array $rules = [],
        ?int $entryId = null,
    ): array {
        if ($companyId < 1 || $accountId < 1 || $connectionId < 1 ||
            $limit < 1 || $limit > 100 ||
            !in_array($filter,['pending','reviewed','dismissed','all'],true) ||
            !in_array($searchDays,[3,14,45,90],true)) {
            throw new InvalidArgumentException('invalid_review_scope');
        }
        // Preserve legacy API semantics: the default still lists unresolved items.
        $records = $entryId !== null
            ? $this->stage->findForReview($companyId,$accountId,$connectionId,$entryId)
            : ($filter === 'pending'
            ? $this->stage->listUnresolved($companyId,$accountId,$connectionId,$limit)
            : $this->stage->listForReview($companyId,$accountId,$connectionId,$filter,$limit));
        $summary = ['suggested'=>0,'needs_review'=>0,'unmatched'=>0];
        $items = [];
        $engine = new CategoryRuleEngine();
        foreach ($records as $record) {
            $movement = new BankEntry(
                companyId: $companyId,
                accountId: $accountId,
                provider: (string)$record['provider'],
                externalId: null,
                date: (string)$record['movement_date'],
                direction: (string)$record['direction'],
                amountCents: (int)$record['amount_cents'],
                currency: (string)$record['currency'],
                description: $record['bank_description'] ?? null,
            );
            $nearRows = [];
            $candidateDetails = [];
            if ($record['state'] === 'reviewed') {
                $decision = [
                    'status'=>'reviewed',
                    'reason'=>'manual_link_confirmed',
                    'candidate_ids'=>[],
                ];
            } elseif ($record['state'] === 'dismissed') {
                $decision = ['status'=>'dismissed','reason'=>'manual_dismissal','candidate_ids'=>[]];
            } elseif ($record['state'] === 'conflict') {
                $decision = ['status'=>'needs_review','reason'=>'source_revision_conflict','candidate_ids'=>[]];
            } else {
                $near=$this->native->near($companyId,$accountId,$movement->date,$searchDays);
                if ($near['truncated']) {
                    $decision=['status'=>'needs_review','reason'=>'native_candidates_truncated','candidate_ids'=>[]];
                } elseif ($near['invalid']) {
                    $decision=['status'=>'needs_review','reason'=>'invalid_native_record','candidate_ids'=>[]];
                } else {
                    $nearRows=$near['rows'];
                    $decision=$this->matching->suggestByFacts($movement,$nearRows,$searchDays);
                }
                $ids=$decision['candidate_ids'];
                foreach ($nearRows as $native) {
                    if (!in_array((int)$native['id'],$ids,true)) continue;
                    $candidateDetails[]=[
                        'id'=>(int)$native['id'],
                        'date'=>(string)$native['date'],
                        'type'=>(string)$native['type'],
                        'amount_cents'=>(int)$native['amount_cents'],
                        'currency'=>(string)$native['currency'],
                    ];
                }
            }
            if (array_key_exists($decision['status'],$summary)) {
                ++$summary[$decision['status']];
            }

            // Explicit manual classification persists on its own staging row;
            // rule-sourced suggestions are recomputed, so disabling a rule
            // does not continue proposing its old category.
            $storedSource=$record['category_source'] ?? null;
            $ruleCategory=$engine->suggest($movement->description,$movement->direction,$rules);
            $category=$storedSource==='manual'
                ? (isset($record['category_id']) ? (int)$record['category_id'] : null)
                : ($ruleCategory['category_id'] ?? null);

            $items[]=[
                'id'=>(int)$record['id'],
                'provider'=>$record['provider'],
                'date'=>$movement->date,
                'direction'=>$movement->direction,
                'amount_cents'=>$movement->amountCents,
                'currency'=>$movement->currency,
                'description'=>$movement->description,
                'stage_state'=>$record['state'],
                'linked_transaction_id'=>isset($record['linked_transaction_id'])
                    ? (int)$record['linked_transaction_id'] : null,
                'status'=>$decision['status'],
                'reason'=>$decision['reason'],
                'candidate_ids'=>$decision['candidate_ids'],
                'candidate_details'=>$candidateDetails,
                'category_id'=>$category,
                'category_source'=>$storedSource==='manual'?'manual':
                    ($ruleCategory!==null?'rule':null),
                'category_rule_id'=>$storedSource==='manual'?null:($ruleCategory['rule_id']??null),
                'first_seen_at'=>$record['first_seen_at'],
                'last_seen_at'=>$record['last_seen_at'],
            ];
        }
        return ['items'=>$items,'summary'=>$summary];
    }
}
