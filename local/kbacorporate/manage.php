<?php
// This file is part of Moodle - http://moodle.org/
//
// Compatibility endpoint for integrations that used the old manage.php URL.

require_once(__DIR__ . '/../../config.php');

require_login();
$context = context_system::instance();
require_capability('local/kbacorporate:managecompanies', $context);

redirect(new moodle_url('/local/kbacorporate/companies.php'));
