# Native Akaunting bank feed

## Where to work

Open **Banking → Transactions** for regular financial work. The Banking
Connectors panel is integrated with the native page, and the default tab
continues to show Akaunting's own financial transactions and totals.
The adjacent bank-feed filters expose source movements that have not yet
become native transactions; this separation prevents an imported statement
from being counted as posted financial income.

- **Needs action**: unlinked bank movements, source conflicts and posted
  movements still in the dedicated provisional category.
- **Uncategorized**: pending imported movements lacking a category, or
  published native transactions in the provisional category.
- **Unmatched**: pending movements with no accepted native link. Check
  suggestions and document information before creating a new entry.
- **Conflicts**: the bank returned changing facts for an existing source ID.
  Do not force reconciliation before verifying the bank records.
- **Imported from bank**: all staged source movements, including reviewed,
  posted and ignored.
- **Posted to ledger**: movements that created Akaunting native
  income/expense transactions.
- **Linked**: accepted matches with an existing native transaction.
- **Ignored**: deliberately dismissed imports; bank evidence remains.

You can open the native transaction for any linked/posted row, adjust its
category when the existing record is unlocked and permitted, or open the
bank review for an ambiguous or unlinked movement. Status reasons are
displayed as fixed, localized codes without exposing bank credentials or
provider identifiers.

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
including upgrades. An authorized company administrator with permission
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
