<?php
// This file is part of Moodle - http://moodle.org/

require_once(__DIR__ . '/../../config.php');
require_login();

$context = context_system::instance();
$companyid = optional_param('companyid', 0, PARAM_INT);
$toolid = optional_param('toolid', 0, PARAM_INT);
$beforeid = optional_param('beforeid', 0, PARAM_INT);
$afterid = optional_param('afterid', 0, PARAM_INT);
$auditid = optional_param('auditid', 0, PARAM_INT);
$export = optional_param('export', '', PARAM_ALPHA);
$statusfilter = optional_param('statusfilter', '', PARAM_ALPHANUMEXT);
$riskfilter = optional_param('riskfilter', '', PARAM_ALPHANUMEXT);
$sectionfilter = optional_param('sectionfilter', '', PARAM_TEXT);
$query = optional_param('q', '', PARAM_TEXT);
$canmanage = has_capability('local/kbacorporate:manageimplementationaudits', $context) ||
    has_capability('local/kbacorporate:managecompanyimplementation', $context);
$caneconomics = has_capability('local/kbacorporate:vieweconomicindicators', $context);

$comparisonclass = '\\local_kbacorporate\\local\\implementation_audit_comparison';
$companies = $comparisonclass::get_accessible_companies((int)$USER->id, $canmanage);
if (!$companies) {
    throw new moodle_exception('curatorworkspacenotassigned', 'local_kbacorporate');
}
if ($companyid === 0 && count($companies) === 1) {
    $companyid = (int)array_key_first($companies);
}
if ($companyid > 0) {
    if (!isset($companies[$companyid]) || !$comparisonclass::can_view_company(
            $companyid, (int)$USER->id, $canmanage)) {
        throw new moodle_exception('companynotallowed', 'local_kbacorporate');
    }
    $company = $companies[$companyid];
} else {
    $company = null;
}

// A direct link from one confirmed audit uses that audit as the "after" point.
if ($auditid > 0) {
    $linked = \local_kbacorporate\local\implementation_audits::get_audit($auditid);
    if ($companyid === 0) {
        $companyid = (int)$linked->companyid;
        if (!isset($companies[$companyid]) || !$comparisonclass::can_view_company(
                $companyid, (int)$USER->id, $canmanage)) {
            throw new moodle_exception('companynotallowed', 'local_kbacorporate');
        }
        $company = $companies[$companyid];
    }
    if ((int)$linked->companyid !== $companyid) {
        throw new moodle_exception('companynotallowed', 'local_kbacorporate');
    }
    if ((string)$linked->status !== \local_kbacorporate\local\implementation_audits::STATUS_CONFIRMED) {
        throw new moodle_exception('auditcomparisonconfirmedonly', 'local_kbacorporate');
    }
    $toolid = (int)$linked->toolid;
    $afterid = (int)$linked->id;
}

$tools = $companyid > 0 ? $comparisonclass::get_tools_with_history($companyid) : [];
$toolsbyid = [];
foreach ($tools as $tool) {
    $toolsbyid[(int)$tool->id] = $tool;
}

// Derive the selected tool from a valid audit where possible.
if ($companyid > 0 && $toolid === 0 && $afterid > 0) {
    $candidate = \local_kbacorporate\local\implementation_audits::get_audit($afterid);
    if ((int)$candidate->companyid === $companyid &&
            (string)$candidate->status === \local_kbacorporate\local\implementation_audits::STATUS_CONFIRMED) {
        $toolid = (int)$candidate->toolid;
    }
}
if ($toolid > 0 && !isset($toolsbyid[$toolid])) {
    // A company or tool switch may leave stale ids in the query string.
    $toolid = 0;
    $beforeid = 0;
    $afterid = 0;
}
if ($toolid === 0 && $tools) {
    $firsttool = reset($tools);
    $toolid = (int)$firsttool->id;
}

$audits = $companyid > 0 && $toolid > 0
    ? $comparisonclass::get_confirmed_audits($companyid, $toolid)
    : [];
$auditsbyid = [];
foreach ($audits as $audit) {
    $auditsbyid[(int)$audit->id] = $audit;
}
if ($beforeid > 0 && !isset($auditsbyid[$beforeid])) {
    $beforeid = 0;
}
if ($afterid > 0 && !isset($auditsbyid[$afterid])) {
    $afterid = 0;
}

$selectionnotice = '';
if ($afterid > 0 && $beforeid === 0) {
    $found = false;
    foreach (array_values($audits) as $index => $audit) {
        if ((int)$audit->id === $afterid) {
            $found = true;
            if (isset($audits[$index + 1])) {
                $beforeid = (int)$audits[$index + 1]->id;
            } else {
                $selectionnotice = 'auditcomparisonnoprevious';
            }
            break;
        }
    }
    if (!$found) {
        $afterid = 0;
    }
}
if ($beforeid === 0 && $afterid === 0 && count($audits) >= 2) {
    $pair = $comparisonclass::default_pair($audits);
    $beforeid = (int)$pair->before->id;
    $afterid = (int)$pair->after->id;
}
if ($beforeid > 0 && $afterid === 0) {
    // Prefer the next newer confirmed audit when only the earlier point was selected.
    foreach (array_values($audits) as $index => $audit) {
        if ((int)$audit->id === $beforeid && $index > 0) {
            $afterid = (int)$audits[$index - 1]->id;
            break;
        }
    }
}

$model = null;
$selectionerror = '';
if ($beforeid > 0 && $afterid > 0) {
    if ($beforeid === $afterid) {
        $selectionerror = 'auditcomparisonsameaudit';
    } else {
        try {
            $model = $comparisonclass::build($companyid, $beforeid, $afterid, $caneconomics);
            // build() may normalise reversed selections.
            $beforeid = (int)$model->beforeaudit->id;
            $afterid = (int)$model->afteraudit->id;
        } catch (moodle_exception $exception) {
            $selectionerror = $exception->errorcode;
        }
    }
}

$validstatuses = [
    $comparisonclass::STATUS_IMPROVED,
    $comparisonclass::STATUS_WORSENED,
    $comparisonclass::STATUS_UNCHANGED,
    $comparisonclass::STATUS_NEWLY_ASSESSED,
    $comparisonclass::STATUS_LOST_ASSESSMENT,
    $comparisonclass::STATUS_ADDED,
    $comparisonclass::STATUS_REMOVED,
    $comparisonclass::STATUS_UNASSESSED,
];
if (!in_array($statusfilter, $validstatuses, true)) {
    $statusfilter = '';
}
$validrisks = [
    $comparisonclass::RISK_NEW,
    $comparisonclass::RISK_RESOLVED,
    $comparisonclass::RISK_PERSISTENT,
    $comparisonclass::RISK_UNKNOWN,
];
if (!in_array($riskfilter, $validrisks, true)) {
    $riskfilter = '';
}
$filteredcriteria = $model ? $comparisonclass::filter_criteria($model->criteria, [
    'status' => $statusfilter,
    'risk' => $riskfilter,
    'section' => $sectionfilter,
    'query' => $query,
]) : [];

$baseparams = [];
foreach ([
    'companyid' => $companyid,
    'toolid' => $toolid,
    'beforeid' => $beforeid,
    'afterid' => $afterid,
] as $key => $value) {
    if ($value > 0) {
        $baseparams[$key] = $value;
    }
}
$filterparams = $baseparams;
if ($statusfilter !== '') {
    $filterparams['statusfilter'] = $statusfilter;
}
if ($riskfilter !== '') {
    $filterparams['riskfilter'] = $riskfilter;
}
if ($sectionfilter !== '') {
    $filterparams['sectionfilter'] = $sectionfilter;
}
if (trim($query) !== '') {
    $filterparams['q'] = trim($query);
}
$pageurl = new moodle_url('/local/kbacorporate/implementation_audit_comparison.php', $filterparams);

if ($export === 'csv' && $model) {
    require_once($CFG->libdir . '/csvlib.class.php');
    $csv = new csv_export_writer('semicolon');
    $csv->set_filename('bms-audit-comparison-' . $companyid . '-' . $beforeid . '-' . $afterid . '-' .
        userdate(time(), '%Y-%m-%d'));
    $safe = static function($value): string {
        return \local_kbacorporate\local\economic_indicators::csv_safe(strip_tags((string)$value));
    };
    $csv->add_data([
        get_string('auditcomparison', 'local_kbacorporate'),
        $safe(format_string($model->beforeaudit->title)) . ' → ' .
            $safe(format_string($model->afteraudit->title)),
    ]);
    $csv->add_data([
        get_string('auditcomparisonbefore', 'local_kbacorporate'),
        userdate((int)$model->beforeaudit->auditdate, get_string('strftimedatefullshort')),
        $model->beforeindex === null ? '' : $model->beforeindex,
    ]);
    $csv->add_data([
        get_string('auditcomparisonafter', 'local_kbacorporate'),
        userdate((int)$model->afteraudit->auditdate, get_string('strftimedatefullshort')),
        $model->afterindex === null ? '' : $model->afterindex,
    ]);
    $csv->add_data([]);
    $csv->add_data([
        get_string('bmssectioncode', 'local_kbacorporate'),
        get_string('bmssectionname', 'local_kbacorporate'),
        get_string('bmscriterioncode', 'local_kbacorporate'),
        get_string('bmscriterionname', 'local_kbacorporate'),
        get_string('auditcomparisonbeforevalue', 'local_kbacorporate'),
        get_string('auditcomparisonaftervalue', 'local_kbacorporate'),
        get_string('auditcomparisonchange', 'local_kbacorporate'),
        get_string('auditcomparisonbeforegap', 'local_kbacorporate'),
        get_string('auditcomparisonaftergap', 'local_kbacorporate'),
        get_string('auditcomparisonstatus', 'local_kbacorporate'),
        get_string('auditcomparisonrisk', 'local_kbacorporate'),
        get_string('auditcomparisonactions', 'local_kbacorporate'),
        get_string('auditcomparisonacceptedevidence', 'local_kbacorporate'),
    ]);
    foreach ($filteredcriteria as $row) {
        $csv->add_data(array_map($safe, [
            $row->sectioncode,
            $row->sectionname,
            $row->criterioncode,
            $row->criterionname,
            $row->beforescore === null ? '' : $row->beforescore . '/' . (int)$model->beforeaudit->scalemax,
            $row->afterscore === null ? '' : $row->afterscore . '/' . (int)$model->afteraudit->scalemax,
            $row->normalizeddelta === null ? '' : $row->normalizeddelta,
            $row->beforegap === null ? '' : $row->beforegap,
            $row->aftergap === null ? '' : $row->aftergap,
            get_string('auditcomparisonstatus' . $row->status, 'local_kbacorporate'),
            get_string('auditcomparisonrisk' . $row->riskstatus, 'local_kbacorporate'),
            count($row->tasks),
            $row->acceptedevidence,
        ]));
    }
    $csv->add_data([]);
    $csv->add_data([
        get_string('auditcomparisonsections', 'local_kbacorporate'),
        get_string('auditcomparisonbeforepercent', 'local_kbacorporate'),
        get_string('auditcomparisonafterpercent', 'local_kbacorporate'),
        get_string('auditcomparisonchangepp', 'local_kbacorporate'),
        get_string('auditcomparisonimproved', 'local_kbacorporate'),
        get_string('auditcomparisonworsened', 'local_kbacorporate'),
        get_string('auditcomparisonnewrisks', 'local_kbacorporate'),
        get_string('auditcomparisonresolvedrisks', 'local_kbacorporate'),
    ]);
    foreach ($model->sections as $section) {
        $csv->add_data(array_map($safe, [
            $section->code . ' · ' . $section->name,
            $section->beforeaverage === null ? '' : $section->beforeaverage,
            $section->afteraverage === null ? '' : $section->afteraverage,
            $section->change === null ? '' : $section->change,
            $section->improved,
            $section->worsened,
            $section->newrisks,
            $section->resolvedrisks,
        ]));
    }
    if ($model->economic->rows) {
        $csv->add_data([]);
        $csv->add_data([
            get_string('auditcomparisoneconomic', 'local_kbacorporate'),
            get_string('auditcomparisonbeforevalue', 'local_kbacorporate'),
            get_string('auditcomparisonaftervalue', 'local_kbacorporate'),
            get_string('auditcomparisonchange', 'local_kbacorporate'),
            get_string('auditcomparisonchangepercent', 'local_kbacorporate'),
            get_string('auditcomparisoneconomicoutcome', 'local_kbacorporate'),
        ]);
        foreach ($model->economic->rows as $row) {
            $csv->add_data(array_map($safe, [
                $row->indicatorcode . ' · ' . $row->indicatorname,
                $row->before ? $row->before->value : '',
                $row->after ? $row->after->value : '',
                $row->delta === null ? '' : $row->delta,
                $row->percentdelta === null ? '' : $row->percentdelta,
                get_string('auditcomparisoneconomic' . $row->outcome, 'local_kbacorporate'),
            ]));
        }
    }
    $csv->download_file();
    exit;
}

$PAGE->set_context($context);
$PAGE->set_url($pageurl);
$PAGE->set_pagelayout('standard');
$PAGE->set_title(get_string('auditcomparison', 'local_kbacorporate'));
$PAGE->set_heading(get_string('auditcomparison', 'local_kbacorporate'));

$deltaformat = static function($value, string $suffix = ''): string {
    if ($value === null) {
        return '—';
    }
    $number = (float)$value;
    return ($number > 0 ? '+' : '') . format_float($number, 2) . $suffix;
};
$statusbadge = static function(string $status): string {
    return html_writer::span(
        get_string('auditcomparisonstatus' . $status, 'local_kbacorporate'),
        'kbacorp-audit-comparison-badge kbacorp-audit-comparison-status-' . $status
    );
};
$riskbadge = static function(string $risk): string {
    return html_writer::span(
        get_string('auditcomparisonrisk' . $risk, 'local_kbacorporate'),
        'kbacorp-audit-comparison-badge kbacorp-audit-comparison-risk-' . $risk
    );
};
$taskstatus = static function(string $status): string {
    $key = 'implementationtaskstatus' . $status;
    return get_string_manager()->string_exists($key, 'local_kbacorporate')
        ? get_string($key, 'local_kbacorporate') : s($status);
};

$renderauditcard = static function(\stdClass $audit, string $label) use ($OUTPUT): string {
    $index = $audit->indexvalue === null ? '—' : format_float((float)$audit->indexvalue, 1);
    $version = !empty($audit->toolversionlabel)
        ? html_writer::div(get_string('bmstoolauditversion', 'local_kbacorporate', s($audit->toolversionlabel)),
            'text-muted') : '';
    return html_writer::div(
        html_writer::span($label, 'kbacorp-audit-comparison-point') .
        html_writer::tag('h3', format_string($audit->title)) .
        html_writer::div(userdate((int)$audit->auditdate, get_string('strftimedatefullshort')),
            'kbacorp-pagelead') .
        $version .
        html_writer::div(
            html_writer::tag('strong', $index) . html_writer::span(get_string('bmsindex', 'local_kbacorporate')),
            'kbacorp-audit-comparison-card-metric'
        ) .
        html_writer::div(
            html_writer::tag('strong', format_float((float)$audit->coverage, 1) . '%') .
                html_writer::span(get_string('bmscoverage', 'local_kbacorporate')),
            'kbacorp-audit-comparison-card-metric'
        ),
        'kbacorp-audit-comparison-card'
    );
};

echo $OUTPUT->header();
echo local_kbacorporate_implementation_navigation($companyid, 'comparison');
if ($company) {
    echo local_kbacorporate_company_context_banner($company);
}

echo html_writer::start_div('kbacorp-curator-hero');
echo html_writer::div(get_string('bmsimplementation', 'local_kbacorporate'), 'kbacorp-eyebrow');
echo $OUTPUT->heading(get_string('auditcomparison', 'local_kbacorporate'), 2);
echo html_writer::tag('p', get_string('auditcomparisondesc', 'local_kbacorporate'), [
    'class' => 'kbacorp-pagelead',
]);
echo html_writer::end_div();

$companyoptions = [0 => get_string('selectcompany', 'local_kbacorporate')];
foreach ($companies as $availablecompany) {
    $companyoptions[(int)$availablecompany->id] = format_string($availablecompany->name);
}
$tooloptions = [0 => get_string('auditcomparisonselecttool', 'local_kbacorporate')];
foreach ($tools as $tool) {
    $tooloptions[(int)$tool->id] = format_string($tool->name) . ' · ' .
        get_string('auditcomparisonauditcount', 'local_kbacorporate', (int)$tool->auditcount);
}
$auditoptions = [0 => get_string('auditcomparisonselectaudit', 'local_kbacorporate')];
foreach ($audits as $audit) {
    $index = $audit->indexvalue === null ? '—' : format_float((float)$audit->indexvalue, 1);
    $auditoptions[(int)$audit->id] = format_string($audit->title) . ' · ' .
        userdate((int)$audit->auditdate, get_string('strftimedatefullshort')) . ' · ' .
        get_string('bmsindexvalue', 'local_kbacorporate', $index);
}

echo html_writer::start_tag('form', [
    'method' => 'get',
    'action' => (new moodle_url('/local/kbacorporate/implementation_audit_comparison.php'))->out(false),
    'class' => 'kbacorp-audit-comparison-filters',
]);
echo html_writer::label(get_string('company', 'local_kbacorporate'), 'audit-comparison-company');
echo html_writer::select($companyoptions, 'companyid', $companyid, false, [
    'id' => 'audit-comparison-company',
    'class' => 'custom-select',
]);
echo html_writer::label(get_string('bmstool', 'local_kbacorporate'), 'audit-comparison-tool');
$toolselectattributes = [
    'id' => 'audit-comparison-tool',
    'class' => 'custom-select',
];
if ($companyid === 0) {
    $toolselectattributes['disabled'] = 'disabled';
}
echo html_writer::select($tooloptions, 'toolid', $toolid, false, $toolselectattributes);
echo html_writer::label(get_string('auditcomparisonbefore', 'local_kbacorporate'), 'audit-comparison-before');
$beforeselectattributes = [
    'id' => 'audit-comparison-before',
    'class' => 'custom-select',
];
if ($toolid === 0) {
    $beforeselectattributes['disabled'] = 'disabled';
}
echo html_writer::select($auditoptions, 'beforeid', $beforeid, false, $beforeselectattributes);
echo html_writer::label(get_string('auditcomparisonafter', 'local_kbacorporate'), 'audit-comparison-after');
$afterselectattributes = [
    'id' => 'audit-comparison-after',
    'class' => 'custom-select',
];
if ($toolid === 0) {
    $afterselectattributes['disabled'] = 'disabled';
}
echo html_writer::select($auditoptions, 'afterid', $afterid, false, $afterselectattributes);
echo html_writer::tag('button', get_string('applyfilter', 'local_kbacorporate'), [
    'type' => 'submit',
    'class' => 'btn btn-primary',
]);
echo html_writer::link(new moodle_url('/local/kbacorporate/implementation_audit_comparison.php'),
    get_string('resetfilters', 'local_kbacorporate'), ['class' => 'btn btn-secondary']);
echo html_writer::end_tag('form');

if (!$company) {
    echo $OUTPUT->notification(get_string('auditcomparisonselectcompany', 'local_kbacorporate'), 'info');
    echo $OUTPUT->footer();
    exit;
}
if (!$tools) {
    echo $OUTPUT->notification(get_string('auditcomparisonnohistory', 'local_kbacorporate'), 'warning');
    echo $OUTPUT->footer();
    exit;
}
if ($selectionnotice !== '') {
    echo $OUTPUT->notification(get_string($selectionnotice, 'local_kbacorporate'), 'warning');
}
if ($selectionerror !== '') {
    $key = get_string_manager()->string_exists($selectionerror, 'local_kbacorporate')
        ? $selectionerror : 'auditcomparisoninvalidpair';
    echo $OUTPUT->notification(get_string($key, 'local_kbacorporate'), 'error');
}
if (!$model) {
    echo $OUTPUT->notification(get_string('auditcomparisonselectpair', 'local_kbacorporate'), 'info');
    echo $OUTPUT->footer();
    exit;
}

$canopenaudits = $canmanage && \local_kbacorporate\local\access::has_full_company_access(
    $companyid, (int)$USER->id);
echo html_writer::start_div('kbacorp-toolbar');
if ($canopenaudits) {
    echo html_writer::link(new moodle_url('/local/kbacorporate/implementation_audit.php', [
        'companyid' => $companyid,
        'id' => $beforeid,
    ]), get_string('auditcomparisonopenbefore', 'local_kbacorporate'), ['class' => 'btn btn-outline-primary']);
    echo html_writer::link(new moodle_url('/local/kbacorporate/implementation_audit.php', [
        'companyid' => $companyid,
        'id' => $afterid,
    ]), get_string('auditcomparisonopenafter', 'local_kbacorporate'), ['class' => 'btn btn-outline-primary']);
}
echo html_writer::link(new moodle_url('/local/kbacorporate/implementation_section_progress.php', [
    'companyid' => $companyid,
    'auditid' => $afterid,
]), get_string('implementationsectionprogress', 'local_kbacorporate'), ['class' => 'btn btn-outline-secondary']);
if ($caneconomics) {
    echo html_writer::link(new moodle_url('/local/kbacorporate/economic_dashboard.php', [
        'companyid' => $companyid,
        'auditid' => $afterid,
    ]), get_string('economicdashboard', 'local_kbacorporate'), ['class' => 'btn btn-outline-secondary']);
}
$exporturl = new moodle_url('/local/kbacorporate/implementation_audit_comparison.php',
    $filterparams + ['export' => 'csv']);
echo html_writer::link($exporturl, get_string('auditcomparisonexportcsv', 'local_kbacorporate'), [
    'class' => 'btn btn-secondary',
]);
echo html_writer::end_div();

echo $OUTPUT->notification(get_string('auditcomparisonreadonlynotice', 'local_kbacorporate'), 'info');
echo $OUTPUT->notification(get_string('auditcomparisoncausalitynotice', 'local_kbacorporate'), 'warning');

echo html_writer::start_div('kbacorp-audit-comparison-pair');
echo $renderauditcard($model->beforeaudit, get_string('auditcomparisonbefore', 'local_kbacorporate'));
echo html_writer::div('→', 'kbacorp-audit-comparison-arrow', ['aria-hidden' => 'true']);
echo $renderauditcard($model->afteraudit, get_string('auditcomparisonafter', 'local_kbacorporate'));
echo html_writer::end_div();

$summary = $model->summary;
echo html_writer::start_div('kbacorp-summary-grid');
$cards = [
    [$model->indexdelta === null ? '—' : $deltaformat($model->indexdelta),
        'auditcomparisonindexdelta', $model->indexdelta !== null && $model->indexdelta < 0 ? 'red' : 'green'],
    [$summary->improved, 'auditcomparisonimproved', 'green'],
    [$summary->worsened, 'auditcomparisonworsened', $summary->worsened ? 'red' : 'green'],
    [$summary->resolvedrisks, 'auditcomparisonresolvedrisks', 'blue'],
    [$summary->newrisks, 'auditcomparisonnewrisks', $summary->newrisks ? 'red' : 'green'],
    [$summary->actionscompletedbetween, 'auditcomparisoncompletedactions', 'violet'],
];
foreach ($cards as $card) {
    echo html_writer::div(
        html_writer::tag('strong', (string)$card[0]) .
        html_writer::span(get_string($card[1], 'local_kbacorporate')),
        'kbacorp-summary-card kbacorp-tone-' . $card[2]
    );
}
echo html_writer::end_div();

echo html_writer::div(get_string('auditcomparisonsummaryline', 'local_kbacorporate', (object)[
    'matched' => $summary->matchedcriteria,
    'comparable' => $summary->comparablecriteria,
    'beforegaps' => $summary->beforegaps,
    'aftergaps' => $summary->aftergaps,
    'evidence' => $summary->acceptedevidence,
]), 'kbacorp-pagelead');

// Section-level comparison.
echo $OUTPUT->heading(get_string('auditcomparisonsections', 'local_kbacorporate'), 2);
$sectiontable = new html_table();
$sectiontable->attributes['class'] = 'generaltable kbacorp-report kbacorp-audit-comparison-section-table';
$sectiontable->head = [
    get_string('bmssection', 'local_kbacorporate'),
    get_string('auditcomparisonbeforepercent', 'local_kbacorporate'),
    get_string('auditcomparisonafterpercent', 'local_kbacorporate'),
    get_string('auditcomparisonchangepp', 'local_kbacorporate'),
    get_string('auditcomparisoncriteriachanges', 'local_kbacorporate'),
    get_string('auditcomparisonriskchanges', 'local_kbacorporate'),
    get_string('status'),
];
foreach ($model->sections as $section) {
    $sectiontable->data[] = [
        html_writer::tag('strong', s($section->code) . ' · ' . format_string($section->name)),
        $section->beforeaverage === null ? '—' : format_float($section->beforeaverage, 2) . '%',
        $section->afteraverage === null ? '—' : format_float($section->afteraverage, 2) . '%',
        $section->change === null ? '—' : $deltaformat($section->change, ' ' .
            get_string('auditcomparisonppshort', 'local_kbacorporate')),
        get_string('auditcomparisonsectionchangesvalue', 'local_kbacorporate', (object)[
            'improved' => $section->improved,
            'worsened' => $section->worsened,
            'unchanged' => $section->unchanged,
        ]),
        get_string('auditcomparisonsectionrisksvalue', 'local_kbacorporate', (object)[
            'new' => $section->newrisks,
            'resolved' => $section->resolvedrisks,
            'persistent' => $section->persistentrisks,
        ]),
        $statusbadge($section->status),
    ];
}
echo html_writer::div(html_writer::table($sectiontable), 'kbacorp-table-scroll');

// Criterion filters and detailed comparison.
echo $OUTPUT->heading(get_string('auditcomparisoncriteria', 'local_kbacorporate'), 2);
$statusoptions = ['' => get_string('auditcomparisonallstatuses', 'local_kbacorporate')];
foreach ($validstatuses as $status) {
    $statusoptions[$status] = get_string('auditcomparisonstatus' . $status, 'local_kbacorporate');
}
$riskoptions = ['' => get_string('auditcomparisonallrisks', 'local_kbacorporate')];
foreach ($validrisks as $risk) {
    $riskoptions[$risk] = get_string('auditcomparisonrisk' . $risk, 'local_kbacorporate');
}
$sectionoptions = ['' => get_string('auditcomparisonallsections', 'local_kbacorporate')];
foreach ($model->sections as $section) {
    $sectionoptions[(string)$section->code] = (string)$section->code . ' · ' . format_string($section->name);
}

echo html_writer::start_tag('form', [
    'method' => 'get',
    'action' => (new moodle_url('/local/kbacorporate/implementation_audit_comparison.php'))->out(false),
    'class' => 'kbacorp-audit-comparison-detail-filters',
]);
foreach ($baseparams as $key => $value) {
    echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => $key, 'value' => $value]);
}
echo html_writer::select($statusoptions, 'statusfilter', $statusfilter, false, ['class' => 'custom-select']);
echo html_writer::select($riskoptions, 'riskfilter', $riskfilter, false, ['class' => 'custom-select']);
echo html_writer::select($sectionoptions, 'sectionfilter', $sectionfilter, false, ['class' => 'custom-select']);
echo html_writer::empty_tag('input', [
    'type' => 'search',
    'name' => 'q',
    'value' => $query,
    'placeholder' => get_string('auditcomparisonsearch', 'local_kbacorporate'),
    'class' => 'form-control',
]);
echo html_writer::tag('button', get_string('applyfilter', 'local_kbacorporate'), [
    'type' => 'submit',
    'class' => 'btn btn-primary',
]);
echo html_writer::link(new moodle_url('/local/kbacorporate/implementation_audit_comparison.php', $baseparams),
    get_string('resetfilters', 'local_kbacorporate'), ['class' => 'btn btn-secondary']);
echo html_writer::end_tag('form');

echo html_writer::div(get_string('auditcomparisonshowncriteria', 'local_kbacorporate', (object)[
    'shown' => count($filteredcriteria),
    'total' => count($model->criteria),
]), 'text-muted');

if (!$filteredcriteria) {
    echo $OUTPUT->notification(get_string('auditcomparisonnofilterresults', 'local_kbacorporate'), 'info');
} else {
    $criteriatable = new html_table();
    $criteriatable->attributes['class'] = 'generaltable kbacorp-report kbacorp-audit-comparison-criteria-table';
    $criteriatable->head = [
        get_string('bmssection', 'local_kbacorporate'),
        get_string('bmscriterionname', 'local_kbacorporate'),
        get_string('auditcomparisonbeforevalue', 'local_kbacorporate'),
        get_string('auditcomparisonaftervalue', 'local_kbacorporate'),
        get_string('auditcomparisonchange', 'local_kbacorporate'),
        get_string('auditcomparisongapchange', 'local_kbacorporate'),
        get_string('auditcomparisonrisk', 'local_kbacorporate'),
        get_string('auditcomparisonactions', 'local_kbacorporate'),
    ];
    foreach ($filteredcriteria as $row) {
        $beforevalue = $row->beforescore === null ? get_string('notassessed', 'local_kbacorporate') :
            (int)$row->beforescore . ' / ' . (int)$model->beforeaudit->scalemax;
        $aftervalue = $row->afterscore === null ? get_string('notassessed', 'local_kbacorporate') :
            (int)$row->afterscore . ' / ' . (int)$model->afteraudit->scalemax;
        $change = $row->normalizeddelta === null ? '—' :
            $deltaformat($row->normalizeddelta, ' ' . get_string('auditcomparisonppshort', 'local_kbacorporate'));
        $change .= html_writer::div($statusbadge($row->status), 'kbacorp-audit-comparison-inline-status');
        $gap = ($row->beforegap === null ? '—' : (int)$row->beforegap) . ' → ' .
            ($row->aftergap === null ? '—' : (int)$row->aftergap);
        if ($row->gapchange !== null) {
            $gap .= html_writer::div(get_string('auditcomparisongapdelta', 'local_kbacorporate',
                $deltaformat($row->gapchange)), 'text-muted');
        }
        $actions = [];
        foreach ($row->tasks as $task) {
            $actions[] = html_writer::link(new moodle_url('/local/kbacorporate/implementation_task.php', [
                'id' => (int)$task->id,
            ]), format_string($task->title)) . html_writer::span(' · ' . $taskstatus((string)$task->status),
                'text-muted');
        }
        $actioncell = $actions ? implode('<br>', $actions) : '—';
        if ($row->acceptedevidence > 0) {
            $actioncell .= html_writer::div(get_string('auditcomparisonacceptedevidencevalue',
                'local_kbacorporate', $row->acceptedevidence), 'text-success');
        }
        $criterionname = html_writer::tag('strong', s($row->criterioncode) . ' · ' .
            format_string($row->criterionname));
        if ($row->sectionchanged) {
            $criterionname .= html_writer::div(get_string('auditcomparisonsectionchanged',
                'local_kbacorporate'), 'text-muted');
        }
        $criteriatable->data[] = [
            s($row->sectioncode) . ' · ' . format_string($row->sectionname),
            $criterionname,
            $beforevalue,
            $aftervalue,
            $change,
            $gap,
            $riskbadge($row->riskstatus),
            $actioncell,
        ];
    }
    echo html_writer::div(html_writer::table($criteriatable), 'kbacorp-table-scroll');
}

// Actions created from the earlier audit.
echo $OUTPUT->heading(get_string('auditcomparisonactionsbetween', 'local_kbacorporate'), 2);
if (!$model->tasks) {
    echo $OUTPUT->notification(get_string('auditcomparisonnoactions', 'local_kbacorporate'), 'info', false);
} else {
    $actiontable = new html_table();
    $actiontable->attributes['class'] = 'generaltable kbacorp-report';
    $actiontable->head = [
        get_string('bmscriterionname', 'local_kbacorporate'),
        get_string('implementationtasktitle', 'local_kbacorporate'),
        get_string('implementationtaskowner', 'local_kbacorporate'),
        get_string('priority', 'local_kbacorporate'),
        get_string('status'),
        get_string('implementationtaskduedate', 'local_kbacorporate'),
        get_string('auditcomparisoncompletedat', 'local_kbacorporate'),
        get_string('auditcomparisonacceptedevidence', 'local_kbacorporate'),
    ];
    foreach ($model->tasks as $task) {
        $owner = (int)$task->ownerid > 0 ? fullname($task) : get_string('kanbanunassigned', 'local_kbacorporate');
        $actiontable->data[] = [
            s($task->criterioncode) . ' · ' . format_string($task->criterionname),
            html_writer::link(new moodle_url('/local/kbacorporate/implementation_task.php', [
                'id' => (int)$task->id,
            ]), format_string($task->title)),
            $owner,
            get_string('gappriority' . $task->priority, 'local_kbacorporate'),
            $taskstatus((string)$task->status),
            (int)$task->duedate > 0 ? userdate((int)$task->duedate, get_string('strftimedatefullshort')) : '—',
            (int)$task->completedat > 0 ? userdate((int)$task->completedat,
                get_string('strftimedatefullshort')) : '—',
            (int)($task->acceptedevidencebetween ?? 0),
        ];
    }
    echo html_writer::div(html_writer::table($actiontable), 'kbacorp-table-scroll');
}

// Economic values linked to the two audit snapshots. Charts are intentionally deferred to v0.34.1.
if ($caneconomics) {
    echo $OUTPUT->heading(get_string('auditcomparisoneconomic', 'local_kbacorporate'), 2);
    if (!$model->economic->rows) {
        echo $OUTPUT->notification(get_string('auditcomparisonnoeconomic', 'local_kbacorporate'), 'info', false);
    } else {
        echo html_writer::div(get_string('auditcomparisoneconomicnotice', 'local_kbacorporate'),
            'kbacorp-pagelead');
        $economictable = new html_table();
        $economictable->attributes['class'] = 'generaltable kbacorp-report';
        $economictable->head = [
            get_string('economicindicator', 'local_kbacorporate'),
            get_string('auditcomparisonbeforevalue', 'local_kbacorporate'),
            get_string('auditcomparisonaftervalue', 'local_kbacorporate'),
            get_string('auditcomparisonchange', 'local_kbacorporate'),
            get_string('auditcomparisonchangepercent', 'local_kbacorporate'),
            get_string('auditcomparisoneconomicoutcome', 'local_kbacorporate'),
        ];
        foreach ($model->economic->rows as $row) {
            $beforevalue = $row->before
                ? \local_kbacorporate\local\economic_indicators::format_value($row->before->value,
                    $row->valuetype, $row->unit, (string)$row->before->currency) : '—';
            $aftervalue = $row->after
                ? \local_kbacorporate\local\economic_indicators::format_value($row->after->value,
                    $row->valuetype, $row->unit, (string)$row->after->currency) : '—';
            $deltavalue = '—';
            if ($row->delta !== null) {
                $currency = $row->after ? (string)$row->after->currency : 'RUB';
                $deltavalue = ($row->delta > 0 ? '+' : '') .
                    \local_kbacorporate\local\economic_indicators::format_value($row->delta,
                        $row->valuetype, $row->unit, $currency);
            }
            $economictable->data[] = [
                html_writer::tag('strong', s($row->indicatorcode) . ' · ' .
                    format_string($row->indicatorname)),
                $beforevalue,
                $aftervalue,
                $deltavalue,
                $row->percentdelta === null ? '—' : $deltaformat($row->percentdelta, '%'),
                html_writer::span(get_string('auditcomparisoneconomic' . $row->outcome,
                    'local_kbacorporate'), 'kbacorp-audit-comparison-economic-' . $row->outcome),
            ];
        }
        echo html_writer::div(html_writer::table($economictable), 'kbacorp-table-scroll');
    }
}

echo $OUTPUT->footer();
