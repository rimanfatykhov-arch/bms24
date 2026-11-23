<?php
/**
 * BMS24 top bar bell transport.
 *
 * GET  uuid=<company>                                 -> read-only JSON feed of the current user's visible notifications
 *                                                         in that company. Marks nothing read.
 * POST uuid=<company>&action=sound&enabled=0|1&sesskey -> stores the opt-in sound as the user's own Moodle preference.
 *
 * Company membership, thread participation and current object access are enforced by
 * communication_service::notification_feed(); nothing is filtered in the browser. A refused company answers with the
 * same neutral {"ok":false,"state":"unavailable"} as any other refusal, so nothing about the refused rows is revealed.
 */
require_once(__DIR__ . '/../../config.php');

use local_kbacompany\local\communication_center_ui_service;
use local_kbacompany\local\communication_service;
use local_kbacompany\local\company_context_service;

require_login(null, false);
if (isguestuser()) {
    throw new require_login_exception('guest');
}
$context = context_system::instance();
require_capability('local/kbacompany:viewowncompany', $context);
$PAGE->set_context($context);
$PAGE->set_url(new moodle_url('/local/kbacompany/communication_notifications_feed.php'));

$companyuuid = required_param('uuid', PARAM_RAW_TRIMMED);
if ($companyuuid === '' || strlen($companyuuid) > 128) {
    throw new moodle_exception('communication_invalid', 'local_kbacompany');
}
$actor = (int)$USER->id;
$ispost = ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST';

@header('Content-Type: application/json; charset=utf-8');
@header('Cache-Control: private, no-store, max-age=0');
@header('X-Content-Type-Options: nosniff');

try {
    if ($ispost) {
        require_sesskey();
        if (required_param('action', PARAM_ALPHA) !== 'sound') {
            throw new moodle_exception('communication_invalid', 'local_kbacompany');
        }
        $enabled = required_param('enabled', PARAM_INT);
        if ($enabled !== 0 && $enabled !== 1) {
            throw new moodle_exception('communication_invalid', 'local_kbacompany');
        }
        // The preference belongs to the account; it is only changed from a company the user still belongs to.
        company_context_service::context($companyuuid, $actor);
        set_user_preference(communication_center_ui_service::SOUND_PREFERENCE, (string)$enabled, $actor);
        $out = ['ok' => true, 'user' => $actor, 'sound' => $enabled === 1];
    } else {
        $feed = communication_service::notification_feed($companyuuid, $actor, 8);
        $out = communication_center_ui_service::bell_feed($companyuuid, $actor, $feed);
    }
} catch (moodle_exception $e) {
    // Neutral refusal: no reason, rows, counts or links. HTTP 200 keeps the open page free of console noise;
    // the client shows the "unavailable" state and plays no sound.
    $out = ['ok' => false, 'state' => $e->errorcode === 'invalidsesskey' ? 'sesskey' : 'unavailable', 'user' => $actor];
}
echo json_encode($out, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
