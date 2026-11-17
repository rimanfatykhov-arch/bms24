<?php

defined('MOODLE_INTERNAL') || die();

$messageproviders = [
    'battleplan_decision_reminder' => [
        'capability' => 'local/kbacompany:viewowncompany',
    ],
    'calendar_reminder' => [
        'capability' => 'local/kbacompany:viewowncompany',
    ],
    // ADR-0009 R2: daily report submitted to the reviewer; decision returned to the author.
    'dailyreport_submitted' => [
        'capability' => 'local/kbacompany:viewowncompany',
    ],
    'dailyreport_decision' => [
        'capability' => 'local/kbacompany:viewowncompany',
    ],
];
