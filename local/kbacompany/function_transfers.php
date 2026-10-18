<?php
require_once(__DIR__ . '/../../config.php');

use local_kbacompany\local\function_transfer_service;

require_login();
$uuid = required_param('uuid', PARAM_RAW_TRIMMED);
$action = optional_param('action', '', PARAM_ALPHAEXT);
$prefunction = optional_param('function', '', PARAM_RAW_TRIMMED);
$context = context_system::instance();
require_capability('local/kbacompany:viewowncompany', $context);

$data = function_transfer_service::context($uuid, (int)$USER->id);
if ($action !== '') {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') { throw new moodle_exception('functiontransfer_postrequired', 'local_kbacompany'); }
    require_sesskey();
    if ($action === 'create') {
        function_transfer_service::create($uuid, (int)$USER->id, [
            'functionuuid'=>required_param('functionuuid', PARAM_RAW_TRIMMED),
            'sourceuserid'=>optional_param('sourceuserid', 0, PARAM_INT),
            'targetpositionuuid'=>optional_param('targetpositionuuid', '', PARAM_RAW_TRIMMED),
            'targetuserid'=>required_param('targetuserid', PARAM_INT),
            'transfertype'=>required_param('transfertype', PARAM_ALPHAEXT),
            'authorityscope'=>optional_param('authorityscope', '', PARAM_TEXT),
            'handovernote'=>optional_param('handovernote', '', PARAM_TEXT),
            'startsat'=>optional_param('startsat', time(), PARAM_INT),
            'endsat'=>optional_param('endsat', 0, PARAM_INT),
        ]);
    } else if ($action === 'submit') {
        function_transfer_service::submit($uuid, (int)$USER->id, required_param('transfer', PARAM_RAW_TRIMMED));
    } else if ($action === 'authorize') {
        function_transfer_service::authorize($uuid, (int)$USER->id, required_param('transfer', PARAM_RAW_TRIMMED));
    } else if ($action === 'accept') {
        function_transfer_service::accept($uuid, (int)$USER->id, required_param('transfer', PARAM_RAW_TRIMMED));
    } else if ($action === 'revoke') {
        function_transfer_service::revoke($uuid, (int)$USER->id, required_param('transfer', PARAM_RAW_TRIMMED));
    } else { throw new moodle_exception('invalidparameter'); }
    redirect(new moodle_url('/local/kbacompany/function_transfers.php', ['uuid'=>$uuid]),
        get_string('functiontransfer_saved', 'local_kbacompany'), null, \core\output\notification::NOTIFY_SUCCESS);
}

$PAGE->set_context($context);
$PAGE->set_url(new moodle_url('/local/kbacompany/function_transfers.php', ['uuid'=>$uuid]));
$PAGE->set_title(get_string('functiontransfer_title', 'local_kbacompany'));
$PAGE->set_heading(get_string('functiontransfer_title', 'local_kbacompany'));
$PAGE->requires->css(new moodle_url('/local/kbacompany/styles.css'));
$bmsshell = \local_kbacompany\local\bms_page_shell_service::begin($uuid, (int)$USER->id, 'functions', get_string('functiontransfer_title', 'local_kbacompany'));

echo html_writer::div(html_writer::link(new moodle_url('/local/kbacompany/functions.php', ['uuid'=>$uuid]),
    '← ' . get_string('function_title', 'local_kbacompany')), 'mb-3');
echo $OUTPUT->heading(get_string('functiontransfer_title', 'local_kbacompany') . ': ' . format_string($data['company']['name']), 2);
echo html_writer::tag('p', get_string('functiontransfer_intro', 'local_kbacompany'), ['class'=>'lead']);
echo $OUTPUT->notification(get_string('functiontransfer_acl_notice', 'local_kbacompany'), 'info');

$functionoptions = [];
foreach ($data['functions'] as $f) { $functionoptions[$f['uuid']] = $f['code'] . ' — ' . $f['name']; }
$employeeoptions = [];
foreach ($data['employees'] as $e) { $employeeoptions[(int)$e['user_id']] = $e['full_name']; }
$positionoptions = ['' => get_string('functiontransfer_noposition', 'local_kbacompany')];
foreach ($data['positions'] as $p) { $positionoptions[$p['uuid']] = $p['code'] . ' — ' . $p['name']; }
$typeoptions = [];
foreach (function_transfer_service::TYPES as $type) { $typeoptions[$type] = get_string('functiontransfer_type_' . $type, 'local_kbacompany'); }

if ($data['can_manage'] && $functionoptions && $employeeoptions) {
    echo $OUTPUT->heading(get_string('functiontransfer_create', 'local_kbacompany'), 3);
    echo html_writer::start_tag('form', ['method'=>'post','class'=>'kbacompany-form mb-4']);
    foreach (['uuid'=>$uuid,'action'=>'create','sesskey'=>sesskey()] as $k=>$v) echo html_writer::empty_tag('input',['type'=>'hidden','name'=>$k,'value'=>$v]);
    echo html_writer::label(get_string('functiontransfer_function', 'local_kbacompany'), 'ft_function');
    echo html_writer::select($functionoptions, 'functionuuid', $prefunction, false, ['id'=>'ft_function','required'=>'required']);
    echo html_writer::label(get_string('functiontransfer_sourceemployee', 'local_kbacompany'), 'ft_source');
    echo html_writer::select(['0'=>get_string('functiontransfer_notset','local_kbacompany')] + $employeeoptions, 'sourceuserid', 0, false, ['id'=>'ft_source']);
    echo html_writer::label(get_string('functiontransfer_targetemployee', 'local_kbacompany'), 'ft_target');
    echo html_writer::select($employeeoptions, 'targetuserid', '', false, ['id'=>'ft_target','required'=>'required']);
    echo html_writer::label(get_string('functiontransfer_targetposition', 'local_kbacompany'), 'ft_position');
    echo html_writer::select($positionoptions, 'targetpositionuuid', '', false, ['id'=>'ft_position']);
    echo html_writer::label(get_string('functiontransfer_type', 'local_kbacompany'), 'ft_type');
    echo html_writer::select($typeoptions, 'transfertype', 'delegation', false, ['id'=>'ft_type']);
    echo html_writer::label(get_string('functiontransfer_authorityscope', 'local_kbacompany'), 'ft_scope');
    echo html_writer::tag('textarea', '', ['id'=>'ft_scope','name'=>'authorityscope','rows'=>3,'required'=>'required']);
    echo html_writer::label(get_string('functiontransfer_handovernote', 'local_kbacompany'), 'ft_note');
    echo html_writer::tag('textarea', '', ['id'=>'ft_note','name'=>'handovernote','rows'=>3]);
    echo html_writer::tag('button', get_string('functiontransfer_create', 'local_kbacompany'), ['type'=>'submit','class'=>'btn btn-primary']);
    echo html_writer::end_tag('form');
}

$table = new html_table();
$table->head = [get_string('functiontransfer_function','local_kbacompany'), get_string('functiontransfer_target','local_kbacompany'),
    get_string('functiontransfer_type','local_kbacompany'), get_string('functiontransfer_status','local_kbacompany'),
    get_string('functiontransfer_period','local_kbacompany'), get_string('actions')];
foreach ($data['transfers'] as $t) {
    $target = format_string($t['target_user_name']);
    if ($t['target_position_name'] !== '') $target .= html_writer::div(format_string($t['target_position_name']), 'small text-muted');
    $period = userdate($t['starts_at'], get_string('strftimedate','langconfig'));
    $period .= ' → ' . ($t['ends_at'] ? userdate($t['ends_at'], get_string('strftimedate','langconfig')) : '∞');
    $actions = '';
    $hidden = static function(array $vals): string { $o=''; foreach($vals as $k=>$v){$o.=html_writer::empty_tag('input',['type'=>'hidden','name'=>$k,'value'=>$v]);} return $o; };
    if ($data['can_manage'] && in_array($t['status'], ['draft','pending'], true)) {
        $next = $t['status'] === 'draft' ? 'submit' : 'authorize';
        $label = get_string('functiontransfer_' . $next, 'local_kbacompany');
        $actions .= html_writer::start_tag('form',['method'=>'post','class'=>'d-inline mr-1']).$hidden(['uuid'=>$uuid,'sesskey'=>sesskey(),'action'=>$next,'transfer'=>$t['uuid']]).html_writer::tag('button',$label,['type'=>'submit','class'=>'btn btn-sm btn-outline-primary']).html_writer::end_tag('form');
    }
    if ($t['status'] === 'active' && $t['target_userid'] === (int)$USER->id) {
        $actions .= html_writer::start_tag('form',['method'=>'post','class'=>'d-inline mr-1']).$hidden(['uuid'=>$uuid,'sesskey'=>sesskey(),'action'=>'accept','transfer'=>$t['uuid']]).html_writer::tag('button',get_string('functiontransfer_accept','local_kbacompany'),['type'=>'submit','class'=>'btn btn-sm btn-success']).html_writer::end_tag('form');
    }
    if ($data['can_manage'] && !in_array($t['status'], ['revoked'], true)) {
        $actions .= html_writer::start_tag('form',['method'=>'post','class'=>'d-inline']).$hidden(['uuid'=>$uuid,'sesskey'=>sesskey(),'action'=>'revoke','transfer'=>$t['uuid']]).html_writer::tag('button',get_string('functiontransfer_revoke','local_kbacompany'),['type'=>'submit','class'=>'btn btn-sm btn-outline-danger']).html_writer::end_tag('form');
    }
    $table->data[] = [s($t['function_code']).' — '.format_string($t['function_name']), $target,
        get_string('functiontransfer_type_'.$t['transfer_type'],'local_kbacompany'),
        get_string('functiontransfer_status_'.$t['effective_status'],'local_kbacompany'), $period, $actions];
}
echo $table->data ? html_writer::table($table) : $OUTPUT->notification(get_string('functiontransfer_empty','local_kbacompany'), 'info');
\local_kbacompany\local\bms_page_shell_service::finish($bmsshell);
