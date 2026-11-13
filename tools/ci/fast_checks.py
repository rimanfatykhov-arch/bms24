#!/usr/bin/env python3
"""FAST tier: cheap, change-scoped checks with per-check timing and PASS/FAIL/NOT RUN.

Checks: git diff --check; PHP lint (changed .php); JS syntax (changed .js); AMD src/build sync; EN/RU language key
parity; CSS brace balance + BMS UI scoping of added bms_ui rules; deterministic install ZIP built from `git archive`
of HEAD with source identity (every ZIP entry == Git blob) and SHA256. Usage:
fast_checks.py --base <sha> --head <sha> --out-dir <dir> [--repo .]
"""
import argparse
import hashlib
import json
import re
import shutil
import subprocess
import sys
import tarfile
import io
import time
import zipfile
from pathlib import Path

PLUGIN = 'local/kbacompany/'


def sh(args, cwd, **kw):
    return subprocess.run(args, cwd=cwd, stdout=subprocess.PIPE, stderr=subprocess.STDOUT, universal_newlines=True, **kw)


def main():
    ap = argparse.ArgumentParser()
    ap.add_argument('--base', required=True); ap.add_argument('--head', required=True)
    ap.add_argument('--out-dir', required=True); ap.add_argument('--repo', default='.')
    a = ap.parse_args()
    repo = Path(a.repo).resolve(); out = Path(a.out_dir).resolve(); out.mkdir(parents=True, exist_ok=True)
    changed = [p for p in sh(['git', 'diff', '--name-only', '--diff-filter=ACMR', a.base, a.head], repo).stdout.split() if p]
    report = {'tier': 'FAST', 'base': a.base, 'head': a.head, 'changed_files': len(changed), 'checks': {}, 'timing_s': {}}

    def check(name, fn):
        t = time.time()
        try:
            status, detail = fn()
        except Exception as e:  # a crashing check is a FAIL, never a PASS
            status, detail = 'FAIL', '%s: %s' % (type(e).__name__, str(e)[:300])
        report['checks'][name] = {'status': status, 'detail': detail}
        report['timing_s'][name] = round(time.time() - t, 2)
        print('%-22s %-8s %s' % (name, status, str(detail)[:160]), flush=True)

    def blob(path):
        return subprocess.run(['git', 'show', '%s:%s' % (a.head, path)], cwd=repo, stdout=subprocess.PIPE, check=True).stdout

    def diff_check():
        r = sh(['git', 'diff', '--check', a.base, a.head], repo)
        return ('PASS' if r.returncode == 0 else 'FAIL', r.stdout.strip()[-300:] or 'clean')

    def php_lint():
        files = [p for p in changed if p.endswith('.php')]
        if not files: return 'NOT RUN', 'no PHP changed'
        if not shutil.which('php'): return 'FAIL', 'php not available'
        bad = []
        for p in files:
            tmp = out / 'lint.php'; tmp.write_bytes(blob(p))
            r = sh(['php', '-l', str(tmp)], repo)
            if r.returncode != 0: bad.append(p + ': ' + r.stdout.strip()[-160:])
        return ('PASS' if not bad else 'FAIL', bad or '%d files, PHP %s' % (len(files), sh(['php', '-r', 'echo PHP_VERSION;'], repo).stdout))

    def js_syntax():
        files = [p for p in changed if p.endswith('.js')]
        if not files: return 'NOT RUN', 'no JS changed'
        if not shutil.which('node'): return 'FAIL', 'node not available'
        bad = []
        for p in files:
            tmp = out / 'check.js'; tmp.write_bytes(blob(p))
            r = sh(['node', '--check', str(tmp)], repo)
            if r.returncode != 0: bad.append(p + ': ' + r.stdout.strip()[-160:])
        return ('PASS' if not bad else 'FAIL', bad or '%d files' % len(files))

    def amd_sync():
        files = [p for p in changed if p.startswith(PLUGIN + 'amd/')]
        if not files: return 'NOT RUN', 'no AMD module changed'
        bad = []
        for p in {re.sub(r'amd/(src|build)/(.+?)(\.min)?\.js$', r'\2', p) for p in files}:
            src, build = PLUGIN + 'amd/src/%s.js' % p, PLUGIN + 'amd/build/%s.min.js' % p
            if blob(src) != blob(build): bad.append(p)
        return ('PASS' if not bad else 'FAIL', bad or 'src == build (repository convention)')

    def lang_parity():
        keys = {}
        for lang in ('en', 'ru'):
            text = blob(PLUGIN + 'lang/%s/local_kbacompany.php' % lang).decode('utf-8')
            keys[lang] = set(re.findall(r"^\$string\['([^']+)'\]", text, re.M))
        missing = sorted(keys['en'] ^ keys['ru'])
        touched = any('/lang/' in p for p in changed)
        return ('PASS' if not missing else 'FAIL', missing[:20] or '%d keys EN == RU%s' % (len(keys['en']), '' if touched else ' (lang not changed)'))

    def css_contract():
        files = [p for p in changed if p.endswith('.css')]
        if not files: return 'NOT RUN', 'no CSS changed'
        bad = []
        for p in files:
            text = re.sub(r'/\*.*?\*/', '', blob(p).decode('utf-8'), flags=re.S)
            if text.count('{') != text.count('}'): bad.append(p + ': unbalanced braces')
        added = sh(['git', 'diff', '--unified=0', a.base, a.head, '--', PLUGIN + 'bms_ui/'], repo).stdout
        for line in added.splitlines():
            if line.startswith('+') and not line.startswith('+++') and '{' in line:
                sel = line[1:].split('{')[0].strip()
                if sel and not sel.startswith(('@', '.bms24-app', 'body.', '.bms-', '}')) and not sel.endswith(','):
                    bad.append('unscoped bms_ui selector: ' + sel[:80])
        return ('PASS' if not bad else 'FAIL', bad or '%d CSS files; added bms_ui rules scoped' % len(files))

    def package_identity():
        if not any(p.startswith(PLUGIN) for p in changed):
            return 'NOT RUN', 'plugin not changed'
        src = out / 'pkg'; shutil.rmtree(src, ignore_errors=True); src.mkdir()
        tar = subprocess.run(['git', 'archive', a.head, PLUGIN], cwd=repo, stdout=subprocess.PIPE, check=True).stdout
        archive = tarfile.open(fileobj=io.BytesIO(tar))
        gitbytes = {m.name: archive.extractfile(m).read() for m in archive.getmembers() if m.isfile()}
        archive.extractall(src)
        zpath = out / 'local_kbacompany-candidate.zip'
        r = sh([sys.executable, str(repo / 'tools/build_install.py'), '--source', str(src / PLUGIN), '--output', str(zpath),
                '--manifest', str(out / 'local_kbacompany-candidate.manifest.json')], repo)
        if r.returncode != 0: return 'FAIL', r.stdout[-300:]
        z = zipfile.ZipFile(zpath); mismatch = 0; n = 0
        for name in z.namelist():
            if name.endswith('/'): continue
            n += 1
            if z.read(name) != gitbytes.get(PLUGIN + name[len('kbacompany/'):]): mismatch += 1
        lines = sorted('%s:%s' % (x[len('kbacompany/'):], hashlib.sha256(z.read(x)).hexdigest()) for x in z.namelist() if not x.endswith('/'))
        sha = hashlib.sha256(zpath.read_bytes()).hexdigest()
        report['artifact'] = {'zip_sha256': sha, 'files': n, 'content_tree_sha256': hashlib.sha256('\n'.join(lines).encode()).hexdigest(),
                              'source_sha': a.head}
        return ('PASS' if mismatch == 0 and n > 0 else 'FAIL', '%d entries, %d differ from Git; zip %s' % (n, mismatch, sha[:12]))

    for name, fn in [('git_diff_check', diff_check), ('php_lint', php_lint), ('js_syntax', js_syntax), ('amd_sync', amd_sync),
                     ('lang_parity', lang_parity), ('css_ui_contract', css_contract), ('package_identity', package_identity)]:
        check(name, fn)
    report['status'] = 'FAIL' if any(c['status'] == 'FAIL' for c in report['checks'].values()) else 'PASS'
    (out / 'fast-report.json').write_text(json.dumps(report, indent=1))
    return 1 if report['status'] == 'FAIL' else 0


if __name__ == '__main__':
    sys.exit(main())
