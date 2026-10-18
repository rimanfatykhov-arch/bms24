<?php
require(__DIR__ . '/../../config.php');

require_login();
require_capability('local/bitrixsync:manage', context_system::instance());

redirect(new moodle_url('/local/bitrixsync/catalog.php'));
