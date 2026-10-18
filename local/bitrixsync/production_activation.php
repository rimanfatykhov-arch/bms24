<?php
require(__DIR__ . '/../../config.php');

require_login();
require_capability('local/bitrixsync:manage', context_system::instance());

$service = new \local_bitrixsync\services\production_activation_service();

$dealid = optional_param('dealid', $service->controlled_deal_id(), PARAM_INT);
$stageid = optional_param('stage', 'C3:EXECUTING', PARAM_RAW_TRIMMED);

$PAGE->set_url(new moodle_url('/local/bitrixsync/production_activation.php', [
    'dealid' => $dealid,
]));
$PAGE->set_context(context_system::instance());
$PAGE->set_title('KBA Connect — Production Activation');

$proof = $service->verify_controlled_run($dealid);
$crmproof = $service->verify_crm_test($dealid);

echo $OUTPUT->header();
echo html_writer::tag('h2', 'KBA Connect — Production Activation');

echo html_writer::tag('h3', 'Controlled Run Proof');
echo html_writer::tag('pre', s(json_encode(
    $proof,
    JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
)));

echo html_writer::tag('h3', 'CRM Final Test');
echo html_writer::tag('pre', s(json_encode(
    $crmproof,
    JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
)));

$endpoint = $service->endpoint();
if ($endpoint !== '') {
    echo html_writer::tag('h3', 'Bitrix24 v5 endpoint');
    echo html_writer::tag('code', s($endpoint));
    echo html_writer::tag(
        'p',
        'Настройте событие/робота Bitrix24 для сделки на этот URL. '
        . 'Токен не публикуйте и не пересылайте.'
    );
}

echo html_writer::tag('h3', 'Recommended CLI');
echo html_writer::tag('pre', s(
    "php local/bitrixsync/cli/production_finalize.php --dealid={$dealid} --stage='{$stageid}' --confirm=FINALIZE_{$dealid}\n"
    . "php local/bitrixsync/cli/production_crm_test.php --dealid={$dealid} --confirm=CRM_TEST_{$dealid}\n"
    . "php local/bitrixsync/cli/production_go_live_check.php --dealid={$dealid}\n"
    . "php local/bitrixsync/cli/production_enable_inbound.php --dealid={$dealid} --stage='{$stageid}' --confirm=ENABLE_INBOUND_{$dealid}\n"
    . "php local/bitrixsync/cli/production_queue_smoke.php --dealid={$dealid} --confirm=QUEUE_{$dealid}"
));

echo $OUTPUT->footer();
