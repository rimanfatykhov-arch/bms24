<?php
define('NO_MOODLE_COOKIES', true);
define('NO_DEBUG_DISPLAY', true);

require(__DIR__ . '/../../config.php');

header('Content-Type: application/json; charset=utf-8');

function kba_connect_webhook_json(
    int $status,
    array $payload
): void {
    http_response_code($status);
    echo json_encode(
        $payload,
        JSON_UNESCAPED_UNICODE |
        JSON_UNESCAPED_SLASHES
    );
    exit;
}

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    kba_connect_webhook_json(
        405,
        [
            'success' => false,
            'error' => 'method_not_allowed',
        ]
    );
}

if (!get_config(
    'local_bitrixsync',
    'v5_inbound_enabled'
)) {
    kba_connect_webhook_json(
        503,
        [
            'success' => false,
            'error' => 'v5_inbound_disabled',
        ]
    );
}

$expectedkey = trim((string)get_config(
    'local_bitrixsync',
    'v5_inbound_token'
));

$providedkey = optional_param(
    'key',
    '',
    PARAM_ALPHANUMEXT
);

if ($expectedkey === '' ||
        !hash_equals($expectedkey, $providedkey)) {
    kba_connect_webhook_json(
        403,
        [
            'success' => false,
            'error' => 'bad_kba_key',
        ]
    );
}

if (get_config(
    'local_bitrixsync',
    'engine_mode'
) !== 'v5') {
    kba_connect_webhook_json(
        409,
        [
            'success' => false,
            'error' => 'engine_not_v5',
        ]
    );
}

// Classic Bitrix outgoing webhook = form-urlencoded POST.
// JSON is also accepted for compatibility with custom automations.
$payload = $_POST;
if (!$payload) {
    $raw = file_get_contents('php://input');
    $decoded = json_decode((string)$raw, true);
    if (is_array($decoded)) {
        $payload = $decoded;
    }
}

$event = strtoupper(trim((string)(
    $payload['event'] ?? ''
)));

$supported = [
    'ONCRMDEALUPDATE',
    'ONCRMDEALADD',
    'ONCRMCONTACTUPDATE',
    'ONCRMCOMPANYUPDATE',
    'ONCRMCOMPANYADD',
];

if (!in_array($event, $supported, true)) {
    kba_connect_webhook_json(
        200,
        [
            'success' => true,
            'status' => 'ignored_event',
            'event' => $event,
        ]
    );
}

$providedtoken = trim((string)(
    $payload['auth']['application_token'] ?? ''
));

$authdomain = strtolower(trim((string)(
    $payload['auth']['domain'] ?? ''
)));

$dealtoken = trim((string)get_config(
    'local_bitrixsync',
    'outgoingtoken'
));

$contacttoken = trim((string)get_config(
    'local_bitrixsync',
    'contactoutgoingtoken'
));

if ($event === 'ONCRMCONTACTUPDATE') {
    if (!get_config(
        'local_bitrixsync',
        'contactprofilesyncenabled'
    )) {
        kba_connect_webhook_json(
            200,
            [
                'success' => true,
                'status' => 'contact_sync_disabled',
            ]
        );
    }

    // v0.4.6 compatible fallback: Contact can reuse Deal token.
    $expectedtoken = $contacttoken !== ''
        ? $contacttoken
        : $dealtoken;
} else {
    $expectedtoken = $dealtoken;
}

if ($expectedtoken === '') {
    kba_connect_webhook_json(
        403,
        [
            'success' => false,
            'error' => 'application_token_not_configured',
            'event' => $event,
        ]
    );
}

if ($providedtoken === '' ||
        !hash_equals($expectedtoken, $providedtoken)) {
    kba_connect_webhook_json(
        403,
        [
            'success' => false,
            'error' => 'bad_application_token',
            'event' => $event,
        ]
    );
}

// Same portal/domain protection used by v0.4.6.
$bitrixurl = trim((string)get_config(
    'local_bitrixsync',
    'bitrix_webhook_url'
));

if ($bitrixurl !== '' && $authdomain !== '') {
    $expectedhost = strtolower((string)parse_url(
        $bitrixurl,
        PHP_URL_HOST
    ));

    if ($expectedhost !== '' &&
            !hash_equals($expectedhost, $authdomain)) {
        kba_connect_webhook_json(
            403,
            [
                'success' => false,
                'error' => 'bad_bitrix_domain',
            ]
        );
    }
}


if (in_array($event, ['ONCRMCOMPANYUPDATE', 'ONCRMCOMPANYADD'], true)) {
    if (!get_config('local_bitrixsync', 'bms_bridge_enabled') ||
            !get_config('local_bitrixsync', 'bms_company_webhook_enabled')) {
        kba_connect_webhook_json(200, [
            'success' => true,
            'status' => 'bms_company_sync_disabled',
        ]);
    }

    $companyid = !empty($payload['data']['FIELDS']['ID'])
        ? (int)$payload['data']['FIELDS']['ID']
        : 0;
    if ($companyid <= 0 && !empty($payload['data']['ID'])) {
        $companyid = (int)$payload['data']['ID'];
    }
    if ($companyid <= 0) {
        kba_connect_webhook_json(400, [
            'success' => false,
            'error' => 'company_id_missing',
        ]);
    }

    $queue = new \local_bitrixsync\services\sync_queue();
    $queueid = $queue->add('company', $companyid, 'bms_sync', [
        'event' => $event,
        'domain' => $authdomain,
    ]);
    kba_connect_webhook_json(202, [
        'success' => true,
        'companyid' => $companyid,
        'queueid' => $queueid,
        'status' => 'bms_company_queued',
    ]);
}

if ($event === 'ONCRMCONTACTUPDATE') {
    $contactid = !empty($payload['data']['FIELDS']['ID'])
        ? (int)$payload['data']['FIELDS']['ID']
        : 0;

    if ($contactid <= 0) {
        kba_connect_webhook_json(
            400,
            [
                'success' => false,
                'error' => 'contact_id_missing',
            ]
        );
    }

    // Contact event is authenticated and retained for v0.4.6 compatibility.
    // It deliberately does not trigger learning enrolment.
    $queue = new \local_bitrixsync\services\sync_queue();
    $queueid = $queue->add(
        'contact',
        $contactid,
        'profile_sync',
        [
            'event' => $event,
            'domain' => $authdomain,
        ]
    );

    kba_connect_webhook_json(
        202,
        [
            'success' => true,
            'contactid' => $contactid,
            'queueid' => $queueid,
            'status' => 'contact_queued',
        ]
    );
}

$dealid = 0;

if (!empty($payload['data']['FIELDS']['ID'])) {
    $dealid = (int)$payload['data']['FIELDS']['ID'];
}

if ($dealid <= 0 &&
        !empty($payload['data']['ID'])) {
    $dealid = (int)$payload['data']['ID'];
}

if ($dealid <= 0 &&
        !empty($payload['FIELDS']['ID'])) {
    $dealid = (int)$payload['FIELDS']['ID'];
}

if ($dealid <= 0 &&
        !empty($payload['DOCUMENT_ID']) &&
        is_array($payload['DOCUMENT_ID'])) {
    $last = end($payload['DOCUMENT_ID']);
    if (is_numeric($last)) {
        $dealid = (int)$last;
    }
}

if ($dealid <= 0) {
    kba_connect_webhook_json(
        400,
        [
            'success' => false,
            'error' => 'deal_id_missing',
        ]
    );
}

$queue = new \local_bitrixsync\services\sync_queue();

$queueid = $queue->add(
    'deal',
    $dealid,
    'full_sync',
    [
        'event' => $event,
        'domain' => $authdomain,
    ]
);

kba_connect_webhook_json(
    202,
    [
        'success' => true,
        'dealid' => $dealid,
        'queueid' => $queueid,
        'event' => $event,
        'status' => 'queued',
    ]
);
