#!/usr/bin/env python3
"""Static structural integration gate. Not a replacement for Moodle DB/E2E."""
from pathlib import Path
import re
R=Path(__file__).resolve().parents[1]
C=R.parent/'kbacorporate'
b=(R/'classes/local/implementation_project_weekly_service.php').read_text()
a=(C/'classes/local/implementation_program_task_sections_projection.php').read_text()
bridge=(C/'classes/local/bridge.php').read_text()
page=(R/'implementation_project_stages.php').read_text()
checks={
 'BMS 1818': int(re.search(r'\$plugin->version\s*=\s*(\d+)',(R/'version.php').read_text()).group(1))>=2026101818,
 'Corporate 0.40.7':int(re.search(r'\$plugin->version\s*=\s*(\d+)',(C/'version.php').read_text()).group(1))>=2026102516,
 'API 1.25':float(re.search(r"API_RELEASE = '([0-9.]+)'",bridge).group(1))>=1.25,
 'API method exposed':'get_implementation_program_task_sections' in bridge,
 'Corporate authorization':'implementation_program_projection::get_program($companyid, $actorid, $programid)' in a,
 'confirmed audit':'STATUS_CONFIRMED' in a,
 'canonical paid program':'MIN(id)' in a and 'commercialstatus' in a,
 'deleted task filter':'t.deleted = 0' in a,
 'Corporate company and audit':'t.companyid = :companyid AND t.auditid = :auditid' in a,
 'bounded result':'MAX_TASKS + 1' in a and "'complete' => $complete" in a,
 'no Corporate mutations':all(x not in a for x in ['$DB->insert_record','$DB->delete_records','$DB->update_record']),
 'no private payload':all(x not in a for x in ['completioncomment','resulturl','evidenceurl','firstname']),
 'existing linked project ACL':'implementation_project_stages_service::model($uuid, $actor, $programid)' in b,
 'BMS battle-plan ACL':'permission_service::can_manage_tasks' in b and 'battle_plan' in b,
 'same BMS company scoped SQL':'i.companyuuid = :companyuuid AND p.companyuuid = :plancompany' in b,
 'single week SQL':'p.periodstart = :week' in b,
 'provider whitelist':"SOURCE = 'kbacorporate_impl'" in b,
 'prevent other owner links':"$plan['owner_id']" in b,
 'no Corporate internal tables':'local_kbacorp_impl_task' not in b and 'local_kbacorp_impl_item' not in b,
 'read-only UI':"$_POST" not in page and ('implementation_project_weekly_service::model' in page or 'implementation_project_result_service::model' in page),
 'week navigation':'implweek_previous' in page and 'implweek_next' in page,
 'both upgrades':'if ($oldversion < 2026101818)' in (R/'db/upgrade.php').read_text() and 'if ($oldversion < 2026102516)' in (C/'db/upgrade.php').read_text(),
 'language parity':set(re.findall(r"\$string\['([^']+)'\]",(R/'lang/ru/local_kbacompany.php').read_text()))==set(re.findall(r"\$string\['([^']+)'\]",(R/'lang/en/local_kbacompany.php').read_text())),
}
for k,v in checks.items():print(('PASS' if v else 'FAIL'),k)
print(f'{sum(checks.values())}/{len(checks)} checks')
if not all(checks.values()): raise SystemExit(1)
