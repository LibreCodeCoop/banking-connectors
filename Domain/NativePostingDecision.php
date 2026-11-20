<?php
// SPDX-FileCopyrightText: 2026 LibreCode Coop and contributors
// SPDX-License-Identifier: AGPL-3.0-or-later
declare(strict_types=1);

namespace Modules\BankingConnectors\Domain;

use InvalidArgumentException;

/**
 * Pure, fail-closed bank posting policy. No external source identity, human
 * confirmation or tax status can be inferred from free-form narration.
 */
final class NativePostingDecision
{
    /**
     * @param array<string,mixed> $entry Company/account-bound, settled bank row.
     * @param array{truncated:bool,invalid:bool,rows:array<int,array<string,mixed>>} $near
     * @return array{action:string,reason:string,category_id:int|null}
     */
    public function plan(array $entry, array $near, ?int $categoryId, bool $possibleOutstandingDocument=false): array
    {
        $state=$entry['state']??'';
        if (!in_array($state,['pending','reviewed','conflict','dismissed'],true)) {
            throw new InvalidArgumentException('invalid_bank_stage_state');
        }
        if ($state!=='pending' || !empty($entry['linked_transaction_id'])) {
            return self::decision('skip','previously_processed');
        }
        if (empty($entry['source_fingerprint']) || empty($entry['content_fingerprint'])) {
            return self::decision('hold','unverified_source_identity');
        }
        if (!in_array(($entry['direction']??null),['credit','debit'],true) ||
            !is_numeric($entry['amount_cents']??null) || (int)$entry['amount_cents']<=0 ||
            !preg_match('/^[A-Z]{3}$/',(string)($entry['currency']??''))) {
            return self::decision('hold','invalid_bank_financial_facts');
        }
        // The native Akaunting amount column cannot represent arbitrary
        // 64-bit bank cents. Refuse a float/DB precision loss.
        if ((int)$entry['amount_cents']>9999999999999) {
            return self::decision('hold','amount_out_of_native_range');
        }
        if ($near['truncated'] || $near['invalid']) {
            return self::decision('hold','native_candidates_incomplete');
        }
        $movement=new BankEntry(
            (int)$entry['company_id'],(int)$entry['account_id'],
            (string)$entry['provider'],null,(string)$entry['movement_date'],
            (string)$entry['direction'],(int)$entry['amount_cents'],
            (string)$entry['currency'],
        );
        $matching=new MatchingEngine();
        // Explicitly tolerate 3 days of settlement delay when looking for
        // another financial fact. When a candidate exists, stop; the user
        // must verify it rather than risking duplicate or false auto-match.
        $candidate=$matching->suggestByFacts($movement,$near['rows'],45);
        if ($candidate['status']==='suggested') {
            return self::decision('hold','existing_native_candidate');
        }
        if ($candidate['status']==='needs_review') {
            return self::decision('hold','ambiguous_existing_candidates');
        }
        if ($possibleOutstandingDocument) {
            return self::decision('hold','possible_outstanding_document');
        }
        $desc=CategoryRuleEngine::normalize((string)($entry['bank_description']??''));
        // A transfer between the organization's own accounts MUST use the
        // native two-legged transfer model. Never book as income/expense.
        if (preg_match('/\b(transferencia entre contas|entre contas|aplicacao financeira|resgate de aplicacao|transferencia interna)\b/u',$desc)) {
            return self::decision('hold','possible_internal_transfer');
        }
        return ['action'=>'create','reason'=>$categoryId===null?
            'native_uncategorized_placeholder':'verified_unmatched_bank_movement',
            'category_id'=>$categoryId];
    }

    private static function decision(string $action,string $reason): array
    {
        return ['action'=>$action,'reason'=>$reason,'category_id'=>null];
    }
}
