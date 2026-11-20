<?php
// SPDX-FileCopyrightText: 2026 LibreCode Coop and contributors
// SPDX-License-Identifier: AGPL-3.0-or-later
declare(strict_types=1);

namespace Modules\BankingConnectors\Connectors\Sicoob;

/**
 * Recognizes a Sicoob outgoing Pix CPF presentation with six visible digits.
 * This is an imperfect identity hint, NOT the recipient's Pix key or proof of
 * ownership. The caller must never persist or display unmasked customer IDs.
 */
final class SicoobMaskedPixCpf
{
    private function __construct(private readonly string $middleDigits) {}

    public static function fromStatement(
        string $provider,string $direction,?string $description,?string $complement
    ): ?self {
        if ($provider!=='sicoob' || $direction!=='debit' ||
            !is_string($description) || stripos($description,'PIX')===false ||
            !is_string($complement) || strlen($complement)>240 ||
            !preg_match('//u',$complement) ||
            preg_match('/[\x00-\x1F\x7F]/',$complement) ||
            preg_match('/^PAGAMENTO\s+PIX\b/i',$complement)!==1) return null;

        // The bank displays a masked CPF, not an unmasked Pix key. Multiple
        // masks or unexpected formats make the recipient ambiguous.
        $found=preg_match_all('/(?<![0-9*])\*{3}\.([0-9]{3})\.([0-9]{3})-\*{2}(?=$|[ \t|,;])/',
            $complement,$matches,PREG_SET_ORDER);
        if ($found!==1) return null;
        return new self($matches[0][1].$matches[0][2]);
    }

    public function visibleDigits(): int { return 6; }

    public function visibleMatchPercent(string $document): int
    {
        $digits=preg_replace('/[^0-9]/','',$document);
        if (!is_string($digits) || !self::validCpf($digits)) return 0;
        $middle=substr($digits,3,6);
        $count=0;
        for($i=0;$i<6;$i++) {
            if ($middle[$i]===$this->middleDigits[$i]) ++$count;
        }
        return intdiv($count*100,6);
    }

    public function matches(string $document): bool
    {
        $digits=preg_replace('/[^0-9]/','',$document);
        return is_string($digits) && self::validCpf($digits) &&
            hash_equals($this->middleDigits,substr($digits,3,6));
    }

    private static function validCpf(string $digits): bool
    {
        if (strlen($digits)!==11 || preg_match('/^(\d)\1{10}$/D',$digits)) return false;
        foreach([9,10] as $length) {
            $sum=0;
            for($i=0;$i<$length;$i++)$sum+=(int)$digits[$i]*($length+1-$i);
            $check=(($sum*10)%11)%10;
            if ($check!==(int)$digits[$length]) return false;
        }
        return true;
    }
}
