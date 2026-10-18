<?php
require_once(__DIR__ . '/../../config.php');
require_login();
$context = context_system::instance();
require_capability('local/kbacorporate:manageimplementationaudits', $context);
$companyid = required_param('companyid', PARAM_INT);
$criterionid = required_param('criterionid', PARAM_INT);
$toolid = required_param('toolid', PARAM_INT);
$id = optional_param('id', 0, PARAM_INT);
$delete = optional_param('delete', 0, PARAM_INT);
$company = \local_kbacorporate\local\access::require_full_company_access($companyid, $USER->id);
$criterion = $DB->get_record_sql("SELECT c.*, t.companyid FROM {local_kbacorp_bms_crit} c
    JOIN {local_kbacorp_bms_tool} t ON t.id = c.toolid WHERE c.id = :id AND c.toolid = :toolid",
    ['id' => $criterionid, 'toolid' => $toolid], MUST_EXIST);
if ((int)$criterion->companyid !== $companyid) { throw new moodle_exception('companynotallowed', 'local_kbacorporate'); }
$returnurl = new moodle_url('/local/kbacorporate/bms_route_edit.php', ['companyid' => $companyid,
    'criterionid' => $criterionid, 'toolid' => $toolid]);
if ($delete) {
    require_sesskey();
    $DB->delete_records('local_kbacorp_bms_route', ['id' => $delete, 'companyid' => $companyid,
        'criterionid' => $criterionid]);
    redirect($returnurl, get_string('bmsroutedeleted', 'local_kbacorporate'));
}
$route = $id ? $DB->get_record('local_kbacorp_bms_route', ['id' => $id, 'companyid' => $companyid,
    'criterionid' => $criterionid], '*', MUST_EXIST) : null;
$form = new \local_kbacorporate\form\bms_route_form(null, ['id' => $id, 'companyid' => $companyid,
    'criterionid' => $criterionid, 'toolid' => $toolid,
    'tools' => \local_kbacorporate\local\implementation_audits::get_tools($companyid, true),
    'courses' => \local_kbacorporate\local\repository::get_company_courses($companyid)]);
if ($route) {
    $route->toolid = $toolid;
    $form->set_data($route);
} else {
    $form->set_data((object)[
        'toolid' => $toolid,
        'evidencecriteria' => (string)($criterion->evidencehint ?? ''),
    ]);
}
if ($form->is_cancelled()) { redirect(new moodle_url('/local/kbacorporate/bms_tool.php',
    ['companyid' => $companyid, 'id' => $toolid])); }
if ($data = $form->get_data()) {
    \local_kbacorporate\local\implementation_audits::save_route($data);
    redirect($returnurl, get_string('bmsroutecreated', 'local_kbacorporate'));
}
$PAGE->set_context($context); $PAGE->set_url($returnurl); $PAGE->set_pagelayout('admin');
$PAGE->set_title(get_string('implementationgaproutes', 'local_kbacorporate')); $PAGE->set_heading(format_string($company->name));
echo $OUTPUT->header(); echo local_kbacorporate_implementation_navigation($companyid, 'tools');
echo html_writer::div(html_writer::link(new moodle_url('/local/kbacorporate/bms_tool.php',
    ['companyid' => $companyid, 'id' => $toolid]), '← ' . get_string('bmstools', 'local_kbacorporate')), 'kbacorp-backlink');
echo $OUTPUT->heading(get_string('implementationgaproutes', 'local_kbacorporate'));
echo html_writer::tag('p', format_string($criterion->name), ['class' => 'kbacorp-pagelead']);
echo html_writer::div(
    html_writer::span(get_string('implementationroutegap', 'local_kbacorporate')) .
    html_writer::span('→', 'kbacorp-route-flow-arrow') .
    html_writer::span(get_string('implementationrouteinstrument', 'local_kbacorporate')) .
    html_writer::span('→', 'kbacorp-route-flow-arrow') .
    html_writer::span(get_string('implementationrouteaction', 'local_kbacorporate')) .
    html_writer::span('→', 'kbacorp-route-flow-arrow') .
    html_writer::span(get_string('implementationrouteproof', 'local_kbacorporate')) .
    html_writer::span('→', 'kbacorp-route-flow-arrow') .
    html_writer::span(get_string('implementationroutereaudit', 'local_kbacorporate')),
    'kbacorp-route-flow'
);
$form->display();
$routes = \local_kbacorporate\local\implementation_audits::get_routes($criterionid);
if ($routes) {
    $legacycount = count(array_filter($routes, static function($item) {
        return (int)$item->actiontoolid <= 0;
    }));
    if ($legacycount) {
        echo $OUTPUT->notification(get_string('implementationroutelegacywarning', 'local_kbacorporate', $legacycount),
            'warning');
    }
    $table = new html_table(); $table->attributes['class'] = 'generaltable kbacorp-report';
    $table->head = [get_string('implementationtool', 'local_kbacorporate'),
        get_string('implementationrouteexpectedresult', 'local_kbacorporate'),
        get_string('implementationrouteevidence', 'local_kbacorporate'),
        get_string('implementationcourseoptional', 'local_kbacorporate'),
        get_string('routepriority', 'local_kbacorporate'), get_string('actions', 'local_kbacorporate')];
    foreach ($routes as $item) {
        $editurl = new moodle_url($returnurl, ['id' => $item->id]);
        $deleteurl = new moodle_url($returnurl, ['delete' => $item->id, 'sesskey' => sesskey()]);
        $table->data[] = [$item->actiontoolname ?: get_string('implementationroutelegacyincomplete', 'local_kbacorporate'),
            s($item->expectedresult ?: '—'), s($item->evidencecriteria ?: '—'), $item->coursename ?: '—',
            get_string('gappriority' . $item->priority, 'local_kbacorporate'),
            html_writer::link($editurl, get_string('edit')) . ' · ' . html_writer::link($deleteurl, get_string('delete'))];
    }
    echo html_writer::div(html_writer::table($table), 'kbacorp-table-scroll');
}
echo $OUTPUT->footer();
