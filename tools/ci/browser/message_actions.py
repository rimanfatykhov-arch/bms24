"""Real Moodle acceptance of editing/withdrawing own task-discussion messages; synthetic data only, loopback-only requests.

Four cases (owned/legacy shell x 1440/320 viewport x click/keyboard, discussion/overview tab) each post a message with a
file, cancel an edit, save an edit (reload, 'Edited', Communication Center shows the same text, file still downloads),
lose a conflicting edit from a second tab without losing its draft, refuse an unconfirmed delete, then delete (text, file
link and the old file URL are gone). Server-side negatives use raw HTTP with real sessions: wrong sesskey, GET, another
author of the same company, another company, replayed request, unconfirmed withdraw and a revoked member.
Observations only -> EVIDENCE/message-actions-result.json; the verdict is computed by contract.message_action_checks.
"""
import hashlib
import json
import os
import re
import subprocess
import sys
import uuid
from pathlib import Path
from urllib.parse import urlencode

sys.path.insert(0, str(Path(__file__).resolve().parent))
from contract import local_url, require  # noqa: E402
from events import Recorder  # noqa: E402
from playwright.sync_api import sync_playwright  # noqa: E402

require(os.environ.get('BMS_CI_BROWSER') == '1', 'CI browser fixture only')
here = Path(__file__).resolve().parent
root = Path(os.environ['EVIDENCE']); core = os.environ['MOODLE_ROOT']; base = 'http://127.0.0.1:8080'
f = json.loads((root / 'actors.private.json').read_text())
company, task = f['companies']['A']['uuid'], f['tasks']['A']['uuid']
TASKS = base + '/local/kbacompany/tasks.php'
CASES = [('owned', 1440, 'click', 'discussion'), ('owned', 320, 'keyboard', 'overview'),
         ('legacy', 1440, 'keyboard', 'discussion'), ('legacy', 320, 'click', 'overview')]
result = {'status': 'FAIL', 'cases': [], 'negatives': {}}


def php(*args):
    out = subprocess.run(['php', str(here / 'ci_message_actions.php'), core, *args], check=True,
                         capture_output=True, text=True).stdout
    return json.loads(out.strip().splitlines()[-1])


def state(message):
    s = php('state', message)
    return {'body': s['body'], 'status': s['status'], 'thread_uuid': s['thread_uuid'],
            'revisions': [r['action'] for r in s['revisions']], 'attachments': s['attachments'], 'stored_files': s['stored_files']}


def acl_switch(phase):
    subprocess.run(['php', str(here / 'ci_state.php'), core, phase], check=True, stdout=subprocess.DEVNULL)
    return json.loads((root / ('p02-switch-%s.json' % phase)).read_text())


def task_url(tab):
    return local_url(TASKS + '?' + urlencode({'uuid': company, 'task': task, 'view': 'detail', 'tab': tab}))


def login(ctx, label):
    a = f['actors'][label]; p = ctx.new_page(); p.set_default_timeout(30000)
    p.goto(local_url(base + '/login/index.php'), wait_until='networkidle')
    p.locator('#username').fill(a['username']); p.locator('#password').fill(a['password'])
    p.locator('#loginbtn').click(); p.wait_for_load_state('networkidle')
    return p


def activate(page, locator, mode):
    """Operate a control the way a user would: mouse click, or keyboard focus + Enter."""
    if mode == 'click':
        locator.click()
    else:
        locator.focus(); page.keyboard.press('Enter')


def post_message(page, tab, text, file):
    page.goto(task_url(tab), wait_until='networkidle')
    form = page.locator('form[data-bms-discussion-composer]').first
    require(form.count() == 1, 'composer missing')
    form.locator('textarea').fill(text)
    if file:
        form.locator('input[type=file]').set_input_files([file])
    # Do not race the next action/reload against Moodle's asynchronous page scripts.
    # A genuine aborted request remains a failure; wait rather than filtering it out.
    with page.expect_navigation(wait_until='networkidle'):
        form.locator('button[type=submit]').click()
    page.wait_for_load_state('networkidle')
    article = page.locator('article.bms-tui-message', has_text=text)
    require(article.count() == 1, 'posted message missing or duplicated')
    return article.get_attribute('id').replace('task-message-', '')


def form_fields(page, message, kind):
    """Hidden + visible fields of the rendered edit/withdraw form of one message (real sesskey/requestkey/hash)."""
    form = page.locator('#task-message-%s details.bms-tui-message-%s form' % (message, kind))
    require(form.count() == 1, '%s form missing' % kind)
    return form.evaluate('f => Object.fromEntries(Array.from(new FormData(f)).filter(([k,v]) => typeof v === "string"))')


def raw_post(ctx, fields):
    r = ctx.request.post(local_url(TASKS), form=fields, max_redirects=0)
    return {'http': r.status, 'location': r.headers.get('location', '')}


def message_text(page, message):
    return page.locator('#task-message-%s' % message).inner_text()


with sync_playwright() as pw:
    browser = pw.chromium.launch(headless=True, args=['--disable-dev-shm-usage'])
    try:
        for shell, width, mode, tab in CASES:
            php('shell', shell)
            ctx = browser.new_context(viewport={'width': width, 'height': 900})
            recorder = Recorder().install(ctx)
            server_errors = []
            page = login(ctx, 'MA')
            page.on('response', lambda r: server_errors.append({'url': r.url, 'status': r.status}) if r.status >= 500 else None)
            token = uuid.uuid4().hex[:12]
            content = ('message-actions attachment %s\n' % token).encode()
            file = {'name': 'ma-%s.txt' % token, 'mimeType': 'text/plain', 'buffer': content}
            t0, t1, t2, t3 = ('MA %s original' % token, 'MA %s edited once' % token,
                              'MA %s edited in tab one' % token, 'MA %s stale draft from tab two' % token)
            case = {'shell': shell, 'width': width, 'mode': mode, 'tab': tab, 'texts': [t0, t1, t2, t3],
                    'file_sha256': hashlib.sha256(content).hexdigest()}
            message = post_message(page, tab, t0, file)
            case['posted'] = state(message)
            article = page.locator('#task-message-%s' % message)
            file_url = article.get_by_role('link', name=file['name'], exact=True).get_attribute('href')
            # 1. cancel: the draft is discarded, nothing is written
            edit = article.locator('details.bms-tui-message-edit')
            activate(page, edit.locator('summary'), mode)
            edit.locator('textarea').fill('MA %s cancelled draft' % token)
            with page.expect_navigation(wait_until='networkidle'):
                activate(page, edit.get_by_role('link', name='Cancel', exact=True), mode)
            page.wait_for_load_state('networkidle')
            case['after_cancel'] = state(message)
            # 2. save an edit, reload, compare with the Communication Center projection and the kept attachment
            article = page.locator('#task-message-%s' % message); edit = article.locator('details.bms-tui-message-edit')
            activate(page, edit.locator('summary'), mode)
            edit.locator('textarea').fill(t1)
            with page.expect_navigation(wait_until='networkidle'):
                activate(page, edit.get_by_role('button', name='Save changes', exact=True), mode)
            page.reload(wait_until='networkidle')
            article = page.locator('#task-message-%s' % message)
            download = ctx.request.get(local_url(article.get_by_role('link', name=file['name'], exact=True).get_attribute('href')), max_redirects=0)
            case['after_edit'] = dict(state(message), task_text=article.inner_text(), edited_marker='Edited' in article.inner_text(),
                                      download_http=download.status,
                                      download_ok=download.status == 200 and hashlib.sha256(download.body()).hexdigest() == hashlib.sha256(content).hexdigest())
            cc = ctx.new_page()
            cc.goto(local_url(base + '/local/kbacompany/communication_center.php?' + urlencode(
                {'uuid': company, 'thread': case['after_edit']['thread_uuid']})), wait_until='networkidle')
            # Match the canonical message body, not a sidebar thread preview or the hidden edit textarea.
            # Real production histories can legitimately show the same text in a preview and the selected feed.
            canonical = cc.locator('#message-%s .bms-comm2-message-body' % message)
            case['communication_center'] = {'edited_text_count': canonical.get_by_text(t1, exact=True).evaluate_all('els => els.filter(e => e.checkVisibility()).length'),
                                            'original_text_count': cc.locator('#bms-comm2-history .bms-comm2-message-body').get_by_text(t0, exact=True).count()}
            cc.close()
            # 3. conflict between two tabs: the second save must fail and keep its draft; tab one's text stays
            page.reload(wait_until='networkidle')
            second = ctx.new_page(); second.goto(page.url, wait_until='networkidle')
            for p, text in ((page, t2), (second, t3)):
                e = p.locator('#task-message-%s details.bms-tui-message-edit' % message)
                activate(p, e.locator('summary'), mode); e.locator('textarea').fill(text)
            with page.expect_navigation(wait_until='networkidle'):
                activate(page, page.locator('#task-message-%s details.bms-tui-message-edit' % message)
                         .get_by_role('button', name='Save changes', exact=True), mode)
            e2 = second.locator('#task-message-%s details.bms-tui-message-edit' % message)
            with second.expect_navigation(wait_until='networkidle'):
                activate(second, e2.get_by_role('button', name='Save changes', exact=True), mode)
            second.wait_for_load_state('networkidle')
            e2 = second.locator('#task-message-%s details.bms-tui-message-edit' % message)
            case['conflict'] = dict(state(message),
                                    alert=second.locator('[role=alert]').all_inner_texts(),
                                    draft_kept=e2.count() == 1 and e2.get_attribute('open') is not None and e2.locator('textarea').input_value() == t3)
            second.close()
            # 4. withdraw: an unconfirmed submit sends nothing; a confirmed one hides text and file and closes the old URL
            page.goto(task_url(tab), wait_until='networkidle')
            posts = []
            page.on('request', lambda r: posts.append(r.url) if r.method == 'POST' else None)
            w = page.locator('#task-message-%s details.bms-tui-message-withdraw' % message)
            activate(page, w.locator('summary'), mode)
            w.get_by_role('button', name='Delete', exact=True).click()
            page.wait_for_timeout(500)
            case['unconfirmed_delete'] = dict(state(message), post_requests=len(posts))
            checkbox = w.locator('input[name=confirmwithdraw]')
            if mode == 'click':
                checkbox.check()
            else:
                checkbox.focus(); page.keyboard.press('Space')
            with page.expect_navigation(wait_until='networkidle'):
                activate(page, w.get_by_role('button', name='Delete', exact=True), mode)
            page.reload(wait_until='networkidle')
            old = ctx.request.get(local_url(file_url), max_redirects=0)
            text = message_text(page, message)
            case['after_withdraw'] = dict(state(message), task_text=text, withdrawn_marker='Message withdrawn.' in text,
                                          old_body_visible=t2 in text, file_link_count=page.get_by_role('link', name=file['name']).count(),
                                          old_file_http=old.status, old_file_leaked=old.status == 200 and old.body() == content,
                                          controls_left=page.locator('#task-message-%s details' % message).count())
            case['browser_events'] = recorder.events
            case['server_errors'] = server_errors
            result['cases'].append(case)
            ctx.close()
        php('shell', 'owned')

        # ---- server-side negatives with real sessions (raw HTTP, no redirects followed)
        ma = browser.new_context(); page = login(ma, 'MA')
        token = uuid.uuid4().hex[:12]
        neg_text = 'MA %s negative target' % token
        target = post_message(page, 'discussion', neg_text, None)
        page.goto(task_url('discussion'), wait_until='networkidle')
        edit = dict(form_fields(page, target, 'edit'), body='MA %s forbidden change' % token)
        withdraw = form_fields(page, target, 'withdraw')
        neg = result['negatives']
        before = state(target)
        neg['wrong_sesskey'] = dict(raw_post(ma, dict(edit, sesskey='0' * 10)), state=state(target))
        get = ma.request.get(local_url(TASKS + '?' + urlencode(edit)), max_redirects=0)
        neg['get_instead_of_post'] = {'http': get.status, 'state': state(target)}
        neg['withdraw_without_confirmation'] = dict(raw_post(ma, {k: v for k, v in withdraw.items() if k != 'confirmwithdraw'}), state=state(target))
        for label in ('EA', 'EB'):
            other = browser.new_context(); op = login(other, label)
            op.goto(local_url(base + '/my/'), wait_until='networkidle')
            sesskey = op.evaluate('() => M.cfg.sesskey')
            forged = dict(edit, sesskey=sesskey, requestkey=uuid.uuid4().hex)
            neg['other_author_same_company' if label == 'EA' else 'other_company'] = dict(raw_post(other, forged), state=state(target))
            other.close()
        neg['unchanged_before_valid'] = before
        neg['target_text'] = neg_text
        valid = dict(edit, body='MA %s replayed change' % token)
        neg['replay_text'] = valid['body']
        first, again = raw_post(ma, valid), raw_post(ma, valid)
        neg['replay'] = {'first': first, 'again': again, 'state': state(target)}
        # revoked member: EA's own message cannot be changed after revocation (same session)
        ea = browser.new_context(); ep = login(ea, 'EA')
        neg['ea_text'] = 'EA %s own message' % token
        own = post_message(ep, 'discussion', neg['ea_text'], None)
        ep.goto(task_url('discussion'), wait_until='networkidle')
        ea_edit = dict(form_fields(ep, own, 'edit'), body='EA %s after revoke' % token)
        switch = acl_switch('revoke_ea')
        try:
            neg['revoked_member'] = dict(raw_post(ea, ea_edit), revoked=switch.get('ea_revoked'), state=state(own))
        finally:
            neg['reopened'] = not acl_switch('reopen_ea').get('ea_revoked')
        ea.close(); ma.close()
        result['status'] = 'PASS'
    except Exception as error:
        result['error'] = '%s: %s' % (type(error).__name__, str(error)[:400])
        try:
            if 'page' in locals() and not page.is_closed():
                page.screenshot(path=str(root / 'message-actions-failure.png'), full_page=True)
        except Exception as diagnostic:
            result['diagnostic_error'] = str(diagnostic)
    finally:
        try:
            php('shell', 'owned')
        except Exception:
            pass
        browser.close()
        dest = root / 'message-actions-result.json'; dest.write_text(json.dumps(result, indent=1, ensure_ascii=False)); dest.chmod(0o644)
print(json.dumps({'status': result['status'], 'cases': len(result['cases']), 'error': result.get('error')}))
sys.exit(0 if result['status'] == 'PASS' else 1)
