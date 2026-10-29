<?php
// BMS24 UI Kit 1.0 — read-only design system and live GET filtering on demo records.
require_once(__DIR__ . '/../../config.php');

use local_kbacompany\local\bms24_ui_kit_service as kit;
use local_kbacompany\local\company_context_service;

require_login();
$uuid = required_param('uuid', PARAM_RAW_TRIMMED);
$context = \context_system::instance();
require_capability('local/kbacompany:viewowncompany', $context);
company_context_service::context($uuid, (int)$USER->id);
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET' &&
        ($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'HEAD') {
    throw new moodle_exception('invalidparameter');
}
$PAGE->set_context($context);
$PAGE->set_url(new \moodle_url('/local/kbacompany/bms24_styleguide.php', ['uuid' => $uuid]));
$PAGE->set_title(get_string('bms24_uikit_heading', 'local_kbacompany'));
$PAGE->set_heading(get_string('bms24_uikit_heading', 'local_kbacompany'));
$PAGE->set_pagelayout('standard');
$PAGE->requires->css(new \moodle_url('/local/kbacompany/bms_ui/tokens.css'));
$PAGE->requires->css(new \moodle_url('/local/kbacompany/bms_ui/owned_shell.css'));
$PAGE->requires->css(new \moodle_url('/local/kbacompany/bms_ui/ui_kit.css'));

$tr = static function(string $key): string { return get_string('bms24_uikit_' . $key, 'local_kbacompany'); };
$url = static function(array $params = []) use ($uuid): \moodle_url {
    return new \moodle_url('/local/kbacompany/bms24_styleguide.php', ['uuid' => $uuid] + $params);
};
$status = optional_param('status', 'all', PARAM_ALPHA);
$statuses = ['new', 'doing', 'review', 'done', 'returned', 'paused'];
if ($status !== 'all' && !in_array($status, $statuses, true)) {
    $status = 'all';
}
$query = trim(optional_param('q', '', PARAM_TEXT));
if (\core_text::strlen($query) > 80) {
    $query = \core_text::substr($query, 0, 80);
}
$page = max(0, min(2, optional_param('page', 0, PARAM_INT)));
$demo = [
    ['title' => $tr('task_1'), 'status' => 'new'],
    ['title' => $tr('task_2'), 'status' => 'review'],
    ['title' => $tr('task_3'), 'status' => 'doing'],
    ['title' => $tr('task_4'), 'status' => 'done'],
];
$filtered = array_values(array_filter($demo, static function(array $row) use ($status, $query): bool {
    return ($status === 'all' || $row['status'] === $status) &&
        ($query === '' || strpos(\core_text::strtolower($row['title']), \core_text::strtolower($query)) !== false);
}));
$pages = max(1, (int)ceil(count($filtered) / 2));
$page = min($page, $pages - 1);
$rows = [];
foreach (array_slice($filtered, $page * 2, 2) as $item) {
    $rows[] = [$item['title'], $tr('status_' . $item['status']), $tr('owner')];
}

$swatches = '';
foreach (['Navy' => '#0B2B41', 'Emerald' => '#0C6C52', 'Mint' => '#ECF7F3',
        'Sky' => '#E4F2FB', 'Sand' => '#FDF6ED', 'Blue' => '#1777B5',
        'Violet' => '#6B43B5', 'White' => '#FFFFFF'] as $name => $hex) {
    $swatches .= \html_writer::div(
        \html_writer::div('', 'bms24-ui-color', ['style' => '--swatch:' . $hex]) .
        \html_writer::tag('strong', s($name)) . \html_writer::tag('code', s($hex)),
        'bms24-ui-swatch');
}
$section = static function(string $id, string $title, string $html): string {
    return \html_writer::tag('section', \html_writer::tag('h2', s($title)) . $html,
        ['id' => 'bms24-demo-' . $id, 'class' => 'bms24-ui-section']);
};
$colors = $section('palette', $tr('palette'), \html_writer::div($swatches, 'bms24-ui-flex'));
$buttons = $section('buttons', $tr('buttons'), \html_writer::div(
    kit::button($url(['status' => 'all']) , $tr('primary'), 'primary') .
    kit::button($url(['status' => 'new']), $tr('secondary')) .
    kit::button($url(['status' => 'done']), $tr('quiet'), 'bms24-ui-flex') .
    \html_writer::tag('button', s($tr('danger')),
        ['class' => 'bms24-ui-btn bms24-ui-btn--danger', 'type' => 'button', 'disabled' => 'disabled']),
    'bms24-ui-flex'));
$badges = '';
foreach ($statuses as $key) {
    $badges .= kit::status($tr('status_' . $key), $key);
}
$badges = $section('statuses', $tr('statuses'), \html_writer::div($badges, 'bms24-ui-flex'));
$options = ['all' => $tr('filter_all')];
foreach ($statuses as $key) {
    $options[$key] = $tr('status_' . $key);
}
$filterbar = kit::filterbar($url(), $tr('filter_region'), [
    ['id' => 'bms24_status', 'name' => 'status', 'type' => 'select',
        'label' => $tr('filter'), 'value' => $status, 'options' => $options],
    ['id' => 'bms24_q', 'name' => 'q', 'type' => 'search',
        'label' => $tr('search'), 'value' => $query, 'placeholder' => $tr('demo_name')],
], ['uuid' => $uuid], $url(), $tr('apply'), $tr('reset'));
$forms = $section('forms', $tr('forms'), $filterbar . \html_writer::tag('p', s($tr('hint')),
    ['class' => 'bms24-ui-microcopy']));
$data = count($rows) ? kit::table($tr('table_region'),
    [$tr('col_task'), $tr('col_status'), $tr('col_owner')], $rows) :
    kit::alert($tr('empty'));
$pagination = [];
for ($i = 0; $i < $pages; $i++) {
    $label = (string)($i + 1);
    $pagination[] = ['url' => $url(['status' => $status, 'q' => $query, 'page' => $i]),
        'label' => $label, 'arialabel' => get_string('bms24_uikit_page', 'local_kbacompany', $label),
        'active' => $page === $i];
}
$data .= kit::pagination($tr('pagination'), $pagination);
$data = $section('table', $tr('data'), $data);
$alerts = $section('alerts', $tr('alert_title'),
    kit::alert($tr('alert_info')) . kit::alert($tr('alert_warning'), 'warning') .
    kit::alert($tr('alert_error'), 'error') . kit::alert($tr('alert_success'), 'success'));
$content = \html_writer::start_div('bms24-app bms24-styleguide') .
    \html_writer::start_div('bms24-main', ['style' => 'width:100%']) .
    \html_writer::tag('h1', s($tr('heading'))) .
    \html_writer::tag('p', s($tr('subtitle'))) .
    \html_writer::div($colors . $buttons . $badges . $forms . $data . $alerts, 'bms24-ui-demo') .
    \html_writer::end_div() . \html_writer::end_div();
echo $OUTPUT->header();
echo $content;
echo $OUTPUT->footer();
