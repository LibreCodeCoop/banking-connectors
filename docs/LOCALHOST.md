# dedicated localhost homologation

The Banking Connectors development environment is a **separate Akaunting 3
installation** in the dedicated local environment, with exclusively synthetic business data.
Do not run these commands against production, a shared database, or a
production-like clone containing real financial data.

## Isolated resources

- Module source: `~/projects/akaunting-banking-connectors`.
- Test application: `~/projects/.tmp/akaunting-banksync-local/app-root`.
- Dedicated database: `akaunting_synthetic` on Docker
  `lcc-banksync-local-db`, native `ak_` prefix.
- PHP container: `lcc-banksync-local-php`. Framework and app dependencies
  are mounted read-only from the existing local-test installation.
- Private internal Docker network: `lcc-banksync-local-net`. Database has
  no published host port.
- Local-only HTTP: `http://127.0.0.1:18789`, forwarded in the dedicated local environment loopback.
- Synthetic users: admin of company 2, admin of company 3, restricted user
  of company 2. Their credentials are kept in private `*.local` files with
  mode `0600` in the test directory; never commit or reuse these passwords.
- Sample financial data: two synthetic companies, each with separate
  bank-account bindings and staged bank movements. No real bank statements
  were copied into the synthetic Akaunting database.

The module's migrations were run through the **native Akaunting Artisan
migrator** and verified against the actual prefixed MariaDB schema. The
migration for audit events enforces uniqueness of native match metadata.
The fingerprint-key binding migration prevents a subsequent poll from
silently treating a rotated key as a new bank event identity.

## Verified execution

- Installation of the module for each synthetic company.
- Read-only bank entry staging, replay, corruption conflict quarantine and
  month-bounded cursor recovery; zero native ledger writes.
- Native Blade render with company-specific connection, candidate list and
  review buttons.
- Real authenticated Web GET/POST: session login, CSRF positive/negative,
  valid/invalid match, idempotent review and reopening.
- Real authenticated JSON API GET/POST: Basic auth, company-scope lookup,
  same preview as Web, explicit audit action and idempotency.
- HTTP denial of unauthenticated requests, restricted banking roles, foreign
  company/account connections and invalid native candidate IDs.
- No source fingerprint, source bank ID or provider credential in Web/API.
- Real Web forms for paused connection creation, manual read-only mode and
  persistent Sync Now requests pass session/CSRF, ACL and tenant HTTP tests.
- The scheduler is registered only by explicit opt-in; all test connections
  are paused or not eligible, and no unattended bank polling is enabled.
- A disposable OpenBao 2.7.1 server and real Agent/AppRole/KV v2 delivered
  synthetic credentials, including a successful certificate-key rotation.
- No real OpenBao production configuration, automatic financial import,
  payment, invoice settlement or webhook is active.

## Re-run localhost checks

```sh
cd ~/projects/akaunting-banking-connectors
BASE="$HOME/projects/.tmp/akaunting-banksync-local"

docker exec -e BANKING_CONNECTORS_ISOLATED_LOCAL=yes \
  -e BANKING_CONNECTORS_APP_ROOT=/var/www/html lcc-banksync-local-php \
  php -d session.upload_progress.enabled=0 \
  modules/BankingConnectors/tests/local-readonly-sync.php

BANKING_CONNECTORS_LOCAL_CREDS="$BASE/admin-credentials.local" \
BANKING_CONNECTORS_LOCAL_COMPANY_ID=2 python3 tests/localhost-http.py
BANKING_CONNECTORS_LOCAL_CREDS="$BASE/admin-credentials.local" \
BANKING_CONNECTORS_LOCAL_COMPANY_ID=2 python3 tests/localhost-review-post.py
BANKING_CONNECTORS_LOCAL_FIXTURE_DIR="$BASE" python3 tests/localhost-multitenant.py
BANKING_CONNECTORS_LOCAL_FIXTURE_DIR="$BASE" python3 tests/localhost-api.py
BANKING_CONNECTORS_LOCAL_FIXTURE_DIR="$BASE" python3 tests/localhost-connections.py

# Additional disposable verification (never contacts production OpenBao):
bash tests/run-openbao-agent-local.sh
bash tests/backup-restore-local.sh
bash tests/run-mariadb-isolated.sh
```

These tests do not require the real Sicoob certificates. Additional
synthetic MariaDB and PDO tests are documented in `docs/TESTING.md`.

This environment is intentionally persistent for further development.
Do not remove the app, Docker volumes or temporary credentials while
tests may still be using them. The local-test filesystem has limited free
space; check disk and memory before cloning dependencies or pulling new
container images.

## Production deployment gate

The fact that localhost Web and API tests pass does **not** authorize
production use. Verified locally: synthetic OpenBao Agent credential delivery,
certificate rotation, worker crash/recovery, additive migration and full
backup+restore in a separate MariaDB. Remaining: real production OpenBao
mapping, bank-side certificate authorization, Sicoob rate limits/outages,
separate regulatory and accounting acceptance for native financial writes,
retention policy and operational alerting.

Never change Akaunting core or disable its native auth, subscription checks,
security middleware or company scoping as a workaround for Banking Connectors.
