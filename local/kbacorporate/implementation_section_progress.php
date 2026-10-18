<?php
require_once(__DIR__ . '/../../config.php');
require_login();

$context = context_system::instance();
$companyid = optional_param('companyid', 0, PARAM_INT);
$auditid = optional_param('auditid', 0, PARAM_INT);
$export = optional_param('export', '', PARAM_ALPHA);
$canmanage = has_capability('local/kbacorporate:manageimplementationaudits', $context) ||
    has_capability('local/kbacorporate:managecompanyimplementation', $context);

$companies = \local_kbacorporate\local\implementation_section_progress::get_accessible_companies(
    (int)$USER->id,
    $canmanage
);
if (!$companies) {
    throw new moodle_exception('curatorworkspacenotassigned', 'local_kbacorporate');
}
if ($companyid === 0 && count($companies) === 1) {
    $companyid = (int)array_key_first($companies);
}
if ($companyid > 0) {
    if (!isset($companies[$companyid]) ||
            !\local_kbacorporate\local\implementation_section_progress::can_view_company(
                $companyid,
                (int)$USER->id,
                $canmanage
            )) {
        throw new moodle_exception('companynotallowed', 'local_kbacorporate');
    }
    $company = $companies[$companyid];
} else {
    $company = null;
}

$audits = $companyid > 0
    ? \local_kbacorporate\local\implementation_section_progress::get_confirmed_audits($companyid)
    : [];
if ($auditid > 0 && !isset($audits[$auditid])) {
    // A company switch may leave the previous audit id in the GET form.
    // Reset to the default confirmed audit without exposing cross-company data.
    $auditid = 0;
}
if ($auditid === 0 && $audits) {
    $defaultaudit = \local_kbacorporate\local\implementation_section_progress::default_audit($audits);
    $auditid = $defaultaudit ? (int)$defaultaudit->id : 0;
}
$model = $auditid > 0
    ? \local_kbacorporate\local\implementation_section_progress::build($companyid, $auditid)
    : null;

$params = [];
if ($companyid > 0) {
    $params['companyid'] = $companyid;
}
if ($auditid > 0) {
    $params['auditid'] = $auditid;
}
$pageurl = new moodle_url('/local/kbacorporate/implementation_section_progress.php', $params);

if ($export === 'csv' && $model) {
    require_once($CFG->libdir . '/csvlib.class.php');
    $csv = new csv_export_writer('semicolon');
    $csv->set_filename('bms-section-progress-' . $companyid . '-' . $auditid . '-' .
        userdate(time(), '%Y-%m-%d'));
    $csv->add_data([
        get_string('bmssectioncode', 'local_kbacorporate'),
        get_string('bmssectionname', 'local_kbacorporate'),
        get_string('sectionprogresscurrentaverage', 'local_kbacorporate'),
        get_string('sectionprogresstargetaverage', 'local_kbacorporate'),
        get_string('sectionprogresspreviousaverage', 'local_kbacorporate'),
        get_string('sectionprogresschange', 'local_kbacorporate'),
        get_string('sectionprogresstotalcriteria', 'local_kbacorporate'),
        get_string('sectionprogressassessedcriteria', 'local_kbacorporate'),
        get_string('sectionprogressgapcriteria', 'local_kbacorporate'),
        get_string('sectionprogressattentioncriteria', 'local_kbacorporate'),
        get_string('sectionprogresscoveredcriteria', 'local_kbacorporate'),
        get_string('sectionprogressactioncoverage', 'local_kbacorporate'),
        get_string('sectionprogresstasks', 'local_kbacorporate'),
        get_string('sectionprogresscompletedtasks', 'local_kbacorporate'),
        get_string('sectionprogressworkflowprogress', 'local_kbacorporate'),
        get_string('sectionprogressacceptedevidence', 'local_kbacorporate'),
        get_string('sectionprogressoverdue', 'local_kbacorporate'),
        get_string('sectionprogressunassigned', 'local_kbacorporate'),
        get_string('status'),
    ]);
    $safe = static function($value): string {
        $value = (string)$value;
        return preg_match('/^[=+\-@]/u', $value) ? "'" . $value : $value;
    };
    foreach ($model->sections as $section) {
        $csv->add_data(array_map($safe, [
            $section->code,
            $section->name,
            $section->currentaverage === null ? '' : $section->currentaverage,
            $section->targetaverage === null ? '' : $section->targetaverage,
            $section->previousaverage === null ? '' : $section->previousaverage,
            $section->scorechange === null ? '' : $section->scorechange,
            $section->totalcriteria,
            $section->assessedcriteria,
            $section->gapcriteria,
            $section->attentioncriteria,
            $section->coveredcriteria,
            $section->actioncoverage . '%',
            $section->tasktotal,
            $section->completedtasks,
            $section->workflowprogress . '%',
            $section->acceptedevidence,
            $section->overduetasks,
            $section->unassignedtasks,
            get_string('sectionprogressstatus' . $section->status, 'local_kbacorporate'),
        ]));
    }
    $csv->download_file();
    exit;
}

$PAGE->set_context($context);
$PAGE->set_url($pageurl);
$PAGE->set_pagelayout('standard');
$PAGE->set_title(get_string('implementationsectionprogress', 'local_kbacorporate'));
$PAGE->set_heading(get_string('implementationsectionprogress', 'local_kbacorporate'));

$progressbar = static function(float $value, string $tone = ''): string {
    $width = max(0, min(100, $value));
    $class = 'kbacorp-section-progressbar';
    if ($tone !== '') {
        $class .= ' kbacorp-section-progressbar-' . $tone;
    }
    return html_writer::div(
        html_writer::span('', '', ['style' => 'width:' . sprintf('%.1F', $width) . '%']),
        $class
    );
};
$statusbadge = static function(string $status): string {
    return html_writer::span(
        get_string('sectionprogressstatus' . $status, 'local_kbacorporate'),
        'kbacorp-section-status kbacorp-section-status-' . $status
    );
};
$taskstatus = static function(string $status): string {
    $key = 'implementationtaskstatus' . $status;
    return get_string_manager()->string_exists($key, 'local_kbacorporate')
        ? get_string($key, 'local_kbacorporate') : s($status);
};
$evidencelabel = static function($task): string {
    $latest = $task->latestevidence ?? null;
    if (!$latest) {
        return get_string('sectionprogressevidencenone', 'local_kbacorporate');
    }
    $key = 'implevidencestatus' . (string)$latest->status;
    return get_string_manager()->string_exists($key, 'local_kbacorporate')
        ? get_string($key, 'local_kbacorporate') : s((string)$latest->status);
};

echo $OUTPUT->header();
echo local_kbacorporate_implementation_navigation($companyid, 'sectionprogress');
if ($company) {
    echo local_kbacorporate_company_context_banner($company);
}

echo html_writer::start_div('kbacorp-curator-hero');
echo html_writer::div(get_string('bmsimplementation', 'local_kbacorporate'), 'kbacorp-eyebrow');
echo $OUTPUT->heading(get_string('implementationsectionprogress', 'local_kbacorporate'), 2);
echo html_writer::tag('p', get_string('implementationsectionprogressdesc', 'local_kbacorporate'), [
    'class' => 'kbacorp-pagelead',
]);
echo html_writer::end_div();

$companyoptions = [0 => get_string('selectcompany', 'local_kbacorporate')];
foreach ($companies as $availablecompany) {
    $companyoptions[(int)$availablecompany->id] = format_string($availablecompany->name);
}
$auditoptions = [0 => get_string('sectionprogressselectaudit', 'local_kbacorporate')];
foreach ($audits as $availableaudit) {
    $index = $availableaudit->indexvalue === null ? '—' : format_float((float)$availableaudit->indexvalue, 1);
    $auditoptions[(int)$availableaudit->id] = format_string($availableaudit->title) . ' · ' .
        userdate((int)$availableaudit->auditdate, get_string('strftimedatefullshort')) . ' · ' .
        get_string('bmsindexvalue', 'local_kbacorporate', $index);
}

echo html_writer::start_tag('form', [
    'method' => 'get',
    'action' => (new moodle_url('/local/kbacorporate/implementation_section_progress.php'))->out(false),
    'class' => 'kbacorp-section-progress-filters',
]);
echo html_writer::label(get_string('company', 'local_kbacorporate'), 'section-progress-company');
echo html_writer::select($companyoptions, 'companyid', $companyid, false, [
    'id' => 'section-progress-company',
    'class' => 'custom-select',
]);
echo html_writer::label(get_string('sectionprogressaudit', 'local_kbacorporate'), 'section-progress-audit');
$auditselectattributes = [
    'id' => 'section-progress-audit',
    'class' => 'custom-select',
];
if ($companyid === 0) {
    $auditselectattributes['disabled'] = 'disabled';
}
echo html_writer::select($auditoptions, 'auditid', $auditid, false, $auditselectattributes);
echo html_writer::tag('button', get_string('applyfilter', 'local_kbacorporate'), [
    'type' => 'submit',
    'class' => 'btn btn-primary',
]);
echo html_writer::link(new moodle_url('/local/kbacorporate/implementation_section_progress.php'),
    get_string('resetfilters', 'local_kbacorporate'), ['class' => 'btn btn-secondary']);
echo html_writer::end_tag('form');

if (!$company) {
    echo $OUTPUT->notification(get_string('sectionprogressselectcompany', 'local_kbacorporate'), 'info');
    echo $OUTPUT->footer();
    exit;
}
if (!$model) {
    echo $OUTPUT->notification(get_string('sectionprogressnoaudit', 'local_kbacorporate'), 'warning');
    echo $OUTPUT->footer();
    exit;
}

$audit = $model->audit;
$canedit = \local_kbacorporate\local\access::can_manage_company_implementation(
    $companyid,
    (int)$USER->id
);
$canopenportfolio = has_capability('local/kbacorporate:manageimplementationaudits', $context) ||
    ($model->program && (int)$model->program->curatorid === (int)$USER->id);

echo html_writer::start_div('kbacorp-toolbar');
if ($canedit) {
    echo html_writer::link(new moodle_url('/local/kbacorporate/implementation_audit.php', [
        'companyid' => $companyid,
        'id' => $auditid,
    ]), get_string('openimplementationaudit', 'local_kbacorporate'), ['class' => 'btn btn-outline-primary']);
}
if ($model->program && \local_kbacorporate\local\implementation_programs::plan_visible($model->program)) {
    echo html_writer::link(new moodle_url('/local/kbacorporate/implementation_plan.php', [
        'companyid' => $companyid,
        'auditid' => $auditid,
    ]), get_string('implementationplan', 'local_kbacorporate'), ['class' => 'btn btn-outline-primary']);
}
if ($canopenportfolio) {
    echo html_writer::link(new moodle_url('/local/kbacorporate/implementation_board.php', [
        'companyid' => $companyid,
        'auditid' => $auditid,
    ]), get_string('kanbanopenfiltered', 'local_kbacorporate'), ['class' => 'btn btn-outline-primary']);
}
echo html_writer::link(new moodle_url('/local/kbacorporate/implementation_audit_comparison.php', [
    'companyid' => $companyid,
    'auditid' => $auditid,
]), get_string('auditcomparison', 'local_kbacorporate'), ['class' => 'btn btn-outline-secondary']);
if ($canopenportfolio) {
    echo html_writer::link(new moodle_url('/local/kbacorporate/implementation_analytics.php', [
        'companyid' => $companyid,
    ]), get_string('implementationanalytics', 'local_kbacorporate'), ['class' => 'btn btn-outline-secondary']);
}
echo html_writer::link(new moodle_url('/local/kbacorporate/implementation_section_progress.php',
    $params + ['export' => 'csv']), get_string('sectionprogressexportcsv', 'local_kbacorporate'), [
        'class' => 'btn btn-secondary',
    ]);
echo html_writer::end_div();

echo $OUTPUT->notification(get_string('sectionprogressreadonlynotice', 'local_kbacorporate'), 'info');
if (!$model->program) {
    echo $OUTPUT->notification(get_string('sectionprogressprogrammissing', 'local_kbacorporate'), 'warning');
}
if ($model->previousaudit) {
    echo $OUTPUT->notification(get_string('sectionprogresscomparison', 'local_kbacorporate', (object)[
        'title' => format_string($model->previousaudit->title),
        'date' => userdate((int)$model->previousaudit->auditdate, get_string('strftimedatefullshort')),
    ]), 'info');
}

echo html_writer::div(
    format_string($audit->title) . ' · ' . format_string($audit->toolname) . ' · ' .
    userdate((int)$audit->auditdate, get_string('strftimedatefullshort')),
    'kbacorp-pagelead'
);

$summary = $model->summary;
$indexvalue = $audit->indexvalue === null ? '—' : format_float((float)$audit->indexvalue, 1);
$indexdelta = null;
if ($model->previousaudit && $audit->indexvalue !== null && $model->previousaudit->indexvalue !== null) {
    $indexdelta = round((float)$audit->indexvalue - (float)$model->previousaudit->indexvalue, 1);
}
echo html_writer::start_div('kbacorp-summary-grid');
$cards = [
    [$indexvalue, 'bmsindex', 'violet'],
    [$summary->sectioncount, 'sectionprogresssections', 'blue'],
    [$summary->sectionswithgaps, 'sectionprogresssectionswithgaps', 'amber'],
    [format_float($summary->actioncoverage, 1) . '%', 'sectionprogressactioncoverage', 'blue'],
    [format_float($summary->workflowprogress, 1) . '%', 'sectionprogressworkflowprogress', 'green'],
    [$summary->overduetasks, 'sectionprogressoverdue', $summary->overduetasks ? 'red' : 'green'],
];
foreach ($cards as $card) {
    echo html_writer::div(
        html_writer::tag('strong', (string)$card[0]) .
        html_writer::span(get_string($card[1], 'local_kbacorporate')),
        'kbacorp-summary-card kbacorp-tone-' . $card[2]
    );
}
echo html_writer::end_div();
if ($indexdelta !== null) {
    echo html_writer::div(get_string('sectionprogressindexchange', 'local_kbacorporate',
        ($indexdelta > 0 ? '+' : '') . format_float($indexdelta, 1)),
        'kbacorp-section-index-delta ' . ($indexdelta > 0 ? 'is-positive' :
            ($indexdelta < 0 ? 'is-negative' : '')));
}

$table = new html_table();
$table->attributes['class'] = 'generaltable kbacorp-report kbacorp-section-progress-table';
$table->head = [
    get_string('bmssection', 'local_kbacorporate'),
    get_string('sectionprogressmaturity', 'local_kbacorporate'),
    get_string('sectionprogresscriteria', 'local_kbacorporate'),
    get_string('sectionprogressactions', 'local_kbacorporate'),
    get_string('sectionprogressworkflow', 'local_kbacorporate'),
    get_string('sectionprogressevidence', 'local_kbacorporate'),
    get_string('sectionprogressrisks', 'local_kbacorporate'),
];

foreach ($model->sections as $section) {
    $sectionanchor = 'section-' . clean_param((string)$section->code, PARAM_ALPHANUMEXT);
    $sectionname = $section->name !== ''
        ? format_string($section->name)
        : get_string('bmsgeneralsection', 'local_kbacorporate');
    $sectioncell = html_writer::tag('strong', s($section->code) . ' · ' . $sectionname) .
        html_writer::div($statusbadge($section->status), 'kbacorp-section-status-wrap') .
        html_writer::div(html_writer::link('#' . $sectionanchor,
            get_string('sectionprogressopendetails', 'local_kbacorporate')), 'kbacorp-section-detail-link');

    $maturity = html_writer::tag('strong', $section->currentaverage === null ? '—' :
        format_float($section->currentaverage, 2) . ' / ' . (int)$audit->scalemax);
    $maturity .= html_writer::div(get_string('sectionprogresstargetvalue', 'local_kbacorporate',
        $section->targetaverage === null ? '—' : format_float($section->targetaverage, 2)), 'text-muted');
    if ($section->scorechange !== null) {
        $maturity .= html_writer::div(get_string('sectionprogresschangevalue', 'local_kbacorporate',
            ($section->scorechange > 0 ? '+' : '') . format_float($section->scorechange, 2)),
            $section->scorechange > 0 ? 'kbacorp-index-delta-positive' :
                ($section->scorechange < 0 ? 'kbacorp-index-delta-negative' : 'text-muted'));
    }

    $criteria = get_string('sectionprogressassessedvalue', 'local_kbacorporate', (object)[
        'assessed' => $section->assessedcriteria,
        'total' => $section->totalcriteria,
    ]);
    $criteria .= html_writer::div(get_string('sectionprogressgapsvalue', 'local_kbacorporate',
        $section->gapcriteria), $section->gapcriteria ? 'text-danger' : 'text-success');
    $criteria .= html_writer::div(get_string('sectionprogressattentionvalue', 'local_kbacorporate',
        $section->attentioncriteria), 'text-muted');

    $actions = html_writer::tag('strong', get_string('sectionprogresscoveredvalue', 'local_kbacorporate',
        (object)['covered' => $section->coveredcriteria, 'gaps' => $section->gapcriteria]));
    $actions .= html_writer::div(format_float($section->actioncoverage, 1) . '%',
        'kbacorp-section-progress-value');
    $actions .= $progressbar((float)$section->actioncoverage, 'coverage');

    $workflow = html_writer::tag('strong', format_float($section->workflowprogress, 1) . '%');
    $workflow .= html_writer::div(get_string('sectionprogresstaskstatusvalue', 'local_kbacorporate', (object)[
        'planned' => $section->plannedtasks,
        'inprogress' => $section->inprogresstasks,
        'review' => $section->reviewtasks,
        'completed' => $section->completedtasks,
    ]), 'text-muted');
    $workflow .= $progressbar((float)$section->workflowprogress, 'workflow');

    $evidence = get_string('sectionprogressacceptedvalue', 'local_kbacorporate',
        $section->acceptedevidence);
    $evidence .= html_writer::div(get_string('sectionprogresssubmittedvalue', 'local_kbacorporate',
        $section->submittedevidence), 'text-muted');
    if ($section->rejectedevidence) {
        $evidence .= html_writer::div(get_string('sectionprogressrejectedvalue', 'local_kbacorporate',
            $section->rejectedevidence), 'text-danger');
    }

    $risks = get_string('sectionprogressoverduevalue', 'local_kbacorporate', $section->overduetasks);
    $risks .= html_writer::div(get_string('sectionprogressunassignedvalue', 'local_kbacorporate',
        $section->unassignedtasks), 'text-muted');

    $table->data[] = [$sectioncell, $maturity, $criteria, $actions, $workflow, $evidence, $risks];
}
echo html_writer::div(html_writer::table($table), 'kbacorp-table-scroll');

echo $OUTPUT->heading(get_string('sectionprogressdetails', 'local_kbacorporate'), 3);
foreach ($model->sections as $section) {
    $sectionanchor = 'section-' . clean_param((string)$section->code, PARAM_ALPHANUMEXT);
    $sectionname = $section->name !== ''
        ? format_string($section->name)
        : get_string('bmsgeneralsection', 'local_kbacorporate');
    $summarylabel = s($section->code) . ' · ' . $sectionname . ' — ' .
        get_string('sectionprogressgapsvalue', 'local_kbacorporate', $section->gapcriteria);
    echo html_writer::start_tag('details', [
        'class' => 'kbacorp-section-progress-details',
        'id' => $sectionanchor,
    ]);
    echo html_writer::tag('summary', $summarylabel . ' ' . $statusbadge($section->status));
    if (!$section->criteria) {
        echo html_writer::div(get_string('sectionprogressnogaps', 'local_kbacorporate'),
            'alert alert-success');
        echo html_writer::end_tag('details');
        continue;
    }

    $detailtable = new html_table();
    $detailtable->attributes['class'] = 'generaltable kbacorp-section-progress-detail-table';
    $detailtable->head = [
        get_string('bmscriterionname', 'local_kbacorporate'),
        get_string('bmsmaturitylevel', 'local_kbacorporate'),
        get_string('targetscore', 'local_kbacorporate'),
        get_string('gaptotarget', 'local_kbacorporate'),
        get_string('routepriority', 'local_kbacorporate'),
        get_string('implementationactions', 'local_kbacorporate'),
    ];
    foreach ($section->criteria as $criterion) {
        $criterioncell = html_writer::tag('strong', format_string($criterion->criterionname)) .
            html_writer::div(s($criterion->criterioncode), 'text-muted');
        $taskparts = [];
        foreach ($criterion->tasks as $task) {
            $ownername = get_string('notassigned', 'local_kbacorporate');
            if ((int)$task->ownerid > 0) {
                $ownername = fullname($task);
            }
            $due = (int)$task->duedate > 0
                ? userdate((int)$task->duedate, get_string('strftimedatefullshort'))
                : get_string('kanbannoduedate', 'local_kbacorporate');
            $taskparts[] = html_writer::div(
                html_writer::link(new moodle_url('/local/kbacorporate/implementation_task.php', [
                    'id' => (int)$task->id,
                ]), format_string($task->title)) .
                html_writer::div($taskstatus((string)$task->status) . ' · ' . s($ownername) . ' · ' .
                    $due . ' · ' . $evidencelabel($task), 'text-muted'),
                'kbacorp-section-task-line'
            );
        }
        if (!$taskparts) {
            if ($canedit && $model->program &&
                    \local_kbacorporate\local\implementation_programs::plan_editable($model->program)) {
                $actionurl = new moodle_url('/local/kbacorporate/implementation_actions.php', [
                    'companyid' => $companyid,
                    'auditid' => $auditid,
                    'focusitemid' => $criterion->itemid,
                ]);
                $actionurl->set_anchor('action-item-' . $criterion->itemid);
                $taskparts[] = html_writer::link($actionurl,
                    get_string('implementationactioncreateone', 'local_kbacorporate'));
            } else {
                $taskparts[] = html_writer::span(
                    get_string('sectionprogressnoaction', 'local_kbacorporate'),
                    'text-muted'
                );
            }
        }
        $detailtable->data[] = [
            $criterioncell,
            $criterion->score,
            $criterion->targetscore,
            $criterion->gap,
            get_string('gappriority' . $criterion->priority, 'local_kbacorporate'),
            implode('', $taskparts),
        ];
    }
    echo html_writer::div(html_writer::table($detailtable), 'kbacorp-table-scroll');
    echo html_writer::end_tag('details');
}

echo $OUTPUT->footer();
