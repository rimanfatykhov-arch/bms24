<?php
namespace local_kbacorporate\task;

defined('MOODLE_INTERNAL') || die();

/** Synchronise active development actions with Moodle course completion. */
class sync_development_courses extends \core\task\scheduled_task {
    public function get_name() {
        return get_string('tasksyncdevelopmentcourses', 'local_kbacorporate');
    }

    public function execute() {
        $result = \local_kbacorporate\local\development_plans::sync_course_completions();
        mtrace(get_string('developmentcoursesyncresult', 'local_kbacorporate', $result));
    }
}
