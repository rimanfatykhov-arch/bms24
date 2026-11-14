"""Independent page-error observer around unmodified browser producers (not a change to Claude's validators)."""
import ipaddress,json,os,runpy,sys
from pathlib import Path
from urllib.parse import urlparse
from playwright.sync_api import BrowserContext,Browser,Route
if os.environ.get('GITHUB_ACTIONS')!='true' or os.environ.get('BMS_CI_BROWSER')!='1':raise SystemExit('CI only')
script=Path(sys.argv[1]).resolve();tag=sys.argv[2];args=sys.argv[3:]
pages=[];original=BrowserContext.new_page
expected_external={'https://cdn.jsdelivr.net/npm/mathjax@2.7.9/MathJax.js?delayStartupUntil=configured'}
original_abort=Route.abort

def abort(self,*args,**kwargs):
    # Observe the actual explicit abort invocation, not an inferred failure string.
    try:row=getattr(self.request.frame.page,'_acceptance_row',None)
    except Exception:row=None
    code=kwargs.get('error_code',args[0] if args else 'failed') or 'failed'
    result=original_abort(self,*args,**kwargs)
    if row is not None:row['route_aborts'].append({'url':self.request.url,'code':code})
    return result
Route.abort=abort

def external_http(url):
    p=urlparse(url);host=(p.hostname or '').lower()
    if p.scheme not in ('http','https') or not host or host in ('localhost','0.0.0.0') or host.endswith('.localhost'):return False
    try:
        address=ipaddress.ip_address(host)
        return not(address.is_loopback or getattr(address,'ipv4_mapped',None) and address.ipv4_mapped.is_loopback)
    except ValueError:return True
def new_page(self,*a,**kw):
    p=original(self,*a,**kw);row={'page_errors':[],'console_errors':[],'http5xx':[],'route_aborts':[],'requestfailed':[]};pages.append(row)
    p._acceptance_row=row
    p.on('pageerror',lambda e:row['page_errors'].append(type(e).__name__))
    p.on('console',lambda m:row['console_errors'].append({'text':m.text,'url':m.location.get('url')}) if m.type=='error' else None)
    p.on('response',lambda r:row['http5xx'].append({'url':r.url,'status':r.status}) if r.status>=500 else None)
    p.on('requestfailed',lambda r:row['requestfailed'].append({'url':r.url,'failure':r.failure,'resource_type':r.resource_type}))
    return p
BrowserContext.new_page=new_page
original_close=Browser.close
def close(self,*a,**kw):
    for ci,ctx in enumerate(self.contexts):
        for pi,p in enumerate(ctx.pages):
            try:
                target=Path(os.environ['TEST_ROOT'])/'public'
                p.screenshot(path=str(target/(tag+'-end-'+str(ci)+'-'+str(pi)+'.png')))
                buttons=p.locator('form:has(input[name=action][value=discussionpost]) button[type=submit]').evaluate_all('els=>els.map(e=>{let r=e.getBoundingClientRect();let t=document.elementFromPoint(r.x+r.width/2,r.y+r.height/2);return {disabled:e.disabled,visible:!!r.width,rect:{x:r.x,y:r.y,w:r.width,h:r.height},top:t?t.tagName+"."+t.className:null}})')
                (target/(tag+'-buttons-'+str(ci)+'-'+str(pi)+'.json')).write_text(json.dumps(buttons,indent=1))
            except Exception:pass  # Supplemental diagnostics only; verdict still comes from strict observations.
    return original_close(self,*a,**kw)
Browser.close=close
status='FAIL'
try:
    sys.argv=[str(script),*args];runpy.run_path(str(script),run_name='__main__')
    for row in pages:
        codes={'failed':{'net::ERR_FAILED'},'blockedbyclient':{'net::ERR_BLOCKED_BY_CLIENT','net::ERR_BLOCKED_BY_CLIENT.Inspector'}}
        row['unexpected_aborts']=[e for e in row['route_aborts'] if not(external_http(e['url']) and e['url'] in expected_external and e['code'] in codes)]
        allowed={(e['url'],failure) for e in row['route_aborts'] if external_http(e['url']) and e['url'] in expected_external for failure in codes.get(e['code'],set())}
        row['unexpected_requests']=[e for e in row['requestfailed'] if (e['url'],e['failure']) not in allowed]
        row['unexpected_console']=[e for e in row['console_errors'] if not any(e['url']==url and e['text']=='Failed to load resource: '+failure for url,failure in allowed)]
    if not pages or any(r['page_errors'] or r['http5xx'] or r['unexpected_console'] or r['unexpected_requests'] or r['unexpected_aborts'] for r in pages):raise RuntimeError('independent browser error observer failed')
    status='PASS'
finally:
    (Path(os.environ['TEST_ROOT'])/'public'/(tag+'-errors.json')).write_text(json.dumps({'status':status,'pages':pages},indent=1))
