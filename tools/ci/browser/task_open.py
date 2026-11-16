"""Real Moodle acceptance of the "user opened the task" History event; synthetic data only, loopback-only requests.

Two cases (owned 1440 EN, legacy 320 RU): list page writes nothing; opening the card writes exactly one event shown in History as
"<name> opened the task"; reload and card-tab switching write nothing more; a hidden tab writes nothing until activated;
another user gets an own event; leaving the page open past the interval writes nothing, reopening after it writes one more;
eight concurrent POSTs write at most one; task state and notifications stay unchanged; a failing endpoint does not break
the card. Server negatives with real sessions: wrong sesskey, GET/HEAD, same-company user without task access,
other company, substituted task, user substitution and a revoked member.
Observations only -> EVIDENCE/task-open-result.json; the verdict is computed by contract.task_open_checks.
"""
import json
import os
import subprocess
import sys
import threading
import urllib.error
import urllib.parse
import urllib.request
import uuid
from pathlib import Path

sys.path.insert(0, str(Path(__file__).resolve().parent))
from contract import local_url, require  # noqa: E402
from events import Recorder  # noqa: E402
from playwright.sync_api import sync_playwright  # noqa: E402

require(os.environ.get('BMS_CI_BROWSER') == '1', 'CI browser fixture only')
here = Path(__file__).resolve().parent
root = Path(os.environ['EVIDENCE']); core = os.environ['MOODLE_ROOT']; base = 'http://127.0.0.1:8080'
f = json.loads((root / 'actors.private.json').read_text())
company, task = f['companies']['A']['uuid'], f['tasks']['A']['uuid']
company_b = f['companies']['B']['uuid']
TASKS = base + '/local/kbacompany/tasks.php'
ENDPOINT = base + '/local/kbacompany/task_open.php'
CASES = [('owned', 1440, 'en'), ('legacy', 320, 'ru')]
HIDDEN = """(() => { let v = 'hidden';
  Object.defineProperty(document, 'visibilityState', {configurable: true, get: () => v});
  Object.defineProperty(document, 'hidden', {configurable: true, get: () => v !== 'visible'});
  window.__bmsShow = () => { v = 'visible'; document.dispatchEvent(new Event('visibilitychange')); }; })()"""
result = {'status': 'FAIL', 'cases': [], 'negatives': {}}


def php(*args):
    out = subprocess.run(['php', str(here / 'ci_task_open.php'), core, *args], check=True,
                         capture_output=True, text=True).stdout
    return json.loads(out.strip().splitlines()[-1])


def rows():
    return php('rows', company, task)['rows']


def actor_rows(label):
    uid = f['actors'][label]['userid']
    return [r for r in rows() if r['actorid'] == uid]


def snapshot():
    s = php('snapshot', company, task)
    return {'task': s['task'], 'business_log': s['business_log'], 'notifications': s['notifications']}


def acl_switch(phase):
    subprocess.run(['php', str(here / 'ci_state.php'), core, phase], check=True, stdout=subprocess.DEVNULL)
    return json.loads((root / ('p02-switch-%s.json' % phase)).read_text())


def task_url(tab='overview', target=None):
    return local_url(TASKS + '?' + urllib.parse.urlencode({'uuid': company, 'task': target or task, 'view': 'detail', 'tab': tab}))


def login(ctx, label):
    a = f['actors'][label]; p = ctx.new_page(); p.set_default_timeout(30000)
    p.goto(local_url(base + '/login/index.php'), wait_until='networkidle')
    p.locator('#username').fill(a['username']); p.locator('#password').fill(a['password'])
    p.locator('#loginbtn').click(); p.wait_for_load_state('networkidle')
    return p


def opened_status(page, timeout=10000):
    """Wait until the client recorded the endpoint's answer (HTTP status) on the marker; None when nothing was sent."""
    marker = page.locator('[data-bms-task-open]')
    if marker.count() != 1:
        return None
    try:
        page.wait_for_function("() => document.querySelector('[data-bms-task-open]').hasAttribute('data-bms-task-open-status')",
                               timeout=timeout)
    except Exception:
        return None
    return marker.get_attribute('data-bms-task-open-status')


def history(page):
    page.goto(task_url('history'), wait_until='networkidle')
    opened_status(page)
    items = page.locator('article[data-history-action=opened]')
    return {'count': items.count(), 'texts': items.all_inner_texts(), 'visible': items.first.is_visible() if items.count() else False}


def sesskey(page):
    return page.evaluate('() => M.cfg.sesskey')


def raw(ctx, method, fields):
    if method == 'POST':
        r = ctx.request.post(local_url(ENDPOINT), form=fields, max_redirects=0)
    elif method == 'HEAD':
        r = ctx.request.fetch(local_url(ENDPOINT + '?' + urllib.parse.urlencode(fields)), method='HEAD', max_redirects=0)
    else:
        r = ctx.request.get(local_url(ENDPOINT + '?' + urllib.parse.urlencode(fields)), max_redirects=0)
    try:
        body = r.json() if method != 'HEAD' else {}
    except Exception:
        body = {'unparsed': True}
    return {'http': r.status, 'result': body.get('result'), 'ok': body.get('ok')}


def concurrent(ctx, page, n=8):
    """n simultaneous POSTs of one session (separate threads, real HTTP), not serialized by the browser."""
    cookie = '; '.join('%s=%s' % (c['name'], c['value']) for c in ctx.cookies(local_url(base + '/')))
    data = urllib.parse.urlencode({'sesskey': sesskey(page), 'uuid': company, 'task': task}).encode()
    gate, out = threading.Barrier(n), []

    def one():
        req = urllib.request.Request(local_url(ENDPOINT), data=data, method='POST',
                                     headers={'Cookie': cookie, 'Content-Type': 'application/x-www-form-urlencoded'})
        gate.wait()
        try:
            with urllib.request.urlopen(req, timeout=30) as resp:
                out.append({'http': resp.status, 'result': json.loads(resp.read()).get('result')})
        except urllib.error.HTTPError as e:
            out.append({'http': e.code, 'result': None})
    threads = [threading.Thread(target=one) for _ in range(n)]
    for t in threads: t.start()
    for t in threads: t.join()
    return out


with sync_playwright() as pw:
    browser = pw.chromium.launch(headless=True, args=['--disable-dev-shm-usage'])
    try:
        result['ids'] = {label: f['actors'][label]['userid'] for label in ('MA', 'EA')}
        result['readable'] = {label: php('readable', company, task, str(f['actors'][label]['userid']))['readable']
                              for label in ('MA', 'EA', 'RA', 'EB')}
        for shell, width, lang in CASES:
            php('shell', shell)
            language = php('lang', lang, str(f['actors']['MA']['userid']))
            php('clear', company, task)
            before = snapshot()
            ctx = browser.new_context(viewport={'width': width, 'height': 900})
            recorder = Recorder().install(ctx)
            server_errors = []
            page = login(ctx, 'MA')
            page.on('response', lambda r: server_errors.append({'url': r.url, 'status': r.status}) if r.status >= 500 else None)
            case = {'shell': shell, 'width': width, 'lang': lang,
                    'language': {'installed': language['installed'], 'sample': language['sample']}}
            # 1. list page writes nothing
            page.goto(local_url(TASKS + '?' + urllib.parse.urlencode({'uuid': company})), wait_until='networkidle')
            page.wait_for_timeout(500)
            case['list'] = {'marker': page.locator('[data-bms-task-open]').count(), 'rows': len(rows())}
            # 2. first opening writes one event, shown in History
            page.goto(task_url('overview'), wait_until='networkidle')
            case['first'] = {'status': opened_status(page), 'rows': len(actor_rows('MA'))}
            # 3. reload and card tabs write nothing more
            page.reload(wait_until='networkidle'); s1 = opened_status(page)
            for tab in ('checklist', 'discussion', 'files'):
                page.goto(task_url(tab), wait_until='networkidle'); opened_status(page)
            case['history'] = history(page)
            case['history']['html_lang'] = page.locator('html').get_attribute('lang')
            case['repeat'] = {'reload_status': s1, 'rows': len(actor_rows('MA'))}
            # 4. a hidden tab of another user writes nothing until it is activated
            hidden = browser.new_context(viewport={'width': width, 'height': 900})
            hidden_recorder = Recorder().install(hidden)
            hp = login(hidden, 'EA')
            hp.add_init_script(HIDDEN)
            hp.goto(task_url('overview'), wait_until='networkidle'); hp.wait_for_timeout(1500)
            case['hidden'] = {'before_activation': len(actor_rows('EA'))}
            hp.evaluate('() => window.__bmsShow()')
            case['hidden'].update(status=opened_status(hp), after_activation=len(actor_rows('EA')))
            hidden_recorder_events = hidden_recorder.events
            hidden.close()
            # 5. leaving the page open past the interval writes nothing; reopening after it writes one more
            php('backdate', company, task, str(f['actors']['MA']['userid']), '901')
            page.wait_for_timeout(2500)
            case['interval'] = {'idle_rows': len(actor_rows('MA'))}
            page.goto(task_url('overview'), wait_until='networkidle')
            case['interval'].update(status=opened_status(page), rows=len(actor_rows('MA')))
            # 6. concurrent requests after another interval write at most one event
            php('backdate', company, task, str(f['actors']['MA']['userid']), '901')
            replies = concurrent(ctx, page)
            case['concurrent'] = {'replies': replies, 'rows': len(actor_rows('MA'))}
            after = snapshot()
            case['business'] = {'before': before, 'after': after}
            case['row_shape'] = rows()
            case['browser_events'] = recorder.events + hidden_recorder_events
            case['server_errors'] = server_errors
            result['cases'].append(case)
            ctx.close()
        php('shell', 'owned'); php('lang', 'en', str(f['actors']['MA']['userid']))

        # ---- a failing endpoint never breaks the card (no recorder: the abort is deliberate)
        php('clear', company, task)
        broken = browser.new_context(); bp = login(broken, 'MA')
        page_errors = []
        bp.on('pageerror', lambda e: page_errors.append(str(e)[:200]))
        bp.route('**/task_open.php', lambda route: route.abort('failed'))
        bp.goto(task_url('overview'), wait_until='networkidle')
        result['failure'] = {'status': opened_status(bp), 'title_visible': bp.locator('h1, h2').first.is_visible(),
                             'tabs': bp.locator('nav a[href*="tab=history"]').count(), 'page_errors': page_errors,
                             'rows': len(rows())}
        broken.close()

        # ---- server-side negatives with real sessions
        neg = result['negatives']
        php('clear', company, task)
        ma = browser.new_context(); mp = login(ma, 'MA')
        mp.goto(local_url(base + '/my/'), wait_until='networkidle')
        key = sesskey(mp)
        valid = {'sesskey': key, 'uuid': company, 'task': task}
        neg['wrong_sesskey'] = dict(raw(ma, 'POST', dict(valid, sesskey='0' * 10)), rows=len(rows()))
        neg['get'] = dict(raw(ma, 'GET', valid), rows=len(rows()))
        neg['head'] = dict(raw(ma, 'HEAD', valid), rows=len(rows()))
        neg['substituted_task'] = dict(raw(ma, 'POST', dict(valid, task=str(uuid.uuid4()))), rows=len(rows()))
        neg['company_task_mismatch'] = dict(raw(ma, 'POST', dict(valid, uuid=company_b)), rows=len(rows()))
        # user substitution: an extra user id is ignored, the server records the session user
        neg['user_substitution'] = dict(raw(ma, 'POST', dict(valid, userid=str(f['actors']['EA']['userid']),
                                                            actorid=str(f['actors']['EA']['userid']))))
        neg['user_substitution']['rows'] = rows()
        for label, name in (('RA', 'same_company_no_access'), ('EB', 'other_company')):
            other = browser.new_context(); op = login(other, label)
            op.goto(local_url(base + '/my/'), wait_until='networkidle')
            php('clear', company, task)
            neg[name] = dict(raw(other, 'POST', {'sesskey': sesskey(op), 'uuid': company, 'task': task}), rows=len(rows()))
            denied = op.goto(task_url('overview'), wait_until='networkidle')
            op.wait_for_timeout(1000)
            neg[name].update(page_http=denied.status if denied else None, marker=op.locator('[data-bms-task-open]').count(),
                             rows_after_page=len(rows()))
            other.close()
        php('clear', company, task)
        ea = browser.new_context(); ep = login(ea, 'EA')
        ep.goto(local_url(base + '/my/'), wait_until='networkidle')
        ea_key = sesskey(ep)
        switch = acl_switch('revoke_ea')
        try:
            neg['revoked_member'] = dict(raw(ea, 'POST', {'sesskey': ea_key, 'uuid': company, 'task': task}),
                                         revoked=switch.get('ea_revoked'), rows=len(rows()))
        finally:
            neg['reopened'] = not acl_switch('reopen_ea').get('ea_revoked')
        ea.close(); ma.close()
        php('clear', company, task)
        result['status'] = 'PASS'
    except Exception as error:
        result['error'] = '%s: %s' % (type(error).__name__, str(error)[:400])
        try:
            if 'page' in locals() and not page.is_closed():
                page.screenshot(path=str(root / 'task-open-failure.png'), full_page=True)
        except Exception as diagnostic:
            result['diagnostic_error'] = str(diagnostic)
    finally:
        try:
            php('shell', 'owned'); php('lang', 'en', str(f['actors']['MA']['userid']))
        except Exception:
            pass
        browser.close()
        dest = root / 'task-open-result.json'; dest.write_text(json.dumps(result, indent=1, ensure_ascii=False)); dest.chmod(0o644)
print(json.dumps({'status': result['status'], 'cases': len(result['cases']), 'error': result.get('error')}))
sys.exit(0 if result['status'] == 'PASS' else 1)
