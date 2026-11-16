<?php
// Records "user opened the task" in the task History. POST only, sesskey required.
// Called by js/task_open.js after the task card is shown in a visible tab.
// The server determines user and time and re-checks company and task access.
define('AJAX_SCRIPT', true);
require_once(__DIR__ . '/../../config.php');

use local_kbacompany\local\task_open_service;

$reply = static function(int $code, bool $ok, string $result): void {
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: private, no-store');
    header('X-Content-Type-Options: nosniff');
    echo json_encode(['contract' => 'bms_task_open_v1', 'ok' => $ok, 'result' => $result]);
    exit;
};

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    header('Allow: POST');
    $reply(405, false, 'method');
}
require_login(null, false, null, false, true);
if (!confirm_sesskey(optional_param('sesskey', '', PARAM_RAW))) { $reply(403, false, 'sesskey'); }
try {
    require_capability('local/kbacompany:viewowncompany', context_system::instance());
    $company = required_param('uuid', PARAM_ALPHANUMEXT);
    $task = required_param('task', PARAM_ALPHANUMEXT);
    $result = task_open_service::record($company, (int)$USER->id, $task);
    $reply(200, true, $result);
} catch (moodle_exception $e) {
    $reply(403, false, 'denied');
} catch (Throwable $e) {
    // A failed write never blocks the task card; the client does not retry.
    $reply(500, false, 'error');
}
