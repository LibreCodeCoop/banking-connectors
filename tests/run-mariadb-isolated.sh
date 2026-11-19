#!/usr/bin/env bash
# SPDX-FileCopyrightText: 2026 LibreCode Coop and contributors
# SPDX-License-Identifier: AGPL-3.0-or-later
set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
NAME="lcc-banking_connectors-test-$$"
WORKER="lcc-banking_connectors-killed-worker-$$"
PASSWORD="$(openssl rand -hex 24)"
cleanup() {
    docker rm -f "$WORKER" >/dev/null 2>&1 || true
    docker rm -f "$NAME" >/dev/null 2>&1 || true
}
trap cleanup EXIT

docker run -d --rm --name "$NAME" --network bridge \
    --tmpfs /var/lib/mysql:rw,size=384m \
    -e MARIADB_ROOT_PASSWORD="$PASSWORD" \
    -e MARIADB_DATABASE=banking_connectors_test mariadb:11.8.9 >/dev/null

READY=no
for _ in $(seq 1 40); do
    if docker exec "$NAME" mariadb -h 127.0.0.1 -P 3306 -N -u root -p"$PASSWORD" -e 'SELECT 1' >/dev/null 2>&1; then
        READY=yes
        break
    fi
    sleep 1
done
if [[ "$READY" != yes ]]; then
    echo 'Isolated MariaDB container did not become ready' >&2
    exit 1
fi

# No host port is published. The PHP test uses the database container's
# private network namespace and a read-only mount of the module source.
docker run --rm --network "container:$NAME" \
    --read-only --cap-drop ALL --security-opt no-new-privileges \
    --entrypoint php -e BANKING_CONNECTORS_TEST_DB_PASSWORD="$PASSWORD" \
    -e BANKING_CONNECTORS_TEST_DB_ISOLATED=yes \
    -v "$ROOT:/app:ro" -w /app \
    ghcr.io/librecodecoop/akaunting-docker-php:3 tests/staging-mysql.php

# Simulate a hard worker crash while a transaction and named lock are held.
# No other bank connection or application database is touched.
docker run -d --name "$WORKER" --network "container:$NAME" \
    --read-only --cap-drop ALL --security-opt no-new-privileges \
    --entrypoint php -e BANKING_CONNECTORS_TEST_DB_PASSWORD="$PASSWORD" \
    -e BANKING_CONNECTORS_TEST_DB_ISOLATED=yes \
    -v "$ROOT:/app:ro" -w /app \
    ghcr.io/librecodecoop/akaunting-docker-php:3 \
    tests/lock-crash-mysql.php hold >/dev/null

ACQUIRED=no
for _ in $(seq 1 20); do
    if docker logs "$WORKER" 2>&1 | grep -q 'LOCK_HELD_UNCOMMITTED_STAGING'; then
        ACQUIRED=yes
        break
    fi
    sleep 1
done
if [[ "$ACQUIRED" != yes ]]; then
    echo 'FAIL crash fixture did not reach uncommitted staging point' >&2
    exit 1
fi

docker kill "$WORKER" >/dev/null
docker rm "$WORKER" >/dev/null

docker run --rm --network "container:$NAME" \
    --read-only --cap-drop ALL --security-opt no-new-privileges \
    --entrypoint php -e BANKING_CONNECTORS_TEST_DB_PASSWORD="$PASSWORD" \
    -e BANKING_CONNECTORS_TEST_DB_ISOLATED=yes \
    -v "$ROOT:/app:ro" -w /app \
    ghcr.io/librecodecoop/akaunting-docker-php:3 \
    tests/lock-crash-mysql.php recover
