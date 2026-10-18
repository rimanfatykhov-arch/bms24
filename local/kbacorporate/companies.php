<?php
require_once(__DIR__ . '/../../config.php');

require_login();
$context = context_system::instance();
require_capability('local/kbacorporate:managecompanies', $context);

$filtercompanyid = optional_param('filtercompanyid', 0, PARAM_INT);
$statusfilter = optional_param('status', 'all', PARAM_ALPHA);
$query = trim((string)optional_param('q', '', PARAM_TEXT));
if (!in_array($statusfilter, ['all', 'active', 'inactive'], true)) {
    $statusfilter = 'all';
}

$delete = optional_param('delete', 0, PARAM_INT);
if ($delete && confirm_sesskey()) {
    $company = $DB->get_record('local_kbacorp_company', ['id' => $delete], '*', MUST_EXIST);
    \local_kbacorporate\local\repository::delete_company($company->id);
    redirect(new moodle_url('/local/kbacorporate/companies.php'), get_string('deleted', 'local_kbacorporate'));
}

$PAGE->set_context($context);
$pageparams = ['filtercompanyid' => $filtercompanyid, 'status' => $statusfilter, 'q' => $query];
$PAGE->set_url(new moodle_url('/local/kbacorporate/companies.php', $pageparams));
$PAGE->set_pagelayout('admin');
$PAGE->set_title(get_string('companies', 'local_kbacorporate'));
$PAGE->set_heading(get_string('pluginname', 'local_kbacorporate'));

$allcompanies = array_values($DB->get_records('local_kbacorp_company', [], 'name ASC'));
$companies = $allcompanies;
if ($filtercompanyid > 0) {
    $companies = array_values(array_filter($companies, static function($company) use ($filtercompanyid) {
        return (int)$company->id === $filtercompanyid;
    }));
}
if ($statusfilter !== 'all') {
    $active = $statusfilter === 'active';
    $companies = array_values(array_filter($companies, static function($company) use ($active) {
        return !empty($company->active) === $active;
    }));
}
$normalisedquery = \core_text::strtolower($query);
if ($normalisedquery !== '') {
    $companies = array_values(array_filter($companies, static function($company) use ($normalisedquery) {
        $haystack = \core_text::strtolower((string)$company->name . ' ' . (string)($company->code ?? '') .
            ' ' . (string)($company->bitrixcompanyid ?? '') . ' ' . (string)($company->primaryactivity ?? '') .
            ' ' . (string)($company->foundedyear ?? ''));
        return strpos($haystack, $normalisedquery) !== false;
    }));
}

echo $OUTPUT->header();
echo $OUTPUT->heading(get_string('companies', 'local_kbacorporate'));

echo html_writer::div(
    $OUTPUT->single_button(new moodle_url('/local/kbacorporate/company_edit.php'), get_string('addcompany', 'local_kbacorporate'), 'get') .
    ' ' . $OUTPUT->single_button(new moodle_url('/local/kbacorporate/index.php'), get_string('backtodashboard', 'local_kbacorporate'), 'get'),
    'kbacorp-toolbar'
);

$companyoptions = [0 => get_string('allcompanies', 'local_kbacorporate')];
foreach ($allcompanies as $item) {
    $companyoptions[(int)$item->id] = format_string($item->name);
}
$statusoptions = [
    'all' => get_string('allstatuses', 'local_kbacorporate'),
    'active' => get_string('activecompanies', 'local_kbacorporate'),
    'inactive' => get_string('inactivecompanies', 'local_kbacorporate'),
];
echo html_writer::start_div('kbacorp-company-filterpanel');
echo html_writer::start_tag('form', ['method' => 'get', 'class' => 'kbacorp-filters kbacorp-company-filters']);
echo html_writer::select($companyoptions, 'filtercompanyid', $filtercompanyid, false,
    ['aria-label' => get_string('company', 'local_kbacorporate')]);
echo html_writer::select($statusoptions, 'status', $statusfilter, false,
    ['aria-label' => get_string('status')]);
echo html_writer::empty_tag('input', ['type' => 'search', 'name' => 'q', 'value' => $query,
    'placeholder' => get_string('companysearchplaceholder', 'local_kbacorporate'),
    'aria-label' => get_string('search')]);
echo html_writer::tag('button', get_string('filter'), ['type' => 'submit', 'class' => 'btn btn-primary']);
echo html_writer::link(new moodle_url('/local/kbacorporate/companies.php'),
    get_string('resetfilters', 'local_kbacorporate'), ['class' => 'btn btn-secondary']);
echo html_writer::end_tag('form');
echo html_writer::div(get_string('companiesfilterresult', 'local_kbacorporate', (object)[
    'shown' => count($companies), 'total' => count($allcompanies),
]), 'kbacorp-company-filter-result');
echo html_writer::end_div();

$table = new html_table();
$table->head = [get_string('companyname', 'local_kbacorporate'),
    get_string('companyprimaryactivity', 'local_kbacorporate'),
    get_string('companyfoundedyear', 'local_kbacorporate'), get_string('companycode', 'local_kbacorporate'),
    get_string('bitrixcompanyid', 'local_kbacorporate'), get_string('active', 'local_kbacorporate'),
    get_string('alertsenabled', 'local_kbacorporate'), get_string('actions', 'local_kbacorporate')];
foreach ($companies as $company) {
    $actions = [];
    $actions[] = html_writer::link(new moodle_url('/local/kbacorporate/company_edit.php', ['id' => $company->id]), get_string('edit'));
    $actions[] = html_writer::link(new moodle_url('/local/kbacorporate/members.php', ['companyid' => $company->id]), get_string('members', 'local_kbacorporate'));
    $actions[] = html_writer::link(new moodle_url('/local/kbacorporate/departments.php', ['companyid' => $company->id]), get_string('departments', 'local_kbacorporate'));
    $actions[] = html_writer::link(new moodle_url('/local/kbacorporate/courses.php', ['companyid' => $company->id]), get_string('courses', 'local_kbacorporate'));
    $actions[] = html_writer::link(new moodle_url('/local/kbacorporate/licenses_manage.php', ['companyid' => $company->id]), get_string('licenses', 'local_kbacorporate'));
    $actions[] = html_writer::link(new moodle_url('/local/kbacorporate/alerts.php', ['companyid' => $company->id]), get_string('attentioncenter', 'local_kbacorporate'));
    if (has_capability('local/kbacorporate:managerolecompetencies', $context)) {
        $actions[] = html_writer::link(
            new moodle_url('/local/kbacorporate/business_roles.php', ['companyid' => $company->id]),
            get_string('businessroles', 'local_kbacorporate')
        );
    }
    $deleteurl = new moodle_url('/local/kbacorporate/companies.php', ['delete' => $company->id, 'sesskey' => sesskey()]);
    $actions[] = html_writer::link($deleteurl, get_string('delete', 'local_kbacorporate'), [
        'onclick' => "return confirm(" . json_encode(get_string('confirmdeletecompany', 'local_kbacorporate', $company->name)) . ");"
    ]);
    $table->data[] = [
        format_string($company->name),
        $company->primaryactivity ? format_string($company->primaryactivity) : '—',
        !empty($company->foundedyear) ? (int)$company->foundedyear : '—',
        s($company->code ?? ''),
        s($company->bitrixcompanyid ?? ''),
        $company->active ? get_string('yes') : get_string('no'),
        !empty($company->alertsenabled)
            ? get_string('yes') . ' · ' . get_string('alertfrequency_' .
                \local_kbacorporate\local\attention_notifier::normalise_frequency((string)$company->alertfrequency),
                'local_kbacorporate')
            : get_string('no'),
        implode(' · ', $actions),
    ];
}
if (!$companies) {
    echo $OUTPUT->notification(get_string('nocompaniesmatchfilter', 'local_kbacorporate'), 'info');
} else {
    echo html_writer::div(html_writer::table($table), 'kbacorp-table-scroll kbacorp-companies-table');
}
echo $OUTPUT->footer();
