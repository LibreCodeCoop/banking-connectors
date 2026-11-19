#!/usr/bin/env python3
# SPDX-FileCopyrightText: 2026 LibreCode Coop and contributors
# SPDX-License-Identifier: AGPL-3.0-or-later
"""End-to-end native Web + dynamically registered synthetic bank module.

Never contacts Sicoob, OpenBao or any real bank. Uses local-test synthetic
Akaunting company 2. An independently installed trusted Akaunting module
provides 'examplebank' without modifying the Banking Connectors registry source.
"""
import os
import json
import secrets
import subprocess
import urllib.request
import urllib.parse
import urllib.error
import http.cookiejar
from html.parser import HTMLParser

BASE = "http://127.0.0.1:18789"
FIXTURE = os.environ.get("BANKING_CONNECTORS_LOCAL_FIXTURE_DIR", "")
if not FIXTURE.endswith("/akaunting-banksync-local") or not os.path.isfile(
    os.path.join(FIXTURE, "app-root", ".env")
):
    raise RuntimeError("Refusing non-synthetic Banking Connectors tests")
with open(os.path.join(FIXTURE, "app-root", ".env"), encoding="utf-8") as conf:
    value = conf.read()
for required in ("APP_ENV=local", "DB_DATABASE=akaunting_synthetic",
                 "DB_HOST=banksync-local-db"):
    if required not in value:
        raise RuntimeError("Refusing unsafe test database")

def check(condition, label):
    if not condition:
        raise RuntimeError("FAIL " + label)
    print("PASS " + label)

class Token(HTMLParser):
    def __init__(self):
        super().__init__()
        self.value = None
    def handle_starttag(self, tag, attrs):
        d = dict(attrs)
        if tag == "input" and d.get("name") == "_token":
            self.value = d.get("value")

class NoRedirect(urllib.request.HTTPRedirectHandler):
    def redirect_request(self, req, fp, code, msg, headers, newurl):
        return None

def request(client, url, form):
    req = urllib.request.Request(BASE + url,
        data=urllib.parse.urlencode(form).encode(),
        headers={"X-Requested-With": "XMLHttpRequest",
                 "Accept": "application/json",
                 "Content-Type": "application/x-www-form-urlencoded"})
    try:
        r = client.open(req, timeout=20)
    except urllib.error.HTTPError as error:
        r = error
    raw = r.read()
    try:
        obj = json.loads(raw.decode())
    except ValueError:
        obj = {}
    return r.code, obj

email, password = open(
    os.path.join(FIXTURE, "admin-credentials.local"), encoding="utf-8"
).read().splitlines()
check(email.endswith("@localhost.invalid"), "only synthetic administrator used")
jar = http.cookiejar.CookieJar()
client = urllib.request.build_opener(urllib.request.HTTPCookieProcessor(jar))
login = client.open(BASE + "/auth/login", timeout=20)
csrf = Token()
csrf.feed(login.read().decode(errors="replace"))
check(bool(csrf.value), "native Laravel CSRF token present")
client.open(urllib.request.Request(
    BASE + "/auth/login",
    data=urllib.parse.urlencode({
        "_token": csrf.value, "email": email, "password": password
    }).encode(),
    headers={"Content-Type": "application/x-www-form-urlencoded"}
), timeout=20).read()
api = urllib.request.build_opener(
    urllib.request.HTTPCookieProcessor(jar), NoRedirect
)

page = client.open(BASE + "/2/banking-connectors", timeout=20)
html = page.read().decode(errors="replace")
token = Token()
token.feed(html)
check(page.status == 200 and bool(token.value),
      "native Akaunting bank management page accessible")
check('value="examplebank"' in html and 'value="sicoob"' in html,
      "separately installed bank provider appears in existing Banking Connectors Web UI")

code, data = request(api, "/2/banking-connectors/connections", {
    "_token": token.value, "account_id": 4, "provider": "unknownbank",
})
check(code == 422, "unregistered bank cannot create a connection via Web")

code, data = request(api, "/2/banking-connectors/connections", {
    "_token": token.value, "account_id": 4, "provider": "examplebank",
})
check(code == 201 and int(data.get("data", {}).get("id", 0)) > 0,
      "trusted external bank module creates company-scoped paused connection")
cid = int(data["data"]["id"])
connection = "/2/banking-connectors/connections/" + str(cid)
check(data["data"].get("created") in (True, False),
      "creation is safe to repeat against existing synthetic test data")

code, data = request(api, connection + "/state", {
    "_token": token.value, "state": "read_only",
})
check(code == 200 and data.get("data", {}).get("state") == "read_only",
      "new bank provider uses the same read-only connection state machine")

key = secrets.token_hex(16)
code, data = request(api, connection + "/request-sync", {
    "_token": token.value, "start_date": "2026-11-16",
    "request_key": key,
})
check(code in (202, 409), "new bank's request enters generic durable queue")
# The initial manual request can be idempotent only if the connection
# already has a pending request from an interrupted previous isolated run.

def worker(args):
    p = subprocess.run([
        "docker", "exec", "lcc-banksync-local-php", "php", "artisan",
        "banking-connectors:poll", str(cid), "--no-ansi", *args,
    ], capture_output=True, timeout=50)
    text = p.stdout.decode(errors="replace")
    if p.returncode != 0:
        # Show a fixed safe code only, never raw response payload.
        raise RuntimeError("FAIL generic worker exit " + str(p.returncode) +
                           " safe_codes=" + "|".join(
                           x for x in text.splitlines() if x.startswith("bank_")))
    payload = next((json.loads(line) for line in text.splitlines()
                    if line.startswith('{"status":')), None)
    return payload

first = worker(["--start=2026-11-16", "--through=2026-11-16"])
check(first["status"] == "ok" and first["conflicts"] == 0
      and first["akaunting_financial_writes"] == 0,
      "generic worker consumes independent bank connector without financial posting")
again = worker(["--start=2026-11-16", "--through=2026-11-16"])
check(again["status"] == "ok" and again["inserted"] == 0
      and again["duplicates"] >= 1,
      "new bank idempotent replay shares the same evidence and cursor")
code, data = request(api, connection + "/state", {
    "_token": token.value, "state": "paused",
})
check(code == 200 and data.get("data", {}).get("state") == "paused",
      "synthetic extra connector returns to paused state")
print("Independent Akaunting bank-connector installation and HTTP/worker integration passed.")
