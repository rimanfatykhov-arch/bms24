<?php
namespace local_kbagamification\form;

defined('MOODLE_INTERNAL') || die();

require_once($CFG->libdir . '/formslib.php');

/**
 * Level form.
 */
class level_form extends \moodleform {
    public function definition() {
        $mform = $this->_form;
        $mform->addElement('hidden', 'id', 0);
        $mform->setType('id', PARAM_INT);

        $mform->addElement('text', 'name', get_string('levelname', 'local_kbagamification'), ['size' => 50]);
        $mform->setType('name', PARAM_TEXT);
        $mform->addRule('name', null, 'required', null, 'client');

        $mform->addElement('text', 'minxp', get_string('minxp', 'local_kbagamification'));
        $mform->setType('minxp', PARAM_INT);
        $mform->addRule('minxp', null, 'required', null, 'client');

        $mform->addElement('advcheckbox', 'enabled', get_string('enabled', 'local_kbagamification'));
        $mform->setDefault('enabled', 1);

        $mform->addElement('text', 'sortorder', get_string('sortorder', 'local_kbagamification'));
        $mform->setType('sortorder', PARAM_INT);
        $mform->setDefault('sortorder', 10);

        $this->add_action_buttons(true, get_string('savechanges'));
    }

    public function validation($data, $files) {
        $errors = parent::validation($data, $files);
        if ((int) $data['minxp'] < 0) {
            $errors['minxp'] = get_string('mustbenonnegative', 'local_kbagamification');
        }
        return $errors;
    }
}
