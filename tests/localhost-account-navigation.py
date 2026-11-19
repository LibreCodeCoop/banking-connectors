#!/usr/bin/env python3
# SPDX-FileCopyrightText: 2026 LibreCode Coop and contributors
# SPDX-License-Identifier: AGPL-3.0-or-later
"""Authenticated bank-account menu and scoped-route regression on synthetic Akaunting."""
import http.cookiejar
from html.parser import HTMLParser
import os
import urllib.error
import urllib.parse
import urllib.request

BASE = "http://127.0.0.1:18789"
FIXTURE = os.environ.get("BANKING_CONNECTORS_LOCAL_FIXTURE_DIR", "")
if not FIXTURE.endswith("/akaunting-banksync-local"):
    raise RuntimeError("Refusing to test outside the isolated local fixture")

class NoRedirect(urllib.request.HTTPRedirectHandler):
    def redirect_request(self, req, fp, code, msg, headers, newurl):
        return None

class Links(HTMLParser):
    def __init__(self):
        super().__init__()
        self.anchors = {}
        self.tokens = []
        self.contents = {}
        self.current_id = None
        self.active_bank_action = None

    def handle_starttag(self, tag, attrs):
        attrs = dict(attrs)
        if tag == "input" and attrs.get("name") == "_token":
            self.tokens.append(attrs.get("value"))
        if attrs.get("id", "").startswith("index-line-actions-bank-integration-account-"):
            self.active_bank_action = attrs["id"]
            self.contents[self.active_bank_action] = ""
        if tag == "a":
            self.current_id = self.active_bank_action or attrs.get("id")
            if self.current_id:
                self.anchors[self.current_id] = attrs.get("href")
                self.contents.setdefault(self.current_id, "")

    def handle_data(self, data):
        if self.current_id in self.contents:
            self.contents[self.current_id] += data

    def handle_endtag(self, tag):
        if tag == "a":
            self.current_id = None
            self.active_bank_action = None

def require(value, label):
    if not value:
        raise RuntimeError("FAIL " + label)
    print("PASS " + label)

def login(filename):
    with open(FIXTURE + "/" + filename, encoding="utf-8") as file:
        email, password = file.read().splitlines()[:2]
    if not email.endswith("@localhost.invalid"):
        raise RuntimeError("Refusing non-synthetic login")
    jar = http.cookiejar.CookieJar()
    client = urllib.request.build_opener(urllib.request.HTTPCookieProcessor(jar))
    response = client.open(BASE + "/auth/login", timeout=20)
    markup = response.read().decode("utf-8", errors="replace")
    parser = Links()
    parser.feed(markup)
    require(bool(parser.tokens), "synthetic login form includes CSRF token")
    req = urllib.request.Request(BASE + "/auth/login",
        data=urllib.parse.urlencode({
            "_token": parser.tokens[0],
            "email": email,
            "password": password,
        }).encode(),
        headers={"Content-Type": "application/x-www-form-urlencoded"})
    client.open(req, timeout=20).read()
    return client, urllib.request.build_opener(
        urllib.request.HTTPCookieProcessor(jar), NoRedirect
    )

def fetch(client, path):
    try:
        response = client.open(BASE + path, timeout=30)
        return response.status, response.read().decode("utf-8", errors="replace")
    except urllib.error.HTTPError as err:
        return err.code, err.read().decode("utf-8", errors="replace")

admin, admin_no_redirect = login("admin-credentials.local")
status, html = fetch(admin, "/2/banking/accounts")
require(status == 200, "native bank accounts listing is available to admin")
require('id="menu-banking-connectors"' not in html and
        'id="menu-bank-integration"' not in html,
        "bank integration is absent from the global sidebar")
parser = Links()
parser.feed(html)
for account_id in (3, 4):
    ident = "index-line-actions-bank-integration-account-" + str(account_id)
    require(parser.anchors.get(ident, "").endswith(
        "/2/banking/accounts/" + str(account_id) + "/integration"
    ), "connected bank account %d links to its own integration" % account_id)
    require("Integração bancária" in parser.contents.get(ident, ""),
            "connected account %d uses localized integration action" % account_id)

ident = "index-line-actions-bank-integration-account-2"
require(parser.anchors.get(ident, "").endswith("/2/banking/accounts/2/integration"),
        "unconnected bank account has its own onboarding link")
require("Conectar banco" in parser.contents.get(ident, ""),
        "unconnected account uses the distinct onboarding label")
require("index-line-actions-bank-integration-account-6" not in parser.anchors,
        "foreign company's bank account does not appear in menu")

for account_id in (3, 4):
    status, html = fetch(admin, "/2/banking/accounts/%d/integration" % account_id)
    require(status == 200 and 'id="banking-connectors-account-context"' in html,
            "account %d opens tenant-scoped integration page" % account_id)
    require("Integração bancária" in html,
            "account %d uses localized page title" % account_id)
    require('href="http://127.0.0.1:18789/2/banking/accounts/%d" id="banking-connectors-back-to-account"' % account_id in html,
            "account %d is not silently substituted with a different account" % account_id)

status, html = fetch(admin, "/2/banking/accounts/2/integration")
require(status == 200 and 'name="account_id"' in html and
        'id="banking-connectors-account-context"' in html,
        "unconnected bank account opens scoped creation form")
require('value="2" selected' in html, "new connection form preselects scoped account")

for forbidden in (6, 999999):
    status, response_html = fetch(admin_no_redirect, "/2/banking/accounts/%d/integration" % forbidden)
    require(status in (302, 403, 404) and
            'id="banking-connectors-account-context"' not in response_html,
            "foreign or nonexistent account %d is rejected before rendering data" % forbidden)

# Account 4 has synthetic connector ID 1. It must never be selectable
# through the account 3 URL, even by an administrator of both accounts.
status, forbidden_content = fetch(admin_no_redirect,
    "/2/banking/accounts/3/integration?connection_id=1")
require(status in (302, 403, 404) and
        'id="banking-connectors-account-context"' not in forbidden_content,
        "account route blocks selecting another account's connector")

restricted, restricted_no_redirect = login("restricted-credentials.local")
restricted_status, restricted_html = fetch(restricted_no_redirect,
    "/2/banking/accounts/3/integration")
require(restricted_status in (302, 401, 403, 404) and
        'id="banking-connectors-account-context"' not in restricted_html,
        "unauthorized synthetic user cannot view account's bank connector")
restricted_listing_status, restricted_listing = fetch(restricted_no_redirect,
    "/2/banking/accounts")
if restricted_listing_status == 200:
    require("index-line-actions-bank-integration-account-" not in restricted_listing,
            "restricted actor does not receive bank integration menu actions")

second, second_no_redirect = login("company3-credentials.local")
status, own_html = fetch(second, "/3/banking/accounts")
require(status == 200, "second company opens native account list")
own_actions = Links()
own_actions.feed(own_html)
require(own_actions.anchors.get("index-line-actions-bank-integration-account-6", "")
        .endswith("/3/banking/accounts/6/integration"),
        "second company's bank connector has its own account link")
require("index-line-actions-bank-integration-account-3" not in own_actions.anchors,
        "second company cannot see first company's integration menu")
status, scoped_html = fetch(second, "/3/banking/accounts/6/integration")
require(status == 200 and
        'href="http://127.0.0.1:18789/3/banking/accounts/6" id="banking-connectors-back-to-account"' in scoped_html,
        "second company's account route preserves its own bank account")
status, rejected_html = fetch(second_no_redirect, "/3/banking/accounts/3/integration")
require(status in (302, 403, 404) and
        'id="banking-connectors-account-context"' not in rejected_html,
        "second company cannot open first company's bank account")

status, html = fetch(admin, "/2/banking-connectors")
require(status == 200, "legacy bank connector URL remains available")

public = urllib.request.build_opener()
status, html = fetch(public, "/2/banking/accounts/3/integration")
require(status in (401, 403, 302) or "/auth/login" in html,
        "unauthenticated account integration is not visible")

print("PASS native dropdown links, localized labels, scoped routes and legacy compatibility")
