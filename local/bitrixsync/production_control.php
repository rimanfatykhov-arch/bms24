<?php
require(__DIR__ . '/../../config.php');

require_login();
require_capability('local/bitrixsync:manage', context_system::instance());

$dealid = optional_param('dealid', 3761, PARAM_INT);
$action = optional_param('action', '', PARAM_ALPHAEXT);
$result = null;
$message = null;
$messagetype = 'info';

if ($action !== '') {
    require_sesskey();
    try {
        if ($action === 'enableautocreate') {
            set_config('match_users_by_email', 1, 'local_bitrixsync');
            set_config('auto_create_users', 1, 'local_bitrixsync');
            $message = 'Автоматическое создание Moodle User включено.';
            $messagetype = 'success';
        } else {
            $service = new \local_bitrixsync\services\multi_contact_deal_service();
        if ($action === 'dryrun') {
            $result = $service->process($dealid, true);
            $message = 'Диагностика завершена. Изменения в Moodle и Bitrix24 не выполнялись.';
            $messagetype = 'success';
        } else if ($action === 'realrun') {
            $confirm = optional_param('confirm', 0, PARAM_BOOL);
            if (!$confirm) {
                throw new moodle_exception('production_simple_confirmation_required', 'local_bitrixsync');
            }
            $result = $service->process($dealid, false);
            $message = 'REAL синхронизация завершена.';
            $messagetype = empty($result['failed']) ? 'success' : 'warning';
        }
        }
    } catch (Throwable $e) {
        $message = $e->getMessage();
        $messagetype = 'error';
    }
}

$PAGE->set_url(new moodle_url('/local/bitrixsync/production_control.php', ['dealid' => $dealid]));
$PAGE->set_context(context_system::instance());
$PAGE->set_title('KBA Connect — Production Control Center');
$PAGE->set_heading('KBA Connect');

echo $OUTPUT->header();
echo \local_bitrixsync\ui\admin_shell::render('production');

echo html_writer::tag('h2', 'Production Control Center');
echo html_writer::tag(
    'p',
    'Диагностика и ручная синхронизация одной сделки Bitrix24. Сначала используйте Dry Run.',
    ['class' => 'lead']
);

echo html_writer::tag(
    'div',
    '<strong>Версия:</strong> KBA Connect v5.0.31.4 RC1 &nbsp; ' .
    '<strong>Moodle:</strong> ' . s($CFG->release) . ' &nbsp; ' .
    '<strong>PHP:</strong> ' . s(PHP_VERSION),
    ['class' => 'alert alert-secondary']
);

if ($message !== null) {
    echo $OUTPUT->notification($message, $messagetype);
}

$autocreate = (bool)get_config('local_bitrixsync', 'auto_create_users');
$autostatus = $autocreate ? 'ВКЛЮЧЕНО' : 'ВЫКЛЮЧЕНО';
$autoclass = $autocreate ? 'alert alert-success' : 'alert alert-warning';
echo html_writer::start_tag('div', ['class' => $autoclass]);
echo html_writer::tag('strong', 'Автоматическое создание Moodle User: ' . $autostatus);
echo html_writer::tag('div', 'Используется только когда контакт Bitrix24 имеет валидный email, а существующий Moodle User не найден по связи или email.');
if (!$autocreate) {
    echo html_writer::start_tag('form', ['method' => 'post', 'class' => 'mt-2']);
    echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'sesskey', 'value' => sesskey()]);
    echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'dealid', 'value' => $dealid]);
    echo html_writer::tag('button', 'Включить автоматическое создание', [
        'type' => 'submit', 'name' => 'action', 'value' => 'enableautocreate', 'class' => 'btn btn-success'
    ]);
    echo html_writer::end_tag('form');
}
echo html_writer::end_tag('div');

echo html_writer::start_tag('form', ['method' => 'post', 'class' => 'card card-body mb-4']);
echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'sesskey', 'value' => sesskey()]);
echo html_writer::tag('label', 'Deal ID', ['for' => 'dealid', 'class' => 'font-weight-bold']);
echo html_writer::empty_tag('input', [
    'type' => 'number', 'name' => 'dealid', 'id' => 'dealid', 'value' => $dealid,
    'min' => 1, 'class' => 'form-control mb-3', 'style' => 'max-width:240px'
]);

echo html_writer::start_tag('div', ['class' => 'mb-3']);
echo html_writer::tag('button', 'Диагностика / Dry Run', [
    'type' => 'submit', 'name' => 'action', 'value' => 'dryrun', 'class' => 'btn btn-primary mr-2'
]);
echo html_writer::end_tag('div');

echo html_writer::start_tag('div', ['class' => 'border-top pt-3']);
echo html_writer::checkbox('confirm', 1, false, 'Подтверждаю REAL синхронизацию этой сделки', ['class' => 'mr-2']);
echo html_writer::tag('button', 'REAL синхронизация', [
    'type' => 'submit', 'name' => 'action', 'value' => 'realrun', 'class' => 'btn btn-danger ml-2'
]);
echo html_writer::end_tag('div');
echo html_writer::end_tag('form');

if (is_array($result)) {
    echo html_writer::tag('h3', 'Результат');
    $summary = [
        'Контактов' => (int)($result['contacts'] ?? 0),
        'Курсов в сделке' => (int)($result['productrows'] ?? 0),
        'Новых зачислений' => (int)($result['enrolled'] ?? 0),
        'Уже было' => (int)($result['already'] ?? 0),
        'Ошибок' => (int)($result['failed'] ?? 0),
    ];
    echo html_writer::start_tag('table', ['class' => 'generaltable mb-4']);
    foreach ($summary as $label => $value) {
        echo html_writer::tag('tr', html_writer::tag('th', s($label)) . html_writer::tag('td', (string)$value));
    }
    echo html_writer::end_tag('table');

    echo html_writer::tag('h3', 'Контакты сделки');
    echo html_writer::start_tag('table', ['class' => 'generaltable']);
    echo html_writer::tag('tr',
        html_writer::tag('th', 'Bitrix Contact ID') .
        html_writer::tag('th', 'Контакт') .
        html_writer::tag('th', 'Email') .
        html_writer::tag('th', 'Moodle User ID') .
        html_writer::tag('th', 'Решение') .
        html_writer::tag('th', 'Новые') .
        html_writer::tag('th', 'Уже') .
        html_writer::tag('th', 'Corporate') .
        html_writer::tag('th', 'Доступ') .
        html_writer::tag('th', 'CRM') .
        html_writer::tag('th', 'Ошибка')
    );
    foreach (($result['contact_results'] ?? []) as $row) {
        echo html_writer::tag('tr',
            html_writer::tag('td', (string)(int)($row['bitrixcontactid'] ?? 0)) .
            html_writer::tag('td', s((string)($row['name'] ?? ''))) .
            html_writer::tag('td', s((string)($row['email'] ?? ''))) .
            html_writer::tag('td', (string)(int)($row['userid'] ?? 0)) .
            html_writer::tag('td', s((string)($row['decision'] ?? ''))) .
            html_writer::tag('td', (string)(int)($row['enrolled'] ?? 0)) .
            html_writer::tag('td', (string)(int)($row['already'] ?? 0)) .
            html_writer::tag('td', s((string)($row['corporate'] ?? ''))) .
            html_writer::tag('td', s((string)($row['access'] ?? ''))) .
            html_writer::tag('td', s((string)($row['crm'] ?? ''))) .
            html_writer::tag('td', s((string)($row['error'] ?? '')))
        );
    }
    echo html_writer::end_tag('table');
}

echo $OUTPUT->footer();
