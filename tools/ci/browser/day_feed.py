"""Real Moodle acceptance of "Day feed -> report draft" (first scenario, task results only); synthetic data, loopback only.

Two cases (owned 1440, legacy 375), each on its own past day with two results submitted by employee EA through the task
workflow and a draft that already has text. Per case: the Communication Center "Day feed" opens on today; choosing the day
shows both results (time, task, result text, task link); an empty day shows the empty state; "Add to report" in the feed
copies the result into "Done" after the existing text and opens the report; back in the feed and after a reload the
first result shows "Added earlier" and the second keeps "Add"; the report link opens the selected date; another date
shows no marks and the day keeps them; in the open form an unsaved edit is kept and a repeated click adds nothing; the
second result is added; a double POST of an added result and an unavailable event write nothing; EB (company B) and
a forged sesskey change nothing; EA sends the report through the existing button; afterwards the feed keeps both marks
and offers no "Add" for that day; no horizontal scroll; no browser errors or HTTP 5xx.
Observations only -> EVIDENCE/day-feed-result.json; the verdict is computed by contract.day_feed_checks.
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
from concurrent.futures import ThreadPoolExecutor
from pathlib import Path

sys.path.insert(0, str(Path(__file__).resolve().parent))
from contract import DAY_FEED_CASES, local_url, require  # noqa: E402
from events import Recorder  # noqa: E402
from playwright.sync_api import sync_playwright  # noqa: E402

require(os.environ.get('BMS_CI_BROWSER') == '1', 'CI browser fixture only')
here = Path(__file__).resolve().parent
root = Path(os.environ['EVIDENCE']); core = os.environ['MOODLE_ROOT']; base = 'http://127.0.0.1:8080'
f = json.loads((root / 'actors.private.json').read_text())
company = f['companies']['A']['uuid']; other = f['companies']['B']['uuid']
CENTER = base + '/local/kbacompany/communication_center.php'
REPORT = base + '/local/kbacompany/daily_report.php'
HSCROLL = '() => document.documentElement.scrollWidth <= window.innerWidth'
OFFSETS = {'owned': 5, 'legacy': 6}
result = {'status': 'FAIL', 'cases': []}


def php(*args):
    process = subprocess.run(['php', str(here / 'ci_day_feed.php'), core, *args], capture_output=True, text=True)
    if process.returncode:
        diagnostic = next((line for line in process.stderr.splitlines() if line.startswith('CI day feed fixture: ')), '')
        raise RuntimeError(f'{args[0]} exited {process.returncode}. {diagnostic}'.rstrip())
    return json.loads(process.stdout.strip().splitlines()[-1])


def state(day):
    return php('state', company, str(f['actors']['EA']['userid']), day)


def login(ctx, label):
    a = f['actors'][label]; p = ctx.new_page(); p.set_default_timeout(30000)
    p.goto(local_url(base + '/login/index.php'), wait_until='networkidle')
    p.locator('#username').fill(a['username']); p.locator('#password').fill(a['password'])
    p.locator('#loginbtn').click(); p.wait_for_load_state('networkidle')
    return p


def center(uuid_=None, **params):
    return local_url(CENTER + '?' + urllib.parse.urlencode(dict({'uuid': uuid_ or company, 'tab': 'dayfeed'}, **params)))


class NoRedirect(urllib.request.HTTPRedirectHandler):
    def redirect_request(self, *args, **kwargs):
        return None


def cookie_of(ctx):
    """Session cookie header of a browser context (Playwright is called on the main thread only)."""
    return '; '.join('%s=%s' % (c['name'], c['value']) for c in ctx.cookies(local_url(base + '/')))


def post(ctx, fields, cookie=None):
    """One POST of this session to the report page (real HTTP, redirect not followed)."""
    cookie = cookie_of(ctx) if cookie is None else cookie
    req = urllib.request.Request(local_url(REPORT), data=urllib.parse.urlencode(fields).encode(), method='POST',
                                 headers={'Cookie': cookie, 'Content-Type': 'application/x-www-form-urlencoded'})
    try:
        with urllib.request.build_opener(NoRedirect).open(req, timeout=60) as resp:
            return resp.status
    except urllib.error.HTTPError as e:
        return e.code
    except Exception as e:  # recorded, judged by the contract
        return type(e).__name__


def feed_items(page):
    return page.locator('[data-dayfeed-event]').evaluate_all('''els => els.map(e => ({
        id: Number(e.dataset.dayfeedEvent), time: (e.querySelector('time') || {}).textContent || '',
        title: (e.querySelector('h3') || {}).textContent || '', text: e.innerText,
        task_href: (e.querySelector('h3 a') || {}).href || '', add: e.querySelectorAll('form[data-dayfeed-add]').length,
        added: e.querySelectorAll('[data-dayfeed-added]').length}))''')


def marks(page):
    """Which results offer "Add" and which show "Added earlier" (visible label and screen-reader hint), and the day."""
    return page.evaluate('''() => {
        const rows = [...document.querySelectorAll('[data-dayfeed-event]')];
        const ids = sel => rows.filter(e => e.querySelector(sel)).map(e => Number(e.dataset.dayfeedEvent));
        const labels = [...document.querySelectorAll('[data-dayfeed-added]')].map(el => {
            const hint = el.querySelector('.sr-only');
            const box = el.getBoundingClientRect();
            return {visible: el.innerText.replace(hint ? hint.innerText : '', '').trim(),
                    hint: hint ? hint.textContent.trim() : '', shown: box.width > 0 && box.height > 0,
                    hint_hidden: hint ? hint.getBoundingClientRect().width <= 1 : false};
        });
        return {day: (document.querySelector('#bms24-dayfeed-day') || {}).value || '',
                add: ids('form[data-dayfeed-add]'), added: ids('[data-dayfeed-added]'), labels};
    }''')


with sync_playwright() as pw:
    browser = pw.chromium.launch(headless=True, args=['--disable-dev-shm-usage'])
    try:
        for shell, width in DAY_FEED_CASES:
            php('shell', shell)
            token = uuid.uuid4().hex[:10]
            today = datetime.date.fromisoformat(php('today', company, str(f['actors']['EA']['userid']))['today'])
            day = (today - datetime.timedelta(days=OFFSETS[shell])).isoformat()
            setup = php('setup', company, str(f['actors']['EA']['userid']), str(f['actors']['MA']['userid']), day, token)
            require(setup.get('enabled') is True and len(setup.get('tasks') or []) == 2, 'setup failed')
            day = setup['day']; t1, t2 = setup['tasks']
            empty = (datetime.date.fromisoformat(setup['today']) - datetime.timedelta(days=40)).isoformat()
            case = {'shell': shell, 'width': width, 'day': day, 'today': setup['today'], 'empty_day': empty,
                    'tasks': setup['tasks'], 'pre': setup['pre'], 'edit': 'CI day feed %s unsaved edit' % token}
            recorder = Recorder(); server_errors = []
            ctx = browser.new_context(viewport={'width': width, 'height': 900}); recorder.install(ctx)
            page = login(ctx, 'EA')
            page.on('response', lambda r: server_errors.append({'url': r.url, 'status': r.status}) if r.status >= 500 else None)
            # 1. the section opens on today in the employee's timezone
            page.goto(center(), wait_until='networkidle')
            case['default_day'] = page.locator('#bms24-dayfeed-day').input_value()
            case['tab_active'] = page.locator('.bms-comm-ux-sections a[data-section-key=dayfeed][aria-current=page]').count()
            # 2. choosing the day through the form shows both results
            page.locator('#bms24-dayfeed-day').fill(day)
            with page.expect_navigation():
                page.locator('form[data-dayfeed-date] button[type=submit]').click()
            page.wait_for_load_state('networkidle')
            case['items'] = feed_items(page)
            case['hscroll_feed'] = page.evaluate(HSCROLL)
            # 3. an empty day
            page.locator('#bms24-dayfeed-day').fill(empty)
            with page.expect_navigation():
                page.locator('form[data-dayfeed-date] button[type=submit]').click()
            page.wait_for_load_state('networkidle')
            case['empty'] = {'items': page.locator('[data-dayfeed-event]').count(),
                             'state': page.locator('.bms24-comm-dayfeed .bms24-empty').count()}
            # 4. "Add to report" in the feed: the result goes after the existing text; the report opens
            page.goto(center(day=day), wait_until='networkidle')
            with page.expect_navigation():
                page.locator('[data-dayfeed-event="%d"] form[data-dayfeed-add] button' % t1['event']).click()
            page.wait_for_load_state('networkidle')
            case['landing'] = {'url': page.url, 'done': page.locator('#dailyreport-done').input_value(),
                               'notice': page.locator('.alert-success[role=status]').count()}
            case['after_add'] = state(day)
            # 5. back in the feed and after a page reload: the first result shows "Added earlier", the second keeps "Add"
            page.goto(center(day=day), wait_until='networkidle')
            case['feed_after_first'] = marks(page)
            page.reload(wait_until='networkidle')
            case['feed_after_reload'] = marks(page)
            case['hscroll_marks'] = page.evaluate(HSCROLL)
            # 6. the ordinary report link opens the selected date
            with page.expect_navigation():
                page.locator('.bms24-comm-dayfeed a.bms24-comm-dfreport').click()
            page.wait_for_load_state('networkidle')
            case['report_link'] = {'url': page.url, 'done': page.locator('#dailyreport-done').input_value()}
            # 7. changing the date: another day shows no marks; back on the day the mark is still there
            page.goto(center(day=day), wait_until='networkidle')
            for key, value in (('feed_other_day', empty), ('feed_back_to_day', day)):
                page.locator('#bms24-dayfeed-day').fill(value)
                with page.expect_navigation():
                    page.locator('form[data-dayfeed-date] button[type=submit]').click()
                page.wait_for_load_state('networkidle')
                case[key] = marks(page)
            # 8. in the open report form (unchanged buttons): unsaved edit kept, a repeated click adds nothing,
            #    then the second result goes after the edit
            page.goto(local_url(REPORT + '?' + urllib.parse.urlencode({'uuid': company, 'day': day})), wait_until='networkidle')
            done = page.locator('#dailyreport-done')
            done.fill(done.input_value() + '\n' + case['edit'])
            with page.expect_navigation():
                page.locator('button[name=addevent][value="%d"]' % t1['event']).click()
            page.wait_for_load_state('networkidle')
            case['after_repeat_form'] = state(day)
            with page.expect_navigation():
                page.locator('button[name=addevent][value="%d"]' % t2['event']).click()
            page.wait_for_load_state('networkidle')
            case['after_second'] = state(day)
            case['hscroll_report'] = page.evaluate(HSCROLL)
            # 9. the feed marks both; a double POST of an added result and an unavailable event write nothing
            page.goto(center(day=day), wait_until='networkidle')
            case['feed_after_second'] = marks(page)
            own = page.evaluate('() => M.cfg.sesskey')
            fields = {'uuid': company, 'day': day, 'source': 'feed', 'addevent': str(t1['event'])}
            cookie = cookie_of(ctx)
            with ThreadPoolExecutor(max_workers=2) as pool:
                case['repeat_http'] = list(pool.map(lambda _: post(None, dict(fields, sesskey=own), cookie), range(2)))
            case['unavailable_http'] = post(ctx, dict(fields, sesskey=own,
                                                      addevent=str(max(t['event'] for t in setup['tasks']) + 1000000)))
            case['after_repeat_feed'] = state(day)
            # 10. company B employee and a forged sesskey change nothing
            case['forged_http'] = post(ctx, dict(fields, sesskey='0' * 10))
            octx = browser.new_context(); op = login(octx, 'EB')
            op.goto(local_url(base + '/my/'), wait_until='networkidle')
            eb = op.evaluate('() => M.cfg.sesskey')
            case['foreign_http'] = {'company_a': post(octx, dict(fields, sesskey=eb)),
                                    'company_b': post(octx, dict(fields, uuid=other, sesskey=eb))}
            op.goto(center(other, day=day), wait_until='networkidle')
            case['foreign_feed_items'] = op.locator('[data-dayfeed-event]').count()
            octx.close()
            case['after_negatives'] = state(day)
            # 11. the employee sends the report through the existing button; the feed keeps the marks, offers no "Add"
            page.goto(local_url(REPORT + '?' + urllib.parse.urlencode({'uuid': company, 'day': day})), wait_until='networkidle')
            with page.expect_navigation():
                page.locator('button[name=action][value=submit]').click()
            page.wait_for_load_state('networkidle')
            case['after_submit'] = state(day)
            page.goto(center(day=day), wait_until='networkidle')
            case['after_submit_feed'] = dict(marks(page), items=page.locator('[data-dayfeed-event]').count(),
                                             sent_note=page.locator('.bms24-comm-dayfeed [role=note]').count())
            case['browser_events'] = recorder.events; case['server_errors'] = server_errors
            ctx.close()
            result['cases'].append(case)
        result['status'] = 'PASS'
    except Exception as error:
        result['error'] = '%s: %s' % (type(error).__name__, str(error)[:400])
        try:
            if 'page' in locals() and not page.is_closed():
                page.screenshot(path=str(root / 'day-feed-failure.png'), full_page=True)
        except Exception as diagnostic:
            result['diagnostic_error'] = str(diagnostic)
    finally:
        try:
            php('shell', 'owned')
        except Exception:
            pass
        browser.close()
        dest = root / 'day-feed-result.json'; dest.write_text(json.dumps(result, indent=1, ensure_ascii=False)); dest.chmod(0o644)
print(json.dumps({'status': result['status'], 'cases': len(result['cases']), 'error': result.get('error')}))
sys.exit(0 if result['status'] == 'PASS' else 1)
