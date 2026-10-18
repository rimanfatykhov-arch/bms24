<?php
// This file is part of Moodle - http://moodle.org/

require_once(__DIR__ . '/../../config.php');
require_login();

$context = context_system::instance();
$companyid = required_param('companyid', PARAM_INT);
$company = \local_kbacorporate\local\access::require_company_implementation_access($companyid, (int)$USER->id);

$PAGE->set_context($context);
$PAGE->set_url(new moodle_url('/local/kbacorporate/implementation_audits.php', ['companyid' => $companyid]));
$PAGE->set_pagelayout('admin');
$PAGE->set_title(get_string('implementationaudits', 'local_kbacorporate'));
$PAGE->set_heading(format_string($company->name));

echo $OUTPUT->header();
echo local_kbacorporate_implementation_navigation($companyid, 'audits');
echo local_kbacorporate_company_context_banner($company);
echo html_writer::div(html_writer::link(
    new moodle_url('/local/kbacorporate/implementation.php', ['companyid' => $companyid]),
    '← ' . get_string('bmsimplementation', 'local_kbacorporate')
), 'kbacorp-backlink');
echo $OUTPUT->heading(get_string('implementationaudits', 'local_kbacorporate'));
echo html_writer::div(get_string('implementationauditsdesc', 'local_kbacorporate'), 'kbacorp-pagelead');

echo html_writer::start_div('kbacorp-toolbar');
echo $OUTPUT->single_button(
    new moodle_url('/local/kbacorporate/implementation_audit_edit.php', ['companyid' => $companyid]),
    get_string('createimplementationaudit', 'local_kbacorporate'),
    'get'
);
echo $OUTPUT->single_button(
    new moodle_url('/local/kbacorporate/implementation_audit_comparison.php', ['companyid' => $companyid]),
    get_string('auditcomparison', 'local_kbacorporate'),
    'get'
);
if (has_capability('local/kbacorporate:manageimplementationaudits', $context)) {
    echo $OUTPUT->single_button(
        new moodle_url('/local/kbacorporate/bms_tools.php', ['companyid' => $companyid]),
        get_string('bmstools', 'local_kbacorporate'),
        'get'
    );
}
echo html_writer::end_div();

$table = new html_table();
$table->attributes['class'] = 'generaltable kbacorp-report';
$table->head = [
    get_string('bmsauditdate', 'local_kbacorporate'),
    get_string('bmsaudittitle', 'local_kbacorporate'),
    get_string('bmstool', 'local_kbacorporate'),
    get_string('bmsaudittype', 'local_kbacorporate'),
    get_string('bmsindex', 'local_kbacorporate'),
    get_string('bmscoverage', 'local_kbacorporate'),
    get_string('status'),
    get_string('actions'),
];

foreach (\local_kbacorporate\local\implementation_audits::get_audits($companyid) as $audit) {
    $url = new moodle_url('/local/kbacorporate/implementation_audit.php', [
        'id' => (int)$audit->id,
        'companyid' => $companyid,
    ]);
    $toolname = format_string($audit->toolname) .
        (!empty($audit->toolversionlabel) ? ' · ' . s($audit->toolversionlabel) : '');
    $actions = html_writer::link($url, get_string('view'));
    if ((string)$audit->status === \local_kbacorporate\local\implementation_audits::STATUS_CONFIRMED) {
        $actions .= ' · ' . html_writer::link(
            new moodle_url('/local/kbacorporate/implementation_audit_comparison.php', [
                'companyid' => $companyid,
                'auditid' => (int)$audit->id,
            ]),
            get_string('auditcomparison', 'local_kbacorporate')
        );
    }
    $table->data[] = [
        userdate((int)$audit->auditdate, get_string('strftimedatefullshort')),
        html_writer::link($url, format_string($audit->title)),
        $toolname,
        get_string('bmsaudittype' . $audit->audittype, 'local_kbacorporate'),
        $audit->indexvalue === null ? '—' : format_float((float)$audit->indexvalue, 1),
        format_float((float)$audit->coverage, 1) . '%',
        get_string('bmsauditstatus' . $audit->status, 'local_kbacorporate'),
        $actions,
    ];
}

echo html_writer::div(html_writer::table($table), 'kbacorp-table-scroll');
echo $OUTPUT->footer();
