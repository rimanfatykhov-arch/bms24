<?php
namespace local_kbagamification\event;

defined('MOODLE_INTERNAL') || die();

/**
 * Event triggered when KBA XP/Coins are applied.
 */
class reward_applied extends \core\event\base {
    protected function init() {
        $this->data['crud'] = 'c';
        $this->data['edulevel'] = self::LEVEL_PARTICIPATING;
        $this->data['objecttable'] = 'local_kbagam_awards';
    }

    public static function get_name() {
        return get_string('eventrewardapplied', 'local_kbagamification');
    }

    public function get_description() {
        return "XP '{$this->other['xp']}' and coins '{$this->other['coins']}' were applied to user " .
            "with id '{$this->relateduserid}' from source '{$this->other['source']}'.";
    }

    public function get_url() {
        return new \moodle_url('/local/kbagamification/index.php', ['userid' => $this->relateduserid]);
    }

    protected function validate_data() {
        parent::validate_data();
        if (!isset($this->relateduserid)) {
            throw new \coding_exception('The relateduserid must be set.');
        }
        foreach (['xp', 'coins', 'source'] as $required) {
            if (!array_key_exists($required, $this->other)) {
                throw new \coding_exception('Missing required other field: ' . $required);
            }
        }
    }

    public static function get_objectid_mapping() {
        return ['db' => 'local_kbagam_awards', 'restore' => self::NOT_MAPPED];
    }

    public static function get_other_mapping() {
        return false;
    }
}
