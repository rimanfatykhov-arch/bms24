<?php
// Always return a JSON envelope, including expected authentication/validation failures.
define('AJAX_SCRIPT', true);
require_once(__DIR__ . '/../../config.php');

use local_kbacompany\local\bms_ai_bridge_service;

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
try {
    require_login();
    require_capability('local/kbacompany:viewowncompany', context_system::instance());
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        throw new invalid_parameter_exception('POST required.');
    }
    require_sesskey();
    $companyuuid = required_param('uuid', PARAM_RAW_TRIMMED);
    $domain = required_param('domain', PARAM_ALPHANUMEXT);
    $field = required_param('field', PARAM_ALPHANUMEXT);
    $operation = required_param('operation', PARAM_ALPHANUMEXT);
    $recorduuid = optional_param('record', '', PARAM_RAW_TRIMMED);
    $revision = optional_param('revision', 0, PARAM_INT);
    $draftvalue = optional_param('draftvalue', '', PARAM_RAW);
    $section = optional_param('section', '', PARAM_ALPHANUMEXT);
    if (core_text::strlen($draftvalue) > 20000) {
        throw new invalid_parameter_exception('Draft value is too long.');
    }
    core\session\manager::write_close();
    $proposal = bms_ai_bridge_service::assist($companyuuid, (int)$USER->id, $domain, $field,
        $operation, $recorduuid, $revision, $draftvalue, $section);
    echo json_encode(['ok' => true, 'proposal' => $proposal], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
} catch (Throwable $e) {
    $key = 'bmsai_requestfailed';
    $status = 503;
    if ($e instanceof required_capability_exception) {
        $key = 'bmsai_forbidden';
        $status = 403;
    } else if ($e instanceof invalid_parameter_exception) {
        $key = 'bmsai_invalidrequest';
        $status = 400;
    } else if ($e instanceof moodle_exception && ($e->module ?? '') === 'local_kbacompany' &&
            in_array($e->errorcode, ['bmsai_unavailable', 'bmsai_invalidresponse'], true)) {
        $key = $e->errorcode;
        $status = 503;
    }
    http_response_code($status);
    echo json_encode(['ok' => false, 'error_code' => $key, 'error' => get_string($key, 'local_kbacompany')],
        JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
}
exit;
