"""UX-1 observations on real disposable Moodle; synthetic actors, loopback origin only."""
import json
import os
import subprocess
import sys
from pathlib import Path
from urllib.parse import urlencode
from playwright.sync_api import sync_playwright
from contract import local_url, require
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
                response = page.goto(url(tab='notifications'), wait_until='networkidle')
                case['notifications_http'] = response.status
                case['notification_screen'] = page.locator('.bms-comm-ux-notifications').count() == 1
                case['no_conversation_area'] = page.locator('.bms-comm2-layout,.bms-comm2-welcome,.bms-comm2-context,[data-bms-comm-compose]').count() == 0
                case['notifications_overflow'] = page.evaluate('document.documentElement.scrollWidth > innerWidth + 1')
                if width in [375, 1440]:
                    page.screenshot(path=str(root / ('ux1-%s-%s.png' % (mode, width))), full_page=True)
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
