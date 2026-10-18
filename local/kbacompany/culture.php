<?php
require_once(__DIR__ . '/../../config.php');

use local_kbacompany\local\bms_workspace_ux_service;
use local_kbacompany\local\culture_motivation_service;

require_login();
$context = context_system::instance();
require_capability('local/kbacompany:viewowncompany', $context);
$uuid = required_param('uuid', PARAM_RAW_TRIMMED);
$userid = optional_param('userid', 0, PARAM_INT);
$action = optional_param('action', '', PARAM_ALPHA);

if ($action === 'additem') {
    require_sesskey();
    culture_motivation_service::save_item($uuid, (int)$USER->id, [
        'kind' => required_param('kind', PARAM_ALPHA),
        'title' => required_param('title', PARAM_TEXT),
        'description' => optional_param('description', '', PARAM_TEXT),
        'desiredlevel' => optional_param('desiredlevel', 3, PARAM_INT),
    ]);
    redirect(new moodle_url('/local/kbacompany/culture.php', ['uuid'=>$uuid,'userid'=>$userid]));
}
if ($action === 'score') {
    require_sesskey();
    culture_motivation_service::save_score($uuid, required_param('userid', PARAM_INT), (int)$USER->id,
        required_param('itemuuid', PARAM_RAW_TRIMMED), required_param('score', PARAM_INT),
        optional_param('comment', '', PARAM_TEXT));
    redirect(new moodle_url('/local/kbacompany/culture.php', ['uuid'=>$uuid,'userid'=>required_param('userid', PARAM_INT)]));
}

$data = culture_motivation_service::context($uuid, (int)$USER->id, $userid);
$PAGE->set_context($context);
$PAGE->set_url(new moodle_url('/local/kbacompany/culture.php', ['uuid'=>$uuid,'userid'=>$data['userid']]));
$PAGE->set_title(get_string('culture_title', 'local_kbacompany'));
$PAGE->set_heading(get_string('pluginname', 'local_kbacompany'));
$shell = \local_kbacompany\local\bms_page_shell_service::begin($uuid, (int)$USER->id, 'culture',
    get_string('culture_title', 'local_kbacompany'));

$meta = $data['employee'] ? s($data['employee']['full_name']) : get_string('culture_no_employee', 'local_kbacompany');
echo bms_workspace_ux_service::record_header(
    get_string('culture_title', 'local_kbacompany'),
    get_string('culture_intro', 'local_kbacompany'),
    get_string('culture_eyebrow', 'local_kbacompany'),
    $meta
);

$tabs = [
    ['label'=>get_string('people_title','local_kbacompany'), 'url'=>new moodle_url('/local/kbacompany/people.php',['uuid'=>$uuid])],
    ['label'=>get_string('bms_employees_title','local_kbacompany'), 'url'=>new moodle_url('/local/kbacompany/employees.php',['uuid'=>$uuid])],
    ['label'=>get_string('culture_title_short','local_kbacompany'), 'url'=>$PAGE->url, 'active'=>true],
    ['label'=>get_string('bms_learning_title','local_kbacompany'), 'url'=>new moodle_url('/local/kbacompany/learning.php',['uuid'=>$uuid])],
];
$tabhtml = '';
foreach ($tabs as $tab) {
    $class = 'bms-workspace-subnav-link' . (!empty($tab['active']) ? ' is-active' : '');
    $tabhtml .= html_writer::link($tab['url'], $tab['label'], ['class'=>$class]);
}
echo html_writer::tag('nav', $tabhtml, ['class'=>'bms-workspace-subnav mb-3', 'aria-label'=>get_string('culture_tabs','local_kbacompany')]);

$cards = [
    [get_string('culture_kpi_values','local_kbacompany'), $data['summary']['value']],
    [get_string('culture_kpi_culture','local_kbacompany'), $data['summary']['culture']],
    [get_string('culture_kpi_motivation','local_kbacompany'), $data['summary']['motivation']],
    [get_string('culture_kpi_gaps','local_kbacompany'), $data['summary']['gaps']],
];
echo html_writer::start_div('row mb-3');
foreach ($cards as $card) {
    echo html_writer::start_div('col-md-3 mb-2');
    echo html_writer::div(html_writer::div(s($card[0]), 'text-muted small') . html_writer::div((string)$card[1], 'h3 mb-0'), 'card card-body h-100');
    echo html_writer::end_div();
}
echo html_writer::end_div();

if ($data['employees']) {
    echo html_writer::start_tag('form', ['method'=>'get','class'=>'form-inline card card-body mb-3']);
    echo html_writer::empty_tag('input', ['type'=>'hidden','name'=>'uuid','value'=>$uuid]);
    echo html_writer::tag('label', get_string('culture_employee','local_kbacompany'), ['for'=>'culture-user','class'=>'mr-2 font-weight-bold']);
    $options = [];
    foreach ($data['employees'] as $employee) { $options[(int)$employee['user_id']] = $employee['full_name']; }
    echo html_writer::select($options, 'userid', $data['userid'], false, ['id'=>'culture-user','class'=>'custom-select mr-2']);
    echo html_writer::tag('button', get_string('culture_show','local_kbacompany'), ['class'=>'btn btn-outline-primary','type'=>'submit']);
    echo html_writer::end_tag('form');
}

$table = new html_table();
$table->attributes['class'] = 'table table-sm';
$table->head = [get_string('type'), get_string('name'), get_string('culture_target','local_kbacompany'),
    get_string('culture_actual','local_kbacompany'), get_string('culture_status','local_kbacompany'), get_string('actions')];
foreach ($data['rows'] as $row) {
    $kind = get_string('culture_kind_' . $row['kind'], 'local_kbacompany');
    if ($row['actual'] === null) {
        $status = html_writer::span(get_string('culture_status_nodata','local_kbacompany'), 'badge badge-secondary');
    } else if ($row['gap'] > 0) {
        $status = html_writer::span(get_string('culture_status_gap','local_kbacompany'), 'badge badge-warning');
    } else {
        $status = html_writer::span(get_string('culture_status_match','local_kbacompany'), 'badge badge-success');
    }
    $actionhtml = '—';
    if ($data['can_manage'] && $data['userid'] > 0) {
        $actionhtml = html_writer::start_tag('form', ['method'=>'post','class'=>'form-inline']);
        foreach (['uuid'=>$uuid,'userid'=>$data['userid'],'action'=>'score','itemuuid'=>$row['uuid'],'sesskey'=>sesskey()] as $n=>$v) {
            $actionhtml .= html_writer::empty_tag('input', ['type'=>'hidden','name'=>$n,'value'=>$v]);
        }
        $actionhtml .= html_writer::select([1=>1,2=>2,3=>3,4=>4,5=>5], 'score', $row['actual'] ?: 3, false, ['class'=>'custom-select custom-select-sm mr-1']);
        $actionhtml .= html_writer::empty_tag('input', ['type'=>'text','name'=>'comment','value'=>$row['comment'],'class'=>'form-control form-control-sm mr-1','placeholder'=>get_string('culture_comment','local_kbacompany')]);
        $actionhtml .= html_writer::tag('button', get_string('savechanges'), ['type'=>'submit','class'=>'btn btn-sm btn-outline-primary']);
        $actionhtml .= html_writer::end_tag('form');
    }
    $table->data[] = [$kind, format_string($row['title']), $row['desired'], $row['actual'] === null ? '—' : $row['actual'], $status, $actionhtml];
}
echo html_writer::div(html_writer::table($table), 'table-responsive card mb-3');

if ($data['can_manage']) {
    echo html_writer::start_div('card card-body mb-3');
    echo html_writer::tag('h3', get_string('culture_add_item','local_kbacompany'), ['class'=>'h5']);
    echo html_writer::start_tag('form', ['method'=>'post','class'=>'row']);
    foreach (['uuid'=>$uuid,'userid'=>$data['userid'],'action'=>'additem','sesskey'=>sesskey()] as $n=>$v) {
        echo html_writer::empty_tag('input', ['type'=>'hidden','name'=>$n,'value'=>$v]);
    }
    echo html_writer::start_div('col-md-2 mb-2');
    echo html_writer::select(['value'=>get_string('culture_kind_value','local_kbacompany'),'culture'=>get_string('culture_kind_culture','local_kbacompany'),'motivation'=>get_string('culture_kind_motivation','local_kbacompany')], 'kind', 'value', false, ['class'=>'custom-select w-100']);
    echo html_writer::end_div();
    echo html_writer::start_div('col-md-3 mb-2');
    echo html_writer::empty_tag('input', ['name'=>'title','required'=>'required','class'=>'form-control','placeholder'=>get_string('culture_item_title','local_kbacompany')]);
    echo html_writer::end_div();
    echo html_writer::start_div('col-md-4 mb-2');
    echo html_writer::empty_tag('input', ['name'=>'description','class'=>'form-control','placeholder'=>get_string('culture_item_description','local_kbacompany')]);
    echo html_writer::end_div();
    echo html_writer::start_div('col-md-1 mb-2');
    echo html_writer::select([1=>1,2=>2,3=>3,4=>4,5=>5], 'desiredlevel', 4, false, ['class'=>'custom-select w-100']);
    echo html_writer::end_div();
    echo html_writer::start_div('col-md-2 mb-2');
    echo html_writer::tag('button', get_string('culture_add','local_kbacompany'), ['type'=>'submit','class'=>'btn btn-primary btn-block']);
    echo html_writer::end_div();
    echo html_writer::end_tag('form');
    echo html_writer::end_div();
}

echo html_writer::div(get_string('culture_boundary_notice','local_kbacompany'), 'alert alert-light border small');
\local_kbacompany\local\bms_page_shell_service::finish($shell);
