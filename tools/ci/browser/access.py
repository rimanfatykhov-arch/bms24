"""RC32 BROWSER: protected attachment download ACL in ONE run with persistent sessions (Issue #82 review P1/P2/P4).

EA (participant, company A) logs in once; the same browser context is used before revoke, after revoke and after reopen.
EB (company B) and an anonymous context are checked against the same URL set. Revoke/reopen are applied with PHP CLI
on the disposable site between phases. Every request is checked against the exact loopback origin first and never
follows redirects. Output: EVIDENCE/p02-access.json (observations only; verdict in contract.access_checks).
"""
import hashlib
import json
import os
import subprocess
import sys
from pathlib import Path
from urllib.parse import urlparse

sys.path.insert(0, str(Path(__file__).resolve().parent))
from contract import local_url  # noqa: E402
from playwright.sync_api import sync_playwright  # noqa: E402

if os.environ.get('BMS_CI_BROWSER') != '1':
    sys.exit('CI browser fixture only')
root = Path(os.environ['EVIDENCE']); base = 'http://127.0.0.1:8080'; core = os.environ['MOODLE_ROOT']
f = json.loads((root / 'actors.private.json').read_text())
urls = json.loads((root / 'p02-urls.json').read_text())
obs = {'expected_urls': urls, 'phases': {}, 'logins': {}, 'history': {}}


def route(r):
    return r.continue_() if urlparse(r.request.url).netloc == '127.0.0.1:8080' else r.abort()


def state(phase):
    here = Path(__file__).resolve().parent
    subprocess.run(['php', str(here / 'ci_state.php'), core, phase], check=True, stdout=subprocess.DEVNULL)
    if phase in ('revoke_ea', 'reopen_ea'):
        return json.loads((root / ('p02-switch-%s.json' % phase)).read_text())
    return json.loads((root / ('p02-state-%s.json' % phase)).read_text())


def history():
    """Content snapshot (per-row hashes + digest) of company A's communication history; compared by the contract."""
    return state('hist').get('history')


def fetch(ctx, phase):
    rows = {}
    for u in urls:
        r = ctx.request.get(local_url(u), max_redirects=0)
        body = r.body()
        rows[u] = {'http': r.status, 'location': r.headers.get('location', ''),
                   'sha256': hashlib.sha256(body).hexdigest() if r.status == 200 else None}
    obs['phases'][phase] = rows


def login(ctx, label):
    a = f['actors'][label]; p = ctx.new_page(); p.set_default_timeout(30000)
    p.goto(local_url(base + '/login/index.php')); p.locator('#username').fill(a['username']); p.locator('#password').fill(a['password'])
    p.locator('#loginbtn').click(); p.wait_for_load_state('networkidle')
    obs['logins'][label] = obs['logins'].get(label, 0) + 1
    return p


try:
    with sync_playwright() as pw:
        b = pw.chromium.launch(headless=True, args=['--disable-dev-shm-usage'])
        ea = b.new_context(); ea.route('**/*', route); login(ea, 'EA')
        obs['ea_context_id_before'] = id(ea)
        obs['history']['before'] = history()
        fetch(ea, 'ea_before')
        eb = b.new_context(); eb.route('**/*', route); login(eb, 'EB'); fetch(eb, 'eb')
        anon = b.new_context(); anon.route('**/*', route); fetch(anon, 'anon')
        require_switch = state('revoke_ea'); obs['revoked_flag'] = require_switch.get('ea_revoked'); fetch(ea, 'ea_revoked'); obs['history']['after_revoke'] = history()
        reopen = state('reopen_ea'); obs['reopened_flag'] = not reopen.get('ea_revoked'); fetch(ea, 'ea_reopened'); obs['history']['after_reopen'] = history()
        obs['ea_context_id_after'] = id(ea)
        b.close()
except Exception as e:
    obs['execution_error'] = '%s: %s' % (type(e).__name__, str(e)[:300])
dest = root / 'p02-access.json'; dest.write_text(json.dumps(obs, indent=1)); dest.chmod(0o644)
print(json.dumps({'phases': {k: len(v) for k, v in obs['phases'].items()},
                  'history': {k: (v or {}).get('digest') for k, v in obs['history'].items()}}))
