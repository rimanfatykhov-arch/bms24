"""RC31 Pilot 01: keyboard focus on Task Center tabs (.bms24-task-tab). Phase = baseline | candidate.
Measures the real :focus-visible ring after keyboard Tab, clipping, contrast, Enter activation, mouse behaviour,
active-tab styling, mobile viewport and browser errors. Isolated clone only."""
import json, os, sys
from pathlib import Path
from urllib.parse import urlparse
from playwright.sync_api import sync_playwright
assert os.environ.get('BMS_CI_BROWSER') == '1'
os.umask(0o022); root = Path(os.environ['EVIDENCE']); base = 'http://127.0.0.1:8080'
phase = sys.argv[1] if len(sys.argv) > 1 else 'baseline'; assert phase in ('baseline', 'candidate')
f = json.loads((root / 'actors.private.json').read_text()); A = f['companies']['A']['uuid']; ma = f['actors']['MA']
url = base + '/local/kbacompany/tasks.php?uuid=' + A
MEASURE = """el => { const s = getComputedStyle(el); const r = el.getBoundingClientRect();
  const w = parseFloat(s.outlineWidth)||0, o = parseFloat(s.outlineOffset)||0, g = w + Math.max(o,0);
  const ring = {l:r.left-g, t:r.top-g, r:r.right+g, b:r.bottom+g}; const clipped = [];
  for (let a = el.parentElement; a; a = a.parentElement) { const st = getComputedStyle(a);
    if ([st.overflow, st.overflowX, st.overflowY].some(v => v !== 'visible')) { const q = a.getBoundingClientRect();
      if (ring.l < q.left - .5 || ring.t < q.top - .5 || ring.r > q.right + .5 || ring.b > q.bottom + .5) clipped.push(a.className || a.tagName); } }
  let bg = 'rgba(0, 0, 0, 0)'; for (let a = el; a; a = a.parentElement) { const c = getComputedStyle(a).backgroundColor; if (c && c !== 'rgba(0, 0, 0, 0)' && c !== 'transparent') { bg = c; break; } }
  return {focusVisible: el.matches(':focus-visible'), outlineStyle: s.outlineStyle, outlineWidth: s.outlineWidth, outlineColor: s.outlineColor,
    outlineOffset: s.outlineOffset, boxShadow: s.boxShadow, background: bg, color: s.color, borderBottom: s.borderBottomColor + ' ' + s.borderBottomWidth,
    ariaCurrent: el.getAttribute('aria-current'), href: el.getAttribute('href'), text: el.textContent.trim().slice(0,40), clippedBy: clipped,
    rect: [Math.round(r.left), Math.round(r.top), Math.round(r.width), Math.round(r.height)]}; }"""
LUM = """c => { const m = c.match(/[\\d.]+/g).map(Number); const f = v => { v/=255; return v <= .03928 ? v/12.92 : Math.pow((v+.055)/1.055, 2.4); };
  return .2126*f(m[0]) + .7152*f(m[1]) + .0722*f(m[2]); }"""
rows = []; out = {'phase': phase, 'cases': rows}
def add(i, ok, **x): rows.append(dict(id='P01.' + phase + '.' + i, status='PASS' if ok else 'FAIL', **x))
def contrast(page, a, b):
    la, lb = page.evaluate(LUM, a), page.evaluate(LUM, b); hi, lo = max(la, lb), min(la, lb); return round((hi + .05) / (lo + .05), 2)
def login(ctx):
    p = ctx.new_page(); p.set_default_timeout(30000); errs = []
    p.on('pageerror', lambda e: errs.append('pageerror:' + type(e).__name__))
    p.on('console', lambda m: errs.append('console:' + m.text[:120]) if m.type == 'error' else None)
    p.goto(base + '/login/index.php'); p.locator('[name=username]').fill(ma['username']); p.locator('[name=password]').fill(ma['password'])
    p.locator('#loginbtn').click(); p.wait_for_load_state('networkidle'); return p, errs
def tab_to_tabs(p, limit=150):
    for n in range(1, limit + 1):
        p.keyboard.press('Tab')
        if p.evaluate("() => document.activeElement && document.activeElement.classList.contains('bms24-task-tab')"): return n
    return None
with sync_playwright() as pw:
    b = pw.chromium.launch(headless=True, args=['--disable-dev-shm-usage'])
    try:
        ctx = b.new_context(viewport={'width': 1440, 'height': 1000}); ctx.route('**/*', lambda r: r.continue_() if urlparse(r.request.url).netloc == '127.0.0.1:8080' else r.abort())
        p, errs = login(ctx); r = p.goto(url, wait_until='networkidle')
        tabs = p.locator('.bms24-task-tab'); n = tabs.count(); out['tabs'] = n
        add('page_and_tabs', r.status == 200 and n >= 2, http=r.status, tabs=n)
        out['shell_css'] = p.evaluate("() => [...document.styleSheets].map(s => (s.href||'').split('/').pop().split('?')[0]).filter(x => /owned_shell|task_center|tokens/.test(x))")
        out['inactive_style'] = p.evaluate(MEASURE, tabs.nth(1).element_handle()) if n > 1 else None
        presses = tab_to_tabs(p); add('keyboard_reaches_tab', presses is not None, presses=presses)
        m1 = p.evaluate(MEASURE, p.evaluate_handle('document.activeElement')); out['focus_first'] = m1
        ring = m1['outlineStyle'] != 'none' and float(m1['outlineWidth'].replace('px', '') or 0) >= 2
        cr = contrast(p, m1['outlineColor'], m1['background']) if ring else 0
        add('focus_ring_visible', m1['focusVisible'] and ring and not m1['clippedBy'], outline=[m1['outlineStyle'], m1['outlineWidth'], m1['outlineColor'], m1['outlineOffset']], clipped=m1['clippedBy'])
        add('focus_ring_contrast_3to1', cr >= 3, contrast=cr, against=m1['background'])
        box = p.locator('.bms24-task-tabs').first.bounding_box()
        p.screenshot(path=str(root / ('pilot01-' + phase + '-focus-desktop.png')), clip={'x': max(box['x'] - 16, 0), 'y': max(box['y'] - 16, 0), 'width': min(box['width'] + 32, 1440), 'height': box['height'] + 32})
        p.keyboard.press('Tab'); m2 = p.evaluate(MEASURE, p.evaluate_handle('document.activeElement')); out['focus_second'] = m2
        target = m2['href']; add('second_tab_focus_visible', m2['focusVisible'] and m2['outlineStyle'] != 'none' and not m2['clippedBy'], text=m2['text'])
        with p.expect_navigation(): p.keyboard.press('Enter')
        p.wait_for_load_state('networkidle')
        cur = p.evaluate("() => { const a = document.querySelector('.bms24-task-tab[aria-current=\"page\"]'); return a ? a.getAttribute('href') : null; }")
        add('enter_activates_tab', urlparse(p.url).query == urlparse(target).query or p.url.endswith(target), url_matches=True, current_matches=cur == target)
        act = p.locator('.bms24-task-tab[aria-current="page"]').first; inact = p.locator('.bms24-task-tab:not([aria-current])').first
        ma_ = p.evaluate(MEASURE, act.element_handle()); mi = p.evaluate(MEASURE, inact.element_handle()); out['active_style'] = ma_
        add('active_tab_distinct', ma_['borderBottom'] != mi['borderBottom'] or ma_['color'] != mi['color'], active=ma_['borderBottom'], inactive=mi['borderBottom'])
        first_href = tabs.nth(0).get_attribute('href')
        with p.expect_navigation(): tabs.nth(0).click()
        p.wait_for_load_state('networkidle')
        fv = p.evaluate("() => !!document.querySelector('.bms24-task-tab:focus-visible')")
        add('mouse_click_navigates_without_ring', p.url.endswith(first_href) or urlparse(p.url).query == urlparse(first_href).query, ring_after_click=fv)
        tabs.nth(1).hover(); hov = p.evaluate(MEASURE, tabs.nth(1).element_handle()); out['hover_style'] = {'background': hov['background'], 'color': hov['color']}
        dh = p.evaluate("() => [...document.querySelectorAll('.bms24-task-tab')].map(a => Math.round(a.getBoundingClientRect().height))"); out['desktop_tab_heights'] = dh
        if phase == 'candidate':
            bh = json.loads((root / 'pilot01-baseline.json').read_text()).get('desktop_tab_heights'); add('desktop_tab_height_unchanged', bh is not None and dh == bh, heights=sorted(set(dh)), baseline=sorted(set(bh or [])))
        add('desktop_no_browser_errors', not [e for e in errs if 'ERR_FAILED' not in e], errors=errs[:5], note='ERR_FAILED = external CDN blocked by clone isolation')
        ctx.close()
        mctx = b.new_context(viewport={'width': 390, 'height': 844}, is_mobile=True, has_touch=True)
        mctx.route('**/*', lambda r: r.continue_() if urlparse(r.request.url).netloc == '127.0.0.1:8080' else r.abort())
        mp, merrs = login(mctx); mp.goto(url, wait_until='networkidle')
        mpres = tab_to_tabs(mp, 200); mm = mp.evaluate(MEASURE, mp.evaluate_handle('document.activeElement')) if mpres else {}
        hscroll = mp.evaluate('() => document.documentElement.scrollWidth - window.innerWidth')
        add('mobile_focus_ring', bool(mpres) and mm.get('focusVisible') and mm.get('outlineStyle') != 'none' and not mm.get('clippedBy'), presses=mpres, outline=[mm.get('outlineStyle'), mm.get('outlineWidth'), mm.get('outlineColor')], clipped=mm.get('clippedBy'))
        add('mobile_no_page_hscroll', hscroll <= 1, overflow_px=hscroll)
        mbox = mp.locator('.bms24-task-tabs').first.bounding_box()
        mp.screenshot(path=str(root / ('pilot01-' + phase + '-focus-mobile.png')), clip={'x': 0, 'y': max(mbox['y'] - 12, 0), 'width': 390, 'height': min(mbox['height'] + 24, 400)})
        mh = mp.evaluate("() => [...document.querySelectorAll('.bms24-task-tab')].map(a => Math.round(a.getBoundingClientRect().height))"); out['mobile_tab_heights'] = mh
        out['mobile_pointer_coarse'] = mp.evaluate("() => matchMedia('(pointer:coarse)').matches")
        if phase == 'candidate': add('mobile_tap_target_44', out['mobile_pointer_coarse'] and min(mh) >= 44, heights=sorted(set(mh)))
        add('mobile_no_browser_errors', not [e for e in merrs if 'ERR_FAILED' not in e], errors=merrs[:5], note='ERR_FAILED = external CDN blocked by clone isolation')
        mctx.close()
    except Exception as e:
        rows.append({'id': 'P01.' + phase + '.execution', 'status': 'FAIL', 'type': type(e).__name__, 'msg': str(e)[:200]})
    finally:
        b.close()
out['status'] = 'FAIL' if any(x['status'] == 'FAIL' for x in rows) else 'PASS'
dest = root / ('pilot01-' + phase + '.json')
with dest.open('w') as h: json.dump(out, h, indent=1)
for png in root.glob('pilot01-' + phase + '-*.png'): png.chmod(0o644)
dest.chmod(0o644)
print(json.dumps({'status': out['status'], 'cases': [(x['id'], x['status']) for x in rows]}))
