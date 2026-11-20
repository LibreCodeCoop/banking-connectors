<?php
// SPDX-FileCopyrightText: 2026 LibreCode Coop and contributors
// SPDX-License-Identifier: AGPL-3.0-or-later
declare(strict_types=1);

namespace Modules\BankingConnectors\Connectors\Sicoob;

use DateTimeImmutable;
use DateTimeZone;
use DomainException;
use Modules\BankingConnectors\Domain\BankEntry;

/** Verify Pix payment identity before returning non-financial metadata. */
final class SicoobPixEnrichment
{
    /** @return array<string,string> */
    public static function verify(BankEntry $entry, array $pix): array
    {
        if ($entry->provider!=='sicoob' || $entry->direction!=='credit') {
            throw new DomainException('pix_enrichment_not_supported');
        }
        $expected=$entry->details['pix_end_to_end_id']??null;
        if (!is_string($expected) ||
            !preg_match('/^E[0-9]{8}[A-Za-z0-9]{23}$/D',$expected)) {
            throw new DomainException('pix_end_to_end_id_not_available');
        }
        if (($pix['endToEndId']??null)!==$expected) {
            throw new DomainException('pix_identity_mismatch');
        }
        $amount=$pix['valor']??null;
        if (!is_string($amount) || !preg_match('/^[0-9]{1,14}\.[0-9]{2}$/D',$amount)) {
            throw new DomainException('pix_financial_facts_mismatch');
        }
        [$whole,$fraction]=explode('.',$amount);
        if (((int)$whole*100+(int)$fraction)!==$entry->amountCents) {
            throw new DomainException('pix_financial_facts_mismatch');
        }
        $time=$pix['horario']??null;
        if (!is_string($time) || strlen($time)>40) {
            throw new DomainException('pix_time_missing');
        }
        if (!preg_match('/^[0-9]{4}-[0-9]{2}-[0-9]{2}T[0-9]{2}:[0-9]{2}:[0-9]{2}(?:\.[0-9]{1,9})?(?:Z|[+-][0-9]{2}:[0-9]{2})$/D',$time)) {
            throw new DomainException('pix_time_invalid');
        }
        try {
            $date=new DateTimeImmutable($time);
            $bankDate=new DateTimeImmutable($entry->date,new DateTimeZone('America/Sao_Paulo'));
        } catch (\Throwable) {
            throw new DomainException('pix_time_invalid');
        }
        $date=$date->setTimezone(new DateTimeZone('America/Sao_Paulo'));
        if ($date->format('Y-m-d')<$bankDate->modify('-1 day')->format('Y-m-d') ||
            $date->format('Y-m-d')>$bankDate->modify('+1 day')->format('Y-m-d')) {
            throw new DomainException('pix_date_mismatch');
        }
        $details=SicoobMovementDetails::fromPix($pix);
        $details['pix_end_to_end_id']=$expected;
        return $details;
    }
}
