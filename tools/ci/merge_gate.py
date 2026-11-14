#!/usr/bin/env python3
"""BMS24 merge gate (RC32, Issue #82): one unambiguous required check for every PR into main.

decide() is a pure function over the classification and tier reports of THIS PR head:
  * FAST report PASS is always required.
  * STANDARD (focused PHPUnit + security gates) is required for profiles STANDARD and FULL; every test the classifier
    selected must be PASS (NOT RUN / missing / FAIL blocks).
  * BROWSER is required for STANDARD and FULL when anything under local/ changed, on BOTH MariaDB and Percona;
    NOT APPLICABLE only by that explicit rule.
  * Profile FULL additionally requires the FULL workflows to have concluded `success` on the same head SHA.
  * Missing/invalid classification, missing reports, failure, cancellation or unexpected skip => FAIL.
A green gate allows MERGE only. It never authorises a production deployment (separate owner approval + release card).
"""
import argparse
import json
import os
import sys
import time
import urllib.request
from pathlib import Path

FULL_WORKFLOWS = ['PR48 isolated Moodle acceptance', 'RC30 ADR-0004 schema repair', 'RC30 repaired Corporate2613 acceptance',
                  'BMS24 Dev AI checks']
ENGINES = ['mariadb', 'mysqli']  # MariaDB 10.11.19, Percona 8.0.46-37
PROFILES = ('FAST', 'STANDARD', 'FULL')


def browser_applicable(classification):
    return classification['profile'] in ('STANDARD', 'FULL') and any(p.startswith('local/') for p in classification.get('changed_files', []))


def decide(classification, fast, standard, browsers, full):
    """Return (verdict, reasons, requirements). Inputs may be None when a report is missing."""
    reasons, req = [], {}
    if not isinstance(classification, dict) or classification.get('profile') not in PROFILES or not classification.get('head'):
        return 'FAIL', ['classification missing or invalid — merge blocked (classifier error never lowers the profile)'], req
    profile = classification['profile']
    req['profile'] = profile
    # FAST
    req['FAST'] = 'required'
    if not isinstance(fast, dict) or fast.get('status') != 'PASS' or fast.get('head') != classification['head']:
        reasons.append('FAST not PASS for this head')
    # STANDARD
    if profile in ('STANDARD', 'FULL'):
        req['STANDARD'] = 'required'
        tests = list(classification.get('focused_phpunit', [])) + list(classification.get('security_gates', []))
        if not tests:
            reasons.append('STANDARD selected no tests')
        elif not isinstance(standard, dict) or standard.get('status') != 'PASS':
            reasons.append('STANDARD not PASS')
        else:
            got = standard.get('phpunit') or {}
            bad = [t for t in tests if got.get(t) != 'PASS']
            if bad or got.get('init') != 'PASS':
                reasons.append('STANDARD tests not PASS: ' + ', '.join(bad[:8] or ['init']))
    else:
        req['STANDARD'] = 'not applicable (profile FAST)'
    # BROWSER
    if browser_applicable(classification):
        req['BROWSER'] = 'required on ' + ' + '.join(ENGINES)
        for engine in ENGINES:
            r = (browsers or {}).get(engine)
            if not isinstance(r, dict) or r.get('status') != 'PASS' or not r.get('checks'):
                reasons.append('BROWSER not PASS on ' + engine)
            elif any(v.get('status') != 'PASS' for v in r['checks'].values()):
                reasons.append('BROWSER has non-PASS checks on ' + engine)
    else:
        req['BROWSER'] = 'not applicable (profile FAST or no local/ change)'
    # FULL
    if profile == 'FULL':
        req['FULL'] = 'required: ' + '; '.join(FULL_WORKFLOWS)
        for wf in FULL_WORKFLOWS:
            if (full or {}).get(wf) != 'success':
                reasons.append('FULL workflow %r: %s' % (wf, (full or {}).get(wf, 'missing')))
    else:
        req['FULL'] = 'not required for merge (still runs as observation)'
    return ('PASS' if not reasons else 'FAIL'), reasons, req


def load(path):
    try:
        return json.loads(Path(path).read_text(encoding='utf-8'))
    except Exception:
        return None


def full_status(repo, sha, token, wait_s):
    """Wait until every FULL workflow run for `sha` has completed; return {workflow name: conclusion}."""
    deadline = time.time() + wait_s
    while True:
        req = urllib.request.Request('https://api.github.com/repos/%s/actions/runs?head_sha=%s&per_page=100' % (repo, sha),
                                     headers={'Authorization': 'Bearer ' + token, 'Accept': 'application/vnd.github+json'})
        runs = json.loads(urllib.request.urlopen(req, timeout=30).read())['workflow_runs']
        latest = {}
        for r in sorted(runs, key=lambda r: r['run_attempt'] * 10**12 + r['id']):
            latest[r['name']] = r
        result = {wf: (latest[wf]['conclusion'] or latest[wf]['status']) if wf in latest else 'missing' for wf in FULL_WORKFLOWS}
        if all(latest.get(wf, {}).get('status') == 'completed' for wf in FULL_WORKFLOWS) or time.time() > deadline:
            return result
        time.sleep(60)


def main():
    ap = argparse.ArgumentParser()
    ap.add_argument('--artifacts', required=True); ap.add_argument('--report', required=True)
    ap.add_argument('--repo'); ap.add_argument('--sha'); ap.add_argument('--wait-full', type=int, default=3600)
    ap.add_argument('--target', default='this-pr')
    a = ap.parse_args()
    d = Path(a.artifacts)
    t = a.target
    classification = load(d / ('ci-tiers-fast-' + t) / 'classification.json')
    fast = load(d / ('ci-tiers-fast-' + t) / 'fast' / 'fast-report.json')
    standard = load(d / ('ci-tiers-standard-' + t) / 'standard-report.json')
    browsers = {e: load(d / ('ci-tiers-browser-%s-%s' % (t, e)) / 'browser-report.json') for e in ENGINES}
    full = None
    if isinstance(classification, dict) and classification.get('profile') == 'FULL':
        full = full_status(a.repo, a.sha, os.environ['GITHUB_TOKEN'], a.wait_full)
    verdict, reasons, req = decide(classification, fast, standard, browsers, full)
    out = {'verdict': verdict, 'reasons': reasons, 'requirements': req, 'full_workflows': full, 'sha': a.sha,
           'note': 'A PASS allows merge only; production deployment still needs the release card and explicit owner approval.'}
    Path(a.report).write_text(json.dumps(out, indent=1))
    print('## Merge gate: **%s**\n' % verdict)
    print('| Requirement | Value |\n|---|---|')
    for k, v in req.items():
        print('| %s | %s |' % (k, v))
    for r in reasons:
        print('- ' + r)
    print('\n' + out['note'])
    return 0 if verdict == 'PASS' else 1


if __name__ == '__main__':
    sys.exit(main())
