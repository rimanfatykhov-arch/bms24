"""RC31 Pilot 02: attachments in task compact discussion and full Communication Center. Phase = repro | verify.
Isolated clone, synthetic actors and synthetic files only. Records observations: UI controls, HTTP requests, rendered
attachments, downloads (sha256), file-only behaviour, foreign company / revoked / outsider download denial is checked by PHP."""
import json, os, sys, hashlib, re
from pathlib import Path
from urllib.parse import urlparse, parse_qs
from playwright.sync_api import sync_playwright
assert os.environ.get('BMS_CI_BROWSER') == '1'
os.umask(0o022); root = Path(os.environ['EVIDENCE']); base = 'http://127.0.0.1:8080'
phase = sys.argv[1] if len(sys.argv) > 1 else 'repro'
f = json.loads((root / 'actors.private.json').read_text()); A = f['companies']['A']['uuid']; ma = f['actors']['MA']
task = f['tasks']['A']['uuid']
tmp = Path(os.environ['EVIDENCE']) / 'p02-files'; tmp.mkdir(exist_ok=True)
FILES = {}
for name, data in [('p02-text-and-file.txt', b'RC31 pilot02 synthetic attachment A\n'), ('p02-file-only.txt', b'RC31 pilot02 synthetic file-only B\n'),
                   ('p02-compact.txt', b'RC31 pilot02 synthetic compact C\n'), ('p02-compact-only.txt', b'RC31 pilot02 synthetic compact-only D\n')]:
    (tmp / name).write_bytes(data); FILES[name] = hashlib.sha256(data).hexdigest()
obs = {'phase': phase, 'steps': {}}
def note(k, **v): obs['steps'][k] = v
with sync_playwright() as pw:
    b = pw.chromium.launch(headless=True, args=['--disable-dev-shm-usage'])
    ctx = b.new_context(viewport={'width': 1440, 'height': 1000}); ctx.route('**/*', lambda r: r.continue_() if urlparse(r.request.url).netloc == '127.0.0.1:8080' else r.abort())
    p = ctx.new_page(); p.set_default_timeout(30000); posts = []; errs = []
    p.on('request', lambda r: posts.append({'url': urlparse(r.url).path, 'ctype': (r.headers.get('content-type') or '').split(';')[0],
                                           'has_file': (r.post_data_buffer or b'').find(b'filename="p02-') >= 0 if r.method == 'POST' else None}) if r.method == 'POST' else None)
    p.on('pageerror', lambda e: errs.append('pageerror:' + type(e).__name__))
    p.goto(base + '/login/index.php'); p.locator('[name=username]').fill(ma['username']); p.locator('[name=password]').fill(ma['password'])
    p.locator('#loginbtn').click(); p.wait_for_load_state('networkidle')
    def download_all(scope, names):
        out = {}
        for a in p.locator(scope + ' a[href*="pluginfile.php"]').all():
            href = a.get_attribute('href'); fn = href.split('/')[-1].split('?')[0]
            if fn in names:
                r = p.request.get(href); out[fn] = {'http': r.status, 'sha_ok': hashlib.sha256(r.body()).hexdigest() == FILES[fn]}
        return out
    try:
        if phase == 'oversize':
            big = tmp / 'p02-oversize.txt'; big.write_bytes(b'x' * 100 * 1024)
            p.goto(base + '/local/kbacompany/tasks.php?uuid=%s&task=%s&view=detail&tab=overview' % (A, task), wait_until='networkidle')
            form = p.locator('form:has(input[name=action][value=discussionpost])').first
            form.locator('textarea').fill('RC31 P02 oversize2 compact'); form.locator('input[type=file]').set_input_files(str(big)); posts.clear()
            try:
                with p.expect_navigation(timeout=20000): form.evaluate('f => f.requestSubmit()')
                p.wait_for_load_state('networkidle')
            except Exception as e:
                p.screenshot(path=str(root / 'p02-oversize-compact.png')); obs['compact_submit_error'] = type(e).__name__
            note('compact_oversize', request_sent=bool(posts), error_shown=p.locator('.alert-danger, .invalid-feedback, [role=alert], .bms-tui-error').count(),
                 error_text=(p.locator('.alert-danger, .invalid-feedback, [role=alert]').first.inner_text()[:160] if p.locator('.alert-danger, .invalid-feedback, [role=alert]').count() else ''),
                 stored_text='RC31 P02 oversize2 compact' in str(p.locator('.bms-tui-message').all_inner_texts()))
            p.goto(base + '/local/kbacompany/tasks.php?uuid=%s&task=%s&view=detail&tab=overview' % (A, task), wait_until='networkidle')
            href = p.locator('a[href*="communication_center.php"][href*="thread="]').first.get_attribute('href')
            p.goto(href if href.startswith('http') else base + href, wait_until='networkidle')
            cf = p.locator('form[data-bms-comm-compose]'); cf.locator('textarea[name=body]').fill('RC31 P02 oversize2 full')
            cf.locator('input[type=file]').set_input_files(str(big)); posts.clear()
            fname = cf.locator('[data-bms-comm-filename]').inner_text()
            try:
                with p.expect_navigation(timeout=6000): cf.evaluate('f => f.requestSubmit()')
            except Exception: pass
            note('full_oversize_client', blocked_client=not posts, file_validation=cf.locator('input[type=file]').evaluate('e => e.validationMessage'), filename_feedback=fname)
            q = parse_qs(urlparse(p.url).query); key = p.evaluate('M.cfg.sesskey')
            r = p.request.post(base + '/local/kbacompany/communication_center.php', multipart={'uuid': A, 'action': 'send', 'thread': (q.get('thread') or [''])[0],
                'sesskey': key, 'tab': 'discussions', 'body': 'RC31 P02 oversize2 server', 'attachment': {'name': 'p02-oversize.txt', 'mimeType': 'text/plain', 'buffer': big.read_bytes()}})
            note('full_oversize_server_direct', http=r.status, error_in_page='превышает' in r.text() or 'exceeds' in r.text(), sent_bytes=len(big.read_bytes()))
            raise StopIteration
        # ---------- A. compact discussion on the task page
        p.goto(base + '/local/kbacompany/tasks.php?uuid=%s&task=%s&view=detail&tab=overview' % (A, task), wait_until='networkidle')
        form = p.locator('form:has(input[name=action][value=discussionpost])').first
        cfile = form.locator('input[type=file]').count()
        note('compact_controls', forms=p.locator('form:has(input[name=action][value=discussionpost])').count(), file_inputs=cfile,
             file_label_visible=form.locator('label:has-text("")').count() > 0)
        posts.clear()
        form.locator('textarea').fill('RC31 P02 compact text ' + phase)
        if cfile: form.locator('input[type=file]').set_input_files(str(tmp / 'p02-compact.txt'))
        with p.expect_navigation(): form.locator('button[type=submit]').click()
        p.wait_for_load_state('networkidle')
        note('compact_text_and_file', request=posts[-1] if posts else None, file_input_present=bool(cfile),
             rendered_text='RC31 P02 compact text ' + phase in p.content(),
             rendered_file='p02-compact.txt' in p.content(), downloads=download_all('body', ['p02-compact.txt']))
        if cfile:
            posts.clear(); form = p.locator('form:has(input[name=action][value=discussionpost])').first
            form.locator('input[type=file]').set_input_files(str(tmp / 'p02-compact-only.txt'))
            try:
                with p.expect_navigation(timeout=8000): form.locator('button[type=submit]').click()
                p.wait_for_load_state('networkidle')
            except Exception: pass
            note('compact_file_only', request=posts[-1] if posts else None, blocked_client=not posts,
                 rendered_file='p02-compact-only.txt' in p.content(), downloads=download_all('body', ['p02-compact-only.txt']),
                 error_shown=p.locator('.alert-danger, .bms-tui-error, [role=alert]').count())
        # thread link to the full Communication Center
        link = p.locator('a[href*="communication_center.php"][href*="thread="], a[href*="communication_center.php"][href*="objectuuid="]').first
        href = link.get_attribute('href') if link.count() else None
        note('full_link', present=bool(href))
        # ---------- B. full Communication Center
        p.goto(href if href.startswith('http') else base + href, wait_until='networkidle')
        cf = p.locator('form[data-bms-comm-compose]')
        note('full_page', compose_forms=cf.count(), url_has_thread='thread=' in p.url, objectform=p.locator('input[name=action][value=createobject]').count())
        if not cf.count() and p.locator('input[name=action][value=createobject]').count():
            with p.expect_navigation(): p.locator('form:has(input[name=action][value=createobject]) button[type=submit]').first.click()
            p.wait_for_load_state('networkidle'); cf = p.locator('form[data-bms-comm-compose]')
        fi = cf.locator('input[type=file]')
        st = fi.evaluate("e => { const s = getComputedStyle(e); return {opacity: s.opacity, w: e.getBoundingClientRect().width, pe: s.pointerEvents}; }") if fi.count() else None
        lab = cf.locator('label[for="%s"]' % (fi.get_attribute('id') if fi.count() else 'x'))
        note('full_controls', file_inputs=fi.count(), input_style=st, label_present=lab.count() > 0,
             body_required=cf.locator('textarea[name=body]').get_attribute('required') is not None,
             filename_feedback_selector=cf.locator('[data-bms-comm-filename]').count())
        # B1 text only
        posts.clear(); cf.locator('textarea[name=body]').fill('RC31 P02 full text only ' + phase)
        with p.expect_navigation(): cf.locator('button[type=submit]').click()
        p.wait_for_load_state('networkidle'); note('full_text_only', request=posts[-1] if posts else None, rendered='RC31 P02 full text only ' + phase in p.content())
        # B2 text + file (file chosen through the visible label, like a user)
        cf = p.locator('form[data-bms-comm-compose]'); posts.clear()
        with p.expect_file_chooser() as fc: cf.locator('label[for="%s"]' % cf.locator('input[type=file]').get_attribute('id')).click()
        fc.value.set_files(str(tmp / 'p02-text-and-file.txt'))
        feedback = cf.locator('[data-bms-comm-filename]').inner_text() if cf.locator('[data-bms-comm-filename]').count() else ''
        cf.locator('textarea[name=body]').fill('RC31 P02 full text and file ' + phase)
        with p.expect_navigation(): cf.locator('button[type=submit]').click()
        p.wait_for_load_state('networkidle')
        note('full_text_and_file', request=posts[-1] if posts else None, filename_feedback=feedback,
             rendered_text='RC31 P02 full text and file ' + phase in p.content(), rendered_file='p02-text-and-file.txt' in p.content(),
             downloads=download_all('body', ['p02-text-and-file.txt']))
        # B3 file only
        cf = p.locator('form[data-bms-comm-compose]'); posts.clear()
        cf.locator('input[type=file]').set_input_files(str(tmp / 'p02-file-only.txt'))
        try:
            with p.expect_navigation(timeout=8000): cf.locator('button[type=submit]').click()
            p.wait_for_load_state('networkidle')
        except Exception: pass
        note('full_file_only', request=posts[-1] if posts else None, blocked_client=not posts,
             validation_message=cf.locator('textarea[name=body]').evaluate('e => e.validationMessage') if p.locator('form[data-bms-comm-compose] textarea[name=body]').count() else None,
             rendered_file='p02-file-only.txt' in p.content(), downloads=download_all('body', ['p02-file-only.txt']),
             error_shown=p.locator('.alert-danger, [role=alert]').count())
        # B4 direct multipart POST with empty body (bypassing client JS) -> what the server does
        q = parse_qs(urlparse(p.url).query); thread = (q.get('thread') or [''])[0]
        key = p.evaluate('M.cfg.sesskey')
        r = p.request.post(base + '/local/kbacompany/communication_center.php', multipart={
            'uuid': A, 'action': 'send', 'thread': thread, 'sesskey': key, 'tab': 'discussions', 'body': '',
            'attachment': {'name': 'p02-file-only.txt', 'mimeType': 'text/plain', 'buffer': (tmp / 'p02-file-only.txt').read_bytes()}}, max_redirects=0)
        note('server_file_only_direct', http=r.status, location=r.headers.get('location', '')[:200].replace(A, 'A'))
        obs['thread'] = thread
        # CSRF: a forged POST with an invalid sesskey must not create a message
        r = p.request.post(base + '/local/kbacompany/communication_center.php', multipart={
            'uuid': A, 'action': 'send', 'thread': thread, 'sesskey': 'invalidsesskey', 'tab': 'discussions', 'body': 'RC31 P02 csrf ' + phase},
            max_redirects=0)
        note('csrf_invalid_sesskey', http=r.status)
        # double submit: two fast clicks must create one message
        p.goto(base + '/local/kbacompany/communication_center.php?' + urlparse(p.url).query if 'communication_center.php' not in p.url else p.url, wait_until='networkidle')
        cf = p.locator('form[data-bms-comm-compose]'); cf.locator('textarea[name=body]').fill('RC31 P02 double ' + phase)
        btn = cf.locator('button[type=submit]')
        with p.expect_navigation(): btn.dblclick()
        p.wait_for_load_state('networkidle')
        note('double_submit', occurrences=p.content().count('RC31 P02 double ' + phase))
        urls = sorted({a.get_attribute('href') for a in p.locator('a[href*="communication_attachment"]').all()})
        (root / 'p02-urls.json').write_text(json.dumps(urls)); (root / 'p02-urls.json').chmod(0o644)
        obs['attachment_urls'] = len(urls)
        obs['page_errors'] = errs
    except StopIteration:
        pass
    except Exception as e:
        obs['execution_error'] = '%s: %s' % (type(e).__name__, str(e)[:300])
    b.close()
dest = root / ('p02-browser-' + phase + '.json')
dest.write_text(json.dumps(obs, indent=1)); dest.chmod(0o644)
print(json.dumps({k: v for k, v in obs['steps'].items()}, default=str)[:3000])
