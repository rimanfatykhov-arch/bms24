<?php
// Internal controller shared by the existing Create / Edit / Exclude routes.
if (!defined('BMS_EMPLOYEE_FORM')) { http_response_code(404); exit; }
require_once(__DIR__ . '/../../config.php');
require_once(__DIR__ . '/lib.php');

use local_kbacompany\local\company_context_service;
use local_kbacompany\local\permission_service;
use local_kbacompany\local\employee_service;
use local_kbacompany\local\employee_ui_service as ui;
use local_kbacompany\local\document_request_service as requests;
use local_kbacompany\local\bms_page_shell_service;

require_login();
$context = context_system::instance();
require_capability('local/kbacompany:viewowncompany', $context);
$uuid = company_context_service::normalise_uuid(required_param('uuid', PARAM_RAW_TRIMMED));
company_context_service::context($uuid, (int)$USER->id);
if (!permission_service::can_manage_organization($uuid, (int)$USER->id) && !is_siteadmin()) {
    throw new required_capability_exception($context, 'local/kbacompany:manageorganization', 'nopermissions', '');
}
$operation = BMS_EMPLOYEE_FORM;
$userid = $operation === 'create' ? 0 : required_param('userid', PARAM_INT);
$membership = $operation === 'create' ? '' : optional_param('membership', '', PARAM_RAW_TRIMMED);
$model = $userid ? employee_service::directory_record($uuid, $userid, (int)$USER->id, $membership) : null;
if ($model) { $membership = $model['employee']['membership_uuid']; }
$identity = $userid ? 'employee:' . $userid : '';
$post = ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST';
$values = $model ? ['firstname' => $model['employee']['firstname'], 'lastname' => $model['employee']['lastname'],
    'email' => $model['employee']['email'], 'phone' => $model['employee']['raw_phone']] : ['firstname' => '', 'lastname' => '', 'email' => '', 'phone' => ''];
$errors = []; $proof = [];
if ($post) {
    require_sesskey();
    foreach (array_keys($values) as $field) { $values[$field] = optional_param($field, '', PARAM_TEXT); }
    if ($operation === 'close') { $values = ['confirm' => optional_param('confirm', 0, PARAM_BOOL)]; }
    $key = required_param('requestkey', PARAM_ALPHANUM);
    // Invalid/reused proof is fail-closed; a fresh form is required instead of guessing intent.
    $request = requests::verify($key, $uuid, (int)$USER->id, $identity, 0, 'employee_' . $operation, $membership);
    $digest = requests::digest($values);
    $destination = requests::replay($request, $digest);
    if ($destination !== null) { redirect(ui::url($uuid, 'employee_view', $destination)); }
    if ($operation !== 'close') { $errors = employee_service::directory_form_errors($values); }
    if ($operation === 'close' && empty($values['confirm'])) { $errors['_confirm'] = ui::tr('ecu_confirm_required'); }
    if (!$errors) {
        try {
            $saved = employee_service::directory_save($uuid, (int)$USER->id, $operation, $userid, $membership, $values, $request['expected']);
            $destination = ['userid' => (int)$saved['userid'], 'membership' => (string)$saved['membership_uuid']];
            requests::complete($key, $digest, $destination);
        } catch (moodle_exception $e) {
            // Known domain validation messages only; SQL/debug text is not rendered as form feedback.
            $allowed = ['ecu_stale', 'ecu_busy', 'ecu_account_unavailable', 'ecu_check_form', 'ecu_confirm_required',
                'bms_employee_invalid_email', 'bms_employee_invalid_phone', 'bms_employee_name_required', 'bms_employee_email_in_use'];
            if (!in_array($e->errorcode, $allowed, true)) { throw $e; }
            $field = in_array($e->errorcode, ['ecu_account_unavailable', 'bms_employee_email_in_use', 'bms_employee_invalid_email'], true) ? 'email' : '_domain';
            $errors[$field] = ui::tr($e->errorcode);
        }
        if ($destination !== null) { redirect(ui::url($uuid, 'employee_view', $destination),
            ui::tr($operation === 'close' ? 'ecu_closed' : (!empty($saved['existing']) ? 'ecu_already_member' : 'ecu_saved')),
            null, \core\output\notification::NOTIFY_SUCCESS); }
    }
    $proof = ['requestkey' => $key];
} else {
    if ($model && !$model['editable']) { throw new required_capability_exception($context, 'local/kbacompany:manageorganization', 'nopermissions', ''); }
    $proof = requests::issue($uuid, (int)$USER->id, $identity, 0, 'employee_' . $operation, $model['expected'] ?? '', $membership);
}
$route = $operation === 'create' ? 'employee_create' : ($operation === 'close' ? 'employee_delete' : 'employee_edit');
$PAGE->set_context($context);
$PAGE->set_url(ui::url($uuid, $route, $model ? ui::record_params($model['employee']) : []));
$PAGE->set_title(ui::tr('bms_employees_title'));
$PAGE->set_heading(ui::tr('pluginname'));
$shell = bms_page_shell_service::begin($uuid, (int)$USER->id, 'employees', ui::tr('bms_employees_title'));
echo html_writer::div(ui::form($uuid, $operation, $values, $errors, $proof, $model), 'bms-ecu');
// Employee editable fields contain only identity/contact data. Keep AI draft separate.
\local_kbacompany\local\bms_ai_workspace_service::render($uuid, 'employee', 'Сотрудники');
bms_page_shell_service::finish($shell);
