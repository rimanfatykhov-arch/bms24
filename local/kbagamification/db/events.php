<?php
defined('MOODLE_INTERNAL') || die();

$observers = [
    [
        'eventname' => '\\core\\event\\course_module_completion_updated',
        'callback' => '\\local_kbagamification\\local\\observer::course_module_completion_updated',
    ],
    [
        'eventname' => '\\core\\event\\course_completed',
        'callback' => '\\local_kbagamification\\local\\observer::course_completed',
    ],
];
