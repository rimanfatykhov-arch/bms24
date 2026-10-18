<?php
namespace local_kbagamification\event;

defined('MOODLE_INTERNAL') || die();

class reward_order_created extends \core\event\base {
    protected function init() {
        $this->data['crud'] = 'c';
        $this->data['edulevel'] = self::LEVEL_OTHER;
        $this->data['objecttable'] = 'local_kbagam_orders';
    }

    public static function get_name() {
        return get_string('eventrewardordercreated', 'local_kbagamification');
    }

    public function get_description() {
        return "The user with id '{$this->relateduserid}' created KBA reward order '{$this->objectid}'.";
    }
}
