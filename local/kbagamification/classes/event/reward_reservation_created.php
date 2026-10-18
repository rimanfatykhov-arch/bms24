<?php
namespace local_kbagamification\event;

defined('MOODLE_INTERNAL') || die();

/** Reward reservation created. */
class reward_reservation_created extends \core\event\base {
    protected function init() {
        $this->data['crud'] = 'c';
        $this->data['edulevel'] = self::LEVEL_OTHER;
        $this->data['objecttable'] = 'local_kbagam_reservations';
    }
    public static function get_name() {
        return get_string('eventreservationcreated', 'local_kbagamification');
    }
    public function get_description() {
        return 'Reward reservation ' . $this->objectid . ' was created for user ' . $this->relateduserid . '.';
    }
    public function get_url() {
        return new \moodle_url('/local/kbagamification/orders.php');
    }
}
