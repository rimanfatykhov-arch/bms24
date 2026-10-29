#!/usr/bin/env python3
"""Read-only integration contract gate. No server-side Moodle E2E."""
from pathlib import Path
import re
ROOT=Path(__file__).resolve().parents[1]
CORP=ROOT.parent/'kbacorporate'
bms=(ROOT/'classes/local/implementation_project_result_service.php').read_text()
source=(CORP/'classes/local/implementation_program_result_projection.php').read_text()
bridge=(CORP/'classes/local/bridge.php').read_text()
page=(ROOT/'implementation_project_stages.php').read_text()
ru=(ROOT/'lang/ru/local_kbacompany.php').read_text()
en=(ROOT/'lang/en/local_kbacompany.php').read_text()
checks={
 'bms_version':'2026101819' in (ROOT/'version.php').read_text(),
 'corp_version':'2026102517' in (CORP/'version.php').read_text(),
 'api_1_26':"API_RELEASE = '1.26'" in bridge,
 'contract':"'get_implementation_program_result_statuses'" in bridge,
 'corporate_acl':'implementation_program_task_sections_projection::get($companyid, $actorid, $programid)' in source,
 'corporate_complete_guard':"empty($source['complete'])" in source,
 'correct_company_audit':'(int)$source[\'company_id\'] !== $companyid' in source,
 'evidence_latest_only':'MAX(versionno)' in source and 'GROUP BY taskid' in source,
 'indexed_version':'taskid, versionno' in (CORP/'db/install.xml').read_text(),
 'limit_queries':'array_chunk($ids, self::BATCH)' in source,
 'readonly':all(x not in source for x in ['$DB->insert_record', '$DB->delete_records', '$DB->update_record']),
 'no_private_evidence_payload':all(x not in source for x in ["'summary' =>", "'reviewcomment' =>", "'externalurl' =>", "'reviewerid' =>"]),
 'safely_reconcile':"Corporate weekly/result snapshots disagree" in bms and 'UnexpectedValueException' in bms,
 'bms_explicit_link':'implementation_project_weekly_service::model($uuid, $actor, $programid, $week)' in bms,
 'no_corporate_tables': 'local_kbacorp_impl_evid' not in bms and 'local_kbacorp_impl_task' not in bms,
 'result_user_visible':'implementation_project_result_service::model' in page and 'implresult_summary' in page,
 'no_mutation_in_bms_ui':all(x not in page for x in ["$_POST",'$DB->insert_record','$DB->update_record']),
 'bms_migration':'if ($oldversion < 2026101819)' in (ROOT/'db/upgrade.php').read_text(),
 'corp_migration':'if ($oldversion < 2026102517)' in (CORP/'db/upgrade.php').read_text(),
 'language_parity':set(re.findall(r"\$string\['([^']+)'\]",ru))==set(re.findall(r"\$string\['([^']+)'\]",en)),
}
for label,value in checks.items(): print(('PASS' if value else 'FAIL'),label)
print(sum(checks.values()),'/',len(checks())) if False else None
print(f'{sum(checks.values())}/{len(checks)} checks')
if not all(checks.values()):raise SystemExit(1)
