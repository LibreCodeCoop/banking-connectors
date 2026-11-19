<?php
// SPDX-FileCopyrightText: 2026 LibreCode Coop and contributors
// SPDX-License-Identifier: AGPL-3.0-or-later
declare(strict_types=1);

namespace Modules\BankingConnectors\Domain;

use DateTimeImmutable;
use InvalidArgumentException;

/**
 * Read-only candidate matching. Never performs accounting writes.
 *
 * Existing Akaunting rows must be obtained through the native company-scoped
 * authorization layer; each row is rechecked for company/account isolation.
 * A same-value/date match is always a *suggestion*, not a confirmed match.
 */
final class MatchingEngine
{
    /**
     * @param array<int,array<string,mixed>> $existing
     * @return array{status:string,reason:string,candidate_ids:array<int,int>}
     */
    public function evaluate(BankEntry $bank, array $existing, string $fingerprintKey, int $maxDays = 3): array
    {
        if ($maxDays < 0 || $maxDays > 90) {
            throw new InvalidArgumentException('invalid_date_tolerance');
        }
        $bankFingerprint = $bank->fingerprint($fingerprintKey);
        if ($bankFingerprint === null) {
            return self::decision('needs_review', 'no_stable_bank_id');
        }

        $linked = [];
        $candidates = [];
        foreach ($existing as $row) {
            if (!is_array($row) || !isset($row['id'], $row['company_id'], $row['account_id'])) {
                continue;
            }
            if ((int) $row['company_id'] !== $bank->companyId ||
                (int) $row['account_id'] !== $bank->accountId) {
                continue;
            }
            $id = filter_var($row['id'], FILTER_VALIDATE_INT);
            if ($id === false || $id <= 0) {
                continue;
            }

            // Explicit fingerprint links are looked up before heuristic matching,
            // but must still be consistent with amount, currency and direction.
            $rowFingerprint = $row['banking_connectors_fingerprint'] ?? null;
            if (is_string($rowFingerprint) && hash_equals($bankFingerprint, $rowFingerprint)) {
                $linked[] = $row;
            }
            if (!self::sameFinancialMovement($bank, $row)) {
                continue;
            }
            $rowDate = $row['date'] ?? null;
            if (!is_string($rowDate)) {
                continue;
            }
            $date = DateTimeImmutable::createFromFormat('!Y-m-d', $rowDate);
            if (!$date || $date->format('Y-m-d') !== $rowDate) {
                continue;
            }
            $days = (int) $date->diff(new DateTimeImmutable($bank->date))->format('%a');
            if ($days <= $maxDays) {
                $candidates[] = (int) $id;
            }
        }

        if (count($linked) > 1) {
            return self::decision('needs_review', 'conflicting_existing_links');
        }
        if (count($linked) === 1) {
            $row = $linked[0];
            // A linked transaction whose financial values have been changed is
            // an inconsistency, not something to reclassify automatically.
            if (!self::sameFinancialMovement($bank, $row)) {
                return self::decision('needs_review', 'linked_amount_or_direction_changed', [(int) $row['id']]);
            }
            return self::decision('already_linked', 'explicit_fingerprint', [(int) $row['id']]);
        }

        $candidates = array_values(array_unique($candidates));
        sort($candidates);
        if (count($candidates) === 0) {
            return self::decision('unmatched', 'no_candidate');
        }
        if (count($candidates) > 1) {
            return self::decision('needs_review', 'ambiguous_candidates', $candidates);
        }
        return self::decision('suggested', 'same_amount_direction_account_near_date', $candidates);
    }

    /**
     * Suggests candidates for an entry whose bank ID is already verified in
     * staging, but which is deliberately not exposed back to the application.
     * This method never returns "already_linked": that requires an explicit
     * accepted source-to-native link stored separately.
     *
     * @param array<int,array<string,mixed>> $existing
     * @return array{status:string,reason:string,candidate_ids:array<int,int>}
     */
    public function suggestByFacts(BankEntry $bank, array $existing, int $maxDays = 3): array
    {
        if ($maxDays < 0 || $maxDays > 90) {
            throw new InvalidArgumentException('invalid_date_tolerance');
        }
        $candidateIds = [];
        foreach ($existing as $row) {
            if (!is_array($row) || !isset($row['id'], $row['company_id'], $row['account_id']) ||
                (int) $row['company_id'] !== $bank->companyId ||
                (int) $row['account_id'] !== $bank->accountId ||
                !self::sameFinancialMovement($bank, $row)) {
                continue;
            }
            $id = filter_var($row['id'], FILTER_VALIDATE_INT);
            if ($id === false || (int) $id < 1) {
                continue;
            }
            $dateString = $row['date'] ?? null;
            if (!is_string($dateString)) {
                continue;
            }
            $date = DateTimeImmutable::createFromFormat('!Y-m-d', $dateString);
            if (!$date || $date->format('Y-m-d') !== $dateString) {
                continue;
            }
            if ((int) $date->diff(new DateTimeImmutable($bank->date))->format('%a') <= $maxDays) {
                $candidateIds[] = (int) $id;
            }
        }
        $candidateIds = array_values(array_unique($candidateIds));
        sort($candidateIds);
        return match (count($candidateIds)) {
            0 => self::decision('unmatched', 'no_candidate'),
            1 => self::decision('suggested', 'same_amount_direction_account_near_date', $candidateIds),
            default => self::decision('needs_review', 'ambiguous_candidates', $candidateIds),
        };
    }

    private static function sameFinancialMovement(BankEntry $bank, array $row): bool
    {
        $type = $row['type'] ?? null;
        $direction = match ($type) {
            'income' => 'credit',
            'expense' => 'debit',
            default => null,
        };
        return $direction === $bank->direction
            && ($row['currency'] ?? null) === $bank->currency
            && is_int($row['amount_cents'] ?? null)
            && $row['amount_cents'] === $bank->amountCents;
    }

    /**
     * @param array<int,int> $candidateIds
     * @return array{status:string,reason:string,candidate_ids:array<int,int>}
     */
    private static function decision(string $status, string $reason, array $candidateIds = []): array
    {
        return ['status' => $status, 'reason' => $reason, 'candidate_ids' => $candidateIds];
    }
}
