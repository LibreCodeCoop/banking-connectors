# Bank connectors: one isolated implementation per institution

Public product: Banking Connectors, by LibreCode.
Akaunting module: `banking-connectors`, PHP namespace
`Modules\BankingConnectors`. The same name is used throughout the system.

## Architecture

The core owns the durable cursor, idempotence, account/company isolation,
matching and review audit. It does not implement any bank's HTTP transport.

Institution-specific code lives together under Connectors/<Bank>/ or in
a wholly separate Akaunting module. All Sicoob HTTP, mapping, certificate
and credentials code is under Connectors/Sicoob/.

The framework-independent public contracts are:

- BankConnector: id() and open(ConnectionContext $context).
- ConnectionContext: companyId, accountId, providerId, currency,
  accountNumber, credentialReference; no Eloquent models or Web requests.
- ConnectorSession: in-memory BankStatementProvider, stable HMAC fingerprint
  key and optional certificate expiration.
- BankStatementProvider: fetch(companyId, accountId, from, until):
  array of normalized BankEntry objects.
- BankEntry: stable bank-provided movement ID, provider, company, account,
  date, debit/credit, integer minor units and ISO currency.
- BankTransportFailure (optional): a bounded retry delay for errors.

Every new bank implements its own transport, authentication, mapper and
contract. It must validate tenant/account binding and credentials when
open() is called, without making a bank request until fetch() is called.
Never persist tokens, raw responses or HMAC material in Akaunting's DB.

The core denies duplicate connector IDs, unsafe aliases, invalid dates,
ambiguous movement identities, mismatched tenant/account/provider,
unrecognized providers and financial writes. It owns all retry,
idempotence and backoff behavior.

## External module installation

Example source with one trusted registration:

    examples/BankConnectorExample/
        module.json
        Providers/Main.php
        Connectors/ExampleConnector.php

Copy it as modules/BankConnectorExample in the Akaunting module directory.
The manifest declares "requires": ["banking-connectors"]. Its service provider calls:

    $this->app->make(
        \Modules\BankingConnectors\Application\BankConnectorRegistry::class
    )->register(new ExampleConnector());

No Banking Connectors core changes, new routes or migrations are needed. The new
provider appears in the existing Web bank selector, and the generic
banking-connectors:poll command processes its read-only statements. There is no
filesystem scan or arbitrary database-driven class loader.

The example is synthetic, has no network calls and refuses to work
outside APP_ENV=local. Do not install or use it against real finances.

Native Akaunting registration:

    php artisan module:install bank-connector-example 2 en-GB

The worker commands are `banking-connectors:poll` and
`banking-connectors:poll-all`. Financial evidence and Sicoob's provider
identifier remain stable. There is no second technical name for the module.

## Portability and boundaries

Sicoob is restricted to BRL. The domain and connector interfaces allow
other ISO currencies with the correct account mapping. Core statement
logic is independent of Laravel; the Akaunting account, Web/API,
authorization and storage adapters stay in this distribution for now.

To port to another free financial system, implement a new account/tenant,
database and UI adapter, rather than rewrite the banking transports or
provider registry. This avoids adding a second module framework now.

Security note: PHP plugins installed into the same Laravel runtime are
trusted code. The registry prevents accidental loading but cannot sandbox
malicious third-party code with access to the Akaunting process. For
untrusted providers, separate worker processes, credentials and network
policies would be necessary. OpenBao audits AppRole/token identities, not
individual PHP classes running under the same identity.

## Testing

    php tests/bank-connector-plugin.php

The standalone test supplies an independently implemented USD provider,
exercises generic synchronization and retries, preserves tenant identity
and validates HMAC-based idempotence.

For a full synthetic Akaunting installation with the separate example module:

    BANKING_CONNECTORS_LOCAL_FIXTURE_DIR=... \
      python3 tests/localhost-external-connector.py

That test covers UI discovery, authentication, denial of unknown provider
IDs, connection creation, queued manual sync, generic worker execution,
idempotent replay and pausing.

Each real institution requires its own independent read-only contract test:
OAuth/certificates, error and retry codes, stable identities, currencies,
unsettled movements, malformed data, TLS and provider API quotas.
