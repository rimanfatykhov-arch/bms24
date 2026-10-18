<?php
require(__DIR__ . '/../../../../config.php');

use local_bitrixsync\services\corporate_pairing_service;

defined('MOODLE_INTERNAL') || die();
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

if (strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET')) !== 'POST') {
    http_response_code(405); header('Allow: POST');
    echo json_encode(['success' => false, 'code' => 'method_not_allowed', 'message' => 'POST is required']); exit;
}
$contenttype = strtolower((string)($_SERVER['CONTENT_TYPE'] ?? ''));
if (strpos($contenttype, 'application/json') !== 0) {
    http_response_code(415);
    echo json_encode(['success' => false, 'code' => 'json_required', 'message' => 'Content-Type application/json is required']); exit;
}
$raw = file_get_contents('php://input');
$payload = json_decode((string)$raw, true);
if (!is_array($payload)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'code' => 'invalid_json', 'message' => 'Request body must be valid JSON']); exit;
}
try {
    $service = new corporate_pairing_service();
    $result = $service->pair($payload, (string)($_SERVER['REMOTE_ADDR'] ?? 'unknown'));
    $httpcode = (int)($result['_httpcode'] ?? (!empty($result['success']) ? 200 : 400));
    unset($result['_httpcode']);
} catch (\Throwable $e) {
    $httpcode = 500;
    $result = ['success' => false, 'code' => 'pairing_internal_error', 'message' => 'Pairing service is unavailable'];
}
http_response_code($httpcode);
echo json_encode($result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
