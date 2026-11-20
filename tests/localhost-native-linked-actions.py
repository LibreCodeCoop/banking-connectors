#!/usr/bin/env python3
# SPDX-FileCopyrightText: 2026 LibreCode Coop and contributors
# SPDX-License-Identifier: AGPL-3.0-or-later
"""E2E: native filter predicates, row badge and actions with one synthetic bank link."""
import hashlib
import http.cookiejar
import json
import os
import re
import secrets
import subprocess
import urllib.parse
import urllib.request
from html.parser import HTMLParser

ROOT=os.environ.get("BANKING_CONNECTORS_LOCAL_FIXTURE_DIR","")
if not ROOT.endswith("/akaunting-banksync-local"): raise RuntimeError("Isolated local fixture only")
env=open(ROOT+"/app-root/.env",encoding="utf-8").read().splitlines()
for expected in ("APP_ENV=local","DB_HOST=banksync-local-db",
                 "DB_DATABASE=akaunting_synthetic","DB_PREFIX=ak_"):
    if expected not in env: raise RuntimeError("Unsafe database identity")
name="lcc-native-ui-"+secrets.token_hex(12)
fingerprint=hashlib.sha256(name.encode()).hexdigest()
class Token(HTMLParser):
    def __init__(self):super().__init__();self.value=None
    def handle_starttag(self,tag,attrs):
        a=dict(attrs)
        if tag=="input" and a.get("name")=="_token":self.value=a.get("value")
def tinker(code):
    proc=subprocess.run(["docker","exec",
        "-e","HOME=/var/www/html/storage",
        "-e","XDG_CONFIG_HOME=/var/www/html/storage",
        "lcc-banksync-local-php","php","artisan","tinker","--execute",code],
        capture_output=True,text=True,timeout=45)
    if proc.returncode:
        raise RuntimeError("Synthetic artisan DB fixture command failed")
    return proc.stdout.strip().splitlines()[-1] if proc.stdout.strip() else ""

# Every mutation in this test is a synthetic link attached to an existing
# synthetic native transaction; it never changes transactions or accounts.
seed=r"""$c=DB::table("lcc_banking_connectors_connections")
    ->where("company_id",2)->where("account_id",3)->first();
$t=DB::table("transactions")->where("company_id",2)->where("account_id",3)
    ->where("type","income")->first();
if (!$c || !$t) throw new \RuntimeException("synthetic_native_fixture_missing");
$id=DB::table("lcc_banking_connectors_entries")->insertGetId([
    "connection_id"=>$c->id,"company_id"=>2,"account_id"=>3,
    "provider"=>"sicoob","source_fingerprint"=>"%s",
    "content_fingerprint"=>hash("sha256","content-%s"),
    "movement_date"=>"2026-11-20","direction"=>"credit",
    "amount_cents"=>100,"currency"=>"BRL","bank_description"=>"%s",
    "state"=>"reviewed","linked_transaction_id"=>$t->id,"conflict_count"=>0,
    "first_seen_at"=>now(),"last_seen_at"=>now(),
    "created_at"=>now(),"updated_at"=>now()
]);
DB::table("lcc_banking_connectors_native_publications")->insert([
    "company_id"=>2,"account_id"=>3,"connection_id"=>$c->id,
    "entry_id"=>$id,"native_transaction_id"=>$t->id,
    "outcome"=>"created","reason_code"=>"synthetic-ui-only",
    "occurred_at"=>now(),
]);
echo json_encode(["entry_id"=>$id,"transaction_id"=>$t->id]);"""%(fingerprint,fingerprint,name)
cleanup="""DB::table("lcc_banking_connectors_native_publications")->where("company_id",2)->where("entry_id",%d)->delete();
DB::table("lcc_banking_connectors_entries")->where("company_id",2)->where("id",%d)->where("source_fingerprint","%s")->delete();
echo DB::table("lcc_banking_connectors_entries")->where("company_id",2)->where("source_fingerprint","%s")->count();"""
entry_id=None
try:
    added=json.loads(tinker(seed))
    entry_id=int(added["entry_id"]); transaction_id=int(added["transaction_id"])
    email,password=open(ROOT+"/admin-credentials.local",encoding="utf-8").read().splitlines()[:2]
    if not email.endswith("@localhost.invalid"): raise RuntimeError("Synthetic auth only")
    browser=urllib.request.build_opener(urllib.request.HTTPCookieProcessor(http.cookiejar.CookieJar()))
    base="http://127.0.0.1:18789"
    csrf=Token();csrf.feed(browser.open(base+"/auth/login",timeout=25).read().decode(errors="replace"))
    browser.open(base+"/auth/login",data=urllib.parse.urlencode({
        "_token":csrf.value,"email":email,"password":password}).encode(),timeout=25).read()
    def page(path):
        return browser.open(base+path.replace(' ','%20'),timeout=25).read().decode(errors="replace")
    baseline=page("/2/banking/transactions")
    marker='data-banking-connectors-linked="'+str(transaction_id)+'"'
    link_action='banking-connectors-line-action-view-'+str(transaction_id)
    if marker not in baseline or link_action not in baseline:
        raise RuntimeError("Native row did not expose linked badge and per-row action")
    print("PASS native row shows linked-bank badge and the existing hover dropdown action")

    linked=page("/2/banking/transactions?search=bank_status:linked")
    posted=page("/2/banking/transactions?search=bank_status:posted")
    unlinked=page("/2/banking/transactions?search=bank_status:unlinked")
    if marker not in linked or marker not in posted or marker in unlinked:
        raise RuntimeError("Native bank search predicates do not filter by source link/publication")
    if 'index-line-actions-show-' not in unlinked:
        raise RuntimeError("Native unlinked transactions were hidden incorrectly")
    print("PASS actual native status filters distinguish linked, posted and unlinked transactions")

    deep=page("/2/banking/transactions?search=bank_movement:imported account_id:3&bank_entry_id="+str(entry_id))
    if name not in deep or 'data-tabs-content="bank-feed"' not in deep or \
       "bank-feed-native-transactions" not in deep:
        raise RuntimeError("Per-row bank link did not resolve to exact imported source event")
    print("PASS per-transaction native action opens exactly its company/account-bound bank evidence")
finally:
    if entry_id is not None:
        remaining=tinker(cleanup%(entry_id,entry_id,fingerprint,fingerprint))
        if remaining!="0": raise RuntimeError("Synthetic source link cleanup failed")
        print("PASS all synthetic bank-link and audit fixtures removed")
