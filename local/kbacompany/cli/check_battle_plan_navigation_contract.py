#!/usr/bin/env python3
"""Source contract for RC29/1801. This is not a live Moodle role/UX acceptance test."""
from pathlib import Path
import re
root=Path(__file__).resolve().parents[1]
read=lambda name:(root/name).read_text(encoding='utf-8')
page=read('battle_plan.php')
ui=read('classes/local/battle_plan_ui_service.php')
projection=read('classes/local/battle_plan_navigation_service.php')
listing=read('battle_plan_overview.php')
checks={
    'read model consulted only AFTER canonical plan ACL context': page.index('battle_plan_service::context(')<page.index('ui::record_navigation('),
    'company required and login enforced': "company_context_service::context($uuid,$actor)" in page and 'require_login();' in page,
    'one shared navigation for card and CRUD screens': "['detail','edit','fill','action','metrics']" in page and 'ui::record_navigation(' in page,
    'record header old duplicate tab nav removed': "return $html.\\html_writer::tag('nav',$tabs" not in ui,
    'return-to-queue URL constructed server side': "new moodle_url('/local/kbacompany/battle_plan_overview.php', $backparams)" in page and "'fromqueue'=>1" in page,
    'no user-provided return URL accepted': "optional_param('returnurl'" not in page and '$_GET[\'returnurl\']' not in page,
    'queue context preserved in tabs and primary actions': "array_merge($item['params'], $queuefields)" in ui and "record_header($uuid,$data,$tab,$queuefields)" in page,
    'queue context only when controller built URL': '$queueurl !== null && !empty($queuefields[\'fromqueue\'])' in ui,
    'manager link conditional': "if ($projection['canmanage'])" in ui,
    'edit constrained by source ACL and closed state': "!empty($context['selected_can_edit']) && (string)$p['status'] !== 'closed'" in projection,
    'fill constrained by draft or active': "in_array($status, ['draft', 'active'], true)" in projection,
    'no write operations inside presentation model': not re.search(r'\b(insert_record|update_record|delete_records|set_field|create_plan|transition)\s*\(',projection),
    'create selected week preserved': "['view'=>'create','week'=>$filters['week']]" in ui,
    'overview links preserve selected week': "['uuid'=>$uuid,'week'=>$data['week_start']]" in listing,
    'navigation link labels localised': "self::tr('bpu_nav_' . $mode['key'])" in ui,
    'tabs mark active page': "'aria-current'=>$item['selected'] ? 'page' : null" in ui,
    'GET cannot perform POST action': "if ($method!=='POST' && optional_param('action'" in page,
    'POST sesskey enforced': 'require_sesskey();' in page,
}
for label, ok in checks.items(): print(('PASS' if ok else 'FAIL')+' '+label)
if not all(checks.values()): raise SystemExit(2)
