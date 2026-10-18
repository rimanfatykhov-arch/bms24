<?php
require_once(__DIR__ . '/../../config.php');

use local_kbacompany\local\workflow_service;
use local_kbacompany\local\cascade_service;
use local_kbacompany\local\legacy_company_adapter;

require_login();
$context = context_system::instance();
require_capability('local/kbacompany:viewowncompany', $context);
$uuid = required_param('uuid', PARAM_RAW_TRIMMED);
$workuuid = optional_param('work', '', PARAM_RAW_TRIMMED);
$basis = optional_param('basis', '', PARAM_RAW_TRIMMED);
$data = workflow_service::context($uuid, (int)$USER->id, $workuuid);
$error = '';
$input = [];
$action = optional_param('action', '', PARAM_ALPHA);
if ($action !== '') {
    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') { throw new moodle_exception('productpostrequired', 'local_kbacompany'); }
    require_sesskey();
    foreach (['object', 'revision', 'userid', 'reviewerid', 'name', 'description', 'acceptance',
            'deadline', 'strategy', 'plan', 'function', 'body', 'url', 'reason', 'fingerprint', 'norm', 'period', 'periodstart'] as $key) {
        $input[$key] = optional_param($key, '', PARAM_RAW_TRIMMED);
    }
    try {
        $result = workflow_service::command($uuid, (int)$USER->id, $action, $input);
        $target = in_array($action, ['repeat', 'plan', 'task', 'activate', 'close', 'cancel', 'start', 'submit', 'accept', 'return'], true)
            ? $result : '';
        redirect(new moodle_url('/local/kbacompany/execution.php', ['uuid' => $uuid, 'work' => $target, 'basis' => $basis]),
            get_string('productsaved', 'local_kbacompany'));
    } catch (moodle_exception $e) {
        if ($e->module !== 'local_kbacompany') { throw $e; }
        $error = $e->getMessage();
    }
}
$PAGE->set_context($context);
$PAGE->set_url(new moodle_url('/local/kbacompany/execution.php', ['uuid' => $uuid, 'work' => $workuuid, 'basis' => $basis]));
// BMS page shell adapter selects the presentation layout.
$PAGE->set_title(get_string('wf_title', 'local_kbacompany'));
$PAGE->set_heading(get_string('pluginname', 'local_kbacompany'));
$str = static function(string $key): string { return get_string('wf_' . $key, 'local_kbacompany'); };
$bmsshell = \local_kbacompany\local\bms_page_shell_service::begin($uuid, (int)$USER->id, 'execution', get_string('wf_title', 'local_kbacompany'));
if ($data['can_manage']) {
    echo html_writer::link(new moodle_url('/local/kbacompany/deviations.php', ['uuid' => $uuid]),
        get_string('dv_title', 'local_kbacompany'), ['class' => 'btn btn-primary mb-3']);
}
echo html_writer::link(new moodle_url('/local/kbacompany/cascade.php', ['uuid' => $uuid]),
    '← ' . get_string('ac_title', 'local_kbacompany'), ['class' => 'btn btn-outline-primary']);
echo $OUTPUT->heading($str('title') . ': ' . s($data['company']['name']));
echo html_writer::tag('p', $str('intro'));
if ($error !== '') { echo $OUTPUT->notification(s($error), 'error'); }
$cascade = cascade_service::context($uuid, (int)$USER->id);
$objects = $functions = $strategies = $norms = [];
foreach ($cascade['objects'] as $obj) {
    $objects[$obj->uuid] = $obj;
    if ($obj->status !== 'draft') { continue; }
    if ($obj->kind === 'function') { $functions[$obj->uuid] = $obj->code . ' — ' . $obj->name; }
    if ($obj->kind === 'strategy') { $strategies[$obj->uuid] = $obj->code . ' — ' . $obj->name; }
    if ($obj->kind === 'target') { $norms[$obj->uuid] = $obj->code . ' — ' . $obj->name; }
}
if ($basis !== '' && !isset($objects[$basis])) { throw new moodle_exception('wf_reference', 'local_kbacompany'); }
$matches = [];
$approvalmap = [];
foreach ($data['approvals'] as $a) {
    $approvalmap[$a->uuid] = json_decode($a->snapshot, true);
}
foreach ($data['work'] as $w) {
    if ($basis === '') { $matches[$w->uuid] = true; continue; }
    foreach ($approvalmap[$w->approvaluuid]['objects'] ?? [] as $part) {
        if ($part['uuid'] === $basis && ($objects[$basis]->kind !== 'target' || $w->normuuid === $basis)) {
            $matches[$w->uuid] = true;
            if ($w->parentuuid !== '') { $matches[$w->parentuuid] = true; }
        }
    }
}
$members = legacy_company_adapter::organization((int)$data['company']['compatibility']['source_id'])['members'];
$people = [];
foreach ($members as $member) {
    $people[$member->userid] = trim($member->lastname . ' ' . $member->firstname . ' ' . $member->middlename);
}
$person = static function($id) use ($people): string { return s($people[$id] ?? '—'); };
$objlink = static function(string $id) use ($objects, $uuid): string {
    return html_writer::link(new moodle_url('/local/kbacompany/cascade.php', ['uuid' => $uuid, 'object' => $id]),
        s(isset($objects[$id]) ? $objects[$id]->code . ' — ' . $objects[$id]->name : $id));
};
$worklink = static function($work) use ($uuid, $basis): string {
    return html_writer::link(new moodle_url('/local/kbacompany/execution.php',
        ['uuid' => $uuid, 'work' => $work->uuid, 'basis' => $basis]), s($work->name));
};
$start = static function(string $command, array $hidden = []) use ($uuid, $workuuid, $basis): void {
    echo html_writer::start_tag('form', ['method' => 'post', 'class' => 'kbacompany-form mb-3']);
    foreach (array_merge(['uuid' => $uuid, 'work' => $workuuid, 'basis' => $basis,
            'action' => $command, 'sesskey' => sesskey()], $hidden) as $key => $value) {
        echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => $key, 'value' => $value]);
    }
};
$field = static function(string $prefix, string $name, string $label, $options = null,
        string $type = 'text', bool $required = true) use ($input): void {
    $id = $prefix . '_' . $name;
    echo html_writer::label($label, $id);
    $attrs = ['id' => $id, 'name' => $name];
    if ($required) { $attrs['required'] = 'required'; }
    $value = $input[$name] ?? '';
    if (is_array($options)) {
        echo html_writer::select($options, $name, $value, ['' => '—'], $attrs);
    } else if ($type === 'textarea') {
        echo html_writer::tag('textarea', s($value), $attrs + ['rows' => 3, 'maxlength' => 4000]);
    } else {
        echo html_writer::empty_tag('input', $attrs + ['type' => $type, 'value' => $value]);
    }
};
$end = static function(string $label): void {
    echo html_writer::tag('button', $label, ['type' => 'submit', 'class' => 'btn btn-primary']);
    echo html_writer::end_tag('form');
};
$plans = $workmap = [];
$table = new html_table();
$table->head = [$str('name'), $str('kind'), $str('status'), $str('executor'), $str('reviewer'), $str('deadline')];
$today = userdate(time(), '%Y-%m-%d');
foreach ($data['work'] as $work) {
    $workmap[$work->uuid] = $work;
    if ($work->kind === 'plan' && $work->status === 'draft') { $plans[$work->uuid] = $work->name; }
    if (!isset($matches[$work->uuid])) { continue; }
    $late = $work->deadline < $today && !in_array($work->status, ['draft', 'closed', 'accepted', 'cancelled'], true);
    $table->data[] = [$worklink($work), $str('kind_' . $work->kind), $str('status_' . $work->status),
        $person($work->userid), $person($work->reviewerid), s($work->deadline) . ($late ? ' — ' . $str('overdue') : '')];
}
echo $OUTPUT->heading($str('registry'), 3);
if ($basis !== '') {
    echo html_writer::tag('p', $str('basis') . ': ' . $objlink($basis));
    echo html_writer::link(new moodle_url('/local/kbacompany/execution.php', ['uuid' => $uuid]), $str('showall'));
}
echo $data['work'] ? html_writer::table($table) : $OUTPUT->notification($str('empty'), 'info');
$selected = $data['selected'];
if ($data['can_manage']) {
    echo $OUTPUT->heading($str('cycles'), 3);
    echo html_writer::tag('p', $str('cycleintro'));
    $sources = [];
    foreach ($workmap as $w) {
        if ($w->kind === 'plan' && in_array($w->status, ['active', 'closed'], true)) {
            $sources[$w->uuid] = $w->name . ' — ' . $w->deadline;
        }
    }
    if ($sources) {
        $start('repeat');
        $field('repeat', 'object', $str('kind_plan'), $sources);
        $field('repeat', 'period', $str('cadence'), ['weekly' => $str('weekly'), 'monthly' => $str('monthly')]);
        $field('repeat', 'periodstart', $str('periodstart'), null, 'date');
        $end($str('repeat'));
    }
    echo html_writer::tag('p', $str('checkpointintro'));
    foreach ($data['cycles'] as $cycle) {
        $plan = $workmap[$cycle->planuuid] ?? null;
        if (!$plan) { continue; }
        echo html_writer::start_tag('div', ['class' => 'card card-body mb-3']);
        echo html_writer::tag('p', $worklink($plan) . ' · ' . s($cycle->periodstart) . ' · ' .
            $str($cycle->periodtype) . ' · ' . $str('deadline') . ': ' . s($plan->deadline));
        echo html_writer::tag('p', $str('status_' . $plan->status) . ' · ' .
            ($cycle->checkedat ? $str('checked') . ': ' . userdate($cycle->checkedat) :
                $str('pending') . ($plan->deadline < $today ? ' — ' . $str('overdue') : '')));
        if (!$cycle->checkedat) {
            $start('checkpoint', ['object' => $cycle->uuid]);
            $field('checkpoint_' . $cycle->id, 'reason', $str('reason'), null, 'textarea');
            $end($str('checkpoint'));
        } else {
            $entry = $DB->get_records('local_kbacompany_aclog', ['companyuuid' => $uuid,
                'objectuuid' => $cycle->uuid, 'action' => 'wf_checkpoint'], 'id DESC', '*', 0, 1);
            if ($entry) {
                $decision = json_decode(reset($entry)->newvalue ?? 'null', true);
                echo html_writer::tag('p', s($decision['reason'] ?? ''));
            }
        }
        echo html_writer::end_tag('div');
    }
}
if ($selected) {
    echo $OUTPUT->heading(s($selected->name), 3);
    if ($selected->parentuuid !== '' && isset($workmap[$selected->parentuuid])) {
        echo html_writer::tag('p', $str('kind_plan') . ': ' . $worklink($workmap[$selected->parentuuid]));
    }
    if ($selected->functionuuid !== '') { echo html_writer::tag('p', $objlink($selected->functionuuid)); }
    if ($selected->kind === 'task') {
        echo html_writer::tag('p', $selected->normuuid !== '' ? $str('norm') . ': ' . $objlink($selected->normuuid) : $str('nonorm'));
    }
    echo format_text($selected->description, FORMAT_PLAIN);
    echo html_writer::tag('p', $str('acceptance') . ': ' . s($selected->acceptance));
    echo html_writer::tag('p', $str('revision') . ': ' . (int)$selected->revision);
    if ($selected->kind === 'plan') {
        foreach ($data['work'] as $child) {
            if ($child->parentuuid === $selected->uuid) { echo html_writer::tag('p', $worklink($child)); }
        }
    }
    // Display the exact approved normative snapshot used by this work item.
    foreach ($data['approvals'] as $approval) {
        if ($approval->uuid !== $selected->approvaluuid) { continue; }
        $snapshot = json_decode($approval->snapshot, true);
        echo html_writer::start_tag('details', ['class' => 'mb-3']);
        echo html_writer::tag('summary', $str('why'));
        echo html_writer::tag('p', userdate($approval->timecreated) . ' · ' . $str('revision') . ' ' . (int)$approval->revision);
        $parts = $snapshot['objects'] ?? [];
        $rank = array_flip(['intent', 'goal', 'strategy', 'function', 'result', 'target']);
        usort($parts, static function($a, $b) use ($rank): int { return ($rank[$a['kind']] ?? 99) <=> ($rank[$b['kind']] ?? 99); });
        foreach ($parts as $item) {
            echo html_writer::tag('h4', s($item['code'] . ' — ' . $item['name']));
            echo $objlink($item['uuid']);
            echo format_text($item['description'] ?? '', FORMAT_PLAIN);
            foreach (json_decode($item['payload'] ?? '{}', true) ?? [] as $key => $value) {
                echo html_writer::tag('p', get_string('ac_field_' . $key, 'local_kbacompany') . ': ' . s($value ?? '—'));
            }
        }
        echo html_writer::end_tag('details');
    }
    foreach ($data['evidence'] as $e) {
        echo $OUTPUT->heading($str('attempt') . ' ' . (int)$e->attempt, 4);
        echo format_text($e->body ?? '', FORMAT_PLAIN);
        if (!empty($e->url)) { echo html_writer::link($e->url, $str('evidencelink'), ['target' => '_blank', 'rel' => 'noopener noreferrer']); }
        echo html_writer::tag('p', userdate($e->timecreated) . ' · ' . $person($e->userid));
    }
    $hidden = ['object' => $selected->uuid, 'revision' => $selected->revision];
    if ($selected->kind === 'task' && (int)$selected->userid === (int)$USER->id &&
            in_array($selected->status, ['open', 'doing', 'returned'], true)) {
        if ($selected->status !== 'doing') { $start('start', $hidden); $end($str('action_start')); }
        $start('submit', $hidden);
        $field('submit', 'body', $str('body'), null, 'textarea');
        $field('submit', 'url', $str('url'), null, 'url', false);
        $end($str('action_submit'));
    }
    if ($selected->status === 'review' && (int)$selected->reviewerid === (int)$USER->id) {
        foreach (['accept', 'return'] as $command) {
            $start($command, $hidden);
            $field($command, 'reason', $str('reason'), null, 'textarea');
            $end($str('action_' . $command));
        }
    }
    if ($data['can_manage']) {
        if ($selected->kind === 'plan' && in_array($selected->status, ['draft', 'active'], true)) {
            $command = $selected->status === 'draft' ? 'activate' : 'close';
            $start($command, $hidden); $end($str('action_' . $command));
        }
        if (!in_array($selected->status, ['closed', 'accepted', 'cancelled'], true)) {
            $start('cancel', $hidden);
            $field('cancel', 'reason', $str('reason'), null, 'textarea');
            $end($str('action_cancel'));
        }
    }
    echo html_writer::start_tag('details');
    echo html_writer::tag('summary', $str('history'));
    foreach ($data['history'] as $entry) {
        $record = json_decode($entry->newvalue ?? '{}', true);
        echo html_writer::tag('p', userdate($entry->timecreated) . ' · ' . $person($entry->actorid) . ' · ' .
            $str('action_' . substr($entry->action, 3)) . ' · ' . s($record['reason'] ?? ''));
    }
    echo html_writer::end_tag('details');
}
echo $OUTPUT->heading($str('assignments'), 3);
$table = new html_table();
$table->head = [$str('function'), $str('executor'), $str('period')];
foreach ($data['assignments'] as $assignment) {
    $table->data[] = [$objlink($assignment->functionuuid), $person($assignment->userid),
        userdate($assignment->timecreated) . ' — ' . ($assignment->timeended ? userdate($assignment->timeended) : $str('current'))];
}
echo html_writer::table($table);
echo html_writer::start_tag('details', ['class' => 'mb-3']);
echo html_writer::tag('summary', $str('approvals'));
$approvaltable = new html_table();
$approvaltable->head = [$str('basis'), $str('revision'), $str('status'), $str('reviewer')];
$fingerprints = [];
foreach ($data['approvals'] as $a) {
    if (!array_key_exists($a->objectuuid, $fingerprints)) {
        try { $fingerprints[$a->objectuuid] = workflow_service::preview($uuid, $a->objectuuid, (int)$USER->id)['fingerprint']; }
        catch (moodle_exception $e) {
            if ($e->module !== 'local_kbacompany') { throw $e; }
            $fingerprints[$a->objectuuid] = '';
        }
    }
    $state = $a->revoked ? 'revoked' : ($fingerprints[$a->objectuuid] === $a->fingerprint ? 'approvedcurrent' : 'approvedpast');
    $approvaltable->data[] = [$objlink($a->objectuuid), (int)$a->revision, $str($state), $person($a->approvedby)];
}
echo html_writer::table($approvaltable);
echo html_writer::end_tag('details');
if ($data['can_manage']) {
    foreach ($data['assignments'] as $assignment) {
        if ($assignment->timeended) { continue; }
        $start('unassign', ['object' => $assignment->uuid]);
        echo html_writer::tag('p', $objlink($assignment->functionuuid) . ' · ' . $person($assignment->userid));
        $end($str('action_unassign'));
    }
    if ($functions && $people) {
        $start('assign');
        $field('assign', 'object', $str('function'), $functions);
        $field('assign', 'userid', $str('executor'), $people);
        $end($str('action_assign'));
    }
    echo $OUTPUT->heading($str('approvals'), 3);
    echo html_writer::tag('p', $str('approvalhint'));
    echo html_writer::start_tag('details');
    echo html_writer::tag('summary', $str('approvals'));
    foreach ($objects as $obj) {
        if ($obj->status !== 'draft') { continue; }
        try { $preview = workflow_service::preview($uuid, $obj->uuid, (int)$USER->id); }
        catch (moodle_exception $e) {
            if ($e->module !== 'local_kbacompany') { throw $e; }
            echo html_writer::tag('p', $objlink($obj->uuid) . ': ' . s($e->getMessage()));
            continue;
        }
        $start('approve', ['object' => $obj->uuid, 'revision' => $obj->revision, 'fingerprint' => $preview['fingerprint']]);
        echo html_writer::tag('p', $objlink($obj->uuid) . ' · ' . $str('revision') . ' ' . (int)$obj->revision);
        echo html_writer::start_tag('details');
        echo html_writer::tag('summary', $str('snapshot'));
        foreach ($preview['bundle']['objects'] as $part) {
            echo html_writer::tag('p', $objlink($part->uuid) . ' · ' . $str('revision') . ' ' . (int)$part->revision);
            foreach (json_decode($part->payload ?? '{}', true) ?? [] as $key => $value) {
                echo html_writer::tag('p', get_string('ac_field_' . $key, 'local_kbacompany') . ': ' . s($value ?? '—'));
            }
        }
        echo html_writer::end_tag('details');
        $end($str('action_approve'));
    }
    foreach ($data['approvals'] as $approval) {
        if ($approval->revoked) { continue; }
        $start('revoke', ['object' => $approval->uuid]);
        echo html_writer::tag('p', $objlink($approval->objectuuid) . ' · ' . userdate($approval->timecreated));
        $field('revoke_' . $approval->id, 'reason', $str('reason'), null, 'textarea');
        $end($str('action_revoke'));
    }
    echo html_writer::end_tag('details');
    foreach (['plan', 'task'] as $kind) {
        if (($kind === 'plan' && !$strategies) || ($kind === 'task' && (!$plans || !$functions || !$people))) { continue; }
        echo html_writer::start_tag('details', ['class' => 'mt-3']);
        echo html_writer::tag('summary', $str('create_' . $kind));
        $start($kind);
        if ($kind === 'plan') { $field('plan', 'strategy', $str('strategy'), $strategies); }
        else {
            $field('task', 'plan', $str('kind_plan'), $plans);
            $field('task', 'function', $str('function'), $functions);
            $field('task', 'reviewerid', $str('reviewer'), $people);
            $field('task', 'norm', $str('norm'), $norms, 'text', false);
        }
        $field($kind, 'name', $str('name'));
        $field($kind, 'description', $str('description'), null, 'textarea');
        $field($kind, 'acceptance', $str('acceptance'), null, 'textarea');
        $field($kind, 'deadline', $str('deadline'), null, 'date');
        $end($str('create_' . $kind));
        echo html_writer::end_tag('details');
    }
}
echo $OUTPUT->notification($str('methodnotice'), 'info');
\local_kbacompany\local\bms_page_shell_service::finish($bmsshell);
