<?php
namespace local_bitrixsync\moodle;

defined('MOODLE_INTERNAL') || die();

class enrollment_service {
    public function plan(int $userid, int $courseid, int $groupid = 0): array {
        return [
            'userid' => $userid,
            'courseid' => $courseid,
            'groupid' => $groupid,
            'mode' => 'shadow',
            'action' => 'would_enrol',
        ];
    }
}
