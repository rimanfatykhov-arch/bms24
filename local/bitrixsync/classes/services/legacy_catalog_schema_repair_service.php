<?php
namespace local_bitrixsync\services;

defined('MOODLE_INTERNAL') || die();

/**
 * Explicit administrator-triggered migration for historic catalog mapping tables.
 *
 * Additive only:
 * - creates missing canonical fields;
 * - copies legacy values forward;
 * - never deletes legacy columns or rows.
 */
class legacy_catalog_schema_repair_service {
    public function run(): array {
        global $DB;

        $dbman = $DB->get_manager();
        $result = [
            'time' => time(),
            'catmap' => [],
            'productmap' => [],
            'success' => true,
            'errors' => [],
        ];

        try {
            $result['catmap'] = $this->repair_catmap($dbman);
        } catch (\Throwable $e) {
            $result['success'] = false;
            $result['errors'][] = [
                'table' => 'local_bitrixsync_catmap',
                'class' => get_class($e),
                'message' => $e->getMessage(),
                'errorcode' => property_exists($e, 'errorcode') ? (string)$e->errorcode : '',
                'debuginfo' => property_exists($e, 'debuginfo') ? (string)$e->debuginfo : '',
            ];
        }

        try {
            $result['productmap'] = $this->repair_productmap($dbman);
        } catch (\Throwable $e) {
            $result['success'] = false;
            $result['errors'][] = [
                'table' => 'local_bitrixsync_productmap',
                'class' => get_class($e),
                'message' => $e->getMessage(),
                'errorcode' => property_exists($e, 'errorcode') ? (string)$e->errorcode : '',
                'debuginfo' => property_exists($e, 'debuginfo') ? (string)$e->debuginfo : '',
            ];
        }

        set_config(
            'catalog_manual_schema_repair_last',
            json_encode($result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'local_bitrixsync'
        );

        if (!empty($result['success'])) {
            set_config(
                'catalog_legacy_schema_migrated',
                time(),
                'local_bitrixsync'
            );
        }

        return $result;
    }

    private function repair_catmap($dbman): array {
        global $DB;

        $table = new \xmldb_table('local_bitrixsync_catmap');

        $state = [
            'table_exists' => $dbman->table_exists($table),
            'before' => [],
            'after' => [],
            'added_fields' => [],
            'copied' => [
                'sectionid_to_bitrix_section_id' => 0,
                'parentcategoryid_to_bitrix_parent_section_id' => 0,
                'lastsync_to_last_sync' => 0,
            ],
        ];

        if (!$state['table_exists']) {
            throw new \moodle_exception(
                'catalog_mapping_table_missing',
                'local_bitrixsync'
            );
        }

        $state['before'] = array_keys($DB->get_columns('local_bitrixsync_catmap'));

        $fields = [
            'bitrix_section_id' => new \xmldb_field(
                'bitrix_section_id',
                XMLDB_TYPE_INTEGER,
                '10'
            ),
            'bitrix_parent_section_id' => new \xmldb_field(
                'bitrix_parent_section_id',
                XMLDB_TYPE_INTEGER,
                '10'
            ),
            'iblockid' => new \xmldb_field(
                'iblockid',
                XMLDB_TYPE_INTEGER,
                '10'
            ),
            'status' => new \xmldb_field(
                'status',
                XMLDB_TYPE_CHAR,
                '30',
                null,
                XMLDB_NOTNULL,
                null,
                'mapped'
            ),
            'last_sync' => new \xmldb_field(
                'last_sync',
                XMLDB_TYPE_INTEGER,
                '10'
            ),
            'error' => new \xmldb_field(
                'error',
                XMLDB_TYPE_TEXT
            ),
            'timecreated' => new \xmldb_field(
                'timecreated',
                XMLDB_TYPE_INTEGER,
                '10',
                null,
                XMLDB_NOTNULL,
                null,
                '0'
            ),
            'timemodified' => new \xmldb_field(
                'timemodified',
                XMLDB_TYPE_INTEGER,
                '10',
                null,
                XMLDB_NOTNULL,
                null,
                '0'
            ),
        ];

        foreach ($fields as $name => $field) {
            if (!$dbman->field_exists($table, $field)) {
                $dbman->add_field($table, $field);
                $state['added_fields'][] = $name;
            }
        }

        $columns = $DB->get_columns('local_bitrixsync_catmap');

        if (isset($columns['sectionid']) && isset($columns['bitrix_section_id'])) {
            $before = $DB->count_records_select(
                'local_bitrixsync_catmap',
                '(bitrix_section_id IS NULL OR bitrix_section_id = 0) AND sectionid > 0'
            );

            if ($before > 0) {
                $DB->execute(
                    "UPDATE {local_bitrixsync_catmap}
                        SET bitrix_section_id = sectionid
                      WHERE (bitrix_section_id IS NULL OR bitrix_section_id = 0)
                        AND sectionid IS NOT NULL
                        AND sectionid > 0"
                );
            }

            $state['copied']['sectionid_to_bitrix_section_id'] = $before;
        }

        if (isset($columns['parentcategoryid']) &&
                isset($columns['bitrix_parent_section_id'])) {
            $before = $DB->count_records_select(
                'local_bitrixsync_catmap',
                '(bitrix_parent_section_id IS NULL OR bitrix_parent_section_id = 0) AND parentcategoryid > 0'
            );

            if ($before > 0) {
                $DB->execute(
                    "UPDATE {local_bitrixsync_catmap}
                        SET bitrix_parent_section_id = parentcategoryid
                      WHERE (bitrix_parent_section_id IS NULL OR bitrix_parent_section_id = 0)
                        AND parentcategoryid IS NOT NULL
                        AND parentcategoryid > 0"
                );
            }

            $state['copied']['parentcategoryid_to_bitrix_parent_section_id'] = $before;
        }

        if (isset($columns['lastsync']) && isset($columns['last_sync'])) {
            $before = $DB->count_records_select(
                'local_bitrixsync_catmap',
                '(last_sync IS NULL OR last_sync = 0) AND lastsync > 0'
            );

            if ($before > 0) {
                $DB->execute(
                    "UPDATE {local_bitrixsync_catmap}
                        SET last_sync = lastsync
                      WHERE (last_sync IS NULL OR last_sync = 0)
                        AND lastsync IS NOT NULL
                        AND lastsync > 0"
                );
            }

            $state['copied']['lastsync_to_last_sync'] = $before;
        }

        $now = time();

        if (isset($columns['status'])) {
            $DB->execute(
                "UPDATE {local_bitrixsync_catmap}
                    SET status = 'mapped'
                  WHERE status IS NULL OR status = ''"
            );
        }

        if (isset($columns['timecreated'])) {
            $DB->execute(
                "UPDATE {local_bitrixsync_catmap}
                    SET timecreated = :now
                  WHERE timecreated IS NULL OR timecreated = 0",
                ['now' => $now]
            );
        }

        if (isset($columns['timemodified'])) {
            $DB->execute(
                "UPDATE {local_bitrixsync_catmap}
                    SET timemodified = CASE
                        WHEN last_sync IS NOT NULL AND last_sync > 0 THEN last_sync
                        ELSE :now
                    END
                  WHERE timemodified IS NULL OR timemodified = 0",
                ['now' => $now]
            );
        }

        $state['after'] = array_keys($DB->get_columns('local_bitrixsync_catmap'));

        return $state;
    }

    private function repair_productmap($dbman): array {
        global $DB;

        $table = new \xmldb_table('local_bitrixsync_productmap');

        $state = [
            'table_exists' => $dbman->table_exists($table),
            'before' => [],
            'after' => [],
            'added_fields' => [],
            'copied' => [],
        ];

        if (!$state['table_exists']) {
            return $state;
        }

        $state['before'] = array_keys($DB->get_columns('local_bitrixsync_productmap'));

        $fields = [
            'coursecode' => new \xmldb_field(
                'coursecode',
                XMLDB_TYPE_CHAR,
                '100'
            ),
            'categoryid' => new \xmldb_field(
                'categoryid',
                XMLDB_TYPE_INTEGER,
                '10'
            ),
            'bitrix_section_id' => new \xmldb_field(
                'bitrix_section_id',
                XMLDB_TYPE_INTEGER,
                '10'
            ),
            'iblockid' => new \xmldb_field(
                'iblockid',
                XMLDB_TYPE_INTEGER,
                '10'
            ),
            'source' => new \xmldb_field(
                'source',
                XMLDB_TYPE_CHAR,
                '20',
                null,
                XMLDB_NOTNULL,
                null,
                'moodle'
            ),
            'status' => new \xmldb_field(
                'status',
                XMLDB_TYPE_CHAR,
                '30',
                null,
                XMLDB_NOTNULL,
                null,
                'mapped'
            ),
            'last_sync' => new \xmldb_field(
                'last_sync',
                XMLDB_TYPE_INTEGER,
                '10'
            ),
            'error' => new \xmldb_field(
                'error',
                XMLDB_TYPE_TEXT
            ),
            'active' => new \xmldb_field(
                'active',
                XMLDB_TYPE_INTEGER,
                '1',
                null,
                XMLDB_NOTNULL,
                null,
                '1'
            ),
            'timecreated' => new \xmldb_field(
                'timecreated',
                XMLDB_TYPE_INTEGER,
                '10',
                null,
                XMLDB_NOTNULL,
                null,
                '0'
            ),
            'timemodified' => new \xmldb_field(
                'timemodified',
                XMLDB_TYPE_INTEGER,
                '10',
                null,
                XMLDB_NOTNULL,
                null,
                '0'
            ),
        ];

        foreach ($fields as $name => $field) {
            if (!$dbman->field_exists($table, $field)) {
                $dbman->add_field($table, $field);
                $state['added_fields'][] = $name;
            }
        }

        $columns = $DB->get_columns('local_bitrixsync_productmap');

        foreach (['sectionid', 'bitrix_sectionid'] as $legacy) {
            if (isset($columns[$legacy]) && isset($columns['bitrix_section_id'])) {
                $before = $DB->count_records_select(
                    'local_bitrixsync_productmap',
                    "(bitrix_section_id IS NULL OR bitrix_section_id = 0) AND {$legacy} > 0"
                );

                if ($before > 0) {
                    $DB->execute(
                        "UPDATE {local_bitrixsync_productmap}
                            SET bitrix_section_id = {$legacy}
                          WHERE (bitrix_section_id IS NULL OR bitrix_section_id = 0)
                            AND {$legacy} IS NOT NULL
                            AND {$legacy} > 0"
                    );
                }

                $state['copied'][$legacy . '_to_bitrix_section_id'] = $before;
                break;
            }
        }

        if (isset($columns['lastsync']) && isset($columns['last_sync'])) {
            $before = $DB->count_records_select(
                'local_bitrixsync_productmap',
                '(last_sync IS NULL OR last_sync = 0) AND lastsync > 0'
            );

            if ($before > 0) {
                $DB->execute(
                    "UPDATE {local_bitrixsync_productmap}
                        SET last_sync = lastsync
                      WHERE (last_sync IS NULL OR last_sync = 0)
                        AND lastsync IS NOT NULL
                        AND lastsync > 0"
                );
            }

            $state['copied']['lastsync_to_last_sync'] = $before;
        }

        $now = time();

        $DB->execute(
            "UPDATE {local_bitrixsync_productmap}
                SET source = 'moodle'
              WHERE source IS NULL OR source = ''"
        );
        $DB->execute(
            "UPDATE {local_bitrixsync_productmap}
                SET status = 'mapped'
              WHERE status IS NULL OR status = ''"
        );
        $DB->execute(
            "UPDATE {local_bitrixsync_productmap}
                SET active = 1
              WHERE active IS NULL"
        );
        $DB->execute(
            "UPDATE {local_bitrixsync_productmap}
                SET timecreated = :now
              WHERE timecreated IS NULL OR timecreated = 0",
            ['now' => $now]
        );
        $DB->execute(
            "UPDATE {local_bitrixsync_productmap}
                SET timemodified = CASE
                    WHEN last_sync IS NOT NULL AND last_sync > 0 THEN last_sync
                    ELSE :now
                END
              WHERE timemodified IS NULL OR timemodified = 0",
            ['now' => $now]
        );

        $state['after'] = array_keys($DB->get_columns('local_bitrixsync_productmap'));

        return $state;
    }
}
