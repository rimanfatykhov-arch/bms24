<?php
require_once(__DIR__ . '/../../config.php');

use local_kbacompany\local\position_competency_profile_service;

require_login();
$context = context_system::instance();
require_capability('local/kbacompany:viewowncompany', $context);
$uuid = required_param('uuid', PARAM_RAW_TRIMMED);
$positionuuid = required_param('position', PARAM_RAW_TRIMMED);
$action = optional_param('action', '', PARAM_ALPHA);

if ($action !== '') {
    require_sesskey();
    if ($action === 'save') {
        position_competency_profile_service::save_requirement($uuid, (int)$USER->id, $positionuuid,
            required_param('competencyid', PARAM_INT), optional_param('targetlevel', 0, PARAM_INT),
            optional_param('priority', 'normal', PARAM_ALPHA), optional_param('required', 0, PARAM_BOOL));
        redirect(new moodle_url('/local/kbacompany/position_competencies.php', ['uuid' => $uuid, 'position' => $positionuuid]),
            get_string('positioncompetency_saved', 'local_kbacompany'));
    } else if ($action === 'remove') {
        position_competency_profile_service::remove_requirement($uuid, (int)$USER->id, $positionuuid,
            required_param('id', PARAM_INT));
        redirect(new moodle_url('/local/kbacompany/position_competencies.php', ['uuid' => $uuid, 'position' => $positionuuid]),
            get_string('positioncompetency_removed', 'local_kbacompany'));
    } else if ($action === 'publish') {
        position_competency_profile_service::publish($uuid, (int)$USER->id, $positionuuid);
        redirect(new moodle_url('/local/kbacompany/position_competencies.php', ['uuid' => $uuid, 'position' => $positionuuid]),
            get_string('positioncompetency_published', 'local_kbacompany'));
    }
}

$data = position_competency_profile_service::context($uuid, (int)$USER->id, $positionuuid);
$PAGE->set_context($context);
$PAGE->set_url(new moodle_url('/local/kbacompany/position_competencies.php', ['uuid' => $uuid, 'position' => $positionuuid]));
$PAGE->set_title(get_string('positioncompetency_title', 'local_kbacompany'));
$PAGE->set_heading(get_string('pluginname', 'local_kbacompany'));
$shell = \local_kbacompany\local\bms_page_shell_service::begin($uuid, (int)$USER->id, 'positions',
    get_string('positioncompetency_title', 'local_kbacompany'));

$back = new moodle_url('/local/kbacompany/positions.php', ['uuid' => $uuid, 'position' => $positionuuid]);
echo html_writer::div(html_writer::link($back, '← ' . get_string('pdu_back_record', 'local_kbacompany')), 'mb-3');
echo $OUTPUT->heading(s($data['position']['code']) . ' — ' . format_string($data['position']['name']), 2, 'mb-1');
echo html_writer::tag('p', get_string('positioncompetency_intro', 'local_kbacompany'), ['class' => 'text-muted']);

$profile = $data['profile'];
$status = $profile ? get_string('people_status_' . $profile['status'], 'local_kbacompany') : get_string('positioncompetency_not_created', 'local_kbacompany');
$meta = $profile ? s($profile['stable_code']) . ' · ' . get_string('revision', 'local_kbacompany') . ' ' . (int)$profile['revision'] : '—';
echo html_writer::start_div('row mb-3');
foreach ([
    [get_string('status'), $status],
    [get_string('positioncompetency_profile', 'local_kbacompany'), $meta],
    [get_string('positioncompetency_requirements', 'local_kbacompany'), (string)$data['summary']['requirements']],
    [get_string('positioncompetency_required_count', 'local_kbacompany'), (string)$data['summary']['required']],
] as $card) {
    echo html_writer::start_div('col-md-3 mb-2');
    echo html_writer::div(html_writer::tag('div', s($card[0]), ['class' => 'text-muted small']) .
        html_writer::tag('div', s($card[1]), ['class' => 'font-weight-bold']), 'card card-body py-2 h-100');
    echo html_writer::end_div();
}
echo html_writer::end_div();

echo $OUTPUT->heading(get_string('positioncompetency_requirements', 'local_kbacompany'), 3);
if (!$data['requirements']) {
    echo $OUTPUT->notification(get_string('positioncompetency_empty', 'local_kbacompany'), 'info');
} else {
    $table = new html_table();
    $table->head = [get_string('idnumber'), get_string('name'), get_string('positioncompetency_target_level', 'local_kbacompany'),
        get_string('priority'), get_string('positioncompetency_required', 'local_kbacompany'), get_string('actions')];
    foreach ($data['requirements'] as $req) {
        $actions = '';
        if ($data['can_manage'] && $profile && $profile['status'] === 'draft') {
            $url = new moodle_url('/local/kbacompany/position_competencies.php', [
                'uuid' => $uuid, 'position' => $positionuuid, 'action' => 'remove', 'id' => $req['id'], 'sesskey' => sesskey(),
            ]);
            $actions = html_writer::link($url, get_string('delete'), ['class' => 'btn btn-sm btn-outline-danger']);
        }
        $table->data[] = [s($req['code']), format_string($req['name']), $req['target_level'] === null ? '—' : (int)$req['target_level'],
            get_string('positioncompetency_priority_' . $req['priority'], 'local_kbacompany'),
            $req['required'] ? get_string('yes') : get_string('no'), $actions];
    }
    echo html_writer::table($table);
}

if ($data['can_manage']) {
    echo $OUTPUT->heading(get_string('positioncompetency_add', 'local_kbacompany'), 3, 'mt-4');
    if (!$data['competencies']) {
        echo $OUTPUT->notification(get_string('positioncompetency_no_catalog', 'local_kbacompany'), 'warning');
    } else {
        $options = [];
        foreach ($data['competencies'] as $competency) {
            $options[(int)$competency['id']] = trim((string)$competency['idnumber'] . ' — ' . (string)$competency['shortname'], ' —');
        }
        echo html_writer::start_tag('form', ['method' => 'post', 'class' => 'kbacompany-form mb-3']);
        foreach (['uuid' => $uuid, 'position' => $positionuuid, 'action' => 'save', 'sesskey' => sesskey()] as $key => $value) {
            echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => $key, 'value' => $value]);
        }
        echo html_writer::label(get_string('competency', 'core_competency'), 'competencyid');
        echo html_writer::select($options, 'competencyid', '', false, ['id' => 'competencyid', 'required' => 'required']);
        echo html_writer::label(get_string('positioncompetency_target_level', 'local_kbacompany'), 'targetlevel');
        echo html_writer::empty_tag('input', ['type' => 'number', 'name' => 'targetlevel', 'id' => 'targetlevel', 'min' => 0, 'max' => 100, 'value' => 0]);
        echo html_writer::label(get_string('priority'), 'priority');
        echo html_writer::select([
            'low' => get_string('positioncompetency_priority_low', 'local_kbacompany'),
            'normal' => get_string('positioncompetency_priority_normal', 'local_kbacompany'),
            'high' => get_string('positioncompetency_priority_high', 'local_kbacompany'),
            'critical' => get_string('positioncompetency_priority_critical', 'local_kbacompany'),
        ], 'priority', 'normal', false, ['id' => 'priority']);
        echo html_writer::tag('label', html_writer::empty_tag('input', ['type' => 'checkbox', 'name' => 'required', 'value' => 1]) .
            ' ' . get_string('positioncompetency_required', 'local_kbacompany'), ['class' => 'kbacompany-checkbox']);
        echo html_writer::tag('button', get_string('savechanges'), ['type' => 'submit', 'class' => 'btn btn-primary']);
        echo html_writer::end_tag('form');
    }
    if ($profile && $profile['status'] === 'draft' && $data['requirements']) {
        $publish = new moodle_url('/local/kbacompany/position_competencies.php', [
            'uuid' => $uuid, 'position' => $positionuuid, 'action' => 'publish', 'sesskey' => sesskey(),
        ]);
        echo html_writer::link($publish, get_string('positioncompetency_publish', 'local_kbacompany'), ['class' => 'btn btn-success']);
    }
}

echo html_writer::div(get_string('positioncompetency_boundary_note', 'local_kbacompany'), 'alert alert-info mt-4');
\local_kbacompany\local\bms_page_shell_service::finish($shell);
