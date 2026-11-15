"""Real Moodle acceptance of the Communication Center cleanup (items 1-5); synthetic data only, loopback-only requests.

Two cases (owned 1440 click, legacy 320 keyboard). Each: EA posts a message with a file in the task card; MA answers with
"Reply" and an inline @mention; the task card and the Communication Center show the same thread with the quote and the
read-only task events ("Task created"); the company task viewer VA (task manager grant, not a participant) reads the
discussion in the card and the Center, downloads the file, sees "Join discussion" instead of a composer and is not joined
by reading; RA (same company, no task access) and EB (other company) see nothing and cannot download or join; "Write" to
the same colleague reuses one direct conversation; Center filters separate conversations from object discussions; in a
new group the owner removes a member, then leaves, ownership passes to the longest-standing member and both lose access;
a non-owner removal, a forged sesskey and leaving a task discussion change nothing; "opened" stays in the task History.
The last case joins VA explicitly. Finally EA's membership is revoked: the thread and its file are refused in the same
session, and restored on reopen.
Observations only -> EVIDENCE/communication-center-result.json; the verdict is computed by contract.communication_center_checks.
"""
import hashlib
import json
import os
import subprocess
import sys
import uuid
from html.parser import HTMLParser
from pathlib import Path
from urllib.parse import parse_qs, urlencode, urlparse, urljoin

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
CENTER = base + '/local/kbacompany/communication_center.php'
CASES = [('owned', 1440, 'click'), ('legacy', 320, 'keyboard')]
result = {'status': 'FAIL', 'cases': []}


def php(*args):
    out = subprocess.run(['php', str(here / 'ci_communication_center.php'), core, *args], check=True,
                         capture_output=True, text=True).stdout
    return json.loads(out.strip().splitlines()[-1])


result['denial_texts'] = php('denialstrings')['texts']


def shell(mode):
    subprocess.run(['php', str(here / 'ci_message_actions.php'), core, 'shell', mode], check=True, stdout=subprocess.DEVNULL)


def uid(label):
    return f['actors'][label]['userid']


def parts(thread):
    s = php('parts', thread)
    return {'active': sorted(p['userid'] for p in s['parts'] if p['active']),
            'owners': sorted(p['userid'] for p in s['parts'] if p['active'] and p['role'] == 'owner'), 'reads': sorted(s['reads'])}


def find(thread, body):
    return php('find', thread, body)['uuids']


def task_url(tab='discussion', **extra):
    return local_url(TASKS + '?' + urlencode(dict({'uuid': company, 'task': task, 'view': 'detail', 'tab': tab}, **extra)))


def center_url(**params):
    return local_url(CENTER + '?' + urlencode(dict({'uuid': company}, **params)))


def login(ctx, label, account=None):
    a = account or f['actors'][label]; p = ctx.new_page(); p.set_default_timeout(30000)
    p.goto(local_url(base + '/login/index.php'), wait_until='networkidle')
    p.locator('#username').fill(a['username']); p.locator('#password').fill(a['password'])
    p.locator('#loginbtn').click(); p.wait_for_load_state('networkidle')
    return p


def activate(page, locator, mode):
    if mode == 'click':
        locator.click()
    else:
        locator.focus(); page.keyboard.press('Enter')


def sesskey(page):
    return page.evaluate('() => M.cfg.sesskey')


def post(ctx, pairs):
    """Raw form POST (repeated keys allowed), redirects never followed."""
    r = ctx.request.post(local_url(CENTER), data=urlencode(pairs),
                         headers={'Content-Type': 'application/x-www-form-urlencoded'}, max_redirects=0)
    location = r.headers.get('location', '')
    if location:
        destination = local_url(urljoin(CENTER, location))
        require(urlparse(destination).path == urlparse(CENTER).path, 'POST redirected to an unexpected page')
    class Errors(HTMLParser):
        def __init__(self):
            super().__init__(); self.stack = []; self.active = 0; self.texts = []; self.current = []
        def handle_starttag(self, tag, attrs):
            attrs = dict(attrs)
            marked = bool({'alert-danger', 'errorbox', 'errormessage'} & set(attrs.get('class', '').split()))
            marked = marked or attrs.get('data-rel') == 'fatalerror' or attrs.get('id') == 'notice'
            if tag not in ('area', 'base', 'br', 'col', 'embed', 'hr', 'img', 'input', 'link', 'meta', 'param', 'source', 'track', 'wbr'):
                self.stack.append((tag, marked))
                if marked:
                    if not self.active: self.current = []
                    self.active += 1
        def handle_endtag(self, tag):
            if not any(t == tag for t, _ in self.stack): return
            while self.stack:
                t, marked = self.stack.pop()
                if marked:
                    self.active -= 1
                    if not self.active: self.texts.append(' '.join(' '.join(self.current).split()))
                if t == tag: break
        def handle_data(self, data):
            if self.active: self.current.append(data)
    body = r.body(); parser = Errors(); parser.feed(body.decode('utf-8', errors='replace')); parser.close()
    return {'http': r.status, 'location': location, 'url': r.url, 'error_texts': parser.texts,
            'body_sha256': hashlib.sha256(body).hexdigest(),
            'thread': (parse_qs(urlparse(location).query).get('thread') or [''])[0]}


def download(ctx, href):
    r = ctx.request.get(local_url(href), max_redirects=0)
    return {'http': r.status, 'sha256': hashlib.sha256(r.body()).hexdigest() if r.status == 200 else None}


def submit_form(page, form, mode):
    """Open containing details and activate a visible real submit button; never bypass hit testing."""
    require(form.count() == 1, 'form missing')
    # Locator results are in document order: open outer disclosure before its nested summary.
    for detail in form.locator('xpath=ancestor::details').all():
        if detail.get_attribute('open') is None:
            activate(page, detail.locator(':scope > summary'), mode)
    button = form.locator('button[type=submit]')
    require(button.count() == 1 and button.is_visible(), 'visible submit control missing')
    with page.expect_navigation():
        activate(page, button, mode)
    page.wait_for_load_state('networkidle')


def visible_texts(page, selector):
    return page.locator(selector).evaluate_all('els => els.filter(e => e.checkVisibility()).map(e => e.innerText)')


with sync_playwright() as pw:
    browser = pw.chromium.launch(headless=True, args=['--disable-dev-shm-usage'])
    try:
        viewer = php('viewer')
        result['viewer_id'] = viewer['userid']
        result['ids'] = {label: uid(label) for label in ('MA', 'EA', 'RA', 'EB')}
        for index, (shellmode, width, mode) in enumerate(CASES):
            last = index == len(CASES) - 1
            shell(shellmode)
            token = uuid.uuid4().hex[:12]
            t_ea, t_ma, t_group = ('EA %s question' % token, 'MA %s answer @Synthetic EA' % token, 'MA %s group note' % token)
            content = ('communication center attachment %s\n' % token).encode()
            fname = 'cc-%s.txt' % token
            case = {'shell': shellmode, 'width': width, 'mode': mode, 'texts': [t_ea, t_ma, t_group],
                    'file_sha256': hashlib.sha256(content).hexdigest()}
            contexts, events, server_errors = [], [], []

            def open_ctx(label, account=None, record=True):
                # Refusal contexts are not recorded: an expected 404 document legitimately logs a console error.
                # Their verdict comes from page text, DB rows and raw HTTP statuses (5xx is still collected).
                ctx = browser.new_context(viewport={'width': width, 'height': 900})
                rec = Recorder().install(ctx) if record else None
                contexts.append((ctx, rec))
                page = login(ctx, label, account)
                page.on('response', lambda r: server_errors.append({'url': r.url, 'status': r.status}) if r.status >= 500 else None)
                return ctx, page

            # 1. EA asks a question with a file in the task card
            ea_ctx, ea = open_ctx('EA')
            ea.goto(task_url(), wait_until='networkidle')
            form = ea.locator('form[data-bms-discussion-composer]').first
            form.locator('textarea').fill(t_ea)
            form.locator('input[type=file]').set_input_files([{'name': fname, 'mimeType': 'text/plain', 'buffer': content}])
            with ea.expect_navigation():
                form.locator('button[type=submit]').click()
            ea.wait_for_load_state('networkidle')
            thread = php('task_thread', company, task)['thread']
            before_count = php('messages', thread)['count']
            q = find(thread, t_ea)
            require(len(q) == 1, 'question not stored once')
            question = q[0]
            case['question'] = question
            file_href = ea.locator('#task-message-%s' % question).get_by_role('link', name=fname, exact=True).get_attribute('href')

            # 2. MA replies with a quote and an inline mention from the task card
            ma_ctx, ma = open_ctx('MA')
            ma.goto(task_url(), wait_until='networkidle')
            with ma.expect_navigation():
                activate(ma, ma.locator('#task-message-%s' % question).get_by_role('link', name='Reply', exact=True), mode)
            ma.wait_for_load_state('networkidle')
            target = ma.locator('.bms-tui-reply-target')
            case['reply_target'] = {'count': target.count(), 'text': target.first.inner_text() if target.count() else ''}
            composer = ma.locator('form[data-bms-discussion-composer]').first
            composer.locator('textarea').fill(t_ma)
            with ma.expect_navigation():
                activate(ma, composer.locator('button[type=submit]'), mode)
            ma.wait_for_load_state('networkidle')
            r = find(thread, t_ma)
            require(len(r) == 1, 'reply not stored once')
            reply = r[0]
            case['reply'] = php('message', reply)
            case['mention_notification'] = php('notifications', reply, str(uid('EA')))['kinds']
            ma.goto(task_url(), wait_until='networkidle')
            case['card'] = {'quote': visible_texts(ma, '#task-message-%s blockquote' % reply),
                            'events': visible_texts(ma, '.bms-tui-discussion-event'),
                            'messages_after': php('messages', thread)['count'], 'messages_before': before_count}
            ma.goto(task_url('history'), wait_until='networkidle')
            case['card']['history_opened'] = ma.locator('article[data-history-action=opened]').count()

            # 3. the Center shows the same thread, quote, events and the way back to the task
            ma.goto(center_url(tab='discussions', thread=thread), wait_until='networkidle')
            link = ma.locator('.bms-comm2-feed-context-link')
            case['center'] = {'question': ma.locator('#message-%s' % question).count(),
                              'quote': visible_texts(ma, '#message-%s .bms-comm2-quote' % reply),
                              'events': visible_texts(ma, '.bms-comm2-event'),
                              'open_task': {'text': link.first.inner_text() if link.count() else '',
                                            'href': link.first.get_attribute('href') if link.count() else ''},
                              'reply_links': ma.locator('#message-%s' % question).get_by_role('link', name='Reply', exact=True).count(),
                              'composer': ma.locator('form[data-bms-comm-compose]').count()}
            # the oldest history page carries the earliest task events ("Task created")
            ma.goto(center_url(tab='discussions', thread=thread, mpage=100000), wait_until='networkidle')
            case['center']['events_oldest'] = visible_texts(ma, '.bms-comm2-event')

            # 4. the task viewer VA reads without joining; files follow the discussion
            va_ctx, va = open_ctx('VA', viewer)
            va.goto(task_url(), wait_until='networkidle')
            card_text = va.locator('body').inner_text()
            dl = download(va_ctx, file_href)
            va.goto(center_url(tab='discussions', thread=thread), wait_until='networkidle')
            case['viewer'] = {'card_question': t_ea in card_text, 'card_reply': t_ma in card_text,
                              'card_reader_note': 'You see this discussion because' in card_text,
                              'download': dl,
                              'center_question': va.locator('#message-%s' % question).count(),
                              'center_join': va.locator('form.bms-comm2-join button').count(),
                              'center_composer': va.locator('form[data-bms-comm-compose]').count(),
                              'center_reply_links': va.locator('#message-%s' % question).get_by_role('link', name='Reply', exact=True).count(),
                              'parts_after_reading': parts(thread)}
            if last:
                submit_form(va, va.locator('form.bms-comm2-join'), mode)
                case['viewer']['parts_after_join'] = parts(thread)
                case['viewer']['composer_after_join'] = va.locator('form[data-bms-comm-compose]').count()

            # 5. no access: RA (same company, no task access) and EB (other company)
            case['denied'] = {}
            for label in ('RA', 'EB'):
                o_ctx, o = open_ctx(label, record=False)
                o.goto(center_url(tab='discussions', thread=thread), wait_until='networkidle')
                center_text = o.locator('body').inner_text()
                o.goto(task_url(), wait_until='networkidle')
                card_text = o.locator('body').inner_text()
                join = post(o_ctx, [('uuid', company), ('action', 'createobject'), ('objectkind', 'task'),
                                    ('objectuuid', task), ('sesskey', sesskey(o))])
                case['denied'][label] = {'center_text': t_ea in center_text or t_ma in center_text,
                                         'card_text': t_ea in card_text, 'download': download(o_ctx, file_href),
                                         'join_http': join['http'], 'join_response': join, 'parts': parts(thread)}

            # 6. one direct conversation per pair; filters separate conversations from object discussions
            key = sesskey(ma)
            direct = [post(ma_ctx, [('uuid', company), ('action', 'createthread'), ('threadtype', 'direct'), ('title', ''),
                                    ('participants[]', str(uid('EA'))), ('sesskey', key)]) for _ in range(2)]
            case['direct'] = {'responses': direct, 'threads': php('directs', company, str(uid('MA')), str(uid('EA')))['threads']}
            ma.goto(center_url(tab='discussions'), wait_until='networkidle')
            in_discussions = {t: ma.locator('.bms-comm2-thread-list a[data-thread-uuid="%s"]' % t).count()
                              for t in (thread, direct[0]['thread'])}
            ma.goto(center_url(tab='messages'), wait_until='networkidle')
            in_messages = {t: ma.locator('.bms-comm2-thread-list a[data-thread-uuid="%s"]' % t).count()
                           for t in (thread, direct[0]['thread'])}
            case['filters'] = {'discussions': {'task': in_discussions[thread], 'direct': in_discussions[direct[0]['thread']]},
                               'messages': {'task': in_messages[thread], 'direct': in_messages[direct[0]['thread']]},
                               'tabs': ma.locator('a.bms-comm2-filter[href*="tab=messages"], a.bms-comm2-filter[href*="tab=discussions"]').count()}

            # 7. group: owner removes a member, another member leaves; negatives change nothing
            group = post(ma_ctx, [('uuid', company), ('action', 'createthread'), ('threadtype', 'group'),
                                  ('title', 'CC group %s' % token), ('participants[]', str(uid('EA'))),
                                  ('participants[]', str(uid('RA'))), ('participants[]', str(viewer['userid'])),
                                  ('sesskey', key)])['thread']
            post(ma_ctx, [('uuid', company), ('action', 'send'), ('thread', group), ('tab', 'messages'),
                          ('body', t_group), ('sesskey', key)])
            g = {'created': parts(group)}
            g['non_owner_remove'] = dict(post(ea_ctx, [('uuid', company), ('action', 'removeparticipant'), ('thread', group),
                                                       ('participantid', str(uid('RA'))), ('sesskey', sesskey(ea))]),
                                         parts=parts(group))
            g['forged_sesskey'] = dict(post(ma_ctx, [('uuid', company), ('action', 'removeparticipant'), ('thread', group),
                                                     ('participantid', str(uid('RA'))), ('sesskey', '0' * 10)]),
                                       parts=parts(group))
            g['leave_task_discussion'] = dict(post(ma_ctx, [('uuid', company), ('action', 'leavethread'), ('thread', thread),
                                                            ('sesskey', key)]), parts=parts(thread))
            ma.goto(center_url(tab='messages', thread=group), wait_until='networkidle')
            g['remove_controls'] = ma.locator('.bms-comm2-person form button').count()
            # The members list sits in the collapsible context panel: innerText of collapsed content is empty,
            # so count the exact role marker by textContent (one owner row).
            g['owner_marker'] = ma.locator('.bms-comm2-people .bms-comm2-person-role').evaluate_all(
                "els => els.filter(e => e.textContent.trim() === 'owner').length") == 1
            submit_form(ma, ma.locator('.bms-comm2-person form').filter(
                  has=ma.locator('input[name=participantid][value="%s"]' % uid('RA'))), mode)
            g['after_remove'] = parts(group)
            # the owner leaves: ownership passes to the longest-standing remaining member (EA, added first)
            submit_form(ma, ma.locator('details.bms-comm2-leave form'), mode)
            g['after_owner_leave'] = parts(group)
            ea.goto(center_url(tab='messages', thread=group), wait_until='networkidle')
            g['new_owner_controls'] = ea.locator('.bms-comm2-person form button').count()
            g['access_after'] = {}
            for label, page in (('MA', ma), ('RA', None)):
                if page is None:
                    rctx, page = open_ctx('RA', record=False)
                page.goto(center_url(tab='messages', thread=group), wait_until='networkidle')
                g['access_after'][label] = t_group in page.locator('body').inner_text()
            case['group'] = g

            case['browser_events'] = [e for _, rec in contexts if rec for e in rec.events]
            case['server_errors'] = server_errors
            result['cases'].append(case)
            for ctx, _ in contexts:
                ctx.close()
        # revoked membership: EA's open session loses the thread and its file; reopen restores access
        rv_ctx = browser.new_context(); rv = login(rv_ctx, 'EA')
        rv.goto(center_url(tab='discussions', thread=thread), wait_until='networkidle')
        rev = {'before': {'text': t_ea in rv.locator('body').inner_text(), 'download': download(rv_ctx, file_href)}}
        subprocess.run(['php', str(here / 'ci_state.php'), core, 'revoke_ea'], check=True, stdout=subprocess.DEVNULL)
        try:
            rev['revoked'] = json.loads((root / 'p02-switch-revoke_ea.json').read_text()).get('ea_revoked')
            rv.goto(center_url(tab='discussions', thread=thread), wait_until='networkidle')
            rev['after'] = {'text': t_ea in rv.locator('body').inner_text(), 'download': download(rv_ctx, file_href)}
        finally:
            subprocess.run(['php', str(here / 'ci_state.php'), core, 'reopen_ea'], check=True, stdout=subprocess.DEVNULL)
            rev['reopened'] = not json.loads((root / 'p02-switch-reopen_ea.json').read_text()).get('ea_revoked')
        rv.goto(center_url(tab='discussions', thread=thread), wait_until='networkidle')
        rev['restored'] = {'text': t_ea in rv.locator('body').inner_text(), 'download': download(rv_ctx, file_href)}
        rv_ctx.close()
        result['revocation'] = rev
        result['status'] = 'PASS'
    except Exception as error:
        result['error'] = '%s: %s' % (type(error).__name__, str(error)[:400])
        if 'case' in locals():
            result['incomplete_case'] = case  # Diagnostics only; never appended to the required complete matrix.
    finally:
        try:
            shell('owned')
        except Exception:
            pass
        browser.close()
        dest = root / 'communication-center-result.json'; dest.write_text(json.dumps(result, indent=1, ensure_ascii=False)); dest.chmod(0o644)
print(json.dumps({'status': result['status'], 'cases': len(result['cases']), 'error': result.get('error')}))
sys.exit(0 if result['status'] == 'PASS' else 1)
