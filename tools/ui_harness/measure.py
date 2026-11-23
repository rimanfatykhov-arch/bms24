"""Measure harness pages in headless Chromium and check the visual rules (#160, task B).

    python tools/ui_harness/build.py --out DIR
    python tools/ui_harness/measure.py --out DIR [--widths 320,375,768,1440] [--only PAGE_PREFIX]

Serves DIR on 127.0.0.1 (random port), opens every page at every width, runs probe.js and writes measurements.json
plus screenshots (shots/<page>-<width>.png at 768 and 1440). Exit 0 = every rule holds, 1 = violations (listed),
2 = Playwright missing. Needs: pip install playwright && python -m playwright install chromium.

Rules mirror the BROWSER contract communication_ux2_primary_action_geometry (#152): readable full "New conversation"
button in the card header, not over the tabs, with its POST form; no clipped control label; no horizontal overflow;
wide list search. A harness PASS is a pre-push signal on synthetic pages, not CI and not acceptance.
"""
import argparse
import functools
import http.server
import json
import sys
import threading
from pathlib import Path


def violations(m):
    """Rule check for one probe result; returns a list of human-readable problems (empty = PASS)."""
    bad = []
    if not m.get('root'):
        return ['Center root not rendered']
    if m.get('overflow'):
        bad.append('horizontal overflow (wider: %s)' % ', '.join(m.get('wide') or []))
    if m.get('clipped'):
        bad.append('clipped labels: %s' % ' | '.join(m['clipped']))
    a = m.get('action')
    if not a:
        bad.append('"New conversation" not visible')
    else:
        if a['count'] != 1:
            bad.append('%d "New conversation" actions' % a['count'])
        if a['width'] < 96 or a['height'] < 32:
            bad.append('"New conversation" %dx%d px, not a full button' % (a['width'], a['height']))
        if a['scroll_width'] > a['client_width'] + 1:
            bad.append('"New conversation" label clipped (%d > %d)' % (a['scroll_width'], a['client_width']))
        if not a['in_header']:
            bad.append('"New conversation" outside the card header')
        if a['overlaps_tabs']:
            bad.append('"New conversation" overlaps the section tabs')
        if not a['in_form']:
            bad.append('"New conversation" without POST/sesskey form')
    s = m.get('search')
    if s and (s['width'] < 200 or s['width'] < 0.85 * s['side_width'] or s['input_width'] < s['width'] - 64):
        bad.append('list search narrow (%d of %d, input %d)' % (s['width'], s['side_width'], s['input_width']))
    return bad


class QuietHandler(http.server.SimpleHTTPRequestHandler):
    def log_message(self, *args):
        pass


def serve(root):
    handler = functools.partial(QuietHandler, directory=str(root))
    server = http.server.ThreadingHTTPServer(('127.0.0.1', 0), handler)
    threading.Thread(target=server.serve_forever, daemon=True).start()
    return server


def launch(p, channel):
    """Bundled Chromium, else an installed Edge/Chrome (the Playwright browser CDN is not reachable everywhere)."""
    tried = []
    for ch in ([channel] if channel else [None, 'msedge', 'chrome']):
        try:
            return p.chromium.launch(headless=True, channel=ch) if ch else p.chromium.launch(headless=True)
        except Exception as e:  # browser not installed: try the next one
            tried.append('%s: %s' % (ch or 'bundled chromium', str(e).splitlines()[0][:120]))
    raise SystemExit('no browser could be started:\n  ' + '\n  '.join(tried))


def main(argv=None):
    ap = argparse.ArgumentParser(description=__doc__.split('\n')[0])
    ap.add_argument('--out', type=Path, required=True, help='directory written by build.py')
    ap.add_argument('--channel', choices=['msedge', 'chrome'], help='use an installed browser instead of bundled Chromium')
    ap.add_argument('--widths', default='320,375,768,1440')
    ap.add_argument('--only', default='', help='measure only pages whose name starts with this prefix')
    a = ap.parse_args(argv)
    try:
        from playwright.sync_api import sync_playwright
    except ImportError:
        print('Playwright not installed: pip install playwright && python -m playwright install chromium')
        return 2
    root = a.out.resolve()
    manifest = json.loads((root / 'manifest.json').read_text(encoding='utf-8'))
    pages = [p['page'] for p in manifest['pages'] if p['page'].startswith(a.only)]
    widths = [int(w) for w in a.widths.split(',')]
    (root / 'shots').mkdir(exist_ok=True)
    server = serve(root)
    base = 'http://127.0.0.1:%d/' % server.server_address[1]
    results, failed = [], 0
    try:
        with sync_playwright() as p:
            browser = launch(p, a.channel)
            for width in widths:
                ctx = browser.new_context(viewport={'width': width, 'height': 900})
                page = ctx.new_page()
                errors = []
                page.on('pageerror', lambda e: errors.append(str(e)[:200]))
                for name in pages:
                    errors.clear()
                    page.goto(base + name, wait_until='load')
                    m = page.evaluate('window.BMS24_PROBE()')
                    problems = violations(m) + ['page error: %s' % e for e in errors]
                    if width in (768, 1440):
                        page.screenshot(path=str(root / 'shots' / ('%s-%d.png' % (name[:-5], width))))
                    results.append({'page': name, 'width': width, 'probe': m, 'problems': problems})
                    failed += bool(problems)
                    print('%-4s %-34s %5d  %s' % ('FAIL' if problems else 'ok', name, width, '; '.join(problems)))
                ctx.close()
            browser.close()
    finally:
        server.shutdown()
    (root / 'measurements.json').write_text(json.dumps({'repo': manifest['repo'], 'results': results},
                                                       ensure_ascii=False, indent=1), encoding='utf-8')
    print('%d of %d page/width cases failed; details in %s' % (failed, len(results), root / 'measurements.json'))
    return 1 if failed else 0


if __name__ == '__main__':
    sys.exit(main())
