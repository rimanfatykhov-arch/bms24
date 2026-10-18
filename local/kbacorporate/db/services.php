<?php

defined('MOODLE_INTERNAL') || die();

$functions = [
    'local_kbacorporate_erp_contract' => [
        'classname' => 'local_kbacorporate\\external\\erp_api',
        'methodname' => 'contract',
        'description' => 'Return the stable KBA ERP exchange contract.',
        'type' => 'read',
        'ajax' => false,
        'capabilities' => 'local/kbacorporate:useerpapi',
    ],
    'local_kbacorporate_erp_status' => [
        'classname' => 'local_kbacorporate\\external\\erp_api',
        'methodname' => 'status',
        'description' => 'Return ERP API contract readiness and fingerprint.',
        'type' => 'read',
        'ajax' => false,
        'capabilities' => 'local/kbacorporate:useerpapi',
    ],
    'local_kbacorporate_erp_push_entity' => [
        'classname' => 'local_kbacorporate\\external\\erp_api',
        'methodname' => 'push_entity',
        'description' => 'Idempotently push one supported ERP entity.',
        'type' => 'write',
        'ajax' => false,
        'capabilities' => 'local/kbacorporate:useerpapi',
    ],
    'local_kbacorporate_erp_pull_company_snapshot' => [
        'classname' => 'local_kbacorporate\\external\\erp_api',
        'methodname' => 'pull_company_snapshot',
        'description' => 'Pull a paged company competency and development snapshot.',
        'type' => 'read',
        'ajax' => false,
        'capabilities' => 'local/kbacorporate:useerpapi',
    ],
];

$services = [
    'KBA Corporate ERP API v1' => [
        'functions' => [
            'local_kbacorporate_erp_contract',
            'local_kbacorporate_erp_status',
            'local_kbacorporate_erp_push_entity',
            'local_kbacorporate_erp_pull_company_snapshot',
        ],
        'restrictedusers' => 1,
        'enabled' => 0,
        'shortname' => 'local_kbacorporate_erp_v1',
    ],
];
