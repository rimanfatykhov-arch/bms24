<?php
namespace local_bitrixsync\services;

use local_bitrixsync\adapters\bitrix24\moodle_catalog_client;

defined('MOODLE_INTERNAL') || die();

/**
 * Read-only Moodle course -> Bitrix product/section binding diagnostics.
 *
 * v5.0.36.1.4 intentionally avoids one large SQL JOIN. Production KBA Connect
 * installations have evolved through several upgrade generations, so this
 * diagnostic reads the canonical Moodle tables first and then probes mapping
 * tables/fields only when they actually exist.
 */
class catalog_binding_diagnostic_service {
    /** @var moodle_catalog_client */
    private $bitrix;

    public function __construct(?moodle_catalog_client $bitrix = null) {
        $this->bitrix = $bitrix ?? new moodle_catalog_client();
    }

    public function run(): array {
        global $DB;

        $ctx = $this->bitrix->resolve_context();
        $dbman = $DB->get_manager();

        $catmaptable = new \xmldb_table('local_bitrixsync_catmap');
        $productmaptable = new \xmldb_table('local_bitrixsync_productmap');

        $catmapexists = $dbman->table_exists($catmaptable);
        $productmapexists = $dbman->table_exists($productmaptable);

        $productfields = [];
        if ($productmapexists) {
            foreach ([
                'bitrix_product_id',
                'courseid',
                'categoryid',
                'active',
                'bitrix_section_id',
                'iblockid',
                'status',
                'error',
            ] as $fieldname) {
                $productfields[$fieldname] = $dbman->field_exists(
                    $productmaptable,
                    new \xmldb_field($fieldname)
                );
            }
        }

        $categoryfields = [];
        if ($catmapexists) {
            foreach ([
                'categoryid',
                'bitrix_section_id',
                'iblockid',
                'status',
                'error',
            ] as $fieldname) {
                $categoryfields[$fieldname] = $dbman->field_exists(
                    $catmaptable,
                    new \xmldb_field($fieldname)
                );
            }
        }

        $courses = $DB->get_records(
            'course',
            [],
            'sortorder ASC, id ASC',
            'id,fullname,category,sortorder'
        );

        $rows = [];
        $summary = [
            'total' => 0,
            'ok' => 0,
            'warning' => 0,
            'error' => 0,
            'expected_iblockid' => (int)$ctx['iblockid'],
            'mode' => (string)$ctx['mode'],
        ];

        foreach ($courses as $course) {
            if ((int)$course->id === (int)SITEID) {
                continue;
            }

            $summary['total']++;

            $category = $DB->get_record(
                'course_categories',
                ['id' => (int)$course->category],
                'id,name',
                IGNORE_MISSING
            );

            $categorymap = null;
            if ($catmapexists && !empty($categoryfields['categoryid'])) {
                $categorymap = $DB->get_record(
                    'local_bitrixsync_catmap',
                    ['categoryid' => (int)$course->category],
                    '*',
                    IGNORE_MISSING
                );
            }

            $productmap = null;
            if ($productmapexists && !empty($productfields['courseid'])) {
                $conditions = ['courseid' => (int)$course->id];
                if (!empty($productfields['active'])) {
                    $conditions['active'] = 1;
                }

                $matches = $DB->get_records(
                    'local_bitrixsync_productmap',
                    $conditions,
                    'id DESC',
                    '*',
                    0,
                    1
                );

                if ($matches) {
                    $productmap = reset($matches);
                }
            }

            $expectedsectionid = $categorymap &&
                property_exists($categorymap, 'bitrix_section_id')
                ? (int)$categorymap->bitrix_section_id
                : 0;

            $productid = $productmap &&
                property_exists($productmap, 'bitrix_product_id')
                ? (int)$productmap->bitrix_product_id
                : 0;

            $row = [
                'courseid' => (int)$course->id,
                'course' => (string)$course->fullname,
                'categoryid' => (int)$course->category,
                'category' => $category ? (string)$category->name : '',
                'expected_iblockid' => (int)$ctx['iblockid'],
                'expected_sectionid' => $expectedsectionid,
                'map_productid' => $productid,
                'map_iblockid' => $productmap && property_exists($productmap, 'iblockid')
                    ? (int)$productmap->iblockid
                    : 0,
                'map_sectionid' => $productmap && property_exists($productmap, 'bitrix_section_id')
                    ? (int)$productmap->bitrix_section_id
                    : 0,
                'category_map_status' => $categorymap && property_exists($categorymap, 'status')
                    ? (string)$categorymap->status
                    : '',
                'product_map_status' => $productmap && property_exists($productmap, 'status')
                    ? (string)$productmap->status
                    : '',
                'category_error' => $categorymap && property_exists($categorymap, 'error')
                    ? (string)$categorymap->error
                    : '',
                'product_error' => $productmap && property_exists($productmap, 'error')
                    ? (string)$productmap->error
                    : '',
                'live_found' => false,
                'live_iblockid' => 0,
                'live_sectionid' => 0,
                'live_name' => '',
                'status' => 'error',
                'reason' => '',
            ];

            if (!$catmapexists) {
                $row['reason'] = 'category_mapping_table_missing';
                $summary['error']++;
                $rows[] = $row;
                continue;
            }

            if (!$productmapexists) {
                $row['reason'] = 'product_mapping_table_missing';
                $summary['error']++;
                $rows[] = $row;
                continue;
            }

            if ($productid <= 0) {
                $row['reason'] = $expectedsectionid <= 0
                    ? 'category_and_product_mapping_missing'
                    : 'product_mapping_missing';
                $summary['error']++;
                $rows[] = $row;
                continue;
            }

            // Always read the live Bitrix product when Product ID is known.
            // This is essential when the local category map is missing: we still
            // need to see whether the Bitrix product itself has a section.
            try {
                $live = $this->bitrix->get_product_snapshot($productid);
                $row['live_found'] = !empty($live['found']);
                $row['live_iblockid'] = (int)($live['iblockid'] ?? 0);
                $row['live_sectionid'] = (int)($live['sectionid'] ?? 0);
                $row['live_name'] = (string)($live['name'] ?? '');

                if (!$row['live_found']) {
                    $row['status'] = 'error';
                    $row['reason'] = 'bitrix_product_not_found';
                    $summary['error']++;
                } else if ($row['live_iblockid'] > 0 &&
                        $row['live_iblockid'] !== $row['expected_iblockid']) {
                    $row['status'] = 'error';
                    $row['reason'] = 'iblock_mismatch';
                    $summary['error']++;
                } else if ($expectedsectionid <= 0 && $row['live_sectionid'] > 0) {
                    $row['status'] = 'warning';
                    $row['reason'] = 'local_category_mapping_missing_but_live_section_exists';
                    $summary['warning']++;
                } else if ($expectedsectionid <= 0 && $row['live_sectionid'] <= 0) {
                    $row['status'] = 'error';
                    $row['reason'] = 'category_mapping_missing_and_bitrix_section_empty';
                    $summary['error']++;
                } else if ($row['live_sectionid'] <= 0) {
                    $row['status'] = 'warning';
                    $row['reason'] = 'bitrix_section_empty';
                    $summary['warning']++;
                } else if ($row['live_sectionid'] !== $expectedsectionid) {
                    $row['status'] = 'error';
                    $row['reason'] = 'section_mismatch';
                    $summary['error']++;
                } else {
                    $row['status'] = 'ok';
                    $row['reason'] = 'binding_ok';
                    $summary['ok']++;
                }
            } catch (\Throwable $e) {
                $row['status'] = 'error';
                $row['reason'] = 'bitrix_read_error: ' . $e->getMessage();
                $summary['error']++;
            }

            $rows[] = $row;
        }

        $categorymappingrows = [];
        if ($catmapexists) {
            $records = $DB->get_records('local_bitrixsync_catmap', [], 'categoryid ASC');
            foreach ($records as $record) {
                $categorymappingrows[] = [
                    'id' => (int)$record->id,
                    'categoryid' => (int)($record->categoryid ?? 0),
                    'sectionid' => (int)($record->bitrix_section_id ?? 0),
                    'parentsectionid' => (int)($record->bitrix_parent_section_id ?? 0),
                    'iblockid' => (int)($record->iblockid ?? 0),
                    'name' => (string)($record->name ?? ''),
                    'status' => (string)($record->status ?? ''),
                    'error' => (string)($record->error ?? ''),
                    'last_sync' => (int)($record->last_sync ?? 0),
                ];
            }
        }

        $productmappingrows = [];
        if ($productmapexists) {
            $records = $DB->get_records(
                'local_bitrixsync_productmap',
                ['active' => 1],
                'courseid ASC, id DESC'
            );
            foreach ($records as $record) {
                $productmappingrows[] = [
                    'id' => (int)$record->id,
                    'courseid' => (int)($record->courseid ?? 0),
                    'productid' => (int)($record->bitrix_product_id ?? 0),
                    'categoryid' => (int)($record->categoryid ?? 0),
                    'sectionid' => (int)($record->bitrix_section_id ?? 0),
                    'iblockid' => (int)($record->iblockid ?? 0),
                    'status' => (string)($record->status ?? ''),
                    'error' => (string)($record->error ?? ''),
                    'last_sync' => (int)($record->last_sync ?? 0),
                ];
            }
        }

        $result = [
            'time' => time(),
            'context' => [
                'mode' => (string)$ctx['mode'],
                'catalogid' => (int)$ctx['catalogid'],
                'iblockid' => (int)$ctx['iblockid'],
                'name' => (string)$ctx['name'],
            ],
            'database' => [
                'category_mapping_table' => $catmapexists,
                'product_mapping_table' => $productmapexists,
                'category_fields' => $categoryfields,
                'product_fields' => $productfields,
            ],
            'summary' => $summary,
            'category_mappings' => $categorymappingrows,
            'product_mappings' => $productmappingrows,
            'rows' => $rows,
        ];

        set_config(
            'catalog_binding_debug_last',
            json_encode($result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'local_bitrixsync'
        );

        return $result;
    }
}
