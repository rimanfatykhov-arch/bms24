<?php
namespace local_bitrixsync\workflow;

defined('MOODLE_INTERNAL') || die();

class deal_processor {
    public function shadow(int $dealid): array {
        $deal = (new \local_bitrixsync\bitrix24\deal_reader())->get($dealid);
        $rows = (new \local_bitrixsync\bitrix24\product_rows_reader())->get($dealid);

        return [
            'mode' => 'shadow',
            'deal' => $deal,
            'product_rows' => $rows,
            'plan' => [
                'corporate' => [
                    'company' => 'would_resolve',
                    'licenses' => 'would_plan',
                    'seats' => 'would_plan',
                ],
                'moodle' => [
                    'courses' => [],
                    'groups' => [],
                    'enrollments' => [],
                ],
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
