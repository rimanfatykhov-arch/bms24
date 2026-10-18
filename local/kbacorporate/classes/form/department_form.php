<?php
namespace local_kbacorporate\form;

defined('MOODLE_INTERNAL') || die();
require_once($CFG->libdir . '/formslib.php');

class department_form extends \moodleform {
    protected function definition() {
        $mform = $this->_form;
        $companyid = (int)$this->_customdata['companyid'];
        $mform->addElement('hidden', 'id', 0);
        $mform->setType('id', PARAM_INT);
        $mform->addElement('hidden', 'companyid', $companyid);
        $mform->setType('companyid', PARAM_INT);
        $mform->addElement('text', 'name', get_string('departmentname', 'local_kbacorporate'), ['size' => 60]);
        $mform->setType('name', PARAM_TEXT);
        $mform->addRule('name', null, 'required', null, 'client');
        $mform->addElement('text', 'code', get_string('departmentcode', 'local_kbacorporate'), ['size' => 30]);
        $mform->setType('code', PARAM_ALPHANUMEXT);
        $mform->addElement('advcheckbox', 'active', get_string('active', 'local_kbacorporate'));
        $mform->setDefault('active', 1);
        $this->add_action_buttons(true, get_string('savechanges'));
    }
}
