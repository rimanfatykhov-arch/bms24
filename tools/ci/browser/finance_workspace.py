"""Real Moodle acceptance of the FIN-W2 Finance workspace (finance.php); synthetic data only, loopback-only requests.

1. Switch off: MA (manager, company A) gets no workspace on finance.php and no Finance item in the owned menu.
2. Switch on, seeded A/B data. Four cases owned/legacy x 1440 EN / 375 RU for MA: every counter, every per-currency plan
   and the number of budgets with / without a confirmed fact equal finance_workspace_service::overview() for MA (ground
   truth from PHP); the Finance item is the only current item of the owned menu; the six planned sections are text
   without links; active sections open their canonical pages; company B titles never appear; no horizontal scroll,
   no browser errors or 5xx. One full-page screenshot per case goes to the uploaded logs (logs/screens/finance-*.png).
3. EA (employee of A, without the existing financial gate) gets no workspace and no menu item.
4. MB (manager of B) gets no workspace for company A and none of A's titles; on company B MB sees B's figures only.
5. MA is revoked in company A in the same browser context: the next request shows no workspace; then reopened.
Observations only -> EVIDENCE/finance-workspace-result.json; the verdict is computed by contract.finance_workspace_checks.
"""
import json
import os
import subprocess
import sys
import urllib.parse
from pathlib import Path

sys.path.insert(0, str(Path(__file__).resolve().parent))
from contract import FINANCE_CASES, local_url, require  # noqa: E402
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
  const ws = document.querySelector('[data-finance-workspace]');
  const counts = {}; document.querySelectorAll('[data-finance-count]').forEach(e => { counts[e.dataset.financeCount] = Number(e.dataset.value); });
  const plan = {}; document.querySelectorAll('[data-finance-plan]').forEach(e => { plan[e.dataset.financePlan] = e.dataset.value; });
  const facts = {confirmed: document.querySelectorAll('[data-finance-fact="confirmed"]').length,
                 none: document.querySelectorAll('[data-finance-fact="none"]').length};
  const nav = document.querySelector('[data-finance-nav]');
  const planned = nav ? [...nav.querySelectorAll('[data-finance-planned]')].map(e => ({key: e.dataset.financePlanned,
      tag: e.tagName.toLowerCase(), inlink: !!e.closest('a'), href: e.getAttribute('href'), disabled: e.getAttribute('aria-disabled')})) : [];
  const active = nav ? [...nav.querySelectorAll('a')].map(a => ({path: new URL(a.href).pathname.split('/').pop(),
      uuid: new URL(a.href).searchParams.get('uuid'), href: a.href, current: a.getAttribute('aria-current')})) : [];
  return {workspace: !!ws, counts, plan, facts, planned, active,
      attention: [...document.querySelectorAll('[data-finance-attention]')].map(e => e.dataset.financeAttention),
      h1: [...document.querySelectorAll('h1')].map(h => h.textContent.trim()),
      empty: [...document.querySelectorAll('[data-finance-empty]')].map(e => e.dataset.financeEmpty),
      text: document.body.innerText};
}"""
MENU = """() => { const scopes = [...document.querySelectorAll('aside.bms24-sidebar, .bms24-mobilemenu nav')];
  const links = [...new Set(scopes.flatMap(s => [...s.querySelectorAll('a.bms24-navitem')]))];
  return links.filter(a => new URL(a.href).pathname.endsWith('/finance.php')).map(a => ({
      uuid: new URL(a.href).searchParams.get('uuid'), current: a.getAttribute('aria-current'),
      visible: a.getBoundingClientRect().width > 0})); }"""


def php(*args):
    out = subprocess.run(['php', str(here / 'ci_finance_workspace.php'), *args], check=True,
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
    response = page.goto(url('/local/kbacompany/finance.php', company), wait_until='networkidle')
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


def menu_on(page, path):
    page.goto(url('/local/kbacompany/' + path), wait_until='networkidle')
    return page.evaluate(MENU)


try:
    with sync_playwright() as pw:
        browser = pw.chromium.launch(headless=True, args=['--disable-dev-shm-usage'])
        helper('shell', 'owned'); helper('lang', 'en', str(f['actors']['MA']['userid']))
        # ---- 1. switch off
        result['switch_off'] = php('switch', '0')
        ctx = browser.new_context(viewport={'width': 1440, 'height': 900}); rec = Recorder(); rec.install(ctx)
        page = login(ctx, 'MA')
        off = visit(page); off.pop('text', None)
        off['menu_on_model'] = menu_on(page, 'financial_model.php')
        result['off'] = off
        ctx.close()
        # ---- 2. switch on, seeded data, four cases for MA
        result['switch_on'] = php('switch', '1')
        seed = php('seed')
        titles = seed['titles']
        result['titles'] = titles
        foreign_b = [titles['budget_b']]
        own_a = [titles['fact'], titles['nofact'], titles['statement'], titles['gate']]
        result['model'] = {'MA': php('model', 'A', 'MA'), 'MB': php('model', 'B', 'MB')}
        for shell, width, lang in FINANCE_CASES:
            helper('shell', shell); language = helper('lang', lang, str(f['actors']['MA']['userid']))
            ctx = browser.new_context(viewport={'width': width, 'height': 900 if width > 500 else 812})
            rec = Recorder(); rec.install(ctx); errors = []
            page = login(ctx, 'MA')
            page.on('response', lambda r: errors.append({'url': r.url, 'status': r.status}) if r.status >= 500 else None)
            case = {'shell': shell, 'width': width, 'lang': lang, 'language_installed': language.get('installed')}
            state = visit(page, A, width)
            state['shown'] = [t for t in own_a if t in state.get('text', '')]
            case['page'] = strip(state, foreign_b)
            page.screenshot(path=str(screens / ('finance-%s-%s-%s.png' % (shell, width, lang))), full_page=True)
            if shell == 'owned' and width == 1440:
                landing = []
                for link in case['page']['active']:
                    resp = page.goto(local_url(link['href']), wait_until='networkidle')
                    landing.append({'path': urllib.parse.urlparse(page.url).path.split('/')[-1],
                                    'http': resp.status if resp else None,
                                    'workspace_error': page.locator('[data-finance-workspace]').count() == 0
                                    and link['path'] == 'finance.php'})
                case['landing'] = landing
            case['browser_events'] = rec.events
            case['server_errors'] = errors
            result['cases'].append(case)
            ctx.close()
        helper('shell', 'owned'); helper('lang', 'en', str(f['actors']['MA']['userid']))
        # ---- 3. employee of A without the financial gate
        ctx = browser.new_context(viewport={'width': 1440, 'height': 900}); rec = Recorder(); rec.install(ctx)
        page = login(ctx, 'EA')
        employee = strip(visit(page), own_a)
        employee['menu_on_tasks'] = menu_on(page, 'tasks.php')
        result['employee'] = employee
        ctx.close()
        # ---- 4. foreign company manager
        ctx = browser.new_context(viewport={'width': 1440, 'height': 900}); rec = Recorder(); rec.install(ctx)
        page = login(ctx, 'MB')
        result['foreign'] = strip(visit(page, A), own_a)
        result['own_b'] = strip(visit(page, B), own_a)
        page.screenshot(path=str(screens / 'finance-owned-1440-company-b.png'), full_page=True)
        ctx.close()
        # ---- 5. revocation in the same browser context, then reopen
        ctx = browser.new_context(viewport={'width': 1440, 'height': 900}); rec = Recorder(); rec.install(ctx)
        page = login(ctx, 'MA')
        before = visit(page); before.pop('text', None)
        result['revoked_flag'] = php('revoke', 'MA').get('revoked')
        after = strip(visit(page), own_a)
        result['reopened_flag'] = not php('reopen', 'MA').get('revoked')
        result['revocation'] = {'before': before, 'after': after}
        ctx.close()
        browser.close()
    result['status'] = 'PASS'
except Exception as error:
    result['error'] = '%s: %s' % (type(error).__name__, str(error)[:400])
finally:
    try:
        php('switch', '0'); helper('shell', 'owned'); helper('lang', 'en', str(f['actors']['MA']['userid']))
        php('reopen', 'MA')
    except Exception:
        pass
    dest = root / 'finance-workspace-result.json'
    dest.write_text(json.dumps(result, indent=1, ensure_ascii=False)); dest.chmod(0o644)
print(json.dumps({'status': result['status'], 'cases': len(result['cases']), 'error': result.get('error')}))
sys.exit(0 if result['status'] == 'PASS' else 1)
