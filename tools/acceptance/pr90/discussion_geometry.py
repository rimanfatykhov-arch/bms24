"""Read real Moodle layout and try a narrowly scoped CSS experiment, never force-click."""
import json,os,sys
from pathlib import Path
from urllib.parse import urlparse
from playwright.sync_api import sync_playwright

root=Path(os.environ['TEST_ROOT']); ev=Path(os.environ['EVIDENCE']); phase=sys.argv[1]
if os.environ.get('GITHUB_ACTIONS')!='true':raise SystemExit('Disposable CI only')
f=json.loads((ev/'actors.private.json').read_text()); base='http://127.0.0.1:8080'
url=base+'/local/kbacompany/tasks.php?uuid='+f['companies']['A']['uuid']+'&task='+f['tasks']['A']['uuid']+'&view=detail&tab=overview'
selector='.bms24-app .bms24-task-center .bms-task3-layout .bms-task2-aside > .bms24-task-panel'
rows=[]
with sync_playwright() as pw:
    b=pw.chromium.launch(headless=True)
    try:
        for width in [1440,768,390]:
            for experiment in [False,True]:
                ctx=b.new_context(viewport={'width':width,'height':1000})
                ctx.route('**/*',lambda r:r.continue_() if urlparse(r.request.url).scheme=='http' and urlparse(r.request.url).netloc=='127.0.0.1:8080' else r.abort())
                p=ctx.new_page();p.set_default_timeout(5000)
                a=f['actors']['MA'];p.goto(base+'/login/index.php');p.locator('#username').fill(a['username']);p.locator('#password').fill(a['password']);p.locator('#loginbtn').click();p.wait_for_load_state('networkidle')
                p.goto(url,wait_until='networkidle')
                if experiment:p.add_style_tag(content=selector+' {position:relative;top:auto;}')
                form=p.locator('form:has(input[name=action][value=discussionpost])').first
                button=form.locator('button[type=submit]');button.scroll_into_view_if_needed()
                row={'width':width,'css_experiment':experiment,'phase':phase}
                row['geometry']=button.evaluate('e=>{let r=e.getBoundingClientRect(),t=document.elementFromPoint(r.x+r.width/2,r.y+r.height/2);return {rect:r.toJSON(),hit:e===t||e.contains(t),top:t?t.outerHTML.slice(0,200):null,ancestors:Array.from((function*(n){while(n){yield n;n=n.parentElement;}})(e)).slice(0,7).map(n=>({tag:n.tagName,cls:n.className,position:getComputedStyle(n).position,top:getComputedStyle(n).top,overflow:getComputedStyle(n).overflowY,rect:n.getBoundingClientRect().toJSON()}))}}')
                row['panels']=p.locator(selector).evaluate_all('els=>els.map(e=>({position:getComputedStyle(e).position,top:getComputedStyle(e).top,rect:e.getBoundingClientRect().toJSON(),scrollHeight:e.scrollHeight,clientHeight:e.clientHeight}))')
                try:button.click(trial=True);row['actionable']=True
                except Exception as e:row['actionable']=False;row['error']=type(e).__name__
                p.screenshot(path=str(root/'public'/('geometry-'+phase+'-'+str(width)+'-'+str(experiment)+'.png')))
                rows.append(row);ctx.close()
    finally:
        b.close();(root/'public'/('geometry-'+phase+'.json')).write_text(json.dumps(rows,indent=2))
if not all(r['actionable'] and r['geometry']['hit'] for r in rows if r['css_experiment']):raise RuntimeError('proposed layout does not restore hit target')
