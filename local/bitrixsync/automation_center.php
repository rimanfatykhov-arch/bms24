<?php
require(__DIR__ . '/../../config.php');

require_login();
require_capability('local/bitrixsync:manage', context_system::instance());

$PAGE->set_url(new moodle_url('/local/bitrixsync/automation_center.php'));
$PAGE->set_context(context_system::instance());
$PAGE->set_title(get_string('automation_title', 'local_bitrixsync'));

$service = new \local_bitrixsync\services\bitrix_funnel_service();
$action = optional_param('action', '', PARAM_ALPHA);
$message = null;
$error = null;

try {
    if ($action === 'refresh') {
        require_sesskey();
        $snapshot = $service->scan();
        $message = 'Воронки и стадии обновлены из Bitrix24.';
    } else if ($action === 'save') {
        require_sesskey();

        $selected = optional_param_array(
            'stages',
            [],
            PARAM_RAW_TRIMMED
        );
        $service->save_selected_stage_ids($selected);

        $queue = optional_param(
            'queue_enabled',
            0,
            PARAM_BOOL
        );
        set_config(
            'queue_enabled',
            $queue ? 1 : 0,
            'local_bitrixsync'
        );

        $snapshot = $service->get_snapshot();
        $message = 'Автоматизация сохранена.';
    } else {
        $snapshot = $service->get_snapshot();
    }
} catch (\Throwable $e) {
    $snapshot = $service->get_snapshot();
    $error = $e->getMessage();
}

$selected = $service->selected_stage_ids();
$summary = $service->selection_summary();
$queue = (bool)get_config(
    'local_bitrixsync',
    'queue_enabled'
);
$inbound = (bool)get_config(
    'local_bitrixsync',
    'v5_inbound_enabled'
);
$engine = (string)get_config(
    'local_bitrixsync',
    'engine_mode'
);

echo $OUTPUT->header();
echo \local_bitrixsync\ui\admin_shell::render('automation');

echo html_writer::tag(
    'h2',
    get_string('automation_title', 'local_bitrixsync')
);
echo html_writer::tag(
    'p',
    'Выберите воронку и реальные стадии Bitrix24, на которых KBA Connect должен запускать обработку.',
    ['class' => 'lead']
);

if ($message) {
    echo $OUTPUT->notification($message, 'success');
}
if ($error) {
    echo $OUTPUT->notification($error, 'error');
}

echo html_writer::start_tag('div', ['class' => 'row mb-4']);
foreach ([
    'Engine' => strtoupper($engine ?: 'legacy'),
    'Queue' => $queue ? 'ON' : 'OFF',
    'Inbound' => $inbound ? 'ON' : 'OFF',
    'Выбрано стадий' => (string)count($selected),
] as $label => $value) {
    echo html_writer::start_tag('div', [
        'class' => 'col-md-3',
    ]);
    echo html_writer::tag('strong', $label);
    echo html_writer::tag('p', s($value));
    echo html_writer::end_tag('div');
}
echo html_writer::end_tag('div');

if ($summary) {
    echo html_writer::tag('h3', 'Текущие правила запуска');
    foreach ($summary as $funnel) {
        echo html_writer::tag(
            'p',
            html_writer::tag('strong', s($funnel['name'])) .
            ': ' .
            implode(', ', array_map(static function(array $stage): string {
                return s($stage['name']) . ' (' . s($stage['id']) . ')';
            }, $funnel['stages']))
        );
    }
}

echo html_writer::start_tag('form', [
    'method' => 'post',
    'class' => 'mb-4',
]);
echo html_writer::empty_tag('input', [
    'type' => 'hidden',
    'name' => 'sesskey',
    'value' => sesskey(),
]);
echo html_writer::empty_tag('input', [
    'type' => 'hidden',
    'name' => 'action',
    'value' => 'refresh',
]);
echo html_writer::empty_tag('input', [
    'type' => 'submit',
    'value' => 'Обновить воронки и стадии из Bitrix24',
    'class' => 'btn btn-secondary',
]);
echo html_writer::end_tag('form');

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

echo \local_bitrixsync\ui\stage_selector::render(
    $snapshot,
    $selected
);

echo html_writer::start_tag('div', ['class' => 'form-group']);
echo html_writer::checkbox(
    'queue_enabled',
    1,
    $queue,
    ' Включить очередь синхронизации'
);
echo html_writer::end_tag('div');

if ($snapshot) {
    echo html_writer::empty_tag('input', [
        'type' => 'submit',
        'value' => 'Сохранить автоматизацию',
        'class' => 'btn btn-primary',
    ]);
}
echo html_writer::end_tag('form');

echo html_writer::tag(
    'p',
    'Automatic inbound включается только через Мастер Production после контрольных проверок.',
    ['class' => 'text-muted mt-3']
);

echo html_writer::link(
    new moodle_url('/local/bitrixsync/setup_wizard.php'),
    'Открыть Мастер Production',
    ['class' => 'btn btn-outline-danger']
);

echo $OUTPUT->footer();
