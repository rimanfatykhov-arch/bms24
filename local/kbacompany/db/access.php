<?php

defined('MOODLE_INTERNAL') || die();

$capabilities = [
    'local/kbacompany:cloudsync' => [
        'riskbitmask' => RISK_CONFIG,
        'captype' => 'write',
        'contextlevel' => CONTEXT_SYSTEM,
        'archetypes' => [],
    ],
    'local/kbacompany:viewowncompany' => [
        'riskbitmask' => RISK_PERSONAL,
        'captype' => 'read',
        'contextlevel' => CONTEXT_SYSTEM,
        'archetypes' => [
            'user' => CAP_ALLOW,
            'manager' => CAP_ALLOW,
        ],
    ],
    'local/kbacompany:viewallcompanies' => [
        'riskbitmask' => RISK_PERSONAL,
        'captype' => 'read',
        'contextlevel' => CONTEXT_SYSTEM,
        'archetypes' => [],
    ],
    'local/kbacompany:reviewownerprivate' => [
        'riskbitmask' => RISK_PERSONAL,
        'captype' => 'write',
        'contextlevel' => CONTEXT_SYSTEM,
        'archetypes' => [],
    ],
    'local/kbacompany:managepeople' => [
        'riskbitmask' => RISK_PERSONAL | RISK_DATALOSS,
        'captype' => 'write',
        'contextlevel' => CONTEXT_SYSTEM,
        'archetypes' => [
            'user' => CAP_ALLOW,
            'manager' => CAP_ALLOW,
        ],
    ],
    'local/kbacompany:viewpeopleprivate' => [
        'riskbitmask' => RISK_PERSONAL,
        'captype' => 'read',
        'contextlevel' => CONTEXT_SYSTEM,
        'archetypes' => [],
    ],
    'local/kbacompany:manageorganization' => [
        'riskbitmask' => RISK_DATALOSS,
        'captype' => 'write',
        'contextlevel' => CONTEXT_SYSTEM,
        'archetypes' => [
            'user' => CAP_ALLOW,
            'manager' => CAP_ALLOW,
        ],
    ],
    // BMOS-A1.1 OD-A11-1 (owner decision (a), 2026-11-28): issuing and renewing BMOS grants is a separate authority.
    // No archetypes: assigned explicitly, and required together with manageorganization rights in the company.
    'local/kbacompany:managebmosgrants' => [
        'riskbitmask' => RISK_CONFIG | RISK_PERSONAL,
        'captype' => 'write',
        'contextlevel' => CONTEXT_SYSTEM,
        'archetypes' => [],
    ],
];
