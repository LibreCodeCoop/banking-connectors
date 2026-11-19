#!/usr/bin/env python3
# SPDX-FileCopyrightText: 2026 LibreCode Coop and contributors
# SPDX-License-Identifier: AGPL-3.0-or-later
"""Independent second-company and restricted-role HTTP denial tests."""
import os
import http.cookiejar
import urllib.error
import urllib.parse
import urllib.request
from html.parser import HTMLParser

URL = "http://127.0.0.1:18789"
ROOT = os.environ.get("BANKING_CONNECTORS_LOCAL_FIXTURE_DIR", "")
if not ROOT.endswith("/akaunting-banksync-local"):
    raise RuntimeError("Refusing non-synthetic localhost credentials")
class CSRF(HTMLParser):
    def __init__(self): super().__init__(); self.value = None
    def handle_starttag(self, tag, attrs):
        d = dict(attrs)
        if tag == "input" and d.get("name") == "_token":
            self.value = d.get("value")
class NoRedirect(urllib.request.HTTPRedirectHandler):
    def redirect_request(self, req, fp, code, msg, headers, newurl): return None
def status(opener, path, headers=None):
    req = urllib.request.Request(URL + path, headers=headers or {})
    try:
        res = opener.open(req, timeout=15)
    except urllib.error.HTTPError as error:
        res = error
    return (res.code, res.read().decode(errors="replace"),
            urllib.parse.urlparse(res.headers.get("Location", "")).path)
def assert_case(ok, msg):
    if not ok: raise RuntimeError("FAIL " + msg)
    print("PASS " + msg)
def login(filename):
    email, password = open(ROOT + "/" + filename, encoding="utf-8").read().splitlines()
    if not email.endswith("@localhost.invalid"): raise RuntimeError("Unexpected user fixture")
    jar = http.cookiejar.CookieJar()
    opener = urllib.request.build_opener(urllib.request.HTTPCookieProcessor(jar))
    target = urllib.request.build_opener(urllib.request.HTTPCookieProcessor(jar), NoRedirect)
    page = opener.open(URL + "/auth/login", timeout=15)
    p = CSRF(); p.feed(page.read().decode(errors="replace"))
    assert_case(bool(p.value), "synthetic account login form has CSRF")
    request = urllib.request.Request(URL + "/auth/login",
        data=urllib.parse.urlencode({"_token": p.value, "email": email, "password": password}).encode(),
        headers={"Content-Type": "application/x-www-form-urlencoded"})
    opener.open(request, timeout=15).read()
    return target

other = login("company3-credentials.local")
code, html, redirect = status(other, "/3/banking-connectors")
assert_case(code == 200 and "SICOOB - Empresa 3 sintética" in html,
            "second company's authorized admin can access its own staged bank entries")
assert_case("Sicoob - Teste de Conciliação" not in html,
            "second company cannot see first company's bank account")
code, html, redirect = status(other, "/2/banking-connectors")
assert_case((code in (302, 401, 403, 404)) or
            (code == 200 and "Sicoob - Teste de Conciliação" not in html),
            "second-company admin cannot see first-company review queue")
code, html, redirect = status(other, "/3/banking-connectors?connection_id=1",
    {"X-Requested-With": "XMLHttpRequest", "Accept": "application/json"})
assert_case(code == 404, "cross-company connection ID is 404 under authenticated HTTP")

restricted = login("restricted-credentials.local")
code, html, redirect = status(restricted, "/2/banking-connectors")
assert_case((code in (302, 401, 403, 404)) or
            (code == 200 and "Sicoob - Teste de Conciliação" not in html),
            "restricted user cannot read Banking Connectors financial queue")
code, html, redirect = status(restricted, "/3/banking-connectors")
assert_case((code in (302, 401, 403, 404)) or
            (code == 200 and "SICOOB - Empresa 3 sintética" not in html),
            "restricted user cannot read a different company's bank entries")
print("Multi-company authenticated browser smoke passed; synthetic users only.")
