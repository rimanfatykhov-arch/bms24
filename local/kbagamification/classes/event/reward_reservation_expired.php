<?php
namespace local_kbagamification\event;

defined('MOODLE_INTERNAL') || die();

/** Reward reservation expired. */
class reward_reservation_expired extends \core\event\base {
    protected function init() {
        $this->data['crud'] = 'u';
        $this->data['edulevel'] = self::LEVEL_OTHER;
        $this->data['objecttable'] = 'local_kbagam_reservations';
    }
    public static function get_name() {
        return get_string('eventreservationexpired', 'local_kbagamification');
    }
    public function get_description() {
        return 'Reward reservation ' . $this->objectid . ' was expired for user ' . $this->relateduserid . '.';
    }
    public function get_url() {
        return new \moodle_url('/local/kbagamification/orders.php');
    }
}
