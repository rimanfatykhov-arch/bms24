"""Real Moodle acceptance of the DOC-W2 Office workspace (office.php); synthetic data only, loopback-only requests.

1. Switch off: MA (manager, company A) gets no workspace on office.php and no Office item in the owned menu.
2. Switch on, seeded A/B data. Four cases owned/legacy x 1440 EN / 375 RU for EA (employee, company A): every counter
   on the page equals office_workspace_service::overview() for EA (ground truth from PHP), the Office item is the only
   current item of the owned menu, the six planned sections are text without links, active sections open their
   canonical pages, company B objects and private drafts never appear, no horizontal scroll, no browser errors or 5xx.
   One full-page screenshot per case goes to the uploaded logs (logs/screens/office-*.png).
3. MA sees the manager view (create action) with its own ground-truth counters.
4. EB (company B) gets no workspace for company A and none of A's titles; on company B EB sees B's counters only.
5. EA is revoked in company A in the same browser context: the next request shows no workspace; then reopened.
Observations only -> EVIDENCE/office-workspace-result.json; the verdict is computed by contract.office_workspace_checks.
"""
import json
import os
import subprocess
import sys
import urllib.parse
from pathlib import Path

sys.path.insert(0, str(Path(__file__).resolve().parent))
from contract import OFFICE_CASES, local_url, require  # noqa: E402
from events import Recorder  # noqa: E402
from playwright.sync_api import sync_playwright  # noqa: E402

require(os.environ.get('BMS_CI_BROWSER') == '1', 'CI browser fixture only')
here = Path(__file__).resolve().parent
root = Path(os.environ['EVIDENCE']); core = os.environ['MOODLE_ROOT']; base = 'http://127.0.0.1:8080'
screens = root.parent / 'logs' / 'screens'
screens.mkdir(parents=True, exist_ok=True)
f = json.loads((root / 'actors.private.json').read_text())
A = f['companies']['A']['uuid']; B = f['companies']['B']['uuid']
result = {'status': 'FAIL', 'company': A, 'cases': []}
HSCROLL = '() => ({scroll: document.documentElement.scrollWidth, inner: window.innerWidth})'
PAGE_STATE = """() => {
  const ws = document.querySelector('[data-office-workspace]');
  const counts = {}; document.querySelectorAll('[data-office-count]').forEach(e => { counts[e.dataset.officeCount] = Number(e.dataset.value); });
  const nav = document.querySelector('[data-office-nav]');
  const planned = nav ? [...nav.querySelectorAll('[data-office-planned]')].map(e => ({key: e.dataset.officePlanned,
      tag: e.tagName.toLowerCase(), inlink: !!e.closest('a'), href: e.getAttribute('href'), disabled: e.getAttribute('aria-disabled')})) : [];
  const active = nav ? [...nav.querySelectorAll('a')].map(a => ({path: new URL(a.href).pathname.split('/').pop(),
      uuid: new URL(a.href).searchParams.get('uuid'), href: a.href, current: a.getAttribute('aria-current')})) : [];
  return {workspace: !!ws, counts, planned, active, h1: [...document.querySelectorAll('h1')].map(h => h.textContent.trim()),
      empty: [...document.querySelectorAll('[data-office-empty]')].map(e => e.dataset.officeEmpty),
      create: document.querySelectorAll('a[href*="documents.php"][href*="view=create"]').length,
      text: document.body.innerText};
}"""
MENU = """() => { const scopes = [...document.querySelectorAll('aside.bms24-sidebar, .bms24-mobilemenu nav')];
  const links = scopes.flatMap(s => [...s.querySelectorAll('a.bms24-navitem')]);
  return links.filter(a => new URL(a.href).pathname.endsWith('/office.php')).map(a => ({
      uuid: new URL(a.href).searchParams.get('uuid'), current: a.getAttribute('aria-current'),
      visible: a.getBoundingClientRect().width > 0})); }"""


def php(*args):
    out = subprocess.run(['php', str(here / 'ci_office_workspace.php'), *args], check=True,
                         capture_output=True, text=True).stdout
    return json.loads(out.strip().splitlines()[-1])


def helper(*args):
    out = subprocess.run(['php', str(here / 'ci_task_open.php'), core, *args], check=True,
                         capture_output=True, text=True).stdout
    return json.loads(out.strip().splitlines()[-1])


def login(ctx, label):
    a = f['actors'][label]; p = ctx.new_page(); p.set_default_timeout(30000)
    p.goto(local_url(base + '/login/index.php'), wait_until='networkidle')
    p.locator('#username').fill(a['username']); p.locator('#password').fill(a['password'])
    p.locator('#loginbtn').click(); p.wait_for_load_state('networkidle')
    return p


def url(path, company=A, **params):
    return local_url(base + path + '?' + urllib.parse.urlencode(dict(uuid=company, **params)))


def visit(page, company=A, width=1440):
    response = page.goto(url('/local/kbacompany/office.php', company), wait_until='networkidle')
    state = page.evaluate(PAGE_STATE)
    state['http'] = response.status if response else None
    state['owned_shell'] = page.locator('[data-bms24-owned-shell]').count() > 0
    if state['owned_shell'] and width < 861:
        button = page.locator('.bms24-menubutton')
        if button.count() and button.is_visible():
            button.click(); page.wait_for_timeout(300)
    state['menu'] = page.evaluate(MENU)
    state['hscroll'] = page.evaluate(HSCROLL)
    return state


def strip(state, private):
    """Keep only whether each private title leaked; never store page text in the evidence."""
    text = state.pop('text', '')
    state['leaked'] = [t for t in private if t and t in text]
    return state


try:
    with sync_playwright() as pw:
        browser = pw.chromium.launch(headless=True, args=['--disable-dev-shm-usage'])
        helper('shell', 'owned'); helper('lang', 'en', str(f['actors']['MA']['userid']))
        # ---- 1. switch off
        result['switch_off'] = php('switch', '0')
        ctx = browser.new_context(viewport={'width': 1440, 'height': 900}); rec = Recorder(); rec.install(ctx)
        page = login(ctx, 'MA')
        off = visit(page); off.pop('text', None)
        page.goto(url('/local/kbacompany/documents.php'), wait_until='networkidle')
        off['menu_on_documents'] = page.evaluate(MENU)
        result['off'] = off
        ctx.close()
        # ---- 2. switch on, seeded data, four cases for EA
        result['switch_on'] = php('switch', '1')
        seed = php('seed')
        result['titles'] = seed['titles']
        private = seed['private']
        result['model'] = {'EA': php('model', 'A', 'EA')['counts'], 'MA': php('model', 'A', 'MA')['counts'],
                           'EB': php('model', 'B', 'EB')['counts']}
        for shell, width, lang in OFFICE_CASES:
            helper('shell', shell); language = helper('lang', lang, str(f['actors']['EA']['userid']))
            ctx = browser.new_context(viewport={'width': width, 'height': 900 if width > 500 else 812})
            rec = Recorder(); rec.install(ctx); errors = []
            page = login(ctx, 'EA')
            page.on('response', lambda r: errors.append({'url': r.url, 'status': r.status}) if r.status >= 500 else None)
            case = {'shell': shell, 'width': width, 'lang': lang, 'language_installed': language.get('installed')}
            case['page'] = strip(visit(page, A, width), private)
            page.screenshot(path=str(screens / ('office-%s-%s-%s.png' % (shell, width, lang))), full_page=True)
            if shell == 'owned' and width == 1440:
                # Every active section opens its canonical page for EA (status < 400, no workspace error).
                landing = []
                for link in case['page']['active']:
                    resp = page.goto(local_url(link['href']), wait_until='networkidle')
                    landing.append({'path': urllib.parse.urlparse(page.url).path.split('/')[-1],
                                    'http': resp.status if resp else None,
                                    'workspace_error': page.locator('[data-office-workspace]').count() == 0
                                    and link['path'] == 'office.php'})
                case['landing'] = landing
            case['browser_events'] = rec.events
            case['server_errors'] = errors
            result['cases'].append(case)
            ctx.close()
        helper('shell', 'owned'); helper('lang', 'en', str(f['actors']['EA']['userid']))
        # ---- 3. manager view
        ctx = browser.new_context(viewport={'width': 1440, 'height': 900}); rec = Recorder(); rec.install(ctx)
        page = login(ctx, 'MA')
        result['manager'] = strip(visit(page), [seed['titles']['review_b'], seed['titles']['task_b'],
                                                f['documents']['B']['title']])
        page.screenshot(path=str(screens / 'office-owned-1440-manager.png'), full_page=True)
        result['manager']['browser_events'] = rec.events
        ctx.close()
        # ---- 4. foreign company
        ctx = browser.new_context(viewport={'width': 1440, 'height': 900}); rec = Recorder(); rec.install(ctx)
        page = login(ctx, 'EB')
        result['foreign'] = strip(visit(page, A), [seed['titles']['effective'], seed['titles']['review'],
                                                   seed['titles']['task'], f['documents']['A']['title']])
        result['own_b'] = strip(visit(page, B), [seed['titles']['effective'], seed['titles']['review'],
                                                 seed['titles']['task'], f['documents']['A']['title']])
        ctx.close()
        # ---- 5. revocation in the same browser context, then reopen
        ctx = browser.new_context(viewport={'width': 1440, 'height': 900}); rec = Recorder(); rec.install(ctx)
        page = login(ctx, 'EA')
        before = visit(page); before.pop('text', None)
        result['revoked_flag'] = php('revoke', 'EA').get('revoked')
        after = strip(visit(page), [seed['titles']['effective'], seed['titles']['review'], seed['titles']['task']])
        result['reopened_flag'] = not php('reopen', 'EA').get('revoked')
        result['revocation'] = {'before': before, 'after': after}
        ctx.close()
        browser.close()
    result['status'] = 'PASS'
except Exception as error:
    result['error'] = '%s: %s' % (type(error).__name__, str(error)[:400])
finally:
    try:
        php('switch', '0'); helper('shell', 'owned')
        helper('lang', 'en', str(f['actors']['EA']['userid'])); helper('lang', 'en', str(f['actors']['MA']['userid']))
    except Exception:
        pass
    dest = root / 'office-workspace-result.json'
    dest.write_text(json.dumps(result, indent=1, ensure_ascii=False)); dest.chmod(0o644)
print(json.dumps({'status': result['status'], 'cases': len(result['cases']), 'error': result.get('error')}))
sys.exit(0 if result['status'] == 'PASS' else 1)
