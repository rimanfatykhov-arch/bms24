<?php
require_once(__DIR__ . '/../../config.php');

use local_kbacompany\local\recurring_task_service;
use local_kbacompany\local\recurring_task_library_service;
use local_kbacompany\local\recurring_task_library_admin_service;
use local_kbacompany\local\company_service;
use local_kbacompany\local\battle_plan_service;
use local_kbacompany\local\task_service;

require_login();
$context = context_system::instance();
require_capability('local/kbacompany:viewowncompany', $context);
$uuid = required_param('uuid', PARAM_RAW_TRIMMED);
$category = optional_param('category', 'owner', PARAM_ALPHANUMEXT);
$query = trim(optional_param('q', '', PARAM_TEXT));
$planuuid = optional_param('plan', '', PARAM_RAW_TRIMMED);
$focus = optional_param('template', '', PARAM_ALPHANUMEXT);
$manage = optional_param('manage', 0, PARAM_BOOL);
$edittemplate = optional_param('edittemplate', '', PARAM_ALPHANUMEXT);
$editcategory = optional_param('editcategory', '', PARAM_ALPHANUMEXT);
$newtemplate = optional_param('newtemplate', 0, PARAM_BOOL);
$newcategory = optional_param('newcategory', 0, PARAM_BOOL);
$showarchive = optional_param('archive', 0, PARAM_BOOL);
$companycontext = company_service::company_context($uuid, (int)$USER->id);
$canmanage = recurring_task_library_admin_service::can_manage($uuid, (int)$USER->id);
if ($manage && !$canmanage) { $manage = 0; }

$redirect_library = static function(array $extra = []) use ($uuid, $category, $planuuid): moodle_url {
    $params = ['uuid' => $uuid, 'category' => $category];
    if ($planuuid !== '') { $params['plan'] = $planuuid; }
    return new moodle_url('/local/kbacompany/recurring_task_library.php', array_merge($params, $extra));
};

$redirect_anchor = static function(array $extra, string $anchor) use ($redirect_library): moodle_url {
    $url = $redirect_library($extra);
    $url->set_anchor($anchor);
    return $url;
};

$template_data = static function(): array {
    $mask = 0;
    foreach (optional_param_array('weekday', [], PARAM_INT) as $day) {
        $day = (int)$day;
        if ($day >= 1 && $day <= 7) { $mask |= (1 << ($day - 1)); }
    }
    return [
        'title' => required_param('title', PARAM_RAW_TRIMMED),
        'description' => optional_param('description', '', PARAM_RAW),
        'product' => optional_param('product', '', PARAM_RAW),
        'category' => required_param('categorykey', PARAM_ALPHANUMEXT),
        'cadence' => required_param('cadence', PARAM_ALPHANUMEXT),
        'weekdaymask' => $mask,
        'monthweek' => optional_param('monthweek', 0, PARAM_INT),
        'stream' => required_param('stream', PARAM_ALPHANUMEXT),
        'priority' => required_param('priority', PARAM_ALPHANUMEXT),
        'planminutes' => optional_param('planminutes', 0, PARAM_INT),
        'requires_review' => optional_param('requires_review', 0, PARAM_BOOL),
    ];
};

$action = optional_param('action', '', PARAM_ALPHA);
if ($action !== '') {
    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') { throw new moodle_exception('taskcenter_post', 'local_kbacompany'); }
    require_sesskey();
    try {
        if ($action === 'install') {
            $series = recurring_task_service::install_library_template($uuid, (int)$USER->id,
                required_param('template', PARAM_ALPHANUMEXT));
            redirect(new moodle_url('/local/kbacompany/recurring_tasks.php', array_merge(['uuid' => $uuid, 'series' => $series],
                    $planuuid !== '' ? ['plan' => $planuuid] : [])),
                get_string('recurringtask_library_installed', 'local_kbacompany'));
        } else if ($action === 'addbattleplan') {
            $planuuid = required_param('plan', PARAM_RAW_TRIMMED);
            $templatekey = required_param('template', PARAM_ALPHANUMEXT);
            battle_plan_service::add_from_template($uuid, (int)$USER->id, $planuuid, $templatekey, [
                'product' => required_param('product', PARAM_RAW),
                'planminutes' => optional_param('planminutes', 0, PARAM_INT),
            ]);
            redirect(new moodle_url('/local/kbacompany/battle_plan.php', ['uuid' => $uuid, 'plan' => $planuuid]),
                get_string('battleplan_saved', 'local_kbacompany'));
        } else {
            if (!$canmanage) { throw new moodle_exception('tasklibrary_permission', 'local_kbacompany'); }
            if ($action === 'createcategory') {
                $key = recurring_task_library_admin_service::create_category($uuid, (int)$USER->id,
                    required_param('title', PARAM_RAW_TRIMMED));
                redirect($redirect_library(['category' => $key]),
                    get_string('tasklibrary_category_created', 'local_kbacompany'));
            } else if ($action === 'savecategory') {
                recurring_task_library_admin_service::save_category($uuid, (int)$USER->id,
                    required_param('categorykey', PARAM_ALPHANUMEXT), required_param('revision', PARAM_INT),
                    required_param('title', PARAM_RAW_TRIMMED));
                redirect($redirect_library([]), get_string('tasklibrary_category_saved', 'local_kbacompany'));
            } else if ($action === 'archivecategory' || $action === 'restorecategory') {
                $target = $action === 'archivecategory' ? 'archived' : 'active';
                recurring_task_library_admin_service::transition_category($uuid, (int)$USER->id,
                    required_param('categorykey', PARAM_ALPHANUMEXT), required_param('revision', PARAM_INT), $target);
                redirect($redirect_library([]), get_string($target === 'active' ?
                    'tasklibrary_category_restored' : 'tasklibrary_category_archived', 'local_kbacompany'));
            } else if ($action === 'createtemplate') {
                $key = recurring_task_library_admin_service::create_template($uuid, (int)$USER->id, $template_data());
                redirect($redirect_library(['template' => $key]),
                    get_string('tasklibrary_template_created', 'local_kbacompany'));
            } else if ($action === 'savetemplate') {
                $key = required_param('template', PARAM_ALPHANUMEXT);
                recurring_task_library_admin_service::save_template($uuid, (int)$USER->id, $key,
                    required_param('revision', PARAM_INT), $template_data());
                redirect($redirect_library(['template' => $key]),
                    get_string('tasklibrary_template_saved', 'local_kbacompany'));
            } else if ($action === 'archivetemplate' || $action === 'restoretemplate') {
                $key = required_param('template', PARAM_ALPHANUMEXT);
                $target = $action === 'archivetemplate' ? 'archived' : 'active';
                recurring_task_library_admin_service::transition_template($uuid, (int)$USER->id, $key,
                    required_param('revision', PARAM_INT), $target);
                redirect($redirect_library([]), get_string($target === 'active' ?
                    'tasklibrary_template_restored' : 'tasklibrary_template_archived', 'local_kbacompany'));
            }
        }
    } catch (moodle_exception $e) {
        if ($e->module !== 'local_kbacompany') { throw $e; }
        $error = $e->getMessage();
    }
}

$draftplans = battle_plan_service::draft_plan_options($uuid, (int)$USER->id);
$draftplanopts = [];
foreach ($draftplans as $planoption) {
    $draftplanopts[$planoption['uuid']] = $planoption['label'] . ' · ' . $planoption['employee_name'] .
        ' · ' . $planoption['position_name'];
}

$categoryoptions = recurring_task_library_service::category_options($uuid);
$categories = array_keys($categoryoptions);
if (!isset($categoryoptions[$category])) { $category = $categories ? reset($categories) : ''; }
$templates = recurring_task_library_service::templates($uuid);
if ($focus !== '' && isset($templates[$focus])) { $category = (string)$templates[$focus]['category']; }
$visible = [];
foreach ($templates as $template) {
    if ((string)$template['category'] !== $category) { continue; }
    if ($focus !== '' && (string)$template['key'] !== $focus) { continue; }
    if ($query !== '' && core_text::strpos(core_text::strtolower((string)$template['title']), core_text::strtolower($query)) === false) {
        continue;
    }
    $visible[] = $template;
}

$categoryadmin = $canmanage ? recurring_task_library_admin_service::category_admin_rows($uuid) : [];
$templateadmin = $canmanage ? recurring_task_library_admin_service::template_admin_rows($uuid) : [];
$editrow = ($canmanage && $edittemplate !== '' && isset($templateadmin[$edittemplate])) ? $templateadmin[$edittemplate] : null;

$PAGE->set_context($context);
$PAGE->set_url(new moodle_url('/local/kbacompany/recurring_task_library.php', [
    'uuid' => $uuid, 'category' => $category, 'q' => $query, 'plan' => $planuuid, 'template' => $focus,
    'edittemplate' => $edittemplate, 'editcategory' => $editcategory, 'newtemplate' => $newtemplate,
    'newcategory' => $newcategory, 'archive' => $showarchive,
]));
$PAGE->set_title(get_string('recurringtask_library_title', 'local_kbacompany'));
$PAGE->set_heading(get_string('pluginname', 'local_kbacompany'));

$cadenceoptions = [];
foreach (recurring_task_service::CADENCES as $cadence) {
    $cadenceoptions[$cadence] = get_string('recurringtask_cadence_' . $cadence, 'local_kbacompany');
}
$streamoptions = [];
foreach (task_service::STREAMS as $stream) { $streamoptions[$stream] = get_string('taskcenter_stream_' . $stream, 'local_kbacompany'); }
$priorityoptions = [];
foreach (task_service::PRIORITIES as $priority) { $priorityoptions[$priority] = get_string('taskcenter_priority_' . $priority, 'local_kbacompany'); }
$monthweekoptions = [0 => get_string('recurringtask_monthweek_none', 'local_kbacompany')];
for ($i = 1; $i <= 5; $i++) { $monthweekoptions[$i] = get_string('recurringtask_monthweek_' . $i, 'local_kbacompany'); }

$render_template_form = static function(array $values, string $actionname) use ($uuid, $categoryoptions, $cadenceoptions,
        $streamoptions, $priorityoptions, $monthweekoptions): void {
    $key = (string)($values['key'] ?? '');
    $revision = (int)($values['_library_revision'] ?? 0);
    $mask = (int)($values['weekdaymask'] ?? 0);
    echo html_writer::start_tag('form', ['method' => 'post', 'class' => 'kbacompany-form border rounded p-3 mb-3']);
    echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'uuid', 'value' => $uuid]);
    echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'action', 'value' => $actionname]);
    echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'sesskey', 'value' => sesskey()]);
    if ($key !== '') {
        echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'template', 'value' => $key]);
        echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'revision', 'value' => $revision]);
    }
    echo html_writer::start_tag('div', ['class' => 'row']);
    echo html_writer::start_tag('div', ['class' => 'col-md-8']);
    echo html_writer::label(get_string('tasklibrary_template_name', 'local_kbacompany'), 'lib_title_' . $key);
    echo html_writer::empty_tag('input', ['type' => 'text', 'name' => 'title', 'id' => 'lib_title_' . $key,
        'value' => (string)($values['title'] ?? ''), 'class' => 'form-control', 'required' => 'required', 'maxlength' => 255]);
    echo html_writer::end_tag('div');
    echo html_writer::start_tag('div', ['class' => 'col-md-4']);
    echo html_writer::label(get_string('tasklibrary_template_category', 'local_kbacompany'), 'lib_cat_' . $key);
    echo html_writer::select($categoryoptions, 'categorykey', (string)($values['category'] ?? ''), null,
        ['id' => 'lib_cat_' . $key, 'class' => 'form-control', 'required' => 'required']);
    echo html_writer::end_tag('div');
    echo html_writer::end_tag('div');

    echo html_writer::label(get_string('tasklibrary_template_description', 'local_kbacompany'), 'lib_desc_' . $key, false, ['class' => 'mt-2']);
    echo html_writer::tag('textarea', s((string)($values['description'] ?? '')), ['name' => 'description', 'id' => 'lib_desc_' . $key,
        'rows' => 3, 'class' => 'form-control']);
    echo html_writer::label(get_string('tasklibrary_template_product', 'local_kbacompany'), 'lib_product_' . $key, false, ['class' => 'mt-2']);
    echo html_writer::tag('textarea', s((string)($values['product'] ?? '')), ['name' => 'product', 'id' => 'lib_product_' . $key,
        'rows' => 2, 'class' => 'form-control']);

    echo html_writer::start_tag('div', ['class' => 'row mt-2']);
    echo html_writer::start_tag('div', ['class' => 'col-md-4']);
    echo html_writer::label(get_string('recurringtask_cadence', 'local_kbacompany'), 'lib_cad_' . $key);
    echo html_writer::select($cadenceoptions, 'cadence', (string)($values['cadence'] ?? 'weekly'), null,
        ['id' => 'lib_cad_' . $key, 'class' => 'form-control']);
    echo html_writer::end_tag('div');
    echo html_writer::start_tag('div', ['class' => 'col-md-4']);
    echo html_writer::label(get_string('taskcenter_stream', 'local_kbacompany'), 'lib_stream_' . $key);
    echo html_writer::select($streamoptions, 'stream', (string)($values['stream'] ?? 'operate'), null,
        ['id' => 'lib_stream_' . $key, 'class' => 'form-control']);
    echo html_writer::end_tag('div');
    echo html_writer::start_tag('div', ['class' => 'col-md-4']);
    echo html_writer::label(get_string('taskcenter_priority', 'local_kbacompany'), 'lib_priority_' . $key);
    echo html_writer::select($priorityoptions, 'priority', (string)($values['priority'] ?? 'normal'), null,
        ['id' => 'lib_priority_' . $key, 'class' => 'form-control']);
    echo html_writer::end_tag('div');
    echo html_writer::end_tag('div');

    echo html_writer::start_tag('div', ['class' => 'row mt-2']);
    echo html_writer::start_tag('div', ['class' => 'col-md-6']);
    echo html_writer::tag('div', get_string('recurringtask_weekdays', 'local_kbacompany'), ['class' => 'font-weight-bold mb-1']);
    for ($day = 1; $day <= 7; $day++) {
        $attrs = ['type' => 'checkbox', 'name' => 'weekday[]', 'value' => $day, 'id' => 'lib_day_' . $key . '_' . $day];
        if (($mask & (1 << ($day - 1))) !== 0) { $attrs['checked'] = 'checked'; }
        echo html_writer::start_tag('label', ['class' => 'mr-3']);
        echo html_writer::empty_tag('input', $attrs) . ' ' . get_string('recurringtask_weekday_' . $day, 'local_kbacompany');
        echo html_writer::end_tag('label');
    }
    echo html_writer::end_tag('div');
    echo html_writer::start_tag('div', ['class' => 'col-md-3']);
    echo html_writer::label(get_string('recurringtask_monthweek', 'local_kbacompany'), 'lib_monthweek_' . $key);
    echo html_writer::select($monthweekoptions, 'monthweek', (int)($values['monthweek'] ?? 0), null,
        ['id' => 'lib_monthweek_' . $key, 'class' => 'form-control']);
    echo html_writer::end_tag('div');
    echo html_writer::start_tag('div', ['class' => 'col-md-3']);
    echo html_writer::label(get_string('tasklibrary_template_planminutes', 'local_kbacompany'), 'lib_minutes_' . $key);
    echo html_writer::empty_tag('input', ['type' => 'number', 'name' => 'planminutes', 'id' => 'lib_minutes_' . $key,
        'value' => (int)($values['planminutes'] ?? 0), 'min' => 0, 'max' => 10080, 'step' => 1, 'class' => 'form-control']);
    echo html_writer::end_tag('div');
    echo html_writer::end_tag('div');

    $reviewattrs = ['type' => 'checkbox', 'name' => 'requires_review', 'value' => 1, 'id' => 'lib_review_' . $key];
    if (!empty($values['requires_review'])) { $reviewattrs['checked'] = 'checked'; }
    echo html_writer::start_tag('label', ['class' => 'mt-2']);
    echo html_writer::empty_tag('input', $reviewattrs) . ' ' . get_string('tasklibrary_template_requires_review', 'local_kbacompany');
    echo html_writer::end_tag('label');
    echo html_writer::tag('div', '');
    echo html_writer::tag('button', get_string($actionname === 'createtemplate' ? 'tasklibrary_add_template' : 'tasklibrary_save', 'local_kbacompany'),
        ['type' => 'submit', 'class' => 'btn btn-primary mt-2']);
    echo html_writer::end_tag('form');
};

$bmsshell = \local_kbacompany\local\bms_page_shell_service::begin($uuid, (int)$USER->id, 'recurring',
    get_string('recurringtask_library_title', 'local_kbacompany'));
echo $OUTPUT->heading(get_string('recurringtask_library_title', 'local_kbacompany'));
echo html_writer::tag('p', get_string('recurringtask_library_intro', 'local_kbacompany'), ['class' => 'lead']);
echo html_writer::tag('p', get_string('recurringtask_library_rule', 'local_kbacompany'), ['class' => 'alert alert-info']);
if (!empty($error)) { echo $OUTPUT->notification(s($error), 'error'); }

echo html_writer::link(new moodle_url('/local/kbacompany/tasks.php', ['uuid' => $uuid]),
    get_string('taskcenter_title', 'local_kbacompany'), ['class' => 'btn btn-outline-secondary mr-2']);
echo html_writer::link(new moodle_url('/local/kbacompany/recurring_tasks.php', array_merge(['uuid' => $uuid], $planuuid !== '' ? ['plan' => $planuuid] : [])),
    get_string('recurringtask_title', 'local_kbacompany'), ['class' => 'btn btn-primary mr-2']);
if ($planuuid !== '') {
    echo html_writer::link(new moodle_url('/local/kbacompany/battle_plan.php', ['uuid' => $uuid, 'plan' => $planuuid]),
        get_string('tasktemplate_back_to_battleplan', 'local_kbacompany'), ['class' => 'btn btn-outline-secondary mr-2']);
}
if ($canmanage) {
    echo html_writer::link($redirect_anchor(['newtemplate' => 1], 'library-editor'),
        '+ ' . get_string('tasklibrary_add_template', 'local_kbacompany'), ['class' => 'btn btn-success mr-2']);
    echo html_writer::link($redirect_anchor(['newcategory' => 1], 'library-editor'),
        '+ ' . get_string('tasklibrary_add_category', 'local_kbacompany'), ['class' => 'btn btn-outline-primary mr-2']);
    echo html_writer::link($redirect_anchor(['archive' => 1], 'library-archive'),
        get_string('tasklibrary_archived', 'local_kbacompany'), ['class' => 'btn btn-outline-secondary']);
}

// Legacy management panel is kept for old bookmarked URLs, but normal CRUD is rendered inline below.
if ($manage && $canmanage) {
    echo html_writer::start_tag('div', ['class' => 'card my-3']);
    echo html_writer::start_tag('div', ['class' => 'card-body']);
    echo html_writer::tag('h3', get_string('tasklibrary_manage', 'local_kbacompany'));
    echo html_writer::tag('p', get_string('tasklibrary_manage_help', 'local_kbacompany'), ['class' => 'alert alert-info']);
    echo html_writer::tag('p', get_string('tasklibrary_delete_note', 'local_kbacompany'), ['class' => 'small text-muted']);

    echo html_writer::start_tag('details', ['class' => 'mb-3']);
    echo html_writer::tag('summary', get_string('tasklibrary_add_category', 'local_kbacompany'), ['class' => 'btn btn-outline-primary d-inline-block']);
    echo html_writer::start_tag('form', ['method' => 'post', 'class' => 'form-inline mt-2']);
    echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'uuid', 'value' => $uuid]);
    echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'action', 'value' => 'createcategory']);
    echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'sesskey', 'value' => sesskey()]);
    echo html_writer::empty_tag('input', ['type' => 'text', 'name' => 'title', 'class' => 'form-control mr-2',
        'placeholder' => get_string('tasklibrary_category_name', 'local_kbacompany'), 'required' => 'required', 'maxlength' => 255]);
    echo html_writer::tag('button', get_string('tasklibrary_add_category', 'local_kbacompany'), ['type' => 'submit', 'class' => 'btn btn-primary']);
    echo html_writer::end_tag('form');
    echo html_writer::end_tag('details');

    echo html_writer::tag('h4', get_string('tasklibrary_categories', 'local_kbacompany'));
    foreach ($categoryadmin as $catrow) {
        echo html_writer::start_tag('div', ['class' => 'border rounded p-2 mb-2']);
        if ($catrow['status'] === 'active') {
            echo html_writer::start_tag('form', ['method' => 'post', 'class' => 'form-inline']);
            foreach (['uuid' => $uuid, 'action' => 'savecategory', 'categorykey' => $catrow['key'],
                'revision' => $catrow['revision'], 'sesskey' => sesskey()] as $name => $value) {
                echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => $name, 'value' => $value]);
            }
            echo html_writer::empty_tag('input', ['type' => 'text', 'name' => 'title', 'value' => $catrow['title'],
                'class' => 'form-control mr-2', 'required' => 'required', 'maxlength' => 255]);
            echo html_writer::tag('button', get_string('tasklibrary_save', 'local_kbacompany'), ['type' => 'submit', 'class' => 'btn btn-outline-primary mr-2']);
            echo html_writer::end_tag('form');
            echo html_writer::start_tag('form', ['method' => 'post', 'class' => 'd-inline']);
            foreach (['uuid' => $uuid, 'action' => 'archivecategory', 'categorykey' => $catrow['key'],
                'revision' => $catrow['revision'], 'sesskey' => sesskey()] as $name => $value) {
                echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => $name, 'value' => $value]);
            }
            echo html_writer::tag('button', get_string('tasklibrary_delete', 'local_kbacompany'), ['type' => 'submit', 'class' => 'btn btn-outline-danger btn-sm mt-2']);
            echo html_writer::end_tag('form');
        } else {
            echo html_writer::tag('strong', s($catrow['title'])) . ' ' . html_writer::tag('span', get_string('tasklibrary_status_archived', 'local_kbacompany'), ['class' => 'badge badge-secondary']);
            echo html_writer::start_tag('form', ['method' => 'post', 'class' => 'd-inline ml-2']);
            foreach (['uuid' => $uuid, 'action' => 'restorecategory', 'categorykey' => $catrow['key'],
                'revision' => $catrow['revision'], 'sesskey' => sesskey()] as $name => $value) {
                echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => $name, 'value' => $value]);
            }
            echo html_writer::tag('button', get_string('tasklibrary_restore', 'local_kbacompany'), ['type' => 'submit', 'class' => 'btn btn-outline-primary btn-sm']);
            echo html_writer::end_tag('form');
        }
        echo html_writer::end_tag('div');
    }
    echo html_writer::tag('p', get_string('tasklibrary_category_hidden_note', 'local_kbacompany'), ['class' => 'small text-muted']);

    echo html_writer::start_tag('details', ['class' => 'mb-3', 'open' => $editrow ? null : 'open']);
    echo html_writer::tag('summary', get_string('tasklibrary_add_template', 'local_kbacompany'), ['class' => 'btn btn-outline-primary d-inline-block']);
    $render_template_form([
        'category' => $category, 'cadence' => 'weekly', 'stream' => 'operate', 'priority' => 'normal',
        'weekdaymask' => 0, 'monthweek' => 0, 'planminutes' => 0, 'requires_review' => false,
    ], 'createtemplate');
    echo html_writer::end_tag('details');

    if ($editrow && $editrow['_library_status'] === 'active') {
        echo html_writer::tag('h4', get_string('tasklibrary_edit', 'local_kbacompany') . ': ' . s($editrow['title']));
        $render_template_form($editrow, 'savetemplate');
        echo html_writer::link($redirect_library([]), get_string('tasklibrary_cancel', 'local_kbacompany'),
            ['class' => 'btn btn-outline-secondary mb-3']);
    }

    $archived = array_filter($templateadmin, static function(array $row): bool { return $row['_library_status'] === 'archived'; });
    echo html_writer::tag('h4', get_string('tasklibrary_archived', 'local_kbacompany'));
    if (!$archived) {
        echo html_writer::tag('p', get_string('tasklibrary_no_archived', 'local_kbacompany'), ['class' => 'small text-muted']);
    } else {
        foreach ($archived as $row) {
            echo html_writer::start_tag('div', ['class' => 'border rounded p-2 mb-2']);
            echo html_writer::tag('strong', s($row['title'])) . ' · ' . s($row['category']);
            echo html_writer::start_tag('form', ['method' => 'post', 'class' => 'd-inline ml-2']);
            foreach (['uuid' => $uuid, 'action' => 'restoretemplate', 'template' => $row['key'],
                'revision' => $row['_library_revision'], 'sesskey' => sesskey()] as $name => $value) {
                echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => $name, 'value' => $value]);
            }
            echo html_writer::tag('button', get_string('tasklibrary_restore', 'local_kbacompany'), ['type' => 'submit', 'class' => 'btn btn-outline-primary btn-sm']);
            echo html_writer::end_tag('form');
            echo html_writer::end_tag('div');
        }
    }
    echo html_writer::end_tag('div');
    echo html_writer::end_tag('div');
}

if ($canmanage && ($newtemplate || $newcategory || $edittemplate !== '' || $editcategory !== '')) {
    echo html_writer::start_tag('div', ['id' => 'library-editor', 'class' => 'card my-3 border-primary']);
    echo html_writer::start_tag('div', ['class' => 'card-body']);
    if ($newcategory) {
        echo html_writer::tag('h3', get_string('tasklibrary_add_category', 'local_kbacompany'));
        echo html_writer::start_tag('form', ['method' => 'post']);
        foreach (['uuid' => $uuid, 'action' => 'createcategory', 'sesskey' => sesskey()] as $name => $value) {
            echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => $name, 'value' => $value]);
        }
        echo html_writer::label(get_string('tasklibrary_category_name', 'local_kbacompany'), 'tasklib_new_category');
        echo html_writer::empty_tag('input', ['type' => 'text', 'name' => 'title', 'id' => 'tasklib_new_category',
            'class' => 'form-control', 'required' => 'required', 'maxlength' => 255]);
        echo html_writer::tag('button', get_string('tasklibrary_add_category', 'local_kbacompany'),
            ['type' => 'submit', 'class' => 'btn btn-primary mt-2 mr-2']);
        echo html_writer::link($redirect_library([]), get_string('tasklibrary_cancel', 'local_kbacompany'),
            ['class' => 'btn btn-outline-secondary mt-2']);
        echo html_writer::end_tag('form');
    } else if ($editcategory !== '' && isset($categoryadmin[$editcategory])) {
        $catrow = $categoryadmin[$editcategory];
        echo html_writer::tag('h3', get_string('tasklibrary_edit_category', 'local_kbacompany') . ': ' . s($catrow['title']));
        if ($catrow['status'] === 'active') {
            echo html_writer::start_tag('form', ['method' => 'post']);
            foreach (['uuid' => $uuid, 'action' => 'savecategory', 'categorykey' => $catrow['key'],
                'revision' => $catrow['revision'], 'sesskey' => sesskey()] as $name => $value) {
                echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => $name, 'value' => $value]);
            }
            echo html_writer::label(get_string('tasklibrary_category_name', 'local_kbacompany'), 'tasklib_edit_category');
            echo html_writer::empty_tag('input', ['type' => 'text', 'name' => 'title', 'id' => 'tasklib_edit_category',
                'value' => $catrow['title'], 'class' => 'form-control', 'required' => 'required', 'maxlength' => 255]);
            echo html_writer::tag('button', get_string('tasklibrary_save', 'local_kbacompany'),
                ['type' => 'submit', 'class' => 'btn btn-primary mt-2 mr-2']);
            echo html_writer::end_tag('form');
            echo html_writer::start_tag('form', ['method' => 'post', 'class' => 'd-inline']);
            foreach (['uuid' => $uuid, 'action' => 'archivecategory', 'categorykey' => $catrow['key'],
                'revision' => $catrow['revision'], 'sesskey' => sesskey()] as $name => $value) {
                echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => $name, 'value' => $value]);
            }
            echo html_writer::tag('button', get_string('tasklibrary_delete', 'local_kbacompany'),
                ['type' => 'submit', 'class' => 'btn btn-outline-danger mt-2 mr-2']);
            echo html_writer::end_tag('form');
            echo html_writer::link($redirect_library([]), get_string('tasklibrary_cancel', 'local_kbacompany'),
                ['class' => 'btn btn-outline-secondary mt-2']);
        }
    } else if ($editrow && $editrow['_library_status'] === 'active') {
        echo html_writer::tag('h3', get_string('tasklibrary_edit', 'local_kbacompany') . ': ' . s($editrow['title']));
        $render_template_form($editrow, 'savetemplate');
        echo html_writer::link($redirect_library([]), get_string('tasklibrary_cancel', 'local_kbacompany'),
            ['class' => 'btn btn-outline-secondary']);
    } else if ($newtemplate) {
        echo html_writer::tag('h3', get_string('tasklibrary_add_template', 'local_kbacompany'));
        $render_template_form([
            'category' => $category, 'cadence' => 'weekly', 'stream' => 'operate', 'priority' => 'normal',
            'weekdaymask' => 0, 'monthweek' => 0, 'planminutes' => 0, 'requires_review' => false,
        ], 'createtemplate');
        echo html_writer::link($redirect_library([]), get_string('tasklibrary_cancel', 'local_kbacompany'),
            ['class' => 'btn btn-outline-secondary']);
    }
    echo html_writer::end_tag('div');
    echo html_writer::end_tag('div');
}

if ($canmanage && $showarchive) {
    echo html_writer::start_tag('div', ['id' => 'library-archive', 'class' => 'card my-3']);
    echo html_writer::start_tag('div', ['class' => 'card-body']);
    echo html_writer::tag('h3', get_string('tasklibrary_archived', 'local_kbacompany'));
    $hasarchive = false;
    foreach ($categoryadmin as $catrow) {
        if ($catrow['status'] !== 'archived') { continue; }
        $hasarchive = true;
        echo html_writer::start_tag('div', ['class' => 'border rounded p-2 mb-2']);
        echo html_writer::tag('strong', s($catrow['title'])) . ' · ' . get_string('tasklibrary_category_name', 'local_kbacompany');
        echo html_writer::start_tag('form', ['method' => 'post', 'class' => 'd-inline ml-2']);
        foreach (['uuid' => $uuid, 'action' => 'restorecategory', 'categorykey' => $catrow['key'],
            'revision' => $catrow['revision'], 'sesskey' => sesskey()] as $name => $value) {
            echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => $name, 'value' => $value]);
        }
        echo html_writer::tag('button', get_string('tasklibrary_restore', 'local_kbacompany'),
            ['type' => 'submit', 'class' => 'btn btn-outline-primary btn-sm']);
        echo html_writer::end_tag('form');
        echo html_writer::end_tag('div');
    }
    foreach ($templateadmin as $row) {
        if ($row['_library_status'] !== 'archived') { continue; }
        $hasarchive = true;
        echo html_writer::start_tag('div', ['class' => 'border rounded p-2 mb-2']);
        echo html_writer::tag('strong', s($row['title'])) . ' · ' . get_string('tasklibrary_template_name', 'local_kbacompany');
        echo html_writer::start_tag('form', ['method' => 'post', 'class' => 'd-inline ml-2']);
        foreach (['uuid' => $uuid, 'action' => 'restoretemplate', 'template' => $row['key'],
            'revision' => $row['_library_revision'], 'sesskey' => sesskey()] as $name => $value) {
            echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => $name, 'value' => $value]);
        }
        echo html_writer::tag('button', get_string('tasklibrary_restore', 'local_kbacompany'),
            ['type' => 'submit', 'class' => 'btn btn-outline-primary btn-sm']);
        echo html_writer::end_tag('form');
        echo html_writer::end_tag('div');
    }
    if (!$hasarchive) { echo html_writer::tag('p', get_string('tasklibrary_no_archived', 'local_kbacompany')); }
    echo html_writer::end_tag('div');
    echo html_writer::end_tag('div');
}

echo html_writer::start_tag('form', ['method' => 'get', 'class' => 'form-inline my-3']);
echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'uuid', 'value' => $uuid]);
echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'category', 'value' => $category]);
if ($planuuid !== '') { echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'plan', 'value' => $planuuid]); }
echo html_writer::empty_tag('input', ['type' => 'search', 'name' => 'q', 'value' => $query,
    'class' => 'form-control mr-2', 'placeholder' => get_string('recurringtask_library_search', 'local_kbacompany')]);
echo html_writer::tag('button', get_string('search'), ['type' => 'submit', 'class' => 'btn btn-outline-secondary']);
echo html_writer::end_tag('form');

echo html_writer::start_tag('div', ['class' => 'mb-2']);
foreach ($categoryoptions as $cat => $label) {
    $class = $cat === $category ? 'btn btn-secondary mr-1 mb-1' : 'btn btn-outline-secondary mr-1 mb-1';
    echo html_writer::link(new moodle_url('/local/kbacompany/recurring_task_library.php', [
        'uuid' => $uuid, 'category' => $cat, 'plan' => $planuuid,
    ]), s($label), ['class' => $class]);
}
echo html_writer::end_tag('div');

if ($canmanage && $category !== '' && isset($categoryadmin[$category]) && $categoryadmin[$category]['status'] === 'active') {
    $selectedcat = $categoryadmin[$category];
    echo html_writer::start_tag('div', ['class' => 'd-flex flex-wrap align-items-center border rounded p-2 mb-3 bg-light']);
    echo html_writer::tag('span', get_string('tasklibrary_selected_category', 'local_kbacompany') . ': ' .
        html_writer::tag('strong', s($selectedcat['title'])), ['class' => 'mr-3 mb-1']);
    echo html_writer::link($redirect_anchor(['editcategory' => $category, 'category' => $category], 'library-editor'),
        get_string('tasklibrary_edit_category', 'local_kbacompany'), ['class' => 'btn btn-outline-primary btn-sm mr-2 mb-1']);
    echo html_writer::start_tag('form', ['method' => 'post', 'class' => 'd-inline mb-1']);
    foreach (['uuid' => $uuid, 'action' => 'archivecategory', 'categorykey' => $selectedcat['key'],
        'revision' => $selectedcat['revision'], 'sesskey' => sesskey()] as $name => $value) {
        echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => $name, 'value' => $value]);
    }
    echo html_writer::tag('button', get_string('tasklibrary_delete_category', 'local_kbacompany'),
        ['type' => 'submit', 'class' => 'btn btn-outline-danger btn-sm']);
    echo html_writer::end_tag('form');
    echo html_writer::tag('div', get_string('tasklibrary_category_manage_hint', 'local_kbacompany'),
        ['class' => 'w-100 small text-muted mt-1']);
    echo html_writer::end_tag('div');
}

if (!$visible) { echo $OUTPUT->notification(get_string('recurringtask_library_empty', 'local_kbacompany'), 'info'); }
foreach ($visible as $template) {
    echo html_writer::start_tag('div', ['class' => 'card mb-3']);
    echo html_writer::start_tag('div', ['class' => 'card-body']);
    echo html_writer::tag('h4', s($template['title']), ['class' => 'card-title']);
    if (!empty($template['description'])) { echo html_writer::tag('p', nl2br(s((string)$template['description']))); }
    echo html_writer::tag('p', get_string('recurringtask_library_source', 'local_kbacompany') . ': ' . s((string)$template['source_label']),
        ['class' => 'small text-muted mb-1']);
    echo html_writer::tag('p', get_string('recurringtask_library_sourcecadence', 'local_kbacompany') . ': ' .
        s((string)$template['source_cadence'] !== '' ? (string)$template['source_cadence'] : get_string('recurringtask_library_notset', 'local_kbacompany')));
    if ((int)($template['source_score'] ?? 0) > 0 || (string)($template['source_time'] ?? '') !== '') {
        $meta = [];
        if ((int)($template['source_score'] ?? 0) > 0) { $meta[] = get_string('recurringtask_library_sourcescore', 'local_kbacompany') . ': ' . (int)$template['source_score']; }
        if ((string)($template['source_time'] ?? '') !== '') { $meta[] = get_string('recurringtask_library_sourcetime', 'local_kbacompany') . ': ' . s((string)$template['source_time']); }
        echo html_writer::tag('p', implode(' · ', $meta), ['class' => 'small text-muted']);
    }
    echo html_writer::tag('p', get_string('recurringtask_cadence', 'local_kbacompany') . ': ' .
        get_string('recurringtask_cadence_' . $template['cadence'], 'local_kbacompany'));
    if (!empty($template['product'])) {
        echo html_writer::tag('p', html_writer::tag('strong', get_string('taskcenter_product', 'local_kbacompany') . ': ') . s((string)$template['product']));
    }
    if (!empty($template['requires_review'])) {
        echo html_writer::tag('p', get_string('recurringtask_library_requires_review', 'local_kbacompany'), ['class' => 'alert alert-warning']);
    }
    if ($canmanage) {
        echo html_writer::tag('p', ((bool)$template['_library_builtin'] ? get_string('tasklibrary_builtin', 'local_kbacompany') :
            get_string('tasklibrary_custom', 'local_kbacompany')) . ' · ' .
            get_string('tasklibrary_revision', 'local_kbacompany', (int)$template['_library_revision']), ['class' => 'small text-muted']);
        echo html_writer::link($redirect_anchor(['edittemplate' => $template['key'], 'category' => $template['category']], 'library-editor'),
            get_string('tasklibrary_edit', 'local_kbacompany'), ['class' => 'btn btn-outline-secondary btn-sm mr-2']);
        echo html_writer::start_tag('form', ['method' => 'post', 'class' => 'd-inline']);
        foreach (['uuid' => $uuid, 'action' => 'archivetemplate', 'template' => $template['key'],
            'revision' => $template['_library_revision'], 'sesskey' => sesskey()] as $name => $value) {
            echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => $name, 'value' => $value]);
        }
        echo html_writer::tag('button', get_string('tasklibrary_delete', 'local_kbacompany'), ['type' => 'submit', 'class' => 'btn btn-outline-danger btn-sm']);
        echo html_writer::end_tag('form');
        echo html_writer::tag('hr', '');
    }
    echo html_writer::start_tag('form', ['method' => 'post', 'class' => 'd-inline']);
    foreach (['uuid' => $uuid, 'action' => 'install', 'template' => $template['key'], 'sesskey' => sesskey()] as $name => $value) {
        echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => $name, 'value' => $value]);
    }
    echo html_writer::tag('button', get_string('recurringtask_library_use', 'local_kbacompany'), ['type' => 'submit', 'class' => 'btn btn-primary mr-2']);
    echo html_writer::end_tag('form');
    if ($draftplanopts) {
        echo html_writer::start_tag('details', ['class' => 'mt-2']);
        echo html_writer::tag('summary', get_string('tasktemplate_add_to_battleplan', 'local_kbacompany'),
            ['class' => 'btn btn-outline-primary d-inline-block']);
        echo html_writer::start_tag('form', ['method' => 'post', 'class' => 'kbacompany-form border rounded p-3 mt-2']);
        foreach (['uuid' => $uuid, 'action' => 'addbattleplan', 'template' => $template['key'], 'sesskey' => sesskey()] as $name => $value) {
            echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => $name, 'value' => $value]);
        }
        echo html_writer::label(get_string('battleplan_title', 'local_kbacompany'), 'tpl_plan_' . $template['key']);
        echo html_writer::select($draftplanopts, 'plan', $planuuid, ['' => get_string('choosedots')],
            ['id' => 'tpl_plan_' . $template['key'], 'class' => 'form-control', 'required' => 'required']);
        echo html_writer::label(get_string('taskcenter_product', 'local_kbacompany'), 'tpl_product_' . $template['key'], false, ['class' => 'mt-2']);
        echo html_writer::tag('textarea', s((string)($template['product'] ?? '')), ['name' => 'product', 'id' => 'tpl_product_' . $template['key'],
            'rows' => 2, 'class' => 'form-control', 'required' => 'required']);
        echo html_writer::label(get_string('taskcenter_planminutes', 'local_kbacompany'), 'tpl_minutes_' . $template['key'], false, ['class' => 'mt-2']);
        echo html_writer::empty_tag('input', ['type' => 'number', 'name' => 'planminutes', 'id' => 'tpl_minutes_' . $template['key'],
            'value' => (int)($template['planminutes'] ?? 0), 'min' => 0, 'step' => 1, 'class' => 'form-control']);
        echo html_writer::tag('button', get_string('tasktemplate_confirm_add', 'local_kbacompany'),
            ['type' => 'submit', 'class' => 'btn btn-primary mt-2']);
        echo html_writer::end_tag('form');
        echo html_writer::end_tag('details');
    } else {
        echo html_writer::tag('p', get_string('taskcenter_battleplan_no_draft', 'local_kbacompany'), ['class' => 'small text-muted mt-2']);
        echo html_writer::link(new moodle_url('/local/kbacompany/battle_plan.php', ['uuid' => $uuid]),
            get_string('battleplan_title', 'local_kbacompany'), ['class' => 'btn btn-outline-primary mt-1']);
    }
    echo html_writer::end_tag('div');
    echo html_writer::end_tag('div');
}

echo html_writer::tag('p', get_string('recurringtask_library_provenance_multi', 'local_kbacompany'), ['class' => 'small text-muted mt-4']);
\local_kbacompany\local\bms_page_shell_service::finish($bmsshell);
