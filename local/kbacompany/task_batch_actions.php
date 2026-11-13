<?php
/** build1842: POST-only task batch commands; never an HTTP GET mutation. */
require_once(__DIR__ . '/../../config.php');

use local_kbacompany\local\company_context_service;
use local_kbacompany\local\task_batch_actions_service as batch;
use local_kbacompany\local\task_service;
use local_kbacompany\local\task_ui_service as ui;

require_login();
require_capability('local/kbacompany:viewowncompany', context_system::instance());
if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    throw new moodle_exception('taskcenter_post', 'local_kbacompany');
}
require_sesskey();
$company = required_param('uuid', PARAM_RAW_TRIMMED);
$actor = (int)$USER->id;
company_context_service::context($company, $actor);
if (!required_param('confirmed', PARAM_BOOL)) {
    throw new moodle_exception('tui_confirm_required', 'local_kbacompany');
}
$requestkey = required_param('requestkey', PARAM_ALPHANUM);
if (!preg_match('/^[a-f0-9]{32}$/D', $requestkey)) {
    throw new moodle_exception('tui_expired_form', 'local_kbacompany');
}
$operation = required_param('operation', PARAM_ALPHA);
$selection = batch::selection(optional_param_array('selected', [], PARAM_INT));
ksort($selection);
$duedate = optional_param('duedate', '', PARAM_RAW_TRIMMED);
$assignee = optional_param('assigneeid', 0, PARAM_INT);
$fingerprint = hash('sha256', json_encode([$company, $actor, $selection, $operation, $duedate, $assignee]));
$key = $company . ':' . $actor . ':' . $requestkey;
$receipts = $SESSION->bms_task1842_receipts ?? [];
foreach ($receipts as $k => $record) {
    if ((int)$record['time'] < time() - 7200) { unset($receipts[$k]); }
}
if (isset($receipts[$key])) {
    if ($receipts[$key]['fingerprint'] !== $fingerprint) {
        throw new moodle_exception('tui_expired_form', 'local_kbacompany');
    }
    $result = $receipts[$key]['result'];
} else {
    $result = batch::run($company, $actor, $selection, $operation, $duedate, $assignee);
    $receipts[$key] = ['fingerprint'=>$fingerprint, 'result'=>$result, 'time'=>time()];
    $SESSION->bms_task1842_receipts = array_slice($receipts, -40, null, true);
}
$SESSION->bms_task1842_result = ['company'=>$company, 'actor'=>$actor,
    'data'=>$result, 'time'=>time()];
$manager = task_service::can_manage($company, $actor);
$params = ['scope'=>task_service::normalise_scope(optional_param('scope', 'roles', PARAM_ALPHA), $manager),
    'display'=>'list'];
foreach (['q'=>PARAM_RAW_TRIMMED, 'status'=>PARAM_ALPHA, 'stream'=>PARAM_ALPHA,
    'sourcekind'=>PARAM_ALPHA, 'battleplan'=>PARAM_RAW_TRIMMED, 'signal'=>PARAM_ALPHA,
    'project'=>PARAM_RAW_TRIMMED, 'sort'=>PARAM_ALPHA, 'dir'=>PARAM_ALPHA] as $name=>$type) {
    $value = optional_param($name, '', $type);
    if ($value !== '') { $params[$name] = $value; }
}
$filterassignee = optional_param('filterassignee', 0, PARAM_INT);
if ($filterassignee > 0) { $params['assignee'] = $filterassignee; }
redirect(ui::url($company, $params));
