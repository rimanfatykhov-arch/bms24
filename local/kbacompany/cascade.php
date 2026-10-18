<?php
require_once(__DIR__ . '/../../config.php');

use local_kbacompany\local\cascade_service;

require_login();
$context = context_system::instance();
require_capability('local/kbacompany:viewowncompany', $context);
$uuid = required_param('uuid', PARAM_RAW_TRIMMED);
$objectuuid = optional_param('object', '', PARAM_RAW_TRIMMED);
$data = cascade_service::context($uuid, (int)$USER->id);
$objects = [];
foreach ($data['objects'] as $obj) {
    $objects[$obj->uuid] = $obj;
}
if ($objectuuid !== '' && !isset($objects[$objectuuid])) {
    throw new moodle_exception('ac_reference', 'local_kbacompany');
}
$selected = $objects[$objectuuid] ?? null;
$kind = $selected ? $selected->kind : optional_param('kind', 'intent', PARAM_ALPHA);
$definitions = cascade_service::fields($kind);
$url = new moodle_url('/local/kbacompany/cascade.php', ['uuid' => $uuid, 'object' => $objectuuid, 'kind' => $kind]);
$values = $selected ? array_merge((array)$selected, $selected->data) : [];
$error = '';
$action = optional_param('action', '', PARAM_ALPHA);
if ($action !== '') {
    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
        throw new moodle_exception('productpostrequired', 'local_kbacompany');
    }
    require_sesskey();
    require_capability('local/kbacompany:manageorganization', $context);
    try {
        if ($action === 'save') {
            foreach (array_merge(['code', 'name', 'description', 'parentuuid'], array_keys($definitions)) as $key) {
                $values[$key] = optional_param($key, '', PARAM_RAW_TRIMMED);
            }
            $objectuuid = cascade_service::save($uuid, (int)$USER->id, $kind, $values, $objectuuid,
                required_param('revision', PARAM_INT));
        } else if ($action === 'contribute' || $action === 'unlink') {
            cascade_service::contribute($uuid, (int)$USER->id,
                required_param('plan', PARAM_RAW_TRIMMED), required_param('function', PARAM_RAW_TRIMMED),
                $action === 'unlink');
        } else if ($action === 'archive') {
            cascade_service::archive($uuid, (int)$USER->id, $objectuuid, required_param('revision', PARAM_INT));
        } else {
            throw new moodle_exception('ac_invalid', 'local_kbacompany');
        }
        redirect(new moodle_url('/local/kbacompany/cascade.php', ['uuid' => $uuid, 'object' => $objectuuid]),
            get_string('productsaved', 'local_kbacompany'));
    } catch (moodle_exception $e) {
        if ($e->module !== 'local_kbacompany') {
            throw $e;
        }
        $error = $e->getMessage();
    }
}
$PAGE->set_context($context);
$PAGE->set_url($url);
// BMS page shell adapter selects the presentation layout.
$PAGE->set_title(get_string('ac_title', 'local_kbacompany'));
$PAGE->set_heading(get_string('pluginname', 'local_kbacompany'));
$bmsshell = \local_kbacompany\local\bms_page_shell_service::begin($uuid, (int)$USER->id, 'cascade', get_string('ac_title', 'local_kbacompany'));
echo html_writer::link(new moodle_url('/local/kbacompany/execution.php', ['uuid' => $uuid]),
    get_string('wf_title', 'local_kbacompany'), ['class' => 'btn btn-primary mb-3']);
$str = static function(string $key): string { return get_string('ac_' . $key, 'local_kbacompany'); };
$link = static function($obj) use ($uuid): string {
    return html_writer::link(new moodle_url('/local/kbacompany/cascade.php',
        ['uuid' => $uuid, 'object' => $obj->uuid]), s($obj->code . ' — ' . $obj->name));
};
echo html_writer::link(new moodle_url('/local/kbacompany/bms_workspace.php', ['uuid' => $uuid]),
    '← ' . get_string('backtocompany', 'local_kbacompany'));
echo $OUTPUT->heading($str('title') . ': ' . s($data['company']['name']));
echo $OUTPUT->notification(get_string('wf_approvalhint', 'local_kbacompany'), 'info');
if ($error !== '') {
    echo $OUTPUT->notification(s($error), 'error');
}
echo html_writer::tag('p', $str('workflow'));
echo html_writer::link(new moodle_url('/local/kbacompany/indicators.php', ['uuid' => $uuid]),
    get_string('productsstats', 'local_kbacompany'), ['class' => 'btn btn-outline-primary mb-3']);
$options = [];
foreach (['node' => 'local_kbacompany_orgnode', 'position' => 'local_kbacompany_position',
        'statistic' => 'local_kbacompany_stat', 'product' => 'local_kbacompany_product'] as $type => $table) {
    $options[$type] = [];
    foreach ($DB->get_records($table, ['companyuuid' => $uuid], 'code ASC') as $ref) {
        $options[$type][$ref->uuid] = $ref->code . ' — ' . $ref->name;
    }
}
foreach (cascade_service::KINDS as $type) {
    echo html_writer::link(new moodle_url('/local/kbacompany/cascade.php', ['uuid' => $uuid, 'kind' => $type]),
        $str('kind_' . $type), ['class' => 'btn btn-outline-secondary mr-2 mb-2']);
}
$table = new html_table();
$table->head = [$str('object'), $str('kind'), $str('parent'), $str('status'), $str('gaps')];
foreach ($objects as $obj) {
    $gaps = [];
    foreach ($data['gaps'][$obj->uuid] ?? [] as $gap => $missing) {
        if ($missing) {
            $gaps[] = $str($gap);
        }
    }
    $table->data[] = [$link($obj), $str('kind_' . $obj->kind),
        isset($objects[$obj->parentuuid]) ? $link($objects[$obj->parentuuid]) : '—',
        $str($obj->status), implode('; ', $gaps)];
}
echo $objects ? html_writer::table($table) : $OUTPUT->notification($str('empty'), 'info');

$startform = static function(string $action, array $extra = []) use ($uuid, $objectuuid, $kind): void {
    echo html_writer::start_tag('form', ['method' => 'post', 'class' => 'kbacompany-form mb-3']);
    foreach (array_merge(['uuid' => $uuid, 'object' => $objectuuid, 'kind' => $kind,
            'sesskey' => sesskey(), 'action' => $action], $extra) as $key => $value) {
        echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => $key, 'value' => $value]);
    }
};
$endform = static function(string $label): void {
    echo html_writer::tag('button', $label, ['type' => 'submit', 'class' => 'btn btn-primary']);
    echo html_writer::end_tag('form');
};
if ($selected) {
    echo $OUTPUT->heading(s($selected->code . ' — ' . $selected->name), 3);
    echo html_writer::link(new moodle_url('/local/kbacompany/execution.php',
        ['uuid' => $uuid, 'basis' => $selected->uuid]), get_string('wf_relatedwork', 'local_kbacompany'),
        ['class' => 'btn btn-primary mb-3']);
    echo html_writer::tag('p', $str($selected->status) . ' · ' . $str('revision') . ': ' . (int)$selected->revision);
    echo format_text($selected->description, FORMAT_PLAIN);
    $details = new html_table();
    $details->head = [$str('field'), $str('value')];
    foreach ($definitions as $field => [$type, $required]) {
        $value = $selected->data[$field];
        $display = $value === null ? '—' : ($options[$type][$value] ?? $value);
        $cell = s($display);
        if ($type === 'statistic' && $value !== null) {
            $cell = html_writer::link(new moodle_url('/local/kbacompany/indicators.php',
                ['uuid' => $uuid, 'stat' => $value]), $cell);
        }
        $details->data[] = [$str('field_' . $field), $cell];
    }
    echo html_writer::table($details);
    echo $OUTPUT->heading($str('children'), 4);
    foreach ($objects as $child) {
        if ($child->parentuuid === $selected->uuid) {
            echo html_writer::tag('p', $link($child) . ' · ' . $str($child->status));
        }
    }
    echo $OUTPUT->heading($str('contributions'), 4);
    foreach ($data['links'] as $relation) {
        if ($relation->planuuid !== $objectuuid && $relation->functionuuid !== $objectuuid) {
            continue;
        }
        echo html_writer::tag('p', $link($objects[$relation->planuuid]) . ' → ' . $link($objects[$relation->functionuuid]));
        if ($data['can_manage']) {
            $startform('unlink', ['plan' => $relation->planuuid, 'function' => $relation->functionuuid]);
            $endform($str('unlink'));
        }
    }
    echo html_writer::start_tag('details', ['class' => 'mb-3']);
    echo html_writer::tag('summary', $str('history'));
    $history = new html_table();
    $history->head = [$str('date'), $str('action'), $str('actor'), $str('revision'), $str('changes')];
    foreach (cascade_service::history($uuid, (int)$USER->id, $objectuuid) as $entry) {
        $snapshot = json_decode($entry->newvalue ?? 'null');
        $previous = json_decode($entry->oldvalue ?? 'null');
        $changes = [];
        if (isset($snapshot->revision) || isset($previous->revision)) {
            $oldfields = $previous ? array_merge((array)$previous, json_decode($previous->payload ?? '{}', true) ?? []) : [];
            $newfields = $snapshot ? array_merge((array)$snapshot, json_decode($snapshot->payload ?? '{}', true) ?? []) : [];
            foreach (array_merge(['code', 'name', 'description', 'parentuuid'], array_keys($definitions)) as $field) {
                $before = $oldfields[$field] ?? null;
                $after = $newfields[$field] ?? null;
                if ($before !== $after) {
                    $changes[] = s($str('field_' . $field) . ': ' . ($before ?? '—') . ' → ' . ($after ?? '—'));
                }
            }
        }
        $actionlabel = strpos($entry->action, 'wf_') === 0
            ? get_string('wf_action_' . substr($entry->action, 3), 'local_kbacompany') : $str('action_' . $entry->action);
        $history->data[] = [userdate($entry->timecreated), $actionlabel,
            $entry->actorid ? (int)$entry->actorid : '—', isset($snapshot->revision) ? (int)$snapshot->revision : '—',
            implode(html_writer::empty_tag('br'), $changes)];
    }
    echo html_writer::table($history);
    echo html_writer::end_tag('details');
}
if ($data['can_manage'] && (!$selected || $selected->status === 'draft')) {
    echo $OUTPUT->heading($str($selected ? 'edit' : 'create') . ': ' . $str('kind_' . $kind), 3);
    $startform('save', ['revision' => $selected ? $selected->revision : 0]);
    $formfields = ['code' => ['input', true], 'name' => ['input', true], 'description' => ['text', false]];
    if (cascade_service::PARENTS[$kind] !== '') {
        $formfields['parentuuid'] = ['parent', $kind !== 'function'];
        $options['parent'] = [];
        foreach ($objects as $obj) {
            if ($obj->kind === cascade_service::PARENTS[$kind] && $obj->status === 'draft' && $obj->uuid !== $objectuuid) {
                $options['parent'][$obj->uuid] = $obj->code . ' — ' . $obj->name;
            }
        }
    }
    foreach (array_merge($formfields, $definitions) as $field => [$type, $required]) {
        $id = 'ac_' . $field;
        echo html_writer::label($str('field_' . $field) . ($required ? ' *' : ''), $id);
        $attrs = ['id' => $id, 'name' => $field];
        if ($required) {
            $attrs['required'] = 'required';
        }
        $value = $values[$field] ?? '';
        if (isset($options[$type])) {
            echo html_writer::select($options[$type], $field, $value, ['' => '—'], $attrs);
        } else if ($type === 'text') {
            echo html_writer::tag('textarea', s($value), $attrs + ['rows' => 3, 'maxlength' => 4000]);
        } else {
            $attrs['type'] = in_array($type, ['date', 'number'], true) ? $type : 'text';
            $attrs['value'] = $value;
            if ($type === 'number') {
                $attrs['step'] = '0.000001';
            }
            echo html_writer::empty_tag('input', $attrs);
        }
    }
    $endform($str('save'));
    if ($selected) {
        $startform('archive', ['revision' => $selected->revision]);
        echo html_writer::tag('p', $str('archivehint'));
        $endform($str('archive'));
    }
}
if ($data['can_manage']) {
    $plans = $functions = [];
    foreach ($objects as $obj) {
        if ($obj->status !== 'draft') {
            continue;
        }
        if ($obj->kind === 'strategy') {
            $plans[$obj->uuid] = $obj->code . ' — ' . $obj->name;
        } else if ($obj->kind === 'function') {
            $functions[$obj->uuid] = $obj->code . ' — ' . $obj->name;
        }
    }
    if ($plans && $functions) {
        echo $OUTPUT->heading($str('contributions'), 3);
        $startform('contribute');
        echo html_writer::label($str('kind_strategy'), 'ac_plan');
        echo html_writer::select($plans, 'plan', $objectuuid, false, ['id' => 'ac_plan']);
        echo html_writer::label($str('kind_function'), 'ac_function');
        echo html_writer::select($functions, 'function', $objectuuid, false, ['id' => 'ac_function']);
        $endform($str('link'));
    }
}
\local_kbacompany\local\bms_page_shell_service::finish($bmsshell);
