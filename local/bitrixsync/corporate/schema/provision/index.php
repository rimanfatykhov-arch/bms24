<?php
require(__DIR__ . '/../../../../../config.php');

use local_bitrixsync\security\corporate_endpoint_auth;
use local_bitrixsync\services\corporate_role_schema_service;

defined('MOODLE_INTERNAL') || die();

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

if (strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET')) !== 'POST') {
    http_response_code(405);
    header('Allow: POST');
    echo json_encode([
        'success' => false,
        'code' => 'method_not_allowed',
        'message' => 'POST is required',
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

$auth = corporate_endpoint_auth::validate_request();
if (empty($auth['success'])) {
    http_response_code((int)$auth['httpcode']);
    unset($auth['httpcode']);
    echo json_encode($auth, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

$result = (new corporate_role_schema_service())->provision();
if (!empty($result['success'])) {
    http_response_code(200);
} else if (($result['code'] ?? '') === 'field_schema_conflict') {
    http_response_code(409);
} else if (($result['code'] ?? '') === 'bitrix_permission_denied') {
    http_response_code(403);
} else {
    http_response_code(503);
}
echo json_encode($result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
