<?php
namespace local_bitrixsync\shadow;

defined('MOODLE_INTERNAL') || die();

class report_engine {

    public function build(array $deal, array $courses, array $corporate): array {
        return [
            'mode' => 'shadow_only',
            'deal' => $deal,
            'courses' => $courses,
            'corporate' => $corporate,
            'moodle_plan' => [
                'enrollments' => [],
                'groups' => [],
                'users' => [],
            ],
            'changes' => [
                'database' => 0,
                'corporate' => 0,
                'moodle' => 0,
                'crm' => 0,
            ],
        ];
    }
}
