<?php
// Company-scoped BMS24 access roles. Core Moodle rights are never modified here.
require_once(__DIR__ . '/../../config.php');

use local_kbacompany\local\access_role_service as roles;
use local_kbacompany\local\company_context_service;
use local_kbacompany\local\bms_page_shell_service;

require_login();
$context = context_system::instance();
require_capability('local/kbacompany:viewowncompany', $context);
$uuid = required_param('uuid', PARAM_RAW_TRIMMED);
$uuid = company_context_service::normalise_uuid($uuid);
$actor = (int)$USER->id;
roles::require_administrator($uuid, $actor);
$baseurl = new moodle_url('/local/kbacompany/access_roles.php', ['uuid' => $uuid]);
$roleid = optional_param('roleid', 0, PARAM_INT);
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_sesskey();
    $action = required_param('operation', PARAM_ALPHANUMEXT);
    if ($action === 'templates') {
        roles::seed_templates($uuid, $actor);
    } else if ($action === 'create') {
        roles::create_role($uuid, $actor, required_param('name', PARAM_TEXT),
            optional_param('description', '', PARAM_TEXT));
    } else if ($action === 'grant') {
        $roleid = required_param('roleid', PARAM_INT);
        roles::set_grant($uuid, $actor, $roleid,
            required_param('objecttype', PARAM_ALPHANUMEXT),
            required_param('actionname', PARAM_ALPHANUMEXT),
            required_param('enabled', PARAM_BOOL),
            optional_param('scope', 'company', PARAM_ALPHA));
    } else if ($action === 'assign') {
        $roleid = required_param('roleid', PARAM_INT);
        roles::assign($uuid, $actor, $roleid, required_param('userid', PARAM_INT), true);
    } else if ($action === 'revoke') {
        $roleid = required_param('roleid', PARAM_INT);
        roles::assign($uuid, $actor, $roleid, required_param('userid', PARAM_INT), false);
    } else {
        throw new moodle_exception('invalidparameter');
    }
    redirect(new moodle_url('/local/kbacompany/access_roles.php', ['uuid' => $uuid, 'roleid' => $roleid]),
        get_string('bmsacl_saved', 'local_kbacompany'));
}

$PAGE->set_context($context);
$PAGE->set_url($baseurl);
$PAGE->set_title(get_string('bmsacl_title', 'local_kbacompany'));
$PAGE->set_heading(get_string('pluginname', 'local_kbacompany'));
$shell = bms_page_shell_service::begin($uuid, $actor, 'settings', get_string('bmsacl_title', 'local_kbacompany'));

$all = roles::list_roles($uuid, $actor);
if ($roleid <= 0 && $all) { $roleid = (int)$all[0]->id; }
$selected = null;
foreach ($all as $role) {
    if ((int)$role->id === $roleid) { $selected = $role; break; }
}
if (!$selected && $all) { $selected = $all[0]; $roleid = (int)$selected->id; }
$csrf = html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'sesskey', 'value' => sesskey()]);
$companyfield = html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'uuid', 'value' => $uuid]);
$actionfield = static function(string $value): string {
    return html_writer::empty_tag('input', ['type'=>'hidden', 'name'=>'operation', 'value'=>$value]);
};
$formstart = static function(string $class = '') use ($baseurl): string {
    return html_writer::start_tag('form', ['method'=>'post', 'action'=>$baseurl->out(false), 'class'=>$class]);
};
$hidden = $csrf . $companyfield;

echo html_writer::tag('h2', get_string('bmsacl_title','local_kbacompany'), ['class'=>'h3 mb-2']);
echo html_writer::tag('p', get_string('bmsacl_intro','local_kbacompany'), ['class'=>'text-muted']);
echo html_writer::div(get_string('bmsacl_company_security','local_kbacompany'), 'alert alert-info');
$seats = roles::seat_snapshot($uuid, $actor);
echo html_writer::start_div('card card-body mb-3');
echo html_writer::tag('h3', get_string('bmsacl_seats', 'local_kbacompany'), ['class' => 'h5']);
echo html_writer::tag('p', get_string('bmsacl_activeusers', 'local_kbacompany') . ': ' .
    (int)$seats['activeusers'], ['class' => 'mb-1']);
echo html_writer::tag('p', get_string('bmsacl_unknownlimit', 'local_kbacompany'),
    ['class' => 'text-muted mb-0']);
echo html_writer::end_div();
echo html_writer::div(get_string('bmsacl_integratornote', 'local_kbacompany'), 'alert alert-secondary');
// Explicit enforcement register. Non-enforced objects are not editable toggles.
echo html_writer::start_div('card card-body mb-3');
echo html_writer::tag('h3', get_string('bmsacl_enforcement_title', 'local_kbacompany'), ['class'=>'h5']);
echo html_writer::tag('p', get_string('bmsacl_enforcement_note', 'local_kbacompany'), ['class'=>'text-muted']);
$enforcementtable = new html_table();
$enforcementtable->attributes['class'] = 'table table-sm table-striped';
$enforcementtable->head = [get_string('bmsacl_object', 'local_kbacompany'),
    get_string('bmsacl_enforcement_status', 'local_kbacompany')];
foreach (roles::OBJECTS as $objectkey => $objectlabel) {
    $active = in_array($objectkey, roles::ENFORCED_OBJECTS, true);
    $enforcementtable->data[] = [s($objectlabel), $active && $objectkey !== 'management_cycle' ? get_string('bmsacl_partial', 'local_kbacompany') : ($active ?
        get_string('bmsacl_enforced', 'local_kbacompany') :
        get_string('bmsacl_notenforced', 'local_kbacompany'))];
}
echo html_writer::table($enforcementtable);
echo html_writer::end_div();

// Effective permission projection only for server-enforced management cycles.
$inspectusers = roles::member_options($uuid, $actor);
$inspectid = optional_param('inspectuserid', 0, PARAM_INT);
if (!$inspectid || !isset($inspectusers[$inspectid])) {
    $inspectid = $inspectusers ? (int)array_key_first($inspectusers) : 0;
}
echo html_writer::start_div('card card-body mb-3');
echo html_writer::tag('h3', get_string('bmsacl_effective_title', 'local_kbacompany'), ['class'=>'h5']);
echo html_writer::tag('p', get_string('bmsacl_effective_note', 'local_kbacompany'), ['class'=>'text-muted']);
if ($inspectusers) {
    $inspectform = html_writer::start_tag('form', ['method'=>'get', 'action'=>$baseurl->out(false), 'class'=>'form-inline mb-3']);
    $inspectform .= html_writer::empty_tag('input', ['type'=>'hidden','name'=>'uuid','value'=>$uuid]);
    $inspectform .= html_writer::empty_tag('input', ['type'=>'hidden','name'=>'roleid','value'=>$roleid]);
    $options = '';
    foreach ($inspectusers as $id => $label) {
        $options .= html_writer::tag('option', s($label), ['value'=>(int)$id,
            'selected'=>((int)$id === $inspectid) ? 'selected' : null]);
    }
    $inspectform .= html_writer::tag('select', $options,
        ['name'=>'inspectuserid','class'=>'form-control mr-2']);
    $inspectform .= html_writer::tag('button', get_string('bmsacl_inspect','local_kbacompany'),
        ['type'=>'submit','class'=>'btn btn-outline-primary']);
    echo $inspectform . html_writer::end_tag('form');
    $effective = new html_table();
    $effective->head = [get_string('bmsacl_object','local_kbacompany'),
        get_string('bmsacl_view','local_kbacompany'),
        get_string('bmsacl_createaction','local_kbacompany'),
        get_string('bmsacl_edit','local_kbacompany')];
    $effective->attributes['class'] = 'table table-bordered';
    $values = [s(roles::OBJECTS['management_cycle'])];
    foreach (['view', 'create', 'edit'] as $permissionaction) {
        $values[] = roles::can($uuid, $inspectid, 'management_cycle', $permissionaction) ?
            get_string('yes') : get_string('no');
    }
    $effective->data = [$values];
    foreach (['task', 'battle_plan'] as $objectkey) {
        $effective->data[] = [s(roles::OBJECTS[$objectkey]),
            roles::department_view_userids($uuid, $inspectid, $objectkey) ?
                get_string('bmsacl_departmentview','local_kbacompany') : '—',
            roles::can($uuid, $inspectid, $objectkey, 'create') ? get_string('yes') : get_string('no'),
            roles::can($uuid, $inspectid, $objectkey, 'edit') ? get_string('yes') : get_string('no')];
    }
    echo html_writer::table($effective);
} else {
    echo html_writer::div(get_string('bmsacl_noinspect','local_kbacompany'), 'alert alert-secondary');
}
echo html_writer::end_div();

echo html_writer::div(html_writer::link(new moodle_url('/local/kbacompany/tariff_catalog.php',
    ['uuid'=>$uuid]), get_string('bmstariff_title','local_kbacompany'),
    ['class'=>'btn btn-outline-primary mb-3']));


echo html_writer::start_div('card card-body mb-3');
echo html_writer::tag('h3', get_string('bmsacl_create','local_kbacompany'), ['class'=>'h5']);
echo $formstart('form-inline mb-3') . $hidden . $actionfield('templates') .
    html_writer::tag('button', get_string('bmsacl_templates','local_kbacompany'),
        ['class'=>'btn btn-outline-primary', 'type'=>'submit']) . html_writer::end_tag('form');
echo $formstart('form-row align-items-end') . $hidden . $actionfield('create');
echo html_writer::div(
    html_writer::tag('label', get_string('bmsacl_role_name','local_kbacompany'), ['for'=>'bmsacl-name']) .
    html_writer::empty_tag('input', ['type'=>'text', 'name'=>'name', 'id'=>'bmsacl-name', 'maxlength'=>100,
        'required'=>'required', 'class'=>'form-control w-100']), 'col-md-4 mb-2');
echo html_writer::div(
    html_writer::tag('label', get_string('bmsacl_role_description','local_kbacompany'), ['for'=>'bmsacl-desc']) .
    html_writer::empty_tag('input', ['type'=>'text', 'name'=>'description', 'id'=>'bmsacl-desc',
        'maxlength'=>1000, 'class'=>'form-control w-100']), 'col-md-6 mb-2');
echo html_writer::div(html_writer::tag('button', get_string('bmsacl_add','local_kbacompany'),
    ['type'=>'submit', 'class'=>'btn btn-success']), 'col-md-2 mb-2');
echo html_writer::end_tag('form');
echo html_writer::end_div();

if (!$all) {
    echo html_writer::div(get_string('bmsacl_empty','local_kbacompany'), 'alert alert-secondary');
} else {
    echo html_writer::start_div('row');
    echo html_writer::start_div('col-lg-3 mb-3');
    echo html_writer::start_div('list-group');
    foreach ($all as $role) {
        echo html_writer::link(new moodle_url('/local/kbacompany/access_roles.php',
            ['uuid'=>$uuid, 'roleid'=>$role->id]), s($role->name),
            ['class'=>'list-group-item list-group-item-action' . ((int)$role->id === $roleid ? ' active' : '')]);
    }
    echo html_writer::end_div() . html_writer::end_div();
    echo html_writer::start_div('col-lg-9');
    echo html_writer::tag('h3', format_string($selected->name), ['class'=>'h4']);
    echo html_writer::tag('p', s($selected->description), ['class'=>'text-muted']);
    echo html_writer::tag('h4', get_string('bmsacl_matrix','local_kbacompany'), ['class'=>'h5']);
    $grants = roles::role_grants($uuid, $actor);
    $rolegrants = $grants[$roleid] ?? [];
    $grants = $rolegrants['management_cycle'] ?? [];
    $table = new html_table();
    $table->attributes['class'] = 'table table-bordered';
    $table->head = [get_string('bmsacl_object','local_kbacompany'),
        get_string('bmsacl_view','local_kbacompany'),
        get_string('bmsacl_createaction','local_kbacompany'),
        get_string('bmsacl_edit','local_kbacompany')];
    $cells = [s(roles::OBJECTS['management_cycle'])];
    foreach (['view','create','edit'] as $action) {
        $enabled = !empty($grants[$action]);
        $out = $formstart('d-inline-block') . $hidden . $actionfield('grant') .
            html_writer::empty_tag('input', ['type'=>'hidden','name'=>'roleid','value'=>$roleid]) .
            html_writer::empty_tag('input', ['type'=>'hidden','name'=>'objecttype','value'=>'management_cycle']) .
            html_writer::empty_tag('input', ['type'=>'hidden','name'=>'actionname','value'=>$action]) .
            html_writer::empty_tag('input', ['type'=>'hidden','name'=>'enabled','value'=>$enabled ? 0 : 1]) .
            html_writer::tag('button', $enabled ? get_string('yes') : get_string('no'),
                ['type'=>'submit','class'=>'btn btn-sm ' . ($enabled ? 'btn-success' : 'btn-outline-secondary'),
                 'aria-label'=>get_string('bmsacl_toggle','local_kbacompany', $action)]) .
            html_writer::end_tag('form');
        $cells[] = $out;
    }
    $table->data = [$cells];
    foreach (['task', 'battle_plan'] as $objectkey) {
        $enabled = !empty($rolegrants[$objectkey]['edit']);
        $out = $formstart('d-inline-block') . $hidden . $actionfield('grant') .
            html_writer::empty_tag('input', ['type'=>'hidden','name'=>'roleid','value'=>$roleid]) .
            html_writer::empty_tag('input', ['type'=>'hidden','name'=>'objecttype','value'=>$objectkey]) .
            html_writer::empty_tag('input', ['type'=>'hidden','name'=>'actionname','value'=>'edit']) .
            html_writer::empty_tag('input', ['type'=>'hidden','name'=>'enabled','value'=>$enabled ? 0 : 1]) .
            html_writer::tag('button', $enabled ? get_string('yes') : get_string('no'),
                ['type'=>'submit', 'class'=>'btn btn-sm ' . ($enabled ? 'btn-success' : 'btn-outline-secondary')]) .
            html_writer::end_tag('form');
        $viewenabled = (($rolegrants[$objectkey]['view'] ?? '') === 'department');
        $viewcontrol = $formstart('d-inline-block') . $hidden . $actionfield('grant') .
            html_writer::empty_tag('input', ['type'=>'hidden','name'=>'roleid','value'=>$roleid]) .
            html_writer::empty_tag('input', ['type'=>'hidden','name'=>'objecttype','value'=>$objectkey]) .
            html_writer::empty_tag('input', ['type'=>'hidden','name'=>'actionname','value'=>'view']) .
            html_writer::empty_tag('input', ['type'=>'hidden','name'=>'scope','value'=>'department']) .
            html_writer::empty_tag('input', ['type'=>'hidden','name'=>'enabled','value'=>$viewenabled ? 0 : 1]) .
            html_writer::tag('button', get_string('bmsacl_departmentview','local_kbacompany') . ': ' .
                ($viewenabled ? get_string('yes') : get_string('no')),
                ['type'=>'submit','class'=>'btn btn-sm ' . ($viewenabled ? 'btn-success' : 'btn-outline-secondary')]) .
            html_writer::end_tag('form');
        $createenabled = (($rolegrants[$objectkey]['create'] ?? '') === 'company');
        $createcontrol = $formstart('d-inline-block') . $hidden . $actionfield('grant') .
            html_writer::empty_tag('input', ['type'=>'hidden','name'=>'roleid','value'=>$roleid]) .
            html_writer::empty_tag('input', ['type'=>'hidden','name'=>'objecttype','value'=>$objectkey]) .
            html_writer::empty_tag('input', ['type'=>'hidden','name'=>'actionname','value'=>'create']) .
            html_writer::empty_tag('input', ['type'=>'hidden','name'=>'scope','value'=>'company']) .
            html_writer::empty_tag('input', ['type'=>'hidden','name'=>'enabled','value'=>$createenabled ? 0 : 1]) .
            html_writer::tag('button', $createenabled ? get_string('yes') : get_string('no'),
                ['type'=>'submit','class'=>'btn btn-sm ' . ($createenabled ? 'btn-success' : 'btn-outline-secondary')]) .
            html_writer::end_tag('form');
        $table->data[] = [s(roles::OBJECTS[$objectkey]), $viewcontrol, $createcontrol, $out];
    }
    foreach (['edit'=>'bmsacl_taskdepartmentedit', 'approve'=>'bmsacl_taskdepartmentapprove'] as $right=>$langkey) {
        $enabled = (($rolegrants['task'][$right] ?? '') === 'department');
        $control = $formstart('d-inline-block') . $hidden . $actionfield('grant') .
            html_writer::empty_tag('input', ['type'=>'hidden','name'=>'roleid','value'=>$roleid]) .
            html_writer::empty_tag('input', ['type'=>'hidden','name'=>'objecttype','value'=>'task']) .
            html_writer::empty_tag('input', ['type'=>'hidden','name'=>'actionname','value'=>$right]) .
            html_writer::empty_tag('input', ['type'=>'hidden','name'=>'scope','value'=>'department']) .
            html_writer::empty_tag('input', ['type'=>'hidden','name'=>'enabled','value'=>$enabled ? 0 : 1]) .
            html_writer::tag('button', get_string($langkey,'local_kbacompany') . ': ' .
                ($enabled ? get_string('yes') : get_string('no')),
                ['type'=>'submit','class'=>'btn btn-sm ' . ($enabled ? 'btn-success' : 'btn-outline-secondary')]) .
            html_writer::end_tag('form');
        echo html_writer::div($control, 'mb-3');
    }
    $departmentcreate = (($rolegrants['battle_plan']['create'] ?? '') === 'department');
    $createcontrol = $formstart('d-inline-block') . $hidden . $actionfield('grant') .
        html_writer::empty_tag('input', ['type'=>'hidden','name'=>'roleid','value'=>$roleid]) .
        html_writer::empty_tag('input', ['type'=>'hidden','name'=>'objecttype','value'=>'battle_plan']) .
        html_writer::empty_tag('input', ['type'=>'hidden','name'=>'actionname','value'=>'create']) .
        html_writer::empty_tag('input', ['type'=>'hidden','name'=>'scope','value'=>'department']) .
        html_writer::empty_tag('input', ['type'=>'hidden','name'=>'enabled','value'=>$departmentcreate ? 0 : 1]) .
        html_writer::tag('button', get_string('bmsacl_departmentcreate','local_kbacompany') . ': ' .
            ($departmentcreate ? get_string('yes') : get_string('no')),
            ['type'=>'submit','class'=>'btn btn-sm ' . ($departmentcreate ? 'btn-success' : 'btn-outline-secondary')]) .
        html_writer::end_tag('form');
    echo html_writer::div($createcontrol, 'mb-3');
    $departmentedit = (($rolegrants['battle_plan']['edit'] ?? '') === 'department');
    $editcontrol = $formstart('d-inline-block') . $hidden . $actionfield('grant') .
        html_writer::empty_tag('input', ['type'=>'hidden','name'=>'roleid','value'=>$roleid]) .
        html_writer::empty_tag('input', ['type'=>'hidden','name'=>'objecttype','value'=>'battle_plan']) .
        html_writer::empty_tag('input', ['type'=>'hidden','name'=>'actionname','value'=>'edit']) .
        html_writer::empty_tag('input', ['type'=>'hidden','name'=>'scope','value'=>'department']) .
        html_writer::empty_tag('input', ['type'=>'hidden','name'=>'enabled','value'=>$departmentedit ? 0 : 1]) .
        html_writer::tag('button', get_string('bmsacl_departmentedit','local_kbacompany') . ': ' .
            ($departmentedit ? get_string('yes') : get_string('no')),
            ['type'=>'submit','class'=>'btn btn-sm ' . ($departmentedit ? 'btn-success' : 'btn-outline-secondary')]) .
        html_writer::end_tag('form');
    echo html_writer::div($editcontrol, 'mb-3');
    $approveenabled = (($rolegrants['battle_plan']['approve'] ?? '') === 'department');
    $approvecontrol = $formstart('d-inline-block') . $hidden . $actionfield('grant') .
        html_writer::empty_tag('input', ['type'=>'hidden','name'=>'roleid','value'=>$roleid]) .
        html_writer::empty_tag('input', ['type'=>'hidden','name'=>'objecttype','value'=>'battle_plan']) .
        html_writer::empty_tag('input', ['type'=>'hidden','name'=>'actionname','value'=>'approve']) .
        html_writer::empty_tag('input', ['type'=>'hidden','name'=>'scope','value'=>'department']) .
        html_writer::empty_tag('input', ['type'=>'hidden','name'=>'enabled','value'=>$approveenabled ? 0 : 1]) .
        html_writer::tag('button', get_string('bmsacl_departmentapprove','local_kbacompany') . ': ' .
            ($approveenabled ? get_string('yes') : get_string('no')),
            ['type'=>'submit','class'=>'btn btn-sm ' . ($approveenabled ? 'btn-success' : 'btn-outline-secondary')]) .
        html_writer::end_tag('form');
    echo html_writer::div($approvecontrol, 'mb-3');
    echo html_writer::table($table);
    echo html_writer::div(get_string('bmsacl_coming','local_kbacompany'), 'alert alert-secondary');

    $members = roles::member_options($uuid, $actor);
    echo html_writer::tag('h4', get_string('bmsacl_members','local_kbacompany'), ['class'=>'h5']);
    if ($members) {
        echo $formstart('form-inline mb-3') . $hidden . $actionfield('assign') .
            html_writer::empty_tag('input', ['type'=>'hidden', 'name'=>'roleid', 'value'=>$roleid]);
        $options = '';
        foreach ($members as $userid=>$label) {
            $options .= html_writer::tag('option', s($label), ['value'=>$userid]);
        }
        echo html_writer::tag('label', get_string('bmsacl_grantee','local_kbacompany'),
            ['for'=>'bmsacl-user', 'class'=>'mr-2']) . html_writer::tag('select', $options,
            ['name'=>'userid', 'id'=>'bmsacl-user', 'class'=>'form-control mr-2']) .
            html_writer::tag('button', get_string('bmsacl_assign','local_kbacompany'),
                ['type'=>'submit','class'=>'btn btn-outline-primary']) . html_writer::end_tag('form');
    }
    $assignments = roles::assigned_members($uuid, $actor);
    foreach ($assignments as $assignment) {
        if ((int)$assignment->roleid !== $roleid) { continue; }
        $userid = (int)$assignment->userid;
        $label = $members[$userid] ?? ('Moodle ID ' . $userid);
        $remove = $formstart('d-inline ml-2') . $hidden . $actionfield('revoke') .
            html_writer::empty_tag('input', ['type'=>'hidden','name'=>'roleid','value'=>$roleid]) .
            html_writer::empty_tag('input', ['type'=>'hidden','name'=>'userid','value'=>$userid]) .
            html_writer::tag('button', get_string('bmsacl_revoke','local_kbacompany'),
                ['type'=>'submit','class'=>'btn btn-sm btn-outline-danger']) . html_writer::end_tag('form');
        echo html_writer::div(s($label) . $remove, 'border-bottom py-2');
    }
    echo html_writer::end_div() . html_writer::end_div();
}
bms_page_shell_service::finish($shell);
