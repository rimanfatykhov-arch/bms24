<?php
require(__DIR__ . '/../../config.php');

require_login();
require_capability('local/bitrixsync:manage', context_system::instance());

$service = new \local_bitrixsync\services\production_activation_service();
$funnelservice = new \local_bitrixsync\services\bitrix_funnel_service();
$fieldmanager = new \local_bitrixsync\services\crm_field_manager();

$dealid = $service->controlled_deal_id();
$stageid = trim((string)get_config(
    'local_bitrixsync',
    'v5_allowed_stages'
));
$action = optional_param('action', 'status', PARAM_ALPHAEXT);

$message = null;
$messagetype = 'success';
$technicalresult = null;

function kba_connect_require_simple_confirmation(): void {
    $confirmed = optional_param(
        'confirm',
        0,
        PARAM_BOOL
    );

    if (!$confirmed) {
        throw new \moodle_exception(
            'production_simple_confirmation_required',
            'local_bitrixsync'
        );
    }
}

try {
    if ($action !== 'status') {
        require_sesskey();
    }

    if ($action === 'finalize') {
        kba_connect_require_simple_confirmation();

        if (!$fieldmanager->is_ready(false)) {
            throw new \moodle_exception(
                'production_crm_fields_not_ready',
                'local_bitrixsync'
            );
        }

        if ($stageid === '') {
            throw new \moodle_exception(
                'production_stage_required',
                'local_bitrixsync'
            );
        }

        // CRM fields are synchronized separately in the background.
        // Finalize only validates the current field map.
        $technicalresult = $service->finalize(
            $dealid,
            $stageid,
            false
        );

        $message = 'Production подготовлен. Автоматический входящий webhook пока выключен.';

    } else if ($action === 'crmtest') {
        kba_connect_require_simple_confirmation();

        $technicalresult = $service->crm_controlled_test(
            $dealid
        );

        $message = 'Контрольная проверка CRM выполнена.';

    } else if ($action === 'confirmbitrix') {
        kba_connect_require_simple_confirmation();

        $technicalresult = $service->confirm_bitrix_event(
            $dealid
        );

        $message = 'Настройка исходящего webhook Bitrix24 подтверждена.';

    } else if ($action === 'enableinbound') {
        kba_connect_require_simple_confirmation();

        if ($stageid === '') {
            throw new \moodle_exception(
                'production_stage_required',
                'local_bitrixsync'
            );
        }

        $technicalresult = $service->enable_inbound(
            $dealid,
            $stageid
        );

        $message = 'Автоматическая обработка включена.';

    } else if ($action === 'queuesmoke') {
        kba_connect_require_simple_confirmation();

        $technicalresult = $service->queue_smoke(
            $dealid
        );

        $message = 'Тест очереди выполнен.';

    } else if ($action === 'safeoff') {
        kba_connect_require_simple_confirmation();

        $technicalresult = $service->safe_off();

        $message = 'KBA Connect переведён в безопасный режим.';
        $messagetype = 'warning';
    }
} catch (\Throwable $e) {
    $message = $e->getMessage();
    $messagetype = 'error';
}

$status = $service->wizard_status(
    $dealid,
    $stageid
);

$crmfieldsready = $fieldmanager->is_ready(false);
$selectedstages = $funnelservice->selection_summary();
$outgoingtokenready = trim((string)get_config(
    'local_bitrixsync',
    'outgoingtoken'
)) !== '';

$proofready = !empty(
    $status['controlled_run']['ready']
);

$finalized = !empty(
    $status['finalized']
);

$crmready = !empty(
    $status['crm_test']['ready']
);

$bitrixeventready =
    !empty($status['endpoint_ready']) &&
    !empty($status['bitrix_event_confirmed']) &&
    $outgoingtokenready;

$inboundenabled = !empty(
    $status['inbound_enabled']
);

$PAGE->set_url(new moodle_url(
    '/local/bitrixsync/setup_wizard.php'
));
$PAGE->set_context(context_system::instance());
$PAGE->set_title(get_string(
    'production_simple_title',
    'local_bitrixsync'
));
$PAGE->set_heading(get_string(
    'pluginname',
    'local_bitrixsync'
));

function kba_connect_simple_badge(
    bool $ok,
    string $yes = 'Готово',
    string $no = 'Нужно настроить'
): string {
    return html_writer::tag(
        'span',
        $ok ? $yes : $no,
        [
            'class' => $ok
                ? 'badge badge-success'
                : 'badge badge-warning',
        ]
    );
}

function kba_connect_simple_action_form(
    string $action,
    string $label,
    string $buttonclass = 'btn-primary',
    string $confirmtext = 'Подтверждаю выполнение этого шага'
): string {
    $html = html_writer::start_tag(
        'form',
        [
            'method' => 'post',
            'class' => 'mt-3',
        ]
    );

    $html .= html_writer::empty_tag(
        'input',
        [
            'type' => 'hidden',
            'name' => 'sesskey',
            'value' => sesskey(),
        ]
    );

    $html .= html_writer::empty_tag(
        'input',
        [
            'type' => 'hidden',
            'name' => 'action',
            'value' => $action,
        ]
    );

    $html .= html_writer::start_tag(
        'div',
        ['class' => 'form-group']
    );

    $html .= html_writer::checkbox(
        'confirm',
        1,
        false,
        ' ' . $confirmtext
    );

    $html .= html_writer::end_tag('div');

    $html .= html_writer::empty_tag(
        'input',
        [
            'type' => 'submit',
            'value' => $label,
            'class' => 'btn ' . $buttonclass,
        ]
    );

    $html .= html_writer::end_tag('form');

    return $html;
}

function kba_connect_simple_status_row(
    string $label,
    bool $ok,
    string $detail = ''
): string {
    return html_writer::tag(
        'tr',
        html_writer::tag(
            'td',
            s($label)
        ) .
        html_writer::tag(
            'td',
            kba_connect_simple_badge($ok)
        ) .
        html_writer::tag(
            'td',
            s($detail)
        )
    );
}

echo $OUTPUT->header();

if (class_exists('\local_bitrixsync\ui\admin_shell')) {
    echo \local_bitrixsync\ui\admin_shell::render(
        'production'
    );
}

echo html_writer::tag(
    'h2',
    get_string(
        'production_simple_title',
        'local_bitrixsync'
    )
);

echo html_writer::tag(
    'p',
    get_string(
        'production_simple_intro',
        'local_bitrixsync'
    ),
    ['class' => 'lead']
);

if ($message !== null) {
    echo $OUTPUT->notification(
        s($message),
        $messagetype
    );
}

// ------------------------------------------------------------------
// Top state cards.
// ------------------------------------------------------------------
echo html_writer::start_tag(
    'div',
    ['class' => 'row mb-4']
);

$topcards = [
    [
        'title' => 'Production',
        'ok' => $finalized,
        'detail' => $finalized
            ? 'Подготовлен'
            : 'Не подготовлен',
    ],
    [
        'title' => 'CRM',
        'ok' => $crmready,
        'detail' => $crmready
            ? 'Проверен'
            : 'Нужна проверка',
    ],
    [
        'title' => 'Bitrix24',
        'ok' => $bitrixeventready,
        'detail' => $bitrixeventready
            ? 'Webhook подтверждён'
            : 'Нужно настроить webhook',
    ],
    [
        'title' => 'Автоматизация',
        'ok' => $inboundenabled,
        'detail' => $inboundenabled
            ? 'Включена'
            : 'Выключена',
    ],
];

foreach ($topcards as $card) {
    echo html_writer::start_tag(
        'div',
        ['class' => 'col-md-3 mb-2']
    );

    echo html_writer::start_tag(
        'div',
        ['class' => 'card h-100']
    );

    echo html_writer::start_tag(
        'div',
        ['class' => 'card-body']
    );

    echo html_writer::tag(
        'h4',
        s($card['title'])
    );

    echo kba_connect_simple_badge(
        $card['ok'],
        $card['ok'] ? 'OK' : 'OFF',
        $card['ok'] ? 'OK' : 'OFF'
    );

    echo html_writer::tag(
        'p',
        s($card['detail']),
        ['class' => 'mt-2 mb-0']
    );

    echo html_writer::end_tag('div');
    echo html_writer::end_tag('div');
    echo html_writer::end_tag('div');
}

echo html_writer::end_tag('div');

// ------------------------------------------------------------------
// Step 1: readiness.
// ------------------------------------------------------------------
echo html_writer::start_tag(
    'div',
    ['class' => 'card mb-3']
);
echo html_writer::start_tag(
    'div',
    ['class' => 'card-body']
);

echo html_writer::tag(
    'h3',
    '1. Проверка готовности'
);

echo html_writer::start_tag(
    'table',
    ['class' => 'generaltable']
);

$run = $status['controlled_run']['run'] ?? [];
$rundetail = $proofready
    ? 'Последний контрольный REAL run: ' .
        ($run['resolved'] ?? 0) . '/' .
        ($run['productrows'] ?? 0) .
        ' курсов, ошибок: ' .
        ($run['failed'] ?? 0)
    : 'Нужен успешный контрольный REAL run';

echo kba_connect_simple_status_row(
    'Контрольный запуск',
    $proofready,
    $rundetail
);

echo kba_connect_simple_status_row(
    'CRM поля',
    $crmfieldsready,
    $crmfieldsready
        ? 'Компания, Контакт и Сделка готовы'
        : 'Откройте CRM поля и синхронизируйте отсутствующие'
);

echo kba_connect_simple_status_row(
    'Стадии Bitrix24',
    $stageid !== '',
    $stageid !== ''
        ? (string)count(
            array_filter(
                array_map(
                    'trim',
                    explode(',', $stageid)
                )
            )
        ) . ' стадий выбрано'
        : 'Стадии не выбраны'
);

echo kba_connect_simple_status_row(
    'Application Token',
    $outgoingtokenready,
    $outgoingtokenready
        ? 'Токен исходящего webhook Сделки сохранён'
        : 'Откройте Bitrix24 и сохраните Application Token'
);

echo html_writer::end_tag('table');

echo html_writer::start_tag(
    'div',
    ['class' => 'mt-3']
);

if (!$crmfieldsready) {
    echo html_writer::link(
        new moodle_url(
            '/local/bitrixsync/crm_fields.php'
        ),
        'Настроить CRM поля',
        ['class' => 'btn btn-outline-primary mr-2']
    );
}

if ($stageid === '') {
    echo html_writer::link(
        new moodle_url(
            '/local/bitrixsync/funnels.php'
        ),
        'Выбрать стадии Bitrix24',
        ['class' => 'btn btn-outline-primary mr-2']
    );
}

if (!$outgoingtokenready) {
    echo html_writer::link(
        new moodle_url(
            '/local/bitrixsync/bitrix_connection.php'
        ),
        'Настроить Bitrix24',
        ['class' => 'btn btn-outline-primary']
    );
}

echo html_writer::end_tag('div');

echo html_writer::end_tag('div');
echo html_writer::end_tag('div');

// ------------------------------------------------------------------
// Step 2: production finalize.
// ------------------------------------------------------------------
$step2ready =
    $proofready &&
    $crmfieldsready &&
    $stageid !== '' &&
    $outgoingtokenready;

echo html_writer::start_tag(
    'div',
    ['class' => 'card mb-3']
);
echo html_writer::start_tag(
    'div',
    ['class' => 'card-body']
);

echo html_writer::tag(
    'h3',
    '2. Подготовка Production ' .
    kba_connect_simple_badge(
        $finalized
    )
);

echo html_writer::tag(
    'p',
    'Включает v5 engine, очередь и CRM writeback. '
    . 'Автоматический входящий webhook при этом остаётся выключенным.'
);

if ($finalized) {
    echo $OUTPUT->notification(
        'Production уже подготовлен.',
        'success'
    );
} else if ($step2ready) {
    echo kba_connect_simple_action_form(
        'finalize',
        'Подготовить Production'
    );
} else {
    echo $OUTPUT->notification(
        'Сначала завершите пункт 1.',
        'info'
    );
}

echo html_writer::end_tag('div');
echo html_writer::end_tag('div');

// ------------------------------------------------------------------
// Step 3: CRM test.
// ------------------------------------------------------------------
echo html_writer::start_tag(
    'div',
    ['class' => 'card mb-3']
);
echo html_writer::start_tag(
    'div',
    ['class' => 'card-body']
);

echo html_writer::tag(
    'h3',
    '3. Проверка записи в Bitrix24 ' .
    kba_connect_simple_badge(
        $crmready
    )
);

echo html_writer::tag(
    'p',
    'Проверяет обновление Компании, Контакта, Сделки и таймлайна Bitrix24. '
    . 'Повторные зачисления Moodle и места Corporate создаваться не должны.'
);

if ($crmready) {
    echo $OUTPUT->notification(
        'CRM writeback проверен.',
        'success'
    );
} else if ($finalized) {
    echo kba_connect_simple_action_form(
        'crmtest',
        'Запустить проверку CRM'
    );
} else {
    echo $OUTPUT->notification(
        'Сначала подготовьте Production в пункте 2.',
        'info'
    );
}

echo html_writer::end_tag('div');
echo html_writer::end_tag('div');

// ------------------------------------------------------------------
// Step 4: Bitrix event.
// ------------------------------------------------------------------
echo html_writer::start_tag(
    'div',
    ['class' => 'card mb-3']
);
echo html_writer::start_tag(
    'div',
    ['class' => 'card-body']
);

echo html_writer::tag(
    'h3',
    '4. Исходящий webhook Bitrix24 ' .
    kba_connect_simple_badge(
        $bitrixeventready
    )
);

if (!empty($status['endpoint_ready'])) {
    echo html_writer::tag(
        'p',
        'Скопируйте этот URL в исходящий webhook Bitrix24 для события ONCRMDEALUPDATE:'
    );

    echo html_writer::tag(
        'textarea',
        s((string)$status['endpoint']),
        [
            'class' => 'form-control',
            'rows' => 3,
            'readonly' => 'readonly',
        ]
    );

    echo html_writer::tag(
        'p',
        'Application Token хранится на странице Bitrix24.',
        ['class' => 'text-muted mt-2']
    );

    echo html_writer::link(
        new moodle_url(
            '/local/bitrixsync/bitrix_connection.php'
        ),
        'Открыть настройки Bitrix24',
        ['class' => 'btn btn-outline-primary mr-2']
    );

    if (!$bitrixeventready && $outgoingtokenready) {
        echo kba_connect_simple_action_form(
            'confirmbitrix',
            'Подтвердить, что webhook настроен',
            'btn-secondary',
            'Я настроил исходящий webhook Bitrix24 на указанный URL'
        );
    }
} else {
    echo $OUTPUT->notification(
        'URL обработчика ещё не готов. Проверьте настройки Bitrix24.',
        'warning'
    );
}

echo html_writer::end_tag('div');
echo html_writer::end_tag('div');

// ------------------------------------------------------------------
// Step 5: enable automatic processing.
// ------------------------------------------------------------------
echo html_writer::start_tag(
    'div',
    ['class' => 'card mb-3']
);
echo html_writer::start_tag(
    'div',
    ['class' => 'card-body']
);

echo html_writer::tag(
    'h3',
    '5. Автоматическая обработка ' .
    kba_connect_simple_badge(
        $inboundenabled,
        'Включена',
        'Выключена'
    )
);

if ($inboundenabled) {
    echo $OUTPUT->notification(
        'KBA Connect принимает события Bitrix24 автоматически.',
        'success'
    );

    echo html_writer::tag(
        'details',
        html_writer::tag(
            'summary',
            'Техническая проверка очереди'
        ) .
        kba_connect_simple_action_form(
            'queuesmoke',
            'Запустить тест очереди',
            'btn-outline-secondary',
            'Запустить безопасный smoke-test очереди'
        )
    );
} else if ($crmready && $bitrixeventready) {
    echo html_writer::tag(
        'p',
        'После включения события выбранных стадий Bitrix24 будут автоматически попадать в очередь KBA Connect.'
    );

    echo kba_connect_simple_action_form(
        'enableinbound',
        'Включить автоматическую обработку',
        'btn-danger',
        'Я подтверждаю включение автоматической обработки сделок Bitrix24'
    );
} else {
    echo $OUTPUT->notification(
        'Для включения автоматизации завершите пункты 3 и 4.',
        'info'
    );
}

echo html_writer::end_tag('div');
echo html_writer::end_tag('div');

// ------------------------------------------------------------------
// Safe off.
// ------------------------------------------------------------------
echo html_writer::start_tag(
    'div',
    ['class' => 'card border-danger mt-4']
);
echo html_writer::start_tag(
    'div',
    ['class' => 'card-body']
);

echo html_writer::tag(
    'h3',
    'Безопасное отключение'
);

echo html_writer::tag(
    'p',
    'Останавливает automatic inbound и очередь и возвращает engine_mode=legacy. '
    . 'Данные, CRM-поля и история не удаляются.'
);

echo kba_connect_simple_action_form(
    'safeoff',
    'Отключить автоматизацию (SAFE OFF)',
    'btn-outline-danger',
    'Я подтверждаю безопасное отключение автоматизации'
);

echo html_writer::end_tag('div');
echo html_writer::end_tag('div');

// Technical details are hidden by default.
echo html_writer::start_tag(
    'details',
    ['class' => 'mt-4']
);
echo html_writer::tag(
    'summary',
    'Техническая информация'
);

echo html_writer::start_tag(
    'div',
    ['class' => 'mt-3']
);

echo html_writer::tag(
    'p',
    'Контрольная сделка: #' . (int)$dealid
);
echo html_writer::tag(
    'p',
    'STAGE_ID: ' . s($stageid !== '' ? $stageid : 'не выбраны')
);
echo html_writer::tag(
    'p',
    'Engine: ' . s((string)$status['engine_mode'])
);
echo html_writer::tag(
    'p',
    'Queue: ' . (!empty($status['queue_enabled']) ? 'ON' : 'OFF')
);

if ($technicalresult !== null) {
    echo html_writer::tag(
        'pre',
        s(json_encode(
            $technicalresult,
            JSON_PRETTY_PRINT |
            JSON_UNESCAPED_UNICODE |
            JSON_UNESCAPED_SLASHES
        ))
    );
}

echo html_writer::end_tag('div');
echo html_writer::end_tag('details');

echo $OUTPUT->footer();
