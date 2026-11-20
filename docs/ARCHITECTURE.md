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
  `ReviewPreview` and `ReviewDecisionService`. Exact amount, direction,
  account, currency and near date suggest eligible candidates; they never
  auto-confirm. The account Web view offers 3/14/45/90-day discovery windows,
  keeps completed/ignored entries visible and lets users retract links.
  The default JSON preview contract remains pending-only.
- **Review events**: `accept`, `dismiss` and `reopen` update only Banking Connectors
  state, with idempotency keys and append-only application-level audit events.
  A unique constraint prevents two bank movements in the same company/account
  from claiming the same native transaction ID.
- **Learning-assisted review**: the private, per-account Naive Bayes text
  baseline trains solely on the latest manual category audit per staged entry,
  using tokenized descriptions and debit/credit direction. It is read-only
  and automatically reflects later human corrections. Model strengths are
  not calibrated probabilities; an insufficient, one-class, ambiguous or
  unrecognized sample yields no prediction. The default is **abstention**,
  never unattended native accounting writes.
  Exact whole-name candidates from the same company's active customer/vendor
  registry are presented as unverified counterparties only (without CPF/CNPJ
  lookup); the native read-sales-customers/read-purchases-vendors permission
  must be present to query the corresponding contact type. A customer credit
  does not by itself prove an invoice is paid.
  An external Rubix ML dependency was deliberately not introduced at this
  stage: the historical labeled data are not yet sufficient to benchmark
  Rubix against the auditable local baseline or justify a larger dependency
  graph. Benchmark replacement models with holdout data before promotion.
- **Native posting**: once separately enabled for a verified bank connection,
  scheduled polling may call the native Akaunting `CreateTransaction` job for
  an entirely new, settled and unambiguous movement. Activation writes a
  per-connection high-water ID and UTC timestamp, and the publisher only
  considers bank entries first discovered *after* those values, with recent
  movement dates. Previously staged evidence remains in the review filters
  without causing financial backfill. A unique
  company/account/source-to-native link is committed atomically with the
  native transaction. Replays cannot create duplicates. Pending invoices,
  bills, existing transactions (including transfer legs), unknown counterpart
  facts, potential internal transfers, and incomplete candidate searches
  are held for human attention. Unclassified posted movements use a dedicated
  income/expense **A classificar** category so Akaunting reports remain
  consistent with its required non-null category model. No document is marked
  paid or native reconciliation marked complete merely by import.
- **Native transaction UI**: an Akaunting view composer augments the existing
  Transactions content component, preserving the native Income/Expense/All/
  Recurring tabs and adding one **Bank movements** tab. Both lists use
  Akaunting's own search-dropdown component: `bank_status` on native ledger
  records and `bank_movement` on stage rows, with the existing account filter.
  SearchStringApplying extends only the authenticated Transactions route and
  applies company/account-scoped SQL EXISTS predicates *before native
  pagination*, while the ordinary search manager strips the module-only token
  before parsing core columns. This prevents invalid synthetic column
  references and preserves native search and totals.
  Native transaction row actions are enriched in the existing `x-table.actions`
  hover menu, with one batched company-scoped query for the current page;
  linked rows show a bank-evidence marker and a bounded source description
  under their account name. The Bank movements tab renders through the
  **same `x-transactions.index.transaction` component and same table Blade**
  as the real financial list; a source-aware view composer restores the
  scoped bank rows after Akaunting's `setParentData()` inherited the parent
  native ledger collection. Without that step, the bank tab would
  misleadingly render native records as if they were bank source rows.
  The bank row presenter maps dates, credit/debit, category, account,
  contact/document where actually linked, and amount into the native columns.
  Bank-source review actions use the original `x-table.actions` control and
  only expose print/PDF where a real native transaction is present.
  The existing `x-tabs.nav-pin` handles the default bank tab; its
  `bank_movement:needs_action` search criterion is visible in the native
  search field, alongside supported financial filters.
  Stage rows never become financial ledger rows
  until safely published or linked. The two overridden view components are
  copied from the project's current native templates because Akaunting does
  not expose a stack insertion point for an extra tab or row marker; upstream
  updates to those components require a compatibility check.
  Account integration remains limited to setup, security, polling and rules.
  All UI and query extensions require banking read ACL and company membership.
- **Classifications**: a bounded, validated `bank_description` is extracted
  from the Sicoob description field. Per-company/account category rules use
  case/accent-normalized `contains`, debit/credit direction, enabled flag
  and numeric priority. Exact same-priority category conflicts return no
  suggestion. Rules are advisory for existing transactions and can provide
  a category for a newly published entry only when native publishing is
  independently enabled. Rules never override a manually set category.
  Operator classification and optional category-only changes to eligible
  existing native records are audited separately.
- **Narrow native category edits**: an authenticated reviewer can explicitly
  request category-only change for an already reviewed, manually linked and
  non-reconciled income/expense transaction. The service verifies amount,
  direction, currency, company/account, the previously displayed native
  category, document linkage and concurrent changes. One SQL transaction
  changes only native `category_id`/`updated_at` plus staging/audit rows.
  This narrow direct SQL operation deliberately avoids Akaunting's broad
  UpdateTransaction job (which may modify attachments/tax relationships).
  It does not run the model's update events, so integrations depending on
  those events must use Akaunting's native edit screen instead.
- **Accounting operations deliberately excluded**: no automatic invoice
  settlement, tax determination, funds transfer, bank-initiated payment or
  write to an existing invoice/transfer; these require independently
  verified identity, accounting facts and explicit user review.

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
- Native transactions may be created automatically only under the
  **explicitly enabled per-connection posting policy**, from trusted
  settled bank movements, through Akaunting's native CreateTransaction job.
  Existing records and document settlement are never changed automatically.
  A user-confirmed, audited category-only edit of an eligible unlocked
  existing transaction remains supported.
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

There are no public scheduler routes, bank-side payment routes, webhook
handlers or credential provisioning endpoints. Admins may explicitly
toggle per-connection native publishing with a CSRF-protected endpoint.
The server-side commands are `banking-connectors:poll`,
`banking-connectors:poll-all` and `banking-connectors:publish`.
All bank transport remains read-only; only a verified, approved
Akaunting-side posting policy may create native income/expense records. Credential files
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
Native financial publication is disabled for each installed connection until
authorized, and production enabling requires independent review of unmatched
historical movements and recovery/rollback readiness. Source-to-native IDs,
issue states and audit records survive module rollback. The bank transport
remains read-only.

## Pix R$1 experiment

A voluntary small-value Pix can validate delivery of a specific bank event.
A PSP-issued dynamic Pix charge with a `txid` may correlate that particular
payment when the contract supports it; it cannot identify unrelated bank
movements. Never infer legal ownership or authorize bookkeeping based solely
on amount/date or a R$1 transfer.
