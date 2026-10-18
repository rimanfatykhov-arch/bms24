<?php
require(__DIR__ . '/../../config.php');

require_login();
require_capability('local/bitrixsync:manage', context_system::instance());

global $DB;

$PAGE->set_url(new moodle_url('/local/bitrixsync/admin_dashboard.php'));
$PAGE->set_context(context_system::instance());
$PAGE->set_title(get_string('admin_dashboard_title', 'local_bitrixsync'));

$latest = false;
$dealruntable = new xmldb_table('local_bitrixsync_dealrun');
if ($DB->get_manager()->table_exists($dealruntable)) {
    $latest = $DB->get_record_sql(
        "SELECT *
           FROM {local_bitrixsync_dealrun}
       ORDER BY timecreated DESC, id DESC",
        [],
        IGNORE_MULTIPLE
    );
}

$coursemapcount = 0;
$coursemaptable = new xmldb_table('local_bitrixsync_coursemap');
if ($DB->get_manager()->table_exists($coursemaptable)) {
    $coursemapcount = $DB->count_records('local_bitrixsync_coursemap');
}

$queuepending = 0;
$queuetable = new xmldb_table('local_bitrixsync_syncqueue');
if ($DB->get_manager()->table_exists($queuetable)) {
    $queuepending = $DB->count_records(
        'local_bitrixsync_syncqueue',
        ['status' => 'pending']
    );
}

$bitrixconfigured =
    trim((string)get_config('local_bitrixsync', 'bitrix_webhook_url')) !== '';

$outgoingtokenconfigured =
    trim((string)get_config('local_bitrixsync', 'outgoingtoken')) !== '';

$bitrixsecure =
    $bitrixconfigured &&
    $outgoingtokenconfigured;

$crmready = (
    new \local_bitrixsync\services\crm_field_manager()
)->is_ready(false);

$corporateenabled = (bool)get_config(
    'local_bitrixsync',
    'corporate_enable'
);

$queueenabled = (bool)get_config(
    'local_bitrixsync',
    'queue_enabled'
);

$inboundenabled = (bool)get_config(
    'local_bitrixsync',
    'v5_inbound_enabled'
);

echo $OUTPUT->header();
echo \local_bitrixsync\ui\admin_shell::render('dashboard');

echo html_writer::tag(
    'h2',
    get_string('admin_dashboard_title', 'local_bitrixsync')
);

echo html_writer::tag(
    'p',
    'Главный рабочий экран интеграции Moodle ↔ Bitrix24 ↔ Corporate.',
    ['class' => 'lead']
);

$cards = [
    [
        'title' => 'Bitrix24',
        'ok' => $bitrixsecure,
        'detail' => $bitrixsecure
            ? 'REST webhook + Application Token настроены'
            : ($bitrixconfigured
                ? 'Добавьте Application Token исходящего webhook'
                : 'Укажите REST webhook'),
        'url' => '/local/bitrixsync/bitrix_connection.php',
    ],
    [
        'title' => 'CRM поля',
        'ok' => $crmready,
        'detail' => $crmready
            ? 'Карта полей готова'
            : 'Нужно проверить/создать поля',
        'url' => '/local/bitrixsync/crm_fields.php',
    ],
    [
        'title' => 'Курсы и товары',
        'ok' => $coursemapcount > 0,
        'detail' => 'Сопоставлений: ' . $coursemapcount,
        'url' => '/local/bitrixsync/course_mapping.php',
    ],
    [
        'title' => 'Corporate',
        'ok' => $corporateenabled,
        'detail' => $corporateenabled
            ? 'Интеграция включена'
            : 'Интеграция выключена',
        'url' => '/local/bitrixsync/corporate_rules.php',
    ],
    [
        'title' => 'Очередь',
        'ok' => $queueenabled,
        'detail' => 'Pending: ' . $queuepending,
        'url' => '/local/bitrixsync/automation_center.php',
    ],
    [
        'title' => 'Production',
        'ok' => $inboundenabled,
        'detail' => $inboundenabled
            ? 'Automatic inbound ON'
            : 'Automatic inbound OFF',
        'url' => '/local/bitrixsync/setup_wizard.php',
    ],
    [
        'title' => 'Production Actions',
        'ok' => true,
        'detail' => 'Sync Deal / Diagnostics / Contact hydration',
        'url' => '/local/bitrixsync/production_control.php',
    ],
    [
        'title' => 'KBA Connect',
        'ok' => true,
        'detail' => 'v5.0.31.3 RC1 · Moodle ' . $CFG->release . ' · PHP ' . PHP_VERSION,
        'url' => '/admin/plugins.php',
    ],
];

echo html_writer::start_tag('div', ['class' => 'row']);
foreach ($cards as $card) {
    echo html_writer::start_tag('div', [
        'class' => 'col-md-4 mb-3',
    ]);
    $body =
        \local_bitrixsync\ui\admin_shell::status_badge(
            $card['ok']
        ) .
        html_writer::tag('p', s($card['detail']), [
            'class' => 'mt-2 mb-0',
        ]);

    echo \local_bitrixsync\ui\admin_shell::card(
        $card['title'],
        $body,
        $card['url']
    );
    echo html_writer::end_tag('div');
}
echo html_writer::end_tag('div');

echo html_writer::tag('h3', 'Последний запуск', [
    'class' => 'mt-4',
]);

if ($latest) {
    $mode = !empty($latest->dryrun) ? 'DRY RUN' : 'REAL';
    echo html_writer::start_tag('table', [
        'class' => 'generaltable',
    ]);
    $rows = [
        'Run' => 'RUN-' . (int)$latest->id,
        'Deal' => (string)(int)$latest->dealid,
        'Режим' => $mode,
        'Статус' => (string)$latest->status,
        'Курсов определено' => (string)(int)$latest->resolved,
        'Новых зачислений' => (string)(int)$latest->enrolled,
        'Уже обработано' => (string)(int)$latest->already,
        'Ошибок' => (string)(int)$latest->failed,
        'CRM' => (string)($latest->crm_status ?? ''),
        'Время' => userdate((int)$latest->timecreated),
    ];
    foreach ($rows as $label => $value) {
        echo html_writer::tag(
            'tr',
            html_writer::tag('th', s($label)) .
            html_writer::tag('td', s($value))
        );
    }
    echo html_writer::end_tag('table');
} else {
    echo $OUTPUT->notification(
        'История запусков пока пуста.',
        'info'
    );
}

echo $OUTPUT->footer();
