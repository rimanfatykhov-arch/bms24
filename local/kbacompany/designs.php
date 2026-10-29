<?php
require_once(__DIR__ . '/../../config.php');
require_once(__DIR__ . '/lib.php');
use local_kbacompany\local\design_service;
require_login();
$uuid = required_param('uuid', PARAM_RAW_TRIMMED);
$object = optional_param('object', '', PARAM_RAW_TRIMMED);
$version = optional_param('version', 0, PARAM_INT);
$comparefrom = optional_param('comparefrom', 0, PARAM_INT);
$compareto = optional_param('compareto', 0, PARAM_INT);
$data = design_service::context($uuid, (int)$USER->id, $object, $version);
$action = optional_param('action', '', PARAM_ALPHA);
$error = '';
if ($action !== '') {
    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') { throw new moodle_exception('productpostrequired', 'local_kbacompany'); }
    require_sesskey();
    $input = ['object' => $object];
    foreach (['source', 'sourceuuid', 'sourcerevision', 'revision', 'name', 'mission', 'intent',
            'showmission', 'showintent', 'node', 'reason', 'code', 'type', 'layout_parent',
            'reporting_parent', 'sortorder', 'color', 'item', 'targetnode', 'result', 'position',
            'plannedfte', 'managerial', 'branch', 'visualtier', 'product', 'productuuid', 'companyproduct', 'companyproductuuid'] as $key) {
        $input[$key] = optional_param($key, '', PARAM_RAW_TRIMMED);
    }
    try {
        $result = design_service::command($uuid, (int)$USER->id, $action, $input);
        redirect(new moodle_url('/local/kbacompany/designs.php', ['uuid' => $uuid, 'object' => $result]), get_string('productsaved', 'local_kbacompany'));
    } catch (moodle_exception $e) {
        if ($e->module !== 'local_kbacompany') { throw $e; }
        $error = $e->getMessage();
    }
}
$comparison = null;
if ($object !== '' && $comparefrom > 0 && $compareto > 0) {
    $comparison = design_service::compare_versions($uuid, (int)$USER->id, $object, $comparefrom, $compareto);
}
$str = static function(string $key): string { return get_string('design_' . $key, 'local_kbacompany'); };
$PAGE->set_context(context_system::instance());
$PAGE->set_url(new moodle_url('/local/kbacompany/designs.php', ['uuid' => $uuid, 'object' => $object, 'version' => $version]));
// BMS page shell adapter selects the presentation layout.
$PAGE->set_title($str('title'));
$PAGE->set_heading(get_string('pluginname', 'local_kbacompany'));
$bmsshell = \local_kbacompany\local\bms_page_shell_service::begin($uuid, (int)$USER->id, 'organization', $str('title'));
echo html_writer::link(new moodle_url('/local/kbacompany/bms_workspace.php', ['uuid' => $uuid]), get_string('backtocompany', 'local_kbacompany'), ['class' => 'btn btn-outline-primary']);
echo local_kbacompany_organization_tabs($uuid, 'projects', true);
echo html_writer::div(html_writer::link(new moodle_url('/local/kbacompany/ofs.php', ['uuid' => $uuid, 'mode' => 'design', 'object' => $object]),
    get_string('ofsdesigner_open', 'local_kbacompany'), ['class' => 'btn btn-primary']), 'mb-3');
echo $OUTPUT->heading($str('title') . ': ' . s($data['company']['name']));
echo html_writer::tag('p', $str('intro'));
if ($error !== '') { echo $OUTPUT->notification(s($error), 'error'); }
$start = static function(string $action, array $extra = []) use ($uuid, $object, $data): void {
    echo html_writer::start_tag('form', ['method' => 'post', 'class' => 'kbacompany-form mb-3']);
    foreach (array_merge(['uuid' => $uuid, 'object' => $object, 'action' => $action,
            'revision' => $data['selected']->revision ?? 0, 'sesskey' => sesskey()], $extra) as $key => $value) {
        echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => $key, 'value' => $value]);
    }
};
$field = static function(string $id, string $name, string $label, string $value = '', bool $area = false): void {
    echo html_writer::label($label, $id);
    if ($area) { echo html_writer::tag('textarea', s($value), ['id' => $id, 'name' => $name, 'rows' => 3, 'maxlength' => 4000]); }
    else { echo html_writer::empty_tag('input', ['id' => $id, 'name' => $name, 'value' => $value, 'type' => 'text', 'required' => 'required', 'maxlength' => 255]); }
};
$end = static function(string $label): void {
    echo html_writer::tag('button', $label, ['type' => 'submit', 'class' => 'btn btn-primary']);
    echo html_writer::end_tag('form');
};
echo $OUTPUT->heading($str('catalog'), 3);
$start('create');
$field('new_name', 'name', $str('name'));
echo html_writer::label($str('source'), 'design_source');
$sources = ['bms_ofs' => get_string('ofsdesigner_bmstemplate', 'local_kbacompany'),
    'seven' => $str('seven'), 'directors' => $str('directors'), 'live' => $str('live')];
if ($data['templates']) { $sources['custom'] = $str('custom'); }
echo html_writer::select($sources, 'source', 'bms_ofs', false, ['id' => 'design_source']);
if ($data['templates']) {
    $templates = [];
    foreach ($data['templates'] as $template) { $templates[$template->uuid] = $template->name; }
    echo html_writer::label($str('customsource'), 'custom_source');
    echo html_writer::select($templates, 'sourceuuid', '', false, ['id' => 'custom_source']);
}
$end($str('create'));
$t = new html_table();
$t->head = [$str('name'), $str('state'), $str('version')];
foreach ($data['projects'] as $p) {
    $t->data[] = [html_writer::link(new moodle_url('/local/kbacompany/designs.php', ['uuid' => $uuid, 'object' => $p->uuid]), s($p->name)), $str('status_' . $p->status), (int)$p->revision];
}
echo html_writer::table($t);
if ($data['selected']) {
    $p = $data['selected']; $v = $data['version']; $payload = $data['payload'];
    $currentview = (int)$p->revision === (int)$v->revision;
    $editable = $p->status === 'draft' && $currentview;
    echo $OUTPUT->heading(s($payload['name']) . ' · ' . $str('version') . ' ' . (int)$v->revision, 3);
    echo html_writer::start_div('kbacompany-design-header');
    echo html_writer::tag('h3', s($payload['name']));
    if ($payload['showmission'] && $payload['mission'] !== '') {
        echo html_writer::tag('p', html_writer::tag('strong', $str('mission') . ': ') . s($payload['mission']));
    }
    if ($payload['showintent'] && $payload['intent'] !== '') {
        echo html_writer::tag('p', html_writer::tag('strong', $str('intent') . ': ') . s($payload['intent']));
    }
    echo html_writer::end_div();
    echo $OUTPUT->heading($str('readiness'), 3);
    if (!$data['issues']) {
        echo $OUTPUT->notification($str('ready'), 'success');
    } else {
        echo $OUTPUT->notification(get_string('design_issuecount', 'local_kbacompany', count($data['issues'])), 'warning');
        echo html_writer::start_tag('ul', ['class' => 'kbacompany-design-issues']);
        foreach ($data['issues'] as $issue) {
            echo html_writer::tag('li', get_string('design_' . $issue['key'], 'local_kbacompany', s($issue['label'])));
        }
        echo html_writer::end_tag('ul');
    }
    if ($editable) {
        $start('save');
        $field('edit_name', 'name', $str('name'), $p->name);
        $field('mission', 'mission', $str('mission'), $payload['mission'], true);
        $field('intent', 'intent', $str('intent'), $payload['intent'], true);
        $missionattrs = ['type' => 'checkbox', 'name' => 'showmission', 'value' => 1];
        if ($payload['showmission']) { $missionattrs['checked'] = 'checked'; }
        $intentattrs = ['type' => 'checkbox', 'name' => 'showintent', 'value' => 1];
        if ($payload['showintent']) { $intentattrs['checked'] = 'checked'; }
        echo html_writer::tag('label', html_writer::empty_tag('input', $missionattrs) . ' ' . $str('showmission'));
        echo html_writer::tag('label', html_writer::empty_tag('input', $intentattrs) . ' ' . $str('showintent'));
        $end($str('save'));
    }
    $names = [];
    foreach ($payload['nodes'] as $n) { $names[$n['uuid']] = $n['code'] . ' — ' . $n['name']; }
    $nodes = $payload['nodes'];
    usort($nodes, static function(array $a, array $b): int {
        return [$a['sortorder'], $a['code']] <=> [$b['sortorder'], $b['code']];
    });
    $nodemap = [];
    foreach ($nodes as $n) { $nodemap[$n['uuid']] = $n; }
    $levels = [];
    foreach ($nodes as $n) {
        $level = 0; $parent = $n['layout_parent']; $guard = [];
        while ($parent !== '' && isset($nodemap[$parent]) && !isset($guard[$parent])) {
            $guard[$parent] = true; $level++; $parent = $nodemap[$parent]['layout_parent'];
        }
        $levels[$level][] = $n;
    }
    echo $OUTPUT->heading($str('visual'), 3);
    echo html_writer::start_div('kbacompany-design-board');
    foreach ($levels as $level => $items) {
        echo html_writer::start_div('kbacompany-design-level');
        echo html_writer::span($str('level') . ' ' . ($level + 1), 'kbacompany-design-level-label');
        foreach ($items as $n) {
            echo html_writer::start_tag('article', ['class' => 'kbacompany-design-node',
                'style' => '--kba-design-color:' . s($n['color'])]);
            echo html_writer::tag('small', s($n['code']) . ' · ' . $str('type_' . $n['type']));
            echo html_writer::tag('h4', s($n['name']));
            echo html_writer::tag('p', $str('layout') . ': ' . s($names[$n['layout_parent']] ?? '—'));
            echo html_writer::tag('p', $str('reporting') . ': ' . s($names[$n['reporting_parent']] ?? '—'));
            if ($editable) {
                echo html_writer::start_tag('details');
                echo html_writer::tag('summary', $str('editnode'));
                $start('node', ['node' => $n['uuid']]);
                $field('code_' . $n['uuid'], 'code', $str('code'), $n['code']);
                $field('name_' . $n['uuid'], 'name', $str('nodename'), $n['name']);
                echo html_writer::label($str('type'), 'type_' . $n['uuid']);
                echo html_writer::select(['leadership' => $str('type_leadership'), 'division' => $str('type_division'),
                    'department' => $str('type_department'), 'section' => $str('type_section'),
                    'service' => $str('type_service'), 'office' => $str('type_office')], 'type', $n['type'], false,
                    ['id' => 'type_' . $n['uuid']]);
                $parents = $names; unset($parents[$n['uuid']]);
                echo html_writer::label($str('layout'), 'layout_' . $n['uuid']);
                echo html_writer::select($parents, 'layout_parent', $n['layout_parent'], ['' => '—'], ['id' => 'layout_' . $n['uuid']]);
                echo html_writer::label($str('reporting'), 'reporting_' . $n['uuid']);
                echo html_writer::select($parents, 'reporting_parent', $n['reporting_parent'], ['' => '—'], ['id' => 'reporting_' . $n['uuid']]);
                echo html_writer::label($str('sortorder'), 'sort_' . $n['uuid']);
                echo html_writer::empty_tag('input', ['id' => 'sort_' . $n['uuid'], 'name' => 'sortorder',
                    'type' => 'number', 'min' => 0, 'max' => 9999, 'value' => $n['sortorder'], 'required' => 'required']);
                echo html_writer::label($str('color'), 'color_' . $n['uuid']);
                echo html_writer::empty_tag('input', ['id' => 'color_' . $n['uuid'], 'name' => 'color',
                    'type' => 'color', 'value' => $n['color'], 'required' => 'required']);
                $end($str('savenode'));
                echo html_writer::end_tag('details');
            }
            echo html_writer::end_tag('article');
        }
        echo html_writer::end_div();
    }
    echo html_writer::end_div();
    if ($editable) {
        echo $OUTPUT->heading($str('insert'), 3);
        echo html_writer::tag('p', $str('inserthelp'));
        $start('insert');
        $field('insert_code', 'code', $str('code'));
        $field('insert_name', 'name', $str('nodename'));
        echo html_writer::label($str('type'), 'insert_type');
        echo html_writer::select(['leadership' => $str('type_leadership'), 'division' => $str('type_division'),
            'department' => $str('type_department'), 'section' => $str('type_section'),
            'service' => $str('type_service'), 'office' => $str('type_office')], 'type', 'department', false,
            ['id' => 'insert_type']);
        echo html_writer::label($str('layout'), 'insert_layout');
        echo html_writer::select($names, 'layout_parent', '', ['' => '—'], ['id' => 'insert_layout']);
        echo html_writer::label($str('reporting'), 'insert_reporting');
        echo html_writer::select($names, 'reporting_parent', '', ['' => '—'], ['id' => 'insert_reporting']);
        echo html_writer::label($str('sortorder'), 'insert_sort');
        echo html_writer::empty_tag('input', ['id' => 'insert_sort', 'name' => 'sortorder', 'type' => 'number',
            'min' => 0, 'max' => 9999, 'value' => 100, 'required' => 'required']);
        echo html_writer::label($str('color'), 'insert_color');
        echo html_writer::empty_tag('input', ['id' => 'insert_color', 'name' => 'color', 'type' => 'color',
            'value' => '#00843D', 'required' => 'required']);
        $end($str('insertnode'));
    }
    $positions = [];
    foreach ($payload['positions'] as $position) {
        $positions[$position['uuid']] = $position['code'] . ' — ' . $position['name'];
    }
    echo $OUTPUT->heading($str('positions'), 3);
    $pt = new html_table();
    $pt->head = [$str('node'), $str('code'), $str('positionname'), $str('plannedfte'), $str('managerial')];
    foreach ($payload['positions'] as $position) {
        $name = s($position['name']);
        if ($editable) {
            $name .= html_writer::start_tag('details') . html_writer::tag('summary', $str('edit'));
            ob_start();
            $start('position', ['item' => $position['uuid']]);
            $field('pcode_' . $position['uuid'], 'code', $str('code'), $position['code']);
            $field('pname_' . $position['uuid'], 'name', $str('positionname'), $position['name']);
            echo html_writer::label($str('node'), 'pnode_' . $position['uuid']);
            echo html_writer::select($names, 'targetnode', $position['nodeuuid'], false, ['id' => 'pnode_' . $position['uuid']]);
            echo html_writer::label($str('plannedfte'), 'pfte_' . $position['uuid']);
            echo html_writer::empty_tag('input', ['id' => 'pfte_' . $position['uuid'], 'name' => 'plannedfte',
                'type' => 'number', 'min' => '.01', 'max' => '999.99', 'step' => '.01', 'required' => 'required',
                'value' => $position['plannedfte']]);
            $managerattrs = ['type' => 'checkbox', 'name' => 'managerial', 'value' => 1];
            if ($position['managerial']) { $managerattrs['checked'] = 'checked'; }
            echo html_writer::tag('label', html_writer::empty_tag('input', $managerattrs) . ' ' . $str('managerial'));
            $end($str('saveposition'));
            $name .= ob_get_clean() . html_writer::end_tag('details');
        }
        $pt->data[] = [s($names[$position['nodeuuid']] ?? '—'), s($position['code']), $name,
            s($position['plannedfte']), $position['managerial'] ? $str('yes') : $str('no')];
    }
    echo html_writer::table($pt);
    if ($editable) {
        $start('position');
        $field('position_code', 'code', $str('code'));
        $field('position_name', 'name', $str('positionname'));
        echo html_writer::label($str('node'), 'position_node');
        echo html_writer::select($names, 'targetnode', '', false, ['id' => 'position_node']);
        echo html_writer::label($str('plannedfte'), 'position_fte');
        echo html_writer::empty_tag('input', ['id' => 'position_fte', 'name' => 'plannedfte', 'type' => 'number',
            'min' => '.01', 'max' => '999.99', 'step' => '.01', 'required' => 'required', 'value' => '1']);
        echo html_writer::tag('label', html_writer::empty_tag('input', ['type' => 'checkbox', 'name' => 'managerial',
            'value' => 1]) . ' ' . $str('managerial'));
        $end($str('addposition'));
    }
    echo $OUTPUT->heading($str('functions'), 3);
    echo html_writer::tag('p', $str('functionhelp'));
    $ft = new html_table();
    $ft->head = [$str('node'), $str('code'), $str('functionname'), $str('result'), $str('responsibleposition')];
    foreach ($payload['functions'] as $function) {
        $name = s($function['name']);
        if ($editable) {
            $name .= html_writer::start_tag('details') . html_writer::tag('summary', $str('edit'));
            ob_start();
            $start('function', ['item' => $function['uuid']]);
            $field('fcode_' . $function['uuid'], 'code', $str('code'), $function['code']);
            $field('fname_' . $function['uuid'], 'name', $str('functionname'), $function['name']);
            $field('fresult_' . $function['uuid'], 'result', $str('result'), $function['result'], true);
            echo html_writer::label($str('node'), 'fnode_' . $function['uuid']);
            echo html_writer::select($names, 'targetnode', $function['nodeuuid'], false, ['id' => 'fnode_' . $function['uuid']]);
            echo html_writer::label($str('responsibleposition'), 'fposition_' . $function['uuid']);
            echo html_writer::select($positions, 'position', $function['positionuuid'], ['' => '—'],
                ['id' => 'fposition_' . $function['uuid']]);
            $end($str('savefunction'));
            $name .= ob_get_clean() . html_writer::end_tag('details');
        }
        $ft->data[] = [s($names[$function['nodeuuid']] ?? '—'), s($function['code']), $name,
            s($function['result']), s($positions[$function['positionuuid']] ?? '—')];
    }
    echo html_writer::table($ft);
    if ($editable) {
        $start('function');
        $field('function_code', 'code', $str('code'));
        $field('function_name', 'name', $str('functionname'));
        $field('function_result', 'result', $str('result'), '', true);
        echo html_writer::label($str('node'), 'function_node');
        echo html_writer::select($names, 'targetnode', '', false, ['id' => 'function_node']);
        echo html_writer::label($str('responsibleposition'), 'function_position');
        echo html_writer::select($positions, 'position', '', ['' => '—'], ['id' => 'function_position']);
        $end($str('addfunction'));
    }
    echo $OUTPUT->heading($str('history'), 3);
    foreach ($data['versions'] as $r) {
        echo html_writer::div(html_writer::link(new moodle_url('/local/kbacompany/designs.php',
            ['uuid' => $uuid, 'object' => $object, 'version' => $r->revision]),
            $str('version') . ' ' . (int)$r->revision . ' · ' . s(userdate($r->timecreated))));
    }
    if (count($data['versions']) > 1) {
        $revisionoptions = [];
        foreach ($data['versions'] as $r) { $revisionoptions[$r->revision] = $str('version') . ' ' . (int)$r->revision; }
        echo html_writer::start_tag('form', ['method' => 'get', 'class' => 'kbacompany-form mb-3']);
        echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'uuid', 'value' => $uuid]);
        echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'object', 'value' => $object]);
        echo html_writer::label($str('comparefrom'), 'compare_from');
        echo html_writer::select($revisionoptions, 'comparefrom', $comparefrom ?: min(array_keys($revisionoptions)), false,
            ['id' => 'compare_from']);
        echo html_writer::label($str('compareto'), 'compare_to');
        echo html_writer::select($revisionoptions, 'compareto', $compareto ?: max(array_keys($revisionoptions)), false,
            ['id' => 'compare_to']);
        echo html_writer::tag('button', $str('compare'), ['type' => 'submit', 'class' => 'btn btn-secondary']);
        echo html_writer::end_tag('form');
    }
    if ($comparison) {
        echo $OUTPUT->heading($str('comparison'), 4);
        $ct = new html_table();
        $ct->head = [$str('compareobject'), $str('code'), $str('difference')];
        foreach (['nodes', 'positions', 'functions'] as $kind) {
            foreach ($comparison[$kind] as $change) {
                $detail = $str('diff_' . $change['status']);
                if ($change['fields']) { $detail .= ': ' . s(implode(', ', $change['fields'])); }
                $ct->data[] = [$str('compare_' . $kind), s($change['code']), $detail];
            }
        }
        if ($comparison['header']) {
            $ct->data[] = [$str('compare_header'), '—',
                $str('diff_changed') . ': ' . s(implode(', ', $comparison['header']))];
        }
        echo $ct->data ? html_writer::table($ct) : $OUTPUT->notification($str('nodifferences'), 'success');
    }
    echo $OUTPUT->heading($str('pdf'), 3);
    echo html_writer::start_tag('form', ['method' => 'get',
        'action' => (new moodle_url('/local/kbacompany/design_pdf.php'))->out(false),
        'class' => 'kbacompany-form mb-3']);
    foreach (['uuid' => $uuid, 'object' => $object, 'version' => $v->revision] as $key => $value) {
        echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => $key, 'value' => $value]);
    }
    echo html_writer::label($str('pdfformat'), 'pdf_format');
    echo html_writer::select(array_combine(['A0', 'A1', 'A2', 'A3', 'A4'], ['A0', 'A1', 'A2', 'A3', 'A4']),
        'format', 'A3', false, ['id' => 'pdf_format']);
    echo html_writer::label($str('pdfmode'), 'pdf_mode');
    echo html_writer::select(['poster' => $str('pdfposter'), 'multipage' => $str('pdfmultipage')],
        'mode', 'poster', false, ['id' => 'pdf_mode']);
    echo html_writer::label($str('pdfpeople'), 'pdf_people');
    echo html_writer::select(['positions' => $str('pdfpositions'), 'fio' => $str('pdffio'),
        'photo' => $str('pdfphoto')], 'people', 'positions', false, ['id' => 'pdf_people']);
    echo html_writer::tag('button', $str('downloadpdf'), ['type' => 'submit', 'class' => 'btn btn-secondary']);
    echo html_writer::end_tag('form');
    $start('create', ['source' => 'version', 'sourceuuid' => $object, 'sourcerevision' => $v->revision]);
    $field('fork_name', 'name', $str('name'));
    $end($str('fork'));
    if ($editable) {
        if (!$data['issues']) {
            $start('submit');
            $field('submit_reason', 'reason', $str('submitreason'), '', true);
            $end($str('submit'));
        }
        $start('maketemplate');
        $field('template_reason', 'reason', $str('templatereason'), '', true);
        $end($str('maketemplate'));
        $start('archive');
        $field('archive_reason', 'reason', $str('reason'), '', true);
        $end($str('archive'));
    } else if ($currentview && $p->status === 'review') {
        $start('approve');
        $field('approve_reason', 'reason', $str('approvereason'), '', true);
        $end($str('approve'));
        $start('return');
        $field('return_reason', 'reason', $str('returnreason'), '', true);
        $end($str('return'));
    } else if ($currentview && $p->status === 'approved') {
        if ($data['branches']) {
            $branchoptions = [];
            $defaultbranch = '';
            foreach ($data['branches'] as $branch) {
                $branchoptions[$branch->uuid] = $branch->code . ' — ' . $branch->name;
                if ($branch->isdefault) { $defaultbranch = $branch->uuid; }
            }
            $start('apply');
            echo html_writer::label($str('applybranch'), 'apply_branch');
            echo html_writer::select($branchoptions, 'branch', $defaultbranch ?: array_key_first($branchoptions), false,
                ['id' => 'apply_branch']);
            $field('apply_reason', 'reason', $str('applyreason'), '', true);
            $end($str('apply'));
            echo $OUTPUT->notification($str('applynotice'), 'warning');
        } else {
            echo $OUTPUT->notification($str('nobranch'), 'warning');
        }
    }
}
\local_kbacompany\local\bms_page_shell_service::finish($bmsshell);
