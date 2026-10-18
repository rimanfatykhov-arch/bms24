<?php
namespace local_bitrixsync\resolver;

defined('MOODLE_INTERNAL') || die();

/**
 * Alpha.7 shadow resolver pipeline.
 *
 * Produces a plan only. No writes.
 */
class pipeline {
    public function resolve(array $deal, array $products): array {
        return [
            'deal' => $deal,
            'products' => $products,
            'moodle_plan' => [
                'courses' => [],
                'groups' => [],
                'enrollments' => [],
            ],
            'corporate_plan' => [
                'company' => 'would_resolve',
                'licenses' => [],
                'seats' => [],
            ],
            'changes' => [
                'database' => 0,
                'corporate' => 0,
                'moodle' => 0,
                'crm' => 0,
            ],
            'mode' => 'shadow_only',
        ];
    }
}
