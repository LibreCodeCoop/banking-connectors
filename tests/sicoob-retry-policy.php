<?php
// SPDX-FileCopyrightText: 2026 LibreCode Coop and contributors
// SPDX-License-Identifier: AGPL-3.0-or-later
declare(strict_types=1);

require dirname(__DIR__) . '/Contracts/BankTransportFailure.php';
require dirname(__DIR__) . '/Connectors/Sicoob/SicoobHttpFailure.php';
require dirname(__DIR__) . '/Connectors/Sicoob/SicoobHttpClient.php';

use Modules\BankingConnectors\Connectors\Sicoob\SicoobHttpClient;
use Modules\BankingConnectors\Connectors\Sicoob\SicoobHttpFailure;

function checkRetry(bool $condition, string $description): void {
    if (!$condition) {
        throw new RuntimeException('FAIL ' . $description);
    }
    echo 'PASS ' . $description . PHP_EOL;
}

$cases = [
    [0, 'bank_api_network_failed'],
    [401, 'bank_api_unauthorized'],
    [403, 'bank_api_forbidden'],
    [429, 'bank_api_rate_limited'],
    [500, 'bank_api_temporarily_unavailable'],
    [503, 'bank_api_temporarily_unavailable'],
    [404, 'bank_api_request_failed'],
];
foreach ($cases as [$status, $expected]) {
    checkRetry((new SicoobHttpFailure($status))->safeCode === $expected,
        "safe and stable error code for HTTP {$status}");
}
checkRetry(SicoobHttpFailure::parseRetryAfter('120') === 120,
    'numeric Retry-After parsed');
checkRetry(SicoobHttpFailure::parseRetryAfter('9999999') === 3600,
    'large Retry-After capped at one hour');
checkRetry(SicoobHttpFailure::parseRetryAfter('0') === 1,
    'zero Retry-After clamped');
checkRetry(SicoobHttpFailure::parseRetryAfter("60\r\nX-Injected: yes") === null,
    'untrusted header injection ignored');
checkRetry(SicoobHttpFailure::parseRetryAfter('garbage') === null,
    'invalid retry guidance ignored');

$tokenFile = tempnam(sys_get_temp_dir(), 'banking-connectors-test-token-');
if ($tokenFile === false) {
    throw new RuntimeException('Unable to create synthetic token fixture');
}
file_put_contents($tokenFile, 'synthetic-not-a-real-bearer');
try {
    $config = [
        'MODE' => 'sandbox',
        'CLIENT_ID' => 'synthetic',
        'ACCOUNT_NUMBER' => '123456',
        'API_BASE_URL' => 'https://sandbox.sicoob.com.br/conta-corrente/v4',
        'AUTH_URL' => 'https://sandbox.sicoob.com.br/token',
        'SANDBOX_TOKEN_FILE' => $tokenFile,
    ];
    foreach ([403, 429, 503] as $httpStatus) {
        $calls = 0;
        $client = new SicoobHttpClient($config,
            static function () use (&$calls, $httpStatus): array {
                ++$calls;
                return [
                    'status' => $httpStatus,
                    'body' => '{"credentials":"SHOULD_NEVER_APPEAR_IN_ERROR"}',
                    'retry_after' => '900',
                ];
            }
        );
        try {
            $client->statement(2026, 11, 1, 3);
            throw new RuntimeException('FAIL HTTP error unexpectedly accepted');
        } catch (SicoobHttpFailure $e) {
            checkRetry($e->status === $httpStatus && $e->retryAfterSeconds === 900,
                "HTTP {$httpStatus} status and bounded retry metadata preserved");
            checkRetry(!str_contains($e->getMessage(), 'SHOULD_NEVER_APPEAR'),
                "HTTP {$httpStatus} response body never reflected");
        }
        checkRetry($calls === 1, "HTTP {$httpStatus} not aggressively retried");
    }
    $calls = 0;
    $client = new SicoobHttpClient($config, static function () use (&$calls): array {
        ++$calls;
        return ['status' => 0, 'body' => false];
    });
    try {
        $client->statement(2026, 11, 1, 3);
        throw new RuntimeException('FAIL network loss accepted');
    } catch (SicoobHttpFailure $e) {
        checkRetry($e->safeCode === 'bank_api_network_failed' && $calls === 1,
            'network outage gives sanitized error without repeat request');
    }
    $client = new SicoobHttpClient($config, static fn(): array => [
        'status' => 200, 'body' => 'invalid json SENTINEL_RAW_BANK_DATA',
    ]);
    try {
        $client->statement(2026, 11, 1, 3);
        throw new RuntimeException('FAIL invalid JSON was accepted');
    } catch (RuntimeException $e) {
        checkRetry($e->getMessage() === 'bank_api_response_invalid',
            'malformed bank JSON fails closed without disclosing raw payload');
    }
    $client = new SicoobHttpClient($config, static fn(): array => [
        'status' => 204, 'body' => '',
    ]);
    checkRetry($client->statement(2026, 11, 1, 3) === [
        'resultado' => ['transacoes' => []],
    ], 'HTTP 204 empty statement handled as empty bank movements');

    // Production OAuth and mTLS transport only through fake exchange:
    // no network call, no live account information or real certificate.
    $cert = tempnam(sys_get_temp_dir(), 'synthetic-bank-cert-');
    $key = tempnam(sys_get_temp_dir(), 'synthetic-bank-key-');
    $pass = tempnam(sys_get_temp_dir(), 'synthetic-bank-pass-');
    if (!$cert || !$key || !$pass) {
        throw new RuntimeException('Could not create synthetic mTLS file paths');
    }
    try {
        file_put_contents($cert, "synthetic-file");
        file_put_contents($key, "synthetic-file");
        file_put_contents($pass, "synthetic-password");
        $prod = [
            'MODE' => 'production',
            'CLIENT_ID' => 'synthetic',
            'ACCOUNT_NUMBER' => '123456',
            'AUTH_URL' => 'https://auth.sicoob.com.br/token',
            'API_BASE_URL' => 'https://api.sicoob.com.br/conta-corrente/v4',
            'TLS_CERT_PATH' => $cert,
            'TLS_KEY_PATH' => $key,
            'TLS_KEY_PASSWORD_FILE' => $pass,
        ];
        $tokenCalls = 0;
        $bankCalls = 0;
        $client = new SicoobHttpClient($prod,
            static function (string $method) use (&$tokenCalls, &$bankCalls): array {
                if ($method === 'POST') {
                    ++$tokenCalls;
                    return [
                        'status' => 200,
                        'body' => '{"access_token":"synthetic-not-real","expires_in":600}',
                    ];
                }
                ++$bankCalls;
                if ($bankCalls === 1) {
                    return ['status' => 401, 'body' => '{"error":"invalid_token"}'];
                }
                return ['status' => 200, 'body' => '{"resultado":{"transacoes":[]}}'];
            }
        );
        $result = $client->statement(2026, 11, 1, 3);
        checkRetry(isset($result['resultado']) &&
            $tokenCalls === 2 && $bankCalls === 2,
            'production GET 401 renews OAuth once and fetches once again');
        $oauthCalls = 0;
        $brokenOAuth = new SicoobHttpClient($prod, static function () use (&$oauthCalls): array {
            ++$oauthCalls;
            return ['status' => 401, 'body' => '{"error":"unauthorized"}'];
        });
        try {
            $brokenOAuth->statement(2026, 11, 1, 3);
            throw new RuntimeException('FAIL OAuth 401 unexpectedly accepted');
        } catch (SicoobHttpFailure $e) {
            checkRetry($e->safeCode === 'bank_api_unauthorized' &&
                !$e->statementRequest && $oauthCalls === 1,
                'OAuth 401 cannot trigger an extra token request');
        }
    } finally {
        @unlink($cert);
        @unlink($key);
        @unlink($pass);
    }
} finally {
    @unlink($tokenFile);
}
echo "Sicoob transport failure policy passed without bank traffic.\n";
