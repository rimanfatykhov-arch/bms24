"""Real Moodle acceptance of saved daily reports R1/R2 (ADR-0009); synthetic data only, loopback-only requests.

Two cases (owned 1440 today, legacy 375 yesterday). Employee EA, reviewer MA (battle plan reviewer), foreign EB, same-company
non-reviewer RA. Per case: draft survives a new login; two tabs on one draft give a conflict that keeps the second tab's text;
eight concurrent submit POSTs (one request key) create one version and one reviewer notification; a forged sesskey POST and
a GET change nothing; (today) the submitted snapshot keeps the task title after the task changes; eight concurrent "return"
POSTs give one decision and one author notification; rework + resubmit in the browser create version 2; eight concurrent
"accept" POSTs give one accepted decision bound to version 2; EB and RA cannot decide; the reviewer's Communication Center
notification links to the review tab; no horizontal scroll at the case width; no browser errors or HTTP 5xx.
Observations only -> EVIDENCE/daily-reports-flow-result.json; the verdict is computed by contract.daily_reports_flow_checks.
"""
import datetime
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
company = f['companies']['A']['uuid']
PAGE = base + '/local/kbacompany/daily_report.php'
CASES = [('owned', 1440, 0), ('legacy', 375, 1)]
HSCROLL = '() => document.documentElement.scrollWidth <= window.innerWidth'
FORMDATA = 'f => Object.fromEntries(Array.from(new FormData(f)).filter(([k, v]) => typeof v === "string"))'
result = {'status': 'FAIL', 'cases': [], 'negatives': {}}


def php(*args):
    out = subprocess.run(['php', str(here / 'ci_daily_reports.php'), core, *args], check=True,
                         capture_output=True, text=True).stdout
    return json.loads(out.strip().splitlines()[-1])


def state(day, marker=''):
    return php('state', company, str(f['actors']['EA']['userid']), day, marker)


def login(ctx, label):
    a = f['actors'][label]; p = ctx.new_page(); p.set_default_timeout(30000)
    p.goto(local_url(base + '/login/index.php'), wait_until='networkidle')
    p.locator('#username').fill(a['username']); p.locator('#password').fill(a['password'])
    p.locator('#loginbtn').click(); p.wait_for_load_state('networkidle')
    return p


def url(**params):
    return local_url(PAGE + '?' + urllib.parse.urlencode(dict({'uuid': company}, **params)))


class NoRedirect(urllib.request.HTTPRedirectHandler):
    def redirect_request(self, *args, **kwargs):
        return None


def parallel_post(ctx, fields, n=8):
    """n simultaneous POSTs of one session (threads, real HTTP, redirects not followed)."""
    cookie = '; '.join('%s=%s' % (c['name'], c['value']) for c in ctx.cookies(local_url(base + '/')))
    data = urllib.parse.urlencode(fields).encode()
    action = local_url(PAGE + '?' + urllib.parse.urlencode({'uuid': company}))
    gate, out = threading.Barrier(n), []
    opener = urllib.request.build_opener(NoRedirect)

    def one():
        req = urllib.request.Request(action, data=data, method='POST',
                                     headers={'Cookie': cookie, 'Content-Type': 'application/x-www-form-urlencoded'})
        gate.wait()
        try:
            with opener.open(req, timeout=60) as resp:
                out.append(resp.status)
        except urllib.error.HTTPError as e:
            out.append(e.code)
        except Exception as e:  # recorded, judged by the contract
            out.append(type(e).__name__)
    threads = [threading.Thread(target=one) for _ in range(n)]
    for t in threads: t.start()
    for t in threads: t.join()
    return sorted(out, key=str)


def form_fields(page, selector, action=None):
    form = page.locator(selector).first
    require(form.count() == 1, 'form missing: %s' % selector)
    fields = form.evaluate(FORMDATA)
    if action:
        fields['action'] = action
    return fields


def review_form(page, action):
    return form_fields(page, 'form:has(input[name=action][value=%s])' % action)


with sync_playwright() as pw:
    browser = pw.chromium.launch(headless=True, args=['--disable-dev-shm-usage'])
    try:
        setup = php('setup', company, str(f['actors']['EA']['userid']), str(f['actors']['MA']['userid']))
        require(setup.get('enabled') is True and setup.get('task'), 'setup failed')
        result['setup'] = {'enabled': setup['enabled'], 'today': setup['today']}
        today = datetime.date.fromisoformat(setup['today'])
        for shell, width, offset in CASES:
            php('shell', shell)
            day = (today - datetime.timedelta(days=offset)).isoformat()
            token = uuid.uuid4().hex[:10]
            texts = {k: 'DR %s %s' % (token, k) for k in ('draft', 'tab1', 'tab2', 'submit', 'rework', 'forged')}
            case = {'shell': shell, 'width': width, 'day': day, 'today': offset == 0, 'texts': texts}
            recorder = Recorder(); server_errors = []
            ctx = browser.new_context(viewport={'width': width, 'height': 900}); recorder.install(ctx)
            page = login(ctx, 'EA')
            page.on('response', lambda r: server_errors.append({'url': r.url, 'status': r.status}) if r.status >= 500 else None)
            # 1. draft saved on the server survives a new login
            page.goto(url(day=day), wait_until='networkidle')
            page.locator('#dailyreport-done').fill(texts['draft'])
            with page.expect_navigation():
                page.locator('button[name=action][value=save]').click()
            page.wait_for_load_state('networkidle')
            ctx.close()
            ctx = browser.new_context(viewport={'width': width, 'height': 900}); recorder.install(ctx)
            page = login(ctx, 'EA')
            page.on('response', lambda r: server_errors.append({'url': r.url, 'status': r.status}) if r.status >= 500 else None)
            page.goto(url(day=day), wait_until='networkidle')
            case['relogin_draft'] = page.locator('#dailyreport-done').input_value()
            case['hscroll_form'] = page.evaluate(HSCROLL)
            # 2. two tabs on one draft: the second save conflicts and keeps its text
            second = ctx.new_page(); second.goto(page.url, wait_until='networkidle')
            page.locator('#dailyreport-done').fill(texts['tab1'])
            with page.expect_navigation():
                page.locator('button[name=action][value=save]').click()
            second.locator('#dailyreport-done').fill(texts['tab2'])
            with second.expect_navigation():
                second.locator('button[name=action][value=save]').click()
            second.wait_for_load_state('networkidle')
            case['conflict'] = {'shown': second.locator('[data-dailyreport-conflict]').count(),
                                'tab2_kept': texts['tab2'] in second.content(), 'tab1_saved': texts['tab1'] in second.content()}
            second.close()
            # 3. eight concurrent submit POSTs with one request key -> one version, one reviewer notification
            page.goto(url(day=day), wait_until='networkidle')
            page.locator('#dailyreport-done').fill(texts['submit'])
            submit = form_fields(page, 'form[data-dailyreport-form]', 'submit')
            case['submit_http'] = parallel_post(ctx, submit)
            case['after_submit'] = state(day, setup['task_title'] if offset == 0 else '')
            # 4. forged sesskey POST and GET change nothing
            forged = dict(submit, sesskey='0' * 10, requestkey=str(uuid.uuid4()), done=texts['forged'])
            case['forged_http'] = parallel_post(ctx, forged, 1)
            get = ctx.request.get(local_url(PAGE + '?' + urllib.parse.urlencode(forged)), max_redirects=0)
            case['get_http'] = get.status
            case['after_forged'] = state(day, setup['task_title'] if offset == 0 else '')
            # 5. today: changing the task after submission does not rewrite the stored snapshot
            if offset == 0:
                php('touch_task', company, setup['task'])
                case['after_touch'] = state(day, setup['task_title'])
            report = case['after_submit']['report']['uuid'] if case['after_submit']['report'] else ''
            # 6. reviewer returns with a reason: eight concurrent POSTs -> one decision, one author notification
            rctx = browser.new_context(viewport={'width': width, 'height': 900}); recorder.install(rctx)
            rp = login(rctx, 'MA')
            rp.on('response', lambda r: server_errors.append({'url': r.url, 'status': r.status}) if r.status >= 500 else None)
            rp.goto(url(tab='review', report=report), wait_until='networkidle')
            case['hscroll_card'] = rp.evaluate(HSCROLL)
            ret = dict(review_form(rp, 'return'), comment='DR %s reason' % token)
            case['return_http'] = parallel_post(rctx, ret)
            case['after_return'] = state(day)
            # 7. author reworks and resubmits in the browser -> version 2
            page.goto(url(day=day), wait_until='networkidle')
            with page.expect_navigation():
                page.locator('form:has(input[name=action][value=rework]) button[type=submit]').click()
            page.wait_for_load_state('networkidle')
            page.locator('#dailyreport-done').fill(texts['rework'])
            with page.expect_navigation():
                page.locator('button[name=action][value=submit]').click()
            page.wait_for_load_state('networkidle')
            case['after_rework'] = state(day)
            # 8. reviewer accepts version 2 with a rating: eight concurrent POSTs -> one accepted decision
            rp.goto(url(tab='review', report=report), wait_until='networkidle')
            accept = dict(review_form(rp, 'accept'), rating='positive', comment='')
            case['accept_version'] = accept.get('version')
            case['accept_http'] = parallel_post(rctx, accept)
            case['after_accept'] = state(day)
            # 9. foreign member and same-company non-reviewer cannot decide
            negatives = {}
            for label in ('EB', 'RA'):
                octx = browser.new_context(); op = login(octx, label)
                op.goto(local_url(base + '/my/'), wait_until='networkidle')
                forgedaccept = dict(accept, sesskey=op.evaluate('() => M.cfg.sesskey'), requestkey=str(uuid.uuid4()), rating='negative',
                                    comment='forged')
                negatives[label] = {'http': parallel_post(octx, forgedaccept, 1)}
                octx.close()
            case['negatives'] = negatives
            case['after_negatives'] = state(day)
            # 10. the reviewer's Communication Center notification links to the review tab
            rp.goto(local_url(base + '/local/kbacompany/communication_center.php?' + urllib.parse.urlencode(
                {'uuid': company, 'tab': 'notifications'})), wait_until='networkidle')
            case['notification_links'] = rp.locator('a[href*="daily_report.php"][href*="tab=review"]').count()
            case['ids'] = {k: f['actors'][k]['userid'] for k in ('EA', 'MA')}
            case['browser_events'] = recorder.events; case['server_errors'] = server_errors
            rctx.close(); ctx.close()
            result['cases'].append(case)
        result['status'] = 'PASS'
    except Exception as error:
        result['error'] = '%s: %s' % (type(error).__name__, str(error)[:400])
        try:
            if 'page' in locals() and not page.is_closed():
                page.screenshot(path=str(root / 'daily-reports-flow-failure.png'), full_page=True)
        except Exception as diagnostic:
            result['diagnostic_error'] = str(diagnostic)
    finally:
        try:
            php('shell', 'owned')
        except Exception:
            pass
        browser.close()
        dest = root / 'daily-reports-flow-result.json'; dest.write_text(json.dumps(result, indent=1, ensure_ascii=False)); dest.chmod(0o644)
print(json.dumps({'status': result['status'], 'cases': len(result['cases']), 'error': result.get('error')}))
sys.exit(0 if result['status'] == 'PASS' else 1)
