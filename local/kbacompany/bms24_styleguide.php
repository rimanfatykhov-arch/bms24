<?php
require_once(__DIR__ . '/../../config.php');

require_login();
$uuid = required_param('uuid', PARAM_RAW_TRIMMED);
$context = \context_system::instance();
require_capability('local/kbacompany:viewowncompany', $context);
\local_kbacompany\local\company_context_service::context($uuid, (int)$USER->id);
$PAGE->set_context($context);
$PAGE->set_url(new \moodle_url('/local/kbacompany/bms24_styleguide.php', ['uuid' => $uuid]));
$PAGE->set_title(get_string('bms24_styleguide', 'local_kbacompany'));
$PAGE->set_heading(get_string('bms24_styleguide', 'local_kbacompany'));
$PAGE->set_pagelayout('standard');
$PAGE->requires->css(new \moodle_url('/local/kbacompany/bms_ui/tokens.css'));
$PAGE->requires->css(new \moodle_url('/local/kbacompany/bms_ui/owned_shell.css'));

$sections = [
    'navy' => '#0B2B41', 'emerald' => '#0C6C52', 'mint' => '#ECF7F3',
    'sky' => '#E4F2FB', 'sand' => '#FDF6ED', 'blue' => '#1777B5',
    'violet' => '#6B43B5',
];
$swatches = '';
foreach ($sections as $name => $color) {
    $swatches .= \html_writer::div(
        \html_writer::div('', '', ['style' => 'height:50px;background:' . $color . ';border-radius:9px']) .
        \html_writer::tag('strong', s(ucfirst($name))) . ' — ' . s($color),
        'bms24-card', ['style' => 'min-width:140px;flex:1']);
}
$controls = \html_writer::tag('h2', get_string('bms24_styleguide_components', 'local_kbacompany')) .
    \html_writer::div(
        \html_writer::tag('a', get_string('bms24_styleguide_action', 'local_kbacompany'),
            ['class' => 'bms24-button bms24-button-primary', 'href' => '#bms24-controls']) .
        ' ' . \html_writer::span(get_string('bms24_styleguide_sample', 'local_kbacompany'),
            'bms24-badge bms24-badge-info'), 'bms24-card');
$content = \html_writer::start_div('bms24-app') . \html_writer::start_div('bms24-main', ['style' => 'width:100%']) .
    \html_writer::tag('h1', get_string('bms24_styleguide', 'local_kbacompany')) .
    \html_writer::tag('p', get_string('bms24_styleguide_help', 'local_kbacompany')) .
    \html_writer::div($swatches, '', ['style' => 'display:flex;flex-wrap:wrap;gap:12px;margin:16px 0']) .
    \html_writer::div($controls, '', ['id' => 'bms24-controls']) .
    \html_writer::end_div() . \html_writer::end_div();
echo $OUTPUT->header();
echo $content;
echo $OUTPUT->footer();
