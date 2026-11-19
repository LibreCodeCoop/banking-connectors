# Banking Connectors

Open-source bank statement synchronization and auditable reconciliation,
with an Akaunting integration and portable PHP connector contracts.
The public product and Akaunting module are both named **Banking Connectors**:
module alias `banking-connectors` and PHP namespace `Modules\BankingConnectors`.
Sicoob is the first real read-only bank adapter; other banks
can be added through isolated connector directories or separate Akaunting
modules without changing the synchronization core. This project is
independent of Akaunting's proprietary Bank Feeds app.

## Current capabilities

- Company/account-scoped bank identities and read-only Sicoob statement parsing.
- Incremental polling with durable cursor, replay-safe staging, safe error
  codes, explicit CLI commands, opt-in Akaunting scheduling and MySQL locks
  against simultaneous workers on the same bank connection.
- Banking Connectors-owned database tables, strict deduplication, revision quarantine
  and immutable-in-application review-event history.
- Native Akaunting Web and JSON API previews using the same candidate matcher.
- Generic bank connection UI, manual queue and polling scheduler for all
  registered providers, with one canonical command family.
- Web configuration of paused connections, opt-in synchronization, queued manual
  requests and bounded retry intervals; credentials never enter the browser.
- Account-scoped **Bank integration** actions in Akaunting's native bank-account
  dropdown (and **Connect bank** for eligible unlinked accounts), with no
  duplicate global sidebar entry or Akaunting core modifications.
- When an asynchronous queue is available, a user-initiated refresh dispatches
  a company-scoped worker immediately; a one-minute opt-in scheduler processes
  stranded manual requests, while normal automatic polling remains 15-minute.
  Neither HTTP controllers nor webhook diagnostics directly contact the bank.
- Three account-scoped Web sections: **Movements and review**, **Configure integration**
  (non-secret provider, certificate health and read-only synchronization), and
  **Categorization rules** (separate income and expense category selectors).
- Bank descriptions are normalized, bounded and stored only as a whitelisted
  field. Rules propose company/account-specific categories by movement direction,
  bank description and priority; competing equal-priority categories fail closed.
- Manual category overrides win over automated suggestions, even after bank
  replay. Users can filter pending/linked/ignored entries, inspect more
  candidate transactions in bounded date windows, and reopen incorrect links.
- Explicit, idempotent match/dismiss/reopen actions modify only Banking
  Connectors metadata, not invoice settlement or transaction creation.
- A **separate, expressly confirmed option** can change only `category_id`
  on an existing, manually linked, non-reconciled native Akaunting transaction.
  It rechecks company/account, financial facts, existing category, document
  linkage and concurrent changes, then stores an audit event. It never
  confirms bank payment, creates a transaction, or alters amount/date/taxes.
- Synthetic localhost tests for Web authentication, CSRF, HTTP API, tenant/ACL
  isolation, MariaDB migrations and recovery.
- Sicoob HTTP failure classification with bounded Retry-After backoff (429),
  safe error codes (401/403/5xx), and a read-only CLI health check for monitoring.

## Limitations

The Sicoob connector is read-only and the scheduler is disabled by default
until an operator enables a connection. Access credentials, mTLS certificates
and private keys **must be provisioned outside the browser** using restricted
worker mounts or a configured OpenBao Agent; the Web configuration tab never
asks users to paste a certificate password or private key. OpenBao Agent
delivery and synthetic certificate rotation have passed in an isolated
environment. OpenBao provisioning is deployment-dependent and must not be
assumed to be active at every installation.

The category rules run on imported statements, not directly on Akaunting
financial transactions. No system may automatically settle invoices, import
native expenses, infer a tax code, or rewrite already reconciled financial
records on the strength of a rule or a bank webhook. Manual native category
changes require explicit confirmation and a verified existing link.
Production deployments must validate their own backup/restore, accountant
policy, bank rate limits, secret delivery, permissions, monitoring and rollout;
the tests here do not by themselves prove readiness for every installation.

Git deployment: [docs/GIT_DEPLOY.md](docs/GIT_DEPLOY.md) ·
Architecture: [docs/ARCHITECTURE.md](docs/ARCHITECTURE.md) ·
Extending banks: [docs/CONNECTORS.md](docs/CONNECTORS.md) ·
Feature comparison: [docs/PARITY.md](docs/PARITY.md) ·
OpenBao credential delivery: [docs/OPENBAO.md](docs/OPENBAO.md) ·
Local verification: [docs/LOCALHOST.md](docs/LOCALHOST.md) ·
Tests: [docs/TESTING.md](docs/TESTING.md) ·
Monitoring: [docs/OBSERVABILITY.md](docs/OBSERVABILITY.md) ·
Next steps: [docs/ROADMAP.md](docs/ROADMAP.md)

## Quick synthetic tests

Requires PHP 8.2+ with PDO SQLite:

```sh
php tests/contract.php
php tests/bank-connector-plugin.php
php tests/provider.php
php tests/sicoob-http-client.php
php tests/sicoob-retry-policy.php
php tests/health-snapshot.php
php tests/scoped-credentials.php
php tests/mtls-certificate-verifier.php
php tests/native-mapper.php
php tests/staging-sqlite.php
php tests/read-only-sync-sqlite.php
php tests/connection-control-sqlite.php
php tests/review-decision-sqlite.php
php tests/category-workflow-sqlite.php
```

See the testing guide for disposable Docker/MariaDB and full localhost
Akaunting integration tests.

## License

AGPL-3.0-or-later. No proprietary Bank Feeds source is included.
