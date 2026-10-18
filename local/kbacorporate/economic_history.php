<?php
// This file is part of Moodle - http://moodle.org/

require_once(__DIR__ . '/../../config.php');
require_login();
$context = context_system::instance();
require_capability('local/kbacorporate:vieweconomicindicators', $context);

$companyid = required_param('companyid', PARAM_INT);
$indicatorid = required_param('indicatorid', PARAM_INT);
$company = \local_kbacorporate\local\access::require_full_company_access($companyid, (int)$USER->id);
$indicator = \local_kbacorporate\local\economic_indicators::get_indicator($indicatorid, $companyid);
$values = \local_kbacorporate\local\economic_indicators::get_values($companyid, ['indicatorid' => $indicatorid]);
$history = \local_kbacorporate\local\economic_indicators::get_indicator_history($companyid, $indicatorid);
$canmanage = has_capability('local/kbacorporate:manageeconomicindicators', $context);

$PAGE->set_context($context);
$PAGE->set_url(new moodle_url('/local/kbacorporate/economic_history.php', [
    'companyid' => $companyid,
    'indicatorid' => $indicatorid,
]));
$PAGE->set_pagelayout('admin');
$PAGE->set_title(get_string('economicindicatorhistory', 'local_kbacorporate'));
$PAGE->set_heading(format_string($company->name));

echo $OUTPUT->header();
echo local_kbacorporate_implementation_navigation($companyid, 'economics');
echo local_kbacorporate_company_context_banner($company);
echo html_writer::div(html_writer::link(new moodle_url('/local/kbacorporate/economic_indicators.php', [
    'companyid' => $companyid,
]), '← ' . get_string('economicregistry', 'local_kbacorporate')), 'kbacorp-backlink');
echo $OUTPUT->heading(s($indicator->code) . ' — ' . format_string($indicator->name));
if (!empty($indicator->description)) {
    echo html_writer::div(format_text($indicator->description, FORMAT_PLAIN), 'kbacorp-pagelead');
}
if ($canmanage && !empty($indicator->active)) {
    echo html_writer::div(html_writer::link(new moodle_url('/local/kbacorporate/economic_value_edit.php', [
        'companyid' => $companyid,
        'indicatorid' => $indicatorid,
    ]), get_string('economicaddvalue', 'local_kbacorporate'), ['class' => 'btn btn-primary']), 'mb-3');
}

echo $OUTPUT->heading(get_string('economicvalueshistory', 'local_kbacorporate'), 2);
if (!$values) {
    echo $OUTPUT->notification(get_string('economicregistrynovalues', 'local_kbacorporate'), 'info', false);
} else {
    $table = new html_table();
    $table->attributes['class'] = 'generaltable kbacorp-report';
    $table->head = [
        get_string('economicperiod', 'local_kbacorporate'),
        get_string('economicscenario', 'local_kbacorporate'),
        get_string('economicvalue', 'local_kbacorporate'),
        get_string('economicsource', 'local_kbacorporate'),
        get_string('economicregistryaudit', 'local_kbacorporate'),
        get_string('economicverified', 'local_kbacorporate'),
        get_string('comment'),
        get_string('actions'),
    ];
    foreach ($values as $value) {
        $audit = '—';
        if (!empty($value->auditid) && !empty($value->audittitle)) {
            $audit = html_writer::link(new moodle_url('/local/kbacorporate/implementation_audit.php', [
                'id' => (int)$value->auditid,
                'companyid' => $companyid,
            ]), format_string($value->audittitle));
        }
        $actions = $canmanage ? html_writer::link(new moodle_url('/local/kbacorporate/economic_value_edit.php', [
            'companyid' => $companyid,
            'id' => (int)$value->id,
        ]), get_string('edit')) : '—';
        $table->data[] = [
            \local_kbacorporate\local\economic_indicators::period_label($value),
            get_string('economicscenario' . $value->scenario, 'local_kbacorporate'),
            \local_kbacorporate\local\economic_indicators::format_value(
                $value->value, (string)$value->valuetype, (string)$value->unit, (string)$value->currency),
            get_string('source' . $value->source, 'local_kbacorporate'),
            $audit,
            get_string($value->verified ? 'economicverifiedyes' : 'economicverifiedno', 'local_kbacorporate'),
            $value->comment ? s($value->comment) : '—',
            $actions,
        ];
    }
    echo html_writer::div(html_writer::table($table), 'kbacorp-table-scroll');
}

echo $OUTPUT->heading(get_string('economicchangelog', 'local_kbacorporate'), 2);
if (!$history) {
    echo $OUTPUT->notification(get_string('economicnohistory', 'local_kbacorporate'), 'info', false);
} else {
    $users = [];
    foreach ($history as $event) {
        if (!empty($event->actorid)) {
            $users[(int)$event->actorid] = true;
        }
    }
    $userrecords = $users ? $DB->get_records_list('user', 'id', array_keys($users), '', 'id,firstname,lastname') : [];
    $historytable = new html_table();
    $historytable->attributes['class'] = 'generaltable kbacorp-report';
    $historytable->head = [
        get_string('date'),
        get_string('economicchangeentity', 'local_kbacorporate'),
        get_string('economicchangeaction', 'local_kbacorporate'),
        get_string('user'),
        get_string('economicsource', 'local_kbacorporate'),
        get_string('economicchangedetails', 'local_kbacorporate'),
    ];
    foreach ($history as $event) {
        $actor = !empty($event->actorid) && isset($userrecords[(int)$event->actorid])
            ? fullname($userrecords[(int)$event->actorid]) : get_string('system');
        $old = $event->oldjson ? json_decode($event->oldjson, true) : null;
        $new = $event->newjson ? json_decode($event->newjson, true) : null;
        $details = [];
        if ($event->entitytype === 'value') {
            $oldvalue = is_array($old) && array_key_exists('value', $old) ? $old['value'] : null;
            $newvalue = is_array($new) && array_key_exists('value', $new) ? $new['value'] : null;
            if ($oldvalue !== $newvalue) {
                $details[] = get_string('economicvalue', 'local_kbacorporate') . ': ' .
                    ($oldvalue === null ? '—' : s((string)$oldvalue)) . ' → ' .
                    ($newvalue === null ? '—' : s((string)$newvalue));
            }
            $periodkey = is_array($new) ? ($new['periodkey'] ?? '') : '';
            if ($periodkey !== '') {
                $details[] = get_string('economicperiod', 'local_kbacorporate') . ': ' . s($periodkey);
            }
            $scenario = is_array($new) ? ($new['scenario'] ?? '') : '';
            if (in_array($scenario, \local_kbacorporate\local\economic_indicators::scenarios(), true)) {
                $details[] = get_string('economicscenario', 'local_kbacorporate') . ': ' .
                    get_string('economicscenario' . $scenario, 'local_kbacorporate');
            }
        } else {
            $oldname = is_array($old) ? ($old['name'] ?? null) : null;
            $newname = is_array($new) ? ($new['name'] ?? null) : null;
            if ($oldname !== $newname) {
                $details[] = get_string('name') . ': ' . s((string)$oldname) . ' → ' . s((string)$newname);
            }
        }
        $historytable->data[] = [
            userdate((int)$event->timecreated, get_string('strftimedatetimeshort')),
            get_string('economichistoryentity' . $event->entitytype, 'local_kbacorporate'),
            get_string('economichistoryaction' . $event->action, 'local_kbacorporate'),
            s($actor),
            in_array((string)$event->source,
                \local_kbacorporate\local\economic_indicators::sources(), true)
                ? get_string('source' . $event->source, 'local_kbacorporate') : s($event->source),
            $details ? implode('<br>', $details) : '—',
        ];
    }
    echo html_writer::div(html_writer::table($historytable), 'kbacorp-table-scroll');
}

echo $OUTPUT->footer();
