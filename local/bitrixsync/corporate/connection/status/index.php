<?php
require(__DIR__ . '/../../../../../config.php');

use local_bitrixsync\security\corporate_endpoint_auth;
use local_bitrixsync\services\corporate_connection_service;

defined('MOODLE_INTERNAL') || die();
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
if (strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET')) !== 'GET') {
    http_response_code(405); header('Allow: GET');
    echo json_encode(['success' => false, 'code' => 'method_not_allowed', 'message' => 'GET is required']); exit;
}
$auth = corporate_endpoint_auth::validate_request();
if (empty($auth['success'])) {
    http_response_code((int)$auth['httpcode']); unset($auth['httpcode']);
    echo json_encode($auth, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); exit;
}
if (empty($auth['connection'])) {
    echo json_encode([
        'success' => true, 'connected' => true,
        'kba_connect' => ['status' => 'healthy'],
        'bitrix24' => ['connected' => null, 'status' => 'legacy_auth'],
        'corporate_api' => ['supported' => true, 'version' => 'legacy'],
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); exit;
}
try {
    $result = (new corporate_connection_service())->status($auth['connection']);
    http_response_code(200);
} catch (\Throwable $e) {
    http_response_code(500);
    $result = ['success' => false, 'code' => 'connection_status_error', 'message' => 'Connection status is unavailable'];
}
echo json_encode($result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
