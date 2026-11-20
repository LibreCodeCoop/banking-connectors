#!/usr/bin/env python3
# SPDX-FileCopyrightText: 2026 LibreCode Coop and contributors
# SPDX-License-Identifier: AGPL-3.0-or-later
"""Authenticated native Akaunting Banking > Transactions view smoke."""
import http.cookiejar
import os
import urllib.parse
import urllib.request
from html.parser import HTMLParser

BASE="http://127.0.0.1:18789"
FIXTURE=os.environ.get("BANKING_CONNECTORS_LOCAL_FIXTURE_DIR","")
if not FIXTURE.endswith("/akaunting-banksync-local"):
    raise RuntimeError("Synthetic localhost fixture required")
env=open(FIXTURE+"/app-root/.env",encoding="utf-8").read().splitlines()
for line in ("APP_ENV=local","DB_HOST=banksync-local-db",
             "DB_DATABASE=akaunting_synthetic","DB_PREFIX=ak_"):
    if line not in env: raise RuntimeError("Not a synthetic Akaunting database")
with open(FIXTURE+"/admin-credentials.local",encoding="utf-8") as f:
    email,password=f.read().splitlines()[:2]
if not email.endswith("@localhost.invalid"):
    raise RuntimeError("Refusing non-synthetic credentials")
class CSRF(HTMLParser):
    def __init__(self):
        super().__init__();self.token=None
    def handle_starttag(self,tag,attrs):
        d=dict(attrs)
        if tag=="input" and d.get("name")=="_token":
            self.token=d.get("value")
jar=http.cookiejar.CookieJar()
client=urllib.request.build_opener(urllib.request.HTTPCookieProcessor(jar))
login=client.open(BASE+"/auth/login",timeout=25)
parsed=CSRF();parsed.feed(login.read().decode(errors="replace"))
if not parsed.token: raise RuntimeError("Missing synthetic auth token")
client.open(urllib.request.Request(BASE+"/auth/login",method="POST",
    data=urllib.parse.urlencode({
        "_token":parsed.token,"email":email,"password":password}).encode(),
    headers={"Content-Type":"application/x-www-form-urlencoded"}),timeout=25).read()

def get(path):
    response=client.open(BASE+path,timeout=30)
    return response.status,response.geturl(),response.read().decode(errors="replace")
code,_,native=get("/2/banking/transactions")
if code!=200 or "bank-feed-native-transactions" not in native:
    raise RuntimeError("Native transactions list did not render module bank feed")
if "index-more-actions-new-income-transaction" not in native:
    raise RuntimeError("Native Akaunting create income transaction action missing")
if "Conciliação bancária nas transações" not in native:
    raise RuntimeError("Native bank feed section not translated")
print("PASS native transactions page retains Akaunting actions, summary and bank feed filters")

for name in ("needs_action","uncategorized","unlinked","conflict","imported","posted","matched","ignored"):
    code,_,body=get("/2/banking/transactions?bank_feed="+name)
    if code!=200 or 'bank-feed-native-transactions' not in body:
        raise RuntimeError("Bank feed filtered view failed: "+name)
    if "native-resolve" not in body and "Revisar / corrigir" not in body and "Nenhuma movimentação" not in body:
        raise RuntimeError("Bank feed view missing review action/empty state: "+name)
print("PASS native bank feed status filters and bounded table render")

code,_,invalid=get("/2/banking/transactions?bank_feed=not_a_filter")
if code!=200 or 'bank-feed-native-transactions' not in invalid:
    raise RuntimeError("Unexpected search filter reached SQL")
print("PASS unknown filter fails into native-safe default view")

code,_,settings=get("/2/banking/accounts/3/integration")
if code!=200 or "Configuração da conexão bancária" not in settings:
    raise RuntimeError("Account integration should open operational settings by default")
if "Publicação automática no financeiro" not in settings:
    raise RuntimeError("Missing explicit bank financial-publishing opt-in")
import json
import re
import urllib.error

class NoRedirect(urllib.request.HTTPRedirectHandler):
    def redirect_request(self,req,fp,code,msg,headers,newurl):
        return None

def native_post(url,values,token=None):
    headers={"Content-Type":"application/x-www-form-urlencoded",
        "Accept":"application/json","X-Requested-With":"XMLHttpRequest"}
    if token: headers["X-XSRF-TOKEN"]=token
    nonredirect=urllib.request.build_opener(
        urllib.request.HTTPCookieProcessor(jar), NoRedirect)
    request=urllib.request.Request(BASE+url,
        data=urllib.parse.urlencode(values).encode(),method="POST",headers=headers)
    try: result=nonredirect.open(request,timeout=20)
    except urllib.error.HTTPError as error: result=error
    raw=result.read()
    try: parsed=json.loads(raw.decode())
    except (ValueError,UnicodeError): parsed={}
    return result.code,parsed

optin=re.search(r'/2/banking-connectors/connections/(\d+)/native-publishing',settings)
if not optin: raise RuntimeError("Native publishing control has no tenant-scoped action")
endpoint=optin.group(0)
csrf=next((urllib.parse.unquote(c.value) for c in jar if c.name=='XSRF-TOKEN'),None)
if not csrf: raise RuntimeError("No authenticated XSRF token")
status,_=native_post(endpoint,{"enabled":"1"})
if status!=419: raise RuntimeError("Native financial publishing lacks CSRF protection")
status,_=native_post(endpoint,{"enabled":"invalid"},csrf)
if status!=422: raise RuntimeError("Invalid native posting state was not rejected")
status,_=native_post(endpoint,{"enabled":"1"},csrf)
if status not in (200,409):
    raise RuntimeError("Valid native posting request had unexpected result")
if status==200:
    # This branch is synthetic only: undo instantly, never leave automation
    # enabled as a side effect of a UI/ACL test.
    status,_=native_post(endpoint,{"enabled":"0"},csrf)
    if status!=200: raise RuntimeError("Synthetic native publication rollback failed")
print("PASS native auto-post opt-in is CSRF protected, validated and reversible")

code,_,review=get("/2/banking/transactions/bank-feed/accounts/3?filter=pending")
if code!=200 or "Movimentações e conciliação" not in review:
    raise RuntimeError("Centralized transactions review route unavailable")
if "banking-connectors-back-to-account" not in review or "Voltar às transações" not in review:
    raise RuntimeError("Centralized review does not return to transactions")
print("PASS native review route and account-only configuration separation")
