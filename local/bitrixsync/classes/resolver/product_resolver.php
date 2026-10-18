<?php
namespace local_bitrixsync\resolver;

defined('MOODLE_INTERNAL') || die();

class product_resolver {
    public function resolve(int $productid): array {
        global $DB;

        if ($DB->get_manager()->table_exists(new \xmldb_table('local_bitrixsync_productmap'))) {
            $map = $DB->get_record('local_bitrixsync_productmap', [
                'bitrixproductid' => $productid,
                'active' => 1,
            ]);

            if ($map) {
                return [
                    'productid' => $productid,
                    'courseid' => (int)$map->courseid,
                    'status' => 'mapped',
                ];
            }
        }

        return [
            'productid' => $productid,
            'courseid' => 0,
            'status' => 'unmapped',
        ];
    }
}
