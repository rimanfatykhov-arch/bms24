<?php
namespace local_kbagamification\task;

defined('MOODLE_INTERNAL') || die();

use local_kbagamification\local\reward_manager;

/**
 * Periodically expire abandoned marketplace reward reservations.
 *
 * @package local_kbagamification
 */
class expire_reward_reservations extends \core\task\scheduled_task {
    public function get_name() {
        return get_string('taskexpirereservations', 'local_kbagamification');
    }

    public function execute() {
        reward_manager::expire_reservations(500);
    }
}
