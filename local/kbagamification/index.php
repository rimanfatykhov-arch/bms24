<?php
require_once(__DIR__ . '/../../config.php');

$userid = optional_param('userid', $USER->id, PARAM_INT);
require_login();

// Compatibility entry point retained for bookmarks and links created by KBA Gamification <= 1.5.
redirect(new moodle_url('/local/kbagamification/profile.php', ['userid' => $userid]));
