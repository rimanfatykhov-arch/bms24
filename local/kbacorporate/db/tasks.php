<?php

defined('MOODLE_INTERNAL') || die();

$tasks = [
    [
        'classname' => 'local_kbacorporate\task\run_health_check',
        'blocking' => 0,
        'minute' => '35',
        'hour' => '6',
        'day' => '*',
        'month' => '*',
        'dayofweek' => '*',
    ],
    [
        'classname' => 'local_kbacorporate\task\prune_erp_audit',
        'blocking' => 0,
        'minute' => '50',
        'hour' => '3',
        'day' => '*',
        'month' => '*',
        'dayofweek' => '*',
    ],
    [
        'classname' => 'local_kbacorporate\task\send_attention_digest',
        'blocking' => 0,
        'minute' => '15',
        'hour' => '7',
        'day' => '*',
        'month' => '*',
        'dayofweek' => '*',
    ],
    [
        'classname' => 'local_kbacorporate\task\sync_development_courses',
        'blocking' => 0,
        'minute' => '25',
        'hour' => '*',
        'day' => '*',
        'month' => '*',
        'dayofweek' => '*',
    ],
    [
        'classname' => 'local_kbacorporate\task\send_development_reminders',
        'blocking' => 0,
        'minute' => '5',
        'hour' => '8',
        'day' => '*',
        'month' => '*',
        'dayofweek' => '*',
    ],
    [
        'classname' => 'local_kbacorporate\task\send_implementation_deadline_reminders',
        'blocking' => 0,
        'minute' => '20',
        'hour' => '8',
        'day' => '*',
        'month' => '*',
        'dayofweek' => '*',
    ],
];
