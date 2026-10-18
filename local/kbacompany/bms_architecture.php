<?php

require_once(__DIR__ . '/../../config.php');

use local_kbacompany\local\bms_ui_service;

require_login();
$context = context_system::instance();
require_capability('local/kbacompany:viewowncompany', $context);
$uuid = required_param('uuid', PARAM_RAW_TRIMMED);
$stage = optional_param('stage', '', PARAM_ALPHANUMEXT);
$focus = optional_param('focus', '', PARAM_ALPHANUMEXT);

$PAGE->set_context($context);
$PAGE->requires->css(new moodle_url('/local/kbacompany/styles.css'));
$PAGE->set_url(new moodle_url('/local/kbacompany/bms_architecture.php', [
    'uuid' => $uuid,
    'stage' => $stage,
    'focus' => $focus,
]));
$PAGE->set_title(get_string('bmsarchitecture_title', 'local_kbacompany'));
$PAGE->set_heading(get_string('pluginname', 'local_kbacompany'));

$useowned = \local_kbacompany\local\bms24_shell_service::is_enabled();
$usetheme = !$useowned && bms_ui_service::supports_kba_shell();
if ($useowned) {
    \local_kbacompany\local\bms24_shell_service::prepare_page();
} else {
    $PAGE->set_pagelayout($usetheme ? 'bms' : 'standard');
}

$model = bms_ui_service::architecture_model($uuid, (int)$USER->id, $stage, $focus);
$company = $model['company'];
$org = $model['organization'];
$processes = $model['process_context'];
$functions = $model['function_context'];
$routes = $model['route_map'];
$flow = $model['architecture_flow'];
$selected = $model['architecture_selected'];
$stageworkspace = $model['architecture_stage_workspace'];
$recommended = $model['architecture_recommended'];

$rendericon = static function(?array $icon) use ($OUTPUT): string {
    if (empty($icon['key'])) {
        return '';
    }
    return $OUTPUT->pix_icon((string)$icon['key'], '', (string)($icon['component'] ?? 'moodle'), ['class' => 'icon']);
};
$decoratenodes = static function(array &$nodes) use (&$decoratenodes, $rendericon): void {
    foreach ($nodes as &$node) {
        $node['iconhtml'] = $rendericon($node['icon'] ?? null);
        unset($node['icon']);
        if (!empty($node['children']) && is_array($node['children'])) {
            $decoratenodes($node['children']);
        }
    }
    unset($node);
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
$decoratenodes($model['tree']['nodes']);

$workspaceurl = new moodle_url($routes['workspace']['url']);
$companyurl = new moodle_url('/local/kbacompany/bms_workspace.php', ['uuid' => $company['uuid']]);
$settingsurl = new moodle_url('/local/kbacompany/bms_settings.php', ['uuid' => $company['uuid']]);
$planurl = new moodle_url('/local/kbacompany/bms_settings.php', ['uuid' => $company['uuid'], 'tab' => 'plan']);

$statusclass = static function(string $variant): string {
    if ($variant === 'success') { return 'badge badge-success'; }
    if ($variant === 'warning') { return 'badge badge-warning'; }
    if ($variant === 'danger') { return 'badge badge-danger'; }
    return 'badge badge-secondary';
};

if (!$usetheme && !$useowned) {
    echo $OUTPUT->header();
    echo $OUTPUT->heading(get_string('bmsarchitecture_title', 'local_kbacompany'));
    echo html_writer::tag('p', get_string('bmsarchitecture_description', 'local_kbacompany'), ['class' => 'lead']);

    echo html_writer::tag('h2', get_string('bmsarchitecture_logic_title', 'local_kbacompany'), ['class' => 'h4 mt-4']);
    echo html_writer::start_tag('ol', ['class' => 'mb-4']);
    foreach ($flow as $step) {
        $label = s($step['label']) . ' — ' . (int)$step['progress'] . '% (' . s($step['state_label']) . ')';
        echo html_writer::tag('li', html_writer::link($step['url'], $label) .
            html_writer::tag('div', s($step['description']), ['class' => 'text-muted small mb-2']));
    }
    echo html_writer::end_tag('ol');

    echo html_writer::start_tag('div', ['class' => 'row']);
    echo html_writer::start_tag('aside', ['class' => 'col-lg-4']);
    echo html_writer::tag('h2', get_string('bmsworkspace_tree_label', 'local_kbacompany'), ['class' => 'h5']);
    foreach ($model['tree']['nodes'] as $group) {
        echo html_writer::tag('h3', s($group['label']), ['class' => 'h6 mt-3']);
        echo html_writer::start_tag('ul', ['class' => 'list-unstyled']);
        foreach ($group['children'] as $node) {
            $class = !empty($node['active']) ? 'mb-2 font-weight-bold' : 'mb-2';
            echo html_writer::tag('li', html_writer::link($node['url'], s($node['label'])), ['class' => $class]);
        }
        echo html_writer::end_tag('ul');
    }
    echo html_writer::end_tag('aside');

    echo html_writer::start_tag('main', ['class' => 'col-lg-8']);
    echo html_writer::tag('h2', s($stageworkspace['title']), ['class' => 'h4']);
    echo html_writer::tag('p', s($stageworkspace['description']), ['class' => 'text-muted']);
    echo html_writer::tag('p', s($stageworkspace['state_label']) . ' — ' . (int)$stageworkspace['progress'] . '%');
    echo html_writer::tag('div', s($stageworkspace['guidance']), ['class' => 'alert alert-info']);
    echo html_writer::start_tag('ul');
    foreach ($stageworkspace['facts'] as $fact) {
        echo html_writer::tag('li', s($fact['label']) . ': ' . (int)$fact['value']);
    }
    echo html_writer::end_tag('ul');
    foreach ($stageworkspace['actions'] as $action) {
        echo html_writer::link($action['url'], s($action['label']), [
            'class' => !empty($action['primary']) ? 'btn btn-primary mr-2 mb-2' : 'btn btn-outline-secondary mr-2 mb-2',
        ]);
    }
    if ($recommended) {
        echo html_writer::tag('p', get_string('bmsarchitecture_nextstep', 'local_kbacompany') . ': ' .
            html_writer::link($recommended['url'], s($recommended['label'])), ['class' => 'mt-3']);
    } else {
        echo html_writer::tag('p', get_string('bmsarchitecture_allready', 'local_kbacompany'), ['class' => 'mt-3']);
    }
    echo html_writer::end_tag('main');
    echo html_writer::end_tag('div');
    echo $OUTPUT->footer();
    exit;
}

$render = static function(string $template, array $context) use ($OUTPUT, $useowned): string {
    return $useowned
        ? \local_kbacompany\local\bms24_shell_service::fragment($template, $context)
        : $OUTPUT->render_from_template('theme_kba/bms/' . $template, $context);
};

// Guided design sequence: the cards stay inside the architecture center and
// select a stage instead of immediately throwing the user into another module.
$logichtml = html_writer::start_div('row');
foreach ($flow as $step) {
    $isselected = $step['key'] === $selected['key'];
    $badge = html_writer::span((string)$step['number'], 'badge badge-success badge-pill mr-2');
    $state = html_writer::span(s($step['state_label']) . ' · ' . (int)$step['progress'] . '%',
        $statusclass($step['variant']) . ' ml-2');
    $title = html_writer::div($badge . html_writer::span(s($step['label']), 'font-weight-bold') . $state, 'mb-2');
    $description = html_writer::div(s($step['description']), 'small text-muted');
    $content = $title . $description;
    $classes = 'd-block h-100 p-3 border rounded text-reset text-decoration-none bg-white';
    if ($isselected) {
        $classes .= ' border-success';
    }
    $logichtml .= html_writer::div(
        html_writer::link($step['url'], $content, ['class' => $classes]),
        'col-12 col-md-6 col-xl-3 mb-2'
    );
}
$logichtml .= html_writer::end_div();
$logiccard = $render('card', [
    'header' => [
        'eyebrow' => get_string('bmsarchitecture_eyebrow', 'local_kbacompany'),
        'title' => get_string('bmsarchitecture_logic_title', 'local_kbacompany'),
        'subtitle' => get_string('bmsarchitecture_guided_subtitle', 'local_kbacompany'),
    ],
    'contenthtml' => $logichtml,
    'accent' => 'brand',
]);

// Keep compact global architecture counters, but remove the duplicated second
// eight-block architecture catalogue from the previous release.
$stats = [];
$stats[] = $render('stat_card', [
    'label' => get_string('company_processes', 'local_kbacompany'),
    'value' => (string)$processes['summary']['total'],
    'caption' => get_string('bmsarchitecture_process_caption', 'local_kbacompany'),
]);
$stats[] = $render('stat_card', [
    'label' => get_string('company_functions', 'local_kbacompany'),
    'value' => (string)count($functions['functions']),
    'caption' => get_string('bmsarchitecture_function_caption', 'local_kbacompany'),
]);
$stats[] = $render('stat_card', [
    'label' => get_string('bmsworkspace_stat_units', 'local_kbacompany'),
    'value' => (string)$org['summary']['org_units'],
    'caption' => get_string('bmsworkspace_stat_units_caption', 'local_kbacompany'),
]);
$stats[] = $render('stat_card', [
    'label' => get_string('bmsworkspace_stat_positions', 'local_kbacompany'),
    'value' => (string)$org['summary']['positions'],
    'caption' => get_string('bmsworkspace_stat_positions_caption', 'local_kbacompany'),
]);
$stathtml = html_writer::start_div('row g-3 mb-3');
foreach ($stats as $stat) {
    $stathtml .= html_writer::div($stat, 'col-12 col-sm-6 col-xl-3');
}
$stathtml .= html_writer::end_div();

// Selected guided stage work area.
$progressbar = html_writer::start_div('progress mb-3', [
    'style' => 'height: 8px;',
    'role' => 'progressbar',
    'aria-valuenow' => (string)$stageworkspace['progress'],
    'aria-valuemin' => '0',
    'aria-valuemax' => '100',
]);
$progressbar .= html_writer::div('', 'progress-bar bg-success', [
    'style' => 'width: ' . (int)$stageworkspace['progress'] . '%;',
]);
$progressbar .= html_writer::end_div();

$stagecontent = html_writer::start_div('mb-3');
$stagecontent .= html_writer::span(s($stageworkspace['state_label']), $statusclass($stageworkspace['variant']));
$stagecontent .= html_writer::span((int)$stageworkspace['progress'] . '%', 'ml-2 font-weight-bold');
$stagecontent .= html_writer::end_div();
$stagecontent .= $progressbar;
$stagecontent .= html_writer::div(s($stageworkspace['guidance']), 'alert alert-info mb-3');

$stagecontent .= html_writer::start_div('row mb-3');
foreach ($stageworkspace['facts'] as $fact) {
    $facthtml = html_writer::div(s($fact['label']), 'small text-muted mb-1');
    $facthtml .= html_writer::div((string)(int)$fact['value'], 'h4 mb-0');
    $stagecontent .= html_writer::div(
        html_writer::div($facthtml, 'border rounded p-3 h-100 bg-white'),
        'col-12 col-sm-6 col-xl-4 mb-2'
    );
}
$stagecontent .= html_writer::end_div();

$stagecontent .= html_writer::tag('h3', get_string('bmsarchitecture_actions', 'local_kbacompany'), ['class' => 'h6']);
$stagecontent .= html_writer::start_div('mb-3');
foreach ($stageworkspace['actions'] as $action) {
    $stagecontent .= html_writer::link($action['url'], s($action['label']), [
        'class' => !empty($action['primary']) ? 'btn btn-success mr-2 mb-2' : 'btn btn-outline-secondary mr-2 mb-2',
    ]);
}
$stagecontent .= html_writer::end_div();

if ($recommended) {
    $nexttext = get_string('bmsarchitecture_nextstep', 'local_kbacompany') . ': ' . s($recommended['label']);
    if ($stageworkspace['is_recommended']) {
        $nexttext = get_string('bmsarchitecture_thisisnext', 'local_kbacompany') . ' ' . s($recommended['label']);
    }
    $stagecontent .= html_writer::div(
        html_writer::span($nexttext, 'font-weight-bold mr-2') .
        html_writer::link($recommended['url'], get_string('bmsarchitecture_openstep', 'local_kbacompany')),
        'border rounded p-3 bg-light'
    );
} else {
    $stagecontent .= html_writer::div(get_string('bmsarchitecture_allready', 'local_kbacompany'),
        'alert alert-success mb-0');
}

$stagecard = $render('card', [
    'header' => [
        'eyebrow' => get_string('bmsarchitecture_stage_number', 'local_kbacompany', $stageworkspace['number']),
        'title' => $stageworkspace['title'],
        'subtitle' => $stageworkspace['description'],
    ],
    'contenthtml' => $stagecontent,
    'accent' => 'brand',
]);

$principlecard = $render('card', [
    'header' => [
        'eyebrow' => 'BMS24',
        'title' => get_string('bmsarchitecture_principle_title', 'local_kbacompany'),
    ],
    'contenthtml' => html_writer::tag('p', get_string('bmsarchitecture_principle_text', 'local_kbacompany'),
        ['class' => 'mb-0 lead']),
]);

$workspacehtml = $stathtml . $stagecard . html_writer::div($principlecard, 'mt-3');
$navigatorhtml = $render('tree_navigator', array_merge($model['tree'], [
    'toolbarhtml' => html_writer::tag('strong', get_string('bmsworkspace_tree_label', 'local_kbacompany')),
]));
$splithtml = $render('split_view', [
    'navigatorhtml' => $navigatorhtml,
    'workspacehtml' => $workspacehtml,
    'wideleft' => false,
]);
$contenthtml = $logiccard . html_writer::div($splithtml, 'mt-3');

$shell = [
    'sidebar' => [
        'arialabel' => get_string('bmsworkspace_sidebar_label', 'local_kbacompany'),
        'brandmarkhtml' => html_writer::link($workspaceurl, html_writer::span('BMS24', 'bms-sidebar-brandmark-text'), ['class' => 'bms-brand-home-link', 'aria-label' => 'BMS24 — ' . get_string('bmsworkspace_nav', 'local_kbacompany')]),
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
                ['label' => get_string('bmsarchitecture_title', 'local_kbacompany'), 'url' => null],
            ],
        ],
        'quickactionhtml' => html_writer::link($workspaceurl, get_string('bmsworkspace_nav', 'local_kbacompany'),
            ['class' => 'kba-bms-button']),
        'planhtml' => html_writer::link($planurl, get_string('bms_plan_title', 'local_kbacompany'),
            ['class' => 'kba-bms-button kba-bms-button--plan']),
        'notificationshtml' => '',
        'usermenuhtml' => $OUTPUT->user_menu(),
    ],
    'header' => [
        'eyebrow' => get_string('bmsarchitecture_eyebrow', 'local_kbacompany'),
        'title' => get_string('bmsarchitecture_title', 'local_kbacompany'),
        'description' => get_string('bmsarchitecture_description', 'local_kbacompany'),
        'meta' => ['items' => []],
        'actions' => ['items' => []],
    ],
    'contenthtml' => $contenthtml,
];

echo $OUTPUT->header();
echo $useowned
    ? \local_kbacompany\local\bms24_shell_service::render($shell)
    : $OUTPUT->render_from_template('theme_kba/bms/app_shell', $shell);
echo $OUTPUT->footer();
