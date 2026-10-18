<?php
require_once(__DIR__ . '/../../config.php');

require_login();
$context = context_system::instance();
require_capability('local/kbacorporate:managecourses', $context);
$companyid = required_param('companyid', PARAM_INT);
$delete = optional_param('delete', 0, PARAM_INT);
$company = $DB->get_record('local_kbacorp_company', ['id' => $companyid], '*', MUST_EXIST);

if ($delete && confirm_sesskey()) {
    \local_kbacorporate\local\repository::delete_course($delete, $companyid);
    redirect(new moodle_url('/local/kbacorporate/courses.php', ['companyid' => $companyid]), get_string('deleted', 'local_kbacorporate'));
}

$PAGE->set_context($context);
$PAGE->set_url(new moodle_url('/local/kbacorporate/courses.php', ['companyid' => $companyid]));
$PAGE->set_pagelayout('admin');
$PAGE->set_title(get_string('courses', 'local_kbacorporate'));
$PAGE->set_heading(format_string($company->name));

$form = new \local_kbacorporate\form\course_form(null, ['companyid' => $companyid]);
if ($data = $form->get_data()) {
    \local_kbacorporate\local\repository::add_course($companyid, (int)$data->courseid, (int)$data->groupid);
    redirect(new moodle_url('/local/kbacorporate/courses.php', ['companyid' => $companyid]), get_string('saved', 'local_kbacorporate'));
}

$mappings = $DB->get_records_sql(
    "SELECT cc.id, cc.courseid, cc.groupid, c.fullname, c.shortname, g.name AS groupname
       FROM {local_kbacorp_course} cc
       JOIN {course} c ON c.id = cc.courseid
  LEFT JOIN {groups} g ON g.id = cc.groupid
      WHERE cc.companyid = :companyid AND cc.active = 1
   ORDER BY c.fullname",
    ['companyid' => $companyid]
);

echo $OUTPUT->header();
echo local_kbacorporate_company_dashboard_navigation($companyid);
echo $OUTPUT->heading(get_string('courses', 'local_kbacorporate') . ': ' . format_string($company->name));
$form->display();
$table = new html_table();
$table->head = [get_string('course', 'local_kbacorporate'), get_string('group', 'local_kbacorporate'), get_string('actions', 'local_kbacorporate')];
foreach ($mappings as $mapping) {
    $deleteurl = new moodle_url('/local/kbacorporate/courses.php', ['companyid' => $companyid, 'delete' => $mapping->id, 'sesskey' => sesskey()]);
    $table->data[] = [
        html_writer::link(new moodle_url('/course/view.php', ['id' => $mapping->courseid]), format_string($mapping->fullname), ['class' => 'kbacorp-course-link']),
        $mapping->groupid ? format_string($mapping->groupname) : get_string('nogroup', 'local_kbacorporate'),
        html_writer::link($deleteurl, get_string('delete', 'local_kbacorporate'), [
            'onclick' => "return confirm(" . json_encode(get_string('confirmdeletecourse', 'local_kbacorporate')) . ");"
        ]),
    ];
}
echo html_writer::table($table);
echo $OUTPUT->single_button(new moodle_url('/local/kbacorporate/licenses_manage.php', ['companyid' => $companyid]), get_string('licensesmanage', 'local_kbacorporate'), 'get');
echo $OUTPUT->single_button(new moodle_url('/local/kbacorporate/companies.php'), get_string('companies', 'local_kbacorporate'), 'get');
echo $OUTPUT->footer();
