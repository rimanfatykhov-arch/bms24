<?php
require_once(__DIR__ . '/../../config.php');
use local_kbacompany\local\battle_plan_protocol_service;
use local_kbacompany\local\bms_workspace_ux_service;
require_login();
$uuid = required_param('uuid', PARAM_ALPHANUMEXT);
$status = optional_param('status', '', PARAM_ALPHA);
$owner = max(0, optional_param('owner', 0, PARAM_INT));
$from = optional_param('from', '', PARAM_TEXT);
$to = optional_param('to', '', PARAM_TEXT);
$overdue = optional_param('overdue', 0, PARAM_BOOL);
$page = max(0, optional_param('page', 0, PARAM_INT));
// Audit trail filters are independent from decision registry filters.
$auditpage = max(0, optional_param('auditpage', 0, PARAM_INT));
// Read-only revision history for a company-scoped decision selected from audit.
$audithistory = max(0, optional_param('audithistory', 0, PARAM_INT));
$auditactor = max(0, optional_param('auditactor', 0, PARAM_INT));
$auditoperation = optional_param('auditoperation', '', PARAM_ALPHA);
$auditresult = optional_param('auditresult', '', PARAM_ALPHA);
$auditfrom = optional_param('auditfrom', '', PARAM_TEXT);
$auditto = optional_param('auditto', '', PARAM_TEXT);
if (!in_array($auditoperation, ['', 'status', 'owner', 'duedate'], true) ||
        !in_array($auditresult, ['', 'allupdated', 'partial', 'allskipped'], true)) {
    throw new invalid_parameter_exception('Invalid audit filter');
}
foreach ([$auditfrom, $auditto] as $auditdate) {
    if ($auditdate !== '' && (!preg_match('/^\d{4}-\d{2}-\d{2}$/D', $auditdate) ||
            !($dateobj = DateTimeImmutable::createFromFormat('!Y-m-d', $auditdate)) ||
            $dateobj->format('Y-m-d') !== $auditdate)) {
        throw new invalid_parameter_exception('Invalid audit date');
    }
}

// Unified bulk action: preview every operation, then confirm with fresh authorization.
$bulkresult = optional_param('bulkresult', '', PARAM_ALPHANUMEXT);
$bulkphase = optional_param('bulkphase', '', PARAM_ALPHA);
$bulkoperation = optional_param('bulkoperation', '', PARAM_ALPHA);
$bulkpreview = null;
if (in_array($bulkphase, ['preview', 'confirm'], true)) {
    require_sesskey();
    if (!in_array($bulkoperation, ['status', 'owner', 'duedate'], true)) {
        throw new invalid_parameter_exception('Invalid bulk operation');
    }
    $selected = optional_param_array('selected', [], PARAM_INT);
    $selected = array_values(array_unique(array_filter(array_map('intval', $selected),
        static function($id) { return $id > 0; })));
    if (!$selected || count($selected) > 20) {
        throw new invalid_parameter_exception('Select 1 to 20 decisions');
    }
    $bulkstatus = optional_param('bulkstatus', 'inprogress', PARAM_ALPHA);
    $bulkowner = max(0, optional_param('bulkowner', 0, PARAM_INT));
    $bulkduedate = optional_param('bulkduedate', '', PARAM_TEXT);
    if ($bulkoperation === 'status' && !in_array($bulkstatus,
            ['pending', 'inprogress', 'completed', 'cancelled'], true)) {
        throw new invalid_parameter_exception('Invalid status');
    }
    if ($bulkoperation === 'duedate') {
        $date = $bulkduedate === '' ? false : DateTimeImmutable::createFromFormat('!Y-m-d', $bulkduedate);
        if (!$date || $date->format('Y-m-d') !== $bulkduedate) {
            throw new invalid_parameter_exception('Invalid due date');
        }
    }
    // Verify management access even if all submitted IDs are invalid.
    $allowedowners = battle_plan_protocol_service::company_owner_options($uuid, (int)$USER->id);
    if ($bulkoperation === 'owner' && !isset($allowedowners[$bulkowner])) {
        throw new moodle_exception('nopermissions', 'error');
    }
    if ($bulkphase === 'confirm') {
        $ok = 0; $skipped = 0;
        $itemresults = [];
        foreach ($selected as $id) {
            try {
                $success = $bulkoperation === 'status'
                    ? battle_plan_protocol_service::bulk_change_status($uuid, (int)$USER->id,
                        $id, $bulkstatus)
                    : battle_plan_protocol_service::bulk_change_assignment($uuid, (int)$USER->id,
                        $id, $bulkoperation, $bulkowner, $bulkduedate);
                if ($success) {
                    $ok++;
                    $itemresults[] = ['id'=>$id, 'outcome'=>'updated', 'reason'=>''];
                } else {
                    $skipped++;
                    $itemresults[] = ['id'=>$id, 'outcome'=>'skipped', 'reason'=>'unavailable'];
                }
            } catch (\Throwable $e) {
                $skipped++;
                // Do not expose exception text: it may contain sensitive implementation details.
                $itemresults[] = ['id'=>$id, 'outcome'=>'skipped', 'reason'=>'validation'];
            }
        }
        // One immutable summary record per confirmed bulk operation, including partial failures.
        $audit = (object)[
            'companyuuid' => $uuid,
            'actoruserid' => (int)$USER->id,
            'operation' => $bulkoperation,
            'selectedcount' => count($selected),
            'updatedcount' => $ok,
            'skippedcount' => $skipped,
            'detailspayload' => json_encode($itemresults, JSON_UNESCAPED_UNICODE),
            'timecreated' => time(),
        ];
        $DB->insert_record('local_kbacompany_bp_baudit', $audit);
        redirect(new moodle_url('/local/kbacompany/battle_plan_decision_registry.php',
            ['uuid'=>$uuid,'status'=>$status,'owner'=>$owner,'from'=>$from,'to'=>$to,
                'overdue'=>$overdue,'page'=>$page,'bulkresult'=>$ok.'-'.$skipped]));
    }
    // Preview only actual latest revisions of visible company decisions; recheck on confirm.
    $bulkpreview = [];
    foreach ($selected as $id) {
        $decision = $DB->get_record('local_kbacompany_bp_proto', ['id'=>$id, 'companyuuid'=>$uuid]);
        if (!$decision) { continue; }
        $latest = $DB->get_records('local_kbacompany_bp_proto',
            ['companyuuid'=>$uuid,'weekstart'=>$decision->weekstart,'taskuuid'=>$decision->taskuuid],
            'revision DESC', 'id', 0, 1);
        if ($latest && (int)reset($latest)->id === $id) { $bulkpreview[] = $decision; }
    }
}
foreach (['from' => $from, 'to' => $to] as $name => $date) {
    if ($date !== '' && (!preg_match('/^\d{4}-\d{2}-\d{2}$/D', $date) ||
            !\DateTimeImmutable::createFromFormat('!Y-m-d', $date))) {
        throw new invalid_parameter_exception('Invalid date filter');
    }
}
$filters = ['status'=>$status, 'owner'=>$owner, 'from'=>$from, 'to'=>$to, 'overdue'=>$overdue];
$registry = battle_plan_protocol_service::company_registry($uuid, (int)$USER->id, $filters, $page);
$base = ['uuid'=>$uuid, 'status'=>$status, 'owner'=>$owner, 'from'=>$from, 'to'=>$to, 'overdue'=>$overdue];
$PAGE->set_url(new moodle_url('/local/kbacompany/battle_plan_decision_registry.php', $base));
$PAGE->set_context(context_system::instance());
$PAGE->set_title(get_string('battleplan_registry_title', 'local_kbacompany'));
$PAGE->set_heading(get_string('pluginname', 'local_kbacompany'));
$bmsshell = \local_kbacompany\local\bms_page_shell_service::begin($uuid, (int)$USER->id, 'battleplan',
    get_string('battleplan_registry_title', 'local_kbacompany'));
echo bms_workspace_ux_service::management_nav($uuid, 'decisionsregistry');
echo $OUTPUT->heading(get_string('battleplan_registry_title', 'local_kbacompany'));
// Compact, company-scoped audit trail. company_registry() above already validates management access.
$auditparams = ['companyuuid' => $uuid];
$auditwhere = ['companyuuid = :companyuuid'];
if ($auditactor) {
    $auditwhere[] = 'actoruserid = :auditactor';
    $auditparams['auditactor'] = $auditactor;
}
if ($auditoperation !== '') {
    $auditwhere[] = 'operation = :auditoperation';
    $auditparams['auditoperation'] = $auditoperation;
}
if ($auditresult === 'allupdated') {
    $auditwhere[] = 'updatedcount > 0 AND skippedcount = 0';
} else if ($auditresult === 'partial') {
    $auditwhere[] = 'updatedcount > 0 AND skippedcount > 0';
} else if ($auditresult === 'allskipped') {
    $auditwhere[] = 'updatedcount = 0 AND skippedcount > 0';
}
if ($auditfrom !== '') {
    $auditwhere[] = 'timecreated >= :auditstart';
    $auditparams['auditstart'] = strtotime($auditfrom . ' 00:00:00');
}
if ($auditto !== '') {
    $auditwhere[] = 'timecreated < :auditend';
    $auditparams['auditend'] = strtotime($auditto . ' 00:00:00 +1 day');
}
// Reject inverted date intervals before executing SQL.
if ($auditfrom !== '' && $auditto !== '' && $auditfrom > $auditto) {
    throw new invalid_parameter_exception('Audit start date must not exceed end date');
}
$auditwhereclause = implode(' AND ', $auditwhere);
$auditcount = (int)$DB->count_records_sql(
    'SELECT COUNT(1) FROM {local_kbacompany_bp_baudit} WHERE ' . $auditwhereclause, $auditparams);
$auditperpage = 20;
$bulksql = 'SELECT id,actoruserid,operation,selectedcount,updatedcount,skippedcount,detailspayload,timecreated
              FROM {local_kbacompany_bp_baudit} WHERE ' . $auditwhereclause .
    ' ORDER BY timecreated DESC, id DESC';
$bulklogs = $DB->get_records_sql($bulksql, $auditparams, $auditpage * $auditperpage, $auditperpage);
// Resolve audit-history request only after company_registry() has enforced management ACL.
// Restrict the target to a decision whose id occurs in the current company's audit log.
$auditrevisionhtml = '';
if ($audithistory > 0) {
    $target = $DB->get_record('local_kbacompany_bp_proto',
        ['id' => $audithistory, 'companyuuid' => $uuid],
        'id,companyuuid,weekstart,taskuuid', IGNORE_MISSING);
    if ($target) {
        // Confirm the id came from an audit record of this company. A JSON payload is
        // not indexed; reject unknown ids without exposing other company records.
        $sourceevents = $DB->get_records('local_kbacompany_bp_baudit',
            ['companyuuid' => $uuid], 'id DESC', 'id,detailspayload', 0, 500);
        $inaudit = false;
        foreach ($sourceevents as $sourceevent) {
            $items = json_decode((string)$sourceevent->detailspayload, true);
            if (!is_array($items)) { continue; }
            foreach ($items as $item) {
                if (is_array($item) && (int)($item['id'] ?? 0) === $audithistory) {
                    $inaudit = true;
                    break 2;
                }
            }
        }
        if ($inaudit) {
            $revisions = $DB->get_records('local_kbacompany_bp_proto',
                ['companyuuid' => $uuid, 'weekstart' => $target->weekstart,
                    'taskuuid' => $target->taskuuid], 'revision DESC', '*', 0, 25);
            $revisionauthors = array_values(array_unique(array_map(
                static function($r) { return (int)$r->createdby; }, $revisions)));
            $authors = $revisionauthors ? $DB->get_records_list('user', 'id', $revisionauthors,
                '', 'id,firstname,lastname') : [];
            $historyrows = '';
            foreach ($revisions as $revision) {
                $createdby = (int)$revision->createdby;
                $authorlabel = isset($authors[$createdby]) ? fullname($authors[$createdby]) : '#'.$createdby;
                $statuskey = 'battleplan_execution_'.(string)$revision->executionstatus;
                $statuslabel = get_string($statuskey, 'local_kbacompany');
                $historyrows .= html_writer::tag('div',
                    html_writer::tag('strong', get_string('battleplan_protocol_revision', 'local_kbacompany') .
                        ' '.(int)$revision->revision) . ' · '.s($authorlabel).' · '.
                    userdate((int)$revision->timecreated) .
                    html_writer::tag('div', get_string('battleplan_weekcoord_protocol_reason', 'local_kbacompany').
                        ': '.s($revision->reason)) .
                    html_writer::tag('div', get_string('battleplan_weekcoord_protocol_decision', 'local_kbacompany').
                        ': '.s($revision->decisiontext)) .
                    html_writer::tag('div', get_string('battleplan_execution_status', 'local_kbacompany').
                        ': '.s($statuslabel)) .
                    html_writer::tag('div', get_string('battleplan_protocol_responsible', 'local_kbacompany').
                        ': #'.(int)$revision->owneruserid.' · '.s($revision->duedate)),
                    ['class' => 'border-top py-2']);
            }
            $auditrevisionhtml = html_writer::div(
                html_writer::tag('h3', get_string('battleplan_protocol_history', 'local_kbacompany').
                    ' — #'.$audithistory) . $historyrows, 'bms24-card p-3 mb-3',
                ['id' => 'bpreg-audit-history']);
        }
    }
}
// Only show authors known from this company's audit records, not every Moodle user.
$auditactorids = $DB->get_fieldset_sql('SELECT DISTINCT actoruserid
    FROM {local_kbacompany_bp_baudit} WHERE companyuuid = :companyuuid ORDER BY actoruserid',
    ['companyuuid' => $uuid]);
$auditactoroptions = [0 => get_string('battleplan_bulkaudit_allactors', 'local_kbacompany')];
if ($auditactorids) {
    $allactors = $DB->get_records_list('user', 'id', $auditactorids, '', 'id,firstname,lastname');
    foreach ($auditactorids as $actorid) {
        if (isset($allactors[$actorid])) {
            $auditactoroptions[(int)$actorid] = fullname($allactors[$actorid]);
        }
    }
}
$actorids = array_values(array_unique(array_map(static function($row) { return (int)$row->actoruserid; }, $bulklogs)));
$actors = $actorids ? $DB->get_records_list('user', 'id', $actorids, '', 'id,firstname,lastname') : [];
// Resolve each logged decision against this company before creating a deep link.
// Never use a client-provided task UUID or trust an audit item id without a company check.
$auditedids = [];
foreach ($bulklogs as $auditevent) {
    $auditeditems = json_decode((string)($auditevent->detailspayload ?? ''), true);
    if (!is_array($auditeditems)) { continue; }
    foreach ($auditeditems as $auditeditem) {
        if (is_array($auditeditem) && isset($auditeditem['id']) &&
                filter_var($auditeditem['id'], FILTER_VALIDATE_INT) !== false && (int)$auditeditem['id'] > 0) {
            $auditedids[(int)$auditeditem['id']] = true;
        }
    }
}
$auditeddecisions = [];
if ($auditedids) {
    $auditedrecords = $DB->get_records_list('local_kbacompany_bp_proto', 'id', array_keys($auditedids),
        '', 'id,companyuuid,weekstart,taskuuid');
    foreach ($auditedrecords as $auditedrecord) {
        if ((string)$auditedrecord->companyuuid === (string)$uuid) {
            $auditeddecisions[(int)$auditedrecord->id] = $auditedrecord;
        }
    }
}
$audithistorybase = $base + ['page' => $page, 'auditpage' => $auditpage,
    'auditactor' => $auditactor, 'auditoperation' => $auditoperation,
    'auditresult' => $auditresult, 'auditfrom' => $auditfrom, 'auditto' => $auditto];
$logrows = '';
foreach ($bulklogs as $event) {
    $actorname = isset($actors[$event->actoruserid]) ? fullname($actors[$event->actoruserid]) : '#'.$event->actoruserid;
    $eventoperation = get_string('battleplan_bulkaudit_operation_'.$event->operation, 'local_kbacompany');
    $details = json_decode((string)($event->detailspayload ?? ''), true);
    $detailrows = '';
    if (is_array($details)) {
        foreach ($details as $item) {
            if (!is_array($item) || !isset($item['id'], $item['outcome'])) { continue; }
            $outcome = $item['outcome'] === 'updated' ? 'updated' : 'skipped';
            $reason = isset($item['reason']) && $item['reason'] === 'validation'
                ? 'validation' : 'unavailable';
            $description = get_string('battleplan_bulkaudit_outcome_'.$outcome, 'local_kbacompany');
            if ($outcome === 'skipped') {
                $description .= ' — '.get_string('battleplan_bulkaudit_reason_'.$reason, 'local_kbacompany');
            }
            $decisionid = (int)$item['id'];
            $decisionlabel = '#'.$decisionid;
            if (isset($auditeddecisions[$decisionid])) {
                $auditeddecision = $auditeddecisions[$decisionid];
                $decisionurl = new moodle_url('/local/kbacompany/battle_plan_week_coordination.php', [
                    'uuid' => $uuid,
                    'week' => $auditeddecision->weekstart,
                    'decisiontask' => $auditeddecision->taskuuid,
                ]);
                $decisionlabel = html_writer::link($decisionurl->out(false).'#bpwc-focused-decision',
                    '#'.$decisionid, ['aria-label' => get_string('battleplan_registry_open', 'local_kbacompany')]);
            } else {
                $decisionlabel = s($decisionlabel);
            }
            if (isset($auditeddecisions[$decisionid])) {
                $historyurl = new moodle_url('/local/kbacompany/battle_plan_decision_registry.php',
                    $audithistorybase + ['audithistory' => $decisionid]);
                $decisionlabel .= ' · ' . html_writer::link($historyurl->out(false).'#bpreg-audit-history',
                    get_string('battleplan_protocol_history', 'local_kbacompany'));
            }
            $detailrows .= html_writer::tag('li', $decisionlabel.' — '.s($description));
        }
    }
    $detailhtml = $detailrows !== '' ? html_writer::tag('details',
        html_writer::tag('summary', get_string('battleplan_bulkaudit_details', 'local_kbacompany')) .
        html_writer::tag('ul', $detailrows)) :
        get_string('battleplan_bulkaudit_legacy', 'local_kbacompany');
    $logrows .= html_writer::tag('tr',
        html_writer::tag('td', userdate($event->timecreated, get_string('strftimedatetimeshort', 'langconfig'))) .
        html_writer::tag('td', s($actorname)) .
        html_writer::tag('td', $eventoperation) .
        html_writer::tag('td', (string)$event->selectedcount) .
        html_writer::tag('td', (string)$event->updatedcount) .
        html_writer::tag('td', (string)$event->skippedcount) .
        html_writer::tag('td', $detailhtml));
}
$logtable = html_writer::tag('table',
    html_writer::tag('thead', html_writer::tag('tr',
        html_writer::tag('th', get_string('battleplan_bulkaudit_when', 'local_kbacompany')) .
        html_writer::tag('th', get_string('battleplan_bulkaudit_actor', 'local_kbacompany')) .
        html_writer::tag('th', get_string('battleplan_bulkaudit_operation', 'local_kbacompany')) .
        html_writer::tag('th', get_string('battleplan_bulkaudit_selected', 'local_kbacompany')) .
        html_writer::tag('th', get_string('battleplan_bulkaudit_updated', 'local_kbacompany')) .
        html_writer::tag('th', get_string('battleplan_bulkaudit_skipped', 'local_kbacompany')) .
        html_writer::tag('th', get_string('battleplan_bulkaudit_details', 'local_kbacompany')))) .
    html_writer::tag('tbody', $logrows), ['class'=>'table table-striped']);
// Keep decision registry filters when applying independent audit filters.
$auditbase = $base + ['page' => $page, 'auditactor' => $auditactor,
    'auditoperation' => $auditoperation, 'auditresult' => $auditresult,
    'auditfrom' => $auditfrom, 'auditto' => $auditto];
$auditform = html_writer::start_tag('form', ['method' => 'get',
    'action' => (new moodle_url('/local/kbacompany/battle_plan_decision_registry.php'))->out(false),
    'class' => 'mb-3']);
foreach ($base + ['page' => $page] as $hiddenkey => $hiddenvalue) {
    $auditform .= html_writer::empty_tag('input', ['type' => 'hidden',
        'name' => $hiddenkey, 'value' => $hiddenvalue]);
}
$auditform .= html_writer::label(get_string('battleplan_bulkaudit_actor', 'local_kbacompany'), 'auditactor');
$auditform .= html_writer::select($auditactoroptions, 'auditactor', $auditactor, false,
    ['id' => 'auditactor', 'class' => 'form-control mb-2']);
$operationoptions = ['' => get_string('all', 'core')];
foreach (['status', 'owner', 'duedate'] as $op) {
    $operationoptions[$op] = get_string('battleplan_bulkaudit_operation_'.$op, 'local_kbacompany');
}
$auditform .= html_writer::label(get_string('battleplan_bulkaudit_operation', 'local_kbacompany'), 'auditoperation');
$auditform .= html_writer::select($operationoptions, 'auditoperation', $auditoperation, false,
    ['id' => 'auditoperation', 'class' => 'form-control mb-2']);
$resultoptions = [
    '' => get_string('all', 'core'),
    'allupdated' => get_string('battleplan_bulkaudit_result_success', 'local_kbacompany'),
    'partial' => get_string('battleplan_bulkaudit_result_partial', 'local_kbacompany'),
    'allskipped' => get_string('battleplan_bulkaudit_result_skipped', 'local_kbacompany'),
];
$auditform .= html_writer::label(get_string('battleplan_bulkaudit_result', 'local_kbacompany'), 'auditresult');
$auditform .= html_writer::select($resultoptions, 'auditresult', $auditresult, false,
    ['id' => 'auditresult', 'class' => 'form-control mb-2']);
foreach (['auditfrom', 'auditto'] as $field) {
    $auditform .= html_writer::label(get_string('battleplan_bulkaudit_'.$field, 'local_kbacompany'), $field);
    $auditform .= html_writer::empty_tag('input', ['type' => 'date', 'name' => $field,
        'id' => $field, 'value' => $$field, 'class' => 'form-control mb-2']);
}
$auditform .= html_writer::empty_tag('input', ['type' => 'submit',
    'value' => get_string('battleplan_show', 'local_kbacompany'), 'class' => 'btn btn-primary']);
$auditform .= ' ' . html_writer::link(
    new moodle_url('/local/kbacompany/battle_plan_decision_registry.php', $base + ['page' => $page]),
    get_string('battleplan_bulkaudit_reset', 'local_kbacompany'),
    ['class' => 'btn btn-secondary ml-2']);
$auditform .= html_writer::end_tag('form');
$auditpaging = $OUTPUT->paging_bar($auditcount, $auditpage, $auditperpage,
    new moodle_url('/local/kbacompany/battle_plan_decision_registry.php', $auditbase), 'auditpage');
$auditfiltersactive = $auditactor > 0 || $auditoperation !== '' || $auditresult !== '' ||
    $auditfrom !== '' || $auditto !== '' || $auditpage > 0;
$auditdetailattrs = ['class' => 'bms24-card mb-3', 'id' => 'bpreg-audit'];
if ($auditfiltersactive || $audithistory > 0) { $auditdetailattrs['open'] = 'open'; }
echo html_writer::start_tag('details', $auditdetailattrs);
echo html_writer::tag('summary', get_string('battleplan_bulkaudit_title', 'local_kbacompany') . ' ('.$auditcount.')');
echo $auditrevisionhtml;
echo $auditform;
echo html_writer::tag('p', get_string('battleplan_bulkaudit_found', 'local_kbacompany', $auditcount),
    ['class' => 'text-muted small']);
echo $bulklogs ? html_writer::div($logtable, 'table-responsive') :
    html_writer::tag('p', get_string('battleplan_bulkaudit_empty', 'local_kbacompany'));
echo $auditpaging;
echo html_writer::end_tag('details');

echo html_writer::tag('p', get_string('battleplan_registry_help', 'local_kbacompany'), ['class'=>'text-muted']);
$attrs = ['method'=>'get','action'=>(new moodle_url('/local/kbacompany/battle_plan_decision_registry.php'))->out(false),
    'class'=>'bms24-card mb-3'];
echo html_writer::start_tag('form', $attrs);
echo html_writer::empty_tag('input', ['type'=>'hidden','name'=>'uuid','value'=>$uuid]);
$statusopts = [''=>get_string('all', 'core')];
foreach (['pending','inprogress','completed','cancelled'] as $value) {
    $statusopts[$value] = get_string('battleplan_execution_'.$value, 'local_kbacompany');
}
echo html_writer::label(get_string('battleplan_registry_status','local_kbacompany'), 'bpreg_status');
echo html_writer::select($statusopts, 'status', $status, false, ['id'=>'bpreg_status', 'class'=>'form-control mb-2']);
echo html_writer::label(get_string('battleplan_protocol_responsible','local_kbacompany'), 'bpreg_owner');
// Choices are sourced only from saved decisions in this company after management ACL checks.
$owneropts = [0 => get_string('battleplan_registry_all_owners', 'local_kbacompany')];
foreach (battle_plan_protocol_service::company_owner_options($uuid, (int)$USER->id) as $id=>$name) {
    $owneropts[$id] = $name;
}
echo html_writer::select($owneropts, 'owner', $owner, false, ['id'=>'bpreg_owner','class'=>'form-control mb-2']);
foreach (['from','to'] as $key) {
    echo html_writer::label(get_string('battleplan_registry_'.$key,'local_kbacompany'), 'bpreg_'.$key);
    echo html_writer::empty_tag('input', ['type'=>'date','name'=>$key,'id'=>'bpreg_'.$key,
        'value'=>$$key,'class'=>'form-control mb-2']);
}
echo html_writer::checkbox('overdue', 1, (bool)$overdue,
    get_string('battleplan_registry_overdue','local_kbacompany'), ['id'=>'bpreg_overdue']);
echo html_writer::empty_tag('input', ['type'=>'submit','value'=>get_string('battleplan_show','local_kbacompany'),
    'class'=>'btn btn-primary ml-2']);
echo html_writer::end_tag('form');
if ($bulkpreview !== null) {
    echo html_writer::start_div('bms24-card mb-3');
    echo $OUTPUT->heading(get_string('battleplan_bulk_preview', 'local_kbacompany'), 3);
    echo html_writer::tag('p', get_string('battleplan_bulk_preview_help', 'local_kbacompany'));
    $previews = '';
    foreach ($bulkpreview as $decision) {
        if ($bulkoperation === 'status') {
            $before = get_string('battleplan_execution_'.$decision->executionstatus,'local_kbacompany');
            $after = get_string('battleplan_execution_'.$bulkstatus,'local_kbacompany');
        } else if ($bulkoperation === 'owner') {
            $before = (string)$decision->owneruserid;
            $after = $allowedowners[$bulkowner];
        } else {
            $before = (string)$decision->duedate;
            $after = $bulkduedate;
        }
        $previews .= html_writer::tag('tr', html_writer::tag('td', s($decision->decisiontext)) .
            html_writer::tag('td', s($before)) . html_writer::tag('td', s($after)));
    }
    echo html_writer::div(html_writer::tag('table',
        html_writer::tag('thead', html_writer::tag('tr',
            html_writer::tag('th', get_string('battleplan_registry_decision','local_kbacompany')) .
            html_writer::tag('th', get_string('battleplan_bulk_current','local_kbacompany')) .
            html_writer::tag('th', get_string('battleplan_bulk_new','local_kbacompany')))) .
        html_writer::tag('tbody', $previews), ['class'=>'table table-striped']), 'table-responsive');
    if ($bulkpreview) {
        echo html_writer::start_tag('form', ['method'=>'post',
            'action'=>(new moodle_url('/local/kbacompany/battle_plan_decision_registry.php'))->out(false)]);
        $hidden = ['uuid'=>$uuid,'status'=>$status,'owner'=>$owner,'from'=>$from,
            'to'=>$to,'overdue'=>$overdue,'page'=>$page,'sesskey'=>sesskey(),
            'bulkphase'=>'confirm','bulkoperation'=>$bulkoperation,'bulkstatus'=>$bulkstatus,
            'bulkowner'=>$bulkowner,'bulkduedate'=>$bulkduedate];
        foreach ($hidden as $key=>$val) {
            echo html_writer::empty_tag('input', ['type'=>'hidden','name'=>$key,'value'=>$val]);
        }
        foreach ($bulkpreview as $decision) {
            echo html_writer::empty_tag('input', ['type'=>'hidden','name'=>'selected[]','value'=>(int)$decision->id]);
        }
        echo html_writer::empty_tag('input', ['type'=>'submit','class'=>'btn btn-primary',
            'value'=>get_string('battleplan_bulk_confirm_apply','local_kbacompany')]);
        echo html_writer::end_tag('form');
    }
    echo html_writer::end_div();
}
if (preg_match('/^(\d+)-(\d+)$/D', $bulkresult, $matches)) {
    echo $OUTPUT->notification(get_string('battleplan_bulk_result', 'local_kbacompany',
        (object)['updated'=>$matches[1], 'skipped'=>$matches[2]]), 'info');
}
$owners = []; $ids = [];
foreach ($registry['rows'] as $entry) { if ((int)$entry->owneruserid > 0) {$ids[(int)$entry->owneruserid]=true;} }
if ($ids) { $owners = $DB->get_records_list('user', 'id', array_keys($ids), '', 'id,firstname,lastname'); }
$rows = '';
foreach ($registry['rows'] as $entry) {
    $person = $owners[(int)$entry->owneruserid] ?? null;
    $name = $person ? fullname($person) : get_string('unknownuser');
    $state = (string)$entry->executionstatus;
    if (!in_array($state, ['pending','inprogress','completed','cancelled'], true)) {$state = 'pending';}
    $coordurl = new moodle_url('/local/kbacompany/battle_plan_week_coordination.php',
        ['uuid'=>$uuid,'week'=>$entry->weekstart,'decisiontask'=>$entry->taskuuid]);
    $pick = html_writer::checkbox('selected[]', (int)$entry->id, false, '',
        ['class'=>'bms24-decision-bulk-checkbox', 'aria-label'=>get_string('battleplan_bulk_select', 'local_kbacompany')]);
    $cells = [$pick, s($entry->weekstart), s($entry->decisiontext), s($name),
        get_string('battleplan_execution_'.$state,'local_kbacompany'), s($entry->duedate),
        html_writer::link($coordurl->out(false).'#bpwc-focused-decision',
            get_string('battleplan_registry_open','local_kbacompany'))];
    $rows .= html_writer::tag('tr', implode('', array_map(static function($content) {
        return html_writer::tag('td', $content);
    }, $cells)));
}
if ($rows === '') {
    echo html_writer::div(get_string('battleplan_registry_empty','local_kbacompany'), 'alert alert-info');
} else {
    $heads = '';
    foreach (['battleplan_bulk_select','battleplan_week','battleplan_registry_decision','battleplan_protocol_responsible',
        'battleplan_registry_status','battleplan_protocol_duedate','battleplan_registry_action'] as $key) {
        $heads .= html_writer::tag('th', get_string($key,'local_kbacompany'));
    }
    // One form, one selection and one preview workflow for all operations.
    $owneropts = ['0'=>get_string('choosedots', 'core')] +
        battle_plan_protocol_service::company_owner_options($uuid, (int)$USER->id);
    echo html_writer::start_tag('form', ['method'=>'post',
        'action'=>(new moodle_url('/local/kbacompany/battle_plan_decision_registry.php'))->out(false),
        'class'=>'bms24-decision-bulk-form','id'=>'bpreg-bulk-form']);
    foreach ($base + ['page'=>$page, 'sesskey'=>sesskey(), 'bulkphase'=>'preview'] as $key=>$value) {
        echo html_writer::empty_tag('input', ['type'=>'hidden','name'=>$key,'value'=>$value]);
    }
    echo html_writer::start_div('bms24-card mb-2');
    echo html_writer::tag('strong', get_string('battleplan_bulk_unified_title', 'local_kbacompany'));
    echo html_writer::tag('label', get_string('battleplan_bulk_operation', 'local_kbacompany'),
        ['for'=>'bpreg-operation','class'=>'d-block mt-2']);
    echo html_writer::select([
        'status'=>get_string('battleplan_registry_status','local_kbacompany'),
        'owner'=>get_string('battleplan_bulk_assign_owner','local_kbacompany'),
        'duedate'=>get_string('battleplan_bulk_assign_date','local_kbacompany')],
        'bulkoperation', 'status', false, ['id'=>'bpreg-operation','class'=>'form-control mb-2']);
    $bulkopts = [];
    foreach (['pending','inprogress','completed','cancelled'] as $val) {
        $bulkopts[$val] = get_string('battleplan_execution_'.$val, 'local_kbacompany');
    }
    echo html_writer::div(html_writer::select($bulkopts, 'bulkstatus', 'inprogress', false,
        ['class'=>'form-control']), 'mb-2', ['id'=>'bpreg-status-fields']);
    echo html_writer::div(html_writer::select($owneropts,'bulkowner',0,false,
        ['class'=>'form-control']), 'mb-2', ['id'=>'bpreg-owner-fields','style'=>'display:none']);
    echo html_writer::div(html_writer::empty_tag('input',
        ['type'=>'date','name'=>'bulkduedate','class'=>'form-control']), 'mb-2',
        ['id'=>'bpreg-date-fields','style'=>'display:none']);
    echo html_writer::tag('label', html_writer::checkbox('selectvisible', '1', false,
        get_string('battleplan_bulk_select_page', 'local_kbacompany'),
        ['id'=>'bpreg-select-page']), ['class'=>'d-block mb-2']);
    echo html_writer::empty_tag('input', ['type'=>'submit','class'=>'btn btn-primary',
        'value'=>get_string('battleplan_bulk_preview','local_kbacompany')]);
    echo html_writer::tag('p', get_string('battleplan_bulk_unified_help','local_kbacompany'),
        ['class'=>'small text-muted mb-0 mt-2']);
    echo html_writer::end_div();
    echo html_writer::div(html_writer::tag('table',
        html_writer::tag('thead',html_writer::tag('tr',$heads)).html_writer::tag('tbody',$rows),
        ['class'=>'table table-striped']), 'table-responsive');
    echo html_writer::end_tag('form');
    $PAGE->requires->js_init_code(<<<'JS'
(function() {
  var form = document.getElementById('bpreg-bulk-form');
  if (!form) { return; }
  var operation = document.getElementById('bpreg-operation');
  var chooseAll = document.getElementById('bpreg-select-page');
  var checks = Array.prototype.slice.call(form.querySelectorAll('.bms24-decision-bulk-checkbox'));
  function refreshFields() {
    ['status','owner','duedate'].forEach(function(name) {
      var block = document.getElementById('bpreg-' + (name === 'duedate' ? 'date' : name) + '-fields');
      if (block) { block.style.display = operation.value === name ? '' : 'none'; }
    });
  }
  function updateMaster() {
    chooseAll.checked = checks.length > 0 && checks.every(function(c) { return c.checked; });
    chooseAll.indeterminate = checks.some(function(c) { return c.checked; }) && !chooseAll.checked;
  }
  operation.addEventListener('change', refreshFields);
  chooseAll.addEventListener('change', function() {
    checks.slice(0,20).forEach(function(c) { c.checked = chooseAll.checked; });
    updateMaster();
  });
  checks.forEach(function(c) { c.addEventListener('change', updateMaster); });
  form.addEventListener('submit', function(e) {
    var count = checks.filter(function(c) { return c.checked; }).length;
    if (count === 0 || count > 20) { e.preventDefault(); window.alert('Выберите от 1 до 20 решений'); }
  });
  refreshFields(); updateMaster();
})();
JS
    );
}
echo $OUTPUT->paging_bar($registry['total'], $registry['page'], $registry['pagesize'],
    new moodle_url('/local/kbacompany/battle_plan_decision_registry.php', $base));
\local_kbacompany\local\bms_page_shell_service::finish($bmsshell);
