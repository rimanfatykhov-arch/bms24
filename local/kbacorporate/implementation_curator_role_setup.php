<?php
// Explicit post-upgrade installation of the least-privilege curator role.
require_once(__DIR__ . '/../../config.php');
require_login();
$context = context_system::instance();
require_capability('moodle/role:manage', $context);
$PAGE->set_context($context);
$PAGE->set_url(new moodle_url('/local/kbacorporate/implementation_curator_role_setup.php'));
$PAGE->set_pagelayout('admin');
$PAGE->set_title(get_string('curatorrolesetup', 'local_kbacorporate'));
$PAGE->set_heading(get_string('curatorrolesetup', 'local_kbacorporate'));
if (optional_param('create', 0, PARAM_BOOL)) {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        throw new moodle_exception('invalidrequest', 'error');
    }
    require_sesskey();
    \local_kbacorporate\local\implementation_curator_role::provision_role();
    redirect(new moodle_url('/local/kbacorporate/implementation_curator_role_setup.php'),
        get_string('curatorrolecreated', 'local_kbacorporate'));
}
$role = $DB->get_record('role', [
    'shortname' => \local_kbacorporate\local\implementation_curator_role::ROLENAME,
]);
echo $OUTPUT->header();
echo $OUTPUT->heading(get_string('curatorrolesetup', 'local_kbacorporate'));
echo html_writer::tag('p', get_string('curatorrolesetupdesc', 'local_kbacorporate'));
if ($role) {
    $rolepermission = $DB->get_record('role_capabilities', [
        'roleid' => (int)$role->id,
        'capability' => \local_kbacorporate\local\implementation_curator_role::CAPABILITY,
        'contextid' => $context->id,
    ]);
    if (!$rolepermission || (int)$rolepermission->permission !== CAP_ALLOW) {
        echo $OUTPUT->notification(get_string('curatorrolecapnotassigned', 'local_kbacorporate'), 'warning');
        echo html_writer::link(new moodle_url('/admin/roles/define.php', [
            'action' => 'edit', 'roleid' => (int)$role->id,
        ]), get_string('curatorrolepermissions', 'local_kbacorporate'));
    } else {
        echo $OUTPUT->notification(get_string('curatorrolealreadyexists', 'local_kbacorporate'), 'success');
    }
    echo html_writer::link(new moodle_url('/admin/roles/assign.php', ['contextid' => $context->id]),
        get_string('curatorroleassign', 'local_kbacorporate'), ['class' => 'btn btn-primary']);
} else {
    echo html_writer::start_tag('form', ['method' => 'post']);
    echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'sesskey', 'value' => sesskey()]);
    echo html_writer::tag('button', get_string('curatorrolecreate', 'local_kbacorporate'),
        ['type' => 'submit', 'name' => 'create', 'value' => 1, 'class' => 'btn btn-primary']);
    echo html_writer::end_tag('form');
}
echo $OUTPUT->footer();
