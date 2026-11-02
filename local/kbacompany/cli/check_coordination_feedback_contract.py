#!/usr/bin/env python3
"""Static source regression contract. Not a Moodle runtime/DB test."""
from pathlib import Path
p=Path(__file__).resolve().parents[1]
s=(p/'classes/local/battle_plan_week_coordination_service.php').read_text()
view=(p/'battle_plan_week_coordination.php').read_text()
checks={
 'latest-only task selection': '$latestlinked = $linkedtasks ? $linkedtasks[0] : null;' in s,
 'requires canonical task completion': "(string)($latestlinked['status'] ?? '') === 'done'" in s and "(int)($latestlinked['completed_at'] ?? 0) > 0" in s,
 'no older-task completion scan': 'foreach ($linkedtasks as $linkedtask)' not in s,
 'task ACL lookup retained': 'task_service::manual_tasks_by_trace_marker(' in s,
 'invalid history user retained': "'invalid_history_user_filter'=>$invalidhistoryuser" in s,
 'invalid user warning in view': "get_string('battleplan_weekcoord_inaccessible_person'" in view,
 'CEO guarded by invalid-filter state': "empty($data['invalid_history_user_filter'])" in view,
 'no new DDL': '$DB->' not in (p/'release/RELEASE_NOTES_RC29_LATEST_TASK_FEEDBACK_2026101711.md').read_text(),
}
for key,ok in checks.items(): print(('PASS' if ok else 'FAIL')+' '+key)
if not all(checks.values()): raise SystemExit(1)
