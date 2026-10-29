#!/usr/bin/env python3
"""Static 1817 compliance gate; does not replace Moodle E2E."""
from pathlib import Path
import re
R=Path(__file__).resolve().parents[1]
corp=R.parent/'kbacorporate'
read=lambda path:(R/path).read_text(encoding='utf-8')
bms=read('classes/local/implementation_project_stages_service.php')
page=read('implementation_project_stages.php')
projection=(corp/'classes/local/implementation_program_sections_projection.php').read_text()
bridge=(corp/'classes/local/bridge.php').read_text()
checks={
 'bms_version':int(re.search(r'\$plugin->version\s*=\s*(\d+)',read('version.php')).group(1))>=2026101817,
 'corporate_version':int(re.search(r'\$plugin->version\s*=\s*(\d+)',(corp/'version.php').read_text()).group(1))>=2026102515,
 'api_version':float(re.search(r"API_RELEASE = '([0-9.]+)'",bridge).group(1))>=1.24,
 'api_exposed':'get_implementation_program_sections' in bridge,
 'source_acl':'implementation_program_projection::get_program($companyid, $actorid, $programid)' in projection,
 'confirmed_audit':'STATUS_CONFIRMED' in projection,
 'safe_projection':'sanitize_model' in projection and 'raw_admin_notes' not in projection,
 'read_only':'$DB->insert_record' not in projection and '$DB->update_record' not in projection,
 'explicit_link':"$DB->get_record(links::TABLE" in bms and "'companyuuid' => $uuid" in bms,
 'same_company':'linked_company_id($uuid, $actor)' in bms,
 'bms_manage':'administrative_scale_service::can_manage($uuid, $actor)' in bms,
 'linked_project_published':'eligible_projects($admin[\'items\'])' in bms,
 'only_verified_bridge':'get_implementation_program_sections' in bms,
 'page_uses_service':('implementation_project_stages_service::model' in page or 'implementation_project_weekly_service::model' in page or 'implementation_project_result_service::model' in page),
 'page_no_mutation':"$_SERVER['REQUEST_METHOD']" not in page and 'required_param(\'action\'' not in page,
 'deep_link_only_when_valid':"if ($linked['valid_project'])" in read('implementation_projects.php'),
 'no_direct_corporate_tables':'local_kbacorp_impl_item' not in bms and 'local_kbacorp_impl_task' not in bms,
 'bms_upgrade':read('db/upgrade.php').count('if ($oldversion < 2026101817)')==1,
 'language_parity':set(re.findall(r"\$string\['([^']+)'\]",read('lang/ru/local_kbacompany.php')))==set(re.findall(r"\$string\['([^']+)'\]",read('lang/en/local_kbacompany.php'))),
 'no_new_table':'xmldb_table' not in read('db/upgrade.php').split('if ($oldversion < 2026101817)')[1].split('return true;')[0],
}
for name,ok in checks.items(): print(('PASS' if ok else 'FAIL'),name)
print(f'{sum(checks.values())}/{len(checks)} checks passed')
if not all(checks.values()): raise SystemExit(1)
