#!/usr/bin/env python3
"""Advisory only. Required FULL and release approvals never depend on this output."""
import argparse
import json
import re
import subprocess


def classify(path, status, patch):
    # Deliberately narrow allowlists. Renames/deletions, tests and policy are FULL.
    if status != 'M':
        return 'FULL', 'structural or unknown change'
    sensitive = r'(?i)(sesskey|csrf|capabilit|permission|authoriz|companyuuid|tenant|pluginfile|attachment|upload|download|filearea|rbac|sql|finance|payment|token|\bapi\b)'
    changed = '\n'.join(x[1:] for x in patch.splitlines() if x[:1] in ('+', '-') and x[:3] not in ('+++', '---'))
    if re.search(sensitive, path + '\n' + changed):
        return 'FULL', 'security or integration semantics'
    if re.fullmatch(r'local/kbacompany/lang/(en|ru)/local_kbacompany.php', path):
        lines = [x.strip() for x in changed.splitlines() if x.strip()]
        if lines and all(re.fullmatch(r"\$string\['[a-zA-Z0-9_]+'\]\s*=\s*'(?:[^'\\]|\\.)*';", x) for x in lines):
            return 'FAST', 'literal translations'
        # PHP translations can execute code: unrecognised syntax is FULL.
        return 'FULL', 'translation syntax requires review'
    if re.fullmatch(r'local/kbacompany/bms_ui/[a-zA-Z0-9_/-]+\.css', path):
        if re.search(r'(?i)(url\s*\(|@import|content\s*:|display\s*:|visibility\s*:|pointer-events)', changed):
            return 'FULL', 'CSS can hide controls or load resources'
        return 'FAST', 'presentation CSS; isolated visual smoke still required'
    if path == 'local/kbacompany/classes/local/deviation_service.php':
        # Not currently eligible: service includes company and domain boundaries.
        return 'FULL', 'domain service requires full regression'
    return 'FULL', 'unknown risk (no approved STANDARD allowlist yet)'


def inspect(base, head):
    def git(*args):
        return subprocess.check_output(['git', *args])
    base = git('rev-parse', '--verify', base + '^{commit}').decode().strip()
    head = git('rev-parse', '--verify', head + '^{commit}').decode().strip()
    raw = git('diff', '--no-renames', '--name-status', '-z', base, head).decode().split('\0')
    rows = []
    for i in range(0, len(raw)-1, 2):
        status, path = raw[i:i+2]
        patch = git('diff', '--no-ext-diff', '--no-textconv', '--unified=0', base, head, '--', path).decode(errors='replace')
        tier, reason = classify(path, status, patch)
        rows.append(dict(path=path, status=status, profile=tier, reason=reason))
    tier = 'FULL' if not rows or any(x['profile'] == 'FULL' for x in rows) else 'FAST'
    return dict(base=base, head=head, recommendation=tier, files=rows,
                mandatory_full=True, release_authorized=False,
                standard='Fixed Issue79 benchmark runs in shadow regardless of recommendation')


if __name__ == '__main__':
    ap = argparse.ArgumentParser()
    ap.add_argument('--base', required=True)
    ap.add_argument('--head', required=True)
    args = ap.parse_args()
    print(json.dumps(inspect(args.base, args.head), indent=2))
