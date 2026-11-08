<?php
require_once(__DIR__ . '/../../config.php');

use local_kbacompany\local\battle_plan_service;
use local_kbacompany\local\battle_plan_week_coordination_service;
use local_kbacompany\local\battle_plan_protocol_service;
use local_kbacompany\local\task_service;
use local_kbacompany\local\bms24_ui_kit_service;

$GLOBALS['bms24_coord_render_step'] = 'bootstrap';
require_login();
$uuid = required_param('uuid', PARAM_ALPHANUMEXT);
$week = optional_param('week', '', PARAM_TEXT);
$orgnode = optional_param('orgnode', '', PARAM_ALPHANUMEXT);
$historyuserid = optional_param('historyuserid', 0, PARAM_INT);
$historyweeks = optional_param('historyweeks', 8, PARAM_INT);
$decisiontask = optional_param('decisiontask', '', PARAM_ALPHANUMEXT);
$protocolfilter = optional_param('protocolfilter', 'all', PARAM_ALPHA);
$protocolsearch = trim(optional_param('protocolsearch', '', PARAM_TEXT));
$protocolsearch = core_text::substr($protocolsearch, 0, 120);
if (!in_array($protocolfilter, ['all', 'pending', 'inprogress', 'completed', 'cancelled', 'unsaved'], true)) {
    $protocolfilter = 'all';
}

$protocolerror = '';
if (optional_param('protocolaction', '', PARAM_ALPHA) === 'status') {
    require_sesskey();
    try {
        battle_plan_protocol_service::change_status($uuid, (int)$USER->id,
            required_param('week', PARAM_TEXT), optional_param('orgnode', '', PARAM_ALPHANUMEXT),
            required_param('taskuuid', PARAM_ALPHANUMEXT), required_param('expectedrevision', PARAM_INT),
            required_param('executionstatus', PARAM_ALPHA));
        redirect((new moodle_url('/local/kbacompany/battle_plan_week_coordination.php',
            ['uuid'=>$uuid,'week'=>$week,'orgnode'=>$orgnode,'historyuserid'=>$historyuserid,
                'historyweeks'=>$historyweeks,'protocolfilter'=>$protocolfilter,'protocolsearch'=>$protocolsearch,
                'decisiontask'=>required_param('taskuuid', PARAM_ALPHANUMEXT),
                'protocolsaved'=>1]))->out(false) . '#bpwc-protocol-' . required_param('taskuuid', PARAM_ALPHANUMEXT));
    } catch (\Throwable $e) {
        $protocolerror = $e->getMessage();
    }
}

if (optional_param('protocolaction', '', PARAM_ALPHA) === 'save') {
    require_sesskey();
    try {
        battle_plan_protocol_service::save($uuid, (int)$USER->id,
            required_param('week', PARAM_TEXT), optional_param('orgnode', '', PARAM_ALPHANUMEXT),
            required_param('taskuuid', PARAM_ALPHANUMEXT), required_param('expectedrevision', 0, PARAM_INT),
            required_param('reason', PARAM_TEXT), required_param('decisiontext', PARAM_TEXT),
            required_param('owneruserid', PARAM_INT), optional_param('duedate', '', PARAM_TEXT));
        redirect((new moodle_url('/local/kbacompany/battle_plan_week_coordination.php',
            ['uuid'=>$uuid,'week'=>$week,'orgnode'=>$orgnode,'historyuserid'=>$historyuserid,
                'historyweeks'=>$historyweeks,'protocolfilter'=>$protocolfilter,'protocolsearch'=>$protocolsearch,
                'decisiontask'=>required_param('taskuuid', PARAM_ALPHANUMEXT),
                'protocolsaved'=>1]))->out(false) . '#bpwc-protocol-' . required_param('taskuuid', PARAM_ALPHANUMEXT));
    } catch (\Throwable $e) {
        $protocolerror = $e->getMessage();
    }
}

$GLOBALS['bms24_coord_render_step'] = 'read-model';
$data = battle_plan_week_coordination_service::context(
    $uuid, (int)$USER->id, $week, $orgnode, $historyuserid, $historyweeks);
$PAGE->set_url(new moodle_url('/local/kbacompany/battle_plan_week_coordination.php', ['uuid'=>$uuid]));
$PAGE->set_context(context_system::instance());
$PAGE->set_title(get_string('battleplan_weekcoord_title','local_kbacompany'));
$PAGE->set_heading(get_string('pluginname','local_kbacompany'));

$GLOBALS['bms24_coord_render_step'] = 'app-shell';
$bmsshell = \local_kbacompany\local\bms_page_shell_service::begin(
    $uuid, (int)$USER->id, 'battleplan', get_string('battleplan_weekcoord_title','local_kbacompany'));
echo \local_kbacompany\local\bms_workspace_ux_service::management_nav($uuid, 'coordweek', $data['week_start']);
echo $OUTPUT->heading(get_string('battleplan_weekcoord_title','local_kbacompany'));
echo html_writer::link(new moodle_url('/local/kbacompany/battle_plan_decision_registry.php',
    ['uuid'=>$uuid]), get_string('battleplan_registry_title','local_kbacompany'),
    ['class'=>'btn btn-secondary mb-3']);
if ($protocolerror !== '') { echo $OUTPUT->notification(s($protocolerror), 'error'); }
if (optional_param('protocolsaved', 0, PARAM_BOOL)) {
    echo $OUTPUT->notification(get_string('battleplan_protocol_saved','local_kbacompany'), 'success');
}
echo html_writer::tag('p', get_string('battleplan_weekcoord_intro','local_kbacompany'), ['class'=>'lead']);
// A scoped, read-only decision detail remains available even when a task is no longer open.
if ($decisiontask !== '') {
    $focused = battle_plan_protocol_service::company_decision(
        $uuid, (int)$USER->id, $data['week_start'], $decisiontask);
    if ($focused) {
        $state = (string)$focused->executionstatus;
        if (!in_array($state, ['pending','inprogress','completed','cancelled'], true)) { $state = 'pending'; }
        $owner = $DB->get_record('user', ['id'=>(int)$focused->owneruserid, 'deleted'=>0],
            'id,firstname,lastname', IGNORE_MISSING);
        $ownername = $owner ? fullname($owner) : get_string('unknownuser');
        $detail = html_writer::tag('h2', get_string('battleplan_focus_title','local_kbacompany'));
        $detail .= html_writer::tag('p', s((string)$focused->decisiontext));
        $detail .= html_writer::tag('p', get_string('battleplan_week','local_kbacompany').': '.s($focused->weekstart));
        $detail .= html_writer::tag('p', get_string('battleplan_protocol_responsible','local_kbacompany').': '.s($ownername));
        $detail .= html_writer::tag('p', get_string('battleplan_registry_status','local_kbacompany').': '.
            get_string('battleplan_execution_'.$state,'local_kbacompany'));
        $detail .= html_writer::tag('p', get_string('battleplan_protocol_duedate','local_kbacompany').': '.
            s((string)$focused->duedate));
        $detail .= html_writer::tag('p', get_string('battleplan_weekcoord_protocol_reason','local_kbacompany').': '.
            s((string)$focused->reason));
        $taskurl = new moodle_url('/local/kbacompany/tasks.php',
            ['uuid'=>$uuid,'task'=>$decisiontask,'view'=>'detail']);
        $detail .= html_writer::link($taskurl, get_string('battleplan_focus_task','local_kbacompany'),
            ['class'=>'btn btn-outline-primary']);
        $detail .= ' ' . html_writer::link('#bpwc-protocol-' . $decisiontask,
            get_string('battleplan_execution_confirm', 'local_kbacompany'),
            ['class'=>'btn btn-primary ml-2']);
        $detail .= ' ' . html_writer::link(new moodle_url(
            '/local/kbacompany/battle_plan_decision_registry.php', ['uuid'=>$uuid]),
            get_string('battleplan_registry_title', 'local_kbacompany'),
            ['class'=>'btn btn-secondary ml-2']);
        echo html_writer::div($detail, 'bms24-card mb-4', ['id'=>'bpwc-focused-decision', 'tabindex'=>'-1']);
    } else {
        echo html_writer::div(get_string('battleplan_focus_not_found','local_kbacompany'),
            'alert alert-warning', ['id'=>'bpwc-focused-decision']);
    }
}


$GLOBALS['bms24_coord_render_step'] = 'filters';
$nodeopts = [''=>get_string('battleplan_overview_all_units','local_kbacompany')];
foreach ($data['orgnodes'] as $node) { $nodeopts[(string)$node['uuid']] = (string)$node['name']; }
$history=(array)($data['management_history'] ?? []);
if (!empty($data['invalid_history_user_filter'])) {
    echo html_writer::div(get_string('battleplan_weekcoord_inaccessible_person', 'local_kbacompany'),
        'alert alert-warning', ['role'=>'status']);
}

$useropts=[0=>get_string('battleplan_weekcoord_history_all_people','local_kbacompany')];
foreach ((array)($history['users'] ?? []) as $userid=>$name) { $useropts[(int)$userid]=(string)$name; }
$weekopts=[
    4=>get_string('battleplan_weekcoord_history_4weeks','local_kbacompany'),
    8=>get_string('battleplan_weekcoord_history_8weeks','local_kbacompany'),
];
echo bms24_ui_kit_service::filterbar(
    new moodle_url('/local/kbacompany/battle_plan_week_coordination.php'),
    get_string('battleplan_weekcoord_filter_title','local_kbacompany'),
    [
        ['id'=>'bpwc_week','name'=>'week','label'=>get_string('battleplan_week','local_kbacompany'),'type'=>'date','value'=>$data['week_start']],
        ['id'=>'bpwc_unit','name'=>'orgnode','label'=>get_string('battleplan_overview_unit','local_kbacompany'),'type'=>'select','value'=>$data['orgnode_filter'],'options'=>$nodeopts],
        ['id'=>'bpwc_history_user','name'=>'historyuserid','label'=>get_string('battleplan_weekcoord_history_person','local_kbacompany'),'type'=>'select','value'=>(int)($history['userid']??0),'options'=>$useropts],
        ['id'=>'bpwc_history_weeks','name'=>'historyweeks','label'=>get_string('battleplan_weekcoord_history_depth','local_kbacompany'),'type'=>'select','value'=>(int)($history['weeks']??8),'options'=>$weekopts],
    ],
    ['uuid'=>$uuid],
    new moodle_url('/local/kbacompany/battle_plan_week_coordination.php',['uuid'=>$uuid]),
    get_string('battleplan_show','local_kbacompany'),
    get_string('reset','core')
);
echo html_writer::tag('p', s(battle_plan_service::period_label($data['week_start'],$data['week_end'])), ['class'=>'text-muted']);

$GLOBALS['bms24_coord_render_step'] = 'navigation';
// Weekly coordination navigation: a single read-only meeting flow over existing canonical controllers.
$coordsteps = [
    ['followup', 'battleplan_weekcoord_step_review'],
    ['agenda', 'battleplan_weekcoord_step_decide'],
    ['decisions', 'battleplan_decision_control_title'],
    ['protocol', 'battleplan_weekcoord_step_protocol'],
    ['history', 'battleplan_weekcoord_step_analyse'],
    ['patterns', 'battleplan_weekcoord_step_followthrough'],
];
$coordlinks = '';
foreach ($coordsteps as $step) {
    $coordlinks .= html_writer::link('#bpwc-' . $step[0], get_string($step[1], 'local_kbacompany'),
        ['class' => 'bms24-weekcoord-step', 'aria-label' => get_string($step[1], 'local_kbacompany')]);
}
echo html_writer::div(
    html_writer::tag('strong', get_string('battleplan_weekcoord_steps_title', 'local_kbacompany')) .
    html_writer::div($coordlinks, 'bms24-weekcoord-step-links') .
    html_writer::tag('p', get_string('battleplan_weekcoord_steps_help', 'local_kbacompany'),
        ['class' => 'text-muted mb-0']),
    'bms24-card bms24-weekcoord-steps mb-4', ['role' => 'navigation',
        'aria-label' => get_string('battleplan_weekcoord_steps_title', 'local_kbacompany')]);


$GLOBALS['bms24_coord_render_step'] = 'overview-kpis';
// Action-first decision queue. Every card links to an existing canonical controller.
$defs = [
    'submitted'=>['battleplan_weekcoord_submitted','battle_plan.php',['status'=>'submitted']],
    'needs_decision'=>['battleplan_overview_needs_decision','battle_plan_overview.php',['attention'=>'needs_decision']],
    'overdue_tasks'=>['battleplan_overview_overdue_tasks','battle_plan_overview.php',['attention'=>'overdue']],
    'critical_exceptions'=>['battleplan_overview_critical_exceptions','indicators.php',['tab'=>'exceptions']],
    'missing_plans'=>['battleplan_coverage_missing_plans','battle_plan_coverage.php',['gap'=>'missing_plan']],
    'blocked'=>['battleplan_readiness_blocked','battle_plan_readiness.php',['readiness'=>'blocked']],
];
$cards='';
foreach ($defs as $key=>$def) {
    $params=['uuid'=>$uuid,'week'=>$data['week_start']]+$def[2];
    if ($data['orgnode_filter'] !== '' && $def[1] !== 'indicators.php') { $params['orgnode']=$data['orgnode_filter']; }
    $url=new moodle_url('/local/kbacompany/'.$def[1],$params);
    $cards.=html_writer::link($url,
        html_writer::tag('strong',(string)$data['summary'][$key]).
        html_writer::tag('span',get_string($def[0],'local_kbacompany')),
        ['class'=>'bms24-weekcoord-kpi']);
}
echo html_writer::div($cards,'bms24-weekcoord-kpis mb-4');

$perf=(array)$data['performance'];
$perfcards='';
foreach ([
    'productivity'=>'bpu_cycle_productivity',
    'effectiveness'=>'bpu_cycle_effectiveness',
    'coverage'=>'bpu_cycle_data_coverage',
] as $key=>$lang) {
    $value=$perf[$key] ?? null;
    $perfcards.=html_writer::div(
        html_writer::tag('small',get_string($lang,'local_kbacompany')).
        html_writer::tag('strong',$value===null?'—':((int)$value).'%'),
        'bms24-weekcoord-performance-card');
}
echo html_writer::start_div('bms24-card bms24-weekcoord-performance mb-4');
echo html_writer::start_div('bms24-cardheader');
echo html_writer::tag('h2',get_string('battleplan_weekcoord_performance','local_kbacompany'));
echo html_writer::tag('p',get_string('battleplan_weekcoord_performance_help','local_kbacompany'));
echo html_writer::end_div();
echo html_writer::div($perfcards,'bms24-weekcoord-performance-grid');
echo html_writer::end_div();

$GLOBALS['bms24_coord_render_step'] = 'agenda';
// Concrete agenda: no second decision registry, only direct links into canonical objects.
echo html_writer::start_div('bms24-card bms24-weekcoord-agenda mb-4', ['id' => 'bpwc-agenda']);
echo html_writer::start_div('bms24-cardheader');
echo html_writer::tag('h2',get_string('battleplan_weekcoord_agenda','local_kbacompany'));
echo html_writer::tag('p',get_string('battleplan_weekcoord_agenda_help','local_kbacompany'));
echo html_writer::end_div();
if (!$data['agenda']) {
    echo $OUTPUT->notification(get_string('battleplan_weekcoord_empty','local_kbacompany'),'success');
} else {
    $html='';
    foreach (array_slice($data['agenda'],0,16) as $item) {
        $kind=s((string)$item['kind']);
        $actions='';
        foreach ((array)($item['actions'] ?? []) as $action) {
            $class=!empty($action['primary']) ? 'btn btn-sm btn-primary' : 'btn btn-sm btn-outline-secondary';
            $actions.=html_writer::link((string)$action['url'],
                get_string((string)$action['label'],'local_kbacompany'), ['class'=>$class]);
        }
        $body=html_writer::div(
                html_writer::span(get_string('battleplan_weekcoord_kind_'.$kind,'local_kbacompany'),
                    'bms24-weekcoord-kind is-'.$kind).
                html_writer::link((string)$item['url'],html_writer::tag('strong',s((string)$item['title'])),
                    ['class'=>'bms24-weekcoord-agenda-title']),
                'bms24-weekcoord-agenda-main'
            ).
            html_writer::tag('small',s((string)$item['meta']),['class'=>'text-muted']);
        $html.=html_writer::div($body.html_writer::div($actions,'bms24-weekcoord-actions'),
            'bms24-weekcoord-agenda-row');
    }
    echo html_writer::div($html,'bms24-weekcoord-agenda-list');
    if (count($data['agenda'])>16) {
        echo html_writer::tag('p',get_string('battleplan_weekcoord_more','local_kbacompany',count($data['agenda'])-16),['class'=>'text-muted mt-2']);
    }
}
echo html_writer::end_div();

// Previous-week follow-up: canonical tasks + factual week-over-week movement, never causality.
$followup=(array)($data['previous_week_followup'] ?? []);
$fsummary=(array)($followup['summary'] ?? []);
$frows=(array)($followup['rows'] ?? []);
echo html_writer::start_div('bms24-card bms24-weekcoord-followup mb-4', ['id' => 'bpwc-followup']);
echo html_writer::start_div('bms24-cardheader');
echo html_writer::tag('h2',get_string('battleplan_weekcoord_followup_title','local_kbacompany'));
echo html_writer::tag('p',get_string('battleplan_weekcoord_followup_help','local_kbacompany',
    battle_plan_service::period_label($data['previous_week'],
        (new DateTimeImmutable($data['previous_week'],new DateTimeZone('UTC')))->modify('+6 days')->format('Y-m-d'))));
echo html_writer::end_div();
$followcards='';
foreach ([
    'total'=>'battleplan_weekcoord_followup_total',
    'done'=>'battleplan_weekcoord_followup_done',
    'open'=>'battleplan_weekcoord_followup_open',
] as $key=>$lang) {
    $followcards.=html_writer::div(
        html_writer::tag('small',get_string($lang,'local_kbacompany')).
        html_writer::tag('strong',(string)(int)($fsummary[$key]??0)),
        'bms24-weekcoord-performance-card');
}
echo html_writer::div($followcards,'bms24-weekcoord-performance-grid mb-3');
if (!$frows) {
    echo html_writer::tag('p',get_string('battleplan_weekcoord_followup_empty','local_kbacompany'),['class'=>'text-muted']);
} else {
    $followhtml='';
    foreach (array_slice($frows,0,12) as $row) {
        $d=(array)$row['delta'];
        $fmt=static function($value): string {
            if ($value===null) { return '—'; }
            $value=(int)$value;
            return ($value>0?'+':'').$value.' п.п.';
        };
        $movement=get_string('bpu_cycle_productivity','local_kbacompany').': '.$fmt($d['productivity']??null).' · '.
            get_string('bpu_cycle_effectiveness','local_kbacompany').': '.$fmt($d['effectiveness']??null).' · '.
            get_string('bpu_cycle_data_coverage','local_kbacompany').': '.$fmt($d['coverage']??null);
        $meta=get_string('battleplan_weekcoord_followup_counts','local_kbacompany',(object)[
            'done'=>(int)$row['done'],'open'=>(int)$row['open'],'total'=>(int)$row['total']]);
        if ((string)$row['orgnode']!=='') { $meta.=' · '.(string)$row['orgnode']; }
        $actions='';
        foreach ((array)$row['actions'] as $action) {
            $class=!empty($action['primary'])?'btn btn-sm btn-primary':'btn btn-sm btn-outline-secondary';
            $actions.=html_writer::link((string)$action['url'],
                get_string((string)$action['label'],'local_kbacompany'),['class'=>$class]);
        }
        $openitems='';
        if (!empty($row['open_items'])) {
            $titles=[];
            foreach ((array)$row['open_items'] as $task) { $titles[]=s((string)$task['title']); }
            $openitems=html_writer::tag('small',get_string('battleplan_weekcoord_followup_open_items','local_kbacompany').': '.
                implode(' · ',$titles),['class'=>'text-muted']);
        }
        $main=html_writer::div(
            html_writer::tag('strong',s((string)$row['employee_name'].' — '.(string)$row['position'])).
            html_writer::tag('small',s($meta),['class'=>'text-muted']).
            html_writer::tag('small',s($movement),['class'=>'bms24-weekcoord-unit-metrics']).$openitems,
            'bms24-weekcoord-followup-main');
        $followhtml.=html_writer::div($main.html_writer::div($actions,'bms24-weekcoord-actions'),
            'bms24-weekcoord-agenda-row');
    }
    echo html_writer::div($followhtml,'bms24-weekcoord-agenda-list');
}
echo html_writer::tag('p',get_string('battleplan_weekcoord_followup_causality','local_kbacompany'),
    ['class'=>'text-muted mt-3 mb-0']);
echo html_writer::end_div();

// Manager dashboard: exact saved protocol execution states, deadline breach only for open decisions.
$decisionqueue = battle_plan_protocol_service::decision_queue(
    $uuid, (int)$USER->id, $data['week_start'], $data['orgnode_filter']);
$today = date('Y-m-d', time());
$counts = ['total'=>0,'pending'=>0,'inprogress'=>0,'completed'=>0,'cancelled'=>0,
    'overdue'=>0,'upcoming'=>0,'undated'=>0];
$byowner = [];
foreach ($decisionqueue as $entry) {
    $executionstatus = (string)($entry->executionstatus ?? 'pending');
    if (!in_array($executionstatus, ['pending','inprogress','completed','cancelled'], true)) {
        $executionstatus = 'pending';
    }
    $counts['total']++;
    $counts[$executionstatus]++;
    $date = (string)$entry->duedate;
    $isopen = !in_array($executionstatus, ['completed','cancelled'], true);
    if ($isopen) {
        if ($date === '') { $counts['undated']++; }
        else if ($date < $today) { $counts['overdue']++; }
        else { $counts['upcoming']++; }
    }
    $ownerid = (int)$entry->owneruserid;
    if (!isset($byowner[$ownerid])) {
        $byowner[$ownerid] = ['total'=>0,'open'=>0,'overdue'=>0,'completed'=>0];
    }
    $byowner[$ownerid]['total']++;
    if ($executionstatus === 'completed') { $byowner[$ownerid]['completed']++; }
    if ($isopen) {
        $byowner[$ownerid]['open']++;
        if ($date !== '' && $date < $today) { $byowner[$ownerid]['overdue']++; }
    }
}
echo html_writer::start_div('bms24-card bms24-weekcoord-decisions mb-4', ['id'=>'bpwc-decisions']);
echo html_writer::tag('h2', get_string('battleplan_decision_control_title','local_kbacompany'));
echo html_writer::tag('p', get_string('battleplan_dashboard_explainer','local_kbacompany'),
    ['class'=>'text-muted']);
echo html_writer::tag('p', get_string('battleplan_protocol_task_status_help','local_kbacompany'),
    ['class'=>'text-muted small']);
$stats = '';
foreach (['total','pending','inprogress','completed','cancelled','overdue','upcoming','undated'] as $type) {
    $label = in_array($type, ['pending','inprogress','completed','cancelled'], true) ?
        get_string('battleplan_execution_'.$type,'local_kbacompany') :
        get_string('battleplan_decision_'.$type,'local_kbacompany');
    $stats .= html_writer::div(html_writer::tag('strong',(string)$counts[$type]).
        html_writer::tag('span',$label), 'bms24-weekcoord-kpi');
}
echo html_writer::div($stats, 'bms24-weekcoord-kpis mb-3');
if (!$decisionqueue) {
    echo html_writer::tag('p',get_string('battleplan_decision_empty','local_kbacompany'),
        ['class'=>'text-muted']);
} else {
    $ownerids = array_unique(array_map(static function($item) {return (int)$item->owneruserid;}, $decisionqueue));
    list($uql, $uparams) = $DB->get_in_or_equal($ownerids, SQL_PARAMS_NAMED, 'owner');
    $ownerrecords = $DB->get_records_select('user', 'id '.$uql, $uparams,
        '', 'id,firstname,lastname');
    // Aggregates share the same access-filtered queue as the decision list.
    if ($byowner) {
        $ownerrows = '';
        uasort($byowner, static function($a, $b) {
            return ($b['overdue'] <=> $a['overdue']) ?: ($b['open'] <=> $a['open']);
        });
        foreach ($byowner as $ownerid=>$metrics) {
            $person = $ownerrecords[$ownerid] ?? null;
            $name = $person ? fullname($person) : get_string('unknownuser');
            $cells = [s($name), (string)$metrics['total'], (string)$metrics['open'],
                (string)$metrics['overdue'], (string)$metrics['completed']];
            $ownerrows .= html_writer::tag('tr', implode('', array_map(static function($cell) {
                return html_writer::tag('td', $cell);
            }, $cells)));
        }
        $ownerhead = '';
        foreach (['battleplan_protocol_responsible','battleplan_decision_total',
                'battleplan_dashboard_open','battleplan_decision_overdue',
                'battleplan_execution_completed'] as $key) {
            $ownerhead .= html_writer::tag('th', get_string($key,'local_kbacompany'));
        }
        echo html_writer::tag('h3', get_string('battleplan_dashboard_by_owner','local_kbacompany'));
        echo html_writer::div(html_writer::tag('table',
            html_writer::tag('thead',html_writer::tag('tr',$ownerhead)).
            html_writer::tag('tbody',$ownerrows), ['class'=>'table table-striped']), 'table-responsive mb-3');
    }
    $rows = '';
    foreach ($decisionqueue as $entry) {
        $owner = $ownerrecords[(int)$entry->owneruserid] ?? null;
        $ownername = $owner ? fullname($owner) : '—';
        $date = (string)$entry->duedate;
        $executionstatus = (string)($entry->executionstatus ?? 'pending');
        $status = in_array($executionstatus, ['completed', 'cancelled'], true) ? 'closed' :
            ($date === '' ? 'undated' : ($date < $today ? 'overdue' : 'upcoming'));
        // The canonical Task Center projection enforces independent task-level access.
        // Never infer task completion from the decision's execution status or vice versa.
        $task = task_service::readable_projection($uuid, (int)$USER->id, (string)$entry->taskuuid);
        $taskurl = new moodle_url('/local/kbacompany/tasks.php',
            ['uuid'=>$uuid,'task'=>(string)$entry->taskuuid,'view'=>'detail']);
        $taskcell = $task ? html_writer::link($taskurl, s((string)$entry->tasktitle)) :
            get_string('battleplan_protocol_task_unavailable', 'local_kbacompany');
        $taskstate = $task && isset($task['status']) ? (string)$task['status'] : '';
        $taskstatecell = $taskstate !== '' ?
            get_string('taskcenter_status_'.$taskstate, 'local_kbacompany') : '—';
        $confirmurl = new moodle_url('/local/kbacompany/battle_plan_week_coordination.php',
            ['uuid'=>$uuid, 'week'=>$data['week_start'], 'orgnode'=>$data['orgnode_filter'],
             'historyuserid'=>$historyuserid, 'historyweeks'=>$historyweeks]);
        // The manager confirms separately in the existing revision-checked protocol form.
        $confirmcell = html_writer::link($confirmurl->out(false).'#bpwc-protocol',
            get_string('battleplan_protocol_review_decision', 'local_kbacompany'));
        $cells = [$taskcell, s($entry->decisiontext), s($ownername),
            $date === '' ? '—' : s(userdate(strtotime($date.' 12:00'), '%d.%m.%Y')),
            $taskstatecell,
            ($status === 'closed' ? '—' : get_string('battleplan_decision_'.$status,'local_kbacompany')),
            get_string('battleplan_execution_'.$executionstatus, 'local_kbacompany'),
            $confirmcell];
        $rows .= html_writer::tag('tr',implode('',array_map(static function($cell) {
            return html_writer::tag('td',$cell);
        },$cells)));
    }
    $headers = ['battleplan_decision_task','battleplan_weekcoord_protocol_decision',
        'battleplan_protocol_responsible','battleplan_protocol_duedate',
        'battleplan_protocol_task_status','battleplan_decision_deadline_status',
        'battleplan_execution_status','battleplan_protocol_review_action'];
    $head = '';
    foreach ($headers as $key) { $head .= html_writer::tag('th',get_string($key,'local_kbacompany')); }
    echo html_writer::div(html_writer::tag('table',
        html_writer::tag('thead',html_writer::tag('tr',$head)).html_writer::tag('tbody',$rows),
        ['class'=>'table table-striped table-responsive-sm']), 'table-responsive');
}
echo html_writer::end_div();

$GLOBALS['bms24_coord_render_step'] = 'protocols';
// Meeting protocol preparation: a read-only task-linked worksheet. No unsaved text is treated as a decision.
echo html_writer::start_div('bms24-card bms24-weekcoord-protocol mb-4', ['id' => 'bpwc-protocol']);
echo html_writer::start_div('bms24-cardheader');
echo html_writer::tag('h2', get_string('battleplan_weekcoord_protocol_title', 'local_kbacompany'));
echo html_writer::tag('p', get_string('battleplan_weekcoord_protocol_help', 'local_kbacompany'));
echo html_writer::end_div();
$protocolitems = [];
foreach ($frows as $employee) {
    foreach ((array)($employee['open_items'] ?? []) as $task) {
        $taskuuid = (string)($task['uuid'] ?? '');
        if ($taskuuid === '' || isset($protocolitems[$taskuuid])) { continue; }
        // The read-model already scopes employee and task visibility to the current actor.
        $protocolitems[$taskuuid] = [
            'title' => (string)($task['title'] ?? ''),
            'employee' => (string)($employee['employee_name'] ?? ''),
            'uuid' => $taskuuid,
        ];
    }
}
if (!$protocolitems) {
    echo html_writer::tag('p', get_string('battleplan_weekcoord_protocol_empty', 'local_kbacompany'),
        ['class'=>'text-muted']);
} else {
    // Apply text and status filters against the ACL-scoped full candidate set before the UI cap.
    $candidates = array_values($protocolitems);
    if ($protocolsearch !== '') {
        $needle = core_text::strtolower($protocolsearch);
        $candidates = array_values(array_filter($candidates, static function(array $item) use ($needle): bool {
            return core_text::strpos(core_text::strtolower($item['title'] . ' ' . $item['employee']), $needle) !== false;
        }));
    }
    $candidateuuids = array_column($candidates, 'uuid');
    $allstatus = $candidateuuids ? battle_plan_protocol_service::latest($uuid, (int)$USER->id,
        $data['week_start'], $data['orgnode_filter'], $candidateuuids) : [];
    if ($protocolfilter !== 'all') {
        $candidates = array_values(array_filter($candidates, static function(array $item) use ($allstatus, $protocolfilter, $decisiontask): bool {
            $id = $item['uuid'];
            $status = isset($allstatus[$id]) ? (string)$allstatus[$id]->executionstatus : 'unsaved';
            return $protocolfilter === $status || $decisiontask === $id;
        }));
    }
    $matchedcount = count($candidates);
    $shown = array_slice($candidates, 0, 30);
    // Always show an explicitly focused decision, even when it is beyond the first page.
    if ($decisiontask !== '' && isset($protocolitems[$decisiontask]) &&
        !in_array($decisiontask, array_column($shown, 'uuid'), true)) {
        $shown[] = $protocolitems[$decisiontask];
    }
    $shownuuids = array_column($shown, 'uuid');
    $saved = array_intersect_key($allstatus, array_flip($shownuuids));
    $historybytask = $shownuuids ? battle_plan_protocol_service::history($uuid, (int)$USER->id,
        $data['week_start'], $data['orgnode_filter'], $shownuuids) : [];
    $owneroptions = battle_plan_protocol_service::owner_options($data);
    // Filter the ACL-scoped protocol list without changing its saved data or mutations.
    $filterlabels = ['all'=>current_language() === 'ru' ? 'Все решения' : 'All decisions',
        'pending'=>get_string('battleplan_execution_pending', 'local_kbacompany'),
        'inprogress'=>get_string('battleplan_execution_inprogress', 'local_kbacompany'),
        'completed'=>get_string('battleplan_execution_completed', 'local_kbacompany'),
        'cancelled'=>get_string('battleplan_execution_cancelled', 'local_kbacompany'),
        'unsaved'=>current_language() === 'ru' ? 'Без протокола' : 'No protocol'];
    echo html_writer::start_tag('form', ['method'=>'get', 'class'=>'form-inline mb-3']);
    foreach (['uuid'=>$uuid, 'week'=>$data['week_start'], 'orgnode'=>$data['orgnode_filter'],
        'historyuserid'=>$historyuserid, 'historyweeks'=>$historyweeks] as $key=>$value) {
        echo html_writer::empty_tag('input', ['type'=>'hidden', 'name'=>$key, 'value'=>$value]);
    }
    echo html_writer::label(current_language() === 'ru' ? 'Поиск по задаче или сотруднику' :
        'Search task or employee', 'bpwc-protocolsearch', false, ['class'=>'mr-2']);
    echo html_writer::empty_tag('input', ['type'=>'search', 'name'=>'protocolsearch',
        'id'=>'bpwc-protocolsearch', 'value'=>$protocolsearch, 'maxlength'=>120,
        'placeholder'=>current_language() === 'ru' ? 'Название задачи или имя' : 'Task or employee name',
        'class'=>'form-control mr-3 mb-2']);
    echo html_writer::label(current_language() === 'ru' ? 'Статус решения' : 'Decision status',
        'bpwc-protocolfilter', false, ['class'=>'mr-2']);
    echo html_writer::select($filterlabels, 'protocolfilter', $protocolfilter, false,
        ['id'=>'bpwc-protocolfilter', 'class'=>'form-control mr-2']);
    echo html_writer::tag('button', get_string('battleplan_show','local_kbacompany'),
        ['type'=>'submit','class'=>'btn btn-outline-primary']);
    echo html_writer::end_tag('form');
    $visiblecount = 0;
    foreach ($shown as $item) {
        $taskuuid = $item['uuid'];
        $prev = $saved[$taskuuid] ?? null;
        $currentstatus = $prev ? (string)$prev->executionstatus : 'unsaved';
        // Always expose a directly linked decision, even if the active filter differs.
        if ($protocolfilter !== 'all' && $protocolfilter !== $currentstatus && $decisiontask !== $taskuuid) {
            continue;
        }
        $visiblecount++;
        $taskurl = new moodle_url('/local/kbacompany/tasks.php',
            ['uuid'=>$uuid, 'task'=>$taskuuid, 'view'=>'detail']);
        $isfocused = $decisiontask === $taskuuid;
        echo html_writer::start_tag('details', ['class'=>'card mb-2 bms24-protocol-compact' .
            ($isfocused ? ' bms24-protocol-focused' : ''), 'id'=>'bpwc-protocol-'.$taskuuid,
            'tabindex'=>'-1'] + ($isfocused ? ['open'=>'open'] : []));
        $statuslabel = $prev ? get_string('battleplan_execution_'.$currentstatus, 'local_kbacompany') :
            $filterlabels['unsaved'];
        echo html_writer::tag('summary',
            html_writer::tag('strong', s($item['employee']) . ': ' . s($item['title'])) .
            html_writer::tag('span', s($statuslabel), ['class'=>'badge badge-light ml-2']),
            ['class'=>'p-3 bms24-protocol-compact-summary']);
        echo html_writer::start_div('card-body pt-0');
        // Read-only decision detail is always visible; editing is an explicit action.
        if ($prev) {
            $ownername = $owneroptions[(int)$prev->owneruserid] ??
                (current_language() === 'ru' ? 'Недоступный сотрудник' : 'Unavailable employee');
            $decisionfields = [
                get_string('battleplan_weekcoord_protocol_reason', 'local_kbacompany') => (string)$prev->reason,
                get_string('battleplan_weekcoord_protocol_decision', 'local_kbacompany') => (string)$prev->decisiontext,
                $responsiblelabel = (current_language() === 'ru' ? 'Ответственный' : 'Responsible') => $ownername,
                get_string('battleplan_protocol_duedate', 'local_kbacompany') => (string)$prev->duedate,
            ];
            echo html_writer::start_div('bms24-decision-detail border rounded p-3 mb-3');
            foreach ($decisionfields as $fieldlabel => $fieldvalue) {
                echo html_writer::div(html_writer::tag('strong', s($fieldlabel) . ': ') .
                    nl2br(s($fieldvalue !== '' ? $fieldvalue : '—')),
                    'bms24-decision-detail-row mb-2');
            }
            echo html_writer::end_div();
        }
        // One compact action bar, available only inside the ACL-scoped protocol card.
        echo html_writer::start_div('bms24-decision-actions d-flex flex-wrap align-items-center mb-3',
            ['role'=>'group', 'aria-label'=>current_language() === 'ru' ? 'Действия с решением' : 'Decision actions']);
        echo html_writer::link($taskurl, current_language() === 'ru' ? 'Открыть задачу' : 'Open task',
            ['class'=>'btn btn-outline-secondary btn-sm mr-2 mb-2']);
        if ($prev) {
            echo html_writer::link('#bpwc-editor-'.$taskuuid,
                current_language() === 'ru' ? 'Изменить решение' : 'Edit decision',
                ['class'=>'btn btn-outline-secondary btn-sm mr-2 mb-2']);
            if (!in_array($currentstatus, ['completed', 'cancelled'], true)) {
                // Confirming a decision is an explicit POST; it never closes the Task Center task.
                echo html_writer::start_tag('form', ['method'=>'post','class'=>'d-inline-block mb-2 mr-2']);
                foreach (['uuid'=>$uuid, 'week'=>$data['week_start'],
                    'orgnode'=>$data['orgnode_filter'], 'historyuserid'=>$historyuserid,
                    'historyweeks'=>$historyweeks, 'protocolfilter'=>$protocolfilter,
                    'protocolsearch'=>$protocolsearch, 'protocolaction'=>'status',
                    'decisiontask'=>$taskuuid, 'taskuuid'=>$taskuuid,
                    'expectedrevision'=>(int)$prev->revision, 'executionstatus'=>'completed',
                    'sesskey'=>sesskey()] as $key=>$val) {
                    echo html_writer::empty_tag('input', ['type'=>'hidden','name'=>$key,'value'=>$val]);
                }
                echo html_writer::tag('button',
                    current_language() === 'ru' ? 'Подтвердить выполнение' : 'Confirm completion',
                    ['type'=>'submit','class'=>'btn btn-success btn-sm']);
                echo html_writer::end_tag('form');
            }
        }
        echo html_writer::end_div();
        if ($prev) {
            echo html_writer::tag('p', get_string('battleplan_protocol_revision','local_kbacompany') . ' ' .
                (int)$prev->revision . ' · ' . userdate((int)$prev->timecreated),
                ['class'=>'text-muted small mt-2']);
        }
        if ($prev) {
            $statuses = [];
            foreach (['pending', 'inprogress', 'completed', 'cancelled'] as $statuskey) {
                $statuses[$statuskey] = get_string('battleplan_execution_'.$statuskey, 'local_kbacompany');
            }
            echo html_writer::start_tag('form', ['method'=>'post','class'=>'bms24-decision-status-form my-3']);
            foreach (['uuid'=>$uuid, 'week'=>$data['week_start'], 'orgnode'=>$data['orgnode_filter'],
                'historyuserid'=>$historyuserid, 'historyweeks'=>$historyweeks,
                'protocolfilter'=>$protocolfilter, 'protocolaction'=>'status', 'decisiontask'=>$taskuuid,
                'taskuuid'=>$taskuuid, 'expectedrevision'=>(int)$prev->revision,
                'sesskey'=>sesskey()] as $key=>$val) {
                echo html_writer::empty_tag('input', ['type'=>'hidden','name'=>$key,'value'=>$val]);
            }
            echo html_writer::label(get_string('battleplan_execution_status','local_kbacompany'),
                'bpwc_status_'.$taskuuid);
            echo html_writer::select($statuses, 'executionstatus', (string)$prev->executionstatus, false,
                ['id'=>'bpwc_status_'.$taskuuid, 'class'=>'form-control form-control-sm mb-2']);
            echo html_writer::tag('button', get_string('battleplan_execution_confirm','local_kbacompany'),
                ['type'=>'submit','class'=>'btn btn-outline-primary']);
            echo html_writer::end_tag('form');
        }
        echo html_writer::start_tag('details', ['class'=>'bms24-decision-editor mt-3 mb-3', 'id'=>'bpwc-editor-'.$taskuuid]);
        echo html_writer::tag('summary', $prev ?
            (current_language() === 'ru' ? 'Редактировать решение' : 'Edit decision') :
            (current_language() === 'ru' ? 'Создать протокол' : 'Create protocol'),
            ['class'=>'btn btn-outline-secondary']);
        echo html_writer::start_tag('form', ['method'=>'post','class'=>'mt-3']);
        foreach (['uuid'=>$uuid,'week'=>$data['week_start'],'orgnode'=>$data['orgnode_filter'],
            'historyuserid'=>$historyuserid,'historyweeks'=>$historyweeks,
            'protocolfilter'=>$protocolfilter, 'protocolaction'=>'save','decisiontask'=>$taskuuid,'taskuuid'=>$taskuuid,
            'expectedrevision'=>$prev ? (int)$prev->revision : 0,'sesskey'=>sesskey()] as $key=>$val) {
            echo html_writer::empty_tag('input', ['type'=>'hidden','name'=>$key,'value'=>$val]);
        }
        foreach (['reason'=>'battleplan_weekcoord_protocol_reason',
            'decisiontext'=>'battleplan_weekcoord_protocol_decision'] as $key=>$label) {
            echo html_writer::label(get_string($label,'local_kbacompany'), 'bpwc_'.$key.'_'.$taskuuid);
            echo html_writer::tag('textarea', s($prev ? (string)$prev->$key : ''),
                ['name'=>$key,'id'=>'bpwc_'.$key.'_'.$taskuuid,'rows'=>2,'maxlength'=>4000,
                    'required'=>'required','class'=>'form-control mb-2']);
        }
        // Use a direct localized label to avoid stale Moodle language-cache entries from older releases.
        $responsiblelabel = current_language() === 'ru' ? 'Ответственный' : 'Responsible';
        echo html_writer::label($responsiblelabel, 'bpwc_owner_'.$taskuuid);
        $defaultowner = $prev ? (int)$prev->owneruserid : 0;
        if (!$defaultowner && count($owneroptions) === 1) { $defaultowner = (int)array_key_first($owneroptions); }
        echo html_writer::select($owneroptions, 'owneruserid', $defaultowner,
            [''=>get_string('battleplan_protocol_select_owner','local_kbacompany')],
            ['id'=>'bpwc_owner_'.$taskuuid,'required'=>'required','class'=>'form-control mb-2',
                'aria-label'=>$responsiblelabel]);
        if (!$owneroptions) {
            echo html_writer::div(get_string('battleplan_protocol_no_owners', 'local_kbacompany'),
                'alert alert-warning mt-2');
        }
        echo html_writer::label(get_string('battleplan_protocol_duedate','local_kbacompany'),
            'bpwc_due_'.$taskuuid);
        echo html_writer::empty_tag('input', ['type'=>'date','name'=>'duedate',
            'id'=>'bpwc_due_'.$taskuuid,'value'=>$prev ? s((string)$prev->duedate) : '',
            'class'=>'form-control mb-2']);
        echo html_writer::tag('button', get_string('battleplan_protocol_save','local_kbacompany'),
            ['type'=>'submit','class'=>'btn btn-primary'] + (!$owneroptions ? ['disabled'=>'disabled'] : []));
        echo html_writer::end_tag('form');
        echo html_writer::end_tag('details');
        $revisions = (array)($historybytask[$taskuuid] ?? []);
        if ($revisions) {
            $revisionhtml = '';
            foreach ($revisions as $revision) {
                $author = $DB->get_record('user', ['id'=>(int)$revision->createdby],
                    'id,firstname,lastname', IGNORE_MISSING);
                $authorname = $author ? fullname($author) : '—';
                $ownername = $owneroptions[(int)$revision->owneruserid] ??
                    (string)(int)$revision->owneruserid;
                $revisionhtml .= html_writer::tag('div',
                    html_writer::tag('strong', get_string('battleplan_protocol_revision','local_kbacompany') .
                        ' '.(int)$revision->revision) . ' · ' . s($authorname) . ' · ' .
                    userdate((int)$revision->timecreated) .
                    html_writer::tag('div', get_string('battleplan_weekcoord_protocol_reason','local_kbacompany') .
                        ': ' . s($revision->reason)) .
                    html_writer::tag('div', get_string('battleplan_weekcoord_protocol_decision','local_kbacompany') .
                        ': ' . s($revision->decisiontext)) .
                    html_writer::tag('div', get_string('battleplan_execution_status','local_kbacompany') .
                        ': ' . get_string('battleplan_execution_'.(string)$revision->executionstatus,'local_kbacompany')) .
                    html_writer::tag('div', $responsiblelabel .
                        ': ' . s($ownername) . ' · ' . s($revision->duedate)),
                    ['class'=>'border-top py-2']);
            }
            echo html_writer::tag('details',
                html_writer::tag('summary',get_string('battleplan_protocol_history','local_kbacompany').
                    ' ('.count($revisions).')') . $revisionhtml,
                ['class'=>'mt-3']);
        }
        echo html_writer::end_div();
        echo html_writer::end_tag('details');
    }
    if ($visiblecount === 0) {
        echo html_writer::div(current_language() === 'ru' ?
            'По заданным условиям задачи не найдены.' : 'No tasks match these filters.',
            'alert alert-light border');
    }
    if ($matchedcount>30) {
        echo html_writer::tag('p',get_string('battleplan_weekcoord_protocol_limit','local_kbacompany'),
            ['class'=>'text-muted']);
    }
}
echo html_writer::tag('p',get_string('battleplan_protocol_explainer','local_kbacompany'),
    ['class'=>'alert alert-info']);
echo html_writer::end_div();

// Bounded 4/8-week management history. All values are recomputed from canonical read-models.
$historyrows=(array)($history['rows'] ?? []);
echo html_writer::start_div('bms24-card bms24-weekcoord-history mb-4', ['id' => 'bpwc-history']);
echo html_writer::start_div('bms24-cardheader');
echo html_writer::tag('h2',get_string('battleplan_weekcoord_history_title','local_kbacompany'));
$historyscope=(int)($history['userid']??0)>0
    ? (string)($history['users'][(int)$history['userid']] ?? '')
    : ($data['orgnode_filter']!=='' ? (string)($nodeopts[$data['orgnode_filter']]??'')
        : get_string('battleplan_weekcoord_history_company_scope','local_kbacompany'));
echo html_writer::tag('p',get_string('battleplan_weekcoord_history_help','local_kbacompany',(object)[
    'weeks'=>(int)($history['weeks']??8),'scope'=>$historyscope]));
echo html_writer::end_div();
if (!$historyrows) {
    echo html_writer::tag('p',get_string('battleplan_weekcoord_history_empty','local_kbacompany'),['class'=>'text-muted']);
} else {
    $rowshtml='';
    foreach ($historyrows as $index=>$hrow) {
        $iscurrent=$index===count($historyrows)-1;
        $label=battle_plan_service::period_label((string)$hrow['week_start'],(string)$hrow['week_end']);
        $management=(array)($hrow['management_tasks']??[]);
        $attention=(array)($hrow['attention']??[]);
        $weekmeta=get_string('battleplan_weekcoord_history_week_meta','local_kbacompany',(object)[
            'plans'=>(int)($hrow['plans']??0),
            'done'=>(int)($management['done']??0),
            'open'=>(int)($management['open']??0),
        ]);
        $attentiontotal=(int)($attention['needs_decision']??0)+(int)($attention['overdue_tasks']??0)+
            (int)($attention['critical_exceptions']??0);
        if ($attentiontotal>0) {
            $weekmeta.=' · '.get_string('battleplan_weekcoord_history_attention','local_kbacompany',$attentiontotal);
        }
        $weekhead=html_writer::link((string)$hrow['url'],html_writer::tag('strong',s($label)));
        if ($iscurrent) {
            $weekhead.=' '.html_writer::tag('span',get_string('bpu_cycle_trend_current','local_kbacompany'),['class'=>'badge badge-success']);
        }
        $weekhead.=html_writer::tag('small',s($weekmeta),['class'=>'text-muted d-block']);
        $metrics='';
        foreach ([
            'productivity'=>'bpu_cycle_productivity',
            'effectiveness'=>'bpu_cycle_effectiveness',
            'coverage'=>'bpu_cycle_data_coverage',
        ] as $key=>$lang) {
            $value=$hrow[$key]??null;
            $display=$value===null?'—':((int)$value).'%';
            $width=$value===null?0:max(0,min(100,(int)$value));
            $metrics.=html_writer::div(
                html_writer::tag('span',get_string($lang,'local_kbacompany'),['class'=>'bms-bpu-trend-label']).
                html_writer::div(html_writer::div('','bms-bpu-trend-fill',['style'=>'width:'.$width.'%']),
                    'bms-bpu-trend-track',['aria-hidden'=>'true']).
                html_writer::tag('strong',s($display),['class'=>'bms-bpu-trend-value']),
                'bms-bpu-trend-metric');
        }
        $rowshtml.=html_writer::div(html_writer::div($weekhead,'bms-bpu-trend-week').$metrics,
            'bms-bpu-trend-row'.($iscurrent?' is-current':''));
    }
    echo html_writer::div($rowshtml,'bms-bpu-trend');
    echo html_writer::tag('p',get_string('battleplan_weekcoord_history_note','local_kbacompany'),
        ['class'=>'text-muted mt-3 mb-0']);
}
echo html_writer::end_div();

// Transparent recurring-management patterns over the same bounded history.
$patternmodel=(array)($data['management_patterns'] ?? []);
$patterns=(array)($patternmodel['items'] ?? []);
echo html_writer::start_div('bms24-card bms24-weekcoord-patterns mb-4', ['id' => 'bpwc-patterns']);
echo html_writer::start_div('bms24-cardheader');
echo html_writer::tag('h2',get_string('battleplan_weekcoord_patterns_title','local_kbacompany'));
echo html_writer::tag('p',get_string('battleplan_weekcoord_patterns_help','local_kbacompany'));
echo html_writer::end_div();
if (!$patterns) {
    echo html_writer::tag('p',get_string('battleplan_weekcoord_patterns_empty','local_kbacompany'),['class'=>'text-muted']);
} else {
    $patternhtml='';
    foreach ($patterns as $pattern) {
        $key=(string)($pattern['key'] ?? '');
        $matched=[];
        foreach ((array)($pattern['matched_weeks'] ?? []) as $patternweek) {
            try {
                $matched[]=(new DateTimeImmutable((string)$patternweek,new DateTimeZone('UTC')))->format('d.m.Y');
            } catch (Exception $e) {
                $matched[]=s((string)$patternweek);
            }
        }
        $rulekey='battleplan_weekcoord_pattern_rule_'.$key;
        $titlekey='battleplan_weekcoord_pattern_'.$key;
        $facts=get_string($rulekey,'local_kbacompany');
        if ($matched) {
            $facts.=' · '.get_string('battleplan_weekcoord_pattern_weeks','local_kbacompany',implode(', ',$matched));
        }
        $patternaction=(array)($pattern['action'] ?? []);
        $taskfeedback=(array)($pattern['task_feedback'] ?? []);
        $latesttask=is_array($taskfeedback['latest'] ?? null)?$taskfeedback['latest']:null;
        $factualresult=is_array($taskfeedback['factual_result'] ?? null)?$taskfeedback['factual_result']:null;
        $actions='';
        $feedbackhtml='';
        $resultfeedback='';
        if ($latesttask) {
            $taskstatus=(string)($latesttask['status'] ?? 'open');
            $statuskey='taskcenter_status_'.$taskstatus;
            $taskurl=new moodle_url('/local/kbacompany/tasks.php',[
                'uuid'=>$uuid,'task'=>(string)$latesttask['uuid'],'view'=>'detail',
            ]);
            $feedbackhtml=html_writer::div(
                html_writer::tag('strong',get_string('battleplan_weekcoord_pattern_linked_task','local_kbacompany')).' '.
                html_writer::span(s((string)$latesttask['title']),'bms24-weekcoord-pattern-task-title').' · '.
                html_writer::span(get_string($statuskey,'local_kbacompany'),'badge badge-secondary'),
                'bms24-weekcoord-pattern-feedback');
            $actions.=html_writer::link($taskurl,
                get_string('battleplan_weekcoord_pattern_open_task','local_kbacompany'),
                ['class'=>'btn btn-sm btn-primary']);
        }
        if ($factualresult) {
            $completedat=(int)($factualresult['completed_at'] ?? 0);
            $resulthead=html_writer::tag('strong',get_string('battleplan_weekcoord_pattern_result_title','local_kbacompany'));
            if ($completedat>0) {
                $resulthead.=html_writer::tag('small',get_string('battleplan_weekcoord_pattern_result_completed','local_kbacompany',
                    userdate($completedat,'%d.%m.%Y')),['class'=>'text-muted d-block']);
            }
            $resultbody='';
            if ((string)($factualresult['state'] ?? '') === 'ready') {
                $before=(array)($factualresult['before'] ?? []);
                $after=(array)($factualresult['after'] ?? []);
                $beforelabel=battle_plan_service::period_label((string)($before['week_start'] ?? ''),(string)($before['week_end'] ?? ''));
                $afterlabel=battle_plan_service::period_label((string)($after['week_start'] ?? ''),(string)($after['week_end'] ?? ''));
                $resulttable=new html_table();
                $resulttable->attributes['class']='table table-sm mb-2 bms24-weekcoord-result-table';
                $resulttable->head=[
                    get_string('battleplan_weekcoord_pattern_result_metric','local_kbacompany'),
                    get_string('battleplan_weekcoord_pattern_result_before','local_kbacompany',$beforelabel),
                    get_string('battleplan_weekcoord_pattern_result_after','local_kbacompany',$afterlabel),
                    get_string('battleplan_weekcoord_pattern_result_delta','local_kbacompany'),
                ];
                $metriclabels=[
                    'productivity'=>'bpu_cycle_productivity',
                    'effectiveness'=>'bpu_cycle_effectiveness',
                    'coverage'=>'bpu_cycle_data_coverage',
                ];
                foreach ($metriclabels as $metrickey=>$metriclang) {
                    $metric=(array)(($factualresult['metrics'] ?? [])[$metrickey] ?? []);
                    $beforevalue=$metric['before'] ?? null;
                    $aftervalue=$metric['after'] ?? null;
                    $delta=$metric['delta'] ?? null;
                    $deltatext='—';
                    if ($delta!==null) {
                        $delta=(int)$delta;
                        $deltatext=($delta>0?'+':'').$delta.' '.get_string('battleplan_weekcoord_pattern_result_pp','local_kbacompany');
                    }
                    $resulttable->data[]=[
                        get_string($metriclang,'local_kbacompany'),
                        $beforevalue===null?'—':((int)$beforevalue).'%',
                        $aftervalue===null?'—':((int)$aftervalue).'%',
                        $deltatext,
                    ];
                }
                $resultbody=html_writer::table($resulttable);
            } else if ((string)($factualresult['state'] ?? '') === 'insufficient_after') {
                $resultbody=html_writer::tag('p',get_string('battleplan_weekcoord_pattern_result_insufficient_after','local_kbacompany'),
                    ['class'=>'mb-2']);
            } else {
                $resultbody=html_writer::tag('p',get_string('battleplan_weekcoord_pattern_result_insufficient_before','local_kbacompany'),
                    ['class'=>'mb-2']);
            }
            $resultfeedback=html_writer::div(
                $resulthead.$resultbody.
                html_writer::tag('small',get_string('battleplan_weekcoord_pattern_result_causality','local_kbacompany'),
                    ['class'=>'text-muted d-block']),
                'bms24-weekcoord-pattern-result mt-2');
        }
        if (!empty($patternaction['available']) && !empty($patternaction['url'])) {
            $actions.=html_writer::link((string)$patternaction['url'],
                $latesttask ? get_string('battleplan_weekcoord_pattern_create_next_task','local_kbacompany')
                    : get_string('battleplan_weekcoord_pattern_create_task','local_kbacompany'),
                ['class'=>'btn btn-sm '.($latesttask?'btn-outline-secondary':'btn-primary')]);
        } else if (!$latesttask && (int)($patternmodel['scope_userid'] ?? 0) === 0) {
            $actions.=html_writer::tag('small',
                get_string('battleplan_weekcoord_pattern_select_person','local_kbacompany'),
                ['class'=>'text-muted']);
        }
        $patternhtml.=html_writer::div(
            html_writer::div(
                html_writer::span(get_string('battleplan_weekcoord_pattern_kind_'.(string)$pattern['kind'],'local_kbacompany'),
                    'bms24-weekcoord-kind is-'.s((string)$pattern['kind'])).
                html_writer::tag('strong',get_string($titlekey,'local_kbacompany')),
                'bms24-weekcoord-agenda-main'
            ).html_writer::tag('small',s($facts),['class'=>'text-muted']).$feedbackhtml.$resultfeedback.
            html_writer::div($actions,'bms24-weekcoord-actions'),
            'bms24-weekcoord-agenda-row'
        );
    }
    echo html_writer::div($patternhtml,'bms24-weekcoord-agenda-list');
}
echo html_writer::tag('p',get_string('battleplan_weekcoord_patterns_note','local_kbacompany'),
    ['class'=>'text-muted mt-3 mb-0']);
echo html_writer::end_div();

// Executive summary intentionally uses only the same authorized unit projections below.
// A unit filter or employee history filter does not silently become a company-wide view.
if ($data['orgnode_filter'] === '' && (int)($history['userid'] ?? 0) === 0 &&
        empty($data['invalid_history_user_filter']) && !empty($data['by_unit'])) {
    $ceo=(array)($data['ceo_overview'] ?? []);
    echo html_writer::start_div('bms24-card bms24-weekcoord-ceo mb-4');
    echo html_writer::start_div('bms24-cardheader');
    echo html_writer::tag('h2',get_string('battleplan_weekcoord_ceo_title','local_kbacompany'));
    echo html_writer::tag('p',get_string('battleplan_weekcoord_ceo_help','local_kbacompany'));
    echo html_writer::end_div();
    $ceocards='';
    foreach ([
        'units'=>'battleplan_weekcoord_ceo_units',
        'units_with_patterns'=>'battleplan_weekcoord_ceo_units_patterns',
        'recurring_signals'=>'battleplan_weekcoord_ceo_patterns',
        'management_open'=>'battleplan_weekcoord_ceo_open',
        'needs_decision'=>'battleplan_overview_needs_decision',
        'overdue_tasks'=>'battleplan_overview_overdue_tasks',
    ] as $key=>$label) {
        $ceocards.=html_writer::div(
            html_writer::tag('small',get_string($label,'local_kbacompany')).
            html_writer::tag('strong',(string)(int)($ceo[$key] ?? 0)),
            'bms24-weekcoord-performance-card');
    }
    echo html_writer::div($ceocards,'bms24-weekcoord-performance-grid mb-3');
    $ceorows='';
    foreach ((array)$data['by_unit'] as $unit) {
        $key=(string)($unit['uuid'] ?? '');
        $count=count((array)($data['unit_patterns'][$key] ?? []));
        $url=new moodle_url('/local/kbacompany/battle_plan_week_coordination.php',[
            'uuid'=>$uuid, 'week'=>$data['week_start'], 'orgnode'=>$key,
            'historyweeks'=>(int)($history['weeks'] ?? 8),
        ]);
        $summary=get_string('battleplan_weekcoord_ceo_unit_row','local_kbacompany',(object)[
            'patterns'=>$count,
            'open'=>(int)($unit['management_tasks']['open'] ?? 0),
            'overdue'=>(int)($unit['overdue_tasks'] ?? 0),
        ]);
        $ceorows.=html_writer::div(
            html_writer::link($url,s((string)$unit['name'])).' · '.s($summary),
            'bms24-weekcoord-agenda-row');
    }
    echo html_writer::div($ceorows,'bms24-weekcoord-agenda-list');
    echo html_writer::tag('p',get_string('battleplan_weekcoord_ceo_note','local_kbacompany'),
        ['class'=>'text-muted mt-3 mb-0']);
    echo html_writer::end_div();
}

// Unit view reuses weighted performance and management-task feedback from the overview read-model.
if ($data['by_unit']) {
    echo html_writer::start_div('bms24-card bms24-weekcoord-units mb-4');
    echo html_writer::start_div('bms24-cardheader');
    echo html_writer::tag('h2',get_string('battleplan_weekcoord_units','local_kbacompany'));
    echo html_writer::tag('p',get_string('battleplan_weekcoord_units_help','local_kbacompany'));
    echo html_writer::end_div();
    $units='';
    foreach ($data['by_unit'] as $unit) {
        $p=(array)$unit['performance'];
        $management=(array)$unit['management_tasks'];
        $params=['uuid'=>$uuid,'week'=>$data['week_start'],'orgnode'=>(string)$unit['uuid']];
        $uniturl=new moodle_url('/local/kbacompany/battle_plan_week_coordination.php',$params);
        $metrics=get_string('bpu_cycle_productivity','local_kbacompany').': '.(($p['productivity']??null)===null?'—':(int)$p['productivity'].'%').' · '.
            get_string('bpu_cycle_effectiveness','local_kbacompany').': '.(($p['effectiveness']??null)===null?'—':(int)$p['effectiveness'].'%').' · '.
            get_string('bpu_cycle_data_coverage','local_kbacompany').': '.(($p['coverage']??null)===null?'—':(int)$p['coverage'].'%');
        $signals=[];
        if ((int)$unit['needs_decision']>0) { $signals[]=get_string('battleplan_overview_needs_decision','local_kbacompany').': '.(int)$unit['needs_decision']; }
        if ((int)$unit['overdue_tasks']>0) { $signals[]=get_string('battleplan_overview_overdue_tasks','local_kbacompany').': '.(int)$unit['overdue_tasks']; }
        if ((int)$unit['critical_exceptions']>0) { $signals[]=get_string('battleplan_overview_critical_exceptions','local_kbacompany').': '.(int)$unit['critical_exceptions']; }
        $unitkey=(string)$unit['uuid'];
        $repeats=(array)($data['unit_patterns'][$unitkey] ?? []);
        $repeatlabels=[];
        foreach ($repeats as $repeat) {
            $langkey='battleplan_weekcoord_pattern_'.(string)$repeat['key'];
            $repeatlabels[]=get_string($langkey,'local_kbacompany').' ('.
                implode(', ',array_map('strval',(array)($repeat['matched_weeks'] ?? []))).')';
        }
        $repeattext=$repeatlabels ? implode(' · ',$repeatlabels) :
            get_string('battleplan_weekcoord_unit_patterns_none','local_kbacompany');
        $taskfeedback=get_string('battleplan_weekcoord_management_tasks','local_kbacompany',(object)[
            'open'=>(int)($management['open']??0),'done'=>(int)($management['done']??0)]);
        $units.=html_writer::link($uniturl,
            html_writer::div(
                html_writer::tag('strong',s((string)$unit['name'])).
                html_writer::tag('small',get_string('battleplan_overview_active_plans','local_kbacompany').': '.(int)$unit['plans'],['class'=>'text-muted']),
                'bms24-weekcoord-unit-head').
            html_writer::tag('small',s($metrics),['class'=>'bms24-weekcoord-unit-metrics']).
            html_writer::tag('small',s($signals?implode(' · ',$signals):get_string('battleplan_weekcoord_no_signals','local_kbacompany')),['class'=>'bms24-weekcoord-unit-signals']).
            html_writer::tag('small',s($taskfeedback),['class'=>'text-muted']).
            html_writer::tag('small',s($repeattext),['class'=>'bms24-weekcoord-unit-signals']),
            ['class'=>'bms24-weekcoord-unit']);
    }
    echo html_writer::div($units,'bms24-weekcoord-unit-grid');
    echo html_writer::end_div();
}

$GLOBALS['bms24_coord_render_step'] = 'footer';
echo html_writer::div(get_string('battleplan_weekcoord_method_note','local_kbacompany'),'alert alert-info');
\local_kbacompany\local\bms_page_shell_service::finish($bmsshell);
