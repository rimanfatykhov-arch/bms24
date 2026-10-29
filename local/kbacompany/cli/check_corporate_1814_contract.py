#!/usr/bin/env python3
"""Static guard for 1814: Corporate is a read-only source; BMS UUID remains boundary."""
from pathlib import Path
import re
root=Path(__file__).resolve().parents[1]
read=lambda rel:(root/rel).read_text(encoding='utf-8')
s=read('classes/local/task_provider/kbacorporate_impl_task_provider.php')
p=read('classes/local/task_provider_service.php')
b=read('classes/local/battle_plan_service.php')
u=read('templates/task_sources.php')
c=read('cli/build_release.py')
checks={
    'version': int(re.search(r'\$plugin->version\s*=\s*(\d+)', read('version.php')).group(1)) >= 2026101814,
    'upgrade_savepoint': 'if ($oldversion < 2026101814)' in read('db/upgrade.php'),
    'registered_provider': "'kbacorporate_impl' => new kbacorporate_impl_task_provider()" in p,
    'public_contract_only': 'list_implementation_task_projections' in s and 'get_implementation_task_projection' in s,
    'no_corporate_sql': 'local_kbacorp_' not in s and 'get_records_sql' not in s,
    'bridged_version': "version_compare((string)$bridge::API_RELEASE, '1.22', '>=')" in s,
    'verified_legacy_mapping': "source_component'] ?? '') !== 'local_kbacorporate'" in s,
    'verified_bitrix_mapping': 'get_company_by_bitrix_id' in s and '$id !== $legacyid' in s,
    'explicit_binding': "'sourcekey' => 'company:' . $id, 'active' => 1" in s,
    'source_settings': "'providerkey'=>'kbacorporate_impl'" in u,
    'prohibit_ineligible_attachment': "($provider === 'kbacorporate_impl' && empty($task['attachable']))" in b,
    'filter_ineligible_candidates': "($key !== 'kbacorporate_impl' || !empty($task['attachable']))" in b,
    'safe_internal_task_url': "'/local/kbacorporate/implementation_task.php'" in s,
    'no_unverified_source_url': "(string)($row['source_url']" not in s,
    'read_only': "'editable' => false" in s,
    'bounded_pagination': 'MAX_ITEMS = 500;' in s and 'PAGE_SIZE = 100;' in s,
    'php_smoke_in_release_builder': 'test_kbacorporate_impl_provider.php' in c,
    'company_link_not_freetext': "'sourcekey'=>$source['source_key']" in u and "'type'=>'text'" not in u,
}
for name, ok in checks.items():print(('PASS' if ok else 'FAIL')+' '+name)
print(f"Corporate provider contract: {sum(checks.values())}/{len(checks)}")
if not all(checks.values()):raise SystemExit(1)
