<?php
defined('MOODLE_INTERNAL') || die();

function xmldb_local_bitrixsync_upgrade($oldversion) {
    global $DB;

    $dbman = $DB->get_manager();

    if ($oldversion < 2026082100) {
        // Enrollment table: create or bring previous alpha schema up to the v5 foundation.
        $table = new xmldb_table('local_bitrixsync_enrollment');
        if (!$dbman->table_exists($table)) {
            $table->add_field('id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE);
            $table->add_field('dealid', XMLDB_TYPE_INTEGER, '10');
            $table->add_field('companyid', XMLDB_TYPE_INTEGER, '10');
            $table->add_field('contactid', XMLDB_TYPE_INTEGER, '10');
            $table->add_field('userid', XMLDB_TYPE_INTEGER, '10');
            $table->add_field('productid', XMLDB_TYPE_INTEGER, '10');
            $table->add_field('productrowid', XMLDB_TYPE_INTEGER, '10');
            $table->add_field('courseid', XMLDB_TYPE_INTEGER, '10');
            $table->add_field('groupid', XMLDB_TYPE_INTEGER, '10');
            $table->add_field('status', XMLDB_TYPE_CHAR, '50', null, XMLDB_NOTNULL, null, 'pending');
            $table->add_field('error', XMLDB_TYPE_TEXT, null);
            $table->add_field('timecreated', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $table->add_field('timemodified', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $table->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);
            $table->add_index('deal_idx', XMLDB_INDEX_NOTUNIQUE, ['dealid']);
            $table->add_index('userid_idx', XMLDB_INDEX_NOTUNIQUE, ['userid']);
            $table->add_index('course_idx', XMLDB_INDEX_NOTUNIQUE, ['courseid']);
            $table->add_index('deal_user_course_idx', XMLDB_INDEX_NOTUNIQUE, ['dealid', 'userid', 'courseid']);
            $dbman->create_table($table);
        } else {
            $fields = [
                new xmldb_field('companyid', XMLDB_TYPE_INTEGER, '10'),
                new xmldb_field('contactid', XMLDB_TYPE_INTEGER, '10'),
                new xmldb_field('productid', XMLDB_TYPE_INTEGER, '10'),
                new xmldb_field('productrowid', XMLDB_TYPE_INTEGER, '10'),
                new xmldb_field('groupid', XMLDB_TYPE_INTEGER, '10'),
                new xmldb_field('error', XMLDB_TYPE_TEXT),
                new xmldb_field('timemodified', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0'),
            ];
            foreach ($fields as $field) {
                if (!$dbman->field_exists($table, $field)) {
                    $dbman->add_field($table, $field);
                }
            }
            $indexes = [
                new xmldb_index('deal_idx', XMLDB_INDEX_NOTUNIQUE, ['dealid']),
                new xmldb_index('userid_idx', XMLDB_INDEX_NOTUNIQUE, ['userid']),
                new xmldb_index('course_idx', XMLDB_INDEX_NOTUNIQUE, ['courseid']),
                new xmldb_index('deal_user_course_idx', XMLDB_INDEX_NOTUNIQUE, ['dealid', 'userid', 'courseid']),
            ];
            foreach ($indexes as $index) {
                if (!$dbman->index_exists($table, $index)) {
                    $dbman->add_index($table, $index);
                }
            }
        }

        // Product mapping table.
        $table = new xmldb_table('local_bitrixsync_productmap');
        if (!$dbman->table_exists($table)) {
            $table->add_field('id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE);
            $table->add_field('bitrix_product_id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL);
            $table->add_field('courseid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL);
            $table->add_field('coursecode', XMLDB_TYPE_CHAR, '100');
            $table->add_field('categoryid', XMLDB_TYPE_INTEGER, '10');
            $table->add_field('active', XMLDB_TYPE_INTEGER, '1', null, XMLDB_NOTNULL, null, '1');
            $table->add_field('timecreated', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $table->add_field('timemodified', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $table->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);
            $table->add_index('product_idx', XMLDB_INDEX_NOTUNIQUE, ['bitrix_product_id']);
            $table->add_index('course_idx', XMLDB_INDEX_NOTUNIQUE, ['courseid']);
            $dbman->create_table($table);
        } else {
            $fields = [
                new xmldb_field('coursecode', XMLDB_TYPE_CHAR, '100'),
                new xmldb_field('categoryid', XMLDB_TYPE_INTEGER, '10'),
                new xmldb_field('active', XMLDB_TYPE_INTEGER, '1', null, XMLDB_NOTNULL, null, '1'),
                new xmldb_field('timecreated', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0'),
                new xmldb_field('timemodified', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0'),
            ];
            foreach ($fields as $field) {
                if (!$dbman->field_exists($table, $field)) {
                    $dbman->add_field($table, $field);
                }
            }
            $indexes = [
                new xmldb_index('product_idx', XMLDB_INDEX_NOTUNIQUE, ['bitrix_product_id']),
                new xmldb_index('course_idx', XMLDB_INDEX_NOTUNIQUE, ['courseid']),
            ];
            foreach ($indexes as $index) {
                if (!$dbman->index_exists($table, $index)) {
                    $dbman->add_index($table, $index);
                }
            }
        }

        // Company bridge.
        $table = new xmldb_table('local_bitrixsync_company');
        if (!$dbman->table_exists($table)) {
            $table->add_field('id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE);
            $table->add_field('bitrix_company_id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL);
            $table->add_field('moodle_company_id', XMLDB_TYPE_INTEGER, '10');
            $table->add_field('corporate_company_id', XMLDB_TYPE_INTEGER, '10');
            $table->add_field('license_id', XMLDB_TYPE_INTEGER, '10');
            $table->add_field('seats_total', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $table->add_field('seats_used', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $table->add_field('status', XMLDB_TYPE_CHAR, '50', null, XMLDB_NOTNULL, null, 'active');
            $table->add_field('last_sync', XMLDB_TYPE_INTEGER, '10');
            $table->add_field('timecreated', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $table->add_field('timemodified', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $table->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);
            $table->add_index('bitrix_company_idx', XMLDB_INDEX_UNIQUE, ['bitrix_company_id']);
            $table->add_index('corporate_company_idx', XMLDB_INDEX_NOTUNIQUE, ['corporate_company_id']);
            $dbman->create_table($table);
        }

        // Contact bridge.
        $table = new xmldb_table('local_bitrixsync_contact');
        if (!$dbman->table_exists($table)) {
            $table->add_field('id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE);
            $table->add_field('bitrix_contact_id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL);
            $table->add_field('userid', XMLDB_TYPE_INTEGER, '10');
            $table->add_field('position', XMLDB_TYPE_CHAR, '255');
            $table->add_field('phone', XMLDB_TYPE_CHAR, '50');
            $table->add_field('corporate_employee', XMLDB_TYPE_INTEGER, '1', null, XMLDB_NOTNULL, null, '0');
            $table->add_field('last_login', XMLDB_TYPE_INTEGER, '10');
            $table->add_field('timecreated', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $table->add_field('timemodified', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $table->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);
            $table->add_index('bitrix_contact_idx', XMLDB_INDEX_UNIQUE, ['bitrix_contact_id']);
            $table->add_index('userid_idx', XMLDB_INDEX_NOTUNIQUE, ['userid']);
            $dbman->create_table($table);
        }

        // Sync queue.
        $table = new xmldb_table('local_bitrixsync_syncqueue');
        if (!$dbman->table_exists($table)) {
            $table->add_field('id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE);
            $table->add_field('entity_type', XMLDB_TYPE_CHAR, '50', null, XMLDB_NOTNULL);
            $table->add_field('entity_id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL);
            $table->add_field('action', XMLDB_TYPE_CHAR, '100', null, XMLDB_NOTNULL);
            $table->add_field('status', XMLDB_TYPE_CHAR, '50', null, XMLDB_NOTNULL, null, 'pending');
            $table->add_field('attempts', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $table->add_field('payload', XMLDB_TYPE_TEXT);
            $table->add_field('error', XMLDB_TYPE_TEXT);
            $table->add_field('timecreated', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $table->add_field('timemodified', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $table->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);
            $table->add_index('entity_idx', XMLDB_INDEX_NOTUNIQUE, ['entity_type', 'entity_id']);
            $table->add_index('status_idx', XMLDB_INDEX_NOTUNIQUE, ['status']);
            $dbman->create_table($table);
        }

        // Keep production traffic on the legacy mode until v5 is explicitly enabled.
        if (get_config('local_bitrixsync', 'engine_mode') === false) {
            set_config('engine_mode', 'legacy', 'local_bitrixsync');
        }

        upgrade_plugin_savepoint(true, 2026082100, 'local', 'bitrixsync');
    }

    if ($oldversion < 2026082101) {
        // v5.0.6-alpha binds KBA Connect to the stable KBA Corporate Dashboard
        // Public API v1.9. No direct Corporate Dashboard table access is added.
        if (get_config('local_bitrixsync', 'corporate_license_mode') === false) {
            set_config('corporate_license_mode', 'enforce', 'local_bitrixsync');
        }

        upgrade_plugin_savepoint(true, 2026082101, 'local', 'bitrixsync');
    }


    if ($oldversion < 2026082102) {
        $table = new xmldb_table('local_bitrixsync_prodcache');

        if (!$dbman->table_exists($table)) {
            $table->add_field('id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE);
            $table->add_field('bitrix_product_id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL);
            $table->add_field('name', XMLDB_TYPE_CHAR, '255', null, XMLDB_NOTNULL, null, '');
            $table->add_field('code', XMLDB_TYPE_CHAR, '100');
            $table->add_field('xmlid', XMLDB_TYPE_CHAR, '255');
            $table->add_field('iblockid', XMLDB_TYPE_INTEGER, '10');
            $table->add_field('sectionid', XMLDB_TYPE_INTEGER, '10');
            $table->add_field('active', XMLDB_TYPE_INTEGER, '1', null, XMLDB_NOTNULL, null, '1');
            $table->add_field('source', XMLDB_TYPE_CHAR, '20', null, XMLDB_NOTNULL, null, 'catalog');
            $table->add_field('timesynced', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $table->add_field('timecreated', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $table->add_field('timemodified', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');

            $table->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);
            $table->add_index('product_idx', XMLDB_INDEX_UNIQUE, ['bitrix_product_id']);
            $table->add_index('active_idx', XMLDB_INDEX_NOTUNIQUE, ['active']);

            $dbman->create_table($table);
        }

        upgrade_plugin_savepoint(true, 2026082102, 'local', 'bitrixsync');
    }


    if ($oldversion < 2026082103) {
        // Normalize the currently stored webhook URL if an administrator
        // pasted a full Bitrix REST method URL instead of the base webhook.
        $configured = trim((string)get_config('local_bitrixsync', 'bitrix_webhook_url'));
        if ($configured !== '') {
            $normalized = \local_bitrixsync\adapters\bitrix24\client::normalize_webhook_url($configured);
            if ($normalized !== $configured) {
                set_config('bitrix_webhook_url', $normalized, 'local_bitrixsync');
            }
        }

        upgrade_plugin_savepoint(true, 2026082103, 'local', 'bitrixsync');
    }


    if ($oldversion < 2026082104) {
        // Moodle Master Catalog: enrich course->product bindings.
        $table = new xmldb_table('local_bitrixsync_productmap');

        $fields = [
            new xmldb_field('bitrix_section_id', XMLDB_TYPE_INTEGER, '10'),
            new xmldb_field('iblockid', XMLDB_TYPE_INTEGER, '10'),
            new xmldb_field('source', XMLDB_TYPE_CHAR, '20', null, XMLDB_NOTNULL, null, 'moodle'),
            new xmldb_field('status', XMLDB_TYPE_CHAR, '30', null, XMLDB_NOTNULL, null, 'mapped'),
            new xmldb_field('last_sync', XMLDB_TYPE_INTEGER, '10'),
            new xmldb_field('error', XMLDB_TYPE_TEXT),
        ];

        foreach ($fields as $field) {
            if (!$dbman->field_exists($table, $field)) {
                $dbman->add_field($table, $field);
            }
        }

        // Moodle category -> Bitrix catalog section.
        $table = new xmldb_table('local_bitrixsync_catmap');

        if (!$dbman->table_exists($table)) {
            $table->add_field('id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE);
            $table->add_field('categoryid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL);
            $table->add_field('bitrix_section_id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL);
            $table->add_field('bitrix_parent_section_id', XMLDB_TYPE_INTEGER, '10');
            $table->add_field('iblockid', XMLDB_TYPE_INTEGER, '10');
            $table->add_field('name', XMLDB_TYPE_CHAR, '255', null, XMLDB_NOTNULL, null, '');
            $table->add_field('status', XMLDB_TYPE_CHAR, '30', null, XMLDB_NOTNULL, null, 'synced');
            $table->add_field('last_sync', XMLDB_TYPE_INTEGER, '10');
            $table->add_field('error', XMLDB_TYPE_TEXT);
            $table->add_field('timecreated', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $table->add_field('timemodified', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');

            $table->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);
            $table->add_index('category_idx', XMLDB_INDEX_UNIQUE, ['categoryid']);
            $table->add_index('section_idx', XMLDB_INDEX_NOTUNIQUE, ['bitrix_section_id']);

            $dbman->create_table($table);
        }

        set_config('catalog_master_source', 'moodle', 'local_bitrixsync');

        if (get_config('local_bitrixsync', 'catalog_allow_legacy_write') === false) {
            set_config('catalog_allow_legacy_write', 1, 'local_bitrixsync');
        }

        upgrade_plugin_savepoint(true, 2026082104, 'local', 'bitrixsync');
    }


    if ($oldversion < 2026082105) {
        // Contact -> Moodle user bridge enrichment.
        $table = new xmldb_table('local_bitrixsync_contact');

        $fields = [
            new xmldb_field('username', XMLDB_TYPE_CHAR, '100'),
            new xmldb_field('email', XMLDB_TYPE_CHAR, '255'),
            new xmldb_field('status', XMLDB_TYPE_CHAR, '30', null, XMLDB_NOTNULL, null, 'new'),
            new xmldb_field('last_sync', XMLDB_TYPE_INTEGER, '10'),
            new xmldb_field('error', XMLDB_TYPE_TEXT),
        ];

        foreach ($fields as $field) {
            if (!$dbman->field_exists($table, $field)) {
                $dbman->add_field($table, $field);
            }
        }

        // Deal processing audit.
        $table = new xmldb_table('local_bitrixsync_dealrun');

        if (!$dbman->table_exists($table)) {
            $table->add_field('id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE);
            $table->add_field('dealid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL);
            $table->add_field('companyid', XMLDB_TYPE_INTEGER, '10');
            $table->add_field('contactid', XMLDB_TYPE_INTEGER, '10');
            $table->add_field('userid', XMLDB_TYPE_INTEGER, '10');
            $table->add_field('dryrun', XMLDB_TYPE_INTEGER, '1', null, XMLDB_NOTNULL, null, '1');
            $table->add_field('status', XMLDB_TYPE_CHAR, '50', null, XMLDB_NOTNULL, null, 'processing');
            $table->add_field('productrows', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $table->add_field('resolved', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $table->add_field('enrolled', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $table->add_field('already', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $table->add_field('failed', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $table->add_field('payload', XMLDB_TYPE_TEXT);
            $table->add_field('error', XMLDB_TYPE_TEXT);
            $table->add_field('timecreated', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $table->add_field('timemodified', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');

            $table->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);
            $table->add_index('deal_idx', XMLDB_INDEX_NOTUNIQUE, ['dealid']);
            $table->add_index('status_idx', XMLDB_INDEX_NOTUNIQUE, ['status']);
            $table->add_index('time_idx', XMLDB_INDEX_NOTUNIQUE, ['timecreated']);

            $dbman->create_table($table);
        }

        // Safe defaults for the alpha: resolve existing users, but do not
        // create new Moodle accounts until an administrator explicitly enables it.
        if (get_config('local_bitrixsync', 'auto_create_users') === false) {
            set_config('auto_create_users', 0, 'local_bitrixsync');
        }

        if (get_config('local_bitrixsync', 'match_users_by_email') === false) {
            set_config('match_users_by_email', 1, 'local_bitrixsync');
        }

        upgrade_plugin_savepoint(true, 2026082105, 'local', 'bitrixsync');
    }


    if ($oldversion < 2026082106) {
        $table = new xmldb_table('local_bitrixsync_pralias');

        if (!$dbman->table_exists($table)) {
            $table->add_field('id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE);
            $table->add_field('legacy_product_id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL);
            $table->add_field('legacy_product_name', XMLDB_TYPE_CHAR, '255', null, XMLDB_NOTNULL, null, '');
            $table->add_field('courseid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL);
            $table->add_field('canonical_product_id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL);
            $table->add_field('match_method', XMLDB_TYPE_CHAR, '50', null, XMLDB_NOTNULL, null, 'exact_name');
            $table->add_field('status', XMLDB_TYPE_CHAR, '30', null, XMLDB_NOTNULL, null, 'active');
            $table->add_field('active', XMLDB_TYPE_INTEGER, '1', null, XMLDB_NOTNULL, null, '1');
            $table->add_field('last_used', XMLDB_TYPE_INTEGER, '10');
            $table->add_field('error', XMLDB_TYPE_TEXT);
            $table->add_field('timecreated', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $table->add_field('timemodified', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');

            $table->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);
            $table->add_index('legacy_product_idx', XMLDB_INDEX_UNIQUE, ['legacy_product_id']);
            $table->add_index('course_idx', XMLDB_INDEX_NOTUNIQUE, ['courseid']);
            $table->add_index('canonical_product_idx', XMLDB_INDEX_NOTUNIQUE, ['canonical_product_id']);
            $table->add_index('active_idx', XMLDB_INDEX_NOTUNIQUE, ['active']);

            $dbman->create_table($table);
        }

        if (get_config('local_bitrixsync', 'legacy_alias_resolution') === false) {
            set_config('legacy_alias_resolution', 1, 'local_bitrixsync');
        }

        if (get_config('local_bitrixsync', 'legacy_alias_autosave') === false) {
            set_config('legacy_alias_autosave', 1, 'local_bitrixsync');
        }

        upgrade_plugin_savepoint(true, 2026082106, 'local', 'bitrixsync');
    }


    if ($oldversion < 2026082107) {
        // Persist Corporate licence/allocation identifiers for idempotent
        // diagnostics and repeat processing.
        $table = new xmldb_table('local_bitrixsync_enrollment');

        $fields = [
            new xmldb_field('corp_license_id', XMLDB_TYPE_INTEGER, '10'),
            new xmldb_field('corp_allocation_id', XMLDB_TYPE_INTEGER, '10'),
            new xmldb_field('corp_status', XMLDB_TYPE_CHAR, '30'),
        ];

        foreach ($fields as $field) {
            if (!$dbman->field_exists($table, $field)) {
                $dbman->add_field($table, $field);
            }
        }

        // CRM writeback/timeline audit.
        $table = new xmldb_table('local_bitrixsync_dealrun');

        $fields = [
            new xmldb_field('crm_status', XMLDB_TYPE_CHAR, '30'),
            new xmldb_field('crm_error', XMLDB_TYPE_TEXT),
            new xmldb_field('timeline_id', XMLDB_TYPE_INTEGER, '10'),
            new xmldb_field('company_timeline_id', XMLDB_TYPE_INTEGER, '10'),
        ];

        foreach ($fields as $field) {
            if (!$dbman->field_exists($table, $field)) {
                $dbman->add_field($table, $field);
            }
        }

        if (get_config('local_bitrixsync', 'crm_writeback_enable') === false) {
            set_config('crm_writeback_enable', 1, 'local_bitrixsync');
        }

        if (get_config('local_bitrixsync', 'crm_timeline_enable') === false) {
            set_config('crm_timeline_enable', 1, 'local_bitrixsync');
        }

        if (get_config('local_bitrixsync', 'v5_inbound_enabled') === false) {
            set_config('v5_inbound_enabled', 0, 'local_bitrixsync');
        }

        if ((string)get_config('local_bitrixsync', 'v5_inbound_token') === '') {
            try {
                $token = bin2hex(random_bytes(24));
            } catch (\Throwable $e) {
                $token = sha1(
                    uniqid('kba-connect-', true) . microtime(true)
                );
            }

            set_config('v5_inbound_token', $token, 'local_bitrixsync');
        }

        upgrade_plugin_savepoint(
            true,
            2026082107,
            'local',
            'bitrixsync'
        );
    }


    if ($oldversion < 2026082108) {
        if ((int)get_config(
            'local_bitrixsync',
            'bitrix_connect_timeout'
        ) <= 0) {
            set_config(
                'bitrix_connect_timeout',
                3,
                'local_bitrixsync'
            );
        }

        if ((int)get_config(
            'local_bitrixsync',
            'bitrix_request_timeout'
        ) <= 0) {
            set_config(
                'bitrix_request_timeout',
                8,
                'local_bitrixsync'
            );
        }

        // Force an explicit first refresh using the safe hotfix path.
        unset_config(
            'production_readiness_cache',
            'local_bitrixsync'
        );

        upgrade_plugin_savepoint(
            true,
            2026082108,
            'local',
            'bitrixsync'
        );
    }


    if ($oldversion < 2026082109) {
        // r2 moves CRM field creation from LSAPI web requests to Moodle cron.
        upgrade_plugin_savepoint(
            true,
            2026082109,
            'local',
            'bitrixsync'
        );
    }


    if ($oldversion < 2026082110) {
        // Admin navigation only: group all KBA Connect pages under one
        // category in Site administration -> Plugins -> Local plugins.
        upgrade_plugin_savepoint(
            true,
            2026082110,
            'local',
            'bitrixsync'
        );
    }


    if ($oldversion < 2026082111) {
        // Safe Activation release: CLI tooling only, no schema changes.
        upgrade_plugin_savepoint(
            true,
            2026082111,
            'local',
            'bitrixsync'
        );
    }



    if ($oldversion < 2026083112) {
        $table = new xmldb_table('local_bitrixsync_contact');
        $fields = [
            new xmldb_field('access_status', XMLDB_TYPE_CHAR, '30', null, XMLDB_NOTNULL, null, 'none'),
            new xmldb_field('access_sent_at', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0'),
            new xmldb_field('access_error', XMLDB_TYPE_TEXT),
        ];
        foreach ($fields as $field) {
            if (!$dbman->field_exists($table, $field)) {
                $dbman->add_field($table, $field);
            }
        }

        if (get_config('local_bitrixsync', 'access_delivery_enable') === false) {
            set_config('access_delivery_enable', 1, 'local_bitrixsync');
        }
        if (get_config('local_bitrixsync', 'access_recover_kba_users') === false) {
            set_config('access_recover_kba_users', 1, 'local_bitrixsync');
        }
        if (get_config('local_bitrixsync', 'access_write_legacy_password') === false) {
            set_config('access_write_legacy_password', 1, 'local_bitrixsync');
        }
        if (get_config('local_bitrixsync', 'corporate_employee_sync_enable') === false) {
            set_config('corporate_employee_sync_enable', 1, 'local_bitrixsync');
        }

        upgrade_plugin_savepoint(true, 2026083112, 'local', 'bitrixsync');
    }

    return true;

    if ($oldversion < 2026082400) {
        // Production Launch Pack safety defaults.
        if (get_config('local_bitrixsync', 'controlled_test_deal_id') === false) {
            set_config('controlled_test_deal_id', 3761, 'local_bitrixsync');
        }

        if (get_config('local_bitrixsync', 'v5_allowed_stages') === false) {
            set_config('v5_allowed_stages', '', 'local_bitrixsync');
        }

        // Auxiliary execution UI tables from the beta portal line.
        $table = new xmldb_table('local_bitrixsync_runs');
        if (!$dbman->table_exists($table)) {
            $table->add_field('id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE);
            $table->add_field('deal_id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL);
            $table->add_field('run_id', XMLDB_TYPE_CHAR, '128', null, XMLDB_NOTNULL);
            $table->add_field('status', XMLDB_TYPE_CHAR, '50', null, XMLDB_NOTNULL);
            $table->add_field('timecreated', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $table->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);
            $table->add_index('deal_idx', XMLDB_INDEX_NOTUNIQUE, ['deal_id']);
            $table->add_index('run_idx', XMLDB_INDEX_UNIQUE, ['run_id']);
            $dbman->create_table($table);
        }

        $table = new xmldb_table('local_bitrixsync_events');
        if (!$dbman->table_exists($table)) {
            $table->add_field('id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE);
            $table->add_field('run_id', XMLDB_TYPE_CHAR, '128', null, XMLDB_NOTNULL);
            $table->add_field('event', XMLDB_TYPE_CHAR, '100', null, XMLDB_NOTNULL);
            $table->add_field('status', XMLDB_TYPE_CHAR, '50', null, XMLDB_NOTNULL);
            $table->add_field('timecreated', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $table->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);
            $table->add_index('run_idx', XMLDB_INDEX_NOTUNIQUE, ['run_id']);
            $dbman->create_table($table);
        }

        // Force a new readiness refresh after the launch upgrade.
        unset_config('production_readiness_cache', 'local_bitrixsync');

        upgrade_plugin_savepoint(true, 2026082400, 'local', 'bitrixsync');
    }


    if ($oldversion < 2026082401) {
        // Keep inbound disabled on upgrade. Activation is a deliberate,
        // post-idempotency operation.
        set_config('v5_inbound_enabled', 0, 'local_bitrixsync');
        unset_config('production_readiness_cache', 'local_bitrixsync');

        upgrade_plugin_savepoint(true, 2026082401, 'local', 'bitrixsync');
    }


    if ($oldversion < 2026082402) {
        if (get_config(
                'local_bitrixsync',
                'production_wizard_bitrix_event_confirmed'
            ) === false) {
            set_config(
                'production_wizard_bitrix_event_confirmed',
                0,
                'local_bitrixsync'
            );
        }

        // Upgrade never enables automatic traffic by itself.
        set_config('v5_inbound_enabled', 0, 'local_bitrixsync');
        unset_config('production_readiness_cache', 'local_bitrixsync');

        upgrade_plugin_savepoint(true, 2026082402, 'local', 'bitrixsync');
    }


    if ($oldversion < 2026082403) {
        // Transport hardening only. Never enable automatic production traffic
        // as a side effect of this hotfix.
        set_config('v5_inbound_enabled', 0, 'local_bitrixsync');
        unset_config('production_readiness_cache', 'local_bitrixsync');

        upgrade_plugin_savepoint(true, 2026082403, 'local', 'bitrixsync');
    }


    if ($oldversion < 2026082407) {

        $tables = [
            'local_bitrixsync_rules' => [
                'name','description','active','priority','trigger_type'
            ],
            'local_bitrixsync_rule_conditions' => [
                'ruleid','field','operator','value'
            ],
            'local_bitrixsync_rule_actions' => [
                'ruleid','action','priority','active'
            ],
            'local_bitrixsync_rule_logs' => [
                'ruleid','dealid','status','message'
            ],
        ];

        // Tables are created by upgrade path in the next XML migration step.
        set_config('rule_engine_mode','database','local_bitrixsync');

        upgrade_plugin_savepoint(true, 2026082407, 'local', 'bitrixsync');
    }


    if ($oldversion < 2026082413) {
        // UI/navigation migration only. Do not change production state.
        unset_config('production_readiness_cache', 'local_bitrixsync');
        upgrade_plugin_savepoint(true, 2026082413, 'local', 'bitrixsync');
    }


    if ($oldversion < 2026082414) {
        // Funnel/stage selector UX migration only.
        // Keep existing comma-separated v5_allowed_stages fully compatible.
        // If a v0.4.6 snapshot exists, the service can use it until refreshed.
        unset_config('production_readiness_cache', 'local_bitrixsync');

        upgrade_plugin_savepoint(true, 2026082414, 'local', 'bitrixsync');
    }


    if ($oldversion < 2026082415) {
        // CRM field provisioning is moved out of browser requests.
        // No production/inbound settings are changed.
        upgrade_plugin_savepoint(true, 2026082415, 'local', 'bitrixsync');
    }


    if ($oldversion < 2026082416) {
        // Reuse the original v0.4.6 configuration names:
        // outgoingtoken / contactoutgoingtoken.
        if (get_config(
                'local_bitrixsync',
                'contactprofilesyncenabled'
            ) === false) {
            set_config(
                'contactprofilesyncenabled',
                0,
                'local_bitrixsync'
            );
        }

        // Security migration never enables inbound automatically.
        set_config(
            'v5_inbound_enabled',
            0,
            'local_bitrixsync'
        );
        unset_config(
            'production_readiness_cache',
            'local_bitrixsync'
        );

        upgrade_plugin_savepoint(
            true,
            2026082416,
            'local',
            'bitrixsync'
        );
    }


    if ($oldversion < 2026082417) {
        // Production Wizard UX only.
        // No automatic production setting is changed by this upgrade.
        unset_config(
            'production_readiness_cache',
            'local_bitrixsync'
        );

        upgrade_plugin_savepoint(
            true,
            2026082417,
            'local',
            'bitrixsync'
        );
    }


    if ($oldversion < 2026082418) {
        // Legacy CRM field compatibility / diagnostics only.
        // Preserve the v0.4.6 `fieldmap` config if it exists.
        unset_config(
            'production_readiness_cache',
            'local_bitrixsync'
        );

        upgrade_plugin_savepoint(
            true,
            2026082418,
            'local',
            'bitrixsync'
        );
    }


    if ($oldversion < 2026082419) {
        $defaults = [
            'accesschannel' => 'moodle',
            'accesssendername' => 'Бизнес Академия',
            'accesssenderemail' => 'info@kzn.mba',
            'accessemailsubject' =>
                'Доступ к образовательной среде Бизнес Академии',
            'accessemailbody' =>
                "Здравствуйте, {firstname}!\n\n" .
                "Для вас создан доступ к образовательной среде Бизнес Академии.\n\n" .
                "Адрес для входа: {url}\n" .
                "Логин: {login}\n" .
                "Временный пароль: {password}\n\n" .
                "При первом входе система попросит вас установить новый пароль.\n\n" .
                "Курс: {course}\n\n" .
                "Желаем продуктивного обучения!\n" .
                "Бизнес Академия\n" .
                "https://бизнес-академия.рф/",
            'timelineenabled' => 1,
            'timeline_sync_header' => '🎓 Moodle — синхронизация',
            'timeline_sync_user' => '👤 Ученик: {fullname}',
            'timeline_sync_email' => '📧 Email: {email}',
            'timeline_sync_course' =>
                '📚 Курс: {course} [ID {courseid}]',
            'timeline_sync_group' =>
                '👥 Группа: {group} [ID {groupid}]',
            'timeline_account_created' =>
                '✅ Создана учетная запись Moodle [User ID {userid}]',
            'timeline_course_enrolled' =>
                '✅ Пользователь зачислен на курс',
            'timeline_group_added' =>
                '✅ Пользователь добавлен в группу «{group}» [ID {groupid}]',
            'timeline_learning_started' =>
                "▶️ Ученик приступил к обучению\n" .
                "📈 Прогресс: {progress}%\n" .
                "🕒 Начало обучения: {startedat}",
            'timeline_course_completed' =>
                "🏁 Курс завершён\n" .
                "📈 Прогресс: {progress}%\n" .
                "🕒 Дата завершения: {completedat}",
            'timeline_access_sent' =>
                "🔐 Доступ ученику отправлен\n" .
                "📨 Канал: {channel}\n" .
                "🕒 Дата отправки: {accesssentat}\n" .
                "{activityline}",
            'timeline_sync_error' =>
                "⚠️ Ошибка синхронизации\n" .
                "🔧 Тип: {errortype}\n" .
                "❌ {error}",
            'timeline_first_login' =>
                "👋 Ученик впервые вошёл в Moodle\n" .
                "🕒 Первый вход: {firstloginat}",
            'timeline_progress_thresholds' => '25,50,75,100',
            'timeline_progress_threshold' =>
                "🎯 Достигнут порог: {threshold}%\n" .
                "📈 Текущий прогресс: {progress}%\n" .
                "✅ Выполнено активностей: {completedactivities} из {totalactivities}\n" .
                "🕒 Последняя активность: {lastactivity}",
            'timeline_webinar_attended' =>
                "🎥 Вебинар посещён\n" .
                "🖥️ Вебинар: {webinar}\n" .
                "🕒 Дата: {webinarat}\n" .
                "⏱️ Время участия: {webinarminutes} мин\n" .
                "📊 Посещено вебинаров: {webinarattended} из {webinartotal} ({webinarpercent}%)",
            'timeline_v5_deal_summary' =>
                "KBA Connect v5 — синхронизация обучения\n\n" .
                "Компания: {company}\n" .
                "Moodle User ID: {userid}\n" .
                "Курсов в сделке: {productrows}\n" .
                "Курсов обработано: {processed}\n" .
                "Новых зачислений: {enrolled}\n" .
                "Уже было доступно: {already}\n" .
                "Ошибок: {failed}\n\n" .
                "Программы:\n{courses}",
            'timeline_v5_company_summary' =>
                "KBA Connect — корпоративное обучение\n\n" .
                "Компания: {company}\n" .
                "Corporate Company ID: {corporatecompanyid}\n" .
                "Активных программ: {activecoursecount}\n" .
                "Сотрудников: {employeecount}\n\n" .
                "Программы:\n{courses}",
        ];

        foreach ($defaults as $name => $value) {
            if (get_config(
                    'local_bitrixsync',
                    $name
                ) === false) {
                set_config(
                    $name,
                    $value,
                    'local_bitrixsync'
                );
            }
        }

        upgrade_plugin_savepoint(
            true,
            2026082419,
            'local',
            'bitrixsync'
        );
    }


    if ($oldversion < 2026082420) {
        global $DB;

        // Clear only the previous broken CRM field background executions.
        // Existing field mappings and production data are preserved.
        $DB->delete_records_select(
            'local_bitrixsync_syncqueue',
            "entity_type = :entitytype
             AND action IN (:refresh, :provision)
             AND status IN (:failed, :processing)",
            [
                'entitytype' => 'crmfields',
                'refresh' => 'crm_fields_refresh_entity',
                'provision' => 'crm_fields_provision_entity',
                'failed' => 'failed',
                'processing' => 'processing',
            ]
        );

        unset_config(
            'production_readiness_cache',
            'local_bitrixsync'
        );

        upgrade_plugin_savepoint(
            true,
            2026082420,
            'local',
            'bitrixsync'
        );
    }


    if ($oldversion < 2026083110) {
        // v5.0.31.2: a paid/active deal may contain more than one contact.
        // Enable the intended automatic Contact -> Moodle User path and keep
        // email matching active. No existing user, mapping or enrollment data
        // is deleted or rewritten by this migration.
        set_config('match_users_by_email', 1, 'local_bitrixsync');
        set_config('auto_create_users', 1, 'local_bitrixsync');

        if (get_config('local_bitrixsync', 'logging_level') === false) {
            set_config('logging_level', 'normal', 'local_bitrixsync');
        }
        if (get_config('local_bitrixsync', 'debug_deal_id') === false) {
            set_config('debug_deal_id', 0, 'local_bitrixsync');
        }

        upgrade_plugin_savepoint(
            true,
            2026083110,
            'local',
            'bitrixsync'
        );
    }


    if ($oldversion < 2026083111) {
        // v5.0.31.3: Production default for Contact -> Moodle User automation.
        // The administrator explicitly approved automatic user creation for
        // linked Bitrix24 contacts with valid unique email addresses.
        set_config('match_users_by_email', 1, 'local_bitrixsync');
        set_config('auto_create_users', 1, 'local_bitrixsync');

        upgrade_plugin_savepoint(
            true,
            2026083111,
            'local',
            'bitrixsync'
        );
    }


    if ($oldversion < 2026083200) {
        // v5.0.32 RC1 — Corporate Role Schema Bridge.
        // No Corporate Dashboard tables are touched. Bitrix field identity and
        // enum mapping are discovered/provisioned lazily through secured endpoints.
        if (get_config('local_bitrixsync', 'corporate_role_field_id') === false) {
            set_config('corporate_role_field_id', 0, 'local_bitrixsync');
        }
        if (get_config('local_bitrixsync', 'corporate_role_field_name') === false) {
            set_config('corporate_role_field_name', '', 'local_bitrixsync');
        }
        if (get_config('local_bitrixsync', 'corporate_role_enum_map') === false) {
            set_config('corporate_role_enum_map', '{}', 'local_bitrixsync');
        }

        upgrade_plugin_savepoint(true, 2026083200, 'local', 'bitrixsync');
    }


    if ($oldversion < 2026083300) {
        // v5.0.33 RC1 — Corporate Dashboard Pairing.
        // Adds one-time pairing codes, revocable integration connections and
        // an IP-scoped pairing rate limiter. No Corporate Dashboard tables or
        // Bitrix24 credentials are touched.
        $table = new xmldb_table('local_bitrixsync_pairing');
        if (!$dbman->table_exists($table)) {
            $table->add_field('id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE);
            $table->add_field('codehash', XMLDB_TYPE_CHAR, '64', null, XMLDB_NOTNULL);
            $table->add_field('environment', XMLDB_TYPE_CHAR, '20', null, XMLDB_NOTNULL, null, 'production');
            $table->add_field('timecreated', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $table->add_field('timeexpires', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $table->add_field('timeused', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $table->add_field('used', XMLDB_TYPE_INTEGER, '1', null, XMLDB_NOTNULL, null, '0');
            $table->add_field('attempts', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $table->add_field('lastattempt', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $table->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);
            $table->add_index('codehash_uix', XMLDB_INDEX_UNIQUE, ['codehash']);
            $table->add_index('active_idx', XMLDB_INDEX_NOTUNIQUE, ['used', 'timeexpires']);
            $dbman->create_table($table);
        }

        $table = new xmldb_table('local_bitrixsync_corpconn');
        if (!$dbman->table_exists($table)) {
            $table->add_field('id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE);
            $table->add_field('connectionid', XMLDB_TYPE_CHAR, '128', null, XMLDB_NOTNULL);
            $table->add_field('component', XMLDB_TYPE_CHAR, '100', null, XMLDB_NOTNULL, null, 'local_kbacorporate');
            $table->add_field('environment', XMLDB_TYPE_CHAR, '20', null, XMLDB_NOTNULL, null, 'production');
            $table->add_field('tokenhash', XMLDB_TYPE_CHAR, '64', null, XMLDB_NOTNULL);
            $table->add_field('tokenfingerprint', XMLDB_TYPE_CHAR, '16', null, XMLDB_NOTNULL, null, '');
            $table->add_field('clientversion', XMLDB_TYPE_CHAR, '50', null, XMLDB_NOTNULL, null, '');
            $table->add_field('apiversion', XMLDB_TYPE_CHAR, '20', null, XMLDB_NOTNULL, null, '');
            $table->add_field('timecreated', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $table->add_field('lastseen', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $table->add_field('revoked', XMLDB_TYPE_INTEGER, '1', null, XMLDB_NOTNULL, null, '0');
            $table->add_field('timerevoked', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $table->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);
            $table->add_index('connid_uix', XMLDB_INDEX_UNIQUE, ['connectionid']);
            $table->add_index('tokenhash_uix', XMLDB_INDEX_UNIQUE, ['tokenhash']);
            $table->add_index('active_idx', XMLDB_INDEX_NOTUNIQUE, ['revoked', 'environment']);
            $dbman->create_table($table);
        }

        $table = new xmldb_table('local_bitrixsync_pairattempt');
        if (!$dbman->table_exists($table)) {
            $table->add_field('id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE);
            $table->add_field('iphash', XMLDB_TYPE_CHAR, '64', null, XMLDB_NOTNULL);
            $table->add_field('windowstart', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $table->add_field('attempts', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $table->add_field('blockeduntil', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $table->add_field('timemodified', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $table->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);
            $table->add_index('iphash_uix', XMLDB_INDEX_UNIQUE, ['iphash']);
            $dbman->create_table($table);
        }

        if (get_config('local_bitrixsync', 'corporate_pairing_pepper') === false) {
            set_config('corporate_pairing_pepper', bin2hex(random_bytes(32)), 'local_bitrixsync');
        }
        upgrade_plugin_savepoint(true, 2026083300, 'local', 'bitrixsync');
    }


    if ($oldversion < 2026083301) {
        // v5.0.33.1 — Pairing Database Migration Repair.
        // Idempotently recreate any pairing tables that were missed during the RC1 upgrade.
        $table = new xmldb_table('local_bitrixsync_pairing');
        if (!$dbman->table_exists($table)) {
            $table->add_field('id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE);
            $table->add_field('codehash', XMLDB_TYPE_CHAR, '64', null, XMLDB_NOTNULL);
            $table->add_field('environment', XMLDB_TYPE_CHAR, '20', null, XMLDB_NOTNULL, null, 'production');
            $table->add_field('timecreated', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $table->add_field('timeexpires', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $table->add_field('timeused', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $table->add_field('used', XMLDB_TYPE_INTEGER, '1', null, XMLDB_NOTNULL, null, '0');
            $table->add_field('attempts', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $table->add_field('lastattempt', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $table->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);
            $table->add_index('codehash_uix', XMLDB_INDEX_UNIQUE, ['codehash']);
            $table->add_index('active_idx', XMLDB_INDEX_NOTUNIQUE, ['used', 'timeexpires']);
            $dbman->create_table($table);
        }

        $table = new xmldb_table('local_bitrixsync_corpconn');
        if (!$dbman->table_exists($table)) {
            $table->add_field('id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE);
            $table->add_field('connectionid', XMLDB_TYPE_CHAR, '128', null, XMLDB_NOTNULL);
            $table->add_field('component', XMLDB_TYPE_CHAR, '100', null, XMLDB_NOTNULL, null, 'local_kbacorporate');
            $table->add_field('environment', XMLDB_TYPE_CHAR, '20', null, XMLDB_NOTNULL, null, 'production');
            $table->add_field('tokenhash', XMLDB_TYPE_CHAR, '64', null, XMLDB_NOTNULL);
            $table->add_field('tokenfingerprint', XMLDB_TYPE_CHAR, '16', null, XMLDB_NOTNULL, null, '');
            $table->add_field('clientversion', XMLDB_TYPE_CHAR, '50', null, XMLDB_NOTNULL, null, '');
            $table->add_field('apiversion', XMLDB_TYPE_CHAR, '20', null, XMLDB_NOTNULL, null, '');
            $table->add_field('timecreated', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $table->add_field('lastseen', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $table->add_field('revoked', XMLDB_TYPE_INTEGER, '1', null, XMLDB_NOTNULL, null, '0');
            $table->add_field('timerevoked', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $table->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);
            $table->add_index('connid_uix', XMLDB_INDEX_UNIQUE, ['connectionid']);
            $table->add_index('tokenhash_uix', XMLDB_INDEX_UNIQUE, ['tokenhash']);
            $table->add_index('active_idx', XMLDB_INDEX_NOTUNIQUE, ['revoked', 'environment']);
            $dbman->create_table($table);
        }

        $table = new xmldb_table('local_bitrixsync_pairattempt');
        if (!$dbman->table_exists($table)) {
            $table->add_field('id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE);
            $table->add_field('iphash', XMLDB_TYPE_CHAR, '64', null, XMLDB_NOTNULL);
            $table->add_field('windowstart', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $table->add_field('attempts', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $table->add_field('blockeduntil', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $table->add_field('timemodified', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $table->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);
            $table->add_index('iphash_uix', XMLDB_INDEX_UNIQUE, ['iphash']);
            $dbman->create_table($table);
        }

        if (get_config('local_bitrixsync', 'corporate_pairing_pepper') === false) {
            set_config('corporate_pairing_pepper', bin2hex(random_bytes(32)), 'local_bitrixsync');
        }

        upgrade_plugin_savepoint(true, 2026083301, 'local', 'bitrixsync');
    }


    if ($oldversion < 2026083302) {
        // v5.0.33.2 — Pairing Storage Recovery.
        // Force an idempotent schema verification with a new savepoint so Moodle
        // runs this block even if an earlier RC recorded its version before all
        // pairing tables were physically created.

        $table = new xmldb_table('local_bitrixsync_pairing');
        if (!$dbman->table_exists($table)) {
            $table->add_field('id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE);
            $table->add_field('codehash', XMLDB_TYPE_CHAR, '64', null, XMLDB_NOTNULL);
            $table->add_field('environment', XMLDB_TYPE_CHAR, '20', null, XMLDB_NOTNULL, null, 'production');
            $table->add_field('timecreated', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $table->add_field('timeexpires', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $table->add_field('timeused', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $table->add_field('used', XMLDB_TYPE_INTEGER, '1', null, XMLDB_NOTNULL, null, '0');
            $table->add_field('attempts', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $table->add_field('lastattempt', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $table->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);
            $table->add_index('codehash_uix', XMLDB_INDEX_UNIQUE, ['codehash']);
            $table->add_index('active_idx', XMLDB_INDEX_NOTUNIQUE, ['used', 'timeexpires']);
            $dbman->create_table($table);
        }

        $table = new xmldb_table('local_bitrixsync_corpconn');
        if (!$dbman->table_exists($table)) {
            $table->add_field('id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE);
            $table->add_field('connectionid', XMLDB_TYPE_CHAR, '128', null, XMLDB_NOTNULL);
            $table->add_field('component', XMLDB_TYPE_CHAR, '100', null, XMLDB_NOTNULL, null, 'local_kbacorporate');
            $table->add_field('environment', XMLDB_TYPE_CHAR, '20', null, XMLDB_NOTNULL, null, 'production');
            $table->add_field('tokenhash', XMLDB_TYPE_CHAR, '64', null, XMLDB_NOTNULL);
            $table->add_field('tokenfingerprint', XMLDB_TYPE_CHAR, '16', null, XMLDB_NOTNULL, null, '');
            $table->add_field('clientversion', XMLDB_TYPE_CHAR, '50', null, XMLDB_NOTNULL, null, '');
            $table->add_field('apiversion', XMLDB_TYPE_CHAR, '20', null, XMLDB_NOTNULL, null, '');
            $table->add_field('timecreated', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $table->add_field('lastseen', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $table->add_field('revoked', XMLDB_TYPE_INTEGER, '1', null, XMLDB_NOTNULL, null, '0');
            $table->add_field('timerevoked', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $table->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);
            $table->add_index('connid_uix', XMLDB_INDEX_UNIQUE, ['connectionid']);
            $table->add_index('tokenhash_uix', XMLDB_INDEX_UNIQUE, ['tokenhash']);
            $table->add_index('active_idx', XMLDB_INDEX_NOTUNIQUE, ['revoked', 'environment']);
            $dbman->create_table($table);
        }

        $table = new xmldb_table('local_bitrixsync_pairattempt');
        if (!$dbman->table_exists($table)) {
            $table->add_field('id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE);
            $table->add_field('iphash', XMLDB_TYPE_CHAR, '64', null, XMLDB_NOTNULL);
            $table->add_field('windowstart', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $table->add_field('attempts', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $table->add_field('blockeduntil', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $table->add_field('timemodified', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $table->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);
            $table->add_index('iphash_uix', XMLDB_INDEX_UNIQUE, ['iphash']);
            $dbman->create_table($table);
        }

        if (get_config('local_bitrixsync', 'corporate_pairing_pepper') === false) {
            set_config('corporate_pairing_pepper', bin2hex(random_bytes(32)), 'local_bitrixsync');
        }

        upgrade_plugin_savepoint(true, 2026083302, 'local', 'bitrixsync');
    }


    if ($oldversion < 2026083400) {
        // v5.0.34 RC1 — Simplified Corporate Integration.
        // No destructive schema changes. Pairing storage from v5.0.33 remains intact.
        upgrade_plugin_savepoint(true, 2026083400, 'local', 'bitrixsync');
    }


    if ($oldversion < 2026083402) {
        // v5.0.34.2 — Corporate Roles via the proven CRM Field Engine.
        // No destructive schema changes.
        upgrade_plugin_savepoint(true, 2026083402, 'local', 'bitrixsync');
    }


    if ($oldversion < 2026083403) {
        // v5.0.34.3 — read-only Corporate Bitrix diagnostics.
        upgrade_plugin_savepoint(true, 2026083403, 'local', 'bitrixsync');
    }


    if ($oldversion < 2026083404) {
        // v5.0.34.4 — Corporate Role Enum Provisioning Fix.
        upgrade_plugin_savepoint(true, 2026083404, 'local', 'bitrixsync');
    }


    if ($oldversion < 2026083405) {
        // v5.0.34.5 — Role Schema Normalization Fix.
        // No destructive database or Bitrix24 schema changes.
        upgrade_plugin_savepoint(true, 2026083405, 'local', 'bitrixsync');
    }


    if ($oldversion < 2026083406) {
        // v5.0.34.6 — Corporate Control Center initialization fix.
        upgrade_plugin_savepoint(true, 2026083406, 'local', 'bitrixsync');
    }


    if ($oldversion < 2026083407) {
        // v5.0.34.7 — Enum Recognition + Error Classification Fix.
        // No destructive DB or Bitrix24 changes.
        upgrade_plugin_savepoint(true, 2026083407, 'local', 'bitrixsync');
    }


    if ($oldversion < 2026083500) {
        // v5.0.35 RC1 — Contact Role Sync + Access Transport Fix.
        // No destructive database changes.
        upgrade_plugin_savepoint(true, 2026083500, 'local', 'bitrixsync');
    }


    if ($oldversion < 2026083510) {
        // v5.0.35.1 — Corporate Company Mapping Fix.
        // No destructive DB changes.
        upgrade_plugin_savepoint(true, 2026083510, 'local', 'bitrixsync');
    }


    if ($oldversion < 2026083520) {
        // v5.0.35.2 — Corporate Role Sync Diagnostics.
        upgrade_plugin_savepoint(true, 2026083520, 'local', 'bitrixsync');
    }


    if ($oldversion < 2026083530) {
        // v5.0.35.3 — Setup Center Corporate Status Fix.
        // No database schema changes.
        upgrade_plugin_savepoint(true, 2026083530, 'local', 'bitrixsync');
    }


    if ($oldversion < 2026083540) {
        // v5.0.35.4 — Diagnostics Type Fix.
        // No database schema changes.
        upgrade_plugin_savepoint(true, 2026083540, 'local', 'bitrixsync');
    }


    if ($oldversion < 2026083600) {
        // v5.0.36.0 — Corporate Role Registry Foundation.
        $table = new xmldb_table('local_bitrixsync_role_registry');
        if (!$dbman->table_exists($table)) {
            $table->add_field('id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE);
            $table->add_field('rolecode', XMLDB_TYPE_CHAR, '100', null, XMLDB_NOTNULL);
            $table->add_field('name', XMLDB_TYPE_CHAR, '255', null, XMLDB_NOTNULL);
            $table->add_field('description', XMLDB_TYPE_TEXT, null, null, null);
            $table->add_field('active', XMLDB_TYPE_INTEGER, '1', null, XMLDB_NOTNULL, null, '1');
            $table->add_field('sort', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $table->add_field('source', XMLDB_TYPE_CHAR, '50', null, XMLDB_NOTNULL, null, 'system');
            $table->add_field('timecreated', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $table->add_field('timemodified', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $table->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);
            $table->add_index('rolecode_uix', XMLDB_INDEX_UNIQUE, ['rolecode']);
            $dbman->create_table($table);
        }

        $now = time();
        $roles = [
            ['employee', 'Сотрудник', 10],
            ['corporate_manager', 'Корпоративный менеджер', 20],
            ['department_manager', 'Руководитель подразделения', 30],
            ['hr', 'HR', 40],
        ];
        foreach ($roles as $role) {
            if (!$DB->record_exists('local_bitrixsync_role_registry', ['rolecode'=>$role[0]])) {
                $DB->insert_record('local_bitrixsync_role_registry', (object)[
                    'rolecode'=>$role[0], 'name'=>$role[1], 'active'=>1,
                    'sort'=>$role[2], 'source'=>'system',
                    'timecreated'=>$now, 'timemodified'=>$now,
                ]);
            }
        }
        upgrade_plugin_savepoint(true, 2026083600, 'local', 'bitrixsync');
    }

    if ($oldversion < 2026083610) {
        upgrade_plugin_savepoint(true, 2026083610, 'local', 'bitrixsync');
    }


    if ($oldversion < 2026083611) {
        // v5.0.36.1.1 — Catalog Section Iblock Fix.
        // No database schema changes.
        upgrade_plugin_savepoint(true, 2026083611, 'local', 'bitrixsync');
    }


    if ($oldversion < 2026083612) {
        // v5.0.36.1.2 — Catalog Product Binding Fix.
        // No database schema changes.
        upgrade_plugin_savepoint(true, 2026083612, 'local', 'bitrixsync');
    }


    if ($oldversion < 2026083613) {
        // v5.0.36.1.3 — Catalog Binding Diagnostics.
        // No database schema changes.
        upgrade_plugin_savepoint(true, 2026083613, 'local', 'bitrixsync');
    }


    if ($oldversion < 2026083614) {
        // v5.0.36.1.4 — Catalog Diagnostics DB Fix.
        // No database schema changes.
        upgrade_plugin_savepoint(true, 2026083614, 'local', 'bitrixsync');
    }


    if ($oldversion < 2026083615) {
        // v5.0.36.1.5 — Catalog Mapping Deep Diagnostics.
        // No database schema changes.
        upgrade_plugin_savepoint(true, 2026083615, 'local', 'bitrixsync');
    }


    if ($oldversion < 2026083616) {
        // v5.0.36.1.6 — Catalog Category Mapping Repair.
        // No database schema changes.
        upgrade_plugin_savepoint(true, 2026083616, 'local', 'bitrixsync');
    }


    if ($oldversion < 2026083617) {
        // v5.0.36.1.7 — repair Moodle <-> Bitrix catalog mapping schema.
        //
        // Historical installations may already have catmap/productmap tables
        // created by older builds but without the fields later catalog code
        // expects. Fresh install.xml also now owns these tables.

        // Category mapping.
        $table = new xmldb_table('local_bitrixsync_catmap');

        if (!$dbman->table_exists($table)) {
            $table->add_field('id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE);
            $table->add_field('categoryid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL);
            $table->add_field('bitrix_section_id', XMLDB_TYPE_INTEGER, '10');
            $table->add_field('bitrix_parent_section_id', XMLDB_TYPE_INTEGER, '10');
            $table->add_field('iblockid', XMLDB_TYPE_INTEGER, '10');
            $table->add_field('name', XMLDB_TYPE_CHAR, '255', null, XMLDB_NOTNULL, null, '');
            $table->add_field('status', XMLDB_TYPE_CHAR, '30', null, XMLDB_NOTNULL, null, 'mapped');
            $table->add_field('last_sync', XMLDB_TYPE_INTEGER, '10');
            $table->add_field('error', XMLDB_TYPE_TEXT);
            $table->add_field('timecreated', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $table->add_field('timemodified', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $table->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);
            $table->add_index('category_idx', XMLDB_INDEX_UNIQUE, ['categoryid']);
            $table->add_index('section_idx', XMLDB_INDEX_NOTUNIQUE, ['bitrix_section_id']);
            $dbman->create_table($table);
        } else {
            $fields = [
                new xmldb_field('categoryid', XMLDB_TYPE_INTEGER, '10'),
                new xmldb_field('bitrix_section_id', XMLDB_TYPE_INTEGER, '10'),
                new xmldb_field('bitrix_parent_section_id', XMLDB_TYPE_INTEGER, '10'),
                new xmldb_field('iblockid', XMLDB_TYPE_INTEGER, '10'),
                new xmldb_field('name', XMLDB_TYPE_CHAR, '255', null, XMLDB_NOTNULL, null, ''),
                new xmldb_field('status', XMLDB_TYPE_CHAR, '30', null, XMLDB_NOTNULL, null, 'mapped'),
                new xmldb_field('last_sync', XMLDB_TYPE_INTEGER, '10'),
                new xmldb_field('error', XMLDB_TYPE_TEXT),
                new xmldb_field('timecreated', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0'),
                new xmldb_field('timemodified', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0'),
            ];

            foreach ($fields as $field) {
                if (!$dbman->field_exists($table, $field)) {
                    $dbman->add_field($table, $field);
                }
            }

            // Recover an older section identifier column if one exists.
            $newsection = new xmldb_field('bitrix_section_id');
            foreach (['bitrixid', 'sectionid', 'bitrix_sectionid'] as $legacyname) {
                $legacy = new xmldb_field($legacyname);
                if ($dbman->field_exists($table, $legacy)) {
                    $DB->execute(
                        "UPDATE {local_bitrixsync_catmap}
                            SET bitrix_section_id = $legacyname
                          WHERE (bitrix_section_id IS NULL OR bitrix_section_id = 0)
                            AND $legacyname IS NOT NULL
                            AND $legacyname > 0"
                    );
                    break;
                }
            }

            $categoryidx = new xmldb_index('category_idx', XMLDB_INDEX_UNIQUE, ['categoryid']);
            if ($dbman->field_exists($table, new xmldb_field('categoryid')) &&
                    !$dbman->index_exists($table, $categoryidx)) {
                // Add only when existing data is unique.
                $duplicates = $DB->get_records_sql(
                    "SELECT categoryid, COUNT(1) AS cnt
                       FROM {local_bitrixsync_catmap}
                      GROUP BY categoryid
                     HAVING COUNT(1) > 1",
                    [],
                    0,
                    1
                );
                if (!$duplicates) {
                    $dbman->add_index($table, $categoryidx);
                }
            }

            $sectionidx = new xmldb_index(
                'section_idx',
                XMLDB_INDEX_NOTUNIQUE,
                ['bitrix_section_id']
            );
            if (!$dbman->index_exists($table, $sectionidx)) {
                $dbman->add_index($table, $sectionidx);
            }
        }

        // Product mapping.
        $table = new xmldb_table('local_bitrixsync_productmap');

        if (!$dbman->table_exists($table)) {
            $table->add_field('id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE);
            $table->add_field('bitrix_product_id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL);
            $table->add_field('courseid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL);
            $table->add_field('coursecode', XMLDB_TYPE_CHAR, '100');
            $table->add_field('categoryid', XMLDB_TYPE_INTEGER, '10');
            $table->add_field('bitrix_section_id', XMLDB_TYPE_INTEGER, '10');
            $table->add_field('iblockid', XMLDB_TYPE_INTEGER, '10');
            $table->add_field('source', XMLDB_TYPE_CHAR, '20', null, XMLDB_NOTNULL, null, 'moodle');
            $table->add_field('status', XMLDB_TYPE_CHAR, '30', null, XMLDB_NOTNULL, null, 'mapped');
            $table->add_field('last_sync', XMLDB_TYPE_INTEGER, '10');
            $table->add_field('error', XMLDB_TYPE_TEXT);
            $table->add_field('active', XMLDB_TYPE_INTEGER, '1', null, XMLDB_NOTNULL, null, '1');
            $table->add_field('timecreated', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $table->add_field('timemodified', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $table->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);
            $table->add_index('product_idx', XMLDB_INDEX_NOTUNIQUE, ['bitrix_product_id']);
            $table->add_index('course_idx', XMLDB_INDEX_NOTUNIQUE, ['courseid']);
            $table->add_index('active_idx', XMLDB_INDEX_NOTUNIQUE, ['active']);
            $dbman->create_table($table);
        } else {
            $fields = [
                new xmldb_field('coursecode', XMLDB_TYPE_CHAR, '100'),
                new xmldb_field('categoryid', XMLDB_TYPE_INTEGER, '10'),
                new xmldb_field('bitrix_section_id', XMLDB_TYPE_INTEGER, '10'),
                new xmldb_field('iblockid', XMLDB_TYPE_INTEGER, '10'),
                new xmldb_field('source', XMLDB_TYPE_CHAR, '20', null, XMLDB_NOTNULL, null, 'moodle'),
                new xmldb_field('status', XMLDB_TYPE_CHAR, '30', null, XMLDB_NOTNULL, null, 'mapped'),
                new xmldb_field('last_sync', XMLDB_TYPE_INTEGER, '10'),
                new xmldb_field('error', XMLDB_TYPE_TEXT),
                new xmldb_field('active', XMLDB_TYPE_INTEGER, '1', null, XMLDB_NOTNULL, null, '1'),
                new xmldb_field('timecreated', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0'),
                new xmldb_field('timemodified', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0'),
            ];

            foreach ($fields as $field) {
                if (!$dbman->field_exists($table, $field)) {
                    $dbman->add_field($table, $field);
                }
            }
        }

        upgrade_plugin_savepoint(true, 2026083617, 'local', 'bitrixsync');
    }


    if ($oldversion < 2026083618) {
        // v5.0.36.1.8 — Category Mapping DB Write Diagnostics.
        // No database schema changes.
        upgrade_plugin_savepoint(true, 2026083618, 'local', 'bitrixsync');
    }


    if ($oldversion < 2026083619) {
        // v5.0.36.1.9 — Legacy Catalog Mapping Schema Migration.
        //
        // Historical production schema:
        //   sectionid
        //   parentcategoryid
        //   lastsync
        //
        // Canonical schema:
        //   bitrix_section_id
        //   bitrix_parent_section_id
        //   last_sync
        //
        // Legacy columns are preserved for rollback safety. Data is copied
        // forward into the canonical columns, never deleted.

        $table = new xmldb_table('local_bitrixsync_catmap');

        if ($dbman->table_exists($table)) {
            $canonicalfields = [
                new xmldb_field('bitrix_section_id', XMLDB_TYPE_INTEGER, '10'),
                new xmldb_field('bitrix_parent_section_id', XMLDB_TYPE_INTEGER, '10'),
                new xmldb_field('iblockid', XMLDB_TYPE_INTEGER, '10'),
                new xmldb_field('status', XMLDB_TYPE_CHAR, '30', null, XMLDB_NOTNULL, null, 'mapped'),
                new xmldb_field('last_sync', XMLDB_TYPE_INTEGER, '10'),
                new xmldb_field('error', XMLDB_TYPE_TEXT),
                new xmldb_field('timecreated', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0'),
                new xmldb_field('timemodified', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0'),
            ];

            foreach ($canonicalfields as $field) {
                if (!$dbman->field_exists($table, $field)) {
                    $dbman->add_field($table, $field);
                }
            }

            // Copy legacy section identifiers into canonical fields.
            if ($dbman->field_exists($table, new xmldb_field('sectionid'))) {
                $DB->execute(
                    "UPDATE {local_bitrixsync_catmap}
                        SET bitrix_section_id = sectionid
                      WHERE (bitrix_section_id IS NULL OR bitrix_section_id = 0)
                        AND sectionid IS NOT NULL
                        AND sectionid > 0"
                );
            }

            if ($dbman->field_exists($table, new xmldb_field('parentcategoryid'))) {
                $DB->execute(
                    "UPDATE {local_bitrixsync_catmap}
                        SET bitrix_parent_section_id = parentcategoryid
                      WHERE (bitrix_parent_section_id IS NULL OR bitrix_parent_section_id = 0)
                        AND parentcategoryid IS NOT NULL
                        AND parentcategoryid > 0"
                );
            }

            if ($dbman->field_exists($table, new xmldb_field('lastsync'))) {
                $DB->execute(
                    "UPDATE {local_bitrixsync_catmap}
                        SET last_sync = lastsync
                      WHERE (last_sync IS NULL OR last_sync = 0)
                        AND lastsync IS NOT NULL
                        AND lastsync > 0"
                );
            }

            // Normalize safe defaults.
            $now = time();

            $DB->execute(
                "UPDATE {local_bitrixsync_catmap}
                    SET status = 'mapped'
                  WHERE status IS NULL OR status = ''"
            );

            $DB->execute(
                "UPDATE {local_bitrixsync_catmap}
                    SET timecreated = :now1
                  WHERE timecreated IS NULL OR timecreated = 0",
                ['now1' => $now]
            );

            $DB->execute(
                "UPDATE {local_bitrixsync_catmap}
                    SET timemodified = CASE
                        WHEN last_sync IS NOT NULL AND last_sync > 0 THEN last_sync
                        ELSE :now2
                    END
                  WHERE timemodified IS NULL OR timemodified = 0",
                ['now2' => $now]
            );

            // Add canonical indexes when safe.
            $categoryidx = new xmldb_index(
                'category_idx',
                XMLDB_INDEX_UNIQUE,
                ['categoryid']
            );
            if ($dbman->field_exists($table, new xmldb_field('categoryid')) &&
                    !$dbman->index_exists($table, $categoryidx)) {
                $duplicates = $DB->get_records_sql(
                    "SELECT categoryid, COUNT(1) AS cnt
                       FROM {local_bitrixsync_catmap}
                      GROUP BY categoryid
                     HAVING COUNT(1) > 1",
                    [],
                    0,
                    1
                );
                if (!$duplicates) {
                    $dbman->add_index($table, $categoryidx);
                }
            }

            $sectionidx = new xmldb_index(
                'section_idx',
                XMLDB_INDEX_NOTUNIQUE,
                ['bitrix_section_id']
            );
            if (!$dbman->index_exists($table, $sectionidx)) {
                $dbman->add_index($table, $sectionidx);
            }
        }

        $table = new xmldb_table('local_bitrixsync_productmap');
        if ($dbman->table_exists($table)) {
            $canonicalfields = [
                new xmldb_field('coursecode', XMLDB_TYPE_CHAR, '100'),
                new xmldb_field('categoryid', XMLDB_TYPE_INTEGER, '10'),
                new xmldb_field('bitrix_section_id', XMLDB_TYPE_INTEGER, '10'),
                new xmldb_field('iblockid', XMLDB_TYPE_INTEGER, '10'),
                new xmldb_field('source', XMLDB_TYPE_CHAR, '20', null, XMLDB_NOTNULL, null, 'moodle'),
                new xmldb_field('status', XMLDB_TYPE_CHAR, '30', null, XMLDB_NOTNULL, null, 'mapped'),
                new xmldb_field('last_sync', XMLDB_TYPE_INTEGER, '10'),
                new xmldb_field('error', XMLDB_TYPE_TEXT),
                new xmldb_field('active', XMLDB_TYPE_INTEGER, '1', null, XMLDB_NOTNULL, null, '1'),
                new xmldb_field('timecreated', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0'),
                new xmldb_field('timemodified', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0'),
            ];

            foreach ($canonicalfields as $field) {
                if (!$dbman->field_exists($table, $field)) {
                    $dbman->add_field($table, $field);
                }
            }

            // Recover from common historical product-map aliases.
            foreach (['sectionid', 'bitrix_sectionid'] as $legacyname) {
                if ($dbman->field_exists($table, new xmldb_field($legacyname))) {
                    $DB->execute(
                        "UPDATE {local_bitrixsync_productmap}
                            SET bitrix_section_id = $legacyname
                          WHERE (bitrix_section_id IS NULL OR bitrix_section_id = 0)
                            AND $legacyname IS NOT NULL
                            AND $legacyname > 0"
                    );
                    break;
                }
            }

            if ($dbman->field_exists($table, new xmldb_field('lastsync'))) {
                $DB->execute(
                    "UPDATE {local_bitrixsync_productmap}
                        SET last_sync = lastsync
                      WHERE (last_sync IS NULL OR last_sync = 0)
                        AND lastsync IS NOT NULL
                        AND lastsync > 0"
                );
            }

            $now = time();
            $DB->execute(
                "UPDATE {local_bitrixsync_productmap}
                    SET status = 'mapped'
                  WHERE status IS NULL OR status = ''"
            );
            $DB->execute(
                "UPDATE {local_bitrixsync_productmap}
                    SET source = 'moodle'
                  WHERE source IS NULL OR source = ''"
            );
            $DB->execute(
                "UPDATE {local_bitrixsync_productmap}
                    SET active = 1
                  WHERE active IS NULL"
            );
            $DB->execute(
                "UPDATE {local_bitrixsync_productmap}
                    SET timecreated = :now3
                  WHERE timecreated IS NULL OR timecreated = 0",
                ['now3' => $now]
            );
            $DB->execute(
                "UPDATE {local_bitrixsync_productmap}
                    SET timemodified = CASE
                        WHEN last_sync IS NOT NULL AND last_sync > 0 THEN last_sync
                        ELSE :now4
                    END
                  WHERE timemodified IS NULL OR timemodified = 0",
                ['now4' => $now]
            );
        }

        set_config('catalog_legacy_schema_migrated', time(), 'local_bitrixsync');

        upgrade_plugin_savepoint(true, 2026083619, 'local', 'bitrixsync');
    }


    if ($oldversion < 2026083620) {
        // v5.0.36.1.10 — Manual Legacy Catalog Schema Repair UI.
        // No destructive DB changes here; repair is admin-triggered.
        upgrade_plugin_savepoint(true, 2026083620, 'local', 'bitrixsync');
    }


    if ($oldversion < 2026083621) {
        // v5.0.36.1.11 — Legacy Mapping Dual-Write Fix.
        // No destructive DB changes.
        upgrade_plugin_savepoint(true, 2026083621, 'local', 'bitrixsync');
    }


    if ($oldversion < 2026083622) {
        // v5.0.36.1.12 — Access Delivery Flow Fix.
        // No destructive database changes.
        upgrade_plugin_savepoint(true, 2026083622, 'local', 'bitrixsync');
    }


    if ($oldversion < 2026083623) {
        upgrade_plugin_savepoint(true, 2026083623, 'local', 'bitrixsync');
    }


    if ($oldversion < 2026083624) {
        // v5.0.36.1.14 — Automatic Multi-Contact Deal Processing Fix.
        // No destructive database changes.
        upgrade_plugin_savepoint(true, 2026083624, 'local', 'bitrixsync');
    }


    if ($oldversion < 2026083625) {
        // v5.0.36.1.15 — Secure Access Resend & Messenger Copy.
        upgrade_plugin_savepoint(true, 2026083625, 'local', 'bitrixsync');
    }

    if ($oldversion < 2026083626) {
        // v5.0.36.1.16 — BMS Company & Employee Bridge. Schema-neutral.
        // Adds a scheduled consumer, Company webhook routing and CRM field definition only.
        upgrade_plugin_savepoint(true, 2026083626, 'local', 'bitrixsync');
    }

    if ($oldversion < 2026083627) {
        // v5.0.36.1.17 — BMS Bridge Diagnostics. Schema-neutral/read-only.
        upgrade_plugin_savepoint(true, 2026083627, 'local', 'bitrixsync');
    }

    if ($oldversion < 2026083628) {
        // v5.0.36.1.18 — Explicit BMS Client Field Selector. Schema-neutral.
        // Company → BMS sync now requires an administrator-selected boolean Company field.
        upgrade_plugin_savepoint(true, 2026083628, 'local', 'bitrixsync');
    }

    if ($oldversion < 2026083629) {
        // 5.0.36.1.19 is schema-neutral: the BMS Company field selector now
        // prefers the verified CRM Field Engine cache and supplements it with
        // live Bitrix discovery when available.
        upgrade_plugin_savepoint(true, 2026083629, 'local', 'bitrixsync');
    }

    if ($oldversion < 2026083630) {
        // 5.0.36.1.20 is schema-neutral: expose the logical CRM Field Engine
        // resolution cache directly in the BMS field selector and resolve an
        // administrator-selected FIELD_NAME from that same verified cache first.
        upgrade_plugin_savepoint(true, 2026083630, 'local', 'bitrixsync');
    }

    if ($oldversion < 2026083631) {
        // 5.0.36.1.21 is schema-neutral: recover Corporate company identity via
        // the documented API, prevent silent Contact hydration skips and expose
        // per-contact Moodle-user resolution results during BMS Company sync.
        upgrade_plugin_savepoint(true, 2026083631, 'local', 'bitrixsync');
    }

    if ($oldversion < 2026083632) {
        // 5.0.36.1.22 is schema-neutral: outbound BMS employee phone data is
        // separated from email and invalid historical email-like PHONE values are guarded.
        upgrade_plugin_savepoint(true, 2026083632, 'local', 'bitrixsync');
    }

    return true;
}
