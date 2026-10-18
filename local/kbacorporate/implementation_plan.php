<?php
require_once(__DIR__ . '/../../config.php');
require_login();
$context = context_system::instance();
$companyid = required_param('companyid', PARAM_INT);
$auditid = required_param('auditid', PARAM_INT);
$company = \local_kbacorporate\local\access::require_company_implementation_access($companyid, (int)$USER->id);
$audit = \local_kbacorporate\local\implementation_audits::get_audit($auditid);
if ((int)$audit->companyid !== $companyid) { throw new moodle_exception('companynotallowed', 'local_kbacorporate'); }
$action = optional_param('action', '', PARAM_ALPHA);
$program = \local_kbacorporate\local\implementation_programs::get_for_audit($auditid);
$canmethodology = has_capability('local/kbacorporate:manageimplementationaudits', $context);
$canopenkanban = $canmethodology || ($program && (int)$program->curatorid === (int)$USER->id);
if ($action === 'request') {
    require_sesskey();
    $packagecode = required_param('package', PARAM_ALPHANUMEXT);
    \local_kbacorporate\local\implementation_programs::request($companyid, $auditid, $USER->id, $packagecode);
    if (has_capability('local/kbacorporate:manageimplementationprograms', $context)) {
        redirect(new moodle_url('/local/kbacorporate/implementation_program_edit.php', [
            'companyid' => $companyid,
            'auditid' => $auditid,
        ]), get_string('implementationprogramrequested', 'local_kbacorporate'));
    }
    $requesturl = new moodle_url('/local/kbacorporate/implementation.php', ['companyid' => $companyid]);
    $requesturl->set_anchor('implementation-request');
    redirect($requesturl, get_string('implementationprogramrequested', 'local_kbacorporate'));
}
$implementationurl = new moodle_url('/local/kbacorporate/implementation.php', ['companyid' => $companyid]);
if (!$program) {
    $implementationurl->set_anchor('implementation-packages');
    redirect($implementationurl, get_string('implementationplanrequiresrequest', 'local_kbacorporate'));
}
if (!\local_kbacorporate\local\implementation_programs::plan_visible($program)) {
    if (has_capability('local/kbacorporate:manageimplementationprograms', $context)) {
        redirect(new moodle_url('/local/kbacorporate/implementation_program_edit.php', [
            'companyid' => $companyid,
            'auditid' => $auditid,
        ]), get_string('implementationplanrequiresactivation', 'local_kbacorporate'));
    }
    $implementationurl->set_anchor('implementation-request');
    redirect($implementationurl, get_string('implementationplanrequiresactivation', 'local_kbacorporate'));
}
$PAGE->set_context($context); $PAGE->set_url(new moodle_url('/local/kbacorporate/implementation_plan.php',
    ['companyid' => $companyid, 'auditid' => $auditid])); $PAGE->set_pagelayout('admin');
$PAGE->set_title(get_string('implementationplan', 'local_kbacorporate')); $PAGE->set_heading(format_string($company->name));
$rows = \local_kbacorporate\local\implementation_audits::get_gap_rows($auditid);
$planrows = array_values(array_filter($rows, static function($row) { return $row->gap === null || $row->gap > 0; }));
$critical = count(array_filter($planrows, static function($row) { return $row->gappriority === 'critical'; }));
$mapped = count(array_filter($planrows, static function($row) {
    return !empty(array_filter($row->routes, static function($route) {
        return (int)$route->actiontoolid > 0;
    }));
}));
echo $OUTPUT->header(); echo local_kbacorporate_implementation_navigation($companyid, 'clients');
echo local_kbacorporate_company_context_banner($company);
echo html_writer::div(html_writer::link(new moodle_url('/local/kbacorporate/implementation.php',
    ['companyid' => $companyid]), '← ' . get_string('bmsimplementation', 'local_kbacorporate')), 'kbacorp-backlink');
echo html_writer::div(html_writer::link(new moodle_url('/local/kbacorporate/implementation_audit.php',
    ['companyid' => $companyid, 'id' => $auditid]), '← ' . get_string('backtoimplementationaudit', 'local_kbacorporate')), 'kbacorp-backlink');
echo $OUTPUT->heading(get_string('implementationplan', 'local_kbacorporate'));
echo html_writer::tag('p', get_string('implementationplandesc', 'local_kbacorporate'), ['class' => 'kbacorp-pagelead']);
echo $OUTPUT->notification(get_string('implementationprogramstate', 'local_kbacorporate',
    \local_kbacorporate\local\implementation_programs::label($program)), 'success');
if (has_capability('local/kbacorporate:manageimplementationprograms', $context)) {
    echo $OUTPUT->single_button(new moodle_url('/local/kbacorporate/implementation_program_edit.php',
        ['companyid' => $companyid, 'auditid' => $auditid]),
        get_string('editimplementationprogram', 'local_kbacorporate'), 'get');
}
echo html_writer::start_div('kbacorp-toolbar');
echo $OUTPUT->single_button(new moodle_url('/local/kbacorporate/implementation_actions.php',
    ['companyid' => $companyid, 'auditid' => $auditid]), get_string('implementationactions', 'local_kbacorporate'), 'get');
echo $OUTPUT->single_button(new moodle_url('/local/kbacorporate/implementation_tasks.php',
    ['companyid' => $companyid, 'auditid' => $auditid]), get_string('implementationtasks', 'local_kbacorporate'), 'get');
if ($canopenkanban) {
    echo $OUTPUT->single_button(new moodle_url('/local/kbacorporate/implementation_board.php',
        ['companyid' => $companyid, 'auditid' => $auditid]),
        get_string('kanbanopenfiltered', 'local_kbacorporate'), 'get');
}
echo $OUTPUT->single_button(new moodle_url('/local/kbacorporate/implementation_section_progress.php',
    ['companyid' => $companyid, 'auditid' => $auditid]),
    get_string('implementationsectionprogress', 'local_kbacorporate'), 'get');
echo html_writer::end_div();
echo html_writer::start_div('kbacorp-summary-grid');
echo html_writer::div(html_writer::tag('strong', count($planrows)) . html_writer::span(get_string('gapcriteria', 'local_kbacorporate')), 'kbacorp-summary-card kbacorp-tone-amber');
echo html_writer::div(html_writer::tag('strong', $critical) . html_writer::span(get_string('criticalgaps', 'local_kbacorporate')), 'kbacorp-summary-card kbacorp-tone-red');
echo html_writer::div(html_writer::tag('strong', $mapped) . html_writer::span(get_string('mappedgaps', 'local_kbacorporate')), 'kbacorp-summary-card kbacorp-tone-blue');
echo html_writer::end_div();
$comparison = \local_kbacorporate\local\implementation_audits::compare_previous($auditid);
if ($comparison) {
    $change = $comparison->averagechange === null ? '—' : (($comparison->averagechange > 0 ? '+' : '') . format_float($comparison->averagechange, 2));
    echo $OUTPUT->notification(get_string('previousauditcomparison', 'local_kbacorporate', (object)[
        'title' => format_string($comparison->audit->title), 'matched' => $comparison->matched, 'change' => $change]), 'info');
}
if (!$planrows) { echo $OUTPUT->notification(get_string('noimplementationgaps', 'local_kbacorporate'), 'success'); }
else {
    $table = new html_table(); $table->attributes['class'] = 'generaltable kbacorp-report';
    $table->head = [get_string('bmssection', 'local_kbacorporate'), get_string('bmscriterionname', 'local_kbacorporate'),
        get_string('bmsmaturitylevel', 'local_kbacorporate'), get_string('targetscore', 'local_kbacorporate'),
        get_string('gaptotarget', 'local_kbacorporate'), get_string('routepriority', 'local_kbacorporate'),
        get_string('implementationgaproutes', 'local_kbacorporate')];
    foreach ($planrows as $row) {
        $routes = [];
        foreach ($row->routes as $route) {
            $parts = [];
            if ((int)$route->actiontoolid <= 0) {
                $parts[] = html_writer::div(get_string('implementationroutelegacyincomplete', 'local_kbacorporate'),
                    'kbacorp-implementation-route-warning');
            } else {
                $parts[] = html_writer::div(
                    html_writer::span(get_string('implementationtool', 'local_kbacorporate'),
                        'kbacorp-implementation-route-label') .
                    html_writer::span(format_string($route->actiontoolname), 'kbacorp-implementation-route-value'),
                    'kbacorp-implementation-route-row'
                );
            }
            if ((string)$route->expectedresult !== '') {
                $parts[] = html_writer::div(
                    html_writer::span(get_string('implementationrouteexpectedresult', 'local_kbacorporate'),
                        'kbacorp-implementation-route-label') .
                    html_writer::span(s($route->expectedresult), 'kbacorp-implementation-route-value'),
                    'kbacorp-implementation-route-row'
                );
            }
            if ((string)$route->evidencecriteria !== '') {
                $parts[] = html_writer::div(
                    html_writer::span(get_string('implementationrouteevidence', 'local_kbacorporate'),
                        'kbacorp-implementation-route-label') .
                    html_writer::span(s($route->evidencecriteria), 'kbacorp-implementation-route-value'),
                    'kbacorp-implementation-route-row'
                );
            }
            $course = $route->coursename
                ? html_writer::link(new moodle_url('/course/view.php', ['id' => $route->courseid]),
                    format_string($route->coursename))
                : get_string('implementationcoursenotrequired', 'local_kbacorporate');
            $parts[] = html_writer::div(
                html_writer::span(get_string('implementationcourseoptional', 'local_kbacorporate'),
                    'kbacorp-implementation-route-label') .
                html_writer::span($course, 'kbacorp-implementation-route-value'),
                'kbacorp-implementation-route-row'
            );
            if ($route->note) {
                $parts[] = html_writer::div(s($route->note), 'kbacorp-implementation-route-note');
            }
            $createurl = new moodle_url('/local/kbacorporate/implementation_task_edit.php', ['companyid' => $companyid,
                'auditid' => $auditid, 'itemid' => $row->id, 'routeid' => $route->id]);
            $routes[] = html_writer::div(implode('', $parts) .
                (\local_kbacorporate\local\implementation_programs::plan_editable($program) &&
                    (int)$route->actiontoolid > 0
                    ? '<br>' . html_writer::link($createurl,
                        get_string('createimplementationtask', 'local_kbacorporate')) : ''),
                'kbacorp-implementation-route-card');
        }
        if (!$routes) {
            $noroute = $canmethodology
                ? html_writer::link(new moodle_url('/local/kbacorporate/bms_route_edit.php', [
                    'companyid' => $companyid,
                    'toolid' => $audit->toolid,
                    'criterionid' => $row->criterionid,
                ]), get_string('addimplementationgaproute', 'local_kbacorporate'))
                : get_string('implementationactionnoroute', 'local_kbacorporate');
            $routes[] = $noroute .
                (\local_kbacorporate\local\implementation_programs::plan_editable($program) ? ' · ' . html_writer::link(
                    new moodle_url('/local/kbacorporate/implementation_task_edit.php', ['companyid' => $companyid,
                        'auditid' => $auditid, 'itemid' => $row->id]), get_string('createimplementationtask', 'local_kbacorporate')) : '');
        }
        $criterioncell = format_string($row->criterionname);
        $competencylabels = [];
        foreach (\local_kbacorporate\local\implementation_audits::get_item_competencies($row) as $competency) {
            $competencylabels[] = format_string($competency['shortname'] ?? '') . ' · ' .
                get_string('bmscompetencytargetshort', 'local_kbacorporate', (int)($competency['targetlevel'] ?? 0));
        }
        if ($competencylabels) {
            $criterioncell .= html_writer::div(get_string('linkedcompetencies', 'local_kbacorporate') . ': ' .
                implode('; ', $competencylabels), 'kbacorp-bms-item-competencies');
        }
        $table->data[] = [format_string($row->sectionname ?: '—'), $criterioncell,
            (int)$row->score < 0 ? get_string('notassessed', 'local_kbacorporate') : (int)$row->score,
            (int)$row->targetscore < 0 ? '—' : (int)$row->targetscore,
            $row->gap === null ? '—' : $row->gap,
            get_string('gappriority' . $row->gappriority, 'local_kbacorporate'), implode('<br>', $routes)];
    }
    echo html_writer::div(html_writer::table($table), 'kbacorp-table-scroll');
}
echo $OUTPUT->footer();
