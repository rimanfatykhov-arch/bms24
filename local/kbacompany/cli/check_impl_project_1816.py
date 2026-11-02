#!/usr/bin/env python3
"""Structural and security contract for explicit Corporate/BMS project links."""
from pathlib import Path
from xml.etree import ElementTree as ET
R=Path(__file__).resolve().parents[1]
get=lambda n:(R/n).read_text()
bridge=Path(__file__).resolve().parents[2]/'kbacorporate/classes/local/bridge.php'
xml=ET.parse(R/'db/install.xml')
table=xml.find(".//TABLE[@NAME='local_kbacompany_implproj']")
assert table is not None
indexes={x.attrib['NAME']:(x.attrib['UNIQUE'],x.attrib['FIELDS']) for x in table.findall('INDEXES/INDEX')}
checks={
 'table_length':len(table.attrib['NAME'])<=28,
 'program_unique': indexes.get('program_uix') == ('true','companyuuid,programid'),
 'project_unique': indexes.get('project_uix') == ('true','companyuuid,projectuuid'),
 'migration_once':get('db/upgrade.php').count('if ($oldversion < 2026101816)')==1,
 'new_version':'2026101816' in get('version.php'),
 'both_sides_permissions':'administrative_scale_service::can_manage' in get('classes/local/implementation_project_link_service.php') and
    'get_implementation_program_projection' in get('classes/local/implementation_project_link_service.php'),
 'identity_bound':'linked_company_id' in get('classes/local/implementation_project_link_service.php'),
 'post_sesskey': "$_SERVER['REQUEST_METHOD'] === 'POST'" in get('implementation_projects.php') and 'require_sesskey();' in get('implementation_projects.php'),
 'no_direct_corporate_data': 'local_kbacorp_impl_task' not in get('classes/local/implementation_project_link_service.php'),
 'source_unmodified': 'battle_plan_service::add_external_task(' not in get('classes/local/implementation_project_link_service.php'),
 'corporate_api': bridge.exists() and 'list_implementation_program_projections' in bridge.read_text() and '1.23' in bridge.read_text(),
 'localized': all('$string[\'implproj_title\']' in get(f'lang/{l}/local_kbacompany.php') for l in ['en','ru']),
}
for key,value in checks.items(): print(('PASS' if value else 'FAIL'),key)
if not all(checks.values()):raise SystemExit(1)
