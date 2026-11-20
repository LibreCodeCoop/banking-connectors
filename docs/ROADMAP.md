# Banking Connectors delivery roadmap

The module is a free alternative to the user-visible banking synchronization
and reconciliation capabilities of Akaunting's Bank Feeds. Each increment
needs its own observable tests and security acceptance. Existing Akaunting
core functionality must be reused, not modified or forked.

## A. Bank statement staging — implemented locally

- Generic `BankStatementProvider` interface and Sicoob statement mapper.
- Strict company/account binding, stable bank identities, monetary normalization.
- Atomic module-owned staging, safe replay, revision conflicts, explicit
  quarantine and isolation across tenants.
- Read-only incremental cursor per connection with 28-day windows,
  per-window persistence, replay after errors, safe status codes.
- A fingerprint-key commitment blocks accidental key changes before a bank
  request. Manual key rotation policy is not yet available.
- Validated with SQLite, disposable MariaDB, native Akaunting migrations and
  a synthetic local app. The mTLS HTTP client was transferred from the
  validated POC to the module, and OpenBao Agent file-resolution and CLI
  credential checks pass with synthetic credentials.

**Remaining before unattended use:** production OpenBao mount/role/secret
mapping and safe operational rollout, bank-side authorization of mounted
certificates, controlled historical backfill, observed Sicoob throttling/outage
handling and retention/alerts. Real OpenBao Agent secret delivery, synthetic
certificate rotation, MySQL crash recovery and separate DB restore passed.

## B. Review and candidate matching — implemented locally

- Read native Akaunting transactions without changing the ledger.
- Match only company/account, currency, direction, exact integer cents
  and a bounded date tolerance; ambiguity is never auto-resolved.
- Native Web page and JSON API share the same preview/matching service.
- Manual accept/dismiss/reopen affects **Banking Connectors metadata only**, with
  immutable-in-application review events and request idempotency keys.
- Native Web session/CSRF, Basic-authenticated JSON API, multi-company
  isolation, restricted-role denial and mismatched-candidate cases passed
  against a synthetic Akaunting installation.

**Implemented additionally:** configurable description-based category rules
with conflict-safe priority, manual category overrides, read-only previews
of linked/ignored movements, explicit re-opening of prior decisions,
bounded matching windows and a separate setup/rules/review navigation.
A narrowly scoped, user-confirmed category-only update on an already
matched, non-reconciled native transaction is available with idempotency
and an audit event, not as automatic financial posting.

**Learning-assisted suggestions:** available immediately, initially in cold
start until human-reviewed labels accumulate in each company/account/direction.
It is intentionally read-only and does not silently set tax categories,
counterparty identities, invoice payments or native ledger entries. A
statistical confidence score is not a substitute for independent validation.
Compare an eventual Rubix ML backend against this in-process probabilistic
baseline on an isolated labeled holdout before enabling automatic writes,
even to staging.

**Remaining:** pagination beyond the first 50 per filter, richer transaction
search beyond exact amount/account/direction constraints, comprehensive
reconciliation of transfers, refunds, partial invoice payments and historical
snapshots, accountant verification and independent visual/accessibility review.
Automatic native posting is available per authorized connection, disabled by default; document settlement and transfer pairing remain explicit exceptions.

## C. Native financial posting — implemented with per-bank approval

- Native Banking → Transactions shows imported bank movements alongside a
  familiar native ledger interface, with exception filters for unmatched,
  uncategorized, conflicting, ignored and posted records.
- Disabled by default for existing bank connections. A user with native
  transaction create and bank administration permissions explicitly enables
  posting for a verified, active, BRL bank connection. Activation records
  a bank-source high-water mark and UTC timestamp so existing historical
  movements cannot be posted retroactively. Late historical backfill is
  also kept in the review queue.
- The publishing service evaluates each source movement against a bounded
  company/account-scoped search of native income, expense and transfer legs.
  Native candidates, conflicting bank revisions, possible internal transfers,
  and likely open invoices/bills are **held**, never automatically duplicated.
- New unambiguous movements use the native Akaunting CreateTransaction job,
  native income/expense categories, and a source-to-native unique binding
  committed in one database transaction. Missing categories become
  dedicated A classificar native categories, visible under Banking → Transactions.
- Current publishing state and fixed incident codes are visible to reviewers.
  Deterministic rollback-only synthetic integration proves native creation,
  replay safety, foreign-company rejection, duplicate/transfer/document
  restraint and preservation of user financial data.

**Deliberate boundary:** income/expense creation does not settle documents,
reconcile a transaction, select tax rates or initiate payments. Transfer
pairs require human confirmation of both owned accounts; a guessed description
does not justify creating two legs. Production enablement should follow a
dry-run, accountant review of historical entries and verified backup/restore.
Existing bank polling and imported source evidence remain intact.

## D. Operational Sicoob parity — partially implemented

- Implemented: read-only Sicoob HTTP client reused from the validated POC,
  company/account-scoped secret file contract for OpenBao Agent, local X.509
  verification, explicit polling CLI and MySQL named locks per connection.
- Implemented: disabled-by-default registration of the existing Akaunting
  scheduler with a 15-minute polling interval and safe cursor replay.
- Implemented: Web connection management, manually queued Fetch Now with
  idempotency and CSRF, bounded failure backoff and safe status reporting.
- Verified in disposable tests: real OpenBao Agent, AppRole, scoped KV v2,
  synthetic mTLS rotation, killed-worker transaction rollback, independent
  MariaDB restore and migration upgrade retaining prior bank movements.
- Implemented: typed Sicoob 401/403/429/5xx/network failures, bounded HTTP
  Retry-After and fail-safe cursor handling; credential-free machine-readable
  `banking-connectors:health --strict` for Zabbix/other monitor integration.
- Remaining: production OpenBao role/path mapping and Sicoob-approved mounted
  certificates; provider-verified quotas and actual outage recovery; activate
  external alert delivery, retention approval and production deployment gates.
  Unattended bank polling is disabled by default at install; production
  operators can explicitly enable the validated connection and scheduler.
- Provide safe user-facing disable/reconnect and historical resume flows;
  the CLI initial backfill already requires an explicit start date.
- Validate real Sicoob throttling, outages, malformed responses and expired
  certificates, with no lost movement IDs.
- Rehearse backup and **restore**, migration upgrades, partial failure
  recovery, and operational rollback before production rollout.

## E. Additional banks — after Sicoob stabilizes

Implement adapters behind the same provider-independent domain interfaces,
checking each bank's native transaction identity, currency conventions,
payment semantics, rate limits and availability. Candidates include other
Brazilian banks and import formats (OFX/MT940), with future Open Finance
integration only through compliant consent and eligible participants.

## Optional Pix R$1 experiment

A voluntarily authorized, uniquely identified Pix transaction may test
provider event delivery and timing. A dynamic PSP charge with `txid`
can correlate *that one payment*, not all incoming bank movements.
Do not enable money-moving webhook handlers, automatic refunds or native
posting without a separately reviewed authorization/security contract.

Webhook experiments are independent of statement-based polling.
