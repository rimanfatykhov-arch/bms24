#!/usr/bin/env python3
"""Static contract for RC29/1809; runtime Moodle acceptance remains outstanding."""
from pathlib import Path
import re
ROOT = Path(__file__).resolve().parents[1]
read = lambda name: (ROOT / name).read_text(encoding='utf-8')
service = read('classes/local/bms_workspace_action_service.php')
page = read('bms_workspace.php')
runner = read('cli/build_release.py')
ru, en = read('lang/ru/local_kbacompany.php'),read('lang/en/local_kbacompany.php')
checks = {
    'company membership verified at queue SQL source': 'company_context_service::context($companyuuid, $actor)' in service,
    'plan query company and actor restricted': 'p.companyuuid = :company' in service and 'p.reviewerid = :reviewer' in service and 'p.userid = :owner' in service,
    'bounded plan query': 'get_records_sql($sql,' in service and ', 0, 60)' in service,
    'canonical review tasks filtered by existing ACL': "['scope' => 'review']" in service,
    'task execution actor is checked': "effective_assignee_id" in service and '!== $actor' in service,
    'no external provider links': "['provider'] ?? 'bms'" in service,
    'review and overdue dedup': "isset($items[$id]) && $items[$id]['rank'] <= $rank" in service,
    'no manager data read for non-managers': '&& $canmanagewidgets' in page,
    'only visible widget triggers queue': "if (isset($widgetactive['attention']))" in page,
    'action service wired into actual workspace': 'bms_workspace_action_service::project(' in page,
    'action queue reused in fallback': '$fallbackactionhtml' in page,
    'manager old duplicate queue removed': '$queuecard' not in page,
    'existing 9 widget IDs preserved': "'attention' => $actioncard" in page,
    'all outgoing labels escaped': "s(get_string($row['action']" in page and "s($row['title'])" in page,
    'deep links are read-only': "new \\moodle_url('/local/kbacompany/tasks.php'" in service and "new \\moodle_url('/local/kbacompany/battle_plan.php'" in service,
    'no writes or sesskey operations': all(x not in service for x in ['update_record(', 'insert_record(', 'delete_records(', 'transition(']),
    'localized RU/EN': all("$string['" + key + "']" in ru and "$string['" + key + "']" in en for key in ['bmsaction_title','bmsaction_empty','bmsaction_review_plan','bmsaction_decide_indicator']),
    'release upgrade id': 'if ($oldversion < 2026101809)' in read('db/upgrade.php'),
    'release version >= 1809': bool(re.search(r'\$plugin->version\s*=\s*(\d+)\s*;', read('version.php')) and int(re.search(r'\$plugin->version\s*=\s*(\d+)\s*;', read('version.php')).group(1)) >= 2026101809),
    'release test included in release builder': 'test_workspace_1809_queue.php' in runner and 'check_workspace_1809_contract.py' in runner,
}
for name,passed in checks.items():
    print(('PASS ' if passed else 'FAIL ')+name)
print(f'BMS1809 contract: {sum(checks.values())}/{len(checks)}')
if not all(checks.values()): raise SystemExit(2)
