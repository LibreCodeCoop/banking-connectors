#!/usr/bin/env bash
# SPDX-FileCopyrightText: 2026 LibreCode Coop and contributors
# SPDX-License-Identifier: AGPL-3.0-or-later
#
# Reproducible full regression on dedicated local test environment's SYNTHETIC Akaunting only.
set -euo pipefail
ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
BASE="$HOME/projects/.tmp/akaunting-banksync-local"
APP="$BASE/app-root"
SRC="$HOME/projects/akaunting-docker/volumes/akaunting"
DB_CONTAINER="lcc-banksync-local-db"
PHP_CONTAINER="lcc-banksync-local-php"

if [[ ! -f "$APP/.env" || ! -f "$BASE/admin-credentials.local" ||
      ! -f "$BASE/company3-credentials.local" ||
      ! -f "$BASE/restricted-credentials.local" ]]; then
    echo 'Isolated localhost fixture and synthetic credentials required' >&2
    exit 1
fi
if ! grep -qx 'DB_DATABASE=akaunting_synthetic' "$APP/.env" ||
   ! grep -qx 'DB_HOST=banksync-local-db' "$APP/.env" ||
   ! grep -qx 'DB_PREFIX=ak_' "$APP/.env" ||
   ! grep -qx 'APP_ENV=local' "$APP/.env"; then
    echo 'Unsafe database identity: refusing verification run' >&2
    exit 1
fi
docker inspect "$DB_CONTAINER" >/dev/null
docker inspect "$PHP_CONTAINER" >/dev/null
if [[ "$(docker inspect "$DB_CONTAINER" --format '{{.State.Status}}')" != running ||
      "$(docker inspect "$PHP_CONTAINER" --format '{{.State.Status}}')" != running ]]; then
    echo 'Dedicated test containers are not running' >&2
    exit 1
fi
# Database must remain private; any published host port aborts the suite.
if [[ "$(docker inspect "$DB_CONTAINER" --format '{{json .NetworkSettings.Ports}}')" != '{"3306/tcp":null}' ]]; then
    echo 'Isolated database has an unexpected published port' >&2
    exit 1
fi

DB_USER="$(sed -n 's/^DB_USERNAME=//p' "$APP/.env" | head -1)"
DB_PASS="$(sed -n 's/^DB_PASSWORD=//p' "$APP/.env" | head -1)"
if [[ -z "$DB_USER" || -z "$DB_PASS" ]]; then
    echo 'Isolated database credentials not found' >&2
    exit 1
fi
financial_digest() {
    docker exec "$DB_CONTAINER" mariadb -N -u "$DB_USER" "-p$DB_PASS" akaunting_synthetic \
        -e 'SET SESSION group_concat_max_len = 10000000; SELECT SHA2(CONCAT(COUNT(*), "|", COALESCE(GROUP_CONCAT(CONCAT_WS(":",id,company_id,account_id,type,amount,paid_at) ORDER BY id SEPARATOR "|"),"")),256) FROM ak_transactions;' \
        2>/dev/null
}
BEFORE="$(financial_digest)"
[[ -n "$BEFORE" ]] || { echo 'Missing financial checksum' >&2; exit 1; }

echo '== PHP syntax and domain regressions =='
docker run --rm --read-only --cap-drop ALL --security-opt no-new-privileges \
    --tmpfs /tmp:rw,nosuid,noexec,size=16m,mode=1777 \
    -v "$ROOT:/app:ro" \
    -v "$HOME/projects/akaunting-credential-vault:/shared:ro" \
    -w /app php:8.4-cli sh -c '
set -e
for file in $(find Application Console Connectors Contracts Database Domain Http Infrastructure Jobs Providers Resources Routes tests examples -type f -name "*.php" | sort); do php -l "$file" >/dev/null; done
for test in contract provider sicoob-http-client sicoob-retry-policy bank-connector-plugin scoped-credentials mtls-certificate-verifier health-snapshot native-mapper staging-sqlite read-only-sync-sqlite review-decision-sqlite category-workflow-sqlite category-learning-sqlite connection-control-sqlite native-posting-decision; do
    php "tests/$test.php" >/dev/null
    echo "PASS $test"
done
php /shared/tests/contract.php >/dev/null
php /shared/tests/profile-store.php >/dev/null
php /shared/tests/module-stack.php >/dev/null
echo 'PASS generic CredentialVault, encrypted profiles and native installation contracts'
'

echo '== Disposable MariaDB tests =='
bash "$ROOT/tests/run-mariadb-isolated.sh"

echo '== Native Laravel migrations (in-memory SQLite) =='
docker run --rm --user "$(id -u):$(id -g)" --read-only \
    --cap-drop ALL --security-opt no-new-privileges \
    --tmpfs /tmp:rw,nosuid,noexec,size=16m,mode=1777 \
    --entrypoint php -e BANKING_CONNECTORS_FRAMEWORK_AUTOLOAD=/framework/vendor/autoload.php \
    -v "$ROOT:/module:ro" -v "$SRC/vendor:/framework/vendor:ro" \
    -v "$SRC/app/Utilities/helpers.php:/framework/app/Utilities/helpers.php:ro" \
    -w /module ghcr.io/librecodecoop/akaunting-docker-php:3 \
    tests/laravel-migration.php

echo '== Shared CredentialVault with native Laravel Crypt and MySQL =='
docker exec -e CREDENTIAL_VAULT_ISOLATED_LOCAL=yes "$PHP_CONTAINER"   php modules/CredentialVault/tests/localhost-encrypted-profile.php

echo '== Installed Akaunting domain and Blade =='
for test in local-akaunting-integration local-readonly-sync local-view-render local-queued-poll local-queued-positive local-native-publication; do
    docker exec -e BANKING_CONNECTORS_ISOLATED_LOCAL=yes -e BANKING_CONNECTORS_APP_ROOT=/var/www/html \
        "$PHP_CONTAINER" php -d session.upload_progress.enabled=0 \
        "modules/BankingConnectors/tests/$test.php" >/dev/null
    echo "PASS $test"
done

echo '== Compile and PHP-lint native Akaunting Blade output =='
docker exec "$PHP_CONTAINER" php artisan view:cache --no-ansi >/dev/null
docker exec "$PHP_CONTAINER" sh -c '
set -e
found=0
for file in storage/framework/views/*.php; do
    if grep -q "bank-classification-rules-title" "$file"; then
        php -l "$file" >/dev/null
        found=1
    fi
done
test "$found" -eq 1
'
echo 'PASS Bank Connectors Blade is syntactically valid after compilation'

echo '== Authenticated HTTP Web/API and multi-company tests =='
BANKING_CONNECTORS_LOCAL_CREDS="$BASE/admin-credentials.local" BANKING_CONNECTORS_LOCAL_COMPANY_ID=2 \
    python3 "$ROOT/tests/localhost-http.py"
BANKING_CONNECTORS_LOCAL_CREDS="$BASE/admin-credentials.local" BANKING_CONNECTORS_LOCAL_COMPANY_ID=2 \
    python3 "$ROOT/tests/localhost-review-post.py"
BANKING_CONNECTORS_LOCAL_FIXTURE_DIR="$BASE" python3 "$ROOT/tests/localhost-multitenant.py"
BANKING_CONNECTORS_LOCAL_FIXTURE_DIR="$BASE" python3 "$ROOT/tests/localhost-connections.py"
BANKING_CONNECTORS_LOCAL_FIXTURE_DIR="$BASE" python3 "$ROOT/tests/localhost-account-navigation.py"
BANKING_CONNECTORS_LOCAL_FIXTURE_DIR="$BASE" python3 "$ROOT/tests/localhost-classification.py"
BANKING_CONNECTORS_LOCAL_FIXTURE_DIR="$BASE" python3 "$ROOT/tests/localhost-learning.py"
BANKING_CONNECTORS_LOCAL_FIXTURE_DIR="$BASE" python3 "$ROOT/tests/localhost-native-transactions.py"
BANKING_CONNECTORS_LOCAL_FIXTURE_DIR="$BASE" python3 "$ROOT/tests/localhost-native-linked-actions.py"
if [[ -f "$BASE/app-root/modules/BankConnectorExample/module.json" ]]; then
    BANKING_CONNECTORS_LOCAL_FIXTURE_DIR="$BASE" python3 "$ROOT/tests/localhost-external-connector.py"
    echo 'PASS optional independently installed bank connector E2E'
fi
BANKING_CONNECTORS_LOCAL_FIXTURE_DIR="$BASE" python3 "$ROOT/tests/localhost-api.py"

echo '== Synthetic OpenBao Agent file contract and disabled scheduling =='
SICOOB_CONN_ID="$(docker exec "$DB_CONTAINER" mariadb -N -u "$DB_USER" "-p$DB_PASS" akaunting_synthetic -e "SELECT id FROM ak_lcc_banking_connectors_connections WHERE company_id=2 AND account_id=3 AND provider='sicoob' LIMIT 1" 2>/dev/null)"
[[ "$SICOOB_CONN_ID" =~ ^[1-9][0-9]*$ ]] || { echo 'Missing synthetic Sicoob connection' >&2; exit 1; }
SECRET_FIXTURE="$APP/storage/banking_connectors-fixture-secrets/sicoob/company-2/account-3"
if [[ ! -f "$SECRET_FIXTURE/client-id" ]]; then
    echo 'Missing synthetic credential fixture; no real credentials allowed' >&2
    exit 1
fi
docker exec   -e BANKING_CONNECTORS_SECRETS_DIR=/var/www/html/storage/banking_connectors-fixture-secrets   -e BANKING_CONNECTORS_SICOOB_AUTH_URL=https://auth.sicoob.com.br/auth/realms/cooperado/protocol/openid-connect/token   -e BANKING_CONNECTORS_SICOOB_API_URL=https://api.sicoob.com.br/conta-corrente/v4   "$PHP_CONTAINER" php artisan banking-connectors:poll "$SICOOB_CONN_ID"   --check-credentials --no-ansi
docker exec "$PHP_CONTAINER" php artisan banking-connectors:poll-all   --check-eligible --no-ansi
if docker exec "$PHP_CONTAINER" php artisan schedule:list --no-ansi     | grep -q 'banking-connectors:poll-all'; then
    echo 'Unexpected automatic bank polling enabled in localhost fixture' >&2
    exit 1
fi
docker exec -e BANKING_CONNECTORS_AUTO_POLL_ENABLED=yes "$PHP_CONTAINER"   php artisan schedule:list --no-ansi | grep -F 'banking-connectors:poll-all' >/dev/null
echo 'PASS scheduler opt-in discovered, default disabled, no bank calls'
docker exec   -e BANKING_CONNECTORS_SECRETS_DIR=/var/www/html/storage/banking_connectors-fixture-secrets   -e BANKING_CONNECTORS_SICOOB_AUTH_URL=https://auth.sicoob.com.br/auth/realms/cooperado/protocol/openid-connect/token   -e BANKING_CONNECTORS_SICOOB_API_URL=https://api.sicoob.com.br/conta-corrente/v4   "$PHP_CONTAINER" php artisan banking-connectors:sicoob-credential-probe   --connection="$SICOOB_CONN_ID" --no-ansi
docker exec "$PHP_CONTAINER" php artisan banking-connectors:health --strict --no-ansi
echo 'PASS read-only health monitoring reports no active warning in local fixture'
if docker exec "$PHP_CONTAINER" php artisan schedule:list --no-ansi |
    grep -q 'banking-connectors:sicoob-credential-probe'; then
    echo 'Unexpected automatic credential probe enabled' >&2
    exit 1
fi
docker exec -e BANKING_CONNECTORS_AUTO_CREDENTIAL_PROBE=yes "$PHP_CONTAINER"     php artisan schedule:list --no-ansi | grep -F 'banking-connectors:sicoob-credential-probe' >/dev/null
echo 'PASS daily credential probe opt-in; disabled by default'

echo '== OpenBao Agent AppRole + KV v2 integration and synthetic certificate rotation =='
bash "$ROOT/tests/run-openbao-agent-local.sh"

echo '== Restore synthetic database in a separate temporary MariaDB =='
bash "$ROOT/tests/backup-restore-local.sh"

AFTER="$(financial_digest)"
if [[ "$BEFORE" != "$AFTER" ]]; then
    echo 'FAIL: synthetic Akaunting ledger changed during Banking Connectors test suite' >&2
    exit 1
fi
echo 'PASS native Akaunting transaction checksum unchanged through entire suite'
echo 'PASS isolated local-test Web/API verification completed'
