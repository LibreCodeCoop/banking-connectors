#!/usr/bin/env python3
# SPDX-FileCopyrightText: 2026 LibreCode Coop and contributors
# SPDX-License-Identifier: AGPL-3.0-or-later
"""Independent authenticated JSON API client against synthetic local instance."""
import base64
import json
import os
import secrets
import urllib.error
import urllib.parse
import urllib.request

HOST = "http://127.0.0.1:18789"
ROOT = os.environ.get("BANKING_CONNECTORS_LOCAL_FIXTURE_DIR", "")
if not ROOT.endswith("/akaunting-banksync-local"):
    raise RuntimeError("Refusing any nonlocal fixture credentials")

def token(file_name):
    email, password = open(ROOT + "/" + file_name, encoding="utf-8").read().splitlines()
    if not email.endswith("@localhost.invalid"): raise RuntimeError("Unexpected auth source")
    return "Basic " + base64.b64encode((email + ":" + password).encode()).decode("ascii")

def get(token_value, path):
    headers = {"Accept": "application/json"}
    if token_value:
        headers["Authorization"] = token_value
    request = urllib.request.Request(HOST + path, headers=headers)
    try:
        res = urllib.request.urlopen(request, timeout=16)
    except urllib.error.HTTPError as error:
        res = error
    body = res.read()
    try:
        obj = json.loads(body.decode())
    except (UnicodeError, ValueError):
        obj = {}
    return res.status, obj

def post(token_value, path, body):
    request = urllib.request.Request(HOST + path,
        data=json.dumps(body).encode(), method="POST",
        headers={"Accept": "application/json", "Content-Type": "application/json",
                 "Authorization": token_value})
    try:
        res = urllib.request.urlopen(request, timeout=16)
    except urllib.error.HTTPError as error:
        res = error
    raw = res.read()
    try:
        obj = json.loads(raw.decode())
    except (UnicodeError, ValueError):
        obj = {}
    return res.status, obj

def check(ok, name):
    if not ok: raise RuntimeError("FAIL " + name)
    print("PASS " + name)

base = "/api/v1/banking-connectors"
s, resp = get(None, base + "/connections?company_id=2")
check(s == 401, "anonymous API request denied with HTTP 401")

admin = token("admin-credentials.local")
status, health = get(None, base + "/health?company_id=2")
check(status == 401, "anonymous health endpoint denied")
status, health = get(admin, base + "/health?company_id=2")
check(status == 200 and health.get("schema_version") == 1
      and health.get("company_id") == 2
      and health.get("service") == "banking-connectors"
      and health.get("probe_type") == "last_observed_state"
      and health.get("state") in ("ok", "idle", "attention"),
      "monitoring endpoint returns authenticated versioned company status")
check("issues" in health.get("data", {}) and
      "enabled_connections" in health.get("data", {}),
      "monitoring response contains machine-readable state")
check(all("company_id" not in issue or issue["company_id"] == 2
          for issue in health.get("data", {}).get("issues", [])),
      "monitoring response cannot leak another company's issues")
check("client-id" not in str(health) and
      "account-number" not in str(health) and
      "fingerprint-key" not in str(health),
      "health payload excludes credentials and account information")
status, _ = get(admin, base + "/health?company_id=3")
check(status in (401,403,404), "company2 health cannot inspect company3")
status, connections = get(admin, base + "/connections?company_id=2")
check(status == 200 and isinstance(connections.get("data"), list),
      "Basic-authenticated native API lists scoped bank connections")
rows = connections.get("data", [])
check(any(c.get("provider") == "sicoob" for c in rows),
      "Sicoob connection available through native JSON contract")
check(all("credential_reference" not in item for item in rows),
      "API never returns credential reference")
# A test install can have additional connectors. Select the explicitly
# seeded Sicoob fixture instead of relying on a migration's auto ID.
fixture = next((c for c in rows
                if c.get("provider") == "sicoob"
                and c.get("account_name") == "Sicoob - Teste de Conciliação"), None)
check(fixture is not None, "seeded Sicoob review fixture discovered by account")
fixture_id = int(fixture["id"])

status, result = get(admin, base + f"/preview?company_id=2&connection_id={fixture_id}")
payload = result.get("data", {})
check(status == 200 and payload.get("summary") == {
    "suggested": 1, "needs_review": 2, "unmatched": 1
}, "native API returns same deterministic preview as Web")
check(all("source_fingerprint" not in item and "content_fingerprint" not in item
          for item in payload.get("items", [])), "API hides bank HMAC fingerprints")

status, result = get(admin, base + "/preview?company_id=2&connection_id=999999")
check(status == 404, "unknown connection ID rejected via API")
status, result = get(admin, base + "/connections?company_id=3")
check(status in (401, 403, 404), "company2 API user cannot request company3")

other = token("company3-credentials.local")
status, result = get(other, base + "/connections?company_id=3")
check(status == 200 and len(result.get("data", [])) >= 1,
      "second-company API user can access own bank connections")
check(all(row.get("account_name") != "Sicoob - Teste de Conciliação"
          for row in result.get("data", [])), "other-company API has no first-company bank")
status, result = get(other, base + f"/preview?company_id=3&connection_id={fixture_id}")
check(status == 404, "forged foreign bank connection rejected via API")

restricted = token("restricted-credentials.local")
status, result = get(restricted, base + f"/preview?company_id=2&connection_id={fixture_id}")
check(status in (401, 403), "restricted role cannot read banking transactions via API")

entry = next((row for row in payload.get("items", [])
              if row.get("status") == "unmatched" and row.get("stage_state") == "pending"), None)
check(entry is not None, "synthetic unmatched movement exists for API review")
decision = {
    "company_id": 2, "connection_id": fixture_id,
    "entry_id": entry["id"], "operation": "dismiss",
    "request_key": secrets.token_hex(16),
}
status, result = post(admin, base + "/review-decisions?company_id=2", decision)
check(status == 200 and result.get("data", {}).get("state") == "dismissed",
      "API accepts explicit Banking Connectors-only dismissal")
status, result = post(admin, base + "/review-decisions?company_id=2", decision)
check(status == 200 and result.get("data", {}).get("idempotent") is True,
      "API review replay is idempotent")
decision["operation"] = "reopen"
decision["request_key"] = secrets.token_hex(16)
status, result = post(admin, base + "/review-decisions?company_id=2", decision)
check(status == 200 and result.get("data", {}).get("state") == "pending",
      "API reviewer can reopen a prior dismissal without a ledger write")
status, refreshed = get(admin, base + f"/preview?company_id=2&connection_id={fixture_id}")
check(status == 200 and refreshed.get("data", {}).get("summary") == payload.get("summary"),
      "read-only preview unchanged after explicit review and reversal")
print("Native Web/API shared contract verified on localhost with synthetic banking data.")
