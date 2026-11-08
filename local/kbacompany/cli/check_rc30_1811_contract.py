#!/usr/bin/env python3
"""RC30/1811 fail-closed source gate; not a live RBAC/E2E proof."""
from pathlib import Path
import re
ROOT = Path(__file__).resolve().parents[1]
read = lambda p: (ROOT / p).read_text(encoding='utf-8')
svc = read('classes/local/rc30_company_isolation_service.php')
cli = read('cli/qa_management_cycle.php')
links = read('classes/local/management_cycle_acceptance_service.php')
builder = read('cli/build_release.py')
checks = {
  'reciprocal positive control': all(x in svc for x in ('actor_a_own_company','actor_b_own_company','INVALID_POSITIVE_CONTROL')),
  'both negative probes': all(x in svc for x in ('actor_a_foreign_company','actor_b_foreign_company','FOREIGN_ACCESS_OR_PRIVILEGED_ACTOR')),
  'mismatched or duplicate fixture rejected': 'INVALID_TEST_FIXTURE' in svc,
  'privileged actors not accepted as negative fixture': 'HOLD_PRIVILEGED_FIXTURE' in cli and 'viewallcompanies' in cli,
  'unexpected context errors not treated as deny': "$e->errorcode === 'companynotavailable'" in cli and 'throw $e;' in cli,
  'actual live CLI invokes matrix': 'rc30_company_isolation_service::verify(' in cli,
  'same-origin requirement on dashboard URLs': "strtolower((string)$parts['host']) !== strtolower((string)$site['host'])" in links,
  'path traversal rejected': 'rawurldecode($path)' in links and 'str_contains($decoded' in links,
  'dashboard link still verifies company UUID': 'hash_equals($companyuuid' in links,
  'new pure test included in release builder': 'test_rc30_company_isolation.php' in builder and 'check_rc30_1811_contract.py' in builder,
  'migration checkpoint added': 'if ($oldversion < 2026101811)' in read('db/upgrade.php'),
  'version not below 1811': bool(re.search(r'\$plugin->version\s*=\s*(\d+)\s*;', read('version.php')) and int(re.search(r'\$plugin->version\s*=\s*(\d+)\s*;', read('version.php')).group(1)) >= 2026101811),
}
for name, passed in checks.items():
    print(('PASS ' if passed else 'FAIL ') + name)
print(f'RC30/1811: {sum(checks.values())}/{len(checks)} source checks')
if not all(checks.values()):
    raise SystemExit(2)
