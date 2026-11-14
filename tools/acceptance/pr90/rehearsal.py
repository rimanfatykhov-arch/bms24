"""One-off synthetic Moodle rehearsal. Never reads production backups or external credentials."""
import hashlib,json,os,shutil,signal,subprocess,sys,time,urllib.request,zipfile
from pathlib import Path
BASE='f34ab6ff0d2f67a56972e99c23d096ed77a9873c'
root=Path(os.environ['TEST_ROOT']).resolve(); runner=Path(os.environ['RUNNER_TEMP']).resolve()
core=Path(os.environ['MOODLE_ROOT']).resolve(); cand=Path(os.environ['CANDIDATE_ROOT']).resolve()
if os.environ.get('GITHUB_ACTIONS')!='true' or runner not in root.parents or os.environ.get('DBHOST')!='127.0.0.1':
    raise SystemExit('Disposable Actions environment required')
public=root/'public'; public.mkdir(parents=True,exist_ok=True)
ev=root/'evidence'; scripts=cand/'tools/ci/browser'
env=dict(os.environ,BMS_CI_BROWSER='1',EVIDENCE=str(ev),MYSQL_PWD=os.environ['DBPASS'])
report={'status':'FAIL','engine':env['DBTYPE'],'baseline':BASE,'steps':{},'matrix':{f'M{i:02}':'NOT RUN' for i in range(1,11)}}
server=None
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
def smoke(phase):
    start()
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
    stop()
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
    report.update(head=git('rev-parse','HEAD'),tree=git('rev-parse','HEAD^{tree}'),pr96='38a2115b9b00692604b9b0520239bc07014d4944',pr90='62a3e9cafd8252fffc6389af044bf469ff7e3853')
    baseline=root/'baseline-source'
    cmd(['git','-C',cand,'worktree','add','--detach',baseline,BASE],stdout=subprocess.DEVNULL)
    # Existing isolated fresh-install + A/B fixture harness, using baseline source and PR96 validators.
    cmd([sys.executable,cand/'tools/ci/browser_acceptance.py'],env=dict(env,CANDIDATE_ROOT=str(baseline)),stdout=open(root/'baseline.private.log','w'))
    report['steps']['baseline_browser']='PASS'
    start();role_checks('baseline');stop()
    start()
    try:write_checks('baseline_repeat');report['steps']['baseline_repeat_write']='PASS'
    except Exception as e:report['steps']['baseline_repeat_write']='FAIL: '+type(e).__name__
    stop()
    before=state(); snapshot=root/'snapshot'; snapshot.mkdir()
    shutil.copytree(core,snapshot/'code'); shutil.copytree(root/'data',snapshot/'data')
    dbargs=['-h127.0.0.1','-P'+env['DBPORT'],'-u'+env['DBUSER']]
    with open(snapshot/'db.sql','wb') as f:cmd(['mysqldump',*dbargs,'--single-transaction','--skip-comments','--skip-dump-date','bmsci_web'],stdout=f)
    files_before=digest_tree(root/'data/filedir'); code_before=digest_tree(core)
    report['steps']['snapshot_code_db_files']='PASS'
    cmd([sys.executable,cand/'tools/build_install.py','--source',cand/'local/kbacompany','--output',public/'candidate-install.zip','--manifest',public/'manifest.json'])
    manifest=json.loads((public/'manifest.json').read_text()); manifest['source']={k:report[k] for k in ['head','tree','baseline','pr90','pr96']}; (public/'manifest.json').write_text(json.dumps(manifest,indent=2))
    report['zip_sha256']=hashlib.sha256((public/'candidate-install.zip').read_bytes()).hexdigest()
    (public/'SHA256SUMS').write_text(report['zip_sha256']+'  candidate-install.zip\n')
    install()
    try:smoke('candidate');report['steps']['candidate_install_smoke']='PASS'
    except Exception as e:report['steps']['candidate_install_smoke']='FAIL: '+str(e)[:160]
    # Full restore, not just code downgrade. No running HTTP process while restoring.
    stop(); shutil.rmtree(core); shutil.copytree(snapshot/'code',core)
    shutil.rmtree(root/'data'); shutil.copytree(snapshot/'data',root/'data')
    cmd(['mysql',*dbargs,'-e','DROP DATABASE bmsci_web; CREATE DATABASE bmsci_web CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci'])
    with open(snapshot/'db.sql','rb') as f:cmd(['mysql',*dbargs,'bmsci_web'],stdin=f)
    with open(snapshot/'restored.sql','wb') as f:cmd(['mysqldump',*dbargs,'--single-transaction','--skip-comments','--skip-dump-date','bmsci_web'],stdout=f)
    if (snapshot/'db.sql').read_bytes()!=(snapshot/'restored.sql').read_bytes():raise RuntimeError('whole restored DB differs')
    if digest_tree(root/'data/filedir')!=files_before or digest_tree(core)!=code_before or state()!=before:raise RuntimeError('restore content differs')
    start(); cmd([sys.executable,scripts/'access.py','all'],stdout=subprocess.DEVNULL); stop()
    restored=json.loads((ev/'p02-access.json').read_text())
    import contract
    checks=contract.evaluate(contract.access_checks(restored))
    if any(v['status']!='PASS' for v in checks.values()):raise RuntimeError('restored ACL/files failed')
    (public/'restore-checks.json').write_text(json.dumps(checks,indent=1)); report['steps']['restore_code_db_files_acl']='PASS'
    install()
    try:smoke('reinstall');report['steps']['reinstall']='PASS'
    except Exception as e:report['steps']['reinstall']='FAIL: '+str(e)[:160]
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
    stop(); (public/'rehearsal.json').write_text(json.dumps(report,indent=2))
print(json.dumps(report,indent=2))
sys.exit(0 if report['status']=='PASS' else 1)
