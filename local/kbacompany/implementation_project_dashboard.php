<?php
/** BMS24 manager's read-only implementation dashboard for one explicitly linked project. */
require_once(__DIR__ . '/../../config.php');

require_login();
$context = context_system::instance();
require_capability('local/kbacompany:viewowncompany', $context);
$uuid = required_param('uuid', PARAM_RAW_TRIMMED);
$programid = required_param('programid', PARAM_INT);
$week = optional_param('week', '', PARAM_RAW_TRIMMED);
if ($week !== '' && (!preg_match('/^\d{4}-\d{2}-\d{2}$/D', $week) ||
        !checkdate((int)substr($week, 5, 2), (int)substr($week, 8, 2), (int)substr($week, 0, 4)))) {
    throw new invalid_parameter_exception('Invalid week');
}
$baseurl = '/local/kbacompany/implementation_project_dashboard.php';
$PAGE->set_context($context);
$PAGE->set_url(new moodle_url($baseurl, ['uuid'=>$uuid, 'programid'=>$programid, 'week'=>$week]));
$PAGE->set_title(get_string('impldash_title', 'local_kbacompany'));
$PAGE->set_heading(get_string('pluginname', 'local_kbacompany'));
$model = \local_kbacompany\local\implementation_project_dashboard_service::model(
    $uuid, (int)$USER->id, $programid, $week);
$dash = $model['dashboard'];
$bmsshell = \local_kbacompany\local\bms_page_shell_service::begin(
    $uuid, (int)$USER->id, 'adminscale', get_string('impldash_title', 'local_kbacompany'));

echo $OUTPUT->heading(get_string('impldash_title', 'local_kbacompany'), 2);
echo html_writer::tag('p', get_string('impldash_intro', 'local_kbacompany'), ['class'=>'text-muted']);
echo html_writer::tag('h3', format_string($model['projecttitle']), ['class'=>'h5']);
$buttons = html_writer::link(new moodle_url('/local/kbacompany/implementation_project_stages.php',
    ['uuid'=>$uuid,'programid'=>$programid,'week'=>$model['week']]),
    get_string('impldash_stages', 'local_kbacompany'), ['class'=>'bms24-ui-btn']);
$buttons .= ' ' . html_writer::link(new moodle_url('/local/kbacompany/implementation_projects.php',
    ['uuid'=>$uuid]), get_string('implstage_back', 'local_kbacompany'), ['class'=>'bms24-ui-btn']);
echo html_writer::div($buttons, 'mb-3');

// Corporate owns curator assignment. This panel only reveals the already-authorized manager projection.
$curatorname = (string)$model['curator_name'];
echo html_writer::start_div('card mb-3');
echo html_writer::start_div('card-body');
echo html_writer::tag('h3', get_string('impldash_curator', 'local_kbacompany'), ['class'=>'h5']);
echo html_writer::tag('p', $curatorname !== '' ? s($curatorname) :
    get_string($model['curator_id'] > 0 ? 'impldash_curator_unavailable' :
        'impldash_curator_missing', 'local_kbacompany'));
if (has_capability('local/kbacorporate:manageimplementationprograms', $context)) {
    $edit = new moodle_url('/local/kbacorporate/implementation_program_edit.php', [
        'companyid'=>$model['companyid'], 'auditid'=>(int)$model['stages']['audit_id']]);
    echo html_writer::link($edit, get_string('impldash_curator_config', 'local_kbacompany'),
        ['class'=>'bms24-ui-btn']);
}
if ((int)$model['curator_id'] === (int)$USER->id ||
        has_capability('local/kbacorporate:manageimplementationaudits', $context)) {
    echo ' ' . html_writer::link(new moodle_url('/local/kbacorporate/management_dashboards.php',
        ['panel'=>'curator']), get_string('impldash_curator_portfolio', 'local_kbacompany'),
        ['class'=>'bms24-ui-btn']);
    echo ' ' . html_writer::link(new moodle_url('/local/kbacorporate/implementation_curator.php'),
        get_string('impldash_curator_workspace', 'local_kbacompany'), ['class'=>'bms24-ui-btn']);
}
echo html_writer::end_div();echo html_writer::end_div();

// Explicit reference week, not a claim that current evidence decisions happened in this week.
$weekstart = new DateTimeImmutable($model['week'], new DateTimeZone('UTC'));
$weekurl = static function(string $date) use ($baseurl, $uuid, $programid): moodle_url {
    return new moodle_url($baseurl, ['uuid'=>$uuid, 'programid'=>$programid,'week'=>$date]);
};
echo html_writer::start_tag('nav', ['class'=>'d-flex flex-wrap align-items-center gap-2 mb-3',
    'aria-label'=>get_string('implweek_nav', 'local_kbacompany')]);
echo html_writer::link($weekurl($weekstart->modify('-7 days')->format('Y-m-d')),
    get_string('implweek_previous', 'local_kbacompany'), ['class'=>'bms24-ui-btn']);
echo html_writer::tag('strong', get_string('implweek_period', 'local_kbacompany', (object)[
    'start'=>$weekstart->format('d.m.Y'), 'end'=>$weekstart->modify('+6 days')->format('d.m.Y'),
]), ['class'=>'mx-3']);
echo html_writer::link($weekurl($weekstart->modify('+7 days')->format('Y-m-d')),
    get_string('implweek_next', 'local_kbacompany'), ['class'=>'bms24-ui-btn']);
echo html_writer::end_tag('nav');

// No fabricated zeros for incomplete/unauthorised projections.
$cards = [
    ['stages','impldash_stages_count'], ['tasks','impldash_tasks_count'],
    ['overdue','impldash_overdue_count'], ['planned','impldash_planned_count'],
    ['unplanned','impldash_unplanned_count'], ['awaiting_review','impldash_waiting_count'],
    ['returned','impldash_returned_count'], ['accepted','impldash_accepted_count'],
    ['unverified','impldash_unverified_count'],
];
echo html_writer::start_div('kbacompany-summary-grid mb-3');
foreach ($cards as [$key,$label]) {
    $value = $dash['counts'][$key];
    echo html_writer::start_div('kbacompany-summary-card');
    echo html_writer::div($value === null ? '—' : (string)$value, 'kbacompany-summary-value');
    echo html_writer::div(get_string($label, 'local_kbacompany'), 'kbacompany-summary-label');
    echo html_writer::end_div();
}
echo html_writer::end_div();
if (!$dash['stage_available']) {
    echo $OUTPUT->notification(get_string('implstage_no_baseline','local_kbacompany'),'warning');
}
if (!$dash['week_complete']) {
    echo $OUTPUT->notification(get_string('impldash_week_unavailable','local_kbacompany'),'warning');
}
if (!$dash['result_complete']) {
    echo $OUTPUT->notification(get_string('impldash_result_unavailable','local_kbacompany'),'warning');
}
echo html_writer::tag('p', get_string('impldash_scope', 'local_kbacompany'), ['class'=>'text-muted']);

// The attention queue contains verified status metadata only; Corporate independently checks task access.
echo html_writer::tag('h3', get_string('impldash_attention','local_kbacompany'), ['class'=>'h5 mt-4']);
if (!$dash['result_complete']) {
    echo html_writer::div(get_string('impldash_attention_unknown','local_kbacompany'), 'alert alert-warning');
} else if (!$dash['attention']) {
    echo html_writer::div(get_string('impldash_attention_empty','local_kbacompany'), 'alert alert-info');
} else {
    $table = new html_table();
    $table->attributes['class'] = 'table table-striped table-responsive-sm';
    $table->head = [get_string('impldash_task','local_kbacompany'),
        get_string('impldash_section','local_kbacompany'), get_string('impldash_state','local_kbacompany')];
    foreach ($dash['attention'] as $item) {
        $taskid = (int)substr($item['external_id'], strlen('impltask:'));
        $url = new moodle_url('/local/kbacorporate/implementation_task.php', ['id'=>$taskid]);
        $table->data[] = [html_writer::link($url, '#'.$taskid), s($item['section_name']),
            get_string('implresult_state_'.$item['state'], 'local_kbacompany')];
    }
    echo html_writer::table($table);
    if ($dash['attention_total'] > 12) {
        echo html_writer::tag('p', get_string('impldash_more','local_kbacompany'),
            ['class'=>'text-muted']);
    }
}

echo html_writer::tag('h3', get_string('impldash_bysection','local_kbacompany'), ['class'=>'h5 mt-4']);
if (!$dash['sections']) {
    echo html_writer::div(get_string('implstage_empty','local_kbacompany'), 'alert alert-info');
} else {
    $table = new html_table();$table->attributes['class']='table table-striped table-responsive-sm';
    $table->head = [get_string('impldash_section','local_kbacompany'),
        get_string('impldash_tasks_count','local_kbacompany'),
        get_string('impldash_planned_count','local_kbacompany'),
        get_string('impldash_unplanned_count','local_kbacompany'),
        get_string('impldash_waiting_count','local_kbacompany'),
        get_string('impldash_returned_count','local_kbacompany'),
        get_string('impldash_accepted_count','local_kbacompany')];
    foreach ($dash['sections'] as $stage) {
        $cell = [s($stage['name']), (string)$stage['tasks']];
        foreach (['planned','unplanned','awaiting_review','returned','accepted'] as $field) {
            $cell[] = $stage[$field] === null ? '—' : (string)$stage[$field];
        }
        $table->data[] = $cell;
    }
    echo html_writer::table($table);
}
echo \local_kbacompany\local\bms_page_shell_service::finish($bmsshell);
