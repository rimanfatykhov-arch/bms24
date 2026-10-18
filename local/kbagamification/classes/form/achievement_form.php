<?php
namespace local_kbagamification\form;

defined('MOODLE_INTERNAL') || die();

require_once($CFG->libdir . '/formslib.php');

/**
 * Achievement definition form.
 *
 * @package local_kbagamification
 */
class achievement_form extends \moodleform {
    public function definition() {
        $mform = $this->_form;

        $mform->addElement('hidden', 'id', 0);
        $mform->setType('id', PARAM_INT);

        $mform->addElement('text', 'name', get_string('achievementname', 'local_kbagamification'), ['size' => 60]);
        $mform->setType('name', PARAM_TEXT);
        $mform->addRule('name', null, 'required', null, 'client');

        $mform->addElement('textarea', 'description', get_string('achievementdescription', 'local_kbagamification'),
            ['rows' => 4, 'cols' => 60]);
        $mform->setType('description', PARAM_TEXT);

        $icons = [
            'trophy' => '🏆 ' . get_string('icon_trophy', 'local_kbagamification'),
            'footsteps' => '👣 ' . get_string('icon_footsteps', 'local_kbagamification'),
            'target' => '🎯 ' . get_string('icon_target', 'local_kbagamification'),
            'fire' => '🔥 ' . get_string('icon_fire', 'local_kbagamification'),
            'graduate' => '🎓 ' . get_string('icon_graduate', 'local_kbagamification'),
            'brain' => '🧠 ' . get_string('icon_brain', 'local_kbagamification'),
            'star' => '⭐ ' . get_string('icon_star', 'local_kbagamification'),
            'medal' => '🏅 ' . get_string('icon_medal', 'local_kbagamification'),
            'crown' => '👑 ' . get_string('icon_crown', 'local_kbagamification'),
            'book' => '📚 ' . get_string('icon_book', 'local_kbagamification'),
            'briefcase' => '💼 ' . get_string('icon_briefcase', 'local_kbagamification'),
        ];
        $mform->addElement('select', 'icon', get_string('achievementicon', 'local_kbagamification'), $icons);
        $mform->setDefault('icon', 'trophy');

        $rarities = [
            'common' => get_string('rarity_common', 'local_kbagamification'),
            'rare' => get_string('rarity_rare', 'local_kbagamification'),
            'epic' => get_string('rarity_epic', 'local_kbagamification'),
            'legendary' => get_string('rarity_legendary', 'local_kbagamification'),
        ];
        $mform->addElement('select', 'rarity', get_string('rarity', 'local_kbagamification'), $rarities);
        $mform->setDefault('rarity', 'common');

        $conditions = [
            'activity_completed_count' => get_string('condition_activity_completed_count', 'local_kbagamification'),
            'course_completed_count' => get_string('condition_course_completed_count', 'local_kbagamification'),
            'specific_course_completed' => get_string('condition_specific_course_completed', 'local_kbagamification'),
            'xp_total' => get_string('condition_xp_total', 'local_kbagamification'),
            'coins_earned_total' => get_string('condition_coins_earned_total', 'local_kbagamification'),
        ];
        $mform->addElement('select', 'conditiontype', get_string('achievementcondition', 'local_kbagamification'), $conditions);

        $mform->addElement('text', 'targetvalue', get_string('targetvalue', 'local_kbagamification'));
        $mform->setType('targetvalue', PARAM_INT);
        $mform->setDefault('targetvalue', 1);
        $mform->addRule('targetvalue', null, 'required', null, 'client');

        $mform->addElement('text', 'courseid', get_string('courseid', 'local_kbagamification'));
        $mform->setType('courseid', PARAM_INT);
        $mform->setDefault('courseid', 0);
        $mform->addHelpButton('courseid', 'courseid', 'local_kbagamification');

        $mform->addElement('text', 'rewardxp', get_string('achievementrewardxp', 'local_kbagamification'));
        $mform->setType('rewardxp', PARAM_INT);
        $mform->setDefault('rewardxp', 0);

        $mform->addElement('text', 'rewardcoins', get_string('achievementrewardcoins', 'local_kbagamification'));
        $mform->setType('rewardcoins', PARAM_INT);
        $mform->setDefault('rewardcoins', 0);

        $mform->addElement('advcheckbox', 'enabled', get_string('enabled', 'local_kbagamification'));
        $mform->setDefault('enabled', 1);

        $mform->addElement('text', 'sortorder', get_string('sortorder', 'local_kbagamification'));
        $mform->setType('sortorder', PARAM_INT);
        $mform->setDefault('sortorder', 10);

        $this->add_action_buttons(true, get_string('savechanges'));
    }

    public function validation($data, $files) {
        global $DB;
        $errors = parent::validation($data, $files);

        if ((int) $data['targetvalue'] < 1) {
            $errors['targetvalue'] = get_string('targetmustbepositive', 'local_kbagamification');
        }
        if ((int) $data['rewardxp'] < 0 || (int) $data['rewardcoins'] < 0) {
            $errors['rewardcoins'] = get_string('achievementrewardpositive', 'local_kbagamification');
        }
        if ($data['conditiontype'] === 'specific_course_completed') {
            $courseid = (int) $data['courseid'];
            if ($courseid <= 1 || !$DB->record_exists('course', ['id' => $courseid])) {
                $errors['courseid'] = get_string('invalidcourseid', 'local_kbagamification');
            }
        }
        return $errors;
    }
}
