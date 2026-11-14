"""Real Moodle regression: accumulated discussion stays reachable by pointer and keyboard.

Runs only in disposable synthetic Actions acceptance. Does not seed messages with direct
POST, force-click, submit JavaScript, remove history, or disable neighbouring panels.
"""
import json,os,sys
from pathlib import Path
from urllib.parse import urlparse
from playwright.sync_api import sync_playwright

root=Path(os.environ['TEST_ROOT']).resolve(); runner=Path(os.environ['RUNNER_TEMP']).resolve()
if os.environ.get('GITHUB_ACTIONS')!='true' or runner not in root.parents or os.environ.get('BMS_CI_BROWSER')!='1':
    raise SystemExit('Disposable synthetic Actions environment required')
phase,mode=sys.argv[1:3]; f=json.loads((Path(os.environ['EVIDENCE'])/'actors.private.json').read_text())
base='http://127.0.0.1:8080'
url=base+'/local/kbacompany/tasks.php?uuid='+f['companies']['A']['uuid']+'&task='+f['tasks']['A']['uuid']+'&view=detail&tab=overview'
rows=[];status='FAIL'

def require(ok,message):
    if not ok:raise RuntimeError(message)

with sync_playwright() as pw:
    b=pw.chromium.launch(headless=True)
    try:
        for width in [1440,768,390]:
            ctx=b.new_context(viewport={'width':width,'height':1000})
            ctx.route('**/*',lambda r:r.continue_() if urlparse(r.request.url).scheme=='http' and urlparse(r.request.url).netloc=='127.0.0.1:8080' else r.abort())
            p=ctx.new_page();p.set_default_timeout(10000);a=f['actors']['MA']
            p.goto(base+'/login/index.php');p.locator('#username').fill(a['username']);p.locator('#password').fill(a['password']);p.locator('#loginbtn').click();p.wait_for_load_state('networkidle')
            response=p.goto(url,wait_until='networkidle');require(response.status==200,'task HTTP status')
            for method in ['pointer','keyboard']:
                message='discussion-regression-'+phase+'-'+mode+'-'+str(width)+'-'+method
                form=p.locator('form:has(input[name=action][value=discussionpost])').first
                textarea=form.locator('textarea');button=form.locator('button[type=submit]')
                textarea.fill(message)
                if method=='keyboard':
                    textarea.focus()
                    for _ in range(12):
                        p.keyboard.press('Tab')
                        if button.evaluate('e=>e===document.activeElement'):break
                    require(button.evaluate('e=>e===document.activeElement'),'button absent from keyboard sequence')
                    require(button.evaluate('e=>e.matches(":focus-visible")'),'keyboard focus not visible')
                else:button.scroll_into_view_if_needed()
                geometry=button.evaluate('e=>{let r=e.getBoundingClientRect(),t=document.elementFromPoint(r.x+r.width/2,r.y+r.height/2),s=getComputedStyle(e);return {hit:e===t||e.contains(t),disabled:e.disabled,rect:r.toJSON(),focus:e===document.activeElement,outline:s.outlineStyle,outlineWidth:s.outlineWidth}}')
                require(geometry['hit'] and not geometry['disabled'],'send button covered or disabled')
                if mode=='owned':require(p.locator('.bms-task2-aside > .bms24-task-panel').count()>=2,'discussion or recent activity removed')
                p.screenshot(path=str(root/'public'/('discussion-'+phase+'-'+mode+'-'+str(width)+'-'+method+'.png')))
                with p.expect_navigation() as navigation:
                    if method=='pointer':button.click()
                    else:p.keyboard.press('Enter')
                require(navigation.value.status<400,'write navigation failed')
                p.wait_for_load_state('networkidle');p.reload(wait_until='networkidle')
                require(p.get_by_text(message,exact=True).count()==1,'message not persisted exactly once after reload')
                rows.append({'width':width,'method':method,'message':message,'geometry':geometry,'persisted_exactly_once':True})
            ctx.close()
        status='PASS'
    finally:
        b.close();(root/'public'/('discussion-'+phase+'-'+mode+'.json')).write_text(json.dumps({'status':status,'rows':rows},indent=2))
