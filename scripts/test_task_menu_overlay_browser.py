"""Real isolated Moodle Actions/Tools geometry and interaction regression.

Required env: BMS_RECOVERY_BROWSER=1, TEST_ROOT (private actors.private.json),
EVIDENCE (private directory), EXPECTED_TASK_CSS_SHA256. EXPECTED_SHELL defaults
owned; run again on a separately configured isolated legacy fixture, never flip
production configuration. No setup, account mutation, or POST actions here.
"""
import hashlib
import json
import os
from pathlib import Path
from urllib.parse import urljoin, urlparse

from playwright.sync_api import sync_playwright

BASE = 'http://127.0.0.1:8080'
WIDTHS = [320, 360, 390, 480, 482, 720, 768, 1024, 1280, 1440]
SHELL = os.environ.get('EXPECTED_SHELL', 'owned')
MENU = '.bms24-task-menu' if SHELL == 'owned' else '.bms-tui-menu'
PANEL = '.bms24-task-menu-items' if SHELL == 'owned' else '.bms-tui-menu-items'


def require(condition, message):
    if not condition:
        raise AssertionError(message)


def geometry(page):
    return page.evaluate('''() => {
        const card = document.querySelector('.bms-task2-layout, .bms24-task-center, .bms-tui');
        if (!card) throw new Error('task card marker missing');
        const r = card.getBoundingClientRect();
        return {pageWidth: document.documentElement.clientWidth,
            scrollWidth: document.documentElement.scrollWidth,
            card: {x:r.x,y:r.y,width:r.width,height:r.height}};
    }''')


def stable(before, after):
    require(after['scrollWidth'] <= after['pageWidth'] + 1, 'horizontal page overflow')
    require(before['pageWidth'] == after['pageWidth'], 'page width changed')
    require(before['scrollWidth'] == after['scrollWidth'], 'scroll width changed')
    for key in ['x', 'y', 'width', 'height']:
        require(abs(before['card'][key] - after['card'][key]) <= 1, 'card geometry changed: ' + key)


def run():
    require(os.environ.get('BMS_RECOVERY_BROWSER') == '1', 'isolated recovery opt-in required')
    require(SHELL in ['owned', 'legacy'], 'unknown shell')
    evidence = Path(os.environ['EVIDENCE'])
    evidence.mkdir(parents=True, exist_ok=True)
    fixture = json.loads((Path(os.environ['TEST_ROOT']) / 'actors.private.json').read_text())
    actor = fixture['actors']['MA']
    company, task = fixture['companies']['A']['uuid'], fixture['tasks']['A']['uuid']
    tools_url = BASE + '/local/kbacompany/tasks.php?uuid=' + company
    detail_url = tools_url + '&task=' + task + '&view=detail&tab=overview'
    rows, css, failures = [], [], []
    report = {'shell': SHELL, 'status': 'FAIL', 'rows': rows, 'failures': failures, 'loaded_css': css}
    try:
        with sync_playwright() as pw:
            browser = pw.chromium.launch(headless=True)
            try:
                for width in WIDTHS:
                    context = browser.new_context(viewport={'width': width, 'height': 900},
                        has_touch=width <= 480, is_mobile=width <= 480)
                    blocked, errors = set(), []
                    def route(request):
                        parsed = urlparse(request.request.url)
                        if parsed.scheme == 'http' and parsed.netloc == '127.0.0.1:8080':
                            request.continue_()
                        else:
                            blocked.add(request.request.url)
                            request.abort()
                    context.route('**/*', route)
                    page = context.new_page()
                    close_observations = []
                    page.expose_function('recordMenuClose', lambda value: close_observations.append(value))
                    def response(response):
                        if response.status >= 500:
                            errors.append('HTTP ' + str(response.status))
                        if urlparse(response.url).path.endswith('/task_center.css'):
                            css.append({'status': response.status, 'sha256': hashlib.sha256(response.body()).hexdigest()})
                    page.on('response', response)
                    page.on('pageerror', lambda error: errors.append(str(error)))
                    page.on('console', lambda message: errors.append('console: ' + message.text)
                        if message.type == 'error' and message.location.get('url') not in blocked else None)
                    page.goto(BASE + '/login/index.php', wait_until='networkidle')
                    page.locator('#username').fill(actor['username'])
                    page.locator('#password').fill(actor['password'])
                    page.locator('#loginbtn').click()
                    page.wait_for_load_state('networkidle')
                    for name, url in [('tools', tools_url), ('actions', detail_url)]:
                        response = page.goto(url, wait_until='networkidle')
                        require(response.status == 200 and page.url == url, 'unexpected page response/redirect')
                        menu = page.locator(MENU).first
                        require(menu.count() == 1, name + ' menu missing')
                        summary, panel = menu.locator('summary'), menu.locator(PANEL)
                        summary.scroll_into_view_if_needed()
                        before = geometry(page)
                        summary.click()
                        page.wait_for_function('(s) => document.querySelector(s).open', arg=MENU)
                        page.wait_for_timeout(50)
                        after = geometry(page)
                        box = panel.bounding_box()
                        diagnostics = panel.evaluate("""(e) => {
                            function describe(node) {
                                const s=getComputedStyle(node), r=node.getBoundingClientRect();
                                return {tag:node.tagName,id:node.id,classes:node.className,
                                    box:{x:r.x,y:r.y,width:r.width,height:r.height},
                                    position:s.position,top:s.top,left:s.left,right:s.right,bottom:s.bottom,
                                    width:s.width,minWidth:s.minWidth,maxWidth:s.maxWidth,maxHeight:s.maxHeight,
                                    padding:s.padding,borderWidth:s.borderWidth,
                                    margin:s.margin,boxSizing:s.boxSizing,transform:s.transform,
                                    translate:s.translate,scale:s.scale,filter:s.filter,
                                    backdropFilter:s.backdropFilter,contain:s.contain,
                                    willChange:s.willChange,overflowX:s.overflowX,overflowY:s.overflowY,
                                    inlineStyle:node.getAttribute('style')};
                            }
                            const chain=[];let parent=e.parentElement;
                            while(parent){chain.push(describe(parent));parent=parent.parentElement;}
                            return {panel:describe(e),ancestors:chain,viewport:{innerWidth:innerWidth,
                                innerHeight:innerHeight,scrollX:scrollX,scrollY:scrollY,
                                visual:window.visualViewport?{width:visualViewport.width,
                                    height:visualViewport.height,offsetLeft:visualViewport.offsetLeft,
                                    offsetTop:visualViewport.offsetTop,scale:visualViewport.scale}:null}};
                        }""")
                        report.setdefault('geometry_diagnostics', []).append({'width':width,'menu':name,
                            'before':before,'after':after,'diagnostics':diagnostics})
                        page.screenshot(path=str(evidence / ('menu-' + SHELL + '-' + name + '-' + str(width) + '.png')))
                        stable(before, after)
                        require(box and box['x'] >= -1 and box['y'] >= -1
                            and box['x'] + box['width'] <= width + 1
                            and box['y'] + box['height'] <= 901, 'menu outside viewport')
                        page.screenshot(path=str(evidence / ('menu-' + SHELL + '-' + name + '-' + str(width) + '.png')))
                        links = panel.locator('a[href]')
                        require(links.count() > 0, 'empty menu')
                        # Real Playwright hit testing: no forced clicks or dispatchEvent.
                        for index in range(links.count()):
                            links.nth(index).click(trial=True)
                        page.keyboard.press('Escape')
                        require(menu.get_attribute('open') is None, 'Escape did not close')
                        require(summary.evaluate('(e)=>e===document.activeElement'), 'Escape focus not returned')
                        summary.press('Enter')
                        page.wait_for_function('(s) => document.querySelector(s).open', arg=MENU)
                        # Outside point is viewport origin; panel is inset at least 8px.
                        page.mouse.click(1, 1)
                        require(menu.get_attribute('open') is None, 'outside click did not close')
                        require(summary.evaluate('(e)=>e===document.activeElement'), 'outside click focus not returned')
                        summary.press('Space')
                        page.wait_for_function('(s) => document.querySelector(s).open', arg=MENU)
                        page.keyboard.press('Tab')
                        require(panel.evaluate('(e)=>e.contains(document.activeElement)'), 'Tab did not reach items')
                        page.keyboard.press('Escape')
                        require(menu.get_attribute('open') is None, 'Escape from item did not close')
                        # Observe the actual closing event before safe navigation to an existing GET page.
                        summary.click()
                        page.wait_for_function('(s) => document.querySelector(s).open', arg=MENU)
                        links = panel.locator('a[href]')
                        href = links.first.get_attribute('href')
                        require(urlparse(href).scheme in ['', 'http'] and
                            urlparse(href).netloc in ['', '127.0.0.1:8080'], 'unexpected action origin')
                        page.evaluate('''(selector) => {window.__menuClosedOnClick = false;
                            document.addEventListener('click', event => {
                                const menu = document.querySelector(selector);
                                if (menu && event.target.closest(selector+' a')) {
                                    window.recordMenuClose(!menu.open);
                                }
                            });}''', arg=MENU)
                        # A normal click is delivered, but navigation is observed through its real request.
                        with page.expect_navigation(wait_until='networkidle') as destination:
                            with page.expect_request(lambda request: request.is_navigation_request()) as navigation:
                                links.first.click()
                        require(navigation.value.method == 'GET', 'menu unexpectedly submitted mutation')
                        require(destination.value and destination.value.status == 200, 'menu destination did not return HTTP 200')
                        require(page.url == urljoin(url, href), 'menu destination unexpectedly redirected')
                        require('/login/' not in page.url, 'menu link lost authenticated access')
                        require(close_observations and close_observations[-1] is True, 'action click did not close menu')
                        require(not errors, 'browser/network errors')
                        rows.append({'width': width, 'menu': name, 'before': before, 'after': after,
                            'panel': box, 'ordinary_click': 'navigation and closure verified', 'status': 'PASS'})
                    if width == 320:
                        # Supplemental DOM stress fixture, distinct from the real menu rows above.
                        page.goto(detail_url, wait_until='networkidle')
                        menu = page.locator(MENU).first
                        summary, panel = menu.locator('summary'), menu.locator(PANEL)
                        summary.click()
                        page.wait_for_function('(s) => document.querySelector(s).open', arg=MENU)
                        panel.evaluate("""(e) => {const link=e.querySelector('a');
                            for(let i=0;i<40;i++){const copy=link.cloneNode(true);
                                copy.textContent='Long menu item '+i+' '+('UnbrokenLabel'.repeat(12)); e.append(copy);}}
                        """)
                        page.set_viewport_size({'width': width, 'height': 900})
                        page.evaluate("window.dispatchEvent(new Event('resize'))")
                        require(panel.evaluate('(e)=>e.scrollHeight>e.clientHeight'), 'long-list fixture did not overflow')
                        panel.locator('a').last.click(trial=True)
                        box = panel.bounding_box()
                        require(box and box['y'] >= 0 and box['y'] + box['height'] <= 900,
                            'long-list panel outside viewport')
                        require(geometry(page)['scrollWidth'] <= width + 1, 'long-list page overflow')
                        report['long_list_stress'] = 'PASS (DOM fixture on isolated Moodle)'
                        # The same geometry assertion must fail when in-flow reflow is injected.
                        page.keyboard.press('Escape')
                        before = geometry(page)
                        panel.evaluate("(e)=>{e.style.position='static';e.style.height='700px';}")
                        summary.click()
                        page.wait_for_function('(s) => document.querySelector(s).open', arg=MENU)
                        # Override after the toggle positioner, to simulate the missing overlay fix.
                        panel.evaluate("(e)=>{e.style.position='static';e.style.height='700px';}")
                        caught = False
                        try:
                            stable(before, geometry(page))
                        except AssertionError:
                            caught = True
                        require(caught, 'geometry negative control was not caught')
                        report['negative_geometry'] = 'PASS (intentional regression produced FAIL)'
                        page.goto(detail_url, wait_until='networkidle')
                        menu = page.locator(MENU).first
                        menu.locator('summary').click()
                        page.wait_for_function('(s) => document.querySelector(s).open', arg=MENU)
                        page.evaluate("""() => {document.addEventListener('keydown', event => {
                            if(event.key==='Escape') event.stopImmediatePropagation();
                        }, true);} """)
                        page.keyboard.press('Escape')
                        caught = False
                        try:
                            require(menu.get_attribute('open') is None, 'Escape did not close')
                        except AssertionError:
                            caught = True
                        require(caught, 'Escape negative control was not caught')
                        report['negative_escape'] = 'PASS (intentional regression produced FAIL)'
                    response = page.goto(detail_url, wait_until='networkidle')
                    require(response.status == 200, 'overview failed')
                    require(page.get_by_role('heading', name='Последние действия', exact=True).count() == 0
                        and page.get_by_role('heading', name='Recent activity', exact=True).count() == 0,
                        'recent activity block remains')
                    history = page.locator('a[href*="tab=history"]').first
                    require(history.count() > 0, 'history tab missing')
                    history.click()
                    page.wait_for_load_state('networkidle')
                    require(page.locator('.bms-tui-message').count() > 0, 'history has no fixture events')
                    require(not errors, 'browser/network errors')
                    rows.append({'width': width, 'history': 'PASS'})
                    context.close()
            finally:
                browser.close()
        require(len(rows) == len(WIDTHS) * 3, 'incomplete browser matrix')
        if SHELL == 'owned':
            expected = os.environ['EXPECTED_TASK_CSS_SHA256']
            require(css and all(item['status'] == 200 and item['sha256'] == expected for item in css),
                'loaded CSS differs from candidate')
        report['status'] = 'PASS'
    except Exception as error:
        failures.append(type(error).__name__ + ': ' + str(error))
        raise
    finally:
        (evidence / ('task-menu-overlay-' + SHELL + '.json')).write_text(json.dumps(report, indent=2))
    print('TASK_MENU_OVERLAY_PASS', SHELL, len(rows))


if __name__ == '__main__':
    run()
