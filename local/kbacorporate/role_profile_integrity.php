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

$url = new moodle_url('/local/kbacorporate/role_profile_integrity.php', [
    'roleid' => $roleid,
    'companyid' => $companyid,
]);
$PAGE->set_context($context);
$PAGE->set_url($url);
$PAGE->set_pagelayout('admin');
$PAGE->set_title(get_string('roleprofileintegrity', 'local_kbacorporate'));
$PAGE->set_heading(format_string($company->name));

if (data_submitted() && confirm_sesskey()) {
    if (!$canedit) {
        throw new moodle_exception('globalrolereadonly', 'local_kbacorporate');
    }
    $action = required_param('action', PARAM_ALPHANUMEXT);
    if ($action === 'selectivereplace') {
        $selectedids = optional_param_array('selected', [], PARAM_INT);
        $removedomainids = optional_param_array('remove_domain', [], PARAM_INT);
        $selections = [];
        foreach (array_values(array_unique(array_map('intval', $selectedids))) as $competencyid) {
            if ($competencyid <= 0) {
                continue;
            }
            $selections[$competencyid] = [
                'targetlevel' => optional_param('target_' . $competencyid, 0, PARAM_INT),
                'priority' => optional_param('priority_' . $competencyid, 'core', PARAM_ALPHA),
                'required' => optional_param('required_' . $competencyid, 0, PARAM_BOOL),
            ];
        }
        $result = \local_kbacorporate\local\business_roles::replace_domain_competencies_selective(
            $roleid,
            $selections,
            $removedomainids,
            (int)$USER->id
        );
        redirect($url, get_string('roleprofileselectiveresult', 'local_kbacorporate', $result), null,
            \core\output\notification::NOTIFY_SUCCESS);
    }
    // Keep the legacy action route for backward compatibility with old bookmarked forms,
    // but the current UI no longer exposes all-children expansion.
    if ($action === 'expanddomains') {
        $result = \local_kbacorporate\local\business_roles::expand_domain_competencies($roleid, (int)$USER->id);
        redirect($url, get_string('roleprofileexpandresult', 'local_kbacorporate', $result), null,
            \core\output\notification::NOTIFY_SUCCESS);
    }
    throw new moodle_exception('invalidaction');
}

$requirements = \local_kbacorporate\local\business_roles::get_role_competencies($roleid);
$domains = [];
$working = [];
$workingbycomp = [];
foreach ($requirements as $requirement) {
    if (\local_kbacorporate\local\business_roles::is_domain_competency_code((string)$requirement->idnumber)) {
        $domains[] = $requirement;
    } else {
        $working[] = $requirement;
        $workingbycomp[(int)$requirement->competencyid] = $requirement;
    }
}

$coursecovered = 0;
if ($working && $DB->get_manager()->table_exists(new xmldb_table('competency_coursecomp')) &&
        $DB->get_manager()->table_exists(new xmldb_table('local_kbacorp_course'))) {
    $workingids = array_values(array_unique(array_map(static function($item) {
        return (int)$item->competencyid;
    }, $working)));
    list($insql, $params) = $DB->get_in_or_equal($workingids, SQL_PARAMS_NAMED, 'comp');
    $params['companyid'] = $companyid;
    $sql = "SELECT DISTINCT cc.competencyid
              FROM {competency_coursecomp} cc
              JOIN {local_kbacorp_course} kc ON kc.courseid = cc.courseid
             WHERE kc.companyid = :companyid
               AND kc.active = 1
               AND cc.competencyid {$insql}";
    $coursecovered = count($DB->get_fieldset_sql($sql, $params));
}

$domainpreview = [];
foreach ($domains as $domain) {
    $children = $DB->get_records('competency', ['parentid' => (int)$domain->competencyid], 'idnumber ASC, shortname ASC',
        'id,idnumber,shortname,parentid');
    $children = array_values(array_filter($children, static function($child) {
        return !\local_kbacorporate\local\business_roles::is_domain_competency_code((string)$child->idnumber);
    }));
    $domainpreview[] = (object)[
        'requirement' => $domain,
        'children' => $children,
    ];
}

$navigationlinks = [
    html_writer::link(new moodle_url('/local/kbacorporate/index.php', ['companyid' => $companyid]),
        '← ' . get_string('backtodashboard', 'local_kbacorporate')),
    html_writer::link(new moodle_url('/local/kbacorporate/business_roles.php', ['companyid' => $companyid]),
        get_string('businessroles', 'local_kbacorporate')),
    html_writer::link(new moodle_url('/local/kbacorporate/business_role_profile.php', [
        'roleid' => $roleid, 'companyid' => $companyid,
    ]), format_string($role->name)),
    html_writer::link(new moodle_url('/local/kbacorporate/role_learning_matrix.php', [
        'roleid' => $roleid, 'companyid' => $companyid,
    ]), get_string('rolelearningmatrix', 'local_kbacorporate')),
];
$navigation = html_writer::div(implode(html_writer::span('·', 'kbacorp-page-navigation-separator'), $navigationlinks),
    'kbacorp-page-navigation', ['role' => 'navigation']);

echo $OUTPUT->header();
echo $navigation;
echo $OUTPUT->heading(get_string('roleprofileintegrity', 'local_kbacorporate'));
echo html_writer::div(get_string('roleprofileintegritydesc', 'local_kbacorporate'), 'kbacorp-pagelead');
echo $OUTPUT->heading(format_string($role->name), 3);

$cards = [
    [count($working), get_string('roleprofileworkingcompetencies', 'local_kbacorporate')],
    [count($domains), get_string('roleprofiledomainnodes', 'local_kbacorporate')],
    [$coursecovered . '/' . count($working), get_string('roleprofilecoursecoverage', 'local_kbacorporate')],
    [count($domains) === 0 ? get_string('yes') : get_string('no'), get_string('roleprofilegapready', 'local_kbacorporate')],
];
echo html_writer::start_div('kbacorp-metric-grid kbacorp-metric-grid-4');
foreach ($cards as [$value, $label]) {
    echo html_writer::div(html_writer::div(s((string)$value), 'kbacorp-metric-value') .
        html_writer::div(s($label), 'kbacorp-metric-label'), 'kbacorp-metric-card');
}
echo html_writer::end_div();

if (!$domains) {
    echo $OUTPUT->notification(get_string('roleprofileintegrityok', 'local_kbacorporate'), 'success');
} else {
    echo $OUTPUT->notification(get_string('roleprofiledomainwarning', 'local_kbacorporate', count($domains)), 'warning');
    echo $OUTPUT->notification(get_string('roleprofileselectiveintro', 'local_kbacorporate'), 'info');

    if ($canedit) {
        echo html_writer::start_tag('form', ['method' => 'post', 'action' => $url->out(false)]);
        echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'roleid', 'value' => $roleid]);
        echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'companyid', 'value' => $companyid]);
        echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'action', 'value' => 'selectivereplace']);
        echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'sesskey', 'value' => sesskey()]);
    }

    foreach ($domainpreview as $preview) {
        $domain = $preview->requirement;
        $domainlabel = html_writer::tag('strong', s((string)$domain->idnumber)) . ' — ' .
            format_string($domain->shortname);
        echo html_writer::start_div('kbacorp-card mb-3');
        echo html_writer::tag('h4', $domainlabel);
        echo html_writer::div(
            get_string('roleprofileselectivedomainmeta', 'local_kbacorporate', (object)[
                'target' => (int)$domain->targetlevel,
                'priority' => get_string('rolepriority_' . $domain->priority, 'local_kbacorporate'),
                'required' => !empty($domain->required) ? get_string('yes') : get_string('no'),
            ]),
            'kbacorp-muted mb-2'
        );

        if (!$preview->children) {
            echo $OUTPUT->notification(get_string('roleprofiledomainnochildren', 'local_kbacorporate'), 'warning');
            echo html_writer::end_div();
            continue;
        }

        $table = new html_table();
        $table->attributes['class'] = 'generaltable kbacorp-report';
        $table->head = [
            get_string('roleprofileselect', 'local_kbacorporate'),
            get_string('competencyname', 'local_kbacorporate'),
            get_string('targetlevel', 'local_kbacorporate'),
            get_string('rolepriority', 'local_kbacorporate'),
            get_string('rolerequired', 'local_kbacorporate'),
            get_string('status'),
        ];
        foreach ($preview->children as $child) {
            $childid = (int)$child->id;
            $existing = $workingbycomp[$childid] ?? null;
            $selectcell = '';
            if ($canedit && !$existing) {
                $selectcell = html_writer::empty_tag('input', [
                    'type' => 'checkbox',
                    'name' => 'selected[]',
                    'value' => $childid,
                    'aria-label' => get_string('roleprofileselectcompetency', 'local_kbacorporate',
                        format_string($child->shortname)),
                ]);
            } else if ($existing) {
                $selectcell = html_writer::span('✓', 'badge badge-success');
            }

            if ($existing) {
                $targetcell = (int)$existing->targetlevel;
                $prioritycell = get_string('rolepriority_' . $existing->priority, 'local_kbacorporate');
                $requiredcell = !empty($existing->required) ? get_string('yes') : get_string('no');
                $statuscell = html_writer::span(get_string('roleprofilealreadyinprofile', 'local_kbacorporate'),
                    'badge badge-success');
            } else if ($canedit) {
                $targetoptions = '';
                for ($level = 0; $level <= 9; $level++) {
                    $targetoptions .= html_writer::tag('option', (string)$level, [
                        'value' => $level,
                        'selected' => $level === (int)$domain->targetlevel ? 'selected' : null,
                    ]);
                }
                $targetcell = html_writer::tag('select', $targetoptions, [
                    'name' => 'target_' . $childid,
                    'class' => 'custom-select custom-select-sm',
                ]);
                $priorityoptions = '';
                foreach (['critical', 'core', 'additional'] as $priority) {
                    $priorityoptions .= html_writer::tag('option',
                        get_string('rolepriority_' . $priority, 'local_kbacorporate'), [
                            'value' => $priority,
                            'selected' => $priority === (string)$domain->priority ? 'selected' : null,
                        ]);
                }
                $prioritycell = html_writer::tag('select', $priorityoptions, [
                    'name' => 'priority_' . $childid,
                    'class' => 'custom-select custom-select-sm',
                ]);
                $requiredattrs = [
                    'type' => 'checkbox',
                    'name' => 'required_' . $childid,
                    'value' => 1,
                ];
                if (!empty($domain->required)) {
                    $requiredattrs['checked'] = 'checked';
                }
                $requiredcell = html_writer::empty_tag('input', $requiredattrs);
                $statuscell = html_writer::span(get_string('roleprofilecandidate', 'local_kbacorporate'),
                    'badge badge-secondary');
            } else {
                $targetcell = (int)$domain->targetlevel;
                $prioritycell = get_string('rolepriority_' . $domain->priority, 'local_kbacorporate');
                $requiredcell = !empty($domain->required) ? get_string('yes') : get_string('no');
                $statuscell = '';
            }

            $table->data[] = [
                $selectcell,
                html_writer::tag('strong', s((string)$child->idnumber)) . ' — ' . format_string($child->shortname),
                $targetcell,
                $prioritycell,
                $requiredcell,
                $statuscell,
            ];
        }
        echo html_writer::div(html_writer::table($table), 'kbacorp-table-scroll');
        if ($canedit) {
            echo html_writer::label(
                html_writer::empty_tag('input', [
                    'type' => 'checkbox',
                    'id' => 'remove_domain_' . (int)$domain->id,
                    'name' => 'remove_domain[]',
                    'value' => (int)$domain->id,
                    'checked' => 'checked',
                ]) . ' ' . get_string('roleprofileremovedomainafter', 'local_kbacorporate'),
                'remove_domain_' . (int)$domain->id,
                false,
                ['class' => 'd-block mt-2']
            );
        }
        echo html_writer::end_div();
    }

    if ($canedit) {
        echo html_writer::tag('button', get_string('roleprofileapplyselective', 'local_kbacorporate'), [
            'type' => 'submit',
            'class' => 'btn btn-primary',
            'onclick' => 'return confirm(' . json_encode(get_string('roleprofileselectiveconfirm', 'local_kbacorporate')) . ');',
        ]);
        echo html_writer::end_tag('form');
    }
}

echo $navigation;
echo $OUTPUT->footer();
