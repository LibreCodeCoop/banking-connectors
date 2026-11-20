<?php
// SPDX-FileCopyrightText: 2026 LibreCode Coop and contributors
// SPDX-License-Identifier: AGPL-3.0-or-later
declare(strict_types=1);

namespace Modules\BankingConnectors\Domain;

/**
 * Bounded multinomial Naive Bayes trained exclusively on explicitly
 * confirmed per-company/per-account category events.
 *
 * This small, auditable PHP baseline intentionally avoids loading opaque,
 * serialized third-party model objects into a financial application. Scores
 * are model strengths, NOT calibrated statistical probabilities.
 */
final class TokenCategoryLearner
{
    public const MIN_TOTAL = 12;
    public const MIN_CLASS = 4;
    public const MIN_MATCHED_TOKENS = 2;
    public const MIN_MODEL_SCORE = 0.92;
    public const MIN_MARGIN = 0.45;
    public const MAX_EXAMPLES = 500;

    /** @var array<string,true> */
    private const STOP_WORDS = [
        'pix'=>true,'ted'=>true,'doc'=>true,'de'=>true,'da'=>true,'das'=>true,
        'do'=>true,'dos'=>true,'para'=>true,'por'=>true,'com'=>true,'sem'=>true,
        'em'=>true,'no'=>true,'na'=>true,'um'=>true,'uma'=>true,'ao'=>true,
        'recebido'=>true,'recebida'=>true,'enviado'=>true,'enviada'=>true,
        'pagamento'=>true,'pagamentos'=>true,'recebimento'=>true,
        'transferencia'=>true,'transacao'=>true,'movimentacao'=>true,
        'bancaria'=>true,'conta'=>true,'corrente'=>true,'cliente'=>true,
        'banco'=>true,'instituicao'=>true,'financeira'=>true,'valor'=>true,
        'ltda'=>true,'limitada'=>true,'sa'=>true,'me'=>true,'eireli'=>true,
        'empresa'=>true,'cpf'=>true,'cnpj'=>true,'ref'=>true,'referencia'=>true,
    ];

    /** @return list<string> Unique meaningful terms only; never an identifier. */
    public static function tokens(?string $text): array
    {
        if ($text === null || $text === '' || !preg_match('//u', $text)) {
            return [];
        }
        $normalized = CategoryRuleEngine::normalize($text);
        $normalized = preg_replace('/[^a-z]+/u', ' ', $normalized) ?? '';
        $parts = preg_split('/\s+/u', trim($normalized)) ?: [];
        $unique = [];
        foreach ($parts as $token) {
            if (strlen($token) < 3 || strlen($token) > 32 ||
                isset(self::STOP_WORDS[$token])) {
                continue;
            }
            $unique[$token] = true;
            if (count($unique) >= 24) break;
        }
        return array_keys($unique);
    }

    /**
     * @param list<array{direction:string,category_id:int,description:string}> $examples
     * @return array{category_id:int,model_score:float,examples:int,class_examples:int,shared_terms:int}|null
     */
    public function suggest(array $examples, string $direction, ?string $description): ?array
    {
        if (!in_array($direction, ['credit','debit'], true)) return null;
        $inputTokens = self::tokens($description);
        if (count($inputTokens) < self::MIN_MATCHED_TOKENS) return null;

        $counts = [];
        $terms = [];
        $vocabulary = [];
        $total = 0;
        foreach ($examples as $example) {
            if (($example['direction'] ?? null) !== $direction ||
                !is_int($example['category_id']) || $example['category_id'] < 1) {
                continue;
            }
            $features = self::tokens($example['description'] ?? null);
            if (count($features) < self::MIN_MATCHED_TOKENS) continue;
            $category = $example['category_id'];
            $counts[$category] = ($counts[$category] ?? 0) + 1;
            foreach ($features as $word) {
                $terms[$category][$word] = ($terms[$category][$word] ?? 0) + 1;
                $vocabulary[$word] = true;
            }
            $total++;
            if ($total > self::MAX_EXAMPLES) return null; // fail closed
        }
        if ($total < self::MIN_TOTAL || count($counts) < 2) return null;
        $shared = array_values(array_filter($inputTokens, static fn($word) => isset($vocabulary[$word])));
        if (count($shared) < self::MIN_MATCHED_TOKENS) return null;
        $vocabSize = count($vocabulary);
        $scores = [];
        foreach ($counts as $category => $count) {
            if ($count < self::MIN_CLASS) continue;
            $featuresTotal = array_sum($terms[$category] ?? []);
            $log = log($count / $total);
            foreach ($shared as $word) {
                $log += log((($terms[$category][$word] ?? 0) + 1) / ($featuresTotal + $vocabSize));
            }
            $scores[(int)$category] = $log;
        }
        if (count($scores) < 2) return null;
        arsort($scores, SORT_NUMERIC);
        $top = (int)array_key_first($scores);
        $maximum = reset($scores);
        $expSum = 0.0;
        $proportions = [];
        foreach ($scores as $category => $score) {
            $proportions[$category] = exp($score - $maximum);
            $expSum += $proportions[$category];
        }
        $strength = $proportions[$top] / $expSum;
        $otherStrength = max(array_filter(
            array_map(static fn($cat, $value) => $cat === $top ? 0.0 : $value / $expSum,
                array_keys($proportions), array_values($proportions)),
            static fn($v) => $v > 0.0
        ) ?: [0.0]);

        if ($strength < self::MIN_MODEL_SCORE ||
            $strength - $otherStrength < self::MIN_MARGIN) {
            return null;
        }

        return [
            'category_id'=>$top,
            'model_score'=>round($strength, 3),
            'examples'=>$total,
            'class_examples'=>$counts[$top],
            'shared_terms'=>count($shared),
        ];
    }
}
