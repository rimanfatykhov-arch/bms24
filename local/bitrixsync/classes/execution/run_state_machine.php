<?php
namespace local_bitrixsync\execution;

defined('MOODLE_INTERNAL') || die();

class run_state_machine {

    private array $states = [
        'READY',
        'APPROVED',
        'RUNNING',
        'VERIFYING',
        'COMPLETED',
        'FAILED',
    ];

    public function transition(string $current, string $next): array {
        $allowed = [
            'READY' => ['APPROVED'],
            'APPROVED' => ['RUNNING'],
            'RUNNING' => ['VERIFYING', 'FAILED'],
            'VERIFYING' => ['COMPLETED', 'FAILED'],
        ];

        return [
            'from' => $current,
            'to' => $next,
            'allowed' => in_array($next, $allowed[$current] ?? [], true),
            'states' => $this->states,
        ];
    }
}
