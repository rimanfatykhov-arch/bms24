<?php
namespace local_kbagamification\event;

defined('MOODLE_INTERNAL') || die();

/**
 * Event fired when a user receives an achievement.
 *
 * @package local_kbagamification
 */
class achievement_awarded extends \core\event\base {
    protected function init() {
        $this->data['crud'] = 'c';
        $this->data['edulevel'] = self::LEVEL_PARTICIPATING;
        $this->data['objecttable'] = 'local_kbagam_userach';
    }

    public static function get_name() {
        return get_string('eventachievementawarded', 'local_kbagamification');
    }

    public function get_description() {
        return "The user with id '{$this->relateduserid}' received KBA Gamification achievement id " .
            "'{$this->other['achievementid']}'.";
    }

    public function get_url() {
        return new \moodle_url('/local/kbagamification/index.php', ['userid' => $this->relateduserid]);
    }
}
