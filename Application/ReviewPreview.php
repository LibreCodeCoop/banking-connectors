<?php
// SPDX-FileCopyrightText: 2026 LibreCode Coop and contributors
// SPDX-License-Identifier: AGPL-3.0-or-later
declare(strict_types=1);

namespace Modules\BankingConnectors\Application;

use InvalidArgumentException;
use Modules\BankingConnectors\Domain\BankEntry;
use Modules\BankingConnectors\Domain\MatchingEngine;
use Modules\BankingConnectors\Infrastructure\NativeTransactionReader;
use Modules\BankingConnectors\Infrastructure\PdoEntryStage;

/**
 * Produces a read-only, unambiguous review snapshot. Never imports,
 * confirms payments or changes Akaunting's ledger.
 */
final class ReviewPreview
{
    public function __construct(
        private readonly PdoEntryStage $stage,
        private readonly NativeTransactionReader $native,
        private readonly MatchingEngine $matching,
    ) {}

    /**
     * @return array{items:array<int,array<string,mixed>>,summary:array<string,int>}
     */
    public function forConnection(
        int $companyId,
        int $accountId,
        int $connectionId,
        int $limit = 50,
    ): array {
        if ($companyId < 1 || $accountId < 1 || $connectionId < 1 || $limit < 1 || $limit > 100) {
            throw new InvalidArgumentException('invalid_review_scope');
        }
        $records = $this->stage->listUnresolved($companyId, $accountId, $connectionId, $limit);
        $summary = ['suggested' => 0, 'needs_review' => 0, 'unmatched' => 0];
        $items = [];
        foreach ($records as $record) {
            $movement = new BankEntry(
                companyId: $companyId,
                accountId: $accountId,
                provider: (string) $record['provider'],
                externalId: null,
                date: (string) $record['movement_date'],
                direction: (string) $record['direction'],
                amountCents: (int) $record['amount_cents'],
                currency: (string) $record['currency'],
            );
            if ($record['state'] === 'conflict') {
                $decision = ['status' => 'needs_review', 'reason' => 'source_revision_conflict', 'candidate_ids' => []];
            } else {
                $near = $this->native->near($companyId, $accountId, $movement->date);
                if ($near['truncated']) {
                    $decision = ['status' => 'needs_review', 'reason' => 'native_candidates_truncated', 'candidate_ids' => []];
                } elseif ($near['invalid']) {
                    $decision = ['status' => 'needs_review', 'reason' => 'invalid_native_record', 'candidate_ids' => []];
                } else {
                    $decision = $this->matching->suggestByFacts($movement, $near['rows']);
                }
            }
            ++$summary[$decision['status']];
            $items[] = [
                'id' => (int) $record['id'],
                'provider' => $record['provider'],
                'date' => $movement->date,
                'direction' => $movement->direction,
                'amount_cents' => $movement->amountCents,
                'currency' => $movement->currency,
                'stage_state' => $record['state'],
                'status' => $decision['status'],
                'reason' => $decision['reason'],
                'candidate_ids' => $decision['candidate_ids'],
                'first_seen_at' => $record['first_seen_at'],
                'last_seen_at' => $record['last_seen_at'],
            ];
        }
        return ['items' => $items, 'summary' => $summary];
    }
}
