<?php
require_once(__DIR__ . '/../../config.php');

use local_kbacompany\local\resource_gate_service;

require_login();
$context = context_system::instance();
require_capability('local/kbacompany:viewowncompany', $context);
$uuid = required_param('uuid', PARAM_RAW_TRIMMED);
$data = resource_gate_service::context($uuid, (int)$USER->id);
$PAGE->set_context($context);
$PAGE->set_url(new moodle_url('/local/kbacompany/resource_gate.php', ['uuid' => $uuid]));
$PAGE->set_title(get_string('resourcegate_title', 'local_kbacompany'));
$PAGE->set_heading(get_string('pluginname', 'local_kbacompany'));

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_sesskey();
    $action = required_param('action', PARAM_ALPHANUMEXT);
    $item = optional_param('item', '', PARAM_RAW_TRIMMED);
    $revision = optional_param('revision', 0, PARAM_INT);
    if ($action === 'savegate') {
        $count = min(30, max(1, optional_param('itemcount', 7, PARAM_INT)));
        $items = [];
        for ($i = 1; $i <= $count; $i++) {
            $code = optional_param('itemcode_' . $i, '', PARAM_RAW_TRIMMED);
            $name = optional_param('itemname_' . $i, '', PARAM_RAW_TRIMMED);
            if ($code === '' && $name === '') { continue; }
            $ref = optional_param('reference_' . $i, 'none::0', PARAM_RAW_TRIMMED);
            $parts = explode(':', $ref, 3);
            if (count($parts) !== 3) { $parts = ['none', '', '0']; }
            $items[] = [
                'itemcode' => $code,
                'resourcekind' => optional_param('resourcekind_' . $i, 'money', PARAM_ALPHA),
                'name' => $name,
                'required' => optional_param('required_' . $i, 0, PARAM_BOOL),
                'status' => optional_param('status_' . $i, 'pending', PARAM_ALPHA),
                'referencekind' => $parts[0],
                'referenceuuid' => $parts[1],
                'referencerevision' => (int)$parts[2],
                'expected' => optional_param('expected_' . $i, '', PARAM_RAW_TRIMMED),
                'available' => optional_param('available_' . $i, '', PARAM_RAW_TRIMMED),
                'gap' => optional_param('gap_' . $i, '', PARAM_RAW_TRIMMED),
                'evidence' => optional_param('evidence_' . $i, '', PARAM_RAW_TRIMMED),
                'evidenceurl' => optional_param('evidenceurl_' . $i, '', PARAM_RAW_TRIMMED),
                'ownerpositionuuid' => optional_param('owner_' . $i, '', PARAM_RAW_TRIMMED),
                'duedate' => optional_param('duedate_' . $i, '', PARAM_RAW_TRIMMED),
            ];
        }
        $targetcompound = required_param('targetcompound', PARAM_RAW_TRIMMED);
        $targetparts = explode(':', $targetcompound, 2);
        if (count($targetparts) !== 2) { throw new moodle_exception('resourcegate_reference', 'local_kbacompany'); }
        resource_gate_service::save_gate($uuid, (int)$USER->id, [
            'code' => required_param('code', PARAM_RAW_TRIMMED),
            'name' => required_param('name', PARAM_RAW_TRIMMED),
            'targettype' => $targetparts[0],
            'targetuuid' => $targetparts[1],
            'scenariouuid' => optional_param('scenariouuid', '', PARAM_RAW_TRIMMED),
            'plannedstart' => optional_param('plannedstart', '', PARAM_RAW_TRIMMED),
            'ownerpositionuuid' => optional_param('ownerpositionuuid', '', PARAM_RAW_TRIMMED),
        ], $items, $item, $revision);
    } else if ($action === 'submitgate') {
        resource_gate_service::submit_gate($uuid, (int)$USER->id, $item, $revision);
    } else if ($action === 'readygate') {
        resource_gate_service::mark_ready($uuid, (int)$USER->id, $item, $revision,
            optional_param('decisionnote', '', PARAM_RAW_TRIMMED));
    } else if ($action === 'blockgate') {
        resource_gate_service::mark_blocked($uuid, (int)$USER->id, $item, $revision,
            required_param('decisionnote', PARAM_RAW_TRIMMED));
    } else if ($action === 'archivegate') {
        resource_gate_service::archive_gate($uuid, (int)$USER->id, $item, $revision);
    } else {
        throw new moodle_exception('invalidparameter');
    }
    redirect(new moodle_url('/local/kbacompany/resource_gate.php', ['uuid' => $uuid]),
        get_string('resourcegate_saved', 'local_kbacompany'));
}

$edituuid = optional_param('edituuid', '', PARAM_RAW_TRIMMED);
$viewuuid = optional_param('viewuuid', '', PARAM_RAW_TRIMMED);
$viewrevision = optional_param('viewrevision', 0, PARAM_INT);
$editing = $edituuid !== '' ? resource_gate_service::gate($uuid, (int)$USER->id, $edituuid) : null;
$viewing = $viewuuid !== '' ? resource_gate_service::gate($uuid, (int)$USER->id, $viewuuid, $viewrevision) : null;

$targetopts = [];
foreach ($data['target_types'] as $type) {
    foreach ($data['targets'][$type] as $r) {
        $targetopts[$type . ':' . $r['uuid']] = get_string('resourcegate_target_' . $type, 'local_kbacompany') .
            ': ' . $r['code'] . ' — ' . $r['name'] . ' · v' . $r['revision'];
    }
}
$scenarioopts = ['' => get_string('resourcegate_scenario_none', 'local_kbacompany')];
foreach ($data['scenarios'] as $r) { $scenarioopts[$r['uuid']] = $r['code'] . ' — ' . $r['name'] . ' · v' . $r['revision']; }
$positionopts = ['' => '—'];
foreach ($data['positions'] as $r) { $positionopts[$r['uuid']] = $r['code'] . ' — ' . $r['name']; }
$statusopts = [];
foreach ($data['item_states'] as $state) { $statusopts[$state] = get_string('resourcegate_itemstatus_' . $state, 'local_kbacompany'); }
$kindopts = [];
foreach ($data['resource_kinds'] as $kind) { $kindopts[$kind] = get_string('resourcegate_kind_' . $kind, 'local_kbacompany'); }
$referenceopts = [
    'none::0' => get_string('resourcegate_reference_none', 'local_kbacompany'),
    'custom::0' => get_string('resourcegate_reference_custom', 'local_kbacompany'),
];
foreach ($data['budgets'] as $r) {
    $referenceopts['budget:' . $r['uuid'] . ':' . $r['revision']] = get_string('resourcegate_ref_budget', 'local_kbacompany') .
        ': ' . $r['code'] . ' — ' . $r['name'] . ' · v' . $r['revision'];
}
foreach ($data['positions'] as $r) {
    $referenceopts['position:' . $r['uuid'] . ':0'] = get_string('resourcegate_ref_position', 'local_kbacompany') .
        ': ' . $r['code'] . ' — ' . $r['name'];
}
foreach ($data['business_roles'] as $r) {
    $referenceopts['business_role:' . $r['uuid'] . ':0'] = get_string('resourcegate_ref_business_role', 'local_kbacompany') .
        ': ' . $r['code'] . ' — ' . $r['name'];
}
foreach ($data['documents'] as $r) {
    $referenceopts['document:' . $r['uuid'] . ':' . $r['revision']] = get_string('resourcegate_ref_document', 'local_kbacompany') .
        ': ' . $r['code'] . ' — ' . $r['name'] . ' · v' . $r['revision'];
}

$hidden = static function(string $action, array $row = null) use ($uuid): string {
    $s = html_writer::empty_tag('input', ['type'=>'hidden','name'=>'uuid','value'=>$uuid]);
    $s .= html_writer::empty_tag('input', ['type'=>'hidden','name'=>'sesskey','value'=>sesskey()]);
    $s .= html_writer::empty_tag('input', ['type'=>'hidden','name'=>'action','value'=>$action]);
    if ($row) {
        $s .= html_writer::empty_tag('input', ['type'=>'hidden','name'=>'item','value'=>$row['uuid']]);
        $s .= html_writer::empty_tag('input', ['type'=>'hidden','name'=>'revision','value'=>$row['revision']]);
    }
    return $s;
};
$select = static function(string $name, array $options, string $selected, string $class = 'form-control form-control-sm'): string {
    return html_writer::select($options, $name, $selected, false, ['class' => $class]);
};

$bmsshell = \local_kbacompany\local\bms_page_shell_service::begin($uuid, (int)$USER->id,
    'financialmodel', get_string('resourcegate_title', 'local_kbacompany'));
echo $OUTPUT->heading(get_string('resourcegate_title', 'local_kbacompany') . ': ' . format_string($data['company']['name']), 2);
echo html_writer::tag('p', get_string('resourcegate_intro', 'local_kbacompany'), ['class'=>'lead']);
echo $OUTPUT->notification(get_string('resourcegate_scope_notice', 'local_kbacompany'), 'info');
echo html_writer::link(new moodle_url('/local/kbacompany/financial_model.php', ['uuid'=>$uuid]),
    get_string('resourcegate_back_model', 'local_kbacompany'), ['class'=>'btn btn-outline-secondary mr-2 mb-3']);
echo html_writer::link(new moodle_url('/local/kbacompany/budgeting.php', ['uuid'=>$uuid]),
    get_string('budgeting_title', 'local_kbacompany'), ['class'=>'btn btn-outline-secondary mb-3']);

echo $OUTPUT->heading(get_string('resourcegate_registry', 'local_kbacompany'), 3);
if ($data['gates']) {
    $t = new html_table();
    $t->head = [get_string('resourcegate_code','local_kbacompany'), get_string('resourcegate_name','local_kbacompany'),
        get_string('resourcegate_target','local_kbacompany'), get_string('resourcegate_start','local_kbacompany'),
        get_string('resourcegate_status','local_kbacompany'), get_string('resourcegate_readiness','local_kbacompany'), get_string('actions')];
    foreach ($data['gates'] as $r) {
        $summary = $r['coverage'];
        $readiness = get_string('resourcegate_readiness_summary', 'local_kbacompany', (object)[
            'ready'=>$summary['required_ready'], 'pending'=>$summary['required_pending'], 'blocked'=>$summary['required_blocked']]);
        $detailrev = $r['effectivereadyrevision'] ?: $r['revision'];
        $actions = html_writer::link(new moodle_url('/local/kbacompany/resource_gate.php',
            ['uuid'=>$uuid,'viewuuid'=>$r['uuid'],'viewrevision'=>$detailrev]), get_string('view'),
            ['class'=>'btn btn-sm btn-outline-primary mr-1']);
        if (!in_array($r['status'], ['submitted','archived'], true)) {
            $actions .= html_writer::link(new moodle_url('/local/kbacompany/resource_gate.php',
                ['uuid'=>$uuid,'edituuid'=>$r['uuid']]), get_string('edit'), ['class'=>'btn btn-sm btn-outline-secondary mr-1']);
        }
        $make = static function(string $action, string $label, string $class, bool $note = false) use ($hidden, $r): string {
            $x = html_writer::start_tag('form',['method'=>'post','class'=>'d-inline mr-1']) . $hidden($action,$r);
            if ($note) { $x .= html_writer::empty_tag('input',['type'=>'text','name'=>'decisionnote',
                'placeholder'=>get_string('resourcegate_decisionnote','local_kbacompany'),
                'class'=>'form-control form-control-sm d-inline-block mr-1','style'=>'width:180px']); }
            $x .= html_writer::tag('button',$label,['type'=>'submit','class'=>'btn btn-sm '.$class]) . html_writer::end_tag('form');
            return $x;
        };
        if ($r['status'] === 'draft') { $actions .= $make('submitgate',get_string('resourcegate_submit','local_kbacompany'),'btn-outline-primary'); }
        if ($r['status'] === 'submitted') {
            $actions .= $make('readygate',get_string('resourcegate_ready','local_kbacompany'),'btn-outline-success',true);
            $actions .= $make('blockgate',get_string('resourcegate_block','local_kbacompany'),'btn-outline-danger',true);
        }
        if ($r['status'] !== 'submitted') { $actions .= $make('archivegate',get_string('resourcegate_archive','local_kbacompany'),'btn-outline-danger'); }
        $t->data[] = [s($r['code']), s($r['name']), s(get_string('resourcegate_target_' . $r['targettype'],'local_kbacompany')),
            s($r['plannedstart']), s(get_string('resourcegate_state_' . $r['status'],'local_kbacompany')), s($readiness), $actions];
    }
    echo html_writer::table($t);
} else {
    echo $OUTPUT->notification(get_string('resourcegate_empty','local_kbacompany'), 'info');
}

if ($viewing) {
    echo $OUTPUT->heading(get_string('resourcegate_view', 'local_kbacompany') . ': ' . s($viewing['gate']['name']), 3);
    $t = new html_table();
    $t->head = [get_string('resourcegate_itemcode','local_kbacompany'), get_string('resourcegate_kind','local_kbacompany'),
        get_string('resourcegate_itemname','local_kbacompany'), get_string('resourcegate_itemstatus','local_kbacompany'),
        get_string('resourcegate_expected','local_kbacompany'), get_string('resourcegate_available','local_kbacompany'),
        get_string('resourcegate_gap','local_kbacompany'), get_string('resourcegate_evidence','local_kbacompany')];
    foreach ($viewing['items'] as $r) {
        $t->data[] = [s($r['itemcode']), s(get_string('resourcegate_kind_'.$r['resourcekind'],'local_kbacompany')), s($r['name']),
            s(get_string('resourcegate_itemstatus_'.$r['status'],'local_kbacompany')), format_text($r['expected'], FORMAT_PLAIN),
            format_text($r['available'], FORMAT_PLAIN), format_text($r['gap'], FORMAT_PLAIN), format_text($r['evidence'], FORMAT_PLAIN)];
    }
    echo html_writer::table($t);
    if ($viewing['gate']['decisionnote'] !== '') {
        echo $OUTPUT->notification(s($viewing['gate']['decisionnote']), 'info');
    }
}

$defaults = [];
if ($editing) {
    $formgate = $editing['gate'];
    $defaults = $editing['items'];
} else {
    $formgate = ['uuid'=>'','revision'=>0,'code'=>'','name'=>'','targettype'=>'goal','targetuuid'=>'',
        'scenariouuid'=>'','plannedstart'=>'','ownerpositionuuid'=>''];
    $n = 1;
    foreach ($data['resource_kinds'] as $kind) {
        $defaults[] = ['itemcode'=>'RG-'.strtoupper(substr($kind,0,4)),'resourcekind'=>$kind,
            'name'=>get_string('resourcegate_kind_'.$kind,'local_kbacompany'),'required'=>1,'status'=>'pending',
            'referencekind'=>'none','referenceuuid'=>'','referencerevision'=>0,'expected'=>'','available'=>'','gap'=>'',
            'evidence'=>'','evidenceurl'=>'','ownerpositionuuid'=>'','duedate'=>''];
        $n++;
    }
}
while (count($defaults) < 14) {
    $defaults[] = ['itemcode'=>'','resourcekind'=>'money','name'=>'','required'=>0,'status'=>'pending',
        'referencekind'=>'none','referenceuuid'=>'','referencerevision'=>0,'expected'=>'','available'=>'','gap'=>'',
        'evidence'=>'','evidenceurl'=>'','ownerpositionuuid'=>'','duedate'=>''];
}

echo $OUTPUT->heading($editing ? get_string('resourcegate_edit','local_kbacompany') : get_string('resourcegate_create','local_kbacompany'), 3);
echo html_writer::start_tag('form',['method'=>'post']);
echo $hidden('savegate', $formgate['uuid'] !== '' ? $formgate : null);
echo html_writer::empty_tag('input',['type'=>'hidden','name'=>'itemcount','value'=>count($defaults)]);
echo html_writer::start_tag('div',['class'=>'row']);
$fields = [
    ['code',get_string('resourcegate_code','local_kbacompany'),'text',$formgate['code']],
    ['name',get_string('resourcegate_name','local_kbacompany'),'text',$formgate['name']],
    ['plannedstart',get_string('resourcegate_start','local_kbacompany'),'date',$formgate['plannedstart']],
];
foreach ($fields as $f) {
    echo html_writer::start_tag('div',['class'=>'col-md-4 form-group']); echo html_writer::tag('label',$f[1]);
    echo html_writer::empty_tag('input',['type'=>$f[2],'name'=>$f[0],'value'=>$f[3],'class'=>'form-control']); echo html_writer::end_tag('div');
}
echo html_writer::end_tag('div');
echo html_writer::start_tag('div',['class'=>'row']);
$targetselected = $formgate['targetuuid'] !== '' ? $formgate['targettype'].':'.$formgate['targetuuid'] : '';
echo html_writer::start_tag('div',['class'=>'col-md-4 form-group']).html_writer::tag('label',get_string('resourcegate_target','local_kbacompany')).
    $select('targetcompound',$targetopts,$targetselected,'form-control').html_writer::end_tag('div');
echo html_writer::start_tag('div',['class'=>'col-md-4 form-group']).html_writer::tag('label',get_string('resourcegate_scenario','local_kbacompany')).
    $select('scenariouuid',$scenarioopts,$formgate['scenariouuid'],'form-control').html_writer::end_tag('div');
echo html_writer::start_tag('div',['class'=>'col-md-4 form-group']).html_writer::tag('label',get_string('resourcegate_owner','local_kbacompany')).
    $select('ownerpositionuuid',$positionopts,$formgate['ownerpositionuuid'],'form-control').html_writer::end_tag('div');
echo html_writer::end_tag('div');

echo html_writer::tag('p',get_string('resourcegate_items_help','local_kbacompany'),['class'=>'text-muted']);
$t = new html_table();
$t->head = [get_string('resourcegate_itemcode','local_kbacompany'), get_string('resourcegate_kind','local_kbacompany'),
    get_string('resourcegate_itemname','local_kbacompany'), get_string('resourcegate_required','local_kbacompany'),
    get_string('resourcegate_itemstatus','local_kbacompany'), get_string('resourcegate_reference_label','local_kbacompany'),
    get_string('resourcegate_expected','local_kbacompany'), get_string('resourcegate_available','local_kbacompany'),
    get_string('resourcegate_gap','local_kbacompany'), get_string('resourcegate_evidence','local_kbacompany'),
    get_string('resourcegate_owner','local_kbacompany'), get_string('resourcegate_due','local_kbacompany')];
$i=0;
foreach ($defaults as $r) {
    $i++;
    $refselected = ($r['referencekind'] ?: 'none').':'.($r['referenceuuid'] ?: '').':'.((int)$r['referencerevision']);
    $req = html_writer::empty_tag('input',['type'=>'checkbox','name'=>'required_'.$i,'value'=>'1'] + (!empty($r['required']) ? ['checked'=>'checked'] : []));
    $t->data[] = [
        html_writer::empty_tag('input',['type'=>'text','name'=>'itemcode_'.$i,'value'=>$r['itemcode'],'class'=>'form-control form-control-sm','style'=>'min-width:100px']),
        $select('resourcekind_'.$i,$kindopts,$r['resourcekind']),
        html_writer::empty_tag('input',['type'=>'text','name'=>'itemname_'.$i,'value'=>$r['name'],'class'=>'form-control form-control-sm','style'=>'min-width:140px']),
        $req,
        $select('status_'.$i,$statusopts,$r['status']),
        $select('reference_'.$i,$referenceopts,$refselected,'form-control form-control-sm'),
        html_writer::tag('textarea',s($r['expected']),['name'=>'expected_'.$i,'class'=>'form-control form-control-sm','rows'=>2]),
        html_writer::tag('textarea',s($r['available']),['name'=>'available_'.$i,'class'=>'form-control form-control-sm','rows'=>2]),
        html_writer::tag('textarea',s($r['gap']),['name'=>'gap_'.$i,'class'=>'form-control form-control-sm','rows'=>2]),
        html_writer::tag('textarea',s($r['evidence']),['name'=>'evidence_'.$i,'class'=>'form-control form-control-sm','rows'=>2]).
            html_writer::empty_tag('input',['type'=>'url','name'=>'evidenceurl_'.$i,'value'=>$r['evidenceurl'],'class'=>'form-control form-control-sm mt-1','placeholder'=>'https://']),
        $select('owner_'.$i,$positionopts,$r['ownerpositionuuid']),
        html_writer::empty_tag('input',['type'=>'date','name'=>'duedate_'.$i,'value'=>$r['duedate'],'class'=>'form-control form-control-sm']),
    ];
}
echo html_writer::table($t);
echo html_writer::tag('button',get_string('savechanges'),['type'=>'submit','class'=>'btn btn-primary mb-4']);
echo html_writer::end_tag('form');

\local_kbacompany\local\bms_page_shell_service::finish($bmsshell);
