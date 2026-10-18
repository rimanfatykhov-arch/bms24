<?php
require(__DIR__ . '/../../config.php');

require_login();
require_capability('local/bitrixsync:manage', context_system::instance());

global $DB;

$PAGE->set_url(new moodle_url('/local/bitrixsync/course_mapping.php'));
$PAGE->set_context(context_system::instance());
$PAGE->set_title(get_string('course_mapping_title', 'local_bitrixsync'));

echo $OUTPUT->header();
echo \local_bitrixsync\ui\admin_shell::render('courses');

echo html_writer::tag(
    'h2',
    get_string('course_mapping_title', 'local_bitrixsync')
);
echo html_writer::tag(
    'p',
    'Рабочая карта: товар Bitrix24 → курс Moodle.',
    ['class' => 'lead']
);

$table = new xmldb_table('local_bitrixsync_coursemap');

if (!$DB->get_manager()->table_exists($table)) {
    echo $OUTPUT->notification(
        'Таблица coursemap не найдена. Выполните обновление базы KBA Connect.',
        'error'
    );
    echo $OUTPUT->footer();
    exit;
}

$records = $DB->get_records(
    'local_bitrixsync_coursemap',
    null,
    'productid ASC',
    '*',
    0,
    500
);

echo html_writer::start_tag('table', [
    'class' => 'generaltable',
]);
echo html_writer::tag(
    'tr',
    html_writer::tag('th', 'Bitrix Product ID') .
    html_writer::tag('th', 'Moodle Course ID') .
    html_writer::tag('th', 'Курс Moodle') .
    html_writer::tag('th', 'Статус')
);

foreach ($records as $record) {
    $course = $DB->get_record(
        'course',
        ['id' => (int)$record->courseid],
        'id,fullname,shortname',
        IGNORE_MISSING
    );

    $name = $course
        ? format_string($course->fullname)
        : 'Курс не найден';

    $status = trim((string)($record->laststatus ?? ''));
    if ($status === '') {
        $status = 'active';
    }

    echo html_writer::tag(
        'tr',
        html_writer::tag(
            'td',
            s((string)(int)$record->productid)
        ) .
        html_writer::tag(
            'td',
            s((string)(int)$record->courseid)
        ) .
        html_writer::tag('td', $name) .
        html_writer::tag(
            'td',
            \local_bitrixsync\ui\admin_shell::status_badge(
                $status === 'active',
                'ACTIVE',
                strtoupper($status)
            )
        )
    );
}

echo html_writer::end_tag('table');

echo html_writer::link(
    new moodle_url('/local/bitrixsync/catalog.php'),
    'Открыть расширенную синхронизацию каталога',
    ['class' => 'btn btn-outline-secondary mt-3']
);

echo $OUTPUT->footer();
