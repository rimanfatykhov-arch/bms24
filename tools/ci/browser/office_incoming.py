"""Real Moodle acceptance of the DOC-W3 incoming register (office_incoming.php); synthetic data only, loopback only.

1. Office on, register off: MA gets no register on office_incoming.php; "Incoming" stays a planned (non-link) section.
2. Register on, seeded roles. MA (company manager) without a grant: no rights, no "New" action, no link (no fallback).
3. EA (author, create right) opens Office -> Incoming, creates a card, re-sends the same create form (double submit:
   still one card), adds the original PDF. EA cannot register.
4. MA gets the registrar role; opens EA's card, registers: number Вх-YYYY/NNNN, server time, "registered" journal entry;
   re-sends the same register form: same number, the series does not move; the stored original equals the uploaded bytes.
5. MA corrects an attribute with a reason: a new revision and a "corrected" journal entry with the old and new values.
6. Isolation: MB (registrar of company B) and EB never see company A's card or original (list, card link, download link);
   EA's original link works for EA; a foreign download is a 404.
7. Concurrency: N drafts registered by parallel PHP processes (two of them on the same card): distinct, gap-free numbers,
   the duplicate returns the same number, the series equals the number of registered cards.
8. Layout: MA's register list and card in owned/legacy x 1440 EN / 375 RU (mobile card), no horizontal scroll, no browser
   errors or 5xx; one screenshot per case.
9. Revocation: EA's author role is revoked -> the card link is "not found"; EA's membership is revoked -> no register;
   both restored. MA cancels a registered card with a reason: the number stays.
Observations only -> EVIDENCE/office-incoming-result.json; the verdict is computed by contract.office_incoming_checks.
"""
import hashlib
import json
import os
import subprocess
import sys
import tempfile
import urllib.parse
import uuid as uuidlib
from pathlib import Path

sys.path.insert(0, str(Path(__file__).resolve().parent))
from contract import OFFICEIN_CASES, OFFICEIN_RACE, local_url, require  # noqa: E402
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
STATE = """() => {
  const q = s => document.querySelector(s);
  const nav = q('[data-office-nav]');
  const status = q('[data-officein-card] [data-officein-status]');
  return {list: !!q('[data-officein-list]'), card: q('[data-officein-card]') ? q('[data-officein-card]').dataset.officeinCard : null,
    lockversion: q('[data-officein-card]') ? Number(q('[data-officein-card]').dataset.officeinLockversion) : null,
    number: q('[data-officein-number]') ? q('[data-officein-number]').textContent.trim() : null,
    status: status ? status.dataset.officeinStatus : null,
    rows: [...document.querySelectorAll('[data-officein-row]')].map(e => e.dataset.officeinRow),
    norights: !!q('[data-officein-norights]'), notfound: !!q('[data-officein-notfound]'),
    newaction: document.querySelectorAll('[data-officein-new]').length,
    register: document.querySelectorAll('[data-officein-register]').length,
    journal: [...document.querySelectorAll('[data-officein-log]')].map(e => e.dataset.officeinLog),
    error: !!q('[data-officein-error]'),
    link: nav ? nav.querySelectorAll('a[data-office-section="incoming"]').length : null,
    planned: nav ? [...nav.querySelectorAll('[data-office-planned]')].map(e => e.dataset.officePlanned) : [],
    h1: [...document.querySelectorAll('h1')].map(h => h.textContent.trim()),
    rowdisplay: q('[data-officein-row]') ? getComputedStyle(q('[data-officein-row]')).display : null,
    text: document.body.innerText};
}"""


def php(*args):
    run = subprocess.run(['php', str(here / 'ci_office_incoming.php'), *args], capture_output=True, text=True)
    if run.returncode != 0:
        tail = (run.stderr or '').strip().splitlines()[-1:] or ['']
        raise RuntimeError('ci_office_incoming.php %s exited %d: %s' % (args[0], run.returncode, tail[0][:300]))
    return json.loads(run.stdout.strip().splitlines()[-1])


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


def url(company=A, path='/local/kbacompany/office_incoming.php', **params):
    return local_url(base + path + '?' + urllib.parse.urlencode(dict(uuid=company, **params)))


def visit(page, target, private=()):
    response = page.goto(target, wait_until='networkidle')
    state = page.evaluate(STATE)
    state['http'] = response.status if response else None
    state['hscroll'] = page.evaluate(HSCROLL)
    text = state.pop('text', '')
    state['leaked'] = [t for t in private if t and t in text]
    return state


def form_fields(page, operation):
    """Exact hidden + visible values of a rendered form (to re-send it as a double submit)."""
    return page.evaluate("""op => { const f = document.querySelector('form[data-officein-form="' + op + '"]');
      if (!f) return null; const d = {}; new FormData(f).forEach((v, k) => { if (typeof v === 'string') d[k] = v; });
      return d; }""", operation)


def resend(page, fields):
    response = page.request.post(url(), form=fields, max_redirects=5)
    return response.status


def context(browser, width=1440):
    ctx = browser.new_context(viewport={'width': width, 'height': 900 if width > 500 else 812})
    rec = Recorder(); rec.install(ctx)
    return ctx, rec


def track(page):
    errors = []
    page.on('response', lambda r: errors.append({'url': r.url, 'status': r.status}) if r.status >= 500 else None)
    return errors


def pdf_file(tag):
    body = ('%%PDF-1.4\n1 0 obj<< /Type /Catalog >>endobj\n%% DOC-W3 CI %s\ntrailer<< /Root 1 0 R >>\n%%%%EOF\n' % tag).encode()
    path = Path(tempfile.mkdtemp(prefix='officein-')) / ('original-%s.pdf' % tag)
    path.write_bytes(body)
    return path, hashlib.sha256(body).hexdigest()


try:
    with sync_playwright() as pw:
        browser = pw.chromium.launch(headless=True, args=['--disable-dev-shm-usage'])
        helper('shell', 'owned')
        for label in ('MA', 'EA', 'MB', 'EB'):
            helper('lang', 'en', str(f['actors'][label]['userid']))
        # ---- 1. Office on, register off
        result['switch_off'] = php('switch', '1', '0')
        ctx, rec = context(browser)
        page = login(ctx, 'MA')
        off = visit(page, url())
        off['office'] = visit(page, url(path='/local/kbacompany/office.php'))
        result['off'] = off
        ctx.close()
        # ---- 2. register on, roles seeded; MA (manager) without a grant
        result['switch_on'] = php('switch', '1', '1')
        nonce = php('seed')['nonce']
        subject = 'DOC-W3 CI letter ' + nonce
        result['rights_before'] = {'MA': php('rights', 'A', 'MA')['rights'], 'EA': php('rights', 'A', 'EA')['rights'],
                                   'EB': php('rights', 'B', 'EB')['rights'], 'MB': php('rights', 'B', 'MB')['rights']}
        ctx, rec = context(browser)
        page = login(ctx, 'MA')
        result['manager_without_grant'] = visit(page, url())
        result['manager_without_grant']['new_view'] = visit(page, url(view='new'))
        ctx.close()
        # ---- 3. author EA: create (double submit), original, no register
        ctx, rec = context(browser)
        page = login(ctx, 'EA'); errors = track(page)
        office = visit(page, url(path='/local/kbacompany/office.php'))
        page.locator('[data-office-nav] a[data-office-section="incoming"]').first.click()
        page.wait_for_load_state('networkidle')
        author = {'office_link': office['link'], 'landed': page.evaluate(STATE).get('list'),
                  'count_before': php('count', 'A')['count']}
        page.locator('[data-officein-new]').first.click(); page.wait_for_load_state('networkidle')
        page.fill('#officein-subject', subject)
        page.fill('#officein-correspondent', 'CI Sender LLC')
        page.fill('#officein-externalnumber', 'CI-17/4')
        created_form = form_fields(page, 'create')
        page.locator('form[data-officein-form="create"] button[type="submit"]').click()
        page.wait_for_load_state('networkidle')
        card = page.evaluate(STATE)
        card_uuid = card['card']
        created_form.update({'subject': subject, 'correspondent': 'CI Sender LLC', 'externalnumber': 'CI-17/4'})
        author['double_submit_http'] = resend(page, created_form)
        author['count_after_double_submit'] = php('count', 'A')['count']
        original, original_sha = pdf_file(nonce)
        page.set_input_files('#officein-file-original', str(original))
        page.locator('form[data-officein-form="file"]').first.locator('button[type="submit"]').click()
        page.wait_for_load_state('networkidle')
        after_upload = page.evaluate(STATE); after_upload.pop('text', None)
        author.update({'card': card_uuid, 'status': card['status'], 'register_button': after_upload['register'],
                       'journal': after_upload['journal'], 'upload_error': after_upload['error']})
        link = page.locator('a[data-officein-file="office_original"]').first.get_attribute('href')
        download = page.request.get(local_url(urllib.parse.urljoin(base, link)))
        author['download'] = {'http': download.status, 'disposition': (download.headers.get('content-disposition') or '')[:20],
                              'sha_matches': hashlib.sha256(download.body()).hexdigest() == original_sha}
        author['server_errors'] = errors; author['browser_events'] = rec.events
        result['author'] = author
        original_link = link
        ctx.close()
        # ---- 4./5. registrar MA: register, repeat, correct
        result['granted'] = php('grant', 'MA')['rights']
        ctx, rec = context(browser)
        page = login(ctx, 'MA'); errors = track(page)
        registrar = {'list': visit(page, url())}
        before = php('series', 'A')
        page.goto(url(card=card_uuid), wait_until='networkidle')
        register_form = form_fields(page, 'register')
        page.locator('[data-officein-register]').first.click(); page.wait_for_load_state('networkidle')
        registered = page.evaluate(STATE); registered.pop('text', None)
        registrar['registered'] = registered
        registrar['repeat_http'] = resend(page, register_form)
        registrar['series_before'] = before['lastseq']; registrar['series_after'] = php('series', 'A')['lastseq']
        registrar['year'] = before['year']
        registrar['truth'] = php('card', 'A', 'MA', card_uuid)['card']
        registrar['original_sha'] = original_sha
        page.goto(url(card=card_uuid), wait_until='networkidle')
        page.locator('details:has(form[data-officein-form="save"]) > summary').first.click()
        page.fill('#officein-correspondent', 'CI Sender LLC (corrected)')
        page.fill('#officein-reason', 'Typo in the sender name')
        page.locator('form[data-officein-form="save"] button[type="submit"]').click(); page.wait_for_load_state('networkidle')
        registrar['after_correction'] = php('card', 'A', 'MA', card_uuid)['card']
        registrar['server_errors'] = errors; registrar['browser_events'] = rec.events
        result['registrar'] = registrar
        ctx.close()
        # ---- 6. isolation
        private = [subject, 'CI Sender LLC']
        isolation = {}
        for label, company in (('MB', A), ('MB', B), ('EB', B)):
            ctx, rec = context(browser)
            page = login(ctx, label)
            key = '%s_%s' % (label, 'A' if company == A else 'B')
            isolation[key] = {'list': visit(page, url(company), private),
                              'card': visit(page, url(company, card=card_uuid), private)}
            foreign = page.request.get(local_url(urllib.parse.urljoin(base, original_link.replace(A, company))))
            isolation[key]['download_http'] = foreign.status
            isolation[key]['download_leak'] = hashlib.sha256(foreign.body()).hexdigest() == original_sha
            ctx.close()
        result['isolation'] = isolation
        # ---- 7. real parallel registration race (separate PHP processes)
        drafts = php('drafts', str(OFFICEIN_RACE))['uuids']
        start = php('series', 'A')['lastseq']
        jobs = [(d, str(uuidlib.uuid4())) for d in drafts] + [(drafts[0], str(uuidlib.uuid4()))]
        procs = [subprocess.Popen(['php', str(here / 'ci_office_incoming.php'), 'register', d, k],
                                  stdout=subprocess.PIPE, stderr=subprocess.PIPE, text=True) for d, k in jobs]
        race = []
        for p in procs:
            out, err = p.communicate(timeout=120)
            race.append({'exit': p.returncode,
                         'out': json.loads(out.strip().splitlines()[-1]) if p.returncode == 0 and out.strip() else None,
                         'err': (err or '').strip().splitlines()[-1:]})
        result['race'] = {'start': start, 'end': php('series', 'A')['lastseq'], 'drafts': drafts,
                          'results': [{'exit': r['exit'], 'uuid': (r['out'] or {}).get('uuid'),
                                       'number': ((r['out'] or {}).get('result') or {}).get('number'),
                                       'repeated': ((r['out'] or {}).get('result') or {}).get('repeated'),
                                       'err': r['err']} for r in race]}
        # ---- 8. layout cases for the registrar
        for shell, width, lang in OFFICEIN_CASES:
            helper('shell', shell); language = helper('lang', lang, str(f['actors']['MA']['userid']))
            ctx, rec = context(browser, width)
            page = login(ctx, 'MA'); errors = track(page)
            case = {'shell': shell, 'width': width, 'lang': lang, 'language_installed': language.get('installed')}
            case['list'] = visit(page, url())
            page.screenshot(path=str(screens / ('officein-list-%s-%s-%s.png' % (shell, width, lang))), full_page=True)
            case['card'] = visit(page, url(card=card_uuid))
            page.screenshot(path=str(screens / ('officein-card-%s-%s-%s.png' % (shell, width, lang))), full_page=True)
            case['browser_events'] = rec.events; case['server_errors'] = errors
            result['cases'].append(case)
            ctx.close()
        helper('shell', 'owned'); helper('lang', 'en', str(f['actors']['MA']['userid']))
        # ---- 9. revocation and cancellation
        ctx, rec = context(browser)
        page = login(ctx, 'EA')
        revocation = {'before': visit(page, url(card=card_uuid))}
        revocation['role_revoked_rights'] = php('ungrant', 'EA')['rights']
        revocation['after_role'] = visit(page, url(card=card_uuid), private)
        revocation['role_restored_rights'] = php('grant', 'EA')['rights']
        revocation['member_revoked'] = php('revoke', 'EA').get('revoked')
        revocation['after_member'] = visit(page, url(), private)
        revocation['member_reopened'] = not php('reopen', 'EA').get('revoked')
        result['revocation'] = revocation
        ctx.close()
        ctx, rec = context(browser)
        page = login(ctx, 'MA')
        page.goto(url(card=card_uuid), wait_until='networkidle')
        page.locator('details:has(form[data-officein-form="cancel"]) > summary').first.click()
        page.fill('#officein-cancelreason', 'Received by mistake (CI)')
        page.locator('form[data-officein-form="cancel"] button[type="submit"]').click(); page.wait_for_load_state('networkidle')
        result['cancelled'] = php('card', 'A', 'MA', card_uuid)['card']
        ctx.close()
        browser.close()
    result['status'] = 'PASS'
except Exception as error:
    result['error'] = '%s: %s' % (type(error).__name__, str(error)[:400])
finally:
    try:
        php('switch', '0', '0'); helper('shell', 'owned')
        for label in ('MA', 'EA', 'MB', 'EB'):
            helper('lang', 'en', str(f['actors'][label]['userid']))
    except Exception:
        pass
    dest = root / 'office-incoming-result.json'
    dest.write_text(json.dumps(result, indent=1, ensure_ascii=False)); dest.chmod(0o644)
print(json.dumps({'status': result['status'], 'cases': len(result['cases']), 'error': result.get('error')}))
sys.exit(0 if result['status'] == 'PASS' else 1)
