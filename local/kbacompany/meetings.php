<?php
require_once(__DIR__ . '/../../config.php');

use local_kbacompany\local\meeting_service;
use local_kbacompany\local\company_service;
use local_kbacompany\local\task_service;
use local_kbacompany\local\administrative_cycle_service;
use local_kbacompany\local\management_cycle_meeting_preparation_service;
use local_kbacompany\local\recurrence_service;
use local_kbacompany\local\meeting_resource_service;

require_login();
$context = context_system::instance();
require_capability('local/kbacompany:viewowncompany', $context);
$uuid = required_param('uuid', PARAM_RAW_TRIMMED);
$selected = optional_param('meeting', '', PARAM_RAW_TRIMMED);
$sourcethread = optional_param('source_thread', '', PARAM_RAW_TRIMMED);
$cycleproject = optional_param('cycle_project', '', PARAM_RAW_TRIMMED);
$cyclerevision = optional_param('cycle_revision', 0, PARAM_INT);
$prefillstart = optional_param('prefillstart', '', PARAM_RAW_TRIMMED);
$prefillend = optional_param('prefillend', '', PARAM_RAW_TRIMMED);
$prefillparticipants = optional_param_array('prefillparticipants', [], PARAM_INT);
$prefillparticipantscsv = optional_param('prefillparticipantscsv', '', PARAM_RAW_TRIMMED);
if (!$prefillparticipants && $prefillparticipantscsv !== '') {
    $prefillparticipants = array_values(array_unique(array_filter(array_map('intval', explode(',', $prefillparticipantscsv)), static function(int $id): bool { return $id > 0; })));
}
company_service::company_context($uuid, (int)$USER->id);
$cyclesource = null;
if ($cycleproject !== '') {
    $cyclesource = administrative_cycle_service::project_source($uuid, (int)$USER->id, $cycleproject, $cyclerevision);
}

$parse_datetime = static function(string $value): int {
    $value = trim($value);
    if ($value === '') { return 0; }
    $tz = \core_date::get_user_timezone_object();
    $dt = \DateTimeImmutable::createFromFormat('Y-m-d\\TH:i', $value, $tz);
    if (!$dt || $dt->format('Y-m-d\\TH:i') !== $value) {
        throw new moodle_exception('meeting_time', 'local_kbacompany');
    }
    return $dt->getTimestamp();
};

$action = optional_param('action', '', PARAM_ALPHA);
$error = '';
if ($action !== '') {
    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') { throw new moodle_exception('communication_post', 'local_kbacompany'); }
    require_sesskey();
    try {
        if ($action === 'create') {
            $startsat = $parse_datetime(optional_param('startsat', '', PARAM_RAW_TRIMMED));
            $endsat = $parse_datetime(optional_param('endsat', '', PARAM_RAW_TRIMMED));
            $sourcekind = optional_param('sourcekind', 'manual', PARAM_ALPHANUMEXT);
            $sourcethreaduuid = optional_param('sourcethreaduuid', '', PARAM_RAW_TRIMMED);
            $repeatmode = optional_param('repeatmode', 'none', PARAM_ALPHA);
            $repeatcount = optional_param('repeatcount', 1, PARAM_INT);
            $repeatweekdays = optional_param_array('repeatweekdays', [], PARAM_INT);
            $duration = ($startsat > 0 && $endsat > $startsat) ? ($endsat - $startsat) : 0;
            $starts = ($sourcekind === 'manual' && $sourcethreaduuid === '' && $startsat > 0)
                ? recurrence_service::starts($startsat, $repeatmode, $repeatcount, $repeatweekdays)
                : [$startsat];
            $selected = '';
            foreach ($starts as $occurrencestart) {
                $meetinguuid = meeting_service::create($uuid, (int)$USER->id, [
                    'title' => required_param('title', PARAM_TEXT),
                    'purpose' => required_param('purpose', PARAM_RAW),
                    'location' => optional_param('location', '', PARAM_TEXT),
                    'resourceuuid' => optional_param('resourceuuid', '', PARAM_RAW_TRIMMED),
                    'onlineurl' => optional_param('onlineurl', '', PARAM_URL),
                    'startsat' => $occurrencestart,
                    'endsat' => $duration > 0 ? ($occurrencestart + $duration) : 0,
                    'chairid' => optional_param('chairid', (int)$USER->id, PARAM_INT),
                    'secretaryid' => optional_param('secretaryid', 0, PARAM_INT),
                    'participants' => optional_param_array('participants', [], PARAM_INT),
                    'sourcekind' => $sourcekind,
                    'sourceuuid' => optional_param('sourceuuid', '', PARAM_RAW_TRIMMED),
                    'sourcerevision' => optional_param('sourcerevision', 0, PARAM_INT),
                    'sourcethreaduuid' => $sourcethreaduuid,
                ]);
                if ($selected === '') { $selected = $meetinguuid; }
            }
        } else if ($action === 'save') {
            $selected = required_param('meeting', PARAM_RAW_TRIMMED);
            meeting_service::save($uuid, (int)$USER->id, $selected, required_param('revision', PARAM_INT), [
                'title' => required_param('title', PARAM_TEXT),
                'purpose' => required_param('purpose', PARAM_RAW),
                'location' => optional_param('location', '', PARAM_TEXT),
                'resourceuuid' => optional_param('resourceuuid', '', PARAM_RAW_TRIMMED),
                'onlineurl' => optional_param('onlineurl', '', PARAM_URL),
                'startsat' => $parse_datetime(optional_param('startsat', '', PARAM_RAW_TRIMMED)),
                'endsat' => $parse_datetime(optional_param('endsat', '', PARAM_RAW_TRIMMED)),
                'chairid' => required_param('chairid', PARAM_INT),
                'secretaryid' => optional_param('secretaryid', 0, PARAM_INT),
            ]);
        } else if ($action === 'addparticipant') {
            $selected = required_param('meeting', PARAM_RAW_TRIMMED);
            meeting_service::add_participant($uuid, (int)$USER->id, $selected, required_param('userid', PARAM_INT));
        } else if ($action === 'attendance') {
            $selected = required_param('meeting', PARAM_RAW_TRIMMED);
            meeting_service::set_attendance($uuid, (int)$USER->id, $selected,
                required_param('userid', PARAM_INT), required_param('status', PARAM_ALPHA));
        } else if ($action === 'transition') {
            $selected = required_param('meeting', PARAM_RAW_TRIMMED);
            meeting_service::transition($uuid, (int)$USER->id, $selected, required_param('revision', PARAM_INT),
                required_param('transition', PARAM_ALPHA), [
                    'minutes' => optional_param('minutes', '', PARAM_RAW),
                    'result' => optional_param('result', '', PARAM_RAW),
                ]);
        } else if ($action === 'addagenda') {
            $selected = required_param('meeting', PARAM_RAW_TRIMMED);
            meeting_service::add_agenda($uuid, (int)$USER->id, $selected, [
                'title' => required_param('title', PARAM_TEXT),
                'expectedproduct' => optional_param('expectedproduct', '', PARAM_RAW),
                'ownerid' => required_param('ownerid', PARAM_INT),
            ]);
        } else if ($action === 'agendastatus') {
            $selected = required_param('meeting', PARAM_RAW_TRIMMED);
            meeting_service::set_agenda_status($uuid, (int)$USER->id, $selected,
                required_param('agendauuid', PARAM_RAW_TRIMMED), required_param('status', PARAM_ALPHA),
                optional_param('notes', '', PARAM_RAW));
        } else if ($action === 'proposedecision') {
            $selected = required_param('meeting', PARAM_RAW_TRIMMED);
            meeting_service::propose_decision($uuid, (int)$USER->id, $selected, [
                'agendauuid' => optional_param('agendauuid', '', PARAM_RAW_TRIMMED),
                'title' => required_param('title', PARAM_TEXT),
                'statement' => required_param('statement', PARAM_RAW),
                'rationale' => optional_param('rationale', '', PARAM_RAW),
                'expectedresult' => optional_param('expectedresult', '', PARAM_RAW),
                'ownerid' => required_param('ownerid', PARAM_INT),
            ]);
        } else if ($action === 'decision') {
            $selected = required_param('meeting', PARAM_RAW_TRIMMED);
            meeting_service::decide($uuid, (int)$USER->id, required_param('decisionuuid', PARAM_RAW_TRIMMED),
                required_param('decisionaction', PARAM_ALPHA), required_param('decisionrevision', PARAM_INT));
        } else if ($action === 'decisiontask') {
            $selected = required_param('meeting', PARAM_RAW_TRIMMED);
            $taskuuid = meeting_service::create_task_from_decision($uuid, (int)$USER->id,
                required_param('decisionuuid', PARAM_RAW_TRIMMED), [
                    'title' => optional_param('title', '', PARAM_TEXT),
                    'description' => optional_param('description', '', PARAM_RAW),
                    'product' => optional_param('product', '', PARAM_RAW),
                    'assigneeid' => required_param('assigneeid', PARAM_INT),
                    'reviewerid' => optional_param('reviewerid', 0, PARAM_INT),
                    'priority' => optional_param('priority', 'normal', PARAM_ALPHA),
                    'stream' => optional_param('stream', 'change', PARAM_ALPHA),
                    'startdate' => optional_param('startdate', '', PARAM_RAW_TRIMMED),
                    'duedate' => optional_param('duedate', '', PARAM_RAW_TRIMMED),
                    'planminutes' => optional_param('planminutes', 0, PARAM_INT),
                    'parentuuid' => '',
                ]);
            redirect(new moodle_url('/local/kbacompany/tasks.php', ['uuid' => $uuid, 'task' => $taskuuid]),
                get_string('meeting_taskcreated', 'local_kbacompany'));
        }
        redirect(new moodle_url('/local/kbacompany/meetings.php', ['uuid' => $uuid, 'meeting' => $selected]),
            get_string('meeting_saved', 'local_kbacompany'));
    } catch (moodle_exception $e) {
        if ($e->module !== 'local_kbacompany') { throw $e; }
        $error = $e->getMessage();
    }
}

$data = meeting_service::context($uuid, (int)$USER->id, $selected);
$meetingresources = meeting_resource_service::list($uuid, (int)$USER->id, false);
$resourceoptions = ['' => '—'];
foreach ($meetingresources as $resource) { $resourceoptions[$resource['uuid']] = $resource['name'] . ($resource['capacity'] > 0 ? ' · ' . $resource['capacity'] : ''); }
$meetingprep = $selected !== '' ? management_cycle_meeting_preparation_service::context($uuid, (int)$USER->id, $selected) : null;
$PAGE->set_context($context);
$PAGE->set_url(new moodle_url('/local/kbacompany/meetings.php', ['uuid' => $uuid, 'meeting' => $selected]));
$PAGE->set_title(get_string('meeting_title', 'local_kbacompany'));
$PAGE->set_heading(get_string('pluginname', 'local_kbacompany'));

$hidden = static function(string $action, string $meeting = '') use ($uuid): string {
    $html = html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'uuid', 'value' => $uuid]);
    $html .= html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'action', 'value' => $action]);
    $html .= html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'sesskey', 'value' => sesskey()]);
    if ($meeting !== '') { $html .= html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'meeting', 'value' => $meeting]); }
    return $html;
};
$input = static function(string $name, string $value = '', string $type = 'text', bool $required = false): string {
    $attrs = ['type' => $type, 'name' => $name, 'value' => $value, 'class' => 'form-control'];
    if ($required) { $attrs['required'] = 'required'; }
    return html_writer::empty_tag('input', $attrs);
};
$textarea = static function(string $name, string $value = '', bool $required = false, int $rows = 3): string {
    $attrs = ['name' => $name, 'rows' => $rows, 'class' => 'form-control'];
    if ($required) { $attrs['required'] = 'required'; }
    return html_writer::tag('textarea', s($value), $attrs);
};
$employees = $data['employees'];
$people = $employees;
$reviewers = [0 => get_string('taskcenter_noreviewer', 'local_kbacompany')] + $employees;
$streamoptions = [];
foreach (task_service::STREAMS as $stream) { $streamoptions[$stream] = get_string('taskcenter_stream_' . $stream, 'local_kbacompany'); }
$priorityoptions = [];
foreach (task_service::PRIORITIES as $priority) { $priorityoptions[$priority] = get_string('taskcenter_priority_' . $priority, 'local_kbacompany'); }

$datetime_value = static function(int $timestamp): string {
    if ($timestamp <= 0) { return ''; }
    return userdate($timestamp, '%Y-%m-%dT%H:%M');
};

$bmsshell = \local_kbacompany\local\bms_page_shell_service::begin($uuid, (int)$USER->id, 'communications',
    get_string('meeting_title', 'local_kbacompany'));
echo $OUTPUT->heading(get_string('meeting_title', 'local_kbacompany'));
echo html_writer::tag('p', get_string('meeting_intro', 'local_kbacompany'), ['class' => 'lead']);
echo html_writer::tag('p', get_string('meeting_rule', 'local_kbacompany'), ['class' => 'alert alert-info']);
if ($error !== '') { echo $OUTPUT->notification(s($error), 'error'); }

echo html_writer::link(new moodle_url('/local/kbacompany/communication_center.php', ['uuid' => $uuid, 'tab' => 'meetings']),
    get_string('communicationcenter_title', 'local_kbacompany'), ['class' => 'btn btn-outline-primary mr-2']);
echo html_writer::link(new moodle_url('/local/kbacompany/communication_center.php', ['uuid' => $uuid]),
    get_string('communication_title', 'local_kbacompany'), ['class' => 'btn btn-outline-secondary mr-2']);
echo html_writer::link(new moodle_url('/local/kbacompany/tasks.php', ['uuid' => $uuid]),
    get_string('taskcenter_title', 'local_kbacompany'), ['class' => 'btn btn-outline-secondary']);
echo ' ' . html_writer::link(new moodle_url('/local/kbacompany/meeting_resources.php', ['uuid' => $uuid]),
    get_string('meeting_resources', 'local_kbacompany'), ['class' => 'btn btn-outline-secondary btn-sm']);

$summary = get_string('meeting_total', 'local_kbacompany') . ': ' . $data['counts']['total'] . ' · ' .
    get_string('meeting_status_scheduled', 'local_kbacompany') . ': ' . $data['counts']['scheduled'] . ' · ' .
    get_string('meeting_open_decisions', 'local_kbacompany') . ': ' . $data['counts']['open_decisions'];
echo html_writer::tag('p', s($summary), ['class' => 'mt-3']);

echo $OUTPUT->heading(get_string('meeting_list', 'local_kbacompany'), 3);
if (!$data['meetings']) {
    echo $OUTPUT->notification(get_string('meeting_empty', 'local_kbacompany'), 'info');
} else {
    $table = new html_table();
    $table->head = [get_string('meeting_name', 'local_kbacompany'), get_string('meeting_status', 'local_kbacompany'),
        get_string('meeting_time_label', 'local_kbacompany'), get_string('meeting_chair', 'local_kbacompany'),
        get_string('meeting_decisions', 'local_kbacompany')];
    foreach ($data['meetings'] as $meeting) {
        $title = html_writer::link(new moodle_url('/local/kbacompany/meetings.php', [
            'uuid' => $uuid, 'meeting' => $meeting['uuid'],
        ]), s($meeting['title']));
        if (($meeting['source_kind'] ?? 'manual') === 'admin_project' && $meeting['source_label'] !== '') {
            $title .= html_writer::div(s($meeting['source_label']), 'small text-muted');
        }
        $time = $meeting['starts_at'] ? userdate($meeting['starts_at']) : '—';
        $table->data[] = [$title, get_string('meeting_status_' . $meeting['status'], 'local_kbacompany'),
            $time, s($meeting['chair_name']), (string)count($meeting['decisions'])];
    }
    echo html_writer::table($table);
}

$meeting = $data['selected'];
if ($meeting) {
    echo $OUTPUT->heading(s($meeting['title']), 3);
    echo html_writer::tag('p', format_text($meeting['purpose'], FORMAT_PLAIN));
    $meta = get_string('meeting_status', 'local_kbacompany') . ': ' .
        get_string('meeting_status_' . $meeting['status'], 'local_kbacompany') . ' · ' .
        get_string('meeting_chair', 'local_kbacompany') . ': ' . $meeting['chair_name'];
    if ($meeting['starts_at']) { $meta .= ' · ' . userdate($meeting['starts_at']); }
    if ($meeting['location'] !== '') { $meta .= ' · ' . $meeting['location']; }
    if ($meeting['resource_uuid'] !== '') {
        $meetingresource = meeting_resource_service::get($uuid, (int)$USER->id, $meeting['resource_uuid']);
        if ($meetingresource) { $meta .= ' · ' . $meetingresource['name']; }
    }
    echo html_writer::tag('p', s($meta), ['class' => 'text-muted']);
    if ($meeting['online_url'] !== '') {
        echo html_writer::tag('p', html_writer::link($meeting['online_url'], get_string('meeting_join_online','local_kbacompany'), ['target'=>'_blank','rel'=>'noopener noreferrer']));
    }

    $wf = (array)($meeting['workflow'] ?? []);
    $workflowsteps = '';
    foreach ([
        ['meeting_workflow_agenda', (int)($wf['agenda_total'] ?? 0), (int)($wf['agenda_open'] ?? 0), '#meeting-agenda'],
        ['meeting_workflow_decisions', (int)($wf['decisions_total'] ?? 0), (int)($wf['decisions_proposed'] ?? 0), '#meeting-decisions'],
        ['meeting_workflow_tasks', (int)($wf['tasks_total'] ?? 0), (int)($wf['accepted_without_task'] ?? 0), '#meeting-decisions'],
    ] as $step) {
        $body = html_writer::span(get_string($step[0], 'local_kbacompany'), 'bms-meeting-workflow-label') .
            html_writer::tag('strong', (string)$step[1], ['class'=>'bms-meeting-workflow-value']);
        if ((int)$step[2] > 0) {
            $body .= html_writer::span((string)$step[2], 'badge badge-warning');
        }
        $workflowsteps .= html_writer::tag('a', $body, ['href'=>$step[3], 'class'=>'bms-meeting-workflow-step']);
    }
    $workflowstate = !empty($wf['ready_to_close'])
        ? get_string('meeting_workflow_ready', 'local_kbacompany')
        : get_string('meeting_workflow_attention', 'local_kbacompany');
    echo html_writer::div(
        html_writer::tag('strong', get_string('meeting_workflow_title', 'local_kbacompany')) .
        html_writer::div(get_string('meeting_workflow_intro', 'local_kbacompany'), 'small text-muted mb-2') .
        html_writer::div($workflowsteps, 'bms-meeting-workflow-steps') .
        html_writer::div(s($workflowstate), 'small mt-2'),
        'bms-meeting-workflow mb-3'
    );

    if (($meeting['source_kind'] ?? 'manual') === 'admin_project' && $meeting['source_uuid'] !== '') {
        $sourceurl = new moodle_url('/local/kbacompany/administrative_scale.php', [
            'uuid' => $uuid, 'viewkind' => 'project', 'detailuuid' => $meeting['source_uuid'], 'recordtab' => 'execution',
        ]);
        $sourcetext = get_string('meeting_cycle_source', 'local_kbacompany', $meeting['source_label']);
        if (!empty($meeting['source_stale'])) { $sourcetext .= ' · ' . get_string('meeting_cycle_source_stale', 'local_kbacompany'); }
        echo html_writer::div(html_writer::link($sourceurl, s($sourcetext)), 'mb-2');
    }
    if ($meeting['source_thread_uuid'] !== '') {
        echo html_writer::link(new moodle_url('/local/kbacompany/communication_center.php', [
            'uuid' => $uuid, 'thread' => $meeting['source_thread_uuid'],
        ]), get_string('meeting_open_source_discussion', 'local_kbacompany'), ['class' => 'btn btn-sm btn-outline-secondary mr-2']);
    }
    $meetingdiscussionparams = ['uuid'=>$uuid, 'tab'=>'discussions'];
    if ($meeting['thread_uuid'] !== '') { $meetingdiscussionparams['thread'] = $meeting['thread_uuid']; }
    else { $meetingdiscussionparams += ['objectkind'=>'meeting', 'objectuuid'=>$meeting['uuid']]; }
    echo html_writer::link(new moodle_url('/local/kbacompany/communication_center.php', $meetingdiscussionparams),
        get_string('meeting_open_discussion', 'local_kbacompany'), ['class' => 'btn btn-sm btn-outline-primary']);

    if ($meetingprep) {
        echo html_writer::start_div('border rounded p-3 my-3 bg-light');
        echo $OUTPUT->heading(get_string('mcycle_prep_title','local_kbacompany'), 4);
        echo html_writer::tag('p', get_string('mcycle_prep_intro','local_kbacompany'), ['class'=>'text-muted']);
        echo html_writer::div(
            html_writer::link($meetingprep['cycle_url'], s($meetingprep['cycle_title'])) . ' · ' .
            s($meetingprep['checkpoint_title']) . ' · ' . s($meetingprep['week']),
            'mb-3'
        );
        echo html_writer::div(html_writer::link(new moodle_url('/local/kbacompany/weekly_protocol.php', [
            'uuid'=>$uuid, 'meeting'=>$meeting['uuid']
        ]), get_string('weeklyproto_open','local_kbacompany'), ['class'=>'btn btn-sm btn-outline-primary']), 'mb-3');

        echo $OUTPUT->heading(get_string('mcycle_prep_previous_plan','local_kbacompany'), 5);
        if (!$meetingprep['previous_plans']) {
            echo html_writer::div(get_string('mcycle_prep_no_previous_plan','local_kbacompany'), 'text-muted mb-3');
        } else {
            foreach ($meetingprep['previous_plans'] as $pp) {
                echo html_writer::div(html_writer::link($pp['url'], get_string('mcycle_prep_open_plan','local_kbacompany', $pp['periodstart'])) .
                    ' · ' . get_string('mcycle_prep_plan_summary','local_kbacompany',(object)[
                        'done'=>(int)$pp['summary']['done'], 'total'=>(int)$pp['summary']['tasks'],
                        'plan'=>(int)$pp['summary']['plan_minutes'], 'fact'=>(int)$pp['summary']['fact_minutes'],
                    ]), 'mb-2');
            }
        }

        echo $OUTPUT->heading(get_string('mcycle_prep_indicators','local_kbacompany'), 5);
        if (!$meetingprep['indicators']) {
            echo html_writer::div(get_string('mcycle_prep_no_indicators','local_kbacompany'), 'text-muted mb-3');
        } else {
            $pt = new html_table();
            $pt->attributes['class'] = 'table table-sm table-hover';
            $pt->head = [get_string('battleplan_metric','local_kbacompany'),
                get_string('battleplan_previous_fact','local_kbacompany'),
                get_string('battleplan_fact','local_kbacompany'),
                get_string('battleplan_quota','local_kbacompany'),
                get_string('battleplan_trend','local_kbacompany')];
            foreach ($meetingprep['indicators'] as $m) {
                $metric = html_writer::link($m['indicator_url'], s($m['code'].' — '.$m['name']));
                $prev = $m['previous_fact'] === null ? '—' : format_float((float)$m['previous_fact'],2).' '.s($m['unit']);
                $fact = $m['current_fact'] === null ? '—' : format_float((float)$m['current_fact'],2).' '.s($m['unit']);
                $quota = $m['norm_label'] === '' ? '—' : s($m['norm_label']).' '.s($m['unit']);
                $trend = get_string('indicator_trend_'.($m['trend']['movement'] ?? 'insufficient'),'local_kbacompany');
                $pt->data[] = [$metric,$prev,$fact,$quota,$trend];
            }
            echo html_writer::table($pt);
        }

        echo $OUTPUT->heading(get_string('mcycle_prep_unfinished','local_kbacompany'), 5);
        if (!$meetingprep['unfinished']) {
            echo html_writer::div(get_string('mcycle_prep_no_unfinished','local_kbacompany'), 'text-muted mb-3');
        } else {
            $ut = new html_table();
            $ut->attributes['class'] = 'table table-sm table-hover';
            $ut->head = [get_string('taskcenter_task','local_kbacompany'), get_string('taskcenter_product','local_kbacompany'),
                get_string('taskcenter_status','local_kbacompany'), get_string('mcycle_prep_plan_time','local_kbacompany')];
            foreach ($meetingprep['unfinished'] as $u) {
                $label = $u['url'] !== '' ? html_writer::link($u['url'], s($u['title'])) : s($u['title']);
                $ut->data[] = [$label, s($u['product']), get_string('taskcenter_status_'.$u['status'],'local_kbacompany'),
                    format_float(((int)$u['plan_minutes']) / 60, 1)];
            }
            echo html_writer::table($ut);
        }

        echo $OUTPUT->heading(get_string('mcycle_prep_current_plan','local_kbacompany'), 5);
        if (!$meetingprep['current_plans']) {
            echo html_writer::div(get_string('mcycle_prep_no_current_plan','local_kbacompany'), 'text-muted mb-3');
        } else {
            foreach ($meetingprep['current_plans'] as $cpplan) {
                echo html_writer::div(html_writer::link($cpplan['url'], get_string('mcycle_prep_open_plan','local_kbacompany', $cpplan['periodstart'])) .
                    ' · ' . get_string('mcycle_prep_plan_summary','local_kbacompany',(object)[
                        'done'=>(int)$cpplan['summary']['done'], 'total'=>(int)$cpplan['summary']['tasks'],
                        'plan'=>(int)$cpplan['summary']['plan_minutes'], 'fact'=>(int)$cpplan['summary']['fact_minutes'],
                    ]), 'mb-2');
            }
        }

        echo $OUTPUT->heading(get_string('mcycle_prep_agenda','local_kbacompany'), 5);
        $alist = '';
        foreach ($meetingprep['agenda'] as $a) { $alist .= html_writer::tag('li', s($a['label'])); }
        echo html_writer::tag('ol', $alist, ['class'=>'mb-2']);
        echo html_writer::div(get_string('mcycle_prep_agenda_rule','local_kbacompany'), 'small text-muted');
        echo html_writer::end_div();
    }

    if ($meeting['can_govern'] && !in_array($meeting['status'], ['closed', 'cancelled'], true)) {
        $participantoptions = [];
        foreach ($meeting['participants'] as $part) { $participantoptions[(int)$part['user_id']] = $part['name']; }
        echo html_writer::start_tag('details', ['class' => 'border rounded p-2 my-3']);
        echo html_writer::tag('summary', get_string('meeting_edit', 'local_kbacompany'));
        echo html_writer::start_tag('form', ['method' => 'post', 'class' => 'p-2']);
        echo $hidden('save', $meeting['uuid']);
        echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'revision', 'value' => $meeting['revision']]);
        echo html_writer::label(get_string('meeting_name', 'local_kbacompany'), 'meeting_edit_title');
        echo $input('title', $meeting['title'], 'text', true);
        echo html_writer::label(get_string('meeting_purpose', 'local_kbacompany'), 'meeting_edit_purpose');
        echo $textarea('purpose', $meeting['purpose'], true, 4);
        echo html_writer::label(get_string('meeting_starts_at', 'local_kbacompany'), 'meeting_edit_start');
        echo $input('startsat', $datetime_value($meeting['starts_at']), 'datetime-local');
        echo html_writer::label(get_string('meeting_ends_at', 'local_kbacompany'), 'meeting_edit_end');
        echo $input('endsat', $datetime_value($meeting['ends_at']), 'datetime-local');
        echo html_writer::label(get_string('meeting_location', 'local_kbacompany'), 'meeting_edit_location');
        echo $input('location', $meeting['location']);
        echo html_writer::label(get_string('meeting_resource', 'local_kbacompany'), 'meeting_edit_resource');
        echo html_writer::select($resourceoptions, 'resourceuuid', $meeting['resource_uuid'], false, ['id'=>'meeting_edit_resource','class'=>'form-control']);
        echo html_writer::label(get_string('meeting_online_url', 'local_kbacompany'), 'meeting_edit_onlineurl');
        echo $input('onlineurl', $meeting['online_url'], 'url');
        echo html_writer::label(get_string('meeting_chair', 'local_kbacompany'), 'meeting_edit_chair');
        echo html_writer::select($participantoptions, 'chairid', $meeting['chair_id'], false, ['class' => 'form-control']);
        echo html_writer::label(get_string('meeting_secretary', 'local_kbacompany'), 'meeting_edit_secretary');
        echo html_writer::select([0 => '—'] + $participantoptions, 'secretaryid', $meeting['secretary_id'], false, ['class' => 'form-control']);
        echo html_writer::tag('button', get_string('savechanges'), ['type' => 'submit', 'class' => 'btn btn-primary mt-2']);
        echo html_writer::end_tag('form');
        echo html_writer::end_tag('details');
    }

    echo $OUTPUT->heading(get_string('meeting_participants', 'local_kbacompany'), 4);
    $pt = new html_table();
    $pt->head = [get_string('meeting_participant', 'local_kbacompany'), get_string('meeting_role', 'local_kbacompany'),
        get_string('meeting_attendance', 'local_kbacompany'), get_string('actions')];
    foreach ($meeting['participants'] as $part) {
        $actionhtml = '';
        if ((int)$part['user_id'] === (int)$USER->id) {
            $actionhtml .= html_writer::start_tag('form', ['method' => 'post', 'class' => 'form-inline']);
            $actionhtml .= $hidden('attendance', $meeting['uuid']);
            $actionhtml .= html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'userid', 'value' => $part['user_id']]);
            $opts = ['accepted' => get_string('meeting_attendance_accepted', 'local_kbacompany'),
                'declined' => get_string('meeting_attendance_declined', 'local_kbacompany')];
            $actionhtml .= html_writer::select($opts, 'status', $part['attendance_status'], false, ['class' => 'form-control form-control-sm mr-1']);
            $actionhtml .= html_writer::tag('button', get_string('savechanges'), ['type' => 'submit', 'class' => 'btn btn-sm btn-outline-secondary']);
            $actionhtml .= html_writer::end_tag('form');
        }
        $pt->data[] = [s($part['name']), get_string('meeting_role_' . $part['role'], 'local_kbacompany'),
            get_string('meeting_attendance_' . $part['attendance_status'], 'local_kbacompany'), $actionhtml];
    }
    echo html_writer::table($pt);

    if ($meeting['can_organize'] && !in_array($meeting['status'], ['closed', 'cancelled'], true)) {
        $existing = [];
        foreach ($meeting['participants'] as $p) { $existing[(int)$p['user_id']] = true; }
        $available = array_diff_key($people, $existing);
        if ($available) {
            echo html_writer::start_tag('form', ['method' => 'post', 'class' => 'form-inline mb-3']);
            echo $hidden('addparticipant', $meeting['uuid']);
            echo html_writer::select($available, 'userid', '', false, ['class' => 'form-control mr-2']);
            echo html_writer::tag('button', get_string('meeting_addparticipant', 'local_kbacompany'),
                ['type' => 'submit', 'class' => 'btn btn-outline-primary']);
            echo html_writer::end_tag('form');
        }
    }

    echo html_writer::tag('div', '', ['id'=>'meeting-agenda']);
    echo $OUTPUT->heading(get_string('meeting_agenda', 'local_kbacompany'), 4);
    if (!$meeting['agenda']) { echo html_writer::tag('p', get_string('meeting_agenda_empty', 'local_kbacompany'), ['class' => 'text-muted']); }
    foreach ($meeting['agenda'] as $agenda) {
        echo html_writer::start_tag('div', ['class' => 'border rounded p-3 mb-2']);
        echo html_writer::tag('strong', s($agenda['title']));
        echo html_writer::tag('div', get_string('meeting_agenda_owner', 'local_kbacompany') . ': ' . s($agenda['owner_name']) .
            ' · ' . get_string('meeting_agenda_status_' . $agenda['status'], 'local_kbacompany'), ['class' => 'small text-muted']);
        if ($agenda['expected_product'] !== '') {
            echo html_writer::tag('div', '<strong>' . s(get_string('meeting_expected_product', 'local_kbacompany')) . ':</strong> ' .
                format_text($agenda['expected_product'], FORMAT_PLAIN));
        }
        if ($agenda['notes'] !== '') { echo format_text($agenda['notes'], FORMAT_PLAIN); }
        if (!in_array($meeting['status'], ['closed', 'cancelled'], true)) {
            echo html_writer::start_tag('form', ['method' => 'post', 'class' => 'form-inline mt-2']);
            echo $hidden('agendastatus', $meeting['uuid']);
            echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'agendauuid', 'value' => $agenda['uuid']]);
            $opts = [];
            foreach (meeting_service::AGENDA_STATUSES as $st) { $opts[$st] = get_string('meeting_agenda_status_' . $st, 'local_kbacompany'); }
            echo html_writer::select($opts, 'status', $agenda['status'], false, ['class' => 'form-control form-control-sm mr-2']);
            echo $input('notes', $agenda['notes']);
            echo html_writer::tag('button', get_string('savechanges'), ['type' => 'submit', 'class' => 'btn btn-sm btn-outline-secondary ml-2']);
            echo html_writer::end_tag('form');
        }
        echo html_writer::end_tag('div');
    }
    if ($meeting['can_govern'] && !in_array($meeting['status'], ['closed', 'cancelled'], true)) {
        echo html_writer::start_tag('form', ['method' => 'post', 'class' => 'border rounded p-3 mb-3']);
        echo $hidden('addagenda', $meeting['uuid']);
        echo html_writer::tag('strong', get_string('meeting_addagenda', 'local_kbacompany'));
        echo $input('title', '', 'text', true);
        echo html_writer::label(get_string('meeting_expected_product', 'local_kbacompany'), 'agenda_product');
        echo $textarea('expectedproduct');
        echo html_writer::label(get_string('meeting_agenda_owner', 'local_kbacompany'), 'agenda_owner');
        echo html_writer::select($employees, 'ownerid', (int)$USER->id, false, ['class' => 'form-control']);
        echo html_writer::tag('button', get_string('meeting_addagenda', 'local_kbacompany'), ['type' => 'submit', 'class' => 'btn btn-primary mt-2']);
        echo html_writer::end_tag('form');
    }

    echo html_writer::tag('div', '', ['id'=>'meeting-decisions']);
    echo $OUTPUT->heading(get_string('meeting_decisions', 'local_kbacompany'), 4);
    foreach ($meeting['decisions'] as $decision) {
        echo html_writer::start_tag('div', ['class' => 'border rounded p-3 mb-3']);
        echo html_writer::tag('strong', s($decision['title']));
        echo html_writer::tag('div', get_string('meeting_decision_status_' . $decision['status'], 'local_kbacompany') .
            ' · ' . get_string('meeting_decision_owner', 'local_kbacompany') . ': ' . s($decision['owner_name']), ['class' => 'small text-muted']);
        echo format_text($decision['statement'], FORMAT_PLAIN);
        if ($decision['expected_result'] !== '') {
            echo html_writer::tag('div', '<strong>' . s(get_string('meeting_expected_result', 'local_kbacompany')) . ':</strong> ' .
                format_text($decision['expected_result'], FORMAT_PLAIN));
        }
        if ($decision['status'] === 'proposed' && $meeting['can_govern']) {
            foreach (['accept', 'reject'] as $da) {
                echo html_writer::start_tag('form', ['method' => 'post', 'class' => 'd-inline mr-1']);
                echo $hidden('decision', $meeting['uuid']);
                echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'decisionuuid', 'value' => $decision['uuid']]);
                echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'decisionrevision', 'value' => $decision['revision']]);
                echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'decisionaction', 'value' => $da]);
                $class = $da === 'accept' ? 'btn btn-sm btn-success' : 'btn btn-sm btn-outline-danger';
                echo html_writer::tag('button', get_string('meeting_decision_' . $da, 'local_kbacompany'), ['type' => 'submit', 'class' => $class]);
                echo html_writer::end_tag('form');
            }
        }
        if ($decision['tasks']) {
            echo html_writer::tag('div', get_string('meeting_tasks', 'local_kbacompany'), ['class' => 'font-weight-bold mt-2']);
            foreach ($decision['tasks'] as $task) {
                echo html_writer::tag('div', html_writer::link(new moodle_url('/local/kbacompany/tasks.php', [
                    'uuid' => $uuid, 'task' => $task['uuid'],
                ]), s($task['title'])) . ' — ' . s($task['assignee_name']) . ' / ' .
                    get_string('taskcenter_status_' . $task['status'], 'local_kbacompany'), ['class' => 'small']);
            }
        }
        if ($decision['status'] === 'accepted' && !in_array($meeting['status'], ['cancelled'], true)) {
            echo html_writer::start_tag('form', ['method' => 'post', 'class' => 'border-top mt-3 pt-3']);
            echo $hidden('decisiontask', $meeting['uuid']);
            echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'decisionuuid', 'value' => $decision['uuid']]);
            echo html_writer::tag('strong', get_string('meeting_create_task', 'local_kbacompany'));
            echo html_writer::label(get_string('taskcenter_assignee', 'local_kbacompany'), 'decision_task_assignee');
            echo html_writer::select($employees, 'assigneeid', $decision['owner_id'], false, ['class' => 'form-control']);
            echo html_writer::label(get_string('taskcenter_reviewer', 'local_kbacompany'), 'decision_task_reviewer');
            echo html_writer::select($reviewers, 'reviewerid', 0, false, ['class' => 'form-control']);
            echo html_writer::label(get_string('taskcenter_duedate', 'local_kbacompany'), 'decision_task_due');
            echo $input('duedate', '', 'date');
            echo html_writer::label(get_string('taskcenter_stream', 'local_kbacompany'), 'decision_task_stream');
            echo html_writer::select($streamoptions, 'stream', 'change', false, ['class' => 'form-control']);
            echo html_writer::label(get_string('taskcenter_priority', 'local_kbacompany'), 'decision_task_priority');
            echo html_writer::select($priorityoptions, 'priority', 'normal', false, ['class' => 'form-control']);
            echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'title', 'value' => '']);
            echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'product', 'value' => '']);
            echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'description', 'value' => '']);
            echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'startdate', 'value' => '']);
            echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'planminutes', 'value' => '0']);
            echo html_writer::tag('button', get_string('meeting_create_task', 'local_kbacompany'), ['type' => 'submit', 'class' => 'btn btn-outline-primary mt-2']);
            echo html_writer::end_tag('form');
        }
        echo html_writer::end_tag('div');
    }

    if (!in_array($meeting['status'], ['closed', 'cancelled'], true)) {
        echo html_writer::start_tag('form', ['method' => 'post', 'class' => 'border rounded p-3 mb-3']);
        echo $hidden('proposedecision', $meeting['uuid']);
        echo html_writer::tag('strong', get_string('meeting_propose_decision', 'local_kbacompany'));
        $agendaopts = ['' => '—'];
        foreach ($meeting['agenda'] as $agenda) { $agendaopts[$agenda['uuid']] = $agenda['title']; }
        echo html_writer::label(get_string('meeting_agenda', 'local_kbacompany'), 'decision_agenda');
        echo html_writer::select($agendaopts, 'agendauuid', '', false, ['class' => 'form-control']);
        echo html_writer::label(get_string('meeting_decision_title', 'local_kbacompany'), 'decision_title');
        echo $input('title', '', 'text', true);
        echo html_writer::label(get_string('meeting_decision_statement', 'local_kbacompany'), 'decision_statement');
        echo $textarea('statement', '', true, 4);
        echo html_writer::label(get_string('meeting_decision_rationale', 'local_kbacompany'), 'decision_rationale');
        echo $textarea('rationale');
        echo html_writer::label(get_string('meeting_expected_result', 'local_kbacompany'), 'decision_expected');
        echo $textarea('expectedresult');
        echo html_writer::label(get_string('meeting_decision_owner', 'local_kbacompany'), 'decision_owner');
        echo html_writer::select($employees, 'ownerid', (int)$USER->id, false, ['class' => 'form-control']);
        echo html_writer::tag('button', get_string('meeting_propose_decision', 'local_kbacompany'), ['type' => 'submit', 'class' => 'btn btn-primary mt-2']);
        echo html_writer::end_tag('form');
    }

    if ($meeting['can_govern']) {
        if (in_array($meeting['status'], ['draft', 'scheduled'], true)) {
            echo html_writer::start_tag('form', ['method' => 'post', 'class' => 'd-inline mr-2']);
            echo $hidden('transition', $meeting['uuid']);
            echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'revision', 'value' => $meeting['revision']]);
            echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'transition', 'value' => 'start']);
            echo html_writer::tag('button', get_string('meeting_start', 'local_kbacompany'), ['type' => 'submit', 'class' => 'btn btn-success']);
            echo html_writer::end_tag('form');
        }
        if (!in_array($meeting['status'], ['closed', 'cancelled'], true)) {
            echo html_writer::start_tag('form', ['method' => 'post', 'class' => 'd-inline']);
            echo $hidden('transition', $meeting['uuid']);
            echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'revision', 'value' => $meeting['revision']]);
            echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'transition', 'value' => 'cancel']);
            echo html_writer::tag('button', get_string('meeting_cancel', 'local_kbacompany'), ['type' => 'submit', 'class' => 'btn btn-outline-danger']);
            echo html_writer::end_tag('form');
        }
        if (in_array($meeting['status'], ['scheduled', 'inprogress'], true)) {
            echo html_writer::tag('div', '', ['id'=>'meeting-close']);
            echo html_writer::start_tag('form', ['method' => 'post', 'class' => 'border rounded p-3 mt-3']);
            echo $hidden('transition', $meeting['uuid']);
            echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'revision', 'value' => $meeting['revision']]);
            echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'transition', 'value' => 'close']);
            echo html_writer::tag('strong', get_string('meeting_close', 'local_kbacompany'));
            echo html_writer::label(get_string('meeting_minutes', 'local_kbacompany'), 'meeting_minutes');
            echo $textarea('minutes', '', true, 6);
            echo html_writer::label(get_string('meeting_result', 'local_kbacompany'), 'meeting_result');
            echo $textarea('result', '', false, 3);
            echo html_writer::tag('div', get_string('meeting_close_help', 'local_kbacompany'), ['class' => 'small text-muted']);
            echo html_writer::tag('button', get_string('meeting_close', 'local_kbacompany'), ['type' => 'submit', 'class' => 'btn btn-primary mt-2']);
            echo html_writer::end_tag('form');
        }
    }
    if ($meeting['status'] === 'closed') {
        echo $OUTPUT->heading(get_string('meeting_minutes', 'local_kbacompany'), 4);
        echo format_text($meeting['minutes'], FORMAT_PLAIN);
        if ($meeting['result'] !== '') {
            echo $OUTPUT->heading(get_string('meeting_result', 'local_kbacompany'), 4);
            echo format_text($meeting['result'], FORMAT_PLAIN);
        }
    }
}

echo $OUTPUT->heading(get_string('meeting_create', 'local_kbacompany'), 3);
echo html_writer::start_tag('form', ['method' => 'post', 'class' => 'border rounded p-3']);
echo $hidden('create');
$createtitle = $cyclesource ? \core_text::substr(get_string('meeting_cycle_default_title', 'local_kbacompany', $cyclesource['name']), 0, 255) : '';
$createpurpose = $cyclesource ? get_string('meeting_cycle_default_purpose', 'local_kbacompany', $cyclesource['expected_result']) : '';
echo html_writer::label(get_string('meeting_name', 'local_kbacompany'), 'meeting_create_title');
echo $input('title', $createtitle, 'text', true);
echo html_writer::label(get_string('meeting_purpose', 'local_kbacompany'), 'meeting_create_purpose');
echo $textarea('purpose', $createpurpose, true, 4);
echo html_writer::label(get_string('meeting_starts_at', 'local_kbacompany'), 'meeting_create_start');
echo $input('startsat', $prefillstart, 'datetime-local');
echo html_writer::label(get_string('meeting_ends_at', 'local_kbacompany'), 'meeting_create_end');
echo $input('endsat', $prefillend, 'datetime-local');
echo html_writer::label(get_string('meeting_location', 'local_kbacompany'), 'meeting_create_location');
echo $input('location');
echo html_writer::label(get_string('meeting_resource', 'local_kbacompany'), 'meeting_create_resource');
echo html_writer::select($resourceoptions, 'resourceuuid', '', false, ['id'=>'meeting_create_resource','class'=>'form-control']);
echo html_writer::label(get_string('meeting_online_url', 'local_kbacompany'), 'meeting_create_onlineurl');
echo $input('onlineurl', '', 'url');
echo html_writer::label(get_string('meeting_chair', 'local_kbacompany'), 'meeting_create_chair');
echo html_writer::select($employees, 'chairid', (int)$USER->id, false, ['class' => 'form-control']);
echo html_writer::label(get_string('meeting_secretary', 'local_kbacompany'), 'meeting_create_secretary');
echo html_writer::select([0 => '—'] + $employees, 'secretaryid', 0, false, ['class' => 'form-control']);
echo html_writer::label(get_string('meeting_participants', 'local_kbacompany'), 'meeting_create_participants');

$validprefillparticipants = [];
foreach ($prefillparticipants as $participantid) {
    $participantid = (int)$participantid;
    if (isset($employees[$participantid])) { $validprefillparticipants[] = $participantid; }
}
echo html_writer::select($employees, 'participants[]', $validprefillparticipants, false, ['class' => 'form-control', 'multiple' => 'multiple']);
if (!$cyclesource && $sourcethread === '') {
    echo html_writer::label(get_string('meeting_repeat', 'local_kbacompany'), 'meeting_create_repeat');
    echo html_writer::select([
        'none' => get_string('meeting_repeat_none', 'local_kbacompany'),
        'weekly' => get_string('meeting_repeat_weekly', 'local_kbacompany'),
        'monthly' => get_string('meeting_repeat_monthly', 'local_kbacompany'),
        'weekdays' => get_string('meeting_repeat_weekdays', 'local_kbacompany'),
    ], 'repeatmode', 'none', false, ['class' => 'form-control']);
    echo html_writer::label(get_string('meeting_repeat_count', 'local_kbacompany'), 'meeting_create_repeat_count');
    echo html_writer::empty_tag('input', ['type'=>'number','name'=>'repeatcount','value'=>1,'min'=>1,'max'=>52,'class'=>'form-control']);
    echo html_writer::div(get_string('meeting_repeat_days', 'local_kbacompany'), 'mt-2 font-weight-bold');
    $daylabels = [1=>get_string('bmscalendar_mon','local_kbacompany'),2=>get_string('bmscalendar_tue','local_kbacompany'),3=>get_string('bmscalendar_wed','local_kbacompany'),4=>get_string('bmscalendar_thu','local_kbacompany'),5=>get_string('bmscalendar_fri','local_kbacompany'),6=>get_string('bmscalendar_sat','local_kbacompany'),7=>get_string('bmscalendar_sun','local_kbacompany')];
    $dayhtml = '';
    foreach ($daylabels as $day=>$label) {
        $checkbox = html_writer::empty_tag('input', ['type'=>'checkbox','name'=>'repeatweekdays[]','value'=>$day]);
        $dayhtml .= html_writer::tag('label', $checkbox . ' ' . s($label), ['class'=>'mr-3']);
    }
    echo html_writer::div($dayhtml, 'mb-2');
    echo html_writer::div(get_string('meeting_repeat_help', 'local_kbacompany'), 'small text-muted mb-2');
}
echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'sourcethreaduuid', 'value' => $sourcethread]);
echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'sourcekind',
    'value' => $cyclesource ? 'admin_project' : 'manual']);
echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'sourceuuid',
    'value' => $cyclesource ? $cyclesource['uuid'] : '']);
echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'sourcerevision',
    'value' => $cyclesource ? $cyclesource['revision'] : 0]);
if ($sourcethread !== '') { echo html_writer::tag('div', get_string('meeting_from_discussion', 'local_kbacompany'), ['class' => 'small text-muted']); }
if ($cyclesource) {
    echo html_writer::tag('div', get_string('meeting_from_cycle_project', 'local_kbacompany', $cyclesource['name']),
        ['class' => 'small text-muted']);
}
echo html_writer::tag('button', get_string('meeting_create', 'local_kbacompany'), ['type' => 'submit', 'class' => 'btn btn-primary mt-2']);
echo html_writer::end_tag('form');

\local_kbacompany\local\bms_page_shell_service::finish($bmsshell);
