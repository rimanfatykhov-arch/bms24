<?php
/** Read-only Communication Center update probe. No mutations, no user content. */
require_once(__DIR__ . '/../../config.php');

use local_kbacompany\local\communication_live_service;

require_login();
$context = context_system::instance();
require_capability('local/kbacompany:viewowncompany', $context);
$companyuuid = required_param('uuid', PARAM_RAW_TRIMMED);
$threaduuid = optional_param('thread', '', PARAM_RAW_TRIMMED);

// No arbitrary long request parameters or unvalidated thread identifiers.
if (strlen($threaduuid) > 64 || strlen($companyuuid) > 128) {
    throw new moodle_exception('communication_invalid', 'local_kbacompany');
}
$state = communication_live_service::snapshot($companyuuid, (int)$USER->id, $threaduuid);
@header('Content-Type: application/json; charset=utf-8');
@header('Cache-Control: private, no-store, max-age=0');
@header('X-Content-Type-Options: nosniff');
echo json_encode($state, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
