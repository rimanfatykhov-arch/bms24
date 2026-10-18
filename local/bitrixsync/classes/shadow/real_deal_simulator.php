<?php
namespace local_bitrixsync\shadow;

defined('MOODLE_INTERNAL') || die();

class real_deal_simulator {
    public function run(int $dealid): array {
        $deal = (new \local_bitrixsync\bitrix24\deal_reader())->get($dealid);
        $rows = (new \local_bitrixsync\bitrix24\product_rows_reader())->get($dealid);

        return [
            'deal' => $deal,
            'product_rows' => $rows,
            'corporate' => [
                'company' => 'shadow_only',
                'licenses' => [],
                'seats' => [],
            ],
            'moodle' => [
                'courses' => [],
                'groups' => [],
                'enrollments' => [],
            ],
            'changes' => [
                'database' => 0,
                'corporate' => 0,
                'moodle' => 0,
            ],
        ];
    }
}
