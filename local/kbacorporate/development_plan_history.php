<?php
require_once(__DIR__ . '/../../config.php');

require_login();

$context = context_system::instance();
$planid = required_param('id', PARAM_INT);
$plan = \local_kbacorporate\local\development_plans::get_plan($planid);
$items = \local_kbacorporate\local\development_plans::get_items($planid);

$isself = (int)$plan->userid === (int)$USER->id;
$canmanage = has_capability('local/kbacorporate:managedevelopmentplans', $context) &&
    \local_kbacorporate\local\access::can_view_employee((int)$plan->companyid, (int)$USER->id, (int)$plan->userid);
$hasmentorassignment = (int)($plan->mentorid ?? 0) === (int)$USER->id;
if (!$hasmentorassignment) {
    foreach ($items as $item) {
        if ((int)($item->mentorid ?? 0) === (int)$USER->id) {
            $hasmentorassignment = true;
            break;
        }
    }
}
$ismentor = $hasmentorassignment &&
    \local_kbacorporate\local\development_plans::is_valid_mentor_assignment(
        (int)$plan->companyid, (int)$USER->id);
if (!$isself && !$canmanage && !$ismentor) {
    throw new required_capability_exception($context,
        'local/kbacorporate:managedevelopmentplans', 'nopermissions', '');
}

$PAGE->set_context($context);
$PAGE->set_url(new moodle_url('/local/kbacorporate/development_plan_history.php', ['id' => $planid]));
$PAGE->set_pagelayout('standard');
$PAGE->set_title(get_string('iprhistorytitle', 'local_kbacorporate'));
$PAGE->set_heading(get_string('iprhistorytitle', 'local_kbacorporate'));

$eventlabels = [];
foreach ([
    'plan_created', 'plan_regenerated', 'plan_updated', 'item_added', 'item_updated', 'item_deleted',
    'item_status_changed', 'item_started', 'evidence_submitted', 'evidence_approved', 'evidence_rejected',
    'checkpoint_recorded', 'plan_submitted', 'plan_accepted', 'plan_changes_requested', 'plan_cancelled',
    'plan_reopened', 'course_completed', 'course_completed_evidence_required', 'plan_completed',
] as $eventtype) {
    $eventlabels[$eventtype] = get_string('iprhistoryevent_' . $eventtype, 'local_kbacorporate');
}
$itemlookup = [];
foreach ($items as $item) {
    $itemlookup[(int)$item->id] = $item;
}

$history = \local_kbacorporate\local\development_plans::get_history($planid);

// Optional filters are read-only and intentionally do not alter history.
$eventfilter = optional_param('eventtype', '', PARAM_ALPHANUMEXT);
if ($eventfilter !== '' && isset($eventlabels[$eventfilter])) {
    $history = array_values(array_filter($history, static function($event) use ($eventfilter) {
        return (string)$event->eventtype === $eventfilter;
    }));
}

echo $OUTPUT->header();
$navlinks = [
    html_writer::link(new moodle_url('/local/kbacorporate/development_plan.php', ['id' => $planid]),
        '← ' . get_string('developmentplan', 'local_kbacorporate')),
    html_writer::link(new moodle_url('/local/kbacorporate/study_plan_progress.php', ['id' => $planid]),
        get_string('studyplanprogresstitle', 'local_kbacorporate')),
];
echo html_writer::div(implode(html_writer::span('·', 'kbacorp-page-navigation-separator'), $navlinks),
    'kbacorp-page-navigation', ['role' => 'navigation']);

echo html_writer::start_div('kbacorp-ipr-history-hero');
echo html_writer::div(get_string('individualdevelopmentplan', 'local_kbacorporate'), 'kbacorp-eyebrow');
echo $OUTPUT->heading(get_string('iprhistorytitle', 'local_kbacorporate'), 2);
echo html_writer::div(format_string($plan->title) . ' · ' . fullname($plan) . ' · ' .
    format_string($plan->companyname), 'kbacorp-pagelead');
echo html_writer::end_div();

echo html_writer::start_tag('form', ['method' => 'get', 'class' => 'kbacorp-filters kbacorp-ipr-history-filters']);
echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'id', 'value' => $planid]);
echo html_writer::label(get_string('ipreventtype', 'local_kbacorporate'), 'ipr-event-filter');
echo html_writer::select(['' => get_string('all')] + $eventlabels, 'eventtype', $eventfilter, false, [
    'id' => 'ipr-event-filter',
]);
echo html_writer::tag('button', get_string('applyfilters', 'local_kbacorporate'), [
    'type' => 'submit', 'class' => 'btn btn-primary',
]);
echo html_writer::link(new moodle_url('/local/kbacorporate/development_plan_history.php', ['id' => $planid]),
    get_string('resetfilters', 'local_kbacorporate'), ['class' => 'btn btn-secondary']);
echo html_writer::end_tag('form');

if (!\local_kbacorporate\local\development_plans::history_available()) {
    echo $OUTPUT->notification(get_string('iprhistoryunavailable', 'local_kbacorporate'), 'warning');
} else if (!$history) {
    echo html_writer::start_div('kbacorp-ipr-history-empty');
    echo $OUTPUT->heading(get_string('iprhistoryempty', 'local_kbacorporate'), 3);
    echo html_writer::div(get_string('iprhistoryemptydesc', 'local_kbacorporate'));
    echo html_writer::end_div();
} else {
    $table = new html_table();
    $table->attributes['class'] = 'generaltable kbacorp-ipr-history-table';
    $table->head = [
        get_string('date'),
        get_string('ipreventtype', 'local_kbacorporate'),
        get_string('iprhistoryobject', 'local_kbacorporate'),
        get_string('iprhistoryactor', 'local_kbacorporate'),
        get_string('iprhistorystatuschange', 'local_kbacorporate'),
        get_string('comment'),
    ];
    foreach ($history as $event) {
        $eventlabel = $eventlabels[(string)$event->eventtype] ?? s((string)$event->eventtype);
        $objecthtml = get_string('iprhistoryplan', 'local_kbacorporate');
        if ((int)$event->itemid > 0) {
            if (isset($itemlookup[(int)$event->itemid])) {
                $linkeditem = $itemlookup[(int)$event->itemid];
                $objecthtml = html_writer::link(
                    new moodle_url('/local/kbacorporate/development_item.php', ['id' => (int)$linkeditem->id]),
                    format_string($linkeditem->competencyname)
                );
            } else if (!empty($event->details['competencyid'])) {
                $competency = $DB->get_record('competency', ['id' => (int)$event->details['competencyid']],
                    'id,shortname,idnumber', IGNORE_MISSING);
                $objecthtml = $competency ? format_string($competency->shortname) :
                    get_string('iprhistorydeleteditem', 'local_kbacorporate', (int)$event->itemid);
            } else {
                $objecthtml = get_string('iprhistorydeleteditem', 'local_kbacorporate', (int)$event->itemid);
            }
        }
        $actor = (int)$event->actorid > 0 ? fullname($event) : get_string('iprsystemactor', 'local_kbacorporate');
        $statushtml = '—';
        if (!empty($event->fromstatus) || !empty($event->tostatus)) {
            $statuslabel = static function(string $status): string {
                if ($status === '') {
                    return '—';
                }
                if (in_array($status, \local_kbacorporate\local\development_plans::item_statuses(), true)) {
                    return get_string('developmentitemstatus_' . $status, 'local_kbacorporate');
                }
                return get_string('developmentplanstatus_' . $status, 'local_kbacorporate');
            };
            $statushtml = s($statuslabel((string)$event->fromstatus)) . ' → ' .
                s($statuslabel((string)$event->tostatus));
        }
        $comment = trim((string)($event->comment ?? '')) !== '' ?
            nl2br(s((string)$event->comment)) : '—';
        $table->data[] = [
            userdate((int)$event->timecreated, get_string('strftimedatetimeshort', 'langconfig')),
            $eventlabel,
            $objecthtml,
            s($actor),
            $statushtml,
            $comment,
        ];
    }
    echo html_writer::div(html_writer::table($table), 'kbacorp-table-scroll');
}

echo html_writer::div(implode(html_writer::span('·', 'kbacorp-page-navigation-separator'), $navlinks),
    'kbacorp-page-navigation', ['role' => 'navigation']);
echo $OUTPUT->footer();
