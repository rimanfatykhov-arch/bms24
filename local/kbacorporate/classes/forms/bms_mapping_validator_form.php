<?php
defined('MOODLE_INTERNAL') || die();

require_once($CFG->libdir . '/formslib.php');

class bms_mapping_validator_form extends moodleform {
    public function definition() {
        $mform = $this->_form;

        $mform->addElement('filepicker', 'jsonfile', get_string('bmsjsonfile', 'local_kbacorporate'), null, [
            'accepted_types' => ['.json']
        ]);
        $mform->addRule('jsonfile', null, 'required', null, 'client');

        $mform->addElement('checkbox', 'dryrun', '', get_string('bmsdryrun', 'local_kbacorporate'));
        $mform->setDefault('dryrun', 1);

        $this->add_action_buttons(false, get_string('bmscheckjson', 'local_kbacorporate'));
    }
}
