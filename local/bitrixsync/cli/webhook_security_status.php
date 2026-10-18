<?php
define('CLI_SCRIPT', true);
require(__DIR__ . '/_bootstrap.php');

$bitrixurl = trim((string)get_config(
    'local_bitrixsync',
    'bitrix_webhook_url'
));
$dealtoken = trim((string)get_config(
    'local_bitrixsync',
    'outgoingtoken'
));
$contacttoken = trim((string)get_config(
    'local_bitrixsync',
    'contactoutgoingtoken'
));

echo json_encode([
    'moodle_to_bitrix_rest_webhook' => $bitrixurl !== '',
    'bitrix_domain' => $bitrixurl !== ''
        ? (string)parse_url($bitrixurl, PHP_URL_HOST)
        : '',
    'deal_application_token' => $dealtoken !== '',
    'contact_event_enabled' => (bool)get_config(
        'local_bitrixsync',
        'contactprofilesyncenabled'
    ),
    'contact_application_token' => $contacttoken !== ''
        ? 'dedicated'
        : ($dealtoken !== '' ? 'deal_token_fallback' : 'missing'),
    'kba_url_key' => trim((string)get_config(
        'local_bitrixsync',
        'v5_inbound_token'
    )) !== '',
    'inbound_enabled' => (bool)get_config(
        'local_bitrixsync',
        'v5_inbound_enabled'
    ),
], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL;
