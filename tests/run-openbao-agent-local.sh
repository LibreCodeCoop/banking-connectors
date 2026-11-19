#!/usr/bin/env bash
# SPDX-FileCopyrightText: 2026 LibreCode Coop and contributors
# SPDX-License-Identifier: AGPL-3.0-or-later
#
# Real OpenBao Agent + AppRole + KV v2, with wholly synthetic credentials.
# Never connects to production, production OpenBao or the live bank.
set -euo pipefail
umask 077

IMAGE="${BANKING_CONNECTORS_TEST_OPENBAO_IMAGE:-openbao/openbao:2.7.1}"
APP="$HOME/projects/.tmp/akaunting-banksync-local/app-root"
SRC="$APP/storage/banking_connectors-fixture-secrets/sicoob/company-2/account-3"
SERVER="lcc-banking_connectors-qa-openbao-server"
AGENT="lcc-banking_connectors-qa-openbao-agent"
SCRATCH=""
ROOT_TOKEN=""

if [[ ! -f "$APP/.env" ]] ||
    ! grep -qx 'DB_DATABASE=akaunting_synthetic' "$APP/.env" ||
    ! grep -qx 'DB_HOST=banksync-local-db' "$APP/.env" ||
    ! grep -qx 'APP_ENV=local' "$APP/.env" ||
    ! grep -qx 'DB_PREFIX=ak_' "$APP/.env"; then
    echo 'FAIL unsafe Akaunting test environment' >&2
    exit 1
fi
if [[ ! -f "$SRC/client.pem" || ! -f "$SRC/fingerprint-key" ]]; then
    echo 'FAIL synthetic fixture missing (do not use production secrets)' >&2
    exit 1
fi
QA_DB_USER="$(sed -n 's/^DB_USERNAME=//p' "$APP/.env" | head -1)"
QA_DB_PASS="$(sed -n 's/^DB_PASSWORD=//p' "$APP/.env" | head -1)"
[[ -n "$QA_DB_USER" && -n "$QA_DB_PASS" ]] || { echo 'Missing fixture DB identity' >&2; exit 1; }
SICOOB_CONN_ID="$(docker exec lcc-banksync-local-db mariadb -N -u "$QA_DB_USER" "-p$QA_DB_PASS" akaunting_synthetic -e "SELECT id FROM ak_lcc_banking_connectors_connections WHERE company_id=2 AND account_id=3 AND provider='sicoob' LIMIT 1" 2>/dev/null)"
[[ "$SICOOB_CONN_ID" =~ ^[1-9][0-9]*$ ]] || { echo 'Synthetic connection missing' >&2; exit 1; }
if docker container inspect "$SERVER" >/dev/null 2>&1 ||
   docker container inspect "$AGENT" >/dev/null 2>&1; then
    echo 'FAIL OpenBao QA container name already in use; will not alter it' >&2
    exit 1
fi
if [[ "$(docker network inspect lcc-banksync-local-net --format '{{.Internal}}')" != true ]]; then
    echo 'FAIL bank test network must be internal' >&2
    exit 1
fi
cleanup() {
    docker rm -f "$AGENT" "$SERVER" >/dev/null 2>&1 || true
    if [[ -n "$SCRATCH" && -d "$SCRATCH" ]]; then
        case "$SCRATCH" in
            "$APP"/storage/.banking_connectors-openbao-qa.*) rm -rf -- "$SCRATCH" ;;
            *) echo 'Refusing unsafe QA directory cleanup' >&2 ;;
        esac
    fi
}
trap cleanup EXIT

SCRATCH="$(mktemp -d "$APP/storage/.banking_connectors-openbao-qa.XXXXXXXX")"
OUT="$SCRATCH/output"
AUTH="$SCRATCH/auth"
TARGET="$OUT/sicoob/company-2/account-3"
SEED="$SCRATCH/seed"
mkdir -p "$TARGET" "$AUTH" "$SEED"
for name in client-id account-number client.pem client-key.pem client-key-password fingerprint-key; do
    cp -- "$SRC/$name" "$SEED/$name"
    chmod 0640 "$SEED/$name"
done
chmod 0750 "$SEED"
chmod 700 "$SCRATCH" "$OUT" "$OUT/sicoob" "$OUT/sicoob/company-2" "$TARGET" "$AUTH"

ROOT_TOKEN="$(openssl rand -hex 22)"
docker run -d --rm --name "$SERVER" \
  --network lcc-banksync-local-net --network-alias openbao \
  --read-only --tmpfs /tmp:rw,nosuid,noexec,size=24m,mode=1777 \
  --tmpfs /home/openbao:rw,nosuid,noexec,size=4m,mode=0700,uid=100,gid=1000 \
  --cap-drop ALL --security-opt no-new-privileges \
  --memory 256m -e BAO_DEV_ROOT_TOKEN_ID="$ROOT_TOKEN" \
  -v "$SEED:/fixture:ro" "$IMAGE" \
  server -dev -dev-root-token-id="$ROOT_TOKEN" \
  -dev-listen-address=0.0.0.0:8200 >/dev/null

BAO_ADDR="http://127.0.0.1:8200"
server_bao() {
    docker exec -e BAO_ADDR="$BAO_ADDR" -e BAO_TOKEN="$ROOT_TOKEN" \
        "$SERVER" bao "$@"
}
ready=0
for i in $(seq 1 30); do
    if server_bao status >/dev/null 2>&1; then
        ready=1
        break
    fi
    sleep 1
done
[[ "$ready" == 1 ]] || { echo 'FAIL synthetic OpenBao server unavailable' >&2; exit 1; }

server_bao secrets enable -path=banking-connectors kv-v2 >/dev/null
server_bao auth enable approle >/dev/null
# The fiscal certificate remains under the OLD /nfse KV mount while a
# reusable client accesses it. No migration or production secret copying.
server_bao secrets enable -path=nfse kv-v2 >/dev/null
cat > "$AUTH/fiscal-policy.hcl" <<'POLICY'
path "nfse/data/pfx/companies/2/aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa" {
    capabilities = ["read", "create", "update", "delete"]
}
POLICY
docker exec -i -e BAO_ADDR="$BAO_ADDR" -e BAO_TOKEN="$ROOT_TOKEN" \
    "$SERVER" bao policy write nfse-qa-only - < "$AUTH/fiscal-policy.hcl" >/dev/null
server_bao write auth/approle/role/nfse-qa \
    token_policies=nfse-qa-only token_num_uses=0 token_ttl=30m \
    secret_id_ttl=30m >/dev/null
server_bao kv put -mount=nfse \
    pfx/companies/2/aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa \
    password='synthetic-initial-password' pfx_path='/fake/qa/cert.pfx' >/dev/null
server_bao read -field=role_id auth/approle/role/nfse-qa/role-id > "$AUTH/fiscal-roleid"
server_bao write -f -field=secret_id auth/approle/role/nfse-qa/secret-id > "$AUTH/fiscal-secretid"
chmod 600 "$AUTH/fiscal-roleid" "$AUTH/fiscal-secretid"
cat > "$AUTH/policy.hcl" <<'POLICY'
path "banking-connectors/data/company-2/account-3" {
    capabilities = ["read"]
}
POLICY
docker exec -i -e BAO_ADDR="$BAO_ADDR" -e BAO_TOKEN="$ROOT_TOKEN" \
    "$SERVER" bao policy write banking-connectors-qa-read - < "$AUTH/policy.hcl" >/dev/null
server_bao write auth/approle/role/banking-connectors-qa \
    token_policies=banking-connectors-qa-read \
    token_num_uses=0 token_ttl=30m \
    secret_id_ttl=30m >/dev/null
server_bao kv put -mount=banking-connectors company-2/account-3 \
    client_id='synthetic_qa_client' account_number=123456 \
    certificate=@/fixture/client.pem \
    private_key=@/fixture/client-key.pem \
    key_password=@/fixture/client-key-password \
    fingerprint_key=@/fixture/fingerprint-key >/dev/null
server_bao read -field=role_id auth/approle/role/banking-connectors-qa/role-id \
    > "$AUTH/roleid"
server_bao write -f -field=secret_id auth/approle/role/banking-connectors-qa/secret-id \
    > "$AUTH/secretid"
chmod 600 "$AUTH/roleid" "$AUTH/secretid"

cat > "$AUTH/agent.hcl" <<'HCL'
vault {
    address = "http://lcc-banking_connectors-qa-openbao-server:8200"
}
auto_auth {
    method "approle" {
        mount_path = "auth/approle"
        config = {
            role_id_file_path = "/auth/roleid"
            secret_id_file_path = "/auth/secretid"
            remove_secret_id_file_after_reading = false
        }
    }
    sink "file" {
        config = { path = "/agent-runtime/token" }
    }
}
template_config {
    static_secret_render_interval = "5s"
    exit_on_retry_failure = true
}
template {
    destination = "/output/sicoob/company-2/account-3/client-id"
    contents = "{{ with secret \"banking-connectors/data/company-2/account-3\" }}{{ .Data.data.client_id }}{{ end }}"
    perms = 0600
    error_on_missing_key = true
}
template {
    destination = "/output/sicoob/company-2/account-3/account-number"
    contents = "{{ with secret \"banking-connectors/data/company-2/account-3\" }}{{ .Data.data.account_number }}{{ end }}"
    perms = 0600
    error_on_missing_key = true
}
template {
    destination = "/output/sicoob/company-2/account-3/client.pem"
    contents = "{{ with secret \"banking-connectors/data/company-2/account-3\" }}{{ .Data.data.certificate }}{{ end }}"
    perms = 0600
    error_on_missing_key = true
}
template {
    destination = "/output/sicoob/company-2/account-3/client-key.pem"
    contents = "{{ with secret \"banking-connectors/data/company-2/account-3\" }}{{ .Data.data.private_key }}{{ end }}"
    perms = 0600
    error_on_missing_key = true
}
template {
    destination = "/output/sicoob/company-2/account-3/client-key-password"
    contents = "{{ with secret \"banking-connectors/data/company-2/account-3\" }}{{ .Data.data.key_password }}{{ end }}"
    perms = 0600
    error_on_missing_key = true
}
template {
    destination = "/output/sicoob/company-2/account-3/fingerprint-key"
    contents = "{{ with secret \"banking-connectors/data/company-2/account-3\" }}{{ .Data.data.fingerprint_key }}{{ end }}"
    perms = 0600
    error_on_missing_key = true
}
HCL
chmod 600 "$AUTH/agent.hcl"

docker run -d --name "$AGENT" \
    --network lcc-banksync-local-net --user "$(id -u):$(id -g)" \
    --read-only --tmpfs /tmp:rw,nosuid,noexec,size=24m,mode=1777 \
    --tmpfs /agent-runtime:rw,nosuid,noexec,size=4m,mode=0700,uid=$(id -u),gid=$(id -g) \
    --cap-drop ALL --security-opt no-new-privileges --memory 160m \
    -v "$AUTH:/auth:ro" -v "$OUT:/output:rw" "$IMAGE" \
    agent -config=/auth/agent.hcl -log-level=error >/dev/null

ready=0
for i in $(seq 1 40); do
    total="$(find "$TARGET" -maxdepth 1 -type f | wc -l)"
    if [[ "$total" == 6 ]]; then
        ready=1
        break
    fi
    if [[ "$(docker inspect "$AGENT" --format '{{.State.Running}}' 2>/dev/null || echo false)" != true ]]; then
        echo 'Agent exited before rendering (inspect error class below)' >&2
        break
    fi
    sleep 1
done
if [[ "$ready" != 1 ]]; then
    echo 'FAIL OpenBao Agent did not render all six credentials' >&2
    docker inspect "$AGENT" --format 'agent_status={{.State.Status}}' >&2 || true
    docker logs --tail 30 "$AGENT" 2>&1 | grep -Ei 'error|failed|permission|invalid|denied|template' | sed -E 's/(token|secret|password)=[^ ]+/[redacted]/Ig' | head -14 >&2 || true
    exit 1
fi
for file in client-id account-number client.pem client-key.pem client-key-password fingerprint-key; do
    [[ -f "$TARGET/$file" && ! -L "$TARGET/$file" ]] || {
        echo 'FAIL invalid rendered secret file' >&2
        exit 1
    }
done
echo 'PASS real OpenBao Agent AppRole authenticated and rendered six private KV v2 fields'

# The AppRole used by Agent cannot list or read another company's secret.
if docker exec "$AGENT" sh -c 'BAO_ADDR=http://lcc-banking_connectors-qa-openbao-server:8200 BAO_TOKEN="$(cat /agent-runtime/token)" bao kv get -mount=banking-connectors company-3/account-3 >/dev/null 2>&1'; then
    echo 'FAIL AppRole was able to read an unauthorized company secret' >&2
    exit 1
fi
echo 'PASS limited AppRole cannot read a foreign-company secret'

BANKING_CONNECTORS_SECRETS_DIR="/var/www/html/storage/$(basename "$SCRATCH")/output"
docker exec -e BANKING_CONNECTORS_SECRETS_DIR="$BANKING_CONNECTORS_SECRETS_DIR" \
    -e BANKING_CONNECTORS_SICOOB_AUTH_URL=https://auth.sicoob.com.br/auth/realms/cooperado/protocol/openid-connect/token \
    -e BANKING_CONNECTORS_SICOOB_API_URL=https://api.sicoob.com.br/conta-corrente/v4 \
    lcc-banksync-local-php php artisan banking-connectors:poll \
    "$SICOOB_CONN_ID" --check-credentials --no-ansi
echo 'PASS Banking Connectors validated real Agent-rendered synthetic mTLS credentials without any Sicoob request'

# Rotate a synthetic certificate/key pair in OpenBao KV v2 while Agent runs.
# First apply only the new key and assert fail-closed, then update its
# certificate and assert the new pair can be read without restarting PHP.
openssl req -x509 -newkey rsa:2048 \
    -keyout "$SEED/rotated-key.pem" -out "$SEED/rotated-client.pem" \
    -days 30 -subj '/CN=synthetic-rotated-banking-connectors-test' \
    -passout "file:$SEED/client-key-password" >/dev/null 2>&1
chmod 0640 "$SEED/rotated-key.pem" "$SEED/rotated-client.pem"

ORIGINAL_CERT_DIGEST="$(sha256sum "$TARGET/client.pem" | cut -d' ' -f1)"
ORIGINAL_FINGERPRINT_DIGEST="$(sha256sum "$TARGET/fingerprint-key" | cut -d' ' -f1)"
server_bao kv patch -mount=banking-connectors company-2/account-3 \
    private_key=@/fixture/rotated-key.pem >/dev/null

ready=no
for _ in $(seq 1 18); do
    if cmp -s "$SEED/rotated-key.pem" "$TARGET/client-key.pem"; then
        ready=yes
        break
    fi
    sleep 1
done
[[ "$ready" == yes ]] || { echo 'FAIL Agent did not render rotated private key' >&2; exit 1; }
[[ "$(sha256sum "$TARGET/client.pem" | cut -d' ' -f1)" == "$ORIGINAL_CERT_DIGEST" ]] || {
    echo 'FAIL certificate changed before server rotated it' >&2; exit 1;
}
if docker exec -e BANKING_CONNECTORS_SECRETS_DIR="$BANKING_CONNECTORS_SECRETS_DIR" \
    -e BANKING_CONNECTORS_SICOOB_AUTH_URL=https://auth.sicoob.com.br/auth/realms/cooperado/protocol/openid-connect/token \
    -e BANKING_CONNECTORS_SICOOB_API_URL=https://api.sicoob.com.br/conta-corrente/v4 \
    lcc-banksync-local-php php artisan banking-connectors:poll \
    "$SICOOB_CONN_ID" --check-credentials --no-ansi > "$SCRATCH/invalid-rotation.out" 2>&1; then
    echo 'FAIL mismatched rotated private key was accepted' >&2
    exit 1
fi
grep -q '^bank_mtls_key_mismatch$' "$SCRATCH/invalid-rotation.out" || {
    echo 'FAIL mismatched private key did not produce the safe error code' >&2
    exit 1
}
echo 'PASS Agent-rendered unmatched certificate/key is rejected before bank access'

server_bao kv patch -mount=banking-connectors company-2/account-3 \
    certificate=@/fixture/rotated-client.pem >/dev/null
ready=no
for _ in $(seq 1 18); do
    if cmp -s "$SEED/rotated-client.pem" "$TARGET/client.pem" &&
       cmp -s "$SEED/rotated-key.pem" "$TARGET/client-key.pem"; then
        ready=yes
        break
    fi
    sleep 1
done
[[ "$ready" == yes ]] || { echo 'FAIL Agent did not apply matching rotated certificate' >&2; exit 1; }
[[ "$(sha256sum "$TARGET/fingerprint-key" | cut -d' ' -f1)" == "$ORIGINAL_FINGERPRINT_DIGEST" ]] || {
    echo 'FAIL rotation changed transaction identity key' >&2; exit 1;
}
docker exec -e BANKING_CONNECTORS_SECRETS_DIR="$BANKING_CONNECTORS_SECRETS_DIR" \
    -e BANKING_CONNECTORS_SICOOB_AUTH_URL=https://auth.sicoob.com.br/auth/realms/cooperado/protocol/openid-connect/token \
    -e BANKING_CONNECTORS_SICOOB_API_URL=https://api.sicoob.com.br/conta-corrente/v4 \
    lcc-banksync-local-php php artisan banking-connectors:poll \
    "$SICOOB_CONN_ID" --check-credentials --no-ansi >/dev/null
echo 'PASS rotated synthetic certificate/key pair accepted; stable bank fingerprint key preserved'

echo '== Generic CredentialVault client with real isolated bank and fiscal AppRoles =='
CVAULT="$HOME/projects/akaunting-credential-vault"
test -f "$CVAULT/tests/openbao-integration.php" || { echo 'FAIL CredentialVault test missing' >&2; exit 1; }
docker run --rm --network lcc-banksync-local-net \
    --user "$(id -u):$(id -g)" --read-only \
    --cap-drop ALL --security-opt no-new-privileges \
    --tmpfs /tmp:rw,nosuid,noexec,size=12m,mode=1777 \
    -e CREDENTIAL_VAULT_ISOLATED_QA=yes \
    -v "$CVAULT:/shared:ro" -v "$AUTH:/auth:ro" \
    -w /shared php:8.4-cli php tests/openbao-integration.php
echo 'PASS no production OpenBao, bank credentials, or financial ledger touched'
