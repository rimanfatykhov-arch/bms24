#!/usr/bin/env python3
"""BMS24 RC29 source-contract regression gate. Does not emulate Moodle DB/RBAC/live UX."""
from pathlib import Path
import re,sys
ROOT=Path(__file__).resolve().parents[1]
read=lambda path:(ROOT/path).read_text(encoding='utf-8')
coord=read('classes/local/battle_plan_week_coordination_service.php')
view=read('battle_plan_week_coordination.php')
tasks=read('classes/local/task_service.php')
plans=read('classes/local/battle_plan_service.php')
runner=read('cli/build_release.py')
commui=read('classes/local/communication_center_ui_service.php')
checks={
 'coordination delegates company ACL to canonical overview': 'battle_plan_management_overview_service::context(' in coord,
 'coordination delegates readiness to canonical service': 'battle_plan_week_readiness_service::context(' in coord,
 'coordination delegates coverage to canonical service': 'battle_plan_coverage_service::context(' in coord,
 'coordination delegates tasks to canonical trace lookup': 'task_service::manual_tasks_by_trace_marker(' in coord,
 'Task Center trace lookup has scoped actor and company': bool(re.search(r'function manual_tasks_by_trace_marker\(string \$companyuuid, int \$actor',tasks)),
 'Task Center trace lookup uses canonical projection ACL': 'projection_access($companyuuid, $actor)' in tasks[tasks.index('function manual_tasks_by_trace_marker'):],
 'no stale completed task selected': '$latestlinked = $linkedtasks ? $linkedtasks[0] : null;' in coord and 'foreach ($linkedtasks as $linkedtask)' not in coord,
 'factual feedback requires done and timestamp': "(string)($latestlinked['status'] ?? '') === 'done'" in coord and "(int)($latestlinked['completed_at'] ?? 0) > 0" in coord,
 'factual feedback from bounded history': 'self::factual_result_after_task($completedlinked, $history)' in coord,
 'bad history user explicitly flagged': "'invalid_history_user_filter'=>$invalidhistoryuser" in coord,
 'invalid history user empties history': bool(re.search(r'if \(\$historyuserid > 0 && \$selectedhistoryuser === 0\)\s*\{[^}]*\$history = \[\];',coord)),
 'bad history user warning rendered': "get_string('battleplan_weekcoord_inaccessible_person'" in view,
 'CEO withheld for bad employee filter': "empty($data['invalid_history_user_filter'])" in view,
 'CEO uses canonical per-unit projection': "foreach ((array)$overview['by_unit'] as $unitrow)" in coord,
 'GET coordination only reads context': 'battle_plan_week_coordination_service::context(' in view and not re.search(r'create_plan\(|create_task\(|insert_record\(|update_record\(',view),
 'Battle Plan lookup has canonical existing-context check': 'function existing_plan_for_context(' in plans,
 'Battle Plan canonical create service retained': 'function create_plan(' in plans and 'ensure_plan_record(' in plans,
 'communication fallback uses Moodle string manager': "get_string_manager()->string_exists($key, 'local_kbacompany')" in commui,
 'communication fallback never calls undefined global string_exists': not bool(re.search(r'(?<!->)\bstring_exists\(', commui)),
 'communication navigation uses Moodle-supported html_writer tag': not bool(re.search(r'\\?html_writer::nav\s*\(', commui)) and commui.count("html_writer::tag('nav'") >= 2,
 'release runner validates RU/EN parity': 'validate_languages()' in runner,
 'release runner validates upgrade checkpoint': 'validate_upgrade(build)' in runner,
 'release runner validates XML': 'validate_xml()' in runner,
 'release runner validates ZIP integrity': 'zf.testzip()' in runner,
}
for title,ok in checks.items():print(('PASS' if ok else 'FAIL')+' '+title)
print('RC29 STATIC GATE:',sum(checks.values()),'/',len(checks))
if not all(checks.values()):sys.exit(2)
