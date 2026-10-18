<?php
require_once(__DIR__ . '/../../config.php');
require_login();

$context = context_system::instance();
require_capability('local/kbacorporate:manageimplementationaudits', $context);
$companyid = required_param('companyid', PARAM_INT);
$action = optional_param('action', '', PARAM_ALPHA);
$token = optional_param('token', '', PARAM_ALPHANUMEXT);
$company = \local_kbacorporate\local\access::require_full_company_access($companyid, $USER->id);

if ($action === 'apply') {
    require_sesskey();
    if ($token === '' || empty($SESSION->kbacorp_bms_tool_jsonimports[$token])) {
        throw new moodle_exception('bmstooljsonsessionexpired', 'local_kbacorporate');
    }
    $saved = $SESSION->kbacorp_bms_tool_jsonimports[$token];
    if ((int)$saved['companyid'] !== $companyid) {
        throw new moodle_exception('companynotallowed', 'local_kbacorporate');
    }
    $stats = \local_kbacorporate\local\bms_tool_io::apply($companyid, $saved['rows']);
    unset($SESSION->kbacorp_bms_tool_jsonimports[$token]);
    redirect(new moodle_url('/local/kbacorporate/bms_tool_io.php', ['companyid' => $companyid]),
        get_string('bmstooljsonimportdone', 'local_kbacorporate', (object)$stats));
}

$form = new \local_kbacorporate\form\bms_tool_import_form(null, ['companyid' => $companyid]);
$preview = null;
$token = '';
if ($data = $form->get_data()) {
    $content = $form->get_file_content('jsonfile');
    try {
        $decoded = \local_kbacorporate\local\bms_tool_io::decode((string)$content);
        $preview = \local_kbacorporate\local\bms_tool_io::preview($companyid, $decoded);
        $token = bin2hex(random_bytes(12));
        if (!isset($SESSION->kbacorp_bms_tool_jsonimports)) { $SESSION->kbacorp_bms_tool_jsonimports = []; }
        $SESSION->kbacorp_bms_tool_jsonimports[$token] = ['companyid' => $companyid, 'rows' => $preview['rows'], 'time' => time()];
    } catch (\Throwable $e) {
        $preview = false;
        $errormessage = $e->getMessage();
    }
}

$PAGE->set_context($context);
$PAGE->set_url(new moodle_url('/local/kbacorporate/bms_tool_io.php', ['companyid' => $companyid]));
$PAGE->set_pagelayout('admin');
$PAGE->set_title(get_string('bmstooljsonimport', 'local_kbacorporate'));
$PAGE->set_heading(format_string($company->name));

echo $OUTPUT->header();
echo local_kbacorporate_implementation_navigation($companyid, 'framework');
echo html_writer::div(html_writer::link(new moodle_url('/local/kbacorporate/framework.php', ['companyid' => $companyid]),
    '← ' . get_string('bmsframeworkmanager', 'local_kbacorporate')), 'kbacorp-backlink');
echo $OUTPUT->heading(get_string('bmstooljsonimport', 'local_kbacorporate'), 2);
echo html_writer::tag('p', get_string('bmstooljsonimportdesc', 'local_kbacorporate'), ['class' => 'kbacorp-pagelead']);

if ($preview === false) {
    echo $OUTPUT->notification(s($errormessage), 'error');
}
if (is_array($preview)) {
    $s = $preview['summary'];
    echo html_writer::div(get_string('bmstooljsonsummary', 'local_kbacorporate', (object)$s), 'alert alert-info');
    $table = new html_table();
    $table->attributes['class'] = 'generaltable kbacorp-report';
    $table->head = ['#', get_string('bmstoolcode', 'local_kbacorporate'), get_string('bmscriterioncode', 'local_kbacorporate'),
        get_string('bmscriterionname', 'local_kbacorporate'), get_string('status')];
    $valid = 0;
    foreach ($preview['rows'] as $row) {
        if (in_array($row->status, ['create', 'update', 'unchanged'], true)) { $valid++; }
        $table->data[] = [$row->row, s($row->tool_code), s($row->criterion_code), s($row->name),
            html_writer::span(s($row->message), 'kbacorp-bms-json-status is-' . s($row->status))];
    }
    echo $OUTPUT->heading(get_string('bmstooljsonpreviewtitle', 'local_kbacorporate'), 3);
    echo html_writer::div(html_writer::table($table), 'kbacorp-table-scroll');
    if ($valid > 0 && (int)$s['error'] === 0) {
        echo $OUTPUT->single_button(new moodle_url('/local/kbacorporate/bms_tool_io.php', [
            'companyid' => $companyid, 'action' => 'apply', 'token' => $token, 'sesskey' => sesskey(),
        ]), get_string('bmstooljsonapply', 'local_kbacorporate'), 'post');
    } else if ((int)$s['error'] > 0) {
        echo $OUTPUT->notification(get_string('bmstooljsonapplyblocked', 'local_kbacorporate'), 'warning');
    }
    echo $OUTPUT->heading(get_string('bmstooljsonnewfile', 'local_kbacorporate'), 3);
}
$form->display();
echo $OUTPUT->footer();
