#!/usr/bin/env bash
# SPDX-FileCopyrightText: 2026 LibreCode Coop and contributors
# SPDX-License-Identifier: AGPL-3.0-or-later
#
# Full backup + independent restore of the synthetic local Akaunting DB.
# Never touches production and never restores over the running source schema.
set -euo pipefail
umask 077

BASE="$HOME/projects/.tmp/akaunting-banksync-local"
APP="$BASE/app-root"
SOURCE="lcc-banksync-local-db"
CLONE="lcc-banking_connectors-restore-$$"
TEMP=""

cleanup() {
    docker rm -f "$CLONE" >/dev/null 2>&1 || true
    if [[ -n "$TEMP" && -d "$TEMP" ]]; then
        case "$TEMP" in
            "$BASE"/.backup-restore-qa.*) rm -rf -- "$TEMP" ;;
            *) echo 'Unsafe temporary backup cleanup rejected' >&2 ;;
        esac
    fi
}
trap cleanup EXIT

if [[ ! -f "$APP/.env" ]] ||
    ! grep -qx 'APP_ENV=local' "$APP/.env" ||
    ! grep -qx 'DB_DATABASE=akaunting_synthetic' "$APP/.env" ||
    ! grep -qx 'DB_HOST=banksync-local-db' "$APP/.env" ||
    ! grep -qx 'DB_PREFIX=ak_' "$APP/.env"; then
    echo 'FAIL expected isolated synthetic Akaunting environment not found' >&2
    exit 1
fi
if [[ "$(docker network inspect lcc-banksync-local-net --format '{{.Internal}}')" != true ]] ||
    [[ "$(docker inspect "$SOURCE" --format '{{json .NetworkSettings.Ports}}')" != '{"3306/tcp":null}' ]]; then
    echo 'FAIL isolated database/network check' >&2
    exit 1
fi
if docker container inspect "$CLONE" >/dev/null 2>&1; then
    echo 'FAIL restore container name collision' >&2
    exit 1
fi

DB_USER="$(sed -n 's/^DB_USERNAME=//p' "$APP/.env" | head -1)"
DB_PASS="$(sed -n 's/^DB_PASSWORD=//p' "$APP/.env" | head -1)"
[[ -n "$DB_USER" && -n "$DB_PASS" ]] || { echo 'Missing synthetic DB credentials' >&2; exit 1; }
TEMP="$(mktemp -d "$BASE/.backup-restore-qa.XXXXXXXX")"
BACKUP="$TEMP/synthetic-akaunting.sql"
docker exec "$SOURCE" mariadb-dump \
    --single-transaction --quick --skip-comments --no-tablespaces \
    -u "$DB_USER" "-p$DB_PASS" akaunting_synthetic > "$BACKUP" 2> "$TEMP/dump-errors.txt" || {
        echo 'FAIL synthetic database backup' >&2; exit 1;
    }
test -s "$BACKUP" || { echo 'FAIL empty backup' >&2; exit 1; }
chmod 600 "$BACKUP"
echo 'PASS complete synthetic Akaunting DB backup created (private test file)'

CLONE_PASS="$(openssl rand -hex 24)"
docker run -d --rm --name "$CLONE" --network bridge \
    --tmpfs /var/lib/mysql:rw,size=640m \
    -e MARIADB_ROOT_PASSWORD="$CLONE_PASS" \
    -e MARIADB_DATABASE=akaunting_synthetic_restore \
    mariadb:11.8.9 >/dev/null

ready=no
for _ in $(seq 1 40); do
    if docker exec "$CLONE" mariadb -h 127.0.0.1 -P 3306 -N \
        -u root "-p$CLONE_PASS" akaunting_synthetic_restore \
        -e 'SELECT 1' >/dev/null 2>&1; then
        ready=yes
        break
    fi
    sleep 1
done
[[ "$ready" == yes ]] || { echo 'FAIL disposable restore DB not ready' >&2; exit 1; }
docker exec -i "$CLONE" mariadb -u root "-p$CLONE_PASS" \
    akaunting_synthetic_restore < "$BACKUP" 2> "$TEMP/restore-errors.txt" || {
        echo 'FAIL restoring synthetic Akaunting dump' >&2; exit 1;
    }

# Compare record counts and order-insensitive record digests, not financial
# content or credentials. The database's whole schema and data were restored.
QUERY='SET SESSION group_concat_max_len=10000000;
SELECT CONCAT("native:",COUNT(*),":",SHA2(CONCAT(COUNT(*),"|",COALESCE(GROUP_CONCAT(CONCAT_WS(":",id,company_id,account_id,type,amount,paid_at) ORDER BY id SEPARATOR "|"),"")),256)) FROM ak_transactions;
SELECT CONCAT("connections:",COUNT(*),":",SHA2(CONCAT(COUNT(*),"|",COALESCE(GROUP_CONCAT(CONCAT_WS(":",id,company_id,account_id,provider,state,IFNULL(cursor_date,"none"),IFNULL(poll_requested_at,"none")) ORDER BY id SEPARATOR "|"),"")),256)) FROM ak_lcc_banking_connectors_connections;
SELECT CONCAT("entries:",COUNT(*),":",SHA2(CONCAT(COUNT(*),"|",COALESCE(GROUP_CONCAT(CONCAT_WS(":",id,company_id,account_id,source_fingerprint,content_fingerprint,state,IFNULL(linked_transaction_id,"none")) ORDER BY id SEPARATOR "|"),"")),256)) FROM ak_lcc_banking_connectors_entries;
SELECT CONCAT("reviews:",COUNT(*),":",SHA2(CONCAT(COUNT(*),"|",COALESCE(GROUP_CONCAT(CONCAT_WS(":",id,company_id,account_id,entry_id,operation,request_key) ORDER BY id SEPARATOR "|"),"")),256)) FROM ak_lcc_banking_connectors_review_events;'

docker exec "$SOURCE" mariadb -N -u "$DB_USER" "-p$DB_PASS" \
    akaunting_synthetic -e "$QUERY" > "$TEMP/source-digests" 2>/dev/null
docker exec "$CLONE" mariadb -N -u root "-p$CLONE_PASS" \
    akaunting_synthetic_restore -e "$QUERY" > "$TEMP/restore-digests" 2>/dev/null
if ! cmp -s "$TEMP/source-digests" "$TEMP/restore-digests"; then
    echo 'FAIL backup/restore financial or bank evidence changed' >&2
    exit 1
fi

SCHEMA_QUERY='SELECT COUNT(*) FROM information_schema.table_constraints
WHERE table_schema = DATABASE() AND constraint_type IN ("UNIQUE","FOREIGN KEY")
AND table_name IN ("ak_lcc_banking_connectors_connections", "ak_lcc_banking_connectors_entries",
"ak_lcc_banking_connectors_review_events");'
source_constraints="$(docker exec "$SOURCE" mariadb -N -u "$DB_USER" \
    "-p$DB_PASS" akaunting_synthetic -e "$SCHEMA_QUERY" 2>/dev/null)"
restored_constraints="$(docker exec "$CLONE" mariadb -N -u root \
    "-p$CLONE_PASS" akaunting_synthetic_restore -e "$SCHEMA_QUERY" 2>/dev/null)"
if [[ "$source_constraints" != "$restored_constraints" || "$restored_constraints" -lt 4 ]]; then
    echo 'FAIL bank evidence foreign/unique constraints not preserved' >&2
    exit 1
fi
echo 'PASS restored bank connections, entries, audit events, native transactions'
echo 'PASS restored company isolation, bank identity uniqueness and review constraints'
echo 'PASS restored into a different, disposable DB container'
