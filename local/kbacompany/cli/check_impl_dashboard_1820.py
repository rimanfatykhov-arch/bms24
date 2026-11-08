#!/usr/bin/env python3
from pathlib import Path
import re
B=Path(__file__).resolve().parents[1]
C=B.parent/'kbacorporate'
service=(B/'classes/local/implementation_project_dashboard_service.php').read_text()
page=(B/'implementation_project_dashboard.php').read_text()
program=(C/'implementation_program_edit.php').read_text()
curator=(C/'implementation_curator.php').read_text()
project=(B/'implementation_projects.php').read_text()
checks={
 'build':int(re.search(r'\$plugin->version\s*=\s*(\d+)', (B/'version.php').read_text()).group(1)) >= 2026101820,
 'upgrade':'if ($oldversion < 2026101820)' in (B/'db/upgrade.php').read_text(),
 'existing_assigned_curator':"$program->curatorid = $curatorid" in program,
 'curator_acls':"'curatorid' => (int)$USER->id" in curator,
 'corpb_bridge_call':'get_implementation_program_projection(' in service,
 'bms_verified_base':'implementation_project_result_service::model' in service,
 'company_bound_program':'$program[\'company_id\']' in service and "$program['audit_id']" in service,
 'published_project_acl':'implementation_project_result_service::model' in service,
 'no_corporate_db_read':all(x not in service for x in ['local_kbacorp_impl_task','local_kbacorp_impl_program','local_kbacorp_impl_evid']),
 'no_mutation':all(x not in (service+page) for x in ['$DB->update_record','$DB->insert_record','$DB->delete_records']),
 'no_private_evidence':all(x not in service for x in ["'reviewcomment'", "'summary' =>", "'contactemail'", "'externalurl'"]),
 'explicit_page_login':'require_login();' in page and 'require_capability(' in page,
 'page_week_validation':"Invalid week" in page,
 'dashboard_link':'implementation_project_dashboard.php' in project,
 'nonfake_null_values':"'planned' => $weekcomplete ? 0 : null" in service and "'accepted' => $resultcomplete ? 0 : null" in service,
 'curator_portfolio_link':"['panel'=>'curator']" in page and 'implementation_curator.php' in page,
 'curator_display_acl':"manageimplementationprograms" in page and "manageimplementationaudits" in page,
 'no_crud_on_get':"\u0024_POST" not in page,
 'language_parity':set(re.findall(r"\$string\['([^']+)'\]", (B/'lang/ru/local_kbacompany.php').read_text()))==set(re.findall(r"\$string\['([^']+)'\]", (B/'lang/en/local_kbacompany.php').read_text())),
}
for name,result in checks.items():print(('PASS' if result else 'FAIL'),name)
print(f'{sum(checks.values())}/{len(checks)}')
if not all(checks.values()):raise SystemExit(1)
