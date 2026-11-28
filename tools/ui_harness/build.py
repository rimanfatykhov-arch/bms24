"""BMS24 UI harness — render the real Communication Center templates and CSS on synthetic data (#160, task B).

    python tools/ui_harness/build.py --measure [--repo PATH] [--out DIR] [--shell owned|legacy|both]

Writes static pages (one per fixtures.SCENARIOS entry and shell), copies of the plugin CSS they load, the geometry
probe and manifest.json into --out (default: <tmp>/bms24-ui-harness). Open index.html, or run measure.py.

What is taken from the source (so the harness follows the code, not a hand copy):
  * templates: local/kbacompany/templates/bms24_*.mustache, rendered strictly (unknown partial = error);
  * stylesheets and their order: theme bundle styles.css first, then $PAGE->requires->css() of
    communication_center.php, then (owned shell) bms24_shell_service.php — the order Moodle emits them;
  * owned body class: bms24_shell_service::OWNED_BODY_CLASS; page wrapper classes: communication_center.php;
  * workspace wrapper id: present only when communication_center_ui_service::workspace() emits it.

Limits (also in README): not Moodle — no Boost theme beyond Bootstrap 4.6, no BMS app shell menu, no JS controller,
no server data or permissions. A harness PASS is a fast pre-push signal; it is not CI, not acceptance.
"""
import argparse
import hashlib
import html
import json
import re
import shutil
import subprocess
import sys
import tempfile
import urllib.request
from pathlib import Path

HERE = Path(__file__).resolve().parent
sys.path.insert(0, str(HERE))
from fixtures import SCENARIOS, model  # noqa: E402
from mustache import Renderer, load_strings  # noqa: E402

PLUGIN_URL = '/local/kbacompany/'
BOOTSTRAP = 'https://cdn.jsdelivr.net/npm/bootstrap@4.6.2/dist/css/bootstrap.min.css'
BOOTSTRAP_PATH = 'assets/bootstrap-4.6.2.min.css'
BOOTSTRAP_SHA256 = 'f886516f3d41e9e7bd994c7f7a39a89cafae9483f90396cb0ddeafe8d1ea5e72'


def bootstrap_css(target, source):
    """Use a local copy if available; the browser never needs to fetch Bootstrap."""
    try:
        local = source or target
        if source is not None or local.is_file():
            data = local.read_bytes()
        else:
            with urllib.request.urlopen(BOOTSTRAP, timeout=15) as response:
                data = response.read(256 * 1024)
    except OSError as error:
        raise SystemExit(f'Bootstrap 4.6.2 unavailable ({error}). Use --bootstrap with a local copy.') from error
    if hashlib.sha256(data).hexdigest() != BOOTSTRAP_SHA256:
        raise SystemExit(f'Bootstrap 4.6.2 checksum mismatch. Replace {local} or pass --bootstrap with the official CSS.')
    return data


def css_requires(php):
    """Plugin stylesheets a PHP file adds with $PAGE->requires->css(new moodle_url('/local/kbacompany/...')), in order."""
    text = php.read_text(encoding='utf-8') if php.is_file() else ''
    return re.findall(r"requires->css\(\s*new\s+\\?moodle_url\(\s*'" + re.escape(PLUGIN_URL) + r"([^']+\.css)'", text)


def stylesheets(plugin, shell):
    order = ['styles.css'] + css_requires(plugin / 'communication_center.php')
    if shell == 'owned':
        order += css_requires(plugin / 'classes' / 'local' / 'bms24_shell_service.php')
    seen, out = set(), []
    for rel in order:
        if rel not in seen and (plugin / rel).is_file():
            seen.add(rel)
            out.append(rel)
    return out


def source_facts(plugin):
    shell = (plugin / 'classes' / 'local' / 'bms24_shell_service.php').read_text(encoding='utf-8')
    owned = re.search(r"OWNED_BODY_CLASS\s*=\s*'([^']+)'", shell)
    page = (plugin / 'communication_center.php').read_text(encoding='utf-8')
    wrapper = re.search(r"start_div\('([^']*bms-comm2-shell[^']*)'", page)
    service = (plugin / 'classes' / 'local' / 'communication_center_ui_service.php').read_text(encoding='utf-8')
    return {'owned_body_class': owned.group(1) if owned else 'bms24-owned-page',
            'page_wrapper_class': wrapper.group(1) if wrapper else 'bms-tui bms-comm2 bms-comm2-shell',
            'root_id': 'bms24-comm-root' if "'id' => 'bms24-comm-root'" in service else ''}


def workspace(renderer, data, facts):
    """Mirror of communication_center_ui_service::workspace(): card with header actions and body."""
    card = renderer.render('bms24_card', {
        'header': True, 'title': data['title'],
        'actionshtml': renderer.render('bms24_comm_header', data),
        'contenthtml': '<div class="bms24-comm-cardbody">' + renderer.render('bms24_comm_body', data) + '</div>'})
    # Initial state that communication_live_ui.js applies on load: the in-conversation search starts collapsed
    # unless a query is present (data-has-query="0"). No other JS behaviour is simulated.
    card = re.sub(r'(<form [^>]*class="[^"]*bms-comm2-message-search)("[^>]*data-has-query="0")', r'\1 is-collapsed\2', card)
    idattr = ' id="%s"' % facts['root_id'] if facts['root_id'] else ''
    return '<div class="bms24-comm"%s data-section="%s">%s</div>' % (idattr, html.escape(data['section']), card)


def git_head(repo):
    try:
        out = subprocess.run(['git', '-C', str(repo), 'rev-parse', 'HEAD'], capture_output=True, text=True, timeout=10)
        dirty = subprocess.run(['git', '-C', str(repo), 'status', '--porcelain', '--', 'local/kbacompany'],
                               capture_output=True, text=True, timeout=10)
        return {'head': out.stdout.strip() if out.returncode == 0 else None,
                'plugin_dirty': bool(dirty.stdout.strip()) if dirty.returncode == 0 else None}
    except (OSError, subprocess.SubprocessError):
        return {'head': None, 'plugin_dirty': None}


def build(repo, out, shells=('owned', 'legacy'), offline=False, bootstrap=None):
    plugin = repo / 'local' / 'kbacompany'
    if not (plugin / 'templates').is_dir():
        raise SystemExit('not a BMS24 checkout: %s' % repo)
    renderer = Renderer(plugin / 'templates', load_strings(plugin / 'lang' / 'ru' / 'local_kbacompany.php'))
    facts = source_facts(plugin)
    manifest_path = out / 'manifest.json'
    if out.exists() and (not out.is_dir() or (any(out.iterdir()) and not manifest_path.is_file())):
        raise SystemExit(f'Choose an empty or existing UI harness output directory: {out}')
    bootstrap_data = None if offline else bootstrap_css(out / BOOTSTRAP_PATH, bootstrap)
    (out / 'assets').mkdir(parents=True, exist_ok=True)
    # An interrupted build remains reusable, but cannot be measured until it has a complete manifest.
    manifest_path.write_text('{}', encoding='utf-8')
    (out / 'measurements.json').unlink(missing_ok=True)
    if bootstrap_data is not None:
        (out / BOOTSTRAP_PATH).write_bytes(bootstrap_data)
    shutil.copy2(HERE / 'probe.js', out / 'probe.js')
    manifest = {'repo': git_head(repo), 'facts': facts, 'shells': {}, 'pages': [], 'bootstrap': not offline}
    copied = {}
    for shell in shells:
        sheets = stylesheets(plugin, shell)
        manifest['shells'][shell] = sheets
        for rel in sheets:
            if rel not in copied:
                target = out / 'assets' / rel
                target.parent.mkdir(parents=True, exist_ok=True)
                shutil.copy2(plugin / rel, target)
                copied[rel] = hashlib.sha256((plugin / rel).read_bytes()).hexdigest()
        links = ([] if offline else ['<link rel="stylesheet" href="%s">' % BOOTSTRAP_PATH]) + \
            ['<link rel="stylesheet" href="assets/%s">' % rel for rel in sheets]
        body = 'bms-comm-page' + (' ' + facts['owned_body_class'] if shell == 'owned' else '')
        for name, (section, kwargs) in SCENARIOS.items():
            page = '%s.%s.html' % (name, shell)
            doc = ('<!doctype html>\n<html lang="ru"><head><meta charset="utf-8">'
                   '<meta name="viewport" content="width=device-width,initial-scale=1">\n'
                   '<title>BMS24 harness · %s · %s</title>\n%s\n<script src="probe.js"></script>\n</head>\n'
                   '<body class="%s" style="margin:0;padding:16px;background:#e4f2fb">\n<div class="%s">%s</div>\n</body></html>\n'
                   % (name, shell, '\n'.join(links), body, facts['page_wrapper_class'],
                      workspace(renderer, model(section, **kwargs), facts)))
            if '{{' in doc:
                raise SystemExit('unrendered mustache tag left in %s' % page)
            (out / page).write_text(doc, encoding='utf-8')
            manifest['pages'].append({'page': page, 'scenario': name, 'section': section, 'shell': shell})
    manifest['css_sha256'] = copied
    manifest['templates_used'] = sorted(renderer.used)
    manifest['missing_strings'] = sorted(renderer.missing_strings)
    rows = ''.join('<li><a href="%s">%s</a></li>' % (p['page'], p['page']) for p in manifest['pages'])
    (out / 'index.html').write_text('<!doctype html><meta charset="utf-8"><title>BMS24 UI harness</title>'
                                    '<h1>BMS24 UI harness</h1><p>Synthetic data only. Not Moodle, not acceptance.</p>'
                                    '<ul>%s</ul>' % rows, encoding='utf-8')
    manifest_path.write_text(json.dumps(manifest, ensure_ascii=False, indent=1), encoding='utf-8')
    return manifest


def main(argv=None):
    ap = argparse.ArgumentParser(description=__doc__.split('\n')[0])
    ap.add_argument('--repo', type=Path, default=HERE.parents[1])
    ap.add_argument('--out', type=Path, default=Path(tempfile.gettempdir()) / 'bms24-ui-harness')
    ap.add_argument('--shell', choices=['owned', 'legacy', 'both'], default='both')
    css = ap.add_mutually_exclusive_group()
    css.add_argument('--offline', action='store_true', help='render pages without Bootstrap for build checks only')
    css.add_argument('--bootstrap', type=Path, help='use an existing official bootstrap.min.css from Bootstrap 4.6.2')
    ap.add_argument('--measure', action='store_true', help='check geometry and save screenshots after the build')
    ap.add_argument('--channel', choices=['msedge', 'chrome'], help='browser to use with --measure')
    a = ap.parse_args(argv)
    if a.measure and a.offline:
        ap.error('--measure needs Bootstrap; omit --offline or use --bootstrap')
    if a.channel and not a.measure:
        ap.error('--channel requires --measure')
    shells = ('owned', 'legacy') if a.shell == 'both' else (a.shell,)
    out = a.out.resolve()
    m = build(a.repo.resolve(), out, shells, a.offline, a.bootstrap)
    print('built %d pages from %s%s -> %s' % (len(m['pages']), m['repo']['head'] or 'unknown HEAD',
                                              ' (+ uncommitted plugin changes)' if m['repo']['plugin_dirty'] else '', a.out))
    for shell, sheets in m['shells'].items():
        print('  %s CSS: %s' % (shell, ', '.join(sheets)))
    if m['missing_strings']:
        print('  strings not found (rendered as keys): %s' % ', '.join(m['missing_strings']))
    print('  preview: %s' % (out / 'index.html'))
    if a.measure:
        from measure import main as measure_main
        args = ['--out', str(out)]
        if a.channel:
            args.extend(['--channel', a.channel])
        return measure_main(args)


if __name__ == '__main__':
    main()
