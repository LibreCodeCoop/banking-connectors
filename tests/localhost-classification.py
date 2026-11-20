#!/usr/bin/env python3
# SPDX-FileCopyrightText: 2026 LibreCode Coop and contributors
# SPDX-License-Identifier: AGPL-3.0-or-later
"""Synthetic Akaunting UI: bank setup, category rule management and ACL."""
import atexit
import html
import http.cookiejar
import json
import os
import re
import secrets
import subprocess
import urllib.error
import urllib.parse
import urllib.request
from html.parser import HTMLParser

BASE = "http://127.0.0.1:18789"
FIXTURE = os.environ.get("BANKING_CONNECTORS_LOCAL_FIXTURE_DIR","")
if not FIXTURE.endswith("/akaunting-banksync-local"):
    raise RuntimeError("Refusing category UI test outside isolated fixture")
if not os.path.isfile(FIXTURE + "/app-root/.env"):
    raise RuntimeError("Fixture not installed")
env = open(FIXTURE + "/app-root/.env", encoding="utf-8").read()
for required in ("DB_DATABASE=akaunting_synthetic", "APP_ENV=local", "DB_PREFIX=ak_"):
    if required not in env.splitlines():
        raise RuntimeError("Unsafe fixture identity")

class Token(HTMLParser):
    def __init__(self):
        super().__init__()
        self.csrf = None
    def handle_starttag(self, tag, attrs):
        a = dict(attrs)
        if tag == "input" and a.get("name") == "_token":
            self.csrf = a.get("value")

class NoRedirect(urllib.request.HTTPRedirectHandler):
    def redirect_request(self, req, fp, code, msg, headers, newurl):
        return None

def check(ok, msg):
    if not ok:
        raise RuntimeError("FAIL " + msg)
    print("PASS " + msg)

def auth(filename):
    lines = open(FIXTURE + "/" + filename,encoding="utf-8").read().splitlines()
    email, password = lines[:2]
    if not email.endswith("@localhost.invalid"):
        raise RuntimeError("Refusing non-synthetic login")
    jar = http.cookiejar.CookieJar()
    browser = urllib.request.build_opener(urllib.request.HTTPCookieProcessor(jar))
    form = browser.open(BASE + "/auth/login",timeout=20)
    p = Token()
    p.feed(form.read().decode(errors="replace"))
    check(bool(p.csrf), "synthetic login CSRF token discovered")
    req = urllib.request.Request(BASE + "/auth/login",
        data=urllib.parse.urlencode({"_token":p.csrf,"email":email,"password":password}).encode(),
        headers={"Content-Type":"application/x-www-form-urlencoded"},method="POST")
    browser.open(req,timeout=20).read()
    no_redirect = urllib.request.build_opener(
        urllib.request.HTTPCookieProcessor(jar),NoRedirect)
    csrf = next((urllib.parse.unquote(c.value) for c in jar if c.name == 'XSRF-TOKEN'), None)
    return browser,no_redirect,csrf

def get(browser,path):
    try:
        r=browser.open(BASE+path,timeout=20)
    except urllib.error.HTTPError as e:
        r=e
    return r.code,r.read().decode(errors="replace")

def post(browser,path,fields,token=True,xsrf=None):
    values=dict(fields)
    if xsrf:
        values.pop("_token", None)
    if not token:
        values.pop("_token",None)
    headers={"Content-Type":"application/x-www-form-urlencoded",
             "Accept":"application/json","X-Requested-With":"XMLHttpRequest"}
    if xsrf:
        headers["X-XSRF-TOKEN"]=xsrf
    req=urllib.request.Request(BASE+path,
        data=urllib.parse.urlencode(values).encode(),method="POST",
        headers=headers)
    try: r=browser.open(req,timeout=20)
    except urllib.error.HTTPError as e: r=e
    raw=r.read()
    try: body=json.loads(raw.decode())
    except (ValueError,UnicodeError): body={}
    return r.code,body

admin, admin_nr, admin_csrf=auth("admin-credentials.local")
code,page=get(admin,"/2/banking/accounts/3/integration?tab=settings")
check(code==200 and "Configurar integração" in page and
      "Última sincronização" in page and
      "Credenciais e certificado" in page,
      "bank configuration tab shows connection, last sync and certificate status")
check("-----BEGIN PRIVATE KEY" not in page and
      "client-key-password" not in page,
      "configuration page does not expose bank secret material")

code,page=get(admin,"/2/banking/accounts/3/integration?tab=rules")
p=Token();p.feed(page)
check(code==200 and "Aprendizado com classificações confirmadas" in page,
      "category automation presents the cold-start training disclosure")
check(code==200 and "Regras de categorização" in page and p.csrf and
      'name="description_contains"' in page,
      "category rules tab displays usable creation form")
# Only use category IDs rendered by the synthetic user's own company.
choices=re.findall(
    r'<option\s+value="(\d+)"[^>]*>\s*([^<]*?)\s*\((income|expense)\)\s*</option>',
    page,re.IGNORECASE|re.DOTALL)
expense=next((int(id) for id,_,kind in choices if kind=="expense"),None)
income=next((int(id) for id,_,kind in choices if kind=="income"),None)
check(expense is not None and income is not None,
      "synthetic tenant has both expense and income categories")

path="/2/banking/accounts/3/integration/rules"
needle="qa-category-" + secrets.token_hex(8)
body={"_token":p.csrf,"direction":"debit","description_contains":needle,
      "category_id":expense,"priority":40}
code,res=post(admin_nr,path,body,token=False)
check(code==419,"CSRF rejects untrusted rule creation")
code,res=post(admin_nr,path,body)
rule_id=(res.get("data") or {}).get("rule_id")
check(code==200 and isinstance(rule_id,int) and rule_id>0,
      "authorized creator persists scoped category rule")

def cleanup():
    if not isinstance(rule_id,int) or rule_id<1:
        return
    sql=(
        'DB::table("lcc_banking_connectors_category_rules")'
        '->where("company_id",2)->where("account_id",3)'
        f'->where("id",{rule_id})->delete();'
    )
    r=subprocess.run(["docker","exec","-e","HOME=/var/www/html/storage",
        "-e","XDG_CONFIG_HOME=/var/www/html/storage","lcc-banksync-local-php",
        "php","artisan","tinker","--execute",sql],
        capture_output=True, text=True, timeout=30)
    if r.returncode:
        raise RuntimeError("Synthetic rule cleanup failed")
atexit.register(cleanup)

code,page=get(admin,"/2/banking/accounts/3/integration?tab=rules")
check(code==200 and needle in page,
      "new rule is visible after saving without navigating to unrelated account")
toggle=f"/2/banking/accounts/3/integration/rules/{rule_id}/toggle"
code,res=post(admin_nr,toggle,{"_token":p.csrf,"enabled":"0"})
check(code==200 and res.get("data",{}).get("enabled") is False,
      "rule can be disabled without deleting evidence")
code,res=post(admin_nr,toggle,{"_token":p.csrf,"enabled":"1"})
check(code==200 and res.get("data",{}).get("enabled") is True,
      "rule can be re-enabled")

wrong={**body,"description_contains":needle+"-wrong",
       "category_id":income}
code,res=post(admin_nr,path,wrong)
check(code==409 and res.get("error")=="category_not_accessible",
      "expense rule cannot use income category")
code,res=post(admin_nr,path,body)
check(code==409 and res.get("error")=="duplicate_category_rule",
      "same normalized description cannot create duplicate rule")

restricted, restricted_nr, restricted_csrf=auth("restricted-credentials.local")
check(bool(restricted_csrf),"restricted synthetic user gets session CSRF token")
code,res=post(restricted_nr,path,{**body,"description_contains":needle+"-denied"},xsrf=restricted_csrf)
check(code in (302,401,403,404),
      "user without account privileges cannot create categorization rules")
second, second_nr, second_csrf=auth("company3-credentials.local")
check(bool(second_csrf),"other company gets session CSRF token")
code,res=post(second_nr,path,{**body,"description_contains":needle+"-foreign"},xsrf=second_csrf)
check(code in (302,401,403,404),
      "other company cannot configure first company's account")

code,page=get(admin,"/2/banking/accounts/3/integration?tab=review&filter=reviewed")
check(code==200 and "Associadas" in page and
      "Pendentes" in page and "Ignoradas" in page,
      "review page exposes completed and pending correspondence filters")
print("PASS scoped rule configuration and reconciliation UI without external bank requests")
