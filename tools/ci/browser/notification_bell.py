"""Top bar bell + opt-in sound: observations on the real disposable Moodle (synthetic actors, loopback origin only).

Producer only: records what the browser and the database show; contract.notification_bell_checks() decides.
- Synthetic notifications are created through the canonical generator (ci_notification_bell.php).
- HTMLMediaElement.prototype.play is replaced by a counter in every page (init script): a call to play() is
  evidence that the bell asked for a signal, NOT that a sound was audible (owner's manual check covers that).
- The Playwright clock drives the 30 s polling timer; network, server and database are real.
"""
import json
import os
import subprocess
import sys
import time
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
A, B = f['companies']['A']['uuid'], f['companies']['B']['uuid']
EA, EB, MA, RA = (f['actors'][k] for k in ('EA', 'EB', 'MA', 'RA'))
base = 'http://127.0.0.1:8080'
FEED = '/local/kbacompany/communication_notifications_feed.php'
PAGE = '/local/kbacompany/calendar_notifications.php'
WIDTHS = [(1440, 900), (768, 1024), (320, 640)]
# Blocked site data: every localStorage access throws (as with blocked cookies/storage); sessionStorage stays usable
# so Moodle core keeps working. The bell itself must not depend on browser storage at all.
NO_STORAGE = '''(() => {
  const ls = window.localStorage;
  for (const m of ['getItem', 'setItem', 'removeItem', 'clear', 'key']) {
    const orig = Storage.prototype[m];
    Storage.prototype[m] = function(...a) {
      if (this === ls) throw new DOMException('localStorage blocked by the test', 'SecurityError');
      return orig.apply(this, a);
    };
  }
})();'''
STORAGE_PROBE = '''() => { try { localStorage.setItem('bms24-probe', '1'); localStorage.removeItem('bms24-probe');
  return 'available'; } catch (e) { return e.name; } }'''
PLAY_STUB = '''(() => {
  window.__bellPlays = 0; window.__bellPlayMode = 'ok';
  HTMLMediaElement.prototype.play = function() {
    window.__bellPlays++;
    return window.__bellPlayMode === 'reject'
      ? Promise.reject(new DOMException('play() refused by the test', 'NotAllowedError')) : Promise.resolve();
  };
})();'''
result = {'status': 'FAIL', 'companyuuid': A, 'companyb': B, 'steps': {}, 'widths': [], 'browser_events': [],
          'server_errors': []}
steps = result['steps']
recorders = []
nonce = '%x' % int(time.time() * 1000)


def php(*args):
    out = subprocess.run(['php', str(here / 'ci_notification_bell.php'), core, *map(str, args)], check=True,
                         capture_output=True, text=True).stdout
    return json.loads(out.strip().splitlines()[-1])


def shell(mode):
    subprocess.run(['php', str(here / 'ci_message_actions.php'), core, 'shell', mode], check=True, capture_output=True)


def membership(phase):
    subprocess.run(['php', str(here / 'ci_state.php'), core, phase], check=True, stdout=subprocess.DEVNULL)


def notify(company, actor, tag):
    return php('notify', company, actor['userid'], '%s-%s' % (nonce, tag))['uuid']


def state(company, actor):
    return php('state', company, actor['userid'])


def page_url(company, path=PAGE, **params):
    return local_url(base + path + '?' + urlencode({'uuid': company, **params}))


def context(browser, width=1440, height=900, nostorage=False):
    ctx = browser.new_context(viewport={'width': width, 'height': height})
    rec = Recorder().install(ctx)
    recorders.append(rec)
    if nostorage:
        ctx.add_init_script(NO_STORAGE)
    ctx.add_init_script(PLAY_STUB)
    ctx.clock.install()
    return ctx


def watch(page):
    page.on('response', lambda r: result['server_errors'].append({'url': r.url, 'status': r.status})
            if r.status >= 500 else None)
    return page


def login(ctx, actor):
    page = watch(ctx.new_page())
    page.goto(local_url(base + '/login/index.php'), wait_until='networkidle')
    page.locator('#username').fill(actor['username'])
    page.locator('#password').fill(actor['password'])
    page.locator('#loginbtn').click()
    page.wait_for_load_state('networkidle')
    return page


def polls(page):
    return int(page.locator('[data-bms24-bell]').get_attribute('data-polls') or '0')


def settle(pages, before, ctx):
    """Wait (real time) until every page finished one more poll, then let the claim timer and play() promises run."""
    for page, n in zip(pages, before):
        page.wait_for_function('n => +(document.querySelector("[data-bms24-bell]").dataset.polls || 0) > n', arg=n,
                               timeout=15000)
    ctx.clock.run_for(1000)
    for page in pages:
        page.wait_for_timeout(300)


def tick(ctx, pages, ms=31000):
    """Advance the shared fake clock by one polling interval (or more) and wait for the resulting polls."""
    before = [polls(p) for p in pages]
    ctx.clock.fast_forward(ms)
    settle(pages, before, ctx)


def open_bell(page, company=A):
    """Navigate first, then require the bell to exist and to have finished its first poll (baseline).

    A missing bell is a failure (wait_for_selector raises), never an acceptable "0 polls"."""
    response = page.goto(page_url(company), wait_until='networkidle')
    require(response is not None and response.status == 200, 'BMS page HTTP %r' % (response and response.status))
    page.wait_for_selector('[data-bms24-bell]', state='attached', timeout=15000)
    page.wait_for_function('() => +document.querySelector("[data-bms24-bell]").dataset.polls > 0', timeout=15000)
    page.wait_for_timeout(200)


def plays(page):
    return page.evaluate('window.__bellPlays')


def reset_plays(*pages):
    for page in pages:
        page.evaluate('window.__bellPlays = 0')


def bell(page):
    """What the bell shows: ids, badge, label, state, sound state; plus page-level counters."""
    return page.evaluate('''() => {
      const r = document.querySelector('[data-bms24-bell]');
      if (!r) return null;
      const t = r.querySelector('[data-bms24-bell-toggle]'), b = r.querySelector('[data-bms24-bell-badge]');
      return {ids: [...r.querySelectorAll('.bms24-bell-item')].map(e => e.dataset.id),
              read: Object.fromEntries([...r.querySelectorAll('.bms24-bell-item')].map(e => [e.dataset.id, e.dataset.read])),
              links: Object.fromEntries([...r.querySelectorAll('.bms24-bell-item')].map(e => [e.dataset.id, e.querySelector('a').href])),
              badge: b.hidden ? '' : b.textContent, label: t.getAttribute('aria-label'), title: t.getAttribute('title'),
              state: r.dataset.state, sound: r.dataset.soundState, unread: r.dataset.unread,
              text: r.innerText, plays: window.__bellPlays,
              bells: document.querySelectorAll('[data-bms24-bell]').length,
              headings: r.querySelectorAll('h1,h2,h3,h4,h5,h6').length,
              moodlebells: document.querySelectorAll('.popover-region-notifications,[data-region="popover-region-notifications"]').length};
    }''')


def panel_metrics(page):
    return page.evaluate('''() => {
      const r = document.querySelector('[data-bms24-bell]'), p = r.querySelector('[data-bms24-bell-panel]');
      const q = p.getBoundingClientRect(), cs = getComputedStyle(p), m = document.querySelector('#bms24-main');
      return {open: !p.hidden, expanded: r.querySelector('[data-bms24-bell-toggle]').getAttribute('aria-expanded'),
              left: q.left, right: q.right, top: q.top, bottom: q.bottom, vw: innerWidth, vh: innerHeight,
              overflowy: cs.overflowY, position: cs.position, scroll: p.scrollHeight, client: p.clientHeight,
              maintop: m ? m.getBoundingClientRect().top : null, docheight: document.documentElement.scrollHeight,
              hscroll: document.documentElement.scrollWidth > innerWidth + 1,
              active: document.activeElement === p ? 'panel' : (p.contains(document.activeElement) ? 'inside' :
                      (document.activeElement && document.activeElement.matches('[data-bms24-bell-toggle]') ? 'toggle' : 'other')),
              allhref: p.querySelector('[data-bms24-bell-all]').href};
    }''')


def layout_case(browser, width, height):
    """Panel geometry, keyboard and focus at one width (owned shell, EA)."""
    case = {'width': width}
    ctx = context(browser, width, height)
    try:
        page = login(ctx, EA)
        open_bell(page)
        toggle = page.locator('[data-bms24-bell-toggle]')
        case['toggle_visible'] = toggle.is_visible()
        closed = panel_metrics(page)
        case['closed'] = closed
        # Keyboard: focus the toggle, Enter opens, focus moves into the panel, Tab stays inside, Escape restores focus.
        toggle.focus()
        page.keyboard.press('Enter')
        page.wait_for_timeout(200)
        case['open_keyboard'] = panel_metrics(page)
        page.keyboard.press('Tab')
        case['tab_inside'] = panel_metrics(page)['active']
        page.keyboard.press('Escape')
        page.wait_for_timeout(100)
        case['after_escape'] = panel_metrics(page)
        case['focus_outline'] = toggle.evaluate('e => { const c = getComputedStyle(e); return [c.outlineStyle, parseFloat(c.outlineWidth)]; }')
        # Mouse: open, click outside closes and nothing shifted.
        toggle.click()
        page.wait_for_timeout(200)
        case['open_click'] = panel_metrics(page)
        page.locator('#bms24-main').click(position={'x': 3, 'y': 3})
        page.wait_for_timeout(200)
        case['after_outside'] = panel_metrics(page)
        page.screenshot(path=str(root / ('bell-%d.png' % width)))
    finally:
        ctx.close()
    return case


with sync_playwright() as p:
    browser = p.chromium.launch(headless=True)
    try:
        shell('owned')
        php('pref', EA['userid'], '0')
        php('pref', EB['userid'], '0')
        n0 = notify(A, EA, 'n0')
        nb = notify(B, EB, 'nb')
        ctx = context(browser)
        page = login(ctx, EA)

        # 1. Initial load: one bell (no Moodle bell), badge = server count, baseline never sounds, nothing marked read.
        read0 = state(A, EA)
        open_bell(page)
        steps['initial'] = {'bell': bell(page), 'server': state(A, EA), 'server_before': read0, 'n0': n0, 'nb': nb}

        # 2. Panel open refreshes, still marks nothing read; "All notifications" link; item links.
        page.locator('[data-bms24-bell-toggle]').click()
        page.wait_for_timeout(500)
        steps['panel'] = {'metrics': panel_metrics(page), 'bell': bell(page), 'server': state(A, EA)}
        page.keyboard.press('Escape')

        # 3. Sound off by default: a new notification appears in bell, no signal.
        n1 = notify(A, EA, 'n1')
        tick(ctx, [page])
        steps['new_sound_off'] = {'n1': n1, 'bell': bell(page), 'server': state(A, EA)}

        # 4. Enable (explicit click) plays once as confirmation and stores the preference; test plays again.
        page.locator('[data-bms24-bell-toggle]').click()
        page.locator('[data-bms24-bell-sound-enable]').click()
        page.wait_for_function('() => document.querySelector("[data-bms24-bell]").dataset.sound === "1"', timeout=15000)
        after_enable = plays(page)
        page.locator('[data-bms24-bell-sound-test]').click()
        page.wait_for_timeout(200)
        steps['enable'] = {'plays_after_enable': after_enable, 'plays_after_test': plays(page), 'bell': bell(page),
                           'server': state(A, EA), 'other_account_pref': state(B, EB)['pref']}
        page.keyboard.press('Escape')
        reset_plays(page)

        # 5. Repeated polling without new rows: silent.
        tick(ctx, [page])
        tick(ctx, [page])
        steps['repeat_poll'] = {'bell': bell(page)}

        # 6. Count-only change (n1 read in the Center through its own form): badge follows, silent.
        center = watch(ctx.new_page())
        center.goto(page_url(A, '/local/kbacompany/communication_center.php', tab='notifications'), wait_until='networkidle')
        steps['center_lists_new'] = {'n1_form': center.locator('input[name=notification][value="%s"]' % n1).count(),
                                     'title_shown': center.locator('text=BMS bell synthetic %s-n1' % nonce).count()}
        with center.expect_navigation():
            center.locator('form:has(input[name=notification][value="%s"]) button' % n1).click()
        center.wait_for_load_state('networkidle')
        center.close()
        reset_plays(page)
        tick(ctx, [page])
        steps['count_only'] = {'bell': bell(page), 'server': state(A, EA), 'n1': n1}

        # 7. Batch of three new notifications: exactly one signal.
        batch = [notify(A, EA, 'b%d' % i) for i in range(3)]
        tick(ctx, [page])
        steps['batch'] = {'ids': batch, 'bell': bell(page), 'server': state(A, EA)}

        # 8. Two tabs of the same account: one new notification, one signal in total.
        tab2 = watch(ctx.new_page())
        open_bell(tab2)
        reset_plays(page, tab2)
        n5 = notify(A, EA, 'n5')
        tick(ctx, [page, tab2])
        steps['tabs'] = {'n5': n5, 'plays': [plays(page), plays(tab2)], 'bells': [bell(page), bell(tab2)],
                         'storage': [page.evaluate(STORAGE_PROBE), tab2.evaluate(STORAGE_PROBE)]}
        tab2.close()

        # 8b. Same, with localStorage unavailable in both tabs (separate browser context, same account): the two tabs
        #     receive the same new notification on the same clock tick; the server grants one signal in total.
        ctxn = context(browser, nostorage=True)
        try:
            ns1 = login(ctxn, EA)
            ns2 = watch(ctxn.new_page())
            open_bell(ns1)
            open_bell(ns2)
            reset_plays(ns1, ns2)
            n6 = notify(A, EA, 'n6')
            tick(ctxn, [ns1, ns2])
            steps['tabs_nostorage'] = {'n6': n6, 'plays': [plays(ns1), plays(ns2)], 'bells': [bell(ns1), bell(ns2)],
                                       'storage': [ns1.evaluate(STORAGE_PROBE), ns2.evaluate(STORAGE_PROBE)]}
            # The first context's tab saw the same row afterwards: still no extra signal there.
            reset_plays(page)
            tick(ctx, [page])
            steps['tabs_nostorage']['other_context'] = bell(page)
        finally:
            ctxn.close()

        # 9. Reload: baseline again, silent.
        open_bell(page)
        steps['reload'] = {'bell': bell(page)}

        # 10. Offline: no request; reconnect: one poll, at most one signal for everything that arrived meanwhile.
        reset_plays(page)
        before = polls(page)
        ctx.set_offline(True)
        offline = [notify(A, EA, 'off%d' % i) for i in range(2)]
        ctx.clock.fast_forward(31000)
        ctx.clock.run_for(1000)
        page.wait_for_timeout(500)
        offline_polls = polls(page) - before
        ctx.set_offline(False)
        settle([page], [before], ctx)
        steps['reconnect'] = {'ids': offline, 'polls_while_offline': offline_polls, 'bell': bell(page)}

        # 11. Autoplay refusal: handled, state "blocked", no JS error; test after allowing restores "on".
        reset_plays(page)
        page.evaluate("window.__bellPlayMode = 'reject'")
        n8 = notify(A, EA, 'n8')
        tick(ctx, [page])
        blocked = bell(page)
        page.evaluate("window.__bellPlayMode = 'ok'")
        page.locator('[data-bms24-bell-toggle]').click()
        page.locator('[data-bms24-bell-sound-test]').click()
        page.wait_for_timeout(300)
        steps['autoplay_refused'] = {'n8': n8, 'blocked': blocked, 'after_test': bell(page)}

        # 12. CSRF: wrong sesskey and GET never change the preference.
        wrong = page.request.post(local_url(base + FEED), form={'uuid': A, 'action': 'sound', 'enabled': '0',
                                                                 'sesskey': 'wrong' + nonce})
        get = page.request.get(local_url(base + FEED + '?' + urlencode({'uuid': A, 'action': 'sound', 'enabled': '0'})))
        steps['csrf'] = {'wrong_http': wrong.status, 'wrong': wrong.json(), 'get_http': get.status,
                         'get_ok': get.json().get('ok'), 'pref': state(A, EA)['pref']}

        # 13. Disable: preference stored, next new notification is silent.
        page.locator('[data-bms24-bell-sound-disable]').click()
        page.wait_for_function('() => document.querySelector("[data-bms24-bell]").dataset.sound === "0"', timeout=15000)
        page.keyboard.press('Escape')
        reset_plays(page)
        n9 = notify(A, EA, 'n9')
        tick(ctx, [page])
        steps['disable'] = {'n9': n9, 'bell': bell(page), 'pref': state(A, EA)['pref']}

        # 14. Thread notification: appears, sounds once, opens its conversation; participation revoked -> row,
        #     count and title disappear, silent.
        page.locator('[data-bms24-bell-toggle]').click()
        page.locator('[data-bms24-bell-sound-enable]').click()
        page.wait_for_function('() => document.querySelector("[data-bms24-bell]").dataset.sound === "1"', timeout=15000)
        page.keyboard.press('Escape')
        reset_plays(page)
        th = php('thread', A, MA['userid'], EA['userid'], '%s-t' % nonce)
        tick(ctx, [page])
        thread_seen = bell(page)
        link = page.locator('.bms24-bell-item[data-id="%s"] a' % th['uuid'])
        href = link.get_attribute('href')
        nav = watch(ctx.new_page())
        response = nav.goto(local_url(href), wait_until='networkidle')
        steps['thread_open'] = {'uuid': th['uuid'], 'thread': th['thread'], 'bell': thread_seen, 'href': href,
                                'http': response.status, 'landed': nav.url}
        nav.close()
        reset_plays(page)
        kicked = php('kick', A, th['thread'], MA['userid'], EA['userid'])
        tick(ctx, [page])
        steps['thread_revoked'] = {'uuid': th['uuid'], 'thread': th['thread'], 'active': kicked.get('active'),
                                   'bell': bell(page), 'server': state(A, EA),
                                   'title': 'BMS bell private %s-t' % nonce}

        # 15. Company access revoked in the open session: nothing shown, silent; reopened: rows return, still silent.
        reset_plays(page)
        n11 = notify(A, EA, 'n11')
        membership('revoke_ea')
        tick(ctx, [page])
        revoked = bell(page)
        revoked_server = state(A, EA)
        membership('reopen_ea')
        tick(ctx, [page], 61000)
        steps['company_revoked'] = {'n11': n11, 'revoked': revoked, 'revoked_server': revoked_server,
                                    'restored': bell(page), 'server': state(A, EA)}

        # 16. Company B and roles: EB sees only B; EB and MA cannot read EA's company-A rows through the API.
        ctxb = context(browser)
        pb = login(ctxb, EB)
        open_bell(pb, B)
        foreign = pb.request.get(local_url(base + FEED + '?' + urlencode({'uuid': A})))
        ctxm = context(browser)
        pm = login(ctxm, MA)
        open_bell(pm, A)
        manager = pm.request.get(local_url(base + FEED + '?' + urlencode({'uuid': A})))
        steps['isolation'] = {'eb_bell': bell(pb), 'eb_server': state(B, EB), 'foreign_http': foreign.status,
                              'foreign': foreign.json(), 'ma_http': manager.status, 'ma': manager.json(),
                              'ma_bell': bell(pm), 'ea_ids': steps['initial']['bell']['ids'] + [n1, n5, n6, n9, n11] + batch,
                              'ea_bell': bell(page), 'nb': nb}
        ctxb.close()
        ctxm.close()

        # 17. Legacy shell: no BMS bell (the Moodle bell stays the only one there).
        shell('legacy')
        legacy = watch(ctx.new_page())
        legacy.goto(page_url(A), wait_until='networkidle')
        steps['legacy'] = {'bells': legacy.locator('[data-bms24-bell]').count()}
        legacy.close()
        shell('owned')

        # 18. Another account signs in on the same browser: the open tab stops, shows nothing, stays silent.
        reset_plays(page)
        other = watch(ctx.new_page())
        sesskey = other.goto(page_url(A), wait_until='networkidle') and other.evaluate('M.cfg.sesskey')
        other.goto(local_url(base + '/login/logout.php?' + urlencode({'sesskey': sesskey})), wait_until='networkidle')
        other.close()
        other = login(ctx, RA)
        n12 = notify(A, EA, 'n12')
        # A poll may have hit the logged-out window (login page instead of JSON -> backoff); cover the longest backoff.
        tick(ctx, [page], 301000)
        switched = bell(page)
        stopped_polls = polls(page)
        ctx.clock.fast_forward(120000)
        page.wait_for_timeout(1000)
        steps['account_switch'] = {'bell': switched, 'n12': n12, 'more_polls': polls(page) - stopped_polls,
                                   'ra_bell_count': other.goto(page_url(A), wait_until='networkidle') and
                                   other.locator('[data-bms24-bell]').count()}
        ctx.close()

        # 19. Geometry, keyboard and focus at 1440/768/320.
        for _ in range(9):
            notify(A, EA, 'fill%d' % _)
        for width, height in WIDTHS:
            result['widths'].append(layout_case(browser, width, height))
        result['status'] = 'PASS'
    except Exception as e:
        result['error'] = '%s: %s' % (type(e).__name__, str(e)[:300])
    finally:
        for rec in recorders:
            result['browser_events'].extend(rec.events)
        try:
            shell('owned')
            membership('reopen_ea')
            php('pref', EA['userid'], '0')
        except Exception as e:  # cleanup failure is evidence, not a silent pass
            result['cleanup_error'] = str(e)[:200]
        browser.close()
        (root / 'notification-bell-result.json').write_text(json.dumps(result, indent=1))
sys.exit(0 if result['status'] == 'PASS' else 1)
