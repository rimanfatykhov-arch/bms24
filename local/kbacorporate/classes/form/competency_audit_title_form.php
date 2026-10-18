<?php
namespace local_kbacorporate\form;

defined('MOODLE_INTERNAL') || die();
require_once($CFG->libdir . '/formslib.php');

/** Rename an existing competency audit. */
class competency_audit_title_form extends \moodleform {
    protected function definition() {
        $mform = $this->_form;
        $auditid = (int)($this->_customdata['auditid'] ?? 0);
        $companyid = (int)($this->_customdata['companyid'] ?? 0);

        $mform->addElement('hidden', 'id', $auditid);
        $mform->setType('id', PARAM_INT);
        $mform->addElement('hidden', 'companyid', $companyid);
        $mform->setType('companyid', PARAM_INT);
        $mform->addElement('text', 'title', get_string('audittitle', 'local_kbacorporate'), [
            'size' => 60,
            'maxlength' => 255,
        ]);
        $mform->setType('title', PARAM_TEXT);
        $mform->addRule('title', null, 'required', null, 'client');
        $mform->addRule('title', get_string('maximumchars', '', 255), 'maxlength', 255, 'client');

        $this->add_action_buttons(true, get_string('savechanges'));
    }
}
