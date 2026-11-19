<?php
// SPDX-FileCopyrightText: 2026 LibreCode Coop and contributors
// SPDX-License-Identifier: AGPL-3.0-or-later
declare(strict_types=1);

require dirname(__DIR__) . '/Connectors/Sicoob/SicoobHttpClient.php';

use Modules\BankingConnectors\Connectors\Sicoob\SicoobHttpClient;

function checkHttp(bool $condition, string $label): void
{
    if (!$condition) {
        throw new RuntimeException('FAIL ' . $label);
    }
    echo 'PASS ' . $label . PHP_EOL;
}

try {
    new SicoobHttpClient(['MODE' => 'live']);
    throw new RuntimeException('FAIL unsupported client mode accepted');
} catch (InvalidArgumentException $e) {
    checkHttp($e->getMessage() === 'MODE must be sandbox or production',
        'unsupported bank mode rejected');
}

$config = [
    'MODE' => 'production',
    'ACCOUNT_NUMBER' => '1234567',
    'CLIENT_ID' => 'synthetic',
    'AUTH_URL' => 'https://malicious.invalid/token',
    'API_BASE_URL' => 'https://api.sicoob.com.br/conta-corrente/v4',
];
$client = new SicoobHttpClient($config);
try {
    $client->statement(2026, 11, 31, 31);
    throw new RuntimeException('FAIL invalid bank date accepted');
} catch (InvalidArgumentException $e) {
    checkHttp(str_starts_with($e->getMessage(), 'Invalid calendar date range'),
        'invalid calendar dates rejected before network access');
}
try {
    $client->statement(2026, 11, 1, 1);
    throw new RuntimeException('FAIL OAuth SSRF allowed');
} catch (RuntimeException $e) {
    checkHttp($e->getMessage() === 'Unapproved Sicoob API host',
        'OAuth token endpoint host allowlist enforced');
}

$temp = tempnam(sys_get_temp_dir(), 'sicoob-bearer-synthetic-');
if (!is_string($temp)) {
    throw new RuntimeException('Unable to allocate test credential file');
}
try {
    file_put_contents($temp, 'SYNTHETIC_NOT_REAL_BEARER');
    $sandbox = new SicoobHttpClient([
        'MODE' => 'sandbox',
        'ACCOUNT_NUMBER' => '1234567',
        'CLIENT_ID' => 'synthetic',
        'AUTH_URL' => 'https://sandbox.sicoob.com.br/token',
        'API_BASE_URL' => 'https://api.sicoob.com.br.evil.invalid/conta-corrente/v4',
        'SANDBOX_TOKEN_FILE' => $temp,
    ]);
    try {
        $sandbox->statement(2026, 11, 1, 1);
        throw new RuntimeException('FAIL bank endpoint spoofing allowed');
    } catch (RuntimeException $e) {
        checkHttp($e->getMessage() === 'Unapproved Sicoob API host',
            'bank API endpoint host allowlist enforced');
    }
} finally {
    unlink($temp);
}
echo 'Sicoob HTTP client negative contract passed; no bank network traffic.' . PHP_EOL;
