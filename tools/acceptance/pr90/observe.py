"""Independent page-error observer around unmodified browser producers (not a change to Claude's validators)."""
import json,os,runpy,sys
from pathlib import Path
from urllib.parse import urlparse
from playwright.sync_api import BrowserContext
if os.environ.get('GITHUB_ACTIONS')!='true' or os.environ.get('BMS_CI_BROWSER')!='1':raise SystemExit('CI only')
script=Path(sys.argv[1]).resolve();tag=sys.argv[2];args=sys.argv[3:]
pages=[];original=BrowserContext.new_page
def new_page(self,*a,**kw):
    p=original(self,*a,**kw);row={'page_errors':[],'console_errors':[],'http5xx':[],'blocked_external':[]};pages.append(row)
    p.on('pageerror',lambda e:row['page_errors'].append(type(e).__name__))
    p.on('console',lambda m:row['console_errors'].append({'text':m.text,'url':m.location.get('url')}) if m.type=='error' else None)
    p.on('response',lambda r:row['http5xx'].append({'url':r.url,'status':r.status}) if r.status>=500 else None)
    p.on('requestfailed',lambda r:row['blocked_external'].append(r.url) if urlparse(r.url).netloc!='127.0.0.1:8080' and r.failure=='net::ERR_FAILED' else None)
    return p
BrowserContext.new_page=new_page
status='FAIL'
try:
    sys.argv=[str(script),*args];runpy.run_path(str(script),run_name='__main__')
    for row in pages:
        # Only the exact URL of an intentionally aborted external request is exempted;
        # no blanket ignore for ERR_FAILED or arbitrary console errors.
        row['unexpected_console']=[e for e in row['console_errors'] if not(e['url'] in row['blocked_external'] and 'ERR_FAILED' in e['text'])]
    if not pages or any(r['page_errors'] or r['http5xx'] or r['unexpected_console'] for r in pages):raise RuntimeError('independent browser error observer failed')
    status='PASS'
finally:
    (Path(os.environ['TEST_ROOT'])/'public'/(tag+'-errors.json')).write_text(json.dumps({'status':status,'pages':pages},indent=1))
