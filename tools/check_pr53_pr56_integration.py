#!/usr/bin/env python3
"""Check selective source preservation; not runtime acceptance or deployment approval."""
import hashlib
import json
import re
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
def digest(path):
    return hashlib.sha256(path.read_bytes().replace(b'\r\n', b'\n')).hexdigest()
def check(root=ROOT):
    plan=json.loads((root/'docs/reconcile/pr53-pr56-conflict-map.json').read_text(encoding='utf-8'))
    errors=[]
    if plan['counts'] != {'pr53':59,'pr56':54,'overlap':14,'only_pr53':45,'only_pr56':40}:
        errors.append('conflict map coverage changed')
    # PR57 follow-up: only impossible varchar(20) fixture repaired; assertions retained/extended.
    reviewed_followup = {'local/kbacompany/tests/company_employee_sync_service_test.php': '77fb41119da22be984915d7839dfc9e8352a5a8a7d6da8ca8558e906c062e4bd'}
    reviewed_metadata={'AGENTS.md','docs/00_MASTER_CONTEXT.md'}
    for name, expected in plan['unchanged_schema_hashes'].items():
        if digest(root/name)!=expected:
            errors.append('shared schema/helper changed: '+name)
    for row in plan['rows']:
        path=root/row['path']
        if not path.is_file() or path.is_symlink():
            errors.append('missing/unsafe source path: '+row['path']); continue
        origin={'select-pr53':'pr53_sha256_lf','preserve-pr56':'pr56_sha256_lf'}.get(row['resolution'])
        if origin and row['path'] not in reviewed_metadata and digest(path)!=reviewed_followup.get(row['path'], row[origin]):
            errors.append('parent source lost: '+row['path'])
    notes=next(r for r in plan['rows'] if r['path']=='local/kbacompany/release/RELEASE_NOTES_RC29_build2026110902.md')
    if digest(root/notes['path'])!=notes['pr56_sha256_lf'] or digest(root/'docs/reconcile/PR53_RELEASE_NOTES_0902_HISTORICAL.md')!=notes['pr53_sha256_lf']:
        errors.append('one historical0902 release note was lost')
    upgrade=(root/'local/kbacompany/db/upgrade.php').read_text(encoding='utf-8')
    versions=[int(v) for v in re.findall(r'upgrade_plugin_savepoint\(true,\s*(\d+)',upgrade)]
    if len(versions)!=481 or versions!=sorted(set(versions)) or versions[-1]!=2026111001:
        errors.append('merged upgrade chronology/identity changed')
    driver=(root/'tools/tests/moodle_acceptance.php').read_text(encoding='utf-8')
    for ref in [plan['pr53_sha'],plan['pr56_sha']]:
        if ref not in driver:
            errors.append('missing exact0902 upgrade origin')
    for suite in ['competency_review_queue_test','employee_development_courses_test',
                  'task_rc30_acceptance1864_test','task_rc30_cli_response1866_test',
                  'task_rc30_report_comparison1867_test','rc30_integrated_access_test']:
        if suite not in driver:
            errors.append('missing focused suite: '+suite)
    return errors
if __name__=='__main__':
    errors=check()
    print(json.dumps({'status':'FAIL' if errors else 'PASS','errors':errors,
        'scope':'parent source preservation and acceptance coverage, not release approval'}))
    raise SystemExit(bool(errors))
