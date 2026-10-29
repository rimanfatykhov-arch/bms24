<?php
namespace local_kbacompany\event;

defined('MOODLE_INTERNAL') || die();

/** Identity lifecycle event. No external IDs, credentials or payloads are logged. */
class integration_mapping_created extends \core\event\base {
    protected function init() {
        $this->data['crud'] = 'c';
        $this->data['edulevel'] = self::LEVEL_OTHER;
    }

    public static function get_name() {
        return get_string('bms_int_event_created', 'local_kbacompany');
    }

    public function get_description() {
        return "User {$this->userid} created a company-scoped external identity mapping.";
    }

    public function get_url() {
        return new \moodle_url('/local/kbacompany/integration_mapping.php', [
            'uuid' => $this->other['companyuuid'], 'mapping' => $this->other['mappinguuid'],
        ]);
    }

    protected function validate_data() {
        parent::validate_data();
        foreach (['companyuuid', 'mappinguuid'] as $field) {
            if (empty($this->other[$field])) {
                throw new \coding_exception('Missing identity event scope.');
            }
        }
    }
}
