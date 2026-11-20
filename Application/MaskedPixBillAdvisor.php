<?php
// SPDX-FileCopyrightText: 2026 LibreCode Coop and contributors
// SPDX-License-Identifier: AGPL-3.0-or-later
declare(strict_types=1);

namespace Modules\BankingConnectors\Application;

use DateTimeImmutable;
use InvalidArgumentException;
use Modules\BankingConnectors\Connectors\Sicoob\SicoobMaskedPixCpf;
use PDO;

/**
 * Proposes a fresh, read-only relationship to an already-paid bill/payment.
 * Never changes the bank stage, the native document, or a payment's status.
 * The percentage describes matching visible CPF digits, not confidence.
 */
final class MaskedPixBillAdvisor
{
    private readonly string $documents;
    private readonly string $contacts;
    private readonly string $transactions;
    private readonly string $entries;

    public function __construct(private readonly PDO $db,string $prefix='')
    {
        if (!preg_match('/^[A-Za-z0-9_]{0,40}$/D',$prefix)) {
            throw new InvalidArgumentException('invalid_table_prefix');
        }
        $this->documents=$prefix.'documents';
        $this->contacts=$prefix.'contacts';
        $this->transactions=$prefix.'transactions';
        $this->entries=$prefix.'lcc_banking_connectors_entries';
        $this->db->setAttribute(PDO::ATTR_ERRMODE,PDO::ERRMODE_EXCEPTION);
    }

    /**
     * Caller has checked bank/company ownership and read-purchases-bills plus
     * read-purchases-vendors permissions. Only a single scoped entry is used.
     * @param array<string,mixed> $entry Company/account-scoped preview item.
     * @return array{document_id:int,native_transaction_id:int,number:string,status:string,visible_digits:int,matching_visible_percent:int,compatible_contacts:int}|null
     */
    public function suggest(int $company,int $account,array $entry): ?array
    {
        if ($company<1 || $account<1 || !is_int($entry['id']??null) || $entry['id']<1 ||
            ($entry['stage_state']??null)!=='pending' ||
            ($entry['direction']??null)!=='debit' || ($entry['currency']??null)!=='BRL' ||
            !is_int($entry['amount_cents']??null) || $entry['amount_cents']<1 ||
            !is_array($entry['bank_details']??null)) return null;
        $details=$entry['bank_details'];
        $complement=$details['complement']['value']??null;
        $identity=SicoobMaskedPixCpf::fromStatement(
            (string)($entry['provider']??''),(string)$entry['direction'],
            is_string($entry['description']??null)?$entry['description']:null,
            is_string($complement)?$complement:null
        );
        if ($identity===null) return null;
        // Reassert scope against the immutable bank stage. The caller's
        // displayed entry must be backed by the exact company's bank evidence.
        $sourceQuery=$this->db->prepare("SELECT bank_details FROM {$this->entries}
          WHERE id=? AND company_id=? AND account_id=? AND provider='sicoob'
            AND direction='debit' AND currency='BRL' AND state='pending'
            AND amount_cents=? AND movement_date=? AND bank_description=?");
        $sourceQuery->execute([
            $entry['id'],$company,$account,$entry['amount_cents'],
            $entry['date']??null,$entry['description']??null,
        ]);
        $rawSource=$sourceQuery->fetchColumn();
        if (!is_string($rawSource) || strlen($rawSource)>4096) return null;
        $envelope=json_decode($rawSource,true);
        if (!is_array($envelope)) return null;
        if (isset($envelope['schema_version'])) {
            if ($envelope['schema_version']!==1 || ($envelope['provider']??null)!=='sicoob'
                || !is_array($envelope['fields']??null)) return null;
            $envelope=$envelope['fields'];
        }
        if (!is_string($envelope['complement']??null) ||
            !hash_equals($envelope['complement'],$complement)) return null;

        $date=$entry['date']??null;
        if (!is_string($date)) return null;
        $when=DateTimeImmutable::createFromFormat('!Y-m-d',$date);
        if (!$when || $when->format('Y-m-d')!==$date) return null;
        $major=intdiv($entry['amount_cents'],100);
        if ($major>9999999999999) return null;
        $amount=$major.'.'.str_pad((string)($entry['amount_cents']%100),2,'0',STR_PAD_LEFT);

        // Even though the lookup is already exact-value and narrow-date,
        // refuse a truncated collection rather than guessing uniqueness.
        $sql="SELECT d.id,d.document_number,d.contact_tax_number,
                     c.tax_number AS current_tax_number,t.id AS transaction_id
              FROM {$this->documents} d
              JOIN {$this->contacts} c ON c.id=d.contact_id
                AND c.company_id=d.company_id AND c.type='vendor'
                AND c.enabled=1 AND c.deleted_at IS NULL
              JOIN {$this->transactions} t ON t.document_id=d.id
                AND t.company_id=d.company_id AND t.type='expense'
                AND t.deleted_at IS NULL AND t.account_id=?
                AND t.currency_code='BRL' AND t.amount=?
                AND t.paid_at>=? AND t.paid_at<?
              WHERE d.company_id=? AND d.type='bill' AND d.status='paid'
                AND d.deleted_at IS NULL AND d.currency_code='BRL'
                AND d.amount=?
              ORDER BY d.id,t.id LIMIT 51";
        $statement=$this->db->prepare($sql);
        $statement->execute([
            $account,$amount,
            $when->modify('-14 days')->format('Y-m-d').' 00:00:00',
            $when->modify('+15 days')->format('Y-m-d').' 00:00:00',
            $company,$amount,
        ]);
        $found=$statement->fetchAll(PDO::FETCH_ASSOC);
        if (count($found)>50) return null;
        $matches=[];
        foreach($found as $row) {
            $snapshot=(string)($row['contact_tax_number']??'');
            $current=(string)($row['current_tax_number']??'');
            // Both identity sources must independently agree with the mask;
            // otherwise the document's historic contact may have changed.
            if (!$identity->matches($snapshot) || !$identity->matches($current)) continue;
            if (preg_replace('/\D/','',$snapshot)!==preg_replace('/\D/','',$current)) continue;
            $matches[]=$row;
        }
        if (count($matches)!==1) return null;
        $match=$matches[0];

        $linked=$this->db->prepare("SELECT id FROM {$this->entries}
            WHERE company_id=? AND account_id=? AND linked_transaction_id=?
              AND id<>? LIMIT 1");
        $linked->execute([$company,$account,(int)$match['transaction_id'],$entry['id']]);
        if ($linked->fetchColumn()!==false) return null;

        $contacts=$this->db->prepare("SELECT tax_number FROM {$this->contacts}
            WHERE company_id=? AND type='vendor' AND enabled=1
              AND deleted_at IS NULL ORDER BY id LIMIT 2001");
        $contacts->execute([$company]);
        $all=$contacts->fetchAll(PDO::FETCH_COLUMN);
        if (count($all)>2000) return null;
        $compatible=0;
        foreach($all as $tax) if (is_string($tax) && $identity->matches($tax)) ++$compatible;
        if ($compatible<1) return null;

        return [
            'document_id'=>(int)$match['id'],
            'native_transaction_id'=>(int)$match['transaction_id'],
            'number'=>(string)$match['document_number'],
            'status'=>'provisional',
            'visible_digits'=>$identity->visibleDigits(),
            'matching_visible_percent'=>$identity->visibleMatchPercent((string)$match['contact_tax_number']),
            'compatible_contacts'=>$compatible,
        ];
    }
}
