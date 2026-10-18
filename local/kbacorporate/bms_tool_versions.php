<?php
require_once(__DIR__ . '/../../config.php');
require_login();

$context = context_system::instance();
require_capability('local/kbacorporate:manageimplementationaudits', $context);
$companyid = required_param('companyid', PARAM_INT);
$toolid = required_param('toolid', PARAM_INT);
$action = optional_param('action', '', PARAM_ALPHA);
$company = \local_kbacorporate\local\access::require_full_company_access($companyid, $USER->id);
$tool = $DB->get_record_select('local_kbacorp_bms_tool',
    'id = :id AND companyid IN (0, :companyid)', ['id' => $toolid, 'companyid' => $companyid], '*', MUST_EXIST);
$isglobal = (int)$tool->companyid === 0;

if (\local_kbacorporate\local\implementation_audits::get_criteria($toolid) && empty($tool->currentversionid)) {
    \local_kbacorporate\local\implementation_audits::ensure_versioned($toolid, $USER->id);
    $tool = $DB->get_record('local_kbacorp_bms_tool', ['id' => $toolid], '*', MUST_EXIST);
}
if ($action === 'publish') {
    require_sesskey();
    if ($isglobal) {
        throw new moodle_exception('bmstoolreadonly', 'local_kbacorporate');
    }
    $note = required_param('changenote', PARAM_TEXT);
    if (trim($note) === '') {
        redirect(new moodle_url('/local/kbacorporate/bms_tool_versions.php', [
            'toolid' => $toolid, 'companyid' => $companyid,
        ]), get_string('bmstoolversionpublishrequired', 'local_kbacorporate'), null,
            \core\output\notification::NOTIFY_ERROR);
    }
    $version = \local_kbacorporate\local\implementation_audits::publish_version($toolid, $USER->id, $note);
    redirect(new moodle_url('/local/kbacorporate/bms_tool_versions.php', [
        'toolid' => $toolid, 'companyid' => $companyid,
    ]), get_string('bmstoolversionpublished', 'local_kbacorporate', $version->versionlabel));
}

$comparison = \local_kbacorporate\local\implementation_audits::compare_current_to_draft($toolid);
$versions = \local_kbacorporate\local\implementation_audits::get_versions($toolid);
$current = \local_kbacorporate\local\implementation_audits::get_current_version($toolid);
$PAGE->set_context($context);
$PAGE->set_url(new moodle_url('/local/kbacorporate/bms_tool_versions.php', [
    'toolid' => $toolid, 'companyid' => $companyid,
]));
$PAGE->set_pagelayout('admin');
$PAGE->set_title(get_string('bmstoolversions', 'local_kbacorporate'));
$PAGE->set_heading(format_string($company->name));

echo $OUTPUT->header();
echo local_kbacorporate_implementation_navigation($companyid, 'tools');
echo html_writer::div(html_writer::link(new moodle_url('/local/kbacorporate/bms_tool.php', [
    'id' => $toolid, 'companyid' => $companyid,
]), '← ' . format_string($tool->name)), 'kbacorp-backlink');
echo html_writer::start_div('kbacorp-bms-version-hero');
echo html_writer::div(get_string('bmstoolversioning', 'local_kbacorporate'), 'kbacorp-eyebrow');
echo $OUTPUT->heading(get_string('bmstoolversions', 'local_kbacorporate'), 2);
echo html_writer::tag('p', get_string('bmstoolversionsdesc', 'local_kbacorporate'), ['class' => 'kbacorp-pagelead']);
echo html_writer::div(format_string($tool->name) . ' · ' . s($tool->code), 'kbacorp-bms-tool-code');
echo html_writer::end_div();

$diffcount = count($comparison->toolchanges) + count($comparison->criteriaadded) +
    count($comparison->criteriachanged) + count($comparison->criteriaremoved);
echo html_writer::start_div('kbacorp-summary kbacorp-bms-version-summary');
foreach ([
    [$current ? $current->versionlabel : '—', 'bmstoolversioncurrentlabel', 'total'],
    [$diffcount, 'bmstoolversionchanges', 'developing'],
    [count($versions), 'bmstoolversioncount', 'confirmed'],
] as [$value, $label, $accent]) {
    echo html_writer::div(html_writer::div((string)$value, 'value') .
        html_writer::div(get_string($label, 'local_kbacorporate'), 'label'),
        'kbacorp-card kbacorp-card-accent-' . $accent);
}
echo html_writer::end_div();

if ($diffcount === 0) {
    echo $OUTPUT->notification(get_string('bmstoolnochanges', 'local_kbacorporate'), 'success');
} else {
    echo $OUTPUT->heading(get_string('bmstoolversioncomparison', 'local_kbacorporate'), 3);
    echo html_writer::start_div('kbacorp-bms-version-diff');
    foreach ([
        [count($comparison->toolchanges), 'bmstoolversionsettingschanged'],
        [count($comparison->criteriaadded), 'bmstoolversioncriteriaadded'],
        [count($comparison->criteriachanged), 'bmstoolversioncriteriachanged'],
        [count($comparison->criteriaremoved), 'bmstoolversioncriteriaremoved'],
    ] as [$value, $label]) {
        echo html_writer::div(html_writer::tag('strong', (string)$value) .
            html_writer::span(get_string($label, 'local_kbacorporate')), 'kbacorp-bms-version-diff-card');
    }
    echo html_writer::end_div();
    $changednames = [];
    foreach ($comparison->criteriaadded as $criterion) { $changednames[] = '+ ' . format_string($criterion['name']); }
    foreach ($comparison->criteriachanged as $criterion) { $changednames[] = '± ' . format_string($criterion['after']['name']); }
    foreach ($comparison->criteriaremoved as $criterion) { $changednames[] = '− ' . format_string($criterion['name']); }
    if ($changednames) {
        echo html_writer::alist($changednames, ['class' => 'kbacorp-bms-version-change-list']);
    }
    if (!$isglobal) {
        echo html_writer::start_tag('form', ['method' => 'post', 'class' => 'kbacorp-bms-publish-form']);
        echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'companyid', 'value' => $companyid]);
        echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'toolid', 'value' => $toolid]);
        echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'action', 'value' => 'publish']);
        echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'sesskey', 'value' => sesskey()]);
        echo html_writer::label(get_string('bmstoolpublishnote', 'local_kbacorporate'), 'id_changenote');
        echo html_writer::tag('textarea', '', ['id' => 'id_changenote', 'name' => 'changenote', 'required' => 'required',
            'class' => 'form-control', 'rows' => 3,
            'placeholder' => get_string('bmstoolpublishnotehelp', 'local_kbacorporate')]);
        echo html_writer::tag('button', get_string('bmstoolpublishversion', 'local_kbacorporate'),
            ['type' => 'submit', 'class' => 'btn btn-primary']);
        echo html_writer::end_tag('form');
    }
}

echo $OUTPUT->heading(get_string('bmstoolversionhistory', 'local_kbacorporate'), 3);
$table = new html_table();
$table->attributes['class'] = 'generaltable kbacorp-bms-version-table';
$table->head = [get_string('bmstoolversion', 'local_kbacorporate'), get_string('status'),
    get_string('bmstoolversiondate', 'local_kbacorporate'), get_string('bmstoolversionpublisher', 'local_kbacorporate'),
    get_string('bmstoolcriteriashort', 'local_kbacorporate'), get_string('bmstoolpublishnote', 'local_kbacorporate'),
    get_string('bmstoolversionhash', 'local_kbacorporate')];
foreach ($versions as $version) {
    $snapshot = \local_kbacorporate\local\implementation_audits::get_version_snapshot($version);
    $publisher = $version->publishedby ? $DB->get_record('user', ['id' => (int)$version->publishedby]) : null;
    $table->data[] = [s($version->versionlabel),
        get_string('bmstoolversionstatus' . $version->status, 'local_kbacorporate'),
        userdate((int)$version->publishedat, get_string('strftimedatetimeshort')),
        $publisher ? fullname($publisher) : '—', count($snapshot['criteria']),
        trim((string)$version->changenote) !== '' ? s($version->changenote) : '—',
        html_writer::tag('code', s(substr($version->contenthash, 0, 12)))];
}
echo html_writer::div(html_writer::table($table), 'table-responsive');
echo $OUTPUT->footer();
