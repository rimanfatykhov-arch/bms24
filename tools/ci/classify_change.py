#!/usr/bin/env python3
"""BMS24 CI tier classifier (RC31 Pilot 03, Issue #82).

Classifies a Git diff (base..head) into FAST, STANDARD or FULL, lists the changed modules, the focused PHPUnit
tests and the mandatory security gates, and explains every decision. Unknown or ambiguous changes are FULL.

Usage: classify_change.py --base <sha> --head <sha> [--repo <path>] [--json out.json] [--github-output file]
"""
from __future__ import annotations

import argparse
import fnmatch
import json
import re
import subprocess
import sys
from pathlib import Path

PLUGIN = 'local/kbacompany/'

# ---------------------------------------------------------------- FULL by path (schema, permissions, file serving,
# external APIs, Corporate, CI/test infrastructure, platform). A single match makes the whole change FULL.
FULL_PATHS = [
    ('local/kbacompany/db/*', 'database schema, upgrade steps, capabilities or services definition'),
    ('local/kbacompany/version.php', 'plugin version / upgrade trigger'),
    ('local/kbacompany/lib.php', 'plugin callbacks incl. pluginfile file serving'),
    ('local/kbacompany/settings.php', 'site administration settings'),
    ('local/kbacompany/classes/privacy/*', 'privacy provider (personal data export/deletion)'),
    ('local/kbacompany/classes/external/*', 'external web-service API'),
    ('local/kbacompany/classes/task/*', 'scheduled/adhoc tasks (cron side effects)'),
    ('local/kbacompany/classes/event/*', 'events'),
    ('local/kbacompany/classes/local/*access*', 'access control service'),
    ('local/kbacompany/classes/local/*permission*', 'permission service'),
    ('local/kbacompany/classes/local/membership*', 'company membership / revocation'),
    ('local/kbacompany/classes/local/company_context_service.php', 'company context boundary'),
    ('local/kbacompany/classes/local/identity_registry.php', 'canonical identity registry'),
    ('local/kbacompany/classes/local/*integration*', 'external integration'),
    ('local/kbacompany/classes/local/*bitrix*', 'external integration (Bitrix24)'),
    ('local/kbacompany/classes/local/*odoo*', 'external integration (Odoo)'),
    ('local/kbacompany/classes/local/public_api*', 'public API'),
    ('local/kbacompany/classes/local/*cloud*', 'cloud API'),
    ('local/kbacorporate/*', 'Corporate plugin (retained 2613 line)'),
    ('theme/*', 'KBA Theme'),
    ('.github/*', 'CI workflow definition'),
    ('tools/tests/*', 'acceptance test infrastructure'),
    ('tools/build_*', 'release packaging'),
    ('tools/check_*', 'release / preservation gates'),
    ('release/*', 'release artifacts and patches'),
]
# ---------------------------------------------------------------- FULL by content of added/removed lines.
FULL_CONTENT = [
    (r'\$_FILES\b|get_file_storage\(|create_file_from_|->get_file\(|pluginfile|send_stored_file|file_storage',
     'file upload / storage / serving (Moodle File API security)'),
    (r'\b(require_capability|has_capability|has_any_capability|require_login|require_course_login|is_siteadmin)\s*\(',
     'Moodle capability / login check'),
    (r'\b(require_sesskey|confirm_sesskey|sesskey\s*\()', 'CSRF (sesskey) handling'),
    (r'\b(can_view_|can_manage|can_post|can_edit|can_delete|require_participant|require_access|is_revoked|require_thread_context)\w*\s*\(',
     'domain authorization check'),
    (r'\$DB->(execute|get_records_sql|get_record_sql|get_fieldset_sql|count_records_sql|get_recordset_sql|delete_records_select|set_field_select)\b',
     'raw SQL'),
    (r'\b(curl|download_file_content|file_get_contents\s*\(\s*[\'"]https?:)', 'outbound HTTP'),
    (r'\b(unserialize|eval|exec|shell_exec|proc_open|system|passthru)\s*\(', 'dangerous PHP primitive'),
    (r'\b(xmldb_|\$dbman->|upgrade_plugin_savepoint)', 'schema / upgrade operation'),
]
# ---------------------------------------------------------------- FAST: presentation only.
# CSS is deliberately NOT here: PR #86's security review showed CSS semantics (opacity/visibility,
# resource URLs) can weaken the UI security contract in ways a FAST-level check cannot tell apart.
FAST_PATHS = ['*.md', 'docs/*', '*.png', '*.svg', '*.jpg', '*.gif', '*.txt',
              'local/kbacompany/lang/*', 'local/kbacompany/pix/*']
# Paths that need STANDARD at least (server/client logic without FULL triggers).
STANDARD_PATHS = ['local/kbacompany/*.php', 'local/kbacompany/classes/*', 'local/kbacompany/templates/*',
                  'local/kbacompany/amd/*', 'local/kbacompany/js/*', 'local/kbacompany/tests/*',
                  'local/kbacompany/cli/*', 'tools/ci/*', 'scripts/*']
# Always-on security regression set for STANDARD and FULL (company isolation, revocation, CSRF/ACL paths).
SECURITY_GATES = ['pr48_tenant_isolation_test', 'membership_revocation_test', 'rc30_integrated_access_test',
                  'task_membership_regression_test', 'privacy_provider_test']
FULL_WORKFLOWS = ['PR48 isolated Moodle acceptance (MariaDB + Percona, fresh install, historical upgrades, PHPUnit)',
                  'RC30 ADR-0004 schema repair', 'RC30 repaired Corporate2613 acceptance',
                  'RC30 retained Corporate2613 acceptance (negative control)', 'BMS24 Dev AI checks (required)']
ORDER = {'FAST': 0, 'STANDARD': 1, 'FULL': 2}


def git(repo: Path, *args: str) -> str:
    return subprocess.run(['git', '-C', str(repo), *args], check=True, stdout=subprocess.PIPE,
                          universal_newlines=True, encoding='utf-8', errors='replace').stdout


def match(path: str, pattern: str) -> bool:
    return fnmatch.fnmatchcase(path, pattern)


def module_of(path: str) -> str:
    if path.startswith(PLUGIN):
        rest = path[len(PLUGIN):]
        stem = Path(rest).stem.replace('.min', '')
        if rest.startswith('lang/'):
            return 'lang'
        if rest.startswith('tests/'):
            stem = stem[:-5] if stem.endswith('_test') else stem
        return re.split(r'_(service|ui_service|ui|center|live|test)$', stem)[0].split('_')[0] or stem
    return path.split('/')[0]


def focused_tests(repo: Path, head: str, changed: list[str]) -> list[str]:
    available = [Path(p).stem for p in git(repo, 'ls-tree', '-r', '--name-only', head, PLUGIN + 'tests/').split()
                 if p.endswith('_test.php')]
    wanted: set[str] = set()
    for path in changed:
        if not path.startswith(PLUGIN) or path.startswith(PLUGIN + 'lang/') or path.endswith('.css'):
            continue
        rest = path[len(PLUGIN):]
        if rest.startswith('tests/') and rest.endswith('_test.php'):
            wanted.add(Path(rest).stem)
            continue
        stem = Path(rest).stem.replace('.min', '')
        stripped = re.sub(r'_(ui_service|service|ui|live_ui|live_poll)$', '', stem)
        # 1) tests named after the module itself (communication_service -> communication_service_test,
        #    communication_center -> communication_center_service_test).
        hits = [t for t in available if t[:-5] in (stem, stripped, stripped + '_service') or t[:-5].startswith(stem + '_')]
        if not hits:
            # 2) page scripts (tasks.php, communications.php): the module's service tests + its security regressions.
            prefix = stripped[:-1] if stripped.endswith('s') else stripped
            hits = [t for t in available if t.startswith(prefix + '_') and
                    (t.endswith('_service_test') or re.search(r'(acl|security|access|membership|regression)', t))]
            hits += [t for t in available if t[:-5] in (prefix, prefix + '_service')]
        wanted.update(hits)
    return sorted(wanted)


def classify(repo: Path, base: str, head: str) -> dict:
    changed = [p for p in git(repo, 'diff', '--name-only', '--no-renames', base, head).splitlines() if p]
    diff = git(repo, 'diff', '--unified=0', '--no-renames', base, head)
    reasons: list[dict] = []
    profile = 'FAST' if changed else 'FAST'

    def raise_to(level: str, why: str, where: str) -> None:
        nonlocal profile
        reasons.append({'profile': level, 'reason': why, 'where': where})
        if ORDER[level] > ORDER[profile]:
            profile = level

    for path in changed:
        full = next((why for pat, why in FULL_PATHS if match(path, pat)), None)
        if full:
            raise_to('FULL', full, path)
        elif path.endswith('.css'):
            # RC32 unification of the #87/#86 classifiers: BMS-owned CSS is STANDARD, not FAST —
            # the CSS UI contract plus the always-on STANDARD security gates and the BROWSER tier
            # run for it. CSS anywhere else is an unknown path and stays FULL.
            if match(path, 'local/kbacompany/*.css'):
                raise_to('STANDARD', 'CSS presentation semantics — CSS UI contract + security gates + BROWSER tier', path)
            else:
                raise_to('FULL', 'unknown path — unclassified changes default to FULL', path)
        elif any(match(path, pat) for pat in FAST_PATHS):
            reasons.append({'profile': 'FAST', 'reason': 'presentation/text only', 'where': path})
        elif any(match(path, pat) for pat in STANDARD_PATHS):
            raise_to('STANDARD', 'application code (PHP/JS/template/tests) without schema or permission paths', path)
        else:
            raise_to('FULL', 'unknown path — unclassified changes default to FULL', path)
    current = None
    for line in diff.splitlines():
        if line.startswith('+++ b/'):
            current = line[6:]
            continue
        if line.startswith('--- ') or not line[:1] in ('+', '-'):
            continue
        if current and (current.endswith('.md') or current.startswith('docs/') or current.startswith('tools/ci/')):
            continue
        for pattern, why in FULL_CONTENT:
            if re.search(pattern, line[1:]):
                raise_to('FULL', why, current or '?')
    modules = sorted({module_of(p) for p in changed})
    tests = focused_tests(repo, head, changed) if profile != 'FAST' else []
    gates = SECURITY_GATES if profile != 'FAST' else []
    unique: list[dict] = []
    for r in reasons:
        if r not in unique:
            unique.append(r)
    return {
        'base': base, 'head': head, 'profile': profile, 'changed_files': changed, 'modules': modules,
        'focused_phpunit': tests, 'security_gates': gates,
        'required_workflows': {'FAST': ['ci-tiers / FAST'],
                               'STANDARD': ['ci-tiers / FAST', 'ci-tiers / STANDARD'],
                               'FULL': ['ci-tiers / FAST', 'ci-tiers / STANDARD'] + FULL_WORKFLOWS}[profile],
        'reasons': unique,
        'explanation': {'FAST': 'Only presentation/text files changed; no PHP/JS logic and no security-sensitive content.',
                        'STANDARD': 'Application code changed without schema, permission, file-serving, SQL or external-API triggers.',
                        'FULL': 'At least one FULL trigger (see reasons): schema, permissions, file storage/serving, CSRF, SQL, external API, Corporate, CI infrastructure or unknown path.'}[profile],
    }


def main() -> int:
    ap = argparse.ArgumentParser()
    ap.add_argument('--base', required=True)
    ap.add_argument('--head', required=True)
    ap.add_argument('--repo', default='.')
    ap.add_argument('--json')
    ap.add_argument('--github-output')
    a = ap.parse_args()
    result = classify(Path(a.repo), a.base, a.head)
    text = json.dumps(result, ensure_ascii=False, indent=1)
    if a.json:
        Path(a.json).write_text(text, encoding='utf-8')
    if a.github_output:
        with open(a.github_output, 'a', encoding='utf-8') as out:
            out.write('profile=%s\n' % result['profile'])
            out.write('tests=%s\n' % ' '.join(result['focused_phpunit'] + result['security_gates']))
            out.write('changed_count=%d\n' % len(result['changed_files']))
    print(json.dumps({'profile': result['profile'], 'modules': result['modules'], 'focused_phpunit': result['focused_phpunit'],
                      'security_gates': result['security_gates'],
                      'reasons': [r for r in result['reasons'] if r['profile'] == result['profile']][:12]}, ensure_ascii=False, indent=1))
    return 0


if __name__ == '__main__':
    sys.exit(main())
