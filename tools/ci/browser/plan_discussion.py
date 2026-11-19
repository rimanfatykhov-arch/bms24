"""Real Moodle acceptance of the Battle Plan discussion (Communication Center E2, owner decision Q9); synthetic data only.

A weekly plan of EA (reviewer VA) in company A. Two cases (owned 1440 click, legacy 320 keyboard): EA posts with a file
from the plan card "Discussion" tab; VA answers with "Reply" (quote); the company manager MA (plan reader, not a plan
person) reads the card and the Communication Center without joining, downloads the file, sees "Join discussion" instead
of a composer and the "Open plan" link; RA (no plan access) and EB (other company) see nothing, cannot download, post or
join; a forged sesskey stores nothing; the plan itself never changes. The last case joins MA explicitly. Finally EA's
membership is revoked (thread and file refused in the same session) and reopened (restored).
Observations only -> EVIDENCE/plan-discussion-result.json; the verdict is computed by contract.plan_discussion_checks.
"""
import hashlib
import json
import os
import subprocess
import sys
import uuid
from pathlib import Path
from urllib.parse import parse_qs, urlencode, urlparse

sys.path.insert(0, str(Path(__file__).resolve().parent))
from contract import local_url, require  # noqa: E402
from events import Recorder  # noqa: E402
from playwright.sync_api import sync_playwright  # noqa: E402

require(os.environ.get('BMS_CI_BROWSER') == '1', 'CI browser fixture only')
here = Path(__file__).resolve().parent
root = Path(os.environ['EVIDENCE']); core = os.environ['MOODLE_ROOT']; base = 'http://127.0.0.1:8080'
f = json.loads((root / 'actors.private.json').read_text())
company = f['companies']['A']['uuid']
PLAN = base + '/local/kbacompany/battle_plan.php'
POST = base + '/local/kbacompany/battle_plan_discussion.php'
CENTER = base + '/local/kbacompany/communication_center.php'
CASES = [('owned', 1440, 'click'), ('legacy', 320, 'keyboard')]
result = {'status': 'FAIL', 'cases': []}


def run_php(helper, *args):
    out = subprocess.run(['php', str(here / helper), core, *args], check=True, capture_output=True, text=True).stdout
    return json.loads(out.strip().splitlines()[-1])


def comm(*args):
    return run_php('ci_communication_center.php', *args)


def plan_php(*args):
    return run_php('ci_plan_discussion.php', *args)


def shell(mode):
    subprocess.run(['php', str(here / 'ci_message_actions.php'), core, 'shell', mode], check=True, stdout=subprocess.DEVNULL)


def acl_switch(phase):
    subprocess.run(['php', str(here / 'ci_state.php'), core, phase], check=True, stdout=subprocess.DEVNULL)
    return json.loads((root / ('p02-switch-%s.json' % phase)).read_text())


def uid(label):
    return f['actors'][label]['userid']


def parts(thread):
    s = comm('parts', thread)
    return {'active': sorted(p['userid'] for p in s['parts'] if p['active']), 'reads': sorted(s['reads'])}


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


def raw_post(ctx, url, pairs):
    r = ctx.request.post(local_url(url), data=urlencode(pairs),
                         headers={'Content-Type': 'application/x-www-form-urlencoded'}, max_redirects=0)
    return {'http': r.status, 'location': r.headers.get('location', '')}


def download(ctx, href):
    r = ctx.request.get(local_url(href), max_redirects=0)
    return {'http': r.status, 'sha256': hashlib.sha256(r.body()).hexdigest() if r.status == 200 else None}


def visible_texts(page, selector):
    return page.locator(selector).evaluate_all('els => els.filter(e => e.checkVisibility()).map(e => e.innerText)')


with sync_playwright() as pw:
    browser = pw.chromium.launch(headless=True, args=['--disable-dev-shm-usage'])
    try:
        viewer = comm('viewer')
        plan = plan_php('plan', str(viewer['userid']))['plan']
        result['ids'] = {'MA': uid('MA'), 'EA': uid('EA'), 'RA': uid('RA'), 'EB': uid('EB'), 'VA': viewer['userid']}
        plan_url = local_url(PLAN + '?' + urlencode({'uuid': company, 'plan': plan, 'view': 'detail', 'tab': 'discussion'}))
        result['plan_before'] = plan_php('snapshot', company, plan)
        for index, (shellmode, width, mode) in enumerate(CASES):
            last = index == len(CASES) - 1
            shell(shellmode)
            token = uuid.uuid4().hex[:12]
            t_q, t_a = 'EA %s plan question' % token, 'VA %s plan answer' % token
            content = ('plan discussion attachment %s\n' % token).encode()
            fname = 'pd-%s.txt' % token
            case = {'shell': shellmode, 'width': width, 'mode': mode, 'texts': [t_q, t_a],
                    'file_sha256': hashlib.sha256(content).hexdigest()}
            contexts, server_errors = [], []

            def open_ctx(label, account=None, record=True):
                # Refusal contexts are not recorded: an expected 404 document legitimately logs a console error.
                ctx = browser.new_context(viewport={'width': width, 'height': 900})
                rec = Recorder().install(ctx) if record else None
                contexts.append((ctx, rec))
                page = login(ctx, label, account)
                page.on('response', lambda r: server_errors.append({'url': r.url, 'status': r.status}) if r.status >= 500 else None)
                return ctx, page

            # 1. EA (plan owner) posts with a file from the plan card
            ea_ctx, ea = open_ctx('EA')
            ea.goto(plan_url, wait_until='networkidle')
            case['tab_link'] = ea.locator('a[href*="tab=discussion"]').count()
            form = ea.locator('form[data-bms-plan-discussion-composer]')
            require(form.count() == 1, 'plan composer missing')
            form.locator('textarea[name=message]').fill(t_q)
            form.locator('input[type=file]').set_input_files([{'name': fname, 'mimeType': 'text/plain', 'buffer': content}])
            with ea.expect_navigation():
                activate(ea, form.locator('button[type=submit]'), mode)
            ea.wait_for_load_state('networkidle')
            thread = plan_php('thread', company, plan)['thread']
            q = comm('find', thread, t_q)['uuids']
            require(len(q) == 1, 'plan question not stored once')
            question = case['question'] = q[0]
            case['card_owner'] = {'question': ea.locator('#plan-message-%s' % question).count()}
            file_href = ea.locator('#plan-message-%s' % question).get_by_role('link', name=fname, exact=True).get_attribute('href')

            # 2. VA (reviewer, participant from the start) answers with a quote
            va_ctx, va = open_ctx('VA', viewer)
            va.goto(plan_url, wait_until='networkidle')
            with va.expect_navigation():
                activate(va, va.locator('#plan-message-%s' % question).get_by_role('link', name='Reply', exact=True), mode)
            va.wait_for_load_state('networkidle')
            target = va.locator('.bms-tui-reply-target')
            case['reply_target'] = {'count': target.count(), 'text': target.first.inner_text() if target.count() else ''}
            composer = va.locator('form[data-bms-plan-discussion-composer]')
            composer.locator('textarea[name=message]').fill(t_a)
            with va.expect_navigation():
                activate(va, composer.locator('button[type=submit]'), mode)
            va.wait_for_load_state('networkidle')
            a = comm('find', thread, t_a)['uuids']
            require(len(a) == 1, 'plan answer not stored once')
            case['reply'] = comm('message', a[0])
            case['card_quote'] = visible_texts(va, '#plan-message-%s blockquote' % a[0])

            # 3. MA (company manager, plan reader, not a plan person) reads without joining
            ma_ctx, ma = open_ctx('MA')
            ma.goto(plan_url, wait_until='networkidle')
            card_text = ma.locator('body').inner_text()
            dl = download(ma_ctx, file_href)
            ma.goto(local_url(CENTER + '?' + urlencode({'uuid': company, 'tab': 'discussions', 'thread': thread})),
                    wait_until='networkidle')
            link = ma.locator('.bms-comm2-feed-context-link')
            case['reader'] = {'card_question': t_q in card_text, 'card_answer': t_a in card_text,
                              'card_note': 'You see this discussion because' in card_text,
                              'download': dl,
                              'center_question': ma.locator('#message-%s' % question).count(),
                              'center_join': ma.locator('form.bms-comm2-join button').count(),
                              'center_composer': ma.locator('form[data-bms-comm-compose]').count(),
                              'open_plan': {'text': link.first.inner_text() if link.count() else '',
                                            'href': link.first.get_attribute('href') if link.count() else ''},
                              'parts_after_reading': parts(thread)}
            if last:
                with ma.expect_navigation():
                    ma.locator('form.bms-comm2-join').evaluate('f => f.requestSubmit()')
                ma.wait_for_load_state('networkidle')
                case['reader']['parts_after_join'] = parts(thread)
                case['reader']['composer_after_join'] = ma.locator('form[data-bms-comm-compose]').count()

            # 4. refusals: RA (same company, no plan access), EB (other company), forged sesskey
            count = comm('messages', thread)['count']
            case['denied'] = {}
            for label in ('RA', 'EB'):
                o_ctx, o = open_ctx(label, record=False)
                o.goto(plan_url, wait_until='networkidle')
                card = o.locator('body').inner_text()
                o.goto(local_url(CENTER + '?' + urlencode({'uuid': company, 'tab': 'discussions', 'thread': thread})),
                       wait_until='networkidle')
                center = o.locator('body').inner_text()
                post = raw_post(o_ctx, POST, [('uuid', company), ('plan', plan), ('action', 'post'),
                                               ('message', 'forbidden %s' % token), ('sesskey', sesskey(o))])
                join = raw_post(o_ctx, CENTER, [('uuid', company), ('action', 'createobject'), ('objectkind', 'battleplan'),
                                                 ('objectuuid', plan), ('sesskey', sesskey(o))])
                case['denied'][label] = {'card_text': t_q in card, 'center_text': t_q in center,
                                         'download': download(o_ctx, file_href), 'post_http': post['http'],
                                         'join_http': join['http'], 'join_location': join['location'], 'parts': parts(thread),
                                         'messages': comm('messages', thread)['count']}
            forged = raw_post(ea_ctx, POST, [('uuid', company), ('plan', plan), ('action', 'post'),
                                             ('message', 'forged %s' % token), ('sesskey', '0' * 10)])
            case['forged'] = {'http': forged['http'], 'location': forged['location'],
                              'messages': comm('messages', thread)['count']}
            case['messages_before_refusals'] = count

            case['browser_events'] = [e for _, rec in contexts if rec for e in rec.events]
            case['server_errors'] = server_errors
            result['cases'].append(case)
            for ctx, _ in contexts:
                ctx.close()
        result['plan_after'] = plan_php('snapshot', company, plan)

        # revoked membership: EA (plan owner, participant) loses the thread and its file in the same session
        rv_ctx = browser.new_context(); rv = login(rv_ctx, 'EA')
        rv.goto(plan_url, wait_until='networkidle')
        rev = {'before': {'text': t_q in rv.locator('body').inner_text(), 'download': download(rv_ctx, file_href)}}
        switch = acl_switch('revoke_ea')
        try:
            rev['revoked'] = switch.get('ea_revoked')
            rv.goto(local_url(CENTER + '?' + urlencode({'uuid': company, 'tab': 'discussions', 'thread': thread})),
                    wait_until='networkidle')
            rev['after'] = {'text': t_q in rv.locator('body').inner_text(), 'download': download(rv_ctx, file_href)}
        finally:
            rev['reopened'] = not acl_switch('reopen_ea').get('ea_revoked')
        rv.goto(plan_url, wait_until='networkidle')
        rev['restored'] = {'text': t_q in rv.locator('body').inner_text(), 'download': download(rv_ctx, file_href)}
        rv_ctx.close()
        result['revocation'] = rev
        result['status'] = 'PASS'
    except Exception as error:
        result['error'] = '%s: %s' % (type(error).__name__, str(error)[:400])
    finally:
        try:
            shell('owned')
        except Exception:
            pass
        browser.close()
        dest = root / 'plan-discussion-result.json'; dest.write_text(json.dumps(result, indent=1, ensure_ascii=False)); dest.chmod(0o644)
print(json.dumps({'status': result['status'], 'cases': len(result['cases']), 'error': result.get('error')}))
sys.exit(0 if result['status'] == 'PASS' else 1)
