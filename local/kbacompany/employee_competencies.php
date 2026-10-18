<?php
require_once(__DIR__ . '/../../config.php');

use local_kbacompany\local\employee_competency_profile_service;
use local_kbacompany\local\bms_workspace_ux_service;

require_login();
$context = context_system::instance();
require_capability('local/kbacompany:viewowncompany', $context);
$uuid = required_param('uuid', PARAM_RAW_TRIMMED);
$userid = required_param('userid', PARAM_INT);
$action = optional_param('action', '', PARAM_ALPHA);

if ($action === 'save') {
    require_sesskey();
    employee_competency_profile_service::save_evidence(
        $uuid, $userid, (int)$USER->id,
        required_param('competencyid', PARAM_INT),
        required_param('currentlevel', PARAM_INT),
        optional_param('sourcekind', 'manager_observation', PARAM_ALPHANUMEXT),
        optional_param('evidence', '', PARAM_TEXT),
        optional_param('evidenceurl', '', PARAM_URL)
    );
    redirect(new moodle_url('/local/kbacompany/employee_competencies.php', ['uuid' => $uuid, 'userid' => $userid]),
        get_string('employeecompetency_saved', 'local_kbacompany'));
}

$data = employee_competency_profile_service::context($uuid, $userid, (int)$USER->id);
$PAGE->set_context($context);
$PAGE->set_url(new moodle_url('/local/kbacompany/employee_competencies.php', ['uuid' => $uuid, 'userid' => $userid]));
$PAGE->set_title(get_string('employeecompetency_title', 'local_kbacompany'));
$PAGE->set_heading(get_string('pluginname', 'local_kbacompany'));
$shell = \local_kbacompany\local\bms_page_shell_service::begin($uuid, (int)$USER->id, 'employees',
    get_string('employeecompetency_title', 'local_kbacompany'));

$back = new moodle_url('/local/kbacompany/employee_view.php', ['uuid' => $uuid, 'userid' => $userid]);
$headeractions = html_writer::link(new moodle_url('/local/kbacompany/development_plan.php', ['uuid' => $uuid, 'userid' => $userid]),
    get_string('developmentplan_open', 'local_kbacompany'), ['class' => 'btn btn-primary']);
$meta = $data['position'] ? s($data['position']['name']) : get_string('employeecompetency_no_position', 'local_kbacompany');
echo bms_workspace_ux_service::record_header(
    s($data['employee']['full_name']),
    get_string('employeecompetency_intro', 'local_kbacompany'),
    get_string('employeecompetency_eyebrow', 'local_kbacompany'),
    $meta,
    $headeractions
);
$tabs = [
    ['label' => get_string('bms_employee_view', 'local_kbacompany'), 'url' => $back],
    ['label' => get_string('employeecompetency_title', 'local_kbacompany'), 'url' => $PAGE->url, 'active' => true],
    ['label' => get_string('assessment_open', 'local_kbacompany'), 'url' => new moodle_url('/local/kbacompany/competency_assessment.php', ['uuid'=>$uuid,'userid'=>$userid])],
    ['label' => get_string('competency360_open', 'local_kbacompany'), 'url' => new moodle_url('/local/kbacompany/competency_360.php', ['uuid'=>$uuid,'userid'=>$userid])],
    ['label' => get_string('developmentplan_open', 'local_kbacompany'), 'url' => new moodle_url('/local/kbacompany/development_plan.php', ['uuid'=>$uuid,'userid'=>$userid])],
    ['label' => get_string('competencyprogress_tab', 'local_kbacompany'), 'url' => new moodle_url('/local/kbacompany/competency_progress.php', ['uuid'=>$uuid,'userid'=>$userid])],
];
$tabhtml = '';
foreach ($tabs as $tab) {
    $class = 'bms-workspace-subnav-link' . (!empty($tab['active']) ? ' is-active' : '');
    $tabhtml .= html_writer::link($tab['url'], $tab['label'], ['class' => $class]);
}
echo html_writer::tag('nav', $tabhtml, ['class' => 'bms-workspace-subnav bms-employee-tabs',
    'aria-label' => get_string('competencyprogress_tabs', 'local_kbacompany')]);

if (!$data['position']) {
    echo $OUTPUT->notification(get_string('employeecompetency_no_position', 'local_kbacompany'), 'warning');
} else if (!$data['position_profile']) {
    echo $OUTPUT->notification(get_string('employeecompetency_no_published_profile', 'local_kbacompany'), 'warning');
} else {
    echo html_writer::start_div('row mb-3');
    foreach ([
        [get_string('employeecompetency_total', 'local_kbacompany'), $data['summary']['total']],
        [get_string('employeecompetency_met', 'local_kbacompany'), $data['summary']['met']],
        [get_string('employeecompetency_gap', 'local_kbacompany'), $data['summary']['gap']],
        [get_string('employeecompetency_unknown', 'local_kbacompany'), $data['summary']['unknown']],
    ] as $card) {
        echo html_writer::start_div('col-md-3 mb-2');
        echo html_writer::div(html_writer::tag('div', s($card[0]), ['class' => 'text-muted small']) .
            html_writer::tag('div', (string)$card[1], ['class' => 'h4 mb-0']), 'card card-body py-2 h-100');
        echo html_writer::end_div();
    }
    echo html_writer::end_div();

    echo html_writer::tag('p', get_string('employeecompetency_position_context', 'local_kbacompany',
        (object)['position' => $data['position']['name'], 'profile' => $data['position_profile']['stable_code'],
            'revision' => $data['position_profile']['revision']]), ['class' => 'small text-muted']);

    $table = new html_table();
    $table->attributes['class'] = 'table table-sm';
    $table->head = [get_string('name'), get_string('positioncompetency_target_level', 'local_kbacompany'),
        get_string('employeecompetency_current_level', 'local_kbacompany'), get_string('employeecompetency_gap', 'local_kbacompany'),
        get_string('employeecompetency_evidence', 'local_kbacompany'), get_string('actions')];
    foreach ($data['requirements'] as $row) {
        $statekey = 'employeecompetency_state_' . $row['state'];
        $gaplabel = get_string($statekey, 'local_kbacompany');
        $stateclass = $row['state'] === 'met' ? 'badge badge-success' : ($row['state'] === 'gap' ? 'badge badge-warning' : 'badge badge-secondary');
        $gaplabel = html_writer::span($gaplabel, $stateclass);
        $evidence = $row['evidence'] !== '' ? s($row['evidence']) : '—';
        if ($row['evidence_url'] !== '') {
            $evidence .= ' ' . html_writer::link($row['evidence_url'], get_string('employeecompetency_evidence_link', 'local_kbacompany'),
                ['target' => '_blank', 'rel' => 'noopener noreferrer']);
        }
        $actionhtml = '';
        if ($data['can_manage']) {
            $actionhtml = html_writer::tag('button', get_string('employeecompetency_record', 'local_kbacompany'), [
                'type' => 'button', 'class' => 'btn btn-sm btn-outline-primary',
                'data-toggle' => 'collapse', 'data-target' => '#empcomp-' . (int)$row['competency_id'],
            ]);
        }
        $table->data[] = [format_string($row['name']), $row['target_level'] === null ? '—' : (int)$row['target_level'],
            $row['current_level'] === null ? '—' : (int)$row['current_level'], $gaplabel, $evidence, $actionhtml];
        if ($data['can_manage']) {
            $form = html_writer::start_tag('form', ['method' => 'post', 'class' => 'p-3 bg-light']);
            foreach ([
                'uuid' => $uuid, 'userid' => $userid, 'competencyid' => $row['competency_id'],
                'action' => 'save', 'sesskey' => sesskey(),
            ] as $name => $value) {
                $form .= html_writer::empty_tag('input', ['type' => 'hidden', 'name' => $name, 'value' => $value]);
            }
            $form .= html_writer::tag('label', get_string('employeecompetency_current_level', 'local_kbacompany'), ['class' => 'small font-weight-bold']);
            $form .= html_writer::empty_tag('input', ['type' => 'number', 'name' => 'currentlevel', 'min' => 0, 'max' => 100,
                'value' => $row['current_level'] === null ? 0 : $row['current_level'], 'class' => 'form-control form-control-sm mb-2']);
            $form .= html_writer::tag('label', get_string('employeecompetency_source', 'local_kbacompany'), ['class' => 'small font-weight-bold']);
            $options = '';
            foreach (['manager_observation', 'manual', 'self_reported', 'moodle', 'assessment'] as $source) {
                $options .= html_writer::tag('option', get_string('employeecompetency_source_' . $source, 'local_kbacompany'),
                    ['value' => $source, 'selected' => $row['source'] === $source ? 'selected' : null]);
            }
            $form .= html_writer::tag('select', $options, ['name' => 'sourcekind', 'class' => 'form-control form-control-sm mb-2']);
            $form .= html_writer::tag('label', get_string('employeecompetency_evidence', 'local_kbacompany'), ['class' => 'small font-weight-bold']);
            $form .= html_writer::tag('textarea', s($row['evidence']), ['name' => 'evidence', 'class' => 'form-control form-control-sm mb-2', 'rows' => 2]);
            $form .= html_writer::tag('label', get_string('employeecompetency_evidence_url', 'local_kbacompany'), ['class' => 'small font-weight-bold']);
            $form .= html_writer::empty_tag('input', ['type' => 'url', 'name' => 'evidenceurl', 'value' => $row['evidence_url'], 'class' => 'form-control form-control-sm mb-2']);
            $form .= html_writer::tag('button', get_string('savechanges'), ['type' => 'submit', 'class' => 'btn btn-sm btn-primary']);
            $form .= html_writer::end_tag('form');
            $table->data[] = [html_writer::div($form, 'collapse', ['id' => 'empcomp-' . (int)$row['competency_id']]), '', '', '', '', ''];
        }
    }
    echo html_writer::div(html_writer::table($table), 'table-responsive');
    echo html_writer::tag('p', get_string('employeecompetency_assessment_available', 'local_kbacompany'), ['class' => 'alert alert-info small']);
}

\local_kbacompany\local\bms_page_shell_service::finish($shell);
