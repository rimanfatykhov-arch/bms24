<?php
defined('MOODLE_INTERNAL') || die();
function xmldb_local_kbaai_upgrade($oldversion) {
    global $DB;
    $dbman=$DB->get_manager();
    if ($oldversion < 2026081501) { upgrade_plugin_savepoint(true,2026081501,'local','kbaai'); }
    if ($oldversion < 2026081506) {
        $table=new xmldb_table('local_kbaai_supervisor');
        if (!$dbman->table_exists($table)) {
            $table->add_field('id',XMLDB_TYPE_INTEGER,'10',null,XMLDB_NOTNULL,XMLDB_SEQUENCE,null);
            $table->add_field('courseid',XMLDB_TYPE_INTEGER,'10',null,XMLDB_NOTNULL,null,'0');
            $table->add_field('enabled',XMLDB_TYPE_INTEGER,'1',null,XMLDB_NOTNULL,null,'1');
            $table->add_field('away',XMLDB_TYPE_INTEGER,'1',null,XMLDB_NOTNULL,null,'0');
            $table->add_field('supervisoruserid',XMLDB_TYPE_INTEGER,'10',null,XMLDB_NOTNULL,null,'0');
            $table->add_field('instructions',XMLDB_TYPE_TEXT,null,null,null,null,null);
            $table->add_field('timecreated',XMLDB_TYPE_INTEGER,'10',null,XMLDB_NOTNULL,null,'0');
            $table->add_field('timemodified',XMLDB_TYPE_INTEGER,'10',null,XMLDB_NOTNULL,null,'0');
            $table->add_key('primary',XMLDB_KEY_PRIMARY,array('id'));
            $table->add_index('course_uix',XMLDB_INDEX_UNIQUE,array('courseid'));
            $dbman->create_table($table);
        }
        $table=new xmldb_table('local_kbaai_supreply');
        if (!$dbman->table_exists($table)) {
            $table->add_field('id',XMLDB_TYPE_INTEGER,'10',null,XMLDB_NOTNULL,XMLDB_SEQUENCE,null);
            $table->add_field('courseid',XMLDB_TYPE_INTEGER,'10',null,XMLDB_NOTNULL,null,'0');
            $table->add_field('submissionid',XMLDB_TYPE_INTEGER,'10',null,XMLDB_NOTNULL,null,'0');
            $table->add_field('sourcemessageid',XMLDB_TYPE_INTEGER,'10',null,XMLDB_NOTNULL,null,'0');
            $table->add_field('replymessageid',XMLDB_TYPE_INTEGER,'10',null,XMLDB_NOTNULL,null,'0');
            $table->add_field('provider',XMLDB_TYPE_CHAR,'32',null,XMLDB_NOTNULL,null,'mock');
            $table->add_field('model',XMLDB_TYPE_CHAR,'100',null,XMLDB_NOTNULL,null,'');
            $table->add_field('status',XMLDB_TYPE_CHAR,'20',null,XMLDB_NOTNULL,null,'queued');
            $table->add_field('requiressupervisor',XMLDB_TYPE_INTEGER,'1',null,XMLDB_NOTNULL,null,'0');
            $table->add_field('reason',XMLDB_TYPE_TEXT,null,null,null,null,null);
            $table->add_field('lasterror',XMLDB_TYPE_TEXT,null,null,null,null,null);
            $table->add_field('timecreated',XMLDB_TYPE_INTEGER,'10',null,XMLDB_NOTNULL,null,'0');
            $table->add_field('timemodified',XMLDB_TYPE_INTEGER,'10',null,XMLDB_NOTNULL,null,'0');
            $table->add_field('timecompleted',XMLDB_TYPE_INTEGER,'10',null,XMLDB_NOTNULL,null,'0');
            $table->add_key('primary',XMLDB_KEY_PRIMARY,array('id'));
            $table->add_index('source_uix',XMLDB_INDEX_UNIQUE,array('sourcemessageid'));
            $table->add_index('course_status_ix',XMLDB_INDEX_NOTUNIQUE,array('courseid','status'));
            $dbman->create_table($table);
        }
        upgrade_plugin_savepoint(true,2026081506,'local','kbaai');
    }
    if ($oldversion < 2026081509) {
        // dev4.3 only changes context-building logic and administrator settings.
        upgrade_plugin_savepoint(true,2026081509,'local','kbaai');
    }
    if ($oldversion < 2026081510) {
        // dev4.4 hardens Yandex production connectivity and diagnostics; no database schema change.
        upgrade_plugin_savepoint(true,2026081510,'local','kbaai');
    }
    if ($oldversion < 2026081511) {
        // dev4.5 hardens multimodal Yandex parsing/diagnostics; no database schema change.
        upgrade_plugin_savepoint(true,2026081511,'local','kbaai');
    }
    if ($oldversion < 2026081512) {
        // Product version 0.5.0: add administrator-visible version/build information; no database schema change.
        upgrade_plugin_savepoint(true,2026081512,'local','kbaai');
    }
    if ($oldversion < 2026081513) {
        // Product version 0.5.1: versioned diagnostics/provider class to bypass stale PHP OPcache; no schema change.
        upgrade_plugin_savepoint(true,2026081513,'local','kbaai');
    }
    if ($oldversion < 2026081514) {
        // Product version 0.5.2: public read-only assessment bridge for KBA Element; no schema change.
        upgrade_plugin_savepoint(true,2026081514,'local','kbaai');
    }
    if ($oldversion < 2026081515) {
        // Product version 0.6.0: PDF extraction via Yandex Vision OCR; no database schema change.
        upgrade_plugin_savepoint(true,2026081515,'local','kbaai');
    }
    if ($oldversion < 2026081516) {
        // Product version 0.6.1: notify learners only after AI results are actually published.
        upgrade_plugin_savepoint(true,2026081516,'local','kbaai');
    }
    if ($oldversion < 2026081517) {
        // Product version 0.7.0: local DOCX extraction plus multimodal analysis of embedded images; no schema change.
        upgrade_plugin_savepoint(true,2026081517,'local','kbaai');
    }
    if ($oldversion < 2026081518) {
        // Product version 0.7.1: fix PDF OCR by using application/pdf and asynchronous Yandex OCR flow; no schema change.
        upgrade_plugin_savepoint(true,2026081518,'local','kbaai');
    }
    if ($oldversion < 2026081519) {
        // Product version 0.8.0: safe local XLSX extraction; no database schema change.
        upgrade_plugin_savepoint(true,2026081519,'local','kbaai');
    }
    if ($oldversion < 2026081520) {
        // Product version 0.8.1: make quick vs full provider diagnostics explicit; no schema change.
        upgrade_plugin_savepoint(true,2026081520,'local','kbaai');
    }
    if ($oldversion < 2026081521) {
        // Product version 0.8.2: split administration into permanent sections and diagnostics pages; no schema change.
        upgrade_plugin_savepoint(true,2026081521,'local','kbaai');
    }
    if ($oldversion < 2026081522) {
        // Product version 0.8.3: harmonise learner-facing supervisor assistant naming; no schema change.
        upgrade_plugin_savepoint(true,2026081522,'local','kbaai');
    }
    if ($oldversion < 2026081602) {
        // 0.9.0 — visual rubric tables and grading-source tracking.
        $table = new xmldb_table('local_kbaai_rubric');
        if (!$dbman->table_exists($table)) {
            $table->add_field('id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE, null);
            $table->add_field('kbaelementid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $table->add_field('versionno', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '1');
            $table->add_field('totalscore', XMLDB_TYPE_NUMBER, '12,5', null, XMLDB_NOTNULL, null, '0');
            $table->add_field('valid', XMLDB_TYPE_INTEGER, '1', null, XMLDB_NOTNULL, null, '0');
            $table->add_field('modifiedby', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $table->add_field('timecreated', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $table->add_field('timemodified', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $table->add_key('primary', XMLDB_KEY_PRIMARY, array('id'));
            $table->add_index('kbaelement_uix', XMLDB_INDEX_UNIQUE, array('kbaelementid'));
            $dbman->create_table($table);
        }

        $table = new xmldb_table('local_kbaai_rubric_item');
        if (!$dbman->table_exists($table)) {
            $table->add_field('id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE, null);
            $table->add_field('rubricid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $table->add_field('criterionkey', XMLDB_TYPE_CHAR, '32', null, XMLDB_NOTNULL, null, '');
            $table->add_field('sortorder', XMLDB_TYPE_INTEGER, '6', null, XMLDB_NOTNULL, null, '0');
            $table->add_field('title', XMLDB_TYPE_CHAR, '255', null, XMLDB_NOTNULL, null, '');
            $table->add_field('description', XMLDB_TYPE_TEXT, null, null, null, null, null);
            $table->add_field('maxscore', XMLDB_TYPE_NUMBER, '12,5', null, XMLDB_NOTNULL, null, '0');
            $table->add_field('required', XMLDB_TYPE_INTEGER, '1', null, XMLDB_NOTNULL, null, '1');
            $table->add_field('timecreated', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $table->add_field('timemodified', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $table->add_key('primary', XMLDB_KEY_PRIMARY, array('id'));
            $table->add_key('rubric_fk', XMLDB_KEY_FOREIGN, array('rubricid'), 'local_kbaai_rubric', array('id'));
            $table->add_index('rubric_key_uix', XMLDB_INDEX_UNIQUE, array('rubricid', 'criterionkey'));
            $table->add_index('rubric_sort_ix', XMLDB_INDEX_NOTUNIQUE, array('rubricid', 'sortorder'));
            $dbman->create_table($table);
        }

        $table = new xmldb_table('local_kbaai_rubric_level');
        if (!$dbman->table_exists($table)) {
            $table->add_field('id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE, null);
            $table->add_field('itemid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $table->add_field('sortorder', XMLDB_TYPE_INTEGER, '6', null, XMLDB_NOTNULL, null, '0');
            $table->add_field('score', XMLDB_TYPE_NUMBER, '12,5', null, XMLDB_NOTNULL, null, '0');
            $table->add_field('label', XMLDB_TYPE_CHAR, '255', null, XMLDB_NOTNULL, null, '');
            $table->add_field('description', XMLDB_TYPE_TEXT, null, null, null, null, null);
            $table->add_field('timecreated', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $table->add_field('timemodified', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $table->add_key('primary', XMLDB_KEY_PRIMARY, array('id'));
            $table->add_key('item_fk', XMLDB_KEY_FOREIGN, array('itemid'), 'local_kbaai_rubric_item', array('id'));
            $table->add_index('item_sort_ix', XMLDB_INDEX_NOTUNIQUE, array('itemid', 'sortorder'));
            $dbman->create_table($table);
        }

        $table = new xmldb_table('local_kbaai_assessment');
        $field = new xmldb_field('gradingsource', XMLDB_TYPE_CHAR, '32', null, XMLDB_NOTNULL, null, '', 'publishstatus');
        if ($dbman->table_exists($table) && !$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }

        upgrade_plugin_savepoint(true, 2026081602, 'local', 'kbaai');
    }
    if ($oldversion < 2026092201) {
        // BMS Assistant Profile Foundation. No schema changes.
        upgrade_plugin_savepoint(true, 2026092201, 'local', 'kbaai');
    }
    if ($oldversion < 2026092212) {
        // BMS Assistant Recommendation Contract. No schema changes.
        upgrade_plugin_savepoint(true, 2026092212, 'local', 'kbaai');
    }
    // Schema-neutral assistant release.
    if ($oldversion < 2026092213) {
        upgrade_plugin_savepoint(true, 2026092213, 'local', 'kbaai');
    }

    if ($oldversion < 2026101513) {
        // Advertise explicit BMS domains; proposal-only, no schema changes.
        upgrade_plugin_savepoint(true, 2026101513, 'local', 'kbaai');
    }

    if ($oldversion < 2026101516) {
        // Honor BMS semantic field labels in AI prompt; no DDL.
        upgrade_plugin_savepoint(true, 2026101516, 'local', 'kbaai');
    }

    return true;
}
