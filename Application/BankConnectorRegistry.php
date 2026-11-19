<?php
// SPDX-FileCopyrightText: 2026 LibreCode Coop and contributors
// SPDX-License-Identifier: AGPL-3.0-or-later
declare(strict_types=1);

namespace Modules\BankingConnectors\Application;

use DomainException;
use InvalidArgumentException;
use Modules\BankingConnectors\Contracts\BankConnector;

/**
 * Extensible but explicit: installed trusted module providers register a
 * connector in Laravel's container. No filesystem scans, arbitrary
 * class names from the database, dynamic eval or network discovery.
 */
final class BankConnectorRegistry
{
    /** @var array<string,BankConnector> */
    private array $connectors = [];

    public function register(BankConnector $connector): void
    {
        $id = $connector->id();
        if (!preg_match('/^[a-z][a-z0-9_-]{1,47}$/D', $id)) {
            throw new InvalidArgumentException('invalid_bank_connector_id');
        }
        if (isset($this->connectors[$id])) {
            throw new DomainException('bank_connector_already_registered');
        }
        $this->connectors[$id] = $connector;
    }

    public function get(string $id): BankConnector
    {
        if (!preg_match('/^[a-z][a-z0-9_-]{1,47}$/D', $id)
            || !isset($this->connectors[$id])) {
            throw new DomainException('bank_connector_unavailable');
        }
        return $this->connectors[$id];
    }

    public function has(string $id): bool
    {
        return isset($this->connectors[$id]);
    }

    /** @return list<string> Stable sorted IDs, no secrets or class names. */
    public function ids(): array
    {
        $ids = array_keys($this->connectors);
        sort($ids, SORT_STRING);
        return $ids;
    }
}
