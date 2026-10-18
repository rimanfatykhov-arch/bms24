<?php
require_once('../../config.php');
require_login(); require_capability('moodle/site:config', context_system::instance());
$PAGE->set_url(new moodle_url('/local/kbaai/diagnostics_v081.php')); $PAGE->set_context(context_system::instance()); $PAGE->set_title(get_string('diagnostics','local_kbaai')); $PAGE->set_heading(get_string('diagnostics','local_kbaai'));
if ($_SERVER['REQUEST_METHOD']==='POST') {
    require_sesskey();
    foreach (array('subinstancefield','subuserfield','subanswerfield','submodifiedfield','subrevisionfield','subgradefield','subfeedbackfield','substatusfield','instancecontentfield','instancetaskfield','instanceobjectivesfield','instancecoursefield') as $key) {
        set_config($key, optional_param($key,'',PARAM_ALPHANUMEXT), 'local_kbaai');
    }
    redirect($PAGE->url,get_string('settingssaved','local_kbaai'));
}
$adapter=new \local_kbaai\source\kbaelement_adapter(); $d=$adapter->diagnose();
echo $OUTPUT->header(); echo $OUTPUT->heading(get_string('diagnostics','local_kbaai'));
echo $OUTPUT->notification('Diagnostic code revision: 0.8.1 / build 2026081520', 'info');
$plugininfo = \core_plugin_manager::instance()->get_plugin_info('local_kbaai');
$release = ($plugininfo && !empty($plugininfo->release)) ? (string)$plugininfo->release : '0.8.1';
$versiondisk = ($plugininfo && !empty($plugininfo->versiondisk)) ? (string)$plugininfo->versiondisk : '2026081520';
$versiondb = ($plugininfo && !empty($plugininfo->versiondb)) ? (string)$plugininfo->versiondb : get_string('versionnotinstalled','local_kbaai');
$vtable = new html_table();
$vtable->attributes['class'] = 'generaltable table-sm mb-0';
$vtable->data = array(
    array(get_string('productversion','local_kbaai'), s($release)),
    array(get_string('plugincomponent','local_kbaai'), 'local_kbaai'),
    array(get_string('versionondisk','local_kbaai'), s($versiondisk)),
    array(get_string('versionindb','local_kbaai'), s($versiondb)),
);
echo html_writer::start_div('card mb-4');
echo html_writer::start_div('card-body');
echo html_writer::tag('h3', get_string('versioninfoheading','local_kbaai'), array('class'=>'h5'));
echo html_writer::table($vtable);
echo html_writer::end_div();
echo html_writer::end_div();
$docxdiag=(new \local_kbaai\service\docx_extractor())->diagnose();
$dt=new html_table(); $dt->attributes['class']='generaltable table-sm mb-0';
$dt->data=array(
    array(get_string('diagnosticphpzip','local_kbaai'), !empty($docxdiag['ziparchive']) ? get_string('yes') : get_string('no')),
    array(get_string('diagnosticphpdom','local_kbaai'), !empty($docxdiag['domdocument']) ? get_string('yes') : get_string('no')),
    array(get_string('docxparagraphs','local_kbaai'), isset($docxdiag['paragraphs']) ? (int)$docxdiag['paragraphs'] : 0),
    array(get_string('docxtables','local_kbaai'), isset($docxdiag['tables']) ? (int)$docxdiag['tables'] : 0),
    array(get_string('docximages','local_kbaai'), isset($docxdiag['images']) ? (int)$docxdiag['images'] : 0),
);
echo html_writer::start_div('card mb-4'); echo html_writer::start_div('card-body');
echo html_writer::tag('h3',get_string('diagnosticdocx','local_kbaai'),array('class'=>'h5'));
echo html_writer::table($dt);
if (empty($docxdiag['ok']) && !empty($docxdiag['error'])) { echo $OUTPUT->notification(s($docxdiag['error']),'warning'); }
echo html_writer::end_div(); echo html_writer::end_div();
$xlsxdiag=(new \local_kbaai\service\xlsx_extractor())->diagnose();
$xt=new html_table(); $xt->attributes['class']='generaltable table-sm mb-0';
$xt->data=array(
    array(get_string('diagnosticphpzip','local_kbaai'), !empty($xlsxdiag['ziparchive']) ? get_string('yes') : get_string('no')),
    array(get_string('diagnosticphpdom','local_kbaai'), !empty($xlsxdiag['domdocument']) ? get_string('yes') : get_string('no')),
    array(get_string('xlsxsheets','local_kbaai'), isset($xlsxdiag['sheets']) ? (int)$xlsxdiag['sheets'] : 0),
    array(get_string('xlsxcellscount','local_kbaai'), isset($xlsxdiag['cells']) ? (int)$xlsxdiag['cells'] : 0),
    array(get_string('xlsxformulas','local_kbaai'), isset($xlsxdiag['formulas']) ? (int)$xlsxdiag['formulas'] : 0),
    array(get_string('xlsxmerged','local_kbaai'), isset($xlsxdiag['merged']) ? (int)$xlsxdiag['merged'] : 0),
    array(get_string('xlsxtables','local_kbaai'), isset($xlsxdiag['tables']) ? (int)$xlsxdiag['tables'] : 0),
);
echo html_writer::start_div('card mb-4'); echo html_writer::start_div('card-body');
echo html_writer::tag('h3',get_string('diagnosticxlsx','local_kbaai'),array('class'=>'h5'));
echo html_writer::table($xt);
if (empty($xlsxdiag['ok']) && !empty($xlsxdiag['error'])) { echo $OUTPUT->notification(s($xlsxdiag['error']),'warning'); }
echo html_writer::end_div(); echo html_writer::end_div();
echo $OUTPUT->notification($d['ready']?get_string('mappingready','local_kbaai'):get_string('mappingnotready','local_kbaai'),$d['ready']?'success':'error');
foreach ($d['errors'] as $v) { echo $OUTPUT->notification(s($v),'error'); } foreach ($d['warnings'] as $v) { echo $OUTPUT->notification(s($v),'warning'); }
$table=new html_table(); $table->head=array(get_string('mappingkey','local_kbaai'),get_string('detectedfield','local_kbaai'));
foreach ($d['mapping'] as $k=>$v) { $table->data[]=array(s($k),$v?s($v):'—'); } echo html_writer::table($table);
echo html_writer::tag('h3','kbaelement fields',array('class'=>'h5')); echo html_writer::tag('pre',s(implode(', ',$d['instancecolumns'])));
echo html_writer::tag('h3','kbaelement_submissions fields',array('class'=>'h5')); echo html_writer::tag('pre',s(implode(', ',$d['submissioncolumns'])));
$map=array('subinstancefield'=>'sub_instance','subuserfield'=>'sub_user','subanswerfield'=>'sub_answer','submodifiedfield'=>'sub_modified','subrevisionfield'=>'sub_revision','subgradefield'=>'sub_grade','subfeedbackfield'=>'sub_feedback','substatusfield'=>'sub_status','instancecontentfield'=>'instance_content','instancetaskfield'=>'instance_task','instanceobjectivesfield'=>'instance_objectives','instancecoursefield'=>'instance_course');
echo html_writer::start_tag('form',array('method'=>'post','action'=>$PAGE->url)); echo html_writer::empty_tag('input',array('type'=>'hidden','name'=>'sesskey','value'=>sesskey()));
foreach ($map as $setting=>$logical) { echo html_writer::tag('label',get_string($setting,'local_kbaai')); echo html_writer::empty_tag('input',array('type'=>'text','name'=>$setting,'value'=>(string)get_config('local_kbaai',$setting),'placeholder'=>isset($d['mapping'][$logical])?$d['mapping'][$logical]:'','class'=>'form-control mb-2')); }
echo html_writer::tag('button',get_string('savechanges'),array('type'=>'submit','class'=>'btn btn-primary')); echo html_writer::end_tag('form'); echo $OUTPUT->footer();
