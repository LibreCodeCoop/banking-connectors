<?php
// SPDX-FileCopyrightText: 2026 LibreCode Coop and contributors
// SPDX-License-Identifier: AGPL-3.0-or-later
declare(strict_types=1);

require dirname(__DIR__).'/Domain/BankEntry.php';
require dirname(__DIR__).'/Domain/MatchingEngine.php';
require dirname(__DIR__).'/Domain/CategoryRuleEngine.php';
require dirname(__DIR__).'/Domain/NativePostingDecision.php';

use Modules\BankingConnectors\Domain\NativePostingDecision;

$plan=new NativePostingDecision();
$base=['state'=>'pending','company_id'=>1,'account_id'=>7,
    'provider'=>'sicoob','movement_date'=>'2026-11-17','direction'=>'credit',
    'amount_cents'=>10000,'currency'=>'BRL','source_fingerprint'=>str_repeat('a',64),
    'content_fingerprint'=>str_repeat('b',64),'bank_description'=>'PIX RECEBIDO CLIENTE'];
$empty=['rows'=>[],'truncated'=>false,'invalid'=>false];
$expected=$plan->plan($base,$empty,null);
if ($expected['action']!=='create'||$expected['reason']!=='native_uncategorized_placeholder') {
    throw new RuntimeException('Unmatched confirmed bank movement did not offer native posting');
}
$typed=$plan->plan($base,$empty,11);
if ($typed['action']!=='create'||$typed['category_id']!==11) {
    throw new RuntimeException('Explicit approved category missing');
}
$near=[...$empty,'rows'=>[[
    'id'=>45,'company_id'=>1,'account_id'=>7,'type'=>'income',
    'date'=>'2026-11-16','currency'=>'BRL','amount_cents'=>10000,
]]];
if ($plan->plan($base,$near,null)['reason']!=='existing_native_candidate') {
    throw new RuntimeException('Bank import may duplicate a preexisting invoice payment');
}
$near['rows'][]=[...$near['rows'][0],'id'=>46];
if ($plan->plan($base,$near,null)['reason']!=='ambiguous_existing_candidates') {
    throw new RuntimeException('Ambiguous native receipts must be held');
}
if ($plan->plan($base,$empty,null,true)['reason']!=='possible_outstanding_document') {
    throw new RuntimeException('Unpaid invoice without native payment must prevent duplicate revenue');
}
if ($plan->plan([...$base,'amount_cents'=>999999999999999],$empty,null)['reason']!=='amount_out_of_native_range') {
    throw new RuntimeException('Native amount precision boundary not enforced');
}
foreach (['truncated','invalid'] as $flag) {
    if ($plan->plan($base,[...$empty,$flag=>true],null)['action']!=='hold') {
        throw new RuntimeException('Incomplete native dataset must hold');
    }
}
foreach (['reviewed','dismissed','conflict'] as $state) {
    if ($plan->plan([...$base,'state'=>$state],$empty,null)['action']!=='skip') {
        throw new RuntimeException('Review state was not respected');
    }
}
foreach ([
    'TRANSFERENCIA ENTRE CONTAS PROPRIAS','RESGATE DE APLICACAO FINANCEIRA',
    'APLICAÇÃO FINANCEIRA',
] as $desc) {
    if ($plan->plan([...$base,'direction'=>'debit',
        'bank_description'=>$desc],$empty,null)['reason']!=='possible_internal_transfer') {
        throw new RuntimeException('Bank transfer should not be counted as operational expense');
    }
}
if ($plan->plan([...$base,'source_fingerprint'=>''],$empty,null)['action']!=='hold') {
    throw new RuntimeException('Unverified bank identity must not post');
}
echo "PASS native posting policy: unmatched, provisional category, duplicate, ambiguous, transfers, untrusted source\n";
