#!/usr/bin/env python3
# SPDX-FileCopyrightText: 2026 LibreCode Coop and contributors
# SPDX-License-Identifier: AGPL-3.0-or-later
"""Synthetic authenticated detail-page navigation and nonfinancial UI checks."""
import html, http.cookiejar, os, re, urllib.parse, urllib.request, urllib.error
from html.parser import HTMLParser
root=os.environ.get('BANKING_CONNECTORS_LOCAL_FIXTURE_DIR','')
assert root.endswith('/akaunting-banksync-local')
env=open(root+'/app-root/.env',encoding='utf-8').read()
assert 'APP_ENV=local' in env and 'DB_DATABASE=akaunting_synthetic' in env and 'DB_PREFIX=ak_' in env
base='http://127.0.0.1:18789'
class Csrf(HTMLParser):
 def __init__(self): super().__init__();self.token=None
 def handle_starttag(self,t,a):
  attrs=dict(a)
  if t=='input' and attrs.get('name')=='_token':self.token=attrs.get('value')
e,p=open(root+'/admin-credentials.local',encoding='utf-8').read().splitlines()[:2]
assert e.endswith('@localhost.invalid')
jar=http.cookiejar.CookieJar()
o=urllib.request.build_opener(urllib.request.HTTPCookieProcessor(jar))
c=Csrf();c.feed(o.open(base+'/auth/login').read().decode());assert c.token
o.open(urllib.request.Request(base+'/auth/login',data=urllib.parse.urlencode(dict(_token=c.token,email=e,password=p)).encode()))
origin='/2/banking/transactions?list_records=all&search=type%3Aincome&sort=paid_at&direction=asc&page=2'
entry='/2/banking/transactions/bank-feed/accounts/3/entries/3'
uri=entry+'?'+urllib.parse.urlencode({'from':origin})
class NoRedirect(urllib.request.HTTPRedirectHandler):
 def redirect_request(self, req, fp, code, msg, headers, newurl): return None
check=urllib.request.build_opener(urllib.request.HTTPCookieProcessor(jar),NoRedirect)

try:
 response=check.open(base+uri,timeout=20)
except urllib.error.HTTPError as exc:
 response=exc

page=response.read().decode(errors='replace')

assert response.status==200 and 'id="banking-connectors-single-entry"' in page
assert 'id="bank-entry-3"' in page
assert 'id="banking-connectors-back-to-account"' in page
assert 'id="banking-connectors-tab-settings"' not in page
assert 'id="banking-connectors-tab-rules"' not in page
assert 'banking-connectors-association-details' not in page or '<details' in page
assert 'Configurar integração' not in page
assert 'class="grid grid-cols-3 gap-3 mt-5"' not in page
assert 'id="banking-connectors-save-classification"' in page
assert ('href="'+origin.replace('&','&amp;')+'"') in page
assert 'name="from"' in page and html.escape(origin,quote=True) in page
print('PASS dedicated bank entry route, origin navigation, details, no queue metrics/settings')
for invalid in ['https://evil.example/2/banking/transactions','/3/banking/transactions?search=all','//evil.example','/2/banking/transactions/other']:
 unsafe=entry+'?'+urllib.parse.urlencode({'from':invalid})
 try:
  result=o.open(base+unsafe,timeout=20).read().decode(errors='replace')
 except urllib.error.HTTPError as exc:
  assert exc.code in (403,422)
  continue
 assert 'href="'+invalid+'"' not in result
 assert 'id="banking-connectors-single-entry"' in result
print('PASS cross-tenant and external return targets rejected')
print('Read-only authenticated synthetic checks complete')
