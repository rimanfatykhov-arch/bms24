<?php
require(__DIR__ . '/../../config.php');

require_login();
require_capability('local/bitrixsync:manage', context_system::instance());

global $DB;

$PAGE->set_url(new moodle_url('/local/bitrixsync/setup_center.php'));
$PAGE->set_context(context_system::instance());
$PAGE->set_title(get_string('setup_center_title', 'local_bitrixsync'));

$bitrixconfigured =
    trim((string)get_config('local_bitrixsync', 'bitrix_webhook_url')) !== '' &&
    trim((string)get_config('local_bitrixsync', 'outgoingtoken')) !== '';

$fieldmanager = new \local_bitrixsync\services\crm_field_manager();
$crmready = $fieldmanager->is_ready(false);

$coursemapcount = 0;
$table = new xmldb_table('local_bitrixsync_coursemap');
if ($DB->get_manager()->table_exists($table)) {
    $coursemapcount = $DB->count_records('local_bitrixsync_coursemap');
}

// Pairing / Connection API is the primary Corporate readiness source.
// Legacy bridge settings remain fallback-only for old installations.
$corporateactive = 0;
$corporateconnection = null;
$corporatesource = 'none';

try {
    $corporateservice = new \local_bitrixsync\services\corporate_connection_service();
    $corporateactive = $corporateservice->active_count();

    if ($corporateactive > 0) {
        $corporateconnection = (
            new \local_bitrixsync\repositories\corporate_connection_repository()
        )->latest_active();
        $corporatesource = 'pairing';
    }
} catch (\Throwable $ignored) {
    // Setup Center must remain available even if the connection table
    // is missing during an upgrade.
}

$corporatelegacy =
    (bool)get_config('local_bitrixsync', 'corporate_enable') &&
    trim((string)get_config('local_bitrixsync', 'corporate_api_url')) !== '';

$corporateok = $corporateactive > 0 || $corporatelegacy;

if ($corporatesource === 'none' && $corporatelegacy) {
    $corporatesource = 'legacy';
}

if ($corporatesource === 'pairing') {
    $corporateenvironment = trim((string)($corporateconnection->environment ?? ''));
    $corporateapi = trim((string)($corporateconnection->apiversion ?? ''));
    $corporatecomponent = trim((string)($corporateconnection->component ?? ''));

    $corporatedetails = 'KBA Corporate Dashboard подключён';
    if ($corporateenvironment !== '') {
        $corporatedetails .= ' · ' . ucfirst($corporateenvironment);
    }
    if ($corporateapi !== '') {
        $corporatedetails .= ' · API ' . $corporateapi;
    }
    $corporatedetails .= ' · Connections: ' . $corporateactive;

    if ($corporatecomponent !== '') {
        $corporatedetails .= ' · ' . $corporatecomponent;
    }
} else if ($corporatesource === 'legacy') {
    $corporatedetails = 'Подключение работает через Legacy Corporate bridge. '
        . 'Рекомендуется Pairing / Connection API.';
} else {
    $corporatedetails = 'Подключить KBA Corporate Dashboard через Pairing / Connection API.';
}

$stages = trim((string)get_config(
    'local_bitrixsync',
    'v5_allowed_stages'
));
$automationok =
    $stages !== '' &&
    (bool)get_config('local_bitrixsync', 'queue_enabled');

echo $OUTPUT->header();
echo \local_bitrixsync\ui\admin_shell::render('setup');

echo html_writer::tag(
    'h2',
    get_string('setup_center_title', 'local_bitrixsync')
);
echo html_writer::tag(
    'p',
    'Настройка интеграции по шагам — по логике проверенной версии v0.4.6.',
    ['class' => 'lead']
);

$steps = [
    [
        'n' => 1,
        'title' => 'Bitrix24',
        'ok' => $bitrixconfigured,
        'text' => 'REST webhook Moodle → Bitrix24, URL события Bitrix24 → Moodle и Application Token исходящего webhook.',
        'url' => '/local/bitrixsync/bitrix_connection.php',
        'button' => 'Настроить подключение',
    ],
    [
        'n' => 2,
        'title' => 'CRM поля',
        'ok' => $crmready,
        'text' => 'Проверить и синхронизировать KBA-поля Компании, Контакта и Сделки. Шаблоны писем и таймлайна настраиваются отдельно.',
        'url' => '/local/bitrixsync/crm_fields.php',
        'button' => 'Синхронизировать поля',
    ],
    [
        'n' => 3,
        'title' => 'Курсы и товары',
        'ok' => $coursemapcount > 0,
        'text' => 'Проверить соответствия товаров Bitrix24 курсам Moodle.',
        'url' => '/local/bitrixsync/course_mapping.php',
        'button' => 'Открыть сопоставление',
    ],
    [
        'n' => 4,
        'title' => 'Corporate',
        'ok' => $corporateok,
        'text' => $corporatedetails,
        'url' => '/local/bitrixsync/corporate_rules.php',
        'button' => $corporateok ? 'Открыть Corporate' : 'Настроить Corporate',
    ],
    [
        'n' => 5,
        'title' => 'Автоматизация',
        'ok' => $automationok,
        'text' => 'Выбрать реальные стадии из воронок Bitrix24, включить очередь и проверить правила запуска.',
        'url' => '/local/bitrixsync/funnels.php',
        'button' => 'Выбрать стадии Bitrix24',
    ],
    [
        'n' => 6,
        'title' => 'Production',
        'ok' => (bool)get_config(
            'local_bitrixsync',
            'v5_inbound_enabled'
        ),
        'text' => 'Preflight, CRM test, включение автоматического inbound и SAFE OFF.',
        'url' => '/local/bitrixsync/setup_wizard.php',
        'button' => 'Открыть мастер Production',
    ],
];

foreach ($steps as $step) {
    echo html_writer::start_tag('div', [
        'class' => 'card mb-3',
    ]);
    echo html_writer::start_tag('div', [
        'class' => 'card-body',
    ]);
    echo html_writer::tag(
        'h3',
        $step['n'] . '. ' . $step['title'] . ' ' .
        \local_bitrixsync\ui\admin_shell::status_badge(
            $step['ok'],
            'Готово',
            'Нужно настроить'
        )
    );
    echo html_writer::tag('p', $step['text']);
    echo html_writer::link(
        new moodle_url($step['url']),
        $step['button'],
        ['class' => 'btn btn-primary']
    );
    echo html_writer::end_tag('div');
    echo html_writer::end_tag('div');
}

echo $OUTPUT->footer();
