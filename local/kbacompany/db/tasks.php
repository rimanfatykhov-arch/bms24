<?php

defined('MOODLE_INTERNAL') || die();

$tasks = [
    [
        'classname' => 'local_kbacompany\\task\\send_battle_plan_decision_reminders',
        'blocking' => 0,
        'minute' => '30',
        'hour' => '8',
        'day' => '*',
        'month' => '*',
        'dayofweek' => '*',
    ],
    [
        'classname' => 'local_kbacompany\\task\\send_calendar_reminders',
        'blocking' => 0,
        'minute' => '*/15',
        'hour' => '*',
        'day' => '*',
        'month' => '*',
        'dayofweek' => '*',
    ],
    [
        // Daily reports R5c (owner decision K4): morning reminder about an unsent report; hourly, so that each
        // employee time zone gets its own morning window. Sends nothing while the reporting calendar is off.
        'classname' => 'local_kbacompany\\task\\send_daily_report_reminders',
        'blocking' => 0,
        'minute' => '20',
        'hour' => '*',
        'day' => '*',
        'month' => '*',
        'dayofweek' => '*',
    ],
    [
        'classname' => 'local_kbacompany\\task\\generate_recurring_tasks',
        'blocking' => 0,
        'minute' => '5',
        'hour' => '*',
        'day' => '*',
        'month' => '*',
        'dayofweek' => '*',
    ],
];
