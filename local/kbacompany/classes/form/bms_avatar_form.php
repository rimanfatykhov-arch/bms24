<?php
namespace local_kbacompany\form;

defined('MOODLE_INTERNAL') || die();
require_once($CFG->libdir . '/formslib.php');

/** A self-service, one-image Moodle draft-area upload. */
class bms_avatar_form extends \moodleform {
    protected function definition() {
        $mform = $this->_form;
        $mform->addElement('filepicker', 'avatar', get_string('bms_avatar_upload', 'local_kbacompany'), null,
            ['maxbytes' => 2097152, 'accepted_types' => ['.jpg', '.jpeg', '.png', '.gif', '.webp']]);
        $mform->addRule('avatar', get_string('required'), 'required');
        $mform->addElement('hidden', 'uuid');
        $mform->setType('uuid', PARAM_RAW_TRIMMED);
        $mform->addElement('hidden', 'userid');
        $mform->setType('userid', PARAM_INT);
        $mform->addElement('hidden', 'membership');
        $mform->setType('membership', PARAM_RAW_TRIMMED);
        $this->add_action_buttons(true, get_string('bms_avatar_save', 'local_kbacompany'));
    }
}
