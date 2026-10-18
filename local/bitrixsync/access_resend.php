<?php
require_once(__DIR__ . '/../../config.php');

require_login();
require_capability('moodle/site:config', context_system::instance());

$PAGE->set_url(new moodle_url('/local/bitrixsync/access_resend.php'));
$PAGE->set_context(context_system::instance());
$PAGE->set_title('KBA Connect — Повторная выдача доступа');
$PAGE->set_heading('KBA Connect — Повторная выдача доступа');

$userid = optional_param('userid', 0, PARAM_INT);
$contactid = optional_param('contactid', 0, PARAM_INT);
$dealid = optional_param('dealid', 0, PARAM_INT);
$action = optional_param('action', '', PARAM_ALPHA);

$result = [];

if ($action === 'regenerate') {
    require_sesskey();

    $result = (
        new \local_bitrixsync\services\secure_access_resend_service()
    )->regenerate($userid, $contactid, $dealid);
}

echo $OUTPUT->header();
echo $OUTPUT->heading('Повторная выдача временного доступа');

echo $OUTPUT->notification(
    'Новый временный пароль показывается только на этой странице. '
    . 'В Timeline и диагностические логи пароль не записывается.',
    'info'
);

echo html_writer::start_tag('form', ['method' => 'post', 'class' => 'mb-4']);
echo html_writer::empty_tag('input', ['type'=>'hidden','name'=>'sesskey','value'=>sesskey()]);
echo html_writer::empty_tag('input', ['type'=>'hidden','name'=>'action','value'=>'regenerate']);

$fields = [
    ['Moodle User ID', 'userid', $userid],
    ['Bitrix Contact ID (необязательно)', 'contactid', $contactid],
    ['Bitrix Deal ID (необязательно)', 'dealid', $dealid],
];

foreach ($fields as $field) {
    [$label, $name, $value] = $field;
    echo html_writer::start_div('form-group');
    echo html_writer::label($label, $name);
    echo html_writer::empty_tag('input', [
        'type'=>'number','name'=>$name,'id'=>$name,'value'=>$value ?: '',
        'min'=>0,'class'=>'form-control'
    ]);
    echo html_writer::end_div();
}

echo html_writer::empty_tag('input', [
    'type'=>'submit',
    'value'=>'Сформировать новый временный доступ',
    'class'=>'btn btn-warning',
]);
echo html_writer::end_tag('form');

if (!empty($result)) {
    echo $OUTPUT->notification(
        'Новый временный пароль сформирован. Передайте его ученику и закройте страницу.',
        'success'
    );

    $table = new html_table();
    $table->data = [
        ['Ученик', s((string)$result['fullname'])],
        ['Moodle User ID', (int)$result['userid']],
        ['Логин', s((string)$result['email'])],
        ['Адрес входа', html_writer::link($result['loginurl'], $result['loginurl'])],
        ['Временный пароль', html_writer::tag(
            'code',
            s((string)$result['password']),
            ['style'=>'font-size:1.2rem;']
        )],
        ['Timeline', s((string)$result['timeline_status'])],
        ['Timeline error', s((string)($result['timeline_error'] ?? ''))],
    ];
    echo html_writer::table($table);

    echo html_writer::tag('h3','Сообщение для мессенджера',['class'=>'mt-4']);
    echo html_writer::tag(
        'textarea',
        s((string)$result['messenger_text']),
        [
            'class'=>'form-control',
            'rows'=>16,
            'id'=>'kba-messenger-copy',
            'readonly'=>'readonly',
        ]
    );

    echo html_writer::tag(
        'button',
        'Скопировать сообщение',
        [
            'type'=>'button',
            'class'=>'btn btn-primary mt-2',
            'onclick'=>"navigator.clipboard.writeText(document.getElementById('kba-messenger-copy').value);",
        ]
    );
}

echo $OUTPUT->footer();
