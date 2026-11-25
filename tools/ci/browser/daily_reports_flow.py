"""Real Moodle acceptance of saved daily reports R1/R2 (ADR-0009); synthetic data only, loopback-only requests.

Two cases (owned 1440 today, legacy 375 yesterday). Employee EA, reviewer MA (battle plan reviewer), foreign EB, same-company
non-reviewer RA. Per case: draft survives a new login; two tabs on one draft give a conflict that keeps the second tab's text;
eight concurrent submit POSTs (one request key) create one version and one reviewer notification; a forged sesskey POST and
a GET change nothing; (today) the submitted snapshot keeps the task title after the task changes; eight concurrent "return"
POSTs give one decision and one author notification; rework + resubmit in the browser create version 2; eight concurrent
"accept" POSTs give one accepted decision bound to version 2; EB and RA cannot decide; the reviewer's Communication Center
notification links to the review tab; (R3) author and reviewer write one report discussion, readers' and foreign posts
store nothing; no horizontal scroll at the case width; no browser errors or HTTP 5xx.
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
            # 11. R4 team for the day: the administrator-manager sees every company A member for this day, once, with the
            # accepted report linked; another company's member is absent; no horizontal scroll at the case width.
            rp.goto(url(tab='review', queue='team', day=day), wait_until='networkidle')
            case['team'] = {
                'rows': rp.evaluate('() => Array.from(document.querySelectorAll("[data-dailyreport-team-user]")).map('
                                    'e => [Number(e.dataset.dailyreportTeamUser), e.dataset.dailyreportTeamState])'),
                'counts': rp.evaluate('() => Object.fromEntries(Array.from(document.querySelectorAll('
                                      '"[data-dailyreport-team-count]")).map(e => [e.dataset.dailyreportTeamCount, '
                                      'Number(e.textContent.trim())]))'),
                'filters': rp.locator('form[data-dailyreport-team-filters]').count(),
                'report_links': rp.locator('a[href*="report=%s"]' % report).count() if report else 0,
                'hscroll': rp.evaluate(HSCROLL)}
            # An ordinary employee asking for the same list gets their own tab, not the list.
            page.goto(url(tab='review', queue='team', day=day), wait_until='networkidle')
            case['team_employee_filters'] = page.locator('form[data-dailyreport-team-filters]').count()
            # 12. Release #168 + #173. RA keeps a private draft for the day; a synthetic unit makes MA the manager of EA.
            radraft = 'DR %s ra-draft' % token
            dctx = browser.new_context(viewport={'width': width, 'height': 900}); recorder.install(dctx)
            dp = login(dctx, 'RA')
            dp.on('response', lambda r: server_errors.append({'url': r.url, 'status': r.status}) if r.status >= 500 else None)
            dp.goto(url(day=day), wait_until='networkidle')
            dp.locator('#dailyreport-done').fill(radraft)
            with dp.expect_navigation():
                dp.locator('button[name=action][value=save]').click()
            dp.wait_for_load_state('networkidle')
            dctx.close()
            php('orgchart', company, str(f['actors']['MA']['userid']), str(f['actors']['EA']['userid']))
            rows_js = ('() => Array.from(document.querySelectorAll("[data-dailyreport-team-user]")).map('
                       'e => [Number(e.dataset.dailyreportTeamUser), e.dataset.dailyreportTeamState])')
            scopes = {}
            for scope in ('team', 'company'):
                rp.goto(url(tab='review', queue='team', day=day, scope=scope), wait_until='networkidle')
                scopes[scope] = {'rows': rp.evaluate(rows_js),
                                 'options': rp.evaluate('() => Array.from(document.querySelectorAll('
                                                        '"#dailyreport-team-scope option")).map(o => o.value)'),
                                 'selected': rp.evaluate('() => (document.querySelector("#dailyreport-team-scope") || {}).value'),
                                 'draft_leak': radraft in rp.content(), 'hscroll': rp.evaluate(HSCROLL)}
            # Open the sent report from the list (company scope) with a real click.
            link = rp.locator('a[href*="report=%s"]' % report).first
            opened = {'link': link.count()}
            if opened['link']:
                with rp.expect_navigation():
                    link.click()
                rp.wait_for_load_state('networkidle')
                opened.update(url_report=('report=' + report) in rp.url,
                              versions=rp.locator('[data-dailyreport-version]').count(), draft_leak=radraft in rp.content())
            case['team_scopes'] = scopes; case['team_open'] = opened
            # 13. Monthly summary (#173): the month of the case day, EA's accepted cell links to the card; previous month by
            # link; "my subordinates" through the filter form; the cell opens the card; RA's draft never appears.
            month = day[:7]
            rp.goto(url(tab='summary', month=month), wait_until='networkidle')
            users_js = ('() => Array.from(document.querySelectorAll("[data-dailyreport-summary-user]")).map('
                        'e => Number(e.dataset.dailyreportSummaryUser))')
            cell = rp.locator('[data-dailyreport-summary-user="%d"] a[href*="report=%s"]' % (f['actors']['EA']['userid'], report))
            summ = {'month': rp.evaluate('() => (document.querySelector("[data-dailyreport-summary-month]") || {dataset: {}})'
                                         '.dataset.dailyreportSummaryMonth'),
                    'filters': rp.locator('form[data-dailyreport-summary-filter]').count(),
                    'users_all': rp.evaluate(users_js), 'cell_links': cell.count(),
                    'cell': cell.first.evaluate('a => [a.closest("td").dataset.dailyreportSummaryCell, '
                                                'a.closest("td").dataset.dailyreportSummaryRating]') if cell.count() else None,
                    'draft_leak': radraft in rp.content()}
            with rp.expect_navigation():
                rp.locator('a[data-dailyreport-summary-prev]').first.click()
            rp.wait_for_load_state('networkidle')
            summ['prev_month'] = rp.evaluate('() => (document.querySelector("[data-dailyreport-summary-month]") || '
                                             '{dataset: {}}).dataset.dailyreportSummaryMonth')
            rp.goto(url(tab='summary', month=month), wait_until='networkidle')
            rp.locator('#dailyreport-summary-scope').select_option('mine')
            with rp.expect_navigation():
                rp.locator('form[data-dailyreport-summary-filter] button[type=submit]').click()
            rp.wait_for_load_state('networkidle')
            summ['users_mine'] = rp.evaluate(users_js)
            summ['scope_kept'] = rp.evaluate('() => document.querySelector("#dailyreport-summary-scope").value')
            summ['hscroll'] = rp.evaluate(HSCROLL)
            cell = rp.locator('[data-dailyreport-summary-user="%d"] a[href*="report=%s"]' % (f['actors']['EA']['userid'], report))
            if cell.count():
                with rp.expect_navigation():
                    cell.first.click()
                rp.wait_for_load_state('networkidle')
                summ['card'] = {'url_report': ('report=' + report) in rp.url,
                                'versions': rp.locator('[data-dailyreport-version]').count()}
            case['summary'] = summ
            # 14. An ordinary employee (EA) and a member of another company (EB) get neither list nor summary.
            denied = {}
            page.goto(url(tab='summary', month=month), wait_until='networkidle')
            denied['EA'] = {'summary': page.locator('[data-dailyreport-summary]').count(),
                            'summary_tab': page.locator('a[href*="tab=summary"]').count()}
            # The denials below are expected HTTP 404 pages: their console lines go to a separate recorder (as in
            # daily_reports_nav.py), so the case's no-browser-errors check keeps judging the pages that must work.
            deniedrecorder = Recorder()
            ectx = browser.new_context(viewport={'width': width, 'height': 900}); deniedrecorder.install(ectx)
            ep = login(ectx, 'EB')
            ep.on('response', lambda r: server_errors.append({'url': r.url, 'status': r.status}) if r.status >= 500 else None)
            for key, params in (('team', {'tab': 'review', 'queue': 'team', 'day': day, 'scope': 'company'}),
                                ('summary', {'tab': 'summary', 'month': month}), ('card', {'tab': 'review', 'report': report})):
                resp = ep.goto(url(**params), wait_until='networkidle')
                html = ep.content()
                denied['EB_' + key] = {'http': resp.status if resp else None,
                                       'team': ep.locator('[data-dailyreport-team-user]').count(),
                                       'summary': ep.locator('[data-dailyreport-summary]').count(),
                                       'versions': ep.locator('[data-dailyreport-version]').count(),
                                       'leak': radraft in html or texts['rework'] in html}
            ectx.close()
            denied['browser_events'] = deniedrecorder.events
            case['release_denied'] = denied
            php('orgclear', company)
            case['ids'] ={k: f['actors'][k]['userid'] for k in ('EA', 'MA', 'RA', 'EB')}
            # 15. R3 report discussion: the author writes from the own day view (markup stays text), the reviewer reads it
            # on the card and answers; a forged sesskey, a same-company non-reader and a foreign member store nothing.
            disc = {'author': 'DR %s discussion <b>author</b>' % token, 'reviewer': 'DR %s discussion reviewer' % token,
                    'forged': 'DR %s discussion forged' % token, 'RA': 'DR %s discussion RA' % token,
                    'EB': 'DR %s discussion EB' % token}
            page.goto(url(day=day), wait_until='networkidle')
            case['discussion_author_composer'] = page.locator('form[data-dailyreport-discussion-composer]').count()
            authorpost = form_fields(page, 'form[data-dailyreport-discussion-composer]')
            page.locator('#dailyreport_discussion_message').fill(disc['author'])
            with page.expect_navigation():
                page.locator('form[data-dailyreport-discussion-composer] button[type=submit]').click()
            page.wait_for_load_state('networkidle')
            case['discussion_forged_http'] = parallel_post(ctx, dict(authorpost, sesskey='0' * 10, message=disc['forged']), 1)
            rp.goto(url(tab='review', report=report), wait_until='networkidle')
            section = rp.locator('[data-dailyreport-discussion]')
            case['discussion_reviewer_sections'] = section.count()
            case['discussion_reviewer_sees_author'] = section.count() == 1 and disc['author'] in section.inner_text()
            case['discussion_injected_tags'] = rp.locator('[data-dailyreport-discussion-message] b').count()
            rp.locator('#dailyreport_discussion_message').fill(disc['reviewer'])
            with rp.expect_navigation():
                rp.locator('form[data-dailyreport-discussion-composer] button[type=submit]').click()
            rp.wait_for_load_state('networkidle')
            case['hscroll_discussion'] = rp.evaluate(HSCROLL)
            dneg = {}
            for label in ('EB', 'RA'):
                octx = browser.new_context(); op = login(octx, label)
                op.goto(local_url(base + '/my/'), wait_until='networkidle')
                dneg[label] = {'http': parallel_post(octx, dict(authorpost, sesskey=op.evaluate('() => M.cfg.sesskey'),
                                                                message=disc[label]), 1)}
                octx.close()
            case['discussion_negatives'] = dneg
            case['discussion_texts'] = disc
            case['after_discussion'] = state(day)
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
        try:
            php('orgclear', company)
        except Exception:
            pass
        browser.close()
        dest = root / 'daily-reports-flow-result.json'; dest.write_text(json.dumps(result, indent=1, ensure_ascii=False)); dest.chmod(0o644)
print(json.dumps({'status': result['status'], 'cases': len(result['cases']), 'error': result.get('error')}))
sys.exit(0 if result['status'] == 'PASS' else 1)
