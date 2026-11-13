"""RC31 Pilot 02: download access to P02 attachments. Phase = ea_before | ea_revoked | ea_reopened | eb | anon.
EA = employee of company A (task assignee, thread participant); EB = employee of company B. Synthetic clone only."""
import json, os, sys
from pathlib import Path
from urllib.parse import urlparse
from playwright.sync_api import sync_playwright
assert os.environ.get('BMS_CI_BROWSER') == '1'
root = Path(os.environ['EVIDENCE']); base = 'http://127.0.0.1:8080'; phase = sys.argv[1]
f = json.loads((root / 'actors.private.json').read_text()); urls = json.loads((root / 'p02-urls.json').read_text())
who = {'eb': 'EB', 'anon': None}.get(phase, 'EA')
res = {'phase': phase, 'actor': who, 'urls': len(urls), 'status': {}}
with sync_playwright() as pw:
    b = pw.chromium.launch(headless=True, args=['--disable-dev-shm-usage'])
    ctx = b.new_context(); ctx.route('**/*', lambda r: r.continue_() if urlparse(r.request.url).netloc == '127.0.0.1:8080' else r.abort())
    p = ctx.new_page(); p.set_default_timeout(30000)
    if who:
        a = f['actors'][who]
        p.goto(base + '/login/index.php'); p.locator('#username').fill(a['username']); p.locator('#password').fill(a['password'])
        p.locator('#loginbtn').click(); p.wait_for_load_state('networkidle')
    for u in urls:
        r = ctx.request.get(u, max_redirects=0)
        body = r.body()
        res['status'][u.split('/')[-1].split('?')[0]] = {'http': r.status, 'served_file': r.status == 200 and body.startswith(b'RC31 pilot02 synthetic')}
    b.close()
served = [k for k, v in res['status'].items() if v['served_file']]
res['served'] = len(served)
dest = root / ('p02-access-' + phase + '.json'); dest.write_text(json.dumps(res, indent=1)); dest.chmod(0o644)
print(json.dumps({'phase': phase, 'urls': len(urls), 'served': len(served)}))
