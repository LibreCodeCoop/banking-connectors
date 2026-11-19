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
- Explicit, idempotent match/dismiss/reopen actions. These **only** alter
  Banking Connectors metadata: no payment confirmation, invoice settlement, accounting
  transaction creation or changes to the Akaunting core.
- Synthetic localhost tests for Web authentication, CSRF, HTTP API, tenant/ACL
  isolation, MariaDB migrations and recovery.
- Sicoob HTTP failure classification with bounded Retry-After backoff (429),
  safe error codes (401/403/5xx), and a read-only CLI health check for monitoring.

## Limitations

This is **not yet a production-ready bank feed**. The read-only Sicoob HTTP
client has been carried over from the earlier POC. Credentials can be supplied
by OpenBao Agent using private read-only files. Agent/AppRole templating and
synthetic certificate rotation have passed locally; the **production OpenBao
integration is not configured or approved**. The scheduler and queued polling are disabled by
default until the module is configured, and no connection auto-enables. There is a connection-management Web
UI, but no credential-provisioning UI or native financial posting. Do not
copy certificates or tokens into the source repository.

**Do not install in production.** Synthetic backup/restore, migration upgrades
and worker-crash recovery passed. Production still requires authorization and
read-only validation of the actual OpenBao credential mapping, real bank
throttling/outage recovery, retention policies and controlled deployment.

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
```

See the testing guide for disposable Docker/MariaDB and full localhost
Akaunting integration tests.

## License

AGPL-3.0-or-later. No proprietary Bank Feeds source is included.
