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
from html import unescape
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

def table_columns(body):
    header=re.search(r"<thead\b[^>]*>(.*?)</thead>",body,re.S)
    if not header: raise RuntimeError("Akaunting native transactions table absent")
    heads=re.findall(r"<th\b[^>]*>(.*?)</th>",header.group(1),re.S)
    return [re.sub(r"\s+"," ",unescape(re.sub(r"<[^>]*>"," ",h))).strip() for h in heads]

def bank_row_order(body):
    return re.findall(r'data-bank-row-state="([0-9]+)"',body)

def bank_row_ids(body):
    return set(bank_row_order(body))

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
if 'data-bank-row-state=' in native or 'native-feed-filters' in native:
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
       "data-bank-row-state=" in body or not bank_filter or \
       status not in bank_filter["values"]:
        raise RuntimeError("Native transaction status filtering failed: "+status)
print("PASS all native banking status filters render native ledger without independent UI")

for status in ("needs_action","uncategorized","unlinked","conflict",
               "imported","posted","matched","ignored"):
    code,_,body=get(admin,"/2/banking/transactions?search=bank_movement:"+status)
    if code!=200 or 'data-tabs-content="bank-feed"' not in body or \
       '<table' not in body:
        raise RuntimeError("Bank movement not in native tab: "+status)
    bank_filter=native_filter(body,"bank_movement")
    account_filter=native_filter(body,"account_id")
    if not bank_filter or not account_filter or \
       status not in bank_filter["values"] or "native-feed-filters" in body:
        raise RuntimeError("Bank movements not using a real native search dropdown: "+status)
    if status=="needs_action":
        if not bank_row_ids(body):
            raise RuntimeError("The bank tab renders no real bank source rows")
        if table_columns(body)!=table_columns(native)[1:]:
            raise RuntimeError("Bank tab column order/labels differ from the native financial table")
        for key in ("type","account_id","paid_at","currency_code","category_id","contact_id"):
            if not native_filter(body,key):
                raise RuntimeError("Existing Akaunting search dropdown filter missing: "+key)
        if "index-line-actions-pin-tab-bank-feed" not in body:
            raise RuntimeError("Bank tab does not expose Akaunting's native default-tab pin")
        if not all(x in body for x in (
            "Ver movimentação do extrato", "Revisar / corrigir",
            "Configurar integração da conta", "data-bank-row-state=")):
            raise RuntimeError("Bank movements lack native row actions/visible context")
print("PASS the bank tab renders the EXACT native transaction-table component, headers, filters, pin and row actions")
print("PASS all imported movement statuses use Akaunting native tab/search/table")

_,_,all_body=get(admin,"/2/banking/transactions?search=bank_movement:imported")
all_ids=bank_row_ids(all_body)
if not all_ids:raise RuntimeError("Synthetic bank source fixture is empty")
def ids(search):
    return bank_row_ids(get(admin,"/2/banking/transactions?search="+search)[2])
income=ids("bank_movement:imported type:income")
expense=ids("bank_movement:imported type:expense")
if not income or not expense or income & expense or income | expense!=all_ids:
    raise RuntimeError("Native income/expense filters do not constrain bank movements")
account3=ids("bank_movement:imported account_id:3")
account4=ids("bank_movement:imported account_id:4")
if not account3 or not account4 or account3 & account4 or account3|account4!=all_ids:
    raise RuntimeError("Native account filter does not constrain the staged bank rows")
if ids("bank_movement:imported category_id:999999"):
    raise RuntimeError("Invalid native category filter leaked bank movement rows")
newer=ids("bank_movement:imported paid_at>=2026-11-15")
older=ids("bank_movement:imported paid_at<2026-11-15")
if not newer or not older or newer&older or newer|older!=all_ids:
    raise RuntimeError("Native date range filter did not map to source movement dates")
if ids("bank_movement:imported account_id:999999"):
    raise RuntimeError("Unknown native account filter failed closed incorrectly")
print("PASS native type, company/account, category, date and status filters constrain source SQL")

_,_,sorted_asc=get(admin,
    "/2/banking/transactions?search=bank_movement:imported&list_records=all&sort=paid_at&direction=asc")
_,_,sorted_desc=get(admin,
    "/2/banking/transactions?search=bank_movement:imported&list_records=all&sort=paid_at&direction=desc")
if 'data-tabs-content="bank-feed"' not in sorted_asc or \
   'data-tabs-content="bank-feed"' not in sorted_desc:
    raise RuntimeError("A native sort link switched to another tab")
if bank_row_ids(sorted_asc)!=all_ids or bank_row_ids(sorted_desc)!=all_ids or \
   bank_row_order(sorted_asc)==bank_row_order(sorted_desc):
    raise RuntimeError("Native sortable links do not sort real source rows")
print("PASS native Akaunting table sorting keeps the bank tab and reorders bank evidence")

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
        if resp.status==200 and ("data-bank-row-state=" in content or
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
