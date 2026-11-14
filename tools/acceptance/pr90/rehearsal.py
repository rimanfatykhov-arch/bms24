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
    cmd([sys.executable,Path(__file__).with_name('menus.py'),phase])
    cmd([sys.executable,scripts/'access.py','all'],stdout=subprocess.DEVNULL)
    sys.path.insert(0,str(scripts)); import contract
    a=json.loads((ev/'p02-access.json').read_text()); checks=contract.evaluate(contract.access_checks(a))
    (public/(phase+'-access.json')).write_text(json.dumps({'checks':checks,'observations':a},indent=1))
    if any(x['status']!='PASS' for x in checks.values()):raise RuntimeError('access smoke failed')
    stop()
try:
    report.update(head=git('rev-parse','HEAD'),tree=git('rev-parse','HEAD^{tree}'),pr96='38a2115b9b00692604b9b0520239bc07014d4944',pr90='62a3e9cafd8252fffc6389af044bf469ff7e3853')
    baseline=root/'baseline-source'
    cmd(['git','-C',cand,'worktree','add','--detach',baseline,BASE],stdout=subprocess.DEVNULL)
    # Existing isolated fresh-install + A/B fixture harness, using baseline source and PR96 validators.
    cmd([sys.executable,cand/'tools/ci/browser_acceptance.py'],env=dict(env,CANDIDATE_ROOT=str(baseline)),stdout=open(root/'baseline.private.log','w'))
    report['steps']['baseline_browser']='PASS'
    before=state(); snapshot=root/'snapshot'; snapshot.mkdir()
    shutil.copytree(core,snapshot/'code'); shutil.copytree(root/'data',snapshot/'data')
    dbargs=['-h127.0.0.1','-P'+env['DBPORT'],'-u'+env['DBUSER']]
    with open(snapshot/'db.sql','wb') as f:cmd(['mysqldump',*dbargs,'--single-transaction','--skip-comments','--skip-dump-date','bmsci_web'],stdout=f)
    files_before=digest_tree(root/'data/filedir'); code_before=digest_tree(core/'local/kbacompany')
    report['steps']['snapshot_code_db_files']='PASS'
    cmd([sys.executable,cand/'tools/build_install.py','--source',cand/'local/kbacompany','--output',public/'candidate-install.zip','--manifest',public/'manifest.json'])
    manifest=json.loads((public/'manifest.json').read_text()); manifest['source']={k:report[k] for k in ['head','tree','baseline','pr90','pr96']}; (public/'manifest.json').write_text(json.dumps(manifest,indent=2))
    report['zip_sha256']=hashlib.sha256((public/'candidate-install.zip').read_bytes()).hexdigest()
    (public/'SHA256SUMS').write_text(report['zip_sha256']+'  candidate-install.zip\n')
    install(); smoke('candidate'); report['steps']['candidate_install_smoke']='PASS'
    # Full restore, not just code downgrade. No running HTTP process while restoring.
    stop(); shutil.rmtree(core); shutil.copytree(snapshot/'code',core)
    shutil.rmtree(root/'data'); shutil.copytree(snapshot/'data',root/'data')
    cmd(['mysql',*dbargs,'-e','DROP DATABASE bmsci_web; CREATE DATABASE bmsci_web CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci'])
    with open(snapshot/'db.sql','rb') as f:cmd(['mysql',*dbargs,'bmsci_web'],stdin=f)
    if digest_tree(root/'data/filedir')!=files_before or digest_tree(core/'local/kbacompany')!=code_before or state()!=before:raise RuntimeError('restore content differs')
    start(); cmd([sys.executable,scripts/'access.py','all'],stdout=subprocess.DEVNULL); stop()
    restored=json.loads((ev/'p02-access.json').read_text())
    import contract
    checks=contract.evaluate(contract.access_checks(restored))
    if any(v['status']!='PASS' for v in checks.values()):raise RuntimeError('restored ACL/files failed')
    (public/'restore-checks.json').write_text(json.dumps(checks,indent=1)); report['steps']['restore_code_db_files_acl']='PASS'
    install(); smoke('reinstall'); report['steps']['reinstall']='PASS'
    # Full M07 task-action ACL / M08 task write / M09 legacy still need explicit scenarios; do not infer them from attachment ACL.
    report['matrix'].update({k:'PARTIAL: menu observations available; full acceptance not implied' for k in ['M01','M02','M03','M04','M05']})
    report['status']='PARTIAL'
except Exception as e:
    report['error']=type(e).__name__+': '+str(e)[:180]
finally:
    stop(); (public/'rehearsal.json').write_text(json.dumps(report,indent=2))
print(json.dumps(report,indent=2))
sys.exit(0 if report['status']=='PASS' else 1)
