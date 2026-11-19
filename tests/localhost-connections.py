#!/usr/bin/env python3
# SPDX-FileCopyrightText: 2026 LibreCode Coop and contributors
# SPDX-License-Identifier: AGPL-3.0-or-later
"""Real Web connection-control HTTP contract on synthetic company 2."""
import os
import json
import subprocess
import atexit
import secrets
import urllib.request
import urllib.error
import urllib.parse
import http.cookiejar
from html.parser import HTMLParser

BASE = "http://127.0.0.1:18789"
ROOT = os.environ.get("BANKING_CONNECTORS_LOCAL_FIXTURE_DIR", "")
if not ROOT.endswith("/akaunting-banksync-local"):
    raise RuntimeError("Only isolated local-test tests are permitted")

class Csrf(HTMLParser):
    def __init__(self):
        super().__init__()
        self.token = None
    def handle_starttag(self, tag, attrs):
        d = dict(attrs)
        if tag == "input" and d.get("name") == "_token":
            self.token = d.get("value")

class NoRedirect(urllib.request.HTTPRedirectHandler):
    def redirect_request(self, req, fp, code, msg, headers, newurl):
        return None

def check(value, message):
    if not value:
        raise RuntimeError("FAIL " + message)
    print("PASS " + message)

def auth(name):
    email, password = open(ROOT + "/" + name, encoding="utf-8").read().splitlines()
    if not email.endswith("@localhost.invalid"):
        raise RuntimeError("Untrusted test identity")
    jar = http.cookiejar.CookieJar()
    client = urllib.request.build_opener(urllib.request.HTTPCookieProcessor(jar))
    view = client.open(BASE + "/auth/login", timeout=20)
    p = Csrf()
    p.feed(view.read().decode(errors="replace"))
    check(bool(p.token), "synthetic user receives native CSRF token")
    login = urllib.request.Request(BASE + "/auth/login",
        data=urllib.parse.urlencode({
            "_token": p.token, "email": email, "password": password
        }).encode(),
        headers={"Content-Type": "application/x-www-form-urlencoded"})
    client.open(login, timeout=20).read()
    xsrf = next((urllib.parse.unquote(cookie.value) for cookie in jar
                 if cookie.name == 'XSRF-TOKEN'), None)
    return client, urllib.request.build_opener(
        urllib.request.HTTPCookieProcessor(jar), NoRedirect
    ), xsrf

def post(client, url, data, xsrf=None):
    headers = {"X-Requested-With": "XMLHttpRequest",
               "Accept": "application/json",
               "Content-Type": "application/x-www-form-urlencoded"}
    if xsrf:
        headers['X-XSRF-TOKEN'] = xsrf
    request = urllib.request.Request(
        BASE + url, data=urllib.parse.urlencode(data).encode(), method="POST",
        headers=headers,
    )
    try:
        reply = client.open(request, timeout=20)
    except urllib.error.HTTPError as error:
        reply = error
    raw = reply.read()
    try:
        body = json.loads(raw.decode("utf-8"))
    except (ValueError, UnicodeError):
        body = {}
    return reply.code, body

admin, admin_no_redirect, admin_xsrf = auth("admin-credentials.local")
page = admin.open(BASE + "/2/banking-connectors", timeout=20)
html = page.read().decode(errors="replace")
parser = Csrf()
parser.feed(html)
check(page.status == 200 and parser.token and "Banking Connectors" in html,
      "authorized Web connection panel accessible")
check("Adicionar conexão bancária" in html and 'name="provider"' in html,
      "Web UI exposes registered-bank connection management")
check("client-key-password" not in html and "fingerprint-key" not in html,
      "Web panel contains no private credential filenames or secret material")

code, _ = post(admin_no_redirect, "/2/banking-connectors/connections", {"account_id": 4})
check(code == 419, "missing CSRF cannot create a connection")

code, body = post(admin_no_redirect, "/2/banking-connectors/connections",
                  {"_token": parser.token, "account_id": 4})
check(code == 201 and int(body.get("data", {}).get("id", 0)) > 0,
      "authorized user creates company-scoped paused bank connection")
connection_id = int(body["data"]["id"])
url = "/2/banking-connectors/connections/" + str(connection_id)
atexit.register(lambda: post(admin_no_redirect, url + '/state',
                             {'_token': parser.token, 'state': 'paused'}))
# Recover a stale request left by an interrupted test on this synthetic fixture.
code, _ = post(admin_no_redirect, url + '/state',
               {'_token': parser.token, 'state': 'paused'})
check(code == 200, 'synthetic fixture resets pending queue before test')

code, body = post(admin_no_redirect, "/2/banking-connectors/connections",
                  {"_token": parser.token, "account_id": 4})
check(code == 201 and body.get("data", {}).get("created") is False,
      "duplicate create request is idempotent")

code, body = post(admin_no_redirect, url + "/state",
                  {"_token": parser.token, "state": "enabled"})
check(code == 409 and body.get("error") == "automatic_sync_requires_verified_first_read",
      "cannot enable automatic polling without verified initial read")

code, body = post(admin_no_redirect, url + "/state",
                  {"_token": parser.token, "state": "read_only"})
check(code == 200 and body.get("data", {}).get("state") == "read_only",
      "manual-only bank connection enabled via authorized Web")

code, body = post(admin_no_redirect, url + "/request-sync",
                  {"_token": parser.token, "start_date": "2099-01-01",
                   "request_key": secrets.token_hex(16)})
check(code == 409 and body.get("error") == "future_statement_date_not_allowed",
      "request for future transactions rejected without bank call")

key = secrets.token_hex(16)
request = {"_token": parser.token, "start_date": "2026-11-16", "request_key": key}
code, body = post(admin_no_redirect, url + "/request-sync", request)
check(code == 202 and body.get("data", {}).get("queued") is True
      and body.get("data", {}).get("idempotent") is False
      and body.get("data", {}).get("async_dispatch") == "not_configured",
      "Web queues first synchronization without calling the bank in sync-mode HTTP")
code, body = post(admin_no_redirect, url + "/request-sync", request)
check(code == 202 and body.get("data", {}).get("idempotent") is True,
      "identical sync request is idempotent over HTTP")

code, body = post(admin_no_redirect, url + "/request-sync", {
    **request, "request_key": secrets.token_hex(16),
})
check(code == 409 and body.get("error") == "banking_connectors_already_requested",
      "Web refuses competing queued requests")

# Worker failure is deliberate: the account-4 fixture has no Sicoob secret
# mount, therefore no network request can be attempted.
run = subprocess.run([
    "docker", "exec", "lcc-banksync-local-php", "php", "artisan",
    "banking-connectors:poll-all", "--max=1", "--no-ansi",
], capture_output=True, timeout=50)
check(run.returncode == 1 and b"partial_failure" in run.stdout,
      "queued worker fails safely without bank credentials")
inspect = subprocess.run([
    "docker", "exec", "lcc-banksync-local-php", "php", "artisan",
    "banking-connectors:poll-all", "--check-eligible", "--no-ansi",
], capture_output=True, timeout=20, check=True)
status = json.loads(inspect.stdout.decode().strip().splitlines()[-1])
check(status["eligible_connections"] == 0,
      "failed worker is deferred by retry backoff, not immediately re-polled")

restricted, restricted_client, restricted_xsrf = auth("restricted-credentials.local")
check(bool(restricted_xsrf), 'restricted user receives session CSRF token')
code, _ = post(restricted_client, url + "/state",
               {"state": "enabled"}, xsrf=restricted_xsrf)
check(code in (401, 403, 404), "restricted user cannot change bank state")

other, other_client, other_xsrf = auth("company3-credentials.local")
check(bool(other_xsrf), 'other company admin receives session CSRF token')
code, _ = post(other_client, "/3/banking-connectors/connections/" + str(connection_id) + "/state",
               {"state": "paused"}, xsrf=other_xsrf)
check(code in (401, 403, 404), "foreign-company user cannot alter another company's connection")

try:
    code, body = post(admin_no_redirect, url + "/state",
                      {"_token": parser.token, "state": "paused"})
    check(code == 200 and body.get("data", {}).get("state") == "paused",
          "pausing cancels the test-only queued request")
finally:
    # Never leave a local synthetic bank sync request queued by this test.
    post(admin_no_redirect, url + "/state",
         {"_token": parser.token, "state": "paused"})

print("Native authenticated connection controls passed with no bank calls.")
