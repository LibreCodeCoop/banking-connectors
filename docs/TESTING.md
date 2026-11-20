# Verification guide

Banking Connectors is tested against disposable and isolated datasets. Never run these
scripts against an Akaunting financial production schema or with real customer
data. The local fixtures hard-reject unexpected database names, prefixes or
network hosts.

## PHP domain and database unit tests

Use PHP 8.2+ and PDO SQLite. All values are synthetic:

```sh
php tests/contract.php
php tests/bank-details-sqlite.php
php tests/pix-enrichment.php
php tests/single-entry-review-sqlite.php
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

Without host PHP, run the tests inside a disposable PHP CLI container with
the project source mounted read-only. See `README.md` for prerequisites.

`staging-sqlite.php` checks transaction rollback after a synthetic database
failure, replay idempotency and tenant bounds. `read-only-sync-sqlite.php`
tests month boundaries, cursor persistence, replay after failure, paused
connections and HMAC key-binding safeguards. `review-decision-sqlite.php`
checks append-only decision events, idempotency, invalid candidates,
ambiguous matches, unique native match constraints, reversible reviews and
zero native ledger writes.

## Disposable MariaDB

```sh
bash tests/run-mariadb-isolated.sh
```

The runner creates a private database container with random synthetic
credentials, no published ports and a tmpfs data directory. It automatically
removes the container on exit. The underlying test refuses to create its
schema when the database is nonempty. The runner also forcibly terminates a
worker holding a MySQL named lock and an uncommitted staging transaction,
then verifies rollback, lock release and successful replay by a second worker.

## Native Laravel migration

`tests/laravel-migration.php` exercises the actual Laravel migration against
in-memory SQLite, using a read-only mount of the Akaunting framework/vendor
libraries. It is not a substitute for a complete installed Akaunting test.

The local-test `localhost` installation uses the complete Akaunting 3 installer,
separate MariaDB and the `ak_` prefix. Test only on that isolated app:

```sh
cd ~/projects/akaunting-banking-connectors
docker exec -e BANKING_CONNECTORS_ISOLATED_LOCAL=yes \
  -e BANKING_CONNECTORS_APP_ROOT=/var/www/html lcc-banksync-local-php \
  php -d session.upload_progress.enabled=0 \
  modules/BankingConnectors/tests/local-readonly-sync.php
```

Other installed-app fixture and view tests:
`local-akaunting-integration.php`, `local-view-render.php` and
`local-tenants.php`. All are guarded to require the isolated installation.

## HTTP Web/API

On local-test only, with `http://127.0.0.1:18789` responding and synthetic
credentials in private local `.local` files:

```sh
BASE="$HOME/projects/.tmp/akaunting-banksync-local"
BANKING_CONNECTORS_LOCAL_CREDS="$BASE/admin-credentials.local" \
BANKING_CONNECTORS_LOCAL_COMPANY_ID=2 python3 tests/localhost-http.py
BANKING_CONNECTORS_LOCAL_CREDS="$BASE/admin-credentials.local" \
BANKING_CONNECTORS_LOCAL_COMPANY_ID=2 python3 tests/localhost-review-post.py
BANKING_CONNECTORS_LOCAL_FIXTURE_DIR="$BASE" python3 tests/localhost-multitenant.py
BANKING_CONNECTORS_LOCAL_FIXTURE_DIR="$BASE" python3 tests/localhost-api.py
BANKING_CONNECTORS_LOCAL_FIXTURE_DIR="$BASE" python3 tests/localhost-connections.py
```

These are real HTTP calls against authenticated Akaunting routes. They test
Web CSRF, connection creation, state transitions, queued manual fetch and
failure backoff, review/reversal, API Basic auth, restricted roles,
a second synthetic company, cross-company connection IDs and matching JSON
results. The test suite must not present a 302 followed by a successful
authorized page as direct success of a denied request: negative tests inspect
the initial status or use Akaunting's native JSON error representation.

## Real OpenBao and database disaster-recovery exercises (synthetic)

On local-test, run `bash tests/run-openbao-agent-local.sh` to start a disposable
OpenBao 2.7.1 KV v2 + AppRole + Agent, verify the synthetic account-scoped
policy, render six private credential files, reject an unmatched mTLS key and
accept a rotated pair. The test does **not** contact production OpenBao.

Run `bash tests/backup-restore-local.sh` to dump the complete synthetic
Akaunting database, restore into a *different* disposable MariaDB and compare
native-financial and Banking Connectors checksums/constraints. The test never overwrites
its source database. Additive migration upgrades are checked by
`tests/laravel-migration.php` against populated earlier tables.

`tests/sicoob-retry-policy.php` injects synthetic HTTP status responses into
only the tested Sicoob transport. It verifies 401 renewal, 403 denial,
429 Retry-After, 5xx, network loss, body redaction and invalid JSON without
calling the bank. `tests/read-only-sync-sqlite.php` checks that a typed HTTP
429 propagates through staging without advancing the cursor.

`tests/health-snapshot.php` verifies that a read-only health monitor detects
stale successful reads, overdue manual requests and repeated failures while
excluding paused connections. Check the installed application with
`php artisan banking-connectors:health --strict` (returns nonzero if intervention is
required). This is an integration point for monitoring, **not** evidence that
Zabbix alert delivery has already been configured.

The combined local regression is `bash tests/run-local-verification.sh`.

`tests/native-posting-decision.php` covers duplicate protection, incomplete
candidate reads, transfers and document holds. `tests/local-native-publication.php`
runs the real Akaunting CreateTransaction job in a synthetic rollback-only
MySQL transaction. It checks exact amount/currency/company/account,
activation watermark exclusion of historical movements, provisional
categories, immutable source linking, replay idempotency,
foreign company denial, existing native payments, transfers and a synthetic
unpaid invoice. Every write, including the fake invoice and account, is
rolled back before the test returns. `tests/localhost-native-transactions.py`
logs into the full synthetic Akaunting Web application and checks that the
existing native transaction tabs, native search dropdown and financial action
menu survive alongside the additional Bank movements tab. It verifies
identical headers generated by the **same native transaction table component**,
the native pin control, real bank-source row IDs rather than misleading
inherited financial rows, account subtitles and the original actions control.
It parses the Vue search component's JSON filter definitions, verifies
`bank_status`, `bank_movement` and the native Type, Account, Date,
Currency, Category and Contact choices. Native filters are exercised via
authenticated HTTP with real SQL result-set comparisons, including native
sort-link navigation, invalid values, legacy bookmarks, CSRF and role denial.
`tests/localhost-native-linked-actions.py` attaches one scoped bank-source
event to an existing *synthetic* native transaction, then checks its linked
marker and hover menu, observes correct native SQL filter results and verifies
a deep link to the exact bank event. The synthetic stage and audit rows are
cleaned and their absence checked after the run; native ledger records are
not altered. The native publisher's production flag remains unchanged.
`tests/category-learning-sqlite.php` verifies supervised feedback, conservative
abstention, unknown words, opposite-direction behavior, unique counterparty
matches, ambiguous names, company/account isolation and correction of old
training labels. `tests/localhost-learning.py` seeds and removes explicitly
synthetic category audit entries in a separate private Akaunting instance and
confirms that a real authenticated Web page shows an explained category
suggestion without native financial writes. The training rows and fake
categories are cleaned even if the UI assertion fails.

`tests/category-workflow-sqlite.php` verifies category suggestions, disabled
rules, manual overrides, idempotency, stale category checks, tenant isolation,
and the deliberate native category-only write. `tests/localhost-classification.py`
exercises the authenticated setup/rules/reconciliation UI, including correct
category direction, CSRF, restricted role and second-company rejection.
The synthetic migration test preserves existing bank movements across the
additive classification migration; native ledger count, types and amounts
must remain unchanged throughout the full regression.

The per-account action dropdown is tested by
`tests/localhost-account-navigation.py` under authenticated synthetic users,
including linked accounts, onboarding links, existing bookmarks, URL scope,
unauthorized users and cross-company negatives. The main sidebar is not
modified by this module. The HTTP connection-control test checks
that a synchronous/test queue does not make a bank request inside the PHP Web
worker. `tests/local-queued-poll.php` rejects forged company IDs, stale
request keys and nonexistent connection IDs before any bank call; the
positive `tests/local-queued-positive.php` temporarily creates a scoped
request in a **synthetic transaction**, mocks the bank CLI command and rolls
back the fixture. `schedule:list` also verifies the opt-in pending-only
one-minute fallback and the 15-minute unattended polling cadence.
A live Redis worker with bank credentials remains a separate production
homologation requirement.

## Optional Sicoob read-only contract

Only on a workstation where the separate local mTLS POC credentials are
available, `tests/live-sicoob.php` exercises the **module's** Sicoob HTTP
client and stages movements twice in disposable in-memory SQLite. This is
not evidence that the OpenBao Agent delivered the credentials. Never copy bank
credentials or raw statements into this repository. No transfer, Pix payment,
native financial posting or webhook is performed.

## Known validation gaps

A passing local suite does not establish production readiness. Remaining
evidence includes network failure/recovery with real bank throttling,
live Sicoob throttling/outage behavior, real production OpenBao mapping,
authorized certificate delivery, certificate renewal and deployment rollback;
synthetic crash recovery, OpenBao Agent rotation and backup/restore/upgrade
have passed. Native financial publishing now exists behind a disabled-by-default
per-connection policy. Its conservative bookkeeping contract and rollback-only
Akaunting domain test have passed; activation with real historical data still
requires a reviewed dry-run, tenant-isolated database backup, scoped financial
authorization and an operational rollback plan.
MCP route discovery alone does not prove the MCP is functional.
