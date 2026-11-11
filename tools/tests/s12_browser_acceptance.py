import json,sys
from playwright.sync_api import sync_playwright
phase=sys.argv[1];f=json.load(open('/evidence/actor.private.json'));base='http://127.0.0.1:8080';cases=[]
def record(name,ok,**detail):cases.append(dict(id=name,status='PASS' if ok else 'FAIL',**detail))
with sync_playwright() as p:
 browser=p.chromium.launch(headless=True,args=['--disable-dev-shm-usage'])
 ctx=browser.new_context(storage_state='/evidence/session.private.json') if phase in ['old','reopen'] else browser.new_context()
 page=ctx.new_page()
 if phase in ['before','new']:
  page.goto(base+'/login/index.php',wait_until='networkidle')
  page.locator('[name=username]').fill(f['username']);page.locator('[name=password]').fill(f['password'])
  page.locator('#loginbtn').click();page.wait_for_load_state('networkidle');assert '/login/' not in page.url
 denied=phase in ['old','new']
 for label,c in f['companies'].items():
  for kind,path in [('plan','/local/kbacorporate/development_plan.php?id='+str(c['plan'])),('bms','/local/kbacompany/tasks.php?uuid='+c['uuid']),('members','/local/kbacorporate/members.php?companyid='+str(c['id']))]:
   response=page.goto(base+path,wait_until='networkidle');text=page.locator('body').inner_text()
   expectdeny=denied and label=='A'
   visible='S12 private retained plan' in text if kind=='plan' else response.status==200
   record(phase+'.'+label+'.'+kind,(response.status in [403,404] and not visible) if expectdeny else response.status==200 and visible,http=response.status)
 if phase=='before':
  ctx.storage_state(path='/evidence/session.private.json')
  key=page.evaluate('M.cfg.sesskey');json.dump({'sesskey':key},open('/evidence/sesskey.private.json','w'))
 if denied:
  key=page.evaluate('M.cfg.sesskey')
  control=f['controls'][phase]
  response=ctx.request.post(base+'/local/kbacorporate/development_plan.php',form={'id':control,'action':'acceptplan','sesskey':key})
  record(phase+'.B.allowed_plan_post',response.status==200,http=response.status)
  a=f['companies']['A']
  response=ctx.request.post(base+'/local/kbacorporate/development_plan.php',form={'id':a['plan'],'action':'acceptplan','sesskey':key})
  record(phase+'.A.denied_plan_post',response.status in [403,404],http=response.status)
  response=ctx.request.post(base+'/local/kbacorporate/members.php',form={'companyid':a['id'],'delete':a['member'],'sesskey':key})
  record(phase+'.A.denied_member_delete',response.status in [403,404],http=response.status)
 browser.close()
result={'phase':phase,'status':'PASS' if all(c['status']=='PASS' for c in cases) else 'FAIL','cases':cases}
json.dump(result,open('/evidence/browser-'+phase+'.json','w'),indent=2);print(json.dumps(result))
assert result['status']=='PASS'
