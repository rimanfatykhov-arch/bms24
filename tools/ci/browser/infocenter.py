"""Real Moodle acceptance of the Information Centre by divisions (#190) on Apache ECharts (#192); synthetic data, loopback only.

Fixture (ci_infocenter.php): company A gets a default organizing-board branch with three divisions inserted out of board
order (one without metrics), a department and a position, and weekly metrics bound through the registry (main company
metric rising with a plan and one missing week, position metric falling with direction "down", department metric with a
single point, division metrics with negative values and a real zero / a flat series / no values, one unbound metric);
company B gets its own main metric.
Cases (INFOCENTER_CASES): owned 1440, owned 375, legacy 375. Per case MA (company A) opens the page; the producer records
how many mini charts were drawn before scrolling (lazy initialisation) and the requests for the ECharts module, scrolls
every mini chart into view and records the column order, groups and tiles (trend text, assessment class, the mini chart
read back from its ECharts instance: dots, line pieces, grid lines, no data zoom, rendered SVG, height, "no data"), page
scroll width and column boxes, and the mini chart width before/after a viewport resize. Then it opens the main metric
chart and records the view state (data-* of the chart container and, independently, the ECharts option: data zoom
window, value axis, series, gaps) after the window choice, zoom/pan/reset by buttons, keyboard (arrows skip the gap,
Shift+arrows, +, 0, Home, End), a mouse click on a date, mouse wheel, mouse drag, a two-finger pinch (real touch input
where the page has touch events, otherwise two touch pointers), a viewport resize to 75% of the width (at least 320 px) and Escape (focus back on the tile,
instance disposed); then the flat, negative, single-point, empty and falling charts, and the main chart re-opened.
Screenshots: infocenter-<case>-overview.png, infocenter-<case>-chart.png and infocenter-<case>-chart-negative.png.
MB (company B) opens company A's and its own page. Observations only -> EVIDENCE/infocenter-result.json; the verdict is
computed by contract.infocenter_checks.
"""
import json
import os
import subprocess
import sys
import urllib.parse
from pathlib import Path

sys.path.insert(0, str(Path(__file__).resolve().parent))
from contract import INFOCENTER_CASES, local_url, require  # noqa: E402

ECHARTS = "window.require(['local_kbacompany/echarts-lazy'], res, rej)"

LAZY = '''() => ({total: document.querySelectorAll('[data-bmsic-mini]').length,
    ready: document.querySelectorAll('[data-bmsic-mini][data-engine]').length})'''

OVERVIEW = '''async () => {
    const ec = await new Promise((res, rej) => ''' + ECHARTS + ''');
    const text = el => el ? el.textContent.replace(/\\s+/g, ' ').trim() : '';
    const box = el => { const r = el.getBoundingClientRect();
        return {x: Math.round(r.left), y: Math.round(r.top + window.scrollY), r: Math.round(r.right), w: Math.round(r.width)}; };
    const val = v => v === null || v === undefined ? null : (typeof v === 'object' ? (v.value === undefined ? null : v.value) : v);
    const runs = a => { let c = 0, r = 0; a.forEach(v => { if (v === null) { if (r > 1) { c++; } r = 0; } else { r++; } });
        return c + (r > 1 ? 1 : 0); };
    const mini = m => {
        if (!m) { return {engine: null, dots: 0, lines: 0, grid: 0, datazoom: 0, svg: 0, paths: 0, height: 0, attr: null}; }
        const inst = ec.getInstanceByDom(m); const o = inst ? inst.getOption() : null;
        const fact = o ? (o.series || []).find(s => s.id === 'fact') : null;
        const data = fact ? (fact.data || []).map(val) : [];
        const ya = o && o.yAxis ? o.yAxis[0] : null; const svg = m.querySelector('svg');
        return {engine: m.getAttribute('data-engine'), instance: !!inst, dots: data.filter(v => v !== null).length,
            lines: runs(data), grid: ya && ya.interval > 0 ? Math.round((ya.max - ya.min) / ya.interval) + 1 : 0,
            gaps: fact ? data.length - data.filter(v => v !== null).length : -1,
            datazoom: o ? (o.dataZoom || []).length : -1, connect: fact ? fact.connectNulls : null,
            symbols: fact ? !!(fact.showSymbol && fact.showAllSymbol) : false, silent: fact ? fact.silent === true : false,
            svg: svg ? 1 : 0, paths: svg ? svg.querySelectorAll('path').length : 0,
            svg_w: svg ? Number(svg.getAttribute('width')) : 0, box_w: m.clientWidth,
            height: Math.round(m.getBoundingClientRect().height), pointer: getComputedStyle(m).pointerEvents,
            attr: {dots: Number(m.getAttribute('data-dots')), lines: Number(m.getAttribute('data-lines')),
                grid: Number(m.getAttribute('data-grid'))}};
    };
    const tile = t => {
        let data = null; try { data = JSON.parse(t.getAttribute('data-bmsic-chart')); } catch (e) { data = null; }
        const trend = t.querySelector('.bmsic-trend'); const m = mini(t.querySelector('[data-bmsic-mini]'));
        return {name: text(t.querySelector('.bmsic-tile-name')), value: text(t.querySelector('.bmsic-tile-value')),
            trend: text(trend), symbol: text(t.querySelector('.bmsic-trend-symbol')),
            better: !!(trend && trend.classList.contains('bmsic-q-better')),
            worse: !!(trend && trend.classList.contains('bmsic-q-worse')),
            nodata: text(t.querySelector('.bmsic-nodata')), single: t.querySelectorAll('.bmsic-muted').length,
            dots: m.dots, lines: m.lines, grid: m.grid, mini: m, range: text(t.querySelector('.bmsic-range')),
            tag: t.tagName, type: t.getAttribute('type'), aria: t.getAttribute('aria-label') || '',
            haspopup: t.getAttribute('aria-haspopup'), points: data && Array.isArray(data.points) ? data.points.length : -1,
            gaps: data && Array.isArray(data.points) ? data.points.filter(p => p.gap).length : -1};
    };
    const board = document.querySelector('.bmsic-board');
    return {
        main: [...document.querySelectorAll('section[aria-labelledby=bmsic-main-h] [data-bmsic-chart]')].map(tile),
        columns: [...document.querySelectorAll('.bmsic-board > .bmsic-col')].map(c => ({
            title: text(c.querySelector('h3')), role: c.getAttribute('role'), box: box(c),
            empty: c.querySelectorAll(':scope > p.bmsic-muted').length,
            groups: [...c.querySelectorAll('.bmsic-group')].map(g => ({kind: text(g.querySelector('.bmsic-kind')),
                title: text(g.querySelector('h4')), tiles: [...g.querySelectorAll('[data-bmsic-chart]')].map(tile)}))})),
        unbound: [...document.querySelectorAll('section[aria-labelledby=bmsic-unbound-h] [data-bmsic-chart]')].map(tile),
        board_role: board ? board.getAttribute('role') : null,
        page_scroll: document.documentElement.scrollWidth, page_client: document.documentElement.clientWidth,
        inner: window.innerWidth, dialog_open: !!(document.getElementById('bmsic-dialog') || {}).open,
        all_tiles: document.querySelectorAll('[data-bmsic-chart]').length};
}'''

# A column tile: its width follows the viewport (the main tile's mini chart is capped at 760 px).
MINI_SIZE = '''() => { const m = document.querySelector('.bmsic-board [data-bmsic-mini]');
    const svg = m ? m.querySelector('svg') : null;
    return {svg_w: svg ? Number(svg.getAttribute('width')) : 0, box_w: m ? m.clientWidth : 0}; }'''

SNAP = '''async () => {
    const ec = await new Promise((res, rej) => ''' + ECHARTS + ''');
    const d = document.getElementById('bmsic-dialog'); const box = d.querySelector('[data-bmsic=chart]');
    const canvas = box.querySelector('.bmsic-canvas'); const svg = canvas ? canvas.querySelector('svg') : null;
    const a = k => box.getAttribute('data-' + k); const num = k => a(k) === null ? null : Number(a(k));
    const inst = canvas ? ec.getInstanceByDom(canvas) : null;
    const val = v => v === null || v === undefined ? null : (typeof v === 'object' ? (v.value === undefined ? null : v.value) : v);
    const runs = arr => { let c = 0, r = 0; arr.forEach(v => { if (v === null) { if (r > 1) { c++; } r = 0; } else { r++; } });
        return c + (r > 1 ? 1 : 0); };
    let opt = null;
    if (inst) {
        const o = inst.getOption(); const dz = (o.dataZoom || [])[0] || {}; const cats = (o.xAxis || [{}])[0].data || [];
        const ser = id => (o.series || []).find(s => s.id === id) || null;
        const fact = ser('fact'), plan = ser('plan'), trend = ser('trend');
        const s0 = dz.startValue, s1 = dz.endValue;
        const win = s => (s && s.data ? s.data : []).slice(s0, s1 + 1).map(val);
        const fw = win(fact), pw = win(plan), tw = win(trend);
        const areas = fact && fact.markArea && fact.markArea.data ? fact.markArea.data : [];
        const gapAt = area => cats.indexOf(area[0].xAxis) + 1;
        const marks = fact && fact.markLine && fact.markLine.data ? fact.markLine.data : [];
        const sel = marks.find(m => m.xAxis !== undefined);
        opt = {start: cats[s0], end: cats[s1], span: s1 - s0, lo: o.yAxis[0].min, hi: o.yAxis[0].max,
            dots: fw.filter(v => v !== null).length, factlines: runs(fw), planlines: runs(pw),
            trendlines: tw.filter(v => v !== null).length >= 2 ? 1 : 0,
            gaps: areas.filter(ar => gapAt(ar) >= s0 && gapAt(ar) <= s1).length, selected: sel ? sel.xAxis : null,
            zero_line: marks.some(m => m.yAxis === 0), series: (o.series || []).map(s => s.id),
            fact_connect: fact ? fact.connectNulls : null, plan_connect: plan ? plan.connectNulls : null,
            trend_type: trend && trend.lineStyle ? trend.lineStyle.type : null,
            symbols: fact ? !!(fact.showSymbol && fact.showAllSymbol) : false,
            inside: dz.type === 'inside' && dz.zoomOnMouseWheel === true && dz.moveOnMouseMove === true,
            filter: dz.filterMode || null, width: inst.getWidth()};
    }
    const active = document.activeElement;
    return {open: d.open, title: (document.getElementById('bmsic-dlg-title') || {}).textContent || '',
        meta: d.querySelector('[data-bmsic=meta]').textContent, window: d.querySelector('[data-bmsic=window]').value,
        visible: d.querySelector('[data-bmsic=visible]').textContent, readout: d.querySelector('[data-bmsic=readout]').textContent,
        legend: d.querySelectorAll('[data-bmsic=legend] > span').length, engine: a('engine'),
        start: a('start'), end: a('end'), span: num('span'), selected: a('selected'), lo: num('lo'), hi: num('hi'),
        empty: a('empty') === '1', empty_text: (box.querySelector('.bmsic-empty:not([hidden])') || {}).textContent || '',
        gaps: num('gaps') || 0, factlines: num('factlines') || 0, planlines: num('planlines') || 0,
        trendlines: num('trendlines') || 0, trend_dash: opt && opt.trend_type === 'dashed' && opt.trendlines ? 'dashed' : null,
        dots: num('dots') || 0, texts: svg ? [...svg.querySelectorAll('text')].map(t => t.textContent) : [],
        paths: svg ? svg.querySelectorAll('path').length : 0, instance: !!inst,
        svg_w: svg ? Number(svg.getAttribute('width')) : 0, canvas_w: canvas ? canvas.clientWidth : 0, opt: opt,
        focus_in_chart: !!(active && active.closest && active.closest('[data-bmsic=chart]')),
        focus_tile: active && active.getAttribute ? (active.querySelector('.bmsic-tile-name') || {}).textContent || '' : '',
        box: (() => { const r = d.getBoundingClientRect(); return {x: Math.round(r.left), r: Math.round(r.right)}; })()};
}'''

# Two touch pointers on a page without touch events (the module's pointer fallback handles them).
PINCH = '''([spread]) => {
    const box = document.querySelector('#bmsic-dialog [data-bmsic=chart]'); const r = box.getBoundingClientRect();
    const cx = r.left + r.width / 2, cy = r.top + r.height / 2;
    const fire = (type, id, x) => box.dispatchEvent(new PointerEvent(type, {pointerId: id, pointerType: 'touch',
        clientX: x, clientY: cy, isPrimary: id === 11, bubbles: true, cancelable: true}));
    fire('pointerdown', 11, cx - 20); fire('pointerdown', 12, cx + 20);
    for (let k = 1; k <= 4; k++) { fire('pointermove', 11, cx - 20 - k * spread); fire('pointermove', 12, cx + 20 + k * spread); }
    fire('pointerup', 11, cx - 20 - 4 * spread); fire('pointerup', 12, cx + 20 + 4 * spread);
}'''

# Client coordinates of a category index inside the chart (ECharts public convertToPixel), for a real mouse click.
POINT_OF = '''async ([index]) => {
    const ec = await new Promise((res, rej) => ''' + ECHARTS + ''');
    const canvas = document.querySelector('#bmsic-dialog .bmsic-canvas'); const inst = ec.getInstanceByDom(canvas);
    const r = canvas.getBoundingClientRect(); const x = inst.convertToPixel({xAxisIndex: 0}, index);
    return {x: r.left + x, y: r.top + r.height / 2};
}'''


def touch_pinch(ctx, page, spread):
    """Real two-finger touch input (CDP) on the chart centre, fingers moving apart."""
    b = page.locator('#bmsic-dialog [data-bmsic=chart]').bounding_box()
    cx = b['x'] + b['width'] / 2; cy = b['y'] + b['height'] / 2
    cdp = ctx.new_cdp_session(page)
    pts = lambda d: [{'x': cx - d, 'y': cy, 'id': 0}, {'x': cx + d, 'y': cy, 'id': 1}]  # noqa: E731
    cdp.send('Input.dispatchTouchEvent', {'type': 'touchStart', 'touchPoints': pts(20)})
    for k in range(1, 5):
        page.wait_for_timeout(40)
        cdp.send('Input.dispatchTouchEvent', {'type': 'touchMove', 'touchPoints': pts(20 + k * spread)})
    cdp.send('Input.dispatchTouchEvent', {'type': 'touchEnd', 'touchPoints': []})
    cdp.detach()


def exercise(ctx, page, case, width, root):
    """Per-case observations on the loaded Information Centre page (also used by local harnesses)."""
    page.wait_for_function('() => document.querySelectorAll("[data-bmsic-mini][data-engine]").length > 0')
    case['lazy_initial'] = page.evaluate(LAZY)
    minis = page.locator('[data-bmsic-mini]')
    for k in range(minis.count()):
        minis.nth(k).scroll_into_view_if_needed()
        page.wait_for_timeout(50)
    page.wait_for_function('() => document.querySelectorAll("[data-bmsic-mini]:not([data-engine])").length === 0')
    page.evaluate('() => window.scrollTo(0, 0)')
    case['lazy_final'] = page.evaluate(LAZY)
    case['overview'] = page.evaluate(OVERVIEW)
    page.screenshot(path=str(root / ('infocenter-%s-overview.png' % case['id'])), full_page=True)
    # Mini chart follows a viewport resize.
    # Narrower viewport for the resize checks (the dialog is capped at 1100 px on the desktop).
    narrow = max(320, int(width * 0.75))
    resize = {'mini_before': page.evaluate(MINI_SIZE)}
    page.set_viewport_size({'width': narrow, 'height': 900}); page.wait_for_timeout(500)
    resize['mini_after'] = page.evaluate(MINI_SIZE)
    page.set_viewport_size({'width': width, 'height': 900}); page.wait_for_timeout(500)
    # Main metric chart: window 13 on open, then 26; buttons, keyboard, click, wheel, drag, pinch, reset, Escape.
    steps = {'open': open_chart(page, 'CIIC-M')}
    page.locator('#bmsic-window').select_option('26'); steps['window26'] = snap(page)
    page.locator('[data-bmsic=zoomin]').click(); steps['zoomin'] = snap(page)
    page.locator('[data-bmsic=panleft]').click(); steps['panleft'] = snap(page)
    page.locator('[data-bmsic=panright]').click(); steps['panright'] = snap(page)
    page.locator('[data-bmsic=zoomout]').click(); steps['zoomout'] = snap(page)
    page.locator('[data-bmsic=reset]').click(); steps['reset'] = snap(page)
    page.locator('#bmsic-dialog [data-bmsic=chart]').focus()
    for k in range(5):
        page.keyboard.press('ArrowLeft'); steps['left%d' % (k + 1)] = snap(page)
    page.keyboard.press('+'); steps['key_plus'] = snap(page)
    page.keyboard.press('Shift+ArrowLeft'); steps['key_shift_left'] = snap(page)
    page.keyboard.press('0'); steps['key_reset'] = snap(page)
    page.keyboard.press('Home'); steps['key_home'] = snap(page)
    page.keyboard.press('End'); steps['key_end'] = snap(page)
    # Mouse click on the fourth period from the end (full 20-period window: index 17 of 21 points).
    target = page.evaluate(POINT_OF, [17])
    page.mouse.click(target['x'], target['y']); page.wait_for_timeout(150); steps['click'] = snap(page)
    b = chart_box(page); cx = b['x'] + b['width'] / 2; cy = b['y'] + b['height'] / 2
    page.mouse.move(cx, cy)
    page.mouse.wheel(0, -300); page.wait_for_timeout(300); steps['wheel_in'] = snap(page)
    # Drag stays inside the chart (35% of its width).
    stride = b['width'] * 0.35 / 8
    page.mouse.move(cx, cy); page.mouse.down()
    for k in range(1, 9):
        page.mouse.move(cx + k * stride, cy)
    page.mouse.up(); page.wait_for_timeout(300); steps['drag_right'] = snap(page)
    page.locator('[data-bmsic=reset]').click(); steps['reset2'] = snap(page)
    touch = page.evaluate("() => 'ontouchstart' in window")
    if touch:
        touch_pinch(ctx, page, 30)
    else:
        page.evaluate(PINCH, [30])
    page.wait_for_timeout(300); steps['pinch_out'] = snap(page)
    steps['pinch_out']['pinch_mode'] = 'touch' if touch else 'pointer'
    page.locator('[data-bmsic=reset]').click()
    page.set_viewport_size({'width': narrow, 'height': 900}); page.wait_for_timeout(500)
    steps['resized'] = snap(page)
    page.set_viewport_size({'width': width, 'height': 900}); page.wait_for_timeout(500)
    steps['restored'] = snap(page)
    page.screenshot(path=str(root / ('infocenter-%s-chart.png' % case['id'])))
    close_chart(page); steps['closed'] = snap(page)
    case['main_chart'] = steps
    case['resize'] = resize
    # The other shapes, then the main chart again (a fresh instance with a fresh view).
    case['charts'] = {}
    for code in ('CIIC-FLAT', 'CIIC-NEG', 'CIIC-DEP', 'CIIC-EMPTY', 'CIIC-POS'):
        tile(page, code).scroll_into_view_if_needed()
        case['charts'][code] = open_chart(page, code)
        if code == 'CIIC-NEG':
            page.screenshot(path=str(root / ('infocenter-%s-chart-negative.png' % case['id'])))
        close_chart(page)
        case['charts'][code]['closed'] = snap(page)
    tile(page, 'CIIC-M').scroll_into_view_if_needed()
    case['reopen'] = open_chart(page, 'CIIC-M')
    close_chart(page)
    case['reopen']['closed'] = snap(page)


def main():
    from events import Recorder
    from playwright.sync_api import sync_playwright
    require(os.environ.get('BMS_CI_BROWSER') == '1', 'CI browser fixture only')
    here = Path(__file__).resolve().parent
    root = Path(os.environ['EVIDENCE']); core = os.environ['MOODLE_ROOT']; base = 'http://127.0.0.1:8080'
    f = json.loads((root / 'actors.private.json').read_text())
    company = f['companies']['A']['uuid']; other = f['companies']['B']['uuid']
    page_url = base + '/local/kbacompany/information_center.php'
    result = {'status': 'FAIL', 'cases': []}

    def php(*args):
        process = subprocess.run(['php', str(here / 'ci_infocenter.php'), core, *args], capture_output=True, text=True)
        if process.returncode:
            diagnostic = next((line for line in process.stderr.splitlines() if line.startswith('CI infocenter fixture: ')), '')
            raise RuntimeError(f'{args[0]} exited {process.returncode}. {diagnostic}'.rstrip())
        return json.loads(process.stdout.strip().splitlines()[-1])

    def login(ctx, label):
        a = f['actors'][label]; p = ctx.new_page(); p.set_default_timeout(30000)
        p.goto(local_url(base + '/login/index.php'), wait_until='networkidle')
        p.locator('#username').fill(a['username']); p.locator('#password').fill(a['password'])
        p.locator('#loginbtn').click(); p.wait_for_load_state('networkidle')
        return p

    def url(uuid_):
        return local_url(page_url + '?' + urllib.parse.urlencode({'uuid': uuid_}))

    setup = None
    page = None
    with sync_playwright() as pw:
        browser = pw.chromium.launch(headless=True, args=['--disable-dev-shm-usage'])
        try:
            setup = php('setup', company, other)
            result['fixture'] = {'metrics': setup['metrics'], 'divisions': setup['divisions']}
            for cid, shell, width in INFOCENTER_CASES:
                php('shell', shell)
                case = {'id': cid, 'shell': shell, 'width': width}
                recorder = Recorder(); server_errors = []; module_requests = []
                ctx = browser.new_context(viewport={'width': width, 'height': 900}, has_touch=width < 768)
                recorder.install(ctx)
                page = login(ctx, 'MA')
                page.on('response', lambda r: server_errors.append({'url': r.url, 'status': r.status}) if r.status >= 500 else None)
                page.on('request', lambda r: module_requests.append(r.url) if 'echarts' in r.url else None)
                response = page.goto(url(company), wait_until='networkidle')
                case['http'] = response.status if response else None
                case['owned'] = page.locator('.bms24-app').count() > 0
                exercise(ctx, page, case, width, root)
                case['module_requests'] = module_requests
                case['browser_events'] = recorder.events; case['server_errors'] = server_errors
                ctx.close()
                result['cases'].append(case)
            # Company B manager: company A's page shows nothing of A, own page shows only B.
            octx = browser.new_context(); op = login(octx, 'MB')
            foreign = {}
            for key, target in (('company_a', company), ('company_b', other)):
                response = op.goto(url(target), wait_until='networkidle')
                body = op.locator('body').inner_text()
                foreign[key] = {'http': response.status if response else None,
                                'tiles': op.locator('[data-bmsic-chart]').count(),
                                'names': op.locator('[data-bmsic-chart] .bmsic-tile-name').all_inner_texts(),
                                'a_codes_in_body': sorted(c for c, m in setup['metrics'].items() if m['company'] == 'A' and c in body),
                                'a_divisions_in_body': sorted(d for d in setup['divisions'] if d in body)}
            octx.close()
            result['foreign'] = foreign
            result['status'] = 'PASS'
        except Exception as error:
            result['error'] = '%s: %s' % (type(error).__name__, str(error)[:400])
            try:
                if page is not None and not page.is_closed():
                    page.screenshot(path=str(root / 'infocenter-failure.png'), full_page=True)
            except Exception as diagnostic:
                result['diagnostic_error'] = str(diagnostic)
        finally:
            try:
                php('shell', 'owned')
                if setup is not None:
                    result['cleanup'] = php('cleanup', company, other, ','.join(setup.get('prior_defaults') or []))
            except Exception as error:
                result['cleanup_error'] = '%s: %s' % (type(error).__name__, str(error)[:200])
            browser.close()
            dest = root / 'infocenter-result.json'; dest.write_text(json.dumps(result, indent=1, ensure_ascii=False)); dest.chmod(0o644)
    print(json.dumps({'status': result['status'], 'cases': len(result['cases']), 'error': result.get('error')}))
    return 0 if result['status'] == 'PASS' else 1


def tile(page, code):
    return page.locator('[data-bmsic-chart]').filter(has=page.locator('.bmsic-tile-name', has_text=code + ' — ')).first


def snap(page):
    return page.evaluate(SNAP)


def chart_box(page):
    return page.locator('#bmsic-dialog [data-bmsic=chart]').bounding_box()


def open_chart(page, code):
    tile(page, code).click()
    page.wait_for_function('() => document.getElementById("bmsic-dialog").open')
    return snap(page)


def close_chart(page):
    page.keyboard.press('Escape')
    page.wait_for_function('() => !document.getElementById("bmsic-dialog").open')


if __name__ == '__main__':
    sys.exit(main())
