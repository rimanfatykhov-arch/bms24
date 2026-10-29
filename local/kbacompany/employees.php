<?php
require_once(__DIR__ . '/../../config.php');
require_once(__DIR__ . '/lib.php');

use local_kbacompany\local\company_context_service;
use local_kbacompany\local\employee_service;
use local_kbacompany\local\employee_ui_service as ui;
use local_kbacompany\local\bms_page_shell_service;

require_login();
$context = context_system::instance();
require_capability('local/kbacompany:viewowncompany', $context);
$uuid = company_context_service::normalise_uuid(required_param('uuid', PARAM_RAW_TRIMMED));
$filters = ['q' => optional_param('q', '', PARAM_TEXT), 'status' => optional_param('status', 'active', PARAM_ALPHA),
    'placement' => optional_param('placement', '', PARAM_ALPHA)];
$model = employee_service::browse_directory($uuid, (int)$USER->id, $filters, optional_param('page', 0, PARAM_INT));
$PAGE->set_context($context);
$PAGE->set_url(ui::url($uuid, 'employees', $model['filters'] + ['page' => $model['page']]));
$PAGE->set_title(ui::tr('bms_employees_title'));
$PAGE->set_heading(ui::tr('pluginname'));
$shell = bms_page_shell_service::begin($uuid, (int)$USER->id, 'employees', ui::tr('bms_employees_title'));
echo html_writer::div(ui::listing($uuid, $model), 'bms-ecu');
\local_kbacompany\local\bms_ai_workspace_service::render($uuid, 'employee', 'Сотрудники');
bms_page_shell_service::finish($shell);
