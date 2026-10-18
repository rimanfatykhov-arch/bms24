<?php

require_once(__DIR__ . '/../../config.php');
require_once(__DIR__ . '/lib.php');

use local_kbacompany\local\organizing_board_service;

require_login();
$context = context_system::instance();
require_capability('local/kbacompany:viewowncompany', $context);
$uuid = required_param('uuid', PARAM_RAW_TRIMMED);
$branchuuid = optional_param('branch', '', PARAM_RAW_TRIMMED);

if (optional_param('installtemplate', 0, PARAM_BOOL)) {
    require_sesskey();
    require_capability('local/kbacompany:manageorganization', $context);
    $board = organizing_board_service::install_seven_division_template($uuid, (int)$USER->id);
    redirect(new moodle_url('/local/kbacompany/organizing_board.php', [
        'uuid' => $uuid, 'branch' => $board['selected_branch_uuid'],
    ]), get_string('templateinstalled', 'local_kbacompany'));
}

$board = organizing_board_service::board_context($uuid, (int)$USER->id, $branchuuid);
$company = $board['company'];
$PAGE->set_context($context);
$PAGE->set_url(new moodle_url('/local/kbacompany/organizing_board.php', ['uuid' => $uuid]));
// BMS page shell adapter selects the presentation layout.
$PAGE->set_title(get_string('organizingboardtitle', 'local_kbacompany'));
$PAGE->set_heading(get_string('pluginname', 'local_kbacompany'));

$bmsshell = \local_kbacompany\local\bms_page_shell_service::begin($uuid, (int)$USER->id, 'organization', get_string('organizingboardtitle', 'local_kbacompany'));
echo html_writer::div(html_writer::link(
    new moodle_url('/local/kbacompany/bms_workspace.php', ['uuid' => $uuid]),
    '← ' . get_string('backtocompany', 'local_kbacompany')
), 'mb-3');
echo local_kbacompany_organization_tabs($uuid, 'board',
    \local_kbacompany\local\product_service::can_manage($uuid, (int)$USER->id));
echo $OUTPUT->heading(get_string('organizingboardfor', 'local_kbacompany', format_string($company['name'])));
echo html_writer::tag('p', get_string('organizingboardintro', 'local_kbacompany'), ['class' => 'lead']);
echo html_writer::div(html_writer::link(new moodle_url('/local/kbacompany/ofs.php', [
    'uuid' => $uuid, 'branch' => $board['selected_branch_uuid'],
]), get_string('ofs_openproductview', 'local_kbacompany'), ['class' => 'btn btn-outline-secondary']), 'mb-3');
echo html_writer::div(html_writer::link(new moodle_url('/local/kbacompany/ofs_designer.php', ['uuid' => $uuid]),
    get_string('ofsdesigner_open', 'local_kbacompany'), ['class' => 'btn btn-primary']), 'mb-3');

if (count($board['branches']) > 1) {
    $options = [];
    foreach ($board['branches'] as $branch) {
        $options[$branch['uuid']] = format_string($branch['name']);
    }
    $select = new single_select(new moodle_url('/local/kbacompany/organizing_board.php', ['uuid' => $uuid]),
        'branch', $options, $board['selected_branch_uuid'], null);
    $select->label = get_string('branch', 'local_kbacompany');
    echo $OUTPUT->render($select);
}

if (!$board['nodes']) {
    echo $OUTPUT->notification(get_string('emptyorganizingboard', 'local_kbacompany'), 'info');
    if ($board['can_manage'] && has_capability('local/kbacompany:manageorganization', $context)) {
        $url = new moodle_url('/local/kbacompany/organizing_board.php', [
            'uuid' => $uuid, 'installtemplate' => 1, 'sesskey' => sesskey(),
        ]);
        echo html_writer::link($url, get_string('installsevendivisiontemplate', 'local_kbacompany'), [
            'class' => 'btn btn-primary',
            'data-confirmation' => 'modal',
            'data-confirmation-title-str' => json_encode(['confirm', 'moodle']),
            'data-confirmation-content-str' => json_encode(['confirmtemplateinstall', 'local_kbacompany']),
            'data-confirmation-yes-button-str' => json_encode(['continue', 'moodle']),
            'data-confirmation-destination' => $url->out(false),
        ]);
    }
} else {
    $nodenames = array_column($board['nodes'], 'name', 'uuid');
    echo html_writer::start_div('kbacompany-board');
    foreach ($board['nodes'] as $node) {
        echo html_writer::start_div('kbacompany-board-node', ['style' => '--kba-node-color:' . s($node['color'])]);
        echo html_writer::div(s($node['code']), 'kbacompany-board-code');
        echo html_writer::tag('h3', format_string($node['name']), ['class' => 'kbacompany-board-name']);
        echo html_writer::div(get_string('nodetype' . $node['type'], 'local_kbacompany'), 'kbacompany-board-type');
        if ($node['reporting_parent_uuid'] !== '') {
            echo html_writer::div(get_string('reportingline', 'local_kbacompany',
                s($nodenames[$node['reporting_parent_uuid']] ?? '—')), 'kbacompany-board-type');
        }
        echo html_writer::div(html_writer::link(new moodle_url('/local/kbacompany/positions.php', [
            'uuid' => $uuid, 'node' => $node['uuid'],
        ]), get_string('openpositions', 'local_kbacompany')), 'mt-3');
        echo html_writer::end_div();
    }
    echo html_writer::end_div();
    echo $OUTPUT->notification(get_string('boardfoundationnotice', 'local_kbacompany'), 'info');
}
echo html_writer::div(html_writer::link(new moodle_url('/local/kbacompany/indicators.php', ['uuid' => $uuid]),
    get_string('productsstats', 'local_kbacompany'), ['class' => 'btn btn-secondary']), 'mt-3');
\local_kbacompany\local\bms_page_shell_service::finish($bmsshell);
