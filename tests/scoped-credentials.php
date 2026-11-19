<?php
// SPDX-FileCopyrightText: 2026 LibreCode Coop and contributors
// SPDX-License-Identifier: AGPL-3.0-or-later
declare(strict_types=1);

$shared = dirname(__DIR__, 2) . '/akaunting-credential-vault/Infrastructure/RenderedSecretFiles.php';
if (!is_file($shared)) {
    $shared = '/shared/Infrastructure/RenderedSecretFiles.php';
}
require_once $shared;
require dirname(__DIR__) . '/Connectors/Sicoob/ScopedSicoobCredentials.php';
use Modules\BankingConnectors\Connectors\Sicoob\ScopedSicoobCredentials;

function checkSecret(bool $condition, string $name): void {
    if (!$condition) {
        throw new RuntimeException('FAIL ' . $name);
    }
    echo 'PASS ' . $name . PHP_EOL;
}
$root = sys_get_temp_dir() . '/banking_connectors-secrets-' . bin2hex(random_bytes(8));
$folder = $root . '/sicoob/company-1/account-9';
$files = [
    'client-id' => 'test-client-id',
    'account-number' => '1234567',
    'client.pem' => 'NOT_A_REAL_CERTIFICATE',
    'client-key.pem' => 'NOT_A_REAL_PRIVATE_KEY',
    'client-key-password' => 'test-password',
    'fingerprint-key' => bin2hex(random_bytes(32)),
];
mkdir($folder, 0700, true);
try {
    foreach ($files as $name => $data) {
        file_put_contents($folder . '/' . $name, $data);
        chmod($folder . '/' . $name, 0600);
    }
    $credentials = new ScopedSicoobCredentials(
        $root,
        'https://auth.sicoob.com.br/auth/realms/cooperado/protocol/openid-connect/token',
        'https://api.sicoob.com.br/conta-corrente/v4'
    );
    $config = $credentials->resolve(1, 9, 'sicoob/company-1/account-9');
    checkSecret($config['CLIENT_ID'] === 'test-client-id' &&
        $config['ACCOUNT_NUMBER'] === '1234567' &&
        strlen($config['BANKING_CONNECTORS_FINGERPRINT_KEY']) >= 32,
        'scoped file data resolves without database-stored credentials');
    checkSecret($config['MODE'] === 'production', 'production mode cannot be overridden by user input');

    try {
        $credentials->resolve(2, 9, 'sicoob/company-1/account-9');
        throw new RuntimeException('FAIL tenant reference accepted');
    } catch (DomainException $e) {
        checkSecret($e->getMessage() === 'bank_credential_scope_mismatch',
            'cross-company credential path denied');
    }
    try {
        $credentials->resolve(1, 9, 'sicoob/company-1/account-9/../../something');
        throw new RuntimeException('FAIL path traversal accepted');
    } catch (DomainException $e) {
        checkSecret($e->getMessage() === 'bank_credential_scope_mismatch',
            'request cannot override or traverse server-managed credential path');
    }
    chmod($folder . '/client-id', 0644);
    try {
        $credentials->resolve(1, 9, 'sicoob/company-1/account-9');
        throw new RuntimeException('FAIL world readable key configuration accepted');
    } catch (RuntimeException $e) {
        checkSecret($e->getMessage() === 'bank_secret_permissions_unsafe',
            'world-readable secret file denied');
    }
    chmod($folder . '/client-id', 0600);
    file_put_contents($folder . '/client-id', "synthetic\\r\\nInjected: yes");
    try {
        $credentials->resolve(1, 9, 'sicoob/company-1/account-9');
        throw new RuntimeException('FAIL malformed client header value accepted');
    } catch (RuntimeException $e) {
        checkSecret($e->getMessage() === 'bank_client_identity_invalid',
            'client identity cannot inject additional HTTP headers');
    }
    file_put_contents($folder . '/client-id', 'test-client-id');
    chmod($folder, 0777);
    try {
        $credentials->resolve(1, 9, 'sicoob/company-1/account-9');
        throw new RuntimeException('FAIL world-writable bank secret directory accepted');
    } catch (RuntimeException $e) {
        checkSecret($e->getMessage() === 'bank_secret_permissions_unsafe',
            'world-writable secret directory rejected');
    } finally {
        chmod($folder, 0700);
    }
    unlink($folder . '/client.pem');
    symlink($folder . '/client-key.pem', $folder . '/client.pem');
    try {
        $credentials->resolve(1, 9, 'sicoob/company-1/account-9');
        throw new RuntimeException('FAIL symlink secret accepted');
    } catch (RuntimeException $e) {
        checkSecret($e->getMessage() === 'bank_secret_file_unavailable',
            'symlink to private credential denied');
    }
    unlink($folder . '/client.pem');
    file_put_contents($folder . '/client.pem', 'NOT_A_REAL_CERTIFICATE');
    chmod($folder . '/client.pem', 0600);
    try {
        new ScopedSicoobCredentials($root, 'http://auth.sicoob.com.br/token',
            'https://api.sicoob.com.br/conta-corrente/v4');
        throw new RuntimeException('FAIL http endpoint accepted');
    } catch (InvalidArgumentException $e) {
        checkSecret($e->getMessage() === 'untrusted_bank_endpoint',
            'non-HTTPS token endpoint rejected');
    }
    try {
        new ScopedSicoobCredentials($root, 'https://auth.sicoob.com.br/token',
            'https://api.sicoob.com.br.evil.example/conta-corrente/v4');
        throw new RuntimeException('FAIL SSRF endpoint accepted');
    } catch (InvalidArgumentException $e) {
        checkSecret($e->getMessage() === 'untrusted_bank_endpoint',
            'endpoint host spoofing rejected');
    }
    echo 'Credential file tests passed: synthetic files, no OpenBao login.' . PHP_EOL;
} finally {
    foreach (array_keys($files) as $name) {
        $path = $folder . '/' . $name;
        if (file_exists($path) || is_link($path)) {
            unlink($path);
        }
    }
    rmdir($folder);
    rmdir(dirname($folder));
    rmdir(dirname(dirname($folder)));
    rmdir($root);
}
