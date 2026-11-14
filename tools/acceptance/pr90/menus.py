"""Real Moodle menu geometry/keyboard observations. Missing menus are failures, never skips."""
import json,os,sys
from pathlib import Path
from urllib.parse import urlparse
from playwright.sync_api import sync_playwright
root=Path(os.environ['TEST_ROOT']); ev=Path(os.environ['EVIDENCE']); out=root/'public'; phase=sys.argv[1]
f=json.loads((ev/'actors.private.json').read_text()); a=f['actors']['MA']; company=f['companies']['A']['uuid']; task=f['tasks']['A']['uuid']
base='http://127.0.0.1:8080'; rows=[]
if os.environ.get('BMS_CI_BROWSER')!='1' or os.environ.get('GITHUB_ACTIONS')!='true':raise SystemExit('CI only')
def require(ok,message):
    if not ok:raise RuntimeError(message)
with sync_playwright() as pw:
    browser=pw.chromium.launch(headless=True)
    try:
        for width in [320,360,390,480,482,720,768,1280,1440]:
            ctx=browser.new_context(viewport={'width':width,'height':900},has_touch=width<=480,is_mobile=width<=480)
            blocked=set()
            def route(r):
                if urlparse(r.request.url).scheme=='http' and urlparse(r.request.url).netloc=='127.0.0.1:8080':r.continue_()
                else:blocked.add(r.request.url);r.abort()
            ctx.route('**/*',route)
            p=ctx.new_page(); errors=[]
            p.on('pageerror',lambda e:errors.append(str(e)))
            p.on('console',lambda m:errors.append('console:'+m.text) if m.type=='error' and m.location.get('url') not in blocked else None)
            p.on('response',lambda r:errors.append('HTTP '+str(r.status)) if r.status>=500 and r.url.startswith(base+'/') else None)
            p.goto(base+'/login/index.php');p.locator('#username').fill(a['username']);p.locator('#password').fill(a['password']);p.locator('#loginbtn').click();p.wait_for_load_state('networkidle')
            for name,url in [('tools',base+'/local/kbacompany/tasks.php?uuid='+company),('actions',base+'/local/kbacompany/tasks.php?uuid='+company+'&task='+task+'&view=detail&tab=overview')]:
                response=p.goto(url,wait_until='networkidle');require(response.status==200,'task page failed')
                menu=p.locator('.bms24-task-menu').first;require(menu.count()>0,'menu missing '+name)
                summary=menu.locator('summary');summary.scroll_into_view_if_needed()
                marker=p.locator('.bms24-task-tabs').first
                require(marker.count()>0,'tabs marker missing')
                before=marker.bounding_box();summary.focus();p.keyboard.press('Tab');p.keyboard.press('Shift+Tab')
                focus=summary.evaluate('(e)=>{let s=getComputedStyle(e);return e===document.activeElement && e.matches(":focus-visible") && (parseFloat(s.outlineWidth)>0 || s.boxShadow!=="none")}')
                require(focus,'visible keyboard focus missing');p.keyboard.press('Enter');require(menu.get_attribute('open') is not None,'keyboard did not open')
                after=marker.bounding_box();require(abs(before['y']-after['y'])<1,'content moved')
                require(p.evaluate('document.documentElement.scrollWidth<=window.innerWidth+1'),'page horizontal overflow')
                items=menu.locator('.bms24-task-menu-items'); box=items.bounding_box();require(box and box['x']>=-1 and box['x']+box['width']<=width+1,'menu clipped horizontally')
                links=items.locator('a,button');require(links.count()>0,'empty menu')
                links.last.scroll_into_view_if_needed();last=links.last.bounding_box();require(last and last['y']>=0 and last['y']+last['height']<=901,'last item inaccessible')
                require(links.last.evaluate('(e)=>{let r=e.getBoundingClientRect();let t=document.elementFromPoint(r.x+r.width/2,r.y+r.height/2);return t===e||e.contains(t)}'),'last item occluded')
                p.screenshot(path=str(out/(phase+'-'+name+'-'+str(width)+'-open.png')))
                summary.focus();p.keyboard.press('Space');require(menu.get_attribute('open') is None,'keyboard did not close')
                p.screenshot(path=str(out/(phase+'-'+name+'-'+str(width)+'.png')))
                rows.append({'width':width,'menu':name,'before':before,'after':after,'box':box,'focus_visible':focus,'blocked_external_urls':sorted(blocked),'status':'PASS'})
            require(not errors,'page/HTTP errors '+repr(errors[:3]));ctx.close()
    finally:
        browser.close();(out/(phase+'-menus.json')).write_text(json.dumps(rows,indent=1))
