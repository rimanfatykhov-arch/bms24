<?php
namespace local_bitrixsync\resolver;

defined('MOODLE_INTERNAL') || die();

class product_mapping_resolver {

    public function resolve(array $rows): array {
        global $DB;

        $result = [];

        foreach ($rows as $row) {
            $productid = (int)($row['PRODUCT_ID'] ?? 0);

            $item = [
                'product_id' => $productid,
                'course_id' => 0,
                'source' => null,
                'status' => 'unmapped',
            ];

            if (!$productid) {
                $result[] = $item;
                continue;
            }

            // 1. Direct product catalog mapping.
            if ($DB->get_manager()->table_exists(new \xmldb_table('local_bitrixsync_productmap'))) {
                $map = $DB->get_record('local_bitrixsync_productmap', [
                    'bitrix_product_id' => $productid,
                    'active' => 1,
                ]);

                if ($map) {
                    $item['course_id'] = (int)$map->courseid;
                    $item['source'] = 'productmap';
                    $item['status'] = 'mapped';
                    $result[] = $item;
                    continue;
                }
            }

            // 2. Existing course map catalog.
            if ($DB->get_manager()->table_exists(new \xmldb_table('local_bitrixsync_coursemap'))) {
                $map = $DB->get_record('local_bitrixsync_coursemap', [
                    'productid' => $productid,
                    'laststatus' => 'active',
                ]);

                if ($map) {
                    $item['course_id'] = (int)$map->courseid;
                    $item['source'] = 'coursemap';
                    $item['status'] = 'mapped';
                    $result[] = $item;
                    continue;
                }
            }

            // 3. Historical aliases.
            if ($DB->get_manager()->table_exists(new \xmldb_table('local_bitrixsync_pralias'))) {
                $alias = $DB->get_record('local_bitrixsync_pralias', [
                    'legacy_product_id' => $productid,
                    'active' => 1,
                ]);

                if ($alias) {
                    $item['course_id'] = (int)$alias->courseid;
                    $item['source'] = 'pralias';
                    $item['status'] = 'mapped';
                }
            }

            $result[] = $item;
        }

        return $result;
    }
}
