<?php
require_once(__DIR__ . '/../../config.php');
require_once(__DIR__ . '/lib.php');

use local_kbacompany\local\position_operating_service;
use local_kbacompany\local\product_service;

require_login();
$context = context_system::instance();
require_capability('local/kbacompany:viewowncompany', $context);
$uuid = required_param('uuid', PARAM_RAW_TRIMMED);
$positionuuid = optional_param('position', '', PARAM_RAW_TRIMMED);
$data = position_operating_service::context($uuid, (int)$USER->id, $positionuuid);
$canmanage = $data['can_manage'];

$PAGE->set_context($context);
$PAGE->set_url(new moodle_url('/local/kbacompany/position_os.php', ['uuid' => $uuid, 'position' => $positionuuid]));
// BMS page shell adapter selects the presentation layout.
$PAGE->set_title(get_string('positionos_title', 'local_kbacompany'));
$PAGE->set_heading(get_string('pluginname', 'local_kbacompany'));

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_sesskey();
    if (!$canmanage) { throw new required_capability_exception($context, 'local/kbacompany:manageorganization', 'nopermissions', ''); }
    $action = required_param('action', PARAM_ALPHA);
    if ($action === 'create') {
        position_operating_service::create_work_item($uuid, (int)$USER->id,
            required_param('position', PARAM_RAW_TRIMMED), required_param('function', PARAM_RAW_TRIMMED),
            required_param('frequency', PARAM_ALPHA), optional_param('schedule', '', PARAM_RAW_TRIMMED),
            optional_param('workaction', '', PARAM_RAW_TRIMMED), optional_param('product', '', PARAM_RAW_TRIMMED));
    } else if ($action === 'save') {
        position_operating_service::save_work_item($uuid, (int)$USER->id, required_param('item', PARAM_RAW_TRIMMED),
            required_param('revision', PARAM_INT), required_param('frequency', PARAM_ALPHA),
            optional_param('schedule', '', PARAM_RAW_TRIMMED), required_param('workaction', PARAM_RAW_TRIMMED),
            required_param('product', PARAM_RAW_TRIMMED), optional_param('reason', '', PARAM_RAW_TRIMMED));
    } else if ($action === 'publish') {
        position_operating_service::publish_work_item($uuid, (int)$USER->id,
            required_param('item', PARAM_RAW_TRIMMED), required_param('revision', PARAM_INT));
    } else if ($action === 'archive') {
        position_operating_service::archive_work_item($uuid, (int)$USER->id,
            required_param('item', PARAM_RAW_TRIMMED), required_param('revision', PARAM_INT),
            required_param('reason', PARAM_RAW_TRIMMED));
    } else { throw new moodle_exception('invalidparameter'); }
    redirect(new moodle_url('/local/kbacompany/position_os.php', ['uuid' => $uuid, 'position' => required_param('position', PARAM_RAW_TRIMMED)]),
        get_string('positionos_saved', 'local_kbacompany'));
}

$frequencyoptions = [];
foreach ($data['frequencies'] as $frequency) { $frequencyoptions[$frequency] = get_string('positionos_frequency_' . $frequency, 'local_kbacompany'); }

$bmsshell = \local_kbacompany\local\bms_page_shell_service::begin($uuid, (int)$USER->id, 'organization', get_string('positionos_title', 'local_kbacompany'));
echo html_writer::link(new moodle_url('/local/kbacompany/organization_hub.php', ['uuid' => $uuid]),
    '← ' . get_string('orghub_title', 'local_kbacompany'), ['class' => 'btn btn-outline-primary mb-3']);
echo $OUTPUT->heading(get_string('positionos_title', 'local_kbacompany') . ': ' . format_string($data['company']['name']), 2);
echo html_writer::tag('p', get_string('positionos_intro', 'local_kbacompany'), ['class' => 'lead']);
echo local_kbacompany_organization_tabs($uuid, 'positionos', $canmanage);

$positionoptions = [];
foreach ($data['positions'] as $position) { $positionoptions[$position['uuid']] = $position['code'] . ' — ' . $position['name']; }
if ($positionoptions) {
    echo html_writer::start_tag('form', ['method' => 'get', 'class' => 'mb-4']);
    echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'uuid', 'value' => $uuid]);
    echo html_writer::label(get_string('positionos_position', 'local_kbacompany'), 'positionos_position');
    echo html_writer::select($positionoptions, 'position', $data['selected']['uuid'], false,
        ['id' => 'positionos_position', 'onchange' => 'this.form.submit()']);
    echo html_writer::end_tag('form');
}
if (!$data['selected']) {
    echo $OUTPUT->notification(get_string('positionos_nopositions', 'local_kbacompany'), 'info');
    \local_kbacompany\local\bms_page_shell_service::finish_and_exit($bmsshell);
}

$p = $data['selected'];
echo $OUTPUT->heading(get_string('positionos_jobdescription', 'local_kbacompany'), 3);
$summary = new html_table();
$summary->data = [
    [get_string('positionos_position', 'local_kbacompany'), s($p['code'] . ' — ' . $p['name'])],
    [get_string('positionos_purpose', 'local_kbacompany'), format_text($p['purpose'], FORMAT_PLAIN)],
    [get_string('positionos_ofsplace', 'local_kbacompany'), s($p['org_node_name'])],
    [get_string('positionos_ckp', 'local_kbacompany'), $data['ckp'] ? implode('<br>', array_map(static function(array $c): string {
        return s($c['name']) . ($c['acceptance'] !== '' ? '<br><small>' . s($c['acceptance']) . '</small>' : '');
    }, $data['ckp'])) : '—'],
];
echo html_writer::table($summary);

if ($data['functions']) {
    $table = new html_table();
    $table->head = [get_string('positionos_function', 'local_kbacompany'), get_string('positionos_product', 'local_kbacompany'),
        get_string('positionos_sourceprocess', 'local_kbacompany'), get_string('positionos_action', 'local_kbacompany')];
    foreach ($data['functions'] as $fn) {
        $table->data[] = [s($fn['code'] . ' — ' . $fn['name']), s($fn['product_name']),
            s($fn['source_process_name']) . ' · r' . (int)$fn['source_model_revision'], s($fn['source_action'])];
    }
    echo html_writer::table($table);
} else {
    echo $OUTPUT->notification(get_string('positionos_nofunctions', 'local_kbacompany'), 'info');
}

echo $OUTPUT->heading(get_string('positionos_workprogram', 'local_kbacompany'), 3);
foreach ($data['frequencies'] as $frequency) {
    $items = $data['work_program'][$frequency];
    if (!$items) { continue; }
    echo $OUTPUT->heading(get_string('positionos_frequency_' . $frequency, 'local_kbacompany'), 4);
    foreach ($items as $item) {
        $box = html_writer::tag('strong', s($item['action'])) . '<br>' .
            html_writer::span(get_string('positionos_product', 'local_kbacompany') . ': ' . s($item['product'])) .
            ($item['schedule'] !== '' ? '<br>' . html_writer::span(get_string('positionos_schedule', 'local_kbacompany') . ': ' . s($item['schedule'])) : '') .
            ($item['source_outdated'] ? '<br>' . html_writer::span(get_string('positionos_sourceoutdated', 'local_kbacompany'), 'text-warning') : '');
        if ($canmanage) {
            $box .= html_writer::start_tag('form', ['method' => 'post', 'class' => 'mt-2']);
            foreach (['uuid' => $uuid, 'position' => $p['uuid'], 'item' => $item['uuid'], 'revision' => $item['revision'], 'sesskey' => sesskey()] as $n => $v) {
                $box .= html_writer::empty_tag('input', ['type' => 'hidden', 'name' => $n, 'value' => $v]);
            }
            $box .= html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'action', 'value' => 'publish']);
            if ($item['status'] === 'draft') {
                $box .= html_writer::tag('button', get_string('positionos_publish', 'local_kbacompany'), ['class' => 'btn btn-sm btn-primary', 'type' => 'submit']);
            } else {
                $box .= html_writer::span(get_string('positionos_status_' . $item['status'], 'local_kbacompany'), 'badge badge-success');
            }
            $box .= html_writer::end_tag('form');
        }
        echo html_writer::div($box, 'card card-body mb-2');
    }
}
if (!array_filter($data['work_program'])) { echo $OUTPUT->notification(get_string('positionos_nowork', 'local_kbacompany'), 'info'); }

if ($canmanage && $data['unconfigured_functions']) {
    echo $OUTPUT->heading(get_string('positionos_addwork', 'local_kbacompany'), 4);
    $fnoptions = [];
    foreach ($data['unconfigured_functions'] as $fn) { $fnoptions[$fn['uuid']] = $fn['code'] . ' — ' . $fn['name']; }
    echo html_writer::start_tag('form', ['method' => 'post', 'class' => 'kbacompany-form mb-4']);
    foreach (['uuid' => $uuid, 'position' => $p['uuid'], 'sesskey' => sesskey(), 'action' => 'create'] as $n => $v) {
        echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => $n, 'value' => $v]);
    }
    echo html_writer::label(get_string('positionos_function', 'local_kbacompany'), 'posos_fn');
    echo html_writer::select($fnoptions, 'function', '', false, ['id' => 'posos_fn']);
    echo html_writer::label(get_string('positionos_frequency', 'local_kbacompany'), 'posos_freq');
    echo html_writer::select($frequencyoptions, 'frequency', 'weekly', false, ['id' => 'posos_freq']);
    echo html_writer::label(get_string('positionos_schedule', 'local_kbacompany'), 'posos_schedule');
    echo html_writer::empty_tag('input', ['id' => 'posos_schedule', 'name' => 'schedule']);
    echo html_writer::tag('button', get_string('positionos_add', 'local_kbacompany'), ['class' => 'btn btn-primary', 'type' => 'submit']);
    echo html_writer::end_tag('form');
}

echo $OUTPUT->heading(get_string('positionos_jobfolder', 'local_kbacompany'), 3);
echo html_writer::tag('p', get_string('positionos_jobfolder_help', 'local_kbacompany'));
if ($data['documents']) {
    $table = new html_table();
    $table->head = [get_string('document_code', 'local_kbacompany'), get_string('document_name', 'local_kbacompany'), get_string('document_type', 'local_kbacompany')];
    foreach ($data['documents'] as $doc) {
        $table->data[] = [s($doc['code']), html_writer::link(new moodle_url('/local/kbacompany/documents.php', ['uuid' => $uuid, 'document' => $doc['uuid']]), s($doc['title'])),
            get_string('document_type_' . $doc['type'], 'local_kbacompany')];
    }
    echo html_writer::table($table);
} else { echo $OUTPUT->notification(get_string('positionos_nodocuments', 'local_kbacompany'), 'info'); }

\local_kbacompany\local\bms_page_shell_service::finish($bmsshell);
