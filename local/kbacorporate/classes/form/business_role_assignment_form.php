<?php
namespace local_kbacorporate\form;

defined('MOODLE_INTERNAL') || die();
require_once($CFG->libdir . '/formslib.php');

/** Add one or several business roles to a company employee. */
class business_role_assignment_form extends \moodleform {
    protected function definition() {
        $mform = $this->_form;
        $companyid = (int)$this->_customdata['companyid'];
        $users = $this->_customdata['users'] ?? [];
        $roles = $this->_customdata['roles'] ?? [];
        $defaultuserid = (int)($this->_customdata['userid'] ?? 0);

        $mform->addElement('hidden', 'companyid', $companyid);
        $mform->setType('companyid', PARAM_INT);

        $mform->addElement('autocomplete', 'userid', get_string('employee', 'local_kbacorporate'),
            $users, ['multiple' => false]);
        $mform->setType('userid', PARAM_INT);
        $mform->addRule('userid', null, 'required', null, 'client');
        if ($defaultuserid > 0) {
            $mform->setDefault('userid', $defaultuserid);
        }

        $mform->addElement('autocomplete', 'roleids', get_string('businessroles', 'local_kbacorporate'),
            $roles, ['multiple' => true]);
        $mform->setType('roleids', PARAM_INT);
        $mform->addRule('roleids', null, 'required', null, 'client');

        $mform->addElement('static', 'mergehint', '', get_string('roleassignmentmergehint', 'local_kbacorporate'));
        $this->add_action_buttons(false, get_string('assignbusinessroles', 'local_kbacorporate'));
    }
}
