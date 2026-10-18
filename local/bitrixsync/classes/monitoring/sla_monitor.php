<?php
namespace local_bitrixsync\monitoring;

defined('MOODLE_INTERNAL') || die();

class sla_monitor {

    public function classify(int $seconds): array {
        if ($seconds <= 30) {
            return ['level'=>'normal','color'=>'green'];
        }

        if ($seconds <= 120) {
            return ['level'=>'warning','color'=>'yellow'];
        }

        return ['level'=>'critical','color'=>'red'];
    }
}
