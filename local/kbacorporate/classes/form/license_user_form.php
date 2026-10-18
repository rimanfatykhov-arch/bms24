<?php
namespace local_kbacorporate\form;

defined('MOODLE_INTERNAL') || die();
require_once($CFG->libdir . '/formslib.php');

/** Administrator form for assigning one employee to a licence package. */
class license_user_form extends \moodleform {
    protected function definition() {
        global $DB;

        $mform = $this->_form;
        $license = $this->_customdata['license'];
        $mform->addElement('hidden', 'licenseid', (int)$license->id);
        $mform->setType('licenseid', PARAM_INT);
        $mform->addElement('hidden', 'companyid', (int)$license->companyid);
        $mform->setType('companyid', PARAM_INT);

        $sql = "SELECT u.id, u.firstname, u.lastname, u.email
                  FROM {local_kbacorp_member} m
                  JOIN {user} u ON u.id = m.userid
                 WHERE m.companyid = :companyid
                   AND m.isemployee = :isemployee
                   AND u.deleted = 0
              ORDER BY u.lastname, u.firstname";
        $records = $DB->get_records_sql($sql, [
            'companyid' => (int)$license->companyid,
            'isemployee' => 1,
        ]);
        $users = [];
        foreach ($records as $user) {
            $existing = \local_kbacorporate\local\licenses::get_active_course_allocation(
                (int)$license->companyid,
                (int)$license->courseid,
                (int)$license->groupid,
                (int)$user->id
            );
            if ($existing && (int)$existing->licenseid !== (int)$license->id) {
                continue;
            }
            $users[$user->id] = fullname($user) . ' — ' . $user->email;
        }
        $mform->addElement('autocomplete', 'userid', get_string('employee', 'local_kbacorporate'), $users);
        $mform->setType('userid', PARAM_INT);
        $mform->addRule('userid', null, 'required', null, 'client');
        $this->add_action_buttons(false, get_string('assignlicenseuser', 'local_kbacorporate'));
    }
}
