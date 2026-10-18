<?php
// This file is part of Moodle - http://moodle.org/

defined('MOODLE_INTERNAL') || die();

if ($hassiteconfig) {
    $ADMIN->add('modsettings', new admin_externalpage(
        'local_kbaadmin_modules',
        get_string('menuentry', 'local_kbaadmin'),
        $CFG->wwwroot . '/local/kbaadmin/modules.php',
        'moodle/site:config'
    ));
}
