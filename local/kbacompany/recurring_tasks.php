<?php
require_once(__DIR__ . '/../../config.php');

use local_kbacompany\local\recurring_task_service;
use local_kbacompany\local\task_service;
use local_kbacompany\local\company_service;

require_login();
$context = context_system::instance();
require_capability('local/kbacompany:viewowncompany', $context);
$uuid = required_param('uuid', PARAM_RAW_TRIMMED);
$selected = optional_param('series', '', PARAM_RAW_TRIMMED);
$view = optional_param('view', $selected !== '' ? 'detail' : 'list', PARAM_ALPHA);
if (!in_array($view, ['list', 'create', 'detail', 'edit'], true)) {
    throw new moodle_exception('invalidparameter');
}
$createview = $view === 'create';
$journalpage = max(0, min(100, optional_param('journalpage', 0, PARAM_INT)));
$historypage = max(0, min(100, optional_param('historypage', 0, PARAM_INT)));
$requestedoccversion = max(0, optional_param('occversion', 0, PARAM_INT));
// Presentation-only filter: always query canonical company/actor-scoped definitions first.
// Never allow a GET status parameter to influence authorization or selected-series lookup.
$seriesstatus = optional_param('status', 'all', PARAM_ALPHA);
if ($seriesstatus !== 'all' && !in_array($seriesstatus, recurring_task_service::STATUSES, true)) {
    $seriesstatus = 'all';
}
$defaultstream = optional_param('stream', 'operate', PARAM_ALPHA);
if (!in_array($defaultstream, task_service::STREAMS, true)) { $defaultstream='operate'; }
$planowner = 0;
$planperiod = ''; 
// The originating battle plan is navigation context only, never authorization.
$planuuid = optional_param('plan', '', PARAM_RAW_TRIMMED);
if ($planuuid !== '') {
    // Validate the concrete plan under the current company and actor before displaying a return link.
    $planctx=\local_kbacompany\local\battle_plan_service::context($uuid, (int)$USER->id, $planuuid, 0, '', '',
        ['load_plans' => false, 'load_sources' => false]);
    $planowner=(int)$planctx['selected']['user_id'];
    $planperiod=\local_kbacompany\local\battle_plan_service::period_label(
        $planctx['selected']['period_start'], $planctx['selected']['period_end']);
}
$originparams = $planuuid !== '' ? ['plan' => $planuuid] : [];
company_service::company_context($uuid, (int)$USER->id);

// Imported library provenance is historical metadata stored in legacy descriptions.
// Separate it for presentation only; do not rewrite any existing series rows.
$splitlibrarydescription = static function(string $raw, string $templatekey): array {
    if ($templatekey !== '') {
        foreach (['Создано из библиотеки регулярных задач. Источник:',
                'Created from the recurring task library. Source:'] as $marker) {
            $position = strpos($raw, $marker);
            if ($position !== false && ($position === 0 || trim(substr($raw, max(0, $position-2), 2)) === '')) {
                return [trim(substr($raw, 0, $position)), trim(substr($raw, $position))];
            }
        }
    }
    return [$raw, ''];
};

$action = optional_param('action', '', PARAM_ALPHA);
$formpayload = []; 
if ($action !== '') {
    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') { throw new moodle_exception('taskcenter_post', 'local_kbacompany'); }
    require_sesskey();
    try {
        if (in_array($action, ['create', 'save'], true)) {
            $weekdaymask = 0;
            foreach (optional_param_array('weekdays', [], PARAM_INT) as $weekday) {
                $weekday = (int)$weekday;
                if ($weekday >= 1 && $weekday <= 7) { $weekdaymask |= (1 << ($weekday - 1)); }
            }
            $payload = [
                'title' => required_param('title', PARAM_TEXT),
                'description' => optional_param('description', '', PARAM_RAW),
                'product' => optional_param('product', '', PARAM_RAW),
                'assigneeid' => optional_param('assigneeid', 0, PARAM_INT),
                'dutypositionuuid' => optional_param('dutypositionuuid', '', PARAM_RAW_TRIMMED),
                'reviewerid' => optional_param('reviewerid', 0, PARAM_INT),
                'priority' => optional_param('priority', 'normal', PARAM_ALPHA),
                'stream' => optional_param('stream', 'operate', PARAM_ALPHA),
                'cadence' => optional_param('cadence', 'weekly', PARAM_ALPHA),
                'weekdaymask' => $weekdaymask,
                'monthweek' => optional_param('monthweek', 0, PARAM_INT),
                'startdate' => optional_param('startdate', '', PARAM_RAW_TRIMMED),
                'enddate' => optional_param('enddate', '', PARAM_RAW_TRIMMED),
                'dueoffsetdays' => optional_param('dueoffsetdays', 0, PARAM_INT),
                'planminutes' => optional_param('planminutes', 0, PARAM_INT),
            ];
        }
        if (!empty($payload)) { $formpayload = $payload; }
        if ($action === 'create') {
            // This button represents the Regular Work stream, not an arbitrary series.
            if ($planuuid !== '') { $payload['stream'] = 'run'; $formpayload['stream'] = 'run'; }
            if ($payload['dutypositionuuid'] !== '' && optional_param('dutyshareconfirm', 0, PARAM_INT) !== 1) {
                throw new moodle_exception('recurringtask_duty_confirm_required', 'local_kbacompany');
            }
            $selected = recurring_task_service::create($uuid, (int)$USER->id, $payload);
        } else if ($action === 'save') {
            $selected = required_param('series', PARAM_RAW_TRIMMED);
            // The HTML form edits only the human-readable part of a library description.
            // Preserve the imported source signature on every subsequent revision.
            $previous = recurring_task_service::context($uuid, (int)$USER->id, $selected)['selected'];
            if ($previous && $payload['dutypositionuuid'] !== '' &&
                    $payload['dutypositionuuid'] !== (string)($previous['duty_position_uuid'] ?? '') &&
                    optional_param('dutyshareconfirm', 0, PARAM_INT) !== 1) {
                throw new moodle_exception('recurringtask_duty_confirm_required', 'local_kbacompany');
            }
            if ($previous && (string)$previous['template_key'] !== '') {
                list($plain, $origin) = $splitlibrarydescription((string)$previous['description'],
                    (string)$previous['template_key']);
                if ($origin !== '') {
                    $payload['description'] = trim((string)$payload['description']);
                    $payload['description'] .= ($payload['description'] !== '' ? "\n\n" : '') . $origin;
                    // On validation failure, do not reintroduce the diagnostic text into the edit form.
                    $formpayload['description'] = trim((string)optional_param('description', '', PARAM_RAW));
                }
            }
            recurring_task_service::save($uuid, (int)$USER->id, $selected, required_param('revision', PARAM_INT), $payload);
        } else if (in_array($action, ['activate', 'pause', 'archive'], true)) {
            $selected = required_param('series', PARAM_RAW_TRIMMED);
            // Archiving is destructive for future occurrences: require an explicit POST confirmation.
            if ($action === 'archive' && optional_param('archiveconfirm', 0, PARAM_INT) !== 1) {
                throw new moodle_exception('recurringtask_archive_confirm_required', 'local_kbacompany');
            }
            recurring_task_service::transition($uuid, (int)$USER->id, $selected, required_param('revision', PARAM_INT), $action);
        } else if ($action === 'generate') {
            $selected = required_param('series', PARAM_RAW_TRIMMED);
            // Rendering permissions are not sufficient: verify explicit manual generation on POST.
            $permissions = recurring_task_service::context($uuid, (int)$USER->id, $selected);
            $target = $permissions['selected'];
            if (!$target || (!$permissions['can_manage'] && (int)$target['creator_id'] !== (int)$USER->id)) {
                throw new moodle_exception('recurringtask_permission', 'local_kbacompany');
            }
            recurring_task_service::generate_due_for_series($uuid, (int)$USER->id, $selected, gmdate('Y-m-d'));
        }
        redirect(new moodle_url('/local/kbacompany/recurring_tasks.php', array_merge(['uuid' => $uuid, 'series' => $selected], $originparams,
                $seriesstatus !== 'all' ? ['status' => $seriesstatus] : [])),
            get_string('recurringtask_saved', 'local_kbacompany'));
    } catch (moodle_exception $e) {
        if ($e->module !== 'local_kbacompany') { throw $e; }
        $error = $e->getMessage();
        // Keep the correct CRUD form and submitted values after a validation failure.
        if ($action === 'create') { $view = 'create'; }
        if ($action === 'save') { $view = 'edit'; }
    }
}

$data = recurring_task_service::context($uuid, (int)$USER->id, $selected);
$selectedseries = $data['selected'];
$caneditselected = $selectedseries && ($data['can_manage'] || (int)$selectedseries['creator_id'] === (int)$USER->id);
if (in_array($view, ['detail', 'edit'], true) && !$selectedseries) {
    throw new moodle_exception('recurringtask_reference', 'local_kbacompany');
}
if ($view === 'edit' && (!$caneditselected || $selectedseries['status'] === 'archived')) {
    throw new moodle_exception('recurringtask_permission', 'local_kbacompany');
}
$PAGE->set_context($context);
$PAGE->set_url(new moodle_url('/local/kbacompany/recurring_tasks.php', array_merge(['uuid' => $uuid, 'series' => $selected], $originparams)));
$PAGE->set_title(get_string('recurringtask_title', 'local_kbacompany'));
$PAGE->set_heading(get_string('pluginname', 'local_kbacompany'));

$input = static function(string $name, string $value = '', string $type = 'text', bool $required = false): string {
    $attrs = ['type' => $type, 'name' => $name, 'value' => $value, 'class' => 'form-control'];
    if ($required) { $attrs['required'] = 'required'; }
    $attrs['id']='rt_'.$name;
    return html_writer::empty_tag('input', $attrs);
};
$textarea = static function(string $name, string $value = ''): string {
    return html_writer::tag('textarea', s($value), ['id'=>'rt_'.$name,'name' => $name, 'rows' => 3, 'class' => 'form-control']);
};
$hidden = static function(string $action, string $series = '', int $revision = 0) use ($uuid, $planuuid): string {
    $html = html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'uuid', 'value' => $uuid]);
    if ($planuuid !== '') { $html .= html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'plan', 'value' => $planuuid]); }
    $html .= html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'action', 'value' => $action]);
    $html .= html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'sesskey', 'value' => sesskey()]);
    if ($series !== '') { $html .= html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'series', 'value' => $series]); }
    if ($revision > 0) { $html .= html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'revision', 'value' => $revision]); }
    return $html;
};

$people = [0 => get_string('recurringtask_select_assignee', 'local_kbacompany')];
foreach ($data['employees'] as $id => $name) { $people[(int)$id] = $name; }
$assignees = $data['can_manage'] ? $people : [(int)$USER->id => ($data['employees'][(int)$USER->id] ?? fullname($USER))];
$reviewers = [0 => get_string('taskcenter_noreviewer', 'local_kbacompany')];
foreach ($data['employees'] as $id => $name) { $reviewers[(int)$id] = $name; }
$streams = [];
foreach (task_service::STREAMS as $stream) { $streams[$stream] = get_string('taskcenter_stream_' . $stream, 'local_kbacompany'); }
$priorities = [];
foreach (task_service::PRIORITIES as $priority) { $priorities[$priority] = get_string('taskcenter_priority_' . $priority, 'local_kbacompany'); }
$cadences = [];
foreach (recurring_task_service::CADENCES as $cadence) { $cadences[$cadence] = get_string('recurringtask_cadence_' . $cadence, 'local_kbacompany'); }
$weekdaylabels = [];
for ($day = 1; $day <= 7; $day++) { $weekdaylabels[$day] = get_string('recurringtask_weekday_' . $day, 'local_kbacompany'); }
$monthweeks = [0 => get_string('recurringtask_monthweek_none', 'local_kbacompany')];
for ($week = 1; $week <= 5; $week++) { $monthweeks[$week] = get_string('recurringtask_monthweek_' . $week, 'local_kbacompany'); }
$ownedpresentation = \local_kbacompany\local\bms24_shell_service::is_enabled();

$renderform = static function(?array $series = null, array $posted = []) use ($hidden, $input, $textarea, $assignees, $reviewers,
        $streams, $priorities, $cadences, $weekdaylabels, $monthweeks, $defaultstream, $planowner, $uuid, $originparams,
        $planuuid, $splitlibrarydescription, $data, $USER, $ownedpresentation): void {
    $editing = $series !== null;
    // Never expose SHA256/source provenance in the ordinary Description textarea.
    // Raw provenance remains stored unchanged in the canonical series revision.
    if ($editing && !array_key_exists('description', $posted)) {
        list($humantext, $origindata) = $splitlibrarydescription((string)$series['description'],
            (string)$series['template_key']);
        $posted['description'] = $humantext;
    }
    $value = static function(string $submittedkey, string $serieskey, $default = '') use ($posted, $series) {
        return array_key_exists($submittedkey, $posted) ? $posted[$submittedkey] : ($series[$serieskey] ?? $default);
    };
    $formvalues = [
        'title' => $value('title', 'title'), 'product' => $value('product', 'product'),
        'description' => $value('description', 'description'), 'assigneeid' => $value('assigneeid', 'assignee_id', 0),
        'reviewerid' => $value('reviewerid', 'reviewer_id', 0),
        'dutypositionuuid' => $value('dutypositionuuid', 'duty_position_uuid', ''),
        'cadence' => $value('cadence', 'cadence', 'weekly'), 'stream' => $value('stream', 'stream', $defaultstream),
        'priority' => $value('priority', 'priority', 'normal'),
        'startdate' => $value('startdate', 'start_date', gmdate('Y-m-d')),
        'enddate' => $value('enddate', 'end_date'), 'dueoffsetdays' => $value('dueoffsetdays', 'due_offset_days', 0),
        'planminutes' => $value('planminutes', 'plan_minutes', 0), 'monthweek' => $value('monthweek', 'month_week', 0),
    ];
    $daymask = (int)($series['weekday_mask'] ?? 0);
    if (isset($posted['weekdaymask'])) { $daymask = (int)$posted['weekdaymask']; }
    $defaultassignee = $planowner > 0 && isset($assignees[$planowner]) ? $planowner : 0;
    $assignee = (int)$formvalues['assigneeid'] > 0 ? (int)$formvalues['assigneeid'] : $defaultassignee;
    $formclass = $ownedpresentation ? 'kbacompany-form bms-recurring-form bms-recurring-form-owned bms24-task-panel' :
        'kbacompany-form bms-recurring-form border rounded mb-4';
    echo html_writer::start_tag('form', ['method' => 'post', 'class' => $formclass]);
    echo $hidden($editing ? 'save' : 'create', $editing ? $series['uuid'] : '', $editing ? $series['revision'] : 0);
    // BMS-UX-CRUD-001: single-column form, labels above controls, groups instead of a two-column global form grid.
    echo html_writer::start_tag('section', ['class' => 'bms-rt-section', 'aria-labelledby' => 'rt_basics_heading']);
    echo html_writer::tag('h2', get_string('recurringtask_form_basics', 'local_kbacompany'),
        ['id' => 'rt_basics_heading', 'class' => 'bms-rt-section-heading']);
    echo html_writer::start_tag('div', ['class' => 'bms-rt-field']);
    echo html_writer::label(get_string('taskcenter_task', 'local_kbacompany') . ' *', 'rt_title');
    echo $input('title', (string)$formvalues['title'], 'text', true);
    echo html_writer::end_tag('div');
    echo html_writer::start_tag('div', ['class' => 'bms-rt-field']);
    echo html_writer::label(get_string('taskcenter_product', 'local_kbacompany') . ' *', 'rt_product');
    echo html_writer::tag('textarea', s((string)$formvalues['product']), ['id'=>'rt_product', 'name'=>'product',
        'rows'=>2,'class'=>'form-control','required'=>'required']);
    echo html_writer::tag('small', get_string('recurringtask_product_help', 'local_kbacompany'),
        ['class' => 'form-text text-muted', 'id' => 'rt_product_help']);
    echo html_writer::end_tag('div');
    echo html_writer::start_tag('div', ['class' => 'bms-rt-field']);
    echo html_writer::label(get_string('taskcenter_assignee', 'local_kbacompany') . ' *', 'rt_assigneeid');
    echo html_writer::select($assignees, 'assigneeid', $assignee, false,
        ['id'=>'rt_assigneeid','class'=>'form-control','required'=>'required']);
    echo html_writer::end_tag('div');
    echo html_writer::start_tag('div', ['class' => 'bms-rt-field']);
    echo html_writer::label(get_string('recurringtask_duty_type', 'local_kbacompany'), 'rt_dutypositionuuid');
    if ($data['can_manage']) {
        $options = task_service::duty_position_options($uuid, (int)$USER->id, $assignee);
        $chosen = (string)$formvalues['dutypositionuuid'];
        // Keep the former value visible for the manager on a stale assignment, but never
        // bypass the server validator by treating it as a valid current assignment.
        if ($chosen !== '' && !isset($options[$chosen])) {
            $options[$chosen] = get_string('recurringtask_duty_outdated', 'local_kbacompany');
        }
        echo html_writer::select($options, 'dutypositionuuid', $chosen, false,
            ['id'=>'rt_dutypositionuuid','class'=>'form-control']);
        echo html_writer::tag('small', get_string('recurringtask_duty_help', 'local_kbacompany'),
            ['class'=>'form-text text-muted']);
        echo html_writer::div(html_writer::checkbox('dutyshareconfirm', 1, false,
            get_string('recurringtask_duty_confirm', 'local_kbacompany')), 'mt-2');
    } else {
        $positionuuid = (string)$formvalues['dutypositionuuid'];
        echo html_writer::empty_tag('input', ['type'=>'hidden', 'name'=>'dutypositionuuid',
            'value'=>$positionuuid]);
        echo html_writer::tag('div', get_string($positionuuid === '' ? 'recurringtask_duty_personal'
            : 'recurringtask_duty_position', 'local_kbacompany'), ['class'=>'text-muted']);
    }
    echo html_writer::end_tag('div');
    echo html_writer::end_tag('section');
    echo html_writer::start_tag('section', ['class' => 'bms-rt-section', 'aria-labelledby' => 'rt_schedule_heading']);
    echo html_writer::tag('h2', get_string('recurringtask_form_schedule', 'local_kbacompany'),
        ['id' => 'rt_schedule_heading', 'class' => 'bms-rt-section-heading']);
    echo html_writer::start_tag('div', ['class' => 'bms-rt-field']);
    echo html_writer::label(get_string('recurringtask_cadence', 'local_kbacompany') . ' *', 'rt_cadence');
    $visiblecadences = $cadences;
    if (!$editing) { unset($visiblecadences['review']); }
    echo html_writer::select($visiblecadences, 'cadence', $formvalues['cadence'], false, ['id'=>'rt_cadence','class'=>'form-control']);
    echo html_writer::end_tag('div');
    echo html_writer::start_tag('div', ['class' => 'bms-rt-field']);
    echo html_writer::label(get_string('recurringtask_startdate', 'local_kbacompany') . ' *', 'rt_startdate');
    echo $input('startdate', (string)$formvalues['startdate'], 'date', true);
    echo html_writer::end_tag('div');
    echo html_writer::start_tag('fieldset', ['id'=>'rt_weekdays_group','class'=>'bms-rt-field bms-rt-weekdays']);
    echo html_writer::tag('legend', get_string('recurringtask_weekdays', 'local_kbacompany'), ['class'=>'font-weight-bold h6']);
    foreach ($weekdaylabels as $day => $label) {
        $attrs=['type'=>'checkbox','name'=>'weekdays[]','value'=>$day,'class'=>'mr-1'];
        if (($daymask & (1 << ($day-1))) !== 0) { $attrs['checked']='checked'; }
        echo html_writer::tag('label',html_writer::empty_tag('input',$attrs).' '.s($label),['class'=>'mr-3']);
    }
    echo html_writer::tag('small',get_string('recurringtask_days_select_help','local_kbacompany'),['class'=>'d-block form-text text-muted']);
    echo html_writer::end_tag('fieldset');
    echo html_writer::tag('small',get_string('recurringtask_schedule_warning','local_kbacompany'),
        ['class'=>'text-muted d-block bms-rt-help']);
    echo html_writer::end_tag('section');
    echo html_writer::start_tag('details', ['class'=>'bms-rt-advanced']);
    echo html_writer::tag('summary',get_string('recurringtask_advanced','local_kbacompany'),['class'=>'font-weight-bold py-2']);
    echo html_writer::start_tag('div', ['class'=>'bms-rt-advanced-inner']);
    echo html_writer::start_tag('div', ['id'=>'rt_monthweek_group','class'=>'bms-rt-field']);
    echo html_writer::label(get_string('recurringtask_monthweek','local_kbacompany'), 'rt_monthweek');
    echo html_writer::select($monthweeks,'monthweek',$formvalues['monthweek'],false,['id'=>'rt_monthweek','class'=>'form-control']);
    echo html_writer::tag('small',get_string('recurringtask_monthweek_help','local_kbacompany'),['class'=>'form-text text-muted']);
    echo html_writer::end_tag('div');
    echo html_writer::start_tag('div', ['class'=>'bms-rt-field']);
    echo html_writer::label(get_string('taskcenter_description', 'local_kbacompany'),'rt_description');
    echo $textarea('description', (string)$formvalues['description']);
    echo html_writer::end_tag('div');
    echo html_writer::start_tag('div', ['class'=>'bms-rt-field']);
    echo html_writer::label(get_string('taskcenter_reviewer','local_kbacompany'),'rt_reviewerid');
    echo html_writer::select($reviewers,'reviewerid',$formvalues['reviewerid'],false,['id'=>'rt_reviewerid','class'=>'form-control']);
    echo html_writer::end_tag('div');
    echo html_writer::start_tag('div', ['class'=>'bms-rt-field']);
    echo html_writer::label(get_string('taskcenter_stream','local_kbacompany'),'rt_stream');
    if (!$editing && $planuuid !== '') {
        // Prevent a planned recurring series from drifting into another task stream.
        echo html_writer::div(s($streams['run']), 'form-control-plaintext');
        echo html_writer::empty_tag('input', ['type'=>'hidden','name'=>'stream','value'=>'run','id'=>'rt_stream']);
    } else {
        echo html_writer::select($streams,'stream',$formvalues['stream'],false,
            ['id'=>'rt_stream','class'=>'form-control']);
    }
    echo html_writer::end_tag('div');
    echo html_writer::start_tag('div', ['class'=>'bms-rt-field']);
    echo html_writer::label(get_string('taskcenter_priority','local_kbacompany'),'rt_priority');
    echo html_writer::select($priorities,'priority',$formvalues['priority'],false,['id'=>'rt_priority','class'=>'form-control']);
    echo html_writer::end_tag('div');
    echo html_writer::start_tag('div', ['class'=>'bms-rt-field']);
    echo html_writer::label(get_string('recurringtask_enddate','local_kbacompany'),'rt_enddate');
    echo $input('enddate',(string)$formvalues['enddate'],'date');
    echo html_writer::end_tag('div');
    echo html_writer::start_tag('div', ['class'=>'bms-rt-field']);
    echo html_writer::label(get_string('recurringtask_dueoffset','local_kbacompany'),'rt_dueoffsetdays');
    echo $input('dueoffsetdays',(string)$formvalues['dueoffsetdays'],'number');
    echo html_writer::end_tag('div');
    echo html_writer::start_tag('div', ['class'=>'bms-rt-field']);
    echo html_writer::label(get_string('taskcenter_planminutes','local_kbacompany'),'rt_planminutes');
    echo $input('planminutes',(string)$formvalues['planminutes'],'number');
    echo html_writer::end_tag('div');
    echo html_writer::end_tag('div');
    echo html_writer::end_tag('details');
    echo html_writer::tag('div', get_string('recurringtask_draft_step','local_kbacompany'),
        ['class'=>'bms-rt-save-note', 'role'=>'note']);
    $cancelparams=array_merge(['uuid'=>$uuid],$originparams);
    if ($editing) { $cancelparams['series']=$series['uuid']; $cancelparams['view']='detail'; }
    else if ($planuuid === '') { $cancelparams['view']='list'; }
    $cancelurl=$planuuid!=='' && !$editing ? new moodle_url('/local/kbacompany/battle_plan.php',
        ['uuid'=>$uuid,'plan'=>$planuuid,'tab'=>'tasks']) : new moodle_url('/local/kbacompany/recurring_tasks.php',$cancelparams);
    $actions=html_writer::link($cancelurl,get_string('recurringtask_cancel','local_kbacompany'),
        ['class'=>'btn btn-outline-secondary']);
    $actions.=html_writer::tag('button',get_string($editing?'recurringtask_save_series':'recurringtask_save_draft','local_kbacompany'),
        ['type'=>'submit','class'=>'btn btn-primary']);
    echo html_writer::div($actions,'bms-rt-form-actions');
    echo html_writer::end_tag('form');
};

// Progressive disclosure: show only cadence-relevant options while keeping a working no-JS form.
// Populate the responsibility selector from the same company-scoped endpoint used by Task CRUD.
// No-JS remains supported: server-side validation requires a position of the chosen assignee.
$positionsurl = new moodle_url('/local/kbacompany/tasks.php', ['uuid'=>$uuid,'view'=>'dutypositions']);
$PAGE->requires->js_init_code("(function(){var a=document.getElementById('rt_assigneeid'),p=document.getElementById('rt_dutypositionuuid');if(!a||!p){return;}var url=" . json_encode($positionsurl->out(false)) . ";a.addEventListener('change',function(){var chosen=a.value;p.disabled=true;fetch(url+'&assigneeid='+encodeURIComponent(chosen),{credentials:'same-origin'}).then(function(r){if(!r.ok){throw new Error('HTTP '+r.status);}return r.json();}).then(function(data){if(a.value!==chosen){return;}p.textContent='';(data.options||[]).forEach(function(item){var opt=document.createElement('option');opt.value=item.value;opt.textContent=item.label;p.appendChild(opt);});p.disabled=false;}).catch(function(){p.disabled=false;p.value='';});});})();");
$PAGE->requires->js_init_code("(function(){var c=document.getElementById('rt_cadence');if(!c){return;}var w=document.getElementById('rt_weekdays_group'),m=document.getElementById('rt_monthweek_group');function update(){var v=c.value;if(w){w.style.display=['daily','weekly','biweekly','twiceweekly','monthly'].indexOf(v)>=0?'':'none';}if(m){m.style.display=v==='monthly'?'':'none';var mw=document.getElementById('rt_monthweek');if(v!=='monthly'&&mw){mw.value='0';}}}c.addEventListener('change',update);update();})();");

$bmsshell = \local_kbacompany\local\bms_page_shell_service::begin(
    $uuid, (int)$USER->id, 'recurring', get_string('recurringtask_title', 'local_kbacompany'));
if ($ownedpresentation) {
    echo html_writer::start_div('bms-tui bms24-task-center bms24-recurring-center');
}
$tr=static function(string $key): string { return get_string($key,'local_kbacompany'); };
$url=static function(array $params=[]) use ($uuid,$originparams,$seriesstatus): moodle_url {
    $filterparams = $seriesstatus !== 'all' ? ['status' => $seriesstatus] : [];
    return new moodle_url('/local/kbacompany/recurring_tasks.php',
        array_merge(['uuid'=>$uuid],$originparams,$filterparams,$params));
};
$button = static function(moodle_url $target, string $label, bool $primary = false) use ($ownedpresentation): string {
    if ($ownedpresentation) {
        return \local_kbacompany\local\bms24_ui_kit_service::button($target, $label,
            $primary ? 'primary' : 'secondary');
    }
    return html_writer::link($target, $label, ['class' => 'btn ' .
        ($primary ? 'btn-primary' : 'btn-outline-secondary') . ' mr-2 mb-3']);
};
$statusbadge = static function(string $status) use ($tr, $ownedpresentation): string {
    $label = $tr('recurringtask_status_' . $status);
    if ($ownedpresentation && in_array($status, recurring_task_service::STATUSES, true)) {
        return \local_kbacompany\local\bms24_ui_kit_service::status($label, $status);
    }
    return s($label);
};
$panel = static function(string $title, string $body, array $attrs = []) use ($ownedpresentation): string {
    if ($ownedpresentation) {
        $html = \local_kbacompany\local\bms24_shell_service::fragment('task_panel', [
            'title' => $title, 'bodyhtml' => $body, 'actionshtml' => '',
        ]);
        return $attrs ? html_writer::tag('div', $html, $attrs) : $html;
    }
    return html_writer::tag('section', html_writer::tag('h3', $title, ['class' => 'h5']) . $body, $attrs);
};
$empty=$tr('recurringtask_empty_value');
$format=static function(string $s) use ($empty): string { return $s!==''?$s:$empty; };
$upcoming=static function(array $series) use ($tr): array {
    return recurring_task_service::upcoming_dates($series,gmdate('Y-m-d'),5);
};
$cadencelabel=static function(array $series) use ($tr,$weekdaylabels): string {
    $description=$tr('recurringtask_cadence_'.$series['cadence']);
    $days=[];
    foreach ($weekdaylabels as $day=>$label) {
        if (((int)$series['weekday_mask'] & (1<<($day-1))) !== 0) { $days[]=$label; }
    }
    if ($days && in_array($series['cadence'],['daily','weekly','biweekly','twiceweekly','monthly'],true)) {
        $description.=' · '.implode(', ',$days);
    }
    if ($series['cadence']==='monthly' && (int)$series['month_week']>0) {
        $description.=' · '.$tr('recurringtask_monthweek_'.(int)$series['month_week']);
    }
    return $description;
};
$pagetitle = $view==='list' ? $tr('recurringtask_registry') :
    ($view==='create' ? $tr('recurringtask_new_series') :
    ($view==='edit' ? $tr('recurringtask_edit') : $tr('recurringtask_detail')));
$statushtml = '';
if ($ownedpresentation && $view === 'detail' && $selectedseries) {
    $pagetitle = (string)$selectedseries['title'];
    $statushtml = $statusbadge((string)$selectedseries['status']);
}
$actionshtml = '';
if ($ownedpresentation) {
    if ($planuuid!=='') {
        $actionshtml .= $button(new moodle_url('/local/kbacompany/battle_plan.php',
            ['uuid'=>$uuid,'plan'=>$planuuid,'tab'=>'tasks']), $tr('recurringtask_back_to_plan'));
    }
    $actionshtml .= $button($url(['view'=>'list']), $tr('recurringtask_back_to_registry'));
    if ($view!=='create') {
        $actionshtml .= $button($url(['view'=>'create','stream'=>$defaultstream]),
            $tr('recurringtask_new_series'), true);
    }
    $actionshtml .= $button(new moodle_url('/local/kbacompany/recurring_task_library.php',
        array_merge(['uuid'=>$uuid],$originparams)), $tr('recurringtask_library_title'));
    echo \local_kbacompany\local\bms24_shell_service::fragment('task_header', [
        'title' => $pagetitle,
        'subtitle' => $tr('recurringtask_series_intro'),
        'statushtml' => $statushtml,
        'actionshtml' => $actionshtml,
    ]);
    if (!empty($error)) {
        echo \local_kbacompany\local\bms24_ui_kit_service::alert($error, 'error');
    }
    if ($planuuid!=='') {
        echo html_writer::tag('p',$tr('battleplan_title').' - '.s($planperiod),['class'=>'text-muted']);
    }
} else {
    echo $OUTPUT->heading($pagetitle);
    echo html_writer::tag('p',$tr('recurringtask_series_intro'),['class'=>'bms-rt-page-intro text-muted']);
    if (!empty($error)) { echo $OUTPUT->notification(s($error),'error'); }
if ($planuuid!=='') {
    echo html_writer::tag('p',$tr('battleplan_title').' · '.s($planperiod),['class'=>'text-muted']);
    echo html_writer::link(new moodle_url('/local/kbacompany/battle_plan.php',
        ['uuid'=>$uuid,'plan'=>$planuuid,'tab'=>'tasks']),$tr('recurringtask_back_to_plan'),
        ['class'=>'btn btn-outline-secondary mr-2 mb-3']);
}
echo html_writer::link($url(['view'=>'list']),$tr('recurringtask_back_to_registry'),
    ['class'=>'btn btn-outline-secondary mr-2 mb-3']);
if ($view!=='create') {
    echo html_writer::link($url(['view'=>'create','stream'=>$defaultstream]),$tr('recurringtask_new_series'),
        ['class'=>'btn btn-primary mr-2 mb-3']);
}
echo html_writer::link(new moodle_url('/local/kbacompany/recurring_task_library.php',
    array_merge(['uuid'=>$uuid],$originparams)),$tr('recurringtask_library_title'),
    ['class'=>'btn btn-outline-secondary mr-2 mb-3']);
}
if ($view==='create') {
    if ($planuuid!=='') {
        echo html_writer::tag('p',$tr('recurringtask_active_plan_warning'),['class'=>'alert alert-info']);
    }
    $renderform(null,$formpayload);
} else if ($view==='list') {
    // Counts reflect only definitions visible under recurring_task_service::context() ACL.
    $statuscounts = ['all' => count($data['series'])];
    foreach (recurring_task_service::STATUSES as $seriesstate) { $statuscounts[$seriesstate] = 0; }
    foreach ($data['series'] as $seriesrow) {
        $key = (string)$seriesrow['status'];
        if (isset($statuscounts[$key])) { $statuscounts[$key]++; }
    }
    $statusoptions = ['all' => $tr('recurringtask_filter_all') . ' (' . $statuscounts['all'] . ')'];
    foreach (recurring_task_service::STATUSES as $seriesstate) {
        $statusoptions[$seriesstate] = $tr('recurringtask_status_' . $seriesstate) .
            ' (' . $statuscounts[$seriesstate] . ')';
    }
    if ($ownedpresentation) {
        $hiddenfields = ['uuid' => $uuid, 'view' => 'list'];
        if ($planuuid !== '') { $hiddenfields['plan'] = $planuuid; }
        echo \local_kbacompany\local\bms24_ui_kit_service::filterbar(
            new moodle_url('/local/kbacompany/recurring_tasks.php'),
            $tr('recurringtask_status_label'),
            [[
                'id' => 'rt_status_filter',
                'name' => 'status',
                'label' => $tr('recurringtask_status_label'),
                'type' => 'select',
                'value' => $seriesstatus,
                'options' => $statusoptions,
            ]],
            $hiddenfields,
            $url(['view' => 'list', 'status' => 'all']),
            $tr('recurringtask_filter_apply'),
            $tr('recurringtask_filter_reset')
        );
    } else {
    $filterhtml = html_writer::start_tag('form', [
        'method' => 'get', 'action' => (new moodle_url('/local/kbacompany/recurring_tasks.php'))->out(false),
        'class' => 'bms-rt-status-filter', 'role' => 'search',
    ]);
    $filterhtml .= html_writer::empty_tag('input', [
        'type' => 'hidden', 'name' => 'uuid', 'value' => $uuid,
    ]);
    $filterhtml .= html_writer::empty_tag('input', [
        'type' => 'hidden', 'name' => 'view', 'value' => 'list',
    ]);
    if ($planuuid !== '') {
        $filterhtml .= html_writer::empty_tag('input', [
            'type' => 'hidden', 'name' => 'plan', 'value' => $planuuid,
        ]);
    }
    $filterhtml .= html_writer::label($tr('recurringtask_status_label'), 'rt_status_filter');
    $filterhtml .= html_writer::select($statusoptions, 'status', $seriesstatus, false,
        ['id' => 'rt_status_filter', 'class' => 'form-control']);
    $filterhtml .= html_writer::tag('button', $tr('recurringtask_filter_apply'),
        ['type' => 'submit', 'class' => 'btn btn-outline-primary']);
    if ($seriesstatus !== 'all') {
        $filterhtml .= html_writer::link($url(['view' => 'list', 'status' => 'all']),
            $tr('recurringtask_filter_reset'), ['class' => 'btn btn-outline-secondary']);
    }
    $filterhtml .= html_writer::end_tag('form');
    echo $filterhtml;
    }
    $filteredseries = array_values(array_filter($data['series'], static function(array $row) use ($seriesstatus): bool {
        return $seriesstatus === 'all' || (string)$row['status'] === $seriesstatus;
    }));
    $table=new html_table();
    $table->attributes['class']=$ownedpresentation ? 'bms24-task-table bms24-recurring-table' :
        'table table-striped bms-rt-registry';
    $table->head=[$tr('taskcenter_task'),$tr('recurringtask_cadence'),$tr('taskcenter_assignee'),
        $tr('recurringtask_next'),$tr('recurringtask_status_label')];
    foreach ($filteredseries as $series) {
        $title=html_writer::link($url(['series'=>$series['uuid'],'view'=>'detail']),s($series['title']));
        $title.=html_writer::tag('div',s($series['product']?:$tr('recurringtask_product_missing')),
            ['class'=>'text-muted small']);
        $dates=$upcoming($series);
        $next=$series['status']==='active' && $dates?date('d.m.Y',strtotime($dates[0])):'—';
        $table->data[]=[$title,s($cadencelabel($series)),s($series['assignee_name']),$next,
            $statusbadge((string)$series['status'])];
    }
    if ($filteredseries) {
        echo html_writer::div(html_writer::table($table),
            $ownedpresentation ? 'bms24-task-table-wrap bms24-recurring-table-wrap' : 'bms-rt-registry-scroll');
    } else {
        $emptytext = $seriesstatus === 'all' ? $tr('recurringtask_empty') :
            $tr('recurringtask_filter_empty');
        $message = s($emptytext);
        if ($seriesstatus !== 'all') {
            $message .= ' ' . html_writer::link($url(['view' => 'list', 'status' => 'all']),
                $tr('recurringtask_filter_reset'));
        }
        echo html_writer::div($message, 'bms-rt-filter-empty alert alert-info');
    }
} else if ($view==='edit') {
    $renderform($selectedseries,$formpayload);
} else if ($view==='detail') {
    $series=$selectedseries;
    if (!$ownedpresentation) {
        echo html_writer::tag('h2',s($series['title']),['class'=>'h4 mt-3']);
        echo html_writer::tag('p',$tr('recurringtask_status_label').': '.
            html_writer::tag('strong',$tr('recurringtask_status_'.$series['status'])),['class'=>'mb-3']);
    }
    $field=static function(string $label,string $val) use($empty): string {
        return html_writer::div(html_writer::tag('dt',s($label)).
            html_writer::tag('dd',nl2br(s($val!==''?$val:$empty))),'bms-rt-detail-field');
    };
    // Library provenance is historical metadata, not user-facing instructions.
    // Keep the raw description stored unchanged, and expose metadata in a separate disclosure.
    list($userdescription, $provenance) = $splitlibrarydescription((string)$series['description'],
        (string)$series['template_key']);
    $formatdate=static function(string $date): string {
        if ($date === '') { return ''; }
        $parsed=\DateTimeImmutable::createFromFormat('!Y-m-d',$date,new \DateTimeZone('UTC'));
        return $parsed && $parsed->format('Y-m-d') === $date ? $parsed->format('d.m.Y') : $date;
    };
    $details='';
    $details.=$field($tr('taskcenter_product'),(string)$series['product']);
    $details.=$field($tr('taskcenter_assignee'),(string)$series['assignee_name']);
    $positionuuid = (string)($series['duty_position_uuid'] ?? '');
    $positionname = '';
    if ($positionuuid !== '') {
        global $DB;
        $position = $DB->get_record('local_kbacompany_position',
            ['companyuuid'=>$uuid,'uuid'=>$positionuuid], 'id,name');
        $positionname = $position ? (string)$position->name : $tr('recurringtask_duty_outdated');
    }
    $details.=$field($tr('recurringtask_duty_type'),
        $positionuuid === '' ? $tr('recurringtask_duty_personal') : $positionname);
    if ($positionuuid !== '') {
        $effective = \local_kbacompany\local\position_coverage_service::effective_executor(
            $uuid, $positionuuid, (int)$series['assignee_id']);
        if ($effective !== (int)$series['assignee_id'] && isset($data['employees'][$effective])) {
            $details.=$field($tr('recurringtask_duty_current'), (string)$data['employees'][$effective]);
        }
    }
    $details.=$field($tr('recurringtask_cadence'),$cadencelabel($series));
    $details.=$field($tr('recurringtask_period_start'),$formatdate((string)$series['start_date']));
    $details.=$field($tr('recurringtask_period_end'),$formatdate((string)$series['end_date']));
    $details.=$field($tr('taskcenter_stream'),$tr('taskcenter_stream_'.$series['stream']));
    $details.=$field($tr('taskcenter_priority'),$tr('taskcenter_priority_'.$series['priority']));
    $details.=$field($tr('taskcenter_reviewer'),(string)$series['reviewer_name']);
    if ($userdescription !== '') { $details.=$field($tr('taskcenter_description'),$userdescription); }
    echo $panel($tr('recurringtask_record_details'),
        html_writer::tag('dl',$details,['class'=>'bms-rt-detail-grid']),
        ['class'=>'bms-rt-detail-panel border rounded p-3 mb-3']);
    if ($caneditselected && ($series['template_key'] !== '' || $provenance !== '')) {
        $technical='';
        if ($series['template_key'] !== '') {
            $technical.=$field($tr('recurringtask_from_library'),(string)$series['template_key']);
        }
        $technical.=$field($tr('recurringtask_revision'),(string)$series['revision']);
        if ($provenance !== '') { $technical.=$field($tr('recurringtask_origin_data'),$provenance); }
        echo html_writer::tag('details',html_writer::tag('summary',$tr('recurringtask_technical_details')).
            html_writer::tag('dl',$technical,['class'=>'bms-rt-detail-grid mt-3']),
            ['class'=>'bms-rt-technical border rounded p-3 mb-3']);
    }
    // A library template may have been classified in a different stream.
    // Warn instead of silently rewriting historical series or plan links.
    if ($planuuid !== '' && (string)$series['stream'] !== 'run') {
        $warning = $tr('recurringtask_stream_mismatch');
        if ($caneditselected && $series['status'] !== 'archived') {
            $warning .= ' ' . html_writer::link($url(['series'=>$series['uuid'],'view'=>'edit']),
                $tr('recurringtask_fill_required_link'));
        }
        echo html_writer::tag('div', $warning, ['class'=>'alert alert-info','role'=>'note']);
    }
    $dates=$upcoming($series);
    echo html_writer::tag('section',html_writer::tag('h3',$tr('recurringtask_next_dates'),['class'=>'h5']).
        ($dates?html_writer::tag('p',s(implode(' · ',array_map(static function($d){
            return date('d.m.Y',strtotime($d));},$dates)))):
            html_writer::tag('p',$tr('recurringtask_next_empty'),['class'=>'text-muted'])),
        ['class'=>'bms-rt-detail-panel border rounded p-3 mb-3']);
    if ($series['status'] !== 'active') {
        echo html_writer::tag('p',$tr('recurringtask_preview_inactive'),['class'=>'text-muted']);
    }
    // Actual generated occurrences, never hypothetical dates. Canonical task rows and
    // their existing Battle Plan references are read under their own ACL contracts.
    $occversion = $requestedoccversion <= (int)$series['revision'] ? $requestedoccversion : 0;
    $journal = recurring_task_service::occurrence_journal($uuid, (int)$USER->id,
        (string)$series['uuid'], $journalpage, 20, $occversion);
    $journalbody = '';
    if ($occversion > 0) {
        $journalbody .= html_writer::div(s($tr('recurringtask_history_version_prefix') . ' ' . $occversion) .
            ' · ' . html_writer::link($url(['series' => $series['uuid'], 'view' => 'detail',
                'occversion' => 0, 'journalpage' => 0]) . '#rt-occurrences',
                $tr('recurringtask_history_show_all_occurrences')), 'alert alert-info py-2');
    }
    if (!$journal['items'] && $journalpage === 0) {
        $journalbody = html_writer::tag('p', $tr('recurringtask_occurrences_empty'), ['class' => 'text-muted']);
    } else {
        $table = new html_table();
        $table->attributes['class'] = $ownedpresentation ? 'bms24-task-table bms-rt-occurrence-table' :
            'table bms-rt-occurrence-table';
        $table->head = [$tr('recurringtask_occurrences_date'), $tr('taskcenter_task'),
            $tr('taskcenter_assignee'), $tr('recurringtask_occurrences_status'),
            $tr('recurringtask_occurrences_plans')];
        foreach ($journal['items'] as $entry) {
            $taskurl = new moodle_url('/local/kbacompany/tasks.php',
                ['uuid' => $uuid, 'task' => $entry['uuid'], 'view' => 'detail']);
            $name = html_writer::link($taskurl, s($entry['title']));
            $name .= html_writer::tag('div', $tr('recurringtask_occurrences_revision') . ': ' .
                (int)$entry['revision'], ['class' => 'small text-muted']);
            $datecell = $formatdate($entry['period_key']);
            if ($entry['due_date'] !== $entry['period_key']) {
                $datecell .= html_writer::tag('div', $tr('recurringtask_occurrences_due') . ': ' .
                    s($formatdate($entry['due_date'])), ['class' => 'small text-muted']);
            }
            $statkey = 'taskcenter_status_' . $entry['status'];
            $status = get_string_manager()->string_exists($statkey, 'local_kbacompany') ?
                $tr($statkey) : s($entry['status']);
            $links = [];
            foreach ($entry['plans'] as $linkedplan) {
                $planurl = new moodle_url('/local/kbacompany/battle_plan.php',
                    ['uuid' => $uuid, 'plan' => $linkedplan['uuid'], 'tab' => 'tasks']);
                $links[] = html_writer::link($planurl, s($formatdate($linkedplan['period_start'])) . ' — ' .
                    s($formatdate($linkedplan['period_end'])));
            }
            $planscell = $links ? implode('<br>', $links) :
                html_writer::tag('span', $tr('recurringtask_occurrences_no_visible_plan'),
                    ['class' => 'text-muted']);
            $table->data[] = [$datecell, $name, s($entry['assignee_name']), $status, $planscell];
        }
        if ($journal['items']) {
            $journalbody .= html_writer::div(html_writer::table($table),
                $ownedpresentation ? 'bms24-task-table-wrap bms-rt-occurrence-scroll' : 'bms-rt-occurrence-scroll');
        } else {
            $journalbody .= html_writer::tag('p', $tr('recurringtask_occurrences_page_empty'),
                ['class' => 'text-muted']);
        }
        $pager = '';
        if ($journalpage > 0) {
            $pager .= html_writer::link($url(['series' => $series['uuid'], 'view' => 'detail',
                'journalpage' => $journalpage - 1, 'occversion' => $occversion]) . '#rt-occurrences',
                $tr('recurringtask_occurrences_prev'), ['class' => 'btn btn-outline-secondary btn-sm']);
        }
        if ($journal['has_next']) {
            $pager .= html_writer::link($url(['series' => $series['uuid'], 'view' => 'detail',
                'journalpage' => $journalpage + 1, 'occversion' => $occversion]) . '#rt-occurrences',
                $tr('recurringtask_occurrences_next'), ['class' => 'btn btn-outline-secondary btn-sm']);
        }
        if ($pager !== '') { $journalbody .= html_writer::div($pager, 'bms-rt-occurrence-pager'); }
    }
    echo $panel($tr('recurringtask_occurrences_heading'),
        html_writer::tag('p', $tr('recurringtask_occurrences_hint'), ['class' => 'text-muted small']) . $journalbody,
        ['id' => 'rt-occurrences', 'class' => 'bms-rt-detail-panel border rounded p-3 mb-3']);
    // Append-only definition history: compare genuine DB revisions, not mutable Task occurrences.
    $history = recurring_task_service::revision_history($uuid, (int)$USER->id,
        (string)$series['uuid'], $historypage);
    $fieldlabels = [
        'title' => $tr('recurringtask_history_field_title'),
        'product' => $tr('recurringtask_history_field_product'),
        'description' => $tr('recurringtask_history_field_description'),
        'assigneeid' => $tr('recurringtask_history_field_assigneeid'),
        'reviewerid' => $tr('recurringtask_history_field_reviewerid'),
        'cadence' => $tr('recurringtask_history_field_cadence'),
        'weekdaymask' => $tr('recurringtask_history_field_weekdaymask'),
        'monthweek' => $tr('recurringtask_history_field_monthweek'),
        'startdate' => $tr('recurringtask_history_field_startdate'),
        'enddate' => $tr('recurringtask_history_field_enddate'),
        'dueoffsetdays' => $tr('recurringtask_history_field_dueoffsetdays'),
        'planminutes' => $tr('recurringtask_history_field_planminutes'),
        'priority' => $tr('recurringtask_history_field_priority'),
        'stream' => $tr('recurringtask_history_field_stream'),
        'status' => $tr('recurringtask_history_field_status'),
    ];
    $renderhistoryvalue = static function(string $field, string $raw) use ($tr, $data, $formatdate): string {
        if ($raw === '') { return $tr('recurringtask_empty_value'); }
        if ($field === 'stream' && in_array($raw, task_service::STREAMS, true)) {
            return $tr('taskcenter_stream_' . $raw);
        }
        if ($field === 'status' && in_array($raw, recurring_task_service::STATUSES, true)) {
            return $tr('recurringtask_status_' . $raw);
        }
        if ($field === 'cadence' && in_array($raw, recurring_task_service::CADENCES, true)) {
            return $tr('recurringtask_cadence_' . $raw);
        }
        if ($field === 'assigneeid' || $field === 'reviewerid') {
            return $data['employees'][(int)$raw] ?? $tr('recurringtask_history_actor_unknown');
        }
        if ($field === 'startdate' || $field === 'enddate') { return $formatdate($raw); }
        if ($field === 'weekdaymask') {
            $mask = (int)$raw;
            $labels = [];
            for ($i = 1; $i <= 7; $i++) {
                if (($mask & (1 << ($i - 1))) !== 0) {
                    $labels[] = $tr('recurringtask_weekday_' . $i);
                }
            }
            return $labels ? implode(', ', $labels) : $tr('recurringtask_empty_value');
        }
        if ($field === 'monthweek' && (int)$raw >= 0 && (int)$raw <= 5) {
            return (int)$raw === 0 ? $tr('recurringtask_monthweek_none') :
                $tr('recurringtask_monthweek_' . (int)$raw);
        }
        $short = \core_text::substr($raw, 0, 140);
        return $short . (\core_text::strlen($raw) > 140 ? '…' : '');
    };
    $historybody = '';
    foreach ($history['items'] as $revision) {
        $author = $revision['changed_by_name'] !== '' ? $revision['changed_by_name'] :
            $tr('recurringtask_history_actor_unknown');
        $when = (int)$revision['changed_at'] > 0 ? userdate((int)$revision['changed_at'], '%d.%m.%Y %H:%M') :
            $tr('recurringtask_history_date_unknown');
        $heading = $tr('recurringtask_history_version_prefix') . ' ' . (int)$revision['revision'];
        $heading .= ' · ' . ($revision['is_initial'] ? $tr('recurringtask_history_created') :
            $tr('recurringtask_history_changed'));
        $changes = '';
        if (!$revision['is_initial'] && !$revision['changes']) {
            $changes = html_writer::tag('p', $tr('recurringtask_history_no_field_change'),
                ['class' => 'small text-muted']);
        }
        if ($revision['changes']) {
            $items = '';
            foreach ($revision['changes'] as $change) {
                $label = $fieldlabels[$change['field']] ?? $change['field'];
                $value = !empty($change['redacted']) ? $tr('recurringtask_history_description_changed') :
                    $renderhistoryvalue($change['field'], (string)$change['before']) . ' → ' .
                    $renderhistoryvalue($change['field'], (string)$change['after']);
                $items .= html_writer::tag('li', html_writer::tag('strong', s($label) . ': ') . s($value));
            }
            $changes = html_writer::tag('ul', $items, ['class' => 'bms-rt-history-changes']);
        }
        $versionurl = $url(['series' => $series['uuid'], 'view' => 'detail',
            'historypage' => $historypage, 'journalpage' => 0, 'occversion' => (int)$revision['revision']]) .
            '#rt-occurrences';
        $versionlink = html_writer::link($versionurl, $tr('recurringtask_history_view_tasks'),
            ['class' => 'small']);
        $historybody .= html_writer::tag('article',
            html_writer::tag('div', html_writer::tag('strong', s($heading)), ['class' => 'mb-1']) .
            html_writer::tag('div', s($when) . ' · ' . s($author), ['class' => 'small text-muted mb-2']) .
            $changes . $versionlink,
            ['class' => 'bms-rt-history-entry']);
    }
    $historypager = '';
    if ($historypage > 0) {
        $historypager .= html_writer::link($url(['series' => $series['uuid'], 'view' => 'detail',
            'historypage' => $historypage - 1, 'occversion' => $occversion]) . '#rt-history',
            $tr('recurringtask_occurrences_prev'), ['class' => 'btn btn-outline-secondary btn-sm']);
    }
    if ($history['has_next']) {
        $historypager .= html_writer::link($url(['series' => $series['uuid'], 'view' => 'detail',
            'historypage' => $historypage + 1, 'occversion' => $occversion]) . '#rt-history',
            $tr('recurringtask_occurrences_next'), ['class' => 'btn btn-outline-secondary btn-sm']);
    }
    if ($historypager !== '') { $historybody .= html_writer::div($historypager, 'bms-rt-history-pager'); }
    echo $panel($tr('recurringtask_history_title'),
        html_writer::tag('p', $tr('recurringtask_history_hint'), ['class' => 'small text-muted']) . $historybody,
        ['id' => 'rt-history', 'class' => 'bms-rt-detail-panel border rounded p-3 mb-3']);
    if ($caneditselected) {
        $missing=[];
        if (trim((string)$series['product']) === '') { $missing[]=$tr('taskcenter_product'); }
        if ((int)$series['assignee_id'] <= 0 || trim((string)$series['assignee_name']) === '') {
            $missing[]=$tr('taskcenter_assignee');
        }
        if (trim((string)$series['start_date']) === '') { $missing[]=$tr('recurringtask_startdate'); }
        if ((string)$series['cadence'] === 'review') { $missing[]=$tr('recurringtask_cadence'); }
        $editurl=$url(['series'=>$series['uuid'],'view'=>'edit']);
        // Use the same activation prerequisites as recurring_task_service::validate_definition().
        // The server performs the authoritative check again on POST.
        if ($missing && in_array($series['status'],['draft','paused'],true)) {
            echo html_writer::tag('div',
                html_writer::tag('strong',$tr('recurringtask_activation_blocked_heading')).' '.
                s(implode(', ',$missing)).' '.
                html_writer::link($editurl,$tr('recurringtask_fill_required_link')),
                ['class'=>'alert alert-warning bms-rt-readiness','role'=>'status']);
        }
        if ($series['status'] !== 'archived') {
            echo html_writer::start_tag('div',['class'=>'bms-rt-detail-actions mb-4']);
            // Secondary navigation, distinct from state-changing POST actions.
            echo html_writer::link($editurl,$missing?$tr('recurringtask_fill_required_link'):$tr('recurringtask_edit_action'),
                ['class'=>($missing?'btn btn-primary':'btn btn-outline-secondary').' bms-rt-edit-link']);
            if (in_array($series['status'],['draft','paused'],true)) {
                if (!$missing) {
                    echo html_writer::start_tag('form',['method'=>'post','class'=>'bms-rt-action-form']);
                    echo $hidden('activate',$series['uuid'],$series['revision']);
                    echo html_writer::tag('button',$tr('recurringtask_activate'),
                        ['type'=>'submit','class'=>'btn btn-primary']);
                    echo html_writer::end_tag('form');
                } else {
                    echo html_writer::tag('button',$tr('recurringtask_activate'),
                        ['type'=>'button','class'=>'btn btn-outline-secondary','disabled'=>'disabled',
                         'title'=>$tr('recurringtask_activation_blocked_heading')]);
                }
            } else if ($series['status'] === 'active') {
                echo html_writer::start_tag('form',['method'=>'post','class'=>'bms-rt-action-form']);
                echo $hidden('pause',$series['uuid'],$series['revision']);
                echo html_writer::tag('button',$tr('recurringtask_pause'),
                    ['type'=>'submit','class'=>'btn btn-outline-secondary']);
                echo html_writer::end_tag('form');
                $due=recurring_task_service::is_due_on((object)[
                    'status'=>'active','startdate'=>$series['start_date'],'enddate'=>$series['end_date'],
                    'cadence'=>$series['cadence'],'weekdaymask'=>$series['weekday_mask'],
                    'monthweek'=>$series['month_week']],gmdate('Y-m-d'));
                if ($due) {
                    echo html_writer::start_tag('form',['method'=>'post','class'=>'bms-rt-action-form']);
                    echo $hidden('generate',$series['uuid'],$series['revision']);
                    echo html_writer::tag('button',$tr('recurringtask_generate_today'),
                        ['type'=>'submit','class'=>'btn btn-outline-primary']);
                    echo html_writer::end_tag('form');
                }
            }
            // Destructive action is deliberately hidden behind a disclosure and confirmation checkbox.
            echo html_writer::start_tag('details',['class'=>'bms-rt-more-actions']);
            echo html_writer::tag('summary',$tr('recurringtask_more_actions'));
            echo html_writer::start_tag('form',['method'=>'post','class'=>'bms-rt-archive-form']);
            echo $hidden('archive',$series['uuid'],$series['revision']);
            echo html_writer::tag('p',$tr('recurringtask_archive_warning'),['class'=>'text-muted mb-2']);
            echo html_writer::tag('label',html_writer::empty_tag('input',[
                'type'=>'checkbox','name'=>'archiveconfirm','value'=>'1','required'=>'required']).' '.
                s($tr('recurringtask_archive_checkbox')),['class'=>'bms-rt-archive-confirm']);
            echo html_writer::tag('button',$tr('recurringtask_archive'),
                ['type'=>'submit','class'=>'btn btn-outline-danger mt-2']);
            echo html_writer::end_tag('form');
            echo html_writer::end_tag('details');
            echo html_writer::end_tag('div');
        }
    }
}
if ($ownedpresentation) {
    echo html_writer::end_div();
}
\local_kbacompany\local\bms_page_shell_service::finish($bmsshell);
