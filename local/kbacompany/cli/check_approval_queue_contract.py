#!/usr/bin/env python3
from pathlib import Path
root=Path(__file__).resolve().parents[1]
ui=(root/'battle_plan_overview.php').read_text()
svc=(root/'classes/local/battle_plan_management_overview_service.php').read_text()
for token in ['review_queue', 'bms24-approval-queue', 'approvalpage', 'battle_plan_approval_queue_service::correction_counts', "'view'=>'detail'", "'tab'=>'review'", "'companyuuid'=>$uuid"]:
    assert token in ui or token in svc,token
assert svc.index('$reviewqueue[] = $row;') < svc.index("if ($attention !== 'all' && $row['attention'] !== $attention)"),'attention independence'
assert 'access_role_service::may_approve_department_plan' in svc
assert 'battle_plan_service::review' not in ui
print('approval queue read-only/ACL source contract PASS')
