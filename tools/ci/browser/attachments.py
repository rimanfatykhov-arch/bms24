"""RC32 BROWSER: attachments in the compact task discussion and the full Communication Center. Phase = verify | oversize.

Records observations only (verdict in contract.py): exact rendered message text, exact attachment link text, download
HTTP status + SHA256 of the exact bytes (redirects never followed), POST evidence per endpoint, a unique direct
multipart upload, CSRF and double submit. Every APIRequestContext request is checked against the exact loopback origin
first. The browser route additionally aborts non-loopback page requests. This is request scoping, NOT OS network isolation.
"""
import hashlib
import json
import os
import sys
from pathlib import Path
from urllib.parse import urlparse, parse_qs

sys.path.insert(0, str(Path(__file__).resolve().parent))
from contract import CSRF_REFUSAL_MARKER, FILES, local_url  # noqa: E402
from playwright.sync_api import sync_playwright  # noqa: E402

if os.environ.get('BMS_CI_BROWSER') != '1':
    sys.exit('CI browser fixture only')
os.umask(0o022); root = Path(os.environ['EVIDENCE']); base = 'http://127.0.0.1:8080'
phase = sys.argv[1] if len(sys.argv) > 1 else 'verify'
f = json.loads((root / 'actors.private.json').read_text()); A = f['companies']['A']['uuid']; ma = f['actors']['MA']
task = f['tasks']['A']['uuid']
tmp = root / 'p02-files'; tmp.mkdir(exist_ok=True)
for name, data in FILES.items():
    (tmp / name).write_bytes(data)
obs = {'phase': phase, 'steps': {}}
TASKS = '/local/kbacompany/tasks.php'; CENTER = '/local/kbacompany/communication_center.php'


def note(k, **v):
    obs['steps'][k] = v


with sync_playwright() as pw:
    b = pw.chromium.launch(headless=True, args=['--disable-dev-shm-usage'])
    ctx = b.new_context(viewport={'width': 1440, 'height': 1000})
    ctx.route('**/*', lambda r: r.continue_() if urlparse(r.request.url).netloc == '127.0.0.1:8080' else r.abort())
    p = ctx.new_page(); p.set_default_timeout(30000); posts = []; errs = []

    def on_request(r):
        if r.method == 'POST':
            body = r.post_data_buffer or b''
            posts.append({'path': urlparse(r.url).path, 'multipart': (r.headers.get('content-type') or '').startswith('multipart/form-data'),
                          'has_file': body.find(b'filename="p02-') >= 0})
    p.on('request', on_request)
    # Browser errors are evidence (Codex review P1): uncaught page exceptions and console errors are recorded for every phase,
    # including oversize and failed runs, and evaluated by contract.page_error_checks(). net::ERR_FAILED console lines are
    # the external CDN requests aborted by the loopback route (same rule as task_tabs.py).
    p.on('pageerror', lambda e: errs.append('pageerror:%s:%s' % (type(e).__name__, str(e)[:120])))
    p.on('console', lambda m: errs.append('console:' + m.text[:160]) if m.type == 'error' else None)

    def goto(url):
        p.goto(local_url(url), wait_until='networkidle')

    def posted(path):
        return [x for x in posts if x['path'] == path]

    def observe(name=None, text=None):
        """Exact text + exact attachment link text + download of the exact bytes (no redirects)."""
        row = {}
        if text is not None:
            row['rendered_text_exact'] = text if p.get_by_text(text, exact=True).count() > 0 else None
        if name is not None:
            links = [a for a in p.locator('a[href*="pluginfile.php"]').all() if (a.inner_text() or '').strip() == name]
            row['file_link_text'] = name if links else None
            row['downloads'] = {}
            if links:
                href = links[-1].get_attribute('href')
                r = ctx.request.get(local_url(href), max_redirects=0)
                row['downloads'][name] = {'http': r.status, 'sha256': hashlib.sha256(r.body()).hexdigest() if r.status == 200 else None}
        return row

    def thread_url():
        goto(base + TASKS + '?uuid=%s&task=%s&view=detail&tab=overview' % (A, task))
        href = p.locator('a[href*="communication_center.php"][href*="thread="]').first.get_attribute('href')
        return href if href.startswith('http') else base + href

    goto(base + '/login/index.php'); p.locator('#username').fill(ma['username']); p.locator('#password').fill(ma['password'])
    p.locator('#loginbtn').click(); p.wait_for_load_state('networkidle')
    try:
        if phase == 'oversize':
            big = tmp / 'p02-oversize.txt'; big.write_bytes(b'x' * 100 * 1024)
            goto(base + TASKS + '?uuid=%s&task=%s&view=detail&tab=overview' % (A, task))
            form = p.locator('form:has(input[name=action][value=discussionpost])').first
            form.locator('textarea').fill('RC31 P02 oversize2 compact'); form.locator('input[type=file]').set_input_files(str(big)); posts.clear()
            with p.expect_navigation(timeout=30000): form.evaluate('f => f.requestSubmit()')
            p.wait_for_load_state('networkidle')
            sent = [x for x in posted(TASKS) if x['multipart'] and x['has_file']]
            alert = p.locator('.alert-danger, .invalid-feedback, [role=alert]')
            note('compact_oversize', request_sent=bool(sent), error_text=alert.first.inner_text()[:200] if alert.count() else '')
            goto(thread_url())
            cf = p.locator('form[data-bms-comm-compose]')
            note('limits', form_maxbytes=int(cf.get_attribute('data-maxbytes') or 0))
            cf.locator('textarea[name=body]').fill('RC31 P02 oversize2 full'); posts.clear()
            cf.locator('input[type=file]').set_input_files(str(big))
            validation = cf.locator('input[type=file]').evaluate('e => e.validationMessage')
            try:
                with p.expect_navigation(timeout=6000): cf.evaluate('f => f.requestSubmit()')
            except Exception:
                pass
            note('full_oversize_client', post_sent=bool(posted(CENTER)), file_validation=validation)
            q = parse_qs(urlparse(p.url).query); key = p.evaluate('M.cfg.sesskey')
            r = ctx.request.post(local_url(base + CENTER), max_redirects=0, multipart={
                'uuid': A, 'action': 'send', 'thread': (q.get('thread') or [''])[0], 'sesskey': key, 'tab': 'discussions',
                'body': 'RC31 P02 oversize2 server', 'attachment': {'name': 'p02-oversize.txt', 'mimeType': 'text/plain', 'buffer': big.read_bytes()}})
            note('full_oversize_server_direct', http=r.status, error_in_page='exceeds' in r.text(), sent_bytes=len(big.read_bytes()))
            raise StopIteration
        # ---------- A. compact discussion on the task page
        goto(base + TASKS + '?uuid=%s&task=%s&view=detail&tab=overview' % (A, task))
        form = p.locator('form:has(input[name=action][value=discussionpost])').first
        note('compact_controls', file_inputs=form.locator('input[type=file]').count())
        form.locator('textarea').fill('RC31 P02 compact text ' + phase)
        form.locator('input[type=file]').set_input_files(str(tmp / 'p02-compact.txt'))
        with p.expect_navigation(): form.locator('button[type=submit]').click()
        p.wait_for_load_state('networkidle')
        note('compact_text_and_file', **observe('p02-compact.txt', 'RC31 P02 compact text ' + phase))
        form = p.locator('form:has(input[name=action][value=discussionpost])').first
        form.locator('input[type=file]').set_input_files(str(tmp / 'p02-compact-only.txt'))
        with p.expect_navigation(timeout=30000): form.locator('button[type=submit]').click()
        p.wait_for_load_state('networkidle')
        note('compact_file_only', **observe('p02-compact-only.txt'))
        # ---------- B. full Communication Center
        url = thread_url(); goto(url)
        cf = p.locator('form[data-bms-comm-compose]')
        cf.locator('textarea[name=body]').fill('RC31 P02 full text only ' + phase)
        with p.expect_navigation(): cf.locator('button[type=submit]').click()
        p.wait_for_load_state('networkidle'); note('full_text_only', **observe(None, 'RC31 P02 full text only ' + phase))
        cf = p.locator('form[data-bms-comm-compose]')
        with p.expect_file_chooser() as fc: cf.locator('label[for="%s"]' % cf.locator('input[type=file]').get_attribute('id')).click()
        fc.value.set_files(str(tmp / 'p02-text-and-file.txt'))
        feedback = cf.locator('[data-bms-comm-filename]').inner_text() if cf.locator('[data-bms-comm-filename]').count() else ''
        cf.locator('textarea[name=body]').fill('RC31 P02 full text and file ' + phase)
        with p.expect_navigation(): cf.locator('button[type=submit]').click()
        p.wait_for_load_state('networkidle')
        note('full_text_and_file', filename_feedback=feedback, **observe('p02-text-and-file.txt', 'RC31 P02 full text and file ' + phase))
        cf = p.locator('form[data-bms-comm-compose]')
        cf.locator('input[type=file]').set_input_files(str(tmp / 'p02-file-only.txt'))
        with p.expect_navigation(timeout=30000): cf.locator('button[type=submit]').click()
        p.wait_for_load_state('networkidle')
        note('full_file_only', **observe('p02-file-only.txt'))
        # B4 direct multipart POST with a UNIQUE new file and empty body (bypassing client JS)
        q = parse_qs(urlparse(p.url).query); thread = (q.get('thread') or [''])[0]; key = p.evaluate('M.cfg.sesskey')
        r = ctx.request.post(local_url(base + CENTER), max_redirects=0, multipart={
            'uuid': A, 'action': 'send', 'thread': thread, 'sesskey': key, 'tab': 'discussions', 'body': '',
            'attachment': {'name': 'p02-direct-only.txt', 'mimeType': 'text/plain', 'buffer': FILES['p02-direct-only.txt']}})
        goto(url)
        note('server_file_only_direct', http=r.status, location=r.headers.get('location', ''),
             expected_query={'uuid': A, 'tab': 'discussions', 'thread': thread}, **observe('p02-direct-only.txt'))
        # CSRF: forged POST with an invalid sesskey (must not create a message; checked in the DB afterwards)
        r = ctx.request.post(local_url(base + CENTER), max_redirects=0, multipart={
            'uuid': A, 'action': 'send', 'thread': thread, 'sesskey': 'invalidsesskey', 'tab': 'discussions', 'body': 'RC31 P02 csrf ' + phase})
        note('csrf_invalid_sesskey', http=r.status, refusal_marker=CSRF_REFUSAL_MARKER in r.text(), location=r.headers.get('location', ''))
        # double submit: two fast clicks must create one message (DB count checked afterwards)
        goto(url)
        cf = p.locator('form[data-bms-comm-compose]'); cf.locator('textarea[name=body]').fill('RC31 P02 double ' + phase)
        with p.expect_navigation(): cf.locator('button[type=submit]').dblclick()
        p.wait_for_load_state('networkidle')
        note('double_submit', center_posts=len(posted(CENTER)))
        urls = sorted({a.get_attribute('href') for a in p.locator('a[href*="communication_attachment"]').all()})
        (root / 'p02-urls.json').write_text(json.dumps(urls)); (root / 'p02-urls.json').chmod(0o644)
        obs['attachment_urls'] = len(urls)
    except StopIteration:
        pass
    except Exception as e:
        obs['execution_error'] = '%s: %s' % (type(e).__name__, str(e)[:300])
    finally:
        obs['page_errors'] = list(errs)
    b.close()
dest = root / ('p02-browser-' + phase + '.json')
dest.write_text(json.dumps(obs, indent=1)); dest.chmod(0o644)
print(json.dumps(obs['steps'], default=str)[:3000])
