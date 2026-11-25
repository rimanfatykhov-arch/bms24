"""Real Moodle acceptance of the Information Centre by divisions (#190); synthetic data, loopback only.

Fixture (ci_infocenter.php): company A gets a default organizing-board branch with three divisions inserted out of board
order (one without metrics), a department and a position, and weekly metrics bound through the registry (main company
metric rising with a plan and one missing week, position metric falling with direction "down", department metric with a
single point, division metrics with negative values and a real zero / a flat series / no values, one unbound metric);
company B gets its own main metric.
Cases (INFOCENTER_CASES): owned 1440, owned 375, legacy 375. Per case MA (company A) opens the page and the producer
records the column order, groups and tiles (trend text, assessment class, mini chart dots/lines, "no data"), page
scroll width and column boxes; then opens the main metric chart and records the view state after the window choice,
zoom/pan/reset by buttons, keyboard (arrows skip the gap, Shift+arrows, +, 0, Home), mouse wheel, mouse drag and a
two-pointer pinch, and Escape (focus back on the tile); then the flat, negative, single-point and empty charts.
Screenshots: infocenter-<case>-overview.png and infocenter-<case>-chart.png. MB (company B) opens company A's and its own
page. Observations only -> EVIDENCE/infocenter-result.json; the verdict is computed by contract.infocenter_checks.
"""
import json
import os
import subprocess
import sys
import urllib.parse
from pathlib import Path

sys.path.insert(0, str(Path(__file__).resolve().parent))
from contract import INFOCENTER_CASES, local_url, require  # noqa: E402
from events import Recorder  # noqa: E402
from playwright.sync_api import sync_playwright  # noqa: E402

require(os.environ.get('BMS_CI_BROWSER') == '1', 'CI browser fixture only')
here = Path(__file__).resolve().parent
root = Path(os.environ['EVIDENCE']); core = os.environ['MOODLE_ROOT']; base = 'http://127.0.0.1:8080'
f = json.loads((root / 'actors.private.json').read_text())
company = f['companies']['A']['uuid']; other = f['companies']['B']['uuid']
PAGE = base + '/local/kbacompany/information_center.php'
result = {'status': 'FAIL', 'cases': []}

OVERVIEW = '''() => {
    const text = el => el ? el.textContent.replace(/\\s+/g, ' ').trim() : '';
    const box = el => { const r = el.getBoundingClientRect();
        return {x: Math.round(r.left), y: Math.round(r.top + window.scrollY), r: Math.round(r.right), w: Math.round(r.width)}; };
    const tile = t => {
        let data = null; try { data = JSON.parse(t.getAttribute('data-bmsic-chart')); } catch (e) { data = null; }
        const trend = t.querySelector('.bmsic-trend');
        return {name: text(t.querySelector('.bmsic-tile-name')), value: text(t.querySelector('.bmsic-tile-value')),
            trend: text(trend), symbol: text(t.querySelector('.bmsic-trend-symbol')),
            better: !!(trend && trend.classList.contains('bmsic-q-better')),
            worse: !!(trend && trend.classList.contains('bmsic-q-worse')),
            nodata: text(t.querySelector('.bmsic-nodata')), single: t.querySelectorAll('.bmsic-muted').length,
            dots: t.querySelectorAll('svg.bmsic-spark circle').length, lines: t.querySelectorAll('svg.bmsic-spark polyline').length,
            grid: t.querySelectorAll('svg.bmsic-spark line').length, range: text(t.querySelector('.bmsic-range')),
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

SNAP = '''() => {
    const d = document.getElementById('bmsic-dialog'); const svg = d.querySelector('[data-bmsic=chart] svg');
    const a = k => svg ? svg.getAttribute(k) : null; const n = s => svg ? svg.querySelectorAll(s).length : 0;
    const active = document.activeElement;
    return {open: d.open, title: (document.getElementById('bmsic-dlg-title') || {}).textContent || '',
        meta: d.querySelector('[data-bmsic=meta]').textContent, window: d.querySelector('[data-bmsic=window]').value,
        visible: d.querySelector('[data-bmsic=visible]').textContent, readout: d.querySelector('[data-bmsic=readout]').textContent,
        legend: d.querySelectorAll('[data-bmsic=legend] > span').length,
        start: a('data-start'), end: a('data-end'), span: a('data-span') === null ? null : Number(a('data-span')),
        selected: a('data-selected'), lo: a('data-lo') === null ? null : Number(a('data-lo')),
        hi: a('data-hi') === null ? null : Number(a('data-hi')), empty: a('data-empty') === '1',
        gaps: n('.bmsic-gap'), factlines: n('.bmsic-factline'), planlines: n('.bmsic-planline'),
        trendlines: n('.bmsic-trendline'), trend_dash: svg && svg.querySelector('.bmsic-trendline') ?
            svg.querySelector('.bmsic-trendline').getAttribute('stroke-dasharray') : null, dots: n('.bmsic-dot'),
        texts: svg ? [...svg.querySelectorAll('text')].map(t => t.textContent) : [],
        focus_in_chart: !!(active && active.closest && active.closest('[data-bmsic=chart]')),
        focus_tile: active && active.getAttribute ? (active.querySelector('.bmsic-tile-name') || {}).textContent || '' : '',
        box: (() => { const r = d.getBoundingClientRect(); return {x: Math.round(r.left), r: Math.round(r.right)}; })()};
}'''

PINCH = '''([spread]) => {
    const box = document.querySelector('#bmsic-dialog [data-bmsic=chart]'); const r = box.getBoundingClientRect();
    const cx = r.left + r.width / 2, cy = r.top + r.height / 2;
    const fire = (type, id, x) => box.dispatchEvent(new PointerEvent(type, {pointerId: id, pointerType: 'touch',
        clientX: x, clientY: cy, isPrimary: id === 11, bubbles: true, cancelable: true}));
    fire('pointerdown', 11, cx - 20); fire('pointerdown', 12, cx + 20);
    for (let k = 1; k <= 4; k++) { fire('pointermove', 11, cx - 20 - k * spread); fire('pointermove', 12, cx + 20 + k * spread); }
    fire('pointerup', 11, cx - 20 - 4 * spread); fire('pointerup', 12, cx + 20 + 4 * spread);
}'''


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
    return local_url(PAGE + '?' + urllib.parse.urlencode({'uuid': uuid_}))


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


setup = None
with sync_playwright() as pw:
    browser = pw.chromium.launch(headless=True, args=['--disable-dev-shm-usage'])
    try:
        setup = php('setup', company, other)
        result['fixture'] = {'metrics': setup['metrics'], 'divisions': setup['divisions']}
        for cid, shell, width in INFOCENTER_CASES:
            php('shell', shell)
            case = {'id': cid, 'shell': shell, 'width': width}
            recorder = Recorder(); server_errors = []
            ctx = browser.new_context(viewport={'width': width, 'height': 900}, has_touch=width < 768)
            recorder.install(ctx)
            page = login(ctx, 'MA')
            page.on('response', lambda r: server_errors.append({'url': r.url, 'status': r.status}) if r.status >= 500 else None)
            response = page.goto(url(company), wait_until='networkidle')
            case['http'] = response.status if response else None
            case['owned'] = page.locator('.bms24-app').count() > 0
            case['overview'] = page.evaluate(OVERVIEW)
            page.screenshot(path=str(root / ('infocenter-%s-overview.png' % cid)), full_page=True)
            # Main metric chart: window 13 on open, then 26; buttons, keyboard, wheel, drag, pinch, reset, Escape.
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
            b = chart_box(page); cx = b['x'] + b['width'] / 2; cy = b['y'] + b['height'] / 2
            page.mouse.move(cx, cy)
            page.mouse.wheel(0, -300); page.wait_for_timeout(150); steps['wheel_in'] = snap(page)
            page.mouse.move(cx, cy); page.mouse.down()
            for k in range(1, 9):
                page.mouse.move(cx + k * 25, cy)
            page.mouse.up(); steps['drag_right'] = snap(page)
            page.locator('[data-bmsic=reset]').click(); steps['reset2'] = snap(page)
            page.evaluate(PINCH, [30]); steps['pinch_out'] = snap(page)
            page.locator('[data-bmsic=reset]').click()
            page.screenshot(path=str(root / ('infocenter-%s-chart.png' % cid)))
            close_chart(page); steps['closed'] = snap(page)
            case['main_chart'] = steps
            # The other shapes.
            case['charts'] = {}
            for code in ('CIIC-FLAT', 'CIIC-NEG', 'CIIC-DEP', 'CIIC-EMPTY', 'CIIC-POS'):
                tile(page, code).scroll_into_view_if_needed()
                case['charts'][code] = open_chart(page, code)
                if code == 'CIIC-NEG':
                    page.screenshot(path=str(root / ('infocenter-%s-chart-negative.png' % cid)))
                close_chart(page)
                case['charts'][code]['closed'] = snap(page)
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
            if 'page' in locals() and not page.is_closed():
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
sys.exit(0 if result['status'] == 'PASS' else 1)
