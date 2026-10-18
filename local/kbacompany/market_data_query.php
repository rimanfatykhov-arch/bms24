<?php
require_once(__DIR__ . '/../../config.php');

use local_kbacompany\local\market_data_gateway_service;

require_login();
$context = context_system::instance();
require_capability('local/kbacompany:viewowncompany', $context);
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    throw new moodle_exception('invalidrequest');
}
require_sesskey();
$companyuuid = required_param('uuid', PARAM_RAW_TRIMMED);
$provider = required_param('provider', PARAM_ALPHANUMEXT);
$query = required_param('query', PARAM_RAW_TRIMMED);
$limit = optional_param('limit', 10, PARAM_INT);

header('Content-Type: application/json; charset=utf-8');
try {
    $result = market_data_gateway_service::search($companyuuid, (int)$USER->id, $provider, $query, $limit);
    echo json_encode(['ok' => true, 'result' => $result], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
} catch (Throwable $e) {
    debugging('Market Data Tool Gateway query failed: ' . $e->getMessage(), DEBUG_DEVELOPER);
    $message = get_string('marketdata_query_failed', 'local_kbacompany');
    if ($e instanceof moodle_exception && $e->errorcode === 'marketdata_provider_unavailable') {
        $message = get_string('marketdata_provider_unavailable', 'local_kbacompany');
    }
    echo json_encode(['ok' => false, 'error' => $message], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
}
exit;
