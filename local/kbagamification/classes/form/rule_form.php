<?php
namespace local_kbagamification\form;

defined('MOODLE_INTERNAL') || die();

require_once($CFG->libdir . '/formslib.php');

/**
 * Reward rule form.
 */
class rule_form extends \moodleform {
    public function definition() {
        $mform = $this->_form;

        $mform->addElement('hidden', 'id', 0);
        $mform->setType('id', PARAM_INT);

        $mform->addElement('text', 'name', get_string('rulename', 'local_kbagamification'), ['size' => 60]);
        $mform->setType('name', PARAM_TEXT);
        $mform->addRule('name', null, 'required', null, 'client');

        $events = [
            'activity_completed' => get_string('eventcode_activity_completed', 'local_kbagamification'),
            'course_completed' => get_string('eventcode_course_completed', 'local_kbagamification'),
        ];
        $mform->addElement('select', 'eventcode', get_string('eventtype', 'local_kbagamification'), $events);
        $mform->addRule('eventcode', null, 'required', null, 'client');

        $mform->addElement('text', 'xp', get_string('xp', 'local_kbagamification'));
        $mform->setType('xp', PARAM_INT);
        $mform->setDefault('xp', 0);

        $mform->addElement('text', 'coins', get_string('coins', 'local_kbagamification'));
        $mform->setType('coins', PARAM_INT);
        $mform->setDefault('coins', 0);

        $mform->addElement('advcheckbox', 'enabled', get_string('enabled', 'local_kbagamification'));
        $mform->setDefault('enabled', 1);

        $mform->addElement('text', 'sortorder', get_string('sortorder', 'local_kbagamification'));
        $mform->setType('sortorder', PARAM_INT);
        $mform->setDefault('sortorder', 10);

        $this->add_action_buttons(true, get_string('savechanges'));
    }

    public function validation($data, $files) {
        $errors = parent::validation($data, $files);
        if ((int) $data['xp'] < 0) {
            $errors['xp'] = get_string('rulepositive', 'local_kbagamification');
        }
        if ((int) $data['coins'] < 0) {
            $errors['coins'] = get_string('rulepositive', 'local_kbagamification');
        }
        if ((int) $data['xp'] === 0 && (int) $data['coins'] === 0) {
            $errors['coins'] = get_string('ruleempty', 'local_kbagamification');
        }
        return $errors;
    }
}
