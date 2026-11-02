#!/usr/bin/env python3
"""Contract for read-only, access-scoped and regression-safe cycle analytics."""
from pathlib import Path
root = Path(__file__).resolve().parents[1]
timing = (root/'classes/local/battle_plan_review_timeliness_service.php').read_text()
overview = (root/'battle_plan_overview.php').read_text()
service = (root/'classes/local/battle_plan_review_cycle_analytics_service.php').read_text()
assert timing.index('if (!$canmanage') < timing.index('$cycleplans[]')
assert timing.index('if ($reviewerfilter > 0') < timing.index('$cycleplans[]')
assert timing.index('if ($orgnodefilter !==') < timing.index('$cycleplans[]')
assert 'battle_plan_review_cycle_analytics_service::summarize($cycleplans)' in timing
assert "'event_payload'=>(string)($plan->statepayload ?? '')" in timing
assert 'if ($submissions >= 2 || $returns >= 2)' in service
assert 'array_slice($repeated, 0, 15)' in service
assert 'bms24-review-cycle-analytics' in overview
assert "['uuid'=>$uuid, 'plan'=>$row['plan_uuid'], 'view'=>'detail', 'tab'=>'history']" in overview
assert overview.index("$cycleanalytics = $approvaltiming['cycle_analytics']") > overview.index('$approvaltiming = ')
print('review cycle analytics contract PASS')
