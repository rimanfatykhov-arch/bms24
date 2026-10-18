<?php
require_once(__DIR__ . '/../../config.php');

require_login();
$context = context_system::instance();
require_capability('local/kbacorporate:managerolecompetencies', $context);

$roleid = required_param('roleid', PARAM_INT);
$companyid = required_param('companyid', PARAM_INT);
$role = \local_kbacorporate\local\business_roles::get_role($roleid);
$isglobal = (int)$role->companyid === 0;
if (!$isglobal && (int)$role->companyid !== $companyid) {
    throw new moodle_exception('companynotallowed', 'local_kbacorporate');
}
$company = \local_kbacorporate\local\access::require_full_company_access($companyid, $USER->id);
$canedit = !$isglobal || is_siteadmin();

$url = new moodle_url('/local/kbacorporate/role_profile_bulk_edit.php', [
    'roleid' => $roleid,
    'companyid' => $companyid,
]);
$PAGE->set_context($context);
$PAGE->set_url($url);
$PAGE->set_pagelayout('admin');
$PAGE->set_title(get_string('roleprofilebulkedit', 'local_kbacorporate'));
$PAGE->set_heading(format_string($company->name));

if (data_submitted() && confirm_sesskey()) {
    if (!$canedit) {
        throw new moodle_exception('globalrolereadonly', 'local_kbacorporate');
    }
    $action = required_param('action', PARAM_ALPHANUMEXT);
    if ($action !== 'saveprofile') {
        throw new moodle_exception('invalidaction');
    }

    $requirements = \local_kbacorporate\local\business_roles::get_role_competencies($roleid);
    $updates = [];
    foreach ($requirements as $requirement) {
        if (\local_kbacorporate\local\business_roles::is_domain_competency_code((string)$requirement->idnumber)) {
            continue;
        }
        $rowid = (int)$requirement->id;
        $updates[$rowid] = [
            'targetlevel' => optional_param('target_' . $rowid, (int)$requirement->targetlevel, PARAM_INT),
            'priority' => optional_param('priority_' . $rowid, (string)$requirement->priority, PARAM_ALPHA),
            'required' => optional_param('required_' . $rowid, 0, PARAM_BOOL),
        ];
    }

    $result = \local_kbacorporate\local\business_roles::bulk_update_role_competencies(
        $roleid,
        $updates,
        get_string('roleprofilebulkchangenote', 'local_kbacorporate'),
        (int)$USER->id
    );
    redirect(
        $url,
        get_string('roleprofilebulkresult', 'local_kbacorporate', $result),
        null,
        \core\output\notification::NOTIFY_SUCCESS
    );
}

$requirements = \local_kbacorporate\local\business_roles::get_role_competencies($roleid);
$working = array_values(array_filter($requirements, static function($requirement) {
    return !\local_kbacorporate\local\business_roles::is_domain_competency_code((string)$requirement->idnumber);
}));
$domains = count($requirements) - count($working);
$mandatory = count(array_filter($working, static function($item) {
    return !empty($item->required);
}));
$supporting = count($working) - $mandatory;
$critical = count(array_filter($working, static function($item) {
    return (string)$item->priority === \local_kbacorporate\local\business_roles::PRIORITY_CRITICAL;
}));

$navigationlinks = [
    html_writer::link(new moodle_url('/local/kbacorporate/index.php', ['companyid' => $companyid]),
        '← ' . get_string('backtodashboard', 'local_kbacorporate')),
    html_writer::link(new moodle_url('/local/kbacorporate/business_roles.php', ['companyid' => $companyid]),
        get_string('businessroles', 'local_kbacorporate')),
    html_writer::link(new moodle_url('/local/kbacorporate/business_role_profile.php', [
        'roleid' => $roleid, 'companyid' => $companyid,
    ]), format_string($role->name)),
    html_writer::link(new moodle_url('/local/kbacorporate/role_profile_integrity.php', [
        'roleid' => $roleid, 'companyid' => $companyid,
    ]), get_string('roleprofileintegrity', 'local_kbacorporate')),
    html_writer::link(new moodle_url('/local/kbacorporate/role_learning_matrix.php', [
        'roleid' => $roleid, 'companyid' => $companyid,
    ]), get_string('rolelearningmatrix', 'local_kbacorporate')),
];
$navigation = html_writer::div(
    implode(html_writer::span('·', 'kbacorp-page-navigation-separator'), $navigationlinks),
    'kbacorp-page-navigation',
    ['role' => 'navigation']
);

echo $OUTPUT->header();
echo $navigation;
echo $OUTPUT->heading(get_string('roleprofilebulkedit', 'local_kbacorporate'));
echo html_writer::div(get_string('roleprofilebulkeditdesc', 'local_kbacorporate'), 'kbacorp-pagelead');
echo $OUTPUT->heading(format_string($role->name), 3);

$cards = [
    [count($working), get_string('roleprofileworkingcompetencies', 'local_kbacorporate')],
    [$mandatory, get_string('roleprofilemandatorycount', 'local_kbacorporate')],
    [$supporting, get_string('roleprofilesupportingcount', 'local_kbacorporate')],
    [$critical, get_string('roleprofilecriticalcount', 'local_kbacorporate')],
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

if ($domains > 0) {
    echo $OUTPUT->notification(
        get_string('roleprofilebulkdomainwarning', 'local_kbacorporate', $domains) . ' ' .
        html_writer::link(new moodle_url('/local/kbacorporate/role_profile_integrity.php', [
            'roleid' => $roleid, 'companyid' => $companyid,
        ]), get_string('roleprofileopenintegrity', 'local_kbacorporate')),
        'warning'
    );
}

if (!$working) {
    echo $OUTPUT->notification(get_string('roleprofilebulknorows', 'local_kbacorporate'), 'info');
    echo $navigation;
    echo $OUTPUT->footer();
    exit;
}

if (!$canedit) {
    echo $OUTPUT->notification(get_string('globalrolereadonly', 'local_kbacorporate'), 'info');
}

echo $OUTPUT->notification(get_string('roleprofilebulksafetynote', 'local_kbacorporate'), 'info');

if ($canedit) {
    echo html_writer::start_tag('form', ['method' => 'post', 'action' => $url->out(false)]);
    echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'roleid', 'value' => $roleid]);
    echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'companyid', 'value' => $companyid]);
    echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'action', 'value' => 'saveprofile']);
    echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'sesskey', 'value' => sesskey()]);
}

$table = new html_table();
$table->attributes['class'] = 'generaltable kbacorp-report';
$table->head = [
    get_string('competencylabel', 'local_kbacorporate'),
    get_string('targetlevel', 'local_kbacorporate'),
    get_string('rolepriority', 'local_kbacorporate'),
    get_string('rolerequired', 'local_kbacorporate'),
    get_string('roleprofilesource', 'local_kbacorporate'),
];

foreach ($working as $requirement) {
    $rowid = (int)$requirement->id;
    $competencylabel = html_writer::tag('strong', s((string)$requirement->idnumber)) . ' — ' .
        format_string($requirement->shortname);

    if ($canedit) {
        $targetoptions = '';
        for ($level = 0; $level <= 9; $level++) {
            $attrs = ['value' => $level];
            if ($level === (int)$requirement->targetlevel) {
                $attrs['selected'] = 'selected';
            }
            $targetoptions .= html_writer::tag('option', (string)$level, $attrs);
        }
        $targetcell = html_writer::tag('select', $targetoptions, [
            'name' => 'target_' . $rowid,
            'class' => 'custom-select custom-select-sm',
            'aria-label' => get_string('roleprofilebulktargetaria', 'local_kbacorporate',
                format_string($requirement->shortname)),
        ]);

        $priorityoptions = '';
        foreach (['critical', 'core', 'additional'] as $priority) {
            $attrs = ['value' => $priority];
            if ($priority === (string)$requirement->priority) {
                $attrs['selected'] = 'selected';
            }
            $priorityoptions .= html_writer::tag(
                'option',
                get_string('rolepriority_' . $priority, 'local_kbacorporate'),
                $attrs
            );
        }
        $prioritycell = html_writer::tag('select', $priorityoptions, [
            'name' => 'priority_' . $rowid,
            'class' => 'custom-select custom-select-sm',
            'aria-label' => get_string('roleprofilebulkpriorityaria', 'local_kbacorporate',
                format_string($requirement->shortname)),
        ]);

        $requiredattrs = [
            'type' => 'checkbox',
            'name' => 'required_' . $rowid,
            'value' => 1,
            'aria-label' => get_string('roleprofilebulkrequiredaria', 'local_kbacorporate',
                format_string($requirement->shortname)),
        ];
        if (!empty($requirement->required)) {
            $requiredattrs['checked'] = 'checked';
        }
        $requiredcell = html_writer::empty_tag('input', $requiredattrs);
    } else {
        $targetcell = (int)$requirement->targetlevel;
        $prioritycell = get_string('rolepriority_' . $requirement->priority, 'local_kbacorporate');
        $requiredcell = !empty($requirement->required) ? get_string('yes') : get_string('no');
    }

    $table->data[] = [
        $competencylabel,
        $targetcell,
        $prioritycell,
        $requiredcell,
        s((string)$requirement->source),
    ];
}

echo html_writer::div(html_writer::table($table), 'kbacorp-table-scroll');

if ($canedit) {
    echo html_writer::tag('button', get_string('roleprofilebulksave', 'local_kbacorporate'), [
        'type' => 'submit',
        'class' => 'btn btn-primary',
        'onclick' => 'return confirm(' . json_encode(get_string('roleprofilebulkconfirm', 'local_kbacorporate')) . ');',
    ]);
    echo html_writer::end_tag('form');
}

echo $navigation;
echo $OUTPUT->footer();
