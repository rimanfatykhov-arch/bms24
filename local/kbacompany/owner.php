<?php
require_once(__DIR__ . '/../../config.php');

use local_kbacompany\local\owner_service;
use local_kbacompany\local\bms_ai_bridge_service;

require_login();
$context = context_system::instance();
if (!is_siteadmin() && !has_capability('local/kbacompany:viewowncompany', $context) &&
        !has_capability('local/kbacompany:reviewownerprivate', $context)) {
    require_capability('local/kbacompany:viewowncompany', $context);
}

$uuid = required_param('uuid', PARAM_RAW_TRIMMED);
$frameuuid = optional_param('frame', '', PARAM_RAW_TRIMMED);
$sectionkey = optional_param('section', 'personal', PARAM_ALPHANUMEXT);
$mode = optional_param('mode', 'view', PARAM_ALPHA);
$showhistory = optional_param('history', 0, PARAM_BOOL);
if ($sectionkey !== 'summary' && !in_array($sectionkey, owner_service::SECTION_KEYS, true)) {
    $sectionkey = 'personal';
}
if (!in_array($mode, ['view', 'edit'], true)) {
    $mode = 'view';
}

$PAGE->set_context($context);
$PAGE->set_url(new moodle_url('/local/kbacompany/owner.php', ['uuid' => $uuid]));
$PAGE->set_title(get_string('ownermodel_title', 'local_kbacompany'));
$PAGE->set_heading(get_string('pluginname', 'local_kbacompany'));

$redirecturl = static function(string $frame = '', string $section = 'personal', string $modevalue = 'view') use ($uuid): moodle_url {
    $params = ['uuid' => $uuid, 'section' => $section, 'mode' => $modevalue];
    if ($frame !== '') {
        $params['frame'] = $frame;
    }
    return new moodle_url('/local/kbacompany/owner.php', $params);
};

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_sesskey();
    $action = required_param('action', PARAM_ALPHANUMEXT);
    $postframe = optional_param('frame', '', PARAM_RAW_TRIMMED);
    $revision = optional_param('revision', 0, PARAM_INT);
    $postsection = optional_param('section', 'personal', PARAM_ALPHANUMEXT);

    if ($action === 'createframe') {
        $postframe = owner_service::create_frame($uuid, (int)$USER->id,
            required_param('title', PARAM_RAW_TRIMMED));
        redirect($redirecturl($postframe, 'personal', 'edit'), get_string('ownermodel_created', 'local_kbacompany'));
    } else if ($action === 'savesection') {
        $section = [];
        foreach (owner_service::SECTION_FIELDS as $field) {
            $section[$field] = optional_param($field, '', PARAM_RAW_TRIMMED);
        }
        owner_service::save_section($uuid, (int)$USER->id, $postframe, $revision, $postsection, $section);
        redirect($redirecturl($postframe, $postsection, 'view'), get_string('ownermodel_section_saved', 'local_kbacompany'));
    } else if ($action === 'savesummary') {
        $requirements = [];
        for ($i = 1; $i <= 7; $i++) {
            $requirements[$i] = optional_param('req' . sprintf('%02d', $i), '', PARAM_RAW_TRIMMED);
        }
        owner_service::save_summary($uuid, (int)$USER->id, $postframe, $revision,
            required_param('title', PARAM_RAW_TRIMMED),
            optional_param('main_intent', '', PARAM_RAW_TRIMMED),
            optional_param('notes', '', PARAM_RAW_TRIMMED),
            $requirements);
        redirect($redirecturl($postframe, 'summary', 'view'), get_string('ownermodel_saved', 'local_kbacompany'));
    } else if ($action === 'submitsection') {
        owner_service::submit_for_review($uuid, (int)$USER->id, $postframe, $revision, $postsection);
        redirect($redirecturl($postframe, $postsection), get_string('ownermodel_submitted', 'local_kbacompany'));
    } else if ($action === 'submitall') {
        owner_service::submit_for_review($uuid, (int)$USER->id, $postframe, $revision);
        redirect($redirecturl($postframe, $postsection), get_string('ownermodel_submitted_all', 'local_kbacompany'));
    } else if ($action === 'addcomment') {
        owner_service::add_comment($uuid, (int)$USER->id, $postframe, $revision, $postsection,
            required_param('comment', PARAM_RAW_TRIMMED));
        redirect($redirecturl($postframe, $postsection), get_string('ownermodel_comment_saved', 'local_kbacompany'));
    } else if ($action === 'reviewsection') {
        owner_service::review_section($uuid, (int)$USER->id, $postframe, $revision, $postsection,
            required_param('decision', PARAM_ALPHA), optional_param('comment', '', PARAM_RAW_TRIMMED));
        redirect($redirecturl($postframe, $postsection), get_string('ownermodel_review_saved', 'local_kbacompany'));
    } else if ($action === 'publishframe') {
        owner_service::publish_frame($uuid, (int)$USER->id, $postframe, $revision);
        redirect($redirecturl($postframe, 'summary'), get_string('ownermodel_published', 'local_kbacompany'));
    } else if ($action === 'archiveframe') {
        owner_service::archive_frame($uuid, (int)$USER->id, $postframe, $revision);
        redirect(new moodle_url('/local/kbacompany/owner.php', ['uuid' => $uuid]), get_string('ownermodel_archived', 'local_kbacompany'));
    } else {
        throw new moodle_exception('invalidparameter');
    }
}

$data = owner_service::context($uuid, (int)$USER->id);
$selected = null;
foreach ($data['frames'] as $candidate) {
    if ($frameuuid !== '' && $candidate['uuid'] === $frameuuid) {
        $selected = $candidate;
        break;
    }
}
if (!$selected && !empty($data['frames'])) {
    $selected = $data['frames'][0];
    $frameuuid = (string)$selected['uuid'];
}

$sectiondefs = [];
foreach (owner_service::SECTION_KEYS as $index => $key) {
    $n = $index + 1;
    $sectiondefs[$key] = [
        'number' => '0.' . $n,
        'title' => get_string('ownermodel_section_' . $key . '_title', 'local_kbacompany'),
        'help' => get_string('ownermodel_section_' . $key . '_help', 'local_kbacompany'),
        'weak' => get_string('ownermodel_section_' . $key . '_weak', 'local_kbacompany'),
        'example' => get_string('ownermodel_section_' . $key . '_example', 'local_kbacompany'),
    ];
}

$hidden = static function(string $action, array $extra = []) use ($uuid): string {
    $values = array_merge(['uuid' => $uuid, 'sesskey' => sesskey(), 'action' => $action], $extra);
    $html = '';
    foreach ($values as $name => $value) {
        $html .= html_writer::empty_tag('input', ['type' => 'hidden', 'name' => $name, 'value' => $value]);
    }
    return $html;
};
$statuslabel = static function(string $status): string {
    return get_string('ownermodel_workflow_' . $status, 'local_kbacompany');
};
$statusclass = static function(string $status): string {
    return 'bms-owner-status bms-owner-status--' . preg_replace('/[^a-z]/', '', $status);
};

$bmsshell = \local_kbacompany\local\bms_page_shell_service::begin($uuid, (int)$USER->id, 'owner',
    get_string('ownermodel_title', 'local_kbacompany'));

echo \local_kbacompany\local\bms_workspace_ux_service::architecture_nav($uuid, 'owner');

echo html_writer::start_div('bms-owner-page');

if (!$selected) {
    echo html_writer::start_div('bms-owner-record-head');
    echo html_writer::div(
        html_writer::tag('div', get_string('ownermodel_title', 'local_kbacompany'), ['class' => 'bms-owner-record-title']) .
        html_writer::tag('div', format_string($data['company']['name']), ['class' => 'bms-owner-record-subtitle']),
        'bms-owner-record-identity');
    echo html_writer::end_div();
    echo $OUTPUT->notification(get_string('ownermodel_privacy_notice_v2', 'local_kbacompany'), 'info');
    if ($data['can_manage']) {
        echo html_writer::start_div('bms-owner-empty-card');
        echo html_writer::tag('h3', get_string('ownermodel_start_title', 'local_kbacompany'));
        echo html_writer::tag('p', get_string('ownermodel_start_help', 'local_kbacompany'));
        echo html_writer::start_tag('form', ['method' => 'post']);
        echo $hidden('createframe');
        echo html_writer::tag('label', get_string('ownermodel_name', 'local_kbacompany'), ['for' => 'owner_title', 'class' => 'font-weight-bold']);
        echo html_writer::empty_tag('input', ['id' => 'owner_title', 'name' => 'title', 'class' => 'form-control mb-3',
            'required' => 'required', 'value' => get_string('ownermodel_defaultname', 'local_kbacompany')]);
        echo html_writer::tag('button', get_string('ownermodel_begin', 'local_kbacompany'), ['type' => 'submit', 'class' => 'btn btn-primary']);
        echo html_writer::end_tag('form');
        echo html_writer::end_div();
    } else {
        echo $OUTPUT->notification(get_string('ownermodel_no_frame_for_review', 'local_kbacompany'), 'info');
    }
    echo html_writer::end_div();
    
echo \local_kbacompany\local\bms_page_shell_service::finish($bmsshell);
    exit;
}

$statuses = owner_service::section_statuses($uuid, (int)$USER->id, $selected);
$progress = owner_service::progress($statuses);
$payload = $selected['payload_data'] ?? [];
$sections = $payload['sections'] ?? owner_service::sections_from_payload($payload);
$editable = !empty($selected['editable']) && $data['can_manage'];
$ready = owner_service::ready_to_publish($uuid, (int)$USER->id, $selected);
$overall = $selected['status'] === 'published' ? 'published' : ($progress['changes'] > 0 ? 'changes' :
    ($progress['review'] > 0 ? 'review' : 'draft'));

// SuiteCRM-style record header.
echo html_writer::start_div('bms-owner-record-head');
echo html_writer::start_div('bms-owner-record-identity');
echo html_writer::tag('div', s((string)$selected['title']), ['class' => 'bms-owner-record-title']);
echo html_writer::tag('div', format_string($data['company']['name']) . ' · ' .
    get_string('ownermodel_revision_short', 'local_kbacompany', (int)$selected['revision']), ['class' => 'bms-owner-record-subtitle']);
echo html_writer::end_div();
echo html_writer::start_div('bms-owner-record-actions');
if ($editable && $sectionkey !== 'summary') {
    echo html_writer::link($redirecturl($frameuuid, $sectionkey, 'edit'), get_string('edit'), ['class' => 'btn btn-primary']);
} else if ($editable && $sectionkey === 'summary') {
    echo html_writer::link($redirecturl($frameuuid, 'summary', 'edit'), get_string('edit'), ['class' => 'btn btn-primary']);
}
echo html_writer::start_tag('details', ['class' => 'bms-owner-actions-menu']);
echo html_writer::tag('summary', get_string('actions'), ['class' => 'btn btn-outline-secondary']);
echo html_writer::start_div('bms-owner-actions-dropdown');
if ($editable && $selected['status'] === 'draft') {
    echo html_writer::start_tag('form', ['method' => 'post']);
    echo $hidden('submitall', ['frame' => $frameuuid, 'revision' => (int)$selected['revision'], 'section' => $sectionkey]);
    echo html_writer::tag('button', get_string('ownermodel_submit_all', 'local_kbacompany'), ['type' => 'submit', 'class' => 'dropdown-item']);
    echo html_writer::end_tag('form');
    if ($ready && $selected['status'] === 'draft') {
        echo html_writer::start_tag('form', ['method' => 'post']);
        echo $hidden('publishframe', ['frame' => $frameuuid, 'revision' => (int)$selected['revision']]);
        echo html_writer::tag('button', get_string('ownermodel_publish', 'local_kbacompany'), ['type' => 'submit', 'class' => 'dropdown-item']);
        echo html_writer::end_tag('form');
    }
}
echo html_writer::link(new moodle_url('/local/kbacompany/owner.php', [
    'uuid' => $uuid, 'frame' => $frameuuid, 'section' => $sectionkey, 'history' => 1,
]), get_string('ownermodel_history', 'local_kbacompany'), ['class' => 'dropdown-item']);
if ($editable) {
    echo html_writer::start_tag('form', ['method' => 'post']);
    echo $hidden('archiveframe', ['frame' => $frameuuid, 'revision' => (int)$selected['revision']]);
    echo html_writer::tag('button', get_string('delete'), ['type' => 'submit', 'class' => 'dropdown-item text-danger',
        'onclick' => "return confirm('" . addslashes_js(get_string('ownermodel_deleteconfirm', 'local_kbacompany')) . "')"]);
    echo html_writer::end_tag('form');
}
echo html_writer::end_div();
echo html_writer::end_tag('details');
echo html_writer::end_div();
echo html_writer::end_div();

// Status strip.
echo html_writer::start_div('bms-owner-progress-strip');
echo html_writer::span($statuslabel($overall), $statusclass($overall));
echo html_writer::span(get_string('ownermodel_progress_filled', 'local_kbacompany', (object)[
    'done' => $progress['filled'], 'total' => $progress['total'],
]), 'bms-owner-progress-metric');
echo html_writer::span(get_string('ownermodel_progress_agreed', 'local_kbacompany', $progress['agreed']), 'bms-owner-progress-metric');
if ($progress['changes'] > 0) {
    echo html_writer::span(get_string('ownermodel_progress_changes', 'local_kbacompany', $progress['changes']), 'bms-owner-progress-metric bms-owner-progress-metric--warning');
}
if ($progress['review'] > 0) {
    echo html_writer::span(get_string('ownermodel_progress_review', 'local_kbacompany', $progress['review']), 'bms-owner-progress-metric');
}
echo html_writer::end_div();
echo $OUTPUT->notification(get_string('ownermodel_privacy_notice_v2', 'local_kbacompany'), 'info');

if (count($data['frames']) > 1) {
    echo html_writer::start_div('bms-owner-frame-switcher');
    echo html_writer::span(get_string('ownermodel_other_frames', 'local_kbacompany') . ': ', 'text-muted');
    foreach ($data['frames'] as $frame) {
        echo html_writer::link($redirecturl((string)$frame['uuid'], 'personal'), s((string)$frame['title']), [
            'class' => $frame['uuid'] === $frameuuid ? 'font-weight-bold mr-2' : 'mr-2',
        ]);
    }
    echo html_writer::end_div();
}

$aistatus = \local_kbacompany\local\bms_ai_bridge_service::status();
$aienabled = !empty($aistatus['available']);
$aitestmode = !empty($aistatus['test_mode']);

// Three-column guided workflow.
echo html_writer::start_div('bms-owner-layout');
echo html_writer::start_tag('nav', ['class' => 'bms-owner-nav', 'aria-label' => get_string('ownermodel_sections_nav', 'local_kbacompany')]);
echo html_writer::tag('div', get_string('ownermodel_sections_title', 'local_kbacompany'), ['class' => 'bms-owner-nav-title']);
foreach ($sectiondefs as $key => $def) {
    $status = $statuses[$key] ?? 'empty';
    $classes = 'bms-owner-nav-item';
    if ($key === $sectionkey) {
        $classes .= ' is-active';
    }
    $icon = $status === 'agreed' ? '✓' : ($status === 'changes' ? '!' : ($status === 'review' ? '●' : '○'));
    $label = html_writer::span($icon, 'bms-owner-nav-icon bms-owner-nav-icon--' . $status) .
        html_writer::span(s($def['number'] . ' ' . $def['title']), 'bms-owner-nav-label');
    echo html_writer::link($redirecturl($frameuuid, $key), $label, ['class' => $classes]);
}
$summaryclasses = 'bms-owner-nav-item bms-owner-nav-item--summary' . ($sectionkey === 'summary' ? ' is-active' : '');
echo html_writer::link($redirecturl($frameuuid, 'summary'),
    html_writer::span('→', 'bms-owner-nav-icon') . html_writer::span(get_string('ownermodel_summary_title', 'local_kbacompany'), 'bms-owner-nav-label'),
    ['class' => $summaryclasses]);
echo html_writer::end_tag('nav');

// Main record panel.
echo html_writer::start_tag('main', ['class' => 'bms-owner-main']);
if ($sectionkey !== 'summary') {
    $def = $sectiondefs[$sectionkey];
    $section = $sections[$sectionkey];
    echo html_writer::tag('div', s($def['number']), ['class' => 'bms-owner-section-number']);
    echo html_writer::tag('h2', s($def['title']), ['class' => 'bms-owner-section-title']);
    echo html_writer::tag('p', s($def['help']), ['class' => 'bms-owner-section-help']);
    echo html_writer::start_tag('details', ['class' => 'bms-owner-example']);
    echo html_writer::tag('summary', get_string('ownermodel_show_example', 'local_kbacompany'));
    echo html_writer::tag('div', html_writer::tag('strong', get_string('ownermodel_weak_example', 'local_kbacompany')) .
        html_writer::tag('p', s($def['weak'])), ['class' => 'bms-owner-example-weak']);
    echo html_writer::tag('div', html_writer::tag('strong', get_string('ownermodel_working_example', 'local_kbacompany')) .
        html_writer::tag('p', s($def['example'])), ['class' => 'bms-owner-example-good']);
    echo html_writer::end_tag('details');

    if ($mode === 'edit' && $editable) {
        echo html_writer::start_tag('form', ['method' => 'post', 'class' => 'bms-owner-edit-form']);
        echo $hidden('savesection', ['frame' => $frameuuid, 'revision' => (int)$selected['revision'], 'section' => $sectionkey]);
        foreach (owner_service::SECTION_FIELDS as $fieldname) {
            echo html_writer::start_div('bms-owner-field card mb-3 bms-ai-field');
            echo html_writer::start_div('card-header d-flex justify-content-between align-items-center');
            echo html_writer::tag('label', get_string('ownermodel_field_' . $fieldname, 'local_kbacompany'), [
                'for' => 'owner_' . $fieldname, 'class' => 'bms-owner-field-label mb-0',
            ]);
            if ($aienabled) {
                echo html_writer::tag('button', '✨ ' . get_string('bmsai_help', 'local_kbacompany'), [
                    'type' => 'button', 'class' => 'btn btn-outline-secondary btn-sm bms-owner-ai-help bms-ai-help',
                    'data-field' => $fieldname, 'data-target' => 'owner_' . $fieldname, 'data-section' => $sectionkey,
                    'data-record' => (string)$selected['uuid'], 'data-revision' => (int)$selected['revision'],
                ]);
            } else {
                echo html_writer::tag('span', get_string('bmsai_unavailable_short', 'local_kbacompany'), ['class' => 'badge badge-light text-muted']);
            }
            echo html_writer::end_div();
            echo html_writer::start_div('card-body');
            echo html_writer::tag('div', get_string('ownermodel_field_' . $fieldname . '_help', 'local_kbacompany'), ['class' => 'bms-owner-field-help']);
            echo html_writer::tag('textarea', s((string)($section[$fieldname] ?? '')), [
                'id' => 'owner_' . $fieldname, 'name' => $fieldname, 'rows' => $fieldname === 'statement' ? 7 : 5,
                'class' => 'form-control',
            ]);
            echo html_writer::end_div();
            echo html_writer::end_div();
        }
        echo html_writer::start_div('bms-owner-form-actions');
        echo html_writer::tag('button', get_string('savechanges'), ['type' => 'submit', 'class' => 'btn btn-primary']);
        echo html_writer::link($redirecturl($frameuuid, $sectionkey), get_string('cancel'), ['class' => 'btn btn-outline-secondary']);
        echo html_writer::end_div();
        echo html_writer::div('', 'bms-ai-drawer d-none', ['id' => 'bms-ai-panel', 'role' => 'dialog', 'aria-modal' => 'true']);
        echo html_writer::end_tag('form');
    } else {
        foreach (owner_service::SECTION_FIELDS as $fieldname) {
            $value = trim((string)($section[$fieldname] ?? ''));
            echo html_writer::start_div('bms-owner-detail-row');
            echo html_writer::div(get_string('ownermodel_field_' . $fieldname, 'local_kbacompany'), 'bms-owner-detail-label');
            echo html_writer::div($value !== '' ? nl2br(s($value)) : html_writer::span(get_string('ownermodel_not_filled', 'local_kbacompany'), 'text-muted'),
                'bms-owner-detail-value');
            echo html_writer::end_div();
        }
    }

    echo html_writer::start_div('bms-owner-quality');
    echo html_writer::tag('h3', get_string('ownermodel_quality_title', 'local_kbacompany'));
    $quality = [
        'statement' => trim((string)($section['statement'] ?? '')) !== '',
        'horizonmetric' => trim((string)($section['horizonmetric'] ?? '')) !== '',
        'constraintsprice' => trim((string)($section['constraintsprice'] ?? '')) !== '',
        'businessevidence' => trim((string)($section['businessevidence'] ?? '')) !== '',
    ];
    foreach ($quality as $qualitykey => $ok) {
        echo html_writer::div(($ok ? '✓ ' : '○ ') . get_string('ownermodel_quality_' . $qualitykey, 'local_kbacompany'),
            $ok ? 'bms-owner-quality-item is-ok' : 'bms-owner-quality-item');
    }
    echo html_writer::div('○ ' . get_string('ownermodel_quality_minimum', 'local_kbacompany'), 'bms-owner-quality-item is-check');
    echo html_writer::div('○ ' . get_string('ownermodel_quality_source', 'local_kbacompany'), 'bms-owner-quality-item is-check');
    echo html_writer::end_div();
} else {
    echo html_writer::tag('h2', get_string('ownermodel_summary_title', 'local_kbacompany'), ['class' => 'bms-owner-section-title']);
    echo html_writer::tag('p', get_string('ownermodel_summary_help', 'local_kbacompany'), ['class' => 'bms-owner-section-help']);
    $reqmap = [];
    foreach (($payload['requirements'] ?? []) as $req) {
        if (is_array($req) && isset($req['id'])) {
            $reqmap[(string)$req['id']] = (string)($req['text'] ?? '');
        }
    }
    if ($mode === 'edit' && $editable) {
        echo html_writer::start_tag('form', ['method' => 'post', 'class' => 'bms-owner-edit-form']);
        echo $hidden('savesummary', ['frame' => $frameuuid, 'revision' => (int)$selected['revision'], 'section' => 'summary']);
        echo html_writer::tag('label', get_string('ownermodel_name', 'local_kbacompany'), ['for' => 'owner_title', 'class' => 'bms-owner-field-label']);
        echo html_writer::empty_tag('input', ['id' => 'owner_title', 'name' => 'title', 'class' => 'form-control mb-3', 'required' => 'required',
            'value' => (string)$selected['title']]);
        echo html_writer::tag('label', get_string('ownermodel_main_intent', 'local_kbacompany'), ['for' => 'owner_main_intent', 'class' => 'bms-owner-field-label']);
        echo html_writer::tag('textarea', s((string)($payload['main_intent'] ?? '')), ['id' => 'owner_main_intent', 'name' => 'main_intent', 'rows' => 7, 'class' => 'form-control mb-3']);
        echo html_writer::tag('h3', get_string('ownermodel_section_requirements', 'local_kbacompany'));
        echo html_writer::tag('p', get_string('ownermodel_requirements_help', 'local_kbacompany'), ['class' => 'text-muted']);
        for ($i = 1; $i <= 7; $i++) {
            $id = 'REQ-' . sprintf('%02d', $i);
            $name = 'req' . sprintf('%02d', $i);
            echo html_writer::tag('label', get_string('ownermodel_requirement_number', 'local_kbacompany', $i), ['for' => 'owner_' . $name, 'class' => 'bms-owner-field-label']);
            echo html_writer::tag('textarea', s($reqmap[$id] ?? ''), ['id' => 'owner_' . $name, 'name' => $name, 'rows' => 3, 'class' => 'form-control mb-3']);
        }
        echo html_writer::tag('label', get_string('ownermodel_notes', 'local_kbacompany'), ['for' => 'owner_notes', 'class' => 'bms-owner-field-label']);
        echo html_writer::tag('textarea', s((string)($payload['notes'] ?? '')), ['id' => 'owner_notes', 'name' => 'notes', 'rows' => 4, 'class' => 'form-control mb-3']);
        echo html_writer::tag('button', get_string('savechanges'), ['type' => 'submit', 'class' => 'btn btn-primary mr-2']);
        echo html_writer::link($redirecturl($frameuuid, 'summary'), get_string('cancel'), ['class' => 'btn btn-outline-secondary']);
        echo html_writer::end_tag('form');
    } else {
        echo html_writer::div(nl2br(s((string)($payload['main_intent'] ?? ''))), 'bms-owner-summary-intent');
        echo html_writer::tag('h3', get_string('ownermodel_section_requirements', 'local_kbacompany'));
        if ($reqmap) {
            echo html_writer::start_tag('ol', ['class' => 'bms-owner-requirements']);
            foreach ($reqmap as $reqtext) {
                echo html_writer::tag('li', nl2br(s($reqtext)));
            }
            echo html_writer::end_tag('ol');
        } else {
            echo html_writer::div(get_string('ownermodel_no_requirements', 'local_kbacompany'), 'text-muted');
        }
        echo html_writer::div($ready ? get_string('ownermodel_ready_publish', 'local_kbacompany') : get_string('ownermodel_not_ready_publish', 'local_kbacompany'),
            $ready ? 'alert alert-success mt-3' : 'alert alert-warning mt-3');
    }
}
echo html_writer::end_tag('main');

// Consultant / discussion panel.
echo html_writer::start_tag('aside', ['class' => 'bms-owner-review-panel']);
echo html_writer::tag('h3', get_string('ownermodel_consultant_title', 'local_kbacompany'));
if ($sectionkey === 'summary') {
    echo html_writer::tag('p', get_string('ownermodel_consultant_summary_help', 'local_kbacompany'), ['class' => 'text-muted']);
} else {
    $currentstatus = $statuses[$sectionkey] ?? 'empty';
    echo html_writer::span($statuslabel($currentstatus), $statusclass($currentstatus));
    $events = owner_service::feedback($uuid, (int)$USER->id, $frameuuid, $sectionkey);
    echo html_writer::start_div('bms-owner-feedback-list');
    if (!$events) {
        echo html_writer::div(get_string('ownermodel_no_comments', 'local_kbacompany'), 'text-muted');
    }
    foreach ($events as $event) {
        $actiontext = get_string('ownermodel_feedback_' . $event['action'], 'local_kbacompany');
        echo html_writer::start_div('bms-owner-feedback-item');
        echo html_writer::div(s($event['authorname']) . ' · ' . userdate($event['timecreated']), 'bms-owner-feedback-meta');
        echo html_writer::div(s($actiontext), 'bms-owner-feedback-action');
        if (trim($event['comment']) !== '') {
            echo html_writer::div(nl2br(s($event['comment'])), 'bms-owner-feedback-body');
        }
        echo html_writer::end_div();
    }
    echo html_writer::end_div();

    echo html_writer::start_tag('form', ['method' => 'post', 'class' => 'bms-owner-comment-form']);
    echo $hidden('addcomment', ['frame' => $frameuuid, 'revision' => (int)$selected['revision'], 'section' => $sectionkey]);
    echo html_writer::tag('label', get_string('ownermodel_comment_label', 'local_kbacompany'), ['for' => 'owner_comment', 'class' => 'bms-owner-field-label']);
    echo html_writer::tag('textarea', '', ['id' => 'owner_comment', 'name' => 'comment', 'rows' => 4, 'class' => 'form-control', 'required' => 'required']);
    echo html_writer::tag('button', get_string('ownermodel_comment_send', 'local_kbacompany'), ['type' => 'submit', 'class' => 'btn btn-outline-primary btn-sm mt-2']);
    echo html_writer::end_tag('form');

    if ($editable && $selected['status'] === 'draft' && owner_service::section_is_filled($sections[$sectionkey])) {
        echo html_writer::start_tag('form', ['method' => 'post', 'class' => 'mt-3']);
        echo $hidden('submitsection', ['frame' => $frameuuid, 'revision' => (int)$selected['revision'], 'section' => $sectionkey]);
        echo html_writer::tag('button', get_string('ownermodel_submit_section', 'local_kbacompany'), ['type' => 'submit', 'class' => 'btn btn-primary btn-sm btn-block']);
        echo html_writer::end_tag('form');
    }

    if ($data['can_review'] && $selected['status'] === 'draft' && owner_service::section_is_filled($sections[$sectionkey])) {
        echo html_writer::start_div('bms-owner-review-decision');
        echo html_writer::tag('h4', get_string('ownermodel_review_decision', 'local_kbacompany'));
        echo html_writer::start_tag('form', ['method' => 'post']);
        echo $hidden('reviewsection', ['frame' => $frameuuid, 'revision' => (int)$selected['revision'], 'section' => $sectionkey]);
        echo html_writer::tag('textarea', '', ['name' => 'comment', 'rows' => 4, 'class' => 'form-control mb-2',
            'placeholder' => get_string('ownermodel_review_comment_placeholder', 'local_kbacompany')]);
        echo html_writer::start_div('d-flex flex-wrap');
        echo html_writer::tag('button', get_string('ownermodel_request_changes', 'local_kbacompany'), [
            'type' => 'submit', 'name' => 'decision', 'value' => 'changes', 'class' => 'btn btn-outline-danger btn-sm mr-2 mb-2']);
        echo html_writer::tag('button', get_string('ownermodel_agree_section', 'local_kbacompany'), [
            'type' => 'submit', 'name' => 'decision', 'value' => 'agreed', 'class' => 'btn btn-success btn-sm mb-2']);
        echo html_writer::end_div();
        echo html_writer::end_tag('form');
        echo html_writer::end_div();
    }
}
echo html_writer::end_tag('aside');
echo html_writer::end_div();

if ($showhistory) {
    $history = owner_service::history($uuid, (int)$USER->id, $frameuuid);
    echo html_writer::start_div('bms-owner-subpanel');
    echo html_writer::tag('h3', get_string('ownermodel_history', 'local_kbacompany'));
    $table = new html_table();
    $table->head = [get_string('ownermodel_revision', 'local_kbacompany'), get_string('ownermodel_status', 'local_kbacompany'),
        get_string('ownermodel_modified', 'local_kbacompany')];
    foreach ($history as $row) {
        $table->data[] = [(int)$row['revision'], get_string('ownermodel_status_' . $row['status'], 'local_kbacompany'), userdate((int)$row['timemodified'])];
    }
    echo html_writer::table($table);
    echo html_writer::end_div();
}

echo html_writer::end_div();

if ($sectionkey !== 'summary' && $mode === 'edit' && $editable && $selected) {
    \local_kbacompany\local\bms_ai_ui_service::init($uuid, 'owner', $selected, $sectionkey);
}

echo \local_kbacompany\local\bms_page_shell_service::finish($bmsshell);
