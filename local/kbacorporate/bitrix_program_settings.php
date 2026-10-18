<?php
require_once(__DIR__ . '/../../config.php');
require_login();
$context = context_system::instance();
require_capability('moodle/site:config', $context);
$savedcategory = (int)get_config('local_kbacorporate', 'bitrixprogramcategoryid');
$savedstage = (string)get_config('local_kbacorporate', 'bitrixprogramstageid');
$savedresponsible = (int)get_config('local_kbacorporate', 'bitrixprogramresponsibleid');
$categoryid = optional_param('categoryid', $savedcategory, PARAM_INT);
$stageid = optional_param('stageid', $savedstage, PARAM_TEXT);
$responsibleid = optional_param('responsibleid', $savedresponsible, PARAM_INT);
if (optional_param('save', 0, PARAM_BOOL)) {
    require_sesskey();
    set_config('bitrixprogramcategoryid', $categoryid, 'local_kbacorporate');
    set_config('bitrixprogramstageid', $stageid, 'local_kbacorporate');
    set_config('bitrixprogramresponsibleid', $responsibleid, 'local_kbacorporate');
    redirect(new moodle_url('/local/kbacorporate/bitrix_program_settings.php'),
        get_string('bitrixprogramselectorsaved', 'local_kbacorporate'));
}
$categories = []; $stages = []; $users = []; $loaderrors = []; $userfallback = false;
try { $categories = \local_kbacorporate\local\bitrix_programs::get_categories(); }
catch (Throwable $e) { $loaderrors[] = get_string('bitrixpipelineloadfailed', 'local_kbacorporate', $e->getMessage()); }
if ($categoryid || array_key_exists(0, $categories)) {
    try { $stages = \local_kbacorporate\local\bitrix_programs::get_stages($categoryid); }
    catch (Throwable $e) { $loaderrors[] = get_string('bitrixstageloadfailed', 'local_kbacorporate', $e->getMessage()); }
}
try { $users = \local_kbacorporate\local\bitrix_programs::get_responsible_users(); }
catch (Throwable $e) {
    try { $users = \local_kbacorporate\local\bitrix_programs::get_current_user(); $userfallback = !empty($users); }
    catch (Throwable $fallbackerror) {
        $loaderrors[] = get_string('bitrixuserloadfailed', 'local_kbacorporate', $e->getMessage());
    }
}
if ($savedcategory && !isset($categories[$savedcategory])) { $categories[$savedcategory] = get_string('savedvalue', 'local_kbacorporate', $savedcategory); }
if ($savedstage !== '' && !isset($stages[$savedstage])) { $stages[$savedstage] = get_string('savedvalue', 'local_kbacorporate', $savedstage); }
if ($savedresponsible && !isset($users[$savedresponsible])) { $users[$savedresponsible] = get_string('savedvalue', 'local_kbacorporate', $savedresponsible); }
$PAGE->set_context($context); $PAGE->set_url(new moodle_url('/local/kbacorporate/bitrix_program_settings.php'));
$PAGE->set_pagelayout('admin'); $PAGE->set_title(get_string('bitrixprogramselector', 'local_kbacorporate'));
$PAGE->set_heading(get_string('bitrixprogramselector', 'local_kbacorporate'));
echo $OUTPUT->header(); echo $OUTPUT->heading(get_string('bitrixprogramselector', 'local_kbacorporate'));
echo html_writer::tag('p', get_string('bitrixprogramselectordesc', 'local_kbacorporate'), ['class' => 'kbacorp-pagelead']);
foreach ($loaderrors as $loaderror) { echo $OUTPUT->notification($loaderror, 'error'); }
if ($userfallback) { echo $OUTPUT->notification(get_string('bitrixuserfallbacknotice', 'local_kbacorporate'), 'warning'); }
echo html_writer::start_tag('form', ['method' => 'post']);
echo html_writer::empty_tag('input', ['type'=>'hidden','name'=>'sesskey','value'=>sesskey()]);
echo html_writer::div(html_writer::tag('label', get_string('bitrixprogrampipeline', 'local_kbacorporate'), ['for'=>'id_categoryid']) .
    html_writer::select($categories, 'categoryid', $categoryid, false, ['id'=>'id_categoryid','class'=>'custom-select']), 'form-group');
echo html_writer::tag('button', get_string('loadbitrixstages', 'local_kbacorporate'),
    ['type'=>'submit','name'=>'loadstages','value'=>1,'class'=>'btn btn-secondary mb-3']);
echo html_writer::div(html_writer::tag('label', get_string('bitrixprogramstage', 'local_kbacorporate'), ['for'=>'id_stageid']) .
    html_writer::select($stages, 'stageid', $stageid, false, ['id'=>'id_stageid','class'=>'custom-select']), 'form-group');
echo html_writer::div(html_writer::tag('label', get_string('bitrixprogramresponsible', 'local_kbacorporate'), ['for'=>'id_responsibleid']) .
    html_writer::select([0 => get_string('notset', 'local_kbacorporate')] + $users, 'responsibleid', $responsibleid,
        false, ['id'=>'id_responsibleid','class'=>'custom-select']), 'form-group');
echo html_writer::tag('button', get_string('savechanges'), ['type'=>'submit','name'=>'save','value'=>1,'class'=>'btn btn-primary']);
echo html_writer::end_tag('form'); echo $OUTPUT->footer();
