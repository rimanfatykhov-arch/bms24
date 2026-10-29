<?php
namespace local_kbacompany\event;

defined('MOODLE_INTERNAL') || die();

/** Cycle audit metadata, stored by Moodle logging, not a second history engine. */
class management_cycle_changed extends \core\event\base {
    protected function init() {
        $this->data['crud'] = 'u';
        $this->data['edulevel'] = self::LEVEL_OTHER;
        $this->data['objecttable'] = 'local_kbacompany_mcycle';
    }
    public static function get_name() {
        return get_string('mcu_event', 'local_kbacompany');
    }
    public function get_description() {
        return "User {$this->userid} performed '{$this->other['operation']}' on management cycle {$this->objectid}.";
    }
    public function get_url() {
        return new \moodle_url('/local/kbacompany/management_cycles.php',
            ['uuid'=>$this->other['companyuuid'],'cycle'=>$this->other['cycleuuid'],'tab'=>'history']);
    }
    protected function validate_data() {
        parent::validate_data();
        foreach (['companyuuid','cycleuuid','operation'] as $key) {
            if (empty($this->other[$key])) { throw new \coding_exception('Missing management cycle event scope.'); }
        }
    }
    public static function get_other_mapping() { return []; }
    public static function get_objectid_mapping() { return ['db'=>'local_kbacompany_mcycle','restore'=>self::NOT_MAPPED]; }
}
