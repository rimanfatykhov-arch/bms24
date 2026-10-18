<?php
namespace local_kbagamification\form;

defined('MOODLE_INTERNAL') || die();

require_once($CFG->libdir . '/formslib.php');

/**
 * Manual achievement award form.
 *
 * @package local_kbagamification
 */
class achievement_award_form extends \moodleform {
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
        }

        $records = $DB->get_records('local_kbagam_achievements', ['enabled' => 1], 'sortorder ASC, id ASC');
        $options = [];
        foreach ($records as $record) {
            $options[$record->id] = format_string($record->name);
        }
        $mform->addElement('select', 'achievementid', get_string('achievement', 'local_kbagamification'), $options);
        $mform->addRule('achievementid', null, 'required', null, 'client');

        $mform->addElement('textarea', 'reason', get_string('reason', 'local_kbagamification'), ['rows' => 3, 'cols' => 60]);
        $mform->setType('reason', PARAM_TEXT);
        $mform->setDefault('reason', get_string('manualachievementreason', 'local_kbagamification'));

        $this->add_action_buttons(true, get_string('awardachievement', 'local_kbagamification'));
    }

    public function validation($data, $files) {
        global $DB;
        $errors = parent::validation($data, $files);
        $userid = (int) ($data['userid'] ?? 0);
        $achievementid = (int) ($data['achievementid'] ?? 0);

        if (!$DB->record_exists('user', ['id' => $userid, 'deleted' => 0])) {
            $errors['userid'] = get_string('invaliduserid', 'local_kbagamification');
        }
        if (!$DB->record_exists('local_kbagam_achievements', ['id' => $achievementid, 'enabled' => 1])) {
            $errors['achievementid'] = get_string('invalidachievement', 'local_kbagamification');
        } else if ($DB->record_exists('local_kbagam_userach', [
            'userid' => $userid,
            'achievementid' => $achievementid,
        ])) {
            $errors['achievementid'] = get_string('achievementalreadyawarded', 'local_kbagamification');
        }
        return $errors;
    }
}
