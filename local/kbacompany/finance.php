<?php
// FIN-W2 "Finance" (Финансы) workspace: read-only overview over existing financial model, statements, budgets,
// plan-fact and resource gates. Off unless the site switch is on; access is the existing financial gate.
require_once(__DIR__ . '/../../config.php');

use local_kbacompany\local\bms_page_shell_service;
use local_kbacompany\local\finance_workspace_service;
use local_kbacompany\local\finance_workspace_ui_service;

require_login();
$context = context_system::instance();
require_capability('local/kbacompany:viewowncompany', $context);
$uuid = required_param('uuid', PARAM_RAW_TRIMMED);
$actor = (int)$USER->id;
// Switch off: the route does not exist (nothing is computed). Membership and the financial gate are checked in overview().
finance_workspace_service::require_enabled();
$model = finance_workspace_service::overview($uuid, $actor);

$PAGE->set_context($context);
$PAGE->set_url(finance_workspace_service::url($uuid));
$PAGE->set_title(get_string('financews_title', 'local_kbacompany'));
$PAGE->set_heading(get_string('pluginname', 'local_kbacompany'));
$PAGE->requires->css(new moodle_url('/local/kbacompany/bms_ui/finance_workspace.css'));
$shell = bms_page_shell_service::begin($uuid, $actor, 'financeworkspace', get_string('financews_title', 'local_kbacompany'));
echo finance_workspace_ui_service::render($model);
bms_page_shell_service::finish($shell);
