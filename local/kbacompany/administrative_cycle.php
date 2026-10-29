<?php
// Compatibility route only. Planning is owned by Administrative Scale.
require_once(__DIR__ . '/../../config.php');

use local_kbacompany\local\administrative_cycle_service;

require_login();
$context = context_system::instance();
require_capability('local/kbacompany:viewowncompany', $context);
$uuid = required_param('uuid', PARAM_RAW_TRIMMED);
$project = optional_param('project', '', PARAM_RAW_TRIMMED);
$edituuid = optional_param('edituuid', '', PARAM_RAW_TRIMMED);
$target = administrative_cycle_service::legacy_destination($uuid, (int)$USER->id, $project, $edituuid);
if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'GET' && ($_SERVER['REQUEST_METHOD'] ?? '') !== 'HEAD') {
    // No old POST action is forwarded. The caller must review the canonical form.
    throw new moodle_exception('projectexec_legacy_post', 'local_kbacompany', $target->out(false));
}
redirect($target);
