<?php
// SPDX-FileCopyrightText: 2026 LibreCode Coop and contributors
// SPDX-License-Identifier: AGPL-3.0-or-later
declare(strict_types=1);
require dirname(__DIR__).'/Http/ReturnLocation.php';
use Modules\BankingConnectors\Http\ReturnLocation;
$prefix='/2/banking/transactions';
$valid=[
 $prefix, $prefix.'?list_records=all&page=2&search=type%3Aincome',
 $prefix.'?search=bank_movement%3Aunlinked&sort=paid_at&direction=asc',
];
foreach($valid as $uri) {
 if(ReturnLocation::validate($uri,$prefix)!==$uri) throw new RuntimeException('valid return rejected');
}
foreach([
 'https://evil.test/2/banking/transactions','//evil.test/2/banking/transactions',
 '/3/banking/transactions','/2/banking/transactions/1',
 '/2/banking/transactions#javascript:alert(1)',
 '/2/banking/transactions?query=%0Ainvalid'.str_repeat('0',2048),
 "/2/banking/transactions\r\nHost:evil", "/2/banking/transactions\\foo",
] as $uri) {
 if(ReturnLocation::validate($uri,$prefix)!==null) throw new RuntimeException('unsafe return accepted');
}
echo "PASS trusted same-company list return path and query round-trip; cross-company and external redirects denied\n";
