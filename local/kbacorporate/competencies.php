<?php
require(__DIR__.'/../../config.php');
require_login();
$PAGE->set_url(new moodle_url('/local/kbacorporate/competencies.php'));
$PAGE->set_context(context_system::instance());
$PAGE->set_title('BMS Competencies');
echo $OUTPUT->header();
echo '<h2>BMS Competencies</h2><p>Framework: BMS-COMP-2026-V1</p>';
echo $OUTPUT->footer();
