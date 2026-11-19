# Banking synchronization architecture

Banking Connectors provides a free, provider-independent way to read bank statements,
compare them with Akaunting records and record human review decisions. It
does not alter Akaunting core or claim to settle invoices automatically.

## Boundaries and data ownership

- **Bank transport**: a scoped, injected `BankStatementProvider` reads
  settled movements. The read-only Sicoob HTTP client is derived from the
  validated POC. An OpenBao Agent can supply its OAuth/mTLS material through
  a private file mount; secrets remain outside the module and its database.
  Local X.509 expiry and private-key pairing are validated before outbound
  requests; the bank still determines whether a certificate is authorized.
- **Normalized movement**: provider, company, Akaunting account, bank source ID,
  date, direction, currency and integer cents. Missing stable IDs and partial
  bank responses block automated staging.
- **Staging**: Banking Connectors-owned connection and entry tables. Each source
  fingerprint is unique for one connection; bank revisions become unresolved
  conflicts instead of silently overwriting financial facts.
- **Read-only incremental polling**: inclusive, bounded date windows, durable
  last-completed-day cursor, replay-safe ingestion and safe error codes.
  Cursor advancement is after each fully staged window, never before.
  The Web UI persists explicit company-scoped requests in the Banking Connectors
  connection record without contacting the bank from PHP HTTP workers. When the
  Akaunting queue driver is asynchronous, the controller dispatches a single
  scoped job for a newly queued request; the job revalidates company, connection,
  request key, state and retry window before calling the same CLI domain command.
  Under a synchronous/test queue driver it does not dispatch any bank network
  request. The opt-in scheduler handles missed manual jobs every minute and
  automatically enabled connections every 15 minutes. An explicit CLI command
  holds a MySQL named lock per connection. Failures retain the request and
  impose bounded exponential backoff. Both opt-in cadences reuse Akaunting's
  scheduler; no new daemon is installed. Sicoob HTTP
  401/403/429/5xx and network outages are classified without leaking the
  response body. HTTP Retry-After is bounded to 1 hour and applied to the
  persisted backoff; non-retryable 403 is not retried inside one bank call.
- **Native read boundary**: candidate transactions come from Akaunting's
  company/account-scoped `transactions` table. The reader excludes deleted
  records, recurring parents and split children, and rejects truncated or
  malformed datasets before declaring a movement unmatched.
- **Human review**: the native Web page and JSON API call the same
  `ReviewPreview` and `ReviewDecisionService`. Equal amount, direction,
  account, currency and near date suggest candidates; they never auto-confirm.
- **Review events**: `accept`, `dismiss` and `reopen` update only Banking Connectors
  state, with idempotency keys and append-only application-level audit events.
  A unique constraint prevents two bank movements in the same company/account
  from claiming the same native transaction ID.
- **Accounting operations (not implemented)**: future native Akaunting
  services must validate historical audit decisions again immediately before
  any financial write. A review decision is **not** a native reconciliation,
  receipt, invoice settlement, payment or ledger mutation.

The bank is authoritative for settlement and bank movement identity;
Akaunting remains authoritative for accounting classification, documents,
contacts and all native financial state.

## Identity, keys and durable evidence

Source fingerprint: HMAC-SHA-256 of provider, company, account and the stable
bank movement ID. Content fingerprint: keyed hash of normalized immutable
financial fields. Neither raw bank IDs nor raw bank responses are stored.

A connection pins the HMAC key using a one-way commitment:
`HMAC-SHA-256(secret, "banking-connectors-stable-key-binding-v1")`. A new connection
can establish this commitment before its first read. A key change is rejected
before contacting the bank. Existing staged rows with no trusted commitment
must be migrated explicitly after verifying the original key; the module does
not silently accept an unknown replacement. Key rotation while retaining
bank identities remains a separately audited migration procedure.

The staging and review-event tables are module-owned and intentionally not
dropped by automatic migration rollback. The default retention rule is
**preserve audit evidence; never automatically delete financial movement
identities, review decisions or backups**. The module does not store raw
provider payloads or bearer tokens. An explicit, accountant-reviewed
export/retention/purge policy is required before a destructive workflow exists. Human review events are append-only
through the application service; they are not cryptographically tamper-proof
against a database administrator.

## Security contracts

- Akaunting's native Web admin middleware handles session authentication,
  company selection and CSRF. Controllers require read permissions for
  `banking-transactions` and `banking-accounts`; review POST additionally
  requires `update-banking-transactions`.
- API uses Akaunting's existing API authentication and company-selection
  middleware (Basic or supported OAuth). The same read/update permissions are
  rechecked in the API controller. Company and account are NEVER trusted from
  a caller-supplied financial object; the authorized connection lookup binds
  account ID and current company.
- The module exposes credential-free connection metadata and sanitized review
  data; no raw bank IDs, keys, fingerprints or bank payloads in Web/API.
- Idempotency keys are required for all manual review operations. A replay
  returns the previously recorded result; reuse with different data fails.
- Cross-company access, invalid candidates and conflicting source revisions
  are rejected before any module-owned review state changes.
- No operation creates or alters native Akaunting accounting transactions.
- The Sicoob Pix callback path is deliberately absent. Any future webhook
  must verify provider identity/replay, treating callbacks as hints until
  independently confirmed by bank API.
- Akaunting table prefixes such as `ak_` are supported in migrations and PDO
  access. SQL identifier prefixes are strictly validated.

## Public surfaces

Web: authorized native bank-account dropdown actions pointing to
`GET /{company_id}/banking/accounts/{accountId}/integration`. The previous
`GET /{company_id}/banking-connectors` route stays available for bookmarks,
but no longer has a global sidebar entry. Explicit
`POST /{company_id}/banking-connectors/review-decisions` for audit-only actions,
`POST /{company_id}/banking-connectors/connections` for new paused bindings,
`POST /{company_id}/banking-connectors/connections/{connectionId}/state` for state
changes and `POST /{company_id}/banking-connectors/connections/{connectionId}/request-sync`
for queued read-only manual requests.

API: `GET /api/v1/banking-connectors/connections`,
`GET /api/v1/banking-connectors/preview?connection_id=...`, and
`POST /api/v1/banking-connectors/review-decisions`. API callers supply an Akaunting
company context through the native API contract.

There are no public scheduler routes, financial posting endpoints, webhook
handlers or credential provisioning endpoints. Server-side read-only commands
are `banking-connectors:poll` and `banking-connectors:poll-all`. Credential files
are resolved only from a validated company/account reference; an account
number mismatch aborts before any bank request.

## Test and release boundary

Real Web login, CSRF, JSON Basic Auth, a second synthetic company, foreign
account/connection IDs and a restricted role were tested against a separately
installed Akaunting 3 instance in the dedicated local environment. SQLite/MariaDB test suites exercise
identity, concurrency-relevant uniqueness, cursor recovery, failed bank
requests and review audit invariants. This is *local functional evidence*,
not production authorization.

Disposable OpenBao Agent/AppRole template delivery, certificate/key rotation,
MySQL worker-crash replay, additive migration upgrade and full backup/restore
have been executed successfully on synthetic local fixtures. Production still
requires verifying the actual Vault mapping, Sicoob-side certificate acceptance,
rate limits, outages, alerting, operational rollback and retention rules.
Native financial writes are out of scope until independently approved.

## Pix R$1 experiment

A voluntary small-value Pix can validate delivery of a specific bank event.
A PSP-issued dynamic Pix charge with a `txid` may correlate that particular
payment when the contract supports it; it cannot identify unrelated bank
movements. Never infer legal ownership or authorize bookkeeping based solely
on amount/date or a R$1 transfer.
