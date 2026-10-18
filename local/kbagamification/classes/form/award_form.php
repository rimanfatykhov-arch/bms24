<?php
namespace local_kbagamification\form;

defined('MOODLE_INTERNAL') || die();

require_once($CFG->libdir . '/formslib.php');

/**
 * Manual reward form.
 */
class award_form extends \moodleform {
    public function definition() {
        global $DB;

        $mform = $this->_form;
        $userid = !empty($this->_customdata['userid']) ? (int) $this->_customdata['userid'] : 0;

        if ($userid) {
            $user = $DB->get_record('user', ['id' => $userid, 'deleted' => 0], '*', MUST_EXIST);
            $mform->addElement('static', 'userdisplay', get_string('user'), fullname($user) . ' (' . s($user->email) . ')');
            $mform->addElement('hidden', 'userid', $userid);
            $mform->setType('userid', PARAM_INT);
        } else {
            $mform->addElement('text', 'userid', get_string('userid', 'local_kbagamification'));
            $mform->setType('userid', PARAM_INT);
            $mform->addRule('userid', null, 'required', null, 'client');
            $mform->addElement('static', 'useridhelp', '', get_string('useridhelp', 'local_kbagamification'));
        }

        $mform->addElement('text', 'xp', get_string('xpadjustment', 'local_kbagamification'));
        $mform->setType('xp', PARAM_INT);
        $mform->setDefault('xp', 0);

        $mform->addElement('text', 'coins', get_string('coinsadjustment', 'local_kbagamification'));
        $mform->setType('coins', PARAM_INT);
        $mform->setDefault('coins', 0);

        $mform->addElement('textarea', 'reason', get_string('reason', 'local_kbagamification'), ['rows' => 4, 'cols' => 60]);
        $mform->setType('reason', PARAM_TEXT);
        $mform->addRule('reason', null, 'required', null, 'client');

        $this->add_action_buttons(true, get_string('apply', 'local_kbagamification'));
    }

    public function validation($data, $files) {
        global $DB;
        $errors = parent::validation($data, $files);
        if (empty($data['userid']) || !$DB->record_exists('user', ['id' => (int) $data['userid'], 'deleted' => 0])) {
            $errors['userid'] = get_string('invaliduserid', 'local_kbagamification');
        }
        if ((int) $data['xp'] === 0 && (int) $data['coins'] === 0) {
            $errors['coins'] = get_string('adjustmentempty', 'local_kbagamification');
        }
        return $errors;
    }
}
