<?php
namespace local_bitrixsync\services;

defined('MOODLE_INTERNAL') || die();

/**
 * Deep read-only diagnostics for local_bitrixsync_catmap DB write failures.
 */
class category_mapping_db_diagnostic_service {
    public function run(array $categoryids = []): array {
        global $DB;

        $dbman = $DB->get_manager();
        $table = new \xmldb_table('local_bitrixsync_catmap');

        $result = [
            'time' => time(),
            'table_exists' => $dbman->table_exists($table),
            'columns' => [],
            'indexes' => [],
            'rows' => [],
            'duplicates' => [],
            'probe' => [],
        ];

        if (!$result['table_exists']) {
            $result['error'] = 'local_bitrixsync_catmap table missing';
            return $this->finish($result);
        }

        $columns = $DB->get_columns('local_bitrixsync_catmap');
        foreach ($columns as $name => $column) {
            $result['columns'][$name] = [
                'name' => $name,
                'type' => (string)($column->type ?? ''),
                'max_length' => (int)($column->max_length ?? 0),
                'not_null' => !empty($column->not_null),
                'has_default' => property_exists($column, 'default_value'),
                'default' => property_exists($column, 'default_value')
                    ? $column->default_value
                    : null,
                'auto_increment' => !empty($column->auto_increment),
            ];
        }

        $indexes = $DB->get_indexes('local_bitrixsync_catmap');
        foreach ($indexes as $name => $index) {
            $result['indexes'][$name] = [
                'unique' => !empty($index['unique']),
                'columns' => array_values((array)($index['columns'] ?? [])),
            ];
        }

        if (empty($categoryids)) {
            $categoryids = [8, 9, 10];
        }
        $categoryids = array_values(array_unique(array_map('intval', $categoryids)));

        foreach ($categoryids as $categoryid) {
            if ($categoryid <= 0) {
                continue;
            }

            $category = $DB->get_record(
                'course_categories',
                ['id' => $categoryid],
                'id,name,parent,depth,idnumber,visible',
                IGNORE_MISSING
            );

            $rows = $DB->get_records(
                'local_bitrixsync_catmap',
                ['categoryid' => $categoryid],
                'id ASC'
            );

            $result['rows'][] = [
                'categoryid' => $categoryid,
                'category' => $category ? [
                    'name' => (string)$category->name,
                    'parent' => (int)$category->parent,
                    'depth' => (int)$category->depth,
                    'idnumber' => (string)$category->idnumber,
                    'visible' => (int)$category->visible,
                ] : null,
                'mapping_rows' => array_map(
                    static function($row) {
                        return (array)$row;
                    },
                    array_values($rows)
                ),
            ];

            $count = $DB->count_records(
                'local_bitrixsync_catmap',
                ['categoryid' => $categoryid]
            );
            if ($count > 1) {
                $result['duplicates'][] = [
                    'field' => 'categoryid',
                    'value' => $categoryid,
                    'count' => $count,
                ];
            }
        }

        // Detect any duplicate section IDs across the whole mapping table.
        if (isset($columns['bitrix_section_id'])) {
            $dupes = $DB->get_records_sql(
                "SELECT bitrix_section_id, COUNT(1) AS cnt
                   FROM {local_bitrixsync_catmap}
                  WHERE bitrix_section_id IS NOT NULL
                    AND bitrix_section_id > 0
               GROUP BY bitrix_section_id
                 HAVING COUNT(1) > 1"
            );

            foreach ($dupes as $dupe) {
                $result['duplicates'][] = [
                    'field' => 'bitrix_section_id',
                    'value' => (int)$dupe->bitrix_section_id,
                    'count' => (int)$dupe->cnt,
                ];
            }
        }

        // Rollback-only DB probe: exercise the mapper write shape without persisting.
        foreach ($categoryids as $categoryid) {
            if ($categoryid <= 0) {
                continue;
            }

            $existing = $DB->get_record(
                'local_bitrixsync_catmap',
                ['categoryid' => $categoryid],
                '*',
                IGNORE_MISSING
            );

            if (!$existing) {
                $result['probe'][] = [
                    'categoryid' => $categoryid,
                    'status' => 'skipped',
                    'reason' => 'no_existing_mapping_row',
                ];
                continue;
            }

            $transaction = $DB->start_delegated_transaction();
            try {
                $probe = clone $existing;

                // Touch only fields that are already present in the real row.
                if (property_exists($probe, 'timemodified')) {
                    $probe->timemodified = time();
                }
                if (property_exists($probe, 'status')) {
                    $probe->status = 'diag_probe';
                }

                $DB->update_record('local_bitrixsync_catmap', $probe);

                $transaction->rollback(new \moodle_exception(
                    'category_mapping_db_probe_rollback',
                    'local_bitrixsync'
                ));
            } catch (\Throwable $e) {
                // Moodle delegated rollback intentionally throws.
                if ($e instanceof \moodle_exception &&
                        $e->errorcode === 'category_mapping_db_probe_rollback') {
                    $result['probe'][] = [
                        'categoryid' => $categoryid,
                        'status' => 'ok',
                        'reason' => 'update_record_shape_is_valid',
                    ];
                    continue;
                }

                try {
                    $transaction->rollback($e);
                } catch (\Throwable $ignored) {
                    // Preserve original exception details below.
                }

                $result['probe'][] = [
                    'categoryid' => $categoryid,
                    'status' => 'error',
                    'exception_class' => get_class($e),
                    'message' => $e->getMessage(),
                    'errorcode' => property_exists($e, 'errorcode')
                        ? (string)$e->errorcode
                        : '',
                    'debuginfo' => property_exists($e, 'debuginfo')
                        ? (string)$e->debuginfo
                        : '',
                ];
            }
        }

        return $this->finish($result);
    }

    private function finish(array $result): array {
        set_config(
            'category_mapping_db_debug_last',
            json_encode(
                $result,
                JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
            ),
            'local_bitrixsync'
        );

        return $result;
    }
}
