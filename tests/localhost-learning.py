#!/usr/bin/env python3
# SPDX-FileCopyrightText: 2026 LibreCode Coop and contributors
# SPDX-License-Identifier: AGPL-3.0-or-later
"""End-to-end synthetic ML learning feedback in authenticated bank review."""
import http.cookiejar
import os
import subprocess
import urllib.parse
import urllib.request
from html.parser import HTMLParser

BASE = "http://127.0.0.1:18789"
DIR = os.environ.get("BANKING_CONNECTORS_LOCAL_FIXTURE_DIR", "")
if not DIR.endswith("/akaunting-banksync-local"):
    raise RuntimeError("Refusing to operate outside the synthetic Akaunting")
env = open(DIR + "/app-root/.env",encoding="utf-8").read()
for required in ("APP_ENV=local","DB_DATABASE=akaunting_synthetic",
                 "DB_HOST=banksync-local-db","DB_PREFIX=ak_"):
    if required not in env.splitlines():
        raise RuntimeError("Invalid synthetic database identity")
with open(DIR + "/admin-credentials.local",encoding="utf-8") as f:
    email,password=f.read().splitlines()[:2]
if not email.endswith("@localhost.invalid"):
    raise RuntimeError("Non-synthetic credentials refused")

def local_fixture(action):
    cmd=["docker","exec","-e","BANKING_CONNECTORS_ISOLATED_LOCAL=yes",
        "-e","BANKING_CONNECTORS_APP_ROOT=/var/www/html",
        "lcc-banksync-local-php","php",
        "modules/BankingConnectors/tests/local-learning-fixture.php",action]
    result=subprocess.run(cmd,capture_output=True,text=True,timeout=45)
    if result.returncode:
        raise RuntimeError("Synthetic learning fixture failed: "+result.stderr[-500:])
    return result.stdout

class CSRF(HTMLParser):
    def __init__(self):
        super().__init__()
        self.value=None
    def handle_starttag(self,tag,attrs):
        d=dict(attrs)
        if tag=="input" and d.get("name")=="_token":
            self.value=d.get("value")

jar=http.cookiejar.CookieJar()
client=urllib.request.build_opener(urllib.request.HTTPCookieProcessor(jar))
form=client.open(BASE+"/auth/login",timeout=20)
token=CSRF()
token.feed(form.read().decode(errors="replace"))
if not token.value: raise RuntimeError("Missing synthetic login CSRF")
client.open(urllib.request.Request(
    BASE+"/auth/login",method="POST",
    data=urllib.parse.urlencode({"_token":token.value,"email":email,
                                 "password":password}).encode(),
    headers={"Content-Type":"application/x-www-form-urlencoded"}),timeout=20).read()
page=client.open(BASE+"/2/banking/accounts/3/integration?tab=rules",
                 timeout=20).read().decode(errors="replace")
assert "Aprendizado com classificações confirmadas" in page

local_fixture("cleanup")
try:
    local_fixture("seed")
    rulepage=client.open(BASE+"/2/banking/accounts/3/integration?tab=rules",
                         timeout=20).read().decode(errors="replace")
    if "16 movimentações classificadas manualmente" not in rulepage:
        raise RuntimeError("Learning status did not register 16 confirmed cases")
    review=client.open(BASE+"/2/banking/accounts/3/integration?tab=review",
                       timeout=20).read().decode(errors="replace")
    if "Categoria sugerida pelo histórico" not in review or "BC ML Transport Test" not in review:
        raise RuntimeError("Authenticated UI did not suggest learned transport category")
    if "não uma probabilidade calibrada" not in review:
        raise RuntimeError("ML strength must never masquerade as a calibrated probability")
    print("PASS authentic account UI shows synthetic learned category and explicit evidence")
    print("PASS training samples remain isolated and require human confirmation")
finally:
    local_fixture("cleanup")
    after=client.open(BASE+"/2/banking/accounts/3/integration?tab=rules",
                      timeout=20).read().decode(errors="replace")
    if "16 movimentações classificadas manualmente" in after:
        raise RuntimeError("Synthetic training fixture was not fully cleaned up")
    print("PASS synthetic training examples cleaned after authenticated test")
