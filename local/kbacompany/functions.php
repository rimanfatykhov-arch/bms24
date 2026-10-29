<?php
require_once(__DIR__ . '/../../config.php');

use local_kbacompany\local\function_service;
use local_kbacompany\local\process_model_service;

require_login();
$uuid = required_param('uuid', PARAM_RAW_TRIMMED);
$selecteduuid = optional_param('function', '', PARAM_RAW_TRIMMED);
$processuuid = optional_param('process', '', PARAM_RAW_TRIMMED);
$activityuuid = optional_param('activity', '', PARAM_RAW_TRIMMED);
$action = optional_param('action', '', PARAM_ALPHAEXT);

$PAGE->set_context(context_system::instance());
$PAGE->set_url(new moodle_url('/local/kbacompany/functions.php', ['uuid' => $uuid]));
// BMS page shell adapter selects the presentation layout.
$PAGE->set_title(get_string('function_title', 'local_kbacompany'));
$PAGE->set_heading(get_string('function_title', 'local_kbacompany'));
$PAGE->requires->css(new moodle_url('/local/kbacompany/styles.css'));

$data = function_service::context($uuid, (int)$USER->id, $selecteduuid);
if ($action !== '') {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        throw new moodle_exception('function_postrequired', 'local_kbacompany');
    }
    require_sesskey();
    if (!$data['can_manage']) {
        throw new required_capability_exception(context_system::instance(),
            'local/kbacompany:manageorganization', 'nopermissions', '');
    }
    if ($action === 'create') {
        $saved = function_service::create_from_activity($uuid, (int)$USER->id,
            required_param('processuuid', PARAM_RAW_TRIMMED), required_param('activityuuid', PARAM_RAW_TRIMMED), [
                'code' => required_param('code', PARAM_RAW_TRIMMED),
                'name' => required_param('name', PARAM_RAW_TRIMMED),
                'purpose' => optional_param('purpose', '', PARAM_RAW_TRIMMED),
                'positionuuid' => optional_param('positionuuid', '', PARAM_RAW_TRIMMED),
                'productuuid' => optional_param('productuuid', '', PARAM_RAW_TRIMMED),
                'producttext' => optional_param('producttext', '', PARAM_RAW_TRIMMED),
            ]);
        redirect(new moodle_url('/local/kbacompany/functions.php', ['uuid' => $uuid, 'function' => $saved]),
            get_string('function_saved', 'local_kbacompany'), null, \core\output\notification::NOTIFY_SUCCESS);
    } else if ($action === 'save') {
        $fnuuid = required_param('functionuuid', PARAM_RAW_TRIMMED);
        function_service::save($uuid, (int)$USER->id, $fnuuid, required_param('revision', PARAM_INT), [
            'code' => required_param('code', PARAM_RAW_TRIMMED),
            'name' => required_param('name', PARAM_RAW_TRIMMED),
            'purpose' => optional_param('purpose', '', PARAM_RAW_TRIMMED),
            'positionuuid' => optional_param('positionuuid', '', PARAM_RAW_TRIMMED),
            'productuuid' => optional_param('productuuid', '', PARAM_RAW_TRIMMED),
            'producttext' => optional_param('producttext', '', PARAM_RAW_TRIMMED),
        ]);
        redirect(new moodle_url('/local/kbacompany/functions.php', ['uuid' => $uuid, 'function' => $fnuuid]),
            get_string('function_saved', 'local_kbacompany'), null, \core\output\notification::NOTIFY_SUCCESS);
    } else if ($action === 'refreshsource') {
        $fnuuid = required_param('functionuuid', PARAM_RAW_TRIMMED);
        function_service::refresh_source($uuid, (int)$USER->id, $fnuuid, required_param('revision', PARAM_INT));
        redirect(new moodle_url('/local/kbacompany/functions.php', ['uuid' => $uuid, 'function' => $fnuuid]),
            get_string('function_sourcesynced', 'local_kbacompany'), null, \core\output\notification::NOTIFY_SUCCESS);
    } else if ($action === 'publish') {
        $fnuuid = required_param('functionuuid', PARAM_RAW_TRIMMED);
        function_service::publish($uuid, (int)$USER->id, $fnuuid, required_param('revision', PARAM_INT));
        redirect(new moodle_url('/local/kbacompany/functions.php', ['uuid' => $uuid, 'function' => $fnuuid]),
            get_string('function_published', 'local_kbacompany'), null, \core\output\notification::NOTIFY_SUCCESS);
    } else if ($action === 'archive') {
        $fnuuid = required_param('functionuuid', PARAM_RAW_TRIMMED);
        function_service::archive($uuid, (int)$USER->id, $fnuuid, required_param('revision', PARAM_INT),
            required_param('reason', PARAM_RAW_TRIMMED));
        redirect(new moodle_url('/local/kbacompany/functions.php', ['uuid' => $uuid]),
            get_string('function_archived', 'local_kbacompany'), null, \core\output\notification::NOTIFY_SUCCESS);
    }
    throw new moodle_exception('invalidparameter');
}

$source = null;
if ($processuuid !== '' && $activityuuid !== '') {
    $processmodel = process_model_service::context($uuid, (int)$USER->id, $processuuid);
    foreach ($processmodel['bpmn']['nodes'] as $node) {
        if ($node['type'] === 'task' && $node['uuid'] === $activityuuid) {
            $source = ['process' => $processmodel['process'], 'model' => $processmodel['model'], 'activity' => $node];
            break;
        }
    }
    if (!$source) { throw new moodle_exception('function_invalidsource', 'local_kbacompany'); }
}

$positionoptions = ['' => get_string('function_unassigned', 'local_kbacompany')];
foreach ($data['positions'] as $position) { $positionoptions[$position['uuid']] = $position['name']; }
$productoptions = ['' => get_string('function_producttextoption', 'local_kbacompany')];
foreach ($data['products'] as $product) { $productoptions[$product['uuid']] = $product['name']; }

$hidden = static function(array $values): string {
    $out = '';
    foreach ($values as $name => $value) {
        $out .= html_writer::empty_tag('input', ['type' => 'hidden', 'name' => $name, 'value' => $value]);
    }
    return $out;
};

$editform = static function(array $function) use ($uuid, $positionoptions, $productoptions, $hidden): void {
    echo html_writer::start_tag('form', ['method' => 'post', 'class' => 'kbacompany-form mb-3']);
    echo $hidden(['uuid' => $uuid, 'sesskey' => sesskey(), 'action' => 'save',
        'functionuuid' => $function['uuid'], 'revision' => $function['revision']]);
    echo html_writer::label(get_string('function_code', 'local_kbacompany'), 'fn_code');
    echo html_writer::empty_tag('input', ['id' => 'fn_code', 'name' => 'code', 'value' => $function['code'], 'required' => 'required']);
    echo html_writer::label(get_string('function_name', 'local_kbacompany'), 'fn_name');
    echo html_writer::empty_tag('input', ['id' => 'fn_name', 'name' => 'name', 'value' => $function['name'], 'required' => 'required']);
    echo html_writer::label(get_string('function_purpose', 'local_kbacompany'), 'fn_purpose');
    echo html_writer::tag('textarea', s($function['purpose']), ['id' => 'fn_purpose', 'name' => 'purpose', 'rows' => 3]);
    echo html_writer::label(get_string('function_position', 'local_kbacompany'), 'fn_position');
    echo html_writer::select($positionoptions, 'positionuuid', $function['position_uuid'], false, ['id' => 'fn_position']);
    echo html_writer::label(get_string('function_product', 'local_kbacompany'), 'fn_product');
    echo html_writer::select($productoptions, 'productuuid', $function['product_uuid'], false, ['id' => 'fn_product']);
    echo html_writer::label(get_string('function_producttext', 'local_kbacompany'), 'fn_producttext');
    echo html_writer::tag('textarea', s($function['product_text']), ['id' => 'fn_producttext', 'name' => 'producttext', 'rows' => 2]);
    echo html_writer::tag('button', get_string('savechanges'), ['type' => 'submit', 'class' => 'btn btn-primary']);
    echo html_writer::end_tag('form');
};

$bmsshell = \local_kbacompany\local\bms_page_shell_service::begin($uuid, (int)$USER->id, 'functions', get_string('function_title', 'local_kbacompany'));
echo html_writer::div(html_writer::link(new moodle_url('/local/kbacompany/bms_workspace.php', ['uuid' => $uuid]),
    '← ' . get_string('backtocompany', 'local_kbacompany')), 'mb-3');
echo $OUTPUT->heading(get_string('function_title', 'local_kbacompany') . ': ' . format_string($data['company']['name']), 2);
echo html_writer::div(get_string('function_intro', 'local_kbacompany'), 'lead mb-3');
echo html_writer::div(html_writer::link(new moodle_url('/local/kbacompany/function_transfers.php', ['uuid' => $uuid]),
    get_string('functiontransfer_open', 'local_kbacompany'), ['class' => 'btn btn-outline-primary']), 'mb-3');

$table = new html_table();
$table->head = [get_string('function_code', 'local_kbacompany'), get_string('function_name', 'local_kbacompany'),
    get_string('function_product', 'local_kbacompany'), get_string('function_position', 'local_kbacompany'),
    get_string('function_source', 'local_kbacompany'), get_string('function_status', 'local_kbacompany')];
foreach ($data['functions'] as $function) {
    $name = html_writer::link(new moodle_url('/local/kbacompany/functions.php', [
        'uuid' => $uuid, 'function' => $function['uuid'],
    ]), format_string($function['name']));
    $sourcecell = format_string($function['source_process_name']) . ' → ' . format_string($function['source_action']);
    if ($function['source_outdated']) {
        $sourcecell .= html_writer::div(get_string('function_sourceoutdated', 'local_kbacompany'), 'text-warning small');
    }
    $table->data[] = [s($function['code']), $name, format_string($function['product_name']),
        $function['position_name'] !== '' ? format_string($function['position_name']) : html_writer::span('—', 'text-warning'),
        $sourcecell, get_string('function_status_' . $function['status'], 'local_kbacompany')];
}
echo $table->data ? html_writer::table($table) : $OUTPUT->notification(get_string('function_empty', 'local_kbacompany'), 'info');

if ($source && $data['can_manage']) {
    echo $OUTPUT->heading(get_string('function_createfromactivity', 'local_kbacompany'), 3);
    $activity = $source['activity'];
    echo html_writer::div(get_string('function_sourceactivity', 'local_kbacompany') . ': ' .
        format_string($source['process']['name']) . ' → ' . format_string($activity['name']), 'mb-2');
    echo html_writer::div(get_string('procmodel_action', 'local_kbacompany') . ': ' . format_text($activity['description'], FORMAT_PLAIN), 'mb-2');
    echo html_writer::div(get_string('function_product', 'local_kbacompany') . ': ' .
        format_string($activity['product_name'] !== '' ? $activity['product_name'] : $activity['output']), 'mb-3');
    $preposition = $activity['executor_type'] === 'position' ? $activity['executor_uuid'] : '';
    echo html_writer::start_tag('form', ['method' => 'post', 'class' => 'kbacompany-form mb-4']);
    echo $hidden(['uuid' => $uuid, 'sesskey' => sesskey(), 'action' => 'create',
        'processuuid' => $processuuid, 'activityuuid' => $activityuuid]);
    echo html_writer::label(get_string('function_code', 'local_kbacompany'), 'new_fn_code');
    echo html_writer::empty_tag('input', ['id' => 'new_fn_code', 'name' => 'code', 'required' => 'required']);
    echo html_writer::label(get_string('function_name', 'local_kbacompany'), 'new_fn_name');
    echo html_writer::empty_tag('input', ['id' => 'new_fn_name', 'name' => 'name', 'value' => $activity['name'], 'required' => 'required']);
    echo html_writer::label(get_string('function_purpose', 'local_kbacompany'), 'new_fn_purpose');
    echo html_writer::tag('textarea', s($activity['description']), ['id' => 'new_fn_purpose', 'name' => 'purpose', 'rows' => 3]);
    echo html_writer::label(get_string('function_position', 'local_kbacompany'), 'new_fn_position');
    echo html_writer::select($positionoptions, 'positionuuid', $preposition, false, ['id' => 'new_fn_position']);
    echo html_writer::label(get_string('function_product', 'local_kbacompany'), 'new_fn_product');
    echo html_writer::select($productoptions, 'productuuid', $activity['product_uuid'], false, ['id' => 'new_fn_product']);
    echo html_writer::label(get_string('function_producttext', 'local_kbacompany'), 'new_fn_producttext');
    echo html_writer::tag('textarea', s($activity['product_name'] !== '' ? $activity['product_name'] : $activity['output']),
        ['id' => 'new_fn_producttext', 'name' => 'producttext', 'rows' => 2]);
    echo html_writer::tag('button', get_string('function_create', 'local_kbacompany'), ['type' => 'submit', 'class' => 'btn btn-primary']);
    echo html_writer::end_tag('form');
}

if ($data['selected']) {
    $function = $data['selected'];
    echo $OUTPUT->heading(get_string('function_detail', 'local_kbacompany') . ': ' . format_string($function['name']), 3);
    $detail = new html_table();
    $detail->data = [
        [get_string('function_product', 'local_kbacompany'), format_string($function['product_name'])],
        [get_string('function_position', 'local_kbacompany'), $function['position_name'] !== '' ? format_string($function['position_name']) : '—'],
        [get_string('function_sourceprocess', 'local_kbacompany'), format_string($function['source_process_name'])],
        [get_string('function_sourceaction', 'local_kbacompany'), format_text($function['source_action'], FORMAT_PLAIN)],
        [get_string('function_sourceoutput', 'local_kbacompany'), format_string($function['source_output'])],
        [get_string('function_sourcerevision', 'local_kbacompany'), (string)$function['source_model_revision']],
        [get_string('function_status', 'local_kbacompany'), get_string('function_status_' . $function['status'], 'local_kbacompany')],
    ];
    echo html_writer::table($detail);
    if ($data['can_manage'] && $function['status'] !== 'archived') {
        echo $editform($function);
        if ($function['source_outdated']) {
            echo html_writer::start_tag('form', ['method' => 'post', 'class' => 'd-inline mr-2']);
            echo $hidden(['uuid' => $uuid, 'sesskey' => sesskey(), 'action' => 'refreshsource',
                'functionuuid' => $function['uuid'], 'revision' => $function['revision']]);
            echo html_writer::tag('button', get_string('function_refreshsource', 'local_kbacompany'),
                ['type' => 'submit', 'class' => 'btn btn-outline-warning']);
            echo html_writer::end_tag('form');
        }
        if ($function['status'] === 'draft') {
            echo html_writer::start_tag('form', ['method' => 'post', 'class' => 'd-inline mr-2']);
            echo $hidden(['uuid' => $uuid, 'sesskey' => sesskey(), 'action' => 'publish',
                'functionuuid' => $function['uuid'], 'revision' => $function['revision']]);
            echo html_writer::tag('button', get_string('function_publish', 'local_kbacompany'),
                ['type' => 'submit', 'class' => 'btn btn-success']);
            echo html_writer::end_tag('form');
        }
        echo html_writer::start_tag('form', ['method' => 'post', 'class' => 'd-inline']);
        echo $hidden(['uuid' => $uuid, 'sesskey' => sesskey(), 'action' => 'archive',
            'functionuuid' => $function['uuid'], 'revision' => $function['revision']]);
        echo html_writer::empty_tag('input', ['name' => 'reason', 'placeholder' => get_string('function_archivereason', 'local_kbacompany'), 'required' => 'required']);
        echo html_writer::tag('button', get_string('function_archive', 'local_kbacompany'),
            ['type' => 'submit', 'class' => 'btn btn-outline-danger']);
        echo html_writer::end_tag('form');
    }
}

\local_kbacompany\local\bms_page_shell_service::finish($bmsshell);
