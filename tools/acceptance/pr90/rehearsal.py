"""One-off synthetic Moodle rehearsal. Never reads production backups or external credentials."""
import hashlib,json,os,shutil,signal,subprocess,sys,time,urllib.request,zipfile
from pathlib import Path
root=Path(os.environ['TEST_ROOT']).resolve(); runner=Path(os.environ['RUNNER_TEMP']).resolve()
core=Path(os.environ['MOODLE_ROOT']).resolve(); cand=Path(os.environ['CANDIDATE_ROOT']).resolve()
origins=json.loads((cand/'tools/acceptance/pr90/origins.json').read_text());BASE=origins['main']
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
    # Existing isolated fresh-install + A/B fixture harness, using baseline source and PR96 validators.
    cmd([sys.executable,cand/'tools/ci/browser_acceptance.py'],env=dict(env,CANDIDATE_ROOT=str(baseline)),stdout=open(root/'baseline.private.log','w'))
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
