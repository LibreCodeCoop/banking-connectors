# Feature comparison with Akaunting Bank Feeds

This matrix compares intended user-facing behavior with implemented free
Banking Connectors contracts. It does not claim production parity solely because a
route or a database table exists.

| User-facing capability | Banking Connectors implementation | Current status |
| --- | --- | --- |
| Select bank and connect account | Company/account-scoped paused bindings and Web forms | Paused Sicoob connection creation passes authenticated Web tests; external credential provisioning remains operational |
| Select historical start date | Date-bounded provider fetch with inclusive cursor | Web manual Sync Now accepts explicit start date and queues a read-only job; live bank backfill not yet end-to-end |
| Daily scheduled fetch / Fetch now | Scoped asynchronous job for Web requests, one-minute opt-in recovery, MySQL lock and opt-in native 15-minute background sync | Authenticated Web actions, worker routing and synthetic OpenBao Agent integration passed; no unattended production polling |
| Last fetch, expiry and errors | Connection metadata, error status and CLI health check | Safe error status, last success, queued requests and retry backoff tested; proactive certificate-expiry alerting not integrated with production Zabbix |
| Read bank credits/debits | Provider-specific mapper to normalized bank entries | Module HTTP client validated against real Sicoob GET, 5 movements, ephemeral staging only |
| Avoid duplicated movements | Unique bank ID HMAC and atomic staging | SQLite/MariaDB and local Akaunting verified; key-change guard added |
| Show pending and conflicting entries | Company/account-scoped review queue and permission-controlled native menu | Native Web page and JSON API tested under authenticated users |
| Suggest existing native transactions | Exact amount/sign/account/currency plus nearby date | Shared read-only matcher; ambiguity requires explicit review |
| Review a suggested match | Idempotent audit-only accept/dismiss/reopen | Web POST with CSRF and API POST tested; NO native ledger update |
| Attach bank event to invoice/bill | Native accounting/document link | Not implemented |
| Create receipt or expense | Native Akaunting financial service after consent | Not implemented |
| Configure default categories/rules | Company-specific deterministic policy | Not implemented |
| Suspend/reconnect bank | Company-owned state transitions and scoped OpenBao Agent file references | Paused/manual/eligible-automatic state tested in Web; real Agent/AppRole and synthetic rotation passed; production mapping remains unverified |
| Additional Brazilian banks | Same provider contract | Framework supports adapters; no additional bank certified |

The current shared service boundary is Web/API, not MCP: an MCP client can
eventually consume these API contracts, but a separate MCP route audit and
end-to-end authorization test remain necessary.

Reference implementations studied for architecture (not copied proprietary
code):

- Akaunting public feature description: https://akaunting.com/hc/docs/banking-feeds-reconciliations/bank-feeds/
- OCA bank statement import: https://github.com/OCA/bank-statement-import
- Firefly III importer and duplicate detection: https://github.com/firefly-iii/docs/blob/main/docs/docs/references/data-importer/duplicate-detection.md
- Maxwbh/cobranca-api: https://github.com/Maxwbh/cobranca-api

Parity must be demonstrated through actual feature execution, financial-state
verification and security/regression tests before production deployment.
