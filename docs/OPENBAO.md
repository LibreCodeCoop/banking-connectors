# OpenBao credentials for Banking Connectors

Banking Connectors can use the **same OpenBao deployment** as the LibreCode
Akaunting NFS-e module without implementing a second AppRole client, a secrets
database or an independent credential management service.

## KISS decision

NFS-e already uses the generic OpenBaoSecretStore from nfse-php with encrypted
global OpenBao configuration. Do not change that fiscal implementation merely
to add a bank. For Banking Connectors, an OpenBao Agent can authenticate with a
dedicated read-only policy, renew its token and render secrets into private
files consumed by the Banking Connectors worker. These files should be on tmpfs.

Both systems share **OpenBao**, but not the same broad token,
AppRole, permissions or certificate purpose. The existing shared Akaunting
`CredentialVault` module supplies narrowly scoped secret-file validation
and optional KV v2 clients. Its dependency is declared in
`BankingConnectors/module.json`; do not duplicate that functionality in the
Sicoob adapter. An NFS-e PFX password is not automatically the OAuth client
ID or mTLS certificate accepted by Sicoob. Each company/account must have
an independently verified bank-specific binding.

## Exact file contract

For example, with a **synthetic** Akaunting company 2 and bank account 3,
a Banking Connectors connection stores only this logical reference:

    sicoob/company-2/account-3

The configured BANKING_CONNECTORS_SECRETS_DIR contains regular files with no symlink:

    BANKING_CONNECTORS_SECRETS_DIR/
      sicoob/
        company-2/
          account-3/
            client-id
            account-number
            client.pem
            client-key.pem
            client-key-password
            fingerprint-key

The OpenBao Agent templates render these six files from appropriate KV v2
data. The precise Vault paths must be verified before production setup; do
not invent them or automatically copy an NFS-e certificate.

The fingerprint-key is a stable per-connection HMAC key of at least 32 bytes.
Banking Connectors persists only a one-way key commitment. Rotation without a verified
identity migration blocks sync rather than duplicating bank movements.

Filesystem requirements: private mount, regular non-world-readable files
(0600 or restricted 0640), read-only PHP mount, no credentials in HTML, API,
logs or console output. The Agent handles renewal and server authentication.

A *hypothetical*, narrowly scoped OpenBao KV v2 policy could be:

    path "banking-connectors/data/company-2/account-3" {
      capabilities = ["read"]
    }

This is a hypothetical bank-specific KV v2 mount, **not** the documented
location of existing LibreCode production secrets. The local-test test sets up
this exact synthetic policy and verifies denial of another company's path.
Confirm the existing production mount and role before enabling it.

## Server configuration

Required environment variable names:

- BANKING_CONNECTORS_SECRETS_DIR: absolute path to the private rendered files
- BANKING_CONNECTORS_SICOOB_AUTH_URL: verified official HTTPS OAuth token endpoint
- BANKING_CONNECTORS_SICOOB_API_URL: official HTTPS account-statement API base
- BANKING_CONNECTORS_AUTO_POLL_ENABLED: exactly "yes" to register an opt-in schedule

The code checks that the auth and API hosts match the Sicoob allowlist.
The native Akaunting bank account must have a verified bank account number
equal to the Sicoob account number supplied by the scoped secret.

## First read and recurring operation

An administrator can create a paused connection in Banking Connectors's Web page
and enable manual-only mode. Its credential reference is derived from the
current company and native bank account; the Web page never accepts secrets.
First verify credentials without any bank call:

    php artisan banking-connectors:poll <connection-id> --check-credentials

The first actual read requires an explicit start date:

    php artisan banking-connectors:poll <connection-id> --start=YYYY-MM-DD

Subsequent reads overlap the cursor by two days; bank IDs prevent duplicates.
The batch command considers enabled connections with an existing cursor,
plus manual requests queued through the Web page. Both require a server-side
credential reference, and paused connections are never polled:

    php artisan banking-connectors:poll-all --check-eligible
    php artisan banking-connectors:poll-all

BANKING_CONNECTORS_AUTO_POLL_ENABLED=yes registers two tasks with Akaunting's
existing scheduler: a pending-manual-request recovery task every minute and
automatic reads of enabled connections every 15 minutes. A user-initiated
refresh also dispatches a scoped queue job when the app's queue driver is
asynchronous. None of these auto-enable paused bank accounts or create
native financial transactions.

The deployment must verify that `php artisan schedule:run` is actually
executed every minute and that the queue worker is running. An operational
Redis queue by itself does **not** mean the scheduler is active. OpenBao
Agent file mounts and the banking environment variables must be available
to both the Web/CLI process and the async queue worker, without putting
secrets in the source tree or environment-variable values.

## Verification and limitations

On local-test, the real OpenBao 2.7.1/2.7.2 Agent authenticated with a scoped
AppRole against disposable KV v2, rendered all six synthetic files and
refused another company's secret path. The CLI verified X.509 validity,
private key decryption and matching certificate/key. Rotating only the
private key caused fail-closed behavior; rendering the corresponding new
certificate restored local readiness without rotating the movement HMAC key.

Run the isolated test (no production OpenBao or bank requests):

    bash tests/run-openbao-agent-local.sh

These tests do **not** prove Sicoob authorized the certificate. What
remains unverified is the production OpenBao mount/role mapping, whether the
existing account credentials have all required fields, and unattended
production Sicoob requests/renewal. No production secret was read or copied.
