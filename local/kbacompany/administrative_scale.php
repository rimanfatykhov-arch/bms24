<?php
require_once(__DIR__ . '/../../config.php');

use local_kbacompany\local\administrative_scale_service;
use local_kbacompany\local\administrative_scale_ui_service;
use local_kbacompany\local\battle_plan_service;
use local_kbacompany\local\bms_collection_view_service;

require_login();
$context = context_system::instance();
require_capability('local/kbacompany:viewowncompany', $context);
$uuid = required_param('uuid', PARAM_RAW_TRIMMED);
$data = administrative_scale_service::context($uuid, (int)$USER->id);

$viewkind = optional_param('viewkind', 'overview', PARAM_ALPHANUMEXT);
$view = bms_collection_view_service::normalise_view(optional_param('view', 'list', PARAM_ALPHA));
$q = optional_param('q', '', PARAM_RAW_TRIMMED);
$validviews = array_merge(['overview'], $data['kinds']);
if (!in_array($viewkind, $validviews, true)) {
    $viewkind = 'overview';
}

$PAGE->set_context($context);
$PAGE->set_url(new moodle_url('/local/kbacompany/administrative_scale.php', ['uuid' => $uuid, 'viewkind' => $viewkind]));
$PAGE->set_title(get_string('adminscale_title', 'local_kbacompany'));
$PAGE->set_heading(get_string('pluginname', 'local_kbacompany'));

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_sesskey();
    if (!$data['can_manage']) {
        throw new required_capability_exception($context, 'local/kbacompany:manageorganization', 'nopermissions', '');
    }
    $action = required_param('action', PARAM_ALPHANUMEXT);
    $item = optional_param('item', '', PARAM_RAW_TRIMMED);
    $revision = optional_param('revision', 0, PARAM_INT);
    $returnkind = optional_param('returnkind', $viewkind, PARAM_ALPHANUMEXT);
    if (!in_array($returnkind, $validviews, true)) { $returnkind = 'overview'; }

    $redirectparams = ['uuid' => $uuid, 'viewkind' => $returnkind];
    if ($action === 'save') {
        $saveduuid = administrative_scale_service::save($uuid, (int)$USER->id, [
            'kind' => required_param('kind', PARAM_ALPHANUMEXT),
            'name' => required_param('name', PARAM_RAW_TRIMMED),
            'description' => optional_param('description', '', PARAM_RAW_TRIMMED),
            'situation' => optional_param('situation', '', PARAM_RAW_TRIMMED),
            'plangoal' => optional_param('plangoal', '', PARAM_RAW_TRIMMED),
            'expectedresult' => optional_param('expectedresult', '', PARAM_RAW_TRIMMED),
            'parentuuid' => optional_param('parentuuid', '', PARAM_RAW_TRIMMED),
            'sourceuuid' => optional_param('sourceuuid', '', PARAM_RAW_TRIMMED),
            'ownerpositionuuid' => optional_param('ownerpositionuuid', '', PARAM_RAW_TRIMMED),
            'productuuid' => optional_param('productuuid', '', PARAM_RAW_TRIMMED),
            'metricuuid' => optional_param('metricuuid', '', PARAM_RAW_TRIMMED),
            'targetvalue' => optional_param('targetvalue', '', PARAM_RAW_TRIMMED),
            'startdate' => optional_param('startdate', '', PARAM_RAW_TRIMMED),
            'duedate' => optional_param('duedate', '', PARAM_RAW_TRIMMED),
            'sortorder' => optional_param('sortorder', 0, PARAM_INT),
        ], $item, $revision);
        $redirectparams['detailuuid'] = $saveduuid;
    } else if ($action === 'publish') {
        administrative_scale_service::publish($uuid, (int)$USER->id, $item, $revision);
        $redirectparams['detailuuid'] = $item;
    } else if ($action === 'archive') {
        administrative_scale_service::archive($uuid, (int)$USER->id, $item, $revision);
    } else if ($action === 'sendtobattleplan') {
        $planuuid = required_param('planuuid', PARAM_RAW_TRIMMED);
        battle_plan_service::add_from_admin_scale($uuid, (int)$USER->id, $planuuid, $item);
        $redirectparams['detailuuid'] = $item;
    } else {
        throw new moodle_exception('invalidparameter');
    }
    redirect(new moodle_url('/local/kbacompany/administrative_scale.php', $redirectparams),
        $action === 'sendtobattleplan'
            ? get_string('adminscale_senttobattleplan', 'local_kbacompany')
            : get_string('adminscale_saved', 'local_kbacompany'));
}

$edituuid = optional_param('edituuid', '', PARAM_RAW_TRIMMED);
$detailuuid = optional_param('detailuuid', '', PARAM_RAW_TRIMMED);
$creating = optional_param('create', 0, PARAM_BOOL);
$recordtab = optional_param('recordtab', 'description', PARAM_ALPHA);
if (!in_array($recordtab, ['description', 'execution'], true)) { $recordtab = 'description'; }
$editing = null;
$detail = null;
foreach ($data['items'] as $candidate) {
    if ($edituuid !== '' && $candidate['uuid'] === $edituuid) {
        $editing = $candidate;
        $viewkind = $candidate['kind'];
    }
    if ($detailuuid !== '' && $candidate['uuid'] === $detailuuid) {
        $detail = $candidate;
        $viewkind = $candidate['kind'];
    }
}
if ($edituuid !== '' && !$editing) {
    throw new moodle_exception('invalidparameter');
}
if ($detailuuid !== '' && !$detail) {
    throw new moodle_exception('invalidparameter');
}

$hidden = static function(string $action, string $returnkind, ?array $row = null) use ($uuid): string {
    $html = html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'uuid', 'value' => $uuid]);
    $html .= html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'sesskey', 'value' => sesskey()]);
    $html .= html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'action', 'value' => $action]);
    $html .= html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'returnkind', 'value' => $returnkind]);
    if ($row) {
        $html .= html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'item', 'value' => $row['uuid']]);
        $html .= html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'revision', 'value' => $row['revision']]);
    }
    return $html;
};
$input = static function(string $name, string $value = '', string $type = 'text', bool $required = false): string {
    $attrs = ['type' => $type, 'id' => 'adminscale_' . $name, 'name' => $name, 'value' => $value, 'class' => 'form-control'];
    if ($required) { $attrs['required'] = 'required'; }
    return html_writer::empty_tag('input', $attrs);
};
$textarea = static function(string $name, string $value = '', bool $required = false, int $rows = 4): string {
    $attrs = ['id' => 'adminscale_' . $name, 'name' => $name, 'rows' => $rows, 'class' => 'form-control'];
    if ($required) { $attrs['required'] = 'required'; }
    return html_writer::tag('textarea', s($value), $attrs);
};
$map = static function(array $rows, string $empty = ''): array {
    $out = ['' => $empty !== '' ? $empty : get_string('adminscale_none', 'local_kbacompany')];
    foreach ($rows as $row) {
        $text = ($row['code'] ?? '') !== '' ? $row['code'] . ' — ' . $row['name'] : $row['name'];
        if (isset($row['kind'])) {
            $text = get_string('adminscale_kind_' . $row['kind'], 'local_kbacompany') . ': ' . $text;
        }
        $out[$row['uuid']] = $text;
    }
    return $out;
};
$positions = [];
foreach ($data['positions'] as $r) { $positions[$r['uuid']] = $r['name']; }
$products = [];
foreach ($data['products'] as $r) { $products[$r['uuid']] = $r['name']; }
$metrics = [];
foreach ($data['metrics'] as $r) { $metrics[$r['uuid']] = $r['name']; }
$itemlabels = [];
foreach ($data['items'] as $r) { $itemlabels[$r['uuid']] = $r['code'] . ' — ' . $r['name']; }
$battleplans = $data['battle_plans'];

$sourceoptions = static function(string $kind) use ($data, $map): array {
    if ($kind === 'longtermgoal') {
        return $map($data['ownership_intents'], get_string('adminscale_selectownerintent', 'local_kbacompany'));
    }
    if ($kind === 'goal') {
        return $map($data['strategic_goals'], get_string('adminscale_selectstrategicgoal', 'local_kbacompany'));
    }
    if ($kind === 'policy') {
        return $map($data['policies'], get_string('adminscale_selectpolicy', 'local_kbacompany'));
    }
    if ($kind === 'decision') {
        return $map($data['directives']);
    }
    return [];
};

$fieldvalue = static function(string $kind, string $field, array $row) use ($positions, $products, $metrics, $itemlabels): string {
    $value = $row[$field] ?? '';
    if ($field === 'parentuuid') { return $value !== '' ? ($itemlabels[$value] ?? $value) : ''; }
    if ($field === 'sourceuuid') { return (string)($row['sourcelabel'] ?? ''); }
    if ($field === 'ownerpositionuuid') { return $value !== '' ? ($positions[$value] ?? $value) : ''; }
    if ($field === 'productuuid') { return $value !== '' ? ($products[$value] ?? $value) : ''; }
    if ($field === 'metricuuid') { return $value !== '' ? ($metrics[$value] ?? $value) : ''; }
    if ($field === 'sortorder') { return (string)$value; }
    return (string)$value;
};

$render_field = static function(string $kind, string $field, ?array $row = null) use (
        $data, $input, $textarea, $map, $sourceoptions): string {
    $row = $row ?: [];
    $required = administrative_scale_ui_service::required($kind, $field);
    $labelkey = administrative_scale_ui_service::label_key($kind, $field);
    $label = get_string($labelkey, 'local_kbacompany') . ($required ? ' *' : '');
    $id = 'adminscale_' . $field;
    $control = '';
    $help = '';
    $wide = in_array($field, ['description', 'situation', 'plangoal', 'expectedresult'], true);

    if ($field === 'code') {
        if ($row) {
            $control = html_writer::tag('div', s((string)$row['code']), [
                'id' => $id, 'class' => 'bms-crm-readonly-code',
            ]);
            $help = get_string('adminscale_code_immutable_help', 'local_kbacompany');
        } else {
            $control = html_writer::tag('div', get_string('adminscale_code_auto_value', 'local_kbacompany'), [
                'id' => $id, 'class' => 'bms-crm-readonly-code text-muted',
            ]);
            $help = get_string('adminscale_code_auto_help', 'local_kbacompany');
        }
    } else if ($field === 'name') {
        $control = $input('name', (string)($row['name'] ?? ''), 'text', true);
    } else if ($field === 'parentuuid') {
        $attrs = ['id' => $id, 'class' => 'form-control'];
        if ($required) { $attrs['required'] = 'required'; }
        $control = html_writer::select($map($data['parent_options'][$kind] ?? []), 'parentuuid',
            (string)($row['parentuuid'] ?? ''), false, $attrs);
    } else if ($field === 'sourceuuid') {
        $attrs = ['id' => $id, 'class' => 'form-control'];
        if ($required) { $attrs['required'] = 'required'; }
        $control = html_writer::select($sourceoptions($kind), 'sourceuuid', (string)($row['sourceuuid'] ?? ''), false, $attrs);
    } else if ($field === 'ownerpositionuuid') {
        $control = html_writer::select($map($data['positions']), 'ownerpositionuuid',
            (string)($row['ownerpositionuuid'] ?? ''), false, ['id' => $id, 'class' => 'form-control']);
    } else if ($field === 'productuuid') {
        $control = html_writer::select($map($data['products']), 'productuuid', (string)($row['productuuid'] ?? ''), false,
            ['id' => $id, 'class' => 'form-control']);
        $help = get_string('adminscale_field_ckp_productuuid_help', 'local_kbacompany');
    } else if ($field === 'metricuuid') {
        $attrs = ['id' => $id, 'class' => 'form-control'];
        if ($required) { $attrs['required'] = 'required'; }
        $control = html_writer::select($map($data['metrics']), 'metricuuid', (string)($row['metricuuid'] ?? ''), false, $attrs);
    } else if ($field === 'targetvalue') {
        $control = $input('targetvalue', (string)($row['targetvalue'] ?? ''));
    } else if ($field === 'startdate' || $field === 'duedate') {
        $control = $input($field, (string)($row[$field] ?? ''), 'date', $required);
    } else if ($field === 'sortorder') {
        $control = $input('sortorder', (string)($row['sortorder'] ?? 0), 'number');
        $help = get_string('adminscale_sortorder_help', 'local_kbacompany');
    } else if (in_array($field, ['description', 'situation', 'plangoal', 'expectedresult'], true)) {
        $rows = $field === 'description' && $kind === 'plan' ? 7 : 4;
        if ($field === 'situation') { $rows = 5; }
        $control = $textarea($field, (string)($row[$field] ?? ''), $required, $rows);
    }

    $html = html_writer::start_tag('div', ['class' => $wide ? 'bms-crm-field bms-crm-field-wide' : 'bms-crm-field']);
    $html .= html_writer::label($label, $id, false, ['class' => 'bms-crm-field-label']);
    $html .= $control;
    if ($help !== '') {
        $html .= html_writer::tag('div', $help, ['class' => 'bms-crm-field-help']);
    }
    $html .= html_writer::end_tag('div');
    return $html;
};

$statusactions = static function(array $row, string $viewkind) use ($hidden, $uuid, $battleplans): string {
    $html = html_writer::link(new moodle_url('/local/kbacompany/administrative_scale.php', [
        'uuid' => $uuid, 'viewkind' => $row['kind'], 'detailuuid' => $row['uuid'],
    ]), get_string('view'), ['class' => 'btn btn-sm btn-outline-primary mr-1']);
    $html .= html_writer::link(new moodle_url('/local/kbacompany/administrative_scale.php', [
        'uuid' => $uuid, 'viewkind' => $row['kind'], 'edituuid' => $row['uuid'],
    ]), get_string('edit'), ['class' => 'btn btn-sm btn-outline-secondary']);
    return $html;
};

$render_action_menu = static function(array $row) use ($hidden, $uuid, $battleplans): string {
    $html = html_writer::start_tag('details', ['class' => 'bms-crm-action-menu']);
    $html .= html_writer::tag('summary', get_string('adminscale_actions', 'local_kbacompany'),
        ['class' => 'btn btn-outline-secondary']);
    $html .= html_writer::start_tag('div', ['class' => 'bms-crm-action-menu-panel']);
    if ($row['status'] === 'draft') {
        $html .= html_writer::start_tag('form', ['method' => 'post']);
        $html .= $hidden('publish', $row['kind'], $row);
        $html .= html_writer::tag('button', get_string('adminscale_publish', 'local_kbacompany'),
            ['type' => 'submit', 'class' => 'btn btn-link btn-block text-left']);
        $html .= html_writer::end_tag('form');
    }
    if ($row['kind'] === 'task' && $row['status'] === 'published') {
        $battleplanoptions = [];
        foreach ($battleplans as $plan) {
            if ($row['ownerpositionuuid'] !== '' && ($plan['position_uuid'] ?? '') !== $row['ownerpositionuuid']) {
                continue;
            }
            $battleplanoptions[$plan['uuid']] = $plan['employee_name'] . ' · ' . $plan['position_name'] . ' · ' . $plan['label'];
        }
        if ($battleplanoptions) {
            $html .= html_writer::start_tag('form', ['method' => 'post', 'class' => 'p-2 border-top']);
            $html .= $hidden('sendtobattleplan', $row['kind'], $row);
            $html .= html_writer::select($battleplanoptions, 'planuuid', '', false, ['class' => 'custom-select custom-select-sm']);
            $html .= html_writer::tag('button', get_string('adminscale_sendtobattleplan', 'local_kbacompany'),
                ['type' => 'submit', 'class' => 'btn btn-success btn-sm btn-block mt-2']);
            $html .= html_writer::end_tag('form');
        }
    }
    $html .= html_writer::start_tag('form', ['method' => 'post', 'class' => 'border-top']);
    $html .= $hidden('archive', $row['kind'], $row);
    $html .= html_writer::tag('button', get_string('adminscale_archive', 'local_kbacompany'),
        ['type' => 'submit', 'class' => 'btn btn-link btn-block text-left text-danger']);
    $html .= html_writer::end_tag('form');
    $html .= html_writer::end_tag('div');
    $html .= html_writer::end_tag('details');
    return $html;
};

$render_form = static function(string $kind, ?array $row = null) use ($data, $hidden, $render_field, $uuid): void {
    $row = ($row && $row['kind'] === $kind) ? $row : null;
    $schema = administrative_scale_ui_service::schema($kind);
    $title = $row
        ? get_string('adminscale_editkind', 'local_kbacompany', get_string('adminscale_kind_' . $kind, 'local_kbacompany'))
        : get_string('adminscale_addkind', 'local_kbacompany', get_string('adminscale_kind_' . $kind, 'local_kbacompany'));

    echo html_writer::start_tag('div', ['class' => 'bms-crm-editview']);
    echo html_writer::start_tag('div', ['class' => 'bms-crm-view-header']);
    echo html_writer::start_tag('div');
    echo html_writer::tag('div', get_string('adminscale_editview', 'local_kbacompany'), ['class' => 'bms-crm-eyebrow']);
    echo html_writer::tag('h3', $title, ['class' => 'mb-1']);
    echo html_writer::tag('p', get_string('adminscale_editview_help', 'local_kbacompany'), ['class' => 'text-muted mb-0']);
    echo html_writer::end_tag('div');
    $cancelparams = ['uuid' => $uuid, 'viewkind' => $kind];
    if ($row) { $cancelparams['detailuuid'] = $row['uuid']; }
    echo html_writer::link(new moodle_url('/local/kbacompany/administrative_scale.php', $cancelparams),
        get_string('cancel'), ['class' => 'btn btn-outline-secondary']);
    echo html_writer::end_tag('div');

    echo html_writer::start_tag('form', ['method' => 'post', 'class' => 'bms-crm-form']);
    echo $hidden('save', $kind, $row);
    echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'kind', 'value' => $kind]);
    foreach ($schema as $section) {
        if (empty($section['fields'])) { continue; }
        $sectioncontent = html_writer::start_tag('div', ['class' => 'bms-crm-field-grid']);
        foreach ($section['fields'] as $field) {
            $sectioncontent .= $render_field($kind, $field, $row);
        }
        $sectioncontent .= html_writer::end_tag('div');
        $sectiontitle = get_string('adminscale_section_' . $section['key'], 'local_kbacompany');
        if (!empty($section['collapsed'])) {
            echo html_writer::start_tag('details', ['class' => 'bms-crm-section bms-crm-section-collapsed']);
            echo html_writer::tag('summary', $sectiontitle, ['class' => 'bms-crm-section-title']);
            echo $sectioncontent;
            echo html_writer::end_tag('details');
        } else {
            echo html_writer::start_tag('section', ['class' => 'bms-crm-section']);
            echo html_writer::tag('h4', $sectiontitle, ['class' => 'bms-crm-section-title']);
            echo $sectioncontent;
            echo html_writer::end_tag('section');
        }
    }
    echo html_writer::start_tag('div', ['class' => 'bms-crm-form-actions']);
    echo html_writer::link(new moodle_url('/local/kbacompany/administrative_scale.php', $cancelparams),
        get_string('cancel'), ['class' => 'btn btn-outline-secondary']);
    echo html_writer::tag('button', get_string('savechanges'), ['type' => 'submit', 'class' => 'btn btn-success']);
    echo html_writer::end_tag('div');
    echo html_writer::end_tag('form');
    echo html_writer::end_tag('div');
};

$render_detail = static function(array $row) use (
        $data, $uuid, $fieldvalue, $render_action_menu, $USER, $recordtab): void {
    $kind = $row['kind'];
    $schema = administrative_scale_ui_service::schema($kind);
    $statusclass = $row['status'] === 'published' ? 'badge-success' : ($row['status'] === 'draft' ? 'badge-warning' : 'badge-secondary');
    $statuslabel = get_string('adminscale_status_' . $row['status'], 'local_kbacompany');

    echo html_writer::start_tag('div', ['class' => 'bms-crm-detailview']);
    echo html_writer::start_tag('div', ['class' => 'bms-crm-view-header']);
    echo html_writer::start_tag('div');
    echo html_writer::tag('div', get_string('adminscale_detailview', 'local_kbacompany'), ['class' => 'bms-crm-eyebrow']);
    echo html_writer::tag('h3', s($row['code']) . ' — ' . format_string($row['name']), ['class' => 'mb-2']);
    echo html_writer::tag('span', $statuslabel, ['class' => 'badge ' . $statusclass . ' mr-2']);
    if ($row['stale']) {
        echo html_writer::tag('span', get_string('adminscale_stale_short', 'local_kbacompany'), ['class' => 'badge badge-danger']);
    }
    echo html_writer::end_tag('div');
    echo html_writer::start_tag('div', ['class' => 'bms-crm-view-actions']);
    if ($data['can_manage']) {
        echo html_writer::link(new moodle_url('/local/kbacompany/administrative_scale.php', [
            'uuid' => $uuid, 'viewkind' => $kind, 'edituuid' => $row['uuid'],
        ]), get_string('edit'), ['class' => 'btn btn-success mr-2']);
        echo $render_action_menu($row);
    }
    echo html_writer::end_tag('div');
    echo html_writer::end_tag('div');

    if (!empty($row['path'])) {
        $parts = [];
        foreach ($row['path'] as $node) { $parts[] = $node['code'] . ' — ' . $node['name']; }
        echo html_writer::tag('div', get_string('adminscale_trace', 'local_kbacompany') . ': ' . s(implode(' → ', $parts)),
            ['class' => 'bms-crm-trace']);
    }

    if ($kind === 'project') {
        echo administrative_scale_ui_service::project_tabs($uuid, $row['uuid'], $recordtab);
        if ($recordtab === 'execution') {
            $execution = \local_kbacompany\local\administrative_cycle_service::project_execution(
                $uuid, (int)$USER->id, $row['uuid']);
            echo administrative_scale_ui_service::project_execution($execution);
            echo html_writer::end_tag('div');
            return;
        }
    }

    foreach ($schema as $section) {
        if (empty($section['fields'])) { continue; }
        $visible = [];
        foreach ($section['fields'] as $field) {
            if ($field === 'sortorder' && (int)$row['sortorder'] === 0) { continue; }
            $visible[] = $field;
        }
        if (!$visible) { continue; }
        $title = get_string('adminscale_section_' . $section['key'], 'local_kbacompany');
        echo html_writer::start_tag('section', ['class' => 'bms-crm-section']);
        echo html_writer::tag('h4', $title, ['class' => 'bms-crm-section-title']);
        echo html_writer::start_tag('div', ['class' => 'bms-crm-detail-grid']);
        foreach ($visible as $field) {
            $labelkey = administrative_scale_ui_service::label_key($kind, $field);
            $value = $fieldvalue($kind, $field, $row);
            if ($value === '') { $value = '—'; }
            $istext = in_array($field, ['description', 'situation', 'plangoal', 'expectedresult'], true);
            echo html_writer::start_tag('div', ['class' => $istext ? 'bms-crm-detail-field bms-crm-field-wide' : 'bms-crm-detail-field']);
            echo html_writer::tag('div', get_string($labelkey, 'local_kbacompany'), ['class' => 'bms-crm-detail-label']);
            echo html_writer::tag('div', $istext ? nl2br(s($value)) : s($value), ['class' => 'bms-crm-detail-value']);
            echo html_writer::end_tag('div');
        }
        echo html_writer::end_tag('div');
        echo html_writer::end_tag('section');
    }

    $children = [];
    foreach ($data['items'] as $candidate) {
        if ($candidate['parentuuid'] === $row['uuid']) { $children[] = $candidate; }
    }
    if ($children) {
        echo html_writer::start_tag('section', ['class' => 'bms-crm-section']);
        echo html_writer::tag('h4', get_string('adminscale_related', 'local_kbacompany'), ['class' => 'bms-crm-section-title']);
        $table = new html_table();
        $table->head = [get_string('adminscale_kind', 'local_kbacompany'), get_string('adminscale_code', 'local_kbacompany'),
            get_string('adminscale_name', 'local_kbacompany'), get_string('adminscale_status', 'local_kbacompany')];
        foreach ($children as $child) {
            $link = html_writer::link(new moodle_url('/local/kbacompany/administrative_scale.php', [
                'uuid' => $uuid, 'viewkind' => $child['kind'], 'detailuuid' => $child['uuid'],
            ]), format_string($child['name']));
            $table->data[] = [get_string('adminscale_kind_' . $child['kind'], 'local_kbacompany'), s($child['code']), $link,
                get_string('adminscale_status_' . $child['status'], 'local_kbacompany')];
        }
        echo html_writer::table($table);
        echo html_writer::end_tag('section');
    }

    $history = administrative_scale_service::history($uuid, (int)$USER->id, $row['uuid']);
    if ($history) {
        echo html_writer::start_tag('section', ['class' => 'bms-crm-section']);
        echo html_writer::tag('h4', get_string('adminscale_history', 'local_kbacompany'), ['class' => 'bms-crm-section-title']);
        $table = new html_table();
        $table->head = [get_string('adminscale_revision', 'local_kbacompany'), get_string('adminscale_status', 'local_kbacompany'),
            get_string('adminscale_modified', 'local_kbacompany')];
        foreach ($history as $version) {
            $table->data[] = [(string)$version['revision'], get_string('adminscale_status_' . $version['status'], 'local_kbacompany'),
                userdate($version['timemodified'])];
        }
        echo html_writer::table($table);
        echo html_writer::end_tag('section');
    }
    echo html_writer::end_tag('div');
};

$bmsshell = \local_kbacompany\local\bms_page_shell_service::begin($uuid, (int)$USER->id, 'adminscale',
    get_string('adminscale_title', 'local_kbacompany'));

echo \local_kbacompany\local\bms_workspace_ux_service::architecture_nav($uuid, 'adminscale');

echo html_writer::start_tag('div', ['class' => 'd-flex flex-wrap justify-content-between align-items-center mb-3']);
echo html_writer::start_tag('div');
echo $OUTPUT->heading(get_string('adminscale_title', 'local_kbacompany') . ': ' . format_string($data['company']['name']), 2);
echo html_writer::tag('p', get_string('adminscale_intro_v2', 'local_kbacompany'), ['class' => 'text-muted mb-0']);
echo html_writer::end_tag('div');
echo html_writer::start_tag('div', ['class' => 'mt-2']);
echo html_writer::link(new moodle_url('/local/kbacompany/strategy.php', ['uuid' => $uuid]),
    get_string('adminscale_openstrategy', 'local_kbacompany'), ['class' => 'btn btn-outline-primary mr-2']);
echo html_writer::link(new moodle_url('/local/kbacompany/battle_plan.php', ['uuid' => $uuid]),
    get_string('adminscale_openbattleplan', 'local_kbacompany'), ['class' => 'btn btn-outline-secondary']);
echo html_writer::end_tag('div');
echo html_writer::end_tag('div');

$missing = count($data['alignment']['missing_core']);
$stale = (int)$data['alignment']['stale_count'];
$alignmentclass = ($missing === 0 && $stale === 0) ? 'alert-success' : 'alert-warning';
$alignmenttext = ($missing === 0 && $stale === 0)
    ? get_string('adminscale_alignment_ok', 'local_kbacompany')
    : get_string('adminscale_alignment_attention', 'local_kbacompany', (object)['missing' => $missing, 'stale' => $stale]);
echo html_writer::tag('div', $alignmenttext, ['class' => 'alert ' . $alignmentclass]);
echo html_writer::tag('div', get_string('adminscale_methodology_rule', 'local_kbacompany'), ['class' => 'alert alert-info']);

// SuiteCRM-style navigation: methodology records and execution support are separate views of one management context.
echo html_writer::start_tag('div', ['class' => 'row']);
echo html_writer::start_tag('div', ['class' => 'col-lg-3 mb-3']);
echo html_writer::start_tag('div', ['class' => 'card']);
echo html_writer::tag('div', get_string('adminscale_navigation', 'local_kbacompany'), ['class' => 'card-header font-weight-bold']);
echo html_writer::start_tag('div', ['class' => 'list-group list-group-flush']);
$overviewclass = $viewkind === 'overview' ? ' list-group-item-primary' : '';
echo html_writer::link(new moodle_url('/local/kbacompany/administrative_scale.php', ['uuid' => $uuid, 'viewkind' => 'overview']),
    get_string('adminscale_overview', 'local_kbacompany'), ['class' => 'list-group-item list-group-item-action' . $overviewclass]);
foreach ($data['core_kinds'] as $kind) {
    $active = $viewkind === $kind ? ' list-group-item-primary' : '';
    $count = (int)($data['counts'][$kind] ?? 0);
    $text = get_string('adminscale_kind_' . $kind, 'local_kbacompany') . ' (' . $count . ')';
    echo html_writer::link(new moodle_url('/local/kbacompany/administrative_scale.php', ['uuid' => $uuid, 'viewkind' => $kind]),
        $text, ['class' => 'list-group-item list-group-item-action' . $active]);
}
echo html_writer::tag('div', get_string('adminscale_execution_support', 'local_kbacompany'),
    ['class' => 'list-group-item text-muted small font-weight-bold']);
foreach ($data['execution_kinds'] as $kind) {
    $active = $viewkind === $kind ? ' list-group-item-primary' : '';
    $count = (int)($data['counts'][$kind] ?? 0);
    $text = get_string('adminscale_kind_' . $kind, 'local_kbacompany') . ' (' . $count . ')';
    echo html_writer::link(new moodle_url('/local/kbacompany/administrative_scale.php', ['uuid' => $uuid, 'viewkind' => $kind]),
        $text, ['class' => 'list-group-item list-group-item-action' . $active]);
}
echo html_writer::end_tag('div');
echo html_writer::end_tag('div');
echo html_writer::end_tag('div');

echo html_writer::start_tag('div', ['class' => 'col-lg-9']);
if ($viewkind === 'overview') {
    echo $OUTPUT->heading(get_string('adminscale_overview', 'local_kbacompany'), 3);
    echo html_writer::tag('p', get_string('adminscale_overview_intro', 'local_kbacompany'), ['class' => 'lead']);
    echo html_writer::start_tag('div', ['class' => 'row']);
    foreach ($data['core_kinds'] as $kind) {
        $count = (int)($data['counts'][$kind] ?? 0);
        $cardclass = $count > 0 ? 'border-success' : 'border-warning';
        echo html_writer::start_tag('div', ['class' => 'col-md-6 col-xl-4 mb-3']);
        echo html_writer::start_tag('div', ['class' => 'card h-100 ' . $cardclass]);
        echo html_writer::start_tag('div', ['class' => 'card-body']);
        echo html_writer::tag('h5', get_string('adminscale_kind_' . $kind, 'local_kbacompany'), ['class' => 'card-title']);
        echo html_writer::tag('div', (string)$count, ['class' => 'display-4']);
        echo html_writer::link(new moodle_url('/local/kbacompany/administrative_scale.php', ['uuid' => $uuid, 'viewkind' => $kind]),
            get_string('adminscale_openkind', 'local_kbacompany'), ['class' => 'btn btn-sm btn-outline-primary']);
        echo html_writer::end_tag('div');
        echo html_writer::end_tag('div');
        echo html_writer::end_tag('div');
    }
    echo html_writer::end_tag('div');
    echo $OUTPUT->heading(get_string('adminscale_execution_support', 'local_kbacompany'), 4);
    echo html_writer::tag('p', get_string('adminscale_execution_intro', 'local_kbacompany'), ['class' => 'text-muted']);
} else {
    if ($detail) {
        $render_detail($detail);
    } else if ($editing || $creating) {
        $render_form($viewkind, $editing);
    } else {
        echo html_writer::start_tag('div', ['class' => 'bms-crm-listview']);
        echo html_writer::start_tag('div', ['class' => 'bms-crm-view-header']);
        echo html_writer::start_tag('div');
        echo html_writer::tag('div', get_string('adminscale_listview', 'local_kbacompany'), ['class' => 'bms-crm-eyebrow']);
        echo $OUTPUT->heading(get_string('adminscale_kind_' . $viewkind, 'local_kbacompany'), 3);
        echo html_writer::tag('p', get_string('adminscale_kind_help_' . $viewkind, 'local_kbacompany'), ['class' => 'text-muted mb-0']);
        echo html_writer::end_tag('div');
        if ($data['can_manage']) {
            echo html_writer::link(new moodle_url('/local/kbacompany/administrative_scale.php', [
                'uuid' => $uuid, 'viewkind' => $viewkind, 'create' => 1,
            ]), get_string('adminscale_create_record', 'local_kbacompany'), ['class' => 'btn btn-success']);
        }
        echo html_writer::end_tag('div');

        $rows = $data['by_kind'][$viewkind] ?? [];
        $rows = bms_collection_view_service::filter_rows($rows, $q, ['code', 'name', 'description', 'status']);
        $collectionviews = in_array($viewkind, ['program', 'project'], true);
        if ($collectionviews) {
            echo bms_collection_view_service::toolbar('/local/kbacompany/administrative_scale.php', [
                'uuid'=>$uuid, 'viewkind'=>$viewkind,
            ], $view, $q);
        }
        if (!$rows) {
            echo html_writer::tag('div', get_string('adminscale_empty_kind', 'local_kbacompany'), ['class' => 'bms-crm-empty']);
        } else if (!$collectionviews || $view === 'list') {
            $table = new html_table();
            $table->attributes['class'] = 'generaltable bms-crm-list-table';
            $table->head = [get_string('adminscale_code', 'local_kbacompany'), get_string('adminscale_name', 'local_kbacompany'),
                get_string('adminscale_trace', 'local_kbacompany'), get_string('adminscale_status', 'local_kbacompany'),
                get_string('actions')];
            foreach ($rows as $row) {
                $traceparts = [];
                foreach ($row['path'] as $node) { $traceparts[] = $node['code']; }
                $status = get_string('adminscale_status_' . $row['status'], 'local_kbacompany');
                if ($row['stale']) { $status .= ' · ' . get_string('adminscale_stale_short', 'local_kbacompany'); }
                $detailurl = new moodle_url('/local/kbacompany/administrative_scale.php', [
                    'uuid' => $uuid, 'viewkind' => $viewkind, 'detailuuid' => $row['uuid'], 'view'=>$view, 'q'=>$q,
                ]);
                $namehtml = html_writer::link($detailurl, format_string($row['name']), ['class' => 'font-weight-bold']);
                if ($viewkind === 'plan') {
                    $namehtml .= html_writer::tag('div', get_string('adminscale_situation_short', 'local_kbacompany') . ': ' .
                        s(shorten_text(strip_tags($row['situation']), 120)), ['class' => 'small text-muted']);
                    $namehtml .= html_writer::tag('div', get_string('adminscale_plangoal_short', 'local_kbacompany') . ': ' .
                        s(shorten_text(strip_tags($row['plangoal']), 120)), ['class' => 'small']);
                } else if ($viewkind === 'statistic' && $row['metricuuid'] !== '') {
                    $namehtml .= html_writer::tag('div', s($metrics[$row['metricuuid']] ?? $row['metricuuid']) .
                        ($row['targetvalue'] !== '' ? ': ' . s($row['targetvalue']) : ''), ['class' => 'small text-muted']);
                } else if ($viewkind === 'ckp' && $row['productuuid'] !== '') {
                    $namehtml .= html_writer::tag('div', s($products[$row['productuuid']] ?? $row['productuuid']),
                        ['class' => 'small text-muted']);
                } else if ($viewkind === 'task' && $row['status'] === 'published') {
                    $namehtml .= html_writer::tag('div', get_string('adminscale_battleplan_state', 'local_kbacompany', (object)[
                        'tasks' => (int)$row['canonical_task_count'], 'plans' => (int)$row['battle_plan_count'],
                    ]), ['class' => 'small text-muted']);
                }
                $table->data[] = [html_writer::link($detailurl, s($row['code'])), $namehtml, s(implode(' → ', $traceparts)),
                    s($status), $data['can_manage'] ? $statusactions($row, $viewkind) : html_writer::link($detailurl, get_string('view'))];
            }
            echo html_writer::table($table);
        } else if ($view === 'kanban') {
            $columns = [];
            foreach (['draft', 'published', 'archived'] as $status) {
                $columns[$status] = ['label'=>get_string('adminscale_status_' . $status, 'local_kbacompany'), 'cards'=>[]];
            }
            foreach ($rows as $row) {
                if (!isset($columns[$row['status']])) { continue; }
                $detailurl = new moodle_url('/local/kbacompany/administrative_scale.php', [
                    'uuid'=>$uuid,'viewkind'=>$viewkind,'detailuuid'=>$row['uuid'],'view'=>$view,'q'=>$q,
                ]);
                $meta = [];
                if ($row['startdate'] !== '') { $meta[] = $row['startdate']; }
                if ($row['duedate'] !== '') { $meta[] = $row['duedate']; }
                if (!empty($row['stale'])) { $meta[] = get_string('adminscale_stale_short', 'local_kbacompany'); }
                $columns[$row['status']]['cards'][] = bms_collection_view_service::card(
                    html_writer::link($detailurl, s($row['code'] . ' — ' . $row['name'])), $meta,
                    s(shorten_text(strip_tags((string)$row['description']), 180)));
            }
            echo bms_collection_view_service::kanban($columns);
        } else {
            $counts = ['draft'=>0,'published'=>0,'archived'=>0]; $stale = 0;
            foreach ($rows as $row) { if (isset($counts[$row['status']])) {$counts[$row['status']]++;} if (!empty($row['stale'])) {$stale++;} }
            echo bms_collection_view_service::analytics([
                ['label'=>get_string('bmsview_total','local_kbacompany'),'value'=>count($rows)],
                ['label'=>get_string('adminscale_status_draft','local_kbacompany'),'value'=>$counts['draft']],
                ['label'=>get_string('adminscale_status_published','local_kbacompany'),'value'=>$counts['published']],
                ['label'=>get_string('adminscale_status_archived','local_kbacompany'),'value'=>$counts['archived']],
                ['label'=>get_string('bmsview_attention','local_kbacompany'),'value'=>$stale],
            ]);
        }
        echo html_writer::end_tag('div');
    }
}
echo html_writer::end_tag('div');
echo html_writer::end_tag('div');

if ($data['legacy_cascade_count'] > 0) {
    echo html_writer::tag('p', get_string('adminscale_legacy', 'local_kbacompany', $data['legacy_cascade_count']),
        ['class' => 'small text-muted mt-3']);
}

echo html_writer::link(new moodle_url('/local/kbacompany/bms_workspace.php', ['uuid' => $uuid]),
    get_string('backtocompany', 'local_kbacompany'), ['class' => 'btn btn-link mt-3']);
\local_kbacompany\local\bms_page_shell_service::finish($bmsshell);
