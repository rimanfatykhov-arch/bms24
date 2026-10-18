<?php
require(__DIR__ . '/../../config.php');

require_login();
require_capability('local/bitrixsync:manage', context_system::instance());

$PAGE->set_url(new moodle_url('/local/bitrixsync/corporate_rules.php'));
$PAGE->set_context(context_system::instance());
$PAGE->set_title(get_string('corporate_settings_title', 'local_bitrixsync'));

$action = optional_param('action', '', PARAM_ALPHAEXT);
$message = null;
$error = null;
$diagnostic = null;
$schemastatus = null;
$generatedtoken = null;
$pairingresult = null;
$pairingservice = new \local_bitrixsync\services\corporate_pairing_service();
$schemaservice = new \local_bitrixsync\services\corporate_role_schema_service();

/** Render a compact value row used by the Corporate integration cards. */
function local_bitrixsync_corporate_value_row(string $label, string $value, string $state = ''): string {
    $badge = '';
    if ($state !== '') {
        $badgeclass = $state === 'ok' ? 'badge-success' : ($state === 'warn' ? 'badge-warning' : 'badge-secondary');
        $badge = ' ' . html_writer::tag('span', s($value), ['class' => 'badge ' . $badgeclass]);
        $value = '';
    }
    return html_writer::tag(
        'div',
        html_writer::tag('strong', s($label)) . ': ' . ($value !== '' ? s($value) : '') . $badge,
        ['class' => 'mb-2']
    );
}

try {
    if ($action === 'save') {
        require_sesskey();

        $enable = optional_param('corporate_enable', 0, PARAM_BOOL);
        $mode = optional_param('corporate_license_mode', 'enforce', PARAM_ALPHA);
        $url = trim(optional_param('corporate_api_url', '', PARAM_RAW_TRIMMED));
        $key = trim(optional_param('corporate_api_key', '', PARAM_RAW_TRIMMED));
        $integrationtoken = trim(optional_param('corporate_integration_token', '', PARAM_RAW_TRIMMED));

        if (!in_array($mode, ['off', 'sync', 'enforce'], true)) {
            $mode = 'enforce';
        }

        set_config('corporate_enable', $enable ? 1 : 0, 'local_bitrixsync');
        set_config('corporate_license_mode', $mode, 'local_bitrixsync');
        set_config('corporate_api_url', $url, 'local_bitrixsync');

        // Empty secret fields mean "leave unchanged".
        if ($key !== '') {
            set_config('corporate_api_key', $key, 'local_bitrixsync');
        }
        if ($integrationtoken !== '') {
            set_config('corporate_integration_token', $integrationtoken, 'local_bitrixsync');
        }

        $message = 'Настройки Corporate сохранены.';
    } else if ($action === 'test') {
        require_sesskey();
        $diagnostic = (new \local_bitrixsync\services\corporate_bridge())->diagnostic();
    } else if ($action === 'schema_status') {
        require_sesskey();
        $schemastatus = $schemaservice->status();
        $message = !empty($schemastatus['ready'])
            ? 'Схема корпоративных ролей готова.'
            : 'Проверка схемы завершена. Требуется настройка.';
    } else if ($action === 'schema_provision') {
        require_sesskey();
        $schemastatus = $schemaservice->provision();
        if (!empty($schemastatus['success']) && !empty($schemastatus['ready'])) {
            $message = 'Поле корпоративных ролей Bitrix24 создано / проверено. Схема готова.';
        } else {
            $error = (string)($schemastatus['message'] ?? $schemastatus['reason'] ?? 'Не удалось подготовить схему ролей.');
        }
    } else if ($action === 'role_sync_diagnostics') {
        require_sesskey();
        $contactid = required_param('role_sync_contactid', PARAM_INT);
        $rolesyncdebug = (
            new \local_bitrixsync\services\corporate_role_sync_diagnostic_service()
        )->run($contactid);
    } else if ($action === 'bitrix_diagnostics') {
        require_sesskey();
        $diagnostic = (
            new \local_bitrixsync\services\corporate_bitrix_diagnostic_service()
        )->run();
    } else if ($action === 'generate_pairing') {
        require_sesskey();
        $environment = optional_param('pairing_environment', 'production', PARAM_ALPHA);
        $pairingresult = $pairingservice->generate($environment);
        $message = 'Одноразовый код подключения создан.';
    } else if ($action === 'revoke_pairing') {
        require_sesskey();
        if ($pairingservice->revoke_latest()) {
            $message = 'Подключение Corporate Dashboard отозвано.';
        } else {
            $error = 'Активное подключение не найдено.';
        }
    }
} catch (\Throwable $e) {
    $error = $e->getMessage();
}

// Always show the current schema state when possible, so this page is a real control center.
if ($schemastatus === null) {
    try {
        $schemastatus = $schemaservice->status();
    } catch (\Throwable $ignored) {
        $schemastatus = null;
    }
}

$tokenconfigured = trim((string)get_config('local_bitrixsync', 'corporate_integration_token')) !== '';
$activeconnection = $pairingservice->latest_connection();
$activeconnections = (new \local_bitrixsync\services\corporate_connection_service())->active_count();

$rolesyncdebug = [];
$rolesyncdebugraw = (string)get_config(
    'local_bitrixsync',
    'corporate_role_sync_debug_last'
);
if ($rolesyncdebugraw !== '') {
    $decodedrolesyncdebug = json_decode($rolesyncdebugraw, true);
    if (is_array($decodedrolesyncdebug)) {
        $rolesyncdebug = $decodedrolesyncdebug;
    }
}


$lastrolesync = [];
$lastrolesyncraw = (string)get_config(
    'local_bitrixsync',
    'corporate_role_sync_last'
);
if ($lastrolesyncraw !== '') {
    $decodedlastrolesync = json_decode($lastrolesyncraw, true);
    if (is_array($decodedlastrolesync)) {
        $lastrolesync = $decodedlastrolesync;
    }
}


$schemanormalization = $schemaservice ? $schemaservice->role_schema_diagnostic() : [];

$enumdiagnostic = [];
$enumraw = (string)get_config(
    'local_bitrixsync',
    'corporate_role_enum_last_diagnostic'
);
if ($enumraw !== '') {
    $enumdecoded = json_decode($enumraw, true);
    if (is_array($enumdecoded)) {
        $enumdiagnostic = $enumdecoded;
    }
}


$dashboard = is_array($schemastatus['dashboard'] ?? null) ? $schemastatus['dashboard'] : [];
$field = is_array($schemastatus['field'] ?? null) ? $schemastatus['field'] : [];
$roles = is_array($schemastatus['roles'] ?? null) ? $schemastatus['roles'] : [];
$enumvalues = is_array($schemastatus['enum_values'] ?? null) ? $schemastatus['enum_values'] : [];
$rolesready = 0;
foreach ($roles as $role) {
    if (!empty($role['exists'])) {
        $rolesready++;
    }
}

$dashboarddetected = !empty($dashboard['detected']);
$schemaready = !empty($schemastatus['success']) && !empty($schemastatus['ready']);
$fieldexists = !empty($field['exists']) || !empty($field['id']);

$rolescontract = [
    'employee' => 'Сотрудник',
    'corporate_manager' => 'Корпоративный менеджер',
    'department_manager' => 'Руководитель подразделения',
    'hr' => 'HR',
];

echo $OUTPUT->header();
echo \local_bitrixsync\ui\admin_shell::render('corporate');

echo html_writer::tag('h2', get_string('corporate_settings_title', 'local_bitrixsync'));

if ($message) {
    echo $OUTPUT->notification($message, 'success');
}
if ($error) {
    echo $OUTPUT->notification($error, 'error');
}
if ($pairingresult !== null && !empty($pairingresult['success'])) {
    $expiry = userdate((int)$pairingresult['expires_at'], get_string('strftimedatetimeshort', 'langconfig'));
    echo $OUTPUT->notification(
        'Код подключения (показывается один раз): ' . s((string)$pairingresult['pairing_code']) .
        ' — действует до ' . s($expiry),
        'warning'
    );
}

// ------------------------------------------------------------------
// Card 1. KBA Corporate Dashboard.
// Pairing is the single visible source of connection status.
// ------------------------------------------------------------------
echo html_writer::start_tag('div', ['class' => 'card mb-4']);
echo html_writer::start_tag('div', ['class' => 'card-body']);
echo html_writer::tag('h3', '1. KBA Corporate Dashboard', ['class' => 'h4']);

if ($activeconnection) {
    echo local_bitrixsync_corporate_value_row('Статус', 'Подключено', 'ok');
    echo local_bitrixsync_corporate_value_row('Environment', ucfirst((string)$activeconnection->environment));
    echo local_bitrixsync_corporate_value_row('Component', (string)$activeconnection->component);
    echo local_bitrixsync_corporate_value_row(
        'Dashboard',
        (string)($activeconnection->clientversion ?: 'Подключён')
    );
    echo local_bitrixsync_corporate_value_row('API', (string)($activeconnection->apiversion ?: '1.11'));
    echo local_bitrixsync_corporate_value_row(
        'Последняя проверка',
        !empty($activeconnection->lastseen) ? userdate((int)$activeconnection->lastseen) : '—'
    );

    echo html_writer::start_tag('form', ['method' => 'post', 'class' => 'mt-3']);
    echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'sesskey', 'value' => sesskey()]);
    echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'action', 'value' => 'revoke_pairing']);
    echo html_writer::empty_tag('input', [
        'type' => 'submit',
        'value' => 'Отозвать подключение',
        'class' => 'btn btn-outline-danger'
    ]);
    echo html_writer::end_tag('form');
} else {
    echo local_bitrixsync_corporate_value_row('Статус', 'Не подключено', 'warn');
    echo html_writer::tag(
        'p',
        'KBA Connect URL: ' . s($CFG->wwwroot . '/local/bitrixsync'),
        ['class' => 'text-muted']
    );
    echo html_writer::start_tag('form', ['method' => 'post', 'class' => 'form-inline mt-3']);
    echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'sesskey', 'value' => sesskey()]);
    echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'action', 'value' => 'generate_pairing']);
    echo html_writer::select(
        ['production' => 'Production', 'test' => 'Test', 'development' => 'Development'],
        'pairing_environment',
        'production',
        false,
        ['class' => 'form-control mr-2']
    );
    echo html_writer::empty_tag('input', [
        'type' => 'submit',
        'value' => 'Создать код подключения',
        'class' => 'btn btn-primary'
    ]);
    echo html_writer::end_tag('form');
}

if (!empty($lastrolesync)) {
    echo html_writer::tag('h4', 'Последняя синхронизация ролей', ['class' => 'h5 mt-4']);
    echo local_bitrixsync_corporate_value_row(
        'Contact ID',
        (string)($lastrolesync['contactid'] ?? '—')
    );
    echo local_bitrixsync_corporate_value_row(
        'Moodle User ID',
        (string)($lastrolesync['userid'] ?? '—')
    );
    echo local_bitrixsync_corporate_value_row(
        'Компания',
        (string)($lastrolesync['company'] ?? '—')
    );
    echo local_bitrixsync_corporate_value_row(
        'Bitrix Company ID',
        (string)($lastrolesync['bitrix_company_id'] ?? '—')
    );
    echo local_bitrixsync_corporate_value_row(
        'Corporate Company ID',
        (string)($lastrolesync['corporate_company_id'] ?? '—'),
        !empty($lastrolesync['corporate_company_id']) ? 'ok' : 'warn'
    );
    echo local_bitrixsync_corporate_value_row(
        'Bitrix roles',
        implode(', ', (array)($lastrolesync['roles'] ?? [])) ?: '—'
    );
    echo local_bitrixsync_corporate_value_row(
        'Стратегия',
        strtoupper((string)($lastrolesync['strategy'] ?? 'merge'))
    );
    echo local_bitrixsync_corporate_value_row(
        'Результат',
        (string)($lastrolesync['status'] ?? '—'),
        in_array((string)($lastrolesync['status'] ?? ''), ['merged', 'attached'], true)
            ? 'ok'
            : 'warn'
    );
    echo local_bitrixsync_corporate_value_row(
        'Время',
        !empty($lastrolesync['time'])
            ? userdate((int)$lastrolesync['time'])
            : '—'
    );
}

echo html_writer::tag('h4', 'Проверка синхронизации ролей', ['class' => 'h5 mt-4']);
echo html_writer::start_tag('form', ['method' => 'post', 'class' => 'form-inline mb-3']);
echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'sesskey', 'value' => sesskey()]);
echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'action', 'value' => 'role_sync_diagnostics']);
echo html_writer::label('Contact ID', 'role_sync_contactid', false, ['class' => 'mr-2']);
echo html_writer::empty_tag('input', [
    'type' => 'number',
    'name' => 'role_sync_contactid',
    'id' => 'role_sync_contactid',
    'value' => '12657',
    'min' => 1,
    'class' => 'form-control mr-2',
]);
echo html_writer::empty_tag('input', [
    'type' => 'submit',
    'value' => 'Проверить синхронизацию ролей',
    'class' => 'btn btn-warning'
]);
echo html_writer::end_tag('form');

if (!empty($rolesyncdebug)) {
    echo html_writer::start_tag('div', ['class' => 'card mb-3']);
    echo html_writer::start_tag('div', ['class' => 'card-body']);
    echo html_writer::tag('h5', 'Последний Role Sync Debug');

    echo local_bitrixsync_corporate_value_row(
        'Contact ID',
        (string)($rolesyncdebug['contactid'] ?? '—')
    );
    echo local_bitrixsync_corporate_value_row(
        'Итог',
        (string)($rolesyncdebug['summary'] ?? '—'),
        (($rolesyncdebug['summary'] ?? '') === 'completed') ? 'ok' : 'warn'
    );

    $steps = is_array($rolesyncdebug['steps'] ?? null)
        ? $rolesyncdebug['steps']
        : [];

    if (!empty($steps['moodle_user'])) {
        echo local_bitrixsync_corporate_value_row(
            'Moodle User ID',
            (string)($steps['moodle_user']['userid'] ?? '—')
        );
    }

    if (!empty($steps['company_from_bitrix'])) {
        echo local_bitrixsync_corporate_value_row(
            'Bitrix Company ID',
            (string)($steps['company_from_bitrix']['bitrix_company_id'] ?? '—')
        );
        echo local_bitrixsync_corporate_value_row(
            'Компания',
            (string)($steps['company_from_bitrix']['title'] ?? '—')
        );
    }

    if (!empty($steps['roles_from_bitrix'])) {
        echo local_bitrixsync_corporate_value_row(
            'Role read status',
            (string)($steps['roles_from_bitrix']['status'] ?? '—')
        );
        echo local_bitrixsync_corporate_value_row(
            'Role field',
            (string)($steps['roles_from_bitrix']['field_name'] ?? '—')
        );
        echo local_bitrixsync_corporate_value_row(
            'Raw Bitrix values',
            implode(', ', (array)($steps['roles_from_bitrix']['raw_values'] ?? [])) ?: '—'
        );
        echo local_bitrixsync_corporate_value_row(
            'Roles from Bitrix',
            implode(', ', (array)($steps['roles_from_bitrix']['roles'] ?? [])) ?: '—'
        );
    }

    if (!empty($steps['corporate_sync']['result'])) {
        $corpresult = $steps['corporate_sync']['result'];

        echo local_bitrixsync_corporate_value_row(
            'Corporate status',
            (string)($corpresult['status'] ?? '—')
        );
        echo local_bitrixsync_corporate_value_row(
            'Corporate Company ID',
            (string)($corpresult['corporate_company_id'] ?? '—')
        );

        $roleresult = is_array($corpresult['roles'] ?? null)
            ? $corpresult['roles']
            : [];

        echo local_bitrixsync_corporate_value_row(
            'Role merge status',
            (string)($roleresult['status'] ?? '—')
        );
        echo local_bitrixsync_corporate_value_row(
            'Role merge strategy',
            strtoupper((string)($roleresult['strategy'] ?? 'merge'))
        );
        echo local_bitrixsync_corporate_value_row(
            'Merged roles',
            implode(', ', (array)($roleresult['roles'] ?? [])) ?: '—'
        );

        if (!empty($roleresult['reason'])) {
            echo local_bitrixsync_corporate_value_row(
                'Role merge reason',
                (string)$roleresult['reason'],
                'warn'
            );
        }

        if (!empty($roleresult['error'])) {
            echo local_bitrixsync_corporate_value_row(
                'Role merge error',
                (string)$roleresult['error'],
                'warn'
            );
        }
    }

    if (!empty($rolesyncdebug['error'])) {
        echo local_bitrixsync_corporate_value_row(
            'Ошибка',
            (string)$rolesyncdebug['error'],
            'warn'
        );
    }

    echo html_writer::start_tag('details', ['class' => 'mt-3']);
    echo html_writer::tag('summary', 'Полный Role Sync Debug JSON');
    echo html_writer::tag(
        'pre',
        s(json_encode(
            $rolesyncdebug,
            JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
        )),
        ['class' => 'mt-3']
    );
    echo html_writer::end_tag('details');

    echo html_writer::end_tag('div');
    echo html_writer::end_tag('div');
}

// Legacy settings are preserved but removed from the normal workflow.
echo html_writer::start_tag('details', ['class' => 'mt-4']);
echo html_writer::tag('summary', 'Расширенные / Legacy настройки', ['class' => 'text-muted']);
echo html_writer::start_tag('div', ['class' => 'mt-3']);

echo html_writer::start_tag('form', ['method' => 'post']);
echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'sesskey', 'value' => sesskey()]);
echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'action', 'value' => 'save']);

echo html_writer::start_tag('div', ['class' => 'form-group']);
echo html_writer::checkbox(
    'corporate_enable',
    1,
    (bool)get_config('local_bitrixsync', 'corporate_enable'),
    ' Включить Corporate интеграцию'
);
echo html_writer::end_tag('div');

echo html_writer::label('Режим контроля лицензий', 'corporate_license_mode');
echo html_writer::select(
    ['off' => 'Off', 'sync' => 'Sync', 'enforce' => 'Enforce'],
    'corporate_license_mode',
    (string)(get_config('local_bitrixsync', 'corporate_license_mode') ?: 'enforce'),
    false,
    ['class' => 'form-control mb-3']
);

echo html_writer::label('Corporate API URL', 'corporate_api_url');
echo html_writer::empty_tag('input', [
    'type' => 'url',
    'name' => 'corporate_api_url',
    'id' => 'corporate_api_url',
    'value' => (string)get_config('local_bitrixsync', 'corporate_api_url'),
    'class' => 'form-control mb-3',
]);

echo html_writer::label('Corporate API Key', 'corporate_api_key');
echo html_writer::empty_tag('input', [
    'type' => 'password',
    'name' => 'corporate_api_key',
    'id' => 'corporate_api_key',
    'value' => '',
    'placeholder' => 'Оставьте пустым, чтобы не менять',
    'class' => 'form-control mb-3',
    'autocomplete' => 'new-password',
]);

echo html_writer::label('Legacy Bearer token', 'corporate_integration_token');
echo html_writer::empty_tag('input', [
    'type' => 'password',
    'name' => 'corporate_integration_token',
    'id' => 'corporate_integration_token',
    'value' => '',
    'placeholder' => $tokenconfigured ? 'Настроен — оставьте пустым, чтобы не менять' : '',
    'class' => 'form-control mb-3',
    'autocomplete' => 'new-password',
]);

echo html_writer::empty_tag('input', [
    'type' => 'submit',
    'value' => 'Сохранить Legacy настройки',
    'class' => 'btn btn-outline-secondary'
]);
echo html_writer::end_tag('form');

echo html_writer::end_tag('div');
echo html_writer::end_tag('details');

echo html_writer::end_tag('div');
echo html_writer::end_tag('div');

// ------------------------------------------------------------------
// Card 2. Bitrix24 Corporate Roles.
// ------------------------------------------------------------------
echo html_writer::start_tag('div', ['class' => 'card mb-4']);
echo html_writer::start_tag('div', ['class' => 'card-body']);
echo html_writer::tag('h3', '2. Корпоративные роли Bitrix24', ['class' => 'h4']);
echo html_writer::tag(
    'p',
    'Поле создаётся только для CRM Contact через основной CRM Field Engine KBA Connect и сохранённый REST webhook Bitrix24.',
    ['class' => 'text-muted']
);

$bitrixconnected = !empty($schemastatus['bitrix24']['connected']) ||
    !empty($schemastatus['success']) ||
    in_array((string)($schemastatus['reason'] ?? ''), [
        'field_missing', 'field_wrong_type', 'field_not_multiple', 'enum_missing'
    ], true);

echo local_bitrixsync_corporate_value_row(
    'Bitrix24 REST',
    $bitrixconnected ? 'Connected' : 'Unavailable',
    $bitrixconnected ? 'ok' : 'warn'
);
echo local_bitrixsync_corporate_value_row(
    'Источник',
    'Основной REST webhook / CRM Field Engine'
);

echo local_bitrixsync_corporate_value_row(
    'Поле',
    $schemaready ? 'Ready' : ($fieldexists ? 'Needs attention' : 'Not created'),
    $schemaready ? 'ok' : 'warn'
);
echo local_bitrixsync_corporate_value_row(
    'Название',
    (string)($field['label'] ?? 'KBA — Роли корпоративного обучения')
);
echo local_bitrixsync_corporate_value_row('Field ID', (string)($field['id'] ?? '—'));
echo local_bitrixsync_corporate_value_row('Field code', (string)($field['field_name'] ?? '—'));
echo local_bitrixsync_corporate_value_row('Тип', (string)($field['type'] ?? 'enumeration'));
echo local_bitrixsync_corporate_value_row(
    'Множественное',
    !empty($field['multiple']) ? 'Да' : ($fieldexists ? 'Нет' : 'Ожидается: Да'),
    (!empty($field['multiple']) || !$fieldexists) ? 'ok' : 'warn'
);
echo local_bitrixsync_corporate_value_row(
    'Роли',
    $rolesready . ' / ' . count($rolescontract),
    $rolesready === count($rolescontract) ? 'ok' : 'warn'
);

if (empty($schemastatus['success']) && !empty($schemastatus['message'])) {
    echo html_writer::tag(
        'div',
        s((string)$schemastatus['message']),
        ['class' => 'alert alert-warning mt-3']
    );
}

echo html_writer::tag('h4', 'Контракт ролей', ['class' => 'h5 mt-4']);
echo html_writer::start_tag('table', ['class' => 'table table-sm table-striped']);
echo html_writer::tag(
    'thead',
    html_writer::tag(
        'tr',
        html_writer::tag('th', 'Код') .
        html_writer::tag('th', 'Название') .
        html_writer::tag('th', 'Bitrix enum') .
        html_writer::tag('th', 'Распознано по')
    )
);
echo html_writer::start_tag('tbody');
foreach ($rolescontract as $code => $label) {
    $rolestatus = is_array($roles[$code] ?? null) ? $roles[$code] : [];
    $enumid = !empty($rolestatus['enum_id']) ? (string)$rolestatus['enum_id'] : '—';
    $enumcell = !empty($rolestatus['exists']) ? $enumid : 'Не настроен';
    $matchedby = (string)($rolestatus['matched_by'] ?? '');
    echo html_writer::tag(
        'tr',
        html_writer::tag('td', s($code)) .
        html_writer::tag('td', s($label)) .
        html_writer::tag('td', s($enumcell)) .
        html_writer::tag('td', s($matchedby !== '' ? $matchedby : '—'))
    );
}
echo html_writer::end_tag('tbody');
echo html_writer::end_tag('table');

if (!empty($enumvalues)) {
    echo html_writer::tag('h4', 'Фактический LIST Bitrix24', ['class' => 'h5 mt-4']);
    echo html_writer::start_tag('table', ['class' => 'table table-sm table-striped']);
    echo html_writer::tag(
        'thead',
        html_writer::tag(
            'tr',
            html_writer::tag('th', 'Enum ID') .
            html_writer::tag('th', 'VALUE') .
            html_writer::tag('th', 'XML_ID') .
            html_writer::tag('th', 'Role code') .
            html_writer::tag('th', 'Статус')
        )
    );
    echo html_writer::start_tag('tbody');

    foreach ($enumvalues as $item) {
        $recognized = !empty($item['recognized']);
        echo html_writer::tag(
            'tr',
            html_writer::tag('td', s((string)($item['id'] ?? '—'))) .
            html_writer::tag('td', s((string)($item['value'] ?? ''))) .
            html_writer::tag('td', s((string)($item['xml_id'] ?? ''))) .
            html_writer::tag('td', s((string)($item['rolecode'] ?? ''))) .
            html_writer::tag(
                'td',
                $recognized ? '✅ role' : '⚠ legacy / unexpected'
            )
        );
    }

    echo html_writer::end_tag('tbody');
    echo html_writer::end_tag('table');

    echo html_writer::tag(
        'p',
        'Лишние legacy-значения KBA Connect автоматически не удаляет. После проверки 4/4 их можно удалить вручную в Bitrix24.',
        ['class' => 'text-muted']
    );
}

echo html_writer::start_tag('div', ['class' => 'mt-3']);

echo html_writer::start_tag('form', ['method' => 'post', 'class' => 'd-inline-block mr-2']);
echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'sesskey', 'value' => sesskey()]);
echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'action', 'value' => 'schema_status']);
echo html_writer::empty_tag('input', [
    'type' => 'submit',
    'value' => 'Проверить',
    'class' => 'btn btn-outline-secondary'
]);
echo html_writer::end_tag('form');

echo html_writer::start_tag('form', ['method' => 'post', 'class' => 'd-inline-block']);
echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'sesskey', 'value' => sesskey()]);
echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'action', 'value' => 'schema_provision']);
echo html_writer::empty_tag('input', [
    'type' => 'submit',
    'value' => $fieldexists ? 'Создать / восстановить роли' : 'Создать поле и роли',
    'class' => 'btn btn-success'
]);
echo html_writer::end_tag('form');

echo html_writer::end_tag('div');

if (!empty($schemanormalization)) {
    echo html_writer::start_tag('div', ['class' => 'alert alert-secondary mt-3']);
    echo html_writer::tag('strong', 'Нормализация Role Schema');
    echo html_writer::start_tag('ul', ['class' => 'mb-0 mt-2']);
    echo html_writer::tag(
        'li',
        'Источник: ' . s((string)($schemanormalization['source'] ?? '—'))
    );
    echo html_writer::tag(
        'li',
        'Raw schema keys: ' .
        s(implode(', ', (array)($schemanormalization['raw_keys'] ?? [])))
    );
    echo html_writer::tag(
        'li',
        'Normalized roles: ' .
        s(implode(', ', (array)($schemanormalization['normalized_roles'] ?? [])))
    );
    echo html_writer::tag(
        'li',
        'Unexpected values: ' .
        s(implode(', ', (array)($schemanormalization['unexpected_values'] ?? [])))
    );
    echo html_writer::tag(
        'li',
        'Missing roles: ' .
        s(implode(', ', (array)($schemanormalization['missing_roles'] ?? [])))
    );
    echo html_writer::end_tag('ul');
    echo html_writer::end_tag('div');
}

if (!empty($enumdiagnostic)) {
    echo html_writer::start_tag('div', ['class' => 'alert alert-info mt-3']);
    echo html_writer::tag('strong', 'Последняя диагностика enum provisioning');
    echo html_writer::start_tag('ul', ['class' => 'mb-0 mt-2']);
    echo html_writer::tag('li', 'Field ID: ' . s((string)($enumdiagnostic['field_id'] ?? '—')));
    echo html_writer::tag('li', 'Было значений: ' . s((string)($enumdiagnostic['before_count'] ?? '0')));
    echo html_writer::tag(
        'li',
        'Запрошено добавить: ' .
        s(implode(', ', (array)($enumdiagnostic['requested_add'] ?? [])))
    );
    echo html_writer::tag('li', 'Стало значений: ' . s((string)($enumdiagnostic['after_count'] ?? '0')));
    echo html_writer::tag(
        'li',
        'Осталось отсутствующих ролей: ' .
        s(implode(', ', (array)($enumdiagnostic['remaining_missing'] ?? [])))
    );
    echo html_writer::tag('li', 'HTTP: ' . s((string)($enumdiagnostic['http_code'] ?? '—')));
    echo html_writer::end_tag('ul');
    echo html_writer::end_tag('div');
}

echo html_writer::start_tag('form', ['method' => 'post', 'class' => 'mt-3']);
echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'sesskey', 'value' => sesskey()]);
echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'action', 'value' => 'bitrix_diagnostics']);
echo html_writer::empty_tag('input', [
    'type' => 'submit',
    'value' => 'Диагностика Bitrix24',
    'class' => 'btn btn-warning'
]);
echo html_writer::end_tag('form');

echo html_writer::end_tag('div');
echo html_writer::end_tag('div');

if ($diagnostic !== null) {
    echo html_writer::start_tag('div', ['class' => 'card mb-4']);
    echo html_writer::start_tag('div', ['class' => 'card-body']);
    echo html_writer::tag('h3', '3. Диагностика Bitrix24', ['class' => 'h4']);
    echo html_writer::tag(
        'p',
        'Все проверки только читают данные. REST webhook и другие секреты на странице не выводятся.',
        ['class' => 'text-muted']
    );

    $summarylabels = [
        'ready' => 'Готово',
        'rest_webhook_not_configured' => 'REST webhook не настроен',
        'main_rest_failed' => 'Не работает основной REST webhook',
        'userfield_api_failed' => 'REST работает, но недоступен API пользовательских полей Contact',
        'crm_field_engine_failed' => 'REST userfield работает, но падает CRM Field Engine',
        'not_run' => 'Не запущено',
    ];
    $summary = (string)($diagnostic['summary'] ?? 'not_run');

    echo local_bitrixsync_corporate_value_row(
        'Итог',
        (string)($summarylabels[$summary] ?? $summary),
        $summary === 'ready' ? 'ok' : 'warn'
    );

    $connection = is_array($diagnostic['connection'] ?? null) ? $diagnostic['connection'] : [];
    echo local_bitrixsync_corporate_value_row(
        'REST webhook настроен',
        !empty($diagnostic['configured']) ? 'Да' : 'Нет',
        !empty($diagnostic['configured']) ? 'ok' : 'warn'
    );
    echo local_bitrixsync_corporate_value_row('Bitrix host', (string)($connection['host'] ?? '—'));
    echo local_bitrixsync_corporate_value_row('REST User ID', (string)($connection['rest_user_id'] ?? '—'));
    echo local_bitrixsync_corporate_value_row(
        'REST secret',
        !empty($connection['secret_configured']) ? 'Настроен, скрыт' : 'Не настроен',
        !empty($connection['secret_configured']) ? 'ok' : 'warn'
    );

    echo html_writer::tag('h4', 'Проверки', ['class' => 'h5 mt-4']);
    echo html_writer::start_tag('table', ['class' => 'table table-sm table-striped']);
    echo html_writer::tag('thead', html_writer::tag('tr',
        html_writer::tag('th', 'Проверка') .
        html_writer::tag('th', 'Статус') .
        html_writer::tag('th', 'HTTP') .
        html_writer::tag('th', 'Transport') .
        html_writer::tag('th', 'Ошибка')
    ));
    echo html_writer::start_tag('tbody');

    $labels = [
        'contact_read' => '1. crm.contact.list — основной CRM REST',
        'contact_userfields_standard' => '2. crm.contact.userfield.list — standard',
        'contact_userfields_json' => '3. crm.contact.userfield.list — JSON/.json',
        'crm_field_engine' => '4. CRM Field Engine → Contact',
    ];

    foreach (($diagnostic['tests'] ?? []) as $key => $test) {
        $d = is_array($test['diagnostic'] ?? null) ? $test['diagnostic'] : [];
        echo html_writer::tag('tr',
            html_writer::tag('td', s((string)($labels[$key] ?? $key))) .
            html_writer::tag('td', !empty($test['ok']) ? '✅ OK' : '❌ ERROR') .
            html_writer::tag('td', s((string)($d['http_code'] ?? '—'))) .
            html_writer::tag('td', s((string)($d['transport'] ?? '—'))) .
            html_writer::tag('td', s((string)($test['error'] ?? '')))
        );
    }

    echo html_writer::end_tag('tbody');
    echo html_writer::end_tag('table');

    echo html_writer::start_tag('details', ['class' => 'mt-3']);
    echo html_writer::tag('summary', 'Технические детали JSON');
    echo html_writer::tag(
        'pre',
        s(json_encode(
            $diagnostic,
            JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
        )),
        ['class' => 'mt-3']
    );
    echo html_writer::end_tag('details');

    echo html_writer::end_tag('div');
    echo html_writer::end_tag('div');
}

echo $OUTPUT->footer();
