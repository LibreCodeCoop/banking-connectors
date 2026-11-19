<?php
// SPDX-FileCopyrightText: 2026 LibreCode Coop and contributors
// SPDX-License-Identifier: AGPL-3.0-or-later
declare(strict_types=1);

namespace Modules\BankingConnectors\Connectors\Sicoob;

use DomainException;

/**
 * Checks locally whether bank mTLS PEM files contain a current certificate
 * and its matching private key. Never calls the bank or logs secret material.
 * Bank-side acceptance still requires a real authenticated GET.
 */
final class MtlsCertificateVerifier
{
    /** @param array<string,string> $config */
    public function verify(array $config): int
    {
        $certPem = @file_get_contents($config['TLS_CERT_PATH'] ?? '');
        $keyPem = @file_get_contents($config['TLS_KEY_PATH'] ?? '');
        $password = @file_get_contents($config['TLS_KEY_PASSWORD_FILE'] ?? '');
        if (!is_string($certPem) || !is_string($keyPem) || !is_string($password)) {
            throw new DomainException('bank_mtls_certificate_invalid');
        }
        $certificate = @openssl_x509_read($certPem);
        if ($certificate === false) {
            throw new DomainException('bank_mtls_certificate_invalid');
        }
        $details = @openssl_x509_parse($certificate);
        $now = time();
        if (!is_array($details)
            || !isset($details['validFrom_time_t'], $details['validTo_time_t'])
            || $now < (int) $details['validFrom_time_t']
            || $now >= (int) $details['validTo_time_t']) {
            throw new DomainException('bank_mtls_certificate_expired');
        }
        $key = @openssl_pkey_get_private($keyPem, rtrim($password, "\r\n"));
        if ($key === false) {
            throw new DomainException('bank_mtls_key_invalid');
        }
        if (!openssl_x509_check_private_key($certificate, $key)) {
            throw new DomainException('bank_mtls_key_mismatch');
        }
        return (int) $details['validTo_time_t'];
    }
}
