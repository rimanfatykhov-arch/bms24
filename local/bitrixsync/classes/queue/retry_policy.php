<?php
namespace local_bitrixsync\queue;

defined('MOODLE_INTERNAL') || die();

class retry_policy {

    public function next_attempt(int $attempts): array {
        if ($attempts <= 0) {
            return ['allowed' => true, 'delay' => 30];
        }

        if ($attempts == 1) {
            return ['allowed' => true, 'delay' => 300];
        }

        if ($attempts == 2) {
            return ['allowed' => true, 'delay' => 1800];
        }

        return ['allowed' => false, 'delay' => 0];
    }
}
