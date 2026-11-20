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
