<?php
require(__DIR__ . '/../../config.php');

require_login();
require_capability('local/bitrixsync:manage', context_system::instance());

$PAGE->set_url(new moodle_url('/local/bitrixsync/crm_fields.php'));
$PAGE->set_context(context_system::instance());
$PAGE->set_title(get_string('crm_fields_title', 'local_bitrixsync'));

$manager = new \local_bitrixsync\services\crm_field_manager();
$sync = new \local_bitrixsync\services\crm_field_sync_service();

$recovered = $sync->recover_stale();

$action = optional_param(
    'action',
    '',
    PARAM_ALPHA
);

$message = null;
$error = null;

try {
    if ($action === 'refresh') {
        require_sesskey();

        $sync->enqueue_all(false);

        $message =
            'Проверка CRM-полей поставлена в фон.';
    } else if ($action === 'provision') {
        require_sesskey();

        $sync->enqueue_all(true);

        $message =
            'Создание отсутствующих CRM-полей поставлено в фон.';
    } else if ($action === 'importlegacy') {
        require_sesskey();

        $result = $manager->import_legacy_fieldmap();

        $message =
            'Импортировано существующих полей из карты v0.4.6: ' .
            (int)$result['imported'] .
            '. Новые поля при этом не создавались.';
    } else if ($action === 'resetstale') {
        require_sesskey();

        $count = $sync->fail_processing_now();

        $message =
            'Сброшено зависших CRM-задач: ' .
            $count .
            '. Ошибка теперь видна в таблице.';
    } else if ($action === 'retryfailed') {
        require_sesskey();

        $count = $sync->retry_failed();

        $message =
            'Возвращено в очередь CRM-задач: ' .
            $count .
            '.';
    }
} catch (\Throwable $e) {
    $error = $e->getMessage();
}

$definitions = $manager->definitions();
$resolved = $manager->get_cached_resolution();
$cachetime = $manager->get_cached_resolution_time();
$queuestatus = $sync->queue_status();
$active = $sync->has_active_jobs();
$legacy = $manager->legacy_field_summary();

if ($active) {
    $PAGE->requires->js_init_code(
        "window.setTimeout(function(){window.location.reload();}, 8000);"
    );
}

echo $OUTPUT->header();

if (class_exists('\local_bitrixsync\ui\admin_shell')) {
    echo \local_bitrixsync\ui\admin_shell::render(
        'fields'
    );
}

echo html_writer::tag(
    'h2',
    get_string('crm_fields_title', 'local_bitrixsync')
);

echo html_writer::tag(
    'p',
    'KBA Connect сначала ищет уже существующие поля Moodle/KBA, включая поля старой версии v0.4.6, и только после этого создаёт действительно отсутствующие.',
    ['class' => 'lead']
);

if ($recovered > 0) {
    echo $OUTPUT->notification(
        'Обнаружены старые зависшие фоновые задачи: ' .
        $recovered .
        '. Они переведены в статус «Ошибка», чтобы страница больше не висела бесконечно.',
        'warning'
    );
}

if ($message) {
    echo $OUTPUT->notification(
        $message,
        'success'
    );
}

if ($error) {
    echo $OUTPUT->notification(
        $error,
        'error'
    );
}

// Legacy fieldmap is local and instant - no REST calls.
echo html_writer::start_tag(
    'div',
    ['class' => 'card mb-4']
);
echo html_writer::start_tag(
    'div',
    ['class' => 'card-body']
);

echo html_writer::tag(
    'h3',
    'Существующие поля Moodle из старой версии'
);

if ($legacy) {
    $legacycount = 0;

    foreach ($legacy as $rows) {
        $legacycount += count($rows);
    }

    echo html_writer::tag(
        'p',
        'В старой сохранённой карте Bitrix24 найдено Moodle-полей: ' .
        $legacycount .
        '. Их можно повторно использовать вместо создания дублей.'
    );

    echo html_writer::start_tag(
        'details'
    );
    echo html_writer::tag(
        'summary',
        'Показать найденные поля'
    );

    foreach ($legacy as $entity => $rows) {
        echo html_writer::tag(
            'h4',
            s(ucfirst($entity)),
            ['class' => 'mt-3']
        );

        echo html_writer::start_tag(
            'table',
            ['class' => 'generaltable']
        );

        echo html_writer::tag(
            'tr',
            html_writer::tag('th', 'Название') .
            html_writer::tag('th', 'XML ID') .
            html_writer::tag('th', 'Bitrix FIELD_NAME')
        );

        foreach ($rows as $row) {
            echo html_writer::tag(
                'tr',
                html_writer::tag(
                    'td',
                    s((string)$row['label'])
                ) .
                html_writer::tag(
                    'td',
                    s((string)$row['xml_id'])
                ) .
                html_writer::tag(
                    'td',
                    s((string)$row['field_name'])
                )
            );
        }

        echo html_writer::end_tag('table');
    }

    echo html_writer::end_tag('details');

    echo html_writer::start_tag(
        'form',
        [
            'method' => 'post',
            'class' => 'mt-3',
        ]
    );

    echo html_writer::empty_tag(
        'input',
        [
            'type' => 'hidden',
            'name' => 'sesskey',
            'value' => sesskey(),
        ]
    );

    echo html_writer::empty_tag(
        'input',
        [
            'type' => 'hidden',
            'name' => 'action',
            'value' => 'importlegacy',
        ]
    );

    echo html_writer::empty_tag(
        'input',
        [
            'type' => 'submit',
            'value' => 'Использовать существующие поля v0.4.6',
            'class' => 'btn btn-outline-primary',
        ]
    );

    echo html_writer::end_tag('form');
} else {
    echo html_writer::tag(
        'p',
        'Сохранённая карта полей v0.4.6 не найдена. Будет выполнена обычная проверка Bitrix24.',
        ['class' => 'text-muted']
    );
}

echo html_writer::end_tag('div');
echo html_writer::end_tag('div');

// Queue state.
echo html_writer::tag(
    'h3',
    'Фоновая проверка / синхронизация'
);

if ($active) {
    echo $OUTPUT->notification(
        'Есть активные задачи. Страница обновляется автоматически. '
        . 'Задача, которая остаётся «Выполняется» более 3 минут, будет автоматически помечена как ошибка.',
        'info'
    );
}

echo html_writer::start_tag(
    'table',
    ['class' => 'generaltable mb-4']
);

echo html_writer::tag(
    'tr',
    html_writer::tag('th', 'Сущность') .
    html_writer::tag('th', 'Операция') .
    html_writer::tag('th', 'Статус') .
    html_writer::tag('th', 'Попыток') .
    html_writer::tag('th', 'Возраст') .
    html_writer::tag('th', 'Ошибка')
);

$entitylabels = [
    'company' => 'Компания',
    'contact' => 'Контакт',
    'deal' => 'Сделка',
];

foreach ($queuestatus as $entity => $job) {
    $status = (string)$job['status'];

    if ($status === 'completed') {
        $badge = html_writer::tag(
            'span',
            'Готово',
            ['class' => 'badge badge-success']
        );
    } else if ($status === 'failed') {
        $badge = html_writer::tag(
            'span',
            'Ошибка',
            ['class' => 'badge badge-danger']
        );
    } else if ($status === 'processing') {
        $badge = html_writer::tag(
            'span',
            'Выполняется',
            ['class' => 'badge badge-info']
        );
    } else if ($status === 'pending') {
        $badge = html_writer::tag(
            'span',
            'В очереди',
            ['class' => 'badge badge-warning']
        );
    } else {
        $badge = html_writer::tag(
            'span',
            'Ожидание',
            ['class' => 'badge badge-secondary']
        );
    }

    $operation =
        (string)$job['action'] ===
        'crm_fields_provision_entity'
            ? 'Создать / синхронизировать'
            : (
                (string)$job['action'] ===
                'crm_fields_refresh_entity'
                    ? 'Проверить'
                    : '—'
            );

    echo html_writer::tag(
        'tr',
        html_writer::tag(
            'td',
            s($entitylabels[$entity] ?? $entity)
        ) .
        html_writer::tag(
            'td',
            s($operation)
        ) .
        html_writer::tag(
            'td',
            $badge
        ) .
        html_writer::tag(
            'td',
            s((string)$job['attempts'])
        ) .
        html_writer::tag(
            'td',
            $job['age'] > 0
                ? s((string)$job['age']) . ' сек'
                : '—'
        ) .
        html_writer::tag(
            'td',
            s((string)$job['error'])
        )
    );
}

echo html_writer::end_tag('table');

if ($cachetime > 0) {
    echo html_writer::tag(
        'p',
        'Последняя актуализация карты: ' .
        userdate($cachetime),
        ['class' => 'text-muted']
    );
}

// Current v5 field map.
foreach ($definitions as $entity => $fields) {
    $entitylabel =
        $entitylabels[$entity] ??
        $entity;

    echo html_writer::tag(
        'h3',
        $entitylabel
    );

    echo html_writer::start_tag(
        'table',
        ['class' => 'generaltable mb-4']
    );

    echo html_writer::tag(
        'tr',
        html_writer::tag('th', 'Поле') .
        html_writer::tag('th', 'XML ID') .
        html_writer::tag('th', 'Bitrix FIELD_NAME') .
        html_writer::tag('th', 'Источник') .
        html_writer::tag('th', 'Статус')
    );

    foreach ($fields as $key => $def) {
        $state =
            $resolved[$entity][$key] ??
            [];

        $ready =
            !empty($state['ready']);

        echo html_writer::tag(
            'tr',
            html_writer::tag(
                'td',
                s($def['LABEL'])
            ) .
            html_writer::tag(
                'td',
                s($def['XML_ID'])
            ) .
            html_writer::tag(
                'td',
                s((string)(
                    $state['field_name'] ??
                    '—'
                ))
            ) .
            html_writer::tag(
                'td',
                s((string)(
                    $state['source'] ??
                    '—'
                ))
            ) .
            html_writer::tag(
                'td',
                $ready
                    ? html_writer::tag(
                        'span',
                        'Готово',
                        [
                            'class' =>
                                'badge badge-success',
                        ]
                    )
                    : html_writer::tag(
                        'span',
                        'Отсутствует',
                        [
                            'class' =>
                                'badge badge-warning',
                        ]
                    )
            )
        );
    }

    echo html_writer::end_tag('table');
}

// Actions.
echo html_writer::start_tag(
    'div',
    ['class' => 'd-flex flex-wrap']
);

$buttons = [
    [
        'action' => 'refresh',
        'label' => 'Проверить поля в фоне',
        'class' => 'btn btn-secondary',
        'disabled' => $active,
    ],
    [
        'action' => 'provision',
        'label' => 'Создать только отсутствующие',
        'class' => 'btn btn-primary',
        'disabled' => $active,
    ],
    [
        'action' => 'resetstale',
        'label' => 'Сбросить зависшие задачи',
        'class' => 'btn btn-outline-danger',
        'disabled' => false,
    ],
    [
        'action' => 'retryfailed',
        'label' => 'Повторить задачи с ошибкой',
        'class' => 'btn btn-outline-secondary',
        'disabled' => $active,
    ],
];

foreach ($buttons as $button) {
    echo html_writer::start_tag(
        'form',
        [
            'method' => 'post',
            'class' => 'mr-2 mb-2',
        ]
    );

    echo html_writer::empty_tag(
        'input',
        [
            'type' => 'hidden',
            'name' => 'sesskey',
            'value' => sesskey(),
        ]
    );

    echo html_writer::empty_tag(
        'input',
        [
            'type' => 'hidden',
            'name' => 'action',
            'value' => $button['action'],
        ]
    );

    $attrs = [
        'type' => 'submit',
        'value' => $button['label'],
        'class' => $button['class'],
    ];

    if ($button['disabled']) {
        $attrs['disabled'] = 'disabled';
    }

    echo html_writer::empty_tag(
        'input',
        $attrs
    );

    echo html_writer::end_tag('form');
}

echo html_writer::end_tag('div');

echo html_writer::tag(
    'p',
    'Если Bitrix24 REST зависнет или вернёт ошибку, задача больше не останется бесконечно в «Выполняется»: '
    . 'через 3 минуты она станет «Ошибка», а текст будет показан здесь.',
    ['class' => 'text-muted mt-3']
);

echo $OUTPUT->footer();
