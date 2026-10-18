<?php
defined('MOODLE_INTERNAL') || die();

$capabilities = array(
    'local/kbaai:manage' => array(
        'riskbitmask' => RISK_DATALOSS,
        'captype' => 'write',
        'contextlevel' => CONTEXT_MODULE,
        'archetypes' => array('editingteacher' => CAP_ALLOW, 'manager' => CAP_ALLOW),
    ),
    'local/kbaai:viewtechnical' => array(
        'riskbitmask' => RISK_PERSONAL,
        'captype' => 'read',
        'contextlevel' => CONTEXT_MODULE,
        'archetypes' => array('manager' => CAP_ALLOW),
    ),
    'local/kbaai:viewownfeedback' => array(
        'captype' => 'read',
        'contextlevel' => CONTEXT_MODULE,
        'archetypes' => array('student' => CAP_ALLOW, 'user' => CAP_ALLOW),
    ),
);
