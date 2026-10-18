<?php
require_once('../../config.php');
$cmid = required_param('cmid', PARAM_INT);
redirect(new moodle_url('/local/kbaai/console.php', array('cmid' => $cmid)));
