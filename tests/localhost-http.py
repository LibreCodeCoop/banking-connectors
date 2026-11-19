#!/usr/bin/env python3
# SPDX-FileCopyrightText: 2026 LibreCode Coop and contributors
# SPDX-License-Identifier: AGPL-3.0-or-later
"""Local-only browser/HTTP smoke for a disposable Akaunting installation."""
import os
import http.cookiejar
import urllib.error
import urllib.parse
import urllib.request
from html.parser import HTMLParser

class Csrf(HTMLParser):
    def __init__(self):
        super().__init__()
        self.token = None

    def handle_starttag(self, tag, attrs):
        attributes = dict(attrs)
        if tag == "input" and attributes.get("name") == "_token":
            self.token = attributes.get("value")

def verify(ok, msg):
    if not ok:
        raise RuntimeError("FAIL " + msg)
    print("PASS " + msg)

url = os.environ.get("BANKING_CONNECTORS_LOCAL_URL", "http://127.0.0.1:18789").rstrip("/")
creds_file = os.environ.get("BANKING_CONNECTORS_LOCAL_CREDS", "")
company_id = os.environ.get("BANKING_CONNECTORS_LOCAL_COMPANY_ID", "")
if url != "http://127.0.0.1:18789" or not company_id.isdigit() or not creds_file:
    raise RuntimeError("Dedicated local-test local URL, synthetic company and credential file required")
email, password = open(creds_file, encoding="utf-8").read().splitlines()
if not email.endswith("@localhost.invalid"):
    raise RuntimeError("Refusing credentials outside synthetic local domain")
admin_path = f"/{company_id}/banking-connectors"

class NoRedirect(urllib.request.HTTPRedirectHandler):
    def redirect_request(self, req, fp, code, msg, headers, newurl):
        return None

guest_opener = urllib.request.build_opener(NoRedirect)
try:
    guest_opener.open(url + admin_path, timeout=15)
    raise RuntimeError("FAIL anonymous admin request unexpectedly succeeded")
except urllib.error.HTTPError as error:
    verify(error.code in (302, 401, 403), "anonymous review requires authentication")

cookies = http.cookiejar.CookieJar()
opener = urllib.request.build_opener(urllib.request.HTTPCookieProcessor(cookies))
login = opener.open(url + "/auth/login", timeout=15)
parser = Csrf()
parser.feed(login.read().decode(errors="replace"))
verify(bool(parser.token), "login form renders CSRF token")
body = urllib.parse.urlencode({"_token": parser.token, "email": email, "password": password}).encode()
request = urllib.request.Request(
    url + "/auth/login",
    data=body,
    headers={"Content-Type": "application/x-www-form-urlencoded", "Referer": url + "/auth/login"},
)
opener.open(request, timeout=15).read()
page = opener.open(url + admin_path, timeout=15)
html = page.read().decode(errors="replace")
verify(page.status == 200 and urllib.parse.urlparse(page.url).path == admin_path,
       "authenticated administrator accesses native Banking Connectors route")
verify(("Banking Connectors" in html),
       "native Blade view renders its translated title")
verify("Sicoob - Teste de Conciliação" in html,
       "account-scoped synthetic connection visible")
verify("LOCAL-CR-0001" not in html and "local-fixture-only" not in html,
       "bank source IDs and HMAC test key never appear in UI")

# Akaunting's normal HTML error handler redirects back on 404. For a
# direct status assertion, use its documented AJAX error representation.
try:
    opener.open(urllib.request.Request(
        url + admin_path + "?connection_id=999999",
        headers={"X-Requested-With": "XMLHttpRequest", "Accept": "application/json"},
    ), timeout=15)
    raise RuntimeError("FAIL nonexistent bank connection accepted")
except urllib.error.HTTPError as error:
    verify(error.code == 404, "connection from outside the company-bound list denied")

other_company = str(int(company_id) + 1)
no_redirect = urllib.request.build_opener(
    urllib.request.HTTPCookieProcessor(cookies), NoRedirect
)
try:
    page = no_redirect.open(url + f"/{other_company}/banking-connectors", timeout=15)
    html = page.read().decode(errors="replace")
    verify(page.status != 200 or "Sicoob - Teste de Conciliação" not in html,
           "foreign-company route does not expose the selected company's entries")
except urllib.error.HTTPError as error:
    verify(error.code in (302, 401, 403, 404),
           "foreign-company URL rejected or redirected to authorized context")
print("Localhost HTTP smoke passed; no production writes.")
