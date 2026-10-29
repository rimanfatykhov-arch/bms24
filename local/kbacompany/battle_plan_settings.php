<?php
require_once(__DIR__ . '/../../config.php');

use local_kbacompany\local\battle_plan_template_service;
use local_kbacompany\local\company_service;
use local_kbacompany\local\task_service;

require_login();
$context = context_system::instance();
require_capability('local/kbacompany:viewowncompany', $context);
$uuid = required_param('uuid', PARAM_RAW_TRIMMED);
$kind = optional_param('kind', 'template', PARAM_ALPHA);
if (!in_array($kind, ['template', 'norm'], true)) { $kind = 'template'; }
$selected = optional_param('object', '', PARAM_RAW_TRIMMED);
$preset = optional_param('preset', '', PARAM_ALPHANUMEXT);
company_service::company_context($uuid, (int)$USER->id);
if (!task_service::can_manage($uuid, (int)$USER->id)) {
    throw new moodle_exception('battleplan_setup_permission', 'local_kbacompany');
}

$action = optional_param('action', '', PARAM_ALPHA);
$error = '';
if ($action !== '') {
    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') { throw new moodle_exception('battleplan_post', 'local_kbacompany'); }
    require_sesskey();
    try {
        $objectuuid = optional_param('object', '', PARAM_RAW_TRIMMED);
        $expected = optional_param('revision', 0, PARAM_INT);
        if ($action === 'save_template') {
            $input = [
                'title' => required_param('title', PARAM_TEXT),
                'positionuuid' => required_param('positionuuid', PARAM_RAW_TRIMMED),
            ];
            for ($i = 1; $i <= 25; $i++) {
                $input['row_stream_'.$i] = optional_param('row_stream_'.$i, '', PARAM_ALPHA);
                $input['row_title_'.$i] = optional_param('row_title_'.$i, '', PARAM_TEXT);
                $input['row_product_'.$i] = optional_param('row_product_'.$i, '', PARAM_RAW);
                $input['row_minutes_'.$i] = optional_param('row_minutes_'.$i, 0, PARAM_INT);
                $input['row_day_'.$i] = optional_param('row_day_'.$i, '', PARAM_TEXT);
                $input['row_priority_'.$i] = optional_param('row_priority_'.$i, 'normal', PARAM_ALPHA);
            }
            $selected = battle_plan_template_service::save_template($uuid, (int)$USER->id, $input, $objectuuid, $expected);
            $kind = 'template';
        } else if ($action === 'save_norm') {
            $input = [
                'title' => required_param('title', PARAM_TEXT),
                'positionuuid' => required_param('positionuuid', PARAM_RAW_TRIMMED),
                'danger_below' => optional_param('danger_below', '70', PARAM_RAW_TRIMMED),
                'emergency_below' => optional_param('emergency_below', '90', PARAM_RAW_TRIMMED),
                'normal_high' => optional_param('normal_high', '110', PARAM_RAW_TRIMMED),
                'affluence_high' => optional_param('affluence_high', '130', PARAM_RAW_TRIMMED),
                'danger_trend' => optional_param('danger_trend', '-20', PARAM_RAW_TRIMMED),
            ];
            for ($i = 1; $i <= 20; $i++) {
                $input['metric_'.$i] = optional_param('metric_'.$i, '', PARAM_RAW_TRIMMED);
                $input['quota_'.$i] = optional_param('quota_'.$i, '', PARAM_RAW_TRIMMED);
                $input['weight_'.$i] = optional_param('weight_'.$i, '1', PARAM_RAW_TRIMMED);
                $input['critical_'.$i] = optional_param('critical_'.$i, 0, PARAM_INT);
                $input['comparison_'.$i] = optional_param('comparison_'.$i, '', PARAM_ALPHA);
            }
            $selected = battle_plan_template_service::save_norm($uuid, (int)$USER->id, $input, $objectuuid, $expected);
            $kind = 'norm';
        } else if ($action === 'publish_template') {
            battle_plan_template_service::publish_template($uuid, (int)$USER->id, $objectuuid, $expected);
            $selected = $objectuuid; $kind = 'template';
        } else if ($action === 'archive_template') {
            battle_plan_template_service::archive_template($uuid, (int)$USER->id, $objectuuid, $expected);
            $selected = $objectuuid; $kind = 'template';
        } else if ($action === 'publish_norm') {
            battle_plan_template_service::publish_norm($uuid, (int)$USER->id, $objectuuid, $expected);
            $selected = $objectuuid; $kind = 'norm';
        } else if ($action === 'archive_norm') {
            battle_plan_template_service::archive_norm($uuid, (int)$USER->id, $objectuuid, $expected);
            $selected = $objectuuid; $kind = 'norm';
        }
        redirect(new moodle_url('/local/kbacompany/battle_plan_settings.php', ['uuid'=>$uuid,'kind'=>$kind,'object'=>$selected]),
            get_string('battleplan_setup_saved', 'local_kbacompany'));
    } catch (moodle_exception $e) {
        if ($e->module !== 'local_kbacompany') { throw $e; }
        $error = $e->getMessage();
    }
}

$data = battle_plan_template_service::context($uuid, (int)$USER->id, $kind, $selected);
$PAGE->set_context($context);
$PAGE->set_url(new moodle_url('/local/kbacompany/battle_plan_settings.php', ['uuid'=>$uuid,'kind'=>$kind,'object'=>$selected]));
$PAGE->set_title(get_string('battleplan_setup_title', 'local_kbacompany'));
$PAGE->set_heading(get_string('pluginname', 'local_kbacompany'));

$bmsshell = \local_kbacompany\local\bms_page_shell_service::begin($uuid, (int)$USER->id, 'battleplan', get_string('battleplan_setup_title','local_kbacompany'));
echo $OUTPUT->heading(get_string('battleplan_setup_title','local_kbacompany'));
echo html_writer::tag('p', get_string('battleplan_setup_intro','local_kbacompany'), ['class'=>'lead']);
if ($error !== '') { echo $OUTPUT->notification(s($error), 'error'); }
echo html_writer::link(new moodle_url('/local/kbacompany/battle_plan.php',['uuid'=>$uuid]), get_string('battleplan_title','local_kbacompany'), ['class'=>'btn btn-outline-primary mr-2']);
echo html_writer::link(new moodle_url('/local/kbacompany/battle_plan_settings.php',['uuid'=>$uuid,'kind'=>'template']), get_string('battleplan_template_tab','local_kbacompany'), ['class'=>$kind==='template'?'btn btn-secondary mr-1':'btn btn-outline-secondary mr-1']);
echo html_writer::link(new moodle_url('/local/kbacompany/battle_plan_settings.php',['uuid'=>$uuid,'kind'=>'norm']), get_string('battleplan_norm_tab','local_kbacompany'), ['class'=>$kind==='norm'?'btn btn-secondary':'btn btn-outline-secondary']);
echo html_writer::start_tag('div',['class'=>'my-3']);
if ($kind === 'template') {
    echo html_writer::link(new moodle_url('/local/kbacompany/battle_plan_settings.php',['uuid'=>$uuid,'kind'=>'template','preset'=>'rop']),
        get_string('battleplan_preset_rop_template','local_kbacompany'), ['class'=>'btn btn-outline-success']);
} else {
    echo html_writer::link(new moodle_url('/local/kbacompany/battle_plan_settings.php',['uuid'=>$uuid,'kind'=>'norm','preset'=>'rop']),
        get_string('battleplan_preset_rop_norm','local_kbacompany'), ['class'=>'btn btn-outline-success']);
}
echo html_writer::end_tag('div');

if ($data['objects']) {
    $table = new html_table();
    $table->head = [get_string('battleplan_setup_name','local_kbacompany'), get_string('battleplan_position','local_kbacompany'), get_string('battleplan_setup_revision','local_kbacompany'), get_string('battleplan_status','local_kbacompany')];
    foreach ($data['objects'] as $row) {
        $table->data[] = [
            html_writer::link(new moodle_url('/local/kbacompany/battle_plan_settings.php',['uuid'=>$uuid,'kind'=>$kind,'object'=>$row->uuid]), s($row->title)),
            s($data['positions'][$row->positionuuid] ?? $row->positionuuid),
            (int)$row->revision,
            get_string('battleplan_setup_status_'.$row->status,'local_kbacompany'),
        ];
    }
    echo html_writer::table($table);
}

echo $OUTPUT->heading($data['selected'] ? get_string('battleplan_setup_edit','local_kbacompany') : get_string('battleplan_setup_create','local_kbacompany'), 3);
$current = $data['selected'];
$payload = $current ? (array)$current->payload_data : [];
$presettitle = '';
if (!$current && $preset === 'rop') {
    if ($kind === 'template') {
        $presettitle = get_string('battleplan_preset_rop_title','local_kbacompany');
        $payload = ['rows' => [
            ['stream'=>'change','title'=>'Разработать инструкцию по продажам','product'=>'Инструкция, пригодная для обучения продавцов','plan_minutes'=>180,'day'=>'ср','priority'=>'normal'],
            ['stream'=>'change','title'=>'Разработать коммерческое предложение','product'=>'Шаблон, переданный продавцам','plan_minutes'=>180,'day'=>'чт','priority'=>'normal'],
            ['stream'=>'change','title'=>'Провести инструктаж по коммерческому предложению','product'=>'Продавцы знают, как использовать шаблон','plan_minutes'=>30,'day'=>'пт','priority'=>'normal'],
            ['stream'=>'run','title'=>'Обработать входящую коммуникацию','product'=>'Обработанные входящие сообщения','plan_minutes'=>0,'day'=>'ежедневно','priority'=>'normal'],
            ['stream'=>'run','title'=>'Составить план на день','product'=>'Приоритеты на день','plan_minutes'=>30,'day'=>'ежедневно','priority'=>'normal'],
            ['stream'=>'run','title'=>'Провести координацию с подчинёнными','product'=>'Согласованные действия и приоритеты','plan_minutes'=>150,'day'=>'ежедневно','priority'=>'normal'],
            ['stream'=>'run','title'=>'Провести координацию с руководителем','product'=>'Согласованные действия и приоритеты','plan_minutes'=>150,'day'=>'ежедневно','priority'=>'normal'],
            ['stream'=>'run','title'=>'Проверить подсчёт показателей и планы подчинённых','product'=>'Корректные статистические данные и графики','plan_minutes'=>30,'day'=>'ср','priority'=>'normal'],
            ['stream'=>'run','title'=>'Рассчитать свои недельные показатели','product'=>'Корректные статистические данные и графики','plan_minutes'=>30,'day'=>'ср','priority'=>'normal'],
            ['stream'=>'run','title'=>'Сформировать недельный план','product'=>'План, одобренный руководителем','plan_minutes'=>30,'day'=>'ср','priority'=>'normal'],
            ['stream'=>'operate','title'=>'Провести аналитику отдела продаж','product'=>'Корректные статистические данные для анализа','plan_minutes'=>300,'day'=>'ежедневно','priority'=>'normal'],
            ['stream'=>'operate','title'=>'Прослушать звонки менеджеров','product'=>'Выявленные слабые места в работе менеджеров','plan_minutes'=>600,'day'=>'ежедневно','priority'=>'normal'],
            ['stream'=>'operate','title'=>'Провести тренировки продавцов','product'=>'Менеджеры работают согласно технологии','plan_minutes'=>300,'day'=>'ежедневно','priority'=>'normal'],
            ['stream'=>'operate','title'=>'Доработать скрипты продаж','product'=>'Технология, способствующая росту конверсии','plan_minutes'=>120,'day'=>'чт','priority'=>'normal'],
            ['stream'=>'operate','title'=>'Проконтролировать заполнение базы клиентов','product'=>'Актуальная база клиентов','plan_minutes'=>300,'day'=>'ежедневно','priority'=>'normal'],
            ['stream'=>'develop','title'=>'Пройти курс по продажам в интернете','product'=>'Навык продаж в социальных сетях','plan_minutes'=>240,'day'=>'чт/пн','priority'=>'normal'],
        ]];
    } else {
        $presettitle = get_string('battleplan_preset_rop_norm_title','local_kbacompany');
        $payload = ['thresholds'=>['danger_below'=>70,'emergency_below'=>90,'normal_high'=>110,'affluence_high'=>130,'danger_trend'=>-20], 'metrics'=>[]];
        $wanted = [
            ['terms'=>['объем продаж','объём продаж'],'quota'=>140000],
            ['terms'=>['сумма заключенных договоров','сумма заключённых договоров'],'quota'=>220000],
            ['terms'=>['количество презентаций новым клиентам'],'quota'=>20],
        ];
        foreach ($wanted as $spec) {
            $found = '';
            foreach ($data['statistics'] as $stat) {
                $name = core_text::strtolower($stat['name']);
                foreach ($spec['terms'] as $term) { if (strpos($name, $term) !== false) { $found = $stat['uuid']; break 2; } }
            }
            $payload['metrics'][] = ['stat_uuid'=>$found,'quota'=>$spec['quota'],'weight'=>1,'critical'=>1,'comparison'=>'up'];
        }
    }
}
$formaction = $kind === 'norm' ? 'save_norm' : 'save_template';
echo html_writer::start_tag('form', ['method'=>'post','class'=>'kbacompany-form border rounded p-3 mb-4']);
echo html_writer::empty_tag('input',['type'=>'hidden','name'=>'uuid','value'=>$uuid]);
echo html_writer::empty_tag('input',['type'=>'hidden','name'=>'kind','value'=>$kind]);
echo html_writer::empty_tag('input',['type'=>'hidden','name'=>'action','value'=>$formaction]);
echo html_writer::empty_tag('input',['type'=>'hidden','name'=>'object','value'=>$current ? $current->uuid : '']);
echo html_writer::empty_tag('input',['type'=>'hidden','name'=>'revision','value'=>$current ? (int)$current->revision : 0]);
echo html_writer::empty_tag('input',['type'=>'hidden','name'=>'sesskey','value'=>sesskey()]);
echo html_writer::label(get_string('battleplan_setup_name','local_kbacompany'),'bp_setup_title');
echo html_writer::empty_tag('input',['id'=>'bp_setup_title','name'=>'title','class'=>'form-control','required'=>'required','value'=>$current ? $current->title : $presettitle]);
echo html_writer::label(get_string('battleplan_position','local_kbacompany'),'bp_setup_position');
echo html_writer::select($data['positions'],'positionuuid',$current ? $current->positionuuid : '',[''=>get_string('choosedots')],['id'=>'bp_setup_position','class'=>'form-control','required'=>'required']);

if ($kind === 'template') {
    echo html_writer::tag('p', get_string('battleplan_template_rows_hint','local_kbacompany'), ['class'=>'alert alert-info mt-3']);
    $streamoptions = [];
    foreach (task_service::STREAMS as $stream) { $streamoptions[$stream] = get_string('taskcenter_stream_'.$stream,'local_kbacompany'); }
    $priorityoptions = [];
    foreach (task_service::PRIORITIES as $priority) { $priorityoptions[$priority] = get_string('taskcenter_priority_'.$priority,'local_kbacompany'); }
    $rows = isset($payload['rows']) && is_array($payload['rows']) ? array_values($payload['rows']) : [];
    $t = new html_table();
    $t->head = [get_string('battleplan_template_section','local_kbacompany'),get_string('taskcenter_task','local_kbacompany'),get_string('taskcenter_product','local_kbacompany'),get_string('battleplan_template_plan','local_kbacompany'),get_string('battleplan_template_day','local_kbacompany'),get_string('taskcenter_priority','local_kbacompany')];
    for ($i=1; $i<=25; $i++) {
        $row = $rows[$i-1] ?? [];
        $t->data[] = [
            html_writer::select($streamoptions,'row_stream_'.$i,$row['stream'] ?? 'operate',false,['class'=>'form-control']),
            html_writer::empty_tag('input',['name'=>'row_title_'.$i,'class'=>'form-control','value'=>$row['title'] ?? '']),
            html_writer::empty_tag('input',['name'=>'row_product_'.$i,'class'=>'form-control','value'=>$row['product'] ?? '']),
            html_writer::empty_tag('input',['name'=>'row_minutes_'.$i,'type'=>'number','min'=>'0','class'=>'form-control','value'=>$row['plan_minutes'] ?? 0]),
            html_writer::empty_tag('input',['name'=>'row_day_'.$i,'class'=>'form-control','value'=>$row['day'] ?? '']),
            html_writer::select($priorityoptions,'row_priority_'.$i,$row['priority'] ?? 'normal',false,['class'=>'form-control']),
        ];
    }
    echo html_writer::table($t);
} else {
    $thresholds = isset($payload['thresholds']) && is_array($payload['thresholds']) ? $payload['thresholds'] : [];
    echo html_writer::tag('h4', get_string('battleplan_norm_thresholds','local_kbacompany'), ['class'=>'mt-3']);
    $thresholdfields = [
        'danger_below' => ['battleplan_threshold_danger','70'], 'emergency_below'=>['battleplan_threshold_emergency','90'],
        'normal_high'=>['battleplan_threshold_normal','110'], 'affluence_high'=>['battleplan_threshold_affluence','130'],
        'danger_trend'=>['battleplan_threshold_trend','-20'],
    ];
    foreach ($thresholdfields as $field=>$info) {
        echo html_writer::label(get_string($info[0],'local_kbacompany'),'bp_'.$field);
        echo html_writer::empty_tag('input',['id'=>'bp_'.$field,'name'=>$field,'class'=>'form-control','value'=>$thresholds[$field] ?? $info[1]]);
    }
    echo html_writer::tag('p', get_string('battleplan_norm_formula_hint','local_kbacompany'), ['class'=>'alert alert-info mt-3']);
    $metrics = isset($payload['metrics']) && is_array($payload['metrics']) ? array_values($payload['metrics']) : [];
    $metricoptions = ['' => '—'];
    foreach ($data['statistics'] as $statuuid=>$stat) { $metricoptions[$statuuid] = $stat['code'].' — '.$stat['name'].' ('.$stat['unit'].')'; }
    $compareoptions = ['up'=>get_string('battleplan_comparison_up','local_kbacompany'),'down'=>get_string('battleplan_comparison_down','local_kbacompany')];
    $t = new html_table();
    $t->head=[get_string('battleplan_metric','local_kbacompany'),get_string('battleplan_quota','local_kbacompany'),get_string('battleplan_weight','local_kbacompany'),get_string('battleplan_comparison','local_kbacompany'),get_string('battleplan_critical','local_kbacompany')];
    for($i=1;$i<=20;$i++){
        $row=$metrics[$i-1]??[];
        $t->data[]=[
            html_writer::select($metricoptions,'metric_'.$i,$row['stat_uuid']??'',false,['class'=>'form-control']),
            html_writer::empty_tag('input',['name'=>'quota_'.$i,'class'=>'form-control','value'=>isset($row['quota'])?$row['quota']:'']),
            html_writer::empty_tag('input',['name'=>'weight_'.$i,'class'=>'form-control','value'=>isset($row['weight'])?$row['weight']:'1']),
            html_writer::select($compareoptions,'comparison_'.$i,$row['comparison']??'up',false,['class'=>'form-control']),
            html_writer::empty_tag('input',['type'=>'checkbox','name'=>'critical_'.$i,'value'=>'1'] + (!empty($row['critical'])?['checked'=>'checked']:[])),
        ];
    }
    echo html_writer::table($t);
}
echo html_writer::tag('button', get_string('savechanges'), ['type'=>'submit','class'=>'btn btn-primary mt-3']);
echo html_writer::end_tag('form');

if ($current) {
    echo html_writer::start_tag('div',['class'=>'mb-4']);
    if ($current->status === 'draft') {
        echo html_writer::start_tag('form',['method'=>'post','class'=>'d-inline mr-2']);
        foreach (['uuid'=>$uuid,'kind'=>$kind,'action'=>$kind==='norm'?'publish_norm':'publish_template','object'=>$current->uuid,'revision'=>(int)$current->revision,'sesskey'=>sesskey()] as $n=>$v) echo html_writer::empty_tag('input',['type'=>'hidden','name'=>$n,'value'=>$v]);
        echo html_writer::tag('button',get_string('battleplan_setup_publish','local_kbacompany'),['type'=>'submit','class'=>'btn btn-success']); echo html_writer::end_tag('form');
    }
    if ($current->status !== 'archived') {
        echo html_writer::start_tag('form',['method'=>'post','class'=>'d-inline']);
        foreach (['uuid'=>$uuid,'kind'=>$kind,'action'=>$kind==='norm'?'archive_norm':'archive_template','object'=>$current->uuid,'revision'=>(int)$current->revision,'sesskey'=>sesskey()] as $n=>$v) echo html_writer::empty_tag('input',['type'=>'hidden','name'=>$n,'value'=>$v]);
        echo html_writer::tag('button',get_string('battleplan_setup_archive','local_kbacompany'),['type'=>'submit','class'=>'btn btn-outline-danger']); echo html_writer::end_tag('form');
    }
    echo html_writer::end_tag('div');
    echo $OUTPUT->heading(get_string('battleplan_setup_history','local_kbacompany'),4);
    $h = new html_table(); $h->head=[get_string('battleplan_setup_revision','local_kbacompany'),get_string('battleplan_status','local_kbacompany'),get_string('lastmodified')];
    foreach($data['history'] as $row){$h->data[]=[(int)$row->revision,get_string('battleplan_setup_status_'.$row->status,'local_kbacompany'),userdate((int)$row->timemodified)];}
    echo html_writer::table($h);
}
\local_kbacompany\local\bms_page_shell_service::finish_and_exit($bmsshell);
