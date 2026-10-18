<?php
require_once(__DIR__ . '/../../config.php');

require_login();
$context = context_system::instance();

$companyid = required_param('companyid', PARAM_INT);
$auditid = required_param('auditid', PARAM_INT);
$focusitemid = optional_param('focusitemid', 0, PARAM_INT);
$company = \local_kbacorporate\local\access::require_company_implementation_access($companyid, (int)$USER->id);
$audit = \local_kbacorporate\local\implementation_audits::get_audit($auditid);
if ((int)$audit->companyid !== $companyid) {
    throw new moodle_exception('companynotallowed', 'local_kbacorporate');
}
$program = \local_kbacorporate\local\implementation_programs::get_for_audit($auditid);
$canopenkanban = has_capability('local/kbacorporate:manageimplementationaudits', $context) ||
    ($program && (int)$program->curatorid === (int)$USER->id);
$cancreate = (string)$audit->status === \local_kbacorporate\local\implementation_audits::STATUS_CONFIRMED &&
    \local_kbacorporate\local\implementation_programs::plan_editable($program);

$created = optional_param('created', -1, PARAM_INT);
$existing = optional_param('existing', -1, PARAM_INT);
$ineligible = optional_param('ineligible', -1, PARAM_INT);
$errors = optional_param('errors', -1, PARAM_INT);

if (optional_param('action', '', PARAM_ALPHA) === 'create') {
    require_sesskey();
    if (!$cancreate) {
        throw new moodle_exception((string)$audit->status !==
            \local_kbacorporate\local\implementation_audits::STATUS_CONFIRMED
                ? 'implementationactionauditnotconfirmed' : 'implementationprogramnotactive',
            'local_kbacorporate');
    }
    $selected = optional_param_array('selected', [], PARAM_INT);
    $routeids = optional_param_array('route', [], PARAM_INT);
    $ownerid = optional_param('ownerid', 0, PARAM_INT);
    if (!$selected) {
        redirect(new moodle_url('/local/kbacorporate/implementation_actions.php', [
            'companyid' => $companyid,
            'auditid' => $auditid,
        ]), get_string('implementationactionselectone', 'local_kbacorporate'), null,
            \core\output\notification::NOTIFY_WARNING);
    }
    $result = \local_kbacorporate\local\implementation_actions::create_selected(
        $companyid, $auditid, $selected, $routeids, $ownerid, (int)$USER->id);
    redirect(new moodle_url('/local/kbacorporate/implementation_actions.php', [
        'companyid' => $companyid,
        'auditid' => $auditid,
        'created' => $result->created,
        'existing' => $result->existing,
        'ineligible' => $result->ineligible,
        'errors' => $result->errors,
    ]));
}

$preview = \local_kbacorporate\local\implementation_actions::preview($companyid, $auditid);
$owners = \local_kbacorporate\local\implementation_audits::get_company_owners($companyid);
$kanbanurl = new moodle_url('/local/kbacorporate/implementation_board.php', [
    'companyid' => $companyid,
    'auditid' => $auditid,
]);

$PAGE->set_context($context);
$PAGE->set_url(new moodle_url('/local/kbacorporate/implementation_actions.php', [
    'companyid' => $companyid,
    'auditid' => $auditid,
    'focusitemid' => $focusitemid,
]));
$PAGE->set_pagelayout('admin');
$PAGE->set_title(get_string('implementationactions', 'local_kbacorporate'));
$PAGE->set_heading(format_string($company->name));

$taskstatuslabel = static function(string $status): string {
    $key = 'implementationtaskstatus' . $status;
    return get_string_manager()->string_exists($key, 'local_kbacorporate')
        ? get_string($key, 'local_kbacorporate') : s($status);
};

$formatroute = static function($route): string {
    $parts = [];
    if (!empty($route->actiontoolname)) {
        $parts[] = format_string($route->actiontoolname);
    }
    if (!empty($route->coursename)) {
        $parts[] = format_string($route->coursename);
    }
    if (!$parts) {
        $parts[] = get_string('implementationactionroutegeneric', 'local_kbacorporate');
    }
    return implode(' · ', $parts);
};

echo $OUTPUT->header();
echo local_kbacorporate_implementation_navigation($companyid, 'audits');
echo local_kbacorporate_company_context_banner($company);
echo html_writer::div(
    html_writer::link(new moodle_url('/local/kbacorporate/implementation_audit.php', [
        'companyid' => $companyid,
        'id' => $auditid,
    ]), '← ' . get_string('backtoimplementationaudit', 'local_kbacorporate')) . ' · ' .
    html_writer::link(new moodle_url('/local/kbacorporate/implementation_plan.php', [
        'companyid' => $companyid,
        'auditid' => $auditid,
    ]), get_string('implementationplan', 'local_kbacorporate')),
    'kbacorp-backlink'
);

echo $OUTPUT->heading(get_string('implementationactions', 'local_kbacorporate'));
echo html_writer::tag('p', get_string('implementationactionsdesc', 'local_kbacorporate'), [
    'class' => 'kbacorp-pagelead',
]);
if ($canopenkanban) {
    echo html_writer::div(html_writer::link($kanbanurl,
        get_string('kanbanopenfiltered', 'local_kbacorporate'),
        ['class' => 'btn btn-outline-primary']), 'kbacorp-toolbar');
}
echo html_writer::div(format_string($audit->title) . ' · ' .
    get_string('bmsauditstatus' . $audit->status, 'local_kbacorporate'), 'kbacorp-pagelead');

if ($created >= 0) {
    echo $OUTPUT->notification(get_string('implementationactioncreationresult', 'local_kbacorporate', (object)[
        'created' => max(0, $created),
        'existing' => max(0, $existing),
        'ineligible' => max(0, $ineligible),
        'errors' => max(0, $errors),
    ]), $errors > 0 ? 'warning' : 'success');
}

if ((string)$audit->status !== \local_kbacorporate\local\implementation_audits::STATUS_CONFIRMED) {
    echo $OUTPUT->notification(get_string('implementationactionauditnotconfirmeddesc',
        'local_kbacorporate'), 'warning');
} else if (!\local_kbacorporate\local\implementation_programs::plan_editable($program)) {
    echo $OUTPUT->notification(get_string('implementationactionprogramnoteditable',
        'local_kbacorporate'), 'warning');
} else {
    echo $OUTPUT->notification(get_string('implementationactionpreviewnotice',
        'local_kbacorporate'), 'info');
}

echo html_writer::start_div('kbacorp-summary-grid');
foreach ([
    [$preview->counts->gaps, 'implementationactiongapcount', 'amber'],
    [$preview->counts->critical, 'implementationactioncriticalcount', 'red'],
    [$preview->counts->withactions, 'implementationactioncoveredcount', 'green'],
    [$preview->counts->withoutactions, 'implementationactionuncoveredcount', 'blue'],
] as [$value, $label, $tone]) {
    echo html_writer::div(html_writer::tag('strong', (int)$value) .
        html_writer::span(get_string($label, 'local_kbacorporate')),
        'kbacorp-summary-card kbacorp-tone-' . $tone);
}
echo html_writer::end_div();

if (!$preview->rows) {
    echo $OUTPUT->notification(get_string('implementationactionnogaps', 'local_kbacorporate'), 'success');
    echo $OUTPUT->footer();
    exit;
}

echo html_writer::start_tag('form', [
    'method' => 'post',
    'action' => (new moodle_url('/local/kbacorporate/implementation_actions.php'))->out(false),
    'class' => 'kbacorp-action-builder',
]);
echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'companyid', 'value' => $companyid]);
echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'auditid', 'value' => $auditid]);
echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'action', 'value' => 'create']);
echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'sesskey', 'value' => sesskey()]);

if ($cancreate) {
    $owneroptions = [0 => get_string('notassigned', 'local_kbacorporate')];
    foreach ($owners as $owner) {
        $owneroptions[(int)$owner->id] = fullname($owner) . ' — ' . $owner->email;
    }
    echo html_writer::start_div('kbacorp-filterbar');
    echo html_writer::label(get_string('implementationactioncommonowner', 'local_kbacorporate'),
        'implementation-action-owner');
    echo html_writer::select($owneroptions, 'ownerid', 0, false, [
        'id' => 'implementation-action-owner',
        'class' => 'custom-select',
    ]);
    echo html_writer::span(get_string('implementationactionduedaterule', 'local_kbacorporate'),
        'kbacorp-pagelead');
    echo html_writer::end_div();
}

$table = new html_table();
$table->attributes['class'] = 'generaltable kbacorp-report kbacorp-action-builder-table';
$table->head = [
    get_string('select'),
    get_string('bmscriterionname', 'local_kbacorporate'),
    get_string('bmsmaturitylevel', 'local_kbacorporate'),
    get_string('targetscore', 'local_kbacorporate'),
    get_string('gaptotarget', 'local_kbacorporate'),
    get_string('routepriority', 'local_kbacorporate'),
    get_string('implementationactionroute', 'local_kbacorporate'),
    get_string('implementationtaskexpected', 'local_kbacorporate'),
    get_string('implementationtaskdue', 'local_kbacorporate'),
    get_string('status'),
];

foreach ($preview->rows as $row) {
    $selectcell = '—';
    if ($row->eligible && $cancreate) {
        $selectcell = html_writer::checkbox('selected[]', (int)$row->id,
            $focusitemid > 0 && $focusitemid === (int)$row->id, '', [
            'aria-label' => get_string('implementationactionselectcriterion', 'local_kbacorporate',
                format_string($row->criterionname)),
        ]);
    }

    $criterion = html_writer::tag('strong', format_string($row->criterionname));
    $criterion .= html_writer::div(s($row->criterioncode), 'text-muted');
    if (!empty($row->sectionname)) {
        $criterion .= html_writer::div(format_string($row->sectionname), 'text-muted');
    }
    $criterion = html_writer::div($criterion, '', ['id' => 'action-item-' . (int)$row->id]);

    $routecell = '';
    if ($row->routes) {
        $options = [];
        foreach ($row->routes as $route) {
            $options[(int)$route->id] = $formatroute($route);
        }
        $selectedroute = $row->preferredroute ? (int)$row->preferredroute->id : 0;
        if ($row->eligible && $cancreate && count($options) > 1) {
            $routecell = html_writer::select($options, 'route[' . (int)$row->id . ']',
                $selectedroute, false, ['class' => 'custom-select']);
        } else {
            $routecell = $options[$selectedroute] ?? reset($options);
            if ($row->eligible && $cancreate && $selectedroute > 0) {
                $routecell .= html_writer::empty_tag('input', [
                    'type' => 'hidden',
                    'name' => 'route[' . (int)$row->id . ']',
                    'value' => $selectedroute,
                ]);
            }
        }
    } else {
        $routecell = html_writer::span(get_string('implementationactionnoroute', 'local_kbacorporate'),
            'text-muted');
    }

    if ($row->existingtask) {
        $statuscell = html_writer::link(new moodle_url('/local/kbacorporate/implementation_task.php', [
            'id' => (int)$row->existingtask->id,
        ]), get_string('implementationactionexisting', 'local_kbacorporate',
            $taskstatuslabel((string)$row->existingtask->status)));
    } else {
        $statuscell = get_string($cancreate ? 'implementationactionwillcreate' :
            'implementationactionnotavailable', 'local_kbacorporate');
    }

    $table->data[] = [
        $selectcell,
        $criterion,
        (int)$row->score,
        (int)$row->targetscore,
        (int)$row->gap,
        get_string('gappriority' . $row->priority, 'local_kbacorporate'),
        $routecell,
        format_text($row->expectedresult, FORMAT_PLAIN),
        userdate((int)$row->duedate, get_string('strftimedatefullshort')),
        $statuscell,
    ];
}

echo html_writer::div(html_writer::table($table), 'kbacorp-table-scroll');
if ($cancreate) {
    echo html_writer::div(html_writer::tag('button',
        get_string('implementationactioncreateselected', 'local_kbacorporate'), [
            'type' => 'submit',
            'class' => 'btn btn-primary',
        ]), 'kbacorp-toolbar');
}
echo html_writer::end_tag('form');

echo $OUTPUT->footer();
