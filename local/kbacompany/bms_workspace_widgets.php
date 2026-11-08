<?php
// Manage personal BMS24 workspace cards; all writes require POST and sesskey.
require_once(__DIR__ . '/../../config.php');

use local_kbacompany\local\bms_ui_service;
use local_kbacompany\local\bms_page_shell_service;
use local_kbacompany\local\bms_workspace_widget_service as widgets;

require_login();
$context = context_system::instance();
require_capability('local/kbacompany:viewowncompany', $context);
$uuid = required_param('uuid', PARAM_RAW_TRIMMED);
$userid = (int)$USER->id;
// Membership check before preference reads; no dashboard aggregation on settings screen.
\local_kbacompany\local\company_context_service::context($uuid, $userid);
$available = widgets::available(
    \local_kbacompany\local\permission_service::can_manage_management($uuid, $userid));
$current = widgets::current($uuid, $userid, $available);
$PAGE->set_context($context);
$PAGE->set_url(new moodle_url('/local/kbacompany/bms_workspace_widgets.php', ['uuid' => $uuid]));
$PAGE->set_title(get_string('bmswidgets_title', 'local_kbacompany'));
$PAGE->set_heading(get_string('bmswidgets_title', 'local_kbacompany'));
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
if (!in_array($method, ['GET', 'HEAD', 'POST'], true)) {
    http_response_code(405);
    throw new moodle_exception('invalidparameter');
}
if ($method === 'POST') {
    require_sesskey();
    $action = required_param('action', PARAM_ALPHA);
    if ($action === 'save') {
        $positions = optional_param_array('positions', [], PARAM_INT);
        $selected = optional_param_array('visible', [], PARAM_ALPHANUMEXT);
        $newlayout = widgets::prepare($positions, $selected, $available, $current);
        widgets::save($uuid, $userid, $newlayout, $available);
    } else if ($action === 'reset') {
        widgets::reset($uuid, $userid);
    } else {
        throw new moodle_exception('invalidparameter');
    }
    redirect(new moodle_url('/local/kbacompany/bms_workspace.php', ['uuid' => $uuid]),
        get_string('bmswidgets_saved', 'local_kbacompany'));
}
$state = bms_page_shell_service::begin($uuid, $userid, 'workspace', get_string('bmswidgets_title', 'local_kbacompany'));
$actionurl = new moodle_url('/local/kbacompany/bms_workspace_widgets.php', ['uuid' => $uuid]);
$backurl = new moodle_url('/local/kbacompany/bms_workspace.php', ['uuid' => $uuid]);
echo html_writer::tag('h1', get_string('bmswidgets_title', 'local_kbacompany'), ['class' => 'mb-2']);
echo html_writer::tag('p', get_string('bmswidgets_intro', 'local_kbacompany'), ['class' => 'text-muted']);
echo html_writer::start_tag('form', ['method' => 'post', 'action' => $actionurl->out(false), 'class' => 'bms-widget-preferences']);
echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'sesskey', 'value' => sesskey()]);
echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'action', 'value' => 'save']);
echo html_writer::start_tag('ol', ['class' => 'bms-widget-settings-list', 'id' => 'bms-widget-settings-list']);
foreach ($current['order'] as $index => $key) {
    $id = 'bms-widget-' . $key;
    $checked = in_array($key, $current['visible'], true);
    $toggle = html_writer::empty_tag('input', ['type' => 'checkbox', 'id' => $id, 'name' => 'visible[]',
        'value' => $key, 'checked' => $checked ? 'checked' : null]);
    $position = html_writer::start_tag('select', ['name' => 'positions[' . $key . ']',
        'class' => 'custom-select bms-widget-position',
        'aria-label' => get_string('bmswidgets_position', 'local_kbacompany') . ': ' . get_string('bmswidgets_name_' . $key, 'local_kbacompany')]);
    for ($i = 1; $i <= count($available); $i++) {
        $position .= html_writer::tag('option', (string)$i, ['value' => $i,
            'selected' => ($index + 1 === $i) ? 'selected' : null]);
    }
    $position .= html_writer::end_tag('select');
    $buttons = html_writer::tag('button', '↑', ['type' => 'button', 'class' => 'bms-widget-move-up',
        'aria-label' => get_string('bmswidgets_up', 'local_kbacompany')]) .
        html_writer::tag('button', '↓', ['type' => 'button', 'class' => 'bms-widget-move-down',
            'aria-label' => get_string('bmswidgets_down', 'local_kbacompany')]);
    echo html_writer::tag('li',
        html_writer::tag('label', $toggle . ' ' . s(get_string('bmswidgets_name_' . $key, 'local_kbacompany')),
            ['for' => $id, 'class' => 'bms-widget-label']) .
        html_writer::div($buttons, 'bms-widget-order-buttons') . $position,
        ['class' => 'bms-widget-settings-item', 'data-widget-id' => $key]);
}
echo html_writer::end_tag('ol');
echo html_writer::start_div('bms24-ui-actions');
echo html_writer::tag('button', get_string('bmswidgets_save', 'local_kbacompany'), ['type' => 'submit',
    'class' => 'bms24-ui-btn bms24-ui-btn--primary']);
echo html_writer::link($backurl, get_string('bmsmain_cancel', 'local_kbacompany'),
    ['class' => 'bms24-ui-btn']);
echo html_writer::end_div();
echo html_writer::end_tag('form');
echo html_writer::start_tag('form', ['method' => 'post', 'action' => $actionurl->out(false), 'class' => 'bms-widget-reset-form mt-3']);
echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'sesskey', 'value' => sesskey()]);
echo html_writer::tag('button', get_string('bmswidgets_reset', 'local_kbacompany'),
    ['type' => 'submit', 'name' => 'action', 'value' => 'reset', 'class' => 'bms24-ui-btn']);
echo html_writer::end_tag('form');
$PAGE->requires->js(new moodle_url('/local/kbacompany/js/bms_workspace_widgets.js'));
bms_page_shell_service::finish($state);
