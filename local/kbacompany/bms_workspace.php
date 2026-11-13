<?php

require_once(__DIR__ . '/../../config.php');

use local_kbacompany\local\bms_ui_service;
use local_kbacompany\local\bms_main_tool_service;
use local_kbacompany\local\bms_workspace_widget_service;
use local_kbacompany\local\bms_workspace_action_service;
use local_kbacompany\local\bms_workspace_focus_service;

require_login();
$context = context_system::instance();
require_capability('local/kbacompany:viewowncompany', $context);
$uuid = required_param('uuid', PARAM_RAW_TRIMMED);

$PAGE->set_context($context);
// Page-scoped density controls; do not affect other BMS24 routes.
$PAGE->add_body_class('bms24-workspace-page');
$PAGE->requires->css(new moodle_url('/local/kbacompany/styles.css'));
// Autonomous shell renderer: register shared navigation on this route as well.
$PAGE->requires->js_call_amd('local_kbacompany/bms_shell_navigation', 'init');
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

// Authorise company membership before reading any per-company preferences.
// Then resolve the whitelist before loading widget-specific read models.
\local_kbacompany\local\company_context_service::context($uuid, (int)$USER->id);
$canmanagewidgets = \local_kbacompany\local\permission_service::can_manage_management($uuid, (int)$USER->id);
$widgetavailable = bms_workspace_widget_service::available($canmanagewidgets);
$widgetlayout = bms_workspace_widget_service::current($uuid, (int)$USER->id, $widgetavailable);
// Explicitly requested view never bypasses permission and company checks.
$canownerfocus = \local_kbacompany\local\permission_service::can_manage_owner_private($uuid, (int)$USER->id);
$focusmode = bms_workspace_focus_service::select(optional_param('focus', 'all', PARAM_ALPHA),
    $canmanagewidgets, $canownerfocus);
$focusvisible = bms_workspace_focus_service::visible($widgetlayout['visible'], $focusmode);
$widgetactive = array_fill_keys($focusvisible, true);
$model = bms_ui_service::workspace_model($uuid, (int)$USER->id, $focusvisible,
    bms_workspace_focus_service::include_management_decisions($focusmode));
$company = $model['company'];
$widgetsurl = new moodle_url('/local/kbacompany/bms_workspace_widgets.php', ['uuid' => $uuid]);
// Expose only company/user-authorized shortcuts. Do not alter canonical route map.
$mainprefs = bms_main_tool_service::current($uuid, (int)$USER->id, $model['navigation_groups']);
$model['navigation_groups'] = bms_main_tool_service::personalise_groups(
    $uuid, (int)$USER->id, $model['navigation_groups']);
$preferencesurl = new moodle_url('/local/kbacompany/bms_home_preferences.php', ['uuid' => $uuid]);
$homeurl = new moodle_url('/local/kbacompany/bms_home.php', ['uuid' => $uuid]);


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
// Optional read-only role views, not a replacement for global navigation.
$focusnav = html_writer::start_tag('nav', ['class' => 'bms-workspace-role-switch',
    'aria-label' => get_string('bmsfocus_switch_label', 'local_kbacompany')]);
foreach (bms_workspace_focus_service::modes($canmanagewidgets, $canownerfocus) as $mode) {
    $target = new moodle_url('/local/kbacompany/bms_workspace.php', ['uuid' => $uuid, 'focus' => $mode]);
    $attr = ['class' => 'bms-workspace-role-choice' . ($mode === $focusmode ? ' is-current' : '')];
    if ($mode === $focusmode) { $attr['aria-current'] = 'page'; }
    $focusnav .= html_writer::link($target, get_string('bmsfocus_tab_' . $mode, 'local_kbacompany'), $attr);
}
$focusnav .= html_writer::end_tag('nav');
$focusdescription = html_writer::tag('p',
    get_string('bmsfocus_desc_' . $focusmode, 'local_kbacompany'),
    ['class' => 'bms-workspace-role-description']);
$focusactions = '';
if ($focusmode === 'management' || $focusmode === 'owner') {
    // A manager/owner link is surfaced only when the canonical permission grants access.
    if ($canmanagewidgets) {
        $focusactions .= html_writer::link(new moodle_url('/local/kbacompany/management.php', ['uuid' => $uuid]),
            get_string('bmsfocus_open_management', 'local_kbacompany'), ['class' => 'bms24-ui-btn']);
    }
    if ($focusmode === 'owner' && $canownerfocus) {
        $focusactions .= html_writer::link(new moodle_url('/local/kbacompany/owner.php', ['uuid' => $uuid]),
            get_string('bmsfocus_open_owner', 'local_kbacompany'), ['class' => 'bms24-ui-btn']);
    }
}
$focushtml = html_writer::tag('section', $focusnav . html_writer::div($focusdescription . $focusactions,
    'bms-workspace-role-detail'), ['class' => 'bms-workspace-role-panel']);

$workctx = $model['communication_context'];
$taskctx = $workctx['task_center'];
$org = $model['organization'];
$processctx = $model['process_context'];
$counts = $model['workspace_counters'];
// Hidden Today card must not trigger the separate calendar aggregation.
$todayctx = isset($widgetactive['today'])
    ? \local_kbacompany\local\calendar_workspace_service::today_snapshot($company['uuid'], (int)$USER->id)
    : null;

$weekstart = '';
$myplan = null;
$planlink = new moodle_url('/local/kbacompany/battle_plan.php', ['uuid' => $uuid]);
if (isset($widgetactive['plan'])) {
    $weekstart = \local_kbacompany\local\battle_plan_service::week_start(\local_kbacompany\local\battle_plan_service::user_today());
    $planrecords = $DB->get_records('local_kbacompany_bplan', [
        'companyuuid' => $uuid, 'userid' => (int)$USER->id, 'periodstart' => $weekstart,
    ], 'id DESC', 'id,uuid,status', 0, 1);
    $myplan = $planrecords ? reset($planrecords) : null;
}


$fallbacktasks = [];
foreach (array_slice($taskctx['tasks'], 0, 5) as $task) {
    $fallbacktasks[] = [
        'title' => (string)$task['title'],
        'url' => (string)($task['source_url'] ?? ''),
        'source' => (string)($task['provider_label'] ?? ''),
    ];
}

// RC29/1809: one actor-actionable queue from existing scoped services.
// Loading the queue is conditional on the visible attention widget.
$actionrows = [];
if (isset($widgetactive['attention'])) {
    $planreviews = bms_workspace_action_service::plans($uuid, (int)$USER->id);
    $taskreviews = bms_workspace_action_service::task_reviews($uuid, (int)$USER->id);
    $managerexceptions = !empty($model['management_home']['available']) && $canmanagewidgets
        ? (array)($model['management_home']['exceptions']['rows'] ?? []) : [];
    $actionrows = bms_workspace_action_service::project($uuid, (int)$USER->id,
        (array)($taskctx['tasks'] ?? []), $taskreviews, $planreviews, $managerexceptions,
        \local_kbacompany\local\battle_plan_service::user_today(), $focusmode === 'all' ? 8 : 30);
    $actionrows = bms_workspace_focus_service::actions($actionrows, $focusmode);
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
    // Moodle standard-layout fallback respects saved order and visibility too.
    $fallbackcards = [];
    $fallbackmake = static function(string $key, string $body): string {
        return html_writer::tag('article',
            html_writer::tag('h2', get_string('bmswidgets_name_' . $key, 'local_kbacompany'), ['class' => 'h5']) .
            $body, ['class' => 'card p-3 bms-workspace-widget bms-workspace-widget--' .
                (in_array($key, ['plan', 'actions', 'architecture', 'company'], true) ? 'half' : 'full')]);
    };
    if (isset($widgetactive['today'])) {
    $fallbackcards['today'] = $fallbackmake('today', $todayctx ?
        html_writer::tag('p', get_string('bmsworkspace_today_events', 'local_kbacompany',
            (int)($todayctx['summary']['events'] ?? 0))) .
        html_writer::link($todayctx['calendar_url'], get_string('bmsworkspace_today_open_calendar', 'local_kbacompany')) : '');
    }
    $fallbackcards['plan'] = $fallbackmake('plan', $myplan ?
        html_writer::tag('p', get_string('battleplan_status_' . $myplan->status, 'local_kbacompany')) .
        html_writer::link((new moodle_url('/local/kbacompany/battle_plan.php',
            ['uuid' => $uuid, 'plan' => $myplan->uuid, 'view' => 'detail'])),
            get_string('bmsmain_plan_open', 'local_kbacompany')) :
        html_writer::tag('p', get_string('bmsmain_plan_empty', 'local_kbacompany')) .
        html_writer::link($planlink, get_string('bmsmain_plan_to_list', 'local_kbacompany')));
    $fallbackcards['actions'] = $fallbackmake('actions',
        html_writer::link($taskurl, get_string('bmsworkspace_quick_task', 'local_kbacompany')) . ' · ' .
        html_writer::link($communicationurl, get_string('bmsworkspace_quick_message', 'local_kbacompany')));
    $fallbackstats = '';
    foreach (['incoming', 'tasks', 'control', 'learning'] as $statkey) {
        $fallbackstats .= html_writer::tag('p',
            s(get_string('bmsworkspace_' . ($statkey === 'tasks' ? 'mytasks' : $statkey), 'local_kbacompany')) .
            ': ' . (int)($counts[$statkey] ?? 0));
    }
    $fallbackcards['overview'] = $fallbackmake('overview', $fallbackstats);
    $fallbackattention = '';
    foreach ($attentionitems as $item) {
        $fallbackattention .= html_writer::tag('p', html_writer::link($item['url'], s($item['label'])));
    }
    $fallbackactionhtml = '';
    foreach ($actionrows as $row) {
        $fallbackactionhtml .= html_writer::tag('p',
            html_writer::link(new moodle_url($row['url']), s($row['title']) . ' — ' .
                s(get_string($row['action'], 'local_kbacompany'))));
    }
    $fallbackcards['attention'] = $fallbackmake('attention',
        $fallbackactionhtml ?: get_string('bmsaction_empty', 'local_kbacompany'));
    $fallbacktaskshtml = '';
    foreach ($fallbacktasks as $task) {
        $fallbacktaskshtml .= html_writer::tag('p',
            html_writer::link($task['url'], s($task['title'])));
    }
    $fallbackcards['tasks'] = $fallbackmake('tasks', $fallbacktaskshtml ?: get_string('bmsworkspace_notasks', 'local_kbacompany'));
    if (!empty($model['management_home']['available'])) {
        $fallbackcards['management'] = $fallbackmake('management',
            html_writer::link(new moodle_url('/local/kbacompany/battle_plan_overview.php', ['uuid' => $uuid]),
                get_string('bmsmanagement_open_week', 'local_kbacompany')));
    }
    if (isset($widgetactive['architecture'])) {
    $fallbackcards['architecture'] = $fallbackmake('architecture',
        html_writer::link($architectureurl, get_string('bmsarchitecture_open', 'local_kbacompany')));
    }
    $fallbackcards['company'] = $fallbackmake('company', s(format_string($company['name'])));
    $fallbackwidgethtml = '';
    foreach ($widgetlayout['order'] as $key) {
        if (isset($widgetactive[$key], $fallbackcards[$key])) {
            $fallbackwidgethtml .= $fallbackcards[$key];
        }
    }
    if ($fallbackwidgethtml === '') {
        $fallbackwidgethtml = html_writer::tag('p', get_string('bmsfocus_empty', 'local_kbacompany')) .
            html_writer::link($widgetsurl, get_string('bmswidgets_title', 'local_kbacompany'));
    }
    $fallback = [
        'companyname' => format_string($company['name']),
        'description' => get_string('bmsworkspace_focus_description', 'local_kbacompany'),
        'groups' => $model['navigation_groups'],
        'widgetcontenthtml' => html_writer::div($fallbackwidgethtml, 'bms-workspace-card-grid'),
        'focushtml' => $focushtml,
        'preferencesurl' => $preferencesurl->out(false),
        'widgetsurl' => $widgetsurl->out(false),
        'taskurl' => $taskurl->out(false),
        'communicationurl' => $communicationurl->out(false),
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
$today = \local_kbacompany\local\battle_plan_service::user_today();
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
            ['class' => 'bms24-ui-btn']),
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
$actionbody = '';
if ($actionrows) {
    $actionbody .= html_writer::start_div('bms-action-queue list-group list-group-flush');
    foreach ($actionrows as $row) {
        $priorityclass = $row['rank'] <= 15 ? 'bms-action-queue--urgent' : '';
        $label = html_writer::tag('strong', s($row['title']), ['class' => 'bms-action-queue-title']);
        $detail = html_writer::span(s(get_string($row['action'], 'local_kbacompany')),
            'bms-action-queue-verb');
        if ($row['meta'] !== '') {
            $detail .= html_writer::span(s($row['meta']), 'bms-action-queue-meta');
        }
        $actionbody .= html_writer::link(new moodle_url($row['url']),
            html_writer::div($label . $detail, 'bms-action-queue-text'),
            ['class' => 'list-group-item list-group-item-action bms-action-queue-row ' . $priorityclass]);
    }
    $actionbody .= html_writer::end_div();
} else {
    $actionbody = $render('empty_state', [
        'title' => get_string('bmsaction_empty', 'local_kbacompany'),
        'text' => get_string('bmsaction_empty_details', 'local_kbacompany'),
    ]);
}
$actioncard = $render('card', [
    'header' => [
        'eyebrow' => get_string('bmsworkspace_attention_eyebrow', 'local_kbacompany'),
        'title' => get_string('bmsaction_title', 'local_kbacompany'),
        'subtitle' => get_string('bmsaction_subtitle', 'local_kbacompany'),
    ],
    'contenthtml' => $actionbody,
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
        ['class' => 'bms24-ui-btn bms24-ui-btn--primary']),
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
        ['class' => 'bms24-ui-btn bms24-ui-btn--primary']), 'mt-3');
    $executioncard = $render('card', [
        'header' => [
            'eyebrow' => get_string('bmsmanagement_execution_eyebrow', 'local_kbacompany'),
            'title' => get_string('bmsmanagement_execution_title', 'local_kbacompany'),
            'subtitle' => get_string('bmsmanagement_execution_subtitle', 'local_kbacompany'),
        ],
        'contenthtml' => $execution,
    ]);


}

// Personal Today block: read-only projection of the existing calendar/work domains.
$todayhtml = '';
if (isset($widgetactive['today'])) {
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
        'actionshtml' => html_writer::link($todayctx['calendar_url'], get_string('bmsworkspace_today_open_calendar', 'local_kbacompany'), ['class' => 'bms24-ui-btn']),
    ],
    'contenthtml' => $todaybody,
    'accent' => ((int)$todayctx['summary']['next_hour'] > 0 || (int)$todayctx['summary']['tasks_due'] > 0) ? 'warning' : 'brand',
]);
$todayhtml = $todaycard;
// Quiet days previously consumed a full-width card just to say there was
// nothing else scheduled. Keep accurate counters and one calendar action.
$todayquiet = empty($todayctx['next']) &&
    (int)($todayctx['summary']['tasks_due'] ?? 0) === 0 &&
    (int)($todayctx['summary']['next_hour'] ?? 0) === 0;
if ($todayquiet) {
    $todaylabel = html_writer::tag('strong', get_string('bmsworkspace_today_title', 'local_kbacompany')) .
        html_writer::span(s($todayctx['date_label']), 'bms-workspace-today-date');
    $todaycaption = html_writer::span(get_string('bmsworkspace_today_empty', 'local_kbacompany'),
        'bms-workspace-today-quiet');
    $todaylink = html_writer::link($todayctx['calendar_url'],
        get_string('bmsworkspace_today_open_calendar', 'local_kbacompany'),
        ['class' => 'bms24-ui-btn']);
    $todayhtml = html_writer::tag('section',
        html_writer::div($todaylabel . $todaycaption, 'bms-workspace-today-summary') .
        html_writer::div($todaybadges, 'bms-workspace-today-counters') . $todaylink,
        ['class' => 'bms-workspace-today-strip',
            'aria-label' => get_string('bmsworkspace_today_title', 'local_kbacompany')]);
}

 }

// No invented result rates: the card reads only the signed-in user's canonical
// current-week plan and leads to its real object. Missing plan stays an empty state.
$plancard = '';
if (isset($widgetactive['plan'])) {
if ($myplan) {
    $planlink->param('plan', $myplan->uuid);
    $planlink->param('view', 'detail');
    $planbody = html_writer::tag('p',
        get_string('battleplan_status_' . $myplan->status, 'local_kbacompany'),
        ['class' => 'bms-home-plan-state']) .
        html_writer::link($planlink, get_string('bmsmain_plan_open', 'local_kbacompany'),
            ['class' => 'bms24-ui-btn bms24-ui-btn--primary']);
} else {
    $planbody = html_writer::tag('p', get_string('bmsmain_plan_empty', 'local_kbacompany'),
        ['class' => 'text-muted']) .
        html_writer::link($planlink, get_string('bmsmain_plan_to_list', 'local_kbacompany'),
            ['class' => 'bms24-ui-btn']);
}
$plancard = $render('card', [
    'header' => ['eyebrow' => get_string('bmsmain_personal_eyebrow', 'local_kbacompany'),
        'title' => get_string('bmsmain_plan_title', 'local_kbacompany'),
        'subtitle' => get_string('bmsmain_plan_week', 'local_kbacompany', $weekstart)],
    'contenthtml' => $planbody,
]);
}
// Action verbs, not a second copy of all sidebar destinations. Every URL
// targets an existing internal route; no write happens before the form POST.
$actionsbody = html_writer::start_div('bms-main-quicklinks bms-main-quicklinks--actions');
$mainroutes = bms_main_tool_service::visible_routes($model['navigation_groups']);
if (isset($mainroutes['tasks'])) {
    $createurl = new moodle_url('/local/kbacompany/tasks.php', ['uuid' => $uuid, 'view' => 'create']);
    $actionsbody .= html_writer::link($createurl,
        get_string('taskcenter_create', 'local_kbacompany'),
        ['class' => 'bms24-ui-btn bms24-ui-btn--primary']);
}
if (isset($mainroutes['battleplan'])) {
    $actionsbody .= html_writer::link($planlink,
        $myplan ? get_string('bmsmain_plan_open', 'local_kbacompany') :
            get_string('bmsmain_plan_to_list', 'local_kbacompany'),
        ['class' => 'bms24-ui-btn']);
}
if (isset($mainroutes['communications'])) {
    $actionsbody .= html_writer::link($communicationurl,
        get_string('bmsworkspace_quick_message', 'local_kbacompany'),
        ['class' => 'bms24-ui-btn']);
}
$actionsbody .= html_writer::end_div();
$actionscard = $render('card', [
    'header' => ['eyebrow' => get_string('bmsmain_personal_eyebrow', 'local_kbacompany'),
        'title' => get_string('bmsmain_actions_title', 'local_kbacompany')],
    'contenthtml' => $actionsbody,
]);
// New accounts see a one-time, dismissible native dialog; existing BMS24 users
// retain their current workspace without being interrupted by a forced onboarding.
$rollout = (int)get_config('local_kbacompany', 'bms_main_tool_introduced');
$showintro = !$mainprefs['intro_dismissed'] && $rollout > 0 &&
    (int)$USER->timecreated >= $rollout;
$introhtml = '';
if ($showintro) {
    $introformurl = new moodle_url('/local/kbacompany/bms_home_preferences.php', ['uuid' => $uuid]);
    $introhtml .= html_writer::start_tag('dialog', ['id' => 'bms-main-intro',
        'class' => 'bms-main-intro-dialog', 'aria-labelledby' => 'bms-main-intro-title']);
    $introhtml .= html_writer::tag('h2', get_string('bmsmain_page_title', 'local_kbacompany'),
        ['id' => 'bms-main-intro-title', 'class' => 'h4']);
    $introhtml .= html_writer::tag('p', get_string('bmsmain_page_intro', 'local_kbacompany'),
        ['class' => 'text-muted']);
    $introhtml .= html_writer::start_tag('form', ['action' => $introformurl->out(false), 'method' => 'post']);
    $introhtml .= html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'sesskey', 'value' => sesskey()]);
    $introhtml .= html_writer::start_div('bms-main-tool-grid');
    $introoptions = bms_main_tool_service::main_options($model['navigation_groups']);
    foreach ($introoptions as $key => $route) {
        $id = 'bms-intro-' . $key;
        $radio = html_writer::empty_tag('input', [
            'type' => 'radio', 'name' => 'main', 'id' => $id, 'value' => $key,
            'checked' => $mainprefs['main'] === $key ? 'checked' : null,
            'required' => 'required',
        ]);
        $text = html_writer::tag('strong', s($route['label'])) .
            html_writer::tag('span', get_string('bmsmain_desc_' . $key, 'local_kbacompany'),
                ['class' => 'bms-main-option-description']);
        $introhtml .= html_writer::tag('label', $radio . html_writer::div($text, 'bms-main-option-text'),
            ['class' => 'bms-main-option', 'for' => $id]);
    }
    $introhtml .= html_writer::end_div();
    $introhtml .= html_writer::div(
        html_writer::tag('button', get_string('bmsmain_save_button', 'local_kbacompany'),
            ['type' => 'submit', 'name' => 'action', 'value' => 'save',
                'class' => 'bms24-ui-btn bms24-ui-btn--primary']) . ' ' .
        html_writer::tag('button', get_string('bmsmain_later', 'local_kbacompany'),
            ['type' => 'submit', 'name' => 'action', 'value' => 'later',
                'formnovalidate' => 'formnovalidate', 'class' => 'bms24-ui-btn']),
        'bms-main-dialog-actions');
    $introhtml .= html_writer::end_tag('form');
    $introhtml .= html_writer::end_tag('dialog');
    $PAGE->requires->js(new moodle_url('/local/kbacompany/js/bms_main_intro.js'));
}

// The main tool is already available in the sidebar. The persistent onboarding
// banner was duplicate navigation; keep only the one-time first-use dialog.
$personalisation = $introhtml;


// One whitelisted widget map. Rendering order and visibility only affect presentation.
// No arbitrary user-supplied HTML/route is ever rendered.
$widgetcards = [
    'today' => $todayhtml,
    'plan' => $plancard,
    'actions' => $actionscard,
    'overview' => $overviewcard,
    'attention' => $actioncard,
    'tasks' => $taskcard,
    'architecture' => $architecturecard,
    'company' => $companycard,
];
if (!empty($managementhome['available'])) {
    $widgetcards['management'] = $executioncard;
}
$widths = ['today' => 'full', 'plan' => 'half', 'actions' => 'half', 'overview' => 'full',
    'attention' => 'wide', 'tasks' => 'narrow', 'management' => 'full',
    'architecture' => 'half', 'company' => 'half'];
$contenthtml = html_writer::start_div('bms-home-simple bms-workspace-1808');
if ($personalisation !== '') {
    $contenthtml .= $personalisation;
}
$contenthtml .= $focushtml;
$contenthtml .= html_writer::start_div('bms-workspace-card-grid');
foreach ($widgetlayout['order'] as $key) {
    if (!isset($widgetactive[$key]) || empty($widgetcards[$key])) {
        continue;
    }
    $contenthtml .= html_writer::div($widgetcards[$key],
        'bms-workspace-widget bms-workspace-widget--' . $widths[$key] . ' bms-workspace-widget--' . $key, ['data-widget-id' => $key]);
}
if (!$focusvisible) {
    $contenthtml .= html_writer::tag('p', get_string('bmsfocus_empty', 'local_kbacompany')) .
        html_writer::link($widgetsurl, get_string('bmswidgets_title', 'local_kbacompany'),
            ['class' => 'bms24-ui-btn']);
}
$contenthtml .= html_writer::end_div();
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
        'brandmarkhtml' => html_writer::link($homeurl, html_writer::span('BMS24', 'bms-sidebar-brandmark-text'), ['class' => 'bms-brand-home-link', 'aria-label' => 'BMS24 — ' . get_string('bmsworkspace_nav', 'local_kbacompany')]),
        'brandlabel' => trim(preg_replace('/^BMS24\s*[—-]\s*/u', '', get_string('pluginname', 'local_kbacompany'))),
        'togglelabel' => get_string('bmsworkspace_collapse', 'local_kbacompany'),
        'groups' => $model['navigation_groups'],
        'utilityitems' => [[
            'label' => get_string('bmswidgets_title', 'local_kbacompany'),
            'url' => $widgetsurl->out(false),
            'active' => false,
            'iconhtml' => $OUTPUT->pix_icon('i/settings', '', 'moodle', ['class' => 'icon']),
        ], [
            'label' => get_string('bmsmain_change', 'local_kbacompany'),
            'url' => $preferencesurl->out(false),
            'active' => false,
            'iconhtml' => $OUTPUT->pix_icon('i/settings', '', 'moodle', ['class' => 'icon']),
        ], [
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
        'title' => get_string('bmsworkspace_focus_title', 'local_kbacompany'),
        'description' => get_string('bmsworkspace_focus_description', 'local_kbacompany'),
        'meta' => ['items' => []],
        'actions' => [
            'items' => [
                ['label' => get_string('bmswidgets_title', 'local_kbacompany'), 'url' => $widgetsurl->out(false)],
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
