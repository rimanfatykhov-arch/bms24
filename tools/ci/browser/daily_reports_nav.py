"""Real Moodle acceptance of the daily reports R0 entry point (ADR-0009); synthetic data only, loopback-only requests.

Four cases: owned/legacy shell x 1440 EN / 375 RU. For each case the Task Center list is opened (report-first button,
short phone label, full accessible name) and the daily report page is opened (own URL, "My report" tab, page title).
In the owned shell the workspace menu item must sit directly after Tasks and be the only current item; on phones the
mobile menu shows the short label while the accessible name stays the full one. Neither page may scroll horizontally.
A foreign-company actor must not get the report page of company A.
Observations only -> EVIDENCE/daily-reports-nav-result.json; the verdict is computed by contract.daily_reports_nav_checks.
"""
import json
import os
import subprocess
import sys
import urllib.parse
from pathlib import Path

sys.path.insert(0, str(Path(__file__).resolve().parent))
from contract import DAILY_REPORT_LABELS, local_url, require  # noqa: E402
from events import Recorder  # noqa: E402
from playwright.sync_api import sync_playwright  # noqa: E402

require(os.environ.get('BMS_CI_BROWSER') == '1', 'CI browser fixture only')
here = Path(__file__).resolve().parent
root = Path(os.environ['EVIDENCE']); core = os.environ['MOODLE_ROOT']; base = 'http://127.0.0.1:8080'
f = json.loads((root / 'actors.private.json').read_text())
company = f['companies']['A']['uuid']
CASES = [('owned', 1440, 'en'), ('owned', 375, 'ru'), ('legacy', 1440, 'en'), ('legacy', 375, 'ru')]
result = {'status': 'FAIL', 'company': company, 'cases': [], 'foreign': {}}

HSCROLL = '() => ({scroll: document.documentElement.scrollWidth, inner: window.innerWidth})'
LINK_INFO = """a => { const r = a.getBoundingClientRect(); const u = new URL(a.href);
  const box = s => { const e = a.querySelector(s); if (!e) return null; const b = e.getBoundingClientRect();
    return {w: Math.round(b.width), h: Math.round(b.height)}; };
  // Text a sighted user sees: text nodes whose element is rendered larger than the 1px visually-hidden clip.
  const seen = []; const walk = document.createTreeWalker(a, NodeFilter.SHOW_TEXT); let node;
  while ((node = walk.nextNode())) { const el = node.parentElement, b = el.getBoundingClientRect(), cs = getComputedStyle(el);
    if (node.textContent.trim() && b.width > 1 && b.height > 1 && cs.visibility !== 'hidden') seen.push(node.textContent.trim()); }
  const span = s => { const e = a.querySelector(s); if (!e) return null; const cs = getComputedStyle(e);
    return {text: e.textContent.trim(), aria_hidden: !!e.closest('[aria-hidden="true"]'), display: cs.display,
            visibility: cs.visibility}; };
  return {path: u.pathname, uuid: u.searchParams.get('uuid'), params: [...u.searchParams.keys()].sort(),
    text: seen.join(' '), raw_text: a.innerText.replace(/\\s+/g, ' ').trim(), aria_label: a.getAttribute('aria-label'),
    aria_current: a.getAttribute('aria-current'), visible: r.width > 0 && r.height > 0,
    full_box: box('.bms24-navlabel-full'), short_box: box('.bms24-navlabel-short'),
    full_span: span('.bms24-navlabel-full'), short_span: span('.bms24-navlabel-short')}; }"""
NAV_ORDER = """scope => [...scope.querySelectorAll('a.bms24-navitem')].map(a => ({
  path: new URL(a.href).pathname.split('/').pop(), current: a.getAttribute('aria-current')}))"""


def php(*args):
    out = subprocess.run(['php', str(here / 'ci_task_open.php'), core, *args], check=True,
                         capture_output=True, text=True).stdout
    return json.loads(out.strip().splitlines()[-1])


def login(ctx, label):
    a = f['actors'][label]; p = ctx.new_page(); p.set_default_timeout(30000)
    p.goto(local_url(base + '/login/index.php'), wait_until='networkidle')
    p.locator('#username').fill(a['username']); p.locator('#password').fill(a['password'])
    p.locator('#loginbtn').click(); p.wait_for_load_state('networkidle')
    return p


def url(path, **params):
    return local_url(base + path + '?' + urllib.parse.urlencode(dict(uuid=company, **params)))


def menu(page, width, full):
    """Visible workspace menu of the owned shell: the sidebar on desktop, the opened mobile menu on phones."""
    if page.locator('[data-bms24-owned-shell]').count() == 0:
        return {'present': False}
    if width < 861:
        summary = page.locator('.bms24-mobilemenu > summary')
        if summary.count() and summary.is_visible():
            summary.click()
        scope = page.locator('.bms24-mobilemenu nav').first
    else:
        scope = page.locator('aside.bms24-sidebar').first
    items = scope.locator('a.bms24-navitem[href*="daily_report.php"]')
    visible = [i for i in range(items.count()) if items.nth(i).is_visible()]
    out = {'present': True, 'scope_visible': scope.is_visible(), 'items': items.count(), 'visible_items': len(visible),
           'order': scope.evaluate(NAV_ORDER)}
    if visible:
        link = items.nth(visible[0])
        out['item'] = link.evaluate(LINK_INFO)
        # Accessible name from Chromium's accessibility tree: the short label is aria-hidden, the full name names the link.
        out['item']['aria_snapshot'] = link.aria_snapshot()
    out['accessible_full'] = scope.get_by_role('link', name=full, exact=True).count()
    return out


try:
    with sync_playwright() as pw:
        browser = pw.chromium.launch(headless=True, args=['--disable-dev-shm-usage'])
        for shell, width, lang in CASES:
            php('shell', shell)
            language = php('lang', lang, str(f['actors']['MA']['userid']))
            recorder = Recorder(); server_errors = []
            ctx = browser.new_context(viewport={'width': width, 'height': 900 if width > 500 else 812})
            recorder.install(ctx)
            page = login(ctx, 'MA')
            page.on('response', lambda r: server_errors.append({'url': r.url, 'status': r.status}) if r.status >= 500 else None)
            case = {'shell': shell, 'width': width, 'lang': lang, 'language_installed': language.get('installed')}
            # ---- Task Center list: report-first button
            response = page.goto(url('/local/kbacompany/tasks.php', scope='my'), wait_until='networkidle')
            buttons = page.locator('a.btn[href*="daily_report.php"]')
            case['tasks'] = {'http': response.status if response else None, 'buttons': buttons.count(),
                             'owned_shell': page.locator('[data-bms24-owned-shell]').count() > 0,
                             'hscroll': page.evaluate(HSCROLL)}
            if buttons.count():
                case['tasks']['button'] = buttons.first.evaluate(LINK_INFO)
                case['tasks']['button']['title'] = buttons.first.get_attribute('title')
                case['tasks']['button']['aria_snapshot'] = buttons.first.aria_snapshot()
                case['tasks']['button']['accessible'] = page.get_by_role(
                    'link', name=case['tasks']['button']['aria_label'] or '-', exact=True).count()
            # ---- the button really opens the report page
            if buttons.count() and buttons.first.is_visible():
                with page.expect_navigation():
                    buttons.first.click()
                page.wait_for_load_state('networkidle')
                case['clicked_path'] = urllib.parse.urlparse(page.url).path
            # ---- daily report page
            response = page.goto(url('/local/kbacompany/daily_report.php'), wait_until='networkidle')
            tabs = page.locator('nav[aria-label="%s"] a[aria-current="page"]' % DAILY_REPORT_LABELS[lang]['tabs'])
            case['report'] = {'http': response.status if response else None,
                              'path': urllib.parse.urlparse(page.url).path, 'title': page.title(),
                              'h1': [t.strip() for t in page.locator('h1').all_inner_texts()],
                              'current_tabs': [t.strip() for t in tabs.all_inner_texts()],
                              'current_tab_paths': [urllib.parse.urlparse(h or '').path for h in
                                                    tabs.evaluate_all('els => els.map(e => e.href)')],
                              'owned_shell': page.locator('[data-bms24-owned-shell]').count() > 0,
                              'hscroll': page.evaluate(HSCROLL)}
            case['report']['menu'] = menu(page, width, DAILY_REPORT_LABELS[lang]['nav'])
            case['report']['hscroll_after_menu'] = page.evaluate(HSCROLL)
            case['browser_events'] = recorder.events
            case['server_errors'] = server_errors
            result['cases'].append(case)
            ctx.close()
        # ---- foreign company actor must not get company A's report page
        php('shell', 'owned'); php('lang', 'en', str(f['actors']['MA']['userid']))
        recorder = Recorder(); other = browser.new_context(); recorder.install(other)
        op = login(other, 'EB')
        denied = op.goto(url('/local/kbacompany/daily_report.php'), wait_until='networkidle')
        result['foreign'] = {'http': denied.status if denied else None,
                             'tabs': op.locator('nav[aria-label="%s"] a' % DAILY_REPORT_LABELS['en']['tabs']).count(),
                             'day_form': op.locator('#dailyreport-day').count()}
        other.close()
        browser.close()
    result['status'] = 'PASS'
except Exception as error:
    result['error'] = '%s: %s' % (type(error).__name__, str(error)[:400])
finally:
    try:
        php('shell', 'owned'); php('lang', 'en', str(f['actors']['MA']['userid']))
    except Exception:
        pass
    dest = root / 'daily-reports-nav-result.json'
    dest.write_text(json.dumps(result, indent=1, ensure_ascii=False)); dest.chmod(0o644)
print(json.dumps({'status': result['status'], 'cases': len(result['cases']), 'error': result.get('error')}))
sys.exit(0 if result['status'] == 'PASS' else 1)
