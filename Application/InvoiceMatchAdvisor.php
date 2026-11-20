<?php
// SPDX-FileCopyrightText: 2026 LibreCode Coop and contributors
// SPDX-License-Identifier: AGPL-3.0-or-later
declare(strict_types=1);

namespace Modules\BankingConnectors\Application;

use DateTimeImmutable;
use InvalidArgumentException;
use Modules\BankingConnectors\Domain\CategoryRuleEngine;
use PDO;

/**
 * Read-only candidate search. Neither a similar amount nor a payer name is
 * proof of invoice settlement. No payment or account record is changed.
 */
final class InvoiceMatchAdvisor
{
    private readonly string $documents;

    public function __construct(private readonly PDO $db, string $prefix = '')
    {
        if (!preg_match('/^[A-Za-z0-9_]{0,40}$/D', $prefix)) {
            throw new InvalidArgumentException('invalid_table_prefix');
        }
        $this->documents=$prefix.'documents';
        $this->db->setAttribute(PDO::ATTR_ERRMODE,PDO::ERRMODE_EXCEPTION);
    }

    /**
     * @param array<string,mixed> $movement Company-scoped verified stage movement.
     * @return list<array{id:int,number:string,category_id:int|null,contact_name:string,reason:string}>
     */
    public function suggest(int $company, array $movement): array
    {
        if ($company<1 || ($movement['direction']??null)!=='credit' ||
            !is_int($movement['amount_cents']??null) ||
            $movement['amount_cents']<=0 ||
            ($movement['currency']??null)!=='BRL') return [];
        $date=$movement['date']??null;
        if (!is_string($date)) return [];
        $when=DateTimeImmutable::createFromFormat('!Y-m-d',$date);
        if (!$when || $when->format('Y-m-d')!==$date) return [];
        $fields=$movement['bank_details']??[];
        if (!is_array($fields)) return [];
        $payer=self::field($fields,'sender_name');
        $message=self::field($fields,'payer_message');
        $complement=self::field($fields,'complement');
        $numerator=intdiv($movement['amount_cents'],100);
        $minor=$movement['amount_cents']%100;
        if ($numerator>9999999999999) return [];
        $amount=$numerator.'.'.str_pad((string)$minor,2,'0',STR_PAD_LEFT);
        $query=$this->db->prepare(
            "SELECT id,document_number,contact_name,category_id
             FROM {$this->documents}
             WHERE company_id=:company AND type='invoice'
               AND status='sent' AND deleted_at IS NULL
               AND currency_code='BRL' AND amount=:amount
               AND issued_at >= :from_date AND issued_at < :to_date
             ORDER BY issued_at DESC,id DESC LIMIT 51"
        );
        $query->execute([
            ':company'=>$company,':amount'=>$amount,
            ':from_date'=>$when->modify('-365 days')->format('Y-m-d').' 00:00:00',
            ':to_date'=>$when->modify('+1 day')->format('Y-m-d').' 00:00:00',
        ]);
        $rows=$query->fetchAll(PDO::FETCH_ASSOC);
        // A truncated candidate set cannot justify even a weak suggestion.
        if (count($rows)>50) return [];
        $candidate=[];
        foreach ($rows as $row) {
            if (!is_numeric($row['id']) || (int)$row['id']<1) continue;
            $client=(string)($row['contact_name']??'');
            $document=(string)($row['document_number']??'');
            $reason='amount_only';
            if ($payer!==null && self::exactName($payer,$client)) {
                $reason='payer_and_amount';
            } elseif (($message!==null && self::containsDocument($message,$document)) ||
                ($complement!==null && self::containsDocument($complement,$document))) {
                $reason='invoice_number_and_amount';
            }
            $candidate[]=[
                'id'=>(int)$row['id'],
                'number'=>$document,
                'category_id'=>$row['category_id']!==null?(int)$row['category_id']:null,
                'contact_name'=>$client,
                'reason'=>$reason,
            ];
        }
        $strong=array_values(array_filter($candidate,static fn($row)=>$row['reason']!=='amount_only'));
        if ($strong!==[]) return count($strong)<=5?$strong:[];
        // Value alone is weak; do not flood the reviewer with coincidences.
        return count($candidate)<=3?$candidate:[];
    }

    /** @param array<string,mixed> $details */
    private static function field(array $details,string $key): ?string
    {
        $value=$details[$key]['value']??null;
        return is_string($value) && strlen($value)<=240 &&
            trim($value)!=='' && preg_match('//u',$value) ? trim($value) : null;
    }

    private static function exactName(string $payer,string $contact): bool
    {
        $a=CategoryRuleEngine::normalize($payer);
        $b=CategoryRuleEngine::normalize($contact);
        return strlen($a)>=10 && count(explode(' ',$a))>=2 &&
            $a!=='' && hash_equals($a,$b);
    }

    private static function containsDocument(string $message,string $document): bool
    {
        // Exact, non-substring ID. The message is not a trusted identity.
        if (strlen($document)<5 || strlen($document)>60 ||
            !preg_match('/^[A-Za-z0-9._\\/-]+$/D',$document)) return false;
        return preg_match('/(?<![A-Za-z0-9._\\/-])'.preg_quote($document,'/').'(?![A-Za-z0-9._\\/-])/i',$message)===1;
    }
}
