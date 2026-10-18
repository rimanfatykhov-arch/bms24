<?php
namespace local_kbacorporate\form;

defined('MOODLE_INTERNAL') || die();
require_once($CFG->libdir . '/formslib.php');

/** JSON import form for BMS criterion ↔ competency mappings. */
class bms_competency_import_form extends \moodleform {
    protected function definition() {
        $mform = $this->_form;
        $mform->addElement('hidden', 'companyid', (int)$this->_customdata['companyid']);
        $mform->setType('companyid', PARAM_INT);
        $mform->addElement('filepicker', 'jsonfile', get_string('bmsjsonfile', 'local_kbacorporate'), null,
            ['accepted_types' => ['.json'], 'maxbytes' => 2 * 1024 * 1024]);
        $mform->addRule('jsonfile', null, 'required', null, 'client');
        $this->add_action_buttons(false, get_string('bmsjsonpreview', 'local_kbacorporate'));
    }
}
