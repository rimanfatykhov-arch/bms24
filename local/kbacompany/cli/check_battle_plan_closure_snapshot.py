#!/usr/bin/env python3
from pathlib import Path
r=Path(__file__).resolve().parents[1]
s=(r/'classes/local/battle_plan_service.php').read_text()
m=(r/'classes/local/battle_plan_management_overview_service.php').read_text()
u=(r/'battle_plan_overview.php').read_text()
assert "'closure_snapshot'" in s
assert "'capturedat' => time()" in s
assert "'carried' => (bool)$rolled" in s
assert s.index("$payload['closure_snapshot'] = $closedsnapshot;") < s.index("$plan->status = 'closed';")
assert "$hassnapshot" in m and "task_service::can_view_task" in m
assert "'is_snapshot'=>$hassnapshot" in m
assert 'bms24_rollover_snapshot_frozen' in u and 'bms24_rollover_snapshot_live' in u
print('Battle Plan snapshot source checks PASS (7/7)')
