<?php
require_once(__DIR__ . '/../../config.php');

require_login();
require_capability('moodle/site:config', context_system::instance());

$PAGE->set_url(new moodle_url('/local/bitrixsync/deal_contact_debug.php'));
$PAGE->set_context(context_system::instance());
$PAGE->set_title('KBA Connect — Deal Contact Diagnostics');
$PAGE->set_heading('KBA Connect — Deal Contact Diagnostics');

$dealid=optional_param('dealid',0,PARAM_INT);
$result=[];

if ($dealid>0) {
    require_sesskey();
    $result=(new \local_bitrixsync\services\deal_contact_discovery_diagnostic_service())->run($dealid);
}

echo $OUTPUT->header();
echo $OUTPUT->heading('Диагностика контактов сделки');

$multilastexecution = [];
$multilastraw = (string)get_config(
    'local_bitrixsync',
    'multi_contact_deal_last'
);
if ($multilastraw !== '') {
    $decodedmultilast = json_decode($multilastraw, true);
    if (is_array($decodedmultilast)) {
        $multilastexecution = $decodedmultilast;
    }
}

if (!empty($multilastexecution)) {
    echo html_writer::tag('h3', 'Последний автоматический Multi-Contact Deal Run', ['class' => 'mt-3']);

    $mtable = new html_table();
    $mtable->data = [
        ['Время', !empty($multilastexecution['time'])
            ? userdate((int)$multilastexecution['time']) : '—'],
        ['Deal ID', (int)($multilastexecution['dealid'] ?? 0)],
        ['Contacts', (int)($multilastexecution['contacts'] ?? 0)],
        ['Enrolled', (int)($multilastexecution['enrolled'] ?? 0)],
        ['Already', (int)($multilastexecution['already'] ?? 0)],
        ['Failed', (int)($multilastexecution['failed'] ?? 0)],
    ];
    echo html_writer::table($mtable);

    $ctable = new html_table();
    $ctable->head = [
        'Contact ID',
        'Email',
        'Moodle User ID',
        'Decision',
        'Enrolled',
        'Already',
        'Failed',
        'Access',
        'Corporate',
        'CRM',
        'Error',
    ];

    foreach ((array)($multilastexecution['contact_results'] ?? []) as $row) {
        $ctable->data[] = [
            (int)($row['bitrixcontactid'] ?? 0),
            s((string)($row['email'] ?? '')),
            (int)($row['userid'] ?? 0),
            s((string)($row['decision'] ?? '')),
            (int)($row['enrolled'] ?? 0),
            (int)($row['already'] ?? 0),
            (int)($row['failed'] ?? 0),
            s((string)($row['access'] ?? '')),
            s((string)($row['corporate'] ?? '')),
            s((string)($row['crm'] ?? '')),
            s((string)($row['error'] ?? '')),
        ];
    }
    echo html_writer::table($ctable);
}


echo html_writer::start_tag('form',['method'=>'get','class'=>'form-inline mb-3']);
echo html_writer::empty_tag('input',['type'=>'hidden','name'=>'sesskey','value'=>sesskey()]);
echo html_writer::label('Deal ID','dealid',false,['class'=>'mr-2']);
echo html_writer::empty_tag('input',['type'=>'number','name'=>'dealid','id'=>'dealid','value'=>$dealid ?: '','min'=>1,'class'=>'form-control mr-2']);
echo html_writer::empty_tag('input',['type'=>'submit','value'=>'Проверить контакты сделки','class'=>'btn btn-warning']);
echo html_writer::end_tag('form');

if (!empty($result)) {
    $summary=new html_table();
    $summary->data=[
        ['Deal ID',(int)($result['dealid'] ?? 0)],
        ['Deal loaded',!empty($result['deal_loaded'])?'✅ Да':'❌ Нет'],
        ['Stage',s((string)($result['deal']['stage_id'] ?? ''))],
        ['Company ID',(int)($result['deal']['company_id'] ?? 0)],
        ['Primary Contact ID',(int)($result['deal']['contact_id'] ?? 0)],
        ['Contacts discovered',implode(', ',(array)($result['contact_ids'] ?? [])) ?: '—'],
        ['Итог',s((string)($result['summary'] ?? ''))],
        ['Ошибка',s((string)($result['error'] ?? ''))],
    ];
    echo html_writer::table($summary);

    $table=new html_table();
    $table->head=['Contact ID','Загружен','ФИО','Email','Company ID','Moodle status','Moodle User ID','Would create','Created','Ошибка'];

    foreach ((array)($result['contacts'] ?? []) as $contact) {
        $moodle=is_array($contact['moodle'] ?? null)?$contact['moodle']:[];
        $table->data[]=[
            (int)($contact['contactid'] ?? 0),
            !empty($contact['loaded'])?'✅':'❌',
            s((string)($contact['name'] ?? '')),
            s((string)($contact['email'] ?? '')),
            (int)($contact['companyid'] ?? 0),
            s((string)($moodle['status'] ?? '')),
            (int)($moodle['userid'] ?? 0),
            !empty($moodle['wouldcreate'])?'Да':'Нет',
            !empty($moodle['created'])?'Да':'Нет',
            s((string)($moodle['error'] ?? $contact['load_error'] ?? '')),
        ];
    }
    echo html_writer::table($table);

    echo html_writer::start_tag('details',['class'=>'mb-4']);
    echo html_writer::tag('summary','Полный Deal Contact Debug JSON');
    echo html_writer::tag('pre',s(json_encode($result,JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES)));
    echo html_writer::end_tag('details');
}

echo $OUTPUT->footer();
