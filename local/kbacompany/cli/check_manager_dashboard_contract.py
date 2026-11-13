#!/usr/bin/env python3
"""Build 1800 static access, source-contract and navigation guard, no Moodle DB."""
from pathlib import Path
r=Path(__file__).resolve().parents[1]
service=(r/'classes/local/management_leader_dashboard_service.php').read_text()
page=(r/'management.php').read_text()
tasks=(r/'classes/local/task_service.php').read_text()
protocol=(r/'classes/local/battle_plan_protocol_service.php').read_text()
summary=(r/'classes/local/management_execution_service.php').read_text()
checks={
 'page has company view capability': "require_capability('local/kbacompany:viewowncompany'" in page,
 'leader company membership rechecked': 'company_context_service::context($companyuuid, $actor)' in service,
 'leader management role rechecked': 'permission_service::can_manage_management($companyuuid, $actor)' in service,
 'canonical prior-management context retained': 'management_execution_service::context($uuid, (int)$USER->id, $week)' in page,
 'source exceptions only from scoped management context': "foreach ($base['exceptions']['rows'] as &$exception)" in service,
 'source approval queue from existing overview': "['overview']['review_queue']" in service,
 'all overdue task count from canonical SQL': "'signal'=>'overdue'" in service and "['counts']['total']" in service,
 'canonical task projection uses ACL': 'self::projection_access($companyuuid, $actor)' in tasks,
 'protocol guarded by manageorganization': "has_capability('local/kbacompany:manageorganization'" in service,
 'protocol reuses authorized decision queue': 'battle_plan_protocol_service::decision_queue($companyuuid, $actor, $week, \'\')' in service,
 'protocol service enforces company and task visibility': 'battle_plan_week_coordination_service::context($companyuuid, $actor, $week, $orgnode)' in protocol,
 'denied protocol rendered as unknown': "'protocol_overdue' => $protocolavailable ? $decisionoverdue : null" in service,
 'no duplicate week database storage': 'insert_record(' not in service and 'update_record(' not in service and 'delete_records(' not in service,
 'no arbitrary input URL carry-forward': "new \\moodle_url('/local/kbacompany/tasks.php'" in service and 'decisiontask' in service,
 'preview after aggregates': 'array_slice($rows, 0, max(0, $limit))' in service and "'totals' => $totals" in service,
 'no GET mutation in management': 'update_record(' not in page and 'delete_records(' not in page,
 'Moodle renders escaping': "s((string)$row['title'])" in page and "s((string)$row['detail'])" in page,
 'existing weekly acceptance retains scope': 'weekly_management_acceptance_service::context($companyuuid, $actor, $week)' in summary,
 'no new new route or theme hardcoding': 'management_leader_dashboard_service::context(' in page and 'theme_kba' not in service,
 'migration checkpoint present': (r/'db/upgrade.php').read_text().count('if ($oldversion < 2026101800)') == 1,
}
for description, ok in checks.items():
    print(('PASS' if ok else 'FAIL') + ' ' + description)
if not all(checks.values()):
    raise SystemExit(2)
print('Manager dashboard static contract PASS')
