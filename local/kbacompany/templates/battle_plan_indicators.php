<?php
// Existing canonical indicator and pinned legacy quota UI, isolated from the plan record forms.
defined('MOODLE_INTERNAL') || die();
use local_kbacompany\local\battle_plan_template_service;
use local_kbacompany\local\task_service;
use local_kbacompany\local\battle_plan_ui_service as ui;
use local_kbacompany\local\indicator_registry_service;
use local_kbacompany\local\indicator_condition_service;
use local_kbacompany\local\indicator_condition_execution_service;
// Canonical position indicators for the selected Battle Plan week.
$canmanageindicators = indicator_registry_service::can_manage($uuid, (int)$USER->id);
$cansetmetricplan = indicator_registry_service::can_manage($uuid, (int)$USER->id) && $plan['status'] !== 'closed';
$canexecuteindicator = $canmanageindicators && $plan['status'] === 'draft' &&
    ($data['can_manage'] || (int)$plan['user_id'] === (int)$USER->id);
echo $OUTPUT->heading(get_string('battleplan_position_indicators','local_kbacompany'),4);
if (empty($data['position_indicators'])) {
    echo html_writer::tag('p',get_string('battleplan_position_indicators_empty','local_kbacompany'),['class'=>'text-muted']);
} else {
    $statuscounts = ['ok'=>0,'risk'=>0,'critical'=>0,'nodata'=>0];
    foreach ($data['position_indicators'] as $metricrow) {
        $statuscode = (string)($metricrow['performance']['status'] ?? 'nodata');
        if (!isset($statuscounts[$statuscode])) { $statuscode = 'nodata'; }
        $statuscounts[$statuscode]++;
    }
    $summary = '';
    foreach ([
        'ok' => 'metric_status_ok',
        'risk' => 'metric_status_risk',
        'critical' => 'metric_status_critical',
        'nodata' => 'bpu_metric_unassessed',
    ] as $statuscode => $langkey) {
        $summary .= html_writer::div(
            html_writer::tag('strong', (string)$statuscounts[$statuscode]) .
            html_writer::tag('small', get_string($langkey, 'local_kbacompany')),
            'kba-indicator-kpi');
    }
    echo html_writer::div($summary, 'kba-indicator-kpis kba-indicator-kpis--four mb-3');
    echo html_writer::tag('p', get_string('battleplan_metric_status_rule','local_kbacompany'), ['class'=>'text-muted small']);

    $cards = '';
    foreach ($data['position_indicators'] as $m) {
        $metriclabel = s($m['code'].' — '.$m['name']);
        $metriclabel = html_writer::link($m['indicator_url'], $metriclabel);
        if (!empty($m['daily_aggregate'])) {
            $aggregatehelp = get_string('battleplan_daily_aggregate','local_kbacompany',(object)[
                'factdays'=>(int)$m['fact_days'], 'plandays'=>(int)$m['plan_days']]);
            $aggregateshort = get_string('battleplan_fact','local_kbacompany') . ': ' . (int)$m['fact_days'] .
                ' / ' . get_string('metric_period_plan','local_kbacompany') . ': ' . (int)$m['plan_days'];
            $metriclabel .= html_writer::tag('small', s($aggregateshort), [
                'class'=>'d-block text-muted bms-bpu-metric-aggregate-short',
                'title'=>$aggregatehelp,
            ]);
        }
        if (!empty($m['critical'])) {
            $metriclabel .= ' ' . html_writer::tag('span', get_string('bpu_metric_critical_priority','local_kbacompany'), ['class'=>'badge badge-danger']);
        }
        $periodplan = $m['current_plan'] === null ? '—' : format_float((float)$m['current_plan'], 2) . ' ' . s($m['unit']);
        if (!empty($m['daily_aggregate']) && $m['current_plan'] !== null) {
            $periodplan .= html_writer::tag('small', get_string('bpu_metric_plan_days', 'local_kbacompany',
                (int)$m['plan_days']), ['class'=>'d-block text-muted']);
        }
        if ($cansetmetricplan) {
            $planopen = (string)optional_param('target', '', PARAM_RAW_TRIMMED) === (string)$m['bind_uuid'] ||
                ($action === 'indicatorplan' && (string)($values['binduuid'] ?? '') === (string)$m['bind_uuid']);
            $planbody = html_writer::start_tag('form', ['method'=>'post','class'=>'bms-bpu-metric-target-form']);
            $planbody .= $hidden('indicatorplan', $plan['uuid']);
            $planbody .= html_writer::empty_tag('input', ['type'=>'hidden','name'=>'binduuid','value'=>$m['bind_uuid']]);
            $planbody .= html_writer::tag('p', get_string('bpu_metric_target_intro', 'local_kbacompany'),
                ['class'=>'small text-muted mb-1']);
            $planbody .= html_writer::tag('p', get_string('bpu_metric_target_shared', 'local_kbacompany'),
                ['class'=>'small text-muted mb-1']);
            $existingplans = [];
            foreach (($m['daily_days'] ?? []) as $dayrow) { $existingplans[$dayrow['period']] = $dayrow['plan']; }
            $daycount = !empty($m['daily_aggregate']) ? 7 : 1;
            $dt = new DateTimeImmutable($plan['period_start'], new DateTimeZone('UTC'));
            $postdays = $metricvalue('indicatorplan','binduuid',(string)$m['bind_uuid'],'dayplan', []);
            if (!is_array($postdays)) { $postdays = []; }
            for ($d = 0; $d < $daycount; $d++) {
                $day = $dt->modify('+' . $d . ' days')->format('Y-m-d');
                $default = isset($existingplans[$day]) ? (string)$existingplans[$day] : '';
                if ($daycount === 1) { $default = $m['current_plan'] === null ? '' : (string)$m['current_plan']; }
                $inputvalue = array_key_exists($day, $postdays) ? $postdays[$day] : $default;
                $id = 'bpu-target-' . preg_replace('/[^a-zA-Z0-9_-]/', '-', (string)$m['bind_uuid'] . '-' . $day);
                $planbody .= html_writer::start_div('bms-bpu-metric-target-day');
                $planbody .= html_writer::tag('label', s($day), ['for'=>$id]);
                $planbody .= html_writer::empty_tag('input', [
                    'id'=>$id, 'name'=>'dayplan['.$day.']', 'type'=>'text', 'inputmode'=>'decimal',
                    'class'=>'form-control form-control-sm', 'value'=>$inputvalue,
                    'placeholder'=>get_string('bpu_metric_target_empty', 'local_kbacompany')]);
                $planbody .= html_writer::end_div();
            }
            $planbody .= ui::control('reason', [
                'label'=>'bpu_metric_target_reason','type'=>'textarea',
                'help'=>'bpu_metric_target_reason_help','suffix'=>'target-reason-'.$m['bind_uuid']],
                $metricvalue('indicatorplan','binduuid',(string)$m['bind_uuid'],'reason',''));
            $planbody .= html_writer::tag('button', get_string('bpu_metric_save_target','local_kbacompany'),
                ['type'=>'submit','class'=>'btn btn-primary btn-sm']);
            $planbody .= html_writer::end_tag('form');
            $planactionlabel = $m['current_plan'] === null ? get_string('bpu_metric_set_target', 'local_kbacompany') :
                get_string('design_edit','local_kbacompany') . ' ' .
                    core_text::strtolower(get_string('metric_period_plan','local_kbacompany'));
            $periodplan .= html_writer::tag('details',
                html_writer::tag('summary', s($planactionlabel),
                    ['class'=>'btn btn-sm btn-outline-secondary']) . $planbody,
                ['class'=>'bms-bpu-metric-target-inline','open'=>$planopen?'open':null]);
        }
        $norm = $m['norm_label'] === '' ? '—' : s($m['norm_label']) . ' ' . s($m['unit']);
        $performance = $m['performance'] ?? ['status'=>'nodata','variance'=>null,'variance_percent'=>null,'source'=>''];
        $variance = $performance['variance'] === null ? '—' :
            (($performance['variance'] > 0 ? '+' : '') . format_float((float)$performance['variance'], 2) . ' ' . s($m['unit']));
        if ($performance['variance_percent'] !== null) {
            $variance .= html_writer::tag('small', ' (' . ($performance['variance_percent'] > 0 ? '+' : '') .
                format_float((float)$performance['variance_percent'], 1) . '%)', ['class'=>'d-block text-muted']);
        }
        if (!empty($m['daily_aggregate']) && !empty($m['daily_comparable'])) {
            $variance .= html_writer::tag('small', get_string('bpu_metric_matched_comparison', 'local_kbacompany',
                (object)['days'=>(int)$m['comparison_days'],
                    'fact'=>format_float((float)$m['comparison_fact'], 2),
                    'plan'=>format_float((float)$m['comparison_plan'], 2),
                    'unit'=>$m['unit']]), ['class'=>'d-block text-muted']);
        }
        $statuscode = (string)($performance['status'] ?? 'nodata');
        $statusclass = $statuscode === 'ok' ? 'success' : ($statuscode === 'risk' ? 'warning' : ($statuscode === 'critical' ? 'danger' : 'secondary'));
        $conditionkey = $m['condition'] !== '' ? 'indicator_condition_'.$m['condition'] : 'indicator_condition_undetermined';
        $statuslabel = get_string('metric_status_'.$statuscode, 'local_kbacompany');
        if ($statuscode === 'nodata') {
            if ($m['current_fact'] === null) {
                $statuslabel = get_string('bpu_metric_no_fact', 'local_kbacompany');
            } else if ($m['current_plan'] === null && $m['norm_label'] === '') {
                $statuslabel = get_string('bpu_metric_no_target', 'local_kbacompany');
            } else if (!empty($m['daily_aggregate']) && empty($m['daily_comparable'])) {
                $statuslabel = get_string('bpu_metric_partial', 'local_kbacompany');
            }
        }
        $condition = html_writer::tag('span', s($statuslabel), ['class'=>'badge badge-'.$statusclass]);
        if (!empty($m['daily_aggregate']) && !empty($m['daily_comparable'])) {
            $condition .= html_writer::tag('small', get_string('bpu_metric_interim_status',
                'local_kbacompany'), ['class'=>'d-block text-muted']);
        }
        $condition .= html_writer::tag('small', get_string($conditionkey, 'local_kbacompany'), ['class'=>'d-block mt-1']);
        if (!empty($m['condition_confirmed'])) {
            $condition .= ' ' . html_writer::tag('span', get_string('battleplan_indicator_confirmed','local_kbacompany'), ['class'=>'badge badge-info']);
        }
        if ($m['condition_period'] !== '' && $m['condition_period'] !== $plan['period_start']) {
            $condition .= html_writer::tag('small', get_string('battleplan_indicator_state_period','local_kbacompany', $m['condition_period']), ['class'=>'d-block text-muted']);
        }
        if (!empty($m['task']['visible'])) {
            $tasklabel = html_writer::link($m['task']['url'], s($m['task']['title']));
            if (!empty($m['task']['in_plan'])) {
                $tasklabel .= ' ' . html_writer::tag('span', get_string('battleplan_indicator_task_in_plan','local_kbacompany'), ['class'=>'badge badge-success']);
            }
            $condition .= html_writer::tag('small', get_string('battleplan_indicator_task','local_kbacompany').': '.$tasklabel, ['class'=>'d-block mt-1']);
            $condition .= html_writer::tag('small', get_string('battleplan_indicator_task_status','local_kbacompany') . ': ' .
                get_string('taskcenter_status_' . $m['task']['status'],'local_kbacompany'), ['class'=>'d-block text-muted']);
            if ($m['task']['checklist_percent'] !== null) {
                $condition .= html_writer::tag('small', get_string('battleplan_indicator_task_checklist','local_kbacompany', (object)[
                    'done'=>$m['task']['checklist_done'], 'total'=>$m['task']['checklist_total'],
                    'percent'=>format_float((float)$m['task']['checklist_percent'], 0),
                ]), ['class'=>'d-block text-muted']);
            }
            if ((string)$m['task']['result'] !== '') {
                $condition .= html_writer::tag('small', get_string('battleplan_indicator_task_result','local_kbacompany') . ': ' .
                    s($m['task']['result']), ['class'=>'d-block text-muted']);
            }
        }
        $previousaction = $m['previous_action'] ?? ['exists'=>false,'task'=>['exists'=>false,'visible'=>false],
            'observation'=>['quality'=>'insufficient']];
        if (!empty($previousaction['exists']) && !empty($previousaction['task']['exists'])) {
            $control = '';
            if (!empty($previousaction['task']['visible'])) {
                $tasklabel = html_writer::link($previousaction['task']['url'], s($previousaction['task']['title']));
                $control .= html_writer::tag('strong', get_string('battleplan_indicator_previous_action','local_kbacompany'));
                $control .= html_writer::tag('span', $tasklabel, ['class'=>'d-block']);
                $control .= html_writer::tag('span', get_string('battleplan_indicator_task_status','local_kbacompany') . ': ' .
                    get_string('taskcenter_status_' . $previousaction['task']['status'],'local_kbacompany'), ['class'=>'d-block']);
                if ($previousaction['task']['checklist_percent'] !== null) {
                    $control .= html_writer::tag('span', get_string('battleplan_indicator_task_checklist','local_kbacompany', (object)[
                        'done'=>$previousaction['task']['checklist_done'], 'total'=>$previousaction['task']['checklist_total'],
                        'percent'=>format_float((float)$previousaction['task']['checklist_percent'], 0),
                    ]), ['class'=>'d-block']);
                }
                if ((string)$previousaction['task']['result'] !== '') {
                    $control .= html_writer::tag('span', get_string('battleplan_indicator_task_result','local_kbacompany') . ': ' .
                        s($previousaction['task']['result']), ['class'=>'d-block']);
                }
                $observation = $previousaction['observation'];
                if (($observation['baseline_fact'] ?? null) !== null && ($observation['followup_fact'] ?? null) !== null) {
                    $qualitykey = 'battleplan_indicator_after_action_' . (string)$observation['quality'];
                    $control .= html_writer::tag('span', get_string('battleplan_indicator_after_action','local_kbacompany', (object)[
                        'before'=>format_float((float)$observation['baseline_fact'],2) . ' ' . $m['unit'],
                        'after'=>format_float((float)$observation['followup_fact'],2) . ' ' . $m['unit'],
                        'assessment'=>get_string($qualitykey,'local_kbacompany'),
                    ]), ['class'=>'d-block mt-1']);
                    $control .= html_writer::tag('span', get_string('battleplan_indicator_after_action_note','local_kbacompany'),
                        ['class'=>'d-block text-muted']);
                } else {
                    $control .= html_writer::tag('span', get_string('battleplan_indicator_after_action_pending','local_kbacompany'),
                        ['class'=>'d-block text-muted mt-1']);
                }
            } else {
                $control = html_writer::tag('strong', get_string('battleplan_indicator_previous_action','local_kbacompany')) .
                    html_writer::tag('span', get_string('battleplan_indicator_action_task_hidden','local_kbacompany'),
                        ['class'=>'d-block text-muted']);
            }
            $condition .= html_writer::tag('div', $control, ['class'=>'border-top mt-2 pt-2 small']);
        }
        if ($canexecuteindicator && empty($m['compatibility']) && empty($m['daily_aggregate'])) {
            if (!empty($m['task']['visible']) && empty($m['task']['in_plan'])) {
                $linkform = html_writer::start_tag('form', ['method'=>'post','class'=>'mt-2']);
                $linkform .= $hidden('indicatorlinktask', $plan['uuid']);
                $linkform .= html_writer::empty_tag('input', ['type'=>'hidden','name'=>'taskuuid','value'=>$m['task']['uuid']]);
                $linkform .= html_writer::tag('button', get_string('battleplan_indicator_action_link','local_kbacompany'),
                    ['type'=>'submit','class'=>'btn btn-sm btn-outline-secondary']);
                $linkform .= html_writer::end_tag('form');
                $condition .= $linkform;
            } else if (!empty($m['task']['exists']) && empty($m['task']['visible'])) {
                $condition .= html_writer::tag('small', get_string('battleplan_indicator_action_task_hidden','local_kbacompany'),
                    ['class'=>'d-block mt-2 text-muted']);
            } else if (empty($m['task']['exists'])) {
                $conditionoptions = ['' => get_string('indicator_condition_choose','local_kbacompany')];
                foreach (indicator_condition_service::CONDITIONS as $conditioncode) {
                    $conditionoptions[$conditioncode] = get_string('indicator_condition_'.$conditioncode,'local_kbacompany');
                }
                $selectedcondition = (string)$m['condition'];
                $defaulttitle = get_string('battleplan_indicator_action_default_title','local_kbacompany',
                    (object)['code'=>$m['code'],'name'=>$m['name']]);
                $defaultproduct = get_string('battleplan_indicator_action_default_product','local_kbacompany',
                    (object)['code'=>$m['code'],'name'=>$m['name']]);
                $actionbody = html_writer::tag('p', get_string('battleplan_indicator_action_intro','local_kbacompany'),
                    ['class'=>'small text-muted mb-2']);
                $actionbody .= html_writer::start_tag('form', ['method'=>'post','class'=>'border rounded p-2']);
                $actionbody .= $hidden('indicatoraction', $plan['uuid']);
                $actionbody .= html_writer::empty_tag('input', ['type'=>'hidden','name'=>'binduuid','value'=>$m['bind_uuid']]);
                $priorityoptions = [];
                foreach (task_service::PRIORITIES as $priority) {
                    $priorityoptions[$priority] = get_string('taskcenter_priority_'.$priority,'local_kbacompany');
                }
                $fields = [
                    'condition'=>['label'=>'battleplan_condition','options'=>$conditionoptions,'required'=>true],
                    'comment'=>['label'=>'indicator_condition_manager_comment','type'=>'textarea','help'=>'indicator_condition_context_comment_help'],
                    'title'=>['label'=>'taskcenter_task','required'=>true],
                    'product'=>['label'=>'taskcenter_product','type'=>'textarea','required'=>true],
                    'description'=>['label'=>'taskcenter_description','type'=>'textarea'],
                    'priority'=>['label'=>'taskcenter_priority','options'=>$priorityoptions],
                    'duedate'=>['label'=>'taskcenter_duedate','type'=>'date'],
                    'planminutes'=>['label'=>'taskcenter_planminutes','type'=>'number','min'=>0],
                ];
                $defaults = ['condition'=>$selectedcondition,'comment'=>$m['decision_comment']??'',
                    'title'=>$defaulttitle,'product'=>$defaultproduct,'description'=>'','priority'=>'normal',
                    'duedate'=>$plan['period_end'],'planminutes'=>0];
                foreach ($fields as $name=>$spec) {
                    $spec['suffix']='action-'.(string)$m['bind_uuid'];
                    $actionbody .= ui::control($name,$spec,$metricvalue('indicatoraction','binduuid',
                        (string)$m['bind_uuid'],$name,$defaults[$name]));
                }
                if ($selectedcondition !== '') {
                    $recommendations = [];
                    foreach (indicator_condition_service::action_keys($selectedcondition) as $key) {
                        $recommendations[] = html_writer::tag('li', get_string($key,'local_kbacompany'));
                    }
                    if ($recommendations) {
                        $actionbody .= html_writer::tag('div', get_string('battleplan_indicator_action_recommendation','local_kbacompany') .
                            html_writer::tag('ul', implode('', $recommendations), ['class'=>'mb-2 pl-3']), ['class'=>'small text-muted']);
                    }
                }
                $actionbody .= html_writer::tag('button', get_string('battleplan_indicator_action_submit','local_kbacompany'),
                    ['type'=>'submit','class'=>'btn btn-sm btn-primary']);
                $actionbody .= html_writer::end_tag('form');
                $condition .= html_writer::tag('details',
                    html_writer::tag('summary', get_string('battleplan_indicator_action_manage','local_kbacompany')) . $actionbody,
                    ['class'=>'mt-2','open'=>$action==='indicatoraction' && ($values['binduuid']??'')===$m['bind_uuid']?'open':null]);
            }
        }
        $factdisplay = $m['current_fact'] === null ? '—' : format_float((float)$m['current_fact'], 2) . ' ' . s($m['unit']);
        $factstate = $m['current_fact'] === null ? get_string('bpu_metric_no_fact','local_kbacompany') :
            get_string('battleplan_fact','local_kbacompany');
        $factstateclass = $m['current_fact'] === null ? 'is-empty' : 'is-recorded';
        $factform = html_writer::start_div('bms-bpu-metric-fact-summary ' . $factstateclass);
        $factform .= html_writer::tag('span', s($factstate), ['class'=>'bms-bpu-metric-fact-state']);
        $factform .= html_writer::tag('strong', s($factdisplay), ['class'=>'bms-bpu-metric-fact-value']);
        if (!empty($m['daily_aggregate'])) {
            $aggregatehelp = get_string('battleplan_daily_aggregate', 'local_kbacompany', (object)[
                'factdays'=>(int)$m['fact_days'], 'plandays'=>(int)$m['plan_days']]);
            $factdaysshort = get_string('battleplan_fact','local_kbacompany') . ': ' . (int)$m['fact_days'] .
                ' / ' . (int)$m['plan_days'];
            $factform .= html_writer::tag('small', s($factdaysshort), [
                'class'=>'bms-bpu-metric-fact-days', 'title'=>$aggregatehelp,
            ]);
        }
        $factform .= html_writer::end_div();
        if ($canwritefact) {
            $chosen = (string)optional_param('fact', '', PARAM_RAW_TRIMMED) === (string)$m['bind_uuid'] ||
                ($action === 'indicatorfact' && (string)($values['binduuid'] ?? '') === (string)$m['bind_uuid']);
            $today = userdate(time(), '%Y-%m-%d');
            $defaultdate = $today < $plan['period_start'] ? $plan['period_start'] :
                ($today > $plan['period_end'] ? $plan['period_end'] : $today);
            $querydate = optional_param('factdate', '', PARAM_RAW_TRIMMED);
            if ($querydate >= $plan['period_start'] && $querydate <= $plan['period_end'] &&
                    preg_match('/^\d{4}-\d{2}-\d{2}$/D', $querydate)) {
                $defaultdate = $querydate;
            }
            $isday = !empty($m['daily_aggregate']);
            $defaultdate = $isday ? $defaultdate : $plan['period_start'];
            $entrydate = $metricvalue('indicatorfact','binduuid',(string)$m['bind_uuid'], 'factdate', $defaultdate);
            $factbody = html_writer::start_tag('form', ['method'=>'post','class'=>'bms-bpu-metric-fact-form']);
            $factbody .= html_writer::tag('div',
                html_writer::tag('strong', get_string('indicator_crud_record_fact','local_kbacompany')) .
                html_writer::tag('span', s($m['code'].' — '.$m['name']), ['class'=>'d-block']) .
                html_writer::tag('small',
                    s($plan['period_start'].' — '.$plan['period_end']) . ' · ' .
                    get_string('statunit','local_kbacompany') . ': ' . s($m['unit']),
                    ['class'=>'d-block text-muted']),
                ['class'=>'bms-bpu-metric-fact-context']);
            $factbody .= $hidden('indicatorfact', $plan['uuid']);
            $factbody .= html_writer::empty_tag('input', ['type'=>'hidden','name'=>'binduuid','value'=>$m['bind_uuid']]);
            if ($isday) {
                $factbody .= ui::control('factdate', [
                    'label'=>'bpu_metric_fact_date','type'=>'date','required'=>true,
                    'min'=>$plan['period_start'],'max'=>$plan['period_end'],
                    'suffix'=>'fact-date-'.$m['bind_uuid']], $entrydate);
            } else {
                $factbody .= html_writer::empty_tag('input', [
                    'type'=>'hidden','name'=>'factdate','value'=>$plan['period_start']]);
            }
            $factbody .= ui::control('amount', [
                'label'=>'battleplan_fact', 'required'=>true,'suffix'=>'fact-amount-'.$m['bind_uuid']],
                $metricvalue('indicatorfact','binduuid',(string)$m['bind_uuid'],'amount',''));
            $factbody .= ui::control('reason', [
                'label'=>'bpu_metric_fact_comment','type'=>'textarea',
                'help'=>'bpu_metric_fact_comment_help','suffix'=>'fact-reason-'.$m['bind_uuid']],
                $metricvalue('indicatorfact','binduuid',(string)$m['bind_uuid'],'reason',''));
            $factbody .= html_writer::tag('button', get_string('bpu_metric_save_fact','local_kbacompany'),
                ['type'=>'submit','class'=>'btn btn-primary btn-sm']);
            $factbody .= html_writer::end_tag('form');
            if ($isday && !empty($m['daily_days'])) {
                $historyitems = '';
                foreach ($m['daily_days'] as $day) {
                    if ($day['fact'] === null) { continue; }
                    $link = ui::url($uuid, ['plan'=>$plan['uuid'],'tab'=>'indicators',
                        'fact'=>$m['bind_uuid'],'factdate'=>$day['period']]);
                    $historyitems .= html_writer::tag('li', s($day['period']) . ' — ' .
                        format_float((float)$day['fact'], 2) . ' ' . s($m['unit']) . ' · ' .
                        html_writer::link($link, get_string('bpu_metric_correct_fact','local_kbacompany')));
                }
                if ($historyitems !== '') {
                    $factbody .= html_writer::tag('div',
                        html_writer::tag('strong', get_string('bpu_metric_history','local_kbacompany')) .
                        html_writer::tag('ul', $historyitems, ['class'=>'mb-0 pl-3']),
                        ['class'=>'bms-bpu-metric-history small']);
                }
            }
            $factactionlabel = $m['current_fact'] === null ? get_string('indicator_crud_record_fact','local_kbacompany') :
                get_string('design_edit','local_kbacompany') . ' ' .
                    core_text::strtolower(get_string('battleplan_fact','local_kbacompany'));
            $factform .= html_writer::tag('details',
                html_writer::tag('summary', s($factactionlabel),
                    ['class'=>'btn btn-sm btn-outline-primary']) . $factbody,
                ['class'=>'bms-bpu-metric-inline','open'=>$chosen?'open':null]);
        }
        $movement = (string)($m['trend']['movement'] ?? 'insufficient');
        $quality = (string)($m['trend']['quality'] ?? 'insufficient');
        $movementkey = 'indicator_trend_'.$movement;
        $qualitykey = 'indicator_trend_'.$quality;
        $trend = get_string($movementkey, 'local_kbacompany');
        if ($quality !== 'insufficient') { $trend .= ' · ' . get_string($qualitykey, 'local_kbacompany'); }
        $trendicon = $movement === 'rising' ? '↑' : ($movement === 'falling' ? '↓' :
            ($movement === 'stable' ? '→' : '•'));
        $trendclass = $quality === 'improving' ? 'is-positive' : ($quality === 'worsening' ? 'is-negative' :
            ($movement === 'insufficient' ? 'is-muted' : 'is-neutral'));
        $trenddisplay = html_writer::div(
            html_writer::tag('span', $trendicon, ['class'=>'bms-bpu-metric-trend-icon','aria-hidden'=>'true']) .
            html_writer::tag('span', s($trend), ['class'=>'bms-bpu-metric-trend-text']),
            'bms-bpu-metric-trend ' . $trendclass);

        $progress = '';
        if ($m['current_plan'] !== null && (float)$m['current_plan'] > 0 && $m['current_fact'] !== null &&
                (float)$m['current_fact'] >= 0) {
            $progresspercent = ((float)$m['current_fact'] / (float)$m['current_plan']) * 100;
            $progresswidth = max(0.0, min(100.0, $progresspercent));
            $progresslabel = format_float($progresspercent, 1) . '%';
            $progress = html_writer::div(
                html_writer::div(
                    html_writer::tag('span', get_string('battleplan_fact','local_kbacompany') . ' / ' .
                        get_string('metric_period_plan','local_kbacompany'), ['class'=>'bms-bpu-metric-progress-label']) .
                    html_writer::tag('strong', s($progresslabel), ['class'=>'bms-bpu-metric-progress-value']),
                    'bms-bpu-metric-progress-head') .
                html_writer::div(
                    html_writer::div('', 'bms-bpu-metric-progress-fill', [
                        'style'=>'width:' . format_float($progresswidth, 1, false, false) . '%',
                    ]),
                    'bms-bpu-metric-progress-track', [
                        'role'=>'progressbar',
                        'aria-valuemin'=>'0',
                        'aria-valuemax'=>'100',
                        'aria-valuenow'=>(string)round($progresswidth),
                        'aria-label'=>get_string('battleplan_fact','local_kbacompany') . ' / ' .
                            get_string('metric_period_plan','local_kbacompany'),
                    ]),
                'bms-bpu-metric-progress');
        }
        $previous = $m['previous_fact'] === null ? '—' :
            format_float((float)$m['previous_fact'], 2) . ' ' . s($m['unit']);
        if (!empty($m['daily_aggregate']) && $m['previous_fact'] !== null) {
            $days = (int)($m['previous_fact_days'] ?? 0);
            $previous .= html_writer::tag('small',
                get_string('bpu_metric_previous_fact_days', 'local_kbacompany', $days),
                ['class'=>'d-block text-muted']);
        }
        $metricmeta = html_writer::tag('small',
            get_string('battleplan_previous_fact','local_kbacompany') . ': ' . $previous,
            ['class'=>'d-block text-muted bms-bpu-metric-meta']);
        $metricmeta .= html_writer::tag('small',
            get_string('indicator_norm','local_kbacompany') . ': ' . $norm,
            ['class'=>'d-block text-muted bms-bpu-metric-meta']);
        $metriccell = html_writer::div($metriclabel . $metricmeta, 'bms-bpu-metric-identity');

        $stat = static function(string $label, string $value, string $class = ''): string {
            return html_writer::div(
                html_writer::tag('span', s($label), ['class'=>'bms-bpu-metric-card-label']) .
                html_writer::div($value, 'bms-bpu-metric-card-value'),
                trim('bms-bpu-metric-card-stat ' . $class));
        };

        $daily = '';
        if (!empty($m['daily_aggregate']) && !empty($m['daily_days'])) {
            $dayitems = '';
            foreach ($m['daily_days'] as $dayrow) {
                $dayvalue = $dayrow['fact'] === null ? '—' :
                    format_float((float)$dayrow['fact'], 2) . ' ' . s($m['unit']);
                $dayitems .= html_writer::div(
                    html_writer::tag('span', s($dayrow['period']), ['class'=>'bms-bpu-metric-day-date']) .
                    html_writer::tag('strong', $dayvalue, ['class'=>'bms-bpu-metric-day-value']),
                    'bms-bpu-metric-day');
            }
            $daily = html_writer::div(
                html_writer::tag('span', get_string('battleplan_fact','local_kbacompany'),
                    ['class'=>'bms-bpu-metric-card-label']) .
                html_writer::div($dayitems, 'bms-bpu-metric-days'),
                'bms-bpu-metric-card-daily');
        }

        $cardheader = html_writer::div($metriccell, 'bms-bpu-metric-card-heading');
        $cardstats = html_writer::div(
            $stat(get_string('metric_period_plan','local_kbacompany'), $periodplan, 'is-plan') .
            $stat(get_string('battleplan_fact','local_kbacompany'), $factform, 'is-fact') .
            $stat(get_string('metric_period_variance','local_kbacompany'), $variance, 'is-variance') .
            $stat(get_string('metric_period_status','local_kbacompany'), $condition, 'is-status') .
            $stat(get_string('battleplan_trend','local_kbacompany'), $trenddisplay, 'is-trend'),
            'bms-bpu-metric-card-stats');
        $cards .= html_writer::div($cardheader . $cardstats . $progress . $daily, 'bms-bpu-metric-card');
    }
    echo html_writer::div($cards, 'bms-bpu-metric-cards');
    echo html_writer::tag('p', get_string('battleplan_position_indicators_rule','local_kbacompany'), ['class'=>'text-muted small']);
}

// Legacy pinned quota analysis remains available only when an old quota set is explicitly pinned.
if ($plan['norm_uuid'] !== '') {
    echo $OUTPUT->heading(get_string('battleplan_legacy_quota_section','local_kbacompany'),4);
    $mt=new html_table();
    $mt->head=[get_string('battleplan_metric','local_kbacompany'),get_string('battleplan_previous_fact','local_kbacompany'),
        get_string('battleplan_quota','local_kbacompany'),get_string('battleplan_condition','local_kbacompany'),
        get_string('battleplan_fact','local_kbacompany'),get_string('battleplan_performance','local_kbacompany'),
        get_string('battleplan_trend','local_kbacompany'),get_string('actions')];
    $conditionoptions=[''=>get_string('battleplan_condition_auto','local_kbacompany')];
    foreach (battle_plan_template_service::CONDITIONS as $condition) {
        $conditionoptions[$condition]=get_string('battleplan_condition_'.$condition,'local_kbacompany');
    }
    $canoverride=$metricsedit && ($data['can_manage'] || (int)$plan['reviewer_id']===(int)$USER->id);
    foreach($data['metrics'] as $m){
        $factdisplay=$m['fact']===null?'—':format_float((float)$m['fact'],2);
        if($metricsedit && $data['selected_can_edit'] && $plan['status']!=='closed'){
            $factform=html_writer::start_tag('form',['method'=>'post','class'=>'form-inline']);
            $factform.=$hidden('metricfact',$plan['uuid']);
            $factform.=html_writer::empty_tag('input',['type'=>'hidden','name'=>'statuuid','value'=>$m['stat_uuid']]);
            $factform.=ui::control('amount',['label'=>'battleplan_fact','required'=>true,'suffix'=>'legacy-'.$m['stat_uuid']],
                $metricvalue('metricfact','statuuid',(string)$m['stat_uuid'],'amount',$m['fact']??''));
            if ($m['fact']!==null) {
                $factform.=ui::control('reason',['label'=>'battleplan_correction_reason','suffix'=>'legacy-'.$m['stat_uuid']],
                    $metricvalue('metricfact','statuuid',(string)$m['stat_uuid'],'reason',''));
            }
            $factform.=html_writer::tag('button',get_string('savechanges'),['type'=>'submit','class'=>'btn btn-sm btn-outline-primary']);
            $factform.=html_writer::end_tag('form');
        }else{$factform=s($factdisplay);}
        $condition=$m['condition']!==''?get_string('battleplan_condition_'.$m['condition'],'local_kbacompany'):get_string('battleplan_condition_nodata','local_kbacompany');
        if($m['override_comment']!==''){$condition.=' · '.s($m['override_comment']);}
        $actions='';
        if($canoverride && $plan['status']!=='closed'){
            $actions=html_writer::start_tag('form',['method'=>'post','class'=>'mb-1']).$hidden('metricstate',$plan['uuid']);
            $actions.=html_writer::empty_tag('input',['type'=>'hidden','name'=>'statuuid','value'=>$m['stat_uuid']]);
            $actions.=ui::control('condition',['label'=>'battleplan_condition','options'=>$conditionoptions,'suffix'=>'state-'.$m['stat_uuid']],
                $metricvalue('metricstate','statuuid',(string)$m['stat_uuid'],'condition',$m['condition']===$m['automatic_condition']?'':$m['condition']));
            $actions.=ui::control('comment',['label'=>'battleplan_override_comment','suffix'=>'state-'.$m['stat_uuid']],
                $metricvalue('metricstate','statuuid',(string)$m['stat_uuid'],'comment',$m['override_comment']));
            $actions.=html_writer::tag('button',get_string('battleplan_condition_apply','local_kbacompany'),['type'=>'submit','class'=>'btn btn-sm btn-outline-secondary']).html_writer::end_tag('form');
        }
        $mt->data[]=[s($m['code'].' — '.$m['name'].' ('.$m['unit'].')'),$m['previous_fact']===null?'—':format_float((float)$m['previous_fact'],2),
            format_float((float)$m['quota'],2),$condition,$factform,
            $m['performance']===null?'—':format_float((float)$m['performance'],1).'%',
            $m['trend']===null?'—':format_float((float)$m['trend'],1).'%',$actions];
    }
    echo html_writer::table($mt);
    if($data['overall_condition']['performance']!==null){
        $overalllabel=$data['overall_condition']['condition']!==''?get_string('battleplan_condition_'.$data['overall_condition']['condition'],'local_kbacompany'):get_string('battleplan_condition_nodata','local_kbacompany');
        echo html_writer::tag('p',get_string('battleplan_overall_condition','local_kbacompany',(object)['condition'=>$overalllabel,'performance'=>format_float((float)$data['overall_condition']['performance'],1)]),['class'=>'alert alert-light']);
    }
}

