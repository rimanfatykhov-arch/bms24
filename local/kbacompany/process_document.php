<?php
require_once(__DIR__ . '/../../config.php');

use local_kbacompany\local\company_context_service;
use local_kbacompany\local\process_document_service;
use local_kbacompany\local\process_document_ui_service;
use local_kbacompany\local\bms_page_shell_service;

require_login();
$context = context_system::instance();
require_capability('local/kbacompany:viewowncompany', $context);
$uuid = company_context_service::normalise_uuid(required_param('uuid', PARAM_RAW_TRIMMED));
$processuuid = required_param('process', PARAM_RAW_TRIMMED);
$format = optional_param('format', 'screen', PARAM_ALPHA);
if (!in_array($format, ['screen','print','html'], true)) { throw new moodle_exception('invalidparameter'); }

$PAGE->set_context($context);
$PAGE->set_url(new moodle_url('/local/kbacompany/process_document.php', [
    'uuid'=>$uuid, 'process'=>$processuuid, 'format'=>$format,
]));
$PAGE->set_title(get_string('processdoc_title', 'local_kbacompany'));
$PAGE->set_heading(get_string('processdoc_title', 'local_kbacompany'));
$PAGE->requires->css(new moodle_url('/local/kbacompany/styles.css'));

$document = process_document_service::context($uuid, (int)$USER->id, $processuuid);
if ($format === 'print' || $format === 'html') {
    $body = process_document_ui_service::standalone_html($document);
    $code = preg_replace('/[^A-Za-z0-9._-]+/', '_', (string)$document['process']['code']);
    $filename = 'process-' . $code . '-r' . (int)$document['document']['process_revision'];
    if ($document['document']['model_revision']) { $filename .= '-m' . (int)$document['document']['model_revision']; }
    $filename .= '.html';
    header('Content-Type: text/html; charset=utf-8');
    if ($format === 'html') { header('Content-Disposition: attachment; filename="' . $filename . '"'); }
    header('X-Content-Type-Options: nosniff');
    echo $body;
    exit;
}

$shell = bms_page_shell_service::begin($uuid, (int)$USER->id, 'processes', get_string('processdoc_title', 'local_kbacompany'));
echo process_document_ui_service::screen($uuid, $document);
bms_page_shell_service::finish($shell);
