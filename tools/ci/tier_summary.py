#!/usr/bin/env python3
"""Render a Markdown job summary for the CI tiers: classification, PASS/FAIL/NOT RUN and timings."""
import argparse
import json
from pathlib import Path


def load(path):
    try:
        return json.loads(Path(path).read_text(encoding='utf-8')) if path else None
    except Exception:
        return None


def main():
    ap = argparse.ArgumentParser()
    ap.add_argument('--classification'); ap.add_argument('--fast'); ap.add_argument('--standard'); ap.add_argument('--browser'); ap.add_argument('--target', default='')
    a = ap.parse_args()
    c = load(a.classification); f = load(a.fast); s = load(a.standard); b = load(a.browser)
    print('## CI tiers — %s' % a.target)
    if c:
        print('**Profile: %s** (`%s..%s`, %d files). %s' % (c['profile'], c['base'][:7], c['head'][:7], len(c['changed_files']), c['explanation']))
        print('\nModules: %s' % ', '.join(c['modules']))
        top = [r for r in c['reasons'] if r['profile'] == c['profile']][:10]
        if top:
            print('\n| Reason | Where |\n|---|---|')
            for r in top: print('| %s | `%s` |' % (r['reason'], r['where']))
        print('\nRequired: %s' % '; '.join(c['required_workflows']))
    else:
        print('Classification: NOT RUN')
    if a.fast is not None:
        print('\n### FAST')
        if f:
            print('| Check | Status | s | Detail |\n|---|---|---|---|')
            for k, v in f['checks'].items():
                print('| %s | %s | %s | %s |' % (k, v['status'], f['timing_s'].get(k), str(v['detail'])[:120].replace('|', '/')))
            if f.get('artifact'): print('\nArtifact: ZIP SHA256 `%s`, %d files, content tree `%s`, source `%s`' % (
                f['artifact']['zip_sha256'], f['artifact']['files'], f['artifact']['content_tree_sha256'][:16], f['artifact']['source_sha'][:12]))
        else:
            print('FAST report: NOT RUN')
    if a.standard is not None:
        print('\n### STANDARD')
        if s:
            print('Status: **%s** — %s\n' % (s['status'], s['scope']))
            print('| Test | Status | s |\n|---|---|---|')
            for k, v in s['phpunit'].items():
                print('| %s | %s | %s |' % (k, v, s['timing_s'].get('test:' + k, s['timing_s'].get('phpunit_init') if k == 'init' else '')))
            print('\nTimings: ' + ', '.join('%s %ss' % (k, v) for k, v in s['timing_s'].items() if not k.startswith('test:')))
        elif c and c['profile'] == 'FAST':
            print('NOT REQUIRED (profile FAST)')
        else:
            print('STANDARD report: NOT RUN')
    if a.browser is not None:
        print('\n### BROWSER')
        if b:
            print('Status: **%s** — %s\n' % (b['status'], b['scope']))
            print('| Check | Status |\n|---|---|')
            for k, v in b['checks'].items(): print('| %s | %s |' % (k, v['status']))
            print('\nTimings: ' + ', '.join('%s %ss' % (k, v) for k, v in b['timing_s'].items()))
            if b.get('error'): print('\nError: `%s`' % b['error'])
        else:
            print('BROWSER report: NOT RUN')


if __name__ == '__main__':
    main()
