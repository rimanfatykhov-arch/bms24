<?php
require_once(__DIR__ . '/../../config.php');
require_once($CFG->libdir . '/formslib.php');
require_login();

$context = context_system::instance();
require_login();

$companyid = optional_param('companyid', 0, PARAM_INT);
$debug = optional_param('debug', 0, PARAM_BOOL);

$PAGE->set_context($context);
$PAGE->set_url(new moodle_url('/local/kbacorporate/bms_mapping_validator.php',
    ['companyid' => $companyid, 'debug' => $debug]));
$PAGE->set_pagelayout('admin');
$PAGE->set_title('BMS Mapping Library Validator');
$PAGE->set_heading('BMS Mapping Library Validator');

echo $OUTPUT->header();

echo html_writer::tag('h2', 'BMS Mapping Library Validator');

if ($debug) {
    echo html_writer::start_tag('pre');
    echo "Plugin: OK\n";
    echo "User: " . fullname($USER) . "\n";
    echo "Company ID: " . $companyid . "\n";
    echo "Validator: Loaded\n";
    echo html_writer::end_tag('pre');
}

echo html_writer::tag('p',
    'Загрузите KBA_BMS_MAPPING_LIBRARY JSON для проверки структуры и связей.');

require_once(__DIR__ . '/classes/forms/bms_mapping_validator_form.php');

$mform = new bms_mapping_validator_form();
$mform->display();

echo $OUTPUT->notification(
    'Диагностический режим активен. Форма импорта будет подключена следующим этапом.',
    'info'
);

echo $OUTPUT->footer();
