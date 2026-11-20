<?php
// SPDX-FileCopyrightText: 2026 LibreCode Coop and contributors
// SPDX-License-Identifier: AGPL-3.0-or-later
declare(strict_types=1);

namespace Modules\BankingConnectors\Connectors\Sicoob;

use DomainException;

/**
 * Shared Sicoob credential binding for statement fetching and local health
 * probes. No bank calls, no secret copies, and no raw exception logging.
 */
final class SicoobCredentialResolver
{
    /**
     * @param object $account Company-owned native bank account + connection
     * @return array{config:array<string,string>,valid_to:int}
     */
    public function verify(object $account): array
    {
        $root = getenv('BANKING_CONNECTORS_SECRETS_DIR');
        $auth = getenv('BANKING_CONNECTORS_SICOOB_AUTH_URL');
        $api = getenv('BANKING_CONNECTORS_SICOOB_API_URL');
        if (!is_string($root) || $root === '' ||
            !is_string($auth) || $auth === '' ||
            !is_string($api) || $api === '') {
            throw new DomainException('bank_credentials_not_configured');
        }
        $companyId = (int) ($account->company_id ?? 0);
        $accountId = (int) ($account->account_id ?? 0);
        $config = (new ScopedSicoobCredentials($root, $auth, $api))->resolve(
            $companyId, $accountId,
            is_string($account->credential_reference ?? null)
                ? $account->credential_reference : null
        );
        // Optional separate read-only Pix API scope. Not enabled merely by
        // installing the module or by having current-account credentials.
        $pixBase=getenv('BANKING_CONNECTORS_SICOOB_PIX_API_URL');
        if (is_string($pixBase) && $pixBase!=='') {
            $parts=parse_url($pixBase);
            if (!is_array($parts) || ($parts['scheme']??null)!=='https' ||
                strtolower((string)($parts['host']??''))!=='api.sicoob.com.br' ||
                rtrim((string)($parts['path']??''),'/')!=='/pix/api/v2' ||
                isset($parts['port']) || isset($parts['user']) ||
                isset($parts['pass']) || isset($parts['query']) || isset($parts['fragment'])) {
                throw new DomainException('pix_api_endpoint_not_trusted');
            }
            $config['PIX_API_BASE_URL']=$pixBase;
        }
        $rawAccount = trim((string) ($account->number ?? ''));
        $digits = preg_replace('/[^0-9]/', '', $rawAccount);
        if (!preg_match('/^[0-9.\/ -]{1,40}$/D', $rawAccount) ||
            !is_string($digits) || $digits === '' ||
            !hash_equals($digits, $config['ACCOUNT_NUMBER'])) {
            throw new DomainException('bank_account_number_not_verified');
        }
        $expiration = (new MtlsCertificateVerifier())->verify($config);

        return ['config' => $config, 'valid_to' => $expiration];
    }
}
