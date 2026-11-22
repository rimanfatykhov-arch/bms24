<?php
/** POST-only endpoint of the Battle Plan "Discussion" tab: posts one message to the plan's canonical thread. */
require_once(__DIR__ . '/../../config.php');

use local_kbacompany\local\battle_plan_discussion_service;

require_login();
require_capability('local/kbacompany:viewowncompany', context_system::instance());
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    throw new moodle_exception('communication_post', 'local_kbacompany');
}
require_sesskey();
$uuid = required_param('uuid', PARAM_RAW_TRIMMED);
$plan = required_param('plan', PARAM_ALPHANUMEXT);
$action = required_param('action', PARAM_ALPHA);
$actor = (int)$USER->id;
$back = new moodle_url('/local/kbacompany/battle_plan.php',
    ['uuid' => $uuid, 'plan' => $plan, 'view' => 'detail', 'tab' => 'discussion']);
if ($action !== 'post') {
    throw new moodle_exception('communication_invalid', 'local_kbacompany');
}
try {
    // Company, plan read rule, plan write-to-discussion right, reply target and files are validated by the service.
    battle_plan_discussion_service::post($uuid, $actor, $plan, optional_param('message', '', PARAM_RAW),
        $_FILES['discussionattachment'] ?? null, optional_param('replytouuid', '', PARAM_ALPHANUMEXT));
} catch (moodle_exception $e) {
    if ($e->module !== 'local_kbacompany') { throw $e; }
    // Service messages never distinguish a missing plan from a forbidden one; nothing is stored on failure.
    redirect($back, $e->getMessage(), null, \core\output\notification::NOTIFY_ERROR);
}
redirect($back);
