<?php
// SPDX-FileCopyrightText: 2026 LibreCode Coop and contributors
// SPDX-License-Identifier: AGPL-3.0-or-later
declare(strict_types=1);

namespace Modules\BankConnectorExample\Connectors;

use DateTimeImmutable;
use DomainException;
use Modules\BankingConnectors\Contracts\BankConnector;
use Modules\BankingConnectors\Contracts\BankStatementProvider;
use Modules\BankingConnectors\Domain\BankEntry;
use Modules\BankingConnectors\Domain\ConnectionContext;
use Modules\BankingConnectors\Domain\ConnectorSession;

/**
 * Synthetic, non-network demonstration; NEVER connect to a real bank.
 * Replace this connector with an institution's authenticated HTTP client,
 * response mapper and scopes, leaving Banking Connectors core untouched.
 */
final class ExampleConnector implements BankConnector
{
    public function id(): string
    {
        return 'examplebank';
    }

    public function open(ConnectionContext $scope): ConnectorSession
    {
        if (!app()->environment('local') ||
            $scope->providerId !== $this->id()) {
            throw new DomainException('example_connector_disabled');
        }
        // Test fixture deliberately carries NO real bank credentials.
        $reader = new class($scope) implements BankStatementProvider {
            public function __construct(private readonly ConnectionContext $scope) {}

            public function fetch(
                int $companyId, int $accountId,
                DateTimeImmutable $from, DateTimeImmutable $until
            ): array {
                if ($companyId !== $this->scope->companyId ||
                    $accountId !== $this->scope->accountId ||
                    $from > $until || $from->format('Y-m-d') > $until->format('Y-m-d')) {
                    throw new DomainException('bank_binding_scope_mismatch');
                }
                return [
                    new BankEntry(
                        $companyId, $accountId, $this->scope->providerId,
                        'synthetic-examplebank-' . $from->format('Ymd'),
                        $from->format('Y-m-d'), 'credit', 500,
                        $this->scope->currency,
                    ),
                ];
            }
        };
        return new ConnectorSession(
            $reader,
            str_repeat('synthetic-example-provider-HMAC-', 2),
        );
    }
}
