<?php
// SPDX-FileCopyrightText: 2026 LibreCode Coop and contributors
// SPDX-License-Identifier: AGPL-3.0-or-later
declare(strict_types=1);

namespace Modules\BankingConnectors\Domain;

use InvalidArgumentException;

/** Deterministic, bounded description rules. Never changes the financial ledger. */
final class CategoryRuleEngine
{
    public static function normalize(string $input): string
    {
        $ascii = strtr($input, [
            'á'=>'a','à'=>'a','â'=>'a','ã'=>'a','ä'=>'a',
            'é'=>'e','è'=>'e','ê'=>'e','ë'=>'e',
            'í'=>'i','ì'=>'i','î'=>'i','ï'=>'i',
            'ó'=>'o','ò'=>'o','ô'=>'o','õ'=>'o','ö'=>'o',
            'ú'=>'u','ù'=>'u','û'=>'u','ü'=>'u',
            'ç'=>'c','ñ'=>'n',
            'Á'=>'a','À'=>'a','Â'=>'a','Ã'=>'a','Ä'=>'a',
            'É'=>'e','È'=>'e','Ê'=>'e','Ë'=>'e',
            'Í'=>'i','Ì'=>'i','Î'=>'i','Ï'=>'i',
            'Ó'=>'o','Ò'=>'o','Ô'=>'o','Õ'=>'o','Ö'=>'o',
            'Ú'=>'u','Ù'=>'u','Û'=>'u','Ü'=>'u',
            'Ç'=>'c','Ñ'=>'n',
        ]);
        $ascii = strtolower($ascii);
        return trim(preg_replace('/\s+/u', ' ', $ascii) ?? '');
    }

    public static function needle(string $value): string
    {
        if (!preg_match('//u', $value) ||
            preg_match('/[\x00-\x1F\x7F]/', $value) ||
            strlen($value) > 120) {
            throw new InvalidArgumentException('invalid_category_rule_text');
        }
        $needle = self::normalize($value);
        if (strlen($needle) < 4 || strlen($needle) > 120) {
            throw new InvalidArgumentException('invalid_category_rule_text');
        }
        return $needle;
    }

    /**
     * @param array<int,array<string,mixed>> $rules Preordered by priority ASC, id ASC.
     * @return array{category_id:int,rule_id:int}|null
     */
    public function suggest(?string $description, string $direction, array $rules): ?array
    {
        if ($description === null || trim($description) === '' ||
            !in_array($direction, ['credit', 'debit'], true)) {
            return null;
        }
        $subject = self::normalize($description);
        $matched = null;
        $priority = null;
        foreach ($rules as $rule) {
            if (($rule['direction'] ?? null) !== $direction ||
                !($rule['enabled'] ?? false) ||
                !isset($rule['id'], $rule['category_id'], $rule['description_contains'])) {
                continue;
            }
            $needle = self::normalize((string) $rule['description_contains']);
            if ($needle === '' || !str_contains($subject, $needle)) {
                continue;
            }
            $rulePriority = (int) ($rule['priority'] ?? 100);
            if ($matched !== null && $rulePriority > $priority) {
                break;
            }
            if ($matched !== null && (int) $rule['category_id'] !== $matched['category_id']) {
                // Same-priority competing rules produce no suggestion.
                return null;
            }
            if ($matched === null) {
                $matched = [
                    'rule_id' => (int) $rule['id'],
                    'category_id' => (int) $rule['category_id'],
                ];
                $priority = $rulePriority;
            }
        }
        return $matched;
    }
}
