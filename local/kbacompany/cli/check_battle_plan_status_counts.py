#!/usr/bin/env python3
from pathlib import Path
root=Path(__file__).resolve().parents[1]
s=(root/'classes/local/battle_plan_service.php').read_text()
u=(root/'classes/local/battle_plan_ui_service.php').read_text()
assert "'status_counts'=>$statuscounts" in s
assert "GROUP BY p.status" in s
assert s.index('GROUP BY p.status') < s.index("$where .= ' AND p.status = :filterstate'")
assert "$statuscounts['all'] = array_sum($statuscounts)" in s
assert "($model['status_counts'][$state === '' ? 'all' : $state] ?? 0)" in u
print('Status count source contract PASS (5/5)')
