<?php
namespace local_kbacorporate\form;

defined('MOODLE_INTERNAL') || die();
require_once($CFG->libdir . '/formslib.php');

/** JSON import form for BMS tools and criteria. */
class bms_tool_import_form extends \moodleform {
    protected function definition() {
        $mform = $this->_form;
        $mform->addElement('hidden', 'companyid', (int)$this->_customdata['companyid']);
        $mform->setType('companyid', PARAM_INT);
        $mform->addElement('filepicker', 'jsonfile', get_string('bmstooljsonfile', 'local_kbacorporate'), null,
            ['accepted_types' => ['.json'], 'maxbytes' => 5 * 1024 * 1024]);
        $mform->addRule('jsonfile', null, 'required', null, 'client');
        $this->add_action_buttons(false, get_string('bmstooljsonpreview', 'local_kbacorporate'));
    }
}
