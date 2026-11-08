<?php
// User-owned BMS24 navigation preferences; POST is the only mutation channel.
require_once(__DIR__ . '/../../config.php');

use local_kbacompany\local\bms_main_tool_service as main_tools;
use local_kbacompany\local\bms_page_shell_service;
use local_kbacompany\local\bms_ui_service;

require_login();
$context = context_system::instance();
require_capability('local/kbacompany:viewowncompany', $context);
$uuid = required_param('uuid', PARAM_RAW_TRIMMED);
$userid = (int)$USER->id;
// shell_model validates company membership and provides exactly the available sidebar routes.
$model = bms_ui_service::shell_model($uuid, $userid, 'workspace');
$groups = $model['navigation_groups'];
$options = main_tools::main_options($groups);
$routes = main_tools::visible_routes($groups);

$PAGE->set_context($context);
$PAGE->set_url(new moodle_url('/local/kbacompany/bms_home_preferences.php', ['uuid' => $uuid]));
$PAGE->set_title(get_string('bmsmain_page_title', 'local_kbacompany'));
$PAGE->set_heading(get_string('bmsmain_page_title', 'local_kbacompany'));
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
if (!in_array($method, ['GET', 'HEAD', 'POST'], true)) {
    http_response_code(405);
    throw new moodle_exception('invalidparameter');
}
if ($method === 'POST') {
    require_sesskey();
    $action = required_param('action', PARAM_ALPHA);
    if ($action === 'save') {
        $main = required_param('main', PARAM_ALPHANUMEXT);
        $favorites = optional_param_array('favorites', [], PARAM_ALPHANUMEXT);
        main_tools::save($uuid, $userid, $groups, $main, $favorites);
    } else if ($action === 'later') {
        main_tools::dismiss($uuid, $userid);
    } else {
        throw new moodle_exception('invalidparameter');
    }
    // A fixed internal destination. Never accept returnurl from POST or GET.
    redirect(new moodle_url('/local/kbacompany/bms_workspace.php', ['uuid' => $uuid]),
        get_string('bmsmain_saved', 'local_kbacompany'));
}

$state = bms_page_shell_service::begin($uuid, $userid, 'workspace', get_string('bmsmain_page_title', 'local_kbacompany'));
$current = main_tools::current($uuid, $userid, $groups);
$actionurl = new moodle_url('/local/kbacompany/bms_home_preferences.php', ['uuid' => $uuid]);
echo html_writer::tag('h1', get_string('bmsmain_page_title', 'local_kbacompany'), ['class' => 'mb-2']);
echo html_writer::tag('p', get_string('bmsmain_page_intro', 'local_kbacompany'), ['class' => 'text-muted mb-3']);
echo html_writer::start_tag('form', ['method' => 'post', 'action' => $actionurl->out(false),
    'class' => 'bms-main-preferences']);
echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'sesskey', 'value' => sesskey()]);
echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'action', 'value' => 'save']);
echo html_writer::start_div('bms-main-tool-grid mb-4');
foreach ($options as $key => $item) {
    $id = 'bms-main-' . $key;
    $input = html_writer::empty_tag('input', ['type' => 'radio', 'name' => 'main', 'id' => $id,
        'value' => $key, 'checked' => ($current['main'] === $key) ? 'checked' : null,
        'required' => 'required']);
    $label = html_writer::tag('strong', s($item['label'])) . html_writer::tag('span',
        get_string('bmsmain_desc_' . $key, 'local_kbacompany'), ['class' => 'bms-main-option-description']);
    echo html_writer::tag('label', $input . html_writer::div($label, 'bms-main-option-text'),
        ['class' => 'bms-main-option', 'for' => $id]);
}
echo html_writer::end_div();
echo html_writer::tag('h2', get_string('bmsmain_favorites_title', 'local_kbacompany'), ['class' => 'h5']);
echo html_writer::tag('p', get_string('bmsmain_favorites_hint', 'local_kbacompany'), ['class' => 'text-muted']);
echo html_writer::start_div('bms-main-favorite-list mb-4');
foreach ($routes as $key => $item) {
    $id = 'bms-fav-' . $key;
    $input = html_writer::empty_tag('input', ['type' => 'checkbox', 'name' => 'favorites[]', 'id' => $id,
        'value' => $key, 'checked' => in_array($key, $current['favorites'], true) ? 'checked' : null]);
    echo html_writer::tag('label', $input . ' ' . s($item['label']), ['for' => $id, 'class' => 'bms-main-favorite']);
}
echo html_writer::end_div();
echo html_writer::start_div('bms24-ui-actions');
echo html_writer::tag('button', get_string('bmsmain_save_button', 'local_kbacompany'),
    ['type' => 'submit', 'class' => 'bms24-ui-btn bms24-ui-btn--primary']);
echo html_writer::link(new moodle_url('/local/kbacompany/bms_workspace.php', ['uuid' => $uuid]),
    get_string('bmsmain_cancel', 'local_kbacompany'), ['class' => 'bms24-ui-btn']);
echo html_writer::end_div();
echo html_writer::end_tag('form');
bms_page_shell_service::finish($state);
