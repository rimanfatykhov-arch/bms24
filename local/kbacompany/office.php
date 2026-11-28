<?php
// DOC-W2 "Office" (Канцелярия) workspace: read-only overview over existing canonical documents,
// approvals, acknowledgements, meeting decisions and decision tasks. Off unless the site switch is on.
require_once(__DIR__ . '/../../config.php');

use local_kbacompany\local\bms_page_shell_service;
use local_kbacompany\local\office_workspace_service;
use local_kbacompany\local\office_workspace_ui_service;

require_login();
$context = context_system::instance();
require_capability('local/kbacompany:viewowncompany', $context);
$uuid = required_param('uuid', PARAM_RAW_TRIMMED);
$actor = (int)$USER->id;
// Switch off: the route does not exist (no counts are computed). Membership is checked inside overview().
office_workspace_service::require_enabled();
$model = office_workspace_service::overview($uuid, $actor);

$PAGE->set_context($context);
$PAGE->set_url(office_workspace_service::url($uuid));
$PAGE->set_title(get_string('officews_title', 'local_kbacompany'));
$PAGE->set_heading(get_string('pluginname', 'local_kbacompany'));
$PAGE->requires->css(new moodle_url('/local/kbacompany/bms_ui/office_workspace.css'));
$shell = bms_page_shell_service::begin($uuid, $actor, 'office', get_string('officews_title', 'local_kbacompany'));
echo office_workspace_ui_service::render($model);
bms_page_shell_service::finish($shell);
