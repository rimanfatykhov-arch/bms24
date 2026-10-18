<?php
require_once(__DIR__ . '/../../config.php');

require_login();
$context = context_system::instance();
require_capability('local/kbacorporate:managecompetencyaudits', $context);
$auditid = required_param('id', PARAM_INT);
$confirmitem = optional_param('confirmitem', 0, PARAM_INT);
$rejectitem = optional_param('rejectitem', 0, PARAM_INT);
$confirmall = optional_param('confirmall', 0, PARAM_BOOL);
$audit = \local_kbacorporate\local\competency_audits::get_audit($auditid);
$company = \local_kbacorporate\local\access::require_full_company_access((int)$audit->companyid, $USER->id);
$items = \local_kbacorporate\local\competency_audits::get_items($auditid);
$itemids = array_map(static function($item) { return (int)$item->id; }, $items);
$canconfirm = has_capability(
    'moodle/competency:competencygrade',
    context_user::instance((int)$audit->userid)
);

$baseurl = new moodle_url('/local/kbacorporate/competency_audit.php', ['id' => $auditid]);
$PAGE->set_context($context);
$PAGE->set_url($baseurl);
$PAGE->set_pagelayout('admin');
$PAGE->set_title(format_string($audit->title));
$PAGE->set_heading(format_string($company->name));

if ($confirmitem || $rejectitem || $confirmall) {
    require_sesskey();
    if ($confirmall) {
        require_capability('moodle/competency:competencygrade', context_user::instance((int)$audit->userid));
        $confirmed = 0;
        foreach ($items as $item) {
            if ($item->status === \local_kbacorporate\local\competency_audits::ITEM_PENDING) {
                \local_kbacorporate\local\competency_audits::confirm_assessment((int)$item->id, $USER->id);
                $confirmed++;
            }
        }
        redirect($baseurl, get_string('auditbulkconfirmed', 'local_kbacorporate', $confirmed));
    }
    $itemid = $confirmitem ?: $rejectitem;
    if (!in_array($itemid, $itemids, true)) {
        throw new moodle_exception('audititemnotfound', 'local_kbacorporate');
    }
    if ($confirmitem) {
        require_capability('moodle/competency:competencygrade', context_user::instance((int)$audit->userid));
        \local_kbacorporate\local\competency_audits::confirm_assessment($itemid, $USER->id);
        redirect($baseurl, get_string('auditconfirmed', 'local_kbacorporate'));
    }
    \local_kbacorporate\local\competency_audits::reject_assessment($itemid, $USER->id);
    redirect($baseurl, get_string('auditrejected', 'local_kbacorporate'));
}
$evidencebyitem = \local_kbacorporate\local\competency_audits::get_evidence_for_audit($auditid);

$competencies = $DB->get_records_sql(
    "SELECT c.id, c.shortname, c.idnumber, f.shortname AS frameworkname
       FROM {competency} c
  LEFT JOIN {competency_framework} f ON f.id = c.competencyframeworkid
   ORDER BY f.shortname, c.shortname"
);
$competencyoptions = [];
$missingids = [];
foreach ($items as $item) {
    if ($item->status === \local_kbacorporate\local\competency_audits::ITEM_MISSING) {
        $missingids[(int)$item->competencyid] = true;
    }
}
foreach ($competencies as $competency) {
    if ($audit->audittype === \local_kbacorporate\local\competency_audits::AUDIT_TYPE_ROLE &&
            !isset($missingids[(int)$competency->id])) {
        continue;
    }
    $competencyoptions[(int)$competency->id] = ($competency->frameworkname ?
        '[' . format_string($competency->frameworkname) . '] ' : '') . format_string($competency->shortname);
}
$form = null;
if ($audit->status === \local_kbacorporate\local\competency_audits::AUDIT_OPEN && $competencyoptions) {
    $form = new \local_kbacorporate\form\competency_assessment_form(null, [
        'auditid' => $auditid,
        'competencies' => $competencyoptions,
    ]);
    if ($data = $form->get_data()) {
        \local_kbacorporate\local\competency_audits::add_assessment(
            $auditid, (int)$data->competencyid, (string)$data->method, (int)$data->proposedlevel,
            (string)$data->comment, '', $USER->id
        );
        redirect($baseurl, get_string('assessmentadded', 'local_kbacorporate'));
    }
}

echo $OUTPUT->header();
echo local_kbacorporate_company_dashboard_navigation((int)$audit->companyid);
echo html_writer::div(html_writer::link(new moodle_url('/local/kbacorporate/competency_audits.php', [
    'companyid' => $audit->companyid,
]), '← ' . get_string('competencyaudits', 'local_kbacorporate')), 'kbacorp-backlink');
echo $OUTPUT->heading(format_string($audit->title));
echo html_writer::div(fullname($audit) . ' · ' . format_string($company->name), 'kbacorp-pagelead');
if ($audit->description) {
    echo html_writer::div(format_text($audit->description, FORMAT_PLAIN), 'kbacorp-audit-description');
}
echo html_writer::div(get_string('status') . ': ' .
    \local_kbacorporate\local\competency_audits::status_label($audit->status) . ' · ' .
    get_string('audittype', 'local_kbacorporate') . ': ' .
    \local_kbacorporate\local\competency_audits::audit_type_label($audit->audittype), 'kbacorp-role-scope');
if ($audit->audittype === \local_kbacorporate\local\competency_audits::AUDIT_TYPE_ROLE) {
    echo $OUTPUT->notification(get_string('automatedauditnote', 'local_kbacorporate'), 'info');
}
if ($audit->audittype === \local_kbacorporate\local\competency_audits::AUDIT_TYPE_DEVELOPMENT) {
    echo $OUTPUT->notification(get_string('reassessmentauditrecordnote', 'local_kbacorporate'), 'info');
    echo html_writer::div(html_writer::link(new moodle_url(
        '/local/kbacorporate/competency_reassessment.php', ['id' => $auditid]
    ), get_string('reassessmentopen', 'local_kbacorporate'), ['class' => 'btn btn-primary']), 'mb-3');
}
if (!$canconfirm && array_filter($items, static function($item) {
        return $item->status === \local_kbacorporate\local\competency_audits::ITEM_PENDING;
    })) {
    echo $OUTPUT->notification(get_string('auditcorecapabilityrequired', 'local_kbacorporate'), 'warning');
}
echo html_writer::div(html_writer::link(new moodle_url('/local/kbacorporate/mycompetencies.php', [
    'companyid' => $audit->companyid, 'userid' => $audit->userid,
]), get_string('viewemployeecompetencies', 'local_kbacorporate') . ' →'), 'kbacorp-viewall');

echo $OUTPUT->heading(get_string('auditassessments', 'local_kbacorporate'), 3);
$pendingitems = array_filter($items, static function($item) {
    return $item->status === \local_kbacorporate\local\competency_audits::ITEM_PENDING;
});
if ($canconfirm && $pendingitems) {
    $confirmallurl = new moodle_url('/local/kbacorporate/competency_audit.php', [
        'id' => $auditid, 'confirmall' => 1, 'sesskey' => sesskey(),
    ]);
    echo html_writer::div(html_writer::link(
        $confirmallurl,
        get_string('confirmallproposals', 'local_kbacorporate', count($pendingitems)),
        ['class' => 'btn btn-primary',
            'onclick' => 'return confirm(' . json_encode(
                get_string('confirmallproposalsquestion', 'local_kbacorporate')
            ) . ');']
    ), 'mb-3');
}
if (!$items) {
    echo $OUTPUT->notification(get_string('noassessments', 'local_kbacorporate'), 'info');
} else {
    $table = new html_table();
    $table->attributes['class'] = 'generaltable kbacorp-report kbacorp-audit-items';
    $table->head = [get_string('competencyname', 'local_kbacorporate'),
        get_string('roletargetlevel', 'local_kbacorporate'),
        get_string('auditcollectedlevel', 'local_kbacorporate'),
        get_string('rolepriority', 'local_kbacorporate'), get_string('collectedevidence', 'local_kbacorporate'),
        get_string('status'), get_string('actions', 'local_kbacorporate')];
    foreach ($items as $item) {
        $scaleid = (int)($item->competencyscaleid ?: $item->frameworkscaleid);
        $actions = '—';
        if ($item->status === \local_kbacorporate\local\competency_audits::ITEM_PENDING) {
            $rejecturl = new moodle_url('/local/kbacorporate/competency_audit.php', [
                'id' => $auditid, 'rejectitem' => $item->id, 'sesskey' => sesskey(),
            ]);
            $actions = '';
            if ($canconfirm) {
                $confirmurl = new moodle_url('/local/kbacorporate/competency_audit.php', [
                    'id' => $auditid, 'confirmitem' => $item->id, 'sesskey' => sesskey(),
                ]);
                $actions = html_writer::link($confirmurl,
                    get_string('confirmauditassessment', 'local_kbacorporate'), [
                        'onclick' => 'return confirm(' . json_encode(get_string('confirmauditassessmentquestion',
                            'local_kbacorporate')) . ');',
                    ]) . ' · ';
            }
            $actions .= html_writer::link($rejecturl, get_string('rejectauditassessment', 'local_kbacorporate'), [
                'onclick' => 'return confirm(' . json_encode(get_string('rejectauditassessmentquestion',
                    'local_kbacorporate')) . ');',
            ]);
        }
        $evidencehtml = '—';
        if (!empty($evidencebyitem[(int)$item->id])) {
            $evidencelines = [];
            foreach ($evidencebyitem[(int)$item->id] as $evidence) {
                $line = s($evidence->title);
                if ($evidence->resultvalue) {
                    $line .= ': ' . s($evidence->resultvalue);
                }
                if ($evidence->timeobserved) {
                    $line .= ' · ' . userdate((int)$evidence->timeobserved, get_string('strftimedateshort'));
                }
                if ($evidence->url) {
                    $line .= ' · ' . html_writer::link($evidence->url, get_string('open'), [
                        'target' => '_blank', 'rel' => 'noopener',
                    ]);
                }
                $evidencelines[] = $line;
            }
            $evidencehtml = html_writer::alist($evidencelines);
        }
        $targetlabel = $item->targetlevel === null ? '—' :
            s(\local_kbacorporate\local\competencies::level_label((int)$item->targetlevel, $scaleid));
        $proposedlabel = $item->status === \local_kbacorporate\local\competency_audits::ITEM_MISSING
            ? get_string('competencynotassessed', 'local_kbacorporate')
            : s(\local_kbacorporate\local\competencies::level_label((int)$item->proposedlevel, $scaleid));
        $table->data[] = [format_string($item->shortname),
            $targetlabel,
            $proposedlabel,
            get_string('rolepriority_' . $item->priority, 'local_kbacorporate'),
            $evidencehtml,
            \local_kbacorporate\local\competency_audits::status_label($item->status),
            $actions];
    }
    echo html_writer::table($table);
}
if ($form) {
    echo $OUTPUT->heading(get_string('addassessment', 'local_kbacorporate'), 3);
    $form->display();
}
echo $OUTPUT->footer();
