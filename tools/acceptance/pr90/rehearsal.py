"""One-off synthetic Moodle rehearsal. Never reads production backups or external credentials."""
import hashlib,json,os,shutil,signal,subprocess,sys,time,urllib.request,zipfile
from pathlib import Path
root=Path(os.environ['TEST_ROOT']).resolve(); runner=Path(os.environ['RUNNER_TEMP']).resolve()
core=Path(os.environ['MOODLE_ROOT']).resolve(); cand=Path(os.environ['CANDIDATE_ROOT']).resolve()
origins=json.loads((cand/'tools/acceptance/pr90/origins.json').read_text());BASE=origins['main']
if BASE!='f34ab6ff0d2f67a56972e99c23d096ed77a9873c':raise RuntimeError('baseline product changed; independently review validator pair')
BASELINE_VALIDATOR='01af6405bcb7db73f67cb45fee837ba97b8c6ed3'
if origins.get('baseline_validator')!=BASELINE_VALIDATOR:raise RuntimeError('baseline validator pin changed; independent review required')
if os.environ.get('GITHUB_ACTIONS')!='true' or runner not in root.parents or os.environ.get('DBHOST')!='127.0.0.1':
    raise SystemExit('Disposable Actions environment required')
public=root/'public'; public.mkdir(parents=True,exist_ok=True)
ev=root/'evidence'; scripts=cand/'tools/ci/browser'
env=dict(os.environ,BMS_CI_BROWSER='1',EVIDENCE=str(ev),MYSQL_PWD=os.environ['DBPASS'])
report={'status':'FAIL','engine':env['DBTYPE'],'baseline':BASE,'steps':{},'timings_seconds':{},'matrix':{f'M{i:02}':'NOT RUN' for i in range(1,11)}}
server=None
began=time.monotonic();phase_started=began

def elapsed(label):
    global phase_started
    now=time.monotonic();report['timings_seconds'][label]=round(now-phase_started,3);phase_started=now

def verify_package():
    package=Path(os.environ['PACKAGE_ROOT']).resolve()
    if runner not in package.parents:raise RuntimeError('package outside disposable runner')
    manifest=json.loads((package/'manifest.json').read_text())
    sums=(package/'SHA256SUMS').read_text().splitlines()
    if len(sums)!=2 or {line.split('  ',1)[1] for line in sums}!={'candidate-install.zip','manifest.json'}:raise RuntimeError('incomplete checksums')
    for line in sums:
        digest,name=line.split('  ',1)
        if name not in ['candidate-install.zip','manifest.json'] or hashlib.sha256((package/name).read_bytes()).hexdigest()!=digest:
            raise RuntimeError('package checksum mismatch')
    if manifest['source']['checkout_sha']!=git('rev-parse','HEAD') or manifest['source']['tree']!=git('rev-parse','HEAD^{tree}'):
        raise RuntimeError('package source identity mismatch')
    if manifest['source']['pr_head']!=os.environ['PR_HEAD_SHA'] or manifest['source']['base']!=os.environ['PR_BASE_SHA'] or manifest['source']['base']!=BASE:
        raise RuntimeError('package PR identity/base changed; re-evaluate integration')
    sys.path.insert(0,str(cand/'tools'));from build_install import collect_files
    _,expected=collect_files(cand/'local/kbacompany');expected=dict(expected)
    entries={r['path']:r for r in manifest['entries']}
    with zipfile.ZipFile(package/'candidate-install.zip') as z:
        if z.testzip() or set(z.namelist())!=set(expected) or set(entries)!=set(expected) or len(z.namelist())!=len(expected):
            raise RuntimeError('package content inventory mismatch')
        for name,source in expected.items():
            if not name.startswith('kbacompany/') or '..' in Path(name).parts:raise RuntimeError('unsafe archive path')
            data=z.read(name)
            if data!=source.read_bytes() or hashlib.sha256(data).hexdigest()!=entries[name]['sha256']:
                raise RuntimeError('package bytes do not match candidate')
    for name in ['candidate-install.zip','manifest.json','SHA256SUMS']:shutil.copyfile(package/name,public/name)
    digest=hashlib.sha256((public/'candidate-install.zip').read_bytes()).hexdigest()
    if digest!=manifest['artifact']['zip_sha256']:raise RuntimeError('manifest ZIP digest mismatch')
    report.update(zip_sha256=digest,package_source=manifest['source'],components=manifest['components'],origins=origins)
def cmd(args,**kw):
    return subprocess.run([str(x) for x in args],env=kw.pop('env',env),cwd=core,check=True,**kw)
def git(*args): return subprocess.check_output(['git','-C',str(cand),*args],text=True).strip()
def stop():
    global server
    if server:
        os.killpg(server.pid,signal.SIGTERM); server.wait(timeout=20); server=None; time.sleep(2)
def start():
    global server
    log=open(root/'server.private.log','a')
    server=subprocess.Popen(['php','-S','127.0.0.1:8080','-t',str(core)],env=env,stdout=log,stderr=log,start_new_session=True)
    for _ in range(40):
        try:
            if urllib.request.urlopen('http://127.0.0.1:8080/login/index.php',timeout=2).status==200:return
        except Exception:time.sleep(1)
    raise RuntimeError('server unavailable')
def state():
    cmd(['php',scripts/'ci_state.php',core,'hist'],stdout=subprocess.DEVNULL)
    return json.loads((ev/'p02-state-hist.json').read_text())['history']
def digest_tree(p):
    return {str(f.relative_to(p)):hashlib.sha256(f.read_bytes()).hexdigest() for f in p.rglob('*') if f.is_file()}
def install():
    stop(); shutil.rmtree(core/'local/kbacompany')
    with zipfile.ZipFile(public/'candidate-install.zip') as z:z.extractall(core/'local')
    cmd(['php',core/'admin/cli/upgrade.php','--non-interactive'],stdout=subprocess.DEVNULL)
    cmd(['php',core/'admin/cli/purge_caches.php'],stdout=subprocess.DEVNULL)
    entries=json.loads((public/'manifest.json').read_text())['entries']
    expected={e['path'].removeprefix('kbacompany/'):e['sha256'] for e in entries}
    if digest_tree(core/'local/kbacompany')!=expected:raise RuntimeError('installed plugin differs from immutable package')
def smoke(phase):
    start()
    observed(Path(__file__).with_name('discussion_geometry.py'),phase+'-geometry',phase)
    observed(Path(__file__).with_name('menus.py'),phase+'-menus',phase)
    role_checks(phase)
    observed(scripts/'access.py',phase+'-access','all')
    sys.path.insert(0,str(scripts)); import contract
    a=json.loads((ev/'p02-access.json').read_text()); checks=contract.evaluate(contract.access_checks(a))
    (public/(phase+'-access.json')).write_text(json.dumps({'checks':checks,'observations':a},indent=1))
    if any(x['status']!='PASS' for x in checks.values()):raise RuntimeError('access smoke failed')
    observed(scripts/'task_tabs.py',phase+'-tabs','ci')
    tabs=json.loads((ev/'pilot01-ci.json').read_text()); checks=contract.evaluate(contract.task_tab_checks(tabs))
    (public/(phase+'-tabs.json')).write_text(json.dumps({'checks':checks,'observations':tabs},indent=1))
    if any(x['status']!='PASS' for x in checks.values()):raise RuntimeError('tabs/mobile failed')
    write_checks(phase)
    multiattachment_checks(phase)
    menu_overlay_checks(phase)
    for mode in ['owned','legacy']:
        cmd(['php',Path(__file__).with_name('shell.php'),mode],stdout=subprocess.DEVNULL)
        observed(Path(__file__).parents[1]/'discussion_button.py',phase+'-'+mode+'-discussion',phase,mode)
    cmd(['php',Path(__file__).with_name('shell.php'),'owned'],stdout=subprocess.DEVNULL)
    # Include files created by this candidate's write smoke, not only the baseline URLs.
    observed(scripts/'access.py',phase+'-postwrite-access','all')
    post=json.loads((ev/'p02-access.json').read_text()); checks=contract.evaluate(contract.access_checks(post))
    (public/(phase+'-postwrite-access.json')).write_text(json.dumps({'checks':checks,'observations':post},indent=1))
    if any(x['status']!='PASS' for x in checks.values()):raise RuntimeError('new-file ACL/revoke smoke failed')
    stop()
def multiattachment_checks(phase):
    # Intentional HTTP 500/302 controls have their own strict, provenance-bound recorder.
    # The ordinary observer must not reinterpret these as unmarked network exceptions.
    sys.path.insert(0,str(scripts)); import contract
    target=ev/(phase+'-multiattachments');target.mkdir()
    shutil.copyfile(ev/'actors.private.json',target/'actors.private.json')
    result=subprocess.run([sys.executable,str(scripts/'multiattachments.py')],cwd=core,
        env=dict(env,EVIDENCE=str(target)),stdout=subprocess.DEVNULL,stderr=subprocess.DEVNULL)
    resultfile=target/'multiattachment-result.json'
    obs=json.loads(resultfile.read_text()) if resultfile.exists() else None
    checks=contract.evaluate(contract.multiattachment_checks(obs,result.returncode))
    (public/(phase+'-multiattachments.json')).write_text(json.dumps({'exitcode':result.returncode,'checks':checks,'observations':obs},indent=2))
    if not checks or any(v['status']!='PASS' for v in checks.values()):
        raise RuntimeError('required candidate multiattachment/negative controls failed')

def validate_menu_report(obs,mode,csshash):
    """Independently validate complete observations, not a producer PASS label/count."""
    import math
    import contract
    def require(ok,reason):
        if not ok:raise RuntimeError('menu evidence: '+reason)
    def number(value):return type(value) in (int,float) and math.isfinite(value)
    require(isinstance(obs,dict) and obs.get('status')=='PASS','missing or failed report')
    require(obs.get('shell')==mode and obs.get('failures')==[],'wrong shell or missing/error failures')
    events=obs.get('browser_events')
    require(isinstance(events,list) and not contract.browser_event_violations(events),'missing or invalid browser/network events')
    widths=(320,360,390,480,482,720,768,1024,1280,1440)
    contexts=obs.get('browser_contexts')
    require(isinstance(contexts,list) and len(contexts)==len(widths),'missing browser contexts')
    context_widths=set();flat=[]
    for context in contexts:
        require(isinstance(context,dict),'malformed browser context')
        width=context.get('width');recorded=context.get('events')
        require(type(width) is int and width in widths and width not in context_widths,'duplicate/unexpected browser context width')
        require(isinstance(recorded,list) and not contract.browser_event_violations(recorded),'browser error/provenance mismatch in context')
        context_widths.add(width);flat.extend(recorded)
    require(context_widths==set(widths) and flat==events,'browser contexts and flat evidence differ')
    expected={(width,kind) for width in widths for kind in ('tools','actions','history')}
    rows=obs.get('rows');require(isinstance(rows,list) and len(rows)==len(expected),'incomplete matrix')
    seen=set()
    for row in rows:
        require(isinstance(row,dict),'malformed row')
        width=row.get('width');kind='history' if 'history' in row else row.get('menu')
        require(type(width) is int and isinstance(kind,str) and (width,kind) in expected,'unexpected width/case')
        require((width,kind) not in seen,'duplicate width/case');seen.add((width,kind))
        if kind=='history':
            require(row.get('history')=='PASS' and 'menu' not in row,'history not passed')
            continue
        require(row.get('status')=='PASS' and row.get('ordinary_click')=='navigation and closure verified','menu interaction not proved')
        before,after=row.get('before'),row.get('after')
        require(isinstance(before,dict) and isinstance(after,dict),'missing geometry')
        for geometry in (before,after):
            require(number(geometry.get('pageWidth')) and abs(geometry['pageWidth']-width)<=1,'wrong CSS viewport')
            require(number(geometry.get('scrollWidth')) and geometry['scrollWidth']<=geometry['pageWidth']+1,'horizontal overflow')
            require(isinstance(geometry.get('card'),dict),'missing card geometry')
        for key in ('pageWidth','scrollWidth'):
            require(abs(before[key]-after[key])<=1,'page geometry changed')
        for key in ('x','y','width','height'):
            require(number(before['card'].get(key)) and number(after['card'].get(key)) and
                abs(before['card'][key]-after['card'][key])<=1,'card geometry changed/missing')
        panel=row.get('panel');require(isinstance(panel,dict),'missing panel')
        require(all(number(panel.get(key)) for key in ('x','y','width','height')),'invalid panel geometry')
        require(panel['width']>0 and panel['height']>0 and panel['x']>=-1 and panel['y']>=-1 and
            panel['x']+panel['width']<=width+1 and panel['y']+panel['height']<=901,'panel outside viewport')
    require(seen==expected,'missing required case')
    css=obs.get('loaded_css');require(isinstance(css,list),'CSS observations missing')
    if mode=='owned':
        require(bool(css) and all(isinstance(item,dict) and item.get('status')==200 and item.get('sha256')==csshash
            for item in css),'loaded owned CSS mismatch/missing')

def menu_overlay_checks(phase):
    csshash=hashlib.sha256((core/'local/kbacompany/bms_ui/task_center.css').read_bytes()).hexdigest()
    try:
        for mode in ['owned','legacy']:
            cmd(['php',Path(__file__).with_name('shell.php'),mode],stdout=subprocess.DEVNULL)
            target=ev/(phase+'-menu-overlay-'+mode);target.mkdir()
            result=subprocess.run([sys.executable,str(cand/'scripts/test_task_menu_overlay_browser.py')],cwd=core,
                env=dict(env,BMS_RECOVERY_BROWSER='1',TEST_ROOT=str(ev),EVIDENCE=str(target),
                    EXPECTED_SHELL=mode,EXPECTED_TASK_CSS_SHA256=csshash),stdout=subprocess.DEVNULL,stderr=subprocess.DEVNULL)
            resultfile=target/('task-menu-overlay-'+mode+'.json')
            obs=json.loads(resultfile.read_text()) if resultfile.exists() else {}
            (public/(phase+'-menu-overlay-'+mode+'.json')).write_text(json.dumps(obs,indent=2))
            if result.returncode:
                raise RuntimeError('required ten-width menu/history matrix failed: '+mode)
            validate_menu_report(obs,mode,csshash)
    finally:
        cmd(['php',Path(__file__).with_name('shell.php'),'owned'],stdout=subprocess.DEVNULL)

def write_checks(phase):
    sys.path.insert(0,str(scripts)); import contract
    observed(scripts/'attachments.py',phase+'-writes',phase)
    cmd(['php',scripts/'ci_state.php',core,phase],stdout=subprocess.DEVNULL)
    obs=json.loads((ev/('p02-browser-'+phase+'.json')).read_text()); st=json.loads((ev/('p02-state-'+phase+'.json')).read_text())
    checks=contract.evaluate(contract.attachments_checks(obs,st,phase))
    (public/(phase+'-writes.json')).write_text(json.dumps({'checks':checks,'observations':obs},indent=1))
    # Independent acceptance must not inherit PR96's page_errors false PASS.
    if obs.get('page_errors') or any(x['status']!='PASS' for x in checks.values()):raise RuntimeError('write/CSRF/page errors failed')
def role_checks(phase):
    for mode in ['owned','legacy']:
        cmd(['php',Path(__file__).with_name('shell.php'),mode],stdout=subprocess.DEVNULL)
        observed(Path(__file__).with_name('roles.py'),phase+'-'+mode+'-roles',phase,mode)
    cmd(['php',Path(__file__).with_name('shell.php'),'owned'],stdout=subprocess.DEVNULL)
def observed(script,tag,*args):
    cmd([sys.executable,Path(__file__).with_name('observe.py'),script,tag,*args],stdout=subprocess.DEVNULL)
try:
    report.update(head=git('rev-parse','HEAD'),tree=git('rev-parse','HEAD^{tree}'))
    verify_package();elapsed('verify_package')
    phpversion=cmd(['php','-r','echo PHP_VERSION;'],stdout=subprocess.PIPE,text=True).stdout.strip()
    dbversion=cmd(['mysql','-h127.0.0.1','-P'+env['DBPORT'],'-u'+env['DBUSER'],'-N','-e','SELECT VERSION()'],stdout=subprocess.PIPE,text=True).stdout.strip()
    coregit=subprocess.check_output(['git','-C',str(core),'rev-parse','HEAD'],text=True).strip()
    report['runtime']={'php':phpversion,'database':dbversion,'moodle_git':coregit,'moodle':'4.1.22'}
    if not phpversion.startswith('8.1.') or not dbversion.startswith('10.11.19' if env['DBTYPE']=='mariadb' else '8.0.46-37') or coregit!='df1157bb845eabedd31989d6a1a6e18f638ff890':
        raise RuntimeError('unexpected runtime versions')
    baseline=root/'baseline-source'
    cmd(['git','-C',cand,'worktree','add','--detach',baseline,BASE],stdout=subprocess.DEVNULL)
    # Pin the accepted pre-feature validator, not the new feature contract against old code.
    # This is a distinct baseline checkset; candidate/reinstall retain every current required check.
    validator=root/'baseline-validator'
    cmd(['git','-C',cand,'worktree','add','--detach',validator,BASELINE_VALIDATOR],stdout=subprocess.DEVNULL)
    actualvalidator=subprocess.check_output(['git','-C',str(validator),'rev-parse','HEAD'],text=True).strip()
    if actualvalidator!=BASELINE_VALIDATOR:raise RuntimeError('baseline validator checkout mismatch')
    report['baseline_validation']={'product_sha':BASE,'validator_sha':actualvalidator,
        'checkset':'accepted PR100 BROWSER prior to multiattachment feature',
        'validator_files':digest_tree(validator/'tools/ci/browser'),
        'runner_sha256':hashlib.sha256((validator/'tools/ci/browser_acceptance.py').read_bytes()).hexdigest()}
    cmd([sys.executable,validator/'tools/ci/browser_acceptance.py'],env=dict(env,CANDIDATE_ROOT=str(baseline)),stdout=open(root/'baseline.private.log','w'))
    shutil.copyfile(root/'browser-report.json',public/'baseline-browser-report.json')
    report['steps']['baseline_browser']='PASS'
    start();role_checks('baseline')
    observed(Path(__file__).with_name('discussion_geometry.py'),'baseline-geometry','baseline')
    stop()
    start()
    try:
        write_checks('baseline_repeat')
        raise RuntimeError('baseline regression did not reproduce')
    except Exception:
        # An expected BEFORE failure is evidence, not a candidate PASS. Check the exact failure,
        # not just any exception; later candidate/reinstall writes must all succeed without overrides.
        old=json.loads((public/'baseline_repeat-writes.json').read_text())
        geometry=json.loads((public/'geometry-baseline.json').read_text())
        error=old['observations'].get('execution_error','')
        blocked=[r for r in geometry if r['width']==1440 and not r['css_experiment']]
        if not ('Timeout' in error and 'click' in error and blocked and not blocked[0]['actionable'] and not blocked[0]['geometry']['hit']):
            raise RuntimeError('baseline failure differs from known overlap')
        report['baseline_regression']={'status':'EXPECTED FAIL','error':error,'geometry':'geometry-baseline.json'}
        report['steps']['baseline_overlap_reproduced']='PASS'
    stop()
    elapsed('baseline_reproduction')
    before=state(); snapshot=root/'snapshot'; snapshot.mkdir()
    baseline_urls=(ev/'p02-urls.json').read_bytes()
    shutil.copytree(core,snapshot/'code'); shutil.copytree(root/'data',snapshot/'data')
    dbargs=['-h127.0.0.1','-P'+env['DBPORT'],'-u'+env['DBUSER']]
    with open(snapshot/'db.sql','wb') as f:cmd(['mysqldump',*dbargs,'--single-transaction','--skip-comments','--skip-dump-date','bmsci_web'],stdout=f)
    files_before=digest_tree(root/'data/filedir'); data_before=digest_tree(root/'data'); code_before=digest_tree(core)
    report['steps']['snapshot_code_db_files']='PASS'
    elapsed('snapshot_code_db_files')
    install()
    try:smoke('candidate');report['steps']['candidate_install_smoke']='PASS'
    except Exception as e:report['steps']['candidate_install_smoke']='FAIL: '+str(e)[:160]
    elapsed('install_and_smoke')
    # Full restore, not just code downgrade. No running HTTP process while restoring.
    stop(); shutil.rmtree(core); shutil.copytree(snapshot/'code',core)
    shutil.rmtree(root/'data'); shutil.copytree(snapshot/'data',root/'data')
    cmd(['mysql',*dbargs,'-e','DROP DATABASE bmsci_web; CREATE DATABASE bmsci_web CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci'])
    with open(snapshot/'db.sql','rb') as f:cmd(['mysql',*dbargs,'bmsci_web'],stdin=f)
    with open(snapshot/'restored.sql','wb') as f:cmd(['mysqldump',*dbargs,'--single-transaction','--skip-comments','--skip-dump-date','bmsci_web'],stdout=f)
    if (snapshot/'db.sql').read_bytes()!=(snapshot/'restored.sql').read_bytes():raise RuntimeError('whole restored DB differs')
    if digest_tree(root/'data')!=data_before:raise RuntimeError('complete restored moodledata differs before restart')
    if digest_tree(root/'data/filedir')!=files_before or digest_tree(core)!=code_before or state()!=before:raise RuntimeError('restore content differs')
    (ev/'p02-urls.json').write_bytes(baseline_urls)
    start(); cmd([sys.executable,scripts/'access.py','all'],stdout=subprocess.DEVNULL); stop()
    restored=json.loads((ev/'p02-access.json').read_text())
    import contract
    checks=contract.evaluate(contract.access_checks(restored))
    (public/'restore-checks.json').write_text(json.dumps({'checks':checks,'observations':restored},indent=1))
    if any(v['status']!='PASS' for v in checks.values()):raise RuntimeError('restored ACL/files failed')
    report['steps']['restore_code_db_files_acl']='PASS'
    elapsed('restore_and_verify_code_db_files_acl')
    install()
    try:smoke('reinstall');report['steps']['reinstall']='PASS'
    except Exception as e:report['steps']['reinstall']='FAIL: '+str(e)[:160]
    elapsed('reinstall_and_smoke')
    report['matrix'].update({k:'PASS: real menu geometry, viewport, hit-test and keyboard observations' for k in ['M01','M02','M03','M04','M05']})
    report['matrix']['M06']='PASS: candidate and reinstall task-tab 44px/keyboard scenarios'
    report['matrix']['M08']='PASS: synthetic task discussion write, double submit, invalid CSRF; not task lifecycle transition'
    report['matrix']['M07']='PASS: same MA/EA/EB menu rights and explicit foreign task refusals as baseline'
    report['matrix']['M09']='PASS: owned/legacy allowed and forbidden routes on baseline/candidate/reinstall'
    report['matrix']['M10']='PASS: independent observer on menu/roles/access/tabs/writes, candidate and reinstall'
    if all(v=='PASS' for v in report['steps'].values()):report['status']='PASS'
    else:
        report['status']='FAIL'
        # Preserve detailed per-scenario evidence; do not infer any complete matrix PASS from partial smoke.
        report['matrix']={k:'NOT ACCEPTED: inspect per-phase observations and failing steps' for k in report['matrix']}
except Exception as e:
    report['error']=type(e).__name__+': '+str(e)[:180]
finally:
    stop();report['timings_seconds']['total']=round(time.monotonic()-began,3)
    report['comparison_policy']='Before restart: snapshot and restored whole DB dumps (no comments/dump date) must be byte equal; all code and complete moodledata file hashes must match. After restart: filedir, history, ACL and downloads rechecked; cache/session temp files may be recreated. Synthetic only, not production backup acceptance.'
    (public/'rehearsal.json').write_text(json.dumps(report,indent=2))
print(json.dumps(report,indent=2))
sys.exit(0 if report['status']=='PASS' else 1)
