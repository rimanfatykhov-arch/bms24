<?php
require_once(__DIR__ . '/../../config.php');
require_login();

$context = context_system::instance();
require_capability('local/kbacorporate:manageimplementationaudits', $context);
$companyid = required_param('companyid', PARAM_INT);
$action = optional_param('action', '', PARAM_ALPHA);
$token = optional_param('token', '', PARAM_ALPHANUMEXT);
$company = \local_kbacorporate\local\access::require_full_company_access($companyid, $USER->id);

if ($action === 'export') {
    require_sesskey();
    $payload = \local_kbacorporate\local\bms_competency_io::export_company($companyid);
    $json = json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    $tempdir = make_temp_directory('local_kbacorporate');
    $temppath = $tempdir . '/kba_bms_mapping_library_company_' . $companyid . '_' . sesskey() . '.json';
    file_put_contents($temppath, $json);
    send_file($temppath, 'kba_bms_mapping_library_company_' . $companyid . '.json', 0, 0, false, true, 'application/json; charset=utf-8');
}

if ($action === 'apply') {
    require_sesskey();
    if ($token === '' || empty($SESSION->kbacorp_bms_jsonimports[$token])) {
        throw new moodle_exception('bmsjsonsessionexpired', 'local_kbacorporate');
    }
    $saved = $SESSION->kbacorp_bms_jsonimports[$token];
    if ((int)$saved['companyid'] !== $companyid) {
        throw new moodle_exception('companynotallowed', 'local_kbacorporate');
    }
    $stats = \local_kbacorporate\local\bms_competency_io::apply($companyid, $saved['rows']);
    unset($SESSION->kbacorp_bms_jsonimports[$token]);
    redirect(new moodle_url('/local/kbacorporate/bms_competency_io.php', ['companyid' => $companyid]),
        get_string('bmsjsonimportdone', 'local_kbacorporate', (object)$stats));
}

$form = new \local_kbacorporate\form\bms_competency_import_form(null, ['companyid' => $companyid]);
$preview = null;
if ($data = $form->get_data()) {
    $content = $form->get_file_content('jsonfile');
    try {
        $decoded = \local_kbacorporate\local\bms_competency_io::decode((string)$content);
        $preview = \local_kbacorporate\local\bms_competency_io::preview($companyid, $decoded);
        $token = bin2hex(random_bytes(12));
        if (!isset($SESSION->kbacorp_bms_jsonimports)) { $SESSION->kbacorp_bms_jsonimports = []; }
        $SESSION->kbacorp_bms_jsonimports[$token] = ['companyid' => $companyid, 'rows' => $preview, 'time' => time()];
    } catch (\Throwable $e) {
        $preview = false;
        $errormessage = $e->getMessage();
    }
}

$PAGE->set_context($context);
$PAGE->set_url(new moodle_url('/local/kbacorporate/bms_competency_io.php', ['companyid' => $companyid]));
$PAGE->set_pagelayout('admin');
$PAGE->set_title(get_string('bmsjsoniomenu', 'local_kbacorporate'));
$PAGE->set_heading(format_string($company->name));

echo $OUTPUT->header();
echo local_kbacorporate_implementation_navigation($companyid, 'jsonio');
echo html_writer::div(html_writer::link(new moodle_url('/local/kbacorporate/bms_tools.php', ['companyid' => $companyid]),
    '← ' . get_string('bmstoollibrary', 'local_kbacorporate')), 'kbacorp-backlink');
echo $OUTPUT->heading(get_string('bmsjsoniotitle', 'local_kbacorporate'), 2);
echo html_writer::tag('p', get_string('bmsjsoniodesc', 'local_kbacorporate'), ['class' => 'kbacorp-pagelead']);

echo html_writer::start_div('kbacorp-toolbar');
echo $OUTPUT->single_button(new moodle_url('/local/kbacorporate/bms_competency_io.php', [
    'companyid' => $companyid, 'action' => 'export', 'sesskey' => sesskey(),
]), get_string('bmsjsonexport', 'local_kbacorporate'), 'get');
echo html_writer::end_div();

if ($preview === false) {
    echo $OUTPUT->notification(s($errormessage), 'error');
}
if (is_array($preview)) {
    $table = new html_table();
    $table->attributes['class'] = 'generaltable kbacorp-report';
    $table->head = ['#', get_string('bmstoolcode', 'local_kbacorporate'), get_string('bmscriterioncode', 'local_kbacorporate'),
        get_string('competencyname', 'local_kbacorporate'), get_string('targetlevel', 'local_kbacorporate'),
        get_string('rolepriority', 'local_kbacorporate'), get_string('required'), get_string('status')];
    $valid = 0;
    foreach ($preview as $row) {
        if (in_array($row->status, ['create', 'update', 'unchanged'], true)) { $valid++; }
        $table->data[] = [$row->row, s($row->tool_code), s($row->criterion_code), s($row->competency_code),
            $row->targetlevel, s($row->priority), $row->required ? get_string('yes') : get_string('no'),
            html_writer::span(s($row->message), 'kbacorp-bms-json-status is-' . s($row->status))];
    }
    echo $OUTPUT->heading(get_string('bmsjsonpreviewtitle', 'local_kbacorporate'), 3);
    echo html_writer::div(html_writer::table($table), 'kbacorp-table-scroll');
    if ($valid > 0) {
        echo $OUTPUT->single_button(new moodle_url('/local/kbacorporate/bms_competency_io.php', [
            'companyid' => $companyid, 'action' => 'apply', 'token' => $token, 'sesskey' => sesskey(),
        ]), get_string('bmsjsonapply', 'local_kbacorporate'), 'post');
    }
    echo $OUTPUT->heading(get_string('bmsjsonnewfile', 'local_kbacorporate'), 3);
}
$form->display();
echo $OUTPUT->footer();
