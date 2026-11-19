#!/usr/bin/env python3
# SPDX-FileCopyrightText: 2026 LibreCode Coop and contributors
# SPDX-License-Identifier: AGPL-3.0-or-later
"""Inspect redirected routes without logging credentials or session cookies."""
import os
import http.cookiejar
import urllib.error
import urllib.parse
import urllib.request
from html.parser import HTMLParser
class Hidden(HTMLParser):
    def __init__(self): super().__init__(); self.csrf = None
    def handle_starttag(self, tag, attrs):
        d = dict(attrs)
        if tag == "input" and d.get("name") == "_token":
            self.csrf = d.get("value")
class NoRedirect(urllib.request.HTTPRedirectHandler):
    def redirect_request(self, req, fp, code, msg, headers, newurl): return None
base = "http://127.0.0.1:18789"
path = os.environ.get("BANKING_CONNECTORS_LOCAL_CREDS", "")
if not path or not path.endswith("admin-credentials.local"): raise RuntimeError("Local credentials path required")
email, password = open(path, encoding="utf-8").read().splitlines()
if not email.endswith("@localhost.invalid"): raise RuntimeError("Wrong auth source")
jar = http.cookiejar.CookieJar()
opener = urllib.request.build_opener(urllib.request.HTTPCookieProcessor(jar))
no_redirect = urllib.request.build_opener(urllib.request.HTTPCookieProcessor(jar), NoRedirect)
p = Hidden()
p.feed(opener.open(base+"/auth/login").read().decode(errors="replace"))
body=urllib.parse.urlencode({"_token":p.csrf,"email":email,"password":password}).encode()
response=opener.open(urllib.request.Request(base+"/auth/login", data=body,headers={"Content-Type":"application/x-www-form-urlencoded"}))
print("LOGIN_PATH",urllib.parse.urlparse(response.url).path)
for route in ["/2","/2/banking-connectors","/2/banking/transactions","/2/settings/company","/2/banking-connectors?connection_id=999999","/999/banking-connectors"]:
    for ajax in [False, True] if route == "/2/banking-connectors" else [False]:
        request = urllib.request.Request(base+route, headers={"X-Requested-With":"XMLHttpRequest"} if ajax else {})
        try:
            x=no_redirect.open(request,timeout=12)
        except urllib.error.HTTPError as error:
            x=error
        print("ROUTE",route,"ajax",ajax,"status",x.code,"location_path",urllib.parse.urlparse(x.headers.get("Location","")).path)
