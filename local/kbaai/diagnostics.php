<?php
require_once('../../config.php');
require_login();
require_capability('moodle/site:config', \context_system::instance());
redirect(new moodle_url('/local/kbaai/diagnostics_v082.php'));
