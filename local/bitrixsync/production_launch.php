<?php
require(__DIR__ . '/../../config.php');

require_login();
require_capability('local/bitrixsync:manage', context_system::instance());

$service = new \local_bitrixsync\services\production_launch_service();

$defaultdealid = $service->controlled_deal_id();
$dealid = optional_param('dealid', $defaultdealid, PARAM_INT);
$userid = optional_param('userid', 0, PARAM_INT);
$action = optional_param('action', 'preflight', PARAM_ALPHAEXT);

$PAGE->set_url(new moodle_url('/local/bitrixsync/production_launch.php', [
    'dealid' => $dealid,
]));
$PAGE->set_context(context_system::instance());
$PAGE->set_title('KBA Connect — Controlled Production Launch');
$PAGE->set_heading('KBA Connect — Controlled Production Launch');

$result = null;
$error = null;

try {
    if ($action === 'dryrun') {
        require_sesskey();
        $result = $service->dry_run($dealid, $userid > 0 ? $userid : null);
    } else if ($action === 'execute') {
        require_sesskey();
        $confirmation = required_param('confirmation', PARAM_RAW_TRIMMED);
        $result = $service->execute(
            $dealid,
            $confirmation,
            $userid > 0 ? $userid : null
        );
    } else {
        $result = $service->preflight($dealid, $userid > 0 ? $userid : null);
    }
} catch (\Throwable $e) {
    $error = $e->getMessage();
}

echo $OUTPUT->header();
echo html_writer::tag('h2', 'Controlled Production Launch');

echo html_writer::start_tag('div', ['class' => 'alert alert-warning']);
echo 'Реальный запуск выполняет существующий v5 production engine: '
    . 'Corporate allocation → Moodle enrolment → CRM writeback. '
    . 'Автоматический webhook остаётся отдельным этапом и должен быть '
    . 'включён только после успешного контролируемого запуска.';
echo html_writer::end_tag('div');

echo html_writer::start_tag('form', ['method' => 'get']);
echo html_writer::label('Deal ID', 'dealid');
echo html_writer::empty_tag('input', [
    'type' => 'number',
    'name' => 'dealid',
    'id' => 'dealid',
    'value' => $dealid,
    'class' => 'form-control mb-2',
]);
echo html_writer::label('Moodle User ID override (optional)', 'userid');
echo html_writer::empty_tag('input', [
    'type' => 'number',
    'name' => 'userid',
    'id' => 'userid',
    'value' => $userid ?: '',
    'class' => 'form-control mb-2',
]);
echo html_writer::empty_tag('input', [
    'type' => 'submit',
    'value' => 'Обновить Preflight',
    'class' => 'btn btn-secondary',
]);
echo html_writer::end_tag('form');

if ($error !== null) {
    echo $OUTPUT->notification(s($error), 'error');
}

if (is_array($result)) {
    if (isset($result['checks'])) {
        echo html_writer::tag('h3', 'Preflight');
        echo html_writer::start_tag('table', ['class' => 'generaltable']);
        echo html_writer::tag('tr',
            html_writer::tag('th', 'Проверка') .
            html_writer::tag('th', 'Статус') .
            html_writer::tag('th', 'Детали')
        );

        foreach ($result['checks'] as $name => $check) {
            $status = !empty($check['ok']) ? 'OK' : 'BLOCK';
            if (!empty($check['warning_only_for_manual_test']) && empty($check['ok'])) {
                $status = 'WARN';
            }
            echo html_writer::tag('tr',
                html_writer::tag('td', s($name)) .
                html_writer::tag('td', s($status)) .
                html_writer::tag('td', s((string)$check['detail']))
            );
        }
        echo html_writer::end_tag('table');

        $ready = !empty($result['ready_for_controlled_execute']);
        echo html_writer::tag(
            'p',
            html_writer::tag('strong', 'Controlled execution: ') .
            ($ready ? 'READY' : 'BLOCKED')
        );

        $plan = $result['dry_plan'] ?? [];
        if ($plan) {
            echo html_writer::tag('h3', 'Dry Plan');
            echo html_writer::tag('pre', s(json_encode(
                $plan,
                JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
            )));
        }

        echo html_writer::start_tag('form', ['method' => 'post', 'class' => 'mt-3']);
        echo html_writer::empty_tag('input', [
            'type' => 'hidden',
            'name' => 'sesskey',
            'value' => sesskey(),
        ]);
        echo html_writer::empty_tag('input', [
            'type' => 'hidden',
            'name' => 'dealid',
            'value' => $dealid,
        ]);
        echo html_writer::empty_tag('input', [
            'type' => 'hidden',
            'name' => 'userid',
            'value' => $userid,
        ]);
        echo html_writer::empty_tag('input', [
            'type' => 'hidden',
            'name' => 'action',
            'value' => 'dryrun',
        ]);
        echo html_writer::empty_tag('input', [
            'type' => 'submit',
            'value' => 'Записать Dry Run в историю',
            'class' => 'btn btn-secondary',
        ]);
        echo html_writer::end_tag('form');

        if ($ready) {
            echo html_writer::start_tag('form', ['method' => 'post', 'class' => 'mt-4']);
            echo html_writer::empty_tag('input', [
                'type' => 'hidden',
                'name' => 'sesskey',
                'value' => sesskey(),
            ]);
            echo html_writer::empty_tag('input', [
                'type' => 'hidden',
                'name' => 'dealid',
                'value' => $dealid,
            ]);
            echo html_writer::empty_tag('input', [
                'type' => 'hidden',
                'name' => 'userid',
                'value' => $userid,
            ]);
            echo html_writer::empty_tag('input', [
                'type' => 'hidden',
                'name' => 'action',
                'value' => 'execute',
            ]);
            echo html_writer::label(
                'Для РЕАЛЬНОГО запуска введите EXECUTE_' . $dealid,
                'confirmation'
            );
            echo html_writer::empty_tag('input', [
                'type' => 'text',
                'name' => 'confirmation',
                'id' => 'confirmation',
                'class' => 'form-control mb-2',
                'autocomplete' => 'off',
            ]);
            echo html_writer::empty_tag('input', [
                'type' => 'submit',
                'value' => 'Выполнить REAL controlled run',
                'class' => 'btn btn-danger',
            ]);
            echo html_writer::end_tag('form');
        }
    } else {
        echo html_writer::tag('h3', 'Execution Result');
        echo html_writer::tag('pre', s(json_encode(
            $result,
            JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
        )));

        if (!empty($result['dealrunid'])) {
            echo html_writer::link(
                new moodle_url('/local/bitrixsync/deal_runs.php'),
                'Открыть реальную историю обработки сделок',
                ['class' => 'btn btn-primary mt-3']
            );
        }
    }
}

echo $OUTPUT->footer();
