#!/usr/bin/env python3
"""Source contract gate for 1802 (static only, never call it an executed Moodle E2E)."""
from pathlib import Path
import re
root=Path(__file__).resolve().parents[1]
read=lambda file:(root/file).read_text(encoding='utf-8')
service=read('classes/local/management_cycle_acceptance_service.php')
protocol=read('classes/local/battle_plan_protocol_service.php')
smoke=read('cli/qa_management_cycle.php')
runner=read('cli/build_release.py')
checks={
  'audit contains no DB queries or database writes': not any(x in service for x in ('$DB','get_records(', 'insert_record(', 'update_record(')),
  'audit blocks cross-company navigation': "'dashboard_link_outside_company'" in service and 'hash_equals($companyuuid' in service,
  'audit detects out-of-sync week sources': 'week_mismatch_' in service and 'invalid_management_week' in service,
  'audit detects restricted task links': 'restricted_task_link_exposed' in service,
  'audit does not infer unavailable protocol statistics': 'restricted_protocol_disclosed_as_count' in service,
  'live QA explicitly read only': "'live_transactions_tested' => false" in service and 'READ-ONLY QA smoke' in smoke,
  'live QA checks actor-scoped company permissions before model access': 'permission_service::can_manage_management($companyuuid, $actor)' in smoke,
  'live QA supports reciprocal denied company probe': 'cross_company_denial' in smoke and 'rc30_company_isolation_service::verify(' in smoke,
  'protocol capabilities bound to explicit actor': len(re.findall(r"has_capability\('local/kbacompany:manageorganization',\s*\\context_system::instance\(\),\s*\$actor\)", protocol))==6,
  'protocol no implicit actor check': not re.search(r"has_capability\('local/kbacompany:manageorganization',\s*\\context_system::instance\(\)\)", protocol),
  'decision date strict real calendar check': "!self::valid_iso_date($duedate)" in protocol and "$dt->format('Y-m-d') === $date" in protocol,
  'release builder runs 1802 gates': 'check_management_cycle_acceptance_contract.py' in runner and 'test_management_cycle_acceptance.php' in runner,
  'upgrade checkpoint exists': read('db/upgrade.php').count('if ($oldversion < 2026101802)')==1,
}
for key,value in checks.items(): print(('PASS' if value else 'FAIL')+' '+key)
if not all(checks.values()): raise SystemExit(2)
print('1802 static contract PASS')
