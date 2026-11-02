#!/usr/bin/env python3
"""Check the weekly approval summary is based on plan-scoped, ACL-filtered review events."""
from pathlib import Path
root=Path(__file__).resolve().parents[1]
source=(root/'classes/local/battle_plan_weekly_review_outcome_service.php').read_text()
page=(root/'battle_plan_overview.php').read_text()
for token in ['company_context_service::context', 'task_service::can_manage',
  '(int)$plan->userid !== $actor', '(int)$plan->reviewerid !== $actor',
  '(int)$plan->observerid !== $actor', "'periodstart'=>$week", "'companyuuid'=>$companyuuid",
  'get_in_or_equal($chunk', "'bpout'", "r.companyuuid = :bpcompany", 'ORDER BY r.timecreated DESC, r.id DESC',
  'OUTCOMES', 'submitted', 'approved', 'changes', 'closed']:
 assert token in source,token
assert 'battle_plan_weekly_review_outcome_service::context(' in page
assert "'bms24-weekly-approval-outcomes'" in page
assert "'bpu_weekly_outcomes_reviewers'" in page
assert "'bpu_weekly_outcomes_units'" in page
assert 'battle_plan_service::review(' not in page
print('weekly approval outcome gate PASS')
