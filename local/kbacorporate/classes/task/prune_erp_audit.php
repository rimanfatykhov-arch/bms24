<?php
namespace local_kbacorporate\task;

defined('MOODLE_INTERNAL') || die();

/** Daily retention task for privacy-safe ERP operational logs. */
class prune_erp_audit extends \core\task\scheduled_task {
    public function get_name() {
        return get_string('taskpruneerpaudit', 'local_kbacorporate');
    }

    public function execute() {
        $deleted = \local_kbacorporate\local\erp_audit::prune();
        mtrace('[KBA ERP Audit] expired technical records removed=' . $deleted);
    }
}
