"""UX-1 observations on real disposable Moodle; synthetic actors, loopback origin only."""
import json
import os
import subprocess
import sys
from pathlib import Path
from urllib.parse import parse_qs, urlencode, urlparse
from playwright.sync_api import sync_playwright
from contract import UX_NAV_STEPS, local_url, require
from events import Recorder

require(os.environ.get('BMS_CI_BROWSER') == '1', 'disposable browser only')
root = Path(os.environ['EVIDENCE'])
here = Path(__file__).resolve().parent
core = os.environ['MOODLE_ROOT']
f = json.loads((root / 'actors.private.json').read_text())
company = f['companies']['A']['uuid']
base = 'http://127.0.0.1:8080'
result = {'status': 'FAIL', 'companyuuid': company, 'cases': []}


def shell(mode):
    subprocess.run(['php', str(here / 'ci_message_actions.php'), core, 'shell', mode], check=True, capture_output=True)


def navigate(page, kind, key):
    """One real user click on a visible section link or filter chip; the observation is what the browser then shows."""
    nav = page.locator('.bms-comm-ux-sections a' if kind == 'section' else '.bms-comm-ux-filters a.bms-comm-ux-chip')
    field = 'tab' if kind == 'section' else 'filter'
    target = [a for a in nav.all() if parse_qs(urlparse(a.get_attribute('href') or '').query).get(field) == [key]]
    require(len(target) == 1 and target[0].is_visible(), 'visible %s link %s missing' % (kind, key))
    with page.expect_navigation() as info:
        target[0].click()
    page.wait_for_load_state('networkidle')
    section = page.locator('.bms-comm-ux-sections a[aria-current=page]')
    chip = page.locator('.bms-comm-ux-chip[aria-current=true]')
    return {'kind': kind, 'key': key, 'http': info.value.status if info.value else None, 'url': page.url,
            'active_section': section.get_attribute('href') if section.count() == 1 else None,
            'active_sections': section.count(),
            'active_filter': chip.get_attribute('href') if chip.count() == 1 else None, 'active_filters': chip.count(),
            'notifications': page.locator('.bms-comm-ux-notifications[data-section=notifications]').count(),
            'layout': page.locator('.bms-comm2-layout').count(),
            'filters': page.locator('.bms-comm-ux-filters').count(),
            'overflow': page.evaluate('document.documentElement.scrollWidth > innerWidth + 1')}


# Rendered geometry of one Center section (#145/#152). Measures what is drawn, not what is in the DOM:
# the primary action box against its own label, the card header and the section tabs; clipped control labels;
# the list search width; whether the Center stylesheet is applied (its custom property on the root).
GEOMETRY_JS = r'''() => {
    const root = document.querySelector('#bms24-comm-root');
    const vis = (e) => !!e && e.checkVisibility() && e.getClientRects().length > 0;
    const box = (e) => e.getBoundingClientRect();
    const out = {root: !!root, overflow: document.documentElement.scrollWidth > innerWidth + 1};
    out.css = !!root && getComputedStyle(root).getPropertyValue('--cc-emerald').trim() !== '';
    const triggers = [...document.querySelectorAll('summary.bms-comm2-new-trigger')].filter(vis);
    const head = root && root.querySelector('.bms24-cardheader');
    const t = triggers.length ? triggers[0] : null;
    if (t && head) {
        const b = box(t), h = box(head), tabs = box(root.querySelector('.bms-comm-ux-sections'));
        const details = t.closest('details');
        const form = details && details.querySelector('form[method=post]');
        out.action = {count: triggers.length, label: t.innerText.replace(/\s+/g, ' ').trim(),
            width: Math.round(b.width), height: Math.round(b.height), scroll_width: t.scrollWidth, client_width: t.clientWidth,
            in_header: head.contains(t) && b.left >= h.left - 0.5 && b.right <= h.right + 0.5
                && b.top >= h.top - 0.5 && b.bottom <= h.bottom + 0.5,
            overlaps_tabs: !(b.right <= tabs.left || b.left >= tabs.right || b.bottom <= tabs.top || b.top >= tabs.bottom),
            in_form: !!form && !!form.querySelector('input[name=sesskey]')};
    } else {
        out.action = null;
    }
    const controls = root ? root.querySelectorAll('.bms-comm-ux-sections a, .bms-comm-ux-sections a > span, '
        + '.bms-comm-ux-filters a, .bms24-comm-ntools a, .bms24-comm-btn, summary.bms-comm2-new-trigger') : [];
    out.clipped = [...controls].filter((e) => vis(e) && e.clientWidth > 1 && e.scrollWidth > e.clientWidth + 1)
        .map((e) => e.innerText.replace(/\s+/g, ' ').trim().slice(0, 40));
    const s = document.querySelector('#bms_comm2_search');
    out.search = s && vis(s) ? {width: Math.round(box(s.closest('form')).width),
        side_width: Math.round(box(s.closest('.bms-comm2-sidebar')).width), input_width: Math.round(box(s).width)} : null;
    return out;
}'''


def url(**kwargs):
    return local_url(base + '/local/kbacompany/communication_center.php?' + urlencode({'uuid': company, **kwargs}))


with sync_playwright() as p:
    browser = p.chromium.launch(headless=True)
    try:
        for mode in ['owned', 'legacy']:
            shell(mode)
            for width in [320, 375, 390, 768, 1440]:
                case = {'shell': mode, 'width': width}
                ctx = browser.new_context(viewport={'width': width, 'height': 900})
                rec = Recorder().install(ctx)
                page = ctx.new_page()
                errors = []
                page.on('response', lambda r: errors.append(r.status) if r.status >= 500 else None)
                page.goto(local_url(base + '/login/index.php'), wait_until='networkidle')
                actor = f['actors']['EA']
                page.locator('#username').fill(actor['username'])
                page.locator('#password').fill(actor['password'])
                page.locator('#loginbtn').click()
                page.wait_for_load_state('networkidle')
                response = page.goto(url(), wait_until='networkidle')
                case['http'] = response.status
                case['sections'] = page.locator('.bms-comm-ux-sections a').count()
                case['default_section'] = page.locator('.bms-comm-ux-sections a[aria-current=page]').get_attribute('href')
                case['default_filter'] = page.locator('.bms-comm-ux-chip[aria-current=true]').get_attribute('href')
                case['no_meetings'] = page.locator('.bms-comm-ux-sections a[href*="tab=meetings"]').count() == 0
                case['no_bulk'] = page.locator('input[value=readallnotifications]').count() == 0
                case['overflow'] = page.evaluate('document.documentElement.scrollWidth > innerWidth + 1')
                # Measure real computed colors against the actual background (alpha handled by the validator).
                case['colors'] = page.locator('.bms-comm-ux-chip').evaluate_all('''els => els.map(e => {
                    const c=getComputedStyle(e); return {foreground:c.color, background:c.backgroundColor};
                })''')
                first = page.locator('.bms-comm2-thread').first
                case['thread_available'] = first.count() == 1
                if first.count():
                    first.click()
                    page.wait_for_load_state('networkidle')
                    toggle = page.locator('[data-bms-comm-context-toggle]')
                    toggle.click()
                    case['panel_open'] = page.locator('#bms-comm3-context-details').evaluate('(e) => e.open')
                    page.keyboard.press('Escape')
                    case['panel_closed'] = not page.locator('#bms-comm3-context-details').evaluate('(e) => e.open')
                    case['focus_restored'] = toggle.evaluate('(e) => document.activeElement === e')
                    case['thread_overflow'] = page.evaluate('document.documentElement.scrollWidth > innerWidth + 1')
                # Real clicks through the three sections and the filters of both list sections (UX_NAV_STEPS).
                page.goto(url(), wait_until='networkidle')
                case['navigation'] = [navigate(page, kind, key) for kind, key in UX_NAV_STEPS]
                response = page.goto(url(tab='notifications'), wait_until='networkidle')
                case['notifications_http'] = response.status
                case['notification_screen'] = page.locator('.bms-comm-ux-notifications').count() == 1
                case['no_conversation_area'] = page.locator('.bms-comm2-layout,.bms-comm2-welcome,.bms-comm2-context,[data-bms-comm-compose]').count() == 0
                case['notifications_overflow'] = page.evaluate('document.documentElement.scrollWidth > innerWidth + 1')
                if width in [375, 1440]:
                    page.screenshot(path=str(root / ('ux1-%s-%s.png' % (mode, width))), full_page=True)
                # Rendered geometry of every section (each loaded on its own; synthetic actors only).
                case['geometry'] = {}
                for section in ['messages', 'discussions', 'notifications']:
                    response = page.goto(url(tab=section), wait_until='networkidle')
                    require(response is not None and response.status == 200, 'section %s HTTP failure' % section)
                    case['geometry'][section] = page.evaluate(GEOMETRY_JS)
                    if width in [768, 1440]:
                        page.screenshot(path=str(root / ('ux2-%s-%s-%s.png' % (mode, width, section))), full_page=False)
                case['browser_events'] = rec.events
                case['server_errors'] = errors
                result['cases'].append(case)
                ctx.close()
        result['status'] = 'PASS'
    except Exception as e:
        result['error'] = '%s: %s' % (type(e).__name__, str(e)[:300])
    finally:
        shell('owned')
        browser.close()
        (root / 'communication-ux-result.json').write_text(json.dumps(result, indent=1))
sys.exit(0 if result['status'] == 'PASS' else 1)
