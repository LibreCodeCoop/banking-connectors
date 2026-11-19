<?php
// SPDX-FileCopyrightText: 2026 LibreCode Coop and contributors
// SPDX-License-Identifier: AGPL-3.0-or-later
declare(strict_types=1);

namespace Modules\BankingConnectors\Connectors\Sicoob;

use DomainException;
use InvalidArgumentException;
use Modules\CredentialVault\Infrastructure\RenderedSecretFiles;
use RuntimeException;

/**
 * Sicoob-specific validations only. Generic filesystem isolation belongs to
 * CredentialVault/RenderedSecretFiles and is shared by future bank adapters.
 */
final class ScopedSicoobCredentials
{
    public function __construct(
        private readonly string $root,
        private readonly string $authUrl,
        private readonly string $apiBaseUrl,
    ) {
        if ($root === '' || $root[0] !== '/') {
            throw new InvalidArgumentException('bank_secret_root_must_be_absolute');
        }
        $this->assertAllowedEndpoint($authUrl, 'auth.sicoob.com.br');
        $this->assertAllowedEndpoint($apiBaseUrl, 'api.sicoob.com.br');
    }

    /** @return array<string,string> Existing SicoobClient configuration contract. */
    public function resolve(int $company, int $account, ?string $reference): array
    {
        if ($company < 1 || $account < 1 ||
            $reference !== "sicoob/company-{$company}/account-{$account}") {
            throw new DomainException('bank_credential_scope_mismatch');
        }
        try {
            $shared = new RenderedSecretFiles($this->root);
            $paths = $shared->filePaths('sicoob', $company, $account, $reference, [
                'client-id', 'account-number', 'client.pem', 'client-key.pem',
                'client-key-password', 'fingerprint-key',
            ]);
            $clientId = $shared->text($paths['client-id'], 256);
            $accountNumber = $shared->text($paths['account-number'], 40);
            $fingerprintKey = $shared->text($paths['fingerprint-key'], 1024);
        } catch (DomainException $error) {
            if ($error->getMessage() === 'secret_scope_invalid') {
                throw new DomainException('bank_credential_scope_mismatch');
            }
            throw $error;
        } catch (RuntimeException $error) {
            $safe = match ($error->getMessage()) {
                'secret_mount_unavailable' => 'bank_secret_mount_unavailable',
                'secret_file_unavailable' => 'bank_secret_file_unavailable',
                'secret_permissions_unsafe' => 'bank_secret_permissions_unsafe',
                default => 'bank_secret_file_invalid',
            };
            throw new RuntimeException($safe);
        }

        if (!preg_match('/^[A-Za-z0-9._-]{1,256}$/D', $clientId)) {
            throw new RuntimeException('bank_client_identity_invalid');
        }
        if (!preg_match('/^[0-9]+$/D', $accountNumber)) {
            throw new RuntimeException('bank_account_identity_invalid');
        }
        if (strlen($fingerprintKey) < 32) {
            throw new RuntimeException('bank_fingerprint_key_invalid');
        }

        return [
            'MODE' => 'production',
            'CLIENT_ID' => $clientId,
            'ACCOUNT_NUMBER' => $accountNumber,
            'AUTH_URL' => $this->authUrl,
            'API_BASE_URL' => $this->apiBaseUrl,
            'TLS_CERT_PATH' => $paths['client.pem'],
            'TLS_KEY_PATH' => $paths['client-key.pem'],
            'TLS_KEY_PASSWORD_FILE' => $paths['client-key-password'],
            'BANKING_CONNECTORS_FINGERPRINT_KEY' => $fingerprintKey,
        ];
    }

    private function assertAllowedEndpoint(string $value, string $host): void
    {
        $parts = parse_url($value);
        if (!is_array($parts)
            || ($parts['scheme'] ?? '') !== 'https'
            || strtolower((string) ($parts['host'] ?? '')) !== $host
            || isset($parts['user']) || isset($parts['pass'])
            || isset($parts['fragment'])
            || isset($parts['query'])
            || isset($parts['port'])
        ) {
            throw new InvalidArgumentException('untrusted_bank_endpoint');
        }
    }
}
