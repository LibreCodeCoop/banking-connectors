<?php
// SPDX-FileCopyrightText: 2026 LibreCode Coop and contributors
// SPDX-License-Identifier: AGPL-3.0-or-later
declare(strict_types=1);

require dirname(__DIR__) . '/Domain/BankEntry.php';
require dirname(__DIR__) . '/Contracts/BankStatementProvider.php';
require dirname(__DIR__) . '/Contracts/BankConnector.php';
require dirname(__DIR__) . '/Domain/ConnectorSession.php';
require dirname(__DIR__) . '/Domain/ConnectionContext.php';
require dirname(__DIR__) . '/Application/BankConnectorRegistry.php';
require dirname(__DIR__) . '/Infrastructure/PdoEntryStage.php';
require dirname(__DIR__) . '/Application/ReadOnlySynchronizer.php';
require __DIR__ . '/Support/SqliteStageFixture.php';

use Modules\BankingConnectors\Contracts\BankConnector;
use Modules\BankingConnectors\Contracts\BankStatementProvider;
use Modules\BankingConnectors\Application\BankConnectorRegistry;
use Modules\BankingConnectors\Application\ReadOnlySynchronizer;
use Modules\BankingConnectors\Domain\BankEntry;
use Modules\BankingConnectors\Domain\ConnectorSession;
use Modules\BankingConnectors\Domain\ConnectionContext;
use Modules\BankingConnectors\Infrastructure\PdoEntryStage;
use Modules\BankingConnectors\Tests\Support\SqliteStageFixture;

function checkConnector(bool $pass, string $description): void {
    if (!$pass) {
        throw new RuntimeException('FAIL ' . $description);
    }
    echo 'PASS ' . $description . PHP_EOL;
}

// A fake bank plugin lives *outside* the Banking Connectors implementation classes.
// It knows only the public contracts. It uses no Sicoob or Akaunting source.
$fakeConnector = new class implements BankConnector {
    public function id(): string { return 'usdbank'; }
    public function open(ConnectionContext $connection): ConnectorSession {
        if ($connection->providerId !== $this->id() ||
            $connection->companyId !== 2 ||
            $connection->accountId !== 11 ||
            $connection->currency !== 'USD' ||
            $connection->credentialReference !== 'usdbank/company-2/account-11') {
            throw new DomainException('bank_binding_scope_mismatch');
        }
        $reader = new class implements BankStatementProvider {
            public function fetch(
                int $companyId, int $accountId,
                DateTimeImmutable $from, DateTimeImmutable $until
            ): array {
                if ($companyId !== 2 || $accountId !== 11 || $from > $until) {
                    throw new DomainException('bank_binding_scope_mismatch');
                }
                return [
                    new BankEntry(2, 11, 'usdbank', 'usdbank-entry-001',
                        '2026-11-16', 'credit', 1550, 'USD'),
                ];
            }
        };
        return new ConnectorSession(
            $reader, str_repeat('synthetic-bank-connector-HMAC-', 2)
        );
    }
};
$registry = new BankConnectorRegistry();
$registry->register($fakeConnector);
checkConnector($registry->ids() === ['usdbank'] &&
    $registry->get('usdbank') === $fakeConnector,
    'external connector registered and discoverable without core source edits');
try {
    $registry->register($fakeConnector);
    throw new RuntimeException('FAIL duplicate connector registration accepted');
} catch (DomainException $e) {
    checkConnector($e->getMessage() === 'bank_connector_already_registered',
        'duplicate provider IDs refused before they can hijack bank accounts');
}
foreach (['../../sicoob', 'SICOOB', 'sicoob\\evil', 'unknown'] as $bad) {
    try {
        $registry->get($bad);
        throw new RuntimeException('FAIL unregistered or malicious provider selected');
    } catch (DomainException) {
        checkConnector(true, 'unregistered or malicious connector ID denied');
    }
}
try {
    $registry->register(new class implements BankConnector {
        public function id(): string { return 'usdbank/../../private'; }
        public function open(ConnectionContext $connection): ConnectorSession { throw new DomainException('invalid'); }
    });
    throw new RuntimeException('FAIL unsafe connector ID registered');
} catch (InvalidArgumentException) {
    checkConnector(true, 'unsafe connector identifier rejected');
}
$db = new PDO('sqlite::memory:', null, null, [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION
]);
SqliteStageFixture::install($db);
SqliteStageFixture::connection($db, 5, 2, 11, 'usdbank');
$db->exec("UPDATE lcc_banking_connectors_connections
           SET credential_reference='usdbank/company-2/account-11'
           WHERE id=5");
$connection = new ConnectionContext(
    2, 11, 'usdbank', 'USD', '123456', 'usdbank/company-2/account-11'
);
$session = $registry->get('usdbank')->open($connection);
$sync = new ReadOnlySynchronizer($db, new PdoEntryStage($db));
$from = new DateTimeImmutable('2026-11-16');
$first = $sync->synchronize(
    5, 2, 11, 'usdbank', $session->statements, $session->fingerprintKey,
    $from, $from
);
checkConnector($first['inserted'] === 1 && $first['conflicts'] === 0,
    'independently implemented USD connector uses generic staging/cursor');
$repeat = $sync->synchronize(
    5, 2, 11, 'usdbank', $session->statements, $session->fingerprintKey,
    $from, $from
);
checkConnector($repeat['duplicates'] === 1 && $repeat['inserted'] === 0,
    'generic financial synchronization remains idempotent for new bank');
$record = $db->query(
    'SELECT provider,currency,company_id,account_id FROM lcc_banking_connectors_entries WHERE connection_id=5'
)->fetch(PDO::FETCH_ASSOC);
checkConnector($record['provider'] === 'usdbank' &&
    $record['currency'] === 'USD' &&
    (int) $record['company_id'] === 2 && (int) $record['account_id'] === 11,
    'staging preserves institution, tenant, bank account and currency');
try {
    $registry->get('usdbank')->open(new ConnectionContext(
        3, 11, 'usdbank', 'USD', '123456', 'usdbank/company-3/account-11'
    ));
    throw new RuntimeException('FAIL cross-company bank token accepted');
} catch (DomainException) {
    checkConnector(true, 'new bank connector denies cross-tenant credential binding');
}
try {
    $sync->synchronize(5, 2, 11, 'sicoob', $session->statements,
        $session->fingerprintKey, $from, $from);
    throw new RuntimeException('FAIL provider mismatch staged');
} catch (DomainException) {
    checkConnector(true, 'new bank cannot write movements under the Sicoob provider ID');
}
checkConnector((int) $db->query('SELECT COUNT(*) FROM lcc_banking_connectors_entries')
    ->fetchColumn() === 1,
    'provider plug-in never creates native Akaunting financial transactions');
echo "Provider extension contract passed with a separate synthetic institution.\n";
