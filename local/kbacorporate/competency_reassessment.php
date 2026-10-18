<?php
require_once(__DIR__ . '/../../config.php');

require_login();
$context = context_system::instance();
require_capability('local/kbacorporate:managecompetencyaudits', $context);

$auditid = required_param('id', PARAM_INT);
$projection = \local_kbacorporate\local\competency_reassessment::build($auditid);
$audit = $projection->audit;
$plan = $projection->plan;
$company = \local_kbacorporate\local\access::require_full_company_access(
    (int)$audit->companyid, (int)$USER->id
);
$canconfirm = has_capability('moodle/competency:competencygrade',
    context_user::instance((int)$audit->userid));
$url = new moodle_url('/local/kbacorporate/competency_reassessment.php', ['id' => $auditid]);

$PAGE->set_context($context);
$PAGE->set_url($url);
$PAGE->set_pagelayout('standard');
$PAGE->set_title(get_string('reassessmenttitle', 'local_kbacorporate'));
$PAGE->set_heading(format_string($company->name));
$PAGE->requires->css(new moodle_url('/local/kbacorporate/styles.css'));

if (data_submitted() && confirm_sesskey() && isset($_POST['action'])) {
    $action = required_param('action', PARAM_ALPHANUMEXT);
    $itemids = array_map(static function($row) { return (int)$row->itemid; }, $projection->rows);
    if ($action === 'refresh') {
        $result = \local_kbacorporate\local\competency_reassessment::refresh_evidence(
            $auditid, (int)$USER->id
        );
        redirect($url, get_string('reassessmentrefreshresult', 'local_kbacorporate', $result), null,
            \core\output\notification::NOTIFY_SUCCESS);
    }
    if ($action === 'confirmall') {
        if (!$canconfirm) {
            throw new required_capability_exception($context,
                'moodle/competency:competencygrade', 'nopermissions', '');
        }
        $confirmed = 0;
        foreach ($projection->rows as $row) {
            if ((string)$row->status === \local_kbacorporate\local\competency_audits::ITEM_PENDING) {
                \local_kbacorporate\local\competency_audits::confirm_assessment(
                    (int)$row->itemid, (int)$USER->id
                );
                $confirmed++;
            }
        }
        redirect($url, get_string('reassessmentbulkconfirmed', 'local_kbacorporate', $confirmed), null,
            \core\output\notification::NOTIFY_SUCCESS);
    }
    if ($action === 'confirm' || $action === 'reject') {
        $itemid = required_param('itemid', PARAM_INT);
        if (!in_array($itemid, $itemids, true)) {
            throw new moodle_exception('audititemnotfound', 'local_kbacorporate');
        }
        if ($action === 'confirm') {
            if (!$canconfirm) {
                throw new required_capability_exception($context,
                    'moodle/competency:competencygrade', 'nopermissions', '');
            }
            \local_kbacorporate\local\competency_audits::confirm_assessment($itemid, (int)$USER->id);
            redirect($url, get_string('auditconfirmed', 'local_kbacorporate'), null,
                \core\output\notification::NOTIFY_SUCCESS);
        }
        \local_kbacorporate\local\competency_audits::reject_assessment($itemid, (int)$USER->id);
        redirect($url, get_string('auditrejected', 'local_kbacorporate'), null,
            \core\output\notification::NOTIFY_SUCCESS);
    }
    throw new moodle_exception('invalidaction');
}

// Assessment form is intentionally restricted to missing or rejected items from this reassessment.
$competencyoptions = [];
foreach ($projection->rows as $row) {
    if (in_array((string)$row->status, [
            \local_kbacorporate\local\competency_audits::ITEM_MISSING,
            \local_kbacorporate\local\competency_audits::ITEM_REJECTED,
        ], true)) {
        $competencyoptions[(int)$row->competencyid] =
            ((string)$row->idnumber !== '' ? '[' . s((string)$row->idnumber) . '] ' : '') .
            format_string($row->shortname);
    }
}
$form = null;
if ($competencyoptions) {
    $form = new \local_kbacorporate\form\competency_assessment_form($url, [
        'auditid' => $auditid,
        'competencies' => $competencyoptions,
    ]);
    if ($data = $form->get_data()) {
        $competencyid = (int)$data->competencyid;
        if (!isset($competencyoptions[$competencyid])) {
            throw new moodle_exception('audititemnotfound', 'local_kbacorporate');
        }
        \local_kbacorporate\local\competency_audits::add_assessment(
            $auditid,
            $competencyid,
            (string)$data->method,
            (int)$data->proposedlevel,
            (string)$data->comment,
            '',
            (int)$USER->id
        );
        redirect($url, get_string('assessmentadded', 'local_kbacorporate'), null,
            \core\output\notification::NOTIFY_SUCCESS);
    }
}

// Rebuild after any form processing preparation so display always uses current state.
$projection = \local_kbacorporate\local\competency_reassessment::build($auditid);
$audit = $projection->audit;
$plan = $projection->plan;
$summary = $projection->summary;

$level = static function($value, int $scaleid = 0): string {
    return $value === null
        ? get_string('competencynotassessed', 'local_kbacorporate')
        : \local_kbacorporate\local\competencies::level_label((int)$value, $scaleid);
};
$badge = static function(string $text, string $type = 'secondary'): string {
    return html_writer::span($text, 'badge badge-' . $type);
};

$navigationlinks = [
    html_writer::link(new moodle_url('/local/kbacorporate/development_plan.php', ['id' => (int)$plan->id]),
        '← ' . get_string('developmentplan', 'local_kbacorporate')),
    html_writer::link(new moodle_url('/local/kbacorporate/study_plan_progress.php', ['id' => (int)$plan->id]),
        get_string('studyplanprogresstitle', 'local_kbacorporate')),
    html_writer::link(new moodle_url('/local/kbacorporate/employee_competency_profile.php', [
        'companyid' => (int)$audit->companyid,
        'userid' => (int)$audit->userid,
    ]), get_string('employeecompetencyprofile', 'local_kbacorporate')),
    html_writer::link(new moodle_url('/local/kbacorporate/employee_assessment_history.php', [
        'companyid' => (int)$audit->companyid,
        'userid' => (int)$audit->userid,
    ]), get_string('employeeassessmenthistory', 'local_kbacorporate')),
];
$navigation = html_writer::div(
    implode(html_writer::span('·', 'kbacorp-page-navigation-separator'), $navigationlinks),
    'kbacorp-page-navigation', ['role' => 'navigation']
);

echo $OUTPUT->header();
echo $navigation;
echo $OUTPUT->heading(get_string('reassessmenttitle', 'local_kbacorporate'));
echo html_writer::div(get_string('reassessmentdesc', 'local_kbacorporate'), 'kbacorp-pagelead');
echo html_writer::div(
    html_writer::tag('strong', fullname($audit)) . ' · ' . format_string($plan->title) . ' · ' .
    get_string('status') . ': ' .
    \local_kbacorporate\local\competency_audits::status_label((string)$audit->status),
    'alert alert-info'
);

$cards = [
    [$summary->confirmed . '/' . $summary->total,
        get_string('reassessmentconfirmedcount', 'local_kbacorporate')],
    [$summary->improved, get_string('reassessmentimprovedcount', 'local_kbacorporate')],
    [$summary->targetsreached, get_string('reassessmenttargetscount', 'local_kbacorporate')],
    [$summary->remaininggaps, get_string('reassessmentremaininggapscount', 'local_kbacorporate')],
    [$summary->pending, get_string('reassessmentpendingcount', 'local_kbacorporate')],
    [$summary->missing, get_string('reassessmentmissingcount', 'local_kbacorporate')],
    [$summary->rejected, get_string('reassessmentrejectedcount', 'local_kbacorporate')],
    [$summary->assessmentcoverage . '%', get_string('assessmentcoverage', 'local_kbacorporate')],
];
echo html_writer::start_div('kbacorp-metric-grid kbacorp-metric-grid-4');
foreach ($cards as [$value, $label]) {
    echo html_writer::div(
        html_writer::div(s((string)$value), 'kbacorp-metric-value') .
        html_writer::div(s($label), 'kbacorp-metric-label'),
        'kbacorp-metric-card'
    );
}
echo html_writer::end_div();

$statekey = 'reassessmentstate_' . $projection->state;
$statetype = in_array($projection->state, ['completed'], true) ? 'success' :
    (in_array($projection->state, ['completedwithgaps', 'completedwithrejections'], true) ? 'warning' : 'info');
echo $OUTPUT->notification(get_string($statekey, 'local_kbacorporate', $summary), $statetype);

if (!$canconfirm && $summary->pending > 0) {
    echo $OUTPUT->notification(get_string('auditcorecapabilityrequired', 'local_kbacorporate'), 'warning');
}

echo html_writer::start_div('kbacorp-toolbar');
if ((string)$audit->status === \local_kbacorporate\local\competency_audits::AUDIT_OPEN) {
    echo html_writer::start_tag('form', ['method' => 'post', 'class' => 'd-inline-block']);
    echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'id', 'value' => $auditid]);
    echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'action', 'value' => 'refresh']);
    echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'sesskey', 'value' => sesskey()]);
    echo html_writer::tag('button', get_string('reassessmentrefreshevidence', 'local_kbacorporate'), [
        'type' => 'submit', 'class' => 'btn btn-secondary',
    ]);
    echo html_writer::end_tag('form');
    if ($canconfirm && $summary->pending > 0) {
        echo html_writer::start_tag('form', ['method' => 'post', 'class' => 'd-inline-block']);
        echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'id', 'value' => $auditid]);
        echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'action', 'value' => 'confirmall']);
        echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'sesskey', 'value' => sesskey()]);
        echo html_writer::tag('button', get_string('reassessmentconfirmall', 'local_kbacorporate', $summary->pending), [
            'type' => 'submit', 'class' => 'btn btn-primary',
            'onclick' => 'return confirm(' . json_encode(
                get_string('reassessmentconfirmallquestion', 'local_kbacorporate')
            ) . ');',
        ]);
        echo html_writer::end_tag('form');
    }
}
if ((string)$audit->status === \local_kbacorporate\local\competency_audits::AUDIT_COMPLETED &&
        ($summary->remaininggaps > 0 || $summary->rejected > 0)) {
    echo html_writer::link(new moodle_url('/local/kbacorporate/employee_gap.php', [
        'companyid' => (int)$audit->companyid,
        'userid' => (int)$audit->userid,
    ]), get_string('reassessmentopenremaininggaps', 'local_kbacorporate'), ['class' => 'btn btn-warning']);
    echo html_writer::link(new moodle_url('/local/kbacorporate/gap_learning_plan.php', [
        'companyid' => (int)$audit->companyid,
        'userid' => (int)$audit->userid,
    ]), get_string('reassessmentnextplan', 'local_kbacorporate'), ['class' => 'btn btn-primary']);
}
echo html_writer::link(new moodle_url('/local/kbacorporate/competency_audit.php', ['id' => $auditid]),
    get_string('reassessmentopenauditrecord', 'local_kbacorporate'), ['class' => 'btn btn-outline-secondary']);
echo html_writer::end_div();

if (!$projection->rows) {
    echo $OUTPUT->notification(get_string('reassessmentempty', 'local_kbacorporate'), 'info');
} else {
    $table = new html_table();
    $table->attributes['class'] = 'generaltable kbacorp-report';
    $table->head = [
        get_string('competencylabel', 'local_kbacorporate'),
        get_string('recommendedcourse', 'local_kbacorporate'),
        get_string('reassessmentbefore', 'local_kbacorporate'),
        get_string('reassessmentafter', 'local_kbacorporate'),
        get_string('targetlevel', 'local_kbacorporate'),
        get_string('reassessmentchange', 'local_kbacorporate'),
        get_string('competencygap', 'local_kbacorporate'),
        get_string('collectedevidence', 'local_kbacorporate'),
        get_string('status'),
        get_string('actions', 'local_kbacorporate'),
    ];

    foreach ($projection->rows as $row) {
        $competency = format_string($row->shortname);
        if ($row->idnumber !== '') {
            $competency .= html_writer::div(s($row->idnumber), 'text-muted small');
        }
        $competency .= html_writer::div(
            get_string('rolepriority_' . $row->priority, 'local_kbacorporate') .
            (!empty($row->required) ? ' · ' . get_string('bmsmandatorycriterion', 'local_kbacorporate') : ''),
            'text-muted small'
        );

        $course = $row->courseid > 0
            ? html_writer::link(new moodle_url('/course/view.php', ['id' => $row->courseid]),
                format_string($row->coursename ?: get_string('course')), ['target' => '_blank', 'rel' => 'noopener'])
            : html_writer::span(get_string('nocourseassigned', 'local_kbacorporate'), 'text-muted');
        if ($row->coursecompletedat > 0) {
            $course .= html_writer::div(get_string('reassessmentcoursecompletedat', 'local_kbacorporate',
                userdate($row->coursecompletedat, get_string('strftimedate', 'langconfig'))), 'text-success small');
        }

        $after = '—';
        if ($row->final !== null) {
            $after = $level($row->final, $row->scaleid);
        } else if ($row->proposal !== null) {
            $after = $level($row->proposal, $row->scaleid) . html_writer::div(
                get_string('reassessmentproposal', 'local_kbacorporate'), 'text-warning small');
        } else {
            $after = html_writer::span(get_string('competencynotassessed', 'local_kbacorporate'), 'text-muted');
        }

        $change = '—';
        if ($row->delta !== null) {
            if ($row->delta > 0) {
                $change = $badge('+' . $row->delta, 'success');
            } else if ($row->delta < 0) {
                $change = $badge((string)$row->delta, 'danger');
            } else {
                $change = $badge('0', 'secondary');
            }
        }

        $gap = '—';
        if ($row->final !== null && $row->target !== null) {
            $gap = $row->reached
                ? $badge(get_string('employeegaprow_met', 'local_kbacorporate'), 'success')
                : $badge((string)$row->gap, 'warning');
        }

        $evidence = html_writer::span((string)$row->evidencecount, 'badge badge-secondary');
        if ($row->evidence) {
            $lines = [];
            foreach (array_slice($row->evidence, 0, 3) as $item) {
                $line = s((string)$item->title);
                if (!empty($item->resultvalue)) {
                    $line .= ': ' . s((string)$item->resultvalue);
                }
                if (!empty($item->timeobserved)) {
                    $line .= ' · ' . userdate((int)$item->timeobserved, get_string('strftimedateshort'));
                }
                $lines[] = $line;
            }
            $evidence .= html_writer::alist($lines, ['class' => 'small mb-0']);
        }

        $statusclass = [
            'confirmed' => 'success',
            'pending' => 'warning',
            'missing' => 'secondary',
            'rejected' => 'danger',
        ][$row->status] ?? 'secondary';
        $status = $badge(
            \local_kbacorporate\local\competency_audits::status_label($row->status),
            $statusclass
        );
        $status .= html_writer::div(
            get_string('reassessmentoutcome_' . $row->outcome, 'local_kbacorporate'),
            'text-muted small mt-1'
        );

        $actions = '—';
        if ($row->status === \local_kbacorporate\local\competency_audits::ITEM_PENDING) {
            $parts = [];
            if ($canconfirm) {
                $parts[] = html_writer::start_tag('form', ['method' => 'post', 'class' => 'd-inline-block']) .
                    html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'id', 'value' => $auditid]) .
                    html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'itemid', 'value' => $row->itemid]) .
                    html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'action', 'value' => 'confirm']) .
                    html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'sesskey', 'value' => sesskey()]) .
                    html_writer::tag('button', get_string('confirmauditassessment', 'local_kbacorporate'), [
                        'type' => 'submit', 'class' => 'btn btn-sm btn-success',
                    ]) . html_writer::end_tag('form');
            }
            $parts[] = html_writer::start_tag('form', ['method' => 'post', 'class' => 'd-inline-block']) .
                html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'id', 'value' => $auditid]) .
                html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'itemid', 'value' => $row->itemid]) .
                html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'action', 'value' => 'reject']) .
                html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'sesskey', 'value' => sesskey()]) .
                html_writer::tag('button', get_string('rejectauditassessment', 'local_kbacorporate'), [
                    'type' => 'submit', 'class' => 'btn btn-sm btn-outline-danger',
                ]) . html_writer::end_tag('form');
            $actions = implode(' ', $parts);
        } else if (in_array($row->status, [
                \local_kbacorporate\local\competency_audits::ITEM_MISSING,
                \local_kbacorporate\local\competency_audits::ITEM_REJECTED,
            ], true)) {
            $actions = html_writer::link('#reassessment-form',
                get_string('reassessmentrecordassessment', 'local_kbacorporate'));
        }

        $table->data[] = [
            $competency,
            $course,
            $level($row->baseline, $row->scaleid),
            $after,
            $row->target === null ? '—' : $level($row->target, $row->scaleid),
            $change,
            $gap,
            $evidence,
            $status,
            $actions,
        ];
    }
    echo html_writer::div(html_writer::table($table), 'kbacorp-table-scroll');
}

if ($form) {
    echo html_writer::start_div('mt-4', ['id' => 'reassessment-form']);
    echo $OUTPUT->heading(get_string('reassessmentrecordassessment', 'local_kbacorporate'), 3);
    echo html_writer::div(get_string('reassessmentformnote', 'local_kbacorporate'), 'alert alert-light border');
    $form->display();
    echo html_writer::end_div();
}

echo html_writer::div(get_string('reassessmentsourcetruthnote', 'local_kbacorporate'),
    'alert alert-light border mt-4');
echo $navigation;
echo $OUTPUT->footer();
