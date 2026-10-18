<?php
namespace local_kbacompany\event;

defined('MOODLE_INTERNAL') || die();

/** Metadata-only audit for explicit Position directory commands; core logging owns persistence. */
class position_definition_updated extends \core\event\base {
    protected function init() {
        $this->data['crud'] = 'u';
        $this->data['edulevel'] = self::LEVEL_OTHER;
        $this->data['objecttable'] = 'local_kbacompany_position';
    }
    public static function get_name() { return get_string('pdu_event', 'local_kbacompany'); }
    public function get_description() {
        return "User {$this->userid} performed '{$this->other['operation']}' on position definition {$this->objectid}.";
    }
    public function get_url() {
        return new \moodle_url('/local/kbacompany/positions.php', [
            'uuid' => $this->other['companyuuid'], 'position' => $this->other['positionuuid'], 'tab' => 'history']);
    }
    protected function validate_data() {
        parent::validate_data();
        foreach (['companyuuid', 'positionuuid', 'operation'] as $key) {
            if (empty($this->other[$key])) { throw new \coding_exception('Missing position audit scope.'); }
        }
        if (!in_array($this->other['operation'], ['created', 'edited', 'deactivated', 'restored'], true)) { throw new \coding_exception('Invalid position operation.'); }
    }
    public static function get_other_mapping() { return false; }
    public static function get_objectid_mapping() { return ['db' => 'local_kbacompany_position', 'restore' => self::NOT_MAPPED]; }
}
