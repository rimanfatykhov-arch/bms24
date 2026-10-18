<?php
namespace local_kbacorporate\form;

defined('MOODLE_INTERNAL') || die();
require_once($CFG->libdir . '/formslib.php');

/** Create an employee competency audit. */
class competency_audit_form extends \moodleform {
    protected function definition() {
        $mform = $this->_form;
        $companyid = (int)$this->_customdata['companyid'];
        $users = $this->_customdata['users'] ?? [];
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
        $mform->addElement('text', 'title', get_string('audittitle', 'local_kbacorporate'), ['size' => 60]);
        $mform->setType('title', PARAM_TEXT);
        $mform->addRule('title', get_string('maximumchars', '', 255), 'maxlength', 255, 'client');
        $mform->addElement('advcheckbox', 'fillfromroles',
            get_string('fillauditfromroles', 'local_kbacorporate'));
        $mform->setDefault('fillfromroles', 1);
        $mform->addHelpButton('fillfromroles', 'fillauditfromroles', 'local_kbacorporate');
        $mform->addElement('textarea', 'description', get_string('auditdescription', 'local_kbacorporate'),
            ['rows' => 4, 'cols' => 60]);
        $mform->setType('description', PARAM_TEXT);
        $this->add_action_buttons(true, get_string('createautomatedaudit', 'local_kbacorporate'));
    }

    public function validation($data, $files) {
        $errors = parent::validation($data, $files);
        if (empty($data['fillfromroles']) && trim((string)($data['title'] ?? '')) === '') {
            $errors['title'] = get_string('required');
        }
        return $errors;
    }
}
