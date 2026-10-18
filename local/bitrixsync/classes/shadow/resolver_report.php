<?php
namespace local_bitrixsync\shadow;

defined('MOODLE_INTERNAL') || die();

class resolver_report {

    public function build(array $products): array {
        return [
            'mode' => 'shadow_only',
            'products' => $products,
            'changes' => [
                'database' => 0,
                'corporate' => 0,
                'moodle' => 0,
                'crm' => 0,
            ],
        ];
    }
}
