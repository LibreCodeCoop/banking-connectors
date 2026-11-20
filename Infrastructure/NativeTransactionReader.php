<?php
// SPDX-FileCopyrightText: 2026 LibreCode Coop and contributors
// SPDX-License-Identifier: AGPL-3.0-or-later
declare(strict_types=1);

namespace Modules\BankingConnectors\Infrastructure;

use DateTimeImmutable;
use InvalidArgumentException;
use Modules\BankingConnectors\Domain\AkauntingTransactionMapper;
use PDO;

/**
 * Read-only native Akaunting transactions; company and account are mandatory.
 * Calling controllers must first check Akaunting's existing auth and ACL.
 */
final class NativeTransactionReader
{
    private readonly string $table;

    public function __construct(
        private readonly PDO $db,
        private readonly AkauntingTransactionMapper $mapper,
        string $tablePrefix = '',
    ) {
        if (strlen($tablePrefix) > 40 || !preg_match('/^[A-Za-z0-9_]*$/D', $tablePrefix)) {
            throw new InvalidArgumentException('invalid_table_prefix');
        }
        $this->table = $tablePrefix . 'transactions';
    }

    /**
     * Read a small, date-bounded set of eligible native movements.
     * A truncated or malformed set cannot safely produce "unmatched".
     *
     * @return array{rows:array<int,array<string,mixed>>,truncated:bool,invalid:bool}
     */
    public function near(int $companyId, int $accountId, string $date, int $days = 3): array
    {
        if ($companyId < 1 || $accountId < 1 || $days < 0 || $days > 90) {
            throw new InvalidArgumentException('invalid_lookup_scope');
        }
        $parsed = DateTimeImmutable::createFromFormat('!Y-m-d', $date);
        if (!$parsed || $parsed->format('Y-m-d') !== $date) {
            throw new InvalidArgumentException('invalid_lookup_date');
        }
        $from = $parsed->modify("-{$days} days")->format('Y-m-d') . ' 00:00:00';
        $until = $parsed->modify('+' . ($days + 1) . ' days')->format('Y-m-d') . ' 00:00:00';
        $sql = "SELECT id, company_id, account_id, type, paid_at, amount, currency_code
                FROM {$this->table}
                WHERE company_id = :company_id AND account_id = :account_id
                  AND paid_at >= :from_date AND paid_at < :until_date
                  AND type IN ('income', 'expense', 'income-transfer', 'expense-transfer')
                  AND parent_id = 0 AND split_id IS NULL
                  AND deleted_at IS NULL
                ORDER BY paid_at DESC, id DESC LIMIT 201";
        $stmt = $this->db->prepare($sql);
        $stmt->execute([
            ':company_id' => $companyId,
            ':account_id' => $accountId,
            ':from_date' => $from,
            ':until_date' => $until,
        ]);
        $records = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $truncated = count($records) > 200;
        $invalid = false;
        $rows = [];
        foreach ($records as $record) {
            try {
                $rows[] = $this->mapper->map($record);
            } catch (InvalidArgumentException) {
                // A malformed native record is NOT evidence of no counterpart.
                $invalid = true;
            }
        }
        return ['rows' => $rows, 'truncated' => $truncated, 'invalid' => $invalid];
    }
}
