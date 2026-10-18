<?php
require(__DIR__ . '/../../../../../config.php');

use local_bitrixsync\security\corporate_endpoint_auth;
use local_bitrixsync\services\corporate_role_schema_service;

defined('MOODLE_INTERNAL') || die();

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

if (strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET')) !== 'GET') {
    http_response_code(405);
    header('Allow: GET');
    echo json_encode([
        'success' => false,
        'code' => 'method_not_allowed',
        'message' => 'GET is required',
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

$result = (new corporate_role_schema_service())->status();
http_response_code(!empty($result['success']) ? 200 : 503);
echo json_encode($result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
