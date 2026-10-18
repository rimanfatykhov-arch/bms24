<?php

defined('MOODLE_INTERNAL') || die();

/** Upgrade KBA Company Core. */
function xmldb_local_kbacompany_upgrade(int $oldversion): bool {
    global $DB;

    $dbman = $DB->get_manager();

    if ($oldversion < 2026091010) {
        // v0.1.0 creates only the stable identity registry. Existing Corporate
        // Dashboard company and membership data is not copied, changed or deleted.
        $table = new xmldb_table('local_kbacompany_idreg');
        if (!$dbman->table_exists($table)) {
            $table->add_field('id', XMLDB_TYPE_INTEGER, '10', null,
                XMLDB_NOTNULL, XMLDB_SEQUENCE, null);
            $table->add_field('sourcecomponent', XMLDB_TYPE_CHAR, '100', null,
                XMLDB_NOTNULL, null, null);
            $table->add_field('sourceentity', XMLDB_TYPE_CHAR, '50', null,
                XMLDB_NOTNULL, null, null);
            $table->add_field('sourceid', XMLDB_TYPE_INTEGER, '10', null,
                XMLDB_NOTNULL, null, null);
            $table->add_field('uuid', XMLDB_TYPE_CHAR, '36', null,
                XMLDB_NOTNULL, null, null);
            $table->add_field('timecreated', XMLDB_TYPE_INTEGER, '10', null,
                XMLDB_NOTNULL, null, '0');
            $table->add_field('timemodified', XMLDB_TYPE_INTEGER, '10', null,
                XMLDB_NOTNULL, null, '0');
            $table->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);
            $table->add_index('source_uix', XMLDB_INDEX_UNIQUE,
                ['sourcecomponent', 'sourceentity', 'sourceid']);
            $table->add_index('uuid_uix', XMLDB_INDEX_UNIQUE, ['uuid']);
            $dbman->create_table($table);
        }

        upgrade_plugin_savepoint(true, 2026091010, 'local', 'kbacompany');
    }

    if ($oldversion < 2026091011) {
        // v0.1.0.1 moves Corporate Dashboard compatibility from a hard Moodle
        // install dependency to runtime detection. No schema or data changes.
        upgrade_plugin_savepoint(true, 2026091011, 'local', 'kbacompany');
    }

    if ($oldversion < 2026091012) {
        // v0.1.0.2 adds the Moodle navbar renderer callback only. There are no
        // schema, permission or business-data mutations in this upgrade step.
        upgrade_plugin_savepoint(true, 2026091012, 'local', 'kbacompany');
    }

    if ($oldversion < 2026091201) {
        // v0.2.0 adds a read-only Organization Core service and interface over
        // existing authoritative sources. No schema or business-data changes.
        upgrade_plugin_savepoint(true, 2026091201, 'local', 'kbacompany');
    }

    if ($oldversion < 2026091202) {
        // v0.2.1 adds read-only business-role, assignment and competency-target
        // projections. No schema, assessment or business-data mutations.
        upgrade_plugin_savepoint(true, 2026091202, 'local', 'kbacompany');
    }

    if ($oldversion < 2026091203) {
        // v0.2.2 introduces Company Core-owned organizing-board metadata. The
        // upgrade creates empty tables only: no company structure is seeded,
        // copied, renamed or deleted automatically.
        $branchtable = new xmldb_table('local_kbacompany_orgbranch');
        if (!$dbman->table_exists($branchtable)) {
            $branchtable->add_field('id', XMLDB_TYPE_INTEGER, '10', null,
                XMLDB_NOTNULL, XMLDB_SEQUENCE, null);
            $branchtable->add_field('companyuuid', XMLDB_TYPE_CHAR, '36', null,
                XMLDB_NOTNULL, null, null);
            $branchtable->add_field('uuid', XMLDB_TYPE_CHAR, '36', null,
                XMLDB_NOTNULL, null, null);
            $branchtable->add_field('code', XMLDB_TYPE_CHAR, '50', null,
                XMLDB_NOTNULL, null, null);
            $branchtable->add_field('name', XMLDB_TYPE_CHAR, '255', null,
                XMLDB_NOTNULL, null, null);
            $branchtable->add_field('isdefault', XMLDB_TYPE_INTEGER, '1', null,
                XMLDB_NOTNULL, null, '0');
            $branchtable->add_field('active', XMLDB_TYPE_INTEGER, '1', null,
                XMLDB_NOTNULL, null, '1');
            $branchtable->add_field('sortorder', XMLDB_TYPE_INTEGER, '10', null,
                XMLDB_NOTNULL, null, '0');
            $branchtable->add_field('timecreated', XMLDB_TYPE_INTEGER, '10', null,
                XMLDB_NOTNULL, null, '0');
            $branchtable->add_field('timemodified', XMLDB_TYPE_INTEGER, '10', null,
                XMLDB_NOTNULL, null, '0');
            $branchtable->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);
            $branchtable->add_index('company_code_uix', XMLDB_INDEX_UNIQUE, ['companyuuid', 'code']);
            $branchtable->add_index('uuid_uix', XMLDB_INDEX_UNIQUE, ['uuid']);
            $dbman->create_table($branchtable);
        }

        $nodetable = new xmldb_table('local_kbacompany_orgnode');
        if (!$dbman->table_exists($nodetable)) {
            $nodetable->add_field('id', XMLDB_TYPE_INTEGER, '10', null,
                XMLDB_NOTNULL, XMLDB_SEQUENCE, null);
            $nodetable->add_field('companyuuid', XMLDB_TYPE_CHAR, '36', null,
                XMLDB_NOTNULL, null, null);
            $nodetable->add_field('branchuuid', XMLDB_TYPE_CHAR, '36', null,
                XMLDB_NOTNULL, null, null);
            $nodetable->add_field('uuid', XMLDB_TYPE_CHAR, '36', null,
                XMLDB_NOTNULL, null, null);
            $nodetable->add_field('parentuuid', XMLDB_TYPE_CHAR, '36', null,
                XMLDB_NOTNULL, null, '');
            $nodetable->add_field('code', XMLDB_TYPE_CHAR, '50', null,
                XMLDB_NOTNULL, null, null);
            $nodetable->add_field('nodetype', XMLDB_TYPE_CHAR, '20', null,
                XMLDB_NOTNULL, null, null);
            $nodetable->add_field('name', XMLDB_TYPE_CHAR, '255', null,
                XMLDB_NOTNULL, null, null);
            $nodetable->add_field('sortorder', XMLDB_TYPE_INTEGER, '10', null,
                XMLDB_NOTNULL, null, '0');
            $nodetable->add_field('color', XMLDB_TYPE_CHAR, '7', null,
                XMLDB_NOTNULL, null, '');
            $nodetable->add_field('sourcecomponent', XMLDB_TYPE_CHAR, '100', null,
                XMLDB_NOTNULL, null, '');
            $nodetable->add_field('sourceentity', XMLDB_TYPE_CHAR, '50', null,
                XMLDB_NOTNULL, null, '');
            $nodetable->add_field('sourceid', XMLDB_TYPE_INTEGER, '10', null,
                XMLDB_NOTNULL, null, '0');
            $nodetable->add_field('active', XMLDB_TYPE_INTEGER, '1', null,
                XMLDB_NOTNULL, null, '1');
            $nodetable->add_field('timecreated', XMLDB_TYPE_INTEGER, '10', null,
                XMLDB_NOTNULL, null, '0');
            $nodetable->add_field('timemodified', XMLDB_TYPE_INTEGER, '10', null,
                XMLDB_NOTNULL, null, '0');
            $nodetable->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);
            $nodetable->add_index('company_branch_code_uix', XMLDB_INDEX_UNIQUE,
                ['companyuuid', 'branchuuid', 'code']);
            $nodetable->add_index('uuid_uix', XMLDB_INDEX_UNIQUE, ['uuid']);
            $nodetable->add_index('parent_ix', XMLDB_INDEX_NOTUNIQUE,
                ['companyuuid', 'branchuuid', 'parentuuid']);
            $nodetable->add_index('type_ix', XMLDB_INDEX_NOTUNIQUE,
                ['companyuuid', 'nodetype', 'active']);
            $dbman->create_table($nodetable);
        }

        upgrade_plugin_savepoint(true, 2026091203, 'local', 'kbacompany');
    }

    if ($oldversion < 2026091204) {
        // v0.2.3 adds empty position and staffing-assignment tables. Existing
        // profile positions and company memberships are not copied or changed.
        $positiontable = new xmldb_table('local_kbacompany_position');
        if (!$dbman->table_exists($positiontable)) {
            $positiontable->add_field('id', XMLDB_TYPE_INTEGER, '10', null,
                XMLDB_NOTNULL, XMLDB_SEQUENCE, null);
            $positiontable->add_field('companyuuid', XMLDB_TYPE_CHAR, '36', null,
                XMLDB_NOTNULL, null, null);
            $positiontable->add_field('branchuuid', XMLDB_TYPE_CHAR, '36', null,
                XMLDB_NOTNULL, null, null);
            $positiontable->add_field('orgnodeuuid', XMLDB_TYPE_CHAR, '36', null,
                XMLDB_NOTNULL, null, null);
            $positiontable->add_field('uuid', XMLDB_TYPE_CHAR, '36', null,
                XMLDB_NOTNULL, null, null);
            $positiontable->add_field('code', XMLDB_TYPE_CHAR, '50', null,
                XMLDB_NOTNULL, null, null);
            $positiontable->add_field('name', XMLDB_TYPE_CHAR, '255', null,
                XMLDB_NOTNULL, null, null);
            $positiontable->add_field('purpose', XMLDB_TYPE_TEXT, null, null,
                null, null, null);
            $positiontable->add_field('ismanager', XMLDB_TYPE_INTEGER, '1', null,
                XMLDB_NOTNULL, null, '0');
            $positiontable->add_field('plannedcount', XMLDB_TYPE_INTEGER, '5', null,
                XMLDB_NOTNULL, null, '1');
            $positiontable->add_field('sortorder', XMLDB_TYPE_INTEGER, '10', null,
                XMLDB_NOTNULL, null, '0');
            $positiontable->add_field('active', XMLDB_TYPE_INTEGER, '1', null,
                XMLDB_NOTNULL, null, '1');
            $positiontable->add_field('timecreated', XMLDB_TYPE_INTEGER, '10', null,
                XMLDB_NOTNULL, null, '0');
            $positiontable->add_field('timemodified', XMLDB_TYPE_INTEGER, '10', null,
                XMLDB_NOTNULL, null, '0');
            $positiontable->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);
            $positiontable->add_index('uuid_uix', XMLDB_INDEX_UNIQUE, ['uuid']);
            $positiontable->add_index('company_code_uix', XMLDB_INDEX_UNIQUE, ['companyuuid', 'code']);
            $positiontable->add_index('node_ix', XMLDB_INDEX_NOTUNIQUE,
                ['companyuuid', 'branchuuid', 'orgnodeuuid', 'active']);
            $dbman->create_table($positiontable);
        }

        $assignmenttable = new xmldb_table('local_kbacompany_posassign');
        if (!$dbman->table_exists($assignmenttable)) {
            $assignmenttable->add_field('id', XMLDB_TYPE_INTEGER, '10', null,
                XMLDB_NOTNULL, XMLDB_SEQUENCE, null);
            $assignmenttable->add_field('companyuuid', XMLDB_TYPE_CHAR, '36', null,
                XMLDB_NOTNULL, null, null);
            $assignmenttable->add_field('positionuuid', XMLDB_TYPE_CHAR, '36', null,
                XMLDB_NOTNULL, null, null);
            $assignmenttable->add_field('userid', XMLDB_TYPE_INTEGER, '10', null,
                XMLDB_NOTNULL, null, null);
            $assignmenttable->add_field('assignmenttype', XMLDB_TYPE_CHAR, '20', null,
                XMLDB_NOTNULL, null, 'primary');
            $assignmenttable->add_field('effectivefrom', XMLDB_TYPE_INTEGER, '10', null,
                XMLDB_NOTNULL, null, '0');
            $assignmenttable->add_field('effectiveto', XMLDB_TYPE_INTEGER, '10', null,
                XMLDB_NOTNULL, null, '0');
            $assignmenttable->add_field('active', XMLDB_TYPE_INTEGER, '1', null,
                XMLDB_NOTNULL, null, '1');
            $assignmenttable->add_field('timecreated', XMLDB_TYPE_INTEGER, '10', null,
                XMLDB_NOTNULL, null, '0');
            $assignmenttable->add_field('timemodified', XMLDB_TYPE_INTEGER, '10', null,
                XMLDB_NOTNULL, null, '0');
            $assignmenttable->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);
            $assignmenttable->add_index('assignment_ix', XMLDB_INDEX_NOTUNIQUE,
                ['positionuuid', 'userid', 'assignmenttype', 'active']);
            $assignmenttable->add_index('company_user_ix', XMLDB_INDEX_NOTUNIQUE,
                ['companyuuid', 'userid', 'active']);
            $dbman->create_table($assignmenttable);
        }

        upgrade_plugin_savepoint(true, 2026091204, 'local', 'kbacompany');
    }

    if ($oldversion < 2026091205) {
        // v0.2.3.1 adds client-side company search to the company chooser.
        // No schema, permission or business-data changes are performed.
        upgrade_plugin_savepoint(true, 2026091205, 'local', 'kbacompany');
    }

    if ($oldversion < 2026091206) {
        // v0.2.3.2 adds an authorised direct link to the existing Corporate
        // Dashboard role-assignment form. No data or permission is changed.
        upgrade_plugin_savepoint(true, 2026091206, 'local', 'kbacompany');
    }

    if ($oldversion < 2026091207) {
        // v0.2.3.3 separates the versioned staffing plan from factual employee
        // placement. All changes are additive; existing assignments remain valid.
        $assignmenttable = new xmldb_table('local_kbacompany_posassign');
        $slotfield = new xmldb_field('slotuuid', XMLDB_TYPE_CHAR, '36', null,
            XMLDB_NOTNULL, null, '', 'positionuuid');
        if (!$dbman->field_exists($assignmenttable, $slotfield)) {
            $dbman->add_field($assignmenttable, $slotfield);
        }
        $slotindex = new xmldb_index('slot_ix', XMLDB_INDEX_NOTUNIQUE, ['slotuuid', 'active']);
        if (!$dbman->index_exists($assignmenttable, $slotindex)) {
            $dbman->add_index($assignmenttable, $slotindex);
        }

        $plantable = new xmldb_table('local_kbacompany_staffplan');
        if (!$dbman->table_exists($plantable)) {
            $plantable->add_field('id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE);
            $plantable->add_field('companyuuid', XMLDB_TYPE_CHAR, '36', null, XMLDB_NOTNULL);
            $plantable->add_field('branchuuid', XMLDB_TYPE_CHAR, '36', null, XMLDB_NOTNULL);
            $plantable->add_field('uuid', XMLDB_TYPE_CHAR, '36', null, XMLDB_NOTNULL);
            $plantable->add_field('name', XMLDB_TYPE_CHAR, '255', null, XMLDB_NOTNULL);
            $plantable->add_field('status', XMLDB_TYPE_CHAR, '20', null, XMLDB_NOTNULL, null, 'draft');
            $plantable->add_field('effectivefrom', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $plantable->add_field('effectiveto', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $plantable->add_field('currency', XMLDB_TYPE_CHAR, '3', null, XMLDB_NOTNULL, null, 'RUB');
            $plantable->add_field('approvedby', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $plantable->add_field('timeapproved', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $plantable->add_field('timecreated', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $plantable->add_field('timemodified', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $plantable->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);
            $plantable->add_index('uuid_uix', XMLDB_INDEX_UNIQUE, ['uuid']);
            $plantable->add_index('company_status_ix', XMLDB_INDEX_NOTUNIQUE,
                ['companyuuid', 'branchuuid', 'status']);
            $dbman->create_table($plantable);
        }

        $linetable = new xmldb_table('local_kbacompany_staffline');
        if (!$dbman->table_exists($linetable)) {
            $linetable->add_field('id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE);
            $linetable->add_field('companyuuid', XMLDB_TYPE_CHAR, '36', null, XMLDB_NOTNULL);
            $linetable->add_field('planuuid', XMLDB_TYPE_CHAR, '36', null, XMLDB_NOTNULL);
            $linetable->add_field('orgnodeuuid', XMLDB_TYPE_CHAR, '36', null, XMLDB_NOTNULL);
            $linetable->add_field('positionuuid', XMLDB_TYPE_CHAR, '36', null, XMLDB_NOTNULL);
            $linetable->add_field('plannedfte', XMLDB_TYPE_NUMBER, '10, 2', null, XMLDB_NOTNULL, null, '1');
            $linetable->add_field('salarymin', XMLDB_TYPE_NUMBER, '15, 2', null, XMLDB_NOTNULL, null, '0');
            $linetable->add_field('salarymax', XMLDB_TYPE_NUMBER, '15, 2', null, XMLDB_NOTNULL, null, '0');
            $linetable->add_field('budgetamount', XMLDB_TYPE_NUMBER, '15, 2', null, XMLDB_NOTNULL, null, '0');
            $linetable->add_field('sortorder', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $linetable->add_field('timecreated', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $linetable->add_field('timemodified', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $linetable->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);
            $linetable->add_index('plan_position_uix', XMLDB_INDEX_UNIQUE, ['planuuid', 'positionuuid']);
            $linetable->add_index('company_node_ix', XMLDB_INDEX_NOTUNIQUE, ['companyuuid', 'orgnodeuuid']);
            $dbman->create_table($linetable);
        }

        $slottable = new xmldb_table('local_kbacompany_staffslot');
        if (!$dbman->table_exists($slottable)) {
            $slottable->add_field('id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE);
            $slottable->add_field('companyuuid', XMLDB_TYPE_CHAR, '36', null, XMLDB_NOTNULL);
            $slottable->add_field('planuuid', XMLDB_TYPE_CHAR, '36', null, XMLDB_NOTNULL);
            $slottable->add_field('lineid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL);
            $slottable->add_field('uuid', XMLDB_TYPE_CHAR, '36', null, XMLDB_NOTNULL);
            $slottable->add_field('code', XMLDB_TYPE_CHAR, '80', null, XMLDB_NOTNULL);
            $slottable->add_field('orgnodeuuid', XMLDB_TYPE_CHAR, '36', null, XMLDB_NOTNULL);
            $slottable->add_field('positionuuid', XMLDB_TYPE_CHAR, '36', null, XMLDB_NOTNULL);
            $slottable->add_field('fte', XMLDB_TYPE_NUMBER, '5, 2', null, XMLDB_NOTNULL, null, '1');
            $slottable->add_field('status', XMLDB_TYPE_CHAR, '20', null, XMLDB_NOTNULL, null, 'vacant');
            $slottable->add_field('active', XMLDB_TYPE_INTEGER, '1', null, XMLDB_NOTNULL, null, '1');
            $slottable->add_field('timecreated', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $slottable->add_field('timemodified', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $slottable->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);
            $slottable->add_index('uuid_uix', XMLDB_INDEX_UNIQUE, ['uuid']);
            $slottable->add_index('plan_line_ix', XMLDB_INDEX_NOTUNIQUE, ['planuuid', 'lineid', 'active']);
            $slottable->add_index('company_position_ix', XMLDB_INDEX_NOTUNIQUE,
                ['companyuuid', 'positionuuid', 'active']);
            $dbman->create_table($slottable);
        }

        upgrade_plugin_savepoint(true, 2026091207, 'local', 'kbacompany');
    }

    if ($oldversion < 2026091208) {
        // Additive only: no inferred products, measurements or business-data migration.
        $table = new xmldb_table('local_kbacompany_product');
        if (!$dbman->table_exists($table)) {
            $table->add_field('id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE, null);
            $table->add_field('companyuuid', XMLDB_TYPE_CHAR, '36', null, XMLDB_NOTNULL, null, null);
            $table->add_field('uuid', XMLDB_TYPE_CHAR, '36', null, XMLDB_NOTNULL, null, null);
            $table->add_field('code', XMLDB_TYPE_CHAR, '50', null, XMLDB_NOTNULL, null, null);
            $table->add_field('name', XMLDB_TYPE_CHAR, '255', null, XMLDB_NOTNULL, null, null);
            $table->add_field('targettype', XMLDB_TYPE_CHAR, '10', null, XMLDB_NOTNULL, null, null);
            $table->add_field('targetuuid', XMLDB_TYPE_CHAR, '36', null, XMLDB_NOTNULL, null, null);
            $table->add_field('acceptance', XMLDB_TYPE_TEXT, null, null, null, null, null);
            $table->add_field('timecreated', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $table->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);
            $table->add_index('uuid_uix', XMLDB_INDEX_UNIQUE, ['uuid']);
            $table->add_index('company_code_uix', XMLDB_INDEX_UNIQUE, ['companyuuid', 'code']);
            $dbman->create_table($table);
        }
        $table = new xmldb_table('local_kbacompany_stat');
        if (!$dbman->table_exists($table)) {
            $table->add_field('id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE, null);
            $table->add_field('companyuuid', XMLDB_TYPE_CHAR, '36', null, XMLDB_NOTNULL, null, null);
            $table->add_field('uuid', XMLDB_TYPE_CHAR, '36', null, XMLDB_NOTNULL, null, null);
            $table->add_field('productuuid', XMLDB_TYPE_CHAR, '36', null, XMLDB_NOTNULL, null, null);
            $table->add_field('code', XMLDB_TYPE_CHAR, '50', null, XMLDB_NOTNULL, null, null);
            $table->add_field('name', XMLDB_TYPE_CHAR, '255', null, XMLDB_NOTNULL, null, null);
            $table->add_field('unit', XMLDB_TYPE_CHAR, '50', null, XMLDB_NOTNULL, null, null);
            $table->add_field('periodtype', XMLDB_TYPE_CHAR, '10', null, XMLDB_NOTNULL, null, null);
            $table->add_field('direction', XMLDB_TYPE_CHAR, '10', null, XMLDB_NOTNULL, null, null);
            $table->add_field('timecreated', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $table->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);
            $table->add_index('uuid_uix', XMLDB_INDEX_UNIQUE, ['uuid']);
            $table->add_index('company_code_uix', XMLDB_INDEX_UNIQUE, ['companyuuid', 'code']);
            $table->add_index('product_ix', XMLDB_INDEX_NOTUNIQUE, ['companyuuid', 'productuuid']);
            $dbman->create_table($table);
        }
        $table = new xmldb_table('local_kbacompany_statval');
        if (!$dbman->table_exists($table)) {
            $table->add_field('id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE, null);
            $table->add_field('companyuuid', XMLDB_TYPE_CHAR, '36', null, XMLDB_NOTNULL, null, null);
            $table->add_field('statuuid', XMLDB_TYPE_CHAR, '36', null, XMLDB_NOTNULL, null, null);
            $table->add_field('periodstart', XMLDB_TYPE_CHAR, '10', null, XMLDB_NOTNULL, null, null);
            $table->add_field('kind', XMLDB_TYPE_CHAR, '10', null, XMLDB_NOTNULL, null, null);
            $table->add_field('amount', XMLDB_TYPE_NUMBER, '18,6', null, XMLDB_NOTNULL, null, null);
            $table->add_field('revision', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '1');
            $table->add_field('reason', XMLDB_TYPE_TEXT, null, null, null, null, null);
            $table->add_field('writerid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $table->add_field('timecreated', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $table->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);
            $table->add_index('revision_uix', XMLDB_INDEX_UNIQUE, ['statuuid', 'periodstart', 'kind', 'revision']);
            $table->add_index('company_stat_ix', XMLDB_INDEX_NOTUNIQUE, ['companyuuid', 'statuuid']);
            $table->add_index('writer_ix', XMLDB_INDEX_NOTUNIQUE, ['writerid']);
            $dbman->create_table($table);
        }
        upgrade_plugin_savepoint(true, 2026091208, 'local', 'kbacompany');
    }

    if ($oldversion < 2026091209) {
        // Draft foundation only; never seed business objects or migrate source facts.
        $table = new xmldb_table('local_kbacompany_acobj');
        if (!$dbman->table_exists($table)) {
            $table->add_field('id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE, null);
            $table->add_field('companyuuid', XMLDB_TYPE_CHAR, '36', null, XMLDB_NOTNULL, null, null);
            $table->add_field('uuid', XMLDB_TYPE_CHAR, '36', null, XMLDB_NOTNULL, null, null);
            $table->add_field('kind', XMLDB_TYPE_CHAR, '20', null, XMLDB_NOTNULL, null, null);
            $table->add_field('code', XMLDB_TYPE_CHAR, '50', null, XMLDB_NOTNULL, null, null);
            $table->add_field('name', XMLDB_TYPE_CHAR, '255', null, XMLDB_NOTNULL, null, null);
            $table->add_field('description', XMLDB_TYPE_TEXT, null, null, null, null, null);
            $table->add_field('parentuuid', XMLDB_TYPE_CHAR, '36', null, XMLDB_NOTNULL, null, '');
            $table->add_field('payload', XMLDB_TYPE_TEXT, null, null, null, null, null);
            $table->add_field('revision', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '1');
            $table->add_field('status', XMLDB_TYPE_CHAR, '20', null, XMLDB_NOTNULL, null, 'draft');
            $table->add_field('timecreated', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $table->add_field('timemodified', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $table->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);
            $table->add_index('uuid_uix', XMLDB_INDEX_UNIQUE, ['uuid']);
            $table->add_index('company_code_uix', XMLDB_INDEX_UNIQUE, ['companyuuid', 'code']);
            $table->add_index('parent_ix', XMLDB_INDEX_NOTUNIQUE, ['companyuuid', 'parentuuid']);
            $dbman->create_table($table);
        }
        $table = new xmldb_table('local_kbacompany_aclink');
        if (!$dbman->table_exists($table)) {
            $table->add_field('id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE, null);
            $table->add_field('companyuuid', XMLDB_TYPE_CHAR, '36', null, XMLDB_NOTNULL, null, null);
            $table->add_field('planuuid', XMLDB_TYPE_CHAR, '36', null, XMLDB_NOTNULL, null, null);
            $table->add_field('functionuuid', XMLDB_TYPE_CHAR, '36', null, XMLDB_NOTNULL, null, null);
            $table->add_field('timecreated', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $table->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);
            $table->add_index('plan_function_uix', XMLDB_INDEX_UNIQUE, ['companyuuid', 'planuuid', 'functionuuid']);
            $dbman->create_table($table);
        }
        $table = new xmldb_table('local_kbacompany_aclog');
        if (!$dbman->table_exists($table)) {
            $table->add_field('id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE, null);
            $table->add_field('companyuuid', XMLDB_TYPE_CHAR, '36', null, XMLDB_NOTNULL, null, null);
            $table->add_field('objectuuid', XMLDB_TYPE_CHAR, '36', null, XMLDB_NOTNULL, null, null);
            $table->add_field('action', XMLDB_TYPE_CHAR, '20', null, XMLDB_NOTNULL, null, null);
            $table->add_field('actorid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $table->add_field('oldvalue', XMLDB_TYPE_TEXT, null, null, null, null, null);
            $table->add_field('newvalue', XMLDB_TYPE_TEXT, null, null, null, null, null);
            $table->add_field('timecreated', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $table->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);
            $table->add_index('object_ix', XMLDB_INDEX_NOTUNIQUE, ['companyuuid', 'objectuuid']);
            $table->add_index('actor_ix', XMLDB_INDEX_NOTUNIQUE, ['actorid']);
            $dbman->create_table($table);
        }
        upgrade_plugin_savepoint(true, 2026091209, 'local', 'kbacompany');
    }

    if ($oldversion < 2026091210) {
        // UI-only release: no schema or business data changes.
        upgrade_plugin_savepoint(true, 2026091210, 'local', 'kbacompany');
    }


    if ($oldversion < 2026091211) {
        // Additive workflow tables only; no inferred assignments or approvals.
        $table = new xmldb_table('local_kbacompany_wfassign');
        if (!$dbman->table_exists($table)) {
            $table->add_field('id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE, null);
            $table->add_field('companyuuid', XMLDB_TYPE_CHAR, '36', null, XMLDB_NOTNULL, null, null);
            $table->add_field('uuid', XMLDB_TYPE_CHAR, '36', null, XMLDB_NOTNULL, null, null);
            $table->add_field('functionuuid', XMLDB_TYPE_CHAR, '36', null, XMLDB_NOTNULL, null, null);
            $table->add_field('userid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $table->add_field('timecreated', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $table->add_field('timeended', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $table->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);
            $table->add_index('uuid_uix', XMLDB_INDEX_UNIQUE, ['uuid']);
            $table->add_index('function_ix', XMLDB_INDEX_NOTUNIQUE, ['companyuuid', 'functionuuid', 'timeended']);
            $table->add_index('user_ix', XMLDB_INDEX_NOTUNIQUE, ['userid']);
            $dbman->create_table($table);
        }
        $table = new xmldb_table('local_kbacompany_wfapprove');
        if (!$dbman->table_exists($table)) {
            $table->add_field('id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE, null);
            $table->add_field('companyuuid', XMLDB_TYPE_CHAR, '36', null, XMLDB_NOTNULL, null, null);
            $table->add_field('uuid', XMLDB_TYPE_CHAR, '36', null, XMLDB_NOTNULL, null, null);
            $table->add_field('objectuuid', XMLDB_TYPE_CHAR, '36', null, XMLDB_NOTNULL, null, null);
            $table->add_field('revision', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '1');
            $table->add_field('fingerprint', XMLDB_TYPE_CHAR, '64', null, XMLDB_NOTNULL, null, null);
            $table->add_field('snapshot', XMLDB_TYPE_TEXT, null, null, null, null, null);
            $table->add_field('approvedby', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $table->add_field('timecreated', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $table->add_field('revoked', XMLDB_TYPE_INTEGER, '1', null, XMLDB_NOTNULL, null, '0');
            $table->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);
            $table->add_index('uuid_uix', XMLDB_INDEX_UNIQUE, ['uuid']);
            $table->add_index('object_ix', XMLDB_INDEX_NOTUNIQUE, ['companyuuid', 'objectuuid']);
            $table->add_index('author_ix', XMLDB_INDEX_NOTUNIQUE, ['approvedby']);
            $dbman->create_table($table);
        }
        $table = new xmldb_table('local_kbacompany_wfwork');
        if (!$dbman->table_exists($table)) {
            $table->add_field('id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE, null);
            $table->add_field('companyuuid', XMLDB_TYPE_CHAR, '36', null, XMLDB_NOTNULL, null, null);
            $table->add_field('uuid', XMLDB_TYPE_CHAR, '36', null, XMLDB_NOTNULL, null, null);
            $table->add_field('kind', XMLDB_TYPE_CHAR, '10', null, XMLDB_NOTNULL, null, null);
            $table->add_field('parentuuid', XMLDB_TYPE_CHAR, '36', null, XMLDB_NOTNULL, null, '');
            $table->add_field('functionuuid', XMLDB_TYPE_CHAR, '36', null, XMLDB_NOTNULL, null, '');
            $table->add_field('approvaluuid', XMLDB_TYPE_CHAR, '36', null, XMLDB_NOTNULL, null, null);
            $table->add_field('normuuid', XMLDB_TYPE_CHAR, '36', null, XMLDB_NOTNULL, null, '');
            $table->add_field('assignmentuuid', XMLDB_TYPE_CHAR, '36', null, XMLDB_NOTNULL, null, '');
            $table->add_field('userid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $table->add_field('reviewerid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $table->add_field('name', XMLDB_TYPE_CHAR, '255', null, XMLDB_NOTNULL, null, null);
            $table->add_field('description', XMLDB_TYPE_TEXT, null, null, null, null, null);
            $table->add_field('acceptance', XMLDB_TYPE_TEXT, null, null, null, null, null);
            $table->add_field('deadline', XMLDB_TYPE_CHAR, '10', null, XMLDB_NOTNULL, null, null);
            $table->add_field('status', XMLDB_TYPE_CHAR, '20', null, XMLDB_NOTNULL, null, 'draft');
            $table->add_field('revision', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '1');
            $table->add_field('attempt', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $table->add_field('timecreated', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $table->add_field('timemodified', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $table->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);
            $table->add_index('uuid_uix', XMLDB_INDEX_UNIQUE, ['uuid']);
            $table->add_index('parent_ix', XMLDB_INDEX_NOTUNIQUE, ['companyuuid', 'parentuuid']);
            $table->add_index('assignee_ix', XMLDB_INDEX_NOTUNIQUE, ['userid']);
            $table->add_index('reviewer_ix', XMLDB_INDEX_NOTUNIQUE, ['reviewerid']);
            $dbman->create_table($table);
        }
        $table = new xmldb_table('local_kbacompany_wfevidence');
        if (!$dbman->table_exists($table)) {
            $table->add_field('id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE, null);
            $table->add_field('companyuuid', XMLDB_TYPE_CHAR, '36', null, XMLDB_NOTNULL, null, null);
            $table->add_field('uuid', XMLDB_TYPE_CHAR, '36', null, XMLDB_NOTNULL, null, null);
            $table->add_field('taskuuid', XMLDB_TYPE_CHAR, '36', null, XMLDB_NOTNULL, null, null);
            $table->add_field('attempt', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '1');
            $table->add_field('body', XMLDB_TYPE_TEXT, null, null, null, null, null);
            $table->add_field('url', XMLDB_TYPE_TEXT, null, null, null, null, null);
            $table->add_field('userid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $table->add_field('timecreated', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $table->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);
            $table->add_index('uuid_uix', XMLDB_INDEX_UNIQUE, ['uuid']);
            $table->add_index('attempt_uix', XMLDB_INDEX_UNIQUE, ['companyuuid', 'taskuuid', 'attempt']);
            $table->add_index('user_ix', XMLDB_INDEX_NOTUNIQUE, ['userid']);
            $dbman->create_table($table);
        }
        upgrade_plugin_savepoint(true, 2026091211, 'local', 'kbacompany');
    }


    if ($oldversion < 2026091212) {
        // Additive deviation records only; no inferred causes, corrections or assessments.
        $table = new xmldb_table('local_kbacompany_deviation');
        if (!$dbman->table_exists($table)) {
            $table->add_field('id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE, null);
            $table->add_field('companyuuid', XMLDB_TYPE_CHAR, '36', null, XMLDB_NOTNULL, null, null);
            $table->add_field('uuid', XMLDB_TYPE_CHAR, '36', null, XMLDB_NOTNULL, null, null);
            $table->add_field('approvaluuid', XMLDB_TYPE_CHAR, '36', null, XMLDB_NOTNULL, null, null);
            $table->add_field('normuuid', XMLDB_TYPE_CHAR, '36', null, XMLDB_NOTNULL, null, null);
            $table->add_field('actual', XMLDB_TYPE_CHAR, '32', null, XMLDB_NOTNULL, null, null);
            $table->add_field('observedon', XMLDB_TYPE_CHAR, '10', null, XMLDB_NOTNULL, null, null);
            $table->add_field('evidence', XMLDB_TYPE_TEXT, null, null, null, null, null);
            $table->add_field('cause', XMLDB_TYPE_TEXT, null, null, null, null, null);
            $table->add_field('status', XMLDB_TYPE_CHAR, '20', null, XMLDB_NOTNULL, null, 'open');
            $table->add_field('revision', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '1');
            $table->add_field('createdby', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $table->add_field('analysedby', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $table->add_field('closedby', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $table->add_field('timecreated', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $table->add_field('timemodified', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $table->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);
            $table->add_index('uuid_uix', XMLDB_INDEX_UNIQUE, ['uuid']);
            $table->add_index('norm_ix', XMLDB_INDEX_NOTUNIQUE, ['companyuuid', 'normuuid']);
            $table->add_index('author_ix', XMLDB_INDEX_NOTUNIQUE, ['createdby']);
            $table->add_index('analyst_ix', XMLDB_INDEX_NOTUNIQUE, ['analysedby']);
            $table->add_index('closer_ix', XMLDB_INDEX_NOTUNIQUE, ['closedby']);
            $dbman->create_table($table);
        }
        $table = new xmldb_table('local_kbacompany_devlink');
        if (!$dbman->table_exists($table)) {
            $table->add_field('id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE, null);
            $table->add_field('companyuuid', XMLDB_TYPE_CHAR, '36', null, XMLDB_NOTNULL, null, null);
            $table->add_field('uuid', XMLDB_TYPE_CHAR, '36', null, XMLDB_NOTNULL, null, null);
            $table->add_field('deviationuuid', XMLDB_TYPE_CHAR, '36', null, XMLDB_NOTNULL, null, null);
            $table->add_field('taskuuid', XMLDB_TYPE_CHAR, '36', null, XMLDB_NOTNULL, null, null);
            $table->add_field('reason', XMLDB_TYPE_TEXT, null, null, null, null, null);
            $table->add_field('createdby', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $table->add_field('active', XMLDB_TYPE_INTEGER, '1', null, XMLDB_NOTNULL, null, '1');
            $table->add_field('timecreated', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $table->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);
            $table->add_index('uuid_uix', XMLDB_INDEX_UNIQUE, ['uuid']);
            $table->add_index('task_uix', XMLDB_INDEX_UNIQUE, ['companyuuid', 'deviationuuid', 'taskuuid']);
            $table->add_index('author_ix', XMLDB_INDEX_NOTUNIQUE, ['createdby']);
            $dbman->create_table($table);
        }
        $table = new xmldb_table('local_kbacompany_devcheck');
        if (!$dbman->table_exists($table)) {
            $table->add_field('id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE, null);
            $table->add_field('companyuuid', XMLDB_TYPE_CHAR, '36', null, XMLDB_NOTNULL, null, null);
            $table->add_field('uuid', XMLDB_TYPE_CHAR, '36', null, XMLDB_NOTNULL, null, null);
            $table->add_field('deviationuuid', XMLDB_TYPE_CHAR, '36', null, XMLDB_NOTNULL, null, null);
            $table->add_field('approvaluuid', XMLDB_TYPE_CHAR, '36', null, XMLDB_NOTNULL, null, null);
            $table->add_field('normuuid', XMLDB_TYPE_CHAR, '36', null, XMLDB_NOTNULL, null, null);
            $table->add_field('actual', XMLDB_TYPE_CHAR, '32', null, XMLDB_NOTNULL, null, null);
            $table->add_field('observedon', XMLDB_TYPE_CHAR, '10', null, XMLDB_NOTNULL, null, null);
            $table->add_field('evidence', XMLDB_TYPE_TEXT, null, null, null, null, null);
            $table->add_field('tasksfingerprint', XMLDB_TYPE_CHAR, '64', null, XMLDB_NOTNULL, null, null);
            $table->add_field('inrange', XMLDB_TYPE_INTEGER, '1', null, XMLDB_NOTNULL, null, '0');
            $table->add_field('createdby', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $table->add_field('timecreated', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $table->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);
            $table->add_index('uuid_uix', XMLDB_INDEX_UNIQUE, ['uuid']);
            $table->add_index('deviation_ix', XMLDB_INDEX_NOTUNIQUE, ['companyuuid', 'deviationuuid']);
            $table->add_index('author_ix', XMLDB_INDEX_NOTUNIQUE, ['createdby']);
            $dbman->create_table($table);
        }
        upgrade_plugin_savepoint(true, 2026091212, 'local', 'kbacompany');
    }

    if ($oldversion < 2026091213) {
        $table = new xmldb_table('local_kbacompany_wfrepeat');
        if (!$dbman->table_exists($table)) {
            $table->add_field('id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE, null);
            $table->add_field('companyuuid', XMLDB_TYPE_CHAR, '36', null, XMLDB_NOTNULL, null, null);
            $table->add_field('uuid', XMLDB_TYPE_CHAR, '36', null, XMLDB_NOTNULL, null, null);
            $table->add_field('anchoruuid', XMLDB_TYPE_CHAR, '36', null, XMLDB_NOTNULL, null, null);
            $table->add_field('sourceuuid', XMLDB_TYPE_CHAR, '36', null, XMLDB_NOTNULL, null, null);
            $table->add_field('planuuid', XMLDB_TYPE_CHAR, '36', null, XMLDB_NOTNULL, null, null);
            $table->add_field('periodtype', XMLDB_TYPE_CHAR, '10', null, XMLDB_NOTNULL, null, null);
            $table->add_field('periodstart', XMLDB_TYPE_CHAR, '10', null, XMLDB_NOTNULL, null, null);
            $table->add_field('checkedat', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $table->add_field('timecreated', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $table->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);
            $table->add_index('uuid_uix', XMLDB_INDEX_UNIQUE, ['uuid']);
            $table->add_index('plan_uix', XMLDB_INDEX_UNIQUE, ['planuuid']);
            $table->add_index('cycle_uix', XMLDB_INDEX_UNIQUE, ['companyuuid', 'anchoruuid', 'periodtype', 'periodstart']);
            $dbman->create_table($table);
        }
        upgrade_plugin_savepoint(true, 2026091213, 'local', 'kbacompany');
    }

    if ($oldversion < 2026091214) {
        $table = new xmldb_table('local_kbacompany_flow');
        if (!$dbman->table_exists($table)) {
            $table->add_field('id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE, null);
            $table->add_field('companyuuid', XMLDB_TYPE_CHAR, '36', null, XMLDB_NOTNULL, null, null);
            $table->add_field('uuid', XMLDB_TYPE_CHAR, '36', null, XMLDB_NOTNULL, null, null);
            $table->add_field('code', XMLDB_TYPE_CHAR, '50', null, XMLDB_NOTNULL, null, null);
            $table->add_field('name', XMLDB_TYPE_CHAR, '255', null, XMLDB_NOTNULL, null, null);
            $table->add_field('fromuuid', XMLDB_TYPE_CHAR, '36', null, XMLDB_NOTNULL, null, null);
            $table->add_field('touuid', XMLDB_TYPE_CHAR, '36', null, XMLDB_NOTNULL, null, null);
            $table->add_field('productuuid', XMLDB_TYPE_CHAR, '36', null, XMLDB_NOTNULL, null, null);
            $table->add_field('criteria', XMLDB_TYPE_TEXT, null, null, null, null, null);
            $table->add_field('handoff', XMLDB_TYPE_TEXT, null, null, null, null, null);
            $table->add_field('status', XMLDB_TYPE_CHAR, '10', null, XMLDB_NOTNULL, null, 'draft');
            $table->add_field('revision', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '1');
            $table->add_field('snapshot', XMLDB_TYPE_TEXT, null, null, null, null, null);
            $table->add_field('timecreated', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $table->add_field('timemodified', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $table->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);
            $table->add_index('uuid_uix', XMLDB_INDEX_UNIQUE, ['uuid']);
            $table->add_index('code_uix', XMLDB_INDEX_UNIQUE, ['companyuuid', 'code']);
            $table->add_index('from_ix', XMLDB_INDEX_NOTUNIQUE, ['companyuuid', 'fromuuid']);
            $table->add_index('to_ix', XMLDB_INDEX_NOTUNIQUE, ['companyuuid', 'touuid']);
            $dbman->create_table($table);
        }
        upgrade_plugin_savepoint(true, 2026091214, 'local', 'kbacompany');
    }

    if ($oldversion < 2026091215) {
        $table = new xmldb_table('local_kbacompany_design');
        if (!$dbman->table_exists($table)) {
            $table->add_field('id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE, null);
            $table->add_field('companyuuid', XMLDB_TYPE_CHAR, '36', null, XMLDB_NOTNULL, null, null);
            $table->add_field('uuid', XMLDB_TYPE_CHAR, '36', null, XMLDB_NOTNULL, null, null);
            $table->add_field('name', XMLDB_TYPE_CHAR, '255', null, XMLDB_NOTNULL, null, null);
            $table->add_field('sourcekind', XMLDB_TYPE_CHAR, '12', null, XMLDB_NOTNULL, null, null);
            $table->add_field('sourceuuid', XMLDB_TYPE_CHAR, '36', null, XMLDB_NOTNULL, null, '');
            $table->add_field('sourcerevision', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $table->add_field('status', XMLDB_TYPE_CHAR, '10', null, XMLDB_NOTNULL, null, 'draft');
            $table->add_field('revision', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '1');
            $table->add_field('timecreated', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $table->add_field('timemodified', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $table->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);
            $table->add_index('uuid_uix', XMLDB_INDEX_UNIQUE, ['uuid']);
            $table->add_index('company_ix', XMLDB_INDEX_NOTUNIQUE, ['companyuuid']);
            $dbman->create_table($table);
        }
        $table = new xmldb_table('local_kbacompany_designver');
        if (!$dbman->table_exists($table)) {
            $table->add_field('id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE, null);
            $table->add_field('companyuuid', XMLDB_TYPE_CHAR, '36', null, XMLDB_NOTNULL, null, null);
            $table->add_field('uuid', XMLDB_TYPE_CHAR, '36', null, XMLDB_NOTNULL, null, null);
            $table->add_field('designuuid', XMLDB_TYPE_CHAR, '36', null, XMLDB_NOTNULL, null, null);
            $table->add_field('revision', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '1');
            $table->add_field('payload', XMLDB_TYPE_TEXT, null, null, null, null, null);
            $table->add_field('digest', XMLDB_TYPE_CHAR, '64', null, XMLDB_NOTNULL, null, null);
            $table->add_field('timecreated', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $table->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);
            $table->add_index('uuid_uix', XMLDB_INDEX_UNIQUE, ['uuid']);
            $table->add_index('version_uix', XMLDB_INDEX_UNIQUE, ['companyuuid', 'designuuid', 'revision']);
            $dbman->create_table($table);
        }
        upgrade_plugin_savepoint(true, 2026091215, 'local', 'kbacompany');
    }

    if ($oldversion < 2026091216) {
        // Navigation-only hotfix. No data or schema changes.
        upgrade_plugin_savepoint(true, 2026091216, 'local', 'kbacompany');
    }

    if ($oldversion < 2026091217) {
        // Versioned JSON editor extension only. No data or schema changes.
        upgrade_plugin_savepoint(true, 2026091217, 'local', 'kbacompany');
    }

    if ($oldversion < 2026091218) {
        // Versioned JSON project-model extension only. No data or schema changes.
        upgrade_plugin_savepoint(true, 2026091218, 'local', 'kbacompany');
    }

    if ($oldversion < 2026091219) {
        $table = new xmldb_table('local_kbacompany_orgreport');
        if (!$dbman->table_exists($table)) {
            $table->add_field('id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE, null);
            $table->add_field('companyuuid', XMLDB_TYPE_CHAR, '36', null, XMLDB_NOTNULL, null, null);
            $table->add_field('nodeuuid', XMLDB_TYPE_CHAR, '36', null, XMLDB_NOTNULL, null, null);
            $table->add_field('manageruuid', XMLDB_TYPE_CHAR, '36', null, XMLDB_NOTNULL, null, '');
            $table->add_field('designuuid', XMLDB_TYPE_CHAR, '36', null, XMLDB_NOTNULL, null, null);
            $table->add_field('designrevision', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '1');
            $table->add_field('timecreated', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $table->add_field('timemodified', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $table->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);
            $table->add_index('node_uix', XMLDB_INDEX_UNIQUE, ['companyuuid', 'nodeuuid']);
            $table->add_index('manager_ix', XMLDB_INDEX_NOTUNIQUE, ['companyuuid', 'manageruuid']);
            $table->add_index('design_ix', XMLDB_INDEX_NOTUNIQUE, ['companyuuid', 'designuuid', 'designrevision']);
            $dbman->create_table($table);
        }
        upgrade_plugin_savepoint(true, 2026091219, 'local', 'kbacompany');
    }

    if ($oldversion < 2026091220) {
        $table = new xmldb_table('local_kbacompany_policy');
        if (!$dbman->table_exists($table)) {
            $table->add_field('id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE, null);
            $table->add_field('companyuuid', XMLDB_TYPE_CHAR, '36', null, XMLDB_NOTNULL, null, null);
            $table->add_field('uuid', XMLDB_TYPE_CHAR, '36', null, XMLDB_NOTNULL, null, null);
            $table->add_field('revision', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '1');
            $table->add_field('kind', XMLDB_TYPE_CHAR, '12', null, XMLDB_NOTNULL, null, null);
            $table->add_field('code', XMLDB_TYPE_CHAR, '50', null, XMLDB_NOTNULL, null, null);
            $table->add_field('title', XMLDB_TYPE_CHAR, '255', null, XMLDB_NOTNULL, null, null);
            $table->add_field('purpose', XMLDB_TYPE_TEXT, null, null, null, null, null);
            $table->add_field('scopetype', XMLDB_TYPE_CHAR, '12', null, XMLDB_NOTNULL, null, null);
            $table->add_field('scopeuuid', XMLDB_TYPE_CHAR, '36', null, XMLDB_NOTNULL, null, '');
            $table->add_field('ownertype', XMLDB_TYPE_CHAR, '12', null, XMLDB_NOTNULL, null, null);
            $table->add_field('owneruuid', XMLDB_TYPE_CHAR, '36', null, XMLDB_NOTNULL, null, null);
            $table->add_field('effectivefrom', XMLDB_TYPE_CHAR, '10', null, XMLDB_NOTNULL, null, '');
            $table->add_field('effectiveto', XMLDB_TYPE_CHAR, '10', null, XMLDB_NOTNULL, null, '');
            $table->add_field('documenturl', XMLDB_TYPE_TEXT, null, null, null, null, null);
            $table->add_field('basis', XMLDB_TYPE_TEXT, null, null, null, null, null);
            $table->add_field('status', XMLDB_TYPE_CHAR, '12', null, XMLDB_NOTNULL, null, 'draft');
            $table->add_field('reason', XMLDB_TYPE_TEXT, null, null, null, null, null);
            $table->add_field('actorid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $table->add_field('timecreated', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $table->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);
            $table->add_index('revision_uix', XMLDB_INDEX_UNIQUE, ['companyuuid', 'uuid', 'revision']);
            $table->add_index('code_ix', XMLDB_INDEX_NOTUNIQUE, ['companyuuid', 'code']);
            $table->add_index('status_ix', XMLDB_INDEX_NOTUNIQUE, ['companyuuid', 'status']);
            $table->add_index('owner_ix', XMLDB_INDEX_NOTUNIQUE, ['companyuuid', 'ownertype', 'owneruuid']);
            $table->add_index('actor_ix', XMLDB_INDEX_NOTUNIQUE, ['actorid']);
            $dbman->create_table($table);
        }
        upgrade_plugin_savepoint(true, 2026091220, 'local', 'kbacompany');
    }


    if ($oldversion < 2026091221) {
        // Core stabilization: contracts, permissions and optimistic live-design apply. No schema changes.
        upgrade_plugin_savepoint(true, 2026091221, 'local', 'kbacompany');
    }

    if ($oldversion < 2026091222) {
        // BMS Semantic Core: explicit append-only cross-domain relation graph.
        $table = new xmldb_table('local_kbacompany_semrel');
        if (!$dbman->table_exists($table)) {
            $table->add_field('id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE, null);
            $table->add_field('companyuuid', XMLDB_TYPE_CHAR, '36', null, XMLDB_NOTNULL, null, null);
            $table->add_field('uuid', XMLDB_TYPE_CHAR, '36', null, XMLDB_NOTNULL, null, null);
            $table->add_field('revision', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '1');
            $table->add_field('fromtype', XMLDB_TYPE_CHAR, '20', null, XMLDB_NOTNULL, null, null);
            $table->add_field('fromuuid', XMLDB_TYPE_CHAR, '36', null, XMLDB_NOTNULL, null, null);
            $table->add_field('relation', XMLDB_TYPE_CHAR, '30', null, XMLDB_NOTNULL, null, null);
            $table->add_field('totype', XMLDB_TYPE_CHAR, '20', null, XMLDB_NOTNULL, null, null);
            $table->add_field('touuid', XMLDB_TYPE_CHAR, '36', null, XMLDB_NOTNULL, null, null);
            $table->add_field('status', XMLDB_TYPE_CHAR, '12', null, XMLDB_NOTNULL, null, 'active');
            $table->add_field('timecreated', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $table->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);
            $table->add_index('revision_uix', XMLDB_INDEX_UNIQUE, ['companyuuid', 'uuid', 'revision']);
            $table->add_index('from_ix', XMLDB_INDEX_NOTUNIQUE, ['companyuuid', 'fromtype', 'fromuuid', 'status']);
            $table->add_index('to_ix', XMLDB_INDEX_NOTUNIQUE, ['companyuuid', 'totype', 'touuid', 'status']);
            $table->add_index('relation_ix', XMLDB_INDEX_NOTUNIQUE, ['companyuuid', 'relation', 'status']);
            $dbman->create_table($table);
        }
        upgrade_plugin_savepoint(true, 2026091222, 'local', 'kbacompany');
    }

    if ($oldversion < 2026091223) {
        // v0.2.9.1 projects OFS + CKP from existing canonical tables. No business-data migration is needed.
        upgrade_plugin_savepoint(true, 2026091223, 'local', 'kbacompany');
    }

    if ($oldversion < 2026091224) {
        // v0.2.9.2 adds the canonical versioned Process Architecture store.
        $table = new xmldb_table('local_kbacompany_process');
        if (!$dbman->table_exists($table)) {
            $table->add_field('id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE, null);
            $table->add_field('companyuuid', XMLDB_TYPE_CHAR, '36', null, XMLDB_NOTNULL, null, null);
            $table->add_field('uuid', XMLDB_TYPE_CHAR, '36', null, XMLDB_NOTNULL, null, null);
            $table->add_field('revision', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '1');
            $table->add_field('code', XMLDB_TYPE_CHAR, '50', null, XMLDB_NOTNULL, null, null);
            $table->add_field('name', XMLDB_TYPE_CHAR, '255', null, XMLDB_NOTNULL, null, null);
            $table->add_field('processtype', XMLDB_TYPE_CHAR, '12', null, XMLDB_NOTNULL, null, null);
            $table->add_field('processlevel', XMLDB_TYPE_CHAR, '2', null, XMLDB_NOTNULL, null, null);
            $table->add_field('parentuuid', XMLDB_TYPE_CHAR, '36', null, XMLDB_NOTNULL, null, '');
            $table->add_field('ownerorgunituuid', XMLDB_TYPE_CHAR, '36', null, XMLDB_NOTNULL, null, '');
            $table->add_field('productuuid', XMLDB_TYPE_CHAR, '36', null, XMLDB_NOTNULL, null, '');
            $table->add_field('purpose', XMLDB_TYPE_TEXT, null, null, null, null, null);
            $table->add_field('status', XMLDB_TYPE_CHAR, '12', null, XMLDB_NOTNULL, null, 'draft');
            $table->add_field('createdby', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $table->add_field('timecreated', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $table->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);
            $table->add_index('revision_uix', XMLDB_INDEX_UNIQUE, ['companyuuid', 'uuid', 'revision']);
            $table->add_index('code_ix', XMLDB_INDEX_NOTUNIQUE, ['companyuuid', 'code']);
            $table->add_index('parent_ix', XMLDB_INDEX_NOTUNIQUE, ['companyuuid', 'parentuuid', 'status']);
            $table->add_index('type_ix', XMLDB_INDEX_NOTUNIQUE, ['companyuuid', 'processtype', 'processlevel', 'status']);
            $table->add_index('owner_ix', XMLDB_INDEX_NOTUNIQUE, ['companyuuid', 'ownerorgunituuid', 'status']);
            $table->add_index('product_ix', XMLDB_INDEX_NOTUNIQUE, ['companyuuid', 'productuuid', 'status']);
            $dbman->create_table($table);
        }
        upgrade_plugin_savepoint(true, 2026091224, 'local', 'kbacompany');
    }


    if ($oldversion < 2026091225) {
        // v0.3.0 adds versioned SIPOC + controlled BPMN process-model drafts and published snapshots.
        $table = new xmldb_table('local_kbacompany_procmodel');
        if (!$dbman->table_exists($table)) {
            $table->add_field('id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE, null);
            $table->add_field('companyuuid', XMLDB_TYPE_CHAR, '36', null, XMLDB_NOTNULL, null, null);
            $table->add_field('processuuid', XMLDB_TYPE_CHAR, '36', null, XMLDB_NOTNULL, null, null);
            $table->add_field('revision', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '1');
            $table->add_field('processrevision', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $table->add_field('changeversion', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '1');
            $table->add_field('status', XMLDB_TYPE_CHAR, '12', null, XMLDB_NOTNULL, null, 'draft');
            $table->add_field('scope', XMLDB_TYPE_TEXT, null, null, null, null, null);
            $table->add_field('triggertext', XMLDB_TYPE_TEXT, null, null, null, null, null);
            $table->add_field('startcondition', XMLDB_TYPE_TEXT, null, null, null, null, null);
            $table->add_field('endcondition', XMLDB_TYPE_TEXT, null, null, null, null, null);
            $table->add_field('createdby', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $table->add_field('modifiedby', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $table->add_field('timecreated', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $table->add_field('timemodified', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $table->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);
            $table->add_index('revision_uix', XMLDB_INDEX_UNIQUE, ['companyuuid', 'processuuid', 'revision']);
            $table->add_index('status_ix', XMLDB_INDEX_NOTUNIQUE, ['companyuuid', 'processuuid', 'status']);
            $dbman->create_table($table);
        }
        $table = new xmldb_table('local_kbacompany_procelem');
        if (!$dbman->table_exists($table)) {
            $table->add_field('id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE, null);
            $table->add_field('companyuuid', XMLDB_TYPE_CHAR, '36', null, XMLDB_NOTNULL, null, null);
            $table->add_field('modelid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
            $table->add_field('uuid', XMLDB_TYPE_CHAR, '36', null, XMLDB_NOTNULL, null, null);
            $table->add_field('elementtype', XMLDB_TYPE_CHAR, '20', null, XMLDB_NOTNULL, null, null);
            $table->add_field('code', XMLDB_TYPE_CHAR, '50', null, XMLDB_NOTNULL, null, '');
            $table->add_field('name', XMLDB_TYPE_CHAR, '255', null, XMLDB_NOTNULL, null, null);
            $table->add_field('description', XMLDB_TYPE_TEXT, null, null, null, null, null);
            $table->add_field('executortype', XMLDB_TYPE_CHAR, '20', null, XMLDB_NOTNULL, null, '');
            $table->add_field('executoruuid', XMLDB_TYPE_CHAR, '36', null, XMLDB_NOTNULL, null, '');
            $table->add_field('laneuuid', XMLDB_TYPE_CHAR, '36', null, XMLDB_NOTNULL, null, '');
            $table->add_field('productuuid', XMLDB_TYPE_CHAR, '36', null, XMLDB_NOTNULL, null, '');
            $table->add_field('outputtext', XMLDB_TYPE_TEXT, null, null, null, null, null);
            $table->add_field('sortorder', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $table->add_field('active', XMLDB_TYPE_INTEGER, '1', null, XMLDB_NOTNULL, null, '1');
            $table->add_field('timecreated', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $table->add_field('timemodified', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $table->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);
            $table->add_index('uuid_uix', XMLDB_INDEX_UNIQUE, ['modelid', 'uuid']);
            $table->add_index('type_ix', XMLDB_INDEX_NOTUNIQUE, ['companyuuid', 'modelid', 'elementtype', 'active']);
            $table->add_index('executor_ix', XMLDB_INDEX_NOTUNIQUE, ['companyuuid', 'executortype', 'executoruuid', 'active']);
            $table->add_index('product_ix', XMLDB_INDEX_NOTUNIQUE, ['companyuuid', 'productuuid', 'active']);
            $dbman->create_table($table);
        }
        $table = new xmldb_table('local_kbacompany_procflow');
        if (!$dbman->table_exists($table)) {
            $table->add_field('id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE, null);
            $table->add_field('companyuuid', XMLDB_TYPE_CHAR, '36', null, XMLDB_NOTNULL, null, null);
            $table->add_field('modelid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
            $table->add_field('uuid', XMLDB_TYPE_CHAR, '36', null, XMLDB_NOTNULL, null, null);
            $table->add_field('fromuuid', XMLDB_TYPE_CHAR, '36', null, XMLDB_NOTNULL, null, null);
            $table->add_field('touuid', XMLDB_TYPE_CHAR, '36', null, XMLDB_NOTNULL, null, null);
            $table->add_field('label', XMLDB_TYPE_CHAR, '255', null, XMLDB_NOTNULL, null, '');
            $table->add_field('conditiontext', XMLDB_TYPE_TEXT, null, null, null, null, null);
            $table->add_field('sortorder', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $table->add_field('active', XMLDB_TYPE_INTEGER, '1', null, XMLDB_NOTNULL, null, '1');
            $table->add_field('timecreated', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $table->add_field('timemodified', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $table->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);
            $table->add_index('uuid_uix', XMLDB_INDEX_UNIQUE, ['modelid', 'uuid']);
            $table->add_index('from_ix', XMLDB_INDEX_NOTUNIQUE, ['companyuuid', 'modelid', 'fromuuid', 'active']);
            $table->add_index('to_ix', XMLDB_INDEX_NOTUNIQUE, ['companyuuid', 'modelid', 'touuid', 'active']);
            $dbman->create_table($table);
        }
        upgrade_plugin_savepoint(true, 2026091225, 'local', 'kbacompany');
    }


    if ($oldversion < 2026091226) {
        // v0.3.1 adds STP annotations and version-scoped RACI assignments to the process model.
        $table = new xmldb_table('local_kbacompany_procelem');
        $field = new xmldb_field('activityuuid', XMLDB_TYPE_CHAR, '36', null, XMLDB_NOTNULL, null, '', 'outputtext');
        if ($dbman->table_exists($table) && !$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }
        $table = new xmldb_table('local_kbacompany_procraci');
        if (!$dbman->table_exists($table)) {
            $table->add_field('id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE, null);
            $table->add_field('companyuuid', XMLDB_TYPE_CHAR, '36', null, XMLDB_NOTNULL, null, null);
            $table->add_field('modelid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
            $table->add_field('uuid', XMLDB_TYPE_CHAR, '36', null, XMLDB_NOTNULL, null, null);
            $table->add_field('activityuuid', XMLDB_TYPE_CHAR, '36', null, XMLDB_NOTNULL, null, null);
            $table->add_field('partytype', XMLDB_TYPE_CHAR, '20', null, XMLDB_NOTNULL, null, null);
            $table->add_field('partyuuid', XMLDB_TYPE_CHAR, '36', null, XMLDB_NOTNULL, null, null);
            $table->add_field('racirole', XMLDB_TYPE_CHAR, '1', null, XMLDB_NOTNULL, null, null);
            $table->add_field('active', XMLDB_TYPE_INTEGER, '1', null, XMLDB_NOTNULL, null, '1');
            $table->add_field('timecreated', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $table->add_field('timemodified', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $table->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);
            $table->add_index('uuid_uix', XMLDB_INDEX_UNIQUE, ['modelid', 'uuid']);
            $table->add_index('activity_ix', XMLDB_INDEX_NOTUNIQUE, ['companyuuid', 'modelid', 'activityuuid', 'active']);
            $table->add_index('party_ix', XMLDB_INDEX_NOTUNIQUE, ['companyuuid', 'partytype', 'partyuuid', 'active']);
            $dbman->create_table($table);
        }
        upgrade_plugin_savepoint(true, 2026091226, 'local', 'kbacompany');
    }


    if ($oldversion < 2026091227) {
        // v0.3.2 introduces the canonical versioned Function model without rewriting legacy cascade functions.
        $table = new xmldb_table('local_kbacompany_function');
        if (!$dbman->table_exists($table)) {
            $table->add_field('id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE, null);
            $table->add_field('companyuuid', XMLDB_TYPE_CHAR, '36', null, XMLDB_NOTNULL, null, null);
            $table->add_field('uuid', XMLDB_TYPE_CHAR, '36', null, XMLDB_NOTNULL, null, null);
            $table->add_field('revision', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '1');
            $table->add_field('status', XMLDB_TYPE_CHAR, '12', null, XMLDB_NOTNULL, null, 'draft');
            $table->add_field('code', XMLDB_TYPE_CHAR, '50', null, XMLDB_NOTNULL, null, null);
            $table->add_field('name', XMLDB_TYPE_CHAR, '255', null, XMLDB_NOTNULL, null, null);
            $table->add_field('purpose', XMLDB_TYPE_TEXT, null, null, null, null, null);
            $table->add_field('productuuid', XMLDB_TYPE_CHAR, '36', null, XMLDB_NOTNULL, null, '');
            $table->add_field('producttext', XMLDB_TYPE_TEXT, null, null, null, null, null);
            $table->add_field('positionuuid', XMLDB_TYPE_CHAR, '36', null, XMLDB_NOTNULL, null, '');
            $table->add_field('sourceprocessuuid', XMLDB_TYPE_CHAR, '36', null, XMLDB_NOTNULL, null, null);
            $table->add_field('sourceactivityuuid', XMLDB_TYPE_CHAR, '36', null, XMLDB_NOTNULL, null, null);
            $table->add_field('sourcemodelrevision', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $table->add_field('sourceaction', XMLDB_TYPE_TEXT, null, null, null, null, null);
            $table->add_field('sourceoutput', XMLDB_TYPE_TEXT, null, null, null, null, null);
            $table->add_field('createdby', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $table->add_field('modifiedby', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $table->add_field('timecreated', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $table->add_field('timemodified', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $table->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);
            $table->add_index('revision_uix', XMLDB_INDEX_UNIQUE, ['companyuuid', 'uuid', 'revision']);
            $table->add_index('code_ix', XMLDB_INDEX_NOTUNIQUE, ['companyuuid', 'code', 'status']);
            $table->add_index('position_ix', XMLDB_INDEX_NOTUNIQUE, ['companyuuid', 'positionuuid', 'status']);
            $table->add_index('source_ix', XMLDB_INDEX_NOTUNIQUE, ['companyuuid', 'sourceactivityuuid', 'status']);
            $table->add_index('process_ix', XMLDB_INDEX_NOTUNIQUE, ['companyuuid', 'sourceprocessuuid', 'status']);
            $dbman->create_table($table);
        }
        upgrade_plugin_savepoint(true, 2026091227, 'local', 'kbacompany');
    }


    if ($oldversion < 2026091228) {
        // v0.3.3 introduces ISO-style controlled documents, review/approval evidence,
        // revision acknowledgements and immutable records without converting existing business data.
        $table = new xmldb_table('local_kbacompany_doc');
        if (!$dbman->table_exists($table)) {
            $table->add_field('id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE, null);
            $table->add_field('companyuuid', XMLDB_TYPE_CHAR, '36', null, XMLDB_NOTNULL, null, null);
            $table->add_field('uuid', XMLDB_TYPE_CHAR, '36', null, XMLDB_NOTNULL, null, null);
            $table->add_field('revision', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '1');
            $table->add_field('status', XMLDB_TYPE_CHAR, '16', null, XMLDB_NOTNULL, null, 'draft');
            $table->add_field('reviewround', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '1');
            $table->add_field('code', XMLDB_TYPE_CHAR, '80', null, XMLDB_NOTNULL, null, null);
            $table->add_field('title', XMLDB_TYPE_CHAR, '255', null, XMLDB_NOTNULL, null, null);
            $table->add_field('doctype', XMLDB_TYPE_CHAR, '24', null, XMLDB_NOTNULL, null, null);
            $table->add_field('doclevel', XMLDB_TYPE_INTEGER, '2', null, XMLDB_NOTNULL, null, '3');
            $table->add_field('ownerpositionuuid', XMLDB_TYPE_CHAR, '36', null, XMLDB_NOTNULL, null, '');
            $table->add_field('sourcekind', XMLDB_TYPE_CHAR, '24', null, XMLDB_NOTNULL, null, 'manual');
            $table->add_field('sourceuuid', XMLDB_TYPE_CHAR, '36', null, XMLDB_NOTNULL, null, '');
            $table->add_field('sourcerevision', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $table->add_field('sourcehash', XMLDB_TYPE_CHAR, '64', null, XMLDB_NOTNULL, null, '');
            $table->add_field('contentformat', XMLDB_TYPE_CHAR, '16', null, XMLDB_NOTNULL, null, 'text');
            $table->add_field('content', XMLDB_TYPE_TEXT, null, null, null, null, null);
            $table->add_field('changereason', XMLDB_TYPE_TEXT, null, null, null, null, null);
            $table->add_field('supersedesrevision', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $table->add_field('effectivefrom', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $table->add_field('effectiveto', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $table->add_field('createdby', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $table->add_field('modifiedby', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $table->add_field('timecreated', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $table->add_field('timemodified', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $table->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);
            $table->add_index('revision_uix', XMLDB_INDEX_UNIQUE, ['companyuuid', 'uuid', 'revision']);
            $table->add_index('code_ix', XMLDB_INDEX_NOTUNIQUE, ['companyuuid', 'code', 'status']);
            $table->add_index('status_ix', XMLDB_INDEX_NOTUNIQUE, ['companyuuid', 'status', 'doctype']);
            $table->add_index('source_ix', XMLDB_INDEX_NOTUNIQUE, ['companyuuid', 'sourcekind', 'sourceuuid']);
            $dbman->create_table($table);
        }

        $table = new xmldb_table('local_kbacompany_docreview');
        if (!$dbman->table_exists($table)) {
            $table->add_field('id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE, null);
            $table->add_field('companyuuid', XMLDB_TYPE_CHAR, '36', null, XMLDB_NOTNULL, null, null);
            $table->add_field('documentuuid', XMLDB_TYPE_CHAR, '36', null, XMLDB_NOTNULL, null, null);
            $table->add_field('docrevision', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '1');
            $table->add_field('reviewround', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '1');
            $table->add_field('stage', XMLDB_TYPE_CHAR, '12', null, XMLDB_NOTNULL, null, 'review');
            $table->add_field('reviewerid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $table->add_field('decision', XMLDB_TYPE_CHAR, '12', null, XMLDB_NOTNULL, null, 'pending');
            $table->add_field('comment', XMLDB_TYPE_TEXT, null, null, null, null, null);
            $table->add_field('timecreated', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $table->add_field('timedecided', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $table->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);
            $table->add_index('reviewer_uix', XMLDB_INDEX_UNIQUE,
                ['companyuuid', 'documentuuid', 'docrevision', 'reviewround', 'stage', 'reviewerid']);
            $table->add_index('document_ix', XMLDB_INDEX_NOTUNIQUE,
                ['companyuuid', 'documentuuid', 'docrevision', 'reviewround']);
            $table->add_index('reviewer_ix', XMLDB_INDEX_NOTUNIQUE, ['reviewerid', 'decision']);
            $dbman->create_table($table);
        }

        $table = new xmldb_table('local_kbacompany_docack');
        if (!$dbman->table_exists($table)) {
            $table->add_field('id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE, null);
            $table->add_field('companyuuid', XMLDB_TYPE_CHAR, '36', null, XMLDB_NOTNULL, null, null);
            $table->add_field('documentuuid', XMLDB_TYPE_CHAR, '36', null, XMLDB_NOTNULL, null, null);
            $table->add_field('docrevision', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '1');
            $table->add_field('userid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $table->add_field('status', XMLDB_TYPE_CHAR, '16', null, XMLDB_NOTNULL, null, 'required');
            $table->add_field('requiredby', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $table->add_field('timecreated', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $table->add_field('timeacknowledged', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $table->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);
            $table->add_index('user_uix', XMLDB_INDEX_UNIQUE,
                ['companyuuid', 'documentuuid', 'docrevision', 'userid']);
            $table->add_index('status_ix', XMLDB_INDEX_NOTUNIQUE, ['companyuuid', 'status', 'userid']);
            $dbman->create_table($table);
        }

        $table = new xmldb_table('local_kbacompany_record');
        if (!$dbman->table_exists($table)) {
            $table->add_field('id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE, null);
            $table->add_field('companyuuid', XMLDB_TYPE_CHAR, '36', null, XMLDB_NOTNULL, null, null);
            $table->add_field('uuid', XMLDB_TYPE_CHAR, '36', null, XMLDB_NOTNULL, null, null);
            $table->add_field('code', XMLDB_TYPE_CHAR, '80', null, XMLDB_NOTNULL, null, null);
            $table->add_field('title', XMLDB_TYPE_CHAR, '255', null, XMLDB_NOTNULL, null, null);
            $table->add_field('recordtype', XMLDB_TYPE_CHAR, '24', null, XMLDB_NOTNULL, null, 'form_record');
            $table->add_field('sourceformuuid', XMLDB_TYPE_CHAR, '36', null, XMLDB_NOTNULL, null, null);
            $table->add_field('sourceformrevision', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '1');
            $table->add_field('subjecttype', XMLDB_TYPE_CHAR, '24', null, XMLDB_NOTNULL, null, '');
            $table->add_field('subjectuuid', XMLDB_TYPE_CHAR, '36', null, XMLDB_NOTNULL, null, '');
            $table->add_field('payloadhash', XMLDB_TYPE_CHAR, '64', null, XMLDB_NOTNULL, null, null);
            $table->add_field('payload', XMLDB_TYPE_TEXT, null, null, XMLDB_NOTNULL, null, null);
            $table->add_field('createdby', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $table->add_field('timecreated', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $table->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);
            $table->add_index('uuid_uix', XMLDB_INDEX_UNIQUE, ['companyuuid', 'uuid']);
            $table->add_index('code_ix', XMLDB_INDEX_UNIQUE, ['companyuuid', 'code']);
            $table->add_index('form_ix', XMLDB_INDEX_NOTUNIQUE,
                ['companyuuid', 'sourceformuuid', 'sourceformrevision']);
            $table->add_index('author_ix', XMLDB_INDEX_NOTUNIQUE, ['companyuuid', 'createdby', 'timecreated']);
            $dbman->create_table($table);
        }

        upgrade_plugin_savepoint(true, 2026091228, 'local', 'kbacompany');
    }

    if ($oldversion < 2026091229) {
        // v0.3.4 adds versioned Position Work Program items. No existing business data is modified.
        $table = new xmldb_table('local_kbacompany_poswork');
        if (!$dbman->table_exists($table)) {
            $table->add_field('id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE, null);
            $table->add_field('companyuuid', XMLDB_TYPE_CHAR, '36', null, XMLDB_NOTNULL, null, null);
            $table->add_field('uuid', XMLDB_TYPE_CHAR, '36', null, XMLDB_NOTNULL, null, null);
            $table->add_field('revision', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '1');
            $table->add_field('status', XMLDB_TYPE_CHAR, '12', null, XMLDB_NOTNULL, null, 'draft');
            $table->add_field('positionuuid', XMLDB_TYPE_CHAR, '36', null, XMLDB_NOTNULL, null, null);
            $table->add_field('functionuuid', XMLDB_TYPE_CHAR, '36', null, XMLDB_NOTNULL, null, null);
            $table->add_field('functionrevision', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '1');
            $table->add_field('action', XMLDB_TYPE_TEXT, null, null, XMLDB_NOTNULL, null, null);
            $table->add_field('product', XMLDB_TYPE_TEXT, null, null, XMLDB_NOTNULL, null, null);
            $table->add_field('frequency', XMLDB_TYPE_CHAR, '16', null, XMLDB_NOTNULL, null, null);
            $table->add_field('schedule', XMLDB_TYPE_CHAR, '255', null, XMLDB_NOTNULL, null, '');
            $table->add_field('sortorder', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $table->add_field('changereason', XMLDB_TYPE_TEXT, null, null, null, null, null);
            $table->add_field('createdby', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $table->add_field('modifiedby', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $table->add_field('timecreated', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $table->add_field('timemodified', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $table->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);
            $table->add_index('revision_uix', XMLDB_INDEX_UNIQUE, ['companyuuid', 'uuid', 'revision']);
            $table->add_index('position_ix', XMLDB_INDEX_NOTUNIQUE, ['companyuuid', 'positionuuid', 'status']);
            $table->add_index('function_ix', XMLDB_INDEX_NOTUNIQUE, ['companyuuid', 'functionuuid', 'status']);
            $dbman->create_table($table);
        }
        upgrade_plugin_savepoint(true, 2026091229, 'local', 'kbacompany');
    }

    if ($oldversion < 2026091230) {
        // v0.3.5 adds explicit market, customer-segment, offering and business-model design objects.
        $specs = [
            'local_kbacompany_market' => [
                ['code', XMLDB_TYPE_CHAR, '50', true, null], ['name', XMLDB_TYPE_CHAR, '255', true, null],
                ['geography', XMLDB_TYPE_CHAR, '255', true, ''], ['description', XMLDB_TYPE_TEXT, null, false, null]],
            'local_kbacompany_segment' => [
                ['marketuuid', XMLDB_TYPE_CHAR, '36', true, null], ['code', XMLDB_TYPE_CHAR, '50', true, null],
                ['name', XMLDB_TYPE_CHAR, '255', true, null], ['needs', XMLDB_TYPE_TEXT, null, false, null],
                ['problems', XMLDB_TYPE_TEXT, null, false, null], ['profile', XMLDB_TYPE_TEXT, null, false, null]],
            'local_kbacompany_offering' => [
                ['code', XMLDB_TYPE_CHAR, '50', true, null], ['name', XMLDB_TYPE_CHAR, '255', true, null],
                ['offertype', XMLDB_TYPE_CHAR, '12', true, null], ['segmentuuid', XMLDB_TYPE_CHAR, '36', true, ''],
                ['valueproposition', XMLDB_TYPE_TEXT, null, true, null], ['productuuid', XMLDB_TYPE_CHAR, '36', true, ''],
                ['coreprocessuuid', XMLDB_TYPE_CHAR, '36', true, '']],
            'local_kbacompany_bizmodel' => [
                ['name', XMLDB_TYPE_CHAR, '255', true, null], ['valuecreation', XMLDB_TYPE_TEXT, null, true, null],
                ['channels', XMLDB_TYPE_TEXT, null, false, null], ['relationships', XMLDB_TYPE_TEXT, null, false, null],
                ['revenue_logic', XMLDB_TYPE_TEXT, null, false, null], ['cost_logic', XMLDB_TYPE_TEXT, null, false, null],
                ['keyresources', XMLDB_TYPE_TEXT, null, false, null], ['keypartners', XMLDB_TYPE_TEXT, null, false, null]],
        ];
        foreach ($specs as $tablename => $fields) {
            $table = new xmldb_table($tablename);
            if (!$dbman->table_exists($table)) {
                $table->add_field('id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE, null);
                $table->add_field('companyuuid', XMLDB_TYPE_CHAR, '36', null, XMLDB_NOTNULL, null, null);
                $table->add_field('uuid', XMLDB_TYPE_CHAR, '36', null, XMLDB_NOTNULL, null, null);
                $table->add_field('revision', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '1');
                $table->add_field('status', XMLDB_TYPE_CHAR, '12', null, XMLDB_NOTNULL, null, 'draft');
                foreach ($fields as [$name, $type, $length, $notnull, $default]) {
                    $table->add_field($name, $type, $length, null, $notnull ? XMLDB_NOTNULL : null, null, $default);
                }
                $table->add_field('createdby', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
                $table->add_field('modifiedby', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
                $table->add_field('timecreated', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
                $table->add_field('timemodified', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
                $table->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);
                $table->add_index('revision_uix', XMLDB_INDEX_UNIQUE, ['companyuuid', 'uuid', 'revision']);
                if ($tablename === 'local_kbacompany_segment') $table->add_index('market_ix', XMLDB_INDEX_NOTUNIQUE, ['companyuuid','marketuuid','status']);
                if ($tablename === 'local_kbacompany_offering') {
                    $table->add_index('segment_ix', XMLDB_INDEX_NOTUNIQUE, ['companyuuid','segmentuuid','status']);
                    $table->add_index('process_ix', XMLDB_INDEX_NOTUNIQUE, ['companyuuid','coreprocessuuid','status']);
                }
                if ($tablename === 'local_kbacompany_market') $table->add_index('code_ix', XMLDB_INDEX_NOTUNIQUE, ['companyuuid','code','status']);
                if ($tablename === 'local_kbacompany_bizmodel') $table->add_index('status_ix', XMLDB_INDEX_NOTUNIQUE, ['companyuuid','status']);
                $dbman->create_table($table);
            }
        }
        upgrade_plugin_savepoint(true, 2026091230, 'local', 'kbacompany');
    }


    if ($oldversion < 2026091231) {
        // v0.4.0 introduces Ownership Intent, Strategy, BSC goals and causal strategic map links.
        $table = new xmldb_table('local_kbacompany_ownerintent');
        if (!$dbman->table_exists($table)) {
            $table->add_field('id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE, null);
            $table->add_field('companyuuid', XMLDB_TYPE_CHAR, '36', null, XMLDB_NOTNULL, null, null);
            $table->add_field('uuid', XMLDB_TYPE_CHAR, '36', null, XMLDB_NOTNULL, null, null);
            $table->add_field('revision', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '1');
            $table->add_field('status', XMLDB_TYPE_CHAR, '12', null, XMLDB_NOTNULL, null, 'draft');
            $table->add_field('code', XMLDB_TYPE_CHAR, '50', null, XMLDB_NOTNULL, null, null);
            $table->add_field('name', XMLDB_TYPE_CHAR, '255', null, XMLDB_NOTNULL, null, null);
            $table->add_field('ownershippurpose', XMLDB_TYPE_TEXT, null, null, XMLDB_NOTNULL, null, null);
            $table->add_field('expectations', XMLDB_TYPE_TEXT, null, null, null, null, null);
            $table->add_field('constraints', XMLDB_TYPE_TEXT, null, null, null, null, null);
            $table->add_field('horizon', XMLDB_TYPE_CHAR, '100', null, XMLDB_NOTNULL, null, '');
            $table->add_field('longtermgoal', XMLDB_TYPE_TEXT, null, null, XMLDB_NOTNULL, null, null);
            $table->add_field('createdby', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $table->add_field('modifiedby', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $table->add_field('timecreated', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $table->add_field('timemodified', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $table->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);
            $table->add_index('revision_uix', XMLDB_INDEX_UNIQUE, ['companyuuid', 'uuid', 'revision']);
            $table->add_index('code_ix', XMLDB_INDEX_NOTUNIQUE, ['companyuuid', 'code', 'status']);
            $dbman->create_table($table);
        }

        $table = new xmldb_table('local_kbacompany_strategy');
        if (!$dbman->table_exists($table)) {
            $table->add_field('id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE, null);
            $table->add_field('companyuuid', XMLDB_TYPE_CHAR, '36', null, XMLDB_NOTNULL, null, null);
            $table->add_field('uuid', XMLDB_TYPE_CHAR, '36', null, XMLDB_NOTNULL, null, null);
            $table->add_field('revision', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '1');
            $table->add_field('status', XMLDB_TYPE_CHAR, '12', null, XMLDB_NOTNULL, null, 'draft');
            $table->add_field('ownerintentuuid', XMLDB_TYPE_CHAR, '36', null, XMLDB_NOTNULL, null, null);
            $table->add_field('ownerintentrevision', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $table->add_field('code', XMLDB_TYPE_CHAR, '50', null, XMLDB_NOTNULL, null, null);
            $table->add_field('name', XMLDB_TYPE_CHAR, '255', null, XMLDB_NOTNULL, null, null);
            $table->add_field('thesis', XMLDB_TYPE_TEXT, null, null, XMLDB_NOTNULL, null, null);
            $table->add_field('positioning', XMLDB_TYPE_TEXT, null, null, null, null, null);
            $table->add_field('horizonstart', XMLDB_TYPE_CHAR, '10', null, XMLDB_NOTNULL, null, '');
            $table->add_field('horizonend', XMLDB_TYPE_CHAR, '10', null, XMLDB_NOTNULL, null, '');
            $table->add_field('businessmodeluuid', XMLDB_TYPE_CHAR, '36', null, XMLDB_NOTNULL, null, '');
            $table->add_field('businessmodelrevision', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $table->add_field('createdby', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $table->add_field('modifiedby', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $table->add_field('timecreated', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $table->add_field('timemodified', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $table->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);
            $table->add_index('revision_uix', XMLDB_INDEX_UNIQUE, ['companyuuid', 'uuid', 'revision']);
            $table->add_index('code_ix', XMLDB_INDEX_NOTUNIQUE, ['companyuuid', 'code', 'status']);
            $table->add_index('intent_ix', XMLDB_INDEX_NOTUNIQUE, ['companyuuid', 'ownerintentuuid', 'status']);
            $table->add_index('bizmodel_ix', XMLDB_INDEX_NOTUNIQUE, ['companyuuid', 'businessmodeluuid', 'status']);
            $dbman->create_table($table);
        }

        $table = new xmldb_table('local_kbacompany_stratgoal');
        if (!$dbman->table_exists($table)) {
            $table->add_field('id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE, null);
            $table->add_field('companyuuid', XMLDB_TYPE_CHAR, '36', null, XMLDB_NOTNULL, null, null);
            $table->add_field('uuid', XMLDB_TYPE_CHAR, '36', null, XMLDB_NOTNULL, null, null);
            $table->add_field('revision', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '1');
            $table->add_field('status', XMLDB_TYPE_CHAR, '12', null, XMLDB_NOTNULL, null, 'draft');
            $table->add_field('strategyuuid', XMLDB_TYPE_CHAR, '36', null, XMLDB_NOTNULL, null, null);
            $table->add_field('strategyrevision', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $table->add_field('code', XMLDB_TYPE_CHAR, '50', null, XMLDB_NOTNULL, null, null);
            $table->add_field('name', XMLDB_TYPE_CHAR, '255', null, XMLDB_NOTNULL, null, null);
            $table->add_field('perspective', XMLDB_TYPE_CHAR, '16', null, XMLDB_NOTNULL, null, null);
            $table->add_field('parentuuid', XMLDB_TYPE_CHAR, '36', null, XMLDB_NOTNULL, null, '');
            $table->add_field('parentrevision', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $table->add_field('outcome', XMLDB_TYPE_TEXT, null, null, XMLDB_NOTNULL, null, null);
            $table->add_field('metricuuid', XMLDB_TYPE_CHAR, '36', null, XMLDB_NOTNULL, null, '');
            $table->add_field('targetvalue', XMLDB_TYPE_CHAR, '32', null, XMLDB_NOTNULL, null, '');
            $table->add_field('targetdate', XMLDB_TYPE_CHAR, '10', null, XMLDB_NOTNULL, null, '');
            $table->add_field('processuuid', XMLDB_TYPE_CHAR, '36', null, XMLDB_NOTNULL, null, '');
            $table->add_field('processrevision', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $table->add_field('ownerpositionuuid', XMLDB_TYPE_CHAR, '36', null, XMLDB_NOTNULL, null, '');
            $table->add_field('weight', XMLDB_TYPE_CHAR, '12', null, XMLDB_NOTNULL, null, '');
            $table->add_field('sortorder', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $table->add_field('createdby', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $table->add_field('modifiedby', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $table->add_field('timecreated', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $table->add_field('timemodified', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $table->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);
            $table->add_index('revision_uix', XMLDB_INDEX_UNIQUE, ['companyuuid', 'uuid', 'revision']);
            $table->add_index('code_ix', XMLDB_INDEX_NOTUNIQUE, ['companyuuid', 'code', 'status']);
            $table->add_index('strategy_ix', XMLDB_INDEX_NOTUNIQUE, ['companyuuid', 'strategyuuid', 'status']);
            $table->add_index('perspective_ix', XMLDB_INDEX_NOTUNIQUE, ['companyuuid', 'strategyuuid', 'perspective', 'status']);
            $table->add_index('metric_ix', XMLDB_INDEX_NOTUNIQUE, ['companyuuid', 'metricuuid', 'status']);
            $table->add_index('process_ix', XMLDB_INDEX_NOTUNIQUE, ['companyuuid', 'processuuid', 'status']);
            $dbman->create_table($table);
        }

        $table = new xmldb_table('local_kbacompany_goallink');
        if (!$dbman->table_exists($table)) {
            $table->add_field('id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE, null);
            $table->add_field('companyuuid', XMLDB_TYPE_CHAR, '36', null, XMLDB_NOTNULL, null, null);
            $table->add_field('uuid', XMLDB_TYPE_CHAR, '36', null, XMLDB_NOTNULL, null, null);
            $table->add_field('revision', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '1');
            $table->add_field('status', XMLDB_TYPE_CHAR, '12', null, XMLDB_NOTNULL, null, 'draft');
            $table->add_field('strategyuuid', XMLDB_TYPE_CHAR, '36', null, XMLDB_NOTNULL, null, null);
            $table->add_field('strategyrevision', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $table->add_field('fromgoaluuid', XMLDB_TYPE_CHAR, '36', null, XMLDB_NOTNULL, null, null);
            $table->add_field('fromgoalrevision', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $table->add_field('togoaluuid', XMLDB_TYPE_CHAR, '36', null, XMLDB_NOTNULL, null, null);
            $table->add_field('togoalrevision', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $table->add_field('relation', XMLDB_TYPE_CHAR, '16', null, XMLDB_NOTNULL, null, 'drives');
            $table->add_field('description', XMLDB_TYPE_TEXT, null, null, null, null, null);
            $table->add_field('createdby', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $table->add_field('modifiedby', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $table->add_field('timecreated', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $table->add_field('timemodified', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $table->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);
            $table->add_index('revision_uix', XMLDB_INDEX_UNIQUE, ['companyuuid', 'uuid', 'revision']);
            $table->add_index('strategy_ix', XMLDB_INDEX_NOTUNIQUE, ['companyuuid', 'strategyuuid', 'status']);
            $table->add_index('from_ix', XMLDB_INDEX_NOTUNIQUE, ['companyuuid', 'fromgoaluuid', 'status']);
            $table->add_index('to_ix', XMLDB_INDEX_NOTUNIQUE, ['companyuuid', 'togoaluuid', 'status']);
            $dbman->create_table($table);
        }
        upgrade_plugin_savepoint(true, 2026091231, 'local', 'kbacompany');
    }


    if ($oldversion < 2026091232) {
        // v0.4.1 introduces the versioned Administrative Scale without migrating the legacy cascade.
        $table = new xmldb_table('local_kbacompany_adminitem');
        if (!$dbman->table_exists($table)) {
            $table->add_field('id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE, null);
            $table->add_field('companyuuid', XMLDB_TYPE_CHAR, '36', null, XMLDB_NOTNULL, null, null);
            $table->add_field('uuid', XMLDB_TYPE_CHAR, '36', null, XMLDB_NOTNULL, null, null);
            $table->add_field('revision', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '1');
            $table->add_field('status', XMLDB_TYPE_CHAR, '12', null, XMLDB_NOTNULL, null, 'draft');
            $table->add_field('kind', XMLDB_TYPE_CHAR, '20', null, XMLDB_NOTNULL, null, null);
            $table->add_field('code', XMLDB_TYPE_CHAR, '50', null, XMLDB_NOTNULL, null, null);
            $table->add_field('name', XMLDB_TYPE_CHAR, '255', null, XMLDB_NOTNULL, null, null);
            $table->add_field('description', XMLDB_TYPE_TEXT, null, null, null, null, null);
            $table->add_field('expectedresult', XMLDB_TYPE_TEXT, null, null, null, null, null);
            $table->add_field('parentuuid', XMLDB_TYPE_CHAR, '36', null, XMLDB_NOTNULL, null, '');
            $table->add_field('parentrevision', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $table->add_field('sourcekind', XMLDB_TYPE_CHAR, '20', null, XMLDB_NOTNULL, null, '');
            $table->add_field('sourceuuid', XMLDB_TYPE_CHAR, '36', null, XMLDB_NOTNULL, null, '');
            $table->add_field('sourcerevision', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $table->add_field('ownerpositionuuid', XMLDB_TYPE_CHAR, '36', null, XMLDB_NOTNULL, null, '');
            $table->add_field('productuuid', XMLDB_TYPE_CHAR, '36', null, XMLDB_NOTNULL, null, '');
            $table->add_field('metricuuid', XMLDB_TYPE_CHAR, '36', null, XMLDB_NOTNULL, null, '');
            $table->add_field('targetvalue', XMLDB_TYPE_CHAR, '64', null, XMLDB_NOTNULL, null, '');
            $table->add_field('startdate', XMLDB_TYPE_CHAR, '10', null, XMLDB_NOTNULL, null, '');
            $table->add_field('duedate', XMLDB_TYPE_CHAR, '10', null, XMLDB_NOTNULL, null, '');
            $table->add_field('sortorder', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $table->add_field('createdby', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $table->add_field('modifiedby', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $table->add_field('timecreated', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $table->add_field('timemodified', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $table->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);
            $table->add_index('revision_uix', XMLDB_INDEX_UNIQUE, ['companyuuid', 'uuid', 'revision']);
            $table->add_index('code_ix', XMLDB_INDEX_NOTUNIQUE, ['companyuuid', 'code']);
            $table->add_index('kind_ix', XMLDB_INDEX_NOTUNIQUE, ['companyuuid', 'kind', 'status']);
            $table->add_index('parent_ix', XMLDB_INDEX_NOTUNIQUE, ['companyuuid', 'parentuuid', 'status']);
            $table->add_index('source_ix', XMLDB_INDEX_NOTUNIQUE, ['companyuuid', 'sourcekind', 'sourceuuid', 'status']);
            $table->add_index('owner_ix', XMLDB_INDEX_NOTUNIQUE, ['companyuuid', 'ownerpositionuuid', 'status']);
            $table->add_index('product_ix', XMLDB_INDEX_NOTUNIQUE, ['companyuuid', 'productuuid', 'status']);
            $table->add_index('metric_ix', XMLDB_INDEX_NOTUNIQUE, ['companyuuid', 'metricuuid', 'status']);
            $dbman->create_table($table);
        }
        upgrade_plugin_savepoint(true, 2026091232, 'local', 'kbacompany');
    }


    if ($oldversion < 2026091233) {
        // v0.4.2 introduces a standalone Task Center and weekly Battle Plan projection.
        // Existing legacy workflow tables remain untouched; no task is migrated implicitly.
        $table = new xmldb_table('local_kbacompany_task');
        if (!$dbman->table_exists($table)) {
            $table->add_field('id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE, null);
            $table->add_field('companyuuid', XMLDB_TYPE_CHAR, '36', null, XMLDB_NOTNULL, null, null);
            $table->add_field('uuid', XMLDB_TYPE_CHAR, '36', null, XMLDB_NOTNULL, null, null);
            $table->add_field('parentuuid', XMLDB_TYPE_CHAR, '36', null, XMLDB_NOTNULL, null, '');
            $table->add_field('sourcekind', XMLDB_TYPE_CHAR, '20', null, XMLDB_NOTNULL, null, 'manual');
            $table->add_field('sourceuuid', XMLDB_TYPE_CHAR, '36', null, XMLDB_NOTNULL, null, '');
            $table->add_field('sourcerevision', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $table->add_field('periodkey', XMLDB_TYPE_CHAR, '10', null, XMLDB_NOTNULL, null, '');
            $table->add_field('stream', XMLDB_TYPE_CHAR, '16', null, XMLDB_NOTNULL, null, 'operate');
            $table->add_field('title', XMLDB_TYPE_CHAR, '255', null, XMLDB_NOTNULL, null, null);
            $table->add_field('description', XMLDB_TYPE_TEXT, null, null, null, null, null);
            $table->add_field('product', XMLDB_TYPE_TEXT, null, null, XMLDB_NOTNULL, null, null);
            $table->add_field('assigneeid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $table->add_field('creatorid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $table->add_field('reviewerid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $table->add_field('priority', XMLDB_TYPE_CHAR, '10', null, XMLDB_NOTNULL, null, 'normal');
            $table->add_field('status', XMLDB_TYPE_CHAR, '16', null, XMLDB_NOTNULL, null, 'open');
            $table->add_field('startdate', XMLDB_TYPE_CHAR, '10', null, XMLDB_NOTNULL, null, '');
            $table->add_field('duedate', XMLDB_TYPE_CHAR, '10', null, XMLDB_NOTNULL, null, '');
            $table->add_field('planminutes', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $table->add_field('factminutes', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $table->add_field('result', XMLDB_TYPE_TEXT, null, null, null, null, null);
            $table->add_field('revision', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '1');
            $table->add_field('startedat', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $table->add_field('completedat', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $table->add_field('timecreated', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $table->add_field('timemodified', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $table->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);
            $table->add_index('uuid_uix', XMLDB_INDEX_UNIQUE, ['uuid']);
            $table->add_index('assignee_ix', XMLDB_INDEX_NOTUNIQUE, ['companyuuid', 'assigneeid', 'status']);
            $table->add_index('creator_ix', XMLDB_INDEX_NOTUNIQUE, ['companyuuid', 'creatorid', 'status']);
            $table->add_index('reviewer_ix', XMLDB_INDEX_NOTUNIQUE, ['companyuuid', 'reviewerid', 'status']);
            $table->add_index('source_ix', XMLDB_INDEX_NOTUNIQUE, ['companyuuid', 'sourcekind', 'sourceuuid', 'sourcerevision']);
            $table->add_index('due_ix', XMLDB_INDEX_NOTUNIQUE, ['companyuuid', 'duedate', 'status']);
            $table->add_index('parent_ix', XMLDB_INDEX_NOTUNIQUE, ['companyuuid', 'parentuuid']);
            $dbman->create_table($table);
        }

        $table = new xmldb_table('local_kbacompany_taskcheck');
        if (!$dbman->table_exists($table)) {
            $table->add_field('id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE, null);
            $table->add_field('companyuuid', XMLDB_TYPE_CHAR, '36', null, XMLDB_NOTNULL, null, null);
            $table->add_field('taskuuid', XMLDB_TYPE_CHAR, '36', null, XMLDB_NOTNULL, null, null);
            $table->add_field('uuid', XMLDB_TYPE_CHAR, '36', null, XMLDB_NOTNULL, null, null);
            $table->add_field('itemtext', XMLDB_TYPE_TEXT, null, null, XMLDB_NOTNULL, null, null);
            $table->add_field('assigneeid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $table->add_field('done', XMLDB_TYPE_INTEGER, '1', null, XMLDB_NOTNULL, null, '0');
            $table->add_field('sortorder', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $table->add_field('timecreated', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $table->add_field('timemodified', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $table->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);
            $table->add_index('uuid_uix', XMLDB_INDEX_UNIQUE, ['uuid']);
            $table->add_index('task_ix', XMLDB_INDEX_NOTUNIQUE, ['companyuuid', 'taskuuid', 'done']);
            $dbman->create_table($table);
        }

        $table = new xmldb_table('local_kbacompany_tasklog');
        if (!$dbman->table_exists($table)) {
            $table->add_field('id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE, null);
            $table->add_field('companyuuid', XMLDB_TYPE_CHAR, '36', null, XMLDB_NOTNULL, null, null);
            $table->add_field('taskuuid', XMLDB_TYPE_CHAR, '36', null, XMLDB_NOTNULL, null, null);
            $table->add_field('actorid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $table->add_field('action', XMLDB_TYPE_CHAR, '30', null, XMLDB_NOTNULL, null, null);
            $table->add_field('oldstatus', XMLDB_TYPE_CHAR, '16', null, XMLDB_NOTNULL, null, '');
            $table->add_field('newstatus', XMLDB_TYPE_CHAR, '16', null, XMLDB_NOTNULL, null, '');
            $table->add_field('details', XMLDB_TYPE_TEXT, null, null, null, null, null);
            $table->add_field('timecreated', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $table->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);
            $table->add_index('task_ix', XMLDB_INDEX_NOTUNIQUE, ['companyuuid', 'taskuuid', 'timecreated']);
            $table->add_index('actor_ix', XMLDB_INDEX_NOTUNIQUE, ['actorid', 'timecreated']);
            $dbman->create_table($table);
        }

        $table = new xmldb_table('local_kbacompany_bplan');
        if (!$dbman->table_exists($table)) {
            $table->add_field('id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE, null);
            $table->add_field('companyuuid', XMLDB_TYPE_CHAR, '36', null, XMLDB_NOTNULL, null, null);
            $table->add_field('uuid', XMLDB_TYPE_CHAR, '36', null, XMLDB_NOTNULL, null, null);
            $table->add_field('userid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $table->add_field('positionuuid', XMLDB_TYPE_CHAR, '36', null, XMLDB_NOTNULL, null, null);
            $table->add_field('periodstart', XMLDB_TYPE_CHAR, '10', null, XMLDB_NOTNULL, null, null);
            $table->add_field('periodend', XMLDB_TYPE_CHAR, '10', null, XMLDB_NOTNULL, null, null);
            $table->add_field('status', XMLDB_TYPE_CHAR, '16', null, XMLDB_NOTNULL, null, 'draft');
            $table->add_field('reviewerid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $table->add_field('createdby', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $table->add_field('approvedby', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $table->add_field('submittedat', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $table->add_field('approvedat', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $table->add_field('closedat', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $table->add_field('timecreated', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $table->add_field('timemodified', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $table->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);
            $table->add_index('uuid_uix', XMLDB_INDEX_UNIQUE, ['uuid']);
            $table->add_index('period_uix', XMLDB_INDEX_UNIQUE, ['companyuuid', 'userid', 'positionuuid', 'periodstart']);
            $table->add_index('reviewer_ix', XMLDB_INDEX_NOTUNIQUE, ['companyuuid', 'reviewerid', 'status']);
            $dbman->create_table($table);
        }

        $table = new xmldb_table('local_kbacompany_bplanitem');
        if (!$dbman->table_exists($table)) {
            $table->add_field('id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE, null);
            $table->add_field('companyuuid', XMLDB_TYPE_CHAR, '36', null, XMLDB_NOTNULL, null, null);
            $table->add_field('uuid', XMLDB_TYPE_CHAR, '36', null, XMLDB_NOTNULL, null, null);
            $table->add_field('planuuid', XMLDB_TYPE_CHAR, '36', null, XMLDB_NOTNULL, null, null);
            $table->add_field('taskuuid', XMLDB_TYPE_CHAR, '36', null, XMLDB_NOTNULL, null, null);
            $table->add_field('stream', XMLDB_TYPE_CHAR, '16', null, XMLDB_NOTNULL, null, null);
            $table->add_field('sortorder', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $table->add_field('timecreated', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $table->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);
            $table->add_index('uuid_uix', XMLDB_INDEX_UNIQUE, ['uuid']);
            $table->add_index('task_uix', XMLDB_INDEX_UNIQUE, ['companyuuid', 'planuuid', 'taskuuid']);
            $table->add_index('stream_ix', XMLDB_INDEX_NOTUNIQUE, ['companyuuid', 'planuuid', 'stream']);
            $dbman->create_table($table);
        }

        upgrade_plugin_savepoint(true, 2026091233, 'local', 'kbacompany');
    }


    if ($oldversion < 2026091337) {
        // v0.4.2.1 introduces participant-scoped Communication Core.
        // Communication content is private to explicit participants; management roles do not imply content access.
        $table = new xmldb_table('local_kbacompany_commthread');
        if (!$dbman->table_exists($table)) {
            $table->add_field('id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE, null);
            $table->add_field('companyuuid', XMLDB_TYPE_CHAR, '36', null, XMLDB_NOTNULL, null, null);
            $table->add_field('uuid', XMLDB_TYPE_CHAR, '36', null, XMLDB_NOTNULL, null, null);
            $table->add_field('threadtype', XMLDB_TYPE_CHAR, '16', null, XMLDB_NOTNULL, null, 'direct');
            $table->add_field('title', XMLDB_TYPE_CHAR, '255', null, XMLDB_NOTNULL, null, '');
            $table->add_field('contextkind', XMLDB_TYPE_CHAR, '32', null, XMLDB_NOTNULL, null, '');
            $table->add_field('contextuuid', XMLDB_TYPE_CHAR, '100', null, XMLDB_NOTNULL, null, '');
            $table->add_field('contextrevision', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $table->add_field('createdby', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $table->add_field('status', XMLDB_TYPE_CHAR, '16', null, XMLDB_NOTNULL, null, 'active');
            $table->add_field('lastmessageat', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $table->add_field('timecreated', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $table->add_field('timemodified', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $table->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);
            $table->add_index('uuid_uix', XMLDB_INDEX_UNIQUE, ['uuid']);
            $table->add_index('context_ix', XMLDB_INDEX_NOTUNIQUE, ['companyuuid', 'contextkind', 'contextuuid', 'status']);
            $table->add_index('updated_ix', XMLDB_INDEX_NOTUNIQUE, ['companyuuid', 'lastmessageat']);
            $dbman->create_table($table);
        }

        $table = new xmldb_table('local_kbacompany_commpart');
        if (!$dbman->table_exists($table)) {
            $table->add_field('id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE, null);
            $table->add_field('companyuuid', XMLDB_TYPE_CHAR, '36', null, XMLDB_NOTNULL, null, null);
            $table->add_field('threaduuid', XMLDB_TYPE_CHAR, '36', null, XMLDB_NOTNULL, null, null);
            $table->add_field('userid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $table->add_field('role', XMLDB_TYPE_CHAR, '16', null, XMLDB_NOTNULL, null, 'member');
            $table->add_field('active', XMLDB_TYPE_INTEGER, '1', null, XMLDB_NOTNULL, null, '1');
            $table->add_field('muted', XMLDB_TYPE_INTEGER, '1', null, XMLDB_NOTNULL, null, '0');
            $table->add_field('joinedat', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $table->add_field('leftat', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $table->add_field('timecreated', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $table->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);
            $table->add_index('participant_uix', XMLDB_INDEX_UNIQUE, ['companyuuid', 'threaduuid', 'userid']);
            $table->add_index('user_ix', XMLDB_INDEX_NOTUNIQUE, ['companyuuid', 'userid', 'active']);
            $dbman->create_table($table);
        }

        $table = new xmldb_table('local_kbacompany_commmsg');
        if (!$dbman->table_exists($table)) {
            $table->add_field('id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE, null);
            $table->add_field('companyuuid', XMLDB_TYPE_CHAR, '36', null, XMLDB_NOTNULL, null, null);
            $table->add_field('uuid', XMLDB_TYPE_CHAR, '36', null, XMLDB_NOTNULL, null, null);
            $table->add_field('threaduuid', XMLDB_TYPE_CHAR, '36', null, XMLDB_NOTNULL, null, null);
            $table->add_field('authorid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $table->add_field('body', XMLDB_TYPE_TEXT, null, null, XMLDB_NOTNULL, null, null);
            $table->add_field('replytouuid', XMLDB_TYPE_CHAR, '36', null, XMLDB_NOTNULL, null, '');
            $table->add_field('taskuuid', XMLDB_TYPE_CHAR, '36', null, XMLDB_NOTNULL, null, '');
            $table->add_field('status', XMLDB_TYPE_CHAR, '16', null, XMLDB_NOTNULL, null, 'active');
            $table->add_field('timecreated', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $table->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);
            $table->add_index('uuid_uix', XMLDB_INDEX_UNIQUE, ['uuid']);
            $table->add_index('thread_ix', XMLDB_INDEX_NOTUNIQUE, ['companyuuid', 'threaduuid', 'timecreated']);
            $table->add_index('author_ix', XMLDB_INDEX_NOTUNIQUE, ['companyuuid', 'authorid', 'timecreated']);
            $table->add_index('task_ix', XMLDB_INDEX_NOTUNIQUE, ['companyuuid', 'taskuuid']);
            $dbman->create_table($table);
        }

        $table = new xmldb_table('local_kbacompany_commread');
        if (!$dbman->table_exists($table)) {
            $table->add_field('id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE, null);
            $table->add_field('companyuuid', XMLDB_TYPE_CHAR, '36', null, XMLDB_NOTNULL, null, null);
            $table->add_field('threaduuid', XMLDB_TYPE_CHAR, '36', null, XMLDB_NOTNULL, null, null);
            $table->add_field('userid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $table->add_field('lastmessageid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $table->add_field('timeread', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $table->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);
            $table->add_index('read_uix', XMLDB_INDEX_UNIQUE, ['companyuuid', 'threaduuid', 'userid']);
            $dbman->create_table($table);
        }

        $table = new xmldb_table('local_kbacompany_commmention');
        if (!$dbman->table_exists($table)) {
            $table->add_field('id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE, null);
            $table->add_field('companyuuid', XMLDB_TYPE_CHAR, '36', null, XMLDB_NOTNULL, null, null);
            $table->add_field('threaduuid', XMLDB_TYPE_CHAR, '36', null, XMLDB_NOTNULL, null, null);
            $table->add_field('messageuuid', XMLDB_TYPE_CHAR, '36', null, XMLDB_NOTNULL, null, null);
            $table->add_field('userid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $table->add_field('timecreated', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $table->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);
            $table->add_index('mention_uix', XMLDB_INDEX_UNIQUE, ['companyuuid', 'messageuuid', 'userid']);
            $table->add_index('user_ix', XMLDB_INDEX_NOTUNIQUE, ['companyuuid', 'userid', 'timecreated']);
            $dbman->create_table($table);
        }

        $table = new xmldb_table('local_kbacompany_commnotif');
        if (!$dbman->table_exists($table)) {
            $table->add_field('id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE, null);
            $table->add_field('companyuuid', XMLDB_TYPE_CHAR, '36', null, XMLDB_NOTNULL, null, null);
            $table->add_field('uuid', XMLDB_TYPE_CHAR, '36', null, XMLDB_NOTNULL, null, null);
            $table->add_field('userid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $table->add_field('kind', XMLDB_TYPE_CHAR, '24', null, XMLDB_NOTNULL, null, null);
            $table->add_field('threaduuid', XMLDB_TYPE_CHAR, '36', null, XMLDB_NOTNULL, null, '');
            $table->add_field('messageuuid', XMLDB_TYPE_CHAR, '36', null, XMLDB_NOTNULL, null, '');
            $table->add_field('taskuuid', XMLDB_TYPE_CHAR, '36', null, XMLDB_NOTNULL, null, '');
            $table->add_field('title', XMLDB_TYPE_CHAR, '255', null, XMLDB_NOTNULL, null, '');
            $table->add_field('isread', XMLDB_TYPE_INTEGER, '1', null, XMLDB_NOTNULL, null, '0');
            $table->add_field('timecreated', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $table->add_field('timeread', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $table->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);
            $table->add_index('uuid_uix', XMLDB_INDEX_UNIQUE, ['uuid']);
            $table->add_index('inbox_ix', XMLDB_INDEX_NOTUNIQUE, ['companyuuid', 'userid', 'isread', 'timecreated']);
            $table->add_index('thread_ix', XMLDB_INDEX_NOTUNIQUE, ['companyuuid', 'threaduuid', 'timecreated']);
            $dbman->create_table($table);
        }

        $table = new xmldb_table('local_kbacompany_commattach');
        if (!$dbman->table_exists($table)) {
            $table->add_field('id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE, null);
            $table->add_field('companyuuid', XMLDB_TYPE_CHAR, '36', null, XMLDB_NOTNULL, null, null);
            $table->add_field('uuid', XMLDB_TYPE_CHAR, '36', null, XMLDB_NOTNULL, null, null);
            $table->add_field('messageuuid', XMLDB_TYPE_CHAR, '36', null, XMLDB_NOTNULL, null, null);
            $table->add_field('refkind', XMLDB_TYPE_CHAR, '20', null, XMLDB_NOTNULL, null, null);
            $table->add_field('refvalue', XMLDB_TYPE_TEXT, null, null, XMLDB_NOTNULL, null, null);
            $table->add_field('label', XMLDB_TYPE_CHAR, '255', null, XMLDB_NOTNULL, null, '');
            $table->add_field('timecreated', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $table->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);
            $table->add_index('uuid_uix', XMLDB_INDEX_UNIQUE, ['uuid']);
            $table->add_index('message_ix', XMLDB_INDEX_NOTUNIQUE, ['companyuuid', 'messageuuid']);
            $dbman->create_table($table);
        }

        upgrade_plugin_savepoint(true, 2026091337, 'local', 'kbacompany');
    }


    if ($oldversion < 2026091338) {
        // v0.4.6 migration stage 2: provider bindings and Battle Plan external task references, rebased above the App Shell line.
        $table = new xmldb_table('local_kbacompany_tasksrc');
        if (!$dbman->table_exists($table)) {
            $table->add_field('id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE, null);
            $table->add_field('companyuuid', XMLDB_TYPE_CHAR, '36', null, XMLDB_NOTNULL, null, null);
            $table->add_field('provider', XMLDB_TYPE_CHAR, '32', null, XMLDB_NOTNULL, null, null);
            $table->add_field('sourcekey', XMLDB_TYPE_CHAR, '100', null, XMLDB_NOTNULL, null, null);
            $table->add_field('label', XMLDB_TYPE_CHAR, '255', null, XMLDB_NOTNULL, null, '');
            $table->add_field('config', XMLDB_TYPE_TEXT, null, null, null, null, null);
            $table->add_field('active', XMLDB_TYPE_INTEGER, '1', null, XMLDB_NOTNULL, null, '1');
            $table->add_field('createdby', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $table->add_field('timecreated', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $table->add_field('timemodified', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $table->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);
            $table->add_index('source_uix', XMLDB_INDEX_UNIQUE, ['companyuuid', 'provider', 'sourcekey']);
            $table->add_index('provider_ix', XMLDB_INDEX_NOTUNIQUE, ['companyuuid', 'provider', 'active']);
            $table->add_index('creator_ix', XMLDB_INDEX_NOTUNIQUE, ['createdby', 'timecreated']);
            $dbman->create_table($table);
        }

        $table = new xmldb_table('local_kbacompany_bplanitem');
        if ($dbman->table_exists($table)) {
            // IMPORTANT: taskuuid participates in the legacy unique index. Moodle XMLDB
            // refuses field mutations while dependent indexes exist, so drop that index
            // before changing the field default. This is safe and idempotent for retries
            // after a partially failed 0.4.6 upgrade.
            $oldindex = new xmldb_index('task_uix', XMLDB_INDEX_UNIQUE, ['companyuuid', 'planuuid', 'taskuuid']);
            if ($dbman->index_exists($table, $oldindex)) {
                $dbman->drop_index($table, $oldindex);
            }

            $field = new xmldb_field('taskuuid', XMLDB_TYPE_CHAR, '36', null, XMLDB_NOTNULL, null, '', 'planuuid');
            $dbman->change_field_default($table, $field);

            $field = new xmldb_field('provider', XMLDB_TYPE_CHAR, '32', null, XMLDB_NOTNULL, null, 'bms', 'taskuuid');
            if (!$dbman->field_exists($table, $field)) {
                $dbman->add_field($table, $field);
            }
            $field = new xmldb_field('externalid', XMLDB_TYPE_CHAR, '100', null, XMLDB_NOTNULL, null, '', 'provider');
            if (!$dbman->field_exists($table, $field)) {
                $dbman->add_field($table, $field);
            }

            // Existing Battle Plan references become explicit BMS-provider references.
            $DB->execute("UPDATE {local_kbacompany_bplanitem}
                            SET provider = 'bms', externalid = taskuuid
                          WHERE externalid = ''");

            $index = new xmldb_index('reference_uix', XMLDB_INDEX_UNIQUE,
                ['companyuuid', 'planuuid', 'provider', 'externalid']);
            if (!$dbman->index_exists($table, $index)) {
                $dbman->add_index($table, $index);
            }
        }

        upgrade_plugin_savepoint(true, 2026091338, 'local', 'kbacompany');
    }

    if ($oldversion < 2026091340) {
        // v0.4.6.1: migration dependency-order hotfix. No additional data/schema change.
        upgrade_plugin_savepoint(true, 2026091340, 'local', 'kbacompany');
    }

    if ($oldversion < 2026091341) {
        // 0.4.6.2 is a runtime syntax-compatibility hotfix only.
        upgrade_plugin_savepoint(true, 2026091341, 'local', 'kbacompany');
    }

    if ($oldversion < 2026091343) {
        // 0.4.6.4 changes the BMS presentation/navigation model only. No DB schema change.
        upgrade_plugin_savepoint(true, 2026091343, 'local', 'kbacompany');
    }

    if ($oldversion < 2026091344) {
        // 0.4.6.5 changes Battle Plan manager context selection only. No DB schema change.
        upgrade_plugin_savepoint(true, 2026091344, 'local', 'kbacompany');
    }

    if ($oldversion < 2026091345) {
        // 0.4.6.6 unifies company.php and BMS Workspace entry UX. No DB schema change.
        upgrade_plugin_savepoint(true, 2026091345, 'local', 'kbacompany');
    }

    if ($oldversion < 2026091346) {
        // 0.4.6.7 applies the shared BMS Page Shell adapter. No DB schema change.
        upgrade_plugin_savepoint(true, 2026091346, 'local', 'kbacompany');
    }

    if ($oldversion < 2026091347) {
        // 0.4.6.8 reorganises the Business Architecture UX/model only. No DB schema change.
        upgrade_plugin_savepoint(true, 2026091347, 'local', 'kbacompany');
    }

    if ($oldversion < 2026091348) {
        // 0.4.6.9 turns Business Architecture into a guided workspace. No DB schema change.
        upgrade_plugin_savepoint(true, 2026091348, 'local', 'kbacompany');
    }

    if ($oldversion < 2026091349) {
        // 0.4.6.10 retires the legacy Administrative Cascade from customer-facing UI only.
        // Legacy tables/services remain intact for backward compatibility and historical data.
        upgrade_plugin_savepoint(true, 2026091349, 'local', 'kbacompany');
    }

    if ($oldversion < 2026091350) {
        // 0.4.6.11 adds the OFS Visual Designer and versioned visual metadata inside existing
        // OrgDesignVersion payloads. No database schema change is required.
        upgrade_plugin_savepoint(true, 2026091350, 'local', 'kbacompany');
    }

    if ($oldversion < 2026091351) {
        // 0.4.6.11.1: audit action names used by the OFS Visual Designer (for example
        // design_visualsettings) exceed the original 20-character field length.
        // Widen the audit action field without deleting or rewriting historical records.
        $table = new xmldb_table('local_kbacompany_aclog');
        $field = new xmldb_field('action', XMLDB_TYPE_CHAR, '64', null, XMLDB_NOTNULL, null, null, 'objectuuid');
        if ($dbman->field_exists($table, $field)) {
            $dbman->change_field_precision($table, $field);
        }
        upgrade_plugin_savepoint(true, 2026091351, 'local', 'kbacompany');
    }

    if ($oldversion < 2026091352) {
        // 0.4.6.12 adds SVG/PDF/Draw.io OFS export providers only. No DB schema change.
        upgrade_plugin_savepoint(true, 2026091352, 'local', 'kbacompany');
    }

    if ($oldversion < 2026091353) {
        // 0.4.6.13 adds the built-in Organization Template Library only. No DB schema change.
        upgrade_plugin_savepoint(true, 2026091353, 'local', 'kbacompany');
    }

    if ($oldversion < 2026091354) {
        // Early 0.4.6.14 pre-release checkpoint. No DB schema change.
        upgrade_plugin_savepoint(true, 2026091354, 'local', 'kbacompany');
    }

    if ($oldversion < 2026091355) {
        // Final 0.4.6.14: persist human-reviewed legacy Draw.io imports as normalized
        // company-scoped template drafts with immutable versions. Originals remain read-only.
        $table = new xmldb_table('local_kbacompany_ofstpl');
        if (!$dbman->table_exists($table)) {
            $table->add_field('id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE, null);
            $table->add_field('companyuuid', XMLDB_TYPE_CHAR, '36', null, XMLDB_NOTNULL, null, null);
            $table->add_field('uuid', XMLDB_TYPE_CHAR, '36', null, XMLDB_NOTNULL, null, null);
            $table->add_field('name', XMLDB_TYPE_CHAR, '255', null, XMLDB_NOTNULL, null, null);
            $table->add_field('status', XMLDB_TYPE_CHAR, '16', null, XMLDB_NOTNULL, null, 'draft');
            $table->add_field('revision', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '1');
            $table->add_field('sourcetype', XMLDB_TYPE_CHAR, '32', null, XMLDB_NOTNULL, null, 'legacy_drawio');
            $table->add_field('sourcefilename', XMLDB_TYPE_CHAR, '255', null, XMLDB_NOTNULL, null, null);
            $table->add_field('sourcedriveid', XMLDB_TYPE_CHAR, '128', null, XMLDB_NOTNULL, null, '');
            $table->add_field('sourcemodified', XMLDB_TYPE_CHAR, '40', null, XMLDB_NOTNULL, null, '');
            $table->add_field('sourcechecksum', XMLDB_TYPE_CHAR, '64', null, XMLDB_NOTNULL, null, null);
            $table->add_field('parserversion', XMLDB_TYPE_CHAR, '32', null, XMLDB_NOTNULL, null, null);
            $table->add_field('mappingversion', XMLDB_TYPE_CHAR, '32', null, XMLDB_NOTNULL, null, null);
            $table->add_field('reviewer', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $table->add_field('createdby', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $table->add_field('timecreated', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $table->add_field('timemodified', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $table->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);
            $table->add_index('uuid_uix', XMLDB_INDEX_UNIQUE, ['uuid']);
            $table->add_index('company_status_ix', XMLDB_INDEX_NOTUNIQUE, ['companyuuid', 'status']);
            $table->add_index('checksum_ix', XMLDB_INDEX_NOTUNIQUE, ['companyuuid', 'sourcechecksum']);
            $dbman->create_table($table);
        }

        $table = new xmldb_table('local_kbacompany_ofstplver');
        if (!$dbman->table_exists($table)) {
            $table->add_field('id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE, null);
            $table->add_field('companyuuid', XMLDB_TYPE_CHAR, '36', null, XMLDB_NOTNULL, null, null);
            $table->add_field('uuid', XMLDB_TYPE_CHAR, '36', null, XMLDB_NOTNULL, null, null);
            $table->add_field('templateuuid', XMLDB_TYPE_CHAR, '36', null, XMLDB_NOTNULL, null, null);
            $table->add_field('revision', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '1');
            $table->add_field('payload', XMLDB_TYPE_TEXT, null, null, XMLDB_NOTNULL, null, null);
            $table->add_field('analysis', XMLDB_TYPE_TEXT, null, null, null, null, null);
            $table->add_field('digest', XMLDB_TYPE_CHAR, '64', null, XMLDB_NOTNULL, null, null);
            $table->add_field('mappingdigest', XMLDB_TYPE_CHAR, '64', null, XMLDB_NOTNULL, null, null);
            $table->add_field('createdby', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $table->add_field('timecreated', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $table->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);
            $table->add_index('uuid_uix', XMLDB_INDEX_UNIQUE, ['uuid']);
            $table->add_index('revision_uix', XMLDB_INDEX_UNIQUE, ['companyuuid', 'templateuuid', 'revision']);
            $dbman->create_table($table);
        }

        upgrade_plugin_savepoint(true, 2026091355, 'local', 'kbacompany');
    }

    if ($oldversion < 2026091356) {
        // 0.4.6.15 refines legacy template normalization and preview only.
        // No schema change: persisted 0.4.6.14 template payloads remain valid and immutable.
        upgrade_plugin_savepoint(true, 2026091356, 'local', 'kbacompany');
    }

    if ($oldversion < 2026091357) {
        // 0.4.7 adds participant-scoped Meetings & Decisions. All tables are additive.
        $table = new xmldb_table('local_kbacompany_meeting');
        if (!$dbman->table_exists($table)) {
            $table->add_field('id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE, null);
            $table->add_field('companyuuid', XMLDB_TYPE_CHAR, '36', null, XMLDB_NOTNULL, null, null);
            $table->add_field('uuid', XMLDB_TYPE_CHAR, '36', null, XMLDB_NOTNULL, null, null);
            $table->add_field('title', XMLDB_TYPE_CHAR, '255', null, XMLDB_NOTNULL, null, null);
            $table->add_field('purpose', XMLDB_TYPE_TEXT, null, null, XMLDB_NOTNULL, null, null);
            $table->add_field('status', XMLDB_TYPE_CHAR, '16', null, XMLDB_NOTNULL, null, 'draft');
            $table->add_field('startsat', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $table->add_field('endsat', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $table->add_field('location', XMLDB_TYPE_CHAR, '255', null, XMLDB_NOTNULL, null, '');
            $table->add_field('sourcethreaduuid', XMLDB_TYPE_CHAR, '36', null, XMLDB_NOTNULL, null, '');
            $table->add_field('threaduuid', XMLDB_TYPE_CHAR, '36', null, XMLDB_NOTNULL, null, '');
            $table->add_field('organizerid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $table->add_field('chairid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $table->add_field('secretaryid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $table->add_field('minutes', XMLDB_TYPE_TEXT, null, null, null, null, null);
            $table->add_field('result', XMLDB_TYPE_TEXT, null, null, null, null, null);
            $table->add_field('revision', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '1');
            $table->add_field('closedby', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $table->add_field('closedat', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $table->add_field('timecreated', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $table->add_field('timemodified', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $table->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);
            $table->add_index('uuid_uix', XMLDB_INDEX_UNIQUE, ['uuid']);
            $table->add_index('company_status_ix', XMLDB_INDEX_NOTUNIQUE, ['companyuuid', 'status', 'startsat']);
            $table->add_index('thread_ix', XMLDB_INDEX_NOTUNIQUE, ['companyuuid', 'threaduuid']);
            $table->add_index('source_thread_ix', XMLDB_INDEX_NOTUNIQUE, ['companyuuid', 'sourcethreaduuid']);
            $dbman->create_table($table);
        }

        $table = new xmldb_table('local_kbacompany_meetpart');
        if (!$dbman->table_exists($table)) {
            $table->add_field('id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE, null);
            $table->add_field('companyuuid', XMLDB_TYPE_CHAR, '36', null, XMLDB_NOTNULL, null, null);
            $table->add_field('meetinguuid', XMLDB_TYPE_CHAR, '36', null, XMLDB_NOTNULL, null, null);
            $table->add_field('userid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $table->add_field('role', XMLDB_TYPE_CHAR, '16', null, XMLDB_NOTNULL, null, 'member');
            $table->add_field('attendancestatus', XMLDB_TYPE_CHAR, '16', null, XMLDB_NOTNULL, null, 'invited');
            $table->add_field('required', XMLDB_TYPE_INTEGER, '1', null, XMLDB_NOTNULL, null, '1');
            $table->add_field('active', XMLDB_TYPE_INTEGER, '1', null, XMLDB_NOTNULL, null, '1');
            $table->add_field('timecreated', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $table->add_field('timemodified', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $table->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);
            $table->add_index('participant_uix', XMLDB_INDEX_UNIQUE, ['companyuuid', 'meetinguuid', 'userid']);
            $table->add_index('user_ix', XMLDB_INDEX_NOTUNIQUE, ['companyuuid', 'userid', 'active']);
            $dbman->create_table($table);
        }

        $table = new xmldb_table('local_kbacompany_meetagenda');
        if (!$dbman->table_exists($table)) {
            $table->add_field('id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE, null);
            $table->add_field('companyuuid', XMLDB_TYPE_CHAR, '36', null, XMLDB_NOTNULL, null, null);
            $table->add_field('uuid', XMLDB_TYPE_CHAR, '36', null, XMLDB_NOTNULL, null, null);
            $table->add_field('meetinguuid', XMLDB_TYPE_CHAR, '36', null, XMLDB_NOTNULL, null, null);
            $table->add_field('sortorder', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $table->add_field('title', XMLDB_TYPE_CHAR, '255', null, XMLDB_NOTNULL, null, null);
            $table->add_field('expectedproduct', XMLDB_TYPE_TEXT, null, null, null, null, null);
            $table->add_field('ownerid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $table->add_field('status', XMLDB_TYPE_CHAR, '16', null, XMLDB_NOTNULL, null, 'open');
            $table->add_field('notes', XMLDB_TYPE_TEXT, null, null, null, null, null);
            $table->add_field('timecreated', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $table->add_field('timemodified', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $table->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);
            $table->add_index('uuid_uix', XMLDB_INDEX_UNIQUE, ['uuid']);
            $table->add_index('meeting_ix', XMLDB_INDEX_NOTUNIQUE, ['companyuuid', 'meetinguuid', 'sortorder']);
            $dbman->create_table($table);
        }

        $table = new xmldb_table('local_kbacompany_decision');
        if (!$dbman->table_exists($table)) {
            $table->add_field('id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE, null);
            $table->add_field('companyuuid', XMLDB_TYPE_CHAR, '36', null, XMLDB_NOTNULL, null, null);
            $table->add_field('uuid', XMLDB_TYPE_CHAR, '36', null, XMLDB_NOTNULL, null, null);
            $table->add_field('meetinguuid', XMLDB_TYPE_CHAR, '36', null, XMLDB_NOTNULL, null, null);
            $table->add_field('agendauuid', XMLDB_TYPE_CHAR, '36', null, XMLDB_NOTNULL, null, '');
            $table->add_field('threaduuid', XMLDB_TYPE_CHAR, '36', null, XMLDB_NOTNULL, null, '');
            $table->add_field('title', XMLDB_TYPE_CHAR, '255', null, XMLDB_NOTNULL, null, null);
            $table->add_field('statement', XMLDB_TYPE_TEXT, null, null, XMLDB_NOTNULL, null, null);
            $table->add_field('rationale', XMLDB_TYPE_TEXT, null, null, null, null, null);
            $table->add_field('expectedresult', XMLDB_TYPE_TEXT, null, null, null, null, null);
            $table->add_field('ownerid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $table->add_field('proposedby', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $table->add_field('status', XMLDB_TYPE_CHAR, '16', null, XMLDB_NOTNULL, null, 'proposed');
            $table->add_field('decidedby', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $table->add_field('decidedat', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $table->add_field('revision', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '1');
            $table->add_field('timecreated', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $table->add_field('timemodified', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $table->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);
            $table->add_index('uuid_uix', XMLDB_INDEX_UNIQUE, ['uuid']);
            $table->add_index('meeting_ix', XMLDB_INDEX_NOTUNIQUE, ['companyuuid', 'meetinguuid', 'status']);
            $table->add_index('owner_ix', XMLDB_INDEX_NOTUNIQUE, ['companyuuid', 'ownerid', 'status']);
            $dbman->create_table($table);
        }

        upgrade_plugin_savepoint(true, 2026091357, 'local', 'kbacompany');
    }

    if ($oldversion < 2026091358) {
        // 0.4.8 adds an immutable Administrative Cycle source reference to meetings.
        $table = new xmldb_table('local_kbacompany_meeting');
        if ($dbman->table_exists($table)) {
            $field = new xmldb_field('sourcekind', XMLDB_TYPE_CHAR, '20', null, XMLDB_NOTNULL, null, 'manual', 'location');
            if (!$dbman->field_exists($table, $field)) { $dbman->add_field($table, $field); }
            $field = new xmldb_field('sourceuuid', XMLDB_TYPE_CHAR, '36', null, XMLDB_NOTNULL, null, '', 'sourcekind');
            if (!$dbman->field_exists($table, $field)) { $dbman->add_field($table, $field); }
            $field = new xmldb_field('sourcerevision', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0', 'sourceuuid');
            if (!$dbman->field_exists($table, $field)) { $dbman->add_field($table, $field); }
            $index = new xmldb_index('source_ix', XMLDB_INDEX_NOTUNIQUE,
                ['companyuuid', 'sourcekind', 'sourceuuid', 'sourcerevision']);
            if (!$dbman->index_exists($table, $index)) { $dbman->add_index($table, $index); }
        }
        upgrade_plugin_savepoint(true, 2026091358, 'local', 'kbacompany');
    }


    if ($oldversion < 2026091359) {
        // 0.4.9 adds immutable recurring task definitions. Generated occurrences remain in canonical Task Center.
        $table = new xmldb_table('local_kbacompany_taskrec');
        if (!$dbman->table_exists($table)) {
            $table->add_field('id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE, null);
            $table->add_field('companyuuid', XMLDB_TYPE_CHAR, '36', null, XMLDB_NOTNULL, null, null);
            $table->add_field('uuid', XMLDB_TYPE_CHAR, '36', null, XMLDB_NOTNULL, null, null);
            $table->add_field('revision', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '1');
            $table->add_field('status', XMLDB_TYPE_CHAR, '16', null, XMLDB_NOTNULL, null, 'draft');
            $table->add_field('templatekey', XMLDB_TYPE_CHAR, '64', null, XMLDB_NOTNULL, null, '');
            $table->add_field('category', XMLDB_TYPE_CHAR, '64', null, XMLDB_NOTNULL, null, '');
            $table->add_field('title', XMLDB_TYPE_CHAR, '255', null, XMLDB_NOTNULL, null, null);
            $table->add_field('description', XMLDB_TYPE_TEXT, null, null, null, null, null);
            $table->add_field('product', XMLDB_TYPE_TEXT, null, null, null, null, null);
            $table->add_field('stream', XMLDB_TYPE_CHAR, '16', null, XMLDB_NOTNULL, null, 'operate');
            $table->add_field('assigneeid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $table->add_field('creatorid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $table->add_field('reviewerid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $table->add_field('priority', XMLDB_TYPE_CHAR, '10', null, XMLDB_NOTNULL, null, 'normal');
            $table->add_field('cadence', XMLDB_TYPE_CHAR, '20', null, XMLDB_NOTNULL, null, 'weekly');
            $table->add_field('startdate', XMLDB_TYPE_CHAR, '10', null, XMLDB_NOTNULL, null, '');
            $table->add_field('enddate', XMLDB_TYPE_CHAR, '10', null, XMLDB_NOTNULL, null, '');
            $table->add_field('dueoffsetdays', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $table->add_field('planminutes', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $table->add_field('timecreated', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $table->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);
            $table->add_index('revision_uix', XMLDB_INDEX_UNIQUE, ['companyuuid', 'uuid', 'revision']);
            $table->add_index('status_ix', XMLDB_INDEX_NOTUNIQUE, ['companyuuid', 'status']);
            $table->add_index('assignee_ix', XMLDB_INDEX_NOTUNIQUE, ['companyuuid', 'assigneeid', 'status']);
            $table->add_index('template_ix', XMLDB_INDEX_NOTUNIQUE, ['companyuuid', 'templatekey', 'status']);
            $dbman->create_table($table);
        }
        upgrade_plugin_savepoint(true, 2026091359, 'local', 'kbacompany');
    }

    if ($oldversion < 2026091360) {
        // 0.4.9.1 preserves exact weekday sets and first-week-of-month schedules for recurring tasks.
        $table = new xmldb_table('local_kbacompany_taskrec');
        if ($dbman->table_exists($table)) {
            $field = new xmldb_field('weekdaymask', XMLDB_TYPE_INTEGER, '3', null, XMLDB_NOTNULL, null, '0', 'cadence');
            if (!$dbman->field_exists($table, $field)) { $dbman->add_field($table, $field); }
            $field = new xmldb_field('monthweek', XMLDB_TYPE_INTEGER, '2', null, XMLDB_NOTNULL, null, '0', 'weekdaymask');
            if (!$dbman->field_exists($table, $field)) { $dbman->add_field($table, $field); }
        }
        upgrade_plugin_savepoint(true, 2026091360, 'local', 'kbacompany');
    }

    if ($oldversion < 2026091361) {
        // 0.4.9.2 is schema-neutral: task library templates can create one-off canonical tasks in Battle Plan.
        upgrade_plugin_savepoint(true, 2026091361, 'local', 'kbacompany');
    }

    if ($oldversion < 2026091362) {
        // 0.4.9.3 is schema-neutral: assignee choice, Moodle File API attachments, self-approval and DOCX export.
        upgrade_plugin_savepoint(true, 2026091362, 'local', 'kbacompany');
    }

    if ($oldversion < 2026091363) {
        // 0.4.9.4 is schema-neutral: task discussions, audited soft delete and result/evidence File API area.
        upgrade_plugin_savepoint(true, 2026091363, 'local', 'kbacompany');
    }

    if ($oldversion < 2026091364) {
        // 0.4.9.5 adds an explicit read/observe role to canonical tasks and weekly Battle Plans.
        $table = new xmldb_table('local_kbacompany_task');
        if ($dbman->table_exists($table)) {
            $field = new xmldb_field('observerid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0', 'reviewerid');
            if (!$dbman->field_exists($table, $field)) { $dbman->add_field($table, $field); }
            $index = new xmldb_index('observer_ix', XMLDB_INDEX_NOTUNIQUE, ['companyuuid', 'observerid', 'status']);
            if (!$dbman->index_exists($table, $index)) { $dbman->add_index($table, $index); }
        }
        $table = new xmldb_table('local_kbacompany_bplan');
        if ($dbman->table_exists($table)) {
            $field = new xmldb_field('observerid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0', 'reviewerid');
            if (!$dbman->field_exists($table, $field)) { $dbman->add_field($table, $field); }
            $index = new xmldb_index('observer_ix', XMLDB_INDEX_NOTUNIQUE, ['companyuuid', 'observerid', 'status']);
            if (!$dbman->index_exists($table, $index)) { $dbman->add_index($table, $index); }
        }
        upgrade_plugin_savepoint(true, 2026091364, 'local', 'kbacompany');
    }


    if ($oldversion < 2026091365) {
        // 0.4.10 adds a private versioned Owner LPF model and BMC 2.0 payload/source reference.
        $table = new xmldb_table('local_kbacompany_ownerframe');
        if (!$dbman->table_exists($table)) {
            $table->add_field('id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE, null);
            $table->add_field('companyuuid', XMLDB_TYPE_CHAR, '36', null, XMLDB_NOTNULL, null, null);
            $table->add_field('uuid', XMLDB_TYPE_CHAR, '36', null, XMLDB_NOTNULL, null, null);
            $table->add_field('revision', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '1');
            $table->add_field('status', XMLDB_TYPE_CHAR, '12', null, XMLDB_NOTNULL, null, 'draft');
            $table->add_field('owneruserid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $table->add_field('title', XMLDB_TYPE_CHAR, '255', null, XMLDB_NOTNULL, null, null);
            $table->add_field('payload', XMLDB_TYPE_TEXT, null, null, XMLDB_NOTNULL, null, null);
            $table->add_field('createdby', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $table->add_field('modifiedby', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $table->add_field('timecreated', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $table->add_field('timemodified', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $table->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);
            $table->add_index('revision_uix', XMLDB_INDEX_UNIQUE, ['companyuuid', 'uuid', 'revision']);
            $table->add_index('owner_ix', XMLDB_INDEX_NOTUNIQUE, ['companyuuid', 'owneruserid', 'status']);
            $dbman->create_table($table);
        }
        $table = new xmldb_table('local_kbacompany_bizmodel');
        if ($dbman->table_exists($table)) {
            $field = new xmldb_field('ownerframeuuid', XMLDB_TYPE_CHAR, '36', null, XMLDB_NOTNULL, null, '', 'keypartners');
            if (!$dbman->field_exists($table, $field)) { $dbman->add_field($table, $field); }
            $field = new xmldb_field('ownerframerevision', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0', 'ownerframeuuid');
            if (!$dbman->field_exists($table, $field)) { $dbman->add_field($table, $field); }
            $field = new xmldb_field('canvaspayload', XMLDB_TYPE_TEXT, null, null, null, null, null, 'ownerframerevision');
            if (!$dbman->field_exists($table, $field)) { $dbman->add_field($table, $field); }
        }
        upgrade_plugin_savepoint(true, 2026091365, 'local', 'kbacompany');
    }


    if ($oldversion < 2026091366) {
        // 0.4.10.1 is schema-neutral: Russian client terminology hides internal English method codes.
        upgrade_plugin_savepoint(true, 2026091366, 'local', 'kbacompany');
    }


    if ($oldversion < 2026091367) {
        // 0.4.10.2 is schema-neutral: canonical tasks can be referenced from Task Center into a draft Battle Plan.
        upgrade_plugin_savepoint(true, 2026091367, 'local', 'kbacompany');
    }

    if ($oldversion < 2026091368) {
        // 0.4.10.3 adds versioned Battle Plan templates/quota sets and exact revision references on weekly plans.
        $table = new xmldb_table('local_kbacompany_bptpl');
        if (!$dbman->table_exists($table)) {
            $table->add_field('id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE, null);
            $table->add_field('companyuuid', XMLDB_TYPE_CHAR, '36', null, XMLDB_NOTNULL, null, null);
            $table->add_field('uuid', XMLDB_TYPE_CHAR, '36', null, XMLDB_NOTNULL, null, null);
            $table->add_field('revision', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '1');
            $table->add_field('status', XMLDB_TYPE_CHAR, '12', null, XMLDB_NOTNULL, null, 'draft');
            $table->add_field('positionuuid', XMLDB_TYPE_CHAR, '36', null, XMLDB_NOTNULL, null, null);
            $table->add_field('title', XMLDB_TYPE_CHAR, '255', null, XMLDB_NOTNULL, null, null);
            $table->add_field('payload', XMLDB_TYPE_TEXT, null, null, XMLDB_NOTNULL, null, null);
            $table->add_field('timecreated', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $table->add_field('timemodified', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $table->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);
            $table->add_index('revision_uix', XMLDB_INDEX_UNIQUE, ['companyuuid', 'uuid', 'revision']);
            $table->add_index('position_ix', XMLDB_INDEX_NOTUNIQUE, ['companyuuid', 'positionuuid', 'status']);
            $dbman->create_table($table);
        }
        $table = new xmldb_table('local_kbacompany_bpnorm');
        if (!$dbman->table_exists($table)) {
            $table->add_field('id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE, null);
            $table->add_field('companyuuid', XMLDB_TYPE_CHAR, '36', null, XMLDB_NOTNULL, null, null);
            $table->add_field('uuid', XMLDB_TYPE_CHAR, '36', null, XMLDB_NOTNULL, null, null);
            $table->add_field('revision', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '1');
            $table->add_field('status', XMLDB_TYPE_CHAR, '12', null, XMLDB_NOTNULL, null, 'draft');
            $table->add_field('positionuuid', XMLDB_TYPE_CHAR, '36', null, XMLDB_NOTNULL, null, null);
            $table->add_field('title', XMLDB_TYPE_CHAR, '255', null, XMLDB_NOTNULL, null, null);
            $table->add_field('payload', XMLDB_TYPE_TEXT, null, null, XMLDB_NOTNULL, null, null);
            $table->add_field('timecreated', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $table->add_field('timemodified', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $table->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);
            $table->add_index('revision_uix', XMLDB_INDEX_UNIQUE, ['companyuuid', 'uuid', 'revision']);
            $table->add_index('position_ix', XMLDB_INDEX_NOTUNIQUE, ['companyuuid', 'positionuuid', 'status']);
            $dbman->create_table($table);
        }
        $table = new xmldb_table('local_kbacompany_bplan');
        if ($dbman->table_exists($table)) {
            $field = new xmldb_field('templateuuid', XMLDB_TYPE_CHAR, '36', null, XMLDB_NOTNULL, null, '', 'observerid');
            if (!$dbman->field_exists($table, $field)) { $dbman->add_field($table, $field); }
            $field = new xmldb_field('templaterevision', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0', 'templateuuid');
            if (!$dbman->field_exists($table, $field)) { $dbman->add_field($table, $field); }
            $field = new xmldb_field('normuuid', XMLDB_TYPE_CHAR, '36', null, XMLDB_NOTNULL, null, '', 'templaterevision');
            if (!$dbman->field_exists($table, $field)) { $dbman->add_field($table, $field); }
            $field = new xmldb_field('normrevision', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0', 'normuuid');
            if (!$dbman->field_exists($table, $field)) { $dbman->add_field($table, $field); }
            $field = new xmldb_field('statepayload', XMLDB_TYPE_TEXT, null, null, null, null, null, 'normrevision');
            if (!$dbman->field_exists($table, $field)) { $dbman->add_field($table, $field); }
            $index = new xmldb_index('template_ix', XMLDB_INDEX_NOTUNIQUE, ['companyuuid', 'templateuuid', 'templaterevision']);
            if (!$dbman->index_exists($table, $index)) { $dbman->add_index($table, $index); }
            $index = new xmldb_index('norm_ix', XMLDB_INDEX_NOTUNIQUE, ['companyuuid', 'normuuid', 'normrevision']);
            if (!$dbman->index_exists($table, $index)) { $dbman->add_index($table, $index); }
        }
        upgrade_plugin_savepoint(true, 2026091368, 'local', 'kbacompany');
    }

    if ($oldversion < 2026091369) {
        // 0.4.10.4 adds an optional version-pinned management context for manual tasks.
        $table = new xmldb_table('local_kbacompany_task');
        if ($dbman->table_exists($table)) {
            $field = new xmldb_field('contextkind', XMLDB_TYPE_CHAR, '20', null, XMLDB_NOTNULL, null, '', 'parentuuid');
            if (!$dbman->field_exists($table, $field)) { $dbman->add_field($table, $field); }
            $field = new xmldb_field('contextuuid', XMLDB_TYPE_CHAR, '36', null, XMLDB_NOTNULL, null, '', 'contextkind');
            if (!$dbman->field_exists($table, $field)) { $dbman->add_field($table, $field); }
            $field = new xmldb_field('contextrevision', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0', 'contextuuid');
            if (!$dbman->field_exists($table, $field)) { $dbman->add_field($table, $field); }
            $index = new xmldb_index('context_ix', XMLDB_INDEX_NOTUNIQUE, ['companyuuid', 'contextkind', 'contextuuid', 'contextrevision']);
            if (!$dbman->index_exists($table, $index)) { $dbman->add_index($table, $index); }
        }
        upgrade_plugin_savepoint(true, 2026091369, 'local', 'kbacompany');
    }


    if ($oldversion < 2026091370) {
        // 0.4.10.5 is a schema-neutral task-card UX cleanup.
        upgrade_plugin_savepoint(true, 2026091370, 'local', 'kbacompany');
    }

    if ($oldversion < 2026091371) {
        // 0.4.10.6 is schema-neutral: compact/full task form and responsive task discussion layout.
        upgrade_plugin_savepoint(true, 2026091371, 'local', 'kbacompany');
    }

    if ($oldversion < 2026091372) {
        // 0.4.10.7 is schema-neutral: task card compaction and modal quick create.
        upgrade_plugin_savepoint(true, 2026091372, 'local', 'kbacompany');
    }

    if ($oldversion < 2026091373) {
        // 0.4.10.8 is schema-neutral: create task directly into Battle Plan and expose Management Cycle project relation.
        upgrade_plugin_savepoint(true, 2026091373, 'local', 'kbacompany');
    }

    if ($oldversion < 2026091374) {
        // 0.4.10.9 is schema-neutral: readable Battle Plan periods and direct task-library insertion into a draft plan.
        upgrade_plugin_savepoint(true, 2026091374, 'local', 'kbacompany');
    }

    if ($oldversion < 2026091375) {
        // 0.4.11 adds versioned company categories/templates for the recurring-task library.
        $table = new xmldb_table('local_kbacompany_tasklibcat');
        if (!$dbman->table_exists($table)) {
            $table->add_field('id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE, null);
            $table->add_field('companyuuid', XMLDB_TYPE_CHAR, '36', null, XMLDB_NOTNULL, null, null);
            $table->add_field('uuid', XMLDB_TYPE_CHAR, '36', null, XMLDB_NOTNULL, null, null);
            $table->add_field('revision', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '1');
            $table->add_field('status', XMLDB_TYPE_CHAR, '12', null, XMLDB_NOTNULL, null, 'active');
            $table->add_field('libkey', XMLDB_TYPE_CHAR, '64', null, XMLDB_NOTNULL, null, null);
            $table->add_field('title', XMLDB_TYPE_CHAR, '255', null, XMLDB_NOTNULL, null, null);
            $table->add_field('sortorder', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '1000');
            $table->add_field('creatorid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $table->add_field('timecreated', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $table->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);
            $table->add_index('revision_uix', XMLDB_INDEX_UNIQUE, ['companyuuid', 'libkey', 'revision']);
            $table->add_index('status_ix', XMLDB_INDEX_NOTUNIQUE, ['companyuuid', 'status']);
            $dbman->create_table($table);
        }
        $table = new xmldb_table('local_kbacompany_tasklibtpl');
        if (!$dbman->table_exists($table)) {
            $table->add_field('id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE, null);
            $table->add_field('companyuuid', XMLDB_TYPE_CHAR, '36', null, XMLDB_NOTNULL, null, null);
            $table->add_field('uuid', XMLDB_TYPE_CHAR, '36', null, XMLDB_NOTNULL, null, null);
            $table->add_field('revision', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '1');
            $table->add_field('status', XMLDB_TYPE_CHAR, '12', null, XMLDB_NOTNULL, null, 'active');
            $table->add_field('libkey', XMLDB_TYPE_CHAR, '64', null, XMLDB_NOTNULL, null, null);
            $table->add_field('categorykey', XMLDB_TYPE_CHAR, '64', null, XMLDB_NOTNULL, null, null);
            $table->add_field('payload', XMLDB_TYPE_TEXT, null, null, XMLDB_NOTNULL, null, null);
            $table->add_field('creatorid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $table->add_field('timecreated', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $table->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);
            $table->add_index('revision_uix', XMLDB_INDEX_UNIQUE, ['companyuuid', 'libkey', 'revision']);
            $table->add_index('category_ix', XMLDB_INDEX_NOTUNIQUE, ['companyuuid', 'categorykey', 'status']);
            $table->add_index('status_ix', XMLDB_INDEX_NOTUNIQUE, ['companyuuid', 'status']);
            $dbman->create_table($table);
        }
        upgrade_plugin_savepoint(true, 2026091375, 'local', 'kbacompany');
    }


    if ($oldversion < 2026091376) {
        // 0.4.11.1 is schema-neutral: expose task-library CRUD directly on the library page.
        upgrade_plugin_savepoint(true, 2026091376, 'local', 'kbacompany');
    }

    if ($oldversion < 2026091377) {
        // 0.4.11.2 is schema-neutral: simplify Task Center navigation and localize KBA Element as Learning.
        upgrade_plugin_savepoint(true, 2026091377, 'local', 'kbacompany');
    }

    if ($oldversion < 2026091378) {
        // 0.4.11.3 is schema-neutral: make task-library category management explicit and fix editor anchors.
        upgrade_plugin_savepoint(true, 2026091378, 'local', 'kbacompany');
    }

    if ($oldversion < 2026091379) {
        // 0.5.0 adds the versioned indicator passport, binding and norm layers above canonical statistics.
        $table = new xmldb_table('local_kbacompany_statmeta');
        if (!$dbman->table_exists($table)) {
            $table->add_field('id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE, null);
            $table->add_field('companyuuid', XMLDB_TYPE_CHAR, '36', null, XMLDB_NOTNULL, null, null);
            $table->add_field('statuuid', XMLDB_TYPE_CHAR, '36', null, XMLDB_NOTNULL, null, null);
            $table->add_field('revision', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '1');
            $table->add_field('status', XMLDB_TYPE_CHAR, '12', null, XMLDB_NOTNULL, null, 'active');
            $table->add_field('description', XMLDB_TYPE_TEXT, null, null, null, null, null);
            $table->add_field('sourcekind', XMLDB_TYPE_CHAR, '16', null, XMLDB_NOTNULL, null, 'manual');
            $table->add_field('sourceconfig', XMLDB_TYPE_TEXT, null, null, null, null, null);
            $table->add_field('aggregation', XMLDB_TYPE_CHAR, '16', null, XMLDB_NOTNULL, null, 'direct');
            $table->add_field('createdby', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $table->add_field('timecreated', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $table->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);
            $table->add_index('revision_uix', XMLDB_INDEX_UNIQUE, ['companyuuid', 'statuuid', 'revision']);
            $table->add_index('status_ix', XMLDB_INDEX_NOTUNIQUE, ['companyuuid', 'status']);
            $dbman->create_table($table);
        }
        $table = new xmldb_table('local_kbacompany_statbind');
        if (!$dbman->table_exists($table)) {
            $table->add_field('id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE, null);
            $table->add_field('companyuuid', XMLDB_TYPE_CHAR, '36', null, XMLDB_NOTNULL, null, null);
            $table->add_field('uuid', XMLDB_TYPE_CHAR, '36', null, XMLDB_NOTNULL, null, null);
            $table->add_field('revision', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '1');
            $table->add_field('status', XMLDB_TYPE_CHAR, '12', null, XMLDB_NOTNULL, null, 'active');
            $table->add_field('statuuid', XMLDB_TYPE_CHAR, '36', null, XMLDB_NOTNULL, null, null);
            $table->add_field('contextkind', XMLDB_TYPE_CHAR, '16', null, XMLDB_NOTNULL, null, null);
            $table->add_field('contextuuid', XMLDB_TYPE_CHAR, '36', null, XMLDB_NOTNULL, null, null);
            $table->add_field('contextrevision', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $table->add_field('isprimary', XMLDB_TYPE_INTEGER, '1', null, XMLDB_NOTNULL, null, '0');
            $table->add_field('critical', XMLDB_TYPE_INTEGER, '1', null, XMLDB_NOTNULL, null, '0');
            $table->add_field('weight', XMLDB_TYPE_NUMBER, '8', '3', XMLDB_NOTNULL, null, '1');
            $table->add_field('sortorder', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $table->add_field('createdby', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $table->add_field('timecreated', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $table->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);
            $table->add_index('revision_uix', XMLDB_INDEX_UNIQUE, ['companyuuid', 'uuid', 'revision']);
            $table->add_index('stat_ix', XMLDB_INDEX_NOTUNIQUE, ['companyuuid', 'statuuid', 'status']);
            $table->add_index('context_ix', XMLDB_INDEX_NOTUNIQUE, ['companyuuid', 'contextkind', 'contextuuid', 'status']);
            $dbman->create_table($table);
        }
        $table = new xmldb_table('local_kbacompany_statnorm');
        if (!$dbman->table_exists($table)) {
            $table->add_field('id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE, null);
            $table->add_field('companyuuid', XMLDB_TYPE_CHAR, '36', null, XMLDB_NOTNULL, null, null);
            $table->add_field('uuid', XMLDB_TYPE_CHAR, '36', null, XMLDB_NOTNULL, null, null);
            $table->add_field('revision', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '1');
            $table->add_field('status', XMLDB_TYPE_CHAR, '12', null, XMLDB_NOTNULL, null, 'active');
            $table->add_field('binduuid', XMLDB_TYPE_CHAR, '36', null, XMLDB_NOTNULL, null, null);
            $table->add_field('bindrevision', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $table->add_field('normkind', XMLDB_TYPE_CHAR, '12', null, XMLDB_NOTNULL, null, 'target');
            $table->add_field('targetvalue', XMLDB_TYPE_NUMBER, '18', '6', null, null, null);
            $table->add_field('minval', XMLDB_TYPE_NUMBER, '18', '6', null, null, null);
            $table->add_field('maxval', XMLDB_TYPE_NUMBER, '18', '6', null, null, null);
            $table->add_field('effectivefrom', XMLDB_TYPE_CHAR, '10', null, XMLDB_NOTNULL, null, null);
            $table->add_field('effectiveto', XMLDB_TYPE_CHAR, '10', null, XMLDB_NOTNULL, null, '');
            $table->add_field('createdby', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $table->add_field('timecreated', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $table->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);
            $table->add_index('revision_uix', XMLDB_INDEX_UNIQUE, ['companyuuid', 'uuid', 'revision']);
            $table->add_index('bind_ix', XMLDB_INDEX_NOTUNIQUE, ['companyuuid', 'binduuid', 'status']);
            $table->add_index('effective_ix', XMLDB_INDEX_NOTUNIQUE, ['companyuuid', 'effectivefrom', 'effectiveto', 'status']);
            $dbman->create_table($table);
        }
        upgrade_plugin_savepoint(true, 2026091379, 'local', 'kbacompany');
    }


    if ($oldversion < 2026091380) {
        // 0.5.1 is schema-neutral: Information Center, 13/26/52-period charts and drill-down views.
        upgrade_plugin_savepoint(true, 2026091380, 'local', 'kbacompany');
    }

    if ($oldversion < 2026091381) {
        // 0.5.1.1 hotfix: avoid MySQL reserved keyword MAXVALUE in indicator norm storage.
        $table = new xmldb_table('local_kbacompany_statnorm');
        if ($dbman->table_exists($table)) {
            $field = new xmldb_field('minvalue', XMLDB_TYPE_NUMBER, '18', '6', null, null, null);
            if ($dbman->field_exists($table, $field)) {
                $dbman->rename_field($table, $field, 'minval');
            }
            $field = new xmldb_field('maxvalue', XMLDB_TYPE_NUMBER, '18', '6', null, null, null);
            if ($dbman->field_exists($table, $field)) {
                $dbman->rename_field($table, $field, 'maxval');
            }
        }
        upgrade_plugin_savepoint(true, 2026091381, 'local', 'kbacompany');
    }

    if ($oldversion < 2026091382) {
        // 0.5.1.2 is schema-neutral: allow indicator administration for site admins and Business Roles
        // "Управляющий" / "Супервайзер" without broadening permissions in other BMS modules.
        upgrade_plugin_savepoint(true, 2026091382, 'local', 'kbacompany');
    }

    if ($oldversion < 2026091383) {
        // 0.5.1.3 is schema-neutral: indicator creation is visible without CKP/product and uses indicator-scoped permissions.
        upgrade_plugin_savepoint(true, 2026091383, 'local', 'kbacompany');
    }

    if ($oldversion < 2026091384) {
        // 0.5.2 is schema-neutral: trend classification and derived trend events use canonical stat history.
        upgrade_plugin_savepoint(true, 2026091384, 'local', 'kbacompany');
    }

    if ($oldversion < 2026091385) {
        // 0.5.2 completion: exact trend classification, filters and derived extrema/norm-transition events.
        // No event storage and no duplicate fact rows are introduced.
        upgrade_plugin_savepoint(true, 2026091385, 'local', 'kbacompany');
    }


    if ($oldversion < 2026091501) {
        // 0.5.3 adds a versioned condition-interpretation policy per canonical indicator binding.
        // The table stores explicit methodology sensitivity only; facts, trends and derived states are not copied.
        $table = new xmldb_table('local_kbacompany_statpolicy');
        if (!$dbman->table_exists($table)) {
            $table->add_field('id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE, null);
            $table->add_field('companyuuid', XMLDB_TYPE_CHAR, '36', null, XMLDB_NOTNULL, null, null);
            $table->add_field('uuid', XMLDB_TYPE_CHAR, '36', null, XMLDB_NOTNULL, null, null);
            $table->add_field('revision', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '1');
            $table->add_field('status', XMLDB_TYPE_CHAR, '12', null, XMLDB_NOTNULL, null, 'active');
            $table->add_field('binduuid', XMLDB_TYPE_CHAR, '36', null, XMLDB_NOTNULL, null, null);
            $table->add_field('bindrevision', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $table->add_field('dangerdecline', XMLDB_TYPE_NUMBER, '8', '3', null, null, null);
            $table->add_field('dangerperiods', XMLDB_TYPE_INTEGER, '4', null, XMLDB_NOTNULL, null, '0');
            $table->add_field('affluencegrowth', XMLDB_TYPE_NUMBER, '8', '3', null, null, null);
            $table->add_field('powerlevel', XMLDB_TYPE_NUMBER, '8', '3', null, null, null);
            $table->add_field('powerperiods', XMLDB_TYPE_INTEGER, '4', null, XMLDB_NOTNULL, null, '0');
            $table->add_field('effectivefrom', XMLDB_TYPE_CHAR, '10', null, XMLDB_NOTNULL, null, null);
            $table->add_field('effectiveto', XMLDB_TYPE_CHAR, '10', null, XMLDB_NOTNULL, null, '');
            $table->add_field('createdby', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $table->add_field('timecreated', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $table->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);
            $table->add_index('revision_uix', XMLDB_INDEX_UNIQUE, ['companyuuid', 'uuid', 'revision']);
            $table->add_index('bind_ix', XMLDB_INDEX_NOTUNIQUE, ['companyuuid', 'binduuid', 'status']);
            $table->add_index('effective_ix', XMLDB_INDEX_NOTUNIQUE, ['companyuuid', 'effectivefrom', 'effectiveto', 'status']);
            $dbman->create_table($table);
        }
        upgrade_plugin_savepoint(true, 2026091501, 'local', 'kbacompany');
    }


    if ($oldversion < 2026091502) {
        // 0.5.4 stores only immutable manager condition-confirmation/context events.
        // Automatic condition read-model output and stat facts remain derived/canonical; execution stays in Task.
        $table = new xmldb_table('local_kbacompany_stateevt');
        if (!$dbman->table_exists($table)) {
            $table->add_field('id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE, null);
            $table->add_field('companyuuid', XMLDB_TYPE_CHAR, '36', null, XMLDB_NOTNULL, null, null);
            $table->add_field('uuid', XMLDB_TYPE_CHAR, '36', null, XMLDB_NOTNULL, null, null);
            $table->add_field('eventkind', XMLDB_TYPE_CHAR, '12', null, XMLDB_NOTNULL, null, 'confirm');
            $table->add_field('statuuid', XMLDB_TYPE_CHAR, '36', null, XMLDB_NOTNULL, null, null);
            $table->add_field('binduuid', XMLDB_TYPE_CHAR, '36', null, XMLDB_NOTNULL, null, null);
            $table->add_field('bindrevision', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $table->add_field('periodkey', XMLDB_TYPE_CHAR, '10', null, XMLDB_NOTNULL, null, '');
            $table->add_field('conditioncode', XMLDB_TYPE_CHAR, '16', null, XMLDB_NOTNULL, null, null);
            $table->add_field('analyticcondition', XMLDB_TYPE_CHAR, '16', null, XMLDB_NOTNULL, null, '');
            $table->add_field('analyticreason', XMLDB_TYPE_CHAR, '64', null, XMLDB_NOTNULL, null, '');
            $table->add_field('comment', XMLDB_TYPE_TEXT, null, null, XMLDB_NOTNULL, null, null);
            $table->add_field('actorid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $table->add_field('timecreated', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $table->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);
            $table->add_index('uuid_uix', XMLDB_INDEX_UNIQUE, ['uuid']);
            $table->add_index('bind_ix', XMLDB_INDEX_NOTUNIQUE, ['companyuuid', 'binduuid', 'periodkey', 'timecreated']);
            $table->add_index('stat_ix', XMLDB_INDEX_NOTUNIQUE, ['companyuuid', 'statuuid', 'timecreated']);
            $table->add_index('actor_ix', XMLDB_INDEX_NOTUNIQUE, ['actorid', 'timecreated']);
            $dbman->create_table($table);
        }
        upgrade_plugin_savepoint(true, 2026091502, 'local', 'kbacompany');
    }


    if ($oldversion < 2026091503) {
        // 0.5.5 is schema-neutral: Management Exceptions is a read-model over canonical
        // indicator analytics, governance confirmations and canonical Tasks.
        upgrade_plugin_savepoint(true, 2026091503, 'local', 'kbacompany');
    }

    if ($oldversion < 2026091504) {
        // 0.5.6 is schema-neutral: Battle Plan projects canonical position indicators and
        // appends current-week facts to existing statval history without new storage objects.
        upgrade_plugin_savepoint(true, 2026091504, 'local', 'kbacompany');
    }


    if ($oldversion < 2026091505) {
        // 0.5.7 is schema-neutral: a management action from a Battle Plan indicator row
        // reuses stateevt + canonical Task + existing bplanitem linkage.
        upgrade_plugin_savepoint(true, 2026091505, 'local', 'kbacompany');
    }

    if ($oldversion < 2026091506) {
        // 0.5.8 is schema-neutral: management-action control reads canonical Task progress/result
        // and compares the exact next-week fact on-read without storing action status or causality.
        upgrade_plugin_savepoint(true, 2026091506, 'local', 'kbacompany');
    }

    if ($oldversion < 2026091507) {
        // 0.5.9 is schema-neutral: Management Exceptions projects the management cycle from
        // existing stateevt + canonical Task + Battle Plan + statval without incident/action storage.
        upgrade_plugin_savepoint(true, 2026091507, 'local', 'kbacompany');
    }

    if ($oldversion < 2026091508) {
        // 0.5.10 is schema-neutral: management overview aggregates accessible active Battle Plans,
        // canonical Tasks and position indicator exceptions on-read without summary storage.
        upgrade_plugin_savepoint(true, 2026091508, 'local', 'kbacompany');
    }

    if ($oldversion < 2026091509) {
        // 0.5.11 is schema-neutral: Battle Plan coverage and management gaps are derived
        // from factual staffing assignments, canonical weekly plans, bindings and norm history.
        upgrade_plugin_savepoint(true, 2026091509, 'local', 'kbacompany');
    }

    if ($oldversion < 2026091510) {
        // 0.5.12 is schema-neutral: management-week launch explicitly creates only selected
        // canonical weekly Battle Plans after server-side historical-assignment validation.
        upgrade_plugin_savepoint(true, 2026091510, 'local', 'kbacompany');
    }

    if ($oldversion < 2026091511) {
        // 0.5.13 is schema-neutral: management-week readiness mirrors the existing Battle Plan
        // lifecycle and exposes hard blockers/advisories on-read without a second status registry.
        upgrade_plugin_savepoint(true, 2026091511, 'local', 'kbacompany');
    }

    if ($oldversion < 2026091512) {
        // 0.5.14 is schema-neutral: selected ready Battle Plans are transitioned one by one
        // through the canonical battle_plan_service::transition() lifecycle.
        upgrade_plugin_savepoint(true, 2026091512, 'local', 'kbacompany');
    }

    if ($oldversion < 2026091513) {
        // 0.5.15 is schema-neutral: Weekly Management Acceptance is a stabilization read-model
        // over coverage, readiness, management overview and canonical integrity invariants.
        upgrade_plugin_savepoint(true, 2026091513, 'local', 'kbacompany');
    }

    if ($oldversion < 2026091514) {
        // 0.5.16 introduces the canonical BMS company/employee integration foundation.
        // Bitrix24 REST remains owned by KBA Connect; BMS persists only local company/member state,
        // provider-neutral external identity mappings and a durable outbound command queue.
        $table = new xmldb_table('local_kbacompany_company');
        if (!$dbman->table_exists($table)) {
            $table->add_field('id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE, null);
            $table->add_field('uuid', XMLDB_TYPE_CHAR, '36', null, XMLDB_NOTNULL, null, null);
            $table->add_field('name', XMLDB_TYPE_CHAR, '255', null, XMLDB_NOTNULL, null, null);
            $table->add_field('code', XMLDB_TYPE_CHAR, '100', null, XMLDB_NOTNULL, null, '');
            $table->add_field('primaryactivity', XMLDB_TYPE_TEXT, null, null, null, null, null);
            $table->add_field('foundedyear', XMLDB_TYPE_INTEGER, '4', null, XMLDB_NOTNULL, null, '0');
            $table->add_field('bmsclient', XMLDB_TYPE_INTEGER, '1', null, XMLDB_NOTNULL, null, '0');
            $table->add_field('sourcekind', XMLDB_TYPE_CHAR, '30', null, XMLDB_NOTNULL, null, 'integration');
            $table->add_field('legacycompanyid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $table->add_field('syncstate', XMLDB_TYPE_CHAR, '20', null, XMLDB_NOTNULL, null, 'synced');
            $table->add_field('active', XMLDB_TYPE_INTEGER, '1', null, XMLDB_NOTNULL, null, '1');
            $table->add_field('archivedat', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $table->add_field('createdby', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $table->add_field('modifiedby', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $table->add_field('timecreated', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $table->add_field('timemodified', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $table->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);
            $table->add_index('uuid_uix', XMLDB_INDEX_UNIQUE, ['uuid']);
            $table->add_index('legacy_ix', XMLDB_INDEX_NOTUNIQUE, ['legacycompanyid']);
            $table->add_index('active_ix', XMLDB_INDEX_NOTUNIQUE, ['active', 'bmsclient']);
            $dbman->create_table($table);
        }
        $table = new xmldb_table('local_kbacompany_member');
        if (!$dbman->table_exists($table)) {
            $table->add_field('id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE, null);
            $table->add_field('uuid', XMLDB_TYPE_CHAR, '36', null, XMLDB_NOTNULL, null, null);
            $table->add_field('companyuuid', XMLDB_TYPE_CHAR, '36', null, XMLDB_NOTNULL, null, null);
            $table->add_field('userid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
            $table->add_field('membertype', XMLDB_TYPE_CHAR, '20', null, XMLDB_NOTNULL, null, 'employee');
            $table->add_field('isemployee', XMLDB_TYPE_INTEGER, '1', null, XMLDB_NOTNULL, null, '1');
            $table->add_field('ismanager', XMLDB_TYPE_INTEGER, '1', null, XMLDB_NOTNULL, null, '0');
            $table->add_field('isdepartmentmanager', XMLDB_TYPE_INTEGER, '1', null, XMLDB_NOTNULL, null, '0');
            $table->add_field('ishr', XMLDB_TYPE_INTEGER, '1', null, XMLDB_NOTNULL, null, '0');
            $table->add_field('effectivefrom', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $table->add_field('effectiveto', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $table->add_field('sourcekind', XMLDB_TYPE_CHAR, '30', null, XMLDB_NOTNULL, null, 'bms');
            $table->add_field('active', XMLDB_TYPE_INTEGER, '1', null, XMLDB_NOTNULL, null, '1');
            $table->add_field('timecreated', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $table->add_field('timemodified', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $table->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);
            $table->add_index('uuid_uix', XMLDB_INDEX_UNIQUE, ['uuid']);
            $table->add_index('company_user_ix', XMLDB_INDEX_NOTUNIQUE, ['companyuuid', 'userid', 'active']);
            $table->add_index('user_ix', XMLDB_INDEX_NOTUNIQUE, ['userid', 'active']);
            $table->add_index('effective_ix', XMLDB_INDEX_NOTUNIQUE, ['companyuuid', 'effectivefrom', 'effectiveto', 'active']);
            $dbman->create_table($table);
        }
        $table = new xmldb_table('local_kbacompany_extmap');
        if (!$dbman->table_exists($table)) {
            $table->add_field('id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE, null);
            $table->add_field('provider', XMLDB_TYPE_CHAR, '50', null, XMLDB_NOTNULL, null, null);
            $table->add_field('entitykind', XMLDB_TYPE_CHAR, '20', null, XMLDB_NOTNULL, null, null);
            $table->add_field('localref', XMLDB_TYPE_CHAR, '100', null, XMLDB_NOTNULL, null, null);
            $table->add_field('externalid', XMLDB_TYPE_CHAR, '100', null, XMLDB_NOTNULL, null, null);
            $table->add_field('active', XMLDB_TYPE_INTEGER, '1', null, XMLDB_NOTNULL, null, '1');
            $table->add_field('timecreated', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $table->add_field('timemodified', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $table->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);
            $table->add_index('external_uix', XMLDB_INDEX_UNIQUE, ['provider', 'entitykind', 'externalid']);
            $table->add_index('local_uix', XMLDB_INDEX_UNIQUE, ['provider', 'entitykind', 'localref']);
            $dbman->create_table($table);
        }
        $table = new xmldb_table('local_kbacompany_syncout');
        if (!$dbman->table_exists($table)) {
            $table->add_field('id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE, null);
            $table->add_field('commanduuid', XMLDB_TYPE_CHAR, '36', null, XMLDB_NOTNULL, null, null);
            $table->add_field('companyuuid', XMLDB_TYPE_CHAR, '36', null, XMLDB_NOTNULL, null, null);
            $table->add_field('userid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $table->add_field('commandkind', XMLDB_TYPE_CHAR, '30', null, XMLDB_NOTNULL, null, null);
            $table->add_field('payload', XMLDB_TYPE_TEXT, null, null, XMLDB_NOTNULL, null, null);
            $table->add_field('status', XMLDB_TYPE_CHAR, '20', null, XMLDB_NOTNULL, null, 'pending');
            $table->add_field('attempts', XMLDB_TYPE_INTEGER, '6', null, XMLDB_NOTNULL, null, '0');
            $table->add_field('lasterror', XMLDB_TYPE_TEXT, null, null, null, null, null);
            $table->add_field('timecreated', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $table->add_field('timemodified', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $table->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);
            $table->add_index('command_uix', XMLDB_INDEX_UNIQUE, ['commanduuid']);
            $table->add_index('status_ix', XMLDB_INDEX_NOTUNIQUE, ['status', 'timecreated']);
            $table->add_index('company_ix', XMLDB_INDEX_NOTUNIQUE, ['companyuuid', 'status']);
            $table->add_index('user_ix', XMLDB_INDEX_NOTUNIQUE, ['userid', 'status']);
            $dbman->create_table($table);
        }
        upgrade_plugin_savepoint(true, 2026091514, 'local', 'kbacompany');
    }

    if ($oldversion < 2026091515) {
        // 0.5.16.1 is schema-neutral: canonical company views normalise Moodle DB false
        // (no membership record) to null before strict role projection.
        upgrade_plugin_savepoint(true, 2026091515, 'local', 'kbacompany');
    }

    if ($oldversion < 2026091516) {
        // 0.5.16.2 is schema-neutral: duplicate integration companies are safely
        // reconciled to the legacy-preserved Company UUID when KBA Connect supplies
        // an explicit Corporate company identity. No business history is deleted.
        upgrade_plugin_savepoint(true, 2026091516, 'local', 'kbacompany');
    }

    if ($oldversion < 2026091517) {
        // 0.5.16.3 is schema-neutral: explicit BMS employee phone input updates
        // Moodle phone1 and email-like legacy values are never queued as phone data.
        upgrade_plugin_savepoint(true, 2026091517, 'local', 'kbacompany');
    }

    if ($oldversion < 2026091518) {
        // 0.5.17 is schema-neutral: employee CRM record/view/edit/remove UX reuses canonical
        // Moodle users, BMS memberships and the existing integration outbox.
        upgrade_plugin_savepoint(true, 2026091518, 'local', 'kbacompany');
    }

    if ($oldversion < 2026091519) {
        // Final 0.5.17 hardening is schema-neutral: confirmed removal only, Moodle email policy,
        // complete employee record metadata, and bridge-task-aware integration diagnostics.
        upgrade_plugin_savepoint(true, 2026091519, 'local', 'kbacompany');
    }


    if ($oldversion < 2026091520) {
        // 0.6.0 Financial Model foundation: versioned planning scenarios and financial
        // responsibility centres linked to canonical OFS targets. No BDR/BDDS/budget facts yet.
        $table = new xmldb_table('local_kbacompany_finscenario');
        if (!$dbman->table_exists($table)) {
            $table->add_field('id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE, null);
            $table->add_field('companyuuid', XMLDB_TYPE_CHAR, '36', null, XMLDB_NOTNULL, null, null);
            $table->add_field('uuid', XMLDB_TYPE_CHAR, '36', null, XMLDB_NOTNULL, null, null);
            $table->add_field('revision', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '1');
            $table->add_field('status', XMLDB_TYPE_CHAR, '12', null, XMLDB_NOTNULL, null, 'draft');
            $table->add_field('code', XMLDB_TYPE_CHAR, '50', null, XMLDB_NOTNULL, null, null);
            $table->add_field('name', XMLDB_TYPE_CHAR, '255', null, XMLDB_NOTNULL, null, null);
            $table->add_field('scenariotype', XMLDB_TYPE_CHAR, '12', null, XMLDB_NOTNULL, null, 'base');
            $table->add_field('periodstart', XMLDB_TYPE_CHAR, '10', null, XMLDB_NOTNULL, null, null);
            $table->add_field('periodend', XMLDB_TYPE_CHAR, '10', null, XMLDB_NOTNULL, null, null);
            $table->add_field('currency', XMLDB_TYPE_CHAR, '3', null, XMLDB_NOTNULL, null, 'RUB');
            $table->add_field('description', XMLDB_TYPE_TEXT, null, null, null, null, null);
            $table->add_field('assumptions', XMLDB_TYPE_TEXT, null, null, null, null, null);
            $table->add_field('strategyuuid', XMLDB_TYPE_CHAR, '36', null, XMLDB_NOTNULL, null, '');
            $table->add_field('strategyrevision', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $table->add_field('businessmodeluuid', XMLDB_TYPE_CHAR, '36', null, XMLDB_NOTNULL, null, '');
            $table->add_field('businessmodelrevision', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $table->add_field('createdby', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $table->add_field('modifiedby', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $table->add_field('timecreated', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $table->add_field('timemodified', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $table->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);
            $table->add_index('revision_uix', XMLDB_INDEX_UNIQUE, ['companyuuid', 'uuid', 'revision']);
            $table->add_index('code_ix', XMLDB_INDEX_NOTUNIQUE, ['companyuuid', 'code', 'status']);
            $table->add_index('type_ix', XMLDB_INDEX_NOTUNIQUE, ['companyuuid', 'scenariotype', 'status']);
            $dbman->create_table($table);
        }

        $table = new xmldb_table('local_kbacompany_fincenter');
        if (!$dbman->table_exists($table)) {
            $table->add_field('id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE, null);
            $table->add_field('companyuuid', XMLDB_TYPE_CHAR, '36', null, XMLDB_NOTNULL, null, null);
            $table->add_field('uuid', XMLDB_TYPE_CHAR, '36', null, XMLDB_NOTNULL, null, null);
            $table->add_field('revision', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '1');
            $table->add_field('status', XMLDB_TYPE_CHAR, '12', null, XMLDB_NOTNULL, null, 'draft');
            $table->add_field('code', XMLDB_TYPE_CHAR, '50', null, XMLDB_NOTNULL, null, null);
            $table->add_field('name', XMLDB_TYPE_CHAR, '255', null, XMLDB_NOTNULL, null, null);
            $table->add_field('centertype', XMLDB_TYPE_CHAR, '12', null, XMLDB_NOTNULL, null, 'profit');
            $table->add_field('targettype', XMLDB_TYPE_CHAR, '12', null, XMLDB_NOTNULL, null, null);
            $table->add_field('targetuuid', XMLDB_TYPE_CHAR, '36', null, XMLDB_NOTNULL, null, null);
            $table->add_field('ownerpositionuuid', XMLDB_TYPE_CHAR, '36', null, XMLDB_NOTNULL, null, '');
            $table->add_field('mainstatuuid', XMLDB_TYPE_CHAR, '36', null, XMLDB_NOTNULL, null, '');
            $table->add_field('resultdescription', XMLDB_TYPE_TEXT, null, null, null, null, null);
            $table->add_field('authorityrule', XMLDB_TYPE_TEXT, null, null, null, null, null);
            $table->add_field('reviewperiod', XMLDB_TYPE_CHAR, '12', null, XMLDB_NOTNULL, null, 'monthly');
            $table->add_field('createdby', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $table->add_field('modifiedby', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $table->add_field('timecreated', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $table->add_field('timemodified', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $table->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);
            $table->add_index('revision_uix', XMLDB_INDEX_UNIQUE, ['companyuuid', 'uuid', 'revision']);
            $table->add_index('code_ix', XMLDB_INDEX_NOTUNIQUE, ['companyuuid', 'code', 'status']);
            $table->add_index('target_ix', XMLDB_INDEX_NOTUNIQUE,
                ['companyuuid', 'targettype', 'targetuuid', 'status']);
            $table->add_index('type_ix', XMLDB_INDEX_NOTUNIQUE, ['companyuuid', 'centertype', 'status']);
            $dbman->create_table($table);
        }
        upgrade_plugin_savepoint(true, 2026091520, 'local', 'kbacompany');
    }


    if ($oldversion < 2026091521) {
        // 0.6.1 adds versioned planned BDR, BDDS and forecast-balance statements over
        // published Financial Scenario / optional Financial Responsibility Centre revisions.
        // No accounting actuals, bank movements, payment execution or ERP facts are imported.
        $table = new xmldb_table('local_kbacompany_finstmt');
        if (!$dbman->table_exists($table)) {
            $table->add_field('id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE, null);
            $table->add_field('companyuuid', XMLDB_TYPE_CHAR, '36', null, XMLDB_NOTNULL, null, null);
            $table->add_field('uuid', XMLDB_TYPE_CHAR, '36', null, XMLDB_NOTNULL, null, null);
            $table->add_field('revision', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '1');
            $table->add_field('status', XMLDB_TYPE_CHAR, '12', null, XMLDB_NOTNULL, null, 'draft');
            $table->add_field('code', XMLDB_TYPE_CHAR, '50', null, XMLDB_NOTNULL, null, null);
            $table->add_field('name', XMLDB_TYPE_CHAR, '255', null, XMLDB_NOTNULL, null, null);
            $table->add_field('statementtype', XMLDB_TYPE_CHAR, '12', null, XMLDB_NOTNULL, null, null);
            $table->add_field('scenariouuid', XMLDB_TYPE_CHAR, '36', null, XMLDB_NOTNULL, null, null);
            $table->add_field('scenariorevision', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $table->add_field('centeruuid', XMLDB_TYPE_CHAR, '36', null, XMLDB_NOTNULL, null, '');
            $table->add_field('centerrevision', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $table->add_field('periodstart', XMLDB_TYPE_CHAR, '10', null, XMLDB_NOTNULL, null, null);
            $table->add_field('periodend', XMLDB_TYPE_CHAR, '10', null, XMLDB_NOTNULL, null, null);
            $table->add_field('currency', XMLDB_TYPE_CHAR, '3', null, XMLDB_NOTNULL, null, 'RUB');
            $table->add_field('sourceinfo', XMLDB_TYPE_TEXT, null, null, null, null, null);
            $table->add_field('notes', XMLDB_TYPE_TEXT, null, null, null, null, null);
            $table->add_field('createdby', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $table->add_field('modifiedby', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $table->add_field('timecreated', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $table->add_field('timemodified', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $table->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);
            $table->add_index('revision_uix', XMLDB_INDEX_UNIQUE, ['companyuuid', 'uuid', 'revision']);
            $table->add_index('code_ix', XMLDB_INDEX_NOTUNIQUE, ['companyuuid', 'code', 'status']);
            $table->add_index('scenario_ix', XMLDB_INDEX_NOTUNIQUE,
                ['companyuuid', 'scenariouuid', 'scenariorevision', 'status']);
            $table->add_index('type_ix', XMLDB_INDEX_NOTUNIQUE, ['companyuuid', 'statementtype', 'status']);
            $table->add_index('center_ix', XMLDB_INDEX_NOTUNIQUE, ['companyuuid', 'centeruuid', 'status']);
            $dbman->create_table($table);
        }

        $table = new xmldb_table('local_kbacompany_finline');
        if (!$dbman->table_exists($table)) {
            $table->add_field('id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE, null);
            $table->add_field('companyuuid', XMLDB_TYPE_CHAR, '36', null, XMLDB_NOTNULL, null, null);
            $table->add_field('statementuuid', XMLDB_TYPE_CHAR, '36', null, XMLDB_NOTNULL, null, null);
            $table->add_field('statementrevision', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '1');
            $table->add_field('linecode', XMLDB_TYPE_CHAR, '32', null, XMLDB_NOTNULL, null, null);
            $table->add_field('bucketno', XMLDB_TYPE_INTEGER, '4', null, XMLDB_NOTNULL, null, '1');
            $table->add_field('amount', XMLDB_TYPE_NUMBER, '18', '2', XMLDB_NOTNULL, null, '0');
            $table->add_field('sourceinfo', XMLDB_TYPE_TEXT, null, null, null, null, null);
            $table->add_field('createdby', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $table->add_field('timecreated', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $table->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);
            $table->add_index('cell_uix', XMLDB_INDEX_UNIQUE,
                ['companyuuid', 'statementuuid', 'statementrevision', 'linecode', 'bucketno']);
            $table->add_index('statement_ix', XMLDB_INDEX_NOTUNIQUE,
                ['companyuuid', 'statementuuid', 'statementrevision']);
            $dbman->create_table($table);
        }

        upgrade_plugin_savepoint(true, 2026091521, 'local', 'kbacompany');
    }


    if ($oldversion < 2026091522) {
        // 0.6.2 Budgeting: versioned budgets for goals/programs/projects/org units,
        // immutable budget lines, explicit approval lifecycle and aggregate plan/fact snapshots.
        // No ledger postings, bank transactions, invoices or payment execution are created.
        $table = new xmldb_table('local_kbacompany_finbudget');
        if (!$dbman->table_exists($table)) {
            $table->add_field('id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE, null);
            $table->add_field('companyuuid', XMLDB_TYPE_CHAR, '36', null, XMLDB_NOTNULL, null, null);
            $table->add_field('uuid', XMLDB_TYPE_CHAR, '36', null, XMLDB_NOTNULL, null, null);
            $table->add_field('revision', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '1');
            $table->add_field('status', XMLDB_TYPE_CHAR, '12', null, XMLDB_NOTNULL, null, 'draft');
            $table->add_field('code', XMLDB_TYPE_CHAR, '50', null, XMLDB_NOTNULL, null, null);
            $table->add_field('name', XMLDB_TYPE_CHAR, '255', null, XMLDB_NOTNULL, null, null);
            $table->add_field('budgettype', XMLDB_TYPE_CHAR, '12', null, XMLDB_NOTNULL, null, null);
            $table->add_field('scenariouuid', XMLDB_TYPE_CHAR, '36', null, XMLDB_NOTNULL, null, null);
            $table->add_field('scenariorevision', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $table->add_field('centeruuid', XMLDB_TYPE_CHAR, '36', null, XMLDB_NOTNULL, null, '');
            $table->add_field('centerrevision', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $table->add_field('targetuuid', XMLDB_TYPE_CHAR, '36', null, XMLDB_NOTNULL, null, null);
            $table->add_field('targetrevision', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $table->add_field('periodstart', XMLDB_TYPE_CHAR, '10', null, XMLDB_NOTNULL, null, null);
            $table->add_field('periodend', XMLDB_TYPE_CHAR, '10', null, XMLDB_NOTNULL, null, null);
            $table->add_field('currency', XMLDB_TYPE_CHAR, '3', null, XMLDB_NOTNULL, null, 'RUB');
            $table->add_field('approvalrule', XMLDB_TYPE_TEXT, null, null, null, null, null);
            $table->add_field('notes', XMLDB_TYPE_TEXT, null, null, null, null, null);
            $table->add_field('submittedby', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $table->add_field('approvedby', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $table->add_field('decisionnote', XMLDB_TYPE_TEXT, null, null, null, null, null);
            $table->add_field('createdby', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $table->add_field('modifiedby', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $table->add_field('timecreated', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $table->add_field('timemodified', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $table->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);
            $table->add_index('revision_uix', XMLDB_INDEX_UNIQUE, ['companyuuid', 'uuid', 'revision']);
            $table->add_index('code_ix', XMLDB_INDEX_NOTUNIQUE, ['companyuuid', 'code', 'status']);
            $table->add_index('scenario_ix', XMLDB_INDEX_NOTUNIQUE,
                ['companyuuid', 'scenariouuid', 'scenariorevision', 'status']);
            $table->add_index('target_ix', XMLDB_INDEX_NOTUNIQUE,
                ['companyuuid', 'budgettype', 'targetuuid', 'status']);
            $table->add_index('center_ix', XMLDB_INDEX_NOTUNIQUE, ['companyuuid', 'centeruuid', 'status']);
            $dbman->create_table($table);
        }

        $table = new xmldb_table('local_kbacompany_finbudline');
        if (!$dbman->table_exists($table)) {
            $table->add_field('id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE, null);
            $table->add_field('companyuuid', XMLDB_TYPE_CHAR, '36', null, XMLDB_NOTNULL, null, null);
            $table->add_field('budgetuuid', XMLDB_TYPE_CHAR, '36', null, XMLDB_NOTNULL, null, null);
            $table->add_field('budgetrevision', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '1');
            $table->add_field('linecode', XMLDB_TYPE_CHAR, '50', null, XMLDB_NOTNULL, null, null);
            $table->add_field('name', XMLDB_TYPE_CHAR, '255', null, XMLDB_NOTNULL, null, null);
            $table->add_field('linetype', XMLDB_TYPE_CHAR, '16', null, XMLDB_NOTNULL, null, null);
            $table->add_field('periodstart', XMLDB_TYPE_CHAR, '10', null, XMLDB_NOTNULL, null, null);
            $table->add_field('periodend', XMLDB_TYPE_CHAR, '10', null, XMLDB_NOTNULL, null, null);
            $table->add_field('planamount', XMLDB_TYPE_NUMBER, '18', '2', XMLDB_NOTNULL, null, '0');
            $table->add_field('limitamount', XMLDB_TYPE_NUMBER, '18', '2', XMLDB_NOTNULL, null, '0');
            $table->add_field('ownerpositionuuid', XMLDB_TYPE_CHAR, '36', null, XMLDB_NOTNULL, null, '');
            $table->add_field('indicatoruuid', XMLDB_TYPE_CHAR, '36', null, XMLDB_NOTNULL, null, '');
            $table->add_field('sourceinfo', XMLDB_TYPE_TEXT, null, null, null, null, null);
            $table->add_field('createdby', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $table->add_field('timecreated', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $table->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);
            $table->add_index('line_uix', XMLDB_INDEX_UNIQUE,
                ['companyuuid', 'budgetuuid', 'budgetrevision', 'linecode']);
            $table->add_index('budget_ix', XMLDB_INDEX_NOTUNIQUE,
                ['companyuuid', 'budgetuuid', 'budgetrevision']);
            $table->add_index('owner_ix', XMLDB_INDEX_NOTUNIQUE, ['companyuuid', 'ownerpositionuuid']);
            $dbman->create_table($table);
        }

        $table = new xmldb_table('local_kbacompany_finbudfact');
        if (!$dbman->table_exists($table)) {
            $table->add_field('id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE, null);
            $table->add_field('companyuuid', XMLDB_TYPE_CHAR, '36', null, XMLDB_NOTNULL, null, null);
            $table->add_field('budgetuuid', XMLDB_TYPE_CHAR, '36', null, XMLDB_NOTNULL, null, null);
            $table->add_field('budgetrevision', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '1');
            $table->add_field('linecode', XMLDB_TYPE_CHAR, '50', null, XMLDB_NOTNULL, null, null);
            $table->add_field('asofdate', XMLDB_TYPE_CHAR, '10', null, XMLDB_NOTNULL, null, null);
            $table->add_field('actualamount', XMLDB_TYPE_NUMBER, '18', '2', XMLDB_NOTNULL, null, '0');
            $table->add_field('sourcekind', XMLDB_TYPE_CHAR, '20', null, XMLDB_NOTNULL, null, 'manual');
            $table->add_field('sourceinfo', XMLDB_TYPE_TEXT, null, null, null, null, null);
            $table->add_field('reportedby', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $table->add_field('timecreated', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $table->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);
            $table->add_index('snapshot_ix', XMLDB_INDEX_NOTUNIQUE,
                ['companyuuid', 'budgetuuid', 'budgetrevision', 'linecode', 'asofdate']);
            $table->add_index('budget_ix', XMLDB_INDEX_NOTUNIQUE,
                ['companyuuid', 'budgetuuid', 'budgetrevision']);
            $table->add_index('reporter_ix', XMLDB_INDEX_NOTUNIQUE, ['reportedby', 'timecreated']);
            $dbman->create_table($table);
        }

        upgrade_plugin_savepoint(true, 2026091522, 'local', 'kbacompany');
    }


    if ($oldversion < 2026091523) {
        $table = new xmldb_table('local_kbacompany_resgate');
        if (!$dbman->table_exists($table)) {
            $table->add_field('id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE, null);
            $table->add_field('companyuuid', XMLDB_TYPE_CHAR, '36', null, XMLDB_NOTNULL, null, null);
            $table->add_field('uuid', XMLDB_TYPE_CHAR, '36', null, XMLDB_NOTNULL, null, null);
            $table->add_field('revision', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '1');
            $table->add_field('status', XMLDB_TYPE_CHAR, '12', null, XMLDB_NOTNULL, null, 'draft');
            $table->add_field('code', XMLDB_TYPE_CHAR, '50', null, XMLDB_NOTNULL, null, null);
            $table->add_field('name', XMLDB_TYPE_CHAR, '255', null, XMLDB_NOTNULL, null, null);
            $table->add_field('targettype', XMLDB_TYPE_CHAR, '12', null, XMLDB_NOTNULL, null, null);
            $table->add_field('targetuuid', XMLDB_TYPE_CHAR, '36', null, XMLDB_NOTNULL, null, null);
            $table->add_field('targetrevision', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $table->add_field('scenariouuid', XMLDB_TYPE_CHAR, '36', null, XMLDB_NOTNULL, null, '');
            $table->add_field('scenariorevision', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $table->add_field('plannedstart', XMLDB_TYPE_CHAR, '10', null, XMLDB_NOTNULL, null, '');
            $table->add_field('ownerpositionuuid', XMLDB_TYPE_CHAR, '36', null, XMLDB_NOTNULL, null, '');
            $table->add_field('submittedby', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $table->add_field('decidedby', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $table->add_field('decisionnote', XMLDB_TYPE_TEXT, null, null, null, null, null);
            $table->add_field('createdby', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $table->add_field('modifiedby', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $table->add_field('timecreated', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $table->add_field('timemodified', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $table->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);
            $table->add_index('revision_uix', XMLDB_INDEX_UNIQUE, ['companyuuid', 'uuid', 'revision']);
            $table->add_index('code_ix', XMLDB_INDEX_NOTUNIQUE, ['companyuuid', 'code', 'status']);
            $table->add_index('target_ix', XMLDB_INDEX_NOTUNIQUE, ['companyuuid', 'targettype', 'targetuuid', 'status']);
            $table->add_index('scenario_ix', XMLDB_INDEX_NOTUNIQUE, ['companyuuid', 'scenariouuid', 'scenariorevision', 'status']);
            $dbman->create_table($table);
        }

        $table = new xmldb_table('local_kbacompany_resitem');
        if (!$dbman->table_exists($table)) {
            $table->add_field('id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE, null);
            $table->add_field('companyuuid', XMLDB_TYPE_CHAR, '36', null, XMLDB_NOTNULL, null, null);
            $table->add_field('gateuuid', XMLDB_TYPE_CHAR, '36', null, XMLDB_NOTNULL, null, null);
            $table->add_field('gaterevision', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '1');
            $table->add_field('itemcode', XMLDB_TYPE_CHAR, '50', null, XMLDB_NOTNULL, null, null);
            $table->add_field('resourcekind', XMLDB_TYPE_CHAR, '20', null, XMLDB_NOTNULL, null, null);
            $table->add_field('name', XMLDB_TYPE_CHAR, '255', null, XMLDB_NOTNULL, null, null);
            $table->add_field('required', XMLDB_TYPE_INTEGER, '1', null, XMLDB_NOTNULL, null, '1');
            $table->add_field('status', XMLDB_TYPE_CHAR, '12', null, XMLDB_NOTNULL, null, 'pending');
            $table->add_field('referencekind', XMLDB_TYPE_CHAR, '20', null, XMLDB_NOTNULL, null, 'none');
            $table->add_field('referenceuuid', XMLDB_TYPE_CHAR, '36', null, XMLDB_NOTNULL, null, '');
            $table->add_field('referencerevision', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $table->add_field('expected', XMLDB_TYPE_TEXT, null, null, null, null, null);
            $table->add_field('available', XMLDB_TYPE_TEXT, null, null, null, null, null);
            $table->add_field('gap', XMLDB_TYPE_TEXT, null, null, null, null, null);
            $table->add_field('evidence', XMLDB_TYPE_TEXT, null, null, null, null, null);
            $table->add_field('evidenceurl', XMLDB_TYPE_TEXT, null, null, null, null, null);
            $table->add_field('ownerpositionuuid', XMLDB_TYPE_CHAR, '36', null, XMLDB_NOTNULL, null, '');
            $table->add_field('duedate', XMLDB_TYPE_CHAR, '10', null, XMLDB_NOTNULL, null, '');
            $table->add_field('createdby', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $table->add_field('timecreated', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $table->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);
            $table->add_index('item_uix', XMLDB_INDEX_UNIQUE, ['companyuuid', 'gateuuid', 'gaterevision', 'itemcode']);
            $table->add_index('gate_ix', XMLDB_INDEX_NOTUNIQUE, ['companyuuid', 'gateuuid', 'gaterevision']);
            $table->add_index('kind_ix', XMLDB_INDEX_NOTUNIQUE, ['companyuuid', 'resourcekind', 'status']);
            $table->add_index('owner_ix', XMLDB_INDEX_NOTUNIQUE, ['companyuuid', 'ownerpositionuuid']);
            $dbman->create_table($table);
        }
        upgrade_plugin_savepoint(true, 2026091523, 'local', 'kbacompany');
    }


    if ($oldversion < 2026092125) {
        // v0.6.4 introduces only the local projection required to bind a canonical
        // BMS Company UUID to an external BMS Cloud Workspace. Existing companies
        // remain unmapped and therefore legacy-compatible; no entitlement is revoked.
        $table = new xmldb_table('local_kbacompany_cloudmap');
        if (!$dbman->table_exists($table)) {
            $table->add_field('id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE, null);
            $table->add_field('companyuuid', XMLDB_TYPE_CHAR, '36', null, XMLDB_NOTNULL, null, null);
            $table->add_field('workspaceuuid', XMLDB_TYPE_CHAR, '36', null, XMLDB_NOTNULL, null, null);
            $table->add_field('cloudstatus', XMLDB_TYPE_CHAR, '20', null, XMLDB_NOTNULL, null, 'active');
            $table->add_field('entitlementmode', XMLDB_TYPE_CHAR, '24', null,
                XMLDB_NOTNULL, null, 'legacy_compatibility');
            $table->add_field('snapshotrevision', XMLDB_TYPE_CHAR, '100', null, XMLDB_NOTNULL, null, '');
            $table->add_field('lastsync', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $table->add_field('createdby', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $table->add_field('modifiedby', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $table->add_field('timecreated', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $table->add_field('timemodified', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $table->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);
            $table->add_index('company_uix', XMLDB_INDEX_UNIQUE, ['companyuuid']);
            $table->add_index('workspace_uix', XMLDB_INDEX_UNIQUE, ['workspaceuuid']);
            $table->add_index('status_ix', XMLDB_INDEX_NOTUNIQUE, ['cloudstatus', 'entitlementmode']);
            $dbman->create_table($table);
        }
        upgrade_plugin_savepoint(true, 2026092125, 'local', 'kbacompany');
    }

    if ($oldversion < 2026092127) {
        // v0.6.6 adds a provider-neutral durable event outbox. The legacy syncout
        // command table remains untouched so KBA Connect keeps its current contract.
        $table = new xmldb_table('local_kbacompany_eventout');
        if (!$dbman->table_exists($table)) {
            $table->add_field('id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE, null);
            $table->add_field('eventuuid', XMLDB_TYPE_CHAR, '36', null, XMLDB_NOTNULL, null, null);
            $table->add_field('companyuuid', XMLDB_TYPE_CHAR, '36', null, XMLDB_NOTNULL, null, null);
            $table->add_field('eventtype', XMLDB_TYPE_CHAR, '100', null, XMLDB_NOTNULL, null, null);
            $table->add_field('entitytype', XMLDB_TYPE_CHAR, '40', null, XMLDB_NOTNULL, null, null);
            $table->add_field('entityuuid', XMLDB_TYPE_CHAR, '36', null, XMLDB_NOTNULL, null, null);
            $table->add_field('entityrevision', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $table->add_field('idempotencykey', XMLDB_TYPE_CHAR, '64', null, XMLDB_NOTNULL, null, null);
            $table->add_field('correlationid', XMLDB_TYPE_CHAR, '100', null, XMLDB_NOTNULL, null, '');
            $table->add_field('sourcecommanduuid', XMLDB_TYPE_CHAR, '36', null, XMLDB_NOTNULL, null, '');
            $table->add_field('envelope', XMLDB_TYPE_TEXT, null, null, XMLDB_NOTNULL, null, null);
            $table->add_field('status', XMLDB_TYPE_CHAR, '20', null, XMLDB_NOTNULL, null, 'pending');
            $table->add_field('attempts', XMLDB_TYPE_INTEGER, '6', null, XMLDB_NOTNULL, null, '0');
            $table->add_field('lasterror', XMLDB_TYPE_TEXT, null, null, null, null, null);
            $table->add_field('timecreated', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $table->add_field('timemodified', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $table->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);
            $table->add_index('event_uix', XMLDB_INDEX_UNIQUE, ['eventuuid']);
            $table->add_index('idempotency_uix', XMLDB_INDEX_UNIQUE, ['idempotencykey']);
            $table->add_index('status_ix', XMLDB_INDEX_NOTUNIQUE, ['status', 'timecreated']);
            $table->add_index('company_ix', XMLDB_INDEX_NOTUNIQUE, ['companyuuid', 'status']);
            $table->add_index('eventtype_ix', XMLDB_INDEX_NOTUNIQUE, ['eventtype', 'status']);
            $table->add_index('sourcecommand_ix', XMLDB_INDEX_NOTUNIQUE, ['sourcecommanduuid']);
            $dbman->create_table($table);
        }
        upgrade_plugin_savepoint(true, 2026092127, 'local', 'kbacompany');
    }


    if ($oldversion < 2026092128) {
        // v0.6.7 adds a validated local last-known-good entitlement snapshot cache.
        // Existing companies stay in legacy_compatibility mode; creating this table
        // never revokes access or enables Cloud enforcement automatically.
        $table = new xmldb_table('local_kbacompany_entsnap');
        if (!$dbman->table_exists($table)) {
            $table->add_field('id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE, null);
            $table->add_field('companyuuid', XMLDB_TYPE_CHAR, '36', null, XMLDB_NOTNULL, null, null);
            $table->add_field('workspaceuuid', XMLDB_TYPE_CHAR, '36', null, XMLDB_NOTNULL, null, null);
            $table->add_field('snapshotrevision', XMLDB_TYPE_CHAR, '100', null, XMLDB_NOTNULL, null, null);
            $table->add_field('catalogversion', XMLDB_TYPE_CHAR, '50', null, XMLDB_NOTNULL, null, null);
            $table->add_field('resolverversion', XMLDB_TYPE_CHAR, '50', null, XMLDB_NOTNULL, null, '');
            $table->add_field('generatedat', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $table->add_field('validuntil', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $table->add_field('snapshotjson', XMLDB_TYPE_TEXT, null, null, XMLDB_NOTNULL, null, null);
            $table->add_field('snapshothash', XMLDB_TYPE_CHAR, '64', null, XMLDB_NOTNULL, null, null);
            $table->add_field('status', XMLDB_TYPE_CHAR, '20', null, XMLDB_NOTNULL, null, 'active');
            $table->add_field('receivedat', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $table->add_field('activatedat', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $table->add_field('supersededat', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $table->add_field('createdby', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $table->add_field('timecreated', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $table->add_field('timemodified', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $table->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);
            $table->add_index('workspace_revision_uix', XMLDB_INDEX_UNIQUE, ['workspaceuuid', 'snapshotrevision']);
            $table->add_index('company_status_ix', XMLDB_INDEX_NOTUNIQUE, ['companyuuid', 'status']);
            $table->add_index('workspace_status_ix', XMLDB_INDEX_NOTUNIQUE, ['workspaceuuid', 'status']);
            $table->add_index('validuntil_ix', XMLDB_INDEX_NOTUNIQUE, ['validuntil', 'status']);
            $table->add_index('hash_ix', XMLDB_INDEX_NOTUNIQUE, ['snapshothash']);
            $dbman->create_table($table);
        }
        upgrade_plugin_savepoint(true, 2026092128, 'local', 'kbacompany');
    }


    if ($oldversion < 2026092129) {
        // v0.6.8 hardens the Company↔Workspace projection and adds provider-neutral
        // runtime diagnostics for ERP application state and integration health.
        $table = new xmldb_table('local_kbacompany_cloudmap');
        if ($dbman->table_exists($table)) {
            $field = new xmldb_field('cloudrevision', XMLDB_TYPE_INTEGER, '10', null,
                XMLDB_NOTNULL, null, '0', 'snapshotrevision');
            if (!$dbman->field_exists($table, $field)) {
                $dbman->add_field($table, $field);
            }
            $field = new xmldb_field('erpappstate', XMLDB_TYPE_CHAR, '24', null,
                XMLDB_NOTNULL, null, 'not_provisioned', 'cloudrevision');
            if (!$dbman->field_exists($table, $field)) {
                $dbman->add_field($table, $field);
            }
            $field = new xmldb_field('integrationhealth', XMLDB_TYPE_CHAR, '20', null,
                XMLDB_NOTNULL, null, 'unknown', 'erpappstate');
            if (!$dbman->field_exists($table, $field)) {
                $dbman->add_field($table, $field);
            }
            $index = new xmldb_index('runtime_ix', XMLDB_INDEX_NOTUNIQUE, ['erpappstate', 'integrationhealth']);
            if (!$dbman->index_exists($table, $index)) {
                $dbman->add_index($table, $index);
            }
        }
        upgrade_plugin_savepoint(true, 2026092129, 'local', 'kbacompany');
    }


    if ($oldversion < 2026092130) {
        // v0.6.9 is an architecture stabilization gate. No schema mutation is
        // required: the release validates the 0.6.4-0.6.8 platform contracts
        // and exposes a deterministic Pre-0.7 readiness diagnostic.
        upgrade_plugin_savepoint(true, 2026092130, 'local', 'kbacompany');
    }

    if ($oldversion < 2026092131) {
        // v0.6.9.1 fixes Contact -> existing Moodle User display projection.
        // No schema change: Company identity remains BMS membership; Moodle
        // institution is only a safe display projection and phone1 is filled
        // only when empty or obviously invalid.
        upgrade_plugin_savepoint(true, 2026092131, 'local', 'kbacompany');
    }

    if ($oldversion < 2026092132) {
        // v0.6.9.2 is schema-neutral: employee position is a projection into an existing Moodle custom profile field.
        upgrade_plugin_savepoint(true, 2026092132, 'local', 'kbacompany');
    }

    if ($oldversion < 2026092133) {
        // v0.6.9.3 adds the BMS learning-contour entry and read-only aggregation only.
        // KBA Corporate and Moodle Core remain the learning sources of truth; no schema changes.
        upgrade_plugin_savepoint(true, 2026092133, 'local', 'kbacompany');
    }

    if ($oldversion < 2026092134) {
        // v0.6.9.4 adds company-scoped, append-only consultant review and comment events
        // for the private owner-intent workflow. Owner answers remain in versioned ownerframe records.
        $table = new xmldb_table('local_kbacompany_ownerfb');
        if (!$dbman->table_exists($table)) {
            $table->add_field('id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE, null);
            $table->add_field('companyuuid', XMLDB_TYPE_CHAR, '36', null, XMLDB_NOTNULL, null, null);
            $table->add_field('frameuuid', XMLDB_TYPE_CHAR, '36', null, XMLDB_NOTNULL, null, null);
            $table->add_field('framerevision', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $table->add_field('sectionkey', XMLDB_TYPE_CHAR, '32', null, XMLDB_NOTNULL, null, null);
            $table->add_field('action', XMLDB_TYPE_CHAR, '16', null, XMLDB_NOTNULL, null, null);
            $table->add_field('contenthash', XMLDB_TYPE_CHAR, '64', null, XMLDB_NOTNULL, null, null);
            $table->add_field('authoruserid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $table->add_field('comment', XMLDB_TYPE_TEXT, null, null, null, null, null);
            $table->add_field('timecreated', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $table->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);
            $table->add_index('frame_ix', XMLDB_INDEX_NOTUNIQUE, ['companyuuid', 'frameuuid', 'framerevision']);
            $table->add_index('section_ix', XMLDB_INDEX_NOTUNIQUE, ['companyuuid', 'frameuuid', 'sectionkey']);
            $table->add_index('author_ix', XMLDB_INDEX_NOTUNIQUE, ['authoruserid', 'timecreated']);
            $dbman->create_table($table);
        }
        upgrade_plugin_savepoint(true, 2026092134, 'local', 'kbacompany');
    }


    if ($oldversion < 2026092135) {
        // v0.6.9.5 extends the existing versioned Administrative Scale model to match the
        // methodology more closely. Plan keeps explicit Situation and Plan Goal fields;
        // all other new levels reuse local_kbacompany_adminitem and existing canonical
        // Indicator/Product/Task/Battle Plan domains. No execution facts are duplicated.
        $table = new xmldb_table('local_kbacompany_adminitem');
        if ($dbman->table_exists($table)) {
            $field = new xmldb_field('situation', XMLDB_TYPE_TEXT, null, null, null, null, null, 'description');
            if (!$dbman->field_exists($table, $field)) {
                $dbman->add_field($table, $field);
            }
            $field = new xmldb_field('plangoal', XMLDB_TYPE_TEXT, null, null, null, null, null, 'situation');
            if (!$dbman->field_exists($table, $field)) {
                $dbman->add_field($table, $field);
            }
        }
        upgrade_plugin_savepoint(true, 2026092135, 'local', 'kbacompany');
    }

    if ($oldversion < 2026092136) {
        // v0.6.9.6 adds a company-scoped monotonic stable-code allocator. UUID remains
        // canonical identity; counters are human-reference infrastructure and codes are
        // never reused after archive. Administrative Scale is the first consumer.
        $table = new xmldb_table('local_kbacompany_codecounter');
        if (!$dbman->table_exists($table)) {
            $table->add_field('id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE, null);
            $table->add_field('companyuuid', XMLDB_TYPE_CHAR, '36', null, XMLDB_NOTNULL, null, null);
            $table->add_field('entityscope', XMLDB_TYPE_CHAR, '64', null, XMLDB_NOTNULL, null, null);
            $table->add_field('lastnumber', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $table->add_field('timecreated', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $table->add_field('timemodified', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $table->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);
            $table->add_index('company_scope_uix', XMLDB_INDEX_UNIQUE, ['companyuuid', 'entityscope']);
            $dbman->create_table($table);
        }
        upgrade_plugin_savepoint(true, 2026092136, 'local', 'kbacompany');
    }


    if ($oldversion < 2026092137) {
        // v0.6.9.7 is schema-neutral. It turns the existing Strategy/BSC + Indicator
        // Registry + Position Assignment + Administrative Scale + Battle Plan contracts
        // into one operational BSC workspace. No parallel goal/KPI/task storage is added.
        upgrade_plugin_savepoint(true, 2026092137, 'local', 'kbacompany');
    }

    if ($oldversion < 2026092138) {
        // v0.6.9.8 is schema-neutral. It introduces SuiteCRM-style ListView / DetailView /
        // EditView presentation for the Administrative Scale and kind-specific field labels.
        // Domain validation, ownership, identifiers and storage are unchanged.
        upgrade_plugin_savepoint(true, 2026092138, 'local', 'kbacompany');
    }

    if ($oldversion < 2026092139) {
        // v0.6.9.9 adds the record-oriented Business Model Canvas 2.0 foundation.
        // Root BMC revisions stay in local_kbacompany_bizmodel; Canvas items are snapshot-scoped
        // records with pinned owner requirements, discussion and immutable published snapshots.
        $table = new xmldb_table('local_kbacompany_bizmodel');
        if ($dbman->table_exists($table)) {
            $field = new xmldb_field('stablecode', XMLDB_TYPE_CHAR, '32', null, XMLDB_NOTNULL, null, '', 'status');
            if (!$dbman->field_exists($table, $field)) {
                $dbman->add_field($table, $field);
            }
            $index = new xmldb_index('stablecode_ix', XMLDB_INDEX_NOTUNIQUE, ['companyuuid', 'stablecode']);
            if (!$dbman->index_exists($table, $index)) {
                $dbman->add_index($table, $index);
            }

            // Adopt legacy BMC roots into Stable Code without changing UUID identity.
            $rows = $DB->get_records('local_kbacompany_bizmodel', [], 'companyuuid ASC, id ASC');
            $assigned = [];
            $lastnumber = [];
            foreach ($rows as $row) {
                $companyuuid = (string)$row->companyuuid;
                $uuid = (string)$row->uuid;
                if (!isset($lastnumber[$companyuuid])) {
                    $counter = $DB->get_record('local_kbacompany_codecounter', [
                        'companyuuid' => $companyuuid, 'entityscope' => 'businessmodel:canvas',
                    ]);
                    $lastnumber[$companyuuid] = $counter ? (int)$counter->lastnumber : 0;
                }
                $key = $companyuuid . '|' . $uuid;
                if (!isset($assigned[$key])) {
                    $existing = trim((string)$row->stablecode);
                    if ($existing !== '' && preg_match('/^BMC-(\d+)$/', $existing, $matches)) {
                        $assigned[$key] = $existing;
                        $lastnumber[$companyuuid] = max($lastnumber[$companyuuid], (int)$matches[1]);
                    } else {
                        $lastnumber[$companyuuid]++;
                        $assigned[$key] = 'BMC-' . str_pad((string)$lastnumber[$companyuuid], 4, '0', STR_PAD_LEFT);
                    }
                }
                if ((string)$row->stablecode !== $assigned[$key]) {
                    $DB->set_field('local_kbacompany_bizmodel', 'stablecode', $assigned[$key], ['id' => $row->id]);
                }
            }
            foreach ($lastnumber as $companyuuid => $number) {
                $counter = $DB->get_record('local_kbacompany_codecounter', [
                    'companyuuid' => $companyuuid, 'entityscope' => 'businessmodel:canvas',
                ]);
                $now = time();
                if ($counter) {
                    if ((int)$counter->lastnumber < $number) {
                        $counter->lastnumber = $number;
                        $counter->timemodified = $now;
                        $DB->update_record('local_kbacompany_codecounter', $counter);
                    }
                } else {
                    $DB->insert_record('local_kbacompany_codecounter', (object)[
                        'companyuuid' => $companyuuid,
                        'entityscope' => 'businessmodel:canvas',
                        'lastnumber' => $number,
                        'timecreated' => $now,
                        'timemodified' => $now,
                    ]);
                }
            }
        }

        $table = new xmldb_table('local_kbacompany_bmcitem');
        if (!$dbman->table_exists($table)) {
            $table->add_field('id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE, null);
            $table->add_field('companyuuid', XMLDB_TYPE_CHAR, '36', null, XMLDB_NOTNULL, null, null);
            $table->add_field('canvasuuid', XMLDB_TYPE_CHAR, '36', null, XMLDB_NOTNULL, null, null);
            $table->add_field('canvasrevision', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '1');
            $table->add_field('itemuuid', XMLDB_TYPE_CHAR, '36', null, XMLDB_NOTNULL, null, null);
            $table->add_field('itemrevision', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '1');
            $table->add_field('stablecode', XMLDB_TYPE_CHAR, '32', null, XMLDB_NOTNULL, null, '');
            $table->add_field('status', XMLDB_TYPE_CHAR, '12', null, XMLDB_NOTNULL, null, 'draft');
            $table->add_field('blockkey', XMLDB_TYPE_CHAR, '24', null, XMLDB_NOTNULL, null, null);
            $table->add_field('statekey', XMLDB_TYPE_CHAR, '12', null, XMLDB_NOTNULL, null, null);
            $table->add_field('marker', XMLDB_TYPE_CHAR, '12', null, XMLDB_NOTNULL, null, null);
            $table->add_field('intent', XMLDB_TYPE_CHAR, '12', null, XMLDB_NOTNULL, null, '');
            $table->add_field('horizon', XMLDB_TYPE_CHAR, '12', null, XMLDB_NOTNULL, null, '');
            $table->add_field('title', XMLDB_TYPE_CHAR, '255', null, XMLDB_NOTNULL, null, null);
            $table->add_field('formulation', XMLDB_TYPE_TEXT, null, null, XMLDB_NOTNULL, null, null);
            $table->add_field('evidencenote', XMLDB_TYPE_TEXT, null, null, null, null, null);
            $table->add_field('evidencesource', XMLDB_TYPE_TEXT, null, null, null, null, null);
            $table->add_field('testcriteria', XMLDB_TYPE_TEXT, null, null, null, null, null);
            $table->add_field('ownerpositionuuid', XMLDB_TYPE_CHAR, '36', null, XMLDB_NOTNULL, null, '');
            $table->add_field('sortorder', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $table->add_field('createdby', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $table->add_field('modifiedby', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $table->add_field('timecreated', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $table->add_field('timemodified', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $table->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);
            $table->add_index('snapshot_item_uix', XMLDB_INDEX_UNIQUE, ['companyuuid', 'canvasuuid', 'canvasrevision', 'itemuuid', 'itemrevision']);
            $table->add_index('snapshot_ix', XMLDB_INDEX_NOTUNIQUE, ['companyuuid', 'canvasuuid', 'canvasrevision', 'blockkey', 'statekey']);
            $table->add_index('item_history_ix', XMLDB_INDEX_NOTUNIQUE, ['companyuuid', 'itemuuid', 'itemrevision']);
            $table->add_index('stablecode_ix', XMLDB_INDEX_NOTUNIQUE, ['companyuuid', 'stablecode']);
            $table->add_index('position_ix', XMLDB_INDEX_NOTUNIQUE, ['companyuuid', 'ownerpositionuuid']);
            $table->add_index('actor_ix', XMLDB_INDEX_NOTUNIQUE, ['modifiedby', 'timemodified']);
            $dbman->create_table($table);
        }

        $table = new xmldb_table('local_kbacompany_bmcitemreq');
        if (!$dbman->table_exists($table)) {
            $table->add_field('id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE, null);
            $table->add_field('companyuuid', XMLDB_TYPE_CHAR, '36', null, XMLDB_NOTNULL, null, null);
            $table->add_field('itemuuid', XMLDB_TYPE_CHAR, '36', null, XMLDB_NOTNULL, null, null);
            $table->add_field('itemrevision', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '1');
            $table->add_field('ownerframeuuid', XMLDB_TYPE_CHAR, '36', null, XMLDB_NOTNULL, null, null);
            $table->add_field('ownerframerevision', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $table->add_field('requirementcode', XMLDB_TYPE_CHAR, '32', null, XMLDB_NOTNULL, null, null);
            $table->add_field('timecreated', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $table->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);
            $table->add_index('item_req_uix', XMLDB_INDEX_UNIQUE, ['companyuuid', 'itemuuid', 'itemrevision', 'requirementcode']);
            $table->add_index('requirement_ix', XMLDB_INDEX_NOTUNIQUE, ['companyuuid', 'ownerframeuuid', 'ownerframerevision', 'requirementcode']);
            $dbman->create_table($table);
        }

        $table = new xmldb_table('local_kbacompany_bmccomment');
        if (!$dbman->table_exists($table)) {
            $table->add_field('id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE, null);
            $table->add_field('companyuuid', XMLDB_TYPE_CHAR, '36', null, XMLDB_NOTNULL, null, null);
            $table->add_field('itemuuid', XMLDB_TYPE_CHAR, '36', null, XMLDB_NOTNULL, null, null);
            $table->add_field('itemrevision', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '1');
            $table->add_field('authoruserid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $table->add_field('comment', XMLDB_TYPE_TEXT, null, null, XMLDB_NOTNULL, null, null);
            $table->add_field('timecreated', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $table->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);
            $table->add_index('item_ix', XMLDB_INDEX_NOTUNIQUE, ['companyuuid', 'itemuuid', 'itemrevision']);
            $table->add_index('author_ix', XMLDB_INDEX_NOTUNIQUE, ['authoruserid', 'timecreated']);
            $dbman->create_table($table);
        }

        upgrade_plugin_savepoint(true, 2026092139, 'local', 'kbacompany');
    }

    if ($oldversion < 2026092201) {
        // Russian Business Model vocabulary. No schema changes.
        upgrade_plugin_savepoint(true, 2026092201, 'local', 'kbacompany');
    }

    if ($oldversion < 2026092202) {
        // Employee BMS CRUD UX. No schema changes.
        upgrade_plugin_savepoint(true, 2026092202, 'local', 'kbacompany');
    }

    if ($oldversion < 2026092203) {
        // Financial Model BMS CRUD UX and server-generated FIN/CFO stable codes. No schema changes.
        upgrade_plugin_savepoint(true, 2026092203, 'local', 'kbacompany');
    }


    if ($oldversion < 2026092204) {
        // Business Model validation and canonical GAP registry. These records are scoped to
        // exact Business Model snapshots and reuse Financial Scenario / BMS Indicator domains.
        $table = new xmldb_table('local_kbacompany_bmcval');
        if (!$dbman->table_exists($table)) {
            $table->add_field('id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE, null);
            $table->add_field('companyuuid', XMLDB_TYPE_CHAR, '36', null, XMLDB_NOTNULL, null, null);
            $table->add_field('uuid', XMLDB_TYPE_CHAR, '36', null, XMLDB_NOTNULL, null, null);
            $table->add_field('revision', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '1');
            $table->add_field('canvasuuid', XMLDB_TYPE_CHAR, '36', null, XMLDB_NOTNULL, null, null);
            $table->add_field('canvasrevision', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '1');
            $table->add_field('contour', XMLDB_TYPE_CHAR, '20', null, XMLDB_NOTNULL, null, null);
            $table->add_field('state', XMLDB_TYPE_CHAR, '12', null, XMLDB_NOTNULL, null, null);
            $table->add_field('note', XMLDB_TYPE_TEXT, null, null, null, null, null);
            $table->add_field('evidence', XMLDB_TYPE_TEXT, null, null, null, null, null);
            $table->add_field('finscenariouuid', XMLDB_TYPE_CHAR, '36', null, XMLDB_NOTNULL, null, '');
            $table->add_field('finscenariorevision', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $table->add_field('createdby', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $table->add_field('modifiedby', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $table->add_field('timecreated', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $table->add_field('timemodified', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $table->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);
            $table->add_index('revision_uix', XMLDB_INDEX_UNIQUE, ['companyuuid', 'uuid', 'revision']);
            $table->add_index('snapshot_contour_ix', XMLDB_INDEX_NOTUNIQUE,
                ['companyuuid', 'canvasuuid', 'canvasrevision', 'contour']);
            $table->add_index('finance_ix', XMLDB_INDEX_NOTUNIQUE,
                ['companyuuid', 'finscenariouuid', 'finscenariorevision']);
            $table->add_index('actor_ix', XMLDB_INDEX_NOTUNIQUE, ['modifiedby', 'timemodified']);
            $dbman->create_table($table);
        }

        $table = new xmldb_table('local_kbacompany_bmcgap');
        if (!$dbman->table_exists($table)) {
            $table->add_field('id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE, null);
            $table->add_field('companyuuid', XMLDB_TYPE_CHAR, '36', null, XMLDB_NOTNULL, null, null);
            $table->add_field('uuid', XMLDB_TYPE_CHAR, '36', null, XMLDB_NOTNULL, null, null);
            $table->add_field('revision', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '1');
            $table->add_field('stablecode', XMLDB_TYPE_CHAR, '32', null, XMLDB_NOTNULL, null, '');
            $table->add_field('status', XMLDB_TYPE_CHAR, '12', null, XMLDB_NOTNULL, null, 'candidate');
            $table->add_field('canvasuuid', XMLDB_TYPE_CHAR, '36', null, XMLDB_NOTNULL, null, null);
            $table->add_field('canvasrevision', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '1');
            $table->add_field('blockkey', XMLDB_TYPE_CHAR, '24', null, XMLDB_NOTNULL, null, null);
            $table->add_field('asisitemuuid', XMLDB_TYPE_CHAR, '36', null, XMLDB_NOTNULL, null, null);
            $table->add_field('asisitemrevision', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '1');
            $table->add_field('targetitemuuid', XMLDB_TYPE_CHAR, '36', null, XMLDB_NOTNULL, null, null);
            $table->add_field('targetitemrevision', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '1');
            $table->add_field('significance', XMLDB_TYPE_TEXT, null, null, XMLDB_NOTNULL, null, null);
            $table->add_field('evidence', XMLDB_TYPE_TEXT, null, null, null, null, null);
            $table->add_field('indicatoruuid', XMLDB_TYPE_CHAR, '36', null, XMLDB_NOTNULL, null, '');
            $table->add_field('createdby', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $table->add_field('modifiedby', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $table->add_field('timecreated', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $table->add_field('timemodified', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $table->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);
            $table->add_index('revision_uix', XMLDB_INDEX_UNIQUE, ['companyuuid', 'uuid', 'revision']);
            $table->add_index('snapshot_ix', XMLDB_INDEX_NOTUNIQUE,
                ['companyuuid', 'canvasuuid', 'canvasrevision', 'status']);
            $table->add_index('stablecode_ix', XMLDB_INDEX_NOTUNIQUE, ['companyuuid', 'stablecode']);
            $table->add_index('indicator_ix', XMLDB_INDEX_NOTUNIQUE, ['companyuuid', 'indicatoruuid']);
            $table->add_index('actor_ix', XMLDB_INDEX_NOTUNIQUE, ['modifiedby', 'timemodified']);
            $dbman->create_table($table);
        }

        $table = new xmldb_table('local_kbacompany_bmcgapreq');
        if (!$dbman->table_exists($table)) {
            $table->add_field('id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE, null);
            $table->add_field('companyuuid', XMLDB_TYPE_CHAR, '36', null, XMLDB_NOTNULL, null, null);
            $table->add_field('gapuuid', XMLDB_TYPE_CHAR, '36', null, XMLDB_NOTNULL, null, null);
            $table->add_field('gaprevision', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '1');
            $table->add_field('ownerframeuuid', XMLDB_TYPE_CHAR, '36', null, XMLDB_NOTNULL, null, '');
            $table->add_field('ownerframerevision', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $table->add_field('requirementcode', XMLDB_TYPE_CHAR, '32', null, XMLDB_NOTNULL, null, null);
            $table->add_field('timecreated', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $table->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);
            $table->add_index('gap_req_uix', XMLDB_INDEX_UNIQUE,
                ['companyuuid', 'gapuuid', 'gaprevision', 'requirementcode']);
            $table->add_index('requirement_ix', XMLDB_INDEX_NOTUNIQUE,
                ['companyuuid', 'ownerframeuuid', 'ownerframerevision', 'requirementcode']);
            $dbman->create_table($table);
        }

        upgrade_plugin_savepoint(true, 2026092204, 'local', 'kbacompany');
    }


    if ($oldversion < 2026092205) {
        // Company Context Foundation: Philosophy, Decision Principles, Market Assessment enrichment and BMC/Strategy pinning.
        $table = new xmldb_table('local_kbacompany_philosophy');
        if (!$dbman->table_exists($table)) {
            $table->add_field('id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE, null);
            $table->add_field('companyuuid', XMLDB_TYPE_CHAR, '36', null, XMLDB_NOTNULL, null, null);
            $table->add_field('uuid', XMLDB_TYPE_CHAR, '36', null, XMLDB_NOTNULL, null, null);
            $table->add_field('revision', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '1');
            $table->add_field('status', XMLDB_TYPE_CHAR, '12', null, XMLDB_NOTNULL, null, 'draft');
            $table->add_field('stablecode', XMLDB_TYPE_CHAR, '32', null, XMLDB_NOTNULL, null, '');
            $table->add_field('purpose', XMLDB_TYPE_TEXT, null, null, XMLDB_NOTNULL, null, null);
            $table->add_field('mission', XMLDB_TYPE_TEXT, null, null, XMLDB_NOTNULL, null, null);
            $table->add_field('vision', XMLDB_TYPE_TEXT, null, null, XMLDB_NOTNULL, null, null);
            $table->add_field('horizon', XMLDB_TYPE_CHAR, '64', null, XMLDB_NOTNULL, null, '');
            $table->add_field('notes', XMLDB_TYPE_TEXT, null, null, null, null, null);
            $table->add_field('createdby', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $table->add_field('modifiedby', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $table->add_field('timecreated', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $table->add_field('timemodified', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $table->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);
            $table->add_index('revision_uix', XMLDB_INDEX_UNIQUE, ['companyuuid', 'uuid', 'revision']);
            $table->add_index('status_ix', XMLDB_INDEX_NOTUNIQUE, ['companyuuid', 'status']);
            $table->add_index('stablecode_ix', XMLDB_INDEX_NOTUNIQUE, ['companyuuid', 'stablecode']);
            $dbman->create_table($table);
        }

        $table = new xmldb_table('local_kbacompany_principle');
        if (!$dbman->table_exists($table)) {
            $table->add_field('id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE, null);
            $table->add_field('companyuuid', XMLDB_TYPE_CHAR, '36', null, XMLDB_NOTNULL, null, null);
            $table->add_field('uuid', XMLDB_TYPE_CHAR, '36', null, XMLDB_NOTNULL, null, null);
            $table->add_field('revision', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '1');
            $table->add_field('status', XMLDB_TYPE_CHAR, '12', null, XMLDB_NOTNULL, null, 'draft');
            $table->add_field('stablecode', XMLDB_TYPE_CHAR, '32', null, XMLDB_NOTNULL, null, '');
            $table->add_field('philosophyuuid', XMLDB_TYPE_CHAR, '36', null, XMLDB_NOTNULL, null, null);
            $table->add_field('philosophyrevision', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '1');
            $table->add_field('title', XMLDB_TYPE_CHAR, '255', null, XMLDB_NOTNULL, null, null);
            $table->add_field('formulation', XMLDB_TYPE_TEXT, null, null, XMLDB_NOTNULL, null, null);
            $table->add_field('rationale', XMLDB_TYPE_TEXT, null, null, null, null, null);
            $table->add_field('sortorder', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $table->add_field('createdby', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $table->add_field('modifiedby', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $table->add_field('timecreated', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $table->add_field('timemodified', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $table->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);
            $table->add_index('revision_uix', XMLDB_INDEX_UNIQUE, ['companyuuid', 'uuid', 'revision']);
            $table->add_index('philosophy_ix', XMLDB_INDEX_NOTUNIQUE, ['companyuuid', 'philosophyuuid', 'status']);
            $table->add_index('stablecode_ix', XMLDB_INDEX_NOTUNIQUE, ['companyuuid', 'stablecode']);
            $dbman->create_table($table);
        }

        $table = new xmldb_table('local_kbacompany_marketev');
        if (!$dbman->table_exists($table)) {
            $table->add_field('id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE, null);
            $table->add_field('companyuuid', XMLDB_TYPE_CHAR, '36', null, XMLDB_NOTNULL, null, null);
            $table->add_field('uuid', XMLDB_TYPE_CHAR, '36', null, XMLDB_NOTNULL, null, null);
            $table->add_field('revision', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '1');
            $table->add_field('status', XMLDB_TYPE_CHAR, '12', null, XMLDB_NOTNULL, null, 'draft');
            $table->add_field('stablecode', XMLDB_TYPE_CHAR, '32', null, XMLDB_NOTNULL, null, '');
            $table->add_field('marketuuid', XMLDB_TYPE_CHAR, '36', null, XMLDB_NOTNULL, null, null);
            $table->add_field('marketrevision', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '1');
            $table->add_field('marker', XMLDB_TYPE_CHAR, '12', null, XMLDB_NOTNULL, null, null);
            $table->add_field('title', XMLDB_TYPE_CHAR, '255', null, XMLDB_NOTNULL, null, null);
            $table->add_field('provider', XMLDB_TYPE_CHAR, '64', null, XMLDB_NOTNULL, null, '');
            $table->add_field('sourceref', XMLDB_TYPE_TEXT, null, null, null, null, null);
            $table->add_field('period', XMLDB_TYPE_CHAR, '64', null, XMLDB_NOTNULL, null, '');
            $table->add_field('value', XMLDB_TYPE_CHAR, '255', null, XMLDB_NOTNULL, null, '');
            $table->add_field('unit', XMLDB_TYPE_CHAR, '64', null, XMLDB_NOTNULL, null, '');
            $table->add_field('note', XMLDB_TYPE_TEXT, null, null, null, null, null);
            $table->add_field('fetchedat', XMLDB_TYPE_CHAR, '16', null, XMLDB_NOTNULL, null, '');
            $table->add_field('createdby', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $table->add_field('modifiedby', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $table->add_field('timecreated', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $table->add_field('timemodified', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $table->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);
            $table->add_index('revision_uix', XMLDB_INDEX_UNIQUE, ['companyuuid', 'uuid', 'revision']);
            $table->add_index('market_ix', XMLDB_INDEX_NOTUNIQUE, ['companyuuid', 'marketuuid', 'marketrevision', 'status']);
            $table->add_index('stablecode_ix', XMLDB_INDEX_NOTUNIQUE, ['companyuuid', 'stablecode']);
            $table->add_index('actor_ix', XMLDB_INDEX_NOTUNIQUE, ['modifiedby', 'timemodified']);
            $dbman->create_table($table);
        }

        $table = new xmldb_table('local_kbacompany_market');
        $fields = [
            new xmldb_field('stablecode', XMLDB_TYPE_CHAR, '32', null, XMLDB_NOTNULL, null, ''),
            new xmldb_field('productclass', XMLDB_TYPE_CHAR, '255', null, XMLDB_NOTNULL, null, ''),
            new xmldb_field('clientsegment', XMLDB_TYPE_CHAR, '255', null, XMLDB_NOTNULL, null, ''),
            new xmldb_field('periodstart', XMLDB_TYPE_CHAR, '10', null, XMLDB_NOTNULL, null, ''),
            new xmldb_field('periodend', XMLDB_TYPE_CHAR, '10', null, XMLDB_NOTNULL, null, ''),
            new xmldb_field('unit', XMLDB_TYPE_CHAR, '64', null, XMLDB_NOTNULL, null, ''),
            new xmldb_field('totalsize', XMLDB_TYPE_CHAR, '64', null, XMLDB_NOTNULL, null, ''),
            new xmldb_field('availablesize', XMLDB_TYPE_CHAR, '64', null, XMLDB_NOTNULL, null, ''),
            new xmldb_field('targetsize', XMLDB_TYPE_CHAR, '64', null, XMLDB_NOTNULL, null, ''),
            new xmldb_field('totalcompanies', XMLDB_TYPE_CHAR, '32', null, XMLDB_NOTNULL, null, ''),
            new xmldb_field('positionrank', XMLDB_TYPE_CHAR, '32', null, XMLDB_NOTNULL, null, ''),
            new xmldb_field('companiesabove', XMLDB_TYPE_CHAR, '32', null, XMLDB_NOTNULL, null, ''),
            new xmldb_field('companiesbelow', XMLDB_TYPE_CHAR, '32', null, XMLDB_NOTNULL, null, ''),
            new xmldb_field('peersize', XMLDB_TYPE_CHAR, '32', null, XMLDB_NOTNULL, null, ''),
            new xmldb_field('marketgrowth', XMLDB_TYPE_CHAR, '32', null, XMLDB_NOTNULL, null, ''),
            new xmldb_field('companygrowth', XMLDB_TYPE_CHAR, '32', null, XMLDB_NOTNULL, null, ''),
            new xmldb_field('salesmarginbenchmark', XMLDB_TYPE_CHAR, '64', null, XMLDB_NOTNULL, null, ''),
            new xmldb_field('evidencesummary', XMLDB_TYPE_TEXT, null, null, null, null, null),
        ];
        foreach ($fields as $field) {
            if (!$dbman->field_exists($table, $field)) {
                $dbman->add_field($table, $field);
            }
        }
        $index = new xmldb_index('stablecode_ix', XMLDB_INDEX_NOTUNIQUE, ['companyuuid', 'stablecode']);
        if (!$dbman->index_exists($table, $index)) {
            $dbman->add_index($table, $index);
        }

        foreach (['local_kbacompany_bizmodel', 'local_kbacompany_strategy'] as $tablename) {
            $table = new xmldb_table($tablename);
            foreach ([
                new xmldb_field('philosophyuuid', XMLDB_TYPE_CHAR, '36', null, XMLDB_NOTNULL, null, ''),
                new xmldb_field('philosophyrevision', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0'),
                new xmldb_field('marketuuid', XMLDB_TYPE_CHAR, '36', null, XMLDB_NOTNULL, null, ''),
                new xmldb_field('marketrevision', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0'),
            ] as $field) {
                if (!$dbman->field_exists($table, $field)) {
                    $dbman->add_field($table, $field);
                }
            }
            $index = new xmldb_index('philosophy_ix', XMLDB_INDEX_NOTUNIQUE, ['companyuuid', 'philosophyuuid']);
            if (!$dbman->index_exists($table, $index)) { $dbman->add_index($table, $index); }
            $index = new xmldb_index('market_context_ix', XMLDB_INDEX_NOTUNIQUE, ['companyuuid', 'marketuuid']);
            if (!$dbman->index_exists($table, $index)) { $dbman->add_index($table, $index); }
        }

        // Preserve any historical market code as stablecode when possible.
        if ($dbman->field_exists(new xmldb_table('local_kbacompany_market'), new xmldb_field('stablecode'))) {
            $DB->execute("UPDATE {local_kbacompany_market} SET stablecode = code WHERE stablecode = '' AND code <> ''");
        }

        upgrade_plugin_savepoint(true, 2026092205, 'local', 'kbacompany');
    }

    if ($oldversion < 2026092206) {
        // 0.6.9.11.1 is schema-neutral: UX/AI hardening and a read-only external data adapter.
        upgrade_plugin_savepoint(true, 2026092206, 'local', 'kbacompany');
    }

    if ($oldversion < 2026092208) {
        // Schema-neutral runtime hotfix: canonical Business Architecture sequence and Moodle enrol.courseid.
        upgrade_plugin_savepoint(true, 2026092208, 'local', 'kbacompany');
    }


    if ($oldversion < 2026092209) {
        // 0.7.0 People Foundation: competency framework governance and versioned Role DNA root.
        $table = new xmldb_table('local_kbacompany_compfw');
        if (!$dbman->table_exists($table)) {
            $table->add_field('id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE, null);
            $table->add_field('companyuuid', XMLDB_TYPE_CHAR, '36', null, XMLDB_NOTNULL, null, null);
            $table->add_field('frameworkid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
            $table->add_field('scope', XMLDB_TYPE_CHAR, '12', null, XMLDB_NOTNULL, null, 'company');
            $table->add_field('active', XMLDB_TYPE_INTEGER, '1', null, XMLDB_NOTNULL, null, '1');
            $table->add_field('createdby', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $table->add_field('timecreated', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $table->add_field('timemodified', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $table->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);
            $table->add_index('company_framework_uix', XMLDB_INDEX_UNIQUE, ['companyuuid', 'frameworkid']);
            $table->add_index('company_scope_ix', XMLDB_INDEX_NOTUNIQUE, ['companyuuid', 'scope', 'active']);
            $dbman->create_table($table);
        }

        $table = new xmldb_table('local_kbacompany_roledna');
        if (!$dbman->table_exists($table)) {
            $table->add_field('id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE, null);
            $table->add_field('companyuuid', XMLDB_TYPE_CHAR, '36', null, XMLDB_NOTNULL, null, null);
            $table->add_field('uuid', XMLDB_TYPE_CHAR, '36', null, XMLDB_NOTNULL, null, null);
            $table->add_field('revision', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '1');
            $table->add_field('stablecode', XMLDB_TYPE_CHAR, '32', null, XMLDB_NOTNULL, null, '');
            $table->add_field('status', XMLDB_TYPE_CHAR, '12', null, XMLDB_NOTNULL, null, 'draft');
            $table->add_field('positionuuid', XMLDB_TYPE_CHAR, '36', null, XMLDB_NOTNULL, null, '');
            $table->add_field('businessroleuuid', XMLDB_TYPE_CHAR, '36', null, XMLDB_NOTNULL, null, '');
            $table->add_field('title', XMLDB_TYPE_CHAR, '255', null, XMLDB_NOTNULL, null, null);
            $table->add_field('purpose', XMLDB_TYPE_TEXT, null, null, null, null, null);
            $table->add_field('methodologyversion', XMLDB_TYPE_CHAR, '32', null, XMLDB_NOTNULL, null, 'people-v0.1');
            $table->add_field('createdby', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $table->add_field('modifiedby', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $table->add_field('timecreated', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $table->add_field('timemodified', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $table->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);
            $table->add_index('revision_uix', XMLDB_INDEX_UNIQUE, ['companyuuid', 'uuid', 'revision']);
            $table->add_index('stablecode_ix', XMLDB_INDEX_NOTUNIQUE, ['companyuuid', 'stablecode']);
            $table->add_index('position_ix', XMLDB_INDEX_NOTUNIQUE, ['companyuuid', 'positionuuid', 'status']);
            $table->add_index('role_ix', XMLDB_INDEX_NOTUNIQUE, ['companyuuid', 'businessroleuuid', 'status']);
            $dbman->create_table($table);
        }

        upgrade_plugin_savepoint(true, 2026092209, 'local', 'kbacompany');
    }


    if ($oldversion < 2026092210) {
        // 0.7.0.1 is schema-neutral: BMS24 branding and grouped contextual sidebar navigation.
        upgrade_plugin_savepoint(true, 2026092210, 'local', 'kbacompany');
    }

    if ($oldversion < 2026092211) {
        // 0.7.0.2 is schema-neutral: Owner AI assistance and BMS24 App Shell brand correction.
        upgrade_plugin_savepoint(true, 2026092211, 'local', 'kbacompany');
    }

    if ($oldversion < 2026092212) {
        // 0.7.0.3 is schema-neutral: Owner AI recommendation context and response UX repair.
        upgrade_plugin_savepoint(true, 2026092212, 'local', 'kbacompany');
    }

    if ($oldversion < 2026092213) {
        // 0.7.0.4 is schema-neutral: simplified sidebar, BMS24 footer brand, settings and plan header entry.
        upgrade_plugin_savepoint(true, 2026092213, 'local', 'kbacompany');
    }

    if ($oldversion < 2026092214) {
        // 0.7.0.5 is schema-neutral: guided Strategy/BSC UX and canonical Owner package strategy source.
        upgrade_plugin_savepoint(true, 2026092214, 'local', 'kbacompany');
    }

    if ($oldversion < 2026092215) {
        // 0.7.0.6 is schema-neutral: BMS Assistant companion compatibility and JSON error hardening.
        upgrade_plugin_savepoint(true, 2026092215, 'local', 'kbacompany');
    }

    if ($oldversion < 2026092216) {
        // 0.7.0.7 is schema-neutral: visible, state-aware BMS Assistant UI for Philosophy and Market.
        upgrade_plugin_savepoint(true, 2026092216, 'local', 'kbacompany');
    }

    if ($oldversion < 2026092217) {
        // 0.7.0.8 is schema-neutral: Owner Assistant panel and explicit AI availability UX.
        upgrade_plugin_savepoint(true, 2026092217, 'local', 'kbacompany');
    }

    if ($oldversion < 2026092218) {
        // 0.7.0.9 is schema-neutral: Strategy and BSC Assistant UI unification.
        upgrade_plugin_savepoint(true, 2026092218, 'local', 'kbacompany');
    }

    if ($oldversion < 2026092219) {
        // 0.7.0.10 is schema-neutral: Unified Workspace UX foundation and architecture-local navigation.
        upgrade_plugin_savepoint(true, 2026092219, 'local', 'kbacompany');
    }

    if ($oldversion < 2026092309) {
        // 0.7.1 Position Competency Profile: versioned competency requirements inside Role DNA.
        $table = new xmldb_table('local_kbacompany_rolednacomp');
        if (!$dbman->table_exists($table)) {
            $table->add_field('id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE, null);
            $table->add_field('companyuuid', XMLDB_TYPE_CHAR, '36', null, XMLDB_NOTNULL, null, null);
            $table->add_field('rolednauuid', XMLDB_TYPE_CHAR, '36', null, XMLDB_NOTNULL, null, null);
            $table->add_field('rolednarevision', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '1');
            $table->add_field('competencyid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
            $table->add_field('targetlevel', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $table->add_field('priority', XMLDB_TYPE_CHAR, '12', null, XMLDB_NOTNULL, null, 'normal');
            $table->add_field('required', XMLDB_TYPE_INTEGER, '1', null, XMLDB_NOTNULL, null, '1');
            $table->add_field('createdby', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $table->add_field('timecreated', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $table->add_field('timemodified', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $table->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);
            $table->add_index('requirement_uix', XMLDB_INDEX_UNIQUE,
                ['companyuuid', 'rolednauuid', 'rolednarevision', 'competencyid']);
            $table->add_index('competency_ix', XMLDB_INDEX_NOTUNIQUE, ['companyuuid', 'competencyid']);
            $dbman->create_table($table);
        }
        upgrade_plugin_savepoint(true, 2026092309, 'local', 'kbacompany');
    }

    if ($oldversion < 2026092310) {
        // 0.7.2 Employee Competency Profile: evidence snapshots compared with published position requirements.
        $table = new xmldb_table('local_kbacompany_empcomp');
        if (!$dbman->table_exists($table)) {
            $table->add_field('id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE, null);
            $table->add_field('companyuuid', XMLDB_TYPE_CHAR, '36', null, XMLDB_NOTNULL, null, null);
            $table->add_field('userid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
            $table->add_field('competencyid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
            $table->add_field('currentlevel', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $table->add_field('sourcekind', XMLDB_TYPE_CHAR, '20', null, XMLDB_NOTNULL, null, 'manual');
            $table->add_field('evidence', XMLDB_TYPE_TEXT, null, null, null, null, null);
            $table->add_field('evidenceurl', XMLDB_TYPE_TEXT, null, null, null, null, null);
            $table->add_field('recordedby', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $table->add_field('recordedat', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $table->add_field('timecreated', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $table->add_field('timemodified', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $table->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);
            $table->add_index('employee_comp_uix', XMLDB_INDEX_UNIQUE, ['companyuuid', 'userid', 'competencyid']);
            $table->add_index('employee_ix', XMLDB_INDEX_NOTUNIQUE, ['companyuuid', 'userid']);
            $table->add_index('competency_ix', XMLDB_INDEX_NOTUNIQUE, ['companyuuid', 'competencyid']);
            $dbman->create_table($table);
        }
        upgrade_plugin_savepoint(true, 2026092310, 'local', 'kbacompany');
    }

    if ($oldversion < 2026092311) {
        // 0.7.3 Assessment Engine: formal competency assessment sessions and decisions.
        $table = new xmldb_table('local_kbacompany_assess');
        if (!$dbman->table_exists($table)) {
            $table->add_field('id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE, null);
            $table->add_field('uuid', XMLDB_TYPE_CHAR, '36', null, XMLDB_NOTNULL, null, null);
            $table->add_field('companyuuid', XMLDB_TYPE_CHAR, '36', null, XMLDB_NOTNULL, null, null);
            $table->add_field('userid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
            $table->add_field('positionuuid', XMLDB_TYPE_CHAR, '36', null, null, null, null);
            $table->add_field('profileuuid', XMLDB_TYPE_CHAR, '36', null, null, null, null);
            $table->add_field('profilerevision', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $table->add_field('method', XMLDB_TYPE_CHAR, '32', null, XMLDB_NOTNULL, null, 'manager_observation');
            $table->add_field('status', XMLDB_TYPE_CHAR, '20', null, XMLDB_NOTNULL, null, 'draft');
            $table->add_field('assessorid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $table->add_field('submittedat', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $table->add_field('decidedby', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $table->add_field('decidedat', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $table->add_field('decisionnote', XMLDB_TYPE_TEXT, null, null, null, null, null);
            $table->add_field('createdby', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $table->add_field('timecreated', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $table->add_field('timemodified', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $table->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);
            $table->add_index('uuid_uix', XMLDB_INDEX_UNIQUE, ['uuid']);
            $table->add_index('employee_ix', XMLDB_INDEX_NOTUNIQUE, ['companyuuid','userid']);
            $table->add_index('status_ix', XMLDB_INDEX_NOTUNIQUE, ['companyuuid','status']);
            $dbman->create_table($table);
        }
        $table = new xmldb_table('local_kbacompany_assessitem');
        if (!$dbman->table_exists($table)) {
            $table->add_field('id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE, null);
            $table->add_field('companyuuid', XMLDB_TYPE_CHAR, '36', null, XMLDB_NOTNULL, null, null);
            $table->add_field('assessmentuuid', XMLDB_TYPE_CHAR, '36', null, XMLDB_NOTNULL, null, null);
            $table->add_field('competencyid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
            $table->add_field('targetlevel', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $table->add_field('proposedlevel', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $table->add_field('decidedlevel', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $table->add_field('evidence', XMLDB_TYPE_TEXT, null, null, null, null, null);
            $table->add_field('evidenceurl', XMLDB_TYPE_TEXT, null, null, null, null, null);
            $table->add_field('rationale', XMLDB_TYPE_TEXT, null, null, null, null, null);
            $table->add_field('timecreated', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $table->add_field('timemodified', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $table->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);
            $table->add_index('assessment_comp_uix', XMLDB_INDEX_UNIQUE, ['companyuuid','assessmentuuid','competencyid']);
            $table->add_index('assessment_ix', XMLDB_INDEX_NOTUNIQUE, ['companyuuid','assessmentuuid']);
            $dbman->create_table($table);
        }
        upgrade_plugin_savepoint(true, 2026092311, 'local', 'kbacompany');
    }

    if ($oldversion < 2026092312) {
        // 0.7.4 360 Assessment: respondent cycles, individual responses and aggregate transfer to Assessment Engine.
        $table = new xmldb_table('local_kbacompany_360cycle');
        if (!$dbman->table_exists($table)) {
            $table->add_field('id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE, null);
            $table->add_field('uuid', XMLDB_TYPE_CHAR, '36', null, XMLDB_NOTNULL, null, null);
            $table->add_field('companyuuid', XMLDB_TYPE_CHAR, '36', null, XMLDB_NOTNULL, null, null);
            $table->add_field('userid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
            $table->add_field('positionuuid', XMLDB_TYPE_CHAR, '36', null, null, null, null);
            $table->add_field('profileuuid', XMLDB_TYPE_CHAR, '36', null, null, null, null);
            $table->add_field('profilerevision', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $table->add_field('title', XMLDB_TYPE_CHAR, '255', null, XMLDB_NOTNULL, null, null);
            $table->add_field('status', XMLDB_TYPE_CHAR, '20', null, XMLDB_NOTNULL, null, 'draft');
            $table->add_field('anonymous', XMLDB_TYPE_INTEGER, '1', null, XMLDB_NOTNULL, null, '1');
            $table->add_field('minimumresponses', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '3');
            $table->add_field('openedat', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $table->add_field('closedat', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $table->add_field('assessmentuuid', XMLDB_TYPE_CHAR, '36', null, null, null, null);
            $table->add_field('createdby', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $table->add_field('timecreated', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $table->add_field('timemodified', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $table->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);
            $table->add_index('uuid_uix', XMLDB_INDEX_UNIQUE, ['uuid']);
            $table->add_index('employee_ix', XMLDB_INDEX_NOTUNIQUE, ['companyuuid','userid']);
            $table->add_index('status_ix', XMLDB_INDEX_NOTUNIQUE, ['companyuuid','status']);
            $dbman->create_table($table);
        }
        $table = new xmldb_table('local_kbacompany_360resp');
        if (!$dbman->table_exists($table)) {
            $table->add_field('id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE, null);
            $table->add_field('companyuuid', XMLDB_TYPE_CHAR, '36', null, XMLDB_NOTNULL, null, null);
            $table->add_field('cycleuuid', XMLDB_TYPE_CHAR, '36', null, XMLDB_NOTNULL, null, null);
            $table->add_field('respondentid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
            $table->add_field('relationship', XMLDB_TYPE_CHAR, '20', null, XMLDB_NOTNULL, null, 'peer');
            $table->add_field('status', XMLDB_TYPE_CHAR, '20', null, XMLDB_NOTNULL, null, 'invited');
            $table->add_field('submittedat', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $table->add_field('timecreated', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $table->add_field('timemodified', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $table->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);
            $table->add_index('cycle_respondent_uix', XMLDB_INDEX_UNIQUE, ['companyuuid','cycleuuid','respondentid']);
            $table->add_index('respondent_ix', XMLDB_INDEX_NOTUNIQUE, ['companyuuid','respondentid','status']);
            $dbman->create_table($table);
        }
        $table = new xmldb_table('local_kbacompany_360score');
        if (!$dbman->table_exists($table)) {
            $table->add_field('id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE, null);
            $table->add_field('companyuuid', XMLDB_TYPE_CHAR, '36', null, XMLDB_NOTNULL, null, null);
            $table->add_field('cycleuuid', XMLDB_TYPE_CHAR, '36', null, XMLDB_NOTNULL, null, null);
            $table->add_field('responseid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
            $table->add_field('competencyid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
            $table->add_field('level', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $table->add_field('comment', XMLDB_TYPE_TEXT, null, null, null, null, null);
            $table->add_field('timecreated', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $table->add_field('timemodified', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $table->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);
            $table->add_index('response_comp_uix', XMLDB_INDEX_UNIQUE, ['companyuuid','responseid','competencyid']);
            $table->add_index('cycle_ix', XMLDB_INDEX_NOTUNIQUE, ['companyuuid','cycleuuid']);
            $dbman->create_table($table);
        }
        upgrade_plugin_savepoint(true, 2026092312, 'local', 'kbacompany');
    }


    if ($oldversion < 2026092313) {
        // 0.7.5 Development Plan: individual development actions from confirmed competency gaps.
        $table = new xmldb_table('local_kbacompany_devplan');
        if (!$dbman->table_exists($table)) {
            $table->add_field('id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE, null);
            $table->add_field('uuid', XMLDB_TYPE_CHAR, '36', null, XMLDB_NOTNULL, null, null);
            $table->add_field('companyuuid', XMLDB_TYPE_CHAR, '36', null, XMLDB_NOTNULL, null, null);
            $table->add_field('userid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
            $table->add_field('positionuuid', XMLDB_TYPE_CHAR, '36', null, null, null, null);
            $table->add_field('profileuuid', XMLDB_TYPE_CHAR, '36', null, null, null, null);
            $table->add_field('profilerevision', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $table->add_field('title', XMLDB_TYPE_CHAR, '255', null, XMLDB_NOTNULL, null, null);
            $table->add_field('status', XMLDB_TYPE_CHAR, '20', null, XMLDB_NOTNULL, null, 'draft');
            $table->add_field('targetdate', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $table->add_field('activatedat', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $table->add_field('completedat', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $table->add_field('createdby', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $table->add_field('modifiedby', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $table->add_field('timecreated', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $table->add_field('timemodified', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $table->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);
            $table->add_index('uuid_uix', XMLDB_INDEX_UNIQUE, ['uuid']);
            $table->add_index('employee_ix', XMLDB_INDEX_NOTUNIQUE, ['companyuuid','userid','status']);
            $dbman->create_table($table);
        }
        $table = new xmldb_table('local_kbacompany_devitem');
        if (!$dbman->table_exists($table)) {
            $table->add_field('id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE, null);
            $table->add_field('uuid', XMLDB_TYPE_CHAR, '36', null, XMLDB_NOTNULL, null, null);
            $table->add_field('companyuuid', XMLDB_TYPE_CHAR, '36', null, XMLDB_NOTNULL, null, null);
            $table->add_field('planuuid', XMLDB_TYPE_CHAR, '36', null, XMLDB_NOTNULL, null, null);
            $table->add_field('competencyid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
            $table->add_field('itemtype', XMLDB_TYPE_CHAR, '20', null, XMLDB_NOTNULL, null, 'practice');
            $table->add_field('title', XMLDB_TYPE_CHAR, '255', null, XMLDB_NOTNULL, null, null);
            $table->add_field('description', XMLDB_TYPE_TEXT, null, null, null, null, null);
            $table->add_field('targetlevel', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $table->add_field('baselinelevel', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $table->add_field('status', XMLDB_TYPE_CHAR, '20', null, XMLDB_NOTNULL, null, 'planned');
            $table->add_field('duedate', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $table->add_field('responsibleid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $table->add_field('learningurl', XMLDB_TYPE_TEXT, null, null, null, null, null);
            $table->add_field('evidence', XMLDB_TYPE_TEXT, null, null, null, null, null);
            $table->add_field('createdby', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $table->add_field('modifiedby', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $table->add_field('timecreated', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $table->add_field('timemodified', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $table->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);
            $table->add_index('uuid_uix', XMLDB_INDEX_UNIQUE, ['uuid']);
            $table->add_index('plan_ix', XMLDB_INDEX_NOTUNIQUE, ['companyuuid','planuuid']);
            $table->add_index('responsible_ix', XMLDB_INDEX_NOTUNIQUE, ['companyuuid','responsibleid','status']);
            $dbman->create_table($table);
        }
        upgrade_plugin_savepoint(true, 2026092313, 'local', 'kbacompany');
    }

    if ($oldversion < 2026092314) {
        // 0.7.6 Learning Linkage: canonical Moodle course reference on development items.
        $table = new xmldb_table('local_kbacompany_devitem');
        $field = new xmldb_field('courseid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0', 'responsibleid');
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }
        upgrade_plugin_savepoint(true, 2026092314, 'local', 'kbacompany');
    }

    if ($oldversion < 2026092315) {
        // 0.7.7 Function Transfer & Authorization. Records responsibility transfer without changing Moodle ACL.
        $table = new xmldb_table('local_kbacompany_functxfer');
        if (!$dbman->table_exists($table)) {
            $table->add_field('id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE, null);
            $table->add_field('uuid', XMLDB_TYPE_CHAR, '36', null, XMLDB_NOTNULL, null, null);
            $table->add_field('companyuuid', XMLDB_TYPE_CHAR, '36', null, XMLDB_NOTNULL, null, null);
            $table->add_field('functionuuid', XMLDB_TYPE_CHAR, '36', null, XMLDB_NOTNULL, null, null);
            $table->add_field('sourcepositionuuid', XMLDB_TYPE_CHAR, '36', null, null, null, null);
            $table->add_field('sourceuserid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $table->add_field('targetpositionuuid', XMLDB_TYPE_CHAR, '36', null, null, null, null);
            $table->add_field('targetuserid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
            $table->add_field('transfertype', XMLDB_TYPE_CHAR, '20', null, XMLDB_NOTNULL, null, 'delegation');
            $table->add_field('status', XMLDB_TYPE_CHAR, '20', null, XMLDB_NOTNULL, null, 'draft');
            $table->add_field('authorityscope', XMLDB_TYPE_TEXT, null, null, null, null, null);
            $table->add_field('handovernote', XMLDB_TYPE_TEXT, null, null, null, null, null);
            $table->add_field('startsat', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $table->add_field('endsat', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $table->add_field('authorizedby', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $table->add_field('authorizedat', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $table->add_field('acceptedby', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $table->add_field('acceptedat', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $table->add_field('revokedby', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $table->add_field('revokedat', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $table->add_field('createdby', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $table->add_field('timecreated', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $table->add_field('timemodified', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $table->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);
            $table->add_index('uuid_uix', XMLDB_INDEX_UNIQUE, ['uuid']);
            $table->add_index('function_ix', XMLDB_INDEX_NOTUNIQUE, ['companyuuid','functionuuid','status']);
            $table->add_index('target_ix', XMLDB_INDEX_NOTUNIQUE, ['companyuuid','targetuserid','status']);
            $dbman->create_table($table);
        }
        upgrade_plugin_savepoint(true, 2026092315, 'local', 'kbacompany');
    }

    if ($oldversion < 2026092316) {
        // 0.7.7.1 hotfix checkpoint. The invalid overlong table name could not be created by Moodle XMLDB,
        // so upgrades use the shortened table created in the 2026092315 step and only advance the savepoint here.
        upgrade_plugin_savepoint(true, 2026092316, 'local', 'kbacompany');
    }


    if ($oldversion < 2026092318) {
        // 0.7.9 Values / Culture / Motivation.
        $table = new xmldb_table('local_kbacompany_cultitem');
        if (!$dbman->table_exists($table)) {
            $table->add_field('id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE, null);
            $table->add_field('uuid', XMLDB_TYPE_CHAR, '36', null, XMLDB_NOTNULL, null, null);
            $table->add_field('companyuuid', XMLDB_TYPE_CHAR, '36', null, XMLDB_NOTNULL, null, null);
            $table->add_field('kind', XMLDB_TYPE_CHAR, '20', null, XMLDB_NOTNULL, null, 'value');
            $table->add_field('title', XMLDB_TYPE_CHAR, '255', null, XMLDB_NOTNULL, null, null);
            $table->add_field('description', XMLDB_TYPE_TEXT, null, null, null, null, null);
            $table->add_field('desiredlevel', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '3');
            $table->add_field('active', XMLDB_TYPE_INTEGER, '1', null, XMLDB_NOTNULL, null, '1');
            $table->add_field('createdby', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $table->add_field('modifiedby', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $table->add_field('timecreated', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $table->add_field('timemodified', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $table->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);
            $table->add_index('uuid_uix', XMLDB_INDEX_UNIQUE, ['uuid']);
            $table->add_index('company_kind_ix', XMLDB_INDEX_NOTUNIQUE, ['companyuuid','kind','active']);
            $dbman->create_table($table);
        }
        $table = new xmldb_table('local_kbacompany_cultscore');
        if (!$dbman->table_exists($table)) {
            $table->add_field('id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE, null);
            $table->add_field('companyuuid', XMLDB_TYPE_CHAR, '36', null, XMLDB_NOTNULL, null, null);
            $table->add_field('userid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
            $table->add_field('itemuuid', XMLDB_TYPE_CHAR, '36', null, XMLDB_NOTNULL, null, null);
            $table->add_field('score', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $table->add_field('comment', XMLDB_TYPE_TEXT, null, null, null, null, null);
            $table->add_field('recordedby', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $table->add_field('timecreated', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $table->add_field('timemodified', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $table->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);
            $table->add_index('employee_item_uix', XMLDB_INDEX_UNIQUE, ['companyuuid','userid','itemuuid']);
            $table->add_index('employee_ix', XMLDB_INDEX_NOTUNIQUE, ['companyuuid','userid']);
            $dbman->create_table($table);
        }
        upgrade_plugin_savepoint(true, 2026092318, 'local', 'kbacompany');
    }

    if ($oldversion < 2026092325) {
        // 0.8.5 Management Cycle Templates & Cadence.
        $table = new xmldb_table('local_kbacompany_mcycle');
        if (!$dbman->table_exists($table)) {
            $table->add_field('id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE, null);
            $table->add_field('uuid', XMLDB_TYPE_CHAR, '36', null, XMLDB_NOTNULL, null, null);
            $table->add_field('companyuuid', XMLDB_TYPE_CHAR, '36', null, XMLDB_NOTNULL, null, null);
            $table->add_field('parentuuid', XMLDB_TYPE_CHAR, '36', null, XMLDB_NOTNULL, null, '');
            $table->add_field('templatecode', XMLDB_TYPE_CHAR, '32', null, XMLDB_NOTNULL, null, null);
            $table->add_field('title', XMLDB_TYPE_CHAR, '255', null, XMLDB_NOTNULL, null, null);
            $table->add_field('scopekind', XMLDB_TYPE_CHAR, '20', null, XMLDB_NOTNULL, null, 'company');
            $table->add_field('scopeuuid', XMLDB_TYPE_CHAR, '36', null, XMLDB_NOTNULL, null, '');
            $table->add_field('ownerid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $table->add_field('curatorid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $table->add_field('priority', XMLDB_TYPE_TEXT, null, null, null, null, null);
            $table->add_field('outcome', XMLDB_TYPE_TEXT, null, null, null, null, null);
            $table->add_field('periodstart', XMLDB_TYPE_CHAR, '10', null, XMLDB_NOTNULL, null, null);
            $table->add_field('periodend', XMLDB_TYPE_CHAR, '10', null, XMLDB_NOTNULL, null, null);
            $table->add_field('status', XMLDB_TYPE_CHAR, '16', null, XMLDB_NOTNULL, null, 'draft');
            $table->add_field('statepayload', XMLDB_TYPE_TEXT, null, null, null, null, null);
            $table->add_field('createdby', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $table->add_field('timecreated', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $table->add_field('timemodified', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $table->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);
            $table->add_index('uuid_uix', XMLDB_INDEX_UNIQUE, ['uuid']);
            $table->add_index('period_ix', XMLDB_INDEX_NOTUNIQUE, ['companyuuid','periodstart','status']);
            $table->add_index('owner_ix', XMLDB_INDEX_NOTUNIQUE, ['companyuuid','ownerid','status']);
            $table->add_index('curator_ix', XMLDB_INDEX_NOTUNIQUE, ['companyuuid','curatorid','status']);
            $table->add_index('parent_ix', XMLDB_INDEX_NOTUNIQUE, ['companyuuid','parentuuid']);
            $dbman->create_table($table);
        }
        $table = new xmldb_table('local_kbacompany_mcyclecp');
        if (!$dbman->table_exists($table)) {
            $table->add_field('id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE, null);
            $table->add_field('uuid', XMLDB_TYPE_CHAR, '36', null, XMLDB_NOTNULL, null, null);
            $table->add_field('companyuuid', XMLDB_TYPE_CHAR, '36', null, XMLDB_NOTNULL, null, null);
            $table->add_field('cycleuuid', XMLDB_TYPE_CHAR, '36', null, XMLDB_NOTNULL, null, null);
            $table->add_field('sequence', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $table->add_field('kind', XMLDB_TYPE_CHAR, '20', null, XMLDB_NOTNULL, null, null);
            $table->add_field('title', XMLDB_TYPE_CHAR, '255', null, XMLDB_NOTNULL, null, null);
            $table->add_field('plannedat', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $table->add_field('meetinguuid', XMLDB_TYPE_CHAR, '36', null, XMLDB_NOTNULL, null, '');
            $table->add_field('status', XMLDB_TYPE_CHAR, '16', null, XMLDB_NOTNULL, null, 'planned');
            $table->add_field('result', XMLDB_TYPE_TEXT, null, null, null, null, null);
            $table->add_field('createdby', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $table->add_field('timecreated', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $table->add_field('timemodified', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $table->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);
            $table->add_index('uuid_uix', XMLDB_INDEX_UNIQUE, ['uuid']);
            $table->add_index('cycle_seq_uix', XMLDB_INDEX_UNIQUE, ['companyuuid','cycleuuid','sequence']);
            $table->add_index('planned_ix', XMLDB_INDEX_NOTUNIQUE, ['companyuuid','plannedat','status']);
            $dbman->create_table($table);
        }
        upgrade_plugin_savepoint(true, 2026092325, 'local', 'kbacompany');
    }


    if ($oldversion < 2026092403) {
        // 0.8.6.2 Battle Plan Approval: append-only plan review decisions and correction targets.
        $table = new xmldb_table('local_kbacompany_bplanrev');
        if (!$dbman->table_exists($table)) {
            $table->add_field('id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE, null);
            $table->add_field('uuid', XMLDB_TYPE_CHAR, '36', null, XMLDB_NOTNULL, null, null);
            $table->add_field('companyuuid', XMLDB_TYPE_CHAR, '36', null, XMLDB_NOTNULL, null, null);
            $table->add_field('planuuid', XMLDB_TYPE_CHAR, '36', null, XMLDB_NOTNULL, null, null);
            $table->add_field('itemuuid', XMLDB_TYPE_CHAR, '36', null, XMLDB_NOTNULL, null, '');
            $table->add_field('decision', XMLDB_TYPE_CHAR, '16', null, XMLDB_NOTNULL, null, null);
            $table->add_field('comment', XMLDB_TYPE_TEXT, null, null, null, null, null);
            $table->add_field('actorid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $table->add_field('timecreated', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $table->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);
            $table->add_index('uuid_uix', XMLDB_INDEX_UNIQUE, ['uuid']);
            $table->add_index('plan_ix', XMLDB_INDEX_NOTUNIQUE, ['companyuuid','planuuid','timecreated']);
            $table->add_index('actor_ix', XMLDB_INDEX_NOTUNIQUE, ['companyuuid','actorid']);
            $dbman->create_table($table);
        }
        upgrade_plugin_savepoint(true, 2026092403, 'local', 'kbacompany');
    }


    if ($oldversion < 2026092404) {
        // 0.8.6.3 Coordination: proposals between canonical Battle Plans. Agreement does not edit plans/tasks.
        $table = new xmldb_table('local_kbacompany_bpcoord');
        if (!$dbman->table_exists($table)) {
            $table->add_field('id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE, null);
            $table->add_field('uuid', XMLDB_TYPE_CHAR, '36', null, XMLDB_NOTNULL, null, null);
            $table->add_field('companyuuid', XMLDB_TYPE_CHAR, '36', null, XMLDB_NOTNULL, null, null);
            $table->add_field('sourceplanuuid', XMLDB_TYPE_CHAR, '36', null, XMLDB_NOTNULL, null, null);
            $table->add_field('sourceitemuuid', XMLDB_TYPE_CHAR, '36', null, XMLDB_NOTNULL, null, '');
            $table->add_field('targetplanuuid', XMLDB_TYPE_CHAR, '36', null, XMLDB_NOTNULL, null, null);
            $table->add_field('targetitemuuid', XMLDB_TYPE_CHAR, '36', null, XMLDB_NOTNULL, null, '');
            $table->add_field('summary', XMLDB_TYPE_TEXT, null, null, XMLDB_NOTNULL, null, null);
            $table->add_field('requestedchange', XMLDB_TYPE_TEXT, null, null, null, null, null);
            $table->add_field('status', XMLDB_TYPE_CHAR, '16', null, XMLDB_NOTNULL, null, 'open');
            $table->add_field('createdby', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $table->add_field('respondedby', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $table->add_field('response', XMLDB_TYPE_TEXT, null, null, null, null, null);
            $table->add_field('timecreated', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $table->add_field('timeresponded', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $table->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);
            $table->add_index('uuid_uix', XMLDB_INDEX_UNIQUE, ['uuid']);
            $table->add_index('source_ix', XMLDB_INDEX_NOTUNIQUE, ['companyuuid','sourceplanuuid','status']);
            $table->add_index('target_ix', XMLDB_INDEX_NOTUNIQUE, ['companyuuid','targetplanuuid','status']);
            $dbman->create_table($table);
        }
        upgrade_plugin_savepoint(true, 2026092404, 'local', 'kbacompany');
    }


    if ($oldversion < 2026092406) {
        // 0.8.7 Management A3: one A3 review inside a canonical Management Cycle.
        $table = new xmldb_table('local_kbacompany_a3review');
        if (!$dbman->table_exists($table)) {
            $table->add_field('id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE, null);
            $table->add_field('uuid', XMLDB_TYPE_CHAR, '36', null, XMLDB_NOTNULL, null, null);
            $table->add_field('companyuuid', XMLDB_TYPE_CHAR, '36', null, XMLDB_NOTNULL, null, null);
            $table->add_field('cycleuuid', XMLDB_TYPE_CHAR, '36', null, XMLDB_NOTNULL, null, null);
            $table->add_field('checkpointuuid', XMLDB_TYPE_CHAR, '36', null, XMLDB_NOTNULL, null, '');
            $table->add_field('title', XMLDB_TYPE_CHAR, '255', null, XMLDB_NOTNULL, null, null);
            $table->add_field('indicatoruuid', XMLDB_TYPE_CHAR, '36', null, XMLDB_NOTNULL, null, '');
            foreach (['problemcontext','currentstate','targetstate','gapanalysis','causeanalysis','countermeasures','conclusion','standardization','taskuuids'] as $field) {
                $table->add_field($field, XMLDB_TYPE_TEXT, null, null, null, null, null);
            }
            $table->add_field('baselinefact', XMLDB_TYPE_CHAR, '64', null, XMLDB_NOTNULL, null, '');
            $table->add_field('baselineperiod', XMLDB_TYPE_CHAR, '32', null, XMLDB_NOTNULL, null, '');
            $table->add_field('targetfact', XMLDB_TYPE_CHAR, '64', null, XMLDB_NOTNULL, null, '');
            $table->add_field('repeatfact', XMLDB_TYPE_CHAR, '64', null, XMLDB_NOTNULL, null, '');
            $table->add_field('repeatperiod', XMLDB_TYPE_CHAR, '32', null, XMLDB_NOTNULL, null, '');
            $table->add_field('status', XMLDB_TYPE_CHAR, '16', null, XMLDB_NOTNULL, null, 'draft');
            $table->add_field('ownerid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $table->add_field('createdby', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $table->add_field('timecreated', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $table->add_field('timemodified', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $table->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);
            $table->add_index('uuid_uix', XMLDB_INDEX_UNIQUE, ['uuid']);
            $table->add_index('cycle_ix', XMLDB_INDEX_NOTUNIQUE, ['companyuuid','cycleuuid','status']);
            $table->add_index('owner_ix', XMLDB_INDEX_NOTUNIQUE, ['companyuuid','ownerid','status']);
            $dbman->create_table($table);
        }
        upgrade_plugin_savepoint(true, 2026092406, 'local', 'kbacompany');
    }


    if ($oldversion < 2026092407) {
        // 0.8.8 A3 standardization routing: human-confirmed links into canonical BMS contours.
        $table = new xmldb_table('local_kbacompany_a3std');
        if (!$dbman->table_exists($table)) {
            $table->add_field('id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE, null);
            $table->add_field('uuid', XMLDB_TYPE_CHAR, '36', null, XMLDB_NOTNULL, null, null);
            $table->add_field('companyuuid', XMLDB_TYPE_CHAR, '36', null, XMLDB_NOTNULL, null, null);
            $table->add_field('a3uuid', XMLDB_TYPE_CHAR, '36', null, XMLDB_NOTNULL, null, null);
            $table->add_field('targetkind', XMLDB_TYPE_CHAR, '16', null, XMLDB_NOTNULL, null, null);
            $table->add_field('targetref', XMLDB_TYPE_CHAR, '64', null, XMLDB_NOTNULL, null, '');
            $table->add_field('targetlabel', XMLDB_TYPE_CHAR, '255', null, XMLDB_NOTNULL, null, '');
            $table->add_field('proposal', XMLDB_TYPE_TEXT, null, null, null, null, null);
            $table->add_field('status', XMLDB_TYPE_CHAR, '16', null, XMLDB_NOTNULL, null, 'proposed');
            $table->add_field('createdby', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $table->add_field('confirmedby', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $table->add_field('timecreated', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $table->add_field('timeconfirmed', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $table->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);
            $table->add_index('uuid_uix', XMLDB_INDEX_UNIQUE, ['uuid']);
            $table->add_index('a3_ix', XMLDB_INDEX_NOTUNIQUE, ['companyuuid','a3uuid','status']);
            $table->add_index('target_ix', XMLDB_INDEX_NOTUNIQUE, ['companyuuid','targetkind','targetref','status']);
            $dbman->create_table($table);
        }
        upgrade_plugin_savepoint(true, 2026092407, 'local', 'kbacompany');
    }


    if ($oldversion < 2026092412) {
        // 0.9.0: extend the existing identity store; blank namespace preserves KBA Connect.
        $table = new xmldb_table('local_kbacompany_extmap');
        foreach (['companyuuid' => '36', 'environment' => '64', 'externalmodel' => '64',
                'namespacekey' => '64'] as $name => $length) {
            $field = new xmldb_field($name, XMLDB_TYPE_CHAR, $length, null, XMLDB_NOTNULL, null, '');
            if (!$dbman->field_exists($table, $field)) {
                $dbman->add_field($table, $field);
            }
        }
        foreach (['mappinguuid' => '36', 'externalkey' => '64'] as $name => $length) {
            $field = new xmldb_field($name, XMLDB_TYPE_CHAR, $length, null, null, null, null);
            if (!$dbman->field_exists($table, $field)) {
                $dbman->add_field($table, $field);
            }
        }
        // Add replacement constraints BEFORE removing legacy indexes. DDL is retry-safe.
        foreach ([
            ['ns_external_uix', XMLDB_INDEX_UNIQUE, ['provider', 'namespacekey', 'entitykind', 'externalid']],
            ['ns_local_uix', XMLDB_INDEX_UNIQUE, ['provider', 'namespacekey', 'entitykind', 'localref']],
            ['mapping_uuid_uix', XMLDB_INDEX_UNIQUE, ['mappinguuid']],
            ['external_key_uix', XMLDB_INDEX_UNIQUE, ['externalkey']],
            ['company_scope_ix', XMLDB_INDEX_NOTUNIQUE, ['companyuuid', 'provider', 'environment', 'active']],
        ] as $definition) {
            $index = new xmldb_index($definition[0], $definition[1], $definition[2]);
            if (!$dbman->index_exists($table, $index)) {
                $dbman->add_index($table, $index);
            }
        }
        foreach ([['external_uix', ['provider', 'entitykind', 'externalid']],
                ['local_uix', ['provider', 'entitykind', 'localref']]] as $definition) {
            $index = new xmldb_index($definition[0], XMLDB_INDEX_UNIQUE, $definition[1]);
            if ($dbman->index_exists($table, $index)) {
                $dbman->drop_index($table, $index);
            }
        }
        upgrade_plugin_savepoint(true, 2026092412, 'local', 'kbacompany');
    }

    if ($oldversion < 2026092417) {
        // One nullable manifest, no invented files or historical data rewriting.
        $table = new xmldb_table('local_kbacompany_doc');
        $field = new xmldb_field('filemanifest', XMLDB_TYPE_TEXT, null, null, null, null, null);
        if (!$dbman->field_exists($table, $field)) { $dbman->add_field($table, $field); }
        upgrade_plugin_savepoint(true, 2026092417, 'local', 'kbacompany');
    }

    if ($oldversion < 2026092503) {
        // Extend existing deviations for user intake; old quantitative records are not rewritten.
        $table = new xmldb_table('local_kbacompany_deviation');
        $field = new xmldb_field('confirmedby', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        if (!$dbman->field_exists($table, $field)) { $dbman->add_field($table, $field); }
        $field = new xmldb_field('entrytype', XMLDB_TYPE_CHAR, '16', null, XMLDB_NOTNULL, null, 'quantitative');
        if (!$dbman->field_exists($table, $field)) { $dbman->add_field($table, $field); }
        $field = new xmldb_field('code', XMLDB_TYPE_CHAR, '64', null, null, null, null);
        if (!$dbman->field_exists($table, $field)) { $dbman->add_field($table, $field); }
        $field = new xmldb_field('title', XMLDB_TYPE_CHAR, '255', null, XMLDB_NOTNULL, null, '');
        if (!$dbman->field_exists($table, $field)) { $dbman->add_field($table, $field); }
        $field = new xmldb_field('description', XMLDB_TYPE_TEXT, null, null, null, null, null);
        if (!$dbman->field_exists($table, $field)) { $dbman->add_field($table, $field); }
        $field = new xmldb_field('requirement', XMLDB_TYPE_TEXT, null, null, null, null, null);
        if (!$dbman->field_exists($table, $field)) { $dbman->add_field($table, $field); }
        $field = new xmldb_field('location', XMLDB_TYPE_CHAR, '255', null, XMLDB_NOTNULL, null, '');
        if (!$dbman->field_exists($table, $field)) { $dbman->add_field($table, $field); }
        $field = new xmldb_field('criterion', XMLDB_TYPE_TEXT, null, null, null, null, null);
        if (!$dbman->field_exists($table, $field)) { $dbman->add_field($table, $field); }
        $field = new xmldb_field('basisref', XMLDB_TYPE_TEXT, null, null, null, null, null);
        if (!$dbman->field_exists($table, $field)) { $dbman->add_field($table, $field); }
        $field = new xmldb_field('ownerpositionuuid', XMLDB_TYPE_CHAR, '36', null, XMLDB_NOTNULL, null, '');
        if (!$dbman->field_exists($table, $field)) { $dbman->add_field($table, $field); }
        $field = new xmldb_field('duedate', XMLDB_TYPE_CHAR, '10', null, XMLDB_NOTNULL, null, '');
        if (!$dbman->field_exists($table, $field)) { $dbman->add_field($table, $field); }
        foreach ([['company_author_ix', XMLDB_INDEX_NOTUNIQUE, ['companyuuid','createdby','entrytype']],
                  ['company_status_ix', XMLDB_INDEX_NOTUNIQUE, ['companyuuid','status']],
                  ['company_code_uix', XMLDB_INDEX_UNIQUE, ['companyuuid','code']]] as $definition) {
            $index = new xmldb_index($definition[0], $definition[1], $definition[2]);
            if (!$dbman->index_exists($table, $index)) { $dbman->add_index($table, $index); }
        }
        $table = new xmldb_table('local_kbacompany_devlink');
        $field = new xmldb_field('taskengine', XMLDB_TYPE_CHAR, '16', null, XMLDB_NOTNULL, null, 'workflow');
        if (!$dbman->field_exists($table, $field)) { $dbman->add_field($table, $field); }
        // Fix the ORIGINAL entry point too: a failed 2503 upgrade never reaches 2504/2505.
        require_once(__DIR__ . '/upgradelib.php');
        local_kbacompany_upgrade_deviation_nullable_2026092505(2026092503);
        upgrade_plugin_savepoint(true, 2026092503, 'local', 'kbacompany');
    }

    if ($oldversion < 2026092505) {
        // 0.9.0.9.2: repeat the full 2504 repair with index-safe nullable changes.
        // This also covers sites that recorded 2504 or stopped after dropping norm_ix.
        // Only the normal Moodle upgrade runner may apply this repair. Never run DDL on page views.
        // Preserve all existing UUIDs, codes, observations, states, revisions and history rows.
        foreach (['local_kbacompany_deviation', 'local_kbacompany_devcheck',
                'local_kbacompany_devlink'] as $tablename) {
            if (!$dbman->table_exists(new xmldb_table($tablename))) {
                throw new ddl_table_missing_exception($tablename);
            }
        }
        $table = new xmldb_table('local_kbacompany_deviation');
        $field = new xmldb_field('confirmedby', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        if (!$dbman->field_exists($table, $field)) { $dbman->add_field($table, $field); }
        $field = new xmldb_field('entrytype', XMLDB_TYPE_CHAR, '16', null, XMLDB_NOTNULL, null, 'quantitative');
        if (!$dbman->field_exists($table, $field)) { $dbman->add_field($table, $field); }
        $field = new xmldb_field('code', XMLDB_TYPE_CHAR, '64', null, null, null, null);
        if (!$dbman->field_exists($table, $field)) { $dbman->add_field($table, $field); }
        $field = new xmldb_field('title', XMLDB_TYPE_CHAR, '255', null, XMLDB_NOTNULL, null, '');
        if (!$dbman->field_exists($table, $field)) { $dbman->add_field($table, $field); }
        $field = new xmldb_field('description', XMLDB_TYPE_TEXT, null, null, null, null, null);
        if (!$dbman->field_exists($table, $field)) { $dbman->add_field($table, $field); }
        $field = new xmldb_field('requirement', XMLDB_TYPE_TEXT, null, null, null, null, null);
        if (!$dbman->field_exists($table, $field)) { $dbman->add_field($table, $field); }
        $field = new xmldb_field('location', XMLDB_TYPE_CHAR, '255', null, XMLDB_NOTNULL, null, '');
        if (!$dbman->field_exists($table, $field)) { $dbman->add_field($table, $field); }
        $field = new xmldb_field('criterion', XMLDB_TYPE_TEXT, null, null, null, null, null);
        if (!$dbman->field_exists($table, $field)) { $dbman->add_field($table, $field); }
        $field = new xmldb_field('basisref', XMLDB_TYPE_TEXT, null, null, null, null, null);
        if (!$dbman->field_exists($table, $field)) { $dbman->add_field($table, $field); }
        $field = new xmldb_field('ownerpositionuuid', XMLDB_TYPE_CHAR, '36', null, XMLDB_NOTNULL, null, '');
        if (!$dbman->field_exists($table, $field)) { $dbman->add_field($table, $field); }
        $field = new xmldb_field('duedate', XMLDB_TYPE_CHAR, '10', null, XMLDB_NOTNULL, null, '');
        if (!$dbman->field_exists($table, $field)) { $dbman->add_field($table, $field); }
        foreach ([['company_author_ix', XMLDB_INDEX_NOTUNIQUE, ['companyuuid','createdby','entrytype']],
                  ['company_status_ix', XMLDB_INDEX_NOTUNIQUE, ['companyuuid','status']],
                  ['company_code_uix', XMLDB_INDEX_UNIQUE, ['companyuuid','code']]] as $definition) {
            $index = new xmldb_index($definition[0], $definition[1], $definition[2]);
            if (!$dbman->index_exists($table, $index)) { $dbman->add_index($table, $index); }
        }
        $table = new xmldb_table('local_kbacompany_devlink');
        $field = new xmldb_field('taskengine', XMLDB_TYPE_CHAR, '16', null, XMLDB_NOTNULL, null, 'workflow');
        if (!$dbman->field_exists($table, $field)) { $dbman->add_field($table, $field); }
        require_once(__DIR__ . '/upgradelib.php');
        local_kbacompany_upgrade_deviation_nullable_2026092505(2026092505);
        // Read metadata afresh before recording completion. Do not conceal a schema mismatch.
        $required = [
            'local_kbacompany_deviation' => ['confirmedby','entrytype','code','title','description',
                'requirement','location','criterion','basisref','ownerpositionuuid','duedate'],
            'local_kbacompany_devlink' => ['taskengine'],
        ];
        foreach ($required as $name => $fields) {
            $columns = $DB->get_columns($name, false);
            foreach ($fields as $column) {
                if (!isset($columns[$column])) {
                    throw new ddl_field_missing_exception($column, $name);
                }
            }
        }
        foreach (['local_kbacompany_deviation','local_kbacompany_devcheck'] as $name) {
            $columns = $DB->get_columns($name, false);
            foreach (['approvaluuid','normuuid','actual'] as $column) {
                if (!isset($columns[$column]) || $columns[$column]->not_null) {
                    throw new upgrade_exception('local_kbacompany', 2026092505,
                        'Deviation schema repair incomplete: nullable column ' . $name . '.' . $column);
                }
            }
        }
        $indexes = $DB->get_indexes('local_kbacompany_deviation');
        foreach ([
            [false, ['companyuuid','createdby','entrytype']],
            [false, ['companyuuid','status']],
            [true, ['companyuuid','code']],
        ] as $definition) {
            $found = false;
            foreach ($indexes as $index) {
                if (array_values($index['columns']) === $definition[1] &&
                        (bool)$index['unique'] === $definition[0]) {
                    $found = true;
                    break;
                }
            }
            if (!$found) {
                // XMLDB index_exists compares columns, not uniqueness. Never silently accept
                // a non-unique replacement of the code index, drop indexes or rewrite duplicate data.
                throw new upgrade_exception('local_kbacompany', 2026092505,
                    'Deviation schema repair incomplete: expected index ' . implode(',', $definition[1]));
            }
        }
        if ($oldversion < 2026092504) {
            upgrade_plugin_savepoint(true, 2026092504, 'local', 'kbacompany');
        }
        upgrade_plugin_savepoint(true, 2026092505, 'local', 'kbacompany');
    }


    if ($oldversion < 2026101210) {
        $table = new xmldb_table('local_kbacompany_meeting');
        $field = new xmldb_field('resourceuuid', XMLDB_TYPE_CHAR, '36', null, XMLDB_NOTNULL, null, '', 'location');
        if (!$dbman->field_exists($table, $field)) { $dbman->add_field($table, $field); }
        $field = new xmldb_field('onlineurl', XMLDB_TYPE_CHAR, '255', null, XMLDB_NOTNULL, null, '', 'resourceuuid');
        if (!$dbman->field_exists($table, $field)) { $dbman->add_field($table, $field); }
        $index = new xmldb_index('resource_time_ix', XMLDB_INDEX_NOTUNIQUE, ['companyuuid','resourceuuid','startsat','endsat']);
        if (!$dbman->index_exists($table, $index)) { $dbman->add_index($table, $index); }

        $table = new xmldb_table('local_kbacompany_meetres');
        if (!$dbman->table_exists($table)) {
            $table->add_field('id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE, null);
            $table->add_field('companyuuid', XMLDB_TYPE_CHAR, '36', null, XMLDB_NOTNULL, null, null);
            $table->add_field('uuid', XMLDB_TYPE_CHAR, '36', null, XMLDB_NOTNULL, null, null);
            $table->add_field('code', XMLDB_TYPE_CHAR, '40', null, XMLDB_NOTNULL, null, '');
            $table->add_field('name', XMLDB_TYPE_CHAR, '255', null, XMLDB_NOTNULL, null, '');
            $table->add_field('resourcetype', XMLDB_TYPE_CHAR, '16', null, XMLDB_NOTNULL, null, 'room');
            $table->add_field('location', XMLDB_TYPE_CHAR, '255', null, XMLDB_NOTNULL, null, '');
            $table->add_field('capacity', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $table->add_field('active', XMLDB_TYPE_INTEGER, '1', null, XMLDB_NOTNULL, null, '1');
            $table->add_field('timecreated', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $table->add_field('timemodified', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $table->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);
            $table->add_index('uuid_uix', XMLDB_INDEX_UNIQUE, ['uuid']);
            $table->add_index('company_code_uix', XMLDB_INDEX_UNIQUE, ['companyuuid','code']);
            $table->add_index('company_active_ix', XMLDB_INDEX_NOTUNIQUE, ['companyuuid','active','name']);
            $dbman->create_table($table);
        }
        upgrade_plugin_savepoint(true, 2026101210, 'local', 'kbacompany');
    }

    if ($oldversion < 2026101219) {
        $table = new xmldb_table('local_kbacompany_task');
        $field = new xmldb_field('starttime', XMLDB_TYPE_CHAR, '5', null, XMLDB_NOTNULL, null, '', 'startdate');
        if (!$dbman->field_exists($table, $field)) { $dbman->add_field($table, $field); }
        $field = new xmldb_field('duetime', XMLDB_TYPE_CHAR, '5', null, XMLDB_NOTNULL, null, '', 'duedate');
        if (!$dbman->field_exists($table, $field)) { $dbman->add_field($table, $field); }
        upgrade_plugin_savepoint(true, 2026101219, 'local', 'kbacompany');
    }

    if ($oldversion < 2026101230) {
        require_once(__DIR__ . '/upgradelib.php');
        local_kbacompany_upgrade_carry_staffing_assignments_2026101230();
        upgrade_plugin_savepoint(true, 2026101230, 'local', 'kbacompany');
    }

    if ($oldversion < 2026101233) {
        // rc13: allocation FTE belongs to the factual assignment, not to the normative slot.
        // This permits part-time placement and additional/acting assignments while preserving history.
        $table = new xmldb_table('local_kbacompany_posassign');
        $field = new xmldb_field('assignmentfte', XMLDB_TYPE_NUMBER, '5, 2', null, XMLDB_NOTNULL, null, '1', 'assignmenttype');
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }
        // Existing slot-backed assignments inherit the FTE of their historical staffing slot.
        $sql = "SELECT a.id, s.fte
                  FROM {local_kbacompany_posassign} a
                  JOIN {local_kbacompany_staffslot} s
                    ON s.companyuuid = a.companyuuid AND s.uuid = a.slotuuid
                 WHERE a.slotuuid <> ''";
        $rs = $DB->get_recordset_sql($sql);
        foreach ($rs as $row) {
            $DB->set_field('local_kbacompany_posassign', 'assignmentfte', (float)$row->fte, ['id' => (int)$row->id]);
        }
        $rs->close();
        upgrade_plugin_savepoint(true, 2026101233, 'local', 'kbacompany');
    }


    if ($oldversion < 2026101340) {
        // rc19 hardening/UI release: no database schema changes.
        upgrade_plugin_savepoint(true, 2026101340, 'local', 'kbacompany');
    }

    if ($oldversion < 2026101341) {
        // rc20 quality workspace: existing canonical schemas only.
        upgrade_plugin_savepoint(true, 2026101341, 'local', 'kbacompany');
    }

    if ($oldversion < 2026101348) {
        // rc22 security hardening: no schema or data migration.
        upgrade_plugin_savepoint(true, 2026101348, 'local', 'kbacompany');
    }

    if ($oldversion < 2026101349) {
        // RC22 integration catalogue: presentation only. No schema changes.
        upgrade_plugin_savepoint(true, 2026101349, 'local', 'kbacompany');
    }

    if ($oldversion < 2026101350) {
        // RC23 query-only improvement; no schema or data migrations.
        upgrade_plugin_savepoint(true, 2026101350, 'local', 'kbacompany');
    }

    if ($oldversion < 2026101351) {
        // RC24 regression candidate: no schema or data migration.
        upgrade_plugin_savepoint(true, 2026101351, 'local', 'kbacompany');
    }

    if ($oldversion < 2026101352) {
        // Presentation-only Workspace simplification; no schema or data changes.
        upgrade_plugin_savepoint(true, 2026101352, 'local', 'kbacompany');
    }

    if ($oldversion < 2026101353) {
        // RC25 upgrade guard fix: presentation-only, no schema or data migrations.
        upgrade_plugin_savepoint(true, 2026101353, 'local', 'kbacompany');
    }

    if ($oldversion < 2026101354) {
        upgrade_plugin_savepoint(true, 2026101354, 'local', 'kbacompany');
    }

    if ($oldversion < 2026101355) {
        // RC25 regression test checkpoint: no schema or data changes.
        upgrade_plugin_savepoint(true, 2026101355, 'local', 'kbacompany');
    }

    if ($oldversion < 2026101356) {
        // RC26 workspace read-model and regression coverage; no schema migration.
        upgrade_plugin_savepoint(true, 2026101356, 'local', 'kbacompany');
    }

    if ($oldversion < 2026101357) {
        // Employee calendar card: read-only UI, no schema changes.
        upgrade_plugin_savepoint(true, 2026101357, 'local', 'kbacompany');
    }


    if ($oldversion < 2026101458) {
        // Explicit access-controlled work calendars: no personal Moodle event migration.
        $dbman = $DB->get_manager();
        $table = new \xmldb_table('local_kbacompany_wcalev');
        if (!$dbman->table_exists($table)) {
            $table->add_field('id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE);
            $table->add_field('companyuuid', XMLDB_TYPE_CHAR, '36', null, XMLDB_NOTNULL, null, null);
            $table->add_field('ownerid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $table->add_field('title', XMLDB_TYPE_CHAR, '255', null, XMLDB_NOTNULL, null, null);
            $table->add_field('starttime', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $table->add_field('endtime', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $table->add_field('createdby', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $table->add_field('timecreated', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $table->add_field('timemodified', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $table->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);
            $table->add_index('owner_ix', XMLDB_INDEX_NOTUNIQUE, ['companyuuid', 'ownerid', 'starttime']);
            $dbman->create_table($table);
        }
        $table = new \xmldb_table('local_kbacompany_wcalacl');
        if (!$dbman->table_exists($table)) {
            $table->add_field('id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE);
            $table->add_field('companyuuid', XMLDB_TYPE_CHAR, '36', null, XMLDB_NOTNULL, null, null);
            $table->add_field('ownerid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $table->add_field('granteeid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $table->add_field('canview', XMLDB_TYPE_INTEGER, '1', null, XMLDB_NOTNULL, null, '0');
            $table->add_field('canedit', XMLDB_TYPE_INTEGER, '1', null, XMLDB_NOTNULL, null, '0');
            $table->add_field('cancomment', XMLDB_TYPE_INTEGER, '1', null, XMLDB_NOTNULL, null, '0');
            $table->add_field('timemodified', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $table->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);
            $table->add_index('owner_grantee_uix', XMLDB_INDEX_UNIQUE, ['companyuuid', 'ownerid', 'granteeid']);
            $dbman->create_table($table);
        }
        $table = new \xmldb_table('local_kbacompany_wcalmsg');
        if (!$dbman->table_exists($table)) {
            $table->add_field('id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE);
            $table->add_field('companyuuid', XMLDB_TYPE_CHAR, '36', null, XMLDB_NOTNULL, null, null);
            $table->add_field('eventid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $table->add_field('authorid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $table->add_field('message', XMLDB_TYPE_TEXT, null, null, XMLDB_NOTNULL, null, null);
            $table->add_field('timecreated', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $table->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);
            $table->add_index('event_ix', XMLDB_INDEX_NOTUNIQUE, ['companyuuid', 'eventid', 'timecreated']);
            $dbman->create_table($table);
        }
        upgrade_plugin_savepoint(true, 2026101458, 'local', 'kbacompany');
    }

    if ($oldversion < 2026101459) {
        // RC27 self-service profile route: no schema changes.
        upgrade_plugin_savepoint(true, 2026101459, 'local', 'kbacompany');
    }

    if ($oldversion < 2026101460) {
        // RC28 first enforcement slice: empty company-scoped access roles, grants, assignments and audit.
        // No automatic role grants or changes to the existing Company owner/manager policy.
        $table = new xmldb_table('local_kbacompany_aclrole');
        if (!$dbman->table_exists($table)) {
            $table->add_field('id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE);
            $table->add_field('companyuuid', XMLDB_TYPE_CHAR, '36', null, XMLDB_NOTNULL, null, '');
            $table->add_field('name', XMLDB_TYPE_CHAR, '100', null, XMLDB_NOTNULL, null, '');
            $table->add_field('description', XMLDB_TYPE_TEXT, null, null, null, null);
            $table->add_field('active', XMLDB_TYPE_INTEGER, '1', null, XMLDB_NOTNULL, null, '1');
            $table->add_field('createdby', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $table->add_field('timecreated', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $table->add_field('timemodified', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $table->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);
            $table->add_index('company_name_uix', XMLDB_INDEX_UNIQUE, ['companyuuid', 'name']);
            $table->add_index('company_ix', XMLDB_INDEX_NOTUNIQUE, ['companyuuid', 'active']);
            $dbman->create_table($table);
        }
        $table = new xmldb_table('local_kbacompany_aclgrant');
        if (!$dbman->table_exists($table)) {
            $table->add_field('id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE);
            $table->add_field('companyuuid', XMLDB_TYPE_CHAR, '36', null, XMLDB_NOTNULL, null, '');
            $table->add_field('roleid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $table->add_field('objecttype', XMLDB_TYPE_CHAR, '40', null, XMLDB_NOTNULL, null, '');
            $table->add_field('actionname', XMLDB_TYPE_CHAR, '24', null, XMLDB_NOTNULL, null, '');
            $table->add_field('scope', XMLDB_TYPE_CHAR, '24', null, XMLDB_NOTNULL, null, 'company');
            $table->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);
            $table->add_index('grant_uix', XMLDB_INDEX_UNIQUE, ['companyuuid', 'roleid', 'objecttype', 'actionname']);
            $table->add_index('role_ix', XMLDB_INDEX_NOTUNIQUE, ['companyuuid', 'roleid']);
            $dbman->create_table($table);
        }
        $table = new xmldb_table('local_kbacompany_aclassign');
        if (!$dbman->table_exists($table)) {
            $table->add_field('id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE);
            $table->add_field('companyuuid', XMLDB_TYPE_CHAR, '36', null, XMLDB_NOTNULL, null, '');
            $table->add_field('roleid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $table->add_field('userid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $table->add_field('assignedby', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $table->add_field('timecreated', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $table->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);
            $table->add_index('assign_uix', XMLDB_INDEX_UNIQUE, ['companyuuid', 'roleid', 'userid']);
            $table->add_index('member_ix', XMLDB_INDEX_NOTUNIQUE, ['companyuuid', 'userid']);
            $dbman->create_table($table);
        }
        $table = new xmldb_table('local_kbacompany_aclaudit');
        if (!$dbman->table_exists($table)) {
            $table->add_field('id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE);
            $table->add_field('companyuuid', XMLDB_TYPE_CHAR, '36', null, XMLDB_NOTNULL, null, '');
            $table->add_field('actorid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $table->add_field('eventkind', XMLDB_TYPE_CHAR, '40', null, XMLDB_NOTNULL, null, '');
            $table->add_field('objectid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $table->add_field('details', XMLDB_TYPE_TEXT, null, null, XMLDB_NOTNULL, null);
            $table->add_field('timecreated', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $table->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);
            $table->add_index('audit_ix', XMLDB_INDEX_NOTUNIQUE, ['companyuuid', 'timecreated']);
            $dbman->create_table($table);
        }
        upgrade_plugin_savepoint(true, 2026101460, 'local', 'kbacompany');
    }

    if ($oldversion < 2026101461) {
        // Workload projection uses existing task and meeting tables; no schema change.
        upgrade_plugin_savepoint(true, 2026101461, 'local', 'kbacompany');
    }

    if ($oldversion < 2026101462) {
        $table = new xmldb_table('local_kbacompany_wksched');
        if (!$dbman->table_exists($table)) {
            $table->add_field('id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE);
            $table->add_field('companyuuid', XMLDB_TYPE_CHAR, '36', null, XMLDB_NOTNULL);
            $table->add_field('userid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL);
            $table->add_field('weeklyhours', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '40');
            $table->add_field('modifiedby', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $table->add_field('timemodified', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $table->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);
            $table->add_index('company_user_uix', XMLDB_INDEX_UNIQUE, ['companyuuid','userid']);
            $dbman->create_table($table);
        }
        upgrade_plugin_savepoint(true, 2026101462, 'local', 'kbacompany');
    }
    if ($oldversion < 2026101463) {
        // Regression-only release, no schema mutation.
        upgrade_plugin_savepoint(true, 2026101463, 'local', 'kbacompany');
    }
    if ($oldversion < 2026101464) {
        // RBAC regression-only checkpoint. No database schema mutation.
        upgrade_plugin_savepoint(true, 2026101464, 'local', 'kbacompany');
    }
    if ($oldversion < 2026101465) {
        // Coordination usability and UUID lookup fix; no schema migration.
        upgrade_plugin_savepoint(true, 2026101465, 'local', 'kbacompany');
    }
    if ($oldversion < 2026101466) {
        // Integrator template and seat display: no database schema change.
        upgrade_plugin_savepoint(true, 2026101466, 'local', 'kbacompany');
    }
    if ($oldversion < 2026101467) {
        // Rebrand the previously seeded access-role label without changing identity,
        // permissions or user assignments. Never rename an unrelated custom role.
        $roletable = new xmldb_table('local_kbacompany_aclrole');
        if ($dbman->table_exists($roletable)) {
            $legacyroles = $DB->get_records('local_kbacompany_aclrole', ['name' => 'Интегратор BMS24']);
            foreach ($legacyroles as $role) {
                $exists = $DB->record_exists('local_kbacompany_aclrole', [
                    'companyuuid' => $role->companyuuid,
                    'name' => 'Бизнес-инженер BMS',
                ]);
                if (!$exists) {
                    $role->name = 'Бизнес-инженер BMS';
                    $DB->update_record('local_kbacompany_aclrole', $role);
                }
            }
        }
        upgrade_plugin_savepoint(true, 2026101467, 'local', 'kbacompany');
    }
    if ($oldversion < 2026101468) {
        // Read-only BMS Cloud plan catalog contract; no schema changes.
        upgrade_plugin_savepoint(true, 2026101468, 'local', 'kbacompany');
    }
    if ($oldversion < 2026101469) {
        // RC29.1: read-only commercial catalogue contract, no schema changes.
        upgrade_plugin_savepoint(true, 2026101469, 'local', 'kbacompany');
    }
    if ($oldversion < 2026101470) {
        // Employee profile presentation and canonical Moodle fields only; no schema change.
        upgrade_plugin_savepoint(true, 2026101470, 'local', 'kbacompany');
    }
    if ($oldversion < 2026101471) {
        // Company-scoped personnel overview, read-only, no schema changes.
        upgrade_plugin_savepoint(true, 2026101471, 'local', 'kbacompany');
    }
    if ($oldversion < 2026101472) {
        // Fix Moodle user avatar renderer API; no schema or data changes.
        upgrade_plugin_savepoint(true, 2026101472, 'local', 'kbacompany');
    }
    if ($oldversion < 2026101473) {
        // BMS24 self-service photo upload using canonical Moodle user/icon storage.
        upgrade_plugin_savepoint(true, 2026101473, 'local', 'kbacompany');
    }
    if ($oldversion < 2026101474) {
        // Permit the A3 correction tab route in Management Cycle 2.0; no schema changes.
        upgrade_plugin_savepoint(true, 2026101474, 'local', 'kbacompany');
    }
    if ($oldversion < 2026101475) {
        // Personal management cycle read-only projection; no schema changes.
        upgrade_plugin_savepoint(true, 2026101475, 'local', 'kbacompany');
    }
    if ($oldversion < 2026101476) {
        // RC29 RBAC effective permissions and enforcement transparency; no schema changes.
        upgrade_plugin_savepoint(true, 2026101476, 'local', 'kbacompany');
    }
    if ($oldversion < 2026101477) {
        // Distinct company-level task and battle plan edit grants; no schema change.
        upgrade_plugin_savepoint(true, 2026101477, 'local', 'kbacompany');
    }
    if ($oldversion < 2026101478) {
        // Department-scope view grants for tasks and battle plans, existing ACL tables.
        upgrade_plugin_savepoint(true, 2026101478, 'local', 'kbacompany');
    }
    if ($oldversion < 2026101479) {
        // Independent company-scoped create grants; existing ACL tables only.
        upgrade_plugin_savepoint(true, 2026101479, 'local', 'kbacompany');
    }
    if ($oldversion < 2026101480) {
        // Scoped Battle Plan approval grant, no new schema.
        upgrade_plugin_savepoint(true, 2026101480, 'local', 'kbacompany');
    }
    if ($oldversion < 2026101481) {
        // Department-scoped Battle Plan creation; schema unchanged.
        upgrade_plugin_savepoint(true, 2026101481, 'local', 'kbacompany');
    }
    if ($oldversion < 2026101482) {
        // Department Battle Plan limited editing; no schema changes.
        upgrade_plugin_savepoint(true, 2026101482, 'local', 'kbacompany');
    }
    if ($oldversion < 2026101483) {
        // Department task definition edit and result review rights; no schema changes.
        upgrade_plugin_savepoint(true, 2026101483, 'local', 'kbacompany');
    }
    if ($oldversion < 2026101484) {
        // Harden mutation authorization for task attachments/checklists; no DB changes.
        upgrade_plugin_savepoint(true, 2026101484, 'local', 'kbacompany');
    }
    if ($oldversion < 2026101485) {
        // Restore navigation context from recurring-task library to originating battle plan.
        upgrade_plugin_savepoint(true, 2026101485, 'local', 'kbacompany');
    }
    if ($oldversion < 2026101486) {
        // Read-only weekly projection of canonical daily position metrics.
        upgrade_plugin_savepoint(true, 2026101486, 'local', 'kbacompany');
    }
    if ($oldversion < 2026101487) {
        // Explicit idempotent synchronization of recurring tasks with draft Battle Plans; form clarity.
        upgrade_plugin_savepoint(true, 2026101487, 'local', 'kbacompany');
    }
    if ($oldversion < 2026101488) {
        // Metric input clarity and one-observation chart rendering; no schema change.
        upgrade_plugin_savepoint(true, 2026101488, 'local', 'kbacompany');
    }
    if ($oldversion < 2026101489) {
        // Read-only historical values audit UI; existing append-only data, no schema changes.
        upgrade_plugin_savepoint(true, 2026101489, 'local', 'kbacompany');
    }
    if ($oldversion < 2026101490) {
        // Automatically generated CKP code and independent read-only Information Centre.
        upgrade_plugin_savepoint(true, 2026101490, 'local', 'kbacompany');
    }
    if ($oldversion < 2026101491) {
        // Read-only graphical information centre over canonical organization and metric history.
        upgrade_plugin_savepoint(true, 2026101491, 'local', 'kbacompany');
    }
    if ($oldversion < 2026101492) {
        // Canonical CRUD view/create/edit routes for valuable final products, no DB schema change.
        upgrade_plugin_savepoint(true, 2026101492, 'local', 'kbacompany');
    }
    // RC29 1493: navigation + Moodle-compatible style + CKP form UX, no database changes.
    if ($oldversion < 2026101493) {
        upgrade_plugin_savepoint(true, 2026101493, 'local', 'kbacompany');
    }
    if ($oldversion < 2026101494) {
        // Correct BMS page shell lifecycle and project only explicit main company metric.
        upgrade_plugin_savepoint(true, 2026101494, 'local', 'kbacompany');
    }
    if ($oldversion < 2026101495) {
        // CRUD-001 separate create/detail for metrics and auto Stable Code.
        upgrade_plugin_savepoint(true, 2026101495, 'local', 'kbacompany');
    }
    if ($oldversion < 2026101496) {
        // CRUD-001 CKP ownership picker UX, no schema change.
        upgrade_plugin_savepoint(true, 2026101496, 'local', 'kbacompany');
    }
    if ($oldversion < 2026101497) {
        // CKP form layout: one column, consistent field pairs, no schema changes.
        upgrade_plugin_savepoint(true, 2026101497, 'local', 'kbacompany');
    }
    if ($oldversion < 2026101498) {
        // BMS CRUD one-column metric create form, no database change.
        upgrade_plugin_savepoint(true, 2026101498, 'local', 'kbacompany');
    }
    if ($oldversion < 2026101499) {
        // Restore metric CreateView entry points to canonical BMS CRUD route.
        upgrade_plugin_savepoint(true, 2026101499, 'local', 'kbacompany');
    }
    if ($oldversion < 2026101500) {
        // CRUD ListView: no schema changes.
        upgrade_plugin_savepoint(true, 2026101500, 'local', 'kbacompany');
    }

    if ($oldversion < 2026101501) {
        // CRUD-001 metric DetailView subpanels, no schema changes.
        upgrade_plugin_savepoint(true, 2026101501, 'local', 'kbacompany');
    }

    if ($oldversion < 2026101502) {
        // Unified BMS CRUD EditView for existing metric records; no schema changes.
        upgrade_plugin_savepoint(true, 2026101502, 'local', 'kbacompany');
    }

    if ($oldversion < 2026101503) {
        // CRUD metric cross-navigation and history deep-link; no schema changes.
        upgrade_plugin_savepoint(true, 2026101503, 'local', 'kbacompany');
    }

    if ($oldversion < 2026101504) {
        // Prevent direct editing of archived metric definitions; no database schema change.
        upgrade_plugin_savepoint(true, 2026101504, 'local', 'kbacompany');
    }

    if ($oldversion < 2026101505) {
        // Archived metric related-record write guards; no schema changes.
        upgrade_plugin_savepoint(true, 2026101505, 'local', 'kbacompany');
    }

    if ($oldversion < 2026101506) {
        // Enforce immutable metric Stable Code across legacy update routes.
        upgrade_plugin_savepoint(true, 2026101506, 'local', 'kbacompany');
    }

    if ($oldversion < 2026101507) {
        // Scope the CKP organization owner selector to the BMS CRUD component.
        upgrade_plugin_savepoint(true, 2026101507, 'local', 'kbacompany');
    }

    if ($oldversion < 2026101508) {
        // CKP CRUD target selection validation and registry actions; no schema changes.
        upgrade_plugin_savepoint(true, 2026101508, 'local', 'kbacompany');
    }

    if ($oldversion < 2026101509) {
        // CKP lifecycle: preserve all preexisting products as active; never cascade to metrics.
        $table = new xmldb_table('local_kbacompany_product');
        $field = new xmldb_field('status', XMLDB_TYPE_CHAR, '12', null, XMLDB_NOTNULL, null, 'active', 'acceptance');
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }
        upgrade_plugin_savepoint(true, 2026101509, 'local', 'kbacompany');
    }

    if ($oldversion < 2026101510) {
        // CKP/metric linked DetailViews and proposal-only assistant surfaces; no DDL.
        upgrade_plugin_savepoint(true, 2026101510, 'local', 'kbacompany');
    }

    if ($oldversion < 2026101511) {
        // Scoped field-level proposal-only assistance for CKP and metric CRUD. No DDL.
        upgrade_plugin_savepoint(true, 2026101511, 'local', 'kbacompany');
    }

    if ($oldversion < 2026101512) {
        // Contextual AI field controls for task and battle plan; no DDL.
        upgrade_plugin_savepoint(true, 2026101512, 'local', 'kbacompany');
    }

    if ($oldversion < 2026101513) {
        // Companion local_kbaai domain contract enabled; no schema changes.
        upgrade_plugin_savepoint(true, 2026101513, 'local', 'kbacompany');
    }

    if ($oldversion < 2026101514) {
        // Position editable-field AI assistance and employee draft availability; no DDL.
        upgrade_plugin_savepoint(true, 2026101514, 'local', 'kbacompany');
    }

    if ($oldversion < 2026101515) {
        // Fix task discussion URL: pass task UUID, not task array. No schema changes.
        upgrade_plugin_savepoint(true, 2026101515, 'local', 'kbacompany');
    }

    if ($oldversion < 2026101516) {
        // Human-readable AI field metadata and drawer labels; no DDL.
        upgrade_plugin_savepoint(true, 2026101516, 'local', 'kbacompany');
    }

    if ($oldversion < 2026101517) {
        // Task Detail UX 2.0 only; no DDL.
        upgrade_plugin_savepoint(true, 2026101517, 'local', 'kbacompany');
    }

    if ($oldversion < 2026101518) {
        // Link task assignees to canonical ACL-guarded employee card; no DDL.
        upgrade_plugin_savepoint(true, 2026101518, 'local', 'kbacompany');
    }

    if ($oldversion < 2026101519) {
        // BMS CRUD task creation in battle plans; presentation only, no DDL.
        upgrade_plugin_savepoint(true, 2026101519, 'local', 'kbacompany');
    }

    if ($oldversion < 2026101620) {
        // Battle Plan task creation UX and AI placement; no schema migration.
        upgrade_plugin_savepoint(true, 2026101620, 'local', 'kbacompany');
    }

    if ($oldversion < 2026101621) {
        // Contextual daily/weekly metric fact entry; canonical append-only history, no DDL.
        upgrade_plugin_savepoint(true, 2026101621, 'local', 'kbacompany');
    }

    if ($oldversion < 2026101622) {
        // Manager-only canonical indicator plan entry and day-matched progress; no DDL.
        upgrade_plugin_savepoint(true, 2026101622, 'local', 'kbacompany');
    }

    if ($oldversion < 2026101623) {
        // Restore Word download, show previous-week facts, clarify partial weekly sums; no DDL.
        upgrade_plugin_savepoint(true, 2026101623, 'local', 'kbacompany');
    }

    if ($oldversion < 2026101624) {
        // Polished DOCX: real creator/approver signatures, localized quota header, cleaner tables; no DDL.
        upgrade_plugin_savepoint(true, 2026101624, 'local', 'kbacompany');
    }

    if ($oldversion < 2026101625) {
        // Stream-specific battle plan task create controls and audited active additions; no DDL.
        upgrade_plugin_savepoint(true, 2026101625, 'local', 'kbacompany');
    }

    if ($oldversion < 2026101626) {
        // Recurring-series CRUD UI and language fallback; no schema or data changes.
        upgrade_plugin_savepoint(true, 2026101626, 'local', 'kbacompany');
    }

    if ($oldversion < 2026101627) {
        // UI/layout and language corrections only; no schema changes.
        upgrade_plugin_savepoint(true, 2026101627, 'local', 'kbacompany');
    }

    if ($oldversion < 2026101628) {
        // Recurring-task detail action hierarchy, readiness and archive confirmation; no schema changes.
        upgrade_plugin_savepoint(true, 2026101628, 'local', 'kbacompany');
    }

    if ($oldversion < 2026101629) {
        // Read-only recurring occurrence journal and Battle Plan linkage; no DDL.
        upgrade_plugin_savepoint(true, 2026101629, 'local', 'kbacompany');
    }

    if ($oldversion < 2026101630) {
        // Company-aware recurring series status filter and BMS-owned sidebar route; no DDL.
        upgrade_plugin_savepoint(true, 2026101630, 'local', 'kbacompany');
    }

    if ($oldversion < 2026101631) {
        // Audit the acting editor separately from the original author of the series.
        // Old changes beyond revision 1 cannot be reliably attributed: leave changedby=0.
        $table = new xmldb_table('local_kbacompany_taskrec');
        $field = new xmldb_field('changedby', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0', 'creatorid');
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }
        // Only initial revisions have a proven author in the historical creatorid field.
        $DB->execute("UPDATE {local_kbacompany_taskrec} SET changedby = creatorid
             WHERE revision = :initial AND changedby = :unknown", ['initial' => 1, 'unknown' => 0]);
        upgrade_plugin_savepoint(true, 2026101631, 'local', 'kbacompany');
    }

    if ($oldversion < 2026101632) {
        // Management sidebar navigation: personal-cycle icon and Information Centre link.
        // The existing route, capabilities, company isolation and DB schema are unchanged.
        upgrade_plugin_savepoint(true, 2026101632, 'local', 'kbacompany');
    }

    if ($oldversion < 2026101633) {
        // Position Coverage 01: create empty, company-scoped authorization and audit tables.
        // Do not update posassign, task, recurring definitions, plans or RBAC grants.
        $table = new xmldb_table('local_kbacompany_poscover');
        if (!$dbman->table_exists($table)) {
            $table->add_field('id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE, null);
            $table->add_field('companyuuid', XMLDB_TYPE_CHAR, '36', null, XMLDB_NOTNULL, null, null);
            $table->add_field('uuid', XMLDB_TYPE_CHAR, '36', null, XMLDB_NOTNULL, null, null);
            $table->add_field('positionuuid', XMLDB_TYPE_CHAR, '36', null, XMLDB_NOTNULL, null, null);
            $table->add_field('primaryid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $table->add_field('delegateid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $table->add_field('scope', XMLDB_TYPE_CHAR, '32', null, XMLDB_NOTNULL, null, 'position_work');
            $table->add_field('startdate', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $table->add_field('enddate', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $table->add_field('reason', XMLDB_TYPE_TEXT, null, null, XMLDB_NOTNULL, null, null);
            $table->add_field('status', XMLDB_TYPE_CHAR, '20', null, XMLDB_NOTNULL, null, 'requested');
            $table->add_field('revision', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '1');
            $table->add_field('createdby', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $table->add_field('approvedby', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $table->add_field('approvedat', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $table->add_field('timecreated', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $table->add_field('timemodified', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $table->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);
            $table->add_index('uuid_uix', XMLDB_INDEX_UNIQUE, ['uuid']);
            $table->add_index('company_position_status_ix', XMLDB_INDEX_NOTUNIQUE, ['companyuuid', 'positionuuid', 'status']);
            $table->add_index('company_date_ix', XMLDB_INDEX_NOTUNIQUE, ['companyuuid', 'startdate', 'enddate']);
            $dbman->create_table($table);
        }
        $table = new xmldb_table('local_kbacompany_poscovaudit');
        if (!$dbman->table_exists($table)) {
            $table->add_field('id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE, null);
            $table->add_field('companyuuid', XMLDB_TYPE_CHAR, '36', null, XMLDB_NOTNULL, null, null);
            $table->add_field('coverageuuid', XMLDB_TYPE_CHAR, '36', null, XMLDB_NOTNULL, null, null);
            $table->add_field('revision', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '1');
            $table->add_field('actorid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $table->add_field('event', XMLDB_TYPE_CHAR, '20', null, XMLDB_NOTNULL, null, 'requested');
            $table->add_field('notes', XMLDB_TYPE_TEXT, null, null, null, null, null);
            $table->add_field('timecreated', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $table->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);
            $table->add_index('company_cov_rev_uix', XMLDB_INDEX_UNIQUE, ['companyuuid', 'coverageuuid', 'revision']);
            $table->add_index('company_actor_ix', XMLDB_INDEX_NOTUNIQUE, ['companyuuid', 'actorid']);
            $dbman->create_table($table);
        }
        upgrade_plugin_savepoint(true, 2026101633, 'local', 'kbacompany');
    }

    if ($oldversion < 2026101634) {
        // Explicit duty classification only. Legacy tasks default to personal.
        // Neither assigneeid nor approved Battle Plans are rewritten.
        $table = new xmldb_table('local_kbacompany_task');
        $field = new xmldb_field('dutypositionuuid', XMLDB_TYPE_CHAR, '36', null, XMLDB_NOTNULL, null, '');
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }
        $index = new xmldb_index('duty_ix', XMLDB_INDEX_NOTUNIQUE,
            ['companyuuid', 'dutypositionuuid', 'assigneeid']);
        if (!$dbman->index_exists($table, $index)) {
            $dbman->add_index($table, $index);
        }
        upgrade_plugin_savepoint(true, 2026101634, 'local', 'kbacompany');
    }

    if ($oldversion < 2026101635) {
        // Responsibility UX fix: edit/create actions use the existing dutypositionuuid column.
        // No schema change, bulk reclassification, or assignee migration.
        upgrade_plugin_savepoint(true, 2026101635, 'local', 'kbacompany');
    }

    if ($oldversion < 2026101636) {
        // Explicit recurring-series position ownership. Existing series remain personal.
        // Task instances are NOT rewritten or bulk reclassified.
        $table = new xmldb_table('local_kbacompany_taskrec');
        $field = new xmldb_field('dutypositionuuid', XMLDB_TYPE_CHAR, '36', null, XMLDB_NOTNULL, null, '');
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }
        $index = new xmldb_index('duty_position_ix', XMLDB_INDEX_NOTUNIQUE,
            ['companyuuid', 'dutypositionuuid', 'status']);
        if (!$dbman->index_exists($table, $index)) {
            $dbman->add_index($table, $index);
        }
        upgrade_plugin_savepoint(true, 2026101636, 'local', 'kbacompany');
    }

    if ($oldversion < 2026101637) {
        // Read-only Battle Plan coverage context; no changes to permissions or data storage.
        upgrade_plugin_savepoint(true, 2026101637, 'local', 'kbacompany');
    }

    if ($oldversion < 2026101638) {
        // Coverage work dashboard is read-only: no new tables, grants or task migrations.
        upgrade_plugin_savepoint(true, 2026101638, 'local', 'kbacompany');
    }

    if ($oldversion < 2026101639) {
        // Coverage lifecycle safety / Task Center resolver hardening. No DDL.
        upgrade_plugin_savepoint(true, 2026101639, 'local', 'kbacompany');
    }

    if ($oldversion < 2026101640) {
        // Plugin-owned UI shell and design tokens; opt-in only, no schema change.
        upgrade_plugin_savepoint(true, 2026101640, 'local', 'kbacompany');
    }
    if ($oldversion < 2026101641) {
        // BMS24 UI Kit 1.0: scoped presentation components only, no DDL.
        upgrade_plugin_savepoint(true, 2026101641, 'local', 'kbacompany');
    }
    if ($oldversion < 2026101642) {
        // BMS24 W3 Task Center owns its presentation fragments; no DDL or ACL changes.
        upgrade_plugin_savepoint(true, 2026101642, 'local', 'kbacompany');
    }
    if ($oldversion < 2026101643) {
        // BMS24 W3.2 Recurring series owned UI presentation; no DDL or ACL changes.
        upgrade_plugin_savepoint(true, 2026101643, 'local', 'kbacompany');
    }
    if ($oldversion < 2026101644) {
        // Release checkpoint: Task Center: ссылка из исполнителя в заголовке задачи на каноническую карточку сотрудника.
        // No DDL is performed by this checkpoint itself.
        upgrade_plugin_savepoint(true, 2026101644, 'local', 'kbacompany');
    }

    return true;
}
