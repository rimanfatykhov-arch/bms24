<?php
require_once(__DIR__ . '/../../config.php');

use local_kbacompany\local\process_model_service;
use local_kbacompany\local\function_service;
use local_kbacompany\local\stp_service;

require_login();
$uuid = required_param('uuid', PARAM_RAW_TRIMMED);
$processuuid = required_param('process', PARAM_RAW_TRIMMED);
$action = optional_param('action', '', PARAM_ALPHAEXT);
$download = optional_param('download', 0, PARAM_BOOL);

$PAGE->set_context(context_system::instance());
$PAGE->set_url(new moodle_url('/local/kbacompany/stp.php', ['uuid' => $uuid, 'process' => $processuuid]));
// BMS page shell adapter selects the presentation layout.
$PAGE->set_title(get_string('stp_title', 'local_kbacompany'));
$PAGE->set_heading(get_string('stp_title', 'local_kbacompany'));
$PAGE->requires->css(new moodle_url('/local/kbacompany/styles.css'));

$source = process_model_service::context($uuid, (int)$USER->id, $processuuid);
if ($action !== '') {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        throw new moodle_exception('procmodel_postrequired', 'local_kbacompany');
    }
    require_sesskey();
    if (!$source['can_manage'] || !$source['model'] || $source['model']['status'] !== 'draft') {
        throw new required_capability_exception(context_system::instance(),
            'local/kbacompany:manageorganization', 'nopermissions', '');
    }
    if ($action === 'addraci') {
        $party = required_param('party', PARAM_RAW_TRIMMED);
        if (strpos($party, '|') === false) { throw new moodle_exception('procmodel_invalidexecutor', 'local_kbacompany'); }
        [$partytype, $partyuuid] = explode('|', $party, 2);
        process_model_service::save_raci($uuid, (int)$USER->id, $processuuid,
            (int)$source['model']['id'], (int)$source['model']['change_version'],
            required_param('activityuuid', PARAM_RAW_TRIMMED), $partytype, $partyuuid,
            required_param('racirole', PARAM_ALPHA));
    } else if ($action === 'removeraci') {
        process_model_service::remove_raci($uuid, (int)$USER->id, $processuuid,
            (int)$source['model']['id'], (int)$source['model']['change_version'],
            required_param('raciuuid', PARAM_RAW_TRIMMED));
    } else {
        throw new moodle_exception('invalidparameter');
    }
    redirect(new moodle_url('/local/kbacompany/stp.php', ['uuid' => $uuid, 'process' => $processuuid]),
        get_string('stp_saved', 'local_kbacompany'), null, \core\output\notification::NOTIFY_SUCCESS);
}

$stp = stp_service::context($uuid, (int)$USER->id, $processuuid);
$source = process_model_service::context($uuid, (int)$USER->id, $processuuid);
$functioncontext = function_service::context($uuid, (int)$USER->id);
$functionsbyactivity = [];
foreach ($functioncontext['functions'] as $function) {
    $functionsbyactivity[$function['source_activity_uuid']] = $function;
}

$names = static function(array $items): string {
    $values = [];
    foreach ($items as $item) {
        $value = (string)($item['target_name'] ?? $item['name'] ?? '');
        if ($value !== '') { $values[] = $value; }
    }
    return implode('; ', $values);
};
$annames = static function(array $items): string {
    return implode('; ', array_values(array_filter(array_map(static function(array $item): string {
        return (string)($item['name'] ?? '');
    }, $items))));
};

if ($download) {
    $esc = static function($value): string { return htmlspecialchars((string)$value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); };
    $filename = preg_replace('/[^A-Za-z0-9._-]+/', '_', $stp['document']['code'] . '-r' . $stp['document']['revision']) . '.html';
    header('Content-Type: text/html; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    echo '<!doctype html><html lang="ru"><head><meta charset="utf-8"><title>' . $esc($stp['document']['code']) . '</title>';
    echo '<style>body{font-family:Arial,sans-serif;margin:32px;color:#222}table{border-collapse:collapse;width:100%;margin:16px 0}th,td{border:1px solid #999;padding:7px;vertical-align:top}h1,h2{margin-top:24px}.meta{color:#555}</style></head><body>';
    echo '<h1>' . $esc($stp['document']['code']) . ' — ' . $esc($stp['document']['title']) . '</h1>';
    echo '<p class="meta">Revision ' . (int)$stp['document']['revision'] . ' · ' . $esc($stp['document']['status']) . '</p>';
    echo '<h2>SIPOC</h2><table><tr><th>Supplier</th><th>Input</th><th>Process</th><th>Output</th><th>Customer</th></tr><tr>';
    echo '<td>' . $esc($names($stp['sipoc']['suppliers'])) . '</td><td>' . $esc($names($stp['sipoc']['inputs'])) . '</td>';
    echo '<td>' . $esc($stp['process']['name']) . '</td><td>' . $esc($names($stp['sipoc']['outputs'])) . '</td><td>' . $esc($names($stp['sipoc']['customers'])) . '</td></tr></table>';
    echo '<h2>Procedure & RACI</h2><table><tr><th>#</th><th>Action</th><th>Product</th><th>R</th><th>A</th><th>C</th><th>I</th><th>Controls / records</th></tr>';
    foreach ($stp['steps'] as $step) {
        echo '<tr><td>' . (int)$step['number'] . '</td><td><strong>' . $esc($step['activity']) . '</strong><br>' . $esc($step['action']) . '</td>';
        echo '<td>' . $esc($step['product']) . '</td>';
        foreach (['R','A','C','I'] as $role) { echo '<td>' . $esc(implode('; ', $step['raci'][$role])) . '</td>'; }
        echo '<td>' . $esc($annames(array_merge($step['controls'], $step['records']))) . '</td></tr>';
    }
    echo '</table></body></html>';
    exit;
}

$bmsshell = \local_kbacompany\local\bms_page_shell_service::begin($uuid, (int)$USER->id, 'documents', get_string('stp_title', 'local_kbacompany'));
echo html_writer::div(html_writer::link(new moodle_url('/local/kbacompany/process_design.php', [
    'uuid' => $uuid, 'process' => $processuuid,
]), '← ' . get_string('procmodel_title', 'local_kbacompany')), 'mb-3');
echo $OUTPUT->heading(format_string($stp['document']['code'] . ' — ' . $stp['document']['title']), 2);
echo html_writer::div(get_string('stp_generatednotice', 'local_kbacompany'), 'lead mb-3');
echo html_writer::div(get_string('procmodel_revision', 'local_kbacompany') . ' ' . (int)$stp['document']['revision'] .
    ' · ' . get_string('procmodel_status_' . $stp['document']['status'], 'local_kbacompany'), 'badge badge-secondary mb-3');
echo html_writer::link(new moodle_url('/local/kbacompany/stp.php', [
    'uuid' => $uuid, 'process' => $processuuid, 'download' => 1,
]), get_string('stp_exporthtml', 'local_kbacompany'), ['class' => 'btn btn-outline-secondary mb-3']);
if ($source['can_manage'] && $stp['model']['status'] === 'published') {
    echo ' ' . html_writer::link(new moodle_url('/local/kbacompany/documents.php', [
        'uuid' => $uuid, 'source' => 'stp', 'process' => $processuuid,
    ]), get_string('document_controlstp', 'local_kbacompany'), ['class' => 'btn btn-outline-primary mb-3']);
}

$meta = new html_table();
$meta->data = [
    [get_string('process_purpose', 'local_kbacompany'), format_text($stp['purpose'], FORMAT_PLAIN)],
    [get_string('procmodel_scope', 'local_kbacompany'), format_text($stp['scope'], FORMAT_PLAIN)],
    [get_string('procmodel_trigger', 'local_kbacompany'), format_text($stp['trigger'], FORMAT_PLAIN)],
    [get_string('process_owner', 'local_kbacompany'), format_string($stp['process']['owner_name'])],
    [get_string('process_product', 'local_kbacompany'), format_string($stp['process']['product_name'])],
];
echo html_writer::table($meta);

echo $OUTPUT->heading('SIPOC', 3);
$sipoc = new html_table();
$sipoc->head = ['Supplier', 'Input', 'Process', 'Output', 'Customer'];
$sipoc->data[] = [$names($stp['sipoc']['suppliers']), $names($stp['sipoc']['inputs']),
    format_string($stp['process']['name']), $names($stp['sipoc']['outputs']), $names($stp['sipoc']['customers'])];
echo html_writer::table($sipoc);

echo $OUTPUT->heading(get_string('stp_procedure', 'local_kbacompany'), 3);
$table = new html_table();
$table->head = ['#', get_string('procmodel_action', 'local_kbacompany'), get_string('procmodel_output', 'local_kbacompany'),
    'R', 'A', 'C', 'I', get_string('stp_controlsrecords', 'local_kbacompany'), get_string('function_title', 'local_kbacompany')];
foreach ($stp['steps'] as $step) {
    if (isset($functionsbyactivity[$step['activity_uuid']])) {
        $function = $functionsbyactivity[$step['activity_uuid']];
        $functioncell = html_writer::link(new moodle_url('/local/kbacompany/functions.php', [
            'uuid' => $uuid, 'function' => $function['uuid'],
        ]), format_string($function['code'] . ' — ' . $function['name']));
    } else if ($source['can_manage']) {
        $functioncell = html_writer::link(new moodle_url('/local/kbacompany/functions.php', [
            'uuid' => $uuid, 'process' => $processuuid, 'activity' => $step['activity_uuid'],
        ]), get_string('function_createfromactivity', 'local_kbacompany'));
    } else {
        $functioncell = '—';
    }
    $table->data[] = [(int)$step['number'], format_string($step['activity']) . html_writer::div(format_text($step['action'], FORMAT_PLAIN), 'small'),
        format_string($step['product']), implode(', ', $step['raci']['R']), implode(', ', $step['raci']['A']),
        implode(', ', $step['raci']['C']), implode(', ', $step['raci']['I']),
        $annames(array_merge($step['controls'], $step['records'])), $functioncell];
}
echo html_writer::table($table);

// RACI administration is version-scoped and only available on a working draft.
if ($source['can_manage'] && $source['model'] && $source['model']['status'] === 'draft') {
    $partyoptions = [];
    foreach ($source['orgunits'] as $item) { $partyoptions['orgunit|' . $item['uuid']] = get_string('procmodel_exec_orgunit', 'local_kbacompany') . ': ' . $item['name']; }
    foreach ($source['positions'] as $item) { $partyoptions['position|' . $item['uuid']] = get_string('procmodel_exec_position', 'local_kbacompany') . ': ' . $item['name']; }
    foreach ($source['roles'] as $item) { $partyoptions['role|' . $item['uuid']] = get_string('procmodel_exec_role', 'local_kbacompany') . ': ' . $item['name']; }
    $taskoptions = [];
    foreach ($source['bpmn']['nodes'] as $node) { if ($node['type'] === 'task') { $taskoptions[$node['uuid']] = $node['name']; } }
    if ($taskoptions && $partyoptions) {
        echo $OUTPUT->heading(get_string('stp_raciedit', 'local_kbacompany'), 3);
        echo html_writer::start_tag('form', ['method' => 'post', 'class' => 'kbacompany-form mb-3']);
        foreach (['uuid' => $uuid, 'process' => $processuuid, 'sesskey' => sesskey(), 'action' => 'addraci'] as $key => $value) {
            echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => $key, 'value' => $value]);
        }
        echo html_writer::label(get_string('stp_activity', 'local_kbacompany'), 'raci_activity');
        echo html_writer::select($taskoptions, 'activityuuid', '', false, ['id' => 'raci_activity', 'required' => 'required']);
        echo html_writer::label(get_string('stp_racirole', 'local_kbacompany'), 'raci_role');
        echo html_writer::select(['R'=>'R — Responsible','A'=>'A — Accountable','C'=>'C — Consulted','I'=>'I — Informed'], 'racirole', 'A', false, ['id'=>'raci_role']);
        echo html_writer::label(get_string('stp_party', 'local_kbacompany'), 'raci_party');
        echo html_writer::select($partyoptions, 'party', '', false, ['id' => 'raci_party', 'required' => 'required']);
        echo html_writer::tag('button', get_string('add'), ['type'=>'submit','class'=>'btn btn-outline-primary']);
        echo html_writer::end_tag('form');
    }
    if ($source['raci']) {
        $rt = new html_table();
        $rt->head = [get_string('stp_activity', 'local_kbacompany'), get_string('stp_racirole', 'local_kbacompany'), get_string('stp_party', 'local_kbacompany'), ''];
        $taskmap = [];
        foreach ($source['bpmn']['nodes'] as $node) { $taskmap[$node['uuid']] = $node['name']; }
        foreach ($source['raci'] as $assignment) {
            $remove = html_writer::start_tag('form', ['method'=>'post','class'=>'kbacompany-inline-form']);
            foreach (['uuid'=>$uuid,'process'=>$processuuid,'sesskey'=>sesskey(),'action'=>'removeraci','raciuuid'=>$assignment['uuid']] as $key=>$value) {
                $remove .= html_writer::empty_tag('input',['type'=>'hidden','name'=>$key,'value'=>$value]);
            }
            $remove .= html_writer::tag('button','×',['type'=>'submit','class'=>'btn btn-sm btn-link text-danger']);
            $remove .= html_writer::end_tag('form');
            $rt->data[] = [format_string($taskmap[$assignment['activity_uuid']] ?? $assignment['activity_uuid']), s($assignment['role']), format_string($assignment['party_name']), $remove];
        }
        echo html_writer::table($rt);
    }
}

foreach ([
    'stp_metrics' => $stp['metrics'],
    'stp_resources' => $stp['process_resources'],
    'stp_documents' => $stp['process_documents'],
    'stp_systems' => $stp['process_systems'],
    'stp_records' => $stp['process_records'],
    'stp_controls' => $stp['process_controls'],
] as $label => $items) {
    echo $OUTPUT->heading(get_string($label, 'local_kbacompany'), 4);
    echo html_writer::div($items ? format_string($label === 'stp_metrics' ? $names($items) : $annames($items)) : '—', 'mb-3');
}

\local_kbacompany\local\bms_page_shell_service::finish($bmsshell);
