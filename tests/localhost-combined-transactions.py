#!/usr/bin/env python3
# SPDX-FileCopyrightText: 2026 LibreCode Coop and contributors
# SPDX-License-Identifier: AGPL-3.0-or-later
"""Synthetic-only HTTP check for mixed native/bank listing and native filters."""
import http.cookiejar
import os
import re
import urllib.parse
import urllib.request
from html.parser import HTMLParser

ROOT = os.environ.get('BANKING_CONNECTORS_LOCAL_FIXTURE_DIR', '')
if not ROOT.endswith('/akaunting-banksync-local'):
    raise RuntimeError('Isolated synthetic fixture required')
env = open(ROOT+'/app-root/.env', encoding='utf-8').read().splitlines()
for setting in ('APP_ENV=local', 'DB_DATABASE=akaunting_synthetic', 'DB_PREFIX=ak_'):
    if setting not in env:
        raise RuntimeError('Refusing non-synthetic Akaunting installation')
base = 'http://127.0.0.1:18789'
class Csrf(HTMLParser):
    def __init__(self):
        super().__init__()
        self.token = None
    def handle_starttag(self, tag, attrs):
        args = dict(attrs)
        if tag == 'input' and args.get('name') == '_token':
            self.token = args.get('value')

email, password = open(ROOT+'/admin-credentials.local', encoding='utf-8').read().splitlines()[:2]
if not email.endswith('@localhost.invalid'):
    raise RuntimeError('Only synthetic login accounts may be used')
client = urllib.request.build_opener(urllib.request.HTTPCookieProcessor(http.cookiejar.CookieJar()))
csrf = Csrf()
csrf.feed(client.open(base+'/auth/login', timeout=20).read().decode())
if not csrf.token:
    raise RuntimeError('No local CSRF token')
client.open(urllib.request.Request(base+'/auth/login', method='POST', data=urllib.parse.urlencode({
    '_token':csrf.token, 'email':email, 'password':password,
}).encode()), timeout=20)

def body(params):
    query=urllib.parse.urlencode(params)
    response=client.open(base+'/2/banking/transactions?'+query, timeout=20)
    if response.status!=200:
        raise RuntimeError('Unexpected status')
    return response.read().decode(errors='replace')

def bank_ids(doc):
    return set(re.findall(r'data-bank-row-state="([0-9]+)"',doc))

all_rows=body({'list_records':'all'})
all_ids=bank_ids(all_rows)
assert len(all_ids)==6, 'expected six synthetic unlinked bank movements'
assert 'id="tab-bank-feed"' in all_rows
assert '/banking/transactions/' in all_rows
print('PASS all-transactions tab combines six staged bank movements with native rows')
# Regression: the dedicated bank tab calls NativeTransactionsIndex::sort(),
# where the previous deploy dereferenced an undefined $prefix variable.
for value in ('imported','needs_action','unlinked'):
    bank_page=body({'search':'bank_movement:'+value})
    assert 'tab-bank-feed' in bank_page, 'Bank tab failed: '+value
print('PASS dedicated bank tab renders without undefined-prefix exception')

for search,expected in [
    ('type:income',4),('type:expense',2),
    ('account_id:3',4),('account_id:4',2),
    ('account_id:999999',0),('category_id:999999',0),
    ('paid_at>=2026-11-15',4),
    ('amount>=0',6),('amount>999999999999',0),
    ('bank_status:linked',0),
]:
    actual=bank_ids(body({'list_records':'all','search':search}))
    if len(actual)!=expected or not actual.issubset(all_ids):
        raise RuntimeError('Mixed-list filter failed: '+search)
print('PASS native income, expense, account, category, date, amount and status filters')

# Document-number ordering currently fails in the native Akaunting controller
# before any module composer is invoked. Do not hide that upstream defect.
for column in ('paid_at','amount','number','type','category.name','account.name',
               'contact.name'):
    for direction in ('asc','desc'):
        rows=body({'list_records':'all','sort':column,'direction':direction})
        if bank_ids(rows)!=all_ids:
            raise RuntimeError('Mixed-list native sorting failed: '+column+' '+direction)
print('PASS native sorting options keep all bank movement rows available')

paged=[bank_ids(body({'list_records':'all','limit':3,'page':page}))
       for page in range(1,5)]
if set.union(*paged)!=all_ids or sum(map(len,paged))!=len(all_ids):
    raise RuntimeError('Combined pagination omitted or duplicated bank source rows')
if paged[3]:
    raise RuntimeError('Out-of-range page retained bank movements')
print('PASS combined SQL pagination keeps source movements distinct across pages')

# Never perform financial writes or call the real bank API in this test.
print('Combined native/bank HTTP checks passed; synthetic read-only fixture')
