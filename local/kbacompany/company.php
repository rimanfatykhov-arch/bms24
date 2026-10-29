<?php

require_once(__DIR__ . '/../../config.php');

use local_kbacompany\local\company_service;

require_login();
$context = context_system::instance();
require_capability('local/kbacompany:viewowncompany', $context);
$uuid = required_param('uuid', PARAM_RAW_TRIMMED);

// Preserve the historical company.php URL as a compatibility entry point, but
// do not maintain a second company UI. Validation still happens before the
// redirect so users cannot use this route to bypass company access checks.
$company = company_service::company_context($uuid, (int)$USER->id);
redirect(new moodle_url('/local/kbacompany/bms_workspace.php', [
    'uuid' => $company['uuid'],
]));
