<?php
require_once(__DIR__ . '/../../config.php');
require_login();

$context = context_system::instance();
require_capability('moodle/site:config', $context);

$PAGE->set_url(new moodle_url('/local/bitrixsync/corporate_roles.php'));
$PAGE->set_context($context);
$PAGE->set_title('KBA Corporate Roles');
$PAGE->set_heading('KBA Corporate Roles');

echo $OUTPUT->header();

$roles = (new \local_bitrixsync\services\corporate_role_manager_service())->get_roles();

echo $OUTPUT->heading('KBA Corporate Roles');

echo html_writer::tag('p',
    'Role Registry v5.0.36.1. Управление ролями пока работает в режиме просмотра. ' .
    'Bitrix24 provisioning будет подключён в следующем релизе.'
);

$table = new html_table();
$table->head = ['Код', 'Название', 'Статус', 'Sort'];
$table->data = [];

foreach ($roles as $role) {
    $table->data[] = [
        s($role->rolecode),
        s($role->name),
        !empty($role->active) ? 'Активна' : 'Архив',
        (int)$role->sort
    ];
}

echo html_writer::table($table);

echo $OUTPUT->footer();
