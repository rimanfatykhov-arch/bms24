<?php
require(__DIR__ . '/../../config.php');

require_login();
require_capability('local/bitrixsync:manage', context_system::instance());

$PAGE->set_url(
    new moodle_url('/local/bitrixsync/message_templates.php')
);
$PAGE->set_context(context_system::instance());
$PAGE->set_title(
    get_string('message_templates_title', 'local_bitrixsync')
);

$service = new \local_bitrixsync\services\message_template_service();

$action = optional_param('action', '', PARAM_ALPHA);
$message = null;
$error = null;

try {
    if ($action === 'save') {
        require_sesskey();

        $values = [];

        foreach (array_keys($service->defaults()) as $key) {
            if ($key === 'timelineenabled') {
                $values[$key] = optional_param(
                    $key,
                    0,
                    PARAM_BOOL
                );
                continue;
            }

            if ($key === 'accesschannel') {
                $values[$key] = optional_param(
                    $key,
                    'moodle',
                    PARAM_ALPHANUMEXT
                );
                continue;
            }

            if ($key === 'accesssenderemail') {
                $values[$key] = optional_param(
                    $key,
                    '',
                    PARAM_RAW_TRIMMED
                );
                continue;
            }

            $values[$key] = optional_param(
                $key,
                '',
                PARAM_RAW
            );
        }

        $service->save($values);

        $message = 'Шаблоны сообщений сохранены.';
    } else if ($action === 'reset') {
        require_sesskey();
        $service->reset_defaults();
        $message = 'Восстановлены стандартные шаблоны KBA Connect.';
    }
} catch (\Throwable $e) {
    $error = $e->getMessage();
}

$accessdebug = [];
$accessdebugraw = (string)get_config(
    'local_bitrixsync',
    'access_delivery_last_debug'
);
if ($accessdebugraw !== '') {
    $decodedaccessdebug = json_decode($accessdebugraw, true);
    if (is_array($decodedaccessdebug)) {
        $accessdebug = $decodedaccessdebug;
    }
}

echo $OUTPUT->header();
echo html_writer::link(
    new moodle_url('/local/bitrixsync/access_resend.php'),
    'Повторная выдача доступа / сообщение для мессенджера',
    ['class' => 'btn btn-warning mb-3']
);


if (class_exists('\local_bitrixsync\ui\admin_shell')) {
    echo \local_bitrixsync\ui\admin_shell::render('messages');
}

echo html_writer::tag(
    'h2',
    get_string('message_templates_title', 'local_bitrixsync')
);

echo html_writer::tag('h3', 'Диагностика выдачи доступа', ['class' => 'mt-4']);

$accessdiagtable = new html_table();
$accessdiagtable->data = [
    [
        'Автоматическая выдача доступа',
        get_config('local_bitrixsync', 'access_delivery_enable')
            ? '✅ Включена'
            : '❌ Выключена',
    ],
    [
        'Канал',
        s((string)$service->get('accesschannel')),
    ],
    [
        'Timeline',
        $service->get('timelineenabled')
            ? '✅ Включен'
            : '❌ Выключен',
    ],
    [
        'Имя отправителя',
        s((string)$service->get('accesssendername')),
    ],
    [
        'Email отправителя',
        s((string)$service->get('accesssenderemail')),
    ],
    [
        'Moodle SMTP host',
        !empty($CFG->smtphosts)
            ? s((string)$CFG->smtphosts)
            : '⚠️ Не задан — Moodle использует системную почту PHP/sendmail',
    ],
    [
        'Moodle noreply',
        s((string)($CFG->noreplyaddress ?? '—')),
    ],
];

echo html_writer::table($accessdiagtable);

if (!empty($accessdebug)) {
    echo html_writer::tag('h4', 'Последняя попытка выдачи доступа', ['class' => 'mt-3']);

    $lasttable = new html_table();
    $lasttable->data = [
        ['Время', !empty($accessdebug['time'])
            ? userdate((int)$accessdebug['time'])
            : '—'],
        ['Deal ID', (int)($accessdebug['dealid'] ?? 0)],
        ['Contact ID', (int)($accessdebug['contactid'] ?? 0)],
        ['Moodle User ID', (int)($accessdebug['userid'] ?? 0)],
        ['Получатель', s((string)($accessdebug['recipient'] ?? ''))],
        ['Результат', s((string)($accessdebug['status'] ?? ''))],
        ['Email', s((string)($accessdebug['email_status'] ?? ''))],
        ['Timeline', s((string)($accessdebug['timeline_status'] ?? ''))],
        ['Ошибка Email', s((string)($accessdebug['error'] ?? ''))],
        ['Ошибка Timeline', s((string)($accessdebug['timeline_error'] ?? ''))],
    ];

    echo html_writer::table($lasttable);
} else {
    echo $OUTPUT->notification(
        'После установки v5.0.36.1.12 выполните одну новую регистрацию через '
        . 'Bitrix24. Здесь появится фактический результат Email и Timeline.',
        'info'
    );
}


echo html_writer::tag(
    'p',
    'Возвращены настраиваемые шаблоны из v0.4.6. '
    . 'Существующие значения старой версии используются автоматически.',
    ['class' => 'lead']
);

if ($message) {
    echo $OUTPUT->notification($message, 'success');
}

if ($error) {
    echo $OUTPUT->notification($error, 'error');
}

echo html_writer::start_tag('form', ['method' => 'post']);

echo html_writer::empty_tag('input', [
    'type' => 'hidden',
    'name' => 'sesskey',
    'value' => sesskey(),
]);
echo html_writer::empty_tag('input', [
    'type' => 'hidden',
    'name' => 'action',
    'value' => 'save',
]);

// --------------------------------------------------------------
// Access email.
// --------------------------------------------------------------
echo html_writer::start_tag('div', ['class' => 'card mb-4']);
echo html_writer::start_tag('div', ['class' => 'card-body']);

echo html_writer::tag('h3', 'Письмо с доступом ученику');

echo html_writer::tag(
    'p',
    'Шаблон и канал из v0.4.6 сохранены для совместимости. '
    . 'Подстановки: {firstname}, {lastname}, {fullname}, {login}, '
    . '{password}, {url}, {course}, {courseid}, {dealid}.',
    ['class' => 'text-muted']
);

echo html_writer::label('Канал отправки', 'accesschannel');
echo html_writer::select(
    [
        'none' => 'Не отправлять автоматически',
        'moodle' => 'Moodle Email / SMTP',
    ],
    'accesschannel',
    $service->get('accesschannel'),
    false,
    ['class' => 'form-control mb-3']
);

echo html_writer::tag(
    'p',
    'Рабочий транспорт v5.0.35: Moodle Email / SMTP. '
    . 'Bitrix24 Mail / CRM Email больше не показываются как доступные, '
    . 'пока для них нет реальной реализации.',
    ['class' => 'text-muted']
);

echo html_writer::label('Имя отправителя', 'accesssendername');
echo html_writer::empty_tag('input', [
    'type' => 'text',
    'name' => 'accesssendername',
    'id' => 'accesssendername',
    'value' => $service->get('accesssendername'),
    'class' => 'form-control mb-3',
]);

echo html_writer::label('Email отправителя', 'accesssenderemail');
echo html_writer::empty_tag('input', [
    'type' => 'email',
    'name' => 'accesssenderemail',
    'id' => 'accesssenderemail',
    'value' => $service->get('accesssenderemail'),
    'class' => 'form-control mb-3',
]);

echo html_writer::label('Тема письма', 'accessemailsubject');
echo html_writer::empty_tag('input', [
    'type' => 'text',
    'name' => 'accessemailsubject',
    'id' => 'accessemailsubject',
    'value' => $service->get('accessemailsubject'),
    'class' => 'form-control mb-3',
]);

echo html_writer::label('Шаблон письма', 'accessemailbody');
echo html_writer::tag(
    'textarea',
    s($service->get('accessemailbody')),
    [
        'name' => 'accessemailbody',
        'id' => 'accessemailbody',
        'class' => 'form-control',
        'rows' => 12,
    ]
);

echo html_writer::end_tag('div');
echo html_writer::end_tag('div');

// --------------------------------------------------------------
// Timeline common block.
// --------------------------------------------------------------
echo html_writer::start_tag('div', ['class' => 'card mb-4']);
echo html_writer::start_tag('div', ['class' => 'card-body']);

echo html_writer::tag('h3', 'Сообщения в таймлайн Bitrix24');

echo html_writer::start_tag('div', ['class' => 'form-group']);
echo html_writer::checkbox(
    'timelineenabled',
    1,
    (bool)$service->get('timelineenabled'),
    ' Включить шаблоны таймлайна'
);
echo html_writer::end_tag('div');

$common = [
    'timeline_sync_header' => 'Заголовок',
    'timeline_sync_user' => 'Строка пользователя',
    'timeline_sync_email' => 'Строка Email',
    'timeline_sync_course' => 'Строка курса',
    'timeline_sync_group' => 'Строка группы',
];

foreach ($common as $key => $label) {
    echo html_writer::label($label, $key);
    echo html_writer::tag(
        'textarea',
        s($service->get($key)),
        [
            'name' => $key,
            'id' => $key,
            'class' => 'form-control mb-3',
            'rows' => 2,
        ]
    );
}

echo html_writer::end_tag('div');
echo html_writer::end_tag('div');

// --------------------------------------------------------------
// v5 summary templates used now.
// --------------------------------------------------------------
echo html_writer::start_tag('div', ['class' => 'card mb-4']);
echo html_writer::start_tag('div', ['class' => 'card-body']);

echo html_writer::tag(
    'h3',
    'Текущие сообщения KBA Connect v5'
);

echo html_writer::tag(
    'p',
    'Эти два шаблона реально используются текущим CRM writeback при обновлении '
    . 'таймлайна Сделки и Компании.',
    ['class' => 'text-muted']
);

echo html_writer::label(
    'Таймлайн Сделки',
    'timeline_v5_deal_summary'
);
echo html_writer::tag(
    'textarea',
    s($service->get('timeline_v5_deal_summary')),
    [
        'name' => 'timeline_v5_deal_summary',
        'id' => 'timeline_v5_deal_summary',
        'class' => 'form-control mb-3',
        'rows' => 12,
    ]
);

echo html_writer::tag(
    'p',
    'Подстановки: {company}, {userid}, {productrows}, {processed}, '
    . '{enrolled}, {already}, {failed}, {courses}.',
    ['class' => 'text-muted']
);

echo html_writer::label(
    'Таймлайн Компании',
    'timeline_v5_company_summary'
);
echo html_writer::tag(
    'textarea',
    s($service->get('timeline_v5_company_summary')),
    [
        'name' => 'timeline_v5_company_summary',
        'id' => 'timeline_v5_company_summary',
        'class' => 'form-control mb-3',
        'rows' => 10,
    ]
);

echo html_writer::tag(
    'p',
    'Подстановки: {company}, {corporatecompanyid}, '
    . '{activecoursecount}, {employeecount}, {courses}.',
    ['class' => 'text-muted']
);

echo html_writer::end_tag('div');
echo html_writer::end_tag('div');

// --------------------------------------------------------------
// Original event templates.
// --------------------------------------------------------------
echo html_writer::start_tag('div', ['class' => 'card mb-4']);
echo html_writer::start_tag('div', ['class' => 'card-body']);

echo html_writer::tag(
    'h3',
    'Событийные шаблоны v0.4.6'
);

echo html_writer::tag(
    'p',
    'Сохранены для совместимости и следующих модулей прогресса/уведомлений.',
    ['class' => 'text-muted']
);

$events = [
    'timeline_account_created' => 'Создана учетная запись',
    'timeline_course_enrolled' => 'Зачисление на курс',
    'timeline_group_added' => 'Добавление в группу',
    'timeline_learning_started' => 'Начало обучения',
    'timeline_course_completed' => 'Завершение курса',
    'timeline_access_sent' => 'Доступ отправлен',
    'timeline_sync_error' => 'Ошибка синхронизации',
    'timeline_first_login' => 'Первый вход',
    'timeline_progress_threshold' => 'Порог прогресса',
    'timeline_webinar_attended' => 'Посещение вебинара',
];

foreach ($events as $key => $label) {
    echo html_writer::label($label, $key);
    echo html_writer::tag(
        'textarea',
        s($service->get($key)),
        [
            'name' => $key,
            'id' => $key,
            'class' => 'form-control mb-3',
            'rows' => 4,
        ]
    );
}

echo html_writer::label(
    'Пороги прогресса',
    'timeline_progress_thresholds'
);
echo html_writer::empty_tag('input', [
    'type' => 'text',
    'name' => 'timeline_progress_thresholds',
    'id' => 'timeline_progress_thresholds',
    'value' => $service->get('timeline_progress_thresholds'),
    'class' => 'form-control mb-3',
]);

echo html_writer::end_tag('div');
echo html_writer::end_tag('div');

echo html_writer::empty_tag('input', [
    'type' => 'submit',
    'value' => 'Сохранить шаблоны',
    'class' => 'btn btn-primary',
]);

echo html_writer::end_tag('form');

// Reset defaults separately.
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
    'value' => 'reset',
]);
echo html_writer::empty_tag('input', [
    'type' => 'submit',
    'value' => 'Восстановить стандартные шаблоны',
    'class' => 'btn btn-outline-secondary',
]);
echo html_writer::end_tag('form');

echo $OUTPUT->footer();
