<?php
require_once(__DIR__ . '/../../config.php');
use local_kbacompany\local\flow_service;
use local_kbacompany\local\cascade_service;
require_login();
require_capability('local/kbacompany:viewowncompany', context_system::instance());
$uuid = required_param('uuid', PARAM_RAW_TRIMMED);
$object = optional_param('object', '', PARAM_RAW_TRIMMED);
$data = flow_service::context($uuid, (int)$USER->id, $object);
$action = optional_param('action', '', PARAM_ALPHA);
$error = '';
$input = [];
if ($action !== '') {
    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') { throw new moodle_exception('productpostrequired', 'local_kbacompany'); }
    require_sesskey();
    foreach (['code', 'name', 'fromuuid', 'touuid', 'productuuid', 'criteria', 'handoff', 'revision', 'reason'] as $key) {
        $input[$key] = optional_param($key, '', PARAM_RAW_TRIMMED);
    }
    try {
        $id = flow_service::command($uuid, (int)$USER->id, $action, $input + ['object' => $object]);
        redirect(new moodle_url('/local/kbacompany/flows.php', ['uuid' => $uuid, 'object' => $id]), get_string('productsaved', 'local_kbacompany'));
    } catch (moodle_exception $e) {
        if ($e->module !== 'local_kbacompany') { throw $e; }
        $error = $e->getMessage();
    }
}
$PAGE->set_context(context_system::instance());
$PAGE->set_url(new moodle_url('/local/kbacompany/flows.php', ['uuid' => $uuid, 'object' => $object]));
// BMS page shell adapter selects the presentation layout.
$PAGE->set_title(get_string('flow_title', 'local_kbacompany'));
$PAGE->set_heading(get_string('pluginname', 'local_kbacompany'));
$str = static function(string $key): string { return get_string('flow_' . $key, 'local_kbacompany'); };
$bmsshell = \local_kbacompany\local\bms_page_shell_service::begin($uuid, (int)$USER->id, 'flows', get_string('flow_title', 'local_kbacompany'));
echo html_writer::link(new moodle_url('/local/kbacompany/bms_workspace.php', ['uuid' => $uuid]),
    '← ' . get_string('backtocompany', 'local_kbacompany'), ['class' => 'btn btn-outline-primary mb-3']);
echo $OUTPUT->heading($str('title') . ': ' . s($data['company']['name']));
echo html_writer::tag('p', $str('intro'));
if ($error !== '') { echo $OUTPUT->notification(s($error), 'error'); }
$functions = [];
foreach (cascade_service::context($uuid, (int)$USER->id)['objects'] as $f) {
    if ($f->kind === 'function' && $f->status === 'draft') { $functions[$f->uuid] = $f->code . ' — ' . $f->name; }
}
$products = [];
foreach ($DB->get_records('local_kbacompany_product', ['companyuuid' => $uuid], 'code ASC') as $p) {
    $products[$p->uuid] = $p->code . ' — ' . $p->name;
}
$table = new html_table();
$table->head = [$str('name'), $str('fromuuid'), $str('productuuid'), $str('touuid'), $str('status')];
foreach ($data['flows'] as $f) {
    $snapshot = json_decode($f->snapshot, true);
    $label = static function($key) use ($snapshot, $f, $functions): string {
        $obj = $snapshot[$key]['bundle']['object'] ?? null;
        return $obj ? $obj['code'] . ' — ' . $obj['name'] : ($functions[$f->$key] ?? '—');
    };
    $table->data[] = [html_writer::link(new moodle_url('/local/kbacompany/flows.php',
        ['uuid' => $uuid, 'object' => $f->uuid]), s($f->code . ' — ' . $f->name)),
        s($label('fromuuid')), s($products[$f->productuuid] ?? '—'), s($label('touuid')),
        $str($f->status) . ($f->basiscurrent === false ? ' · ' . $str('stale') : '')];
}
echo html_writer::table($table);
$selected = $data['selected'];
if ($selected) {
    echo $OUTPUT->heading(s($selected->code . ' — ' . $selected->name), 3);
    echo html_writer::tag('p', $str('handoff') . ': ' . s($selected->handoff));
    echo html_writer::tag('p', $str('criteria') . ': ' . s($selected->criteria));
    // Legacy source UUIDs remain in historical flow data, but the obsolete
    // Administrative Cascade is no longer exposed as a customer-facing destination.
    foreach (['fromuuid', 'touuid'] as $field) {
        echo html_writer::span($str($field), 'badge badge-light border mr-2 mb-3');
    }
    if ($selected->basiscurrent === false) { echo $OUTPUT->notification($str('stalehelp'), 'warning'); }
}
if ($data['can_manage']) {
    $formstart = static function(string $action) use ($uuid, $object, $selected): void {
        echo html_writer::start_tag('form', ['method' => 'post', 'class' => 'kbacompany-form mb-3']);
        foreach (['uuid' => $uuid, 'object' => $object, 'action' => $action, 'sesskey' => sesskey(),
                'revision' => $selected->revision ?? 0] as $name => $value) {
            echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => $name, 'value' => $value]);
        }
    };
    $formend = static function(string $title): void {
        echo html_writer::tag('button', $title, ['type' => 'submit', 'class' => 'btn btn-primary']);
        echo html_writer::end_tag('form');
    };
    echo html_writer::link(new moodle_url('/local/kbacompany/flows.php', ['uuid' => $uuid]), $str('new'), ['class' => 'btn btn-outline-primary mb-3']);
    if (!$selected || $selected->status === 'draft') {
        $formstart('save');
        foreach (['code', 'name', 'fromuuid', 'productuuid', 'touuid', 'handoff', 'criteria'] as $field) {
            $value = $input[$field] ?? ($selected->$field ?? '');
            $attrs = ['id' => 'flow_' . $field, 'name' => $field, 'required' => 'required'];
            echo html_writer::label($str($field), $attrs['id']);
            if (in_array($field, ['fromuuid', 'touuid', 'productuuid'], true)) {
                echo html_writer::select($field === 'productuuid' ? $products : $functions, $field, $value, ['' => '—'], $attrs);
            } else if (in_array($field, ['handoff', 'criteria'], true)) {
                echo html_writer::tag('textarea', s($value), $attrs + ['rows' => 3, 'maxlength' => 4000]);
            } else { echo html_writer::empty_tag('input', $attrs + ['type' => 'text', 'value' => $value, 'maxlength' => $field === 'code' ? 50 : 255]); }
        }
        $formend($str('save'));
        if ($selected) {
            echo html_writer::tag('p', $str('publishhelp'));
            $formstart('publish');
            $formend($str('publish'));
        }
    }
    if ($selected && $selected->status !== 'archived') {
        $formstart('archive');
        echo html_writer::label($str('reason'), 'flow_reason');
        echo html_writer::tag('textarea', '', ['id' => 'flow_reason', 'name' => 'reason', 'required' => 'required', 'maxlength' => 4000]);
        $formend($str('archive'));
    }
    if ($data['history']) {
        echo $OUTPUT->heading($str('history'), 3);
        foreach ($data['history'] as $entry) {
            $record = json_decode($entry->newvalue ?? 'null', true);
            $key = substr($entry->action, 5);
            echo html_writer::tag('p', s(userdate($entry->timecreated)) . ' · ' . $str($key) . ' · ' . s($record['reason'] ?? ''));
        }
    }
}
\local_kbacompany\local\bms_page_shell_service::finish($bmsshell);
