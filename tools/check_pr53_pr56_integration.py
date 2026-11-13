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
    # Reviewed stale company/permission literals only; all source/policy assertions retained.
    reviewed_followup['local/kbacompany/tests/company_core_test.php'] = '224a11b3e199a196a6334d23706ab16f8d8d3f5c3e388582a9cb9d3ccaf0116b'
    # ADR-0006 source changes are bound by exact hashes; original parent manifests remain intact.
    reviewed_followup.update({'docs/reconcile/corporate-api126-source.json': 'a1d20c4e5ed07b6018ca05c25198fea697de7062ee7f823c95d5820e363e11de', 'local/kbacompany/classes/local/employee_service.php': '7b81291e7d1fd4987e905e0c67edb1db24780484c128c3039a59b60a2521f237', 'local/kbacompany/lang/en/local_kbacompany.php': '82590f9febf9255ff009f4cbbba64240442d4fe78935caf8d253e57825824a9d', 'local/kbacompany/lang/ru/local_kbacompany.php': 'aa3c2f2597160717bc48070b0803ef4f865a3e413e886bd4918f4135cc869da9', 'local/kbacompany/tests/company_core_test.php': '224a11b3e199a196a6334d23706ab16f8d8d3f5c3e388582a9cb9d3ccaf0116b', 'local/kbacorporate/classes/local/development_plans.php': 'ffe609b47fe2c7e590c4b64599816e2612a5ee46ef3316dfa25376cca31a2415', 'local/kbacorporate/classes/local/erp_exchange.php': '1ed5b8dd2c1c6a11d86882dabee7bc1b9c22cccc1e37c787bf4849469c718c5d', 'local/kbacorporate/db/upgrade.php': '0e3972020a43537c1524b4c8ade694e3b5df5a9c3563c85fdebf02d70a868374', 'local/kbacorporate/tests/erp_exchange_test.php': '2e6833c34401bbb21b10bf998bad968b043b0cb426f4da71f639f7e6c7ee9583', 'local/kbacorporate/version.php': '165bbcf6953d38a0ba716f10969fdcb0d0f57a3e2f6ef095994df71b4e69f342', 'tools/check_corporate_api126.py': '8cd273417f3a75dd1955b8ec5f30285ef527d45767afdd92c3fc3d151e26bf92'})
    # RC30 S14: recommendation regressions added; existing assertions retained unchanged.
    reviewed_followup['local/kbacorporate/tests/course_recommendations_test.php'] = 'dfab254c565758b7a7dabd984ec3c8b196648223697494f673c3313f89a90ec1'
    reviewed_metadata={'AGENTS.md','docs/00_MASTER_CONTEXT.md'}
    for name, expected in plan['unchanged_schema_hashes'].items():
        target = {'local/kbacompany/db/install.xml': '5d61fb813ca0e1da1ab67db808b5a1da321cadfccef3e2c499e8dee279f5e576'}.get(name, expected)
        if digest(root/name)!=target:
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
    if len(versions)!=483 or versions!=sorted(set(versions)) or versions[-1]!=2026111100:
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
