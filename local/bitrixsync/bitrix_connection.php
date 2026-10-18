<?php
require(__DIR__ . '/../../config.php');

require_login();
require_capability('local/bitrixsync:manage', context_system::instance());

$PAGE->set_url(new moodle_url('/local/bitrixsync/bitrix_connection.php'));
$PAGE->set_context(context_system::instance());
$PAGE->set_title(get_string('bitrix_connection_title', 'local_bitrixsync'));

$action = optional_param('action', '', PARAM_ALPHA);
$message = null;
$error = null;
$probe = null;

try {
    if ($action === 'save_rest') {
        require_sesskey();

        $webhook = trim(required_param(
            'bitrix_webhook_url',
            PARAM_RAW_TRIMMED
        ));

        if ($webhook !== '' &&
                !preg_match('~^https://~i', $webhook)) {
            throw new moodle_exception(
                'invalidparameter',
                'error',
                '',
                'Bitrix24 webhook must use HTTPS'
            );
        }

        set_config(
            'bitrix_webhook_url',
            $webhook,
            'local_bitrixsync'
        );

        $message = 'REST webhook Bitrix24 сохранён.';
    } else if ($action === 'save_outgoing') {
        require_sesskey();

        $dealtoken = trim(optional_param(
            'outgoingtoken',
            '',
            PARAM_RAW_TRIMMED
        ));
        $contacttoken = trim(optional_param(
            'contactoutgoingtoken',
            '',
            PARAM_RAW_TRIMMED
        ));
        $contactenabled = optional_param(
            'contactprofilesyncenabled',
            0,
            PARAM_BOOL
        );

        // Empty secret input means "keep existing".
        if ($dealtoken !== '') {
            set_config(
                'outgoingtoken',
                $dealtoken,
                'local_bitrixsync'
            );
        }

        if ($contacttoken !== '') {
            set_config(
                'contactoutgoingtoken',
                $contacttoken,
                'local_bitrixsync'
            );
        }

        set_config(
            'contactprofilesyncenabled',
            $contactenabled ? 1 : 0,
            'local_bitrixsync'
        );

        $message = 'Application Token исходящих webhook сохранён.';
    } else if ($action === 'probe') {
        require_sesskey();

        $probe = (
            new \local_bitrixsync\services\crm_rest_probe_service()
        )->run();
    }
} catch (\Throwable $e) {
    $error = $e->getMessage();
}

$webhook = (string)get_config(
    'local_bitrixsync',
    'bitrix_webhook_url'
);

$dealtokenconfigured = trim((string)get_config(
    'local_bitrixsync',
    'outgoingtoken'
)) !== '';

$contacttokenconfigured = trim((string)get_config(
    'local_bitrixsync',
    'contactoutgoingtoken'
)) !== '';

$contactenabled = (bool)get_config(
    'local_bitrixsync',
    'contactprofilesyncenabled'
);

$funnelservice = new \local_bitrixsync\services\bitrix_funnel_service();
$selectedstages = $funnelservice->selection_summary();

$endpoint = '';
try {
    $endpoint = (
        new \local_bitrixsync\services\production_activation_service()
    )->endpoint();
} catch (\Throwable $e) {
    $endpoint = '';
}

echo $OUTPUT->header();
echo \local_bitrixsync\ui\admin_shell::render('bitrix');

echo html_writer::tag(
    'h2',
    get_string('bitrix_connection_title', 'local_bitrixsync')
);

echo html_writer::tag(
    'p',
    'Подключение разделено на два направления, как в проверенной версии v0.4.6.',
    ['class' => 'lead']
);

if ($message) {
    echo $OUTPUT->notification($message, 'success');
}
if ($error) {
    echo $OUTPUT->notification($error, 'error');
}

// ------------------------------------------------------------------
// Moodle -> Bitrix24.
// ------------------------------------------------------------------
echo html_writer::start_tag('div', ['class' => 'card mb-4']);
echo html_writer::start_tag('div', ['class' => 'card-body']);

echo html_writer::tag(
    'h3',
    '1. Moodle → Bitrix24 — входящий REST webhook Bitrix24'
);
echo html_writer::tag(
    'p',
    'Создайте входящий webhook в Bitrix24 с правом CRM и вставьте его URL сюда. '
    . 'Этот секрет используется Moodle для чтения и обновления CRM.'
);

echo html_writer::start_tag('form', ['method' => 'post']);
echo html_writer::empty_tag('input', [
    'type' => 'hidden',
    'name' => 'sesskey',
    'value' => sesskey(),
]);
echo html_writer::empty_tag('input', [
    'type' => 'hidden',
    'name' => 'action',
    'value' => 'save_rest',
]);

echo html_writer::label(
    'REST webhook Bitrix24',
    'bitrix_webhook_url'
);
echo html_writer::empty_tag('input', [
    'type' => 'password',
    'name' => 'bitrix_webhook_url',
    'id' => 'bitrix_webhook_url',
    'value' => $webhook,
    'class' => 'form-control mb-3',
    'autocomplete' => 'new-password',
]);

echo html_writer::empty_tag('input', [
    'type' => 'submit',
    'value' => 'Сохранить REST webhook',
    'class' => 'btn btn-primary',
]);

echo html_writer::end_tag('form');

echo html_writer::start_tag('form', [
    'method' => 'post',
    'class' => 'mt-3',
]);
echo html_writer::empty_tag('input', [
    'type' => 'hidden',
    'name' => 'sesskey',
    'value' => sesskey(),
]);
echo html_writer::empty_tag('input', [
    'type' => 'hidden',
    'name' => 'action',
    'value' => 'probe',
]);
echo html_writer::empty_tag('input', [
    'type' => 'submit',
    'value' => 'Проверить REST соединение',
    'class' => 'btn btn-outline-secondary',
]);
echo html_writer::end_tag('form');

echo html_writer::end_tag('div');
echo html_writer::end_tag('div');

// ------------------------------------------------------------------
// Stage selection.
// ------------------------------------------------------------------
echo html_writer::start_tag('div', ['class' => 'card mb-4']);
echo html_writer::start_tag('div', ['class' => 'card-body']);

echo html_writer::tag('h3', '2. Воронки и стадии Bitrix24');

if ($selectedstages) {
    foreach ($selectedstages as $funnel) {
        $labels = [];
        foreach ($funnel['stages'] as $stage) {
            $labels[] = $stage['name'] . ' (' . $stage['id'] . ')';
        }

        echo html_writer::tag(
            'p',
            html_writer::tag('strong', s($funnel['name'])) .
            ': ' . s(implode(', ', $labels))
        );
    }
} else {
    echo html_writer::tag(
        'p',
        'Стадии автоматического запуска ещё не выбраны.',
        ['class' => 'text-muted']
    );
}

echo html_writer::link(
    new moodle_url('/local/bitrixsync/funnels.php'),
    'Выбрать воронки и стадии из Bitrix24',
    ['class' => 'btn btn-outline-primary']
);

echo html_writer::end_tag('div');
echo html_writer::end_tag('div');

// ------------------------------------------------------------------
// Bitrix24 -> Moodle outgoing webhook.
// ------------------------------------------------------------------
echo html_writer::start_tag('div', ['class' => 'card mb-4']);
echo html_writer::start_tag('div', ['class' => 'card-body']);

echo html_writer::tag(
    'h3',
    '3. Bitrix24 → Moodle — исходящий webhook Сделки'
);
echo html_writer::tag(
    'p',
    'В Bitrix24 создайте исходящий webhook для ONCRMDEALUPDATE. '
    . 'При необходимости добавьте ONCRMDEALADD. URL обработчика — ниже.'
);

if ($endpoint !== '') {
    echo html_writer::label(
        'URL обработчика в Moodle',
        'kba_outgoing_endpoint'
    );
    echo html_writer::tag(
        'textarea',
        s($endpoint),
        [
            'id' => 'kba_outgoing_endpoint',
            'class' => 'form-control',
            'rows' => 3,
            'readonly' => 'readonly',
        ]
    );
    echo html_writer::tag(
        'p',
        'URL содержит секретный ключ KBA Connect. Не публикуйте его.',
        ['class' => 'text-muted mt-2']
    );
} else {
    echo $OUTPUT->notification(
        'URL обработчика ещё не сформирован. Откройте Production Wizard.',
        'warning'
    );
}

echo html_writer::start_tag('form', [
    'method' => 'post',
    'class' => 'mt-4',
]);
echo html_writer::empty_tag('input', [
    'type' => 'hidden',
    'name' => 'sesskey',
    'value' => sesskey(),
]);
echo html_writer::empty_tag('input', [
    'type' => 'hidden',
    'name' => 'action',
    'value' => 'save_outgoing',
]);

echo html_writer::label(
    'Application Token исходящего webhook Сделки',
    'outgoingtoken'
);
echo html_writer::empty_tag('input', [
    'type' => 'password',
    'name' => 'outgoingtoken',
    'id' => 'outgoingtoken',
    'value' => '',
    'class' => 'form-control mb-2',
    'autocomplete' => 'new-password',
    'placeholder' => $dealtokenconfigured
        ? 'Токен уже сохранён — оставьте пустым, чтобы не менять'
        : 'Вставьте Application Token из Bitrix24',
]);

echo html_writer::tag(
    'p',
    $dealtokenconfigured
        ? 'Application Token Сделки настроен.'
        : 'Application Token Сделки НЕ настроен.',
    [
        'class' => $dealtokenconfigured
            ? 'text-success'
            : 'text-danger',
    ]
);

echo html_writer::start_tag('div', [
    'class' => 'alert alert-light border mt-3',
]);
echo html_writer::tag('strong', 'Настройка в Bitrix24');
echo html_writer::tag(
    'p',
    'Событие: ONCRMDEALUPDATE'
);
echo html_writer::tag(
    'p',
    'URL: тот же URL обработчика Moodle, показанный выше.'
);
echo html_writer::tag(
    'p',
    'После сохранения Bitrix24 покажет Application Token — вставьте его в это поле.'
);
echo html_writer::end_tag('div');

// Optional Contact token restored from v0.4.6.
echo html_writer::tag(
    'h4',
    'Контакт — опционально',
    ['class' => 'mt-4']
);

echo html_writer::start_tag('div', ['class' => 'form-group']);
echo html_writer::checkbox(
    'contactprofilesyncenabled',
    1,
    $contactenabled,
    ' Принимать ONCRMCONTACTUPDATE'
);
echo html_writer::end_tag('div');

echo html_writer::label(
    'Application Token webhook Контакта',
    'contactoutgoingtoken'
);
echo html_writer::empty_tag('input', [
    'type' => 'password',
    'name' => 'contactoutgoingtoken',
    'id' => 'contactoutgoingtoken',
    'value' => '',
    'class' => 'form-control mb-2',
    'autocomplete' => 'new-password',
    'placeholder' => $contacttokenconfigured
        ? 'Отдельный токен Контакта уже сохранён'
        : 'Пусто = использовать токен Сделки',
]);

echo html_writer::tag(
    'p',
    'Если отдельный токен Контакта не задан, используется Application Token Сделки. '
    . 'Событие Контакта не запускает зачисление на курсы.',
    ['class' => 'text-muted']
);

echo html_writer::empty_tag('input', [
    'type' => 'submit',
    'value' => 'Сохранить Application Token',
    'class' => 'btn btn-primary',
]);

echo html_writer::end_tag('form');

echo html_writer::end_tag('div');
echo html_writer::end_tag('div');

if ($probe !== null) {
    echo html_writer::tag('h3', 'Результат REST-проверки');
    echo html_writer::tag(
        'pre',
        s(json_encode(
            $probe,
            JSON_PRETTY_PRINT |
            JSON_UNESCAPED_UNICODE |
            JSON_UNESCAPED_SLASHES
        ))
    );
}

echo $OUTPUT->footer();
