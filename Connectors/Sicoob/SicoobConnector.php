<?php
// SPDX-FileCopyrightText: 2026 LibreCode Coop and contributors
// SPDX-License-Identifier: AGPL-3.0-or-later
declare(strict_types=1);

namespace Modules\BankingConnectors\Connectors\Sicoob;

use Closure;
use DomainException;
use Modules\BankingConnectors\Contracts\BankConnector;
use Modules\BankingConnectors\Domain\ConnectorSession;
use Modules\BankingConnectors\Domain\ConnectionContext;
use Modules\BankingConnectors\Connectors\Sicoob\SicoobCredentialResolver;
use Modules\BankingConnectors\Connectors\Sicoob\SicoobHttpClient;
use Modules\BankingConnectors\Connectors\Sicoob\SicoobProvider;
use Modules\BankingConnectors\Connectors\Sicoob\SicoobStatementMapper;

/**
 * All bank-specific wiring is localized here. The core poller knows only
 * BankConnector and BankStatementProvider, not Sicoob's API or mTLS.
 */
final class SicoobConnector implements BankConnector
{
    public function id(): string
    {
        return 'sicoob';
    }

    public function open(ConnectionContext $connection): ConnectorSession
    {
        if ($connection->providerId !== $this->id()
            || $connection->currency !== 'BRL') {
            throw new DomainException('bank_connection_not_available');
        }
        $result = (new SicoobCredentialResolver())->verify((object) [
            'company_id' => $connection->companyId,
            'account_id' => $connection->accountId,
            'credential_reference' => $connection->credentialReference,
            'number' => $connection->accountNumber,
        ]);
        $config = $result['config'];
        $client = new SicoobHttpClient($config);
        return new ConnectorSession(
            new SicoobProvider(
                Closure::fromCallable([$client, 'statement']),
                new SicoobStatementMapper(),
                $connection->companyId,
                $connection->accountId,
            ),
            $config['BANKING_CONNECTORS_FINGERPRINT_KEY'],
            $result['valid_to'],
        );
    }
}
