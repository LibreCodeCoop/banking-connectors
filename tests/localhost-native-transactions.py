#!/usr/bin/env python3
# SPDX-FileCopyrightText: 2026 LibreCode Coop and contributors
# SPDX-License-Identifier: AGPL-3.0-or-later
"""Authenticate against synthetic Akaunting: native search, tabs, row actions."""
import http.cookiejar
import json
import os
import re
import urllib.error
import urllib.parse
import urllib.request
from html.parser import HTMLParser

BASE="http://127.0.0.1:18789"
FIXTURE=os.environ.get("BANKING_CONNECTORS_LOCAL_FIXTURE_DIR","")
if not FIXTURE.endswith("/akaunting-banksync-local"):
    raise RuntimeError("Only isolated local fixture is allowed")
env=open(FIXTURE+"/app-root/.env",encoding="utf-8").read().splitlines()
for line in ("APP_ENV=local","DB_HOST=banksync-local-db",
             "DB_DATABASE=akaunting_synthetic","DB_PREFIX=ak_"):
    if line not in env: raise RuntimeError("Unsafe synthetic database identity")

class CSRF(HTMLParser):
    def __init__(self): super().__init__();self.token=None
    def handle_starttag(self,tag,attrs):
        data=dict(attrs)
        if tag=="input" and data.get("name")=="_token": self.token=data.get("value")

class SearchFilters(HTMLParser):
    def __init__(self): super().__init__();self.options=[]
    def handle_starttag(self,tag,attrs):
        if tag!="akaunting-search": return
        raw=dict(attrs).get(":filters")
        if raw:
            try: self.options.append(json.loads(raw))
            except ValueError: raise RuntimeError("Invalid native Akaunting search filter JSON")

def native_filter(body,key):
    parser=SearchFilters();parser.feed(body)
    return next((f for group in parser.options for f in group
                 if f.get("key")==key),None)

class NoRedirect(urllib.request.HTTPRedirectHandler):
    def redirect_request(self,request,fp,code,message,headers,url):
        return None

def auth(name):
    email,password=open(FIXTURE+"/"+name,encoding="utf-8").read().splitlines()[:2]
    if not email.endswith("@localhost.invalid"):
        raise RuntimeError("Non-synthetic identity refused")
    jar=http.cookiejar.CookieJar()
    browser=urllib.request.build_opener(urllib.request.HTTPCookieProcessor(jar))
    p=CSRF();p.feed(browser.open(BASE+"/auth/login",timeout=20).read().decode(errors="replace"))
    if not p.token: raise RuntimeError("Synthetic CSRF absent")
    browser.open(urllib.request.Request(BASE+"/auth/login",method="POST",
        data=urllib.parse.urlencode({"_token":p.token,"email":email,"password":password}).encode(),
        headers={"Content-Type":"application/x-www-form-urlencoded"}),timeout=20).read()
    return browser,urllib.request.build_opener(
        urllib.request.HTTPCookieProcessor(jar),NoRedirect),jar

def get(client,path):
    response=client.open(BASE+path.replace(' ','%20'),timeout=25)
    return response.status,response.geturl(),response.read().decode(errors="replace")

def post(browser,path,values,xsrf=None):
    headers={"Content-Type":"application/x-www-form-urlencoded",
        "Accept":"application/json","X-Requested-With":"XMLHttpRequest"}
    if xsrf:headers["X-XSRF-TOKEN"]=xsrf
    req=urllib.request.Request(BASE+path,
        data=urllib.parse.urlencode(values).encode(),method="POST",headers=headers)
    try:reply=browser.open(req,timeout=20)
    except urllib.error.HTTPError as error: reply=error
    try:body=json.loads(reply.read().decode())
    except(ValueError,UnicodeError):body={}
    return reply.status,body

admin,admin_nr,jar=auth("admin-credentials.local")
code,_,native=get(admin,"/2/banking/transactions")
if code!=200 or 'id="tab-bank-feed"' not in native or "Movimentações bancárias" not in native:
    raise RuntimeError("Missing native Akaunting Bank movements tab")
if 'bank-feed-native-transactions' in native or 'native-feed-filters' in native:
    raise RuntimeError("An external bank filter bar was not removed")
if not native_filter(native,"bank_status"):
    raise RuntimeError("Bank integration status must appear as an actual native search dropdown option")
if "index-more-actions-new-income-transaction" not in native:
    raise RuntimeError("Core income/expense creation buttons were removed")
if "banking-connectors-line-action-review-" not in native:
    raise RuntimeError("Native row hover actions do not include bank review")
print("PASS native transactions retain summary/tabs/controls, with integrated search and row action menu")

for status in ("linked","posted","uncategorized","unlinked","connected"):
    code,_,body=get(admin,"/2/banking/transactions?search=bank_status:"+status)
    bank_filter=native_filter(body,"bank_status")
    if code!=200 or 'id="tab-bank-feed"' not in body or \
       "bank-feed-native-transactions" in body or not bank_filter or \
       status not in bank_filter["values"]:
        raise RuntimeError("Native transaction status filtering failed: "+status)
print("PASS all native banking status filters render native ledger without independent UI")

for status in ("needs_action","uncategorized","unlinked","conflict",
               "imported","posted","matched","ignored"):
    code,_,body=get(admin,"/2/banking/transactions?search=bank_movement:"+status)
    if code!=200 or 'data-tabs-content="bank-feed"' not in body or \
       'bank-feed-native-transactions' not in body:
        raise RuntimeError("Bank movement not in native tab: "+status)
    bank_filter=native_filter(body,"bank_movement")
    account_filter=native_filter(body,"account_id")
    if not bank_filter or not account_filter or \
       status not in bank_filter["values"] or "native-feed-filters" in body:
        raise RuntimeError("Bank movements not using a real native search dropdown: "+status)
print("PASS all imported movement statuses use Akaunting native tab/search/table")

code,_,body=get(admin,"/2/banking/transactions?search=bank_movement:unlinked account_id:3")
if code!=200 or 'data-tabs-content="bank-feed"' not in body:
    raise RuntimeError("Native account filter did not combine with bank status")
code,_,body=get(admin,"/2/banking/transactions?search=bank_movement:imported account_id:999999")
if code!=200 or 'data-tabs-content="bank-feed"' not in body:
    raise RuntimeError("Out-of-scope account selection bypassed a scoped search")
code,_,body=get(admin,"/2/banking/transactions?bank_feed=needs_action")
if code!=200 or 'data-tabs-content="bank-feed"' not in body:
    raise RuntimeError("Existing bank_feed bookmarks broken")
print("PASS account-scoped native filters and legacy bank feed URLs")

code,_,settings=get(admin,"/2/banking/accounts/3/integration")
if code!=200 or "Configuração da conexão bancária" not in settings:
    raise RuntimeError("Account integration must remain configuration-first")
if "Publicação automática no financeiro" not in settings:
    raise RuntimeError("Separate posting opt-in missing")
optin=re.search(r"/2/banking-connectors/connections/(\d+)/native-publishing",settings)
if not optin:raise RuntimeError("Bank posting endpoint missing")
xsrf=next((urllib.parse.unquote(c.value) for c in jar if c.name=="XSRF-TOKEN"),None)
if not xsrf: raise RuntimeError("CSRF session token absent")
code,_=post(admin_nr,optin.group(0),{"enabled":"1"})
if code!=419:raise RuntimeError("Unauthorized CSRF post accepted")
code,_=post(admin_nr,optin.group(0),{"enabled":"invalid"},xsrf)
if code!=422:raise RuntimeError("Invalid native publishing toggle accepted")
# Do not toggle posting on even temporarily. This UI test only verifies
# that a valid session still has the secured command available.
print("PASS bank configuration and financial publishing toggle remain secured")

code,_,review=get(admin,"/2/banking/transactions/bank-feed/accounts/3?filter=pending")
if code!=200 or "Movimentações e conciliação" not in review:
    raise RuntimeError("Detailed review route unavailable")
print("PASS native row actions retain detailed, company-scoped review")

restricted,_,_=auth("restricted-credentials.local")
for path in ("/2/banking/transactions?search=bank_movement:imported",
             "/2/banking/transactions?search=bank_status:connected"):
    try:
        resp=restricted.open(BASE+path,timeout=20)
    except urllib.error.HTTPError as error:
        resp=error
    content=resp.read().decode(errors="replace")
    if resp.status not in (401,403,404):
        if resp.status==200 and ("bank-feed-native-transactions" in content or
             "banking-connectors-line-action-review-" in content):
            raise RuntimeError("Restricted user can access banking data")
        raise RuntimeError("Restricted native banking access had unexpected status")
print("PASS restricted role cannot inspect banking data through new native UI")

# Second synthetic company has a bank connection but no native ledger rows.
# Its bank movement tab must remain reachable through native navigation.
other,_,_=auth("company3-credentials.local")
code,_,empty=get(other,"/3/banking/transactions")
if code!=200 or 'id="tab-bank-feed"' not in empty or "Movimentações bancárias" not in empty:
    raise RuntimeError("Empty native ledger hides bank movement tab")
print("PASS native bank tab remains available when the company has zero transactions")
