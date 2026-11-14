"""Build once per CI run. Both DB jobs install this exact artifact, never rebuild it."""
import hashlib,json,os,re,subprocess,sys
from pathlib import Path
repo=Path(__file__).resolve().parents[3];out=Path(sys.argv[1]).resolve()
if os.environ.get('GITHUB_ACTIONS')!='true':raise SystemExit('CI packaging only')
def git(*args):return subprocess.check_output(['git','-C',str(repo),*args],text=True).strip()
def version(component):
    s=(repo/component/'version.php').read_text()
    return {'version':int(re.search(r'\$plugin->version\s*=\s*(\d+)',s)[1]),'release':re.search(r"\$plugin->release\s*=\s*'([^']+)'",s)[1],
            'version_php_sha256':hashlib.sha256(s.encode()).hexdigest()}
out.mkdir(parents=True,exist_ok=True)
subprocess.run([sys.executable,str(repo/'tools/build_install.py'),'--source',str(repo/'local/kbacompany'),'--output',str(out/'candidate-install.zip'),'--manifest',str(out/'manifest.json')],check=True)
m=json.loads((out/'manifest.json').read_text())
m['source']={'checkout_sha':git('rev-parse','HEAD'),'tree':git('rev-parse','HEAD^{tree}'),'pr_head':os.environ['PR_HEAD_SHA'],'base':os.environ['PR_BASE_SHA'],
             'run_id':os.environ['GITHUB_RUN_ID'],'run_attempt':os.environ['GITHUB_RUN_ATTEMPT']}
m['components']={p:version(p) for p in ['local/kbacompany','local/kbacorporate']}
m['runtime_target']={'moodle_git':'b27f0816159a7555a0b3ad9ff2f8f8ef701a29fa','moodle':'4.1.22','php':'8.1','mariadb':'10.11.19','percona':'8.0.46-37','playwright':'1.49.1'}
m['origins']=json.loads((repo/'tools/acceptance/pr90/origins.json').read_text())
m['scope']='Install local_kbacompany only; Corporate is a tested prerequisite, not included in this ZIP. Production baseline unverified.'
(out/'manifest.json').write_text(json.dumps(m,indent=2)+'\n')
(out/'SHA256SUMS').write_text(''.join(hashlib.sha256((out/n).read_bytes()).hexdigest()+'  '+n+'\n' for n in ['candidate-install.zip','manifest.json']))
