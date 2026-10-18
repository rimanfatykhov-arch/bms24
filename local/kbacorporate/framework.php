<?php
require_once(__DIR__ . '/../../config.php');
require_login();
$context = context_system::instance();
require_capability('local/kbacorporate:manageimplementationaudits', $context);
$companyid = required_param('companyid', PARAM_INT);
$company = \local_kbacorporate\local\access::require_full_company_access($companyid, $USER->id);
$frameworks = $DB->get_records('competency_framework', null, 'shortname ASC');
$frameworkid = optional_param('frameworkid', 0, PARAM_INT);
if (!$frameworkid) {
    foreach ($frameworks as $framework) {
        if ((string)$framework->idnumber === 'BMS-COMP-2026-V1') { $frameworkid = (int)$framework->id; break; }
    }
}
if (!$frameworkid && $frameworks) { $frameworkid = (int)array_key_first($frameworks); }
$framework = $frameworkid ? ($frameworks[$frameworkid] ?? $DB->get_record('competency_framework', ['id'=>$frameworkid])) : null;
$competencycount = $framework ? $DB->count_records('competency', ['competencyframeworkid'=>$framework->id]) : 0;
$toolcount = $DB->count_records_select('local_kbacorp_bms_tool', 'companyid IN (0, :companyid)', ['companyid'=>$companyid]);
$criterioncount = $DB->count_records_sql("SELECT COUNT(1) FROM {local_kbacorp_bms_crit} c JOIN {local_kbacorp_bms_tool} t ON t.id=c.toolid WHERE t.companyid IN (0,:companyid) AND c.active=1", ['companyid'=>$companyid]);
$mappingcount = $DB->count_records_sql("SELECT COUNT(1) FROM {local_kbacorp_bms_comp} bc JOIN {local_kbacorp_bms_crit} c ON c.id=bc.criterionid JOIN {local_kbacorp_bms_tool} t ON t.id=c.toolid WHERE t.companyid IN (0,:companyid)", ['companyid'=>$companyid]);
$mappedcriterioncount = $framework ? (int)$DB->count_records_sql("SELECT COUNT(DISTINCT c.id) FROM {local_kbacorp_bms_crit} c JOIN {local_kbacorp_bms_tool} t ON t.id=c.toolid JOIN {local_kbacorp_bms_comp} bc ON bc.criterionid=c.id JOIN {competency} comp ON comp.id=bc.competencyid WHERE t.companyid IN (0,:companyid) AND c.active=1 AND comp.competencyframeworkid=:frameworkid AND NOT " . $DB->sql_like('comp.idnumber', ':domainprefix', false), ['companyid'=>$companyid,'frameworkid'=>$frameworkid,'domainprefix'=>'BMS-D%']) : 0;
$PAGE->set_context($context); $PAGE->set_url(new moodle_url('/local/kbacorporate/framework.php',['companyid'=>$companyid,'frameworkid'=>$frameworkid])); $PAGE->set_pagelayout('admin'); $PAGE->set_title(get_string('bmsframeworkmanager','local_kbacorporate')); $PAGE->set_heading(format_string($company->name));
echo $OUTPUT->header();
echo local_kbacorporate_implementation_navigation($companyid,'framework');
echo html_writer::div(get_string('bmsframeworkeyebrow','local_kbacorporate'),'kbacorp-eyebrow');
echo $OUTPUT->heading(get_string('bmsframeworkmanager','local_kbacorporate'),2);
echo html_writer::tag('p',get_string('bmsframeworkmanagerdesc','local_kbacorporate'),['class'=>'kbacorp-pagelead']);
if ($frameworks) {
  $options=[]; foreach($frameworks as $item){$options[(int)$item->id]=($item->idnumber?$item->idnumber.' — ':'').format_string($item->shortname);} 
  echo html_writer::start_tag('form',['method'=>'get','class'=>'kbacorp-bms-library-filters']);
  echo html_writer::empty_tag('input',['type'=>'hidden','name'=>'companyid','value'=>$companyid]);
  echo html_writer::select($options,'frameworkid',$frameworkid,false,['class'=>'custom-select']);
  echo html_writer::tag('button',get_string('applyfilter','local_kbacorporate'),['type'=>'submit','class'=>'btn btn-primary']);
  echo html_writer::end_tag('form');
}
echo html_writer::start_div('kbacorp-summary kbacorp-bms-tool-summary');
foreach ([[$competencycount,'bmsframeworkstatcompetencies','confirmed'],[$toolcount,'bmsframeworkstattools','developing'],[$criterioncount,'bmsframeworkstatcriteria','total'],[$mappingcount,'bmsframeworkstatmappings','notstarted'],[$mappedcriterioncount.'/'.$criterioncount,'bmsframeworkstatmappedcriteria',$mappedcriterioncount===$criterioncount?'confirmed':'developing']] as [$value,$label,$accent]) { echo html_writer::div(html_writer::div((string)$value,'value').html_writer::div(get_string($label,'local_kbacorporate'),'label'),'kbacorp-card kbacorp-card-accent-'.$accent); }
echo html_writer::end_div();
echo html_writer::start_div('kbacorp-toolbar');
echo $OUTPUT->single_button(new moodle_url('/local/kbacorporate/framework_competencies.php',['companyid'=>$companyid,'frameworkid'=>$frameworkid]),get_string('bmsframeworkcompetencies','local_kbacorporate'),'get');
echo $OUTPUT->single_button(new moodle_url('/local/kbacorporate/bms_tools.php',['companyid'=>$companyid]),get_string('bmstoollibrary','local_kbacorporate'),'get');
echo $OUTPUT->single_button(new moodle_url('/local/kbacorporate/framework_mappings.php',['companyid'=>$companyid,'frameworkid'=>$frameworkid]),get_string('bmsframeworkmappings','local_kbacorporate'),'get');
echo $OUTPUT->single_button(new moodle_url('/local/kbacorporate/bms_competency_io.php',['companyid'=>$companyid]),get_string('bmsjsoniomenu','local_kbacorporate'),'get');
echo $OUTPUT->single_button(new moodle_url('/local/kbacorporate/framework_mapping_integrity.php',['companyid'=>$companyid,'frameworkid'=>$frameworkid]),get_string('bmsintegritybutton','local_kbacorporate'),'get');
echo $OUTPUT->single_button(new moodle_url('/local/kbacorporate/bms_tool_io.php',['companyid'=>$companyid]),get_string('bmstooljsonimport','local_kbacorporate'),'get');
echo $OUTPUT->single_button(new moodle_url('/local/kbacorporate/framework_export.php',['companyid'=>$companyid,'frameworkid'=>$frameworkid,'type'=>'tools','sesskey'=>sesskey()]),get_string('bmsframeworkexporttools','local_kbacorporate'),'get');
echo $OUTPUT->single_button(new moodle_url('/local/kbacorporate/framework_export.php',['companyid'=>$companyid,'frameworkid'=>$frameworkid,'type'=>'master','sesskey'=>sesskey()]),get_string('bmsframeworkexportmaster','local_kbacorporate'),'get');
echo html_writer::end_div();
if ($framework) {
  $table=new html_table(); $table->attributes['class']='generaltable kbacorp-report';
  $table->data=[[get_string('bmsframeworkcode','local_kbacorporate'),s((string)$framework->idnumber)],[get_string('bmsframeworkname','local_kbacorporate'),format_string($framework->shortname)],[get_string('bmsframeworkcompetencies','local_kbacorporate'),(string)$competencycount],[get_string('bmsframeworkmappings','local_kbacorporate'),(string)$mappingcount]];
  echo $OUTPUT->heading(get_string('bmsframeworkcurrent','local_kbacorporate'),3); echo html_writer::table($table);
} else { echo $OUTPUT->notification(get_string('bmsframeworknotfound','local_kbacorporate'),'warning'); }
echo $OUTPUT->footer();
