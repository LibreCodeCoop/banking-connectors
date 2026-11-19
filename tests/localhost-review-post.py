#!/usr/bin/env python3
# SPDX-FileCopyrightText: 2026 LibreCode Coop and contributors
# SPDX-License-Identifier: AGPL-3.0-or-later
"""Local-only CSRF, auth, authorization and idempotent review HTTP smoke."""
import json
import os
import secrets
import http.cookiejar
import urllib.error
import urllib.parse
import urllib.request
from html.parser import HTMLParser

BASE = "http://127.0.0.1:18789"
CREDS = os.environ.get("BANKING_CONNECTORS_LOCAL_CREDS", "")
COMPANY = os.environ.get("BANKING_CONNECTORS_LOCAL_COMPANY_ID", "")
if not CREDS.endswith("admin-credentials.local") or COMPANY != "2":
    raise RuntimeError("Only isolated local-test company 2 fixture is supported")
email, password = open(CREDS, encoding="utf-8").read().splitlines()
if not email.endswith("@localhost.invalid"):
    raise RuntimeError("Refusing unknown identity source")
URL = BASE + f"/{COMPANY}/banking-connectors"
POST = URL + "/review-decisions"

class Html(HTMLParser):
    def __init__(self):
        super().__init__()
        self.forms = []
        self.current = None
        self.csrf = None
        self.select_name = None
    def handle_starttag(self, tag, attrs):
        d = dict(attrs)
        if tag == "form":
            self.current = {"action": d.get("action"), "fields": {}}
        if tag == "select":
            self.select_name = d.get("name")
        if tag == "option" and self.current is not None and self.select_name == "native_transaction_id":
            value = d.get("value", "")
            if value.isdigit() and not self.current["fields"].get("native_transaction_id"):
                self.current["fields"]["native_transaction_id"] = value
        if tag == "input":
            if d.get("name") == "_token":
                self.csrf = d.get("value")
            if self.current is not None and d.get("name"):
                self.current["fields"][d["name"]] = d.get("value", "")
    def handle_endtag(self, tag):
        if tag == "select":
            self.select_name = None
        if tag == "form" and self.current is not None:
            self.forms.append(self.current)
            self.current = None

def assert_http(condition, message):
    if not condition:
        raise RuntimeError("FAIL " + message)
    print("PASS " + message)

class NoRedirect(urllib.request.HTTPRedirectHandler):
    def redirect_request(self, req, fp, code, msg, headers, newurl):
        return None

cookies = http.cookiejar.CookieJar()
client = urllib.request.build_opener(urllib.request.HTTPCookieProcessor(cookies))
no_redirect = urllib.request.build_opener(
    urllib.request.HTTPCookieProcessor(cookies), NoRedirect
)
def login():
    parser = Html()
    parser.feed(client.open(BASE + "/auth/login", timeout=15).read().decode(errors="replace"))
    assert_http(bool(parser.csrf), "login CSRF token exists")
    body = urllib.parse.urlencode({"_token": parser.csrf, "email": email, "password": password}).encode()
    client.open(urllib.request.Request(BASE + "/auth/login", data=body,
        headers={"Content-Type": "application/x-www-form-urlencoded"}), timeout=15).read()

def post(payload, ajax=True, token=True):
    values = dict(payload)
    if not token:
        values.pop("_token", None)
    headers = {"Content-Type": "application/x-www-form-urlencoded"}
    if ajax:
        headers.update({"X-Requested-With": "XMLHttpRequest", "Accept": "application/json"})
    request = urllib.request.Request(POST, data=urllib.parse.urlencode(values).encode(),
                                     headers=headers, method="POST")
    try:
        response = no_redirect.open(request, timeout=15)
    except urllib.error.HTTPError as error:
        response = error
    raw = response.read()
    try:
        body = json.loads(raw.decode("utf-8"))
    except (ValueError, UnicodeDecodeError):
        body = {}
    return response.code, body, response.headers.get("Location", "")

unauthenticated, _, _ = post({"operation": "dismiss", "entry_id": 1,
    "connection_id": 1, "request_key": secrets.token_hex(16)})
assert_http(unauthenticated in (302, 401, 403, 419),
            "anonymous POST cannot register review decision")

login()
page = client.open(URL, timeout=15)
html = page.read().decode(errors="replace")
parser = Html()
parser.feed(html)
assert_http(page.status == 200 and bool(parser.csrf),
            "authenticated review page renders CSRF-enabled POST forms")
forms = [f for f in parser.forms if f["fields"].get("operation") == "accept"
         and f["fields"].get("native_transaction_id")]
assert_http(bool(forms), "review UI offers explicit manual candidate selection")
fields = dict(forms[0]["fields"])
assert_http(fields.get("request_key") is not None
            and (fields.get("connection_id") or "").isdigit()
            and int(fields["connection_id"]) > 0,
            "review form supplies its actual scoped connection and idempotency key")
missing_csrf, _, _ = post(fields, token=False)
assert_http(missing_csrf == 419, "CSRF rejects financial review POST without token")

bad = dict(fields)
bad["request_key"] = secrets.token_hex(16)
bad["native_transaction_id"] = "999999"
invalid, bad_body, _ = post(bad)
assert_http(invalid == 409 and bad_body.get("error") == "native_transaction_not_eligible",
            "unrelated native transaction cannot be accepted")

status, result, _ = post(fields)
assert_http(status == 200 and result.get("state") == "reviewed" and not result.get("idempotent"),
            "explicit authorized POST records Banking Connectors-only match")
second_status, second_result, _ = post(fields)
assert_http(second_status == 200 and second_result.get("idempotent") is True,
            "repeated review POST returns idempotent result")

reopen = {
    "_token": fields["_token"],
    "connection_id": fields["connection_id"],
    "entry_id": fields["entry_id"],
    "operation": "reopen",
    "request_key": secrets.token_hex(16),
}
reopen_status, reopen_body, _ = post(reopen)
assert_http(reopen_status == 200 and reopen_body.get("state") == "pending",
            "explicit reversal restores pending review without financial writes")

final = client.open(URL, timeout=15)
final_html = final.read().decode(errors="replace")
assert_http(final.status == 200 and "Sicoob - Teste de Conciliação" in final_html,
            "Banking Connectors review page remains available after recorded and reversed decision")
print("Localhost POST review smoke passed; only module-owned synthetic records changed.")
