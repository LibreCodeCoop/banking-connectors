<?php
// SPDX-FileCopyrightText: 2026 LibreCode Coop and contributors
// SPDX-License-Identifier: AGPL-3.0-or-later
declare(strict_types=1);

require dirname(__DIR__) . '/Connectors/Sicoob/MtlsCertificateVerifier.php';
use Modules\BankingConnectors\Connectors\Sicoob\MtlsCertificateVerifier;

function checkMtl(bool $ok, string $label): void
{
    if (!$ok) {
        throw new RuntimeException('FAIL ' . $label);
    }
    echo 'PASS ' . $label . PHP_EOL;
}
$path = sys_get_temp_dir() . '/bank-mtls-test-' . bin2hex(random_bytes(7));
mkdir($path, 0700);
try {
    $key = openssl_pkey_new(['private_key_bits' => 2048,
        'private_key_type' => OPENSSL_KEYTYPE_RSA]);
    if ($key === false) {
        throw new RuntimeException('Synthetic OpenSSL key generation unavailable');
    }
    $csr = openssl_csr_new(['commonName' => 'localhost-banking-connectors-test'], $key);
    $certificate = openssl_csr_sign($csr, null, $key, 2);
    if ($certificate === false) {
        throw new RuntimeException('Synthetic OpenSSL certificate generation failed');
    }
    openssl_x509_export($certificate, $certPem);
    $password = 'synthetic-password-do-not-reuse';
    openssl_pkey_export($key, $privatePem, $password);
    foreach ([
        'cert.pem' => $certPem,
        'key.pem' => $privatePem,
        'password' => $password,
    ] as $name => $value) {
        file_put_contents("$path/$name", $value);
        chmod("$path/$name", 0600);
    }
    $config = [
        'TLS_CERT_PATH' => "$path/cert.pem",
        'TLS_KEY_PATH' => "$path/key.pem",
        'TLS_KEY_PASSWORD_FILE' => "$path/password",
    ];
    $verifier = new MtlsCertificateVerifier();
    $verifier->verify($config);
    checkMtl(true, 'valid matching encrypted PEM key and certificate accepted');

    file_put_contents("$path/password", 'not-the-correct-password');
    try {
        $verifier->verify($config);
        throw new RuntimeException('FAIL invalid private key password accepted');
    } catch (DomainException $e) {
        checkMtl($e->getMessage() === 'bank_mtls_key_invalid',
            'wrong private key password rejected locally');
    }
    file_put_contents("$path/password", $password);

    $otherKey = openssl_pkey_new(['private_key_bits' => 2048,
        'private_key_type' => OPENSSL_KEYTYPE_RSA]);
    openssl_pkey_export($otherKey, $otherPem, $password);
    file_put_contents("$path/key.pem", $otherPem);
    try {
        $verifier->verify($config);
        throw new RuntimeException('FAIL mismatched certificate/private key accepted');
    } catch (DomainException $e) {
        checkMtl($e->getMessage() === 'bank_mtls_key_mismatch',
            'valid but unrelated private key rejected locally');
    }
    file_put_contents("$path/key.pem", $privatePem);

    file_put_contents("$path/cert.pem", 'NOT_A_VALID_X509_CERTIFICATE');
    try {
        $verifier->verify($config);
        throw new RuntimeException('FAIL malformed certificate accepted');
    } catch (DomainException $e) {
        checkMtl($e->getMessage() === 'bank_mtls_certificate_invalid',
            'malformed certificate rejected without bank traffic');
    }
    echo 'mTLS local verification passed; no real credentials were used.' . PHP_EOL;
} finally {
    foreach (['cert.pem', 'key.pem', 'password'] as $name) {
        if (is_file("$path/$name")) {
            unlink("$path/$name");
        }
    }
    rmdir($path);
}
