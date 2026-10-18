<?php
// This file is part of Moodle - http://moodle.org/

require_once(__DIR__ . '/../../config.php');
require_login();

$context = context_system::instance();
$companyid = optional_param('companyid', 0, PARAM_INT);
$panel = optional_param('panel', 'owner', PARAM_ALPHA);
$allowedpanels = ['owner', 'ceo', 'hr', 'curator'];
if (!in_array($panel, $allowedpanels, true)) {
    $panel = 'owner';
}

$canmanage = has_capability('local/kbacorporate:manageimplementationaudits', $context);
$iscurator = $DB->record_exists('local_kbacorp_impl_program', ['curatorid' => (int)$USER->id]);
if ($panel === 'curator') {
    if (!$iscurator && !$canmanage) {
        throw new required_capability_exception($context,
            'local/kbacorporate:manageimplementationaudits', 'nopermissions', '');
    }
    $company = null;
    $model = null;
    $portfolio = \local_kbacorporate\local\management_dashboards::build_portfolio((int)$USER->id, $canmanage);
} else {
    if ($companyid <= 0) {
        $companies = \local_kbacorporate\local\implementation_section_progress::get_accessible_companies(
            (int)$USER->id, true);
        if ($companies) {
            $first = reset($companies);
            redirect(new moodle_url('/local/kbacorporate/management_dashboards.php', [
                'companyid' => (int)$first->id,
                'panel' => $panel,
            ]));
        }
        throw new moodle_exception('nocompaniesmatchfilter', 'local_kbacorporate');
    }
    $company = \local_kbacorporate\local\access::require_full_company_access($companyid, (int)$USER->id);
    $model = \local_kbacorporate\local\management_dashboards::build_company($companyid);
    $portfolio = [];
}

$PAGE->set_context($context);
$PAGE->set_url(new moodle_url('/local/kbacorporate/management_dashboards.php', [
    'companyid' => $companyid,
    'panel' => $panel,
]));
$PAGE->set_pagelayout('admin');
$PAGE->set_title(get_string('managementdashboards', 'local_kbacorporate'));
$PAGE->set_heading($company ? format_string($company->name) : get_string('managementpanelcurator', 'local_kbacorporate'));

$card = static function(string $label, string $value, string $hint = '', string $state = ''): string {
    $classes = 'kbacorp-management-card' . ($state ? ' is-' . $state : '');
    return html_writer::div(
        html_writer::div($label, 'kbacorp-management-card-label') .
        html_writer::div($value, 'kbacorp-management-card-value') .
        ($hint !== '' ? html_writer::div($hint, 'kbacorp-management-card-hint') : ''),
        $classes
    );
};
$cards = static function(array $items): string {
    return html_writer::div(implode('', $items), 'kbacorp-management-cards');
};
$percent = static function($value): string {
    return $value === null ? '—' : format_float((float)$value, 1) . '%';
};
$delta = static function($value): string {
    if ($value === null) {
        return '—';
    }
    return ((float)$value > 0 ? '+' : '') . format_float((float)$value, 2);
};
$statuslabel = static function(string $status): string {
    $key = 'implementationtaskstatus' . $status;
    return get_string_manager()->string_exists($key, 'local_kbacorporate') ?
        get_string($key, 'local_kbacorporate') : s($status);
};

echo $OUTPUT->header();
echo local_kbacorporate_implementation_navigation($companyid,
    $panel === 'curator' ? 'curatorportfolio' : 'management');
if ($company) {
    echo local_kbacorporate_company_context_banner($company);
}
echo $OUTPUT->heading(get_string('managementdashboards', 'local_kbacorporate'));
echo html_writer::div(get_string('managementdashboardsdesc', 'local_kbacorporate'), 'kbacorp-pagelead');

$tabs = [];
foreach ($allowedpanels as $key) {
    if ($panel === 'curator' && $key !== 'curator') {
        continue;
    }
    if ($key === 'curator' && !$iscurator && !$canmanage) {
        continue;
    }
    $tabs[] = new tabobject($key,
        new moodle_url('/local/kbacorporate/management_dashboards.php', ['companyid' => $companyid, 'panel' => $key]),
        get_string('managementpanel' . $key, 'local_kbacorporate'));
}
echo $OUTPUT->tabtree($tabs, $panel);

if ($panel === 'curator') {
    $totals = (object)['companies' => count($portfolio), 'overdue' => 0, 'evidence' => 0, 'noprogress' => 0];
    foreach ($portfolio as $row) {
        $totals->overdue += (int)$row->tasksummary->overdue;
        $totals->evidence += (int)$row->evidencepending;
        $totals->noprogress += (int)$row->noprogress;
    }
    echo $cards([
        $card(get_string('managementcompanies', 'local_kbacorporate'), (string)$totals->companies),
        $card(get_string('managementoverdue', 'local_kbacorporate'), (string)$totals->overdue, '', $totals->overdue ? 'danger' : 'good'),
        $card(get_string('managementevidencepending', 'local_kbacorporate'), (string)$totals->evidence, '', $totals->evidence ? 'warning' : 'good'),
        $card(get_string('managementnoprogress', 'local_kbacorporate'), (string)$totals->noprogress, '', $totals->noprogress ? 'danger' : 'good'),
    ]);
    $table = new html_table();
    $table->attributes['class'] = 'generaltable kbacorp-management-portfolio';
    $table->head = [get_string('managementtablecompany', 'local_kbacorporate'),
        get_string('managementindexshort', 'local_kbacorporate'),
        get_string('managementdynamic', 'local_kbacorporate'), get_string('managementoverdue', 'local_kbacorporate'),
        html_writer::span(get_string('managementevidencependingshort', 'local_kbacorporate'), '', [
            'title' => get_string('managementevidencepending', 'local_kbacorporate'),
        ]),
        html_writer::span(get_string('managementlastactivityshort', 'local_kbacorporate'), '', [
            'title' => get_string('managementlastactivity', 'local_kbacorporate'),
        ]),
        html_writer::span(get_string('managementcheckpointshort', 'local_kbacorporate'), '', [
            'title' => get_string('managementcheckpoint', 'local_kbacorporate'),
        ])];
    foreach ($portfolio as $row) {
        $companyparams = ['companyid' => (int)$row->company->id];
        if ($row->latest) {
            $companyparams['auditid'] = (int)$row->latest->id;
        }
        $url = new moodle_url('/local/kbacorporate/implementation_section_progress.php', $companyparams);
        $table->data[] = [
            html_writer::link($url, format_string($row->company->name)) .
                ($row->noprogress ? html_writer::span(get_string('managementnoprogressbadge', 'local_kbacorporate'),
                    'badge badge-danger ml-1') : ''),
            $row->latest && $row->latest->indexvalue !== null ? format_float((float)$row->latest->indexvalue, 2) : '—',
            $delta($row->indexdelta),
            (string)$row->tasksummary->overdue,
            (string)$row->evidencepending,
            $row->lastactivity ? userdate($row->lastactivity, get_string('strftimedatefullshort')) : '—',
            $row->checkpoint ? userdate($row->checkpoint, get_string('strftimedatefullshort')) : '—',
        ];
    }
    echo $portfolio ? html_writer::div(html_writer::table($table),
        'table-responsive kbacorp-management-portfolio-wrap') : $OUTPUT->notification(
            get_string('managementnoportfolio', 'local_kbacorporate'), 'info', false);
    echo $OUTPUT->footer();
    exit;
}

$latestindex = $model->latest && $model->latest->indexvalue !== null ?
    format_float((float)$model->latest->indexvalue, 2) : '—';
$summary = $model->sectionprogress ? $model->sectionprogress->summary : null;

if ($panel === 'owner') {
    echo $cards([
        $card('BMS Index', $latestindex, get_string('managementdelta', 'local_kbacorporate', $delta($model->indexdelta))),
        $card(get_string('managementimplementationprogress', 'local_kbacorporate'),
            $summary ? $percent($summary->workflowprogress) : '—'),
        $card(get_string('managementkeyrisks', 'local_kbacorporate'),
            $summary ? (string)$summary->sectionsrisk : '—', '', $summary && $summary->sectionsrisk ? 'danger' : 'good'),
        $card(get_string('managementoverdueactions', 'local_kbacorporate'),
            (string)$model->tasksummary->overdue, '', $model->tasksummary->overdue ? 'danger' : 'good'),
    ]);
    echo $OUTPUT->heading(get_string('managementeconomickpis', 'local_kbacorporate'), 3);
    $economiccards = [];
    foreach ($model->economic as $row) {
        $economiccards[] = $card(format_string($row->indicator->name),
            \local_kbacorporate\local\economic_indicators::format_value($row->value->value,
                (string)$row->indicator->valuetype, (string)$row->indicator->unit, (string)$row->value->currency),
            userdate((int)$row->value->periodend, get_string('strftimedatefullshort')));
    }
    echo $economiccards ? $cards($economiccards) : $OUTPUT->notification(
        get_string('managementnoeconomic', 'local_kbacorporate'), 'info', false);
    echo $OUTPUT->heading(get_string('managementperiodtrend', 'local_kbacorporate'), 3);
    if ($model->trend) {
        $bars = [];
        foreach ($model->trend as $point) {
            $height = $point->percent === null ? 0 : max(2, min(100, (float)$point->percent));
            $bars[] = html_writer::div(
                html_writer::div('', 'kbacorp-management-trend-fill', ['style' => 'height:' . $height . '%']) .
                html_writer::span($point->value === null ? '—' : format_float($point->value, 1)) .
                html_writer::tag('small', userdate($point->date, '%m.%Y')),
                'kbacorp-management-trend-column'
            );
        }
        echo html_writer::div(implode('', $bars), 'kbacorp-management-trend');
    } else {
        echo $OUTPUT->notification(get_string('managementnoaudits', 'local_kbacorporate'), 'info', false);
    }
}

if ($panel === 'ceo') {
    echo $cards([
        $card(get_string('managementimplementationtasks', 'local_kbacorporate'), (string)$model->tasksummary->total),
        $card(get_string('managementimplementationprogress', 'local_kbacorporate'), $percent($model->tasksummary->progress)),
        $card(get_string('managementoverdue', 'local_kbacorporate'), (string)$model->tasksummary->overdue, '',
            $model->tasksummary->overdue ? 'danger' : 'good'),
        $card(get_string('managementcriticalsections', 'local_kbacorporate'),
            $summary ? (string)$summary->sectionsrisk : '—'),
    ]);
    echo $OUTPUT->heading(get_string('managementcriticalsections', 'local_kbacorporate'), 3);
    $table = new html_table();
    $table->head = [get_string('managementtablesection', 'local_kbacorporate'), get_string('managementcurrentlevel', 'local_kbacorporate'),
        get_string('managementgaps', 'local_kbacorporate'), get_string('managementoverdue', 'local_kbacorporate'),
        get_string('managementimplementationprogress', 'local_kbacorporate')];
    foreach ($model->criticalsections as $section) {
        $table->data[] = [format_string($section->name),
            $section->currentaverage === null ? '—' : format_float($section->currentaverage, 2),
            (string)$section->gapcriteria, (string)$section->overduetasks, $percent($section->workflowprogress)];
    }
    echo $model->criticalsections ? html_writer::table($table) : $OUTPUT->notification(
        get_string('managementnosections', 'local_kbacorporate'), 'info', false);
    echo $OUTPUT->heading(get_string('managementimplementationtasks', 'local_kbacorporate'), 3);
    $table = new html_table();
    $table->head = [get_string('managementtabletask', 'local_kbacorporate'), get_string('managementtableresponsible', 'local_kbacorporate'),
        get_string('status'), get_string('implementationtaskduedate', 'local_kbacorporate')];
    foreach (array_slice($model->tasks, 0, 25) as $task) {
        $url = new moodle_url('/local/kbacorporate/implementation_task.php', [
            'companyid' => $companyid, 'id' => (int)$task->id]);
        $table->data[] = [html_writer::link($url, format_string($task->title)), s($task->ownername),
            $statuslabel((string)$task->status), (int)$task->duedate ? userdate((int)$task->duedate,
                get_string('strftimedatefullshort')) : '—'];
    }
    echo $model->tasks ? html_writer::table($table) : $OUTPUT->notification(
        get_string('managementnotasks', 'local_kbacorporate'), 'info', false);
}

if ($panel === 'hr') {
    echo $cards([
        $card(get_string('managementemployees', 'local_kbacorporate'), (string)$model->members),
        $card(get_string('managementbusinessroles', 'local_kbacorporate'), (string)$model->roles,
            get_string('managementroleassignments', 'local_kbacorporate', $model->roleassignments)),
        $card(get_string('managementcompetencygaps', 'local_kbacorporate'), (string)$model->gaps->gaps,
            get_string('managementcriticalgaps', 'local_kbacorporate', $model->gaps->critical)),
        $card(get_string('managementactiveidp', 'local_kbacorporate'), (string)$model->plans->active,
            get_string('managementoverduevalue', 'local_kbacorporate', $model->plans->overdue)),
    ]);
    echo $cards([
        $card(get_string('managementcoursecompletions', 'local_kbacorporate'), (string)$model->coursecompletions,
            get_string('managementcompletionwarning', 'local_kbacorporate')),
        $card(get_string('managementreassessments', 'local_kbacorporate'), (string)$model->reassessments->total,
            get_string('managementcompletedvalue', 'local_kbacorporate', $model->reassessments->completed)),
        $card(get_string('managementunassessed', 'local_kbacorporate'), (string)$model->gaps->unknown,
            get_string('managementunassessedhint', 'local_kbacorporate')),
        $card(get_string('managementidpactions', 'local_kbacorporate'), (string)$model->planitems->total,
            get_string('managementcompletedvalue', 'local_kbacorporate', $model->planitems->completed)),
    ]);
    echo html_writer::div(get_string('managementmethodologynotice', 'local_kbacorporate'),
        'alert alert-info mt-3');
}

echo $OUTPUT->footer();
