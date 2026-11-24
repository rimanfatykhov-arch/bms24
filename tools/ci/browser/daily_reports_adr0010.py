"""Real Moodle acceptance of ADR-0010 daily report review rules; synthetic data only, loopback-only requests.

Organisation chart ROOT (MA manages) > TEAM (RA manages, EA works there). Two cases (owned 1440, legacy 375), each on its own
earlier day. Per case: MA, at the top of the chart, sees before sending that they review the report themselves, submits,
opens the self review from the own view and accepts it (stored 'self_top', journal "selfreview"); RA sees the org-chart
reviewer MA before sending, puts EA in copy and submits; EA (copy recipient, not a manager) finds the report under
"Copies to me", reads it without decision forms, and a forged decision changes nothing; RA (a unit manager) finds MA's
report under "All company reports" and reads it without decision forms. Screenshots of each screen; no horizontal scroll;
no browser errors or HTTP 5xx. Observations only -> EVIDENCE/daily-reports-adr0010-result.json; the verdict is computed by
contract.daily_reports_adr0010_checks.
"""
import datetime
import json
import os
import subprocess
import sys
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
CASES = [('owned', 1440, 3), ('legacy', 375, 4)]
HSCROLL = '() => document.documentElement.scrollWidth <= window.innerWidth'
DECIDE = 'form:has(input[name=action][value=accept])'
result = {'status': 'FAIL', 'cases': [], 'screenshots': []}
ids = {k: f['actors'][k]['userid'] for k in ('MA', 'RA', 'EA')}


def php(*args):
    out = subprocess.run(['php', str(here / 'ci_daily_reports_adr0010.php'), core, *args], check=True,
                         capture_output=True, text=True).stdout
    return json.loads(out.strip().splitlines()[-1])


def state(label, day):
    return php('state', company, str(ids[label]), day)


def url(**params):
    return local_url(PAGE + '?' + urllib.parse.urlencode(dict({'uuid': company}, **params)))


def shot(page, name):
    path = root / ('adr10-%s.png' % name)
    page.screenshot(path=str(path), full_page=True)
    result['screenshots'].append(path.name)


class NoRedirect(urllib.request.HTTPRedirectHandler):
    def redirect_request(self, *args, **kwargs):
        return None


def post_once(ctx, fields):
    cookie = '; '.join('%s=%s' % (c['name'], c['value']) for c in ctx.cookies(local_url(base + '/')))
    req = urllib.request.Request(local_url(PAGE + '?' + urllib.parse.urlencode({'uuid': company})),
                                 data=urllib.parse.urlencode(fields).encode(), method='POST',
                                 headers={'Cookie': cookie, 'Content-Type': 'application/x-www-form-urlencoded'})
    try:
        with urllib.request.build_opener(NoRedirect).open(req, timeout=60) as resp:
            return resp.status
    except urllib.error.HTTPError as e:
        return e.code


with sync_playwright() as pw:
    browser = pw.chromium.launch(headless=True, args=['--disable-dev-shm-usage'])
    contexts = []; branch = ''
    try:
        setup = php('setup', company, str(ids['MA']), str(ids['RA']), str(ids['EA']))
        require(setup.get('enabled') is True, 'setup failed')
        result['setup'] = {'enabled': True, 'today': setup['today']}
        branch = setup.get('branch', '')
        today = datetime.date.fromisoformat(setup['today'])
        recorder = Recorder(); server_errors = []

        def login(label, width):
            ctx = browser.new_context(viewport={'width': width, 'height': 900}); recorder.install(ctx); contexts.append(ctx)
            a = f['actors'][label]; p = ctx.new_page(); p.set_default_timeout(30000)
            p.on('response', lambda r: server_errors.append({'url': r.url, 'status': r.status}) if r.status >= 500 else None)
            p.goto(local_url(base + '/login/index.php'), wait_until='networkidle')
            p.locator('#username').fill(a['username']); p.locator('#password').fill(a['password'])
            p.locator('#loginbtn').click(); p.wait_for_load_state('networkidle')
            return ctx, p

        for shell, width, offset in CASES:
            subprocess.run(['php', str(here / 'ci_daily_reports.php'), core, 'shell', shell], check=True, capture_output=True)
            day = (today - datetime.timedelta(days=offset)).isoformat()
            token = uuid.uuid4().hex[:8]
            case = {'shell': shell, 'width': width, 'day': day, 'ids': ids, 'hscroll': {}}
            name = '%s-%s' % (shell, width)
            # 1. Top of the chart: MA sees the self review before sending, submits, reviews own report.
            mctx, mp = login('MA', width)
            mp.goto(url(day=day), wait_until='networkidle')
            case['top_preview'] = mp.locator('[data-dailyreport-reviewer]').first.get_attribute('data-dailyreport-reviewer')
            case['hscroll']['top_form'] = mp.evaluate(HSCROLL)
            shot(mp, name + '-top-form')
            mp.locator('#dailyreport-done').fill('A10 %s top' % token)
            with mp.expect_navigation():
                mp.locator('button[name=action][value=submit]').click()
            mp.wait_for_load_state('networkidle')
            case['top_after_submit'] = state('MA', day)
            report = (case['top_after_submit'].get('report') or {}).get('uuid', '')
            selflink = mp.locator('a[href*="tab=review"][href*="report=%s"]' % report)
            case['top_selflink'] = selflink.count()
            with mp.expect_navigation():
                selflink.first.click()
            mp.wait_for_load_state('networkidle')
            case['top_card'] = {'badges': mp.locator('[data-dailyreport-selfreview]').count(),
                                'decide_forms': mp.locator(DECIDE).count()}
            case['hscroll']['top_card'] = mp.evaluate(HSCROLL)
            shot(mp, name + '-top-card')
            form = mp.locator(DECIDE).first
            form.locator('input[name=rating][value=positive]').check()
            with mp.expect_navigation():
                form.locator('button[type=submit]').click()
            mp.wait_for_load_state('networkidle')
            case['top_after_decide'] = state('MA', day)
            # 2. Org chart: RA sees MA as reviewer before sending, puts EA in copy, submits.
            rctx, rp = login('RA', width)
            rp.goto(url(day=day), wait_until='networkidle')
            preview = rp.locator('[data-dailyreport-reviewer]').first
            case['lead_preview'] = {'source': preview.get_attribute('data-dailyreport-reviewer'),
                                    'reviewerid': int(preview.get_attribute('data-dailyreport-reviewerid') or 0),
                                    'names_reviewer': 'Synthetic MA' in preview.inner_text()}
            rp.locator('#dailyreport-cc').select_option([str(ids['EA'])])
            case['hscroll']['lead_form'] = rp.evaluate(HSCROLL)
            shot(rp, name + '-lead-form')
            rp.locator('#dailyreport-done').fill('A10 %s lead' % token)
            with rp.expect_navigation():
                rp.locator('button[name=action][value=submit]').click()
            rp.wait_for_load_state('networkidle')
            case['lead_after_submit'] = state('RA', day)
            leadreport = (case['lead_after_submit'].get('report') or {}).get('uuid', '')
            leadversion = ((case['lead_after_submit'].get('versions') or [{}])[-1]).get('uuid', '')
            # 3. Copy recipient: reads under "Copies to me", no decision forms; a forged decision changes nothing.
            ectx, ep = login('EA', width)
            ep.goto(url(tab='review', queue='copies'), wait_until='networkidle')
            case['copy_queue_links'] = ep.locator('a[href*="report=%s"]' % leadreport).count()
            ep.goto(url(tab='review', report=leadreport), wait_until='networkidle')
            case['copy_card'] = {'readonly_note': ep.locator('[data-dailyreport-readonly=copy_readonly]').count(),
                                 'decide_forms': ep.locator(DECIDE).count(),
                                 'text_shown': ('A10 %s lead' % token) in ep.content()}
            case['hscroll']['copy_card'] = ep.evaluate(HSCROLL)
            shot(ep, name + '-copy-card')
            case['copy_forged_http'] = post_once(ectx, {'uuid': company, 'sesskey': ep.evaluate('() => M.cfg.sesskey'),
                                                        'tab': 'review', 'report': leadreport, 'version': leadversion,
                                                        'action': 'accept', 'rating': 'positive', 'comment': '',
                                                        'requestkey': str(uuid.uuid4())})
            case['lead_after_forged'] = state('RA', day)
            # 4. Manager: RA reads MA's report under "All company reports", without decision forms.
            rp.goto(url(tab='review', queue='company'), wait_until='networkidle')
            case['company_queue_links'] = rp.locator('a[href*="report=%s"]' % report).count()
            shot(rp, name + '-company-queue')
            rp.goto(url(tab='review', report=report), wait_until='networkidle')
            case['manager_card'] = {'readonly_note': rp.locator('[data-dailyreport-readonly=manager_readonly]').count(),
                                    'decide_forms': rp.locator(DECIDE).count()}
            case['hscroll']['manager_card'] = rp.evaluate(HSCROLL)
            shot(rp, name + '-manager-card')
            for ctx in (mctx, rctx, ectx):
                ctx.close()
            contexts.clear()
            result['cases'].append(case)
        result['browser_events'] = recorder.events; result['server_errors'] = server_errors
        result['status'] = 'PASS'
    except Exception as error:
        result['error'] = '%s: %s' % (type(error).__name__, str(error)[:400])
        for ctx in contexts:
            try:
                for p in ctx.pages:
                    p.screenshot(path=str(root / 'adr10-failure.png'), full_page=True)
            except Exception as diagnostic:
                result['diagnostic_error'] = str(diagnostic)
    finally:
        try:
            subprocess.run(['php', str(here / 'ci_daily_reports.php'), core, 'shell', 'owned'], check=True, capture_output=True)
            if branch:
                result['teardown'] = php('teardown', company, branch)
        except Exception as cleanup:
            result['cleanup_error'] = str(cleanup)[:200]
        browser.close()
        dest = root / 'daily-reports-adr0010-result.json'
        dest.write_text(json.dumps(result, indent=1, ensure_ascii=False)); dest.chmod(0o644)
print(json.dumps({'status': result['status'], 'cases': len(result['cases']), 'error': result.get('error')}))
sys.exit(0 if result['status'] == 'PASS' else 1)
