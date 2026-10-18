<?php
namespace local_bitrixsync\moodle;

defined('MOODLE_INTERNAL') || die();

class group_resolver {
    public function resolve(int $companyid, int $courseid): array {
        return [
            'companyid' => $companyid,
            'courseid' => $courseid,
            'mode' => 'shadow',
            'groupid' => 0,
            'action' => 'would_create_or_find',
        ];
    }
}
