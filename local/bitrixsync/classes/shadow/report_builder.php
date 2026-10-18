<?php
namespace local_bitrixsync\shadow;

defined('MOODLE_INTERNAL') || die();

class report_builder {
    public function build(array $plan): array {
        $plan['report'] = 'KBA Connect Resolver Pipeline Shadow Report';
        return $plan;
    }
}
