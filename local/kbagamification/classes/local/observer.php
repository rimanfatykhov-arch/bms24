<?php
namespace local_kbagamification\local;

defined('MOODLE_INTERNAL') || die();

/**
 * Moodle event observers.
 */
class observer {
    /**
     * Award configured rewards when an activity becomes complete.
     *
     * @param \core\event\course_module_completion_updated $event
     */
    public static function course_module_completion_updated(\core\event\course_module_completion_updated $event): void {
        global $DB;

        if (!engine::is_enabled()) {
            return;
        }

        $state = isset($event->other['completionstate']) ? (int) $event->other['completionstate'] : 0;
        if ($state === COMPLETION_INCOMPLETE) {
            return;
        }

        $userid = (int) $event->relateduserid;
        $cmid = (int) $event->contextinstanceid;
        if (!$userid || !$cmid) {
            return;
        }

        $rules = $DB->get_records('local_kbagam_rules', [
            'eventcode' => 'activity_completed',
            'enabled' => 1,
        ], 'sortorder ASC, id ASC');

        foreach ($rules as $rule) {
            engine::apply_rule(
                $rule,
                $userid,
                $cmid,
                get_string('reasonactivitycompleted', 'local_kbagamification'),
                [
                    'courseid' => (int) $event->courseid,
                    'cmid' => $cmid,
                    'completionstate' => $state,
                ]
            );
        }

        achievement_manager::evaluate_user($userid, 'activity_completed', $cmid);
    }

    /**
     * Award configured rewards when a course is completed.
     *
     * @param \core\event\course_completed $event
     */
    public static function course_completed(\core\event\course_completed $event): void {
        global $DB;

        if (!engine::is_enabled()) {
            return;
        }

        $userid = (int) $event->relateduserid;
        $courseid = (int) $event->courseid;
        if (!$userid || !$courseid) {
            return;
        }

        $rules = $DB->get_records('local_kbagam_rules', [
            'eventcode' => 'course_completed',
            'enabled' => 1,
        ], 'sortorder ASC, id ASC');

        foreach ($rules as $rule) {
            engine::apply_rule(
                $rule,
                $userid,
                $courseid,
                get_string('reasoncoursecompleted', 'local_kbagamification'),
                ['courseid' => $courseid]
            );
        }

        achievement_manager::evaluate_user($userid, 'course_completed', $courseid);
    }
}
