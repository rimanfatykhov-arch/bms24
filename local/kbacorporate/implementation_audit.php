<?php
require_once(__DIR__ . '/../../config.php');
require_login(); $context = context_system::instance(); $companyid = required_param('companyid', PARAM_INT); $id = required_param('id', PARAM_INT); $action = optional_param('action', '', PARAM_ALPHA);
$company = \local_kbacorporate\local\access::require_company_implementation_access($companyid, (int)$USER->id); $audit = \local_kbacorporate\local\implementation_audits::get_audit($id);
if ((int)$audit->companyid !== $companyid) { throw new moodle_exception('companynotallowed', 'local_kbacorporate'); }
$program = \local_kbacorporate\local\implementation_programs::get_for_audit($id);
$canopenkanban = has_capability('local/kbacorporate:manageimplementationaudits', $context) ||
    ($program && (int)$program->curatorid === (int)$USER->id);
if ($action === 'confirm') { require_sesskey(); \local_kbacorporate\local\implementation_audits::confirm_audit($id, $USER->id); redirect(new moodle_url('/local/kbacorporate/implementation_audit.php', ['id' => $id, 'companyid' => $companyid]), get_string('bmsauditconfirmed', 'local_kbacorporate')); }
$PAGE->set_context($context); $PAGE->set_url(new moodle_url('/local/kbacorporate/implementation_audit.php', ['id' => $id, 'companyid' => $companyid])); $PAGE->set_pagelayout('admin'); $PAGE->set_title(format_string($audit->title)); $PAGE->set_heading(format_string($company->name));
echo $OUTPUT->header(); echo local_kbacorporate_implementation_navigation($companyid, 'audits');
echo local_kbacorporate_company_context_banner($company); echo html_writer::div(html_writer::link(new moodle_url('/local/kbacorporate/implementation.php', ['companyid' => $companyid]), '← ' . get_string('bmsimplementation', 'local_kbacorporate')) . ' · ' . html_writer::link(new moodle_url('/local/kbacorporate/implementation_audits.php', ['companyid' => $companyid]), get_string('implementationaudits', 'local_kbacorporate')), 'kbacorp-backlink'); echo $OUTPUT->heading(format_string($audit->title));
$versionlabel = !empty($audit->toolversionlabel) ? ' · ' . get_string('bmstoolauditversion',
    'local_kbacorporate', s($audit->toolversionlabel)) : '';
echo html_writer::div(format_string($audit->toolname) . $versionlabel . ' · ' .
    get_string('bmsauditstatus' . $audit->status, 'local_kbacorporate'), 'kbacorp-pagelead');
echo html_writer::start_div('kbacorp-toolbar');
echo $OUTPUT->single_button(new moodle_url('/local/kbacorporate/implementation_plan.php',
    ['auditid' => $id, 'companyid' => $companyid]), get_string('implementationplan', 'local_kbacorporate'), 'get');
echo $OUTPUT->single_button(new moodle_url('/local/kbacorporate/implementation_actions.php',
    ['auditid' => $id, 'companyid' => $companyid]), get_string('implementationactions', 'local_kbacorporate'), 'get');
if ($canopenkanban) {
    echo $OUTPUT->single_button(new moodle_url('/local/kbacorporate/implementation_board.php',
        ['auditid' => $id, 'companyid' => $companyid]),
        get_string('kanbanopenfiltered', 'local_kbacorporate'), 'get');
}
echo $OUTPUT->single_button(new moodle_url('/local/kbacorporate/implementation_section_progress.php',
    ['auditid' => $id, 'companyid' => $companyid]),
    get_string('implementationsectionprogress', 'local_kbacorporate'), 'get');
if ((string)$audit->status === \local_kbacorporate\local\implementation_audits::STATUS_CONFIRMED) {
    echo $OUTPUT->single_button(new moodle_url('/local/kbacorporate/implementation_audit_comparison.php',
        ['auditid' => $id, 'companyid' => $companyid]),
        get_string('auditcomparison', 'local_kbacorporate'), 'get');
}
if (has_capability('local/kbacorporate:vieweconomicindicators', $context)) {
    echo $OUTPUT->single_button(new moodle_url('/local/kbacorporate/economic_dashboard.php',
        ['auditid' => $id, 'companyid' => $companyid]),
        get_string('economicdashboard', 'local_kbacorporate'), 'get');
}
echo html_writer::end_div();
$economic = \local_kbacorporate\local\implementation_audits::get_economic_snapshot($id);
echo $OUTPUT->heading(get_string('economicindicators', 'local_kbacorporate'), 2);
if ($economic) {
    $economicmetrics = \local_kbacorporate\local\implementation_audits::calculate_economic_metrics($economic);
    $money = static function($value) use ($economic) {
        return $value === null ? '—' : format_float((float)$value, 2) . ' ' . s($economic->currency);
    };
    echo html_writer::div(userdate($economic->periodstart, get_string('strftimedatefullshort')) . ' — ' .
        userdate($economic->periodend, get_string('strftimedatefullshort')) . ' · ' .
        get_string('source' . $economic->source, 'local_kbacorporate') . ' · ' .
        get_string($economic->verified ? 'economicverifiedyes' : 'economicverifiedno', 'local_kbacorporate'),
        'kbacorp-pagelead');
    echo html_writer::start_div('kbacorp-summary-grid');
    foreach ([
        ['revenue', $money($economic->revenue), 'green'],
        ['operatingprofit', $money($economic->operatingprofit), 'blue'],
        ['netprofit', $money($economic->netprofit), 'violet'],
        ['operatingprofitperemployee', $money($economicmetrics->operatingprofitperemployee), 'amber'],
    ] as $metric) {
        echo html_writer::div(html_writer::tag('strong', $metric[1]) . html_writer::span(
            get_string('economic' . $metric[0], 'local_kbacorporate')),
            'kbacorp-summary-card kbacorp-tone-' . $metric[2]);
    }
    echo html_writer::end_div();
    $economictable = new html_table();
    $economictable->attributes['class'] = 'generaltable kbacorp-report';
    $economictable->head = [get_string('economicindicator', 'local_kbacorporate'),
        get_string('economicvalue', 'local_kbacorporate')];
    foreach ([
        'grossprofit' => $money($economic->grossprofit),
        'grossmargin' => $economicmetrics->grossmargin === null ? '—' : format_float($economicmetrics->grossmargin, 2) . '%',
        'operatingmargin' => $economicmetrics->operatingmargin === null ? '—' : format_float($economicmetrics->operatingmargin, 2) . '%',
        'netmargin' => $economicmetrics->netmargin === null ? '—' : format_float($economicmetrics->netmargin, 2) . '%',
        'totaltaxes' => $money($economicmetrics->totaltaxes),
        'taxburden' => $economicmetrics->taxburden === null ? '—' : format_float($economicmetrics->taxburden, 2) . '%',
        'employees' => $economic->employees === null ? '—' : (int)$economic->employees,
        'averagesalary' => $money($economic->averagesalary),
        'revenueperemployee' => $money($economicmetrics->revenueperemployee),
        'payrollshare' => $economicmetrics->payrollshare === null ? '—' : format_float($economicmetrics->payrollshare, 2) . '%',
    ] as $key => $value) {
        $economictable->data[] = [get_string('economic' . $key, 'local_kbacorporate'), $value];
    }
    echo html_writer::table($economictable);
    $previouscomparison = \local_kbacorporate\local\implementation_audits::compare_economic_previous($id);
    if ($previouscomparison) {
        $previous = $previouscomparison->snapshot;
        $deltatable = new html_table();
        $deltatable->attributes['class'] = 'generaltable kbacorp-report';
        $deltatable->head = [get_string('economicindicator', 'local_kbacorporate'),
            get_string('economicprevious', 'local_kbacorporate'),
            get_string('economiccurrent', 'local_kbacorporate'),
            get_string('economicchange', 'local_kbacorporate')];
        foreach (['revenue', 'operatingprofit', 'netprofit', 'employees', 'averagesalary'] as $field) {
            $old = $previous->{$field}; $new = $economic->{$field};
            $change = $old !== null && $new !== null ? (float)$new - (float)$old : null;
            $formatter = $field === 'employees' ? static function($value) {
                return $value === null ? '—' : (int)$value;
            } : $money;
            $deltatable->data[] = [get_string('economic' . $field, 'local_kbacorporate'),
                $formatter($old), $formatter($new), $change === null ? '—' :
                    ($change > 0 ? '+' : '') . $formatter($change)];
        }
        echo $OUTPUT->heading(get_string('economicpointcomparison', 'local_kbacorporate'), 3);
        echo html_writer::div(get_string('economiccomparedwith', 'local_kbacorporate',
            format_string($previous->audittitle)), 'kbacorp-pagelead');
        echo html_writer::div(html_writer::table($deltatable), 'kbacorp-table-scroll');
    }
    $economicfiles = [];
    foreach (get_file_storage()->get_area_files($context->id, 'local_kbacorporate',
            'implementation_economic_evidence', $economic->id, 'filename', false) as $file) {
        $economicfiles[] = html_writer::link(moodle_url::make_pluginfile_url($context->id,
            'local_kbacorporate', 'implementation_economic_evidence', $economic->id,
            $file->get_filepath(), $file->get_filename(), true), s($file->get_filename()));
    }
    if ($economicfiles) { echo html_writer::div(implode('<br>', $economicfiles), 'kbacorp-evidence-list'); }
} else {
    echo $OUTPUT->notification(get_string('economicnodata', 'local_kbacorporate'), 'info', false);
}
if ($audit->status !== \local_kbacorporate\local\implementation_audits::STATUS_CONFIRMED) {
    echo $OUTPUT->single_button(new moodle_url('/local/kbacorporate/implementation_economic.php',
        ['auditid' => $id, 'companyid' => $companyid]),
        get_string($economic ? 'editeconomicindicators' : 'addeconomicindicators', 'local_kbacorporate'), 'get');
}
$metrics = \local_kbacorporate\local\implementation_audits::calculate_metrics($id); echo html_writer::start_div('kbacorp-summary-grid');
echo html_writer::div(html_writer::tag('strong', $metrics->indexvalue === null ? '—' : format_float($metrics->indexvalue, 1)) . html_writer::span(get_string('bmsindex', 'local_kbacorporate')), 'kbacorp-summary-card kbacorp-tone-violet');
echo html_writer::div(html_writer::tag('strong', format_float($metrics->coverage, 1) . '%') . html_writer::span(
    get_string($audit->indexmethod === \local_kbacorporate\local\implementation_audits::INDEX_AREA_AVERAGE
        ? 'bmsassessmentcoverage' : 'bmsevidencecoverage', 'local_kbacorporate')),
    'kbacorp-summary-card kbacorp-tone-blue'); echo html_writer::end_div();
if ($audit->indexmethod === \local_kbacorporate\local\implementation_audits::INDEX_AREA_AVERAGE) {
    echo $OUTPUT->heading(get_string('bmsareasummary', 'local_kbacorporate'), 3);
    $sectiontable = new html_table();
    $sectiontable->attributes['class'] = 'generaltable kbacorp-report';
    $sectiontable->head = [get_string('bmssection', 'local_kbacorporate'),
        get_string('bmsaverage', 'local_kbacorporate'), get_string('bmsassessedcount', 'local_kbacorporate'),
        get_string('bmsattentioncount', 'local_kbacorporate')];
    foreach (\local_kbacorporate\local\implementation_audits::calculate_sections($id) as $section) {
        $sectiontable->data[] = [format_string($section['name']),
            $section['average'] === null ? '—' : format_float($section['average'], 2) . ' / ' . (int)$audit->scalemax,
            $section['assessed'] . ' / ' . $section['total'], (int)$section['attention']];
    }
    echo html_writer::div(html_writer::table($sectiontable), 'kbacorp-table-scroll');
}
if ($audit->status !== \local_kbacorporate\local\implementation_audits::STATUS_CONFIRMED) { echo $OUTPUT->single_button(new moodle_url('/local/kbacorporate/implementation_audit.php', ['id' => $id, 'companyid' => $companyid, 'action' => 'confirm', 'sesskey' => sesskey()]), get_string('confirmbmsaudit', 'local_kbacorporate'), 'post'); }
$table = new html_table(); $table->attributes['class'] = 'generaltable kbacorp-report'; $table->head = [get_string('bmscriterionname', 'local_kbacorporate'), get_string('bmscriterionweight', 'local_kbacorporate'), get_string('bmsmaturitylevel', 'local_kbacorporate'), get_string('bmsevidencestatus', 'local_kbacorporate'), get_string('bmsevidencefiles', 'local_kbacorporate'), get_string('actions', 'local_kbacorporate')];
$tasksbyitem = [];
foreach (\local_kbacorporate\local\implementation_audits::get_tasks($companyid, ['auditid' => $id]) as $existingtask) {
    $tasksbyitem[(int)$existingtask->itemid] = $existingtask;
}
$currentsection = null;
foreach (\local_kbacorporate\local\implementation_audits::get_items($id) as $item) {
    if ($item->sectionname && $item->sectionname !== $currentsection) {
        $sectioncell = new html_table_cell(format_string($item->sectionname));
        $sectioncell->colspan = 6;
        $sectioncell->attributes['class'] = 'kbacorp-section-row';
        $table->data[] = [$sectioncell];
        $currentsection = $item->sectionname;
    }
    $filelinks = [];
    foreach (get_file_storage()->get_area_files($context->id, 'local_kbacorporate', 'implementation_evidence', $item->id, 'filename', false) as $file) {
        $filelinks[] = html_writer::link(moodle_url::make_pluginfile_url($context->id, 'local_kbacorporate', 'implementation_evidence', $item->id, $file->get_filepath(), $file->get_filename(), true), s($file->get_filename()));
    }
    if ($item->evidenceurl) { $filelinks[] = html_writer::link($item->evidenceurl, get_string('bmsexternalevidence', 'local_kbacorporate'), ['target' => '_blank', 'rel' => 'noopener']); }
    $actioncell = '—';
    if ($audit->status === \local_kbacorporate\local\implementation_audits::STATUS_CONFIRMED) {
        if (isset($tasksbyitem[(int)$item->id])) {
            $actioncell = html_writer::link(new moodle_url('/local/kbacorporate/implementation_task.php', [
                'id' => (int)$tasksbyitem[(int)$item->id]->id,
            ]), get_string('implementationactionopenexisting', 'local_kbacorporate'));
        } else {
            $assessed = (int)$item->score >= 0 && !in_array((string)$item->evidencestatus, [
                \local_kbacorporate\local\implementation_audits::EVIDENCE_MISSING,
                \local_kbacorporate\local\implementation_audits::EVIDENCE_NEEDS_DATA,
                \local_kbacorporate\local\implementation_audits::EVIDENCE_NOT_APPLICABLE,
            ], true);
            if ($assessed && (int)$item->targetscore >= 0 && (int)$item->score < (int)$item->targetscore) {
                $actionurl = new moodle_url('/local/kbacorporate/implementation_actions.php', [
                    'companyid' => $companyid,
                    'auditid' => $id,
                    'focusitemid' => (int)$item->id,
                ]);
                $actionurl->set_anchor('action-item-' . (int)$item->id);
                $actioncell = html_writer::link($actionurl,
                    get_string('implementationactioncreateone', 'local_kbacorporate'));
            }
        }
    } else {
        $actioncell = html_writer::link(new moodle_url('/local/kbacorporate/implementation_assessment.php', [
            'itemid' => $item->id,
            'auditid' => $id,
            'companyid' => $companyid,
        ]), get_string('evaluate', 'local_kbacorporate'));
    }
    $criterioncell = format_string($item->criterionname);
    $competencylabels = [];
    foreach (\local_kbacorporate\local\implementation_audits::get_item_competencies($item) as $competency) {
        $competencylabels[] = format_string($competency['shortname'] ?? '') . ' · ' .
            get_string('bmscompetencytargetshort', 'local_kbacorporate', (int)($competency['targetlevel'] ?? 0));
    }
    if ($competencylabels) {
        $criterioncell .= html_writer::div(get_string('linkedcompetencies', 'local_kbacorporate') . ': ' .
            implode('; ', $competencylabels), 'kbacorp-bms-item-competencies');
    }
    $table->data[] = [$criterioncell, (int)$item->weight, (int)$item->score < 0 ? get_string('notassessed', 'local_kbacorporate') : (int)$item->score, get_string('bmsevidence' . $item->evidencestatus, 'local_kbacorporate'), $filelinks ? implode('<br>', $filelinks) : '—', $actioncell];
}
echo html_writer::div(html_writer::table($table), 'kbacorp-table-scroll'); echo $OUTPUT->footer();
