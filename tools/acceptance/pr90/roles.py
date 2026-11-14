"""Same synthetic actors and routes before/after package install; no admin shortcut."""
import json,os,sys
from pathlib import Path
from urllib.parse import urlparse,parse_qsl
from playwright.sync_api import sync_playwright
root=Path(os.environ['TEST_ROOT']);ev=Path(os.environ['EVIDENCE']);phase,mode=sys.argv[1:3]
if os.environ.get('GITHUB_ACTIONS')!='true' or os.environ.get('BMS_CI_BROWSER')!='1':raise SystemExit('CI only')
f=json.loads((ev/'actors.private.json').read_text());base='http://127.0.0.1:8080';out={};errors=[]
def require(ok,msg):
    if not ok:raise RuntimeError(msg)
def url(tenant):return base+'/local/kbacompany/tasks.php?uuid='+f['companies'][tenant]['uuid']+'&task='+f['tasks'][tenant]['uuid']+'&view=detail&tab=overview'
with sync_playwright() as pw:
    b=pw.chromium.launch(headless=True)
    try:
        for label in ['MA','EA','EB']:
            ctx=b.new_context();ctx.route('**/*',lambda r:r.continue_() if urlparse(r.request.url).scheme=='http' and urlparse(r.request.url).netloc=='127.0.0.1:8080' else r.abort())
            p=ctx.new_page();p.on('pageerror',lambda e:errors.append(type(e).__name__))
            a=f['actors'][label];p.goto(base+'/login/index.php');p.locator('#username').fill(a['username']);p.locator('#password').fill(a['password']);p.locator('#loginbtn').click();p.wait_for_load_state('networkidle')
            tenant='B' if label=='EB' else 'A';r=p.goto(url(tenant),wait_until='networkidle');require(r.status==200,'own page not served')
            require(f['tasks'][tenant]['title'] in p.inner_text('body'),'own task missing')
            selector='.bms24-task-menu' if mode=='owned' else '.bms-tui-menu'
            links=[]
            for href in p.locator(selector+' a').evaluate_all('(els)=>els.map(e=>e.href)'):
                parsed=urlparse(href);links.append((parsed.path,sorted((k,v) for k,v in parse_qsl(parsed.query) if k!='sesskey')))
            require(bool(links),'expected task menu missing')
            denied=ctx.request.get(url('A' if tenant=='B' else 'B'),max_redirects=0)
            require(denied.status in (403,404),'foreign task not explicitly denied: '+str(denied.status))
            out[label]={'links':links,'own_http':r.status,'foreign_http':denied.status}
            ctx.close()
        require(not errors,'role page execution errors')
    finally:
        b.close();(root/'public'/(phase+'-'+mode+'-roles.json')).write_text(json.dumps(out,indent=1))
baseline=root/'public'/('baseline-'+mode+'-roles.json')
if phase!='baseline':require(json.loads(baseline.read_text())==json.loads(json.dumps(out)),'permissions/menu differ from baseline')
