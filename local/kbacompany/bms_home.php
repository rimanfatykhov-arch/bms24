<?php
// Safe, company-scoped starting point. Explicit page URLs are not redirected.
require_once(__DIR__ . '/../../config.php');

use local_kbacompany\local\bms_ui_service;
use local_kbacompany\local\bms_main_tool_service;

require_login();
$context = context_system::instance();
require_capability('local/kbacompany:viewowncompany', $context);
$uuid = required_param('uuid', PARAM_RAW_TRIMMED);

// Moodle must know the page context before shell_model inspects the active theme.
// A debugging notice here would produce output and break the redirect.
$PAGE->set_context($context);
$PAGE->set_url(new moodle_url('/local/kbacompany/bms_home.php', ['uuid' => $uuid]));

// shell_model validates company membership and filters navigation by permissions.
$model = bms_ui_service::shell_model($uuid, (int)$USER->id, 'workspace');
$url = bms_main_tool_service::main_url($uuid, $model['navigation_groups'], (int)$USER->id);
redirect(new moodle_url($url));
