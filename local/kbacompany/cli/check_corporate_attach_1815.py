#!/usr/bin/env python3
"""Build 1815: corporate navigation is GET-only, mutations inside BMS POST."""
from pathlib import Path
r=Path(__file__).resolve().parents[1]
source=lambda path:(r/path).read_text()
a=source('implementation_battle_plan_attach.php')
s=source('classes/local/implementation_battle_plan_link_service.php')
p=source('classes/local/task_provider/kbacorporate_impl_task_provider.php')
b=source('classes/local/battle_plan_service.php')
checks={
    'new_version':int(__import__('re').search(r'\$plugin->version\s*=\s*(\d+)', source('version.php')).group(1)) >= 2026101815,
    'upgrade_savepoint':'if ($oldversion < 2026101815)' in source('db/upgrade.php'),
    'actor_company_membership':'companies_for_user($actor)' in a,
    'corporate_scope':'corporate_company_id' in p and 'corporate_company_id' in s,
    'no_name_matching':"$company['name'] ===" not in a,
    'explicit_company_binding':'task_provider_service::get_task(' in a,
    'assignee_required':"(int)($task['assignee_id'] ?? 0) === $actor" in s,
    'attachable_required':"!empty($task['attachable'])" in s,
    'draft_only':'creation_plan_options(' in a and "'user_id'" in s,
    'post_only_write':"if ($method === 'POST')" in a and 'require_sesskey()' in a,
    'safe_fixed_redirect':"'/local/kbacompany/battle_plan.php'" in a,
    'canonical_attach':'battle_plan_service::add_external_task(' in a,
    'idempotent_existing_service':"$DB->record_exists('local_kbacompany_bplanitem'" in b,
    'no_direct_corporate_sql':'local_kbacorp_' not in a,
    'preserve_status':'transition(' not in a,
    'create_plan_without_autowrite':"'view'=>'create'" in a,
    'new_pure_tests':'test_implementation_battle_plan_link.php' in source('cli/build_release.py'),
}
for label, ok in checks.items():print(('PASS' if ok else 'FAIL')+' '+label)
print('Contract:',sum(checks.values()),'/',len(checks))
if not all(checks.values()):raise SystemExit(1)
