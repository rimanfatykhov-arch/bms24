<?php
/** Company-scoped task bundle library and launch UI. */
require_once(__DIR__ . '/../../config.php');

use local_kbacompany\local\task_bundle_service as bundles;
use local_kbacompany\local\task_service;
use local_kbacompany\local\company_context_service;
use local_kbacompany\local\organization_service;

require_login();
$context = context_system::instance();
require_capability('local/kbacompany:viewowncompany', $context);
$company = required_param('uuid', PARAM_RAW_TRIMMED);
$actor = (int)$USER->id;
company_context_service::context($company, $actor);
if (!task_service::can_manage($company, $actor)) {
    throw new moodle_exception('taskcenter_permission', 'local_kbacompany');
}
$task = optional_param('task', '', PARAM_RAW_TRIMMED);
$template = optional_param('template', '', PARAM_RAW_TRIMMED);
$page = max(0, optional_param('page', 0, PARAM_INT));
$action = optional_param('action', '', PARAM_ALPHA);
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
if (!in_array($method, ['GET', 'HEAD', 'POST'], true)) {
    throw new moodle_exception('taskcenter_post', 'local_kbacompany');
}
$url = static function(array $params = []) use ($company): moodle_url {
    return new moodle_url('/local/kbacompany/task_templates.php', ['uuid' => $company] + $params);
};
$error = '';
if ($action !== '') {
    if ($method !== 'POST') { throw new moodle_exception('taskcenter_post', 'local_kbacompany'); }
    require_sesskey();
    try {
        if ($action === 'capture') {
            $created = bundles::capture($company, $actor, required_param('task', PARAM_RAW_TRIMMED),
                required_param('title', PARAM_TEXT));
            redirect($url(['template' => $created]), get_string('bms_bundle_saved', 'local_kbacompany'));
        } else if ($action === 'launch') {
            $created = bundles::launch($company, $actor, required_param('template', PARAM_RAW_TRIMMED),
                required_param('requestkey', PARAM_ALPHANUM), required_param('assigneeid', PARAM_INT),
                optional_param('reviewerid', 0, PARAM_INT), required_param('startdate', PARAM_RAW_TRIMMED),
                optional_param('originalassignees', false, PARAM_BOOL));
            redirect(new moodle_url('/local/kbacompany/tasks.php',
                ['uuid' => $company, 'task' => $created, 'view' => 'detail']),
                get_string('bms_bundle_launched', 'local_kbacompany'));
        } else if ($action === 'archive') {
            if (!optional_param('confirmed', false, PARAM_BOOL)) {
                throw new moodle_exception('tui_confirm_required', 'local_kbacompany');
            }
            bundles::archive($company, $actor, required_param('template', PARAM_RAW_TRIMMED));
            redirect($url(), get_string('bms_bundle_archived', 'local_kbacompany'));
        } else {
            throw new moodle_exception('taskcenter_invalid', 'local_kbacompany');
        }
    } catch (moodle_exception $e) {
        $error = $e->getMessage();
    }
}
$PAGE->set_context($context);
$PAGE->set_url($url(['task' => $task, 'template' => $template]));
$PAGE->set_title(get_string('bms_bundle_title', 'local_kbacompany'));
$PAGE->set_heading(get_string('pluginname', 'local_kbacompany'));
$shell = \local_kbacompany\local\bms_page_shell_service::begin($company, $actor, 'tasks',
    get_string('bms_bundle_title', 'local_kbacompany'));
echo html_writer::start_div('bms-tui bms24-task-center');
echo html_writer::tag('h2', get_string('bms_bundle_title', 'local_kbacompany'));
echo html_writer::div(get_string('bms_bundle_intro', 'local_kbacompany'), 'alert alert-info');
echo html_writer::link(new moodle_url('/local/kbacompany/tasks.php', ['uuid' => $company]),
    get_string('tui_back_list', 'local_kbacompany'), ['class' => 'btn btn-outline-secondary mb-3']);
if ($error !== '') { echo html_writer::div(s($error), 'alert alert-danger', ['role' => 'alert']); }
$hidden = static function(array $fields): string {
    $out = '';
    foreach ($fields as $k => $v) {
        $out .= html_writer::empty_tag('input', ['type' => 'hidden', 'name' => $k, 'value' => $v]);
    }
    return $out;
};
if ($task !== '') {
    global $DB;
    $source = $DB->get_record('local_kbacompany_task', ['companyuuid' => $company, 'uuid' => $task]);
    if (!$source || $source->status === 'deleted') {
        throw new moodle_exception('taskcenter_reference', 'local_kbacompany');
    }
    echo html_writer::start_div('card mb-3');
    echo html_writer::start_div('card-body');
    echo html_writer::tag('h3', get_string('bms_bundle_capture', 'local_kbacompany'));
    echo html_writer::div(s((string)$source->title), 'mb-2');
    $capture = $hidden(['uuid' => $company, 'task' => $task, 'action' => 'capture', 'sesskey' => sesskey()]);
    $capture .= html_writer::tag('label', get_string('bms_bundle_name', 'local_kbacompany'), ['for' => 'bundle-title']);
    $capture .= html_writer::empty_tag('input', ['id' => 'bundle-title', 'type' => 'text', 'name' => 'title',
        'class' => 'form-control mb-2', 'maxlength' => 255, 'required' => 'required',
        'value' => (string)$source->title]);
    $capture .= html_writer::tag('button', get_string('bms_bundle_save', 'local_kbacompany'),
        ['type' => 'submit', 'class' => 'btn btn-primary']);
    echo html_writer::tag('form', $capture, ['method' => 'post', 'action' => $url()->out(false)]);
    echo html_writer::end_div();
    echo html_writer::end_div();
}
if ($template !== '') {
    $data = bundles::get($company, $actor, $template);
    $root = $data['snapshot']['root'];
    $subtasks = $data['snapshot']['subtasks'];
    $employeeoptions = [];
    $organisation = organization_service::organization_context($company, $actor);
    foreach ($organisation['employees'] as $person) {
        $employeeoptions[(int)$person['user_id']] = (string)$person['full_name'];
    }
    asort($employeeoptions);
    echo html_writer::start_div('card mb-3');
    echo html_writer::start_div('card-body');
    echo html_writer::tag('h3', s((string)$data['row']->title));
    echo html_writer::tag('p', get_string('bms_bundle_launch_hint', 'local_kbacompany'));
    echo html_writer::tag('p', s($root['title'] . ' · ' . count($subtasks) . ' ' .
        get_string('bms_bundle_children_short', 'local_kbacompany')));
    if ($subtasks) {
        $preview = '';
        foreach ($subtasks as $child) {
            $preview .= html_writer::tag('li', s((string)$child['title']) . ' · ' .
                s((string)count($child['checklist'] ?? [])) . ' ' .
                get_string('bms_bundle_checks_short', 'local_kbacompany'));
        }
        echo html_writer::tag('ul', $preview, ['class' => 'mb-3']);
    }
    $form = $hidden(['uuid' => $company, 'template' => $template, 'action' => 'launch',
        'sesskey' => sesskey(), 'requestkey' => bin2hex(random_bytes(16))]);
    $form .= html_writer::tag('label', get_string('bms_bundle_assignee', 'local_kbacompany'), ['for' => 'bundle-assignee']);
    $form .= html_writer::select($employeeoptions, 'assigneeid', $actor, false,
        ['id' => 'bundle-assignee', 'class' => 'form-control mb-2']);
    $form .= html_writer::tag('label', get_string('bms_bundle_reviewer', 'local_kbacompany'), ['for' => 'bundle-reviewer']);
    $form .= html_writer::select([0 => '—'] + $employeeoptions, 'reviewerid', 0, false,
        ['id' => 'bundle-reviewer', 'class' => 'form-control mb-2']);
    $form .= html_writer::tag('label', get_string('bms_bundle_start', 'local_kbacompany'), ['for' => 'bundle-start']);
    $form .= html_writer::empty_tag('input', ['type' => 'date', 'id' => 'bundle-start', 'name' => 'startdate',
        'required' => 'required', 'value' => gmdate('Y-m-d'), 'class' => 'form-control mb-2']);
    $check = html_writer::empty_tag('input', ['type' => 'checkbox', 'name' => 'originalassignees', 'value' => '1', 'id' => 'bundle-keep']);
    $form .= html_writer::tag('label', $check . ' ' . get_string('bms_bundle_keep', 'local_kbacompany'),
        ['for' => 'bundle-keep', 'class' => 'd-block mb-3']);
    $form .= html_writer::tag('button', get_string('bms_bundle_launch', 'local_kbacompany'),
        ['class' => 'btn btn-primary', 'type' => 'submit']);
    echo html_writer::tag('form', $form, ['method' => 'post', 'action' => $url()->out(false)]);
    echo html_writer::end_div(); echo html_writer::end_div();
}
echo html_writer::tag('h3', get_string('bms_bundle_library', 'local_kbacompany'));
$rows = bundles::library($company, $actor, $page);
if (!$rows) { echo html_writer::div(get_string('bms_bundle_empty', 'local_kbacompany'), 'alert alert-secondary'); }
foreach ($rows as $row) {
    echo html_writer::start_div('card mb-2'); echo html_writer::start_div('card-body');
    echo html_writer::tag('h4', s($row['title']));
    echo html_writer::tag('p', s($row['children'] . ' ' . get_string('bms_bundle_children_short', 'local_kbacompany')) .
        ' · ' . userdate($row['created'], get_string('strftimedate', 'langconfig')));
    echo html_writer::link($url(['template' => $row['uuid']]),
        get_string('bms_bundle_launch', 'local_kbacompany'), ['class' => 'btn btn-primary mr-2']);
    if (optional_param('archiveconfirm', '', PARAM_RAW_TRIMMED) === $row['uuid']) {
        $archive = $hidden(['uuid' => $company, 'template' => $row['uuid'], 'action' => 'archive',
            'sesskey' => sesskey()]);
        $archive .= html_writer::tag('label',
            html_writer::empty_tag('input', ['type' => 'checkbox', 'name' => 'confirmed',
                'value' => '1', 'required' => 'required']) . ' ' .
                get_string('bms_bundle_archive_confirm', 'local_kbacompany'), ['class' => 'd-block']);
        $archive .= html_writer::tag('button', get_string('bms_bundle_archive', 'local_kbacompany'),
            ['type' => 'submit', 'class' => 'btn btn-outline-secondary']);
        echo html_writer::tag('form', $archive, ['method' => 'post', 'action' => $url()->out(false),
            'class' => 'd-inline-block ml-2']);
    } else {
        echo html_writer::link($url(['archiveconfirm' => $row['uuid']]),
            get_string('bms_bundle_archive', 'local_kbacompany'),
            ['class' => 'btn btn-outline-secondary ml-2']);
    }
    echo html_writer::end_div(); echo html_writer::end_div();
}
if (count($rows) === 25) { echo html_writer::link($url(['page' => $page + 1]),
    get_string('bms_bundle_next', 'local_kbacompany'), ['class' => 'btn btn-outline-secondary']); }
if ($page > 0) { echo html_writer::link($url(['page' => $page - 1]),
    get_string('bms_bundle_prev', 'local_kbacompany'), ['class' => 'btn btn-outline-secondary']); }
echo html_writer::end_div();
\local_kbacompany\local\bms_page_shell_service::finish($shell);
