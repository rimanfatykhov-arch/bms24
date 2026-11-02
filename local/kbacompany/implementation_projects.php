<?php
/** Explicit program-to-project mapping: BMS-owned links, Corporate-owned implementation. */
require_once(__DIR__ . '/../../config.php');

use local_kbacompany\local\implementation_project_link_service as links;

require_login();
$context = context_system::instance();
require_capability('local/kbacompany:viewowncompany', $context);
$uuid = required_param('uuid', PARAM_RAW_TRIMMED);
$page = optional_param('page', 0, PARAM_INT);
$page = max(0, min(200, $page));
$PAGE->set_context($context);
$PAGE->set_url(new moodle_url('/local/kbacompany/implementation_projects.php', ['uuid' => $uuid, 'page' => $page]));
$PAGE->set_title(get_string('implproj_title', 'local_kbacompany'));
$PAGE->set_heading(get_string('pluginname', 'local_kbacompany'));

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_sesskey();
    $action = required_param('action', PARAM_ALPHA);
    $programid = required_param('programid', PARAM_INT);
    if ($action === 'link') {
        links::link($uuid, (int)$USER->id, $programid, required_param('projectuuid', PARAM_RAW_TRIMMED));
    } else if ($action === 'unlink') {
        links::unlink($uuid, (int)$USER->id, $programid);
    } else {
        throw new invalid_parameter_exception('Invalid project link action');
    }
    redirect(new moodle_url('/local/kbacompany/implementation_projects.php', ['uuid' => $uuid, 'page' => $page]),
        get_string('implproj_saved', 'local_kbacompany'));
}

$model = links::overview($uuid, (int)$USER->id, $page);
$back = new moodle_url('/local/kbacompany/administrative_scale.php', ['uuid' => $uuid, 'viewkind' => 'project']);
$bmsshell = \local_kbacompany\local\bms_page_shell_service::begin($uuid, (int)$USER->id, 'adminscale',
    get_string('implproj_title', 'local_kbacompany'));
echo $OUTPUT->heading(get_string('implproj_title', 'local_kbacompany'), 2);
echo html_writer::tag('p', get_string('implproj_intro', 'local_kbacompany'), ['class' => 'text-muted']);
echo html_writer::link($back, get_string('implproj_projects', 'local_kbacompany'),
    ['class' => 'bms24-ui-btn mb-3']);

if (!$model['programs']) {
    echo html_writer::tag('p', get_string('implproj_empty', 'local_kbacompany'),
        ['class' => 'alert alert-info']);
}
foreach ($model['programs'] as $program) {
    $id = (int)$program['program_id'];
    echo html_writer::start_tag('section', ['class' => 'card mb-3']);
    echo html_writer::start_tag('div', ['class' => 'card-body']);
    echo html_writer::tag('h3', format_string($program['title']), ['class' => 'h5']);
    echo html_writer::tag('p', s((string)$program['code']) . ' · ' .
        get_string('implproj_status_' . $program['status'], 'local_kbacompany'), ['class' => 'text-muted']);
    echo html_writer::tag('p', get_string('implproj_tasks', 'local_kbacompany', (object)[
        'total' => (int)$program['task_count'], 'done' => (int)$program['completed_count'],
        'overdue' => (int)$program['overdue_count'],
    ]));
    $linked = $program['linked'];
    if ($linked) {
        if ($linked['valid_project']) {
            echo html_writer::tag('p', html_writer::link(new moodle_url(
                '/local/kbacompany/implementation_project_dashboard.php',
                ['uuid' => $uuid, 'programid' => $id]),
                get_string('impldash_open', 'local_kbacompany')));
            echo html_writer::tag('p', html_writer::link(new moodle_url(
                '/local/kbacompany/implementation_project_stages.php',
                ['uuid' => $uuid, 'programid' => $id]),
                get_string('implstage_open', 'local_kbacompany')));
            $projecturl = new moodle_url('/local/kbacompany/administrative_scale.php', [
                'uuid' => $uuid, 'viewkind' => 'project', 'detailuuid' => $linked['projectuuid'],
            ]);
            echo html_writer::tag('p', get_string('implproj_linked', 'local_kbacompany') . ': ' .
                html_writer::link($projecturl, s($linked['project'])));
        } else {
            echo html_writer::tag('p', get_string('implproj_stale', 'local_kbacompany'),
                ['class' => 'alert alert-warning']);
        }
        echo html_writer::start_tag('form', ['method' => 'post', 'action' => $PAGE->url->out(false)]);
        echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'uuid', 'value' => $uuid]);
        echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'page', 'value' => $page]);
        echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'sesskey', 'value' => sesskey()]);
        echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'programid', 'value' => $id]);
        echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'action', 'value' => 'unlink']);
        echo html_writer::tag('button', get_string('implproj_unlink', 'local_kbacompany'), [
            'type' => 'submit', 'class' => 'bms24-ui-btn bms24-ui-btn--danger']);
        echo html_writer::end_tag('form');
    } else if ($model['projects']) {
        echo html_writer::start_tag('form', ['method' => 'post', 'action' => $PAGE->url->out(false)]);
        foreach (['uuid' => $uuid, 'page' => $page, 'sesskey' => sesskey(), 'programid' => $id,
                'action' => 'link'] as $key => $value) {
            echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => $key, 'value' => $value]);
        }
        $options = [];
        foreach ($model['projects'] as $item) { $options[$item['uuid']] = $item['title']; }
        echo html_writer::tag('label', get_string('implproj_select', 'local_kbacompany'), ['for' => 'implproj_select_' . $id]);
        echo html_writer::select($options, 'projectuuid', '', null,
            ['id' => 'implproj_select_' . $id, 'class' => 'custom-select mb-2']);
        echo html_writer::tag('button', get_string('implproj_link', 'local_kbacompany'), [
            'type' => 'submit', 'class' => 'bms24-ui-btn bms24-ui-btn--primary']);
        echo html_writer::end_tag('form');
    } else {
        echo html_writer::tag('p', get_string('implproj_no_projects', 'local_kbacompany'),
            ['class' => 'text-muted']);
    }
    echo html_writer::end_tag('div');
    echo html_writer::end_tag('section');
}
if ($page > 0) {
    echo html_writer::link(new moodle_url('/local/kbacompany/implementation_projects.php',
        ['uuid' => $uuid, 'page' => $page - 1]), get_string('implproj_previous', 'local_kbacompany'),
        ['class' => 'bms24-ui-btn mr-2']);
}
if ($model['has_next']) {
    echo html_writer::link(new moodle_url('/local/kbacompany/implementation_projects.php',
        ['uuid' => $uuid, 'page' => $page + 1]), get_string('implproj_next', 'local_kbacompany'),
        ['class' => 'bms24-ui-btn']);
}
echo \local_kbacompany\local\bms_page_shell_service::finish($bmsshell);
