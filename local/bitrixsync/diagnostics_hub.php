<?php
require(__DIR__ . '/../../config.php');

require_login();
require_capability('local/bitrixsync:manage', context_system::instance());

$PAGE->set_url(new moodle_url('/local/bitrixsync/diagnostics_hub.php'));
$PAGE->set_context(context_system::instance());
$PAGE->set_title(get_string('diagnostics_title', 'local_bitrixsync'));

echo $OUTPUT->header();
echo \local_bitrixsync\ui\admin_shell::render('diagnostics');

echo html_writer::tag(
    'h2',
    get_string('diagnostics_title', 'local_bitrixsync')
);

$items = [
    [
        'title' => 'Bitrix24 REST',
        'text' => 'Проверка profile и CRM userfield API.',
        'url' => '/local/bitrixsync/bitrix_rest_diagnostics.php',
    ],
    [
        'title' => 'Production Readiness',
        'text' => 'Полный health check перед автоматическим запуском.',
        'url' => '/local/bitrixsync/production.php',
    ],
    [
        'title' => 'KBA Corporate Dashboard',
        'text' => 'API v1.10, Bearer token и схема ролей контакта Bitrix24.',
        'url' => '/local/bitrixsync/corporate_schema_diagnostics.php',
    ],
    [
        'title' => 'История сделок',
        'text' => 'Фактические Dry/REAL run и результаты обработки.',
        'url' => '/local/bitrixsync/deal_runs.php',
    ],
    [
        'title' => get_string('bms_diagnostics_card_title', 'local_bitrixsync'),
        'text' => get_string('bms_diagnostics_card_desc', 'local_bitrixsync'),
        'url' => '/local/bitrixsync/bms_diagnostics.php',
    ],
    [
        'title' => 'Расширенная диагностика',
        'text' => 'Служебные проверки KBA Connect.',
        'url' => '/local/bitrixsync/diagnostics.php',
    ],
];

echo html_writer::start_tag('div', ['class' => 'row']);
foreach ($items as $item) {
    echo html_writer::start_tag('div', [
        'class' => 'col-md-6 mb-3',
    ]);
    echo \local_bitrixsync\ui\admin_shell::card(
        $item['title'],
        $item['text'],
        $item['url']
    );
    echo html_writer::end_tag('div');
}
echo html_writer::end_tag('div');

echo $OUTPUT->footer();
