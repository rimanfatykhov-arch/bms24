<?php
require_once(__DIR__ . '/../../config.php');
require_once(__DIR__ . '/lib.php');

use local_kbacompany\local\company_context_service;
use local_kbacompany\local\employee_service;
use local_kbacompany\local\employee_ui_service as ui;
use local_kbacompany\local\employee_workspace_service;
use local_kbacompany\local\bms_page_shell_service;

require_login();
$context = context_system::instance();
require_capability('local/kbacompany:viewowncompany', $context);
$uuid = company_context_service::normalise_uuid(required_param('uuid', PARAM_RAW_TRIMMED));
$userid = required_param('userid', PARAM_INT);
$membership = optional_param('membership', '', PARAM_RAW_TRIMMED);
$model = employee_service::directory_record($uuid, $userid, (int)$USER->id, $membership);
$tab = optional_param('tab', 'overview', PARAM_ALPHA);
if ($tab === 'organization') { $tab = 'assignments'; } // Backward-compatible old Employee card URL.
$tabs = ['overview', 'assignments', 'metrics', 'battleplan', 'tasks', 'calendar', 'competencies', 'learning', 'development', 'documents', 'history', 'integration'];
if (!in_array($tab, $tabs, true)) { $tab = 'overview'; }
if ($tab === 'integration' && !$model['can_manage']) {
    throw new required_capability_exception($context, 'local/kbacompany:manageorganization', 'nopermissions', '');
}
$extra = employee_workspace_service::tab_context($uuid, $userid, (int)$USER->id, $tab, $model['employee']);
if ($tab === 'history' && !empty($extra['can_inspect'])) { $extra['membership_history'] = employee_service::directory_history($uuid, $userid, (int)$USER->id, optional_param('page', 0, PARAM_INT)); }
if ($tab === 'integration') { $extra['integration'] = employee_service::directory_integration($uuid, $userid, (int)$USER->id); }
if ($tab === 'calendar') {
    // Links to the ACL-guarded work calendar; personal Moodle events remain private.
    $extra['calendar_work_available'] = \local_kbacompany\local\employee_work_calendar_service::allowed(
        $uuid, (int)$USER->id, $userid, 'view') ||
        \local_kbacompany\local\employee_work_calendar_service::allowed(
            $uuid, (int)$USER->id, $userid, 'edit') ||
        \local_kbacompany\local\employee_work_calendar_service::allowed(
            $uuid, (int)$USER->id, $userid, 'comment') ||
        \local_kbacompany\local\employee_work_calendar_service::allowed(
            $uuid, (int)$USER->id, $userid, 'grant');
    $extra['work_calendar_url'] = (new moodle_url('/local/kbacompany/employee_work_calendar.php',
        ['uuid'=>$uuid, 'userid'=>$userid]))->out(false);
}
if ($tab === 'calendar' && $userid === (int)$USER->id && !empty($extra['can_inspect'])) {
    $extra['calendar'] = \local_kbacompany\local\calendar_workspace_service::today_snapshot($uuid, (int)$USER->id);
}

$PAGE->set_context($context);
$PAGE->set_url(ui::url($uuid, 'employee_view', ui::record_params($model['employee']) + ['tab' => $tab]));
$PAGE->set_title(ui::tr('bms_employee_view_title', $model['employee']['full_name']));
$PAGE->set_heading(ui::tr('pluginname'));
$shell = bms_page_shell_service::begin($uuid, (int)$USER->id, 'employees', ui::tr('ecu_employee_card'));
echo html_writer::div(ui::detail($uuid, $model, $tab, $extra), 'bms-ecu');
bms_page_shell_service::finish($shell);
