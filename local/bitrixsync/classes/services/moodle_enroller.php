<?php
namespace local_bitrixsync\services;

defined('MOODLE_INTERNAL') || die();

class moodle_enroller {
    public function enrol(int $userid, int $courseid): array {
        global $DB, $CFG;

        require_once($CFG->libdir . '/enrollib.php');

        $DB->get_record('user', ['id' => $userid, 'deleted' => 0], 'id', MUST_EXIST);
        $course = $DB->get_record('course', ['id' => $courseid], '*', MUST_EXIST);

        if (is_enrolled(\context_course::instance($courseid), $userid, '', true)) {
            return ['status' => 'already_enrolled', 'courseid' => $courseid];
        }

        $plugin = enrol_get_plugin('manual');
        if (!$plugin) {
            throw new \moodle_exception('manual_enrol_missing', 'local_bitrixsync');
        }

        $instance = null;
        foreach (enrol_get_instances($courseid, true) as $candidate) {
            if ($candidate->enrol === 'manual') {
                $instance = $candidate;
                break;
            }
        }

        if (!$instance) {
            $instanceid = $plugin->add_instance($course, ['status' => ENROL_INSTANCE_ENABLED]);
            $instance = $DB->get_record('enrol', ['id' => $instanceid], '*', MUST_EXIST);
        }

        $roleid = (int)get_config('local_bitrixsync', 'default_roleid');
        if ($roleid <= 0) {
            $roles = get_archetype_roles('student');
            $role = reset($roles);
            if (!$role) {
                throw new \moodle_exception('student_role_missing', 'local_bitrixsync');
            }
            $roleid = (int)$role->id;
        }

        $plugin->enrol_user($instance, $userid, $roleid, time(), 0, ENROL_USER_ACTIVE);

        return ['status' => 'enrolled', 'courseid' => $courseid, 'roleid' => $roleid];
    }
}
