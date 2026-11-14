#!/usr/bin/env python3
"""BMS24 merge gate (RC32, Issue #82): one unambiguous required check for every PR into main.

decide() is a pure function. It returns PASS only with positive, candidate-bound evidence:
  * Identity: classification head/base == the PR event head/base; every tier report carries identity.json produced by
    THIS workflow run for THIS target (and engine) and the stamped SHA256 matches the report bytes.
  * Job outcomes (GitHub Actions API, this run attempt): 'Plan targets', FAST/STANDARD/BROWSER legs of the candidate
    ('this-pr') must have concluded `success`. A PASS report from a job that later failed or was cancelled blocks; a
    missing or skipped required job blocks. NOT APPLICABLE exists only by the explicit profile rule below.
  * Negative-control legs (targets named negative-*) are REQUIRED when planned: their success means the planted defect was
    detected. Replay legs (replay-*) are INFORMATION only and never change the candidate verdict.
  * FAST report PASS is always required.
  * STANDARD (focused PHPUnit + security gates): required for profiles STANDARD and FULL; every selected test PASS.
  * BROWSER: required for profiles STANDARD and FULL (the BROWSER job runs for every non-FAST profile), on MariaDB AND
    Percona; the report must contain every required check (contract.required_check_names()) with status PASS.
  * Profile FULL additionally requires the newest FULL run of each FULL workflow (matched by workflow file path, event
    pull_request, head SHA, PR number and base SHA at event time) to have concluded `success`. Older runs never mask a
    newer failure, cancellation or unfinished run. The retained Corporate2613 workflow is not part of FULL (expected FAIL
    history) and is not mixed with the repaired one.
  * Profile FAST and STANDARD do not wait for FULL (FULL keeps running as observation).
A green gate allows MERGE only. It never authorises a production deployment (separate owner approval + release card).
Limitation: the gate code itself is taken from the PR merge commit; changes to tools/ci are classified FULL.
"""
import argparse
import hashlib
import json
import os
import sys
import time
import urllib.request
from pathlib import Path

sys.path.insert(0, str(Path(__file__).resolve().parent / 'browser'))
import contract  # noqa: E402

FULL_WORKFLOWS = {  # workflow file path -> display name (the path is what is matched)
    '.github/workflows/pr48-moodle-acceptance.yml': 'PR48 isolated Moodle acceptance',
    '.github/workflows/rc30-schema-repair.yml': 'RC30 ADR-0004 schema repair',
    '.github/workflows/rc30-corporate2613-repair.yml': 'RC30 repaired Corporate2613 acceptance',
    '.github/workflows/bms24-ci.yml': 'BMS24 Dev AI checks',
}
ENGINES = {'mariadb': 'MariaDB 10.11.19', 'mysqli': 'Percona 8.0.46-37'}
PROFILES = ('FAST', 'STANDARD', 'FULL')
SHA_LEN = 40


def required_browser_checks():
    return contract.required_check_names()


def job_names(target):
    return {'FAST': 'FAST / ' + target, 'STANDARD': 'STANDARD / ' + target,
            'BROWSER': {e: 'BROWSER / %s / %s' % (target, n) for e, n in ENGINES.items()}}


def _sha(x):
    return isinstance(x, str) and len(x) == SHA_LEN and all(ch in '0123456789abcdef' for ch in x)


JOB_KEYS = {'FAST': 'fast', 'STANDARD': 'standard', 'BROWSER': 'browser'}  # GITHUB_JOB of the producing workflow job


def execution_attempts(jobs_by_attempt, current):
    """Attempt in which each job of the CURRENT attempt actually executed. Pure.

    Observed GitHub behaviour (runs 43561610550 full re-run, 43561610462 re-run of failed jobs, Issue #82): on 'Re-run failed
    jobs' the successful jobs are copied into the new attempt with a new job id but the original started_at, and their
    artifacts stay those of the original attempt; re-executed jobs get a new started_at and upload new artifacts. So the
    execution attempt is the earliest attempt whose record of the same job has the same started_at as the current record.
    """
    out = {}
    cur = (jobs_by_attempt or {}).get(current) or {}
    for name, rec in cur.items():
        started = (rec or {}).get('started_at')
        if not started:
            continue
        att = current
        for a in range(current - 1, 0, -1):
            prev = ((jobs_by_attempt.get(a) or {}).get(name) or {})
            if prev.get('started_at') == started:
                att = a
            else:
                break
        out[name] = att
    return out


def _identity(ev, tier, expected, profile, engine='', attempt=None):
    """ev = {'identity': dict, 'report': dict, 'report_sha256': hex of the downloaded bytes}. Returns problems.

    attempt = the attempt in which the producing job actually executed (execution_attempts()); the identity must have been
    stamped by that job execution: same run id, that attempt, the expected GITHUB_JOB key.
    """
    if not isinstance(ev, dict) or not isinstance(ev.get('identity'), dict):
        return ['%s%s identity missing' % (tier, '/' + engine if engine else '')]
    i, p = ev['identity'], []
    want = {'tier': tier, 'target': expected['target'], 'head': expected['head'], 'base': expected['base'], 'profile': profile,
            'engine': engine, 'run_id': str(expected['run_id']), 'job': JOB_KEYS[tier],
            'run_attempt': str(attempt) if attempt else '<execution attempt unknown>'}
    for k, v in want.items():
        if str(i.get(k)) != str(v):
            p.append('%s%s identity %s=%r, expected %r' % (tier, '/' + engine if engine else '', k, i.get(k), v))
    if not i.get('report_sha256') or i.get('report_sha256') != ev.get('report_sha256'):
        p.append('%s%s report bytes do not match the stamped hash' % (tier, '/' + engine if engine else ''))
    if not isinstance(ev.get('report'), dict):
        p.append('%s%s report missing or not JSON' % (tier, '/' + engine if engine else ''))
    return p


def decide(expected, classification, evidence, jobs, planned, full, exec_attempts=None):
    """Fail-closed wrapper: any malformed input that makes the decision raise is a FAIL, never a PASS."""
    try:
        return _decide(expected, classification, evidence, jobs, planned, full, exec_attempts)
    except Exception as e:  # noqa: BLE001
        return 'FAIL', ['gate input malformed: %s' % type(e).__name__], {}, {}


def _decide(expected, classification, evidence, jobs, planned, full, exec_attempts):
    """Pure decision.

    expected: {'target', 'head', 'base', 'run_id'} taken from the PR event / this run (never from the reports).
    classification: classification.json of the FAST leg of the target.
    evidence: {'FAST': ev, 'STANDARD': ev, 'BROWSER': {engine: ev}} with ev as in _identity().
    jobs: {job name: conclusion} for this run attempt, or None when the API could not be read.
    planned: list of planned target names (plan job output).
    full: {workflow path: {'conclusion'|'status', 'id', ...}} or None (only consulted for profile FULL).
    exec_attempts: {job name: attempt in which it executed} (execution_attempts()); evidence must come from that attempt.
    Returns (verdict, reasons, requirements, info).
    """
    exec_attempts = exec_attempts if isinstance(exec_attempts, dict) else {}
    reasons, req, info = [], {}, {}
    if not isinstance(expected, dict) or not _sha(expected.get('head')) or not _sha(expected.get('base')) or not expected.get('run_id'):
        return 'FAIL', ['expected candidate identity (head/base/run) missing'], req, info
    target = expected.get('target')
    if not isinstance(classification, dict) or classification.get('profile') not in PROFILES:
        return 'FAIL', ['classification missing or invalid - merge blocked (classifier error never lowers the profile)'], req, info
    if classification.get('head') != expected['head'] or classification.get('base') != expected['base']:
        return 'FAIL', ['classification is for %r..%r, expected %r..%r' % (classification.get('base'), classification.get('head'),
                                                                           expected['base'], expected['head'])], req, info
    profile = classification['profile']
    req['profile'] = profile
    evidence = evidence if isinstance(evidence, dict) else {}
    names = job_names(target)

    # ---- job outcomes of this run attempt (a PASS report never outweighs a failed/cancelled/skipped job)
    if not isinstance(jobs, dict) or not jobs:
        reasons.append('job outcomes of this run could not be read')
        jobs = {}
    if not isinstance(planned, list) or target not in planned:
        reasons.append('plan does not contain target %r' % target)
        planned = planned if isinstance(planned, list) else []
    required_jobs = ['Plan targets', names['FAST'], names['STANDARD']] + list(names['BROWSER'].values())
    negatives = [t for t in planned if t.startswith('negative-')] if target == 'this-pr' else []
    for t in negatives:
        n = job_names(t)
        required_jobs += [n['FAST'], n['STANDARD']] + list(n['BROWSER'].values())
    info['replay_jobs_information_only'] = {k: v for k, v in sorted(jobs.items()) if k.split(' / ')[1:2] and k.split(' / ')[1].startswith('replay-')}
    req['jobs'] = 'success required: ' + ', '.join(required_jobs)
    for j in required_jobs:
        if jobs.get(j) != 'success':
            reasons.append('job %r concluded %r' % (j, jobs.get(j, 'missing')))

    # ---- FAST (always)
    req['FAST'] = 'required'
    ev = evidence.get('FAST')
    p = _identity(ev, 'FAST', expected, profile, attempt=exec_attempts.get(names['FAST']))
    reasons += p
    if not p:
        r = ev['report']
        if r.get('status') != 'PASS' or r.get('head') != expected['head'] or r.get('base') != expected['base']:
            reasons.append('FAST report not PASS for this head/base')

    # ---- STANDARD
    if profile in ('STANDARD', 'FULL'):
        req['STANDARD'] = 'required'
        tests = list(classification.get('focused_phpunit') or []) + list(classification.get('security_gates') or [])
        ev = evidence.get('STANDARD')
        p = _identity(ev, 'STANDARD', expected, profile, attempt=exec_attempts.get(names['STANDARD']))
        reasons += p
        if not tests:
            reasons.append('STANDARD selected no tests')
        elif not p:
            r = ev['report']
            got = r.get('phpunit') if isinstance(r.get('phpunit'), dict) else {}
            bad = [t for t in tests if got.get(t) != 'PASS']
            if r.get('status') != 'PASS' or bad or got.get('init') != 'PASS':
                reasons.append('STANDARD not PASS: ' + ', '.join(bad[:8] or ['status/init']))
    else:
        req['STANDARD'] = 'not applicable (profile FAST); job must still conclude success'

    # ---- BROWSER
    if profile in ('STANDARD', 'FULL'):
        need = required_browser_checks()
        req['BROWSER'] = 'required on %s; %d required checks' % (' + '.join(ENGINES.values()), len(need))
        for engine in ENGINES:
            br = evidence.get('BROWSER')
            ev = br.get(engine) if isinstance(br, dict) else None
            p = _identity(ev, 'BROWSER', expected, profile, engine, attempt=exec_attempts.get(names['BROWSER'][engine]))
            reasons += p
            if p:
                continue
            r = ev['report']
            checks = r.get('checks') if isinstance(r.get('checks'), dict) else {}
            missing = [k for k in need if not isinstance(checks.get(k), dict)]
            bad = [k for k in need if isinstance(checks.get(k), dict) and checks[k].get('status') != 'PASS']
            extra_bad = [k for k, v in checks.items() if k not in need and (not isinstance(v, dict) or v.get('status') != 'PASS')]
            if r.get('status') != 'PASS' or r.get('dbtype') != engine or missing or bad or extra_bad:
                reasons.append('BROWSER %s: status=%r dbtype=%r missing=%r non-PASS=%r' % (engine, r.get('status'), r.get('dbtype'),
                                                                                       missing[:5], (bad + extra_bad)[:5]))
    else:
        req['BROWSER'] = 'not applicable (profile FAST); jobs must still conclude success'

    # ---- FULL
    if profile == 'FULL':
        req['FULL'] = 'required: newest run of ' + '; '.join(FULL_WORKFLOWS)
        for wf in FULL_WORKFLOWS:
            r = full.get(wf) if isinstance(full, dict) else None
            r = r if isinstance(r, dict) else {}
            if r.get('conclusion') != 'success':
                reasons.append('FULL %s: %s' % (FULL_WORKFLOWS[wf], r.get('conclusion') or r.get('status') or 'missing'))
    else:
        req['FULL'] = 'not required for merge (still runs as observation)'
    return ('PASS' if not reasons else 'FAIL'), reasons, req, info


def select_full_runs(runs, head, base, pr, historical=False):
    """Pick, per FULL workflow file, the NEWEST pull_request run for this head/PR/base. Pure.

    The run object already reflects its latest attempt; a newer run id always wins over an older run, whatever its attempt
    number, so an old successful re-run can never mask a newer failure, cancellation or unfinished run. historical=True
    (replay only) accepts runs whose pull_requests list is empty because the PR is closed.
    """
    out = {}
    for wf in FULL_WORKFLOWS:
        cands = []
        for r in runs or []:
            if (r.get('path') or '').split('@')[0] != wf or r.get('event') != 'pull_request' or r.get('head_sha') != head:
                continue
            prs = r.get('pull_requests') or []
            bound = any(x.get('number') == pr and (x.get('base') or {}).get('sha') == base for x in prs)
            if bound or (historical and not prs):
                cands.append(r)
        if not cands:
            out[wf] = {'status': 'missing'}
            continue
        r = max(cands, key=lambda x: x['id'])
        out[wf] = {'id': r['id'], 'run_attempt': r.get('run_attempt'), 'status': r.get('status'),
                   'conclusion': r.get('conclusion') if r.get('status') == 'completed' else None, 'name': r.get('name')}
    return out


def _api(url, token):
    req = urllib.request.Request(url, headers={'Authorization': 'Bearer ' + token, 'Accept': 'application/vnd.github+json'})
    for i in range(5):
        try:
            return json.loads(urllib.request.urlopen(req, timeout=30).read())
        except Exception:
            if i == 4:
                raise
            time.sleep(5 * (i + 1))


def full_status(repo, head, base, pr, token, wait_s, historical=False):
    deadline = time.time() + wait_s
    while True:
        runs, page = [], 1
        while True:
            d = _api('https://api.github.com/repos/%s/actions/runs?head_sha=%s&per_page=100&page=%d' % (repo, head, page), token)
            runs += d.get('workflow_runs', [])
            if len(d.get('workflow_runs', [])) < 100:
                break
            page += 1
        sel = select_full_runs(runs, head, base, pr, historical)
        if all(v.get('status') == 'completed' for v in sel.values()) or time.time() > deadline:
            return sel
        time.sleep(60)


def run_jobs(repo, run_id, attempt, token):
    """{job name: {'conclusion': conclusion or status, 'started_at': ...}} for one attempt."""
    jobs, page = {}, 1
    while True:
        d = _api('https://api.github.com/repos/%s/actions/runs/%s/attempts/%s/jobs?per_page=100&page=%d' % (repo, run_id, attempt, page), token)
        for j in d.get('jobs', []):
            jobs[j['name']] = {'conclusion': j.get('conclusion') if j.get('status') == 'completed' else j.get('status'),
                               'started_at': j.get('started_at')}
        if len(d.get('jobs', [])) < 100:
            return jobs
        page += 1


def artifact_name(tier, target, attempt, engine=''):
    return ({'FAST': 'ci-tiers-fast-%s', 'STANDARD': 'ci-tiers-standard-%s', 'BROWSER': 'ci-tiers-browser-%s-' + engine}[tier] % target
            + '-a%s' % attempt)


def load_evidence(d, tier, target, attempt, engine=''):
    """Read identity.json + report bytes of one tier artifact of the given execution attempt; hash the exact bytes."""
    if not attempt:
        return {}
    base = d / artifact_name(tier, target, attempt, engine)
    report = {'FAST': base / 'fast' / 'fast-report.json', 'STANDARD': base / 'standard-report.json',
              'BROWSER': base / 'browser-report.json'}[tier]
    ev = {}
    for k, path in (('identity', base / 'identity.json'), ('report', report)):
        try:
            raw = path.read_bytes()
            ev[k] = json.loads(raw)
            if k == 'report':
                ev['report_sha256'] = hashlib.sha256(raw).hexdigest()
        except Exception:
            ev[k] = None
    return ev


def main():
    ap = argparse.ArgumentParser()
    ap.add_argument('--artifacts', required=True); ap.add_argument('--report', required=True)
    ap.add_argument('--repo', required=True); ap.add_argument('--target', default='this-pr')
    ap.add_argument('--head', required=True); ap.add_argument('--base', required=True); ap.add_argument('--pr', type=int, required=True)
    ap.add_argument('--run-id', required=True); ap.add_argument('--run-attempt', required=True)
    ap.add_argument('--planned', required=True, help='JSON list of planned target objects (plan job output)')
    ap.add_argument('--wait-full', type=int, default=3600)
    ap.add_argument('--historical', action='store_true', help='replay of a closed PR (information only)')
    a = ap.parse_args()
    token = os.environ.get('GITHUB_TOKEN', '')
    d = Path(a.artifacts)
    expected = {'target': a.target, 'head': a.head, 'base': a.base, 'run_id': a.run_id}
    try:
        planned = [t['name'] for t in json.loads(a.planned)]
    except Exception:
        planned = None
    current = int(a.run_attempt)
    try:
        by_attempt = {n: run_jobs(a.repo, a.run_id, n, token) for n in range(1, current + 1)}
        jobs = {k: v['conclusion'] for k, v in by_attempt[current].items()}
        exec_attempts = execution_attempts(by_attempt, current)
    except Exception as e:
        print('job API error: %s' % type(e).__name__)
        jobs, exec_attempts = None, {}
    names = job_names(a.target)
    try:
        classification = json.loads((d / artifact_name('FAST', a.target, exec_attempts.get(names['FAST']))
                                     / 'classification.json').read_text(encoding='utf-8'))
    except Exception:
        classification = None
    evidence = {'FAST': load_evidence(d, 'FAST', a.target, exec_attempts.get(names['FAST'])),
                'STANDARD': load_evidence(d, 'STANDARD', a.target, exec_attempts.get(names['STANDARD'])),
                'BROWSER': {e: load_evidence(d, 'BROWSER', a.target, exec_attempts.get(names['BROWSER'][e]), e) for e in ENGINES}}
    full = None
    if isinstance(classification, dict) and classification.get('profile') == 'FULL':
        try:
            full = full_status(a.repo, a.head, a.base, a.pr, token, a.wait_full, a.historical)
        except Exception as e:
            print('runs API error: %s' % type(e).__name__)
    verdict, reasons, req, info = decide(expected, classification, evidence, jobs, planned, full, exec_attempts)
    out = {'verdict': verdict, 'reasons': reasons, 'requirements': req, 'expected': expected, 'full_runs': full, 'jobs': jobs,
           'run_attempt': current, 'execution_attempts': exec_attempts,
           'information': info, 'mode': 'historical replay (information only)' if a.historical else 'candidate',
           'note': 'A PASS allows merge only; production deployment still needs the release card and explicit owner approval.'}
    Path(a.report).write_text(json.dumps(out, indent=1))
    print('## Merge gate (%s): **%s**\n' % (a.target, verdict))
    print('| Requirement | Value |\n|---|---|')
    for k, v in req.items():
        print('| %s | %s |' % (k, str(v).replace('|', '/')))
    for r in reasons:
        print('- ' + r)
    print('\n' + out['note'])
    return 0 if verdict == 'PASS' else 1


if __name__ == '__main__':
    sys.exit(main())
