<?php
require(__DIR__ . '/../../config.php');

require_login();
require_capability('local/bitrixsync:manage', context_system::instance());

$dealid = optional_param(
    'deal_id',
    (int)(get_config('local_bitrixsync', 'controlled_test_deal_id') ?: 3761),
    PARAM_INT
);

redirect(new moodle_url('/local/bitrixsync/production_launch.php', [
    'dealid' => $dealid,
    'action' => 'preflight',
]));
