"""Real Moodle acceptance of the adaptive BMS24 navigation (docs/ui/ADAPTIVE_NAVIGATION.md); synthetic data only.

Owned shell at 320/375/390/768/860/861/1024/1440 px, a low window, a landscape phone, 200 % zoom (720 CSS px at DPR 2)
and an employee with a shorter menu; legacy shell at 375/1440; no-JS owned at 375/1440. Narrow cases open the menu,
measure geometry, focus, scroll lock, groups, labels and tap targets, then close it with Escape, the close button and the
backdrop. One narrow case also crosses the breakpoint in both directions and walks Tasks -> Communication Center ->
Daily reports -> Battle Plan -> Tasks through the menu. A synthetic long label with a 9999 counter is cloned into the
rendered menu inside the test browser only (never stored). Finally the owned page is loaded with the owned_shell.css a
browser cached before the adaptive navigation release (the request is answered from a pinned fixture): the desktop must
not show the mobile menu trigger next to the sidebar, and the phone must keep one (hotfix #163). Only observations are written to
EVIDENCE/adaptive-nav-result.json; the verdict is computed by contract.adaptive_nav_checks. The producer tolerates the
pre-fix markup (no menu button: it falls back to the old summary) so that "before" evidence is recorded, never PASSed.
"""
import hashlib
import json
import os
import subprocess
import sys
import urllib.parse
from pathlib import Path

sys.path.insert(0, str(Path(__file__).resolve().parent))
from contract import (ADAPTIVE_NAV_CASES, ADAPTIVE_NAV_MARKER as MARKER, ADAPTIVE_NAV_ROUTES,  # noqa: E402
                      ADAPTIVE_NAV_STALE_CASES, ADAPTIVE_NAV_STALE_CSS, ADAPTIVE_NAV_STRESS, local_url, require)
from events import Recorder  # noqa: E402
from playwright.sync_api import sync_playwright  # noqa: E402

require(os.environ.get('BMS_CI_BROWSER') == '1', 'CI browser fixture only')
here = Path(__file__).resolve().parent
root = Path(os.environ['EVIDENCE']); core = os.environ['MOODLE_ROOT']; base = 'http://127.0.0.1:8080'
f = json.loads((root / 'actors.private.json').read_text())
company = f['companies']['A']['uuid']
result = {'status': 'FAIL', 'company': company, 'cases': []}

# ---- in-page probes (read-only, except STRESS which clones one rendered link and removes it again)
COMMON = r"""
// Content of a closed <details> keeps a layout box in current Chromium; checkVisibility() is the rendered truth.
const vis = e => { if (!e) return false; const r = e.getBoundingClientRect(), cs = getComputedStyle(e);
  if (e.checkVisibility && !e.checkVisibility({visibilityProperty: true})) return false;
  return r.width > 1 && r.height > 1 && cs.visibility !== 'hidden' && cs.display !== 'none'; };
const box = e => { if (!e) return null; const r = e.getBoundingClientRect();
  return {x: Math.round(r.left), y: Math.round(r.top), w: Math.round(r.width), h: Math.round(r.height),
          r: Math.round(r.right), b: Math.round(r.bottom)}; };
const drawerEl = () => document.querySelector('#bms24-drawer') || document.querySelector('.bms24-mobilemenu > nav');
const inside = (el, root) => !!(el && root && root.contains(el));
const focusables = scope => [...scope.querySelectorAll('a[href],button,summary,input,select,textarea,[tabindex]')]
  .filter(e => !e.disabled && e.tabIndex >= 0 && vis(e) && !e.closest('[inert]'));
const brokenWords = label => { const out = []; const walk = document.createTreeWalker(label, NodeFilter.SHOW_TEXT); let n;
  while ((n = walk.nextNode())) { const t = n.textContent; const re = /\S+/g; let m;
    while ((m = re.exec(t))) { const rg = document.createRange(); rg.setStart(n, m.index); rg.setEnd(n, m.index + m[0].length);
      const lines = new Set([...rg.getClientRects()].filter(q => q.width > 0).map(q => Math.round(q.top)));
      if (lines.size > 1) out.push(m[0]); } }
  return out; };
const labelReport = scope => [...scope.querySelectorAll('a.bms24-navitem')].filter(vis).map(a => {
  const r = a.getBoundingClientRect(); const count = a.querySelector('.bms24-count');
  const labels = [...a.querySelectorAll('.bms24-navlabel')].filter(l => { const b = l.getBoundingClientRect(); return b.width > 1 && b.height > 1 && vis(l); });
  const cr = count && vis(count) ? count.getBoundingClientRect() : null;
  return {text: a.innerText.replace(/\s+/g, ' ').trim(), broken: labels.flatMap(brokenWords),
    count: count ? {visible: vis(count), text: count.textContent.trim(), clipped: count.scrollWidth > count.clientWidth + 1,
      inside: !!cr && cr.left >= r.left - 1 && cr.right <= r.right + 1 && cr.top >= r.top - 1 && cr.bottom <= r.bottom + 1} : null}; });
const targets = (scope, sel) => [...scope.querySelectorAll(sel || 'a[href],button,summary')].filter(vis).map(e => ({
  text: (e.getAttribute('aria-label') || e.innerText || '').replace(/\s+/g, ' ').trim().slice(0, 60), ...box(e)}));
const outside = scope => { const s = scope.getBoundingClientRect();
  return [...scope.querySelectorAll('*')].filter(e => { const r = e.getBoundingClientRect();
    return r.width > 1 && r.height > 1 && vis(e) && (r.left < s.left - 1 || r.right > s.right + 1); })
    .map(e => (e.className && e.className.baseVal === undefined ? e.className : e.tagName) + '').slice(0, 5); };
const scrollers = scope => [scope, ...scope.querySelectorAll('*')].filter(e => {
  const cs = getComputedStyle(e); return ['auto', 'scroll'].includes(cs.overflowX) || ['auto', 'scroll'].includes(cs.overflowY); });
const groups = scope => [...scope.querySelectorAll('details.bms24-navgroup')].map(d => ({
  label: (d.querySelector('summary') || {}).innerText || '', open: d.open, summary: box(d.querySelector('summary')),
  items: d.querySelectorAll('a.bms24-navitem').length, visible: [...d.querySelectorAll('a.bms24-navitem')].filter(vis).length,
  current: !!d.querySelector('a[aria-current="page"]')}));
"""

LAYOUT = '() => {' + COMMON + r"""
  const btn = document.querySelector('.bms24-menubutton'), sum = document.querySelector('.bms24-mobilemenu > summary');
  const side = document.querySelector('aside.bms24-sidebar'), main = document.querySelector('.bms24-main'), d = drawerEl();
  const ctl = btn && btn.getAttribute('aria-controls');
  return {owned: !!document.querySelector('[data-bms24-owned-shell]'), inner: innerWidth, innerh: innerHeight,
    client: document.documentElement.clientWidth, scroll: document.documentElement.scrollWidth, scrolly: Math.round(scrollY),
    main: box(main), sidebar_visible: vis(side), summary_visible: vis(sum), drawer_visible: vis(d),
    drawer_focusables: d ? focusables(d).length : 0,
    // BMS site navigation only (sidebar and menu); Moodle's own user menu in the top bar is a real menu and is not counted.
    menu_roles: [...document.querySelectorAll('.bms24-sidebar, .bms24-mobilemenu')].reduce((n, s) =>
      n + s.querySelectorAll('[role="menu"],[role="menuitem"],[role="menubar"]').length, 0),
    button: btn ? {tag: btn.tagName, type: btn.getAttribute('type'), visible: vis(btn), ...box(btn), expanded: btn.getAttribute('aria-expanded'),
      controls: ctl, controls_exists: !!(ctl && document.getElementById(ctl)), name: (btn.getAttribute('aria-label') || btn.innerText).replace(/\s+/g, ' ').trim(),
      hidden_attr: btn.hasAttribute('hidden')} : null,
    html_overflow: getComputedStyle(document.documentElement).overflowY, body_overflow: getComputedStyle(document.body).overflowY,
    css_hrefs: [...document.querySelectorAll('link[rel="stylesheet"]')].map(l => l.href).filter(h => h.indexOf('/bms_ui/owned_shell.css') >= 0),
    main_inert: !!(main && main.closest('[inert]')),
    backdrop_visible: vis(document.querySelector('[data-bms24-drawer-backdrop]')),
    active: document.activeElement ? (document.activeElement === btn ? 'menubutton' : document.activeElement.tagName) : null,
    sidebar: side && vis(side) ? {groups: groups(side), labels: labelReport(side), targets: targets(side, 'a.bms24-navitem,summary'), outside: outside(side),
      current_visible: vis(side.querySelector('a[aria-current="page"]'))} : null};
}"""

DRAWER = '() => {' + COMMON + r"""
  const d = drawerEl(); if (!d || !vis(d)) return {visible: false};
  const btn = document.querySelector('.bms24-menubutton'), back = document.querySelector('[data-bms24-drawer-backdrop]');
  const cur = d.querySelector('a[aria-current="page"]'), close = d.querySelector('[data-bms24-drawer-close]');
  const sc = scrollers(d).map(e => ({cls: (e.className || e.tagName) + '', sw: e.scrollWidth, cw: e.clientWidth, sh: e.scrollHeight, ch: e.clientHeight,
    ox: getComputedStyle(e).overflowX, oy: getComputedStyle(e).overflowY}));
  const named = d.getAttribute('aria-label') || (d.getAttribute('aria-labelledby') && (document.getElementById(d.getAttribute('aria-labelledby')) || {}).textContent) || '';
  return {visible: true, box: box(d), inner: innerWidth, innerh: innerHeight, role: d.getAttribute('role'), modal: d.getAttribute('aria-modal'),
    name: (named || '').trim(), id: d.id, backdrop: back ? {visible: vis(back), ...box(back)} : null,
    close: close ? {visible: vis(close), ...box(close), name: (close.getAttribute('aria-label') || close.innerText).trim(), tag: close.tagName} : null,
    focus_inside: inside(document.activeElement, d), expanded: btn ? btn.getAttribute('aria-expanded') : null,
    current: cur ? {visible: vis(cur), open: !!(cur.closest('details') || {}).open, path: new URL(cur.href).pathname.split('/').pop()} : null,
    groups: groups(d), labels: labelReport(d), targets: targets(d), outside: outside(d), scrollers: sc,
    doc_scroll: document.documentElement.scrollWidth, client: document.documentElement.clientWidth,
    html_overflow: getComputedStyle(document.documentElement).overflowY, main: box(document.querySelector('.bms24-main')),
    main_inert: !!(document.querySelector('.bms24-main') || document.body).closest('[inert]'),
    sidebar_inert: !!(document.querySelector('aside.bms24-sidebar') || document.body).closest('[inert]'),
    focusables: focusables(d).length, menu_roles: d.querySelectorAll('[role="menu"],[role="menuitem"]').length};
}"""

STRESS = '(spec) => {' + COMMON + r"""
  const d = drawerEl(); const host = d && d.querySelector('details[open] a.bms24-navitem');
  if (!host) return {done: false};
  const a = host.cloneNode(true); a.removeAttribute('aria-current'); a.classList.remove('is-active'); a.setAttribute('data-bms24-stress', '1');
  a.querySelectorAll('.bms24-navlabel, .bms24-count').forEach(e => e.remove());
  const l = document.createElement('span'); l.className = 'bms24-navlabel'; l.textContent = spec.label; a.appendChild(l);
  const c = document.createElement('span'); c.className = 'bms24-count'; c.textContent = spec.count; a.appendChild(c);
  host.parentNode.appendChild(a);
  const rep = labelReport(a.parentNode).find(x => x.text.indexOf(spec.count) >= 0) || null;
  const out = {done: true, report: rep, box: box(a), outside: outside(d), drawer: box(d)};
  a.remove(); return out;
}"""

LAST = '() => {' + COMMON + r"""
  const d = drawerEl(); if (!d) return null;
  scrollers(d).forEach(e => { e.scrollTop = e.scrollHeight; });
  const links = [...d.querySelectorAll('a[href]')].filter(vis); const last = links[links.length - 1]; if (!last) return {found: false};
  const r = last.getBoundingClientRect(); const hit = document.elementFromPoint(r.left + r.width / 2, r.top + r.height / 2);
  return {found: true, text: last.innerText.replace(/\s+/g, ' ').trim(), top: Math.round(r.top), bottom: Math.round(r.bottom), innerh: innerHeight,
    hit: !!(hit && last.contains(hit)), doc_scroll: document.documentElement.scrollWidth, client: document.documentElement.clientWidth};
}"""

INSIDE = '() => {' + COMMON + r""" const d = drawerEl(); return inside(document.activeElement, d); }"""
# Which owned_shell.css is in effect: the pre-release file has no rule for the menu button.
SHEET = """() => { const s = [...document.styleSheets].filter(x => (x.href || '').indexOf('/bms_ui/owned_shell.css') >= 0);
  let rules = 0, menubutton = 0;
  s.forEach(x => { for (const r of x.cssRules) { rules++; if ((r.cssText || '').indexOf('bms24-menubutton') >= 0) menubutton++; } });
  return {sheets: s.length, rules: rules, menubutton: menubutton}; }"""
MAIN_FIELD = """() => { const i = document.querySelector('.bms24-main input[name="title"], #region-main input[name="title"], input[name="title"]');
  return i ? i.value : null; }"""


def php(*args):
    out = subprocess.run(['php', str(here / 'ci_task_open.php'), core, *args], check=True, capture_output=True, text=True).stdout
    return json.loads(out.strip().splitlines()[-1])


def login(ctx, label):
    a = f['actors'][label]; p = ctx.new_page(); p.set_default_timeout(30000)
    p.goto(local_url(base + '/login/index.php'), wait_until='networkidle')
    p.locator('#username').fill(a['username']); p.locator('#password').fill(a['password'])
    p.locator('#loginbtn').click(); p.wait_for_load_state('networkidle')
    return p


def url(path, **params):
    return local_url(base + path + '?' + urllib.parse.urlencode(dict(uuid=company, **params)))


def settle(page):
    page.wait_for_timeout(350)


def open_menu(page):
    """New UI: the menu button. Pre-fix UI: the old summary (recorded as such; the contract rejects it)."""
    btn = page.locator('.bms24-menubutton')
    if btn.count() and btn.first.is_visible():
        btn.first.click(); settle(page); return 'button'
    summary = page.locator('.bms24-mobilemenu > summary')
    if summary.count() and summary.first.is_visible():
        summary.first.click(); settle(page); return 'summary'
    return None


def closed_state(page, before_y, method):
    lay = page.evaluate(LAYOUT)
    return {'method': method, 'drawer_visible': lay['drawer_visible'], 'backdrop_visible': lay['backdrop_visible'],
            'expanded': (lay['button'] or {}).get('expanded'), 'active': lay['active'], 'scrolly': lay['scrolly'],
            'expected_scrolly': before_y, 'html_overflow': lay['html_overflow'], 'main_inert': lay['main_inert'],
            'field': page.evaluate(MAIN_FIELD), 'drawer_focusables': lay['drawer_focusables']}


def tab_cycle(page, count, key):
    seen = []
    for _ in range(count):
        page.keyboard.press(key); seen.append(page.evaluate(INSIDE))
    return seen


def narrow_flow(page, case):
    field = page.locator('input[name="title"]')
    case['field_present'] = field.count() > 0
    if field.count():
        field.first.fill(MARKER)
    page.evaluate('() => window.scrollTo(0, Math.min(400, document.documentElement.scrollHeight - innerHeight))')
    settle(page)
    if case['id'] == 'owned-375-ru':
        bell_button = page.locator('[data-bms24-bell-toggle]')
        bell_panel = page.locator('[data-bms24-bell-panel]')
        bell_button.click()
        bell_panel.wait_for(state='visible')
        case['bell_handoff'] = {'open_before_menu': bell_button.get_attribute('aria-expanded') == 'true'}
    before = page.evaluate(LAYOUT); case['before_open'] = before
    case['open_method'] = open_menu(page)
    if case['id'] == 'owned-375-ru':
        case['bell_handoff']['closed_after_menu'] = (
            not bell_panel.is_visible() and bell_button.get_attribute('aria-expanded') == 'false')
    d = page.evaluate(DRAWER); case['drawer'] = d
    page.screenshot(path=str(root / ('adaptive-nav-%s-open.png' % case['id'])))
    if not d.get('visible'):
        return
    case['stress'] = page.evaluate(STRESS, ADAPTIVE_NAV_STRESS)
    n = max(int(d.get('focusables') or 0), 1)
    case['tab_forward'] = tab_cycle(page, n + 3, 'Tab')
    case['tab_backward'] = tab_cycle(page, 4, 'Shift+Tab')
    # The page behind must not scroll while the modal panel is open (wheel over the drawer and over the backdrop).
    y0 = page.evaluate('() => Math.round(scrollY)')
    page.mouse.move(20, d['innerh'] // 2); page.mouse.wheel(0, 600); settle(page)
    page.mouse.move(d['inner'] - 6, d['innerh'] // 2); page.mouse.wheel(0, 600); settle(page)
    case['wheel'] = {'before': y0, 'after': page.evaluate('() => Math.round(scrollY)')}
    # Every group can be opened and then really shows its items.
    summaries = page.locator('#bms24-drawer details.bms24-navgroup > summary, .bms24-mobilemenu > nav details.bms24-navgroup > summary')
    for i in range(summaries.count()):
        s = summaries.nth(i)
        if s.is_visible() and s.evaluate('e => !e.parentElement.open'):
            s.click(); page.wait_for_timeout(80)
    case['groups_all_open'] = page.evaluate(DRAWER).get('groups')
    case['last_item'] = page.evaluate(LAST)
    # Close 1: Escape.
    page.keyboard.press('Escape'); settle(page)
    case['close_escape'] = closed_state(page, before['scrolly'], 'escape')
    # Close 2: the close button.
    if open_menu(page):
        close = page.locator('[data-bms24-drawer-close]')
        if close.count() and close.first.is_visible():
            close.first.click(); settle(page)
        case['close_button'] = closed_state(page, before['scrolly'], 'button')
    # Close 3: the backdrop, where the drawer leaves part of it exposed.
    if open_menu(page):
        d2 = page.evaluate(DRAWER)
        exposed = bool(d2.get('box')) and d2['box']['r'] < d2['inner'] - 16
        case['backdrop_exposed'] = exposed
        if exposed:
            page.mouse.click(d2['inner'] - 8, d2['innerh'] // 2); settle(page)
            case['close_backdrop'] = closed_state(page, before['scrolly'], 'backdrop')
        else:
            page.keyboard.press('Escape'); settle(page)
    case['after_close'] = page.evaluate(LAYOUT)


def resize_flow(page, case, narrow, wide):
    out = {}
    page.set_viewport_size({'width': narrow[0], 'height': narrow[1]}); settle(page)
    out['opened'] = open_menu(page)
    out['open'] = page.evaluate(DRAWER).get('visible')
    page.set_viewport_size({'width': wide[0], 'height': wide[1]}); settle(page)
    out['to_wide'] = page.evaluate(LAYOUT)
    page.set_viewport_size({'width': narrow[0], 'height': narrow[1]}); settle(page)
    out['back_narrow'] = page.evaluate(LAYOUT)
    out['reopen'] = open_menu(page); out['reopen_visible'] = page.evaluate(DRAWER).get('visible')
    page.keyboard.press('Escape'); settle(page)
    out['reclosed'] = page.evaluate(LAYOUT)
    case['resize'] = out


def route_flow(page, case, server_errors):
    steps = []
    for target in ADAPTIVE_NAV_ROUTES:
        step = {'target': target}
        step['opened'] = open_menu(page)
        link = page.locator('#bms24-drawer a.bms24-navitem[href*="/%s"]' % target)
        if link.count() == 0:
            link = page.locator('.bms24-mobilemenu a.bms24-navitem[href*="/%s"]' % target)
        step['links'] = link.count()
        if link.count():
            first = link.first
            if not first.is_visible():
                first.evaluate('a => { const d = a.closest("details"); if (d) d.querySelector("summary").click(); }'); settle(page)
            step['visible'] = first.is_visible()
            if step['visible']:
                with page.expect_navigation() as nav:
                    first.click()
                resp = nav.value
                page.wait_for_load_state('networkidle')
                step['http'] = resp.status if resp else None
                step['path'] = urllib.parse.urlparse(page.url).path.split('/')[-1]
                step['opened_after'] = open_menu(page)
                d = page.evaluate(DRAWER)
                step['current'] = d.get('current'); step['doc_scroll'] = d.get('doc_scroll'); step['client'] = d.get('client')
                page.keyboard.press('Escape'); settle(page)
        steps.append(step)
    case['routes'] = steps


def nojs_flow(browser, case, width, height):
    ctx = browser.new_context(viewport={'width': width, 'height': height}, java_script_enabled=False)
    recorder = Recorder(); recorder.install(ctx)
    page = login(ctx, 'MA')
    resp = page.goto(url('/local/kbacompany/tasks.php', scope='my'), wait_until='networkidle')
    case['http'] = resp.status if resp else None
    case['layout'] = page.evaluate(LAYOUT)
    if width < 861:
        summary = page.locator('.bms24-mobilemenu > summary')
        case['summary_visible'] = summary.count() > 0 and summary.first.is_visible()
        if case['summary_visible']:
            summary.first.click(); page.wait_for_timeout(200)
        case['drawer'] = page.evaluate(DRAWER)
        case['last_item'] = page.evaluate(LAST)
    page.screenshot(path=str(root / ('adaptive-nav-%s.png' % case['id'])))
    case['browser_events'] = recorder.events
    ctx.close()


def stale_flow(browser):
    """The owned page with the stylesheet a browser cached before the release; JavaScript is the current one."""
    body = (here / ADAPTIVE_NAV_STALE_CSS).read_bytes().replace(b'\r\n', b'\n')
    out = {'fixture_sha256': hashlib.sha256(body).hexdigest(), 'cases': []}
    result['stale_css'] = out
    php('shell', 'owned')
    php('lang', 'ru', str(f['actors']['MA']['userid']))
    for width, height in ADAPTIVE_NAV_STALE_CASES:
        row = {'width': width, 'height': height}
        out['cases'].append(row)
        try:
            hits = []
            ctx = browser.new_context(viewport={'width': width, 'height': height})
            recorder = Recorder(); recorder.install(ctx)

            # One-argument handler: Playwright passes the request as a second argument to two-parameter handlers.
            ctx.route('**/local/kbacompany/bms_ui/owned_shell.css*',
                      lambda route: (hits.append(route.request.url),
                                     route.fulfill(status=200, content_type='text/css; charset=utf-8', body=body)))
            page = login(ctx, 'MA')
            resp = page.goto(url('/local/kbacompany/tasks.php', scope='my'), wait_until='networkidle')
            settle(page)
            row['http'] = resp.status if resp else None
            row['hits'] = len(hits)
            row['sheet'] = page.evaluate(SHEET)
            row['layout'] = page.evaluate(LAYOUT)
            page.screenshot(path=str(root / ('adaptive-nav-stale-css-%d.png' % width)))
            row['browser_events'] = recorder.events
            ctx.close()
        except Exception as error:  # recorded per case; the contract fails closed on it
            row['error'] = '%s: %s' % (type(error).__name__, str(error)[:300])


try:
    with sync_playwright() as pw:
        browser = pw.chromium.launch(headless=True, args=['--disable-dev-shm-usage'])
        for spec in ADAPTIVE_NAV_CASES:
            cid, shell, actor, lang, width, height, dpr, js = spec
            case = {'id': cid, 'shell': shell, 'actor': actor, 'lang': lang, 'width': width, 'height': height, 'dpr': dpr, 'js': js}
            result['cases'].append(case)
            try:
                php('shell', shell)
                case['language_installed'] = php('lang', lang, str(f['actors'][actor]['userid'])).get('installed')
                if not js:
                    nojs_flow(browser, case, width, height); continue
                recorder = Recorder(); server_errors = []
                ctx = browser.new_context(viewport={'width': width, 'height': height}, device_scale_factor=dpr)
                recorder.install(ctx)
                page = login(ctx, actor)
                page.on('response', lambda r: server_errors.append({'url': r.url, 'status': r.status}) if r.status >= 500 else None)
                resp = page.goto(url('/local/kbacompany/tasks.php', view='create'), wait_until='networkidle')
                case['http'] = resp.status if resp else None
                case['layout'] = page.evaluate(LAYOUT)
                if shell == 'owned' and width < 861:
                    narrow_flow(page, case)
                    if cid == 'owned-375-ru':
                        resize_flow(page, case, (375, 812), (1440, 900))
                        page.goto(url('/local/kbacompany/tasks.php', scope='my'), wait_until='networkidle')
                        route_flow(page, case, server_errors)
                elif shell == 'owned':
                    page.screenshot(path=str(root / ('adaptive-nav-%s.png' % cid)))
                    if cid == 'owned-861-ru':
                        resize_flow(page, case, (860, 900), (861, 900))
                else:
                    page.screenshot(path=str(root / ('adaptive-nav-%s.png' % cid)))
                case['browser_events'] = recorder.events
                case['server_errors'] = server_errors
                ctx.close()
            except Exception as error:  # recorded per case; the contract fails closed on it
                case['error'] = '%s: %s' % (type(error).__name__, str(error)[:300])
        try:
            stale_flow(browser)
        except Exception as error:
            result['stale_css'] = dict(result.get('stale_css') or {}, error='%s: %s' % (type(error).__name__, str(error)[:300]))
        browser.close()
    result['status'] = 'PASS'
except Exception as error:
    result['error'] = '%s: %s' % (type(error).__name__, str(error)[:400])
finally:
    try:
        php('shell', 'owned')
        for label in ('MA', 'EA'):
            php('lang', 'en', str(f['actors'][label]['userid']))
    except Exception:
        pass
    dest = root / 'adaptive-nav-result.json'
    dest.write_text(json.dumps(result, indent=1, ensure_ascii=False)); dest.chmod(0o644)
print(json.dumps({'status': result['status'], 'cases': len(result['cases']),
                  'case_errors': [c['id'] for c in result['cases'] if c.get('error')], 'error': result.get('error'),
                  'stale_css_errors': [r['width'] for r in (result.get('stale_css') or {}).get('cases', []) if r.get('error')]
                  + (['run'] if (result.get('stale_css') or {}).get('error') else [])}))
stale = result.get('stale_css') or {}
sys.exit(0 if result['status'] == 'PASS' and not any(c.get('error') for c in result['cases'])
         and stale.get('cases') and not stale.get('error') and not any(r.get('error') for r in stale['cases']) else 1)
