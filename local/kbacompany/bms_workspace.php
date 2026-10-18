<?php

require_once(__DIR__ . '/../../config.php');

use local_kbacompany\local\bms_ui_service;

require_login();
$context = context_system::instance();
require_capability('local/kbacompany:viewowncompany', $context);
$uuid = required_param('uuid', PARAM_RAW_TRIMMED);

$PAGE->set_context($context);
$PAGE->requires->css(new moodle_url('/local/kbacompany/styles.css'));
$PAGE->set_url(new moodle_url('/local/kbacompany/bms_workspace.php', ['uuid' => $uuid]));
$PAGE->set_title(get_string('bmsworkspace_title', 'local_kbacompany'));
$PAGE->set_heading(get_string('pluginname', 'local_kbacompany'));

// Select BMS pagelayout before any $OUTPUT call. This preserves Moodle 4.0
// bootstrap_renderer compatibility from the App Shell hotfix line.
$useowned = \local_kbacompany\local\bms24_shell_service::is_enabled();
$usetheme = !$useowned && bms_ui_service::supports_kba_shell();
if ($useowned) {
    \local_kbacompany\local\bms24_shell_service::prepare_page();
} else {
    $PAGE->set_pagelayout($usetheme ? 'bms' : 'standard');
}

$model = bms_ui_service::workspace_model($uuid, (int)$USER->id);
$company = $model['company'];

$rendericon = static function(?array $icon) use ($OUTPUT): string {
    if (empty($icon['key'])) {
        return '';
    }
    return $OUTPUT->pix_icon(
        (string)$icon['key'],
        '',
        (string)($icon['component'] ?? 'moodle'),
        ['class' => 'icon']
    );
};
foreach ($model['navigation_groups'] as &$group) {
    foreach ($group['items'] as &$item) {
        if (empty($item['iconhtml'])) {
            $item['iconhtml'] = $rendericon($item['icon'] ?? null);
        }
        unset($item['icon']);
    }
    unset($item);
}
unset($group);
foreach ($model['tree']['nodes'] as &$node) {
    $node['iconhtml'] = $rendericon($node['icon'] ?? null);
    unset($node['icon']);
}
unset($node);

$routes = $model['route_map'];
$companyurl = new moodle_url('/local/kbacompany/bms_workspace.php', ['uuid' => $company['uuid']]);
$communicationurl = new moodle_url($routes['communications']['url']);
$taskurl = new moodle_url($routes['tasks']['url']);
$architectureurl = new moodle_url($routes['architecturecenter']['url']);
$reviewurl = new moodle_url('/local/kbacompany/communication_center.php', [
    'uuid' => $company['uuid'], 'tab' => 'tasks', 'taskscope' => 'review', 'provider' => 'all',
]);
$overdueurl = new moodle_url('/local/kbacompany/communication_center.php', [
    'uuid' => $company['uuid'], 'tab' => 'tasks', 'taskscope' => 'overdue', 'provider' => 'all',
]);
$learningurl = new moodle_url($routes['learning']['url']);
$newmessageurl = new moodle_url('/local/kbacompany/communication_center.php', ['uuid' => $company['uuid']]);
$newtaskurl = new moodle_url('/local/kbacompany/tasks.php', ['uuid' => $company['uuid']]);
$settingsurl = new moodle_url('/local/kbacompany/bms_settings.php', ['uuid' => $company['uuid']]);
$planurl = new moodle_url('/local/kbacompany/bms_settings.php', ['uuid' => $company['uuid'], 'tab' => 'plan']);

$workctx = $model['communication_context'];
$taskctx = $workctx['task_center'];
$org = $model['organization'];
$processctx = $model['process_context'];
$counts = $model['workspace_counters'];
$todayctx = \local_kbacompany\local\calendar_workspace_service::today_snapshot($company['uuid'], (int)$USER->id);

$fallbacktasks = [];
foreach (array_slice($taskctx['tasks'], 0, 5) as $task) {
    $fallbacktasks[] = [
        'title' => (string)$task['title'],
        'url' => (string)($task['source_url'] ?? ''),
        'source' => (string)($task['provider_label'] ?? ''),
    ];
}

$attentionitems = [];
if ($counts['overdue'] > 0) {
    $attentionitems[] = [
        'label' => get_string('bmsworkspace_attention_overdue', 'local_kbacompany', $counts['overdue']),
        'url' => $overdueurl->out(false),
        'variant' => 'danger',
    ];
}
if ($counts['incoming'] > 0) {
    $attentionitems[] = [
        'label' => get_string('bmsworkspace_attention_incoming', 'local_kbacompany', $counts['incoming']),
        'url' => $communicationurl->out(false),
        'variant' => 'brand',
    ];
}
if ($counts['control'] > 0) {
    $attentionitems[] = [
        'label' => get_string('bmsworkspace_attention_control', 'local_kbacompany', $counts['control']),
        'url' => $reviewurl->out(false),
        'variant' => 'warning',
    ];
}

if (!$usetheme && !$useowned) {
    $fallback = [
        'companyname' => format_string($company['name']),
        'title' => get_string('bmsworkspace_title', 'local_kbacompany'),
        'description' => get_string('bmsworkspace_description_v2', 'local_kbacompany'),
        'groups' => $model['navigation_groups'],
        'stats' => [
            ['label' => get_string('bmsworkspace_incoming', 'local_kbacompany'), 'value' => (string)$counts['incoming']],
            ['label' => get_string('bmsworkspace_mytasks', 'local_kbacompany'), 'value' => (string)$counts['tasks']],
            ['label' => get_string('bmsworkspace_control', 'local_kbacompany'), 'value' => (string)$counts['control']],
            ['label' => get_string('bmsworkspace_learning', 'local_kbacompany'), 'value' => (string)$counts['learning'], 'url' => $learningurl->out(false)],
        ],
        'tasks' => $fallbacktasks,
        'attention' => $attentionitems,
        'architecture' => [
            'url' => $architectureurl->out(false),
            'processes' => (string)$processctx['summary']['total'],
            'units' => (string)$org['summary']['org_units'],
            'positions' => (string)$org['summary']['positions'],
        ],
        'taskurl' => $taskurl->out(false),
        'communicationurl' => $communicationurl->out(false),
        'architectureurl' => $architectureurl->out(false),
        'backurl' => $companyurl->out(false),
    ];
    echo $OUTPUT->header();
    echo $OUTPUT->render_from_template('local_kbacompany/bms_workspace_fallback', $fallback);
    echo $OUTPUT->footer();
    exit;
}

$render = static function(string $template, array $context) use ($OUTPUT, $useowned): string {
    return $useowned
        ? \local_kbacompany\local\bms24_shell_service::fragment($template, $context)
        : $OUTPUT->render_from_template('theme_kba/bms/' . $template, $context);
};

// Daily-work counters: grouped into one compact overview card with direct links.
$overviewstats = [];
$overviewstats[] = html_writer::link($communicationurl, $render('stat_card', [
    'label' => get_string('bmsworkspace_incoming', 'local_kbacompany'),
    'value' => (string)$counts['incoming'],
    'caption' => get_string('bmsworkspace_incoming_caption', 'local_kbacompany'),
]), ['class' => 'd-block text-reset text-decoration-none h-100']);
$overviewstats[] = html_writer::link($taskurl, $render('stat_card', [
    'label' => get_string('bmsworkspace_mytasks', 'local_kbacompany'),
    'value' => (string)$counts['tasks'],
    'delta' => $counts['overdue'] > 0 ? get_string('bmsworkspace_overdue_count', 'local_kbacompany', $counts['overdue']) : '',
    'negative' => $counts['overdue'] > 0,
    'caption' => get_string('bmsworkspace_mytasks_caption', 'local_kbacompany'),
]), ['class' => 'd-block text-reset text-decoration-none h-100']);
$overviewstats[] = html_writer::link($reviewurl, $render('stat_card', [
    'label' => get_string('bmsworkspace_control', 'local_kbacompany'),
    'value' => (string)$counts['control'],
    'caption' => get_string('bmsworkspace_control_caption', 'local_kbacompany'),
]), ['class' => 'd-block text-reset text-decoration-none h-100']);
$overviewstats[] = html_writer::link($learningurl, $render('stat_card', [
    'label' => get_string('bmsworkspace_learning', 'local_kbacompany'),
    'value' => (string)$counts['learning'],
    'caption' => get_string('bmsworkspace_learning_caption', 'local_kbacompany'),
]), [
    'class' => 'd-block text-reset text-decoration-none h-100',
    'title' => get_string('bms_learning_open', 'local_kbacompany'),
]);
$overviewbody = html_writer::start_div('row g-3');
foreach ($overviewstats as $stat) {
    $overviewbody .= html_writer::div($stat, 'col-12 col-sm-6 col-xl-3');
}
$overviewbody .= html_writer::end_div();
$overviewcard = $render('card', [
    'header' => [
        'eyebrow' => get_string('bmsworkspace_overview_eyebrow', 'local_kbacompany'),
        'title' => get_string('bmsworkspace_overview_title', 'local_kbacompany'),
        'subtitle' => get_string('bmsworkspace_overview_subtitle', 'local_kbacompany'),
    ],
    'contenthtml' => $overviewbody,
]);

$statusvariants = [
    'open' => 'neutral', 'doing' => 'info', 'review' => 'warning',
    'returned' => 'danger', 'done' => 'success', 'cancelled' => 'neutral',
];
$taskitems = [];
$today = gmdate('Y-m-d');
foreach (array_slice($taskctx['tasks'], 0, 6) as $task) {
    $due = (string)$task['due_date'];
    $overdue = $due !== '' && $due < $today && !in_array($task['status'], ['done', 'cancelled'], true);
    $taskitems[] = [
        'title' => s($task['title']),
        'url' => (string)($task['source_url'] ?? ''),
        'source' => s(trim((string)($task['provider_label'] ?? '') .
            (((string)($task['source_label'] ?? '') !== '' && (string)($task['source_label'] ?? '') !== (string)($task['provider_label'] ?? ''))
                ? ' · ' . (string)$task['source_label'] : ''))),
        'responsible' => s($task['assignee_name']),
        'deadline' => s($due),
        'status' => [
            'label' => get_string('taskcenter_status_' . $task['status'], 'local_kbacompany'),
            'variant' => $statusvariants[$task['status']] ?? 'neutral',
        ],
        'priority' => $task['priority'],
        'prioritylabel' => get_string('taskcenter_priority_' . $task['priority'], 'local_kbacompany'),
        'overdue' => $overdue,
    ];
}
if ($taskitems) {
    $tasksbody = $render('task_list', ['items' => $taskitems]);
} else {
    $tasksbody = $render('empty_state', [
        'title' => get_string('bmsworkspace_notasks', 'local_kbacompany'),
        'text' => get_string('bmsworkspace_notasks_text', 'local_kbacompany'),
        'action' => ['label' => get_string('communicationcenter_tab_tasks', 'local_kbacompany'), 'url' => $taskurl->out(false)],
    ]);
}
$taskcard = $render('card', [
    'header' => [
        'eyebrow' => get_string('bmsworkspace_workcenter', 'local_kbacompany'),
        'title' => get_string('bmsworkspace_recenttasks', 'local_kbacompany'),
        'subtitle' => get_string('bmsworkspace_recenttasks_subtitle', 'local_kbacompany'),
        'actionshtml' => html_writer::link($taskurl, get_string('bmsworkspace_alltasks', 'local_kbacompany'),
            ['class' => 'kba-bms-button']),
    ],
    'contenthtml' => $tasksbody,
]);

$attentionbody = '';
if ($attentionitems) {
    $attentionbody .= html_writer::start_tag('div', ['class' => 'list-group list-group-flush']);
    foreach ($attentionitems as $item) {
        $badge = $render('badge', [
            'value' => '!',
            'variant' => $item['variant'],
            'title' => $item['label'],
        ]);
        $attentionbody .= html_writer::link($item['url'], $badge . html_writer::span(s($item['label']), 'ml-2'), [
            'class' => 'list-group-item list-group-item-action px-0',
        ]);
    }
    $attentionbody .= html_writer::end_tag('div');
} else {
    $attentionbody = $render('empty_state', [
        'title' => get_string('bmsworkspace_attention_none', 'local_kbacompany'),
        'text' => get_string('bmsworkspace_attention_none_text', 'local_kbacompany'),
    ]);
}
$attentioncard = $render('card', [
    'header' => [
        'eyebrow' => get_string('bmsworkspace_attention_eyebrow', 'local_kbacompany'),
        'title' => get_string('bmsworkspace_attention', 'local_kbacompany'),
        'subtitle' => get_string('bmsworkspace_attention_subtitle', 'local_kbacompany'),
    ],
    'contenthtml' => $attentionbody,
    'accent' => 'brand',
]);

$architecturemeta = html_writer::start_div('row g-3');
$architecturemeta .= html_writer::div(
    html_writer::tag('div', (string)$processctx['summary']['total'], ['class' => 'h4 mb-0']) .
    html_writer::tag('div', get_string('company_processes', 'local_kbacompany'), ['class' => 'text-muted small']),
    'col-4'
);
$architecturemeta .= html_writer::div(
    html_writer::tag('div', (string)$org['summary']['org_units'], ['class' => 'h4 mb-0']) .
    html_writer::tag('div', get_string('bmsworkspace_stat_units', 'local_kbacompany'), ['class' => 'text-muted small']),
    'col-4'
);
$architecturemeta .= html_writer::div(
    html_writer::tag('div', (string)$org['summary']['positions'], ['class' => 'h4 mb-0']) .
    html_writer::tag('div', get_string('bmsworkspace_stat_positions', 'local_kbacompany'), ['class' => 'text-muted small']),
    'col-4'
);
$architecturemeta .= html_writer::end_div();
$architecturemeta .= html_writer::div(
    html_writer::link($architectureurl, get_string('bmsarchitecture_open', 'local_kbacompany'),
        ['class' => 'kba-bms-button kba-bms-button--primary']),
    'mt-3'
);
$architecturecard = $render('card', [
    'header' => [
        'eyebrow' => get_string('bmsarchitecture_eyebrow', 'local_kbacompany'),
        'title' => get_string('bmsarchitecture_title', 'local_kbacompany'),
        'subtitle' => get_string('bmsarchitecture_workspace_subtitle', 'local_kbacompany'),
    ],
    'contenthtml' => $architecturemeta,
]);

$companymeta = html_writer::start_tag('dl', ['class' => 'row mb-0']);
$companymeta .= html_writer::tag('dt', get_string('membershiproles', 'local_kbacompany'), ['class' => 'col-sm-4 text-muted']);
$companymeta .= html_writer::tag('dd', s($model['roles'] ? implode(', ', $model['roles']) : get_string('rolenone', 'local_kbacompany')),
    ['class' => 'col-sm-8']);
if ($company['primary_activity'] !== '') {
    $companymeta .= html_writer::tag('dt', get_string('bmsworkspace_primaryactivity', 'local_kbacompany'), ['class' => 'col-sm-4 text-muted']);
    $companymeta .= html_writer::tag('dd', s($company['primary_activity']), ['class' => 'col-sm-8']);
}
$companymeta .= html_writer::tag('dt', get_string('bmsworkspace_stat_employees', 'local_kbacompany'), ['class' => 'col-sm-4 text-muted']);
$companymeta .= html_writer::tag('dd', (string)$org['summary']['employees'], ['class' => 'col-sm-8']);
$companymeta .= html_writer::end_tag('dl');
$companymeta .= html_writer::start_tag('details', ['class' => 'mt-3']);
$companymeta .= html_writer::tag('summary', get_string('companytechnicaldetails', 'local_kbacompany'));
$technical = new html_table();
$technical->attributes['class'] = 'table table-sm mt-2 mb-0';
$technical->data = [
    [get_string('companycorestatus', 'local_kbacompany'), get_string('connected', 'local_kbacompany')],
    [get_string('stableidentity', 'local_kbacompany'), s($company['uuid'])],
    [get_string('sourceoftruth', 'local_kbacompany'), get_string('sourceoftruthvalue', 'local_kbacompany')],
    [get_string('legacyid', 'local_kbacompany'), (string)$company['compatibility']['source_id']],
];
$companymeta .= html_writer::table($technical);
$companymeta .= html_writer::end_tag('details');
$companycard = $render('card', [
    'header' => [
        'eyebrow' => get_string('bmsworkspace_context', 'local_kbacompany'),
        'title' => get_string('bmsworkspace_company_title', 'local_kbacompany'),
        'subtitle' => get_string('bmsworkspace_company_subtitle', 'local_kbacompany'),
    ],
    'contenthtml' => $companymeta,
]);

$managementhome = $model['management_home'] ?? ['available' => false];
$managementhtml = '';
if (!empty($managementhome['available'])) {
    $exceptions = $managementhome['exceptions'];
    $battleplans = $managementhome['battleplans'];
    $exceptionsurl = new moodle_url('/local/kbacompany/indicators.php', ['uuid' => $company['uuid'], 'tab' => 'exceptions']);
    $overviewurl = new moodle_url('/local/kbacompany/battle_plan_overview.php', ['uuid' => $company['uuid']]);

    $queue = '';
    $rows = array_slice((array)($exceptions['rows'] ?? []), 0, 6);
    if ($rows) {
        $queue .= html_writer::start_div('list-group list-group-flush');
        foreach ($rows as $row) {
            $metric = $row['metric'] ?? [];
            $code = (string)($metric['code'] ?? '');
            $name = (string)($metric['name'] ?? '');
            $contextlabel = (string)($row['context_label'] ?? '');
            $severity = (string)($row['severity'] ?? 'medium');
            $label = trim($code . ($code !== '' && $name !== '' ? ' — ' : '') . $name);
            $meta = $contextlabel;
            if (!empty($row['management_cycle']['requires_new_decision'])) {
                $meta .= ($meta !== '' ? ' · ' : '') . get_string('bmsmanagement_decision_required', 'local_kbacompany');
            } else if (!empty($row['overdue_task_count'])) {
                $meta .= ($meta !== '' ? ' · ' : '') . get_string('bmsmanagement_action_overdue', 'local_kbacompany');
            }
            $badgevariant = $severity === 'critical' ? 'danger' : ($severity === 'high' ? 'warning' : 'neutral');
            $badge = $render('badge', [
                'value' => strtoupper(substr($severity, 0, 1)),
                'variant' => $badgevariant,
                'title' => get_string('indicator_exception_severity_' . $severity, 'local_kbacompany'),
            ]);
            $copy = html_writer::div(s($label), 'font-weight-bold') .
                html_writer::div(s($meta), 'small text-muted');
            $queue .= html_writer::link($exceptionsurl, $badge . html_writer::div($copy, 'ml-2 flex-grow-1'), [
                'class' => 'list-group-item list-group-item-action px-0 d-flex align-items-start',
            ]);
        }
        $queue .= html_writer::end_div();
    } else {
        $queue = $render('empty_state', [
            'title' => get_string('bmsmanagement_clear_title', 'local_kbacompany'),
            'text' => get_string('bmsmanagement_clear_text', 'local_kbacompany'),
        ]);
    }
    $queuecard = $render('card', [
        'header' => [
            'eyebrow' => get_string('bmsmanagement_eyebrow', 'local_kbacompany'),
            'title' => get_string('bmsmanagement_attention_queue', 'local_kbacompany'),
            'subtitle' => get_string('bmsmanagement_attention_queue_subtitle', 'local_kbacompany'),
            'actionshtml' => html_writer::link($exceptionsurl, get_string('bmsmanagement_open_exceptions', 'local_kbacompany'),
                ['class' => 'kba-bms-button']),
        ],
        'contenthtml' => $queue,
        'accent' => 'brand',
    ]);

    $execution = html_writer::start_tag('dl', ['class' => 'row mb-0']);
    $execution .= html_writer::tag('dt', get_string('bmsmanagement_active_plans', 'local_kbacompany'), ['class' => 'col-7 text-muted']);
    $execution .= html_writer::tag('dd', (string)($battleplans['summary']['active_plans'] ?? 0), ['class' => 'col-5 text-right font-weight-bold']);
    $execution .= html_writer::tag('dt', get_string('bmsmanagement_open_actions', 'local_kbacompany'), ['class' => 'col-7 text-muted']);
    $execution .= html_writer::tag('dd', (string)($battleplans['summary']['open_tasks'] ?? 0), ['class' => 'col-5 text-right font-weight-bold']);
    $execution .= html_writer::tag('dt', get_string('bmsmanagement_overdue_actions', 'local_kbacompany'), ['class' => 'col-7 text-muted']);
    $execution .= html_writer::tag('dd', (string)($battleplans['summary']['overdue_tasks'] ?? 0), ['class' => 'col-5 text-right font-weight-bold']);
    $execution .= html_writer::tag('dt', get_string('bmsmanagement_critical_indicators', 'local_kbacompany'), ['class' => 'col-7 text-muted']);
    $execution .= html_writer::tag('dd', (string)($battleplans['summary']['critical_indicators'] ?? 0), ['class' => 'col-5 text-right font-weight-bold']);
    $execution .= html_writer::end_tag('dl');
    $execution .= html_writer::div(html_writer::link($overviewurl, get_string('bmsmanagement_open_week', 'local_kbacompany'),
        ['class' => 'kba-bms-button kba-bms-button--primary']), 'mt-3');
    $executioncard = $render('card', [
        'header' => [
            'eyebrow' => get_string('bmsmanagement_execution_eyebrow', 'local_kbacompany'),
            'title' => get_string('bmsmanagement_execution_title', 'local_kbacompany'),
            'subtitle' => get_string('bmsmanagement_execution_subtitle', 'local_kbacompany'),
        ],
        'contenthtml' => $execution,
    ]);

    $managementhtml .= html_writer::start_div('row g-3 mb-3');
    $managementhtml .= html_writer::div($queuecard, 'col-12 col-xl-8');
    $managementhtml .= html_writer::div($executioncard, 'col-12 col-xl-4');
    $managementhtml .= html_writer::end_div();
}

// Personal Today block: read-only projection of the existing calendar/work domains.
$todayitems = '';
if (!empty($todayctx['next'])) {
    $todayitems .= html_writer::start_tag('div', ['class' => 'list-group list-group-flush']);
    foreach ($todayctx['next'] as $event) {
        $time = !empty($event['all_day']) ? get_string('bmscalendar_all_day', 'local_kbacompany') : (string)($event['time_label'] ?? '');
        $source = (string)($event['source_label'] ?? $event['course_name'] ?? '');
        $title = html_writer::span(s($time), 'text-muted mr-2') . html_writer::span(s((string)$event['name']), 'font-weight-bold');
        $meta = html_writer::div(s($source), 'small text-muted mt-1');
        $url = (string)($event['url'] ?? '');
        $body = $url !== '' ? html_writer::link($url, $title . $meta, ['class' => 'list-group-item list-group-item-action px-0'])
            : html_writer::div($title . $meta, 'list-group-item px-0');
        $todayitems .= $body;
    }
    $todayitems .= html_writer::end_tag('div');
} else {
    $todayitems = html_writer::div(get_string('bmsworkspace_today_empty', 'local_kbacompany'), 'text-muted');
}
$todaybadges = html_writer::span(get_string('bmsworkspace_today_events', 'local_kbacompany', (int)$todayctx['summary']['events']), 'badge badge-light mr-2');
$todaybadges .= html_writer::span(get_string('bmsworkspace_today_meetings', 'local_kbacompany', (int)$todayctx['summary']['meetings']), 'badge badge-light mr-2');
$todaybadges .= html_writer::span(get_string('bmsworkspace_today_tasks', 'local_kbacompany', (int)$todayctx['summary']['tasks_due']), 'badge badge-light mr-2');
if ((int)$todayctx['summary']['next_hour'] > 0) {
    $todaybadges .= html_writer::span(get_string('bmsworkspace_today_next_hour', 'local_kbacompany', (int)$todayctx['summary']['next_hour']), 'badge badge-warning mr-2');
}
if ((int)$todayctx['summary']['tomorrow_checkpoints'] > 0) {
    $todaybadges .= html_writer::span(get_string('bmsworkspace_today_tomorrow_checkpoints', 'local_kbacompany', (int)$todayctx['summary']['tomorrow_checkpoints']), 'badge badge-info mr-2');
}
$todaybody = html_writer::div($todaybadges, 'mb-3') . $todayitems;
$todaycard = $render('card', [
    'header' => [
        'eyebrow' => get_string('bmsworkspace_today_eyebrow', 'local_kbacompany'),
        'title' => get_string('bmsworkspace_today_title', 'local_kbacompany'),
        'subtitle' => get_string('bmsworkspace_today_subtitle', 'local_kbacompany', $todayctx['date_label']),
        'actionshtml' => html_writer::link($todayctx['calendar_url'], get_string('bmsworkspace_today_open_calendar', 'local_kbacompany'), ['class' => 'kba-bms-button']),
    ],
    'contenthtml' => $todaybody,
    'accent' => ((int)$todayctx['summary']['next_hour'] > 0 || (int)$todayctx['summary']['tasks_due'] > 0) ? 'warning' : 'brand',
]);
$todayhtml = html_writer::div($todaycard, 'mb-3');

// RC24 usability: prioritize today's schedule, brief status and actionable work.
// Secondary management and reference panels remain accessible on demand.
$contenthtml = html_writer::start_div('bms-home-simple');
$contenthtml .= $todayhtml;
$contenthtml .= html_writer::div($overviewcard, 'mb-3');
$contenthtml .= html_writer::start_div('row g-3 mb-3');
$contenthtml .= html_writer::div($queuecard ?? $attentioncard, 'col-12 col-xl-7');
$contenthtml .= html_writer::div($taskcard, 'col-12 col-xl-5');
$contenthtml .= html_writer::end_div();
if (!empty($managementhome['available'])) {
    $contenthtml .= html_writer::start_tag('details', ['class' => 'bms-home-details mb-3']);
    $contenthtml .= html_writer::tag('summary', get_string('bmsworkspace_simple_management', 'local_kbacompany'));
    $contenthtml .= html_writer::div($executioncard, 'mt-3');
    $contenthtml .= html_writer::end_tag('details');
}
$contenthtml .= html_writer::start_tag('details', ['class' => 'bms-home-details mb-3']);
$contenthtml .= html_writer::tag('summary', get_string('bmsworkspace_simple_more', 'local_kbacompany'));
$contenthtml .= html_writer::start_div('row g-3 mt-2');
$contenthtml .= html_writer::div($architecturecard, 'col-12 col-xl-6');
$contenthtml .= html_writer::div($companycard, 'col-12 col-xl-6');
$contenthtml .= html_writer::end_div();
$contenthtml .= html_writer::end_tag('details');
$contenthtml .= html_writer::end_div();

$attentionhtml = '';
$attentioncount = $counts['overdue'] + $counts['incoming'] + $counts['control'];
if ($attentioncount > 0) {
    $badge = $render('badge', [
        'value' => (string)$attentioncount,
        'variant' => $counts['overdue'] > 0 ? 'danger' : 'brand',
        'title' => get_string('bmsworkspace_attention', 'local_kbacompany'),
    ]);
    $attentionhtml = html_writer::link($communicationurl,
        $OUTPUT->pix_icon('i/notifications', '', 'moodle') . $badge,
        ['class' => 'kba-bms-iconbutton', 'title' => get_string('bmsworkspace_attention', 'local_kbacompany'),
            'aria-label' => get_string('bmsworkspace_attention', 'local_kbacompany')]);
}

$quickactions = html_writer::link($newtaskurl,
    '+ ' . get_string('bmsworkspace_quick_task', 'local_kbacompany'),
    ['class' => 'kba-bms-button kba-bms-button--primary']);
$quickactions .= ' ' . html_writer::link($newmessageurl,
    get_string('bmsworkspace_quick_message', 'local_kbacompany'),
    ['class' => 'kba-bms-button']);

$shell = [
    'sidebar' => [
        'arialabel' => get_string('bmsworkspace_sidebar_label', 'local_kbacompany'),
        'brandmarkhtml' => html_writer::link($companyurl, html_writer::span('BMS24', 'bms-sidebar-brandmark-text'), ['class' => 'bms-brand-home-link', 'aria-label' => 'BMS24 — ' . get_string('bmsworkspace_nav', 'local_kbacompany')]),
        'brandlabel' => format_string($company['name']),
        'togglelabel' => get_string('bmsworkspace_collapse', 'local_kbacompany'),
        'groups' => $model['navigation_groups'],
        'utilityitems' => [[
            'label' => get_string('bms_settings_title', 'local_kbacompany'),
            'url' => $settingsurl->out(false),
            'active' => false,
            'iconhtml' => $OUTPUT->pix_icon('i/settings', '', 'moodle', ['class' => 'icon']),
        ]],
        'footerhtml' => html_writer::span('© BMS24, 2026', 'bms-sidebar-copyright'),
        'footerbrandlabel' => '',
        'footercompanyhtml' => '',
    ],
    'topbar' => [
        'mobilemenulabel' => get_string('bmsworkspace_mobilemenu', 'local_kbacompany'),
        'sectiontitle' => '',
        'breadcrumbs' => [
            'items' => [
                ['label' => get_string('mycompany', 'local_kbacompany'), 'url' => (new moodle_url('/local/kbacompany/index.php'))->out(false)],
                ['label' => format_string($company['name']), 'url' => $companyurl->out(false)],
                ['label' => get_string('bmsworkspace_nav', 'local_kbacompany'), 'url' => null],
            ],
        ],
        'quickactionhtml' => $quickactions,
        'planhtml' => html_writer::link($planurl, get_string('bms_plan_title', 'local_kbacompany'),
            ['class' => 'kba-bms-button kba-bms-button--plan']),
        'notificationshtml' => $attentionhtml,
        'usermenuhtml' => $OUTPUT->user_menu(),
    ],
    'header' => [
        'eyebrow' => get_string('bmsworkspace_nav', 'local_kbacompany'),
        'title' => format_string($company['name']),
        'description' => get_string('bmsworkspace_description_v2', 'local_kbacompany'),
        'meta' => [
            'items' => [
                ['label' => get_string('bmsworkspace_stat_employees', 'local_kbacompany'), 'value' => (string)$org['summary']['employees']],
                ['label' => get_string('bmsworkspace_mytasks', 'local_kbacompany'), 'value' => (string)$counts['tasks']],
            ],
        ],
        'actions' => [
            'items' => [
                ['label' => get_string('bmsarchitecture_title', 'local_kbacompany'), 'url' => $architectureurl->out(false)],
            ],
        ],
    ],
    'contenthtml' => $contenthtml,
];

echo $OUTPUT->header();
echo $useowned
    ? \local_kbacompany\local\bms24_shell_service::render($shell)
    : $OUTPUT->render_from_template('theme_kba/bms/app_shell', $shell);
echo $OUTPUT->footer();
