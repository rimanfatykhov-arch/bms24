<?php
require(__DIR__ . '/../../config.php');

require_login();
require_capability('local/bitrixsync:manage', context_system::instance());

$PAGE->set_url(new moodle_url('/local/bitrixsync/funnels.php'));
$PAGE->set_context(context_system::instance());
$PAGE->set_title(get_string('funnels_title', 'local_bitrixsync'));

$service = new \local_bitrixsync\services\bitrix_funnel_service();
$action = optional_param('action', '', PARAM_ALPHA);
$message = null;
$error = null;

try {
    if ($action === 'refresh') {
        require_sesskey();
        $snapshot = $service->scan();
        $message = 'Воронки и стадии загружены из Bitrix24.';
    } else if ($action === 'save') {
        require_sesskey();
        $stages = optional_param_array(
            'stages',
            [],
            PARAM_RAW_TRIMMED
        );
        $service->save_selected_stage_ids($stages);
        $message = 'Стадии автоматического запуска сохранены.';
        $snapshot = $service->get_snapshot();
    } else {
        $snapshot = $service->get_snapshot();
    }
} catch (\Throwable $e) {
    $snapshot = $service->get_snapshot();
    $error = $e->getMessage();
}

$selected = $service->selected_stage_ids();
$time = $service->snapshot_time();

echo $OUTPUT->header();
echo \local_bitrixsync\ui\admin_shell::render('automation');

echo html_writer::tag(
    'h2',
    get_string('funnels_title', 'local_bitrixsync')
);
echo html_writer::tag(
    'p',
    'Стадии не вводятся вручную. KBA Connect загружает реальные воронки и стадии прямо из Bitrix24.',
    ['class' => 'lead']
);

if ($message) {
    echo $OUTPUT->notification($message, 'success');
}
if ($error) {
    echo $OUTPUT->notification($error, 'error');
}

if ($time > 0) {
    echo html_writer::tag(
        'p',
        'Последнее обновление из Bitrix24: ' . userdate($time),
        ['class' => 'text-muted']
    );
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
    'value' => 'Загрузить / обновить воронки из Bitrix24',
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

if ($snapshot) {
    echo html_writer::empty_tag('input', [
        'type' => 'submit',
        'value' => 'Сохранить выбранные стадии',
        'class' => 'btn btn-primary',
    ]);
}

echo html_writer::end_tag('form');
echo $OUTPUT->footer();
