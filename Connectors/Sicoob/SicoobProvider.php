<?php
// SPDX-FileCopyrightText: 2026 LibreCode Coop and contributors
// SPDX-License-Identifier: AGPL-3.0-or-later
declare(strict_types=1);
namespace Modules\BankingConnectors\Connectors\Sicoob;

use Closure;
use DateTimeImmutable;
use DomainException;
use InvalidArgumentException;
use Modules\BankingConnectors\Contracts\BankStatementProvider;
use Modules\BankingConnectors\Domain\BankEntry;
use RuntimeException;

/**
 * Bank-specific reader adapter around an injected read-only Sicoob client.
 * No bank credentials are stored in the Akaunting domain model.
 */
final class SicoobProvider implements BankStatementProvider
{
    /** @param Closure(int,int,int,int):array $statementReader */
    public function __construct(
        private readonly Closure $statementReader,
        private readonly SicoobStatementMapper $mapper,
        private readonly int $boundCompanyId,
        private readonly int $boundAccountId,
    ) {
        if ($boundCompanyId < 1 || $boundAccountId < 1) {
            throw new InvalidArgumentException('invalid_bank_binding');
        }
    }

    /** @return array<int,BankEntry> */
    public function fetch(
        int $companyId,
        int $accountId,
        DateTimeImmutable $from,
        DateTimeImmutable $until
    ): array {
        if ($companyId !== $this->boundCompanyId || $accountId !== $this->boundAccountId) {
            throw new DomainException('bank_binding_scope_mismatch');
        }
        if ($from > $until || (int) $from->diff($until)->format('%a') > 35) {
            throw new InvalidArgumentException('invalid_polling_window');
        }
        if ($from->format('H:i:s') !== '00:00:00' || $until->format('H:i:s') !== '00:00:00') {
            throw new InvalidArgumentException('date_only_required');
        }

        $entries = [];
        $seen = [];
        $cursor = $from;
        while ($cursor <= $until) {
            $lastDay = $cursor->modify('last day of this month');
            $end = $lastDay < $until ? $lastDay : $until;
            $response = ($this->statementReader)(
                (int) $cursor->format('Y'),
                (int) $cursor->format('m'),
                (int) $cursor->format('d'),
                (int) $end->format('d')
            );
            if (!is_array($response)) {
                throw new RuntimeException('invalid_bank_response');
            }
            $mapped = $this->mapper->map($response, $companyId, $accountId);
            if ($mapped['rejected'] !== []) {
                // Never silently declare a partially parsed statement imported.
                throw new RuntimeException('bank_statement_requires_review');
            }
            foreach ($mapped['entries'] as $entry) {
                // Fail closed when a provider ignores the requested interval:
                // otherwise the cursor may advance past unverified evidence.
                if ($entry->date < $cursor->format('Y-m-d') ||
                    $entry->date > $end->format('Y-m-d')) {
                    throw new RuntimeException('bank_statement_outside_requested_window');
                }
                // Bank provider IDs are expected to identify unique entries
                // within a bound account even across overlapping windows.
                if (isset($seen[$entry->externalId])) {
                    throw new RuntimeException('bank_statement_duplicate_identity');
                }
                $seen[$entry->externalId] = true;
                $entries[] = $entry;
            }
            $cursor = $end->modify('+1 day');
        }
        return $entries;
    }
}
