<?php
// BMS-POS-COVERAGE-01: company-scoped position substitute requests and approval.
require_once(__DIR__ . '/../../config.php');

use local_kbacompany\local\company_context_service;
use local_kbacompany\local\position_coverage_service as coverage;
use local_kbacompany\local\bms_page_shell_service;

require_login();
$systemcontext = context_system::instance();
require_capability('local/kbacompany:viewowncompany', $systemcontext);
$companyuuid = company_context_service::normalise_uuid(required_param('uuid', PARAM_RAW_TRIMMED));
$actor = (int)$USER->id;
company_context_service::context($companyuuid, $actor);
if (!coverage::can_manage($companyuuid, $actor)) {
    throw new required_capability_exception($systemcontext, 'local/kbacompany:manageorganization',
        'nopermissions', '');
}
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
if (!in_array($method, ['GET', 'HEAD', 'POST'], true)) {
    throw new moodle_exception('invalidparameter');
}
$focus = optional_param('coverage', '', PARAM_RAW_TRIMMED);
$view = optional_param('view', $focus === '' ? 'list' : 'detail', PARAM_ALPHA);
if (!in_array($view, ['list', 'create', 'detail'], true)) { throw new moodle_exception('invalidparameter'); }
$status = optional_param('status', 'all', PARAM_ALPHA);
$page = max(0, optional_param('page', 0, PARAM_INT));
$form = ['assignment' => '', 'delegateid' => 0, 'startdate' => gmdate('Y-m-d'),
    'enddate' => gmdate('Y-m-d'), 'reason' => ''];
$error = '';
$notice = '';
$url = static function(array $params = []) use ($companyuuid): moodle_url {
    return new moodle_url('/local/kbacompany/position_coverage.php',
        array_merge(['uuid' => $companyuuid], $params));
};
if ($method === 'POST') {
    require_sesskey();
    $action = required_param('action', PARAM_ALPHA);
    if (($view === 'create' && $action !== 'request') ||
            ($view === 'detail' && !in_array($action, ['approve', 'reject', 'cancel'], true)) ||
            !in_array($view, ['create', 'detail'], true)) {
        throw new moodle_exception('coverage_invalid_action', 'local_kbacompany');
    }
    try {
        if ($view === 'create') {
            $form['assignment'] = optional_param('assignment', '', PARAM_RAW_TRIMMED);
            $form['delegateid'] = optional_param('delegateid', 0, PARAM_INT);
            $form['startdate'] = optional_param('startdate', '', PARAM_RAW_TRIMMED);
            $form['enddate'] = optional_param('enddate', '', PARAM_RAW_TRIMMED);
            $form['reason'] = optional_param('reason', '', PARAM_TEXT);
            $parts = explode(':', $form['assignment']);
            if (count($parts) !== 2 || !preg_match('/^[a-f0-9-]{36}$/D', $parts[0]) || !ctype_digit($parts[1])) {
                throw new moodle_exception('coverage_invalid_position', 'local_kbacompany');
            }
            $created = coverage::request($companyuuid, $actor, [
                'positionuuid' => $parts[0], 'primaryid' => (int)$parts[1],
                'delegateid' => $form['delegateid'], 'startdate' => $form['startdate'],
                'enddate' => $form['enddate'], 'reason' => $form['reason'],
            ]);
            redirect($url(['coverage' => $created]), get_string('coverage_requested', 'local_kbacompany'),
                null, \core\output\notification::NOTIFY_SUCCESS);
        }
        if ($view === 'detail') {
            if (optional_param('confirmed', 0, PARAM_INT) !== 1) {
                throw new moodle_exception('coverage_confirm_required', 'local_kbacompany');
            }
            coverage::transition($companyuuid, $actor, $focus,
                required_param('revision', PARAM_INT), $action,
                optional_param('comment', '', PARAM_TEXT));
            redirect($url(['coverage' => $focus]), get_string('coverage_saved', 'local_kbacompany'),
                null, \core\output\notification::NOTIFY_SUCCESS);
        }
    } catch (moodle_exception $e) {
        if ($e->module !== 'local_kbacompany' || !get_string_manager()->string_exists($e->errorcode, 'local_kbacompany')) {
            throw $e;
        }
        $error = get_string($e->errorcode, 'local_kbacompany');
    }
}
$options = coverage::options($companyuuid, $actor);
$people = $options['people'];
$positions = $DB->get_records('local_kbacompany_position', ['companyuuid'=>$companyuuid]);
$name = static function(int $uid) use ($DB, $people): string {
    if (isset($people[$uid])) { return $people[$uid]; }
    $user = $DB->get_record('user', ['id'=>$uid], 'id,firstname,lastname');
    return $user ? fullname($user) : '—';
};
$day = static function(int $ts): string {
    return $ts > 0 ? gmdate('d.m.Y', $ts) : '—';
};
$state = static function(string $value): string {
    return get_string('coverage_status_'.$value, 'local_kbacompany');
};
$PAGE->set_context($systemcontext);
$PAGE->set_url($url(['view'=>$view] + ($focus !== '' ? ['coverage'=>$focus] : [])));
$PAGE->set_title(get_string('coverage_title', 'local_kbacompany'));
$PAGE->set_heading(get_string('pluginname', 'local_kbacompany'));
$shell = bms_page_shell_service::begin($companyuuid, $actor, 'coverage',
    get_string('coverage_title', 'local_kbacompany'));
echo html_writer::tag('h2', get_string('coverage_title', 'local_kbacompany'));
echo html_writer::tag('p', get_string('coverage_intro', 'local_kbacompany'), ['class'=>'text-muted']);
echo html_writer::div(get_string('coverage_task_boundary', 'local_kbacompany'), 'alert alert-info');
if ($error !== '') { echo html_writer::div(s($error), 'alert alert-danger', ['role'=>'alert']); }
echo html_writer::div(html_writer::link($url(), get_string('coverage_registry', 'local_kbacompany'),
    ['class'=>'btn btn-outline-secondary mr-2']).' '.
    html_writer::link($url(['view'=>'create']), get_string('coverage_create', 'local_kbacompany'),
    ['class'=>'btn btn-success']), 'mb-3');

if ($view === 'create') {
    echo html_writer::start_tag('form', ['method'=>'post','action'=>$url(['view'=>'create']),
        'class'=>'card p-4 bms-position-coverage-form']);
    echo html_writer::tag('h3', get_string('coverage_create', 'local_kbacompany'));
    echo html_writer::empty_tag('input', ['type'=>'hidden','name'=>'sesskey','value'=>sesskey()]);
    echo html_writer::empty_tag('input', ['type'=>'hidden','name'=>'action','value'=>'request']);
    $pick = [''=>get_string('coverage_choose', 'local_kbacompany')] + $options['primary'];
    echo html_writer::label(get_string('coverage_position', 'local_kbacompany'), 'coverage_assignment');
    echo html_writer::select($pick, 'assignment', $form['assignment'], false,
        ['id'=>'coverage_assignment','class'=>'form-control mb-3','required'=>'required']);
    $peopleopts = [''=>get_string('coverage_choose', 'local_kbacompany')] + $people;
    echo html_writer::label(get_string('coverage_delegate', 'local_kbacompany'), 'coverage_delegateid');
    echo html_writer::select($peopleopts, 'delegateid', $form['delegateid'] ?: '', false,
        ['id'=>'coverage_delegateid','class'=>'form-control mb-3','required'=>'required']);
    foreach (['startdate','enddate'] as $field) {
        echo html_writer::label(get_string('coverage_'.$field, 'local_kbacompany'), 'coverage_'.$field);
        echo html_writer::empty_tag('input', ['type'=>'date','id'=>'coverage_'.$field,'name'=>$field,
            'value'=>$form[$field], 'required'=>'required','class'=>'form-control mb-3']);
    }
    echo html_writer::label(get_string('coverage_reason', 'local_kbacompany'), 'coverage_reason');
    echo html_writer::tag('textarea', s($form['reason']), ['id'=>'coverage_reason','name'=>'reason',
        'rows'=>3,'maxlength'=>1000,'required'=>'required','class'=>'form-control mb-3']);
    echo html_writer::div(get_string('coverage_scope_note', 'local_kbacompany'), 'text-muted mb-3');
    echo html_writer::div(html_writer::tag('button', get_string('coverage_submit', 'local_kbacompany'),
        ['type'=>'submit','class'=>'btn btn-success']), 'text-right');
    echo html_writer::end_tag('form');
} else if ($view === 'detail') {
    $data = coverage::detail($companyuuid, $actor, $focus);
    $r = $data['record'];
    $positionname = isset($positions[$r->positionuuid]) ? $positions[$r->positionuuid]->name : '';
    if ($positionname === '') {
        foreach ($positions as $p) { if ($p->uuid === $r->positionuuid) { $positionname=$p->name; break; } }
    }
    $body = '';
    foreach ([
        'coverage_position' => s($positionname), 'coverage_primary'=>s($name((int)$r->primaryid)),
        'coverage_delegate'=>s($name((int)$r->delegateid)),
        'coverage_startdate'=>$day((int)$r->startdate), 'coverage_enddate'=>$day((int)$r->enddate),
        'coverage_status'=>s($state(coverage::effective_state($r))), 'coverage_reason'=>s((string)$r->reason),
    ] as $key=>$value) {
        $body .= html_writer::tag('dt', get_string($key,'local_kbacompany'), ['class'=>'col-md-4 text-muted']);
        $body .= html_writer::tag('dd', $value, ['class'=>'col-md-8']);
    }
    echo html_writer::div(html_writer::tag('dl', $body, ['class'=>'row mb-0']), 'card p-4 mb-3');
    // Only explicitly position-bound canonical tasks are eligible. No transfer is
    // performed here; the effective executor is derived at read/action time.
    $duties = \local_kbacompany\local\task_service::position_duty_preview($companyuuid, $actor,
        (string)$r->positionuuid, (int)$r->primaryid, 20);
    echo html_writer::tag('h3', get_string('coverage_task_preview','local_kbacompany'));
    echo html_writer::div(get_string('coverage_task_preview_hint','local_kbacompany'),'text-muted mb-2');
    if (!$duties) {
        echo html_writer::div(get_string('coverage_task_preview_empty','local_kbacompany'),
            'alert alert-secondary');
    } else {
        $taskrows = '';
        foreach ($duties as $duty) {
            $link = new moodle_url('/local/kbacompany/tasks.php',
                ['uuid'=>$companyuuid,'task'=>$duty['uuid']]);
            $taskrows .= html_writer::tag('tr',
                html_writer::tag('td', html_writer::link($link,s((string)$duty['title']))).
                html_writer::tag('td', s((string)$duty['assignee_name'])).
                html_writer::tag('td', s((string)$duty['effective_assignee_name'])).
                html_writer::tag('td', s((string)$duty['due_date'])));
        }
        echo html_writer::div(html_writer::tag('table',
            html_writer::tag('thead',html_writer::tag('tr',
                html_writer::tag('th',get_string('taskcenter_task','local_kbacompany')).
                html_writer::tag('th',get_string('coverage_task_original','local_kbacompany')).
                html_writer::tag('th',get_string('coverage_task_current_executor','local_kbacompany')).
                html_writer::tag('th',get_string('taskcenter_duedate','local_kbacompany')))).
            html_writer::tag('tbody',$taskrows),['class'=>'table table-striped']),
            ['class'=>'table-responsive mb-3']);
    }
    // A management-only, READ-ONLY position workload snapshot. No employee private
    // calendar events, permissions or plan/fact write actions are inherited.
    if ((string)$r->status === 'approved') {
        $requestedday = optional_param('week', '', PARAM_RAW_TRIMMED);
        $snapshot = coverage::operational_snapshot($companyuuid, $actor, $focus, $requestedday);
        echo html_writer::start_div('card p-3 mb-3');
        echo html_writer::tag('h3', get_string('coverage_dashboard_title', 'local_kbacompany'));
        echo html_writer::div(get_string('coverage_dashboard_boundary', 'local_kbacompany'),
            'text-muted mb-3');
        echo html_writer::div(get_string('coverage_dashboard_week', 'local_kbacompany') . ': ' .
            s($day(coverage::day($snapshot['from']))) . ' — ' .
            s($day(coverage::day($snapshot['until']))), 'font-weight-bold mb-2');
        $nav = '';
        if ($snapshot['previous_day'] !== '') {
            $nav .= html_writer::link($url(['coverage'=>$focus,'week'=>$snapshot['previous_day']]),
                get_string('coverage_previous', 'local_kbacompany'), ['class'=>'btn btn-outline-secondary btn-sm mr-2']);
        }
        if ($snapshot['next_day'] !== '') {
            $nav .= html_writer::link($url(['coverage'=>$focus,'week'=>$snapshot['next_day']]),
                get_string('coverage_next', 'local_kbacompany'), ['class'=>'btn btn-outline-secondary btn-sm']);
        }
        echo html_writer::div($nav, 'mb-3');
        echo html_writer::tag('h4', get_string('coverage_dashboard_work', 'local_kbacompany'));
        if (!$snapshot['tasks_allowed']) {
            echo html_writer::div(get_string('coverage_dashboard_denied', 'local_kbacompany'), 'text-muted mb-3');
        } else if (!$snapshot['task_rows']) {
            echo html_writer::div(get_string('coverage_dashboard_no_tasks', 'local_kbacompany'), 'text-muted mb-3');
        } else {
            $workrows = '';
            foreach ($snapshot['task_rows'] as $duty) {
                $link = new moodle_url('/local/kbacompany/tasks.php',
                    ['uuid'=>$companyuuid, 'task'=>$duty['uuid']]);
                $workrows .= html_writer::tag('tr',
                    html_writer::tag('td', s($duty['day'])).
                    html_writer::tag('td', html_writer::link($link, s($duty['title']))).
                    html_writer::tag('td', s(get_string('taskcenter_status_'.$duty['status'], 'local_kbacompany'))));
            }
            echo html_writer::div(html_writer::tag('table',
                html_writer::tag('thead', html_writer::tag('tr',
                    html_writer::tag('th', get_string('coverage_dashboard_date','local_kbacompany')).
                    html_writer::tag('th', get_string('taskcenter_task','local_kbacompany')).
                    html_writer::tag('th', get_string('coverage_status','local_kbacompany')))).
                html_writer::tag('tbody', $workrows), ['class'=>'table table-striped']), 'table-responsive mb-3');
        }
        echo html_writer::tag('h4', get_string('coverage_dashboard_metrics', 'local_kbacompany'));
        if (!$snapshot['metric_allowed']) {
            echo html_writer::div(get_string('coverage_dashboard_denied', 'local_kbacompany'), 'text-muted mb-3');
        } else if (!$snapshot['metric_rows']) {
            echo html_writer::div(get_string('coverage_dashboard_no_metrics', 'local_kbacompany'), 'text-muted mb-3');
        } else {
            $metricrows = '';
            foreach ($snapshot['metric_rows'] as $metric) {
                $fmt = static function($value) use ($metric): string {
                    return $value === null ? '—' : s(format_float((float)$value, 2)) . ' ' . s($metric['unit']);
                };
                $statuskey = 'coverage_dashboard_status_'.$metric['status'];
                if (!get_string_manager()->string_exists($statuskey, 'local_kbacompany')) {
                    $statuskey = 'coverage_dashboard_status_nodata';
                }
                $metricrows .= html_writer::tag('tr',
                    html_writer::tag('td', s($metric['code'] . ' — ' . $metric['name'])).
                    html_writer::tag('td', $fmt($metric['plan'])).
                    html_writer::tag('td', $fmt($metric['fact'])).
                    html_writer::tag('td', s(get_string($statuskey, 'local_kbacompany'))));
            }
            echo html_writer::div(html_writer::tag('table',
                html_writer::tag('thead', html_writer::tag('tr',
                    html_writer::tag('th', get_string('coverage_dashboard_metric', 'local_kbacompany')).
                    html_writer::tag('th', get_string('coverage_dashboard_plan', 'local_kbacompany')).
                    html_writer::tag('th', get_string('coverage_dashboard_fact', 'local_kbacompany')).
                    html_writer::tag('th', get_string('coverage_status', 'local_kbacompany')))).
                html_writer::tag('tbody', $metricrows), ['class'=>'table table-striped']), 'table-responsive mb-3');
        }
        echo html_writer::tag('h4', get_string('coverage_dashboard_calendar', 'local_kbacompany'));
        if (!$snapshot['calendar_allowed']) {
            echo html_writer::div(get_string('coverage_dashboard_calendar_denied', 'local_kbacompany'),
                'text-muted');
        } else {
            foreach ($snapshot['calendar_allowed'] as $ownerid) {
                $calendar = new moodle_url('/local/kbacompany/employee_work_calendar.php',
                    ['uuid'=>$companyuuid,'userid'=>$ownerid]);
                echo html_writer::div(html_writer::link($calendar,
                    get_string('coverage_dashboard_calendar_open', 'local_kbacompany') . ': ' .
                    s($name($ownerid))), 'mb-2');
            }
        }
        echo html_writer::end_div();
    }
    $actions = (string)$r->status === 'requested' ? ['approve','reject','cancel'] :
        ((string)$r->status === 'approved' ? ['cancel'] : []);
    foreach ($actions as $action) {
        $extra = html_writer::empty_tag('input', ['type'=>'hidden','name'=>'sesskey','value'=>sesskey()]);
        $extra .= html_writer::empty_tag('input', ['type'=>'hidden','name'=>'action','value'=>$action]);
        $extra .= html_writer::empty_tag('input', ['type'=>'hidden','name'=>'revision','value'=>(int)$r->revision]);
        $extra .= html_writer::tag('label', html_writer::empty_tag('input', ['type'=>'checkbox',
            'name'=>'confirmed','value'=>1,'required'=>'required']) . ' '.
            get_string('coverage_confirm_'.$action, 'local_kbacompany'), ['class'=>'d-block']);
        if ($action !== 'approve') {
            $extra .= html_writer::tag('textarea', '', ['name'=>'comment','rows'=>2,
                'required'=>'required','minlength'=>5,'class'=>'form-control mb-2',
                'placeholder'=>get_string('coverage_comment', 'local_kbacompany')]);
        }
        $extra .= html_writer::tag('button', get_string('coverage_action_'.$action, 'local_kbacompany'),
            ['type'=>'submit','class'=>$action==='approve'?'btn btn-success':'btn btn-outline-secondary']);
        echo html_writer::tag('form', $extra, ['method'=>'post','action'=>$url(['coverage'=>$focus, 'view'=>'detail']),
            'class'=>'card p-3 mb-2']);
    }
    echo html_writer::tag('h3', get_string('coverage_audit', 'local_kbacompany'));
    $rows = '';
    foreach ($data['audit'] as $a) {
        $rows .= html_writer::tag('tr',
            html_writer::tag('td', userdate($a->timecreated, '%d.%m.%Y %H:%M')).
            html_writer::tag('td', s($name((int)$a->actorid))).
            html_writer::tag('td', s($state((string)$a->event))).
            html_writer::tag('td', s((string)$a->notes)));
    }
    echo html_writer::tag('div', html_writer::tag('table',
        html_writer::tag('thead', html_writer::tag('tr',
            html_writer::tag('th', get_string('coverage_time', 'local_kbacompany')).
            html_writer::tag('th', get_string('coverage_actor','local_kbacompany')).
            html_writer::tag('th', get_string('coverage_status','local_kbacompany')).
            html_writer::tag('th', get_string('coverage_comment','local_kbacompany')))).
            html_writer::tag('tbody', $rows), ['class'=>'table table-striped']),
        ['class'=>'table-responsive']);
} else {
    $result = coverage::registry($companyuuid, $actor, $status, $page);
    echo html_writer::start_tag('form', ['method'=>'get','action'=>$url(),'class'=>'mb-3']);
    echo html_writer::empty_tag('input', ['type'=>'hidden','name'=>'uuid','value'=>$companyuuid]);
    $statuses=['all'=>get_string('coverage_all','local_kbacompany')];
    foreach (coverage::STATUSES as $skey) { $statuses[$skey]=$state($skey); }
    echo html_writer::label(get_string('coverage_filter','local_kbacompany'), 'coverage_statusfilter');
    echo html_writer::select($statuses,'status',$result['status'],false,
        ['id'=>'coverage_statusfilter','class'=>'form-control d-inline-block ml-2','style'=>'max-width:240px']);
    echo ' '.html_writer::tag('button',get_string('coverage_apply','local_kbacompany'),
        ['type'=>'submit','class'=>'btn btn-outline-secondary']);
    echo html_writer::end_tag('form');
    $rows='';
    foreach ($result['rows'] as $r) {
        $pname='—'; foreach ($positions as $p) { if ($p->uuid===$r->positionuuid) {$pname=$p->name;break;} }
        $rows.=html_writer::tag('tr',
            html_writer::tag('td',html_writer::link($url(['coverage'=>$r->uuid]),s($pname))).
            html_writer::tag('td', s($name((int)$r->primaryid))).
            html_writer::tag('td', s($name((int)$r->delegateid))).
            html_writer::tag('td',$day((int)$r->startdate).' — '.$day((int)$r->enddate)).
            html_writer::tag('td',s($state(coverage::effective_state($r)))));
    }
    if ($rows==='') { echo html_writer::div(get_string('coverage_empty','local_kbacompany'),'alert alert-secondary'); }
    else {
        $head='';
        foreach (['coverage_position','coverage_primary','coverage_delegate','coverage_period','coverage_status'] as $col) {
            $head.=html_writer::tag('th',get_string($col,'local_kbacompany'));
        }
        echo html_writer::div(html_writer::tag('table',
            html_writer::tag('thead',html_writer::tag('tr',$head)).html_writer::tag('tbody',$rows),
            ['class'=>'table table-striped']), 'table-responsive');
    }
    if ($page>0) {echo html_writer::link($url(['status'=>$result['status'],'page'=>$page-1]),
        get_string('coverage_previous','local_kbacompany'),['class'=>'btn btn-outline-secondary mr-2']);}
    if (($page+1)*20<$result['total']) {echo html_writer::link($url(['status'=>$result['status'],'page'=>$page+1]),
        get_string('coverage_next','local_kbacompany'),['class'=>'btn btn-outline-secondary']);}
}
bms_page_shell_service::finish($shell);
