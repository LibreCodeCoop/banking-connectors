# Native Akaunting bank feed

## Where to work

Open **Banking → Transactions** for normal financial work. Its native
**Income**, **Expense**, **All transactions** and **Recurring templates**
tabs, financial totals and regular search field remain unchanged.
There is **no separate bank-filter toolbar**.

Inside Akaunting's **existing search dropdown** (on financial transactions),
select **Bank integration status**. Values include **Linked to bank
movement**, **Created by bank integration**, **Provisional category**,
**No statement link** and **Connected bank account**. The native financial
list, sorting and pagination apply these criteria at the database query
level, keeping company and account isolation. A transaction on a connected
bank account without a verified bank link may be filtered without being
mistaken for an unimported financial record.

The same native page has an additional **Bank movements** tab, which starts
with **Needs action** selected. It renders through the **identical Akaunting
transaction-table component**, with the same Date/Number, Type/Category,
Account, Contact/Document and Amount columns, the normal sort headers,
pagination, hover-action menu and the built-in **pin as default** control.
**Bank statement status** appears alongside native filters for Type,
Account, Date, Currency, Category and linked Contact, rather than replacing
them. Native filter combinations execute against the imported-source records
with company/account-bound predicates; unlinked sources have no contact or
financial document. Available statement statuses:

- **Needs action**: pending/unmatched events, source conflicts and
  already published entries still using an *A classificar* category.
- **Uncategorized**: pending imports without a category or native postings
  left in the dedicated provisional category.
- **Unmatched**: no accepted correspondence with a native transaction.
- **Conflicts**: source data changed under the same bank movement identity.
- **All bank movements**: every imported source event, reviewed or pending.
- **Posted to ledger**: source events that created a native transaction.
- **Linked**: manually accepted correspondences to existing native records.
- **Ignored**: explicitly dismissed bank movements retained for audit.

**Bank source movements are not financial transactions until published or
associated with an existing native entry.** They therefore appear in the
bank-movements tab, not as fabricated native income/expense rows.

Every linked native financial transaction displays a bank-link indicator
and the available bank narration under the **Account** name in its normal
row. The *existing* Akaunting hover-actions menu adds
**View bank statement movement** for linked records and **Review bank
movements** for transactions on connected bank accounts. A direct source
link opens the exact, company/account-scoped bank movement; there is no
second actions menu. Bank source rows use the **same native action menu** for inspecting and
reviewing the evidence, opening bank settings and, when a verified native
transaction actually exists, opening, printing or downloading its PDF.
Unposted bank evidence cannot be printed or deleted as if it were a real
financial transaction. Source status, bank narration and any fixed issue
reason appear beneath the account name to support classification and review.
A possible internal transfer links to Akaunting's manual transfer flow.

For an eligible linked or pending movement, category changes remain
permission-gated, idempotent, auditable and scoped to the selected bank
account. Status reasons use localized codes, never provider credentials.
Previously bookmarked \`bank_feed\` URLs still select the bank-movements tab.

## Configure an account

Navigate to **Banking → Accounts**, choose a connected bank account and
select **Bank integration**. This page is for provider/credential health,
connection status, synchronization requests, category rules and
authorization for native posting. Private keys are configured by trusted
administrators outside the browser; they are not pasted into this UI.

The bank's statement API remains read-only and cannot initiate payments.
After the first verified import, automatic statement reads can be enabled.
**Synchronize now** requests a queued refresh; the worker still applies
bank authorization, request throttling, company/account isolation and a
durable cursor. Normal unattended polling is every 15 minutes by default:
the implementation does not promise instantaneous Pix notifications.

**Automatic posting to Akaunting starts disabled on every connection**,
including upgrades. The activation stores the last already-imported source
ID and activation timestamp; these historical movements remain visible for
manual review but cannot become new native entries retroactively. Older
bank events discovered long after activation also require review. An authorized company administrator with permission
to create transactions can explicitly enable it only when the connection
is active, BRL, has valid key commitment and a successful bank read.

## What is posted automatically

When enabled, a verified bank movement with no native candidate and no
possible open invoice/bill is created via Akaunting's own
`CreateTransaction` service. Its amount, date, company, bank account and
direction come from immutable bank facts. If no explicit category rule or
confirmed manual category applies, the native income/expense transaction
uses a dedicated **A classificar** category. This is a real native record
and participates in Akaunting totals.

The source-to-native binding commits atomically and uniquely with the
new transaction. Bank replays cannot create a second native transaction.

A matching existing transaction is never auto-created again. Duplicate
candidates, likely open invoices/bills, potential internal transfers,
missing bank identity, source revisions and incomplete searches are held
for **human review**; none of those conditions proves whether a document
has been paid. Even an exact text match to a contact's legal name is only
a suggested counterpart, not proof of payment or tax identity.

Transfers between own accounts must use Akaunting's native two-legged
**Banking → Transfers** flow. The module recognizes both income-transfer
and expense-transfer native legs for review, but intentionally never
guesses a destination account from free-form bank text.

The AI-assisted categorizer is advisory and needs sufficient labeled
examples. A statistical suggestion cannot mark an invoice paid, alter
taxes, create transfers or change an already reconciled financial record.
An existing native category change requires a separate user save.

## Operational verification and backfill

Before production activation, make and verify a scoped database backup and
run the worker's `banking-connectors:publish <connection> --dry-run` command
with native publishing still disabled. Check whether there are outstanding
financial documents, likely duplicates and transfers; confirm that any
legacy imported movements should become new financial records rather than
another payment for an existing invoice.

After enabling, monitor the native publication summary and the **Needs
action** filter. Errors leave the source movement unchanged and preserve
a safe issue code for retry. Disable automatic posting on the bank
connection if anomalies are observed; this stops new creations but does
not retroactively delete valid financial/audit records.

For an incorrect posted transaction, resolve it using Akaunting's
auditable financial correction workflow; never remove a source-to-native
record or a bank source identity simply to make a new import succeed.

The complete source and native financial transaction restoration should
be rehearsed in a *separate, synthetic* environment. Do not replace
production data with a test fixture.

## Detailed bank movement evidence

The dedicated single-entry review page can display a limited set of additional
fields included in the current-account statement API response (such as a Pix
end-to-end identifier, origin institution, remitter name and document number).
Fields are optional and **must not** be inferred from a Pix description. The
read-only statement endpoint does not guarantee the richer fields of the
separate Pix API; no raw API payload or credential data is retained.

Only the explicitly mapped, bounded fields are stored on the staged entry.
The versioned metadata is scoped to the same company, bank account and
connection as its source movement; the paginated review/API preview
intentionally does not return it. The built-in Sicoob provider owns the
detail labels and groups through the optional `BankMovementDetailsProvider`
contract. No native accounting transaction is rewritten.

Entries imported before this schema change do not contain details. An
operator-authorized statement replay of the original period can enrich a
matching staged item idempotently without duplicating its financial entry.
Do not enable unattended polling or backfill as part of deploying this UI.

If the account has been separately granted Sicoob `pix.read`, a reviewer can
explicitly request additional details using the dedicated detail screen's
"Consultar detalhes Pix" action. This is a queued single-entry operation and
requires a valid end-to-end ID already provided by the statement, so it cannot
infer identities from descriptions or silently batch-scan unrelated Pix.
The lookup verifies the E2E ID, amount and date before enriching metadata;
accounting amounts and reconciliation links are not changed. Configuration
and permissions are documented in `docs/CONNECTORS.md`.

## Combined All Transactions view

The standard Akaunting **All Transactions**, Income, and Expense tabs now
display a server-paginated SQL union of native financial transactions and
**unrepresented** bank-source movements. This is a display-only read surface:
no synthetic ledger rows, invoices or account balances are created.

- Native transactions keep native routes, row actions and financial totals.
- A staged bank movement with a valid live native transaction link is **not**
  shown twice. Unlinked movements are clearly marked as bank sources and open
  the single-entry review screen instead of pretending to be ledger items.
- Company/account isolation and enabled-module native ACL checks apply to both
  sets. Native filters for date, type, account, category, contact, currency and
  amount are translated to the bank-source predicates when meaningful. If a
  filter refers to an unavailable bank-source field, that source side is
  excluded rather than silently ignoring a filter.
- SQL UNION pagination, direction and supported native sorts operate on the
  combined result set, with distinct native and bank row identities. Bulk
  financial actions are disabled on mixed rows to prevent acting on staging IDs.
- Native accounting totals **do not** include unposted bank-source movements,
  even when those movements are displayed next to ledger entries.

### Classification and evidence

The existing company/account-scoped Naive Bayes advisor remains abstention-
first and requires multiple explicit human classifications. It can now learn
from allowlisted bank statement complement, payer message and counterparty
labels in addition to the short bank description. No third-party ML service
receives bank data, and generic `Pix recebido`/`Pix emitido` by themselves
remain non-discriminating. Suggested categories are not committed to the
financial ledger without reviewer action.

Exact provider document numbers can prioritize a unique native transaction
reference when its company, account, currency, direction, amount and date
already match. This never settles or reconciles an invoice automatically,
and ambiguous/reused document numbers remain unconfirmed.

Run isolated HTTP acceptance checks against the documented synthetic fixture:

    BANKING_CONNECTORS_LOCAL_FIXTURE_DIR=.../akaunting-banksync-local \
      python3 tests/localhost-combined-transactions.py

Native Akaunting currently has a pre-existing document-number sorting defect
in the tested version, before the module's table composer is invoked; this
sort mode is not claimed to be fixed by the combined view.

## Dedicated single-movement review and navigation

The entry detail URL is a separate Blade view, not a bank queue with an
entry filter. It does not show queue counters, account configuration, or
repeat review filters. The same extracted movement-card partial keeps the
classification and reviewed-link workflows compatible with queue review.

Every native transaction-list action attaches the original same-company list
URI (including filters, sorting, pagination) as an optional `from` parameter.
Only the exact same-company `transactions.index` path passes the return-target
validator; external URLs, cross-company paths and control characters fail
closed to the native transactions index. POST categorization and reconciliation
retain this origin when returning to the detail page. The secondary association
and ignore actions are collapsed in the individual view, leaving the primary
classification action visible. Connection configuration remains on its
dedicated page.

## Candidate invoice matching and classification assistance

On a single credit movement, users who have the **separate native invoice-read
permission** can see a bounded list of candidate open sent invoices. This
read-only advisor only considers the same company, BRL, exact full amount and
an eligible issue date. Partial/draft/paid/cancelled invoices are intentionally
excluded: the total of a partially paid invoice is not its outstanding balance.

A structured bank payer name that exactly matches an invoice contact is
stronger evidence than an invoice number in a free-text payer message; amount
alone is always marked **weak**. Multiple plausible invoices are never
treated as unique. If exactly one strong payer+amount candidate exists and its
income category is still accessible, that category is pre-selected for
explicit confirmation by the reviewer; it is not silently persisted.

An invoice link opens the native Akaunting invoice view. Neither the advisor
nor the existing Naive Bayes classifier records invoice payments, adjusts
account balances, accepts arbitrary bank names as legal identity or writes
to accounting. The classifier still requires sufficient audited examples;
with fewer than 12 examples it must abstain. The next major financial feature
would require a separate transactional settlement contract with verified
identity, partial-payment handling, concurrency protection and idempotency.

## Inheriting classification from a verified native association

The bank movement's **suggested category** can now be taken from a unique,
company/account/direction/amount/date-compatible native transaction. This is
only a candidate, not evidence of who sent or received the Pix. The review
screen displays the native category and, with `read-purchases-bills` access,
links to an already-paid bill behind that transaction. A matching amount alone
never marks a document paid and never authorizes association.

When the reviewer explicitly confirms the source-to-native link, the review
service copies the live native category to the previously uncategorized bank
movement in the **same audited, idempotent staging transaction**, provided that
category is still enabled, belongs to the same company and has the correct
income/expense direction. The original native transaction and bill remain
unchanged. Existing manual or rule classifications are not overwritten. A
reopen removes only a category that came from the retracted native link; a
manual category is preserved.

The local supervised model can also learn from this additional class of
**human-confirmed native association** while the native transaction's current
category still agrees with the staged label. Unreviewed suggestions, stale
links, revoked categories, inactive or mismatched directions, and automatic
native publication are not training labels. The original minimum example
thresholds and uncertainty abstention remain unchanged.

### Observed data limitations

Read-only diagnostics of the current Sicoob statement identified exact-amount
existing native bill transactions that can be proposed for user confirmation,
but stored statement metadata has no verified Pix recipient identifier on
those debit entries, and their statement document number does not match the
native transaction reference. Consequently no new **unattended** source-to-
ledger association is enabled. The same restriction applies to client receipts
without a verified structured payer: a same-amount invoice outside the
settlement date window must not be treated as a match, even for a known client.
The cooperative-production system creates Akaunting bills, but this alone
does not prove a bank movement settled a particular bill.

## Provisional Pix-to-bill relation using a masked CPF

On a **single outgoing Sicoob Pix**, the bank's allowlisted statement
`complement` may present a recipient CPF as `***.123.456-**`. This is a
masked **CPF display**, not necessarily a Pix key and not a bank-certified
identity. The provider-specific parser requires a recognizable `PAGAMENTO PIX`
source and exactly one complete CPF mask; a raw description, partial pattern
or unsupported bank/provider cannot establish identity. Only the **six middle
digits** of a valid full CPF are compared. The displayed percentage is the
number of **visible digits matching**, not a calibrated probability that the
payment belongs to that person. A mask can match multiple contacts.

When the authorized reviewer opens a pending bank entry, a read-only advisor
revalidates the company/account-scoped bank source and compares the mask with
the document's historical and current vendor CPF. It also requires the same
company, BRL, exact amount in cents, paid bill status and an existing native
expense payment on the connected bank account within **14 days** of the bank
movement. More than one eligible bill/payment, a changed contact identity,
an already-linked native payment, excessive/truncated result sets or a stale
movement cause the proposal to abstain. Contact-mask collisions are shown
explicitly, not concealed by the matching percentage.

A qualifying document appears as an **automatically calculated provisional
link** with its native bill URL and the existing payment reference. It is
recomputed on every individual view, not stored as a confirmed association.
This cannot mark a bill as paid (it is already paid), create a new payment,
settle an invoice, set `linked_transaction_id`, alter a ledger category or
train the classifier. Existing native-association confirmation revalidates
the transaction again and remains a separate user action.

The lookup only runs when the signed-in reviewer has **both**
`read-purchases-bills` and `read-purchases-vendors` permissions; it does not
return or log an unmasked CPF. No DICT probing, third-party AI service,
financial mutation, production resync or new database migration is required.

For automatic, **confirmed** settlement, a separate bank-origin proof of the
recipient/payee identity and a transactional payment idempotency contract are
necessary. Six visible CPF digits and a coincident amount alone are not such
proof.
