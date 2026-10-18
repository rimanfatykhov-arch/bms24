<?php
namespace local_kbagamification\event;

defined('MOODLE_INTERNAL') || die();

class reward_order_status_changed extends \core\event\base {
    protected function init() {
        $this->data['crud'] = 'u';
        $this->data['edulevel'] = self::LEVEL_OTHER;
        $this->data['objecttable'] = 'local_kbagam_orders';
    }

    public static function get_name() {
        return get_string('eventrewardorderstatuschanged', 'local_kbagamification');
    }

    public function get_description() {
        return "KBA reward order '{$this->objectid}' status changed from '{$this->other['oldstatus']}' to '{$this->other['newstatus']}'.";
    }
}
