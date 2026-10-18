<?php
require_once('../../config.php');
require_login();
require_capability('moodle/site:config', \context_system::instance());

$providerid = optional_param('provider', (string)(get_config('local_kbaai', 'defaultprovider') ?: 'yandex'), PARAM_ALPHANUMEXT);
$run = optional_param('run', 0, PARAM_BOOL);
$full = optional_param('full', 0, PARAM_BOOL);

$PAGE->set_url('/local/kbaai/provider_test_v071.php', array('provider' => $providerid));
$PAGE->set_context(\context_system::instance());
$PAGE->set_title(get_string('providertest', 'local_kbaai'));
$PAGE->set_heading(get_string('providertest', 'local_kbaai'));

echo $OUTPUT->header();
echo $OUTPUT->heading(get_string('providertest', 'local_kbaai'));
echo $OUTPUT->notification('Diagnostic code revision: 0.7.1 / build 2026081518', 'info');

function local_kbaai_test_badge($ok, $pending = false) {
    if ($pending) {
        return html_writer::span(get_string('diagnosticskipped', 'local_kbaai'), 'badge badge-secondary');
    }
    return $ok
        ? html_writer::span(get_string('diagnosticok', 'local_kbaai'), 'badge badge-success')
        : html_writer::span(get_string('diagnosticfailed', 'local_kbaai'), 'badge badge-danger');
}

function local_kbaai_test_rows(array $rows) {
    $html = html_writer::start_tag('table', array('class' => 'table table-striped table-sm'));
    $html .= html_writer::start_tag('tbody');
    foreach ($rows as $label => $value) {
        if (is_array($value)) {
            $value = json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        }
        if ($value === null || $value === '') {
            $value = '—';
        }
        $html .= html_writer::tag('tr',
            html_writer::tag('th', s((string)$label), array('scope' => 'row', 'style' => 'width:34%')) .
            html_writer::tag('td', s((string)$value))
        );
    }
    $html .= html_writer::end_tag('tbody') . html_writer::end_tag('table');
    return $html;
}

if ($providerid === 'yandex') {
    echo $OUTPUT->notification(get_string('yandexdiagnosticintro', 'local_kbaai'), 'info');
}

if ($run) {
    require_sesskey();
    try {
        $provider = \local_kbaai\provider\provider_factory::create($providerid);
        if ($providerid === 'yandex' && method_exists($provider, 'diagnose')) {
            $report = $provider->diagnose($full);
            $config = $report['configuration'];

            echo $OUTPUT->heading(get_string('diagnosticconfiguration', 'local_kbaai'), 3);
            echo local_kbaai_test_rows(array(
                get_string('yandexfolderid', 'local_kbaai') => $config['folder_id'],
                get_string('diagnostickey', 'local_kbaai') => $config['api_key_present'] ? ($config['api_key_source'] . ' / ' . $config['api_key_mask']) : get_string('diagnosticmissing', 'local_kbaai'),
                get_string('defaultmodel', 'local_kbaai') => $config['text_model_uri'],
                get_string('yandexvisionmodel', 'local_kbaai') => $config['vision_model_uri'],
                get_string('yandexendpoint', 'local_kbaai') => $config['text_endpoint'],
                get_string('yandexopenaibase', 'local_kbaai') => $config['openai_base'],
                get_string('yandextexttimeout', 'local_kbaai') => $config['text_timeout'] . ' s',
                get_string('yandexvisiontimeout', 'local_kbaai') => $config['vision_timeout'] . ' s',
                get_string('yandexocrendpoint', 'local_kbaai') => !empty($config['ocr_endpoint']) ? $config['ocr_endpoint'] : '',
                get_string('pdfocrresultendpoint', 'local_kbaai') => !empty($config['ocr_result_endpoint']) ? $config['ocr_result_endpoint'] : '',
                get_string('pdfocrmode', 'local_kbaai') => !empty($config['ocr_mode']) ? $config['ocr_mode'] : '',
                get_string('yandexocrmodel', 'local_kbaai') => !empty($config['ocr_model']) ? $config['ocr_model'] : '',
                get_string('yandexocrtimeout', 'local_kbaai') => !empty($config['ocr_timeout']) ? $config['ocr_timeout'] . ' s' : '',
                get_string('pdfmaxchars', 'local_kbaai') => !empty($config['pdf_max_chars']) ? $config['pdf_max_chars'] : '',
                get_string('docxmaxchars', 'local_kbaai') => (int)(get_config('local_kbaai','docxmaxchars') ?: 60000),
                get_string('docxmaximages', 'local_kbaai') => (int)(get_config('local_kbaai','docxmaximages') ?: 4),
            ));

            $text = $report['text'];
            echo $OUTPUT->heading(get_string('diagnostictextapi', 'local_kbaai') . ' ' . local_kbaai_test_badge(!empty($text['ok'])), 3);
            if (!empty($text['ok'])) {
                echo local_kbaai_test_rows(array(
                    get_string('diagnosticrequestid', 'local_kbaai') => !empty($text['request_id']) ? $text['request_id'] : '',
                    get_string('diagnosticmodelversion', 'local_kbaai') => !empty($text['model_version']) ? $text['model_version'] : '',
                    get_string('diagnosticstructured', 'local_kbaai') => !empty($text['structured_output']) ? get_string('yes') : get_string('no'),
                    get_string('diagnosticduration', 'local_kbaai') => isset($text['duration']) ? round((float)$text['duration'], 3) . ' s' : '',
                    get_string('diagnosticusage', 'local_kbaai') => !empty($text['usage']) ? $text['usage'] : array(),
                ));
                echo $OUTPUT->notification(get_string('providertestok', 'local_kbaai'), 'success');
            } else {
                echo $OUTPUT->notification(!empty($text['error']) ? s($text['error']) : get_string('providertestfailed', 'local_kbaai'), 'error');
                echo local_kbaai_test_rows(array(
                    'HTTP' => !empty($text['http_status']) ? $text['http_status'] : '',
                    get_string('diagnosticrequestid', 'local_kbaai') => !empty($text['request_id']) ? $text['request_id'] : '',
                    get_string('diagnosticprovidermessage', 'local_kbaai') => !empty($text['provider_message']) ? $text['provider_message'] : '',
                    get_string('diagnosticrecommendation', 'local_kbaai') => !empty($text['help']) ? $text['help'] : '',
                ));
            }

            $vision = $report['vision'];
            echo $OUTPUT->heading(get_string('diagnosticvisionapi', 'local_kbaai') . ' ' . local_kbaai_test_badge(!empty($vision['ok']), !empty($vision['skipped'])), 3);
            if (!empty($vision['skipped'])) {
                echo html_writer::tag('p', get_string('diagnosticvisionnotrun', 'local_kbaai'));
            } else if (!empty($vision['ok'])) {
                echo local_kbaai_test_rows(array(
                    'HTTP' => isset($vision['http_status']) ? $vision['http_status'] : '',
                    get_string('diagnosticrequestid', 'local_kbaai') => !empty($vision['request_id']) ? $vision['request_id'] : '',
                    get_string('diagnosticendpoint', 'local_kbaai') => !empty($vision['endpoint']) ? $vision['endpoint'] : '',
                    get_string('diagnosticmodeluri', 'local_kbaai') => !empty($vision['model_uri']) ? $vision['model_uri'] : '',
                    get_string('diagnosticmodelversion', 'local_kbaai') => !empty($vision['model']) ? $vision['model'] : '',
                    get_string('diagnosticinputmime', 'local_kbaai') => !empty($vision['input_mime']) ? $vision['input_mime'] : '',
                    get_string('diagnosticinputbytes', 'local_kbaai') => isset($vision['input_bytes']) ? $vision['input_bytes'] : '',
                    get_string('diagnosticmaxtokens', 'local_kbaai') => isset($vision['max_tokens']) ? $vision['max_tokens'] : '',
                    get_string('diagnosticfinishreason', 'local_kbaai') => !empty($vision['finish_reason']) ? $vision['finish_reason'] : '',
                    get_string('diagnosticcontenttype', 'local_kbaai') => !empty($vision['content_type']) ? $vision['content_type'] : '',
                    get_string('diagnosticparsepath', 'local_kbaai') => !empty($vision['parse_path']) ? $vision['parse_path'] : '',
                    get_string('diagnosticreasoningtokens', 'local_kbaai') => isset($vision['reasoning_tokens']) ? $vision['reasoning_tokens'] : '',
                    get_string('diagnosticduration', 'local_kbaai') => isset($vision['duration']) ? round((float)$vision['duration'], 3) . ' s' : '',
                    get_string('diagnosticusage', 'local_kbaai') => !empty($vision['usage']) ? $vision['usage'] : array(),
                    get_string('diagnosticresponsepreview', 'local_kbaai') => !empty($vision['preview']) ? $vision['preview'] : '',
                    get_string('diagnosticrawpreview', 'local_kbaai') => !empty($vision['raw_response_preview']) ? $vision['raw_response_preview'] : '',
                ));
            } else {
                echo $OUTPUT->notification(!empty($vision['error']) ? s($vision['error']) : get_string('providertestfailed', 'local_kbaai'), 'error');
                echo local_kbaai_test_rows(array(
                    'HTTP' => isset($vision['http_status']) ? $vision['http_status'] : '',
                    get_string('diagnosticrequestid', 'local_kbaai') => !empty($vision['request_id']) ? $vision['request_id'] : '',
                    get_string('diagnosticendpoint', 'local_kbaai') => !empty($vision['endpoint']) ? $vision['endpoint'] : '',
                    get_string('diagnosticmodeluri', 'local_kbaai') => !empty($vision['model_uri']) ? $vision['model_uri'] : '',
                    get_string('diagnosticinputmime', 'local_kbaai') => !empty($vision['input_mime']) ? $vision['input_mime'] : '',
                    get_string('diagnosticinputbytes', 'local_kbaai') => isset($vision['input_bytes']) ? $vision['input_bytes'] : '',
                    get_string('diagnosticmaxtokens', 'local_kbaai') => isset($vision['max_tokens']) ? $vision['max_tokens'] : '',
                    get_string('diagnosticfinishreason', 'local_kbaai') => !empty($vision['finish_reason']) ? $vision['finish_reason'] : '',
                    get_string('diagnosticcontenttype', 'local_kbaai') => isset($vision['content_type']) ? $vision['content_type'] : '',
                    get_string('diagnosticparsepath', 'local_kbaai') => !empty($vision['parse_path']) ? $vision['parse_path'] : '',
                    get_string('diagnosticreasoningtokens', 'local_kbaai') => isset($vision['reasoning_tokens']) ? $vision['reasoning_tokens'] : '',
                    get_string('diagnosticrefusal', 'local_kbaai') => !empty($vision['refusal']) ? $vision['refusal'] : '',
                    get_string('diagnosticresponsekeys', 'local_kbaai') => !empty($vision['response_keys']) ? $vision['response_keys'] : '',
                    get_string('diagnosticusage', 'local_kbaai') => !empty($vision['usage']) ? $vision['usage'] : array(),
                    get_string('diagnosticprovidermessage', 'local_kbaai') => !empty($vision['provider_message']) ? $vision['provider_message'] : '',
                    get_string('diagnosticrecommendation', 'local_kbaai') => !empty($vision['help']) ? $vision['help'] : '',
                    get_string('diagnosticrawpreview', 'local_kbaai') => !empty($vision['raw_response_preview']) ? $vision['raw_response_preview'] : '',
                ));
            }

            $ocr = !empty($report['ocr']) ? $report['ocr'] : array('ok'=>null, 'skipped'=>true);
            echo $OUTPUT->heading(get_string('diagnosticocrapi', 'local_kbaai') . ' ' . local_kbaai_test_badge(!empty($ocr['ok']), !empty($ocr['skipped'])), 3);
            if (!empty($ocr['skipped'])) {
                echo html_writer::tag('p', get_string('diagnosticocrnotrun', 'local_kbaai'));
            } else if (!empty($ocr['ok'])) {
                echo local_kbaai_test_rows(array(
                    'HTTP' => isset($ocr['http_status']) ? $ocr['http_status'] : '',
                    get_string('diagnosticrequestid', 'local_kbaai') => !empty($ocr['request_id']) ? $ocr['request_id'] : '',
                    get_string('pdfocroperationid', 'local_kbaai') => !empty($ocr['operation_id']) ? $ocr['operation_id'] : '',
                    get_string('diagnosticendpoint', 'local_kbaai') => !empty($ocr['endpoint']) ? $ocr['endpoint'] : '',
                    get_string('pdfocrresultendpoint', 'local_kbaai') => !empty($ocr['result_endpoint']) ? $ocr['result_endpoint'] : '',
                    get_string('pdfocrmimetype', 'local_kbaai') => !empty($ocr['mime_type']) ? $ocr['mime_type'] : '',
                    get_string('pdfocrmode', 'local_kbaai') => !empty($ocr['mode']) ? $ocr['mode'] : '',
                    get_string('yandexocrmodel', 'local_kbaai') => !empty($ocr['model']) ? $ocr['model'] : '',
                    get_string('diagnosticinputbytes', 'local_kbaai') => isset($ocr['input_bytes']) ? $ocr['input_bytes'] : '',
                    get_string('pdfpages', 'local_kbaai') => isset($ocr['page_count']) ? $ocr['page_count'] : '',
                    get_string('pdftables', 'local_kbaai') => isset($ocr['table_count']) ? $ocr['table_count'] : '',
                    get_string('diagnosticduration', 'local_kbaai') => isset($ocr['duration']) ? round((float)$ocr['duration'], 3) . ' s' : '',
                    get_string('diagnosticresponsepreview', 'local_kbaai') => !empty($ocr['preview']) ? $ocr['preview'] : '',
                ));
            } else {
                echo $OUTPUT->notification(!empty($ocr['error']) ? s($ocr['error']) : get_string('providertestfailed', 'local_kbaai'), 'error');
                echo local_kbaai_test_rows(array(
                    'HTTP' => isset($ocr['http_status']) ? $ocr['http_status'] : '',
                    get_string('diagnosticrequestid', 'local_kbaai') => !empty($ocr['request_id']) ? $ocr['request_id'] : '',
                    get_string('pdfocroperationid', 'local_kbaai') => !empty($ocr['operation_id']) ? $ocr['operation_id'] : '',
                    get_string('diagnosticendpoint', 'local_kbaai') => !empty($ocr['endpoint']) ? $ocr['endpoint'] : '',
                    get_string('pdfocrresultendpoint', 'local_kbaai') => !empty($ocr['result_endpoint']) ? $ocr['result_endpoint'] : '',
                    get_string('pdfocrmimetype', 'local_kbaai') => !empty($ocr['mime_type']) ? $ocr['mime_type'] : '',
                    get_string('pdfocrmode', 'local_kbaai') => !empty($ocr['mode']) ? $ocr['mode'] : '',
                    get_string('diagnosticinputbytes', 'local_kbaai') => isset($ocr['input_bytes']) ? $ocr['input_bytes'] : '',
                    get_string('diagnosticprovidermessage', 'local_kbaai') => !empty($ocr['provider_message']) ? $ocr['provider_message'] : '',
                    get_string('diagnosticrecommendation', 'local_kbaai') => !empty($ocr['help']) ? $ocr['help'] : '',
                ));
            }

            $docx = !empty($report['docx']) ? $report['docx'] : array('ok'=>false, 'error'=>get_string('diagnosticdocxnotrun','local_kbaai'));
            echo $OUTPUT->heading(get_string('diagnosticdocx', 'local_kbaai') . ' ' . local_kbaai_test_badge(!empty($docx['ok'])), 3);
            if (!empty($docx['ok'])) {
                echo local_kbaai_test_rows(array(
                    get_string('diagnosticphpzip', 'local_kbaai') => !empty($docx['ziparchive']) ? get_string('yes') : get_string('no'),
                    get_string('diagnosticphpdom', 'local_kbaai') => !empty($docx['domdocument']) ? get_string('yes') : get_string('no'),
                    get_string('docxparagraphs', 'local_kbaai') => isset($docx['paragraphs']) ? $docx['paragraphs'] : '',
                    get_string('docxtables', 'local_kbaai') => isset($docx['tables']) ? $docx['tables'] : '',
                    get_string('docximages', 'local_kbaai') => isset($docx['images']) ? $docx['images'] : '',
                    get_string('diagnosticdocxpreview', 'local_kbaai') => !empty($docx['preview']) ? $docx['preview'] : '',
                ));
            } else {
                echo $OUTPUT->notification(!empty($docx['error']) ? s($docx['error']) : get_string('providertestfailed','local_kbaai'), 'error');
                echo local_kbaai_test_rows(array(
                    get_string('diagnosticphpzip', 'local_kbaai') => !empty($docx['ziparchive']) ? get_string('yes') : get_string('no'),
                    get_string('diagnosticphpdom', 'local_kbaai') => !empty($docx['domdocument']) ? get_string('yes') : get_string('no'),
                ));
            }
        } else {
            $ok = $provider->test_connection();
            echo $OUTPUT->notification($ok ? get_string('providertestok', 'local_kbaai') : get_string('providertestfailed', 'local_kbaai'), $ok ? 'success' : 'error');
        }
    } catch (\Throwable $e) {
        echo $OUTPUT->notification(get_string('providertestfailed', 'local_kbaai') . ': ' . s($e->getMessage()), 'error');
    }
}

$quickurl = new moodle_url('/local/kbaai/provider_test_v071.php', array(
    'run' => 1,
    'provider' => $providerid,
    'sesskey' => sesskey(),
));
$fullurl = new moodle_url('/local/kbaai/provider_test_v071.php', array(
    'run' => 1,
    'full' => 1,
    'provider' => $providerid,
    'sesskey' => sesskey(),
));

echo html_writer::start_div('mt-4');
echo html_writer::link($quickurl, get_string('runprovidertest', 'local_kbaai'), array('class' => 'btn btn-primary mr-2'));
if ($providerid === 'yandex') {
    echo html_writer::link($fullurl, get_string('runproviderfulltest', 'local_kbaai'), array('class' => 'btn btn-secondary'));
}
echo html_writer::end_div();

echo $OUTPUT->footer();
