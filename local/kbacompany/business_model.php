<?php
require_once(__DIR__ . '/../../config.php');

use local_kbacompany\local\business_model_crud_service;
use local_kbacompany\local\business_model_ui_service;
use local_kbacompany\local\business_model_validation_service;
use local_kbacompany\local\market_model_service;
use local_kbacompany\local\bms_collection_view_service;

require_login();
$context = context_system::instance();
require_capability('local/kbacompany:viewowncompany', $context);
$uuid = required_param('uuid', PARAM_RAW_TRIMMED);
$data = market_model_service::context($uuid, (int)$USER->id);
$canmanage = !empty($data['can_manage']);

$PAGE->set_context($context);
$PAGE->set_url(new moodle_url('/local/kbacompany/business_model.php', ['uuid' => $uuid]));
$PAGE->set_title(get_string('bmc_title', 'local_kbacompany'));
$PAGE->set_heading(get_string('pluginname', 'local_kbacompany'));
$PAGE->requires->css(new moodle_url('/local/kbacompany/styles.css'));

$screen = optional_param('screen', '', PARAM_ALPHANUMEXT);
$modeluuid = optional_param('model', '', PARAM_RAW_TRIMMED);
$modelrevision = optional_param('revision', 0, PARAM_INT);
$block = optional_param('block', '', PARAM_ALPHANUMEXT);
$itemuuid = optional_param('item', '', PARAM_RAW_TRIMMED);
$itemrevision = optional_param('itemrev', 0, PARAM_INT);
$stateparam = optional_param('state', 'asis', PARAM_ALPHANUMEXT);
$gapuuid = optional_param('gap', '', PARAM_RAW_TRIMMED);
$gaprevision = optional_param('gaprev', 0, PARAM_INT);
$view = bms_collection_view_service::normalise_view(optional_param('view', 'list', PARAM_ALPHA));
$q = optional_param('q', '', PARAM_RAW_TRIMMED);

$legacy_payload = static function(?array $model = null): array {
    $canvas = $model['canvas'] ?? [];
    $out = [
        'name' => (string)($model['name'] ?? ''),
        'ownerframeuuid' => (string)($model['ownerframeuuid'] ?? ''),
        'philosophyuuid' => (string)($model['philosophyuuid'] ?? ''),
        'marketuuid' => (string)($model['marketuuid'] ?? ''),
        'summary' => (string)($canvas['summary'] ?? $model['summary'] ?? ''),
        'horizon' => (string)($canvas['horizon'] ?? $model['horizon'] ?? ''),
    ];
    foreach (['ideology_first', 'ideology_target', 'ideology_criterion', 'ideology_reqs', 'ideology_facilitator'] as $field) {
        $out[$field] = (string)($canvas[$field] ?? '');
    }
    foreach (business_model_crud_service::BLOCKS as $b) {
        $old = isset($canvas[$b]) && is_array($canvas[$b]) ? $canvas[$b] : [];
        foreach (['first', 'current', 'data', 'hyp', 'target', 'criterion', 'reqs', 'gap', 'facilitator'] as $suffix) {
            $out[$b . '_' . $suffix] = (string)($old[$suffix] ?? '');
        }
    }
    foreach (['market', 'client', 'economics', 'resources', 'ownerrequirements'] as $contour) {
        $out['check_' . $contour] = (string)($canvas['checks'][$contour] ?? '');
        $out['check_' . $contour . '_note'] = (string)($canvas['check_notes'][$contour] ?? '');
    }
    return $out;
};

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_sesskey();
    if (!$canmanage) {
        throw new required_capability_exception($context, 'local/kbacompany:manageorganization', 'nopermissions', '');
    }
    $action = required_param('action', PARAM_ALPHANUMEXT);
    if ($action === 'savemodel') {
        $edituuid = optional_param('modeluuid', '', PARAM_RAW_TRIMMED);
        $editrevision = optional_param('modelrevision', 0, PARAM_INT);
        $existing = null;
        if ($edituuid !== '') {
            $existing = business_model_crud_service::model($uuid, (int)$USER->id, $edituuid, $editrevision);
        }
        $payload = $legacy_payload($existing);
        $payload['name'] = required_param('name', PARAM_RAW_TRIMMED);
        $payload['ownerframeuuid'] = optional_param('ownerframeuuid', '', PARAM_RAW_TRIMMED);
        $payload['philosophyuuid'] = optional_param('philosophyuuid', '', PARAM_RAW_TRIMMED);
        $payload['marketuuid'] = optional_param('marketuuid', '', PARAM_RAW_TRIMMED);
        $payload['summary'] = optional_param('summary', '', PARAM_RAW_TRIMMED);
        $payload['horizon'] = optional_param('horizon', '', PARAM_ALPHANUMEXT);
        $saveduuid = market_model_service::save_business_model($uuid, (int)$USER->id, $payload, $edituuid, $editrevision);
        $saved = business_model_crud_service::model($uuid, (int)$USER->id, $saveduuid);
        redirect(new moodle_url('/local/kbacompany/business_model.php', [
            'uuid' => $uuid, 'model' => $saveduuid, 'revision' => $saved['revision'],
        ]), get_string('bmc_saved', 'local_kbacompany'));
    } else if ($action === 'publishmodel') {
        $targetuuid = required_param('modeluuid', PARAM_RAW_TRIMMED);
        $targetrevision = required_param('modelrevision', PARAM_INT);
        market_model_service::publish_business_model($uuid, (int)$USER->id, $targetuuid, $targetrevision);
        redirect(new moodle_url('/local/kbacompany/business_model.php', [
            'uuid' => $uuid, 'model' => $targetuuid, 'revision' => $targetrevision + 1,
        ]), get_string('bmc_published', 'local_kbacompany'));
    } else if ($action === 'archivemodel') {
        $targetuuid = required_param('modeluuid', PARAM_RAW_TRIMMED);
        $targetrevision = required_param('modelrevision', PARAM_INT);
        market_model_service::archive_business_model($uuid, (int)$USER->id, $targetuuid, $targetrevision);
        redirect(new moodle_url('/local/kbacompany/business_model.php', ['uuid' => $uuid]),
            get_string('bmc_archived', 'local_kbacompany'));
    } else if ($action === 'saveitem') {
        $targetuuid = required_param('modeluuid', PARAM_RAW_TRIMMED);
        $targetrevision = required_param('modelrevision', PARAM_INT);
        $targetitem = optional_param('itemuuid', '', PARAM_RAW_TRIMMED);
        $targetitemrevision = optional_param('itemrevision', 0, PARAM_INT);
        $requirements = optional_param_array('requirements', [], PARAM_RAW_TRIMMED);
        $itemdata = [
            'block' => required_param('block', PARAM_ALPHANUMEXT),
            'state' => required_param('state', PARAM_ALPHANUMEXT),
            'marker' => required_param('marker', PARAM_ALPHANUMEXT),
            'intent' => optional_param('intent', '', PARAM_ALPHANUMEXT),
            'horizon' => optional_param('horizon', '', PARAM_ALPHANUMEXT),
            'title' => required_param('title', PARAM_RAW_TRIMMED),
            'formulation' => required_param('formulation', PARAM_RAW_TRIMMED),
            'evidence' => optional_param('evidence', '', PARAM_RAW_TRIMMED),
            'source' => optional_param('source', '', PARAM_RAW_TRIMMED),
            'testcriteria' => optional_param('testcriteria', '', PARAM_RAW_TRIMMED),
            'ownerpositionuuid' => optional_param('ownerpositionuuid', '', PARAM_RAW_TRIMMED),
            'sortorder' => optional_param('sortorder', 0, PARAM_INT),
            'requirements' => $requirements,
        ];
        $saveditem = business_model_crud_service::save_item($uuid, (int)$USER->id, $targetuuid,
            $targetrevision, $itemdata, $targetitem, $targetitemrevision);
        $saved = business_model_crud_service::item($uuid, (int)$USER->id, $targetuuid, $targetrevision, $saveditem);
        redirect(new moodle_url('/local/kbacompany/business_model.php', [
            'uuid' => $uuid, 'model' => $targetuuid, 'revision' => $targetrevision,
            'screen' => 'item', 'item' => $saveditem, 'itemrev' => $saved['revision'],
        ]), get_string('bmc_item_saved', 'local_kbacompany'));
    } else if ($action === 'archiveitem') {
        $targetuuid = required_param('modeluuid', PARAM_RAW_TRIMMED);
        $targetrevision = required_param('modelrevision', PARAM_INT);
        $targetitem = required_param('itemuuid', PARAM_RAW_TRIMMED);
        $targetitemrevision = required_param('itemrevision', PARAM_INT);
        $targetblock = required_param('block', PARAM_ALPHANUMEXT);
        business_model_crud_service::archive_item($uuid, (int)$USER->id, $targetuuid,
            $targetrevision, $targetitem, $targetitemrevision);
        redirect(new moodle_url('/local/kbacompany/business_model.php', [
            'uuid' => $uuid, 'model' => $targetuuid, 'revision' => $targetrevision,
            'screen' => 'block', 'block' => $targetblock,
        ]), get_string('bmc_item_archived', 'local_kbacompany'));
    } else if ($action === 'comment') {
        $targetuuid = required_param('modeluuid', PARAM_RAW_TRIMMED);
        $targetrevision = required_param('modelrevision', PARAM_INT);
        $targetitem = required_param('itemuuid', PARAM_RAW_TRIMMED);
        $targetitemrevision = required_param('itemrevision', PARAM_INT);
        business_model_crud_service::add_comment($uuid, (int)$USER->id, $targetuuid, $targetrevision,
            $targetitem, $targetitemrevision, required_param('comment', PARAM_RAW_TRIMMED));
        redirect(new moodle_url('/local/kbacompany/business_model.php', [
            'uuid' => $uuid, 'model' => $targetuuid, 'revision' => $targetrevision,
            'screen' => 'item', 'item' => $targetitem, 'itemrev' => $targetitemrevision,
        ]), get_string('bmc_comment_saved', 'local_kbacompany'));
    } else if ($action === 'savevalidation') {
        $targetuuid = required_param('modeluuid', PARAM_RAW_TRIMMED);
        $targetrevision = required_param('modelrevision', PARAM_INT);
        $contour = required_param('contour', PARAM_ALPHANUMEXT);
        business_model_validation_service::save_validation($uuid, (int)$USER->id, $targetuuid,
            $targetrevision, $contour, [
                'state' => required_param('validationstate', PARAM_ALPHANUMEXT),
                'note' => optional_param('note', '', PARAM_RAW_TRIMMED),
                'evidence' => optional_param('evidence', '', PARAM_RAW_TRIMMED),
                'finscenario' => optional_param('finscenario', '', PARAM_RAW_TRIMMED),
            ]);
        redirect(new moodle_url('/local/kbacompany/business_model.php', [
            'uuid' => $uuid, 'model' => $targetuuid, 'revision' => $targetrevision, 'screen' => 'validation',
        ]), get_string('bmcval_saved', 'local_kbacompany'));
    } else if ($action === 'savegap') {
        $targetuuid = required_param('modeluuid', PARAM_RAW_TRIMMED);
        $targetrevision = required_param('modelrevision', PARAM_INT);
        $targetgap = optional_param('gapuuid', '', PARAM_RAW_TRIMMED);
        $targetgaprevision = optional_param('gaprevision', 0, PARAM_INT);
        $requirements = optional_param_array('requirements', [], PARAM_RAW_TRIMMED);
        $savedgap = business_model_validation_service::save_gap($uuid, (int)$USER->id, $targetuuid,
            $targetrevision, [
                'asisitemuuid' => required_param('asisitemuuid', PARAM_RAW_TRIMMED),
                'targetitemuuid' => required_param('targetitemuuid', PARAM_RAW_TRIMMED),
                'significance' => required_param('significance', PARAM_RAW_TRIMMED),
                'evidence' => optional_param('evidence', '', PARAM_RAW_TRIMMED),
                'indicatoruuid' => optional_param('indicatoruuid', '', PARAM_RAW_TRIMMED),
                'requirements' => $requirements,
            ], $targetgap, $targetgaprevision);
        redirect(new moodle_url('/local/kbacompany/business_model.php', [
            'uuid' => $uuid, 'model' => $targetuuid, 'revision' => $targetrevision,
            'screen' => 'gap', 'gap' => $savedgap,
        ]), get_string('bmcgap_saved', 'local_kbacompany'));
    } else if ($action === 'confirmgap' || $action === 'archivegap') {
        $targetuuid = required_param('modeluuid', PARAM_RAW_TRIMMED);
        $targetrevision = required_param('modelrevision', PARAM_INT);
        $targetgap = required_param('gapuuid', PARAM_RAW_TRIMMED);
        $targetgaprevision = required_param('gaprevision', PARAM_INT);
        $targetmodel = business_model_crud_service::model($uuid, (int)$USER->id, $targetuuid, $targetrevision);
        if ($action === 'confirmgap') {
            business_model_validation_service::confirm_gap($uuid, (int)$USER->id, $targetmodel,
                $targetgap, $targetgaprevision);
            $message = get_string('bmcgap_confirmed', 'local_kbacompany');
        } else {
            business_model_validation_service::archive_gap($uuid, (int)$USER->id, $targetmodel,
                $targetgap, $targetgaprevision);
            $message = get_string('bmcgap_archived', 'local_kbacompany');
        }
        redirect(new moodle_url('/local/kbacompany/business_model.php', [
            'uuid' => $uuid, 'model' => $targetuuid, 'revision' => $targetrevision,
            'screen' => $action === 'archivegap' ? 'gaps' : 'gap', 'gap' => $targetgap,
        ]), $message);
    } else {
        throw new moodle_exception('invalidparameter');
    }
}

$horizonlabels = [
    '' => get_string('bmc_horizon_notset', 'local_kbacompany'),
    '0_12' => get_string('bmc_horizon_0_12', 'local_kbacompany'),
    '1_3' => get_string('bmc_horizon_1_3', 'local_kbacompany'),
    '3_5' => get_string('bmc_horizon_3_5', 'local_kbacompany'),
];
$statuslabel = static function(string $status): string {
    return get_string('marketmodel_status_' . $status, 'local_kbacompany');
};
$markerlabel = static function(string $marker): string {
    return get_string('bmc_marker_' . $marker, 'local_kbacompany');
};
$statelabel = static function(string $state): string {
    return get_string('bmc_state_' . $state, 'local_kbacompany');
};
$validationstatelabel = static function(string $state): string {
    return get_string('bmcval_state_' . $state, 'local_kbacompany');
};
$gapstatuslabel = static function(string $status): string {
    return get_string('bmcgap_status_' . $status, 'local_kbacompany');
};

$hidden = static function(string $action, array $extra = []) use ($uuid): string {
    $fields = array_merge(['uuid' => $uuid, 'sesskey' => sesskey(), 'action' => $action], $extra);
    $html = '';
    foreach ($fields as $name => $value) {
        $html .= html_writer::empty_tag('input', [
            'type' => 'hidden', 'name' => $name, 'value' => $value,
        ]);
    }
    return $html;
};

$bmsshell = \local_kbacompany\local\bms_page_shell_service::begin($uuid, (int)$USER->id,
    'businessmodel', get_string('bmc_title', 'local_kbacompany'));

echo \local_kbacompany\local\bms_workspace_ux_service::architecture_nav($uuid, 'businessmodel');

echo html_writer::tag('div',
    html_writer::tag('div',
        html_writer::tag('div', get_string('bmc_eyebrow', 'local_kbacompany'), ['class' => 'text-muted small text-uppercase']) .
        $OUTPUT->heading(get_string('bmc_title', 'local_kbacompany') . ': ' . format_string($data['company']['name']), 2) .
        html_writer::tag('p', get_string('bmc_intro', 'local_kbacompany'), ['class' => 'mb-0 text-muted']),
        ['class' => 'flex-grow-1']) .
    ($canmanage ? html_writer::link(new moodle_url('/local/kbacompany/business_model.php', [
        'uuid' => $uuid, 'screen' => 'newmodel',
    ]), get_string('bmc_create_model', 'local_kbacompany'), ['class' => 'btn btn-primary align-self-start']) : ''),
    ['class' => 'd-flex justify-content-between align-items-start mb-4']);

// Create/edit root model.
if ($screen === 'newmodel' || ($screen === 'editmodel' && $modeluuid !== '')) {
    if (!$canmanage) {
        throw new required_capability_exception($context, 'local/kbacompany:manageorganization', 'nopermissions', '');
    }
    $model = null;
    if ($screen === 'editmodel') {
        $model = business_model_crud_service::model($uuid, (int)$USER->id, $modeluuid, $modelrevision);
    }
    $reqopts = ['' => get_string('marketmodel_noownerreq', 'local_kbacompany')];
    foreach ($data['owner_requirement_sets'] as $set) {
        $reqopts[$set['uuid']] = $set['label'] . ' — ' . get_string('marketmodel_requirement_count',
            'local_kbacompany', count($set['requirements']));
    }
    if ($model && $model['ownerframeuuid'] !== '' && !isset($reqopts[$model['ownerframeuuid']])) {
        $pinned = \local_kbacompany\local\owner_service::requirement_set($uuid, (int)$USER->id,
            $model['ownerframeuuid'], $model['ownerframerevision']);
        if ($pinned) {
            $reqopts[$model['ownerframeuuid']] = $pinned['label'] . ' — ' .
                get_string('marketmodel_requirement_count', 'local_kbacompany', count($pinned['requirements']));
        }
    }
    $philosophyopts = ['' => get_string('companycontext_none', 'local_kbacompany')];
    foreach ($data['published_philosophies'] as $row) {
        $philosophyopts[$row['uuid']] = $row['stablecode'] . ' · v' . $row['revision'] . ' — ' . shorten_text($row['mission'], 90);
    }
    $marketopts = ['' => get_string('companycontext_none', 'local_kbacompany')];
    foreach ($data['published_markets'] as $row) {
        $code = !empty($row['stablecode']) ? $row['stablecode'] : $row['code'];
        $marketopts[$row['uuid']] = $code . ' · v' . $row['revision'] . ' — ' . $row['name'];
    }
    echo $OUTPUT->heading($model ? get_string('bmc_edit_model', 'local_kbacompany') :
        get_string('bmc_create_model', 'local_kbacompany'), 3);
    if ($model && $model['status'] === 'published') {
        echo $OUTPUT->notification(get_string('bmc_new_revision_notice', 'local_kbacompany'), 'info');
    }
    echo html_writer::start_tag('form', ['method' => 'post', 'class' => 'kbacompany-form bms-record-edit']);
    echo $hidden('savemodel', $model ? ['modeluuid' => $model['uuid'], 'modelrevision' => $model['revision']] : []);
    if ($model) {
        echo html_writer::tag('div',
            html_writer::tag('label', get_string('bmc_stablecode', 'local_kbacompany'), ['class' => 'font-weight-bold']) .
            html_writer::tag('div', s($model['stablecode']), ['class' => 'form-control bg-light']), ['class' => 'form-group']);
    }
    echo html_writer::tag('div',
        html_writer::label(get_string('bmc_model_name', 'local_kbacompany'), 'bmc_name', false, ['class' => 'font-weight-bold']) .
        html_writer::empty_tag('input', ['id' => 'bmc_name', 'name' => 'name', 'required' => 'required',
            'class' => 'form-control', 'value' => $model['name'] ?? '']), ['class' => 'form-group']);
    echo html_writer::tag('div',
        html_writer::label(get_string('bmc_owner_requirements', 'local_kbacompany'), 'bmc_ownerframe', false,
            ['class' => 'font-weight-bold']) .
        html_writer::select($reqopts, 'ownerframeuuid', $model['ownerframeuuid'] ?? '', false,
            ['id' => 'bmc_ownerframe', 'class' => 'form-control']) .
        html_writer::tag('small', get_string('bmc_owner_requirements_help', 'local_kbacompany'), ['class' => 'form-text text-muted']),
        ['class' => 'form-group']);
    echo html_writer::tag('div',
        html_writer::label(get_string('companycontext_philosophy', 'local_kbacompany'), 'bmc_philosophy', false, ['class' => 'font-weight-bold']) .
        html_writer::select($philosophyopts, 'philosophyuuid', $model['philosophyuuid'] ?? '', false,
            ['id' => 'bmc_philosophy', 'class' => 'form-control']) .
        html_writer::tag('small', get_string('bmc_philosophy_help', 'local_kbacompany'), ['class' => 'form-text text-muted']),
        ['class' => 'form-group']);
    echo html_writer::tag('div',
        html_writer::label(get_string('companycontext_market', 'local_kbacompany'), 'bmc_market', false, ['class' => 'font-weight-bold']) .
        html_writer::select($marketopts, 'marketuuid', $model['marketuuid'] ?? '', false,
            ['id' => 'bmc_market', 'class' => 'form-control']) .
        html_writer::tag('small', get_string('bmc_market_help', 'local_kbacompany'), ['class' => 'form-text text-muted']),
        ['class' => 'form-group']);
    echo html_writer::tag('div',
        html_writer::label(get_string('bmc_horizon', 'local_kbacompany'), 'bmc_horizon', false, ['class' => 'font-weight-bold']) .
        html_writer::select($horizonlabels, 'horizon', $model['horizon'] ?? '', false,
            ['id' => 'bmc_horizon', 'class' => 'form-control']), ['class' => 'form-group']);
    echo html_writer::tag('div',
        html_writer::label(get_string('bmc_summary', 'local_kbacompany'), 'bmc_summary', false, ['class' => 'font-weight-bold']) .
        html_writer::tag('textarea', s($model['summary'] ?? ''), ['id' => 'bmc_summary', 'name' => 'summary',
            'rows' => 4, 'class' => 'form-control']) .
        html_writer::tag('small', get_string('bmc_summary_help', 'local_kbacompany'), ['class' => 'form-text text-muted']),
        ['class' => 'form-group']);
    echo html_writer::tag('div',
        html_writer::tag('button', get_string('savechanges'), ['type' => 'submit', 'class' => 'btn btn-primary mr-2']) .
        html_writer::link(new moodle_url('/local/kbacompany/business_model.php', $model ? [
            'uuid' => $uuid, 'model' => $model['uuid'], 'revision' => $model['revision'],
        ] : ['uuid' => $uuid]), get_string('cancel'), ['class' => 'btn btn-secondary']),
        ['class' => 'mt-3']);
    echo html_writer::end_tag('form');
    \local_kbacompany\local\bms_page_shell_service::finish($bmsshell);
    exit;
}

// Registry when no model selected.
if ($modeluuid === '') {
    echo $OUTPUT->heading(get_string('bmc_models', 'local_kbacompany'), 3);
    $models = bms_collection_view_service::filter_rows($data['business_models'], $q,
        ['stablecode', 'name', 'status']);
    $actionshtml = $canmanage ? html_writer::link(new moodle_url('/local/kbacompany/business_model.php', [
        'uuid' => $uuid, 'screen' => 'newmodel',
    ]), get_string('bmc_create_model', 'local_kbacompany'), ['class' => 'btn btn-sm btn-primary']) : '';
    echo bms_collection_view_service::toolbar('/local/kbacompany/business_model.php', ['uuid' => $uuid], $view, $q, $actionshtml);

    if (!$models) {
        echo $OUTPUT->notification(get_string('bmc_empty', 'local_kbacompany'), 'info');
    } else if ($view === 'list') {
        $table = new html_table();
        $table->head = [
            get_string('bmc_stablecode', 'local_kbacompany'), get_string('bmc_model_name', 'local_kbacompany'),
            get_string('bmc_horizon', 'local_kbacompany'), get_string('marketmodel_status', 'local_kbacompany'),
            get_string('marketmodel_revision', 'local_kbacompany'), get_string('bmc_updated', 'local_kbacompany'),
        ];
        foreach ($models as $row) {
            $canvas = $row['canvas'] ?? [];
            $href = new moodle_url('/local/kbacompany/business_model.php', [
                'uuid' => $uuid, 'model' => $row['uuid'], 'revision' => $row['revision'],
            ]);
            $table->data[] = [
                html_writer::link($href, s((string)($row['stablecode'] ?? ''))),
                html_writer::link($href, format_string($row['name'])),
                s($horizonlabels[(string)($canvas['horizon'] ?? '')] ?? (string)($canvas['horizon'] ?? '—')),
                s($statuslabel((string)$row['status'])),
                (int)$row['revision'],
                userdate((int)$row['timemodified']),
            ];
        }
        echo html_writer::table($table);
    } else if ($view === 'kanban') {
        $columns = [];
        foreach (['draft', 'published', 'archived'] as $status) {
            $columns[$status] = ['label' => $statuslabel($status), 'cards' => []];
        }
        foreach ($models as $row) {
            if (!isset($columns[$row['status']])) { continue; }
            $canvas = $row['canvas'] ?? [];
            $href = new moodle_url('/local/kbacompany/business_model.php', [
                'uuid' => $uuid, 'model' => $row['uuid'], 'revision' => $row['revision'],
            ]);
            $columns[$row['status']]['cards'][] = bms_collection_view_service::card(
                html_writer::link($href, s((string)($row['stablecode'] ?? '')) . ' — ' . format_string($row['name'])),
                [get_string('bmc_revision_label', 'local_kbacompany', (int)$row['revision']),
                    userdate((int)$row['timemodified'])],
                s($horizonlabels[(string)($canvas['horizon'] ?? '')] ?? (string)($canvas['horizon'] ?? '—')));
        }
        echo bms_collection_view_service::kanban($columns);
    } else {
        $counts = ['draft' => 0, 'published' => 0, 'archived' => 0];
        foreach ($models as $row) {
            if (isset($counts[$row['status']])) { $counts[$row['status']]++; }
        }
        echo bms_collection_view_service::analytics([
            ['label' => get_string('bmsview_total', 'local_kbacompany'), 'value' => count($models)],
            ['label' => $statuslabel('draft'), 'value' => $counts['draft']],
            ['label' => $statuslabel('published'), 'value' => $counts['published']],
            ['label' => $statuslabel('archived'), 'value' => $counts['archived']],
        ]);
    }
    echo html_writer::tag('div',
        $OUTPUT->heading(get_string('bmc_methodology_title', 'local_kbacompany'), 4) .
        html_writer::tag('p', get_string('bmc_methodology_overview', 'local_kbacompany'), ['class' => 'mb-1']) .
        html_writer::tag('p', get_string('bmc_methodology_flow', 'local_kbacompany'), ['class' => 'mb-0 font-weight-bold']),
        ['class' => 'alert alert-light border mt-4']);
    \local_kbacompany\local\bms_page_shell_service::finish($bmsshell);
    exit;
}

$model = business_model_crud_service::model($uuid, (int)$USER->id, $modeluuid, $modelrevision);
$readiness = business_model_crud_service::readiness($uuid, (int)$USER->id, $model);
$validation = business_model_validation_service::summary($uuid, (int)$USER->id, $model);
$schemas = business_model_ui_service::block_schema();
$allitems = business_model_crud_service::items($uuid, (int)$USER->id, $model['uuid'], $model['revision']);
$editable = $canmanage && $model['status'] === 'draft';

// Record header.
echo html_writer::start_tag('div', ['class' => 'bms-record-header border rounded p-3 mb-3 bg-white']);
echo html_writer::start_tag('div', ['class' => 'd-flex justify-content-between align-items-start']);
echo html_writer::start_tag('div');
echo html_writer::tag('div', s($model['stablecode']) . ' · ' . s($statuslabel($model['status'])),
    ['class' => 'text-muted small']);
echo $OUTPUT->heading(format_string($model['name']), 3, 'mb-1');
echo html_writer::tag('div', get_string('bmc_revision_label', 'local_kbacompany', $model['revision']) . ' · ' .
    s($horizonlabels[$model['horizon']] ?? $model['horizon']), ['class' => 'text-muted']);
echo html_writer::end_tag('div');
if ($canmanage) {
    echo html_writer::start_tag('div', ['class' => 'text-right']);
    echo html_writer::link(new moodle_url('/local/kbacompany/business_model.php', [
        'uuid' => $uuid, 'model' => $model['uuid'], 'revision' => $model['revision'], 'screen' => 'editmodel',
    ]), $model['status'] === 'published' ? get_string('bmc_create_revision', 'local_kbacompany') : get_string('edit'),
        ['class' => 'btn btn-outline-primary mr-2']);
    if ($model['status'] === 'draft') {
        echo html_writer::start_tag('form', ['method' => 'post', 'class' => 'd-inline mr-2']);
        echo $hidden('publishmodel', ['modeluuid' => $model['uuid'], 'modelrevision' => $model['revision']]);
        echo html_writer::tag('button', get_string('marketmodel_publish', 'local_kbacompany'),
            ['type' => 'submit', 'class' => 'btn btn-primary']);
        echo html_writer::end_tag('form');
    }
    echo html_writer::start_tag('form', ['method' => 'post', 'class' => 'd-inline']);
    echo $hidden('archivemodel', ['modeluuid' => $model['uuid'], 'modelrevision' => $model['revision']]);
    echo html_writer::tag('button', get_string('bmc_archive', 'local_kbacompany'), [
        'type' => 'submit', 'class' => 'btn btn-outline-danger',
        'onclick' => "return confirm('" . addslashes_js(get_string('bmc_archive_confirm', 'local_kbacompany')) . "')",
    ]);
    echo html_writer::end_tag('form');
    echo html_writer::end_tag('div');
}
echo html_writer::end_tag('div');
echo html_writer::end_tag('div');

// Readiness counters.
$cards = [
    [get_string('bmc_readiness_asis', 'local_kbacompany'), $readiness['asis_blocks'] . '/9'],
    [get_string('bmc_readiness_target', 'local_kbacompany'), $readiness['target_blocks'] . '/9'],
    [get_string('bmc_readiness_req', 'local_kbacompany'), $readiness['requirements_linked'] . '/' . $readiness['requirements_total']],
    [get_string('bmc_readiness_evidence', 'local_kbacompany'),
        get_string('bmc_count_fact', 'local_kbacompany') . ' ' . $readiness['fact'] . ' · ' .
        get_string('bmc_count_data', 'local_kbacompany') . ' ' . $readiness['data'] . ' · ' .
        get_string('bmc_count_hyp', 'local_kbacompany') . ' ' . $readiness['hyp'] . ' · ' .
        get_string('bmc_count_open', 'local_kbacompany') . ' ' . $readiness['open']],
    [get_string('bmcval_readiness', 'local_kbacompany'), $validation['confirmed_contours'] . '/5'],
];
echo html_writer::start_tag('div', ['class' => 'row mb-3']);
foreach ($cards as $card) {
    echo html_writer::tag('div', html_writer::tag('div',
        html_writer::tag('div', s($card[0]), ['class' => 'text-muted small']) .
        html_writer::tag('div', s($card[1]), ['class' => 'font-weight-bold']), ['class' => 'border rounded p-3 bg-white h-100']),
        ['class' => 'col-md-3 mb-2']);
}
echo html_writer::end_tag('div');

// Tabs.
$tabs = [
    'canvas' => get_string('bmc_tab_canvas', 'local_kbacompany'),
    'detail' => get_string('bmc_tab_detail', 'local_kbacompany'),
    'requirements' => get_string('bmc_tab_requirements', 'local_kbacompany'),
    'validation' => get_string('bmcval_tab', 'local_kbacompany'),
    'gaps' => get_string('bmcgap_tab', 'local_kbacompany'),
];
if ($canmanage) {
    $tabs['history'] = get_string('bmc_tab_history', 'local_kbacompany');
}
if ($screen === '' || !isset($tabs[$screen])) {
    $screen = 'canvas';
}
if (in_array($screen, ['block', 'item', 'edititem', 'newitem', 'gap', 'editgap', 'newgap'], true)) {
    // Keep nested screens out of tab active logic.
} else {
    echo html_writer::start_tag('ul', ['class' => 'nav nav-tabs mb-3']);
    foreach ($tabs as $key => $label) {
        echo html_writer::tag('li', html_writer::link(new moodle_url('/local/kbacompany/business_model.php', [
            'uuid' => $uuid, 'model' => $model['uuid'], 'revision' => $model['revision'], 'screen' => $key,
        ]), $label, ['class' => 'nav-link' . ($screen === $key ? ' active' : '')]), ['class' => 'nav-item']);
    }
    echo html_writer::tag('li', html_writer::link(new moodle_url('/local/kbacompany/business_model_legacy.php', [
        'uuid' => $uuid, 'edituuid' => $editable ? $model['uuid'] : '',
    ]), get_string('bmc_tab_legacy', 'local_kbacompany'), ['class' => 'nav-link']), ['class' => 'nav-item']);
    echo html_writer::end_tag('ul');
}

// Item create/edit screen.
if ($screen === 'newitem' || $screen === 'edititem') {
    if (!$editable) {
        throw new moodle_exception('bmc_modelnoteditable', 'local_kbacompany');
    }
    $item = null;
    if ($screen === 'edititem') {
        $item = business_model_crud_service::item($uuid, (int)$USER->id, $model['uuid'], $model['revision'],
            $itemuuid, $itemrevision);
        $block = $item['block'];
        $stateparam = $item['state'];
    }
    if (!isset($schemas[$block]) || !in_array($stateparam, business_model_crud_service::STATES, true)) {
        throw new moodle_exception('invalidparameter');
    }
    $schema = $schemas[$block];
    $reqoptions = business_model_crud_service::requirement_options($uuid, (int)$USER->id, $model);
    $positionoptions = ['' => get_string('bmc_position_none', 'local_kbacompany')] +
        business_model_crud_service::position_options($uuid, (int)$USER->id);
    $selectedreqs = [];
    if ($item) {
        foreach ($item['requirements'] as $req) {
            $selectedreqs[] = $req['code'];
        }
    }
    echo html_writer::link(new moodle_url('/local/kbacompany/business_model.php', [
        'uuid' => $uuid, 'model' => $model['uuid'], 'revision' => $model['revision'],
        'screen' => 'block', 'block' => $block,
    ]), '← ' . get_string('bmc_back_to_block', 'local_kbacompany'), ['class' => 'd-inline-block mb-3']);
    echo $OUTPUT->heading(($item ? get_string('bmc_edit_item', 'local_kbacompany') : get_string('bmc_create_item', 'local_kbacompany')) .
        ' — ' . $schema['label'], 3);
    echo html_writer::tag('div',
        html_writer::tag('div', html_writer::tag('strong', get_string('bmc_method_hint', 'local_kbacompany')) . ' ' . s($schema['purpose']), ['class' => 'mb-2']) .
        html_writer::tag('div', html_writer::tag('strong', get_string('bmc_method_questions', 'local_kbacompany')) . ' ' . s($schema['questions']), ['class' => 'mb-2']) .
        html_writer::tag('div', html_writer::tag('strong', get_string('bmc_method_mistake', 'local_kbacompany')) . ' ' . s($schema['mistake']), ['class' => 'mb-2']) .
        html_writer::tag('div', s(business_model_ui_service::state_help($stateparam)), ['class' => 'text-primary']),
        ['class' => 'alert alert-light border']);

    echo html_writer::start_tag('form', ['method' => 'post', 'class' => 'bms-record-edit']);
    echo $hidden('saveitem', [
        'modeluuid' => $model['uuid'], 'modelrevision' => $model['revision'],
        'itemuuid' => $item['uuid'] ?? '', 'itemrevision' => $item['revision'] ?? 0,
        'block' => $block, 'state' => $stateparam,
    ]);
    echo html_writer::tag('div',
        html_writer::tag('label', get_string('bmc_stablecode', 'local_kbacompany'), ['class' => 'font-weight-bold']) .
        html_writer::tag('div', $item ? s($item['stablecode']) : get_string('bmc_code_auto', 'local_kbacompany'),
            ['class' => 'form-control bg-light']), ['class' => 'form-group']);
    echo html_writer::tag('div',
        html_writer::tag('label', get_string('bmc_state', 'local_kbacompany'), ['class' => 'font-weight-bold']) .
        html_writer::tag('div', s($statelabel($stateparam)), ['class' => 'form-control bg-light']), ['class' => 'form-group']);
    echo html_writer::tag('div',
        html_writer::label(get_string('bmc_item_title', 'local_kbacompany'), 'bmc_item_title', false, ['class' => 'font-weight-bold']) .
        html_writer::empty_tag('input', ['id' => 'bmc_item_title', 'name' => 'title', 'required' => 'required',
            'class' => 'form-control', 'value' => $item['title'] ?? '']), ['class' => 'form-group']);
    echo html_writer::tag('div',
        html_writer::label(get_string('bmc_formulation', 'local_kbacompany'), 'bmc_formulation', false, ['class' => 'font-weight-bold']) .
        html_writer::tag('textarea', s($item['formulation'] ?? ''), ['id' => 'bmc_formulation', 'name' => 'formulation',
            'rows' => 5, 'required' => 'required', 'class' => 'form-control']) .
        html_writer::tag('small', get_string($stateparam === 'target' ? 'bmc_target_form_help' : 'bmc_asis_form_help',
            'local_kbacompany'), ['class' => 'form-text text-muted']), ['class' => 'form-group']);

    $markeroptions = [];
    $markers = $stateparam === 'target' ? business_model_crud_service::TARGET_MARKERS : business_model_crud_service::ASIS_MARKERS;
    foreach ($markers as $marker) {
        $markeroptions[$marker] = $markerlabel($marker);
    }
    echo html_writer::tag('div',
        html_writer::label(get_string('bmc_marker', 'local_kbacompany'), 'bmc_marker', false, ['class' => 'font-weight-bold']) .
        html_writer::select($markeroptions, 'marker', $item['marker'] ?? ($stateparam === 'target' ? 'target' : 'fact'), false,
            ['id' => 'bmc_marker', 'class' => 'form-control']) .
        html_writer::tag('small', get_string('bmc_marker_help', 'local_kbacompany'), ['class' => 'form-text text-muted']),
        ['class' => 'form-group']);

    $intentoptions = ['' => get_string('bmc_intent_none', 'local_kbacompany')];
    foreach (['yellow', 'orange', 'pink', 'red', 'green', 'blue'] as $intent) {
        $intentoptions[$intent] = get_string('bmc_intent_' . $intent, 'local_kbacompany');
    }
    echo html_writer::tag('div',
        html_writer::label(get_string('bmc_intent', 'local_kbacompany'), 'bmc_intent', false, ['class' => 'font-weight-bold']) .
        html_writer::select($intentoptions, 'intent', $item['intent'] ?? '', false,
            ['id' => 'bmc_intent', 'class' => 'form-control']) .
        html_writer::tag('small', get_string('bmc_intent_help', 'local_kbacompany'), ['class' => 'form-text text-muted']),
        ['class' => 'form-group']);
    echo html_writer::tag('div',
        html_writer::label(get_string('bmc_horizon', 'local_kbacompany'), 'bmc_item_horizon', false, ['class' => 'font-weight-bold']) .
        html_writer::select($horizonlabels, 'horizon', $item['horizon'] ?? '', false,
            ['id' => 'bmc_item_horizon', 'class' => 'form-control']), ['class' => 'form-group']);
    echo html_writer::tag('div',
        html_writer::label(get_string('bmc_position', 'local_kbacompany'), 'bmc_position', false, ['class' => 'font-weight-bold']) .
        html_writer::select($positionoptions, 'ownerpositionuuid', $item['ownerpositionuuid'] ?? '', false,
            ['id' => 'bmc_position', 'class' => 'form-control']) .
        html_writer::tag('small', get_string('bmc_position_help', 'local_kbacompany'), ['class' => 'form-text text-muted']),
        ['class' => 'form-group']);

    echo $OUTPUT->heading(get_string('bmc_evidence_section', 'local_kbacompany'), 4);
    echo html_writer::tag('div',
        html_writer::label(get_string('bmc_evidence', 'local_kbacompany'), 'bmc_evidence', false, ['class' => 'font-weight-bold']) .
        html_writer::tag('textarea', s($item['evidence'] ?? ''), ['id' => 'bmc_evidence', 'name' => 'evidence',
            'rows' => 3, 'class' => 'form-control']), ['class' => 'form-group']);
    echo html_writer::tag('div',
        html_writer::label(get_string('bmc_source', 'local_kbacompany'), 'bmc_source', false, ['class' => 'font-weight-bold']) .
        html_writer::tag('textarea', s($item['source'] ?? ''), ['id' => 'bmc_source', 'name' => 'source',
            'rows' => 2, 'class' => 'form-control']) .
        html_writer::tag('small', get_string('bmc_source_help', 'local_kbacompany'), ['class' => 'form-text text-muted']),
        ['class' => 'form-group']);
    echo html_writer::tag('div',
        html_writer::label(get_string('bmc_testcriteria', 'local_kbacompany'), 'bmc_testcriteria', false,
            ['class' => 'font-weight-bold']) .
        html_writer::tag('textarea', s($item['testcriteria'] ?? ''), ['id' => 'bmc_testcriteria', 'name' => 'testcriteria',
            'rows' => 2, 'class' => 'form-control']) .
        html_writer::tag('small', get_string('bmc_testcriteria_help', 'local_kbacompany'), ['class' => 'form-text text-muted']),
        ['class' => 'form-group']);

    echo $OUTPUT->heading(get_string('bmc_req_section', 'local_kbacompany'), 4);
    if (!$reqoptions) {
        echo $OUTPUT->notification(get_string('bmc_no_req_package', 'local_kbacompany'), 'info');
    } else {
        foreach ($reqoptions as $code => $label) {
            $id = 'req_' . preg_replace('/[^a-z0-9]+/i', '_', $code);
            $attrs = ['type' => 'checkbox', 'name' => 'requirements[]', 'value' => $code, 'id' => $id];
            if (in_array($code, $selectedreqs, true)) {
                $attrs['checked'] = 'checked';
            }
            echo html_writer::tag('div',
                html_writer::empty_tag('input', $attrs) . ' ' . html_writer::label($label, $id),
                ['class' => 'form-check mb-2']);
        }
    }

    echo html_writer::start_tag('details', ['class' => 'border rounded p-3 mb-3']);
    echo html_writer::tag('summary', get_string('bmc_advanced', 'local_kbacompany'), ['class' => 'font-weight-bold']);
    echo html_writer::tag('div',
        html_writer::label(get_string('bmc_sortorder', 'local_kbacompany'), 'bmc_sortorder', false, ['class' => 'font-weight-bold']) .
        html_writer::empty_tag('input', ['type' => 'number', 'name' => 'sortorder', 'id' => 'bmc_sortorder',
            'class' => 'form-control', 'value' => $item['sortorder'] ?? 0, 'min' => 0]), ['class' => 'form-group mt-3']);
    echo html_writer::end_tag('details');
    echo html_writer::tag('div',
        html_writer::tag('button', get_string('savechanges'), ['type' => 'submit', 'class' => 'btn btn-primary mr-2']) .
        html_writer::link(new moodle_url('/local/kbacompany/business_model.php', [
            'uuid' => $uuid, 'model' => $model['uuid'], 'revision' => $model['revision'],
            'screen' => 'block', 'block' => $block,
        ]), get_string('cancel'), ['class' => 'btn btn-secondary']), ['class' => 'mt-3']);
    echo html_writer::end_tag('form');
    \local_kbacompany\local\bms_page_shell_service::finish($bmsshell);
    exit;
}

// Single item DetailView.
if ($screen === 'item') {
    $item = business_model_crud_service::item($uuid, (int)$USER->id, $model['uuid'], $model['revision'],
        $itemuuid, $itemrevision);
    $schema = $schemas[$item['block']];
    $comments = business_model_crud_service::comments($uuid, (int)$USER->id, $item['uuid'], $item['revision']);
    $history = business_model_crud_service::item_history($uuid, (int)$USER->id, $item['uuid']);
    $positions = business_model_crud_service::position_options($uuid, (int)$USER->id);
    echo html_writer::link(new moodle_url('/local/kbacompany/business_model.php', [
        'uuid' => $uuid, 'model' => $model['uuid'], 'revision' => $model['revision'],
        'screen' => 'block', 'block' => $item['block'],
    ]), '← ' . $schema['label'], ['class' => 'd-inline-block mb-3']);
    echo html_writer::start_tag('div', ['class' => 'border rounded p-3 bg-white']);
    echo html_writer::start_tag('div', ['class' => 'd-flex justify-content-between align-items-start mb-3']);
    echo html_writer::tag('div',
        html_writer::tag('div', s($item['stablecode']) . ' · ' . s($statelabel($item['state'])) . ' · ' .
            s($markerlabel($item['marker'])), ['class' => 'small text-muted']) .
        $OUTPUT->heading(format_string($item['title']), 3, 'mb-0'));
    if ($editable) {
        echo html_writer::tag('div',
            html_writer::link(new moodle_url('/local/kbacompany/business_model.php', [
                'uuid' => $uuid, 'model' => $model['uuid'], 'revision' => $model['revision'],
                'screen' => 'edititem', 'item' => $item['uuid'], 'itemrev' => $item['revision'],
            ]), get_string('edit'), ['class' => 'btn btn-outline-primary mr-2']) .
            html_writer::start_tag('form', ['method' => 'post', 'class' => 'd-inline']) .
            $hidden('archiveitem', ['modeluuid' => $model['uuid'], 'modelrevision' => $model['revision'],
                'itemuuid' => $item['uuid'], 'itemrevision' => $item['revision'], 'block' => $item['block']]) .
            html_writer::tag('button', get_string('bmc_archive', 'local_kbacompany'), ['type' => 'submit',
                'class' => 'btn btn-outline-danger', 'onclick' => "return confirm('" .
                    addslashes_js(get_string('bmc_item_archive_confirm', 'local_kbacompany')) . "')"]) .
            html_writer::end_tag('form'));
    }
    echo html_writer::end_tag('div');
    $fields = [
        get_string('bmc_block', 'local_kbacompany') => $schema['label'],
        get_string('bmc_state', 'local_kbacompany') => $statelabel($item['state']),
        get_string('bmc_marker', 'local_kbacompany') => $markerlabel($item['marker']),
        get_string('bmc_horizon', 'local_kbacompany') => $horizonlabels[$item['horizon']] ?? $item['horizon'],
        get_string('bmc_position', 'local_kbacompany') => $positions[$item['ownerpositionuuid']] ?? '—',
    ];
    echo html_writer::start_tag('div', ['class' => 'row']);
    foreach ($fields as $label => $value) {
        echo html_writer::tag('div', html_writer::tag('div', s($label), ['class' => 'small text-muted']) .
            html_writer::tag('div', s((string)$value), ['class' => 'font-weight-bold']), ['class' => 'col-md-4 mb-3']);
    }
    echo html_writer::end_tag('div');
    echo $OUTPUT->heading(get_string('bmc_formulation', 'local_kbacompany'), 4);
    echo html_writer::tag('div', nl2br(s($item['formulation'])), ['class' => 'mb-4']);
    if ($item['evidence'] !== '' || $item['source'] !== '' || $item['testcriteria'] !== '') {
        echo $OUTPUT->heading(get_string('bmc_evidence_section', 'local_kbacompany'), 4);
        if ($item['evidence'] !== '') echo html_writer::tag('p', nl2br(s($item['evidence'])));
        if ($item['source'] !== '') echo html_writer::tag('p', html_writer::tag('strong', get_string('bmc_source', 'local_kbacompany') . ': ') . nl2br(s($item['source'])));
        if ($item['testcriteria'] !== '') echo html_writer::tag('p', html_writer::tag('strong', get_string('bmc_testcriteria', 'local_kbacompany') . ': ') . nl2br(s($item['testcriteria'])));
    }
    echo $OUTPUT->heading(get_string('bmc_req_section', 'local_kbacompany'), 4);
    if (!$item['requirements']) {
        echo html_writer::tag('p', '—', ['class' => 'text-muted']);
    } else {
        foreach ($item['requirements'] as $req) {
            echo html_writer::tag('span', s($req['code']), ['class' => 'badge badge-light border mr-2']);
        }
    }
    echo html_writer::end_tag('div');

    echo $OUTPUT->heading(get_string('bmc_discussion', 'local_kbacompany'), 4, 'mt-4');
    if (!$comments) {
        echo html_writer::tag('p', get_string('bmc_no_comments', 'local_kbacompany'), ['class' => 'text-muted']);
    } else {
        foreach ($comments as $comment) {
            echo html_writer::tag('div',
                html_writer::tag('div', s($comment['authorname']) . ' · ' . userdate($comment['timecreated']), ['class' => 'small text-muted']) .
                html_writer::tag('div', nl2br(s($comment['comment']))), ['class' => 'border-bottom py-2']);
        }
    }
    if ($canmanage) {
        echo html_writer::start_tag('form', ['method' => 'post', 'class' => 'mt-3']);
        echo $hidden('comment', ['modeluuid' => $model['uuid'], 'modelrevision' => $model['revision'],
            'itemuuid' => $item['uuid'], 'itemrevision' => $item['revision']]);
        echo html_writer::tag('textarea', '', ['name' => 'comment', 'rows' => 3, 'class' => 'form-control',
            'required' => 'required', 'placeholder' => get_string('bmc_comment_placeholder', 'local_kbacompany')]);
        echo html_writer::tag('button', get_string('bmc_add_comment', 'local_kbacompany'),
            ['type' => 'submit', 'class' => 'btn btn-outline-primary mt-2']);
        echo html_writer::end_tag('form');
    }

    echo $OUTPUT->heading(get_string('bmc_change_history', 'local_kbacompany'), 4, 'mt-4');
    $table = new html_table();
    $table->head = [get_string('marketmodel_revision', 'local_kbacompany'), get_string('bmc_state', 'local_kbacompany'),
        get_string('bmc_marker', 'local_kbacompany'), get_string('bmc_updated', 'local_kbacompany')];
    foreach ($history as $version) {
        $table->data[] = [(int)$version['revision'], s($statelabel($version['state'])), s($markerlabel($version['marker'])),
            userdate((int)$version['timemodified'])];
    }
    echo html_writer::table($table);
    \local_kbacompany\local\bms_page_shell_service::finish($bmsshell);
    exit;
}

// One Canvas block List/Detail aggregate.
if ($screen === 'block') {
    if (!isset($schemas[$block])) {
        throw new moodle_exception('invalidparameter');
    }
    $schema = $schemas[$block];
    $items = business_model_crud_service::items($uuid, (int)$USER->id, $model['uuid'], $model['revision'], $block);
    echo html_writer::link(new moodle_url('/local/kbacompany/business_model.php', [
        'uuid' => $uuid, 'model' => $model['uuid'], 'revision' => $model['revision'], 'screen' => 'canvas',
    ]), '← ' . get_string('bmc_tab_canvas', 'local_kbacompany'), ['class' => 'd-inline-block mb-3']);
    echo $OUTPUT->heading($schema['label'], 3);
    echo html_writer::tag('div',
        html_writer::tag('p', s($schema['purpose']), ['class' => 'mb-2']) .
        html_writer::tag('p', html_writer::tag('strong', get_string('bmc_method_questions', 'local_kbacompany') . ': ') . s($schema['questions']), ['class' => 'mb-2']) .
        html_writer::tag('p', html_writer::tag('strong', get_string('bmc_method_mistake', 'local_kbacompany') . ': ') . s($schema['mistake']), ['class' => 'mb-0']),
        ['class' => 'alert alert-light border']);
    echo html_writer::start_tag('div', ['class' => 'row']);
    foreach (['asis', 'target'] as $state) {
        echo html_writer::start_tag('div', ['class' => 'col-lg-6 mb-3']);
        echo html_writer::start_tag('div', ['class' => 'border rounded bg-white h-100']);
        echo html_writer::start_tag('div', ['class' => 'p-3 border-bottom d-flex justify-content-between align-items-center']);
        echo html_writer::tag('div', html_writer::tag('strong', $statelabel($state)) .
            html_writer::tag('div', s(business_model_ui_service::state_help($state)), ['class' => 'small text-muted']));
        if ($editable) {
            echo html_writer::link(new moodle_url('/local/kbacompany/business_model.php', [
                'uuid' => $uuid, 'model' => $model['uuid'], 'revision' => $model['revision'], 'screen' => 'newitem',
                'block' => $block, 'state' => $state,
            ]), get_string('bmc_add_item', 'local_kbacompany'), ['class' => 'btn btn-sm btn-outline-primary']);
        }
        echo html_writer::end_tag('div');
        $stateitems = array_values(array_filter($items, static function(array $item) use ($state): bool {
            return $item['state'] === $state;
        }));
        if (!$stateitems) {
            echo html_writer::tag('div', get_string('bmc_no_items_state', 'local_kbacompany'), ['class' => 'p-3 text-muted']);
        } else {
            foreach ($stateitems as $item) {
                $href = new moodle_url('/local/kbacompany/business_model.php', [
                    'uuid' => $uuid, 'model' => $model['uuid'], 'revision' => $model['revision'],
                    'screen' => 'item', 'item' => $item['uuid'], 'itemrev' => $item['revision'],
                ]);
                echo html_writer::tag('div',
                    html_writer::tag('div',
                        html_writer::tag('span', s($markerlabel($item['marker'])), ['class' => 'badge badge-light border mr-2']) .
                        html_writer::tag('span', s($item['stablecode']), ['class' => 'small text-muted'])) .
                    html_writer::link($href, format_string($item['title']), ['class' => 'font-weight-bold d-block mt-1']) .
                    html_writer::tag('div', shorten_text(strip_tags($item['formulation']), 180), ['class' => 'small text-muted mt-1']) .
                    ($item['requirements'] ? html_writer::tag('div', implode(' ', array_map(static function(array $req): string {
                        return s($req['code']);
                    }, $item['requirements'])), ['class' => 'small mt-2']) : ''),
                    ['class' => 'p-3 border-bottom']);
            }
        }
        echo html_writer::end_tag('div');
        echo html_writer::end_tag('div');
    }
    echo html_writer::end_tag('div');
    \local_kbacompany\local\bms_page_shell_service::finish($bmsshell);
    exit;
}

if ($screen === 'detail') {
    echo $OUTPUT->heading(get_string('bmc_tab_detail', 'local_kbacompany'), 3);
    $reqsource = $model['ownerframeuuid'] !== '' ? get_string('marketmodel_source_revision', 'local_kbacompany',
        $model['ownerframerevision']) : get_string('bmc_no_req_package', 'local_kbacompany');
    $philosophyref = get_string('companycontext_none', 'local_kbacompany');
    if (!empty($model['philosophyuuid']) && !empty($model['philosophyrevision'])) {
        $pinnedphilosophy = \local_kbacompany\local\company_context_domain_service::philosophy($uuid, (int)$USER->id,
            $model['philosophyuuid'], (int)$model['philosophyrevision']);
        $philosophyref = $pinnedphilosophy['stablecode'] . ' · v' . $pinnedphilosophy['revision'];
    }
    $marketref = get_string('companycontext_none', 'local_kbacompany');
    if (!empty($model['marketuuid']) && !empty($model['marketrevision'])) {
        $pinnedmarket = \local_kbacompany\local\company_context_domain_service::market($uuid, (int)$USER->id,
            $model['marketuuid'], (int)$model['marketrevision']);
        $marketref = (!empty($pinnedmarket['stablecode']) ? $pinnedmarket['stablecode'] : $pinnedmarket['code']) .
            ' · v' . $pinnedmarket['revision'];
    }
    $table = new html_table();
    $table->data = [
        [get_string('bmc_stablecode', 'local_kbacompany'), s($model['stablecode'])],
        [get_string('bmc_model_name', 'local_kbacompany'), format_string($model['name'])],
        [get_string('marketmodel_status', 'local_kbacompany'), s($statuslabel($model['status']))],
        [get_string('marketmodel_revision', 'local_kbacompany'), (int)$model['revision']],
        [get_string('bmc_horizon', 'local_kbacompany'), s($horizonlabels[$model['horizon']] ?? $model['horizon'])],
        [get_string('bmc_owner_requirements', 'local_kbacompany'), s($reqsource)],
        [get_string('companycontext_philosophy', 'local_kbacompany'), s($philosophyref)],
        [get_string('companycontext_market', 'local_kbacompany'), s($marketref)],
        [get_string('bmc_summary', 'local_kbacompany'), nl2br(s($model['summary']))],
    ];
    echo html_writer::table($table);
    \local_kbacompany\local\bms_page_shell_service::finish($bmsshell);
    exit;
}

if ($screen === 'requirements') {
    echo $OUTPUT->heading(get_string('bmc_tab_requirements', 'local_kbacompany'), 3);
    $reqoptions = business_model_crud_service::requirement_options($uuid, (int)$USER->id, $model);
    if (!$reqoptions) {
        echo $OUTPUT->notification(get_string('bmc_no_req_package', 'local_kbacompany'), 'info');
    } else {
        $coverage = [];
        foreach ($allitems as $item) {
            if ($item['state'] !== 'target') {
                continue;
            }
            foreach ($item['requirements'] as $req) {
                $coverage[$req['code']][] = $item;
            }
        }
        $table = new html_table();
        $table->head = [get_string('bmc_req_code', 'local_kbacompany'), get_string('bmc_req_text', 'local_kbacompany'),
            get_string('bmc_req_coverage', 'local_kbacompany')];
        foreach ($reqoptions as $code => $label) {
            $text = preg_replace('/^' . preg_quote($code, '/') . '\s+—\s+/u', '', $label);
            $links = [];
            foreach ($coverage[$code] ?? [] as $item) {
                $links[] = html_writer::link(new moodle_url('/local/kbacompany/business_model.php', [
                    'uuid' => $uuid, 'model' => $model['uuid'], 'revision' => $model['revision'],
                    'screen' => 'item', 'item' => $item['uuid'], 'itemrev' => $item['revision'],
                ]), $item['stablecode'] . ' ' . $item['title']);
            }
            $table->data[] = [s($code), s($text), $links ? implode('<br>', $links) :
                html_writer::tag('span', get_string('bmcval_req_not_target', 'local_kbacompany'), ['class' => 'text-warning'])];
        }
        echo html_writer::table($table);
        echo html_writer::tag('p', get_string('bmc_req_coverage_notice', 'local_kbacompany'), ['class' => 'small text-muted']);
    }
    \local_kbacompany\local\bms_page_shell_service::finish($bmsshell);
    exit;
}


if ($screen === 'validation') {
    echo $OUTPUT->heading(get_string('bmcval_title', 'local_kbacompany'), 3);
    echo html_writer::tag('p', get_string('bmcval_intro', 'local_kbacompany'), ['class' => 'text-muted']);
    $agreeclass = $validation['agreed'] ? 'alert alert-success' : 'alert alert-warning';
    echo html_writer::tag('div', $validation['agreed'] ?
        get_string('bmcval_agreed', 'local_kbacompany') : get_string('bmcval_not_agreed', 'local_kbacompany'),
        ['class' => $agreeclass]);
    if (!$validation['requirements_covered']) {
        echo $OUTPUT->notification(get_string('bmcval_req_block', 'local_kbacompany', (object)[
            'linked' => $validation['requirements_linked'], 'total' => $validation['requirements_total'],
        ]), 'warning');
    }
    $stateoptions = [];
    foreach (business_model_validation_service::VALIDATION_STATES as $st) {
        $stateoptions[$st] = $validationstatelabel($st);
    }
    foreach (business_model_validation_service::CONTOURS as $contour) {
        $row = $validation['validations'][$contour];
        echo html_writer::start_tag('div', ['class' => 'border rounded bg-white p-3 mb-3']);
        echo $OUTPUT->heading(get_string('bmcval_contour_' . $contour, 'local_kbacompany'), 4, 'mb-2');
        echo html_writer::tag('p', get_string('bmcval_help_' . $contour, 'local_kbacompany'), ['class' => 'small text-muted']);
        echo html_writer::start_tag('form', ['method' => 'post']);
        echo $hidden('savevalidation', ['modeluuid' => $model['uuid'], 'modelrevision' => $model['revision'], 'contour' => $contour]);
        echo html_writer::tag('div',
            html_writer::label(get_string('bmcval_state', 'local_kbacompany'), 'valstate_' . $contour, false,
                ['class' => 'font-weight-bold']) .
            html_writer::select($stateoptions, 'validationstate', $row['state'] ?? 'open', false,
                ['id' => 'valstate_' . $contour, 'class' => 'form-control']), ['class' => 'form-group']);
        if ($contour === 'economics') {
            $financeopts = ['' => get_string('bmcval_finance_none', 'local_kbacompany')] + $validation['financial_scenarios'];
            $selectedfinance = $row && $row['finscenariouuid'] !== '' ?
                $row['finscenariouuid'] . '|' . $row['finscenariorevision'] : '';
            echo html_writer::tag('div',
                html_writer::label(get_string('bmcval_finance_scenario', 'local_kbacompany'), 'valfinance', false,
                    ['class' => 'font-weight-bold']) .
                html_writer::select($financeopts, 'finscenario', $selectedfinance, false,
                    ['id' => 'valfinance', 'class' => 'form-control']) .
                html_writer::tag('small', get_string('bmcval_finance_help', 'local_kbacompany'),
                    ['class' => 'form-text text-muted']), ['class' => 'form-group']);
            if (!$validation['financial_scenarios']) {
                echo $OUTPUT->notification(get_string('bmcval_finance_missing', 'local_kbacompany'), 'info');
            }
        }
        echo html_writer::tag('div',
            html_writer::label(get_string('bmcval_note', 'local_kbacompany'), 'valnote_' . $contour, false,
                ['class' => 'font-weight-bold']) .
            html_writer::tag('textarea', s($row['note'] ?? ''), ['id' => 'valnote_' . $contour, 'name' => 'note',
                'rows' => 3, 'class' => 'form-control']), ['class' => 'form-group']);
        echo html_writer::tag('div',
            html_writer::label(get_string('bmcval_evidence', 'local_kbacompany'), 'valevidence_' . $contour, false,
                ['class' => 'font-weight-bold']) .
            html_writer::tag('textarea', s($row['evidence'] ?? ''), ['id' => 'valevidence_' . $contour, 'name' => 'evidence',
                'rows' => 2, 'class' => 'form-control']), ['class' => 'form-group']);
        if ($canmanage) {
            echo html_writer::tag('button', get_string('savechanges'), ['type' => 'submit', 'class' => 'btn btn-primary']);
        }
        echo html_writer::end_tag('form');
        echo html_writer::end_tag('div');
    }
    \local_kbacompany\local\bms_page_shell_service::finish($bmsshell);
    exit;
}

if ($screen === 'newgap' || $screen === 'editgap') {
    if (!$canmanage) {
        throw new required_capability_exception($context, 'local/kbacompany:manageorganization', 'nopermissions', '');
    }
    $gap = null;
    if ($screen === 'editgap') {
        $gap = business_model_validation_service::gap($uuid, (int)$USER->id, $model, $gapuuid);
        if ($gap['status'] === 'archived') {
            throw new moodle_exception('bmcval_invalidstate', 'local_kbacompany');
        }
    }
    $asis = array_values(array_filter($allitems, static function(array $item): bool { return $item['state'] === 'asis'; }));
    $targets = array_values(array_filter($allitems, static function(array $item): bool { return $item['state'] === 'target'; }));
    $itemoptions = static function(array $items) use ($schemas): array {
        $out = ['' => get_string('choosedots')];
        foreach ($items as $item) {
            $out[$item['uuid']] = $item['stablecode'] . ' — ' . $schemas[$item['block']]['label'] . ' — ' . $item['title'];
        }
        return $out;
    };
    $reqoptions = business_model_crud_service::requirement_options($uuid, (int)$USER->id, $model);
    $indicatoroptions = ['' => get_string('bmcgap_indicator_none', 'local_kbacompany')] +
        business_model_validation_service::indicator_options($uuid, (int)$USER->id);
    echo $OUTPUT->heading($gap ? get_string('bmcgap_edit', 'local_kbacompany') :
        get_string('bmcgap_create', 'local_kbacompany'), 3);
    echo html_writer::tag('div', get_string('bmcgap_method', 'local_kbacompany'), ['class' => 'alert alert-light border']);
    echo html_writer::start_tag('form', ['method' => 'post', 'class' => 'bms-record-edit']);
    echo $hidden('savegap', ['modeluuid' => $model['uuid'], 'modelrevision' => $model['revision']] +
        ($gap ? ['gapuuid' => $gap['uuid'], 'gaprevision' => $gap['revision']] : []));
    if ($gap) {
        echo html_writer::tag('div', html_writer::tag('strong', get_string('bmcgap_code', 'local_kbacompany') . ': ') .
            s($gap['stablecode']), ['class' => 'mb-3']);
    }
    echo html_writer::tag('div',
        html_writer::label(get_string('bmcgap_asis', 'local_kbacompany'), 'gapasis', false, ['class' => 'font-weight-bold']) .
        html_writer::select($itemoptions($asis), 'asisitemuuid', $gap['asisitemuuid'] ?? '', false,
            ['id' => 'gapasis', 'class' => 'form-control', 'required' => 'required']), ['class' => 'form-group']);
    echo html_writer::tag('div',
        html_writer::label(get_string('bmcgap_target', 'local_kbacompany'), 'gaptarget', false, ['class' => 'font-weight-bold']) .
        html_writer::select($itemoptions($targets), 'targetitemuuid', $gap['targetitemuuid'] ?? '', false,
            ['id' => 'gaptarget', 'class' => 'form-control', 'required' => 'required']), ['class' => 'form-group']);
    echo html_writer::tag('div',
        html_writer::label(get_string('bmcgap_significance', 'local_kbacompany'), 'gapsignificance', false,
            ['class' => 'font-weight-bold']) .
        html_writer::tag('textarea', s($gap['significance'] ?? ''), ['id' => 'gapsignificance', 'name' => 'significance',
            'rows' => 4, 'class' => 'form-control', 'required' => 'required']) .
        html_writer::tag('small', get_string('bmcgap_significance_help', 'local_kbacompany'),
            ['class' => 'form-text text-muted']), ['class' => 'form-group']);
    echo html_writer::tag('div',
        html_writer::label(get_string('bmcgap_evidence', 'local_kbacompany'), 'gapevidence', false, ['class' => 'font-weight-bold']) .
        html_writer::tag('textarea', s($gap['evidence'] ?? ''), ['id' => 'gapevidence', 'name' => 'evidence',
            'rows' => 3, 'class' => 'form-control']), ['class' => 'form-group']);
    if ($reqoptions) {
        $selectedreq = $gap ? array_column($gap['requirements'], 'code') : [];
        $checks = '';
        foreach ($reqoptions as $code => $label) {
            $id = 'gapreq_' . preg_replace('/[^a-zA-Z0-9_\-]/', '_', $code);
            $checks .= html_writer::tag('div',
                html_writer::empty_tag('input', ['type' => 'checkbox', 'id' => $id, 'name' => 'requirements[]',
                    'value' => $code, 'checked' => in_array($code, $selectedreq, true) ? 'checked' : null]) . ' ' .
                html_writer::label($label, $id), ['class' => 'form-check mb-1']);
        }
        echo html_writer::tag('div', html_writer::tag('div', get_string('bmcgap_requirements', 'local_kbacompany'),
            ['class' => 'font-weight-bold mb-2']) . $checks, ['class' => 'form-group']);
    }
    echo html_writer::tag('div',
        html_writer::label(get_string('bmcgap_indicator', 'local_kbacompany'), 'gapindicator', false,
            ['class' => 'font-weight-bold']) .
        html_writer::select($indicatoroptions, 'indicatoruuid', $gap['indicatoruuid'] ?? '', false,
            ['id' => 'gapindicator', 'class' => 'form-control']) .
        html_writer::tag('small', get_string('bmcgap_indicator_help', 'local_kbacompany'),
            ['class' => 'form-text text-muted']), ['class' => 'form-group']);
    echo html_writer::tag('div',
        html_writer::tag('button', get_string('savechanges'), ['type' => 'submit', 'class' => 'btn btn-primary mr-2']) .
        html_writer::link(new moodle_url('/local/kbacompany/business_model.php', [
            'uuid' => $uuid, 'model' => $model['uuid'], 'revision' => $model['revision'], 'screen' => 'gaps',
        ]), get_string('cancel'), ['class' => 'btn btn-secondary']), ['class' => 'mt-3']);
    echo html_writer::end_tag('form');
    \local_kbacompany\local\bms_page_shell_service::finish($bmsshell);
    exit;
}

if ($screen === 'gap') {
    $gap = business_model_validation_service::gap($uuid, (int)$USER->id, $model, $gapuuid);
    echo html_writer::start_tag('div', ['class' => 'bms-record-header border rounded p-3 mb-3 bg-white']);
    echo html_writer::tag('div', s($gap['stablecode']) . ' · ' . s($gapstatuslabel($gap['status'])), ['class' => 'text-muted small']);
    echo $OUTPUT->heading(get_string('bmcgap_title_record', 'local_kbacompany'), 3, 'mb-2');
    if ($canmanage && $gap['status'] !== 'archived') {
        echo html_writer::link(new moodle_url('/local/kbacompany/business_model.php', [
            'uuid' => $uuid, 'model' => $model['uuid'], 'revision' => $model['revision'],
            'screen' => 'editgap', 'gap' => $gap['uuid'],
        ]), $gap['status'] === 'confirmed' ? get_string('bmcgap_new_revision', 'local_kbacompany') : get_string('edit'),
            ['class' => 'btn btn-outline-primary mr-2']);
        if ($gap['status'] === 'candidate') {
            echo html_writer::start_tag('form', ['method' => 'post', 'class' => 'd-inline mr-2']);
            echo $hidden('confirmgap', ['modeluuid' => $model['uuid'], 'modelrevision' => $model['revision'],
                'gapuuid' => $gap['uuid'], 'gaprevision' => $gap['revision']]);
            echo html_writer::tag('button', get_string('bmcgap_confirm', 'local_kbacompany'),
                ['type' => 'submit', 'class' => 'btn btn-primary']);
            echo html_writer::end_tag('form');
        }
        echo html_writer::start_tag('form', ['method' => 'post', 'class' => 'd-inline']);
        echo $hidden('archivegap', ['modeluuid' => $model['uuid'], 'modelrevision' => $model['revision'],
            'gapuuid' => $gap['uuid'], 'gaprevision' => $gap['revision']]);
        echo html_writer::tag('button', get_string('archive'), ['type' => 'submit', 'class' => 'btn btn-outline-danger']);
        echo html_writer::end_tag('form');
    }
    echo html_writer::end_tag('div');
    $indicatorlabels = business_model_validation_service::indicator_options($uuid, (int)$USER->id);
    echo $OUTPUT->heading(get_string('bmcgap_trace_title', 'local_kbacompany'), 4, 'mt-3 mb-2');
    echo business_model_ui_service::gap_trace($gap, $schemas, $indicatorlabels);
    $table = new html_table();
    $table->data = [
        [get_string('bmcgap_block', 'local_kbacompany'), s($schemas[$gap['block']]['label'] ?? $gap['block'])],
        [get_string('bmcgap_asis', 'local_kbacompany'), s($gap['asisitem']['stablecode'] . ' — ' . $gap['asisitem']['title']) . '<br>' . nl2br(s($gap['asisitem']['formulation']))],
        [get_string('bmcgap_target', 'local_kbacompany'), s($gap['targetitem']['stablecode'] . ' — ' . $gap['targetitem']['title']) . '<br>' . nl2br(s($gap['targetitem']['formulation']))],
        [get_string('bmcgap_significance', 'local_kbacompany'), nl2br(s($gap['significance']))],
        [get_string('bmcgap_evidence', 'local_kbacompany'), nl2br(s($gap['evidence']))],
        [get_string('bmcgap_requirements', 'local_kbacompany'), s(implode(', ', array_column($gap['requirements'], 'code')) ?: '—')],
        [get_string('bmcgap_indicator', 'local_kbacompany'), s($gap['indicatoruuid'] !== '' ?
            ($indicatorlabels[$gap['indicatoruuid']] ?? $gap['indicatoruuid']) : '—')],
    ];
    echo html_writer::table($table);
    echo $OUTPUT->heading(get_string('bmc_change_history', 'local_kbacompany'), 4, 'mt-4');
    $history = business_model_validation_service::gap_history($uuid, (int)$USER->id, $gap['uuid']);
    $htable = new html_table();
    $htable->head = [get_string('marketmodel_revision', 'local_kbacompany'), get_string('marketmodel_status', 'local_kbacompany'),
        get_string('bmc_updated', 'local_kbacompany')];
    foreach ($history as $version) {
        $htable->data[] = [$version['revision'], s($gapstatuslabel($version['status'])), userdate($version['timemodified'])];
    }
    echo html_writer::table($htable);
    \local_kbacompany\local\bms_page_shell_service::finish($bmsshell);
    exit;
}

if ($screen === 'gaps') {
    echo html_writer::start_tag('div', ['class' => 'd-flex justify-content-between align-items-center mb-2']);
    echo $OUTPUT->heading(get_string('bmcgap_title', 'local_kbacompany'), 3, 'mb-0');
    echo html_writer::end_tag('div');
    echo html_writer::tag('div', get_string('bmcgap_method', 'local_kbacompany'), ['class' => 'alert alert-light border']);
    $gaps = business_model_validation_service::gaps($uuid, (int)$USER->id, $model);
    $gaps = bms_collection_view_service::filter_rows($gaps, $q, ['stablecode', 'significance', 'status', 'block']);
    $actionshtml = '';
    if ($canmanage) {
        $actionshtml = html_writer::link(new moodle_url('/local/kbacompany/business_model.php', [
            'uuid' => $uuid, 'model' => $model['uuid'], 'revision' => $model['revision'], 'screen' => 'newgap',
        ]), get_string('bmcgap_create', 'local_kbacompany'), ['class' => 'btn btn-sm btn-primary']);
    }
    echo bms_collection_view_service::toolbar('/local/kbacompany/business_model.php', [
        'uuid'=>$uuid, 'model'=>$model['uuid'], 'revision'=>$model['revision'], 'screen'=>'gaps',
    ], $view, $q, $actionshtml);
    if (!$gaps) {
        echo $OUTPUT->notification(get_string('bmcgap_empty', 'local_kbacompany'), 'info');
    } else if ($view === 'list') {
        $table = new html_table();
        $table->head = [get_string('bmcgap_code', 'local_kbacompany'), get_string('bmcgap_block', 'local_kbacompany'),
            get_string('bmcgap_significance', 'local_kbacompany'), get_string('marketmodel_status', 'local_kbacompany'),
            get_string('bmc_updated', 'local_kbacompany')];
        foreach ($gaps as $gap) {
            $href = new moodle_url('/local/kbacompany/business_model.php', [
                'uuid' => $uuid, 'model' => $model['uuid'], 'revision' => $model['revision'],
                'screen' => 'gap', 'gap' => $gap['uuid'],
            ]);
            $table->data[] = [html_writer::link($href, s($gap['stablecode'])),
                s($schemas[$gap['block']]['label'] ?? $gap['block']), shorten_text(strip_tags($gap['significance']), 160),
                s($gapstatuslabel($gap['status'])), userdate($gap['timemodified'])];
        }
        echo html_writer::table($table);
    } else if ($view === 'kanban') {
        $columns = [];
        foreach (business_model_validation_service::GAP_STATUSES as $status) {
            $columns[$status] = ['label'=>$gapstatuslabel($status), 'cards'=>[]];
        }
        foreach ($gaps as $gap) {
            if (!isset($columns[$gap['status']])) { continue; }
            $href = new moodle_url('/local/kbacompany/business_model.php', [
                'uuid'=>$uuid,'model'=>$model['uuid'],'revision'=>$model['revision'],'screen'=>'gap','gap'=>$gap['uuid'],
            ]);
            $columns[$gap['status']]['cards'][] = bms_collection_view_service::card(
                html_writer::link($href, s($gap['stablecode'])),
                [s($schemas[$gap['block']]['label'] ?? $gap['block']), userdate($gap['timemodified'])],
                s(shorten_text(strip_tags($gap['significance']), 180)));
        }
        echo bms_collection_view_service::kanban($columns);
    } else {
        $counts = ['candidate'=>0,'confirmed'=>0,'archived'=>0];
        foreach ($gaps as $gap) { if (isset($counts[$gap['status']])) { $counts[$gap['status']]++; } }
        echo bms_collection_view_service::analytics([
            ['label'=>get_string('bmsview_total','local_kbacompany'),'value'=>count($gaps)],
            ['label'=>$gapstatuslabel('candidate'),'value'=>$counts['candidate']],
            ['label'=>$gapstatuslabel('confirmed'),'value'=>$counts['confirmed']],
            ['label'=>$gapstatuslabel('archived'),'value'=>$counts['archived']],
        ]);
    }
    \local_kbacompany\local\bms_page_shell_service::finish($bmsshell);
    exit;
}

if ($screen === 'history') {
    echo $OUTPUT->heading(get_string('bmc_tab_history', 'local_kbacompany'), 3);
    $history = market_model_service::business_model_history($uuid, (int)$USER->id, $model['uuid']);
    $table = new html_table();
    $table->head = [get_string('marketmodel_revision', 'local_kbacompany'), get_string('marketmodel_status', 'local_kbacompany'),
        get_string('bmc_updated', 'local_kbacompany'), get_string('actions')];
    foreach ($history as $version) {
        $table->data[] = [(int)$version['revision'], s($statuslabel($version['status'])), userdate((int)$version['timemodified']),
            html_writer::link(new moodle_url('/local/kbacompany/business_model.php', [
                'uuid' => $uuid, 'model' => $version['uuid'], 'revision' => $version['revision'], 'screen' => 'canvas',
            ]), get_string('view'))];
    }
    echo html_writer::table($table);
    \local_kbacompany\local\bms_page_shell_service::finish($bmsshell);
    exit;
}

// Default Canvas aggregate view.
echo html_writer::tag('div',
    html_writer::tag('strong', get_string('bmc_methodology_title', 'local_kbacompany')) . ' — ' .
    s(get_string('bmc_methodology_flow', 'local_kbacompany')), ['class' => 'alert alert-light border']);

echo html_writer::start_tag('div', ['class' => 'bms-bmc-grid']);
foreach (business_model_crud_service::BLOCKS as $b) {
    $stats = $readiness['byblock'][$b];
    $url = new moodle_url('/local/kbacompany/business_model.php', [
        'uuid' => $uuid, 'model' => $model['uuid'], 'revision' => $model['revision'], 'screen' => 'block', 'block' => $b,
    ]);
    $body = html_writer::tag('div', $schemas[$b]['label'], ['class' => 'font-weight-bold mb-2']) .
        html_writer::tag('div', get_string('bmc_state_asis', 'local_kbacompany') . ': ' . $stats['asis'] . ' · ' .
            get_string('bmc_state_target', 'local_kbacompany') . ': ' . $stats['target'], ['class' => 'small']) .
        html_writer::tag('div', get_string('bmc_count_fact', 'local_kbacompany') . ' ' . $stats['fact'] . ' · ' .
            get_string('bmc_count_data', 'local_kbacompany') . ' ' . $stats['data'] . ' · ' .
            get_string('bmc_count_hyp', 'local_kbacompany') . ' ' . $stats['hyp'] . ' · ' .
            get_string('bmc_count_open', 'local_kbacompany') . ' ' . $stats['open'], ['class' => 'small text-muted mt-1']) .
        html_writer::tag('div', shorten_text($schemas[$b]['purpose'], 110), ['class' => 'small text-muted mt-2']);
    echo html_writer::link($url, $body, ['class' => 'bms-bmc-card']);
}
echo html_writer::end_tag('div');

if ($editable) {
    echo html_writer::tag('p', get_string('bmc_canvas_edit_help', 'local_kbacompany'), ['class' => 'text-muted mt-3']);
} else if ($model['status'] === 'published') {
    echo $OUTPUT->notification(get_string('bmc_published_snapshot_notice', 'local_kbacompany'), 'info');
}

\local_kbacompany\local\bms_page_shell_service::finish($bmsshell);
