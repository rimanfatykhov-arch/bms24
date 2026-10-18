<?php
namespace local_kbacorporate\form;

defined('MOODLE_INTERNAL') || die();
require_once($CFG->libdir . '/formslib.php');

class member_form extends \moodleform {
    protected function definition() {
        global $DB;
        $mform = $this->_form;
        $companyid = (int)$this->_customdata['companyid'];
        $mform->addElement('hidden', 'companyid', $companyid);
        $mform->setType('companyid', PARAM_INT);

        $users = [];
        $records = $DB->get_records_select('user', 'deleted = 0 AND id > 1', [], 'lastname ASC, firstname ASC', 'id,firstname,lastname,email');
        foreach ($records as $user) {
            $users[$user->id] = fullname($user) . ' — ' . $user->email;
        }
        $mform->addElement('autocomplete', 'userid', get_string('user', 'local_kbacorporate'), $users, ['multiple' => false]);
        $mform->addRule('userid', null, 'required', null, 'client');
        $mform->addElement('select', 'membertype', get_string('membertype', 'local_kbacorporate'), [
            'employee' => get_string('employee', 'local_kbacorporate'),
            'manager' => get_string('manager', 'local_kbacorporate'),
        ]);
        $this->add_action_buttons(false, get_string('addmember', 'local_kbacorporate'));
    }
}
