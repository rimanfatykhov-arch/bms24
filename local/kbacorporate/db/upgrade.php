<?php
// This file is part of Moodle - http://moodle.org/

defined('MOODLE_INTERNAL') || die();
require_once(__DIR__ . '/../classes/local/economic_indicators.php');

/**
 * Upgrade steps for KBA Corporate Dashboard.
 *
 * @param int $oldversion
 * @return bool
 */
function xmldb_local_kbacorporate_upgrade($oldversion) {
    global $DB;

    $dbman = $DB->get_manager();

    if ($oldversion < 2026081400) {
        $table = new xmldb_table('local_kbacorp_company');

        if ($dbman->table_exists($table)) {
            $oldfield = new xmldb_field(
                'bitrix_company_id',
                XMLDB_TYPE_CHAR,
                '100',
                null,
                false,
                false,
                null,
                'code'
            );
            $newfield = new xmldb_field(
                'bitrixcompanyid',
                XMLDB_TYPE_CHAR,
                '100',
                null,
                false,
                false,
                null,
                'code'
            );
            $oldindex = new xmldb_index('bitrix_uix', XMLDB_INDEX_NOTUNIQUE, ['bitrix_company_id']);
            $newindex = new xmldb_index('bitrix_uix', XMLDB_INDEX_NOTUNIQUE, ['bitrixcompanyid']);

            $hasold = $dbman->field_exists($table, $oldfield);
            $hasnew = $dbman->field_exists($table, $newfield);

            // v0.1.0 used bitrix_company_id. Rename it to the canonical Moodle-style name.
            if ($hasold && !$hasnew) {
                if ($dbman->index_exists($table, $oldindex)) {
                    $dbman->drop_index($table, $oldindex);
                }
                $dbman->rename_field($table, $oldfield, 'bitrixcompanyid');
                $hasold = false;
                $hasnew = true;
            } else if (!$hasnew) {
                // Defensive repair for partially-created installations.
                $dbman->add_field($table, $newfield);
                $hasnew = true;
            }

            // If both names exist after a partial/manual repair, preserve old values then remove old field.
            if ($hasold && $hasnew) {
                $DB->execute(
                    'UPDATE {local_kbacorp_company} ' .
                    'SET bitrixcompanyid = bitrix_company_id ' .
                    'WHERE bitrixcompanyid IS NULL ' .
                    'AND bitrix_company_id IS NOT NULL'
                );
                if ($dbman->index_exists($table, $oldindex)) {
                    $dbman->drop_index($table, $oldindex);
                }
                $dbman->drop_field($table, $oldfield);
            }

            if (!$dbman->index_exists($table, $newindex)) {
                $dbman->add_index($table, $newindex);
            }
        }

        upgrade_plugin_savepoint(true, 2026081400, 'local', 'kbacorporate');
    }


    if ($oldversion < 2026081402) {
        // v0.1.3 only adds a role-aware navbar shortcut; no DB changes.
        upgrade_plugin_savepoint(true, 2026081402, 'local', 'kbacorporate');
    }


    if ($oldversion < 2026081403) {
        // v0.2.0 adds read-only corporate workspace pages and dashboard UI.
        // No database schema changes are required.
        upgrade_plugin_savepoint(true, 2026081403, 'local', 'kbacorporate');
    }


    if ($oldversion < 2026081404) {
        // v0.3.0 adds the BigBlueButton attendance adapter and webinar views.
        // It reads Moodle core BigBlueButton tables and requires no local DB changes.
        upgrade_plugin_savepoint(true, 2026081404, 'local', 'kbacorporate');
    }


    if ($oldversion < 2026081405) {
        $table = new xmldb_table('local_kbacorp_company');
        if ($dbman->table_exists($table)) {
            $minutesfield = new xmldb_field(
                'trainingminutesday', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '90', 'active'
            );
            if (!$dbman->field_exists($table, $minutesfield)) {
                $dbman->add_field($table, $minutesfield);
            }
            $daysfield = new xmldb_field(
                'trainingweekdays', XMLDB_TYPE_CHAR, '20', null, XMLDB_NOTNULL, null, '1,2,3,4,5', 'trainingminutesday'
            );
            if (!$dbman->field_exists($table, $daysfield)) {
                $dbman->add_field($table, $daysfield);
            }
        }

        // Do not assign the new capability here. During plugin upgrade Moodle
        // registers db/access.php capabilities after xmldb upgrade steps.
        // db/access.php uses clonepermissionsfrom to grant existing roles the
        // same permission they already have for viewprogress.
        upgrade_plugin_savepoint(true, 2026081405, 'local', 'kbacorporate');
    }

    if ($oldversion < 2026081406) {
        // v0.4.1 fixes capability registration order for upgrades from v0.3.x.
        // No schema changes are required in this step.
        upgrade_plugin_savepoint(true, 2026081406, 'local', 'kbacorporate');
    }

    if ($oldversion < 2026081407) {
        // v0.4.2 adds the public BMS Connect bridge API and repository helpers.
        // No database schema changes are required.
        upgrade_plugin_savepoint(true, 2026081407, 'local', 'kbacorporate');
    }

    if ($oldversion < 2026081408) {
        // v0.4.3 unifies the BMS public bridge and legacy manage.php endpoint.
        // No database schema changes are required.
        upgrade_plugin_savepoint(true, 2026081408, 'local', 'kbacorporate');
    }

    if ($oldversion < 2026081500) {
        // v0.4.4 formalises Public API v1 and adds read-only API diagnostics.
        // No database schema changes are required.
        upgrade_plugin_savepoint(true, 2026081500, 'local', 'kbacorporate');
    }

    if ($oldversion < 2026081501) {
        // Compatibility savepoint for the parallel company-course snapshot build.
        // No database schema changes are required.
        upgrade_plugin_savepoint(true, 2026081501, 'local', 'kbacorporate');
    }

    if ($oldversion < 2026081502) {
        // v0.4.5 unifies Public API v1 diagnostics/write methods with the
        // read-only BMS company-course snapshot API (Public API v1.1).
        // No database schema changes are required.
        upgrade_plugin_savepoint(true, 2026081502, 'local', 'kbacorporate');
    }

    if ($oldversion < 2026081503) {
        // v0.5.0 adds the atomic BMS training-assignment API, catalogue sync
        // and soft course deactivation. No database schema changes are required.
        upgrade_plugin_savepoint(true, 2026081503, 'local', 'kbacorporate');
    }

    if ($oldversion < 2026081504) {
        // v0.6.0 expands learning-time analytics with period presets, weekly
        // dynamics, plan variance/status and richer CSV exports. No database
        // schema changes are required.
        upgrade_plugin_savepoint(true, 2026081504, 'local', 'kbacorporate');
    }

    if ($oldversion < 2026081505) {
        // v0.7.0 adds BigBlueButton Analytics 2.0: Summary engagement metrics,
        // presence coverage, first-join/lateness analysis, webinar CSV export
        // and Public API v1.4. No database schema changes are required.
        upgrade_plugin_savepoint(true, 2026081505, 'local', 'kbacorporate');
    }


    if ($oldversion < 2026081506) {
        // v0.8.0 adds the optional mod_customcert adapter, corporate certificate
        // registry, secure issued-PDF download proxy, CSV export and Public API
        // v1.5. No local database schema changes are required.
        upgrade_plugin_savepoint(true, 2026081506, 'local', 'kbacorporate');
    }

    if ($oldversion < 2026081507) {
        // v0.8.1 adds an administrator-facing version information page that
        // compares the plugin build on disk with the version stored by Moodle.
        // No database schema changes are required.
        upgrade_plugin_savepoint(true, 2026081507, 'local', 'kbacorporate');
    }

    if ($oldversion < 2026081508) {
        // v0.8.2 makes course names clickable throughout Corporate Dashboard.
        // No database schema changes are required.
        upgrade_plugin_savepoint(true, 2026081508, 'local', 'kbacorporate');
    }


    if ($oldversion < 2026081509) {
        // v0.9.0 adds the unified report centre, multi-sheet XLSX export,
        // executive PDF report and Public API v1.6 management snapshot.
        // No database schema changes are required.
        upgrade_plugin_savepoint(true, 2026081509, 'local', 'kbacorporate');
    }


    if ($oldversion < 2026081510) {
        // v0.10.0 adds company departments and department-level manager scope.
        $departmenttable = new xmldb_table('local_kbacorp_department');
        if (!$dbman->table_exists($departmenttable)) {
            $departmenttable->add_field('id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE, null);
            $departmenttable->add_field('companyid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
            $departmenttable->add_field('name', XMLDB_TYPE_CHAR, '255', null, XMLDB_NOTNULL, null, null);
            $departmenttable->add_field('code', XMLDB_TYPE_CHAR, '100', null, null, null, null);
            $departmenttable->add_field('active', XMLDB_TYPE_INTEGER, '1', null, XMLDB_NOTNULL, null, '1');
            $departmenttable->add_field('timecreated', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $departmenttable->add_field('timemodified', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $departmenttable->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);
            $departmenttable->add_key('company_fk', XMLDB_KEY_FOREIGN, ['companyid'], 'local_kbacorp_company', ['id']);
            $departmenttable->add_index('company_code_ix', XMLDB_INDEX_NOTUNIQUE, ['companyid', 'code']);
            $departmenttable->add_index('active_ix', XMLDB_INDEX_NOTUNIQUE, ['active']);
            $dbman->create_table($departmenttable);
        }

        $membertable = new xmldb_table('local_kbacorp_dept_member');
        if (!$dbman->table_exists($membertable)) {
            $membertable->add_field('id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE, null);
            $membertable->add_field('departmentid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
            $membertable->add_field('userid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
            $membertable->add_field('deptrole', XMLDB_TYPE_CHAR, '20', null, XMLDB_NOTNULL, null, 'employee');
            $membertable->add_field('timecreated', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $membertable->add_field('timemodified', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $membertable->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);
            $membertable->add_key('department_fk', XMLDB_KEY_FOREIGN, ['departmentid'], 'local_kbacorp_department', ['id']);
            $membertable->add_key('user_fk', XMLDB_KEY_FOREIGN, ['userid'], 'user', ['id']);
            $membertable->add_index('dept_user_role_uix', XMLDB_INDEX_UNIQUE, ['departmentid', 'userid', 'deptrole']);
            $membertable->add_index('role_ix', XMLDB_INDEX_NOTUNIQUE, ['deptrole']);
            $dbman->create_table($membertable);
        }

        upgrade_plugin_savepoint(true, 2026081510, 'local', 'kbacorporate');
    }

    if ($oldversion < 2026081511) {
        // v0.10.1 fixes XMLDB key/index collisions in the v0.10.0 department
        // table upgrade definition. No additional schema changes are required.
        upgrade_plugin_savepoint(true, 2026081511, 'local', 'kbacorporate');
    }

    if ($oldversion < 2026081512) {
        // v0.11.0 adds per-company notification scheduling. Risk signals are
        // calculated live and are not duplicated in a separate local table.
        $table = new xmldb_table('local_kbacorp_company');
        if ($dbman->table_exists($table)) {
            $enabledfield = new xmldb_field(
                'alertsenabled', XMLDB_TYPE_INTEGER, '1', null, XMLDB_NOTNULL, null, '0', 'trainingweekdays'
            );
            if (!$dbman->field_exists($table, $enabledfield)) {
                $dbman->add_field($table, $enabledfield);
            }
            $frequencyfield = new xmldb_field(
                'alertfrequency', XMLDB_TYPE_CHAR, '20', null, XMLDB_NOTNULL, null, 'weekly', 'alertsenabled'
            );
            if (!$dbman->field_exists($table, $frequencyfield)) {
                $dbman->add_field($table, $frequencyfield);
            }
            $lastsentfield = new xmldb_field(
                'lastalertsent', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0', 'alertfrequency'
            );
            if (!$dbman->field_exists($table, $lastsentfield)) {
                $dbman->add_field($table, $lastsentfield);
            }
        }
        upgrade_plugin_savepoint(true, 2026081512, 'local', 'kbacorporate');
    }


    if ($oldversion < 2026081513) {
        // v0.12.0 adds corporate licence packages and employee seat allocations.
        // The upgrade is idempotent so it can safely resume after a partial run.
        $licensetable = new xmldb_table('local_kbacorp_license');
        if (!$dbman->table_exists($licensetable)) {
            $licensetable->add_field('id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE, null);
            $licensetable->add_field('companyid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
            $licensetable->add_field('courseid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
            $licensetable->add_field('groupid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $licensetable->add_field('seats', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $licensetable->add_field('datefrom', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $licensetable->add_field('dateto', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $licensetable->add_field('source', XMLDB_TYPE_CHAR, '20', null, XMLDB_NOTNULL, null, 'manual');
            $licensetable->add_field('externaldealid', XMLDB_TYPE_CHAR, '100', null, XMLDB_NOTNULL, null, '');
            $licensetable->add_field('externalrowid', XMLDB_TYPE_CHAR, '100', null, XMLDB_NOTNULL, null, '');
            $licensetable->add_field('status', XMLDB_TYPE_CHAR, '20', null, XMLDB_NOTNULL, null, 'active');
            $licensetable->add_field('timecreated', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $licensetable->add_field('timemodified', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $licensetable->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);
            $licensetable->add_key('company_fk', XMLDB_KEY_FOREIGN, ['companyid'], 'local_kbacorp_company', ['id']);
            $licensetable->add_key('course_fk', XMLDB_KEY_FOREIGN, ['courseid'], 'course', ['id']);
            $licensetable->add_index('company_course_group_ix', XMLDB_INDEX_NOTUNIQUE, ['companyid', 'courseid', 'groupid']);
            $licensetable->add_index('external_ref_ix', XMLDB_INDEX_NOTUNIQUE,
                ['companyid', 'source', 'externaldealid', 'externalrowid']);
            $licensetable->add_index('status_dates_ix', XMLDB_INDEX_NOTUNIQUE, ['status', 'datefrom', 'dateto']);
            $dbman->create_table($licensetable);
        }

        $allocationtable = new xmldb_table('local_kbacorp_license_user');
        if (!$dbman->table_exists($allocationtable)) {
            $allocationtable->add_field('id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE, null);
            $allocationtable->add_field('licenseid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
            $allocationtable->add_field('userid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
            $allocationtable->add_field('status', XMLDB_TYPE_CHAR, '20', null, XMLDB_NOTNULL, null, 'assigned');
            $allocationtable->add_field('assignedby', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $allocationtable->add_field('timeassigned', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $allocationtable->add_field('timestarted', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $allocationtable->add_field('timecompleted', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $allocationtable->add_field('timereleased', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $allocationtable->add_field('timecreated', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $allocationtable->add_field('timemodified', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $allocationtable->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);
            $allocationtable->add_key('license_fk', XMLDB_KEY_FOREIGN, ['licenseid'], 'local_kbacorp_license', ['id']);
            $allocationtable->add_key('user_fk', XMLDB_KEY_FOREIGN, ['userid'], 'user', ['id']);
            $allocationtable->add_index('license_user_uix', XMLDB_INDEX_UNIQUE, ['licenseid', 'userid']);
            $allocationtable->add_index('status_ix', XMLDB_INDEX_NOTUNIQUE, ['status']);
            $allocationtable->add_index('user_status_ix', XMLDB_INDEX_NOTUNIQUE, ['userid', 'status']);
            $dbman->create_table($allocationtable);
        }

        // Preserve all existing corporate course mappings as unlimited legacy
        // packages. This keeps v0.11 behaviour until BMS or an administrator
        // supplies a finite commercial contract.
        if ($dbman->table_exists($licensetable) && $dbman->table_exists($allocationtable)) {
            $now = time();
            $mappings = $DB->get_records('local_kbacorp_course', [], 'id ASC');
            foreach ($mappings as $mapping) {
                $legacy = $DB->get_record('local_kbacorp_license', [
                    'companyid' => (int)$mapping->companyid,
                    'courseid' => (int)$mapping->courseid,
                    'groupid' => (int)$mapping->groupid,
                    'source' => 'legacy',
                ]);
                if (!$legacy) {
                    $legacy = (object)[
                        'companyid' => (int)$mapping->companyid,
                        'courseid' => (int)$mapping->courseid,
                        'groupid' => (int)$mapping->groupid,
                        'seats' => 0,
                        'datefrom' => 0,
                        'dateto' => 0,
                        'source' => 'legacy',
                        'externaldealid' => '',
                        'externalrowid' => '',
                        'status' => !empty($mapping->active) ? 'active' : 'inactive',
                        'timecreated' => $now,
                        'timemodified' => $now,
                    ];
                    $legacy->id = $DB->insert_record('local_kbacorp_license', $legacy);
                }

                $params = [
                    'companyid' => (int)$mapping->companyid,
                    'membertype' => 'employee',
                    'courseid' => (int)$mapping->courseid,
                    'uestatus' => 0,
                    'enrolstatus' => 0,
                ];
                $groupsql = '';
                if (!empty($mapping->groupid)) {
                    $groupsql = ' JOIN {groups_members} gm ON gm.userid = m.userid AND gm.groupid = :groupid';
                    $params['groupid'] = (int)$mapping->groupid;
                }
                $sql = "SELECT DISTINCT m.userid
                          FROM {local_kbacorp_member} m
                          JOIN {user_enrolments} ue ON ue.userid = m.userid AND ue.status = :uestatus
                          JOIN {enrol} e ON e.id = ue.enrolid AND e.courseid = :courseid AND e.status = :enrolstatus
                          {$groupsql}
                         WHERE m.companyid = :companyid
                           AND m.membertype = :membertype";
                $userids = $DB->get_fieldset_sql($sql, $params);
                foreach ($userids as $userid) {
                    if ($DB->record_exists('local_kbacorp_license_user', [
                        'licenseid' => (int)$legacy->id,
                        'userid' => (int)$userid,
                    ])) {
                        continue;
                    }
                    $DB->insert_record('local_kbacorp_license_user', (object)[
                        'licenseid' => (int)$legacy->id,
                        'userid' => (int)$userid,
                        'status' => 'assigned',
                        'assignedby' => 0,
                        'timeassigned' => $now,
                        'timestarted' => 0,
                        'timecompleted' => 0,
                        'timereleased' => 0,
                        'timecreated' => $now,
                        'timemodified' => $now,
                    ]);
                }
            }
        }

        upgrade_plugin_savepoint(true, 2026081513, 'local', 'kbacorporate');
    }


    if ($oldversion < 2026081600) {
        // v0.13.0 integrates KBA Gamification through its public internal API.
        // No local schema changes are required. The new viewgamification capability
        // clones viewprogress permissions through db/access.php during capability refresh.
        upgrade_plugin_savepoint(true, 2026081600, 'local', 'kbacorporate');
    }

    if ($oldversion < 2026081601) {
        // v0.14.0 improves corporate gamification UX. No schema changes.
        upgrade_plugin_savepoint(true, 2026081601, 'local', 'kbacorporate');
    }

    if ($oldversion < 2026081602) {
        // v0.14.1 forces an upgrade/cache refresh for the engagement UX assets and strings.
        // No schema changes are required.
        upgrade_plugin_savepoint(true, 2026081602, 'local', 'kbacorporate');
    }


    if ($oldversion < 2026081800) {
        // v0.14.2.1 FIX: one company membership per Moodle user, with independent
        // employee/manager role flags. The migration is deliberately idempotent
        // so an interrupted production upgrade can be resumed safely.
        $table = new xmldb_table('local_kbacorp_member');
        if ($dbman->table_exists($table)) {
            $employeefield = new xmldb_field(
                'isemployee', XMLDB_TYPE_INTEGER, '1', null, XMLDB_NOTNULL, null, '0', 'membertype'
            );
            if (!$dbman->field_exists($table, $employeefield)) {
                $dbman->add_field($table, $employeefield);
            }

            $managerfield = new xmldb_field(
                'ismanager', XMLDB_TYPE_INTEGER, '1', null, XMLDB_NOTNULL, null, '0', 'isemployee'
            );
            if (!$dbman->field_exists($table, $managerfield)) {
                $dbman->add_field($table, $managerfield);
            }

            // Import the legacy role encoded in membertype. Re-running these
            // statements only sets existing flags to 1 and is therefore safe.
            $DB->execute("UPDATE {local_kbacorp_member} SET isemployee = 1 WHERE membertype = :employee", [
                'employee' => 'employee',
            ]);
            $DB->execute("UPDATE {local_kbacorp_member} SET ismanager = 1 WHERE membertype = :manager", [
                'manager' => 'manager',
            ]);

            // Merge duplicate company/user rows before changing the unique key.
            $rows = $DB->get_records('local_kbacorp_member', [], 'companyid ASC, userid ASC, id ASC');
            $groups = [];
            foreach ($rows as $row) {
                $key = (int)$row->companyid . ':' . (int)$row->userid;
                if (!isset($groups[$key])) {
                    $groups[$key] = [];
                }
                $groups[$key][] = $row;
            }

            $roleid = (int)get_config('local_kbacorporate', 'corporatemanagerroleid');
            if (!$roleid) {
                $role = $DB->get_record('role', ['shortname' => 'kbacorporatemanager']);
                $roleid = $role ? (int)$role->id : 0;
            }
            $systemcontext = context_system::instance();

            foreach ($groups as $group) {
                if (!$group) {
                    continue;
                }
                $isemployee = 0;
                $ismanager = 0;
                $keeper = null;
                foreach ($group as $row) {
                    $rowemployee = !empty($row->isemployee) || $row->membertype === 'employee';
                    $rowmanager = !empty($row->ismanager) || $row->membertype === 'manager';
                    $isemployee = ($isemployee || $rowemployee) ? 1 : 0;
                    $ismanager = ($ismanager || $rowmanager) ? 1 : 0;
                    // Prefer the existing employee row so legacy employee reports
                    // retain the same membership id whenever possible.
                    if ($keeper === null || ($rowemployee && $keeper->membertype !== 'employee')) {
                        $keeper = $row;
                    }
                }

                $groupids = array_map(function($row) {
                    return (int)$row->id;
                }, $group);

                if (count($groupids) > 1) {
                    // Corporate manager role assignments use membership id as the
                    // component itemid. Restrict cleanup to the corporate-manager
                    // role because department-manager assignments use the same
                    // component name and may coincidentally have the same itemid.
                    if ($roleid) {
                        list($insql, $params) = $DB->get_in_or_equal($groupids, SQL_PARAMS_NAMED, 'kbamember');
                        $params['component'] = 'local_kbacorporate';
                        $params['roleid'] = $roleid;
                        $DB->delete_records_select(
                            'role_assignments',
                            "component = :component AND roleid = :roleid AND itemid {$insql}",
                            $params
                        );
                    }

                    foreach ($group as $row) {
                        if ((int)$row->id !== (int)$keeper->id) {
                            $DB->delete_records('local_kbacorp_member', ['id' => (int)$row->id]);
                        }
                    }
                }

                $keeper->isemployee = $isemployee;
                $keeper->ismanager = $ismanager;
                $keeper->membertype = $isemployee ? 'employee' : 'manager';
                $keeper->timemodified = time();
                $DB->update_record('local_kbacorp_member', $keeper);

                if ($ismanager && $roleid) {
                    role_assign(
                        $roleid,
                        (int)$keeper->userid,
                        $systemcontext->id,
                        'local_kbacorporate',
                        (int)$keeper->id
                    );
                }
            }

            $oldindex = new xmldb_index(
                'company_user_type_uix', XMLDB_INDEX_UNIQUE, ['companyid', 'userid', 'membertype']
            );
            if ($dbman->index_exists($table, $oldindex)) {
                $dbman->drop_index($table, $oldindex);
            }
            $newindex = new xmldb_index('company_user_uix', XMLDB_INDEX_UNIQUE, ['companyid', 'userid']);
            if (!$dbman->index_exists($table, $newindex)) {
                $dbman->add_index($table, $newindex);
            }
            $employeeindex = new xmldb_index('employee_ix', XMLDB_INDEX_NOTUNIQUE, ['isemployee']);
            if (!$dbman->index_exists($table, $employeeindex)) {
                $dbman->add_index($table, $employeeindex);
            }
            $managerindex = new xmldb_index('manager_ix', XMLDB_INDEX_NOTUNIQUE, ['ismanager']);
            if (!$dbman->index_exists($table, $managerindex)) {
                $dbman->add_index($table, $managerindex);
            }
        }

        upgrade_plugin_savepoint(true, 2026081800, 'local', 'kbacorporate');
    }

    if ($oldversion < 2026081801) {
        // v0.14.3 adds KBA Connect role-schema provisioning settings and API
        // contract only. No database schema changes are required.
        upgrade_plugin_savepoint(true, 2026081801, 'local', 'kbacorporate');
    }

    if ($oldversion < 2026081802) {
        // v0.14.4 introduces KBA Connect pairing. Pairing state is stored in
        // plugin configuration; no XMLDB schema changes are required. Existing
        // manually configured bearer tokens are preserved for compatibility.
        upgrade_plugin_savepoint(true, 2026081802, 'local', 'kbacorporate');
    }


    if ($oldversion < 2026081803) {
        // v0.14.5: Integration Health Center history. The runtime checks are read-only;
        // this table stores only aggregate technical results and JSON diagnostics.
        $table = new xmldb_table('local_kbacorp_health_log');
        if (!$dbman->table_exists($table)) {
            $table->add_field('id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE, null);
            $table->add_field('status', XMLDB_TYPE_CHAR, '20', null, XMLDB_NOTNULL, null, 'healthy');
            $table->add_field('runsource', XMLDB_TYPE_CHAR, '20', null, XMLDB_NOTNULL, null, 'manual');
            $table->add_field('warnings', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $table->add_field('errors', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $table->add_field('details', XMLDB_TYPE_TEXT, null, null, null, null, null);
            $table->add_field('timecreated', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $table->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);
            $table->add_index('status_time_ix', XMLDB_INDEX_NOTUNIQUE, ['status', 'timecreated']);
            $table->add_index('source_time_ix', XMLDB_INDEX_NOTUNIQUE, ['runsource', 'timecreated']);
            $dbman->create_table($table);
        }
        upgrade_plugin_savepoint(true, 2026081803, 'local', 'kbacorporate');
    }


    if ($oldversion < 2026081804) {
        // v0.14.6: additive company-level business roles used by KBA Connect MERGE API.
        // Existing employee/corporate-manager flags remain unchanged.
        $table = new xmldb_table('local_kbacorp_member');
        if ($dbman->table_exists($table)) {
            $departmentmanagerfield = new xmldb_field(
                'isdepartmentmanager', XMLDB_TYPE_INTEGER, '1', null, XMLDB_NOTNULL, null, '0', 'ismanager'
            );
            if (!$dbman->field_exists($table, $departmentmanagerfield)) {
                $dbman->add_field($table, $departmentmanagerfield);
            }

            $hrfield = new xmldb_field(
                'ishr', XMLDB_TYPE_INTEGER, '1', null, XMLDB_NOTNULL, null, '0', 'isdepartmentmanager'
            );
            if (!$dbman->field_exists($table, $hrfield)) {
                $dbman->add_field($table, $hrfield);
            }

            $departmentmanagerindex = new xmldb_index(
                'departmentmanager_ix', XMLDB_INDEX_NOTUNIQUE, ['isdepartmentmanager']
            );
            if (!$dbman->index_exists($table, $departmentmanagerindex)) {
                $dbman->add_index($table, $departmentmanagerindex);
            }

            $hrindex = new xmldb_index('hr_ix', XMLDB_INDEX_NOTUNIQUE, ['ishr']);
            if (!$dbman->index_exists($table, $hrindex)) {
                $dbman->add_index($table, $hrindex);
            }
        }

        upgrade_plugin_savepoint(true, 2026081804, 'local', 'kbacorporate');
    }


    if ($oldversion < 2026082400) {
        // v0.16.0: Competency Development / My Competencies.
        // Competency data remains in Moodle core tables. The version bump also
        // provisions the new read-only capabilities declared in db/access.php.
        upgrade_plugin_savepoint(true, 2026082400, 'local', 'kbacorporate');
    }


    if ($oldversion < 2026082401) {
        // v0.16.1: My Competencies navigation/access fix. A user may always
        // read their own competency profile; no data/schema migration required.
        upgrade_plugin_savepoint(true, 2026082401, 'local', 'kbacorporate');
    }

    if ($oldversion < 2026082402) {
        // v0.16.2: Moodle 4.0 competency schema compatibility fix.
        // No schema changes: SQL now uses competency.competencyframeworkid.
        upgrade_plugin_savepoint(true, 2026082402, 'local', 'kbacorporate');
    }

    if ($oldversion < 2026082403) {
        // v0.16.3: competency dashboard reads competencies from enrolled courses.
        // No database schema change is required.
        upgrade_plugin_savepoint(true, 2026082403, 'local', 'kbacorporate');
    }

    if ($oldversion < 2026082404) {
        // v0.16.4: BMS competency profile UI wording. No schema changes.
        upgrade_plugin_savepoint(true, 2026082404, 'local', 'kbacorporate');
    }


    if ($oldversion < 2026082500) {
        // v0.17.0: business-role competency target profiles and external mapping.
        // Access roles in local_kbacorp_member remain unchanged; these are separate domain roles.

        $table = new xmldb_table('local_kbacorp_busrole');
        if (!$dbman->table_exists($table)) {
            $table->add_field('id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE, null);
            $table->add_field('companyid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $table->add_field('code', XMLDB_TYPE_CHAR, '100', null, XMLDB_NOTNULL, null, null);
            $table->add_field('uuid', XMLDB_TYPE_CHAR, '36', null, XMLDB_NOTNULL, null, null);
            $table->add_field('name', XMLDB_TYPE_CHAR, '255', null, XMLDB_NOTNULL, null, null);
            $table->add_field('description', XMLDB_TYPE_TEXT, null, null, null, null, null);
            $table->add_field('active', XMLDB_TYPE_INTEGER, '1', null, XMLDB_NOTNULL, null, '1');
            $table->add_field('source', XMLDB_TYPE_CHAR, '50', null, XMLDB_NOTNULL, null, 'manual');
            $table->add_field('timecreated', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $table->add_field('timemodified', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $table->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);
            $table->add_index('company_code_uix', XMLDB_INDEX_UNIQUE, ['companyid', 'code']);
            $table->add_index('uuid_uix', XMLDB_INDEX_UNIQUE, ['uuid']);
            $table->add_index('company_active_ix', XMLDB_INDEX_NOTUNIQUE, ['companyid', 'active']);
            $dbman->create_table($table);
        }

        $table = new xmldb_table('local_kbacorp_rolecomp');
        if (!$dbman->table_exists($table)) {
            $table->add_field('id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE, null);
            $table->add_field('roleid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
            $table->add_field('competencyid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
            $table->add_field('targetlevel', XMLDB_TYPE_INTEGER, '2', null, XMLDB_NOTNULL, null, '0');
            $table->add_field('priority', XMLDB_TYPE_CHAR, '20', null, XMLDB_NOTNULL, null, 'core');
            $table->add_field('required', XMLDB_TYPE_INTEGER, '1', null, XMLDB_NOTNULL, null, '1');
            $table->add_field('source', XMLDB_TYPE_CHAR, '50', null, XMLDB_NOTNULL, null, 'manual');
            $table->add_field('timecreated', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $table->add_field('timemodified', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $table->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);
            $table->add_key('role_fk', XMLDB_KEY_FOREIGN, ['roleid'], 'local_kbacorp_busrole', ['id']);
            $table->add_key('competency_fk', XMLDB_KEY_FOREIGN, ['competencyid'], 'competency', ['id']);
            $table->add_index('role_comp_uix', XMLDB_INDEX_UNIQUE, ['roleid', 'competencyid']);
            $table->add_index('priority_ix', XMLDB_INDEX_NOTUNIQUE, ['priority']);
            $dbman->create_table($table);
        }

        $table = new xmldb_table('local_kbacorp_member_busrole');
        if (!$dbman->table_exists($table)) {
            $table->add_field('id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE, null);
            $table->add_field('companyid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
            $table->add_field('userid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
            $table->add_field('roleid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
            $table->add_field('source', XMLDB_TYPE_CHAR, '50', null, XMLDB_NOTNULL, null, 'manual');
            $table->add_field('externalref', XMLDB_TYPE_CHAR, '255', null, null, null, null);
            $table->add_field('active', XMLDB_TYPE_INTEGER, '1', null, XMLDB_NOTNULL, null, '1');
            $table->add_field('effectivefrom', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $table->add_field('effectiveto', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $table->add_field('timecreated', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $table->add_field('timemodified', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $table->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);
            $table->add_key('company_fk', XMLDB_KEY_FOREIGN, ['companyid'], 'local_kbacorp_company', ['id']);
            $table->add_key('user_fk', XMLDB_KEY_FOREIGN, ['userid'], 'user', ['id']);
            $table->add_key('role_fk', XMLDB_KEY_FOREIGN, ['roleid'], 'local_kbacorp_busrole', ['id']);
            $table->add_index('company_user_role_uix', XMLDB_INDEX_UNIQUE, ['companyid', 'userid', 'roleid']);
            $table->add_index('user_active_ix', XMLDB_INDEX_NOTUNIQUE, ['userid', 'active']);
            $table->add_index('role_active_ix', XMLDB_INDEX_NOTUNIQUE, ['roleid', 'active']);
            $dbman->create_table($table);
        }

        $table = new xmldb_table('local_kbacorp_extmap');
        if (!$dbman->table_exists($table)) {
            $table->add_field('id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE, null);
            $table->add_field('companyid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $table->add_field('system', XMLDB_TYPE_CHAR, '30', null, XMLDB_NOTNULL, null, null);
            $table->add_field('entitytype', XMLDB_TYPE_CHAR, '30', null, XMLDB_NOTNULL, null, null);
            $table->add_field('localentity', XMLDB_TYPE_CHAR, '50', null, XMLDB_NOTNULL, null, null);
            $table->add_field('localid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
            $table->add_field('externalid', XMLDB_TYPE_CHAR, '255', null, XMLDB_NOTNULL, null, null);
            $table->add_field('externalcode', XMLDB_TYPE_CHAR, '255', null, null, null, null);
            $table->add_field('status', XMLDB_TYPE_CHAR, '20', null, XMLDB_NOTNULL, null, 'active');
            $table->add_field('metadata', XMLDB_TYPE_TEXT, null, null, null, null, null);
            $table->add_field('lastsync', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $table->add_field('timecreated', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $table->add_field('timemodified', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $table->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);
            $table->add_index('external_uix', XMLDB_INDEX_UNIQUE, ['companyid', 'system', 'entitytype', 'externalid']);
            $table->add_index('local_ix', XMLDB_INDEX_NOTUNIQUE, ['companyid', 'localentity', 'localid']);
            $table->add_index('system_status_ix', XMLDB_INDEX_NOTUNIQUE, ['system', 'status']);
            $dbman->create_table($table);
        }

        upgrade_plugin_savepoint(true, 2026082500, 'local', 'kbacorporate');
    }

    if ($oldversion < 2026090100) {
        // v0.17.1 is a behavioural hardening release. It deliberately makes no
        // destructive schema changes; direct upgrades still run the v0.17.0
        // business-role table creation above before reaching this savepoint.
        upgrade_plugin_savepoint(true, 2026090100, 'local', 'kbacorporate');
    }

    if ($oldversion < 2026090101) {
        // v0.17.1.1 packages the Moodle 4.0 XMLDB compatibility fixes. The
        // preceding migration is intentionally idempotent so an upgrade that
        // stopped while creating the new tables can safely be resumed.
        upgrade_plugin_savepoint(true, 2026090101, 'local', 'kbacorporate');
    }

    if ($oldversion < 2026090102) {
        // v0.17.1.2 adds the role-management UI without changing the schema.
        upgrade_plugin_savepoint(true, 2026090102, 'local', 'kbacorporate');
    }

    if ($oldversion < 2026090103) {
        // v0.17.2: auditable, two-stage employee competency assessments.
        $table = new xmldb_table('local_kbacorp_audit');
        if (!$dbman->table_exists($table)) {
            $table->add_field('id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE, null);
            $table->add_field('companyid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
            $table->add_field('userid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
            $table->add_field('title', XMLDB_TYPE_CHAR, '255', null, XMLDB_NOTNULL, null, null);
            $table->add_field('description', XMLDB_TYPE_TEXT, null, null, null, null, null);
            $table->add_field('status', XMLDB_TYPE_CHAR, '20', null, XMLDB_NOTNULL, null, 'open');
            $table->add_field('createdby', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $table->add_field('completedat', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $table->add_field('timecreated', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $table->add_field('timemodified', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $table->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);
            $table->add_key('company_fk', XMLDB_KEY_FOREIGN, ['companyid'], 'local_kbacorp_company', ['id']);
            $table->add_key('user_fk', XMLDB_KEY_FOREIGN, ['userid'], 'user', ['id']);
            $table->add_index('company_status_ix', XMLDB_INDEX_NOTUNIQUE, ['companyid', 'status']);
            $table->add_index('user_status_ix', XMLDB_INDEX_NOTUNIQUE, ['userid', 'status']);
            $table->add_index('creator_ix', XMLDB_INDEX_NOTUNIQUE, ['createdby']);
            $dbman->create_table($table);
        }

        $table = new xmldb_table('local_kbacorp_audit_item');
        if (!$dbman->table_exists($table)) {
            $table->add_field('id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE, null);
            $table->add_field('auditid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
            $table->add_field('competencyid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
            $table->add_field('method', XMLDB_TYPE_CHAR, '20', null, XMLDB_NOTNULL, null, 'expert');
            $table->add_field('proposedlevel', XMLDB_TYPE_INTEGER, '2', null, XMLDB_NOTNULL, null, '0');
            $table->add_field('status', XMLDB_TYPE_CHAR, '20', null, XMLDB_NOTNULL, null, 'pending');
            $table->add_field('assessorid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $table->add_field('reviewerid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $table->add_field('comment', XMLDB_TYPE_TEXT, null, null, null, null, null);
            $table->add_field('evidenceurl', XMLDB_TYPE_CHAR, '255', null, null, null, null);
            $table->add_field('timeassessed', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $table->add_field('timeconfirmed', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $table->add_field('timecreated', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $table->add_field('timemodified', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $table->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);
            $table->add_key('audit_fk', XMLDB_KEY_FOREIGN, ['auditid'], 'local_kbacorp_audit', ['id']);
            $table->add_key('competency_fk', XMLDB_KEY_FOREIGN, ['competencyid'], 'competency', ['id']);
            $table->add_index('audit_comp_ix', XMLDB_INDEX_NOTUNIQUE, ['auditid', 'competencyid']);
            $table->add_index('status_ix', XMLDB_INDEX_NOTUNIQUE, ['status']);
            $table->add_index('assessor_ix', XMLDB_INDEX_NOTUNIQUE, ['assessorid']);
            $table->add_index('reviewer_ix', XMLDB_INDEX_NOTUNIQUE, ['reviewerid']);
            $dbman->create_table($table);
        }

        upgrade_plugin_savepoint(true, 2026090103, 'local', 'kbacorporate');
    }

    if ($oldversion < 2026090104) {
        // v0.17.2.1 fixes repeated named SQL parameters in active role lookup.
        // No schema change is required.
        upgrade_plugin_savepoint(true, 2026090104, 'local', 'kbacorporate');
    }

    if ($oldversion < 2026090200) {
        // v0.17.3: automatic role-based audit composition and collected evidence.
        $table = new xmldb_table('local_kbacorp_audit');
        $field = new xmldb_field(
            'audittype', XMLDB_TYPE_CHAR, '20', null, XMLDB_NOTNULL, null, 'manual', 'description'
        );
        if ($dbman->table_exists($table) && !$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }

        $table = new xmldb_table('local_kbacorp_audit_item');
        if ($dbman->table_exists($table)) {
            $fields = [
                new xmldb_field('targetlevel', XMLDB_TYPE_INTEGER, '2', null, null, null, null, 'proposedlevel'),
                new xmldb_field(
                    'priority', XMLDB_TYPE_CHAR, '20', null, XMLDB_NOTNULL, null, 'additional', 'targetlevel'
                ),
                new xmldb_field('required', XMLDB_TYPE_INTEGER, '1', null, XMLDB_NOTNULL, null, '0', 'priority'),
                new xmldb_field('source', XMLDB_TYPE_CHAR, '30', null, XMLDB_NOTNULL, null, 'manual', 'required'),
                new xmldb_field('rolesnapshot', XMLDB_TYPE_TEXT, null, null, null, null, null, 'source'),
            ];
            foreach ($fields as $field) {
                if (!$dbman->field_exists($table, $field)) {
                    $dbman->add_field($table, $field);
                }
            }
        }

        $table = new xmldb_table('local_kbacorp_audit_ev');
        if (!$dbman->table_exists($table)) {
            $table->add_field('id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE, null);
            $table->add_field('audititemid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
            $table->add_field('sourcetype', XMLDB_TYPE_CHAR, '30', null, XMLDB_NOTNULL, null, null);
            $table->add_field('sourcecomponent', XMLDB_TYPE_CHAR, '100', null, null, null, null);
            $table->add_field('sourceitemid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $table->add_field('title', XMLDB_TYPE_CHAR, '255', null, XMLDB_NOTNULL, null, null);
            $table->add_field('resultvalue', XMLDB_TYPE_CHAR, '100', null, null, null, null);
            $table->add_field('proposedlevel', XMLDB_TYPE_INTEGER, '2', null, null, null, null);
            $table->add_field('url', XMLDB_TYPE_CHAR, '255', null, null, null, null);
            $table->add_field('details', XMLDB_TYPE_TEXT, null, null, null, null, null);
            $table->add_field('timeobserved', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $table->add_field('timecreated', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $table->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);
            $table->add_key(
                'audititem_fk', XMLDB_KEY_FOREIGN, ['audititemid'], 'local_kbacorp_audit_item', ['id']
            );
            $table->add_index('item_type_ix', XMLDB_INDEX_NOTUNIQUE, ['audititemid', 'sourcetype']);
            $table->add_index('source_ix', XMLDB_INDEX_NOTUNIQUE, ['sourcecomponent', 'sourceitemid']);
            $dbman->create_table($table);
        }

        upgrade_plugin_savepoint(true, 2026090200, 'local', 'kbacorporate');
    }

    if ($oldversion < 2026090201) {
        // v0.17.4: versioned global role templates and independent company copies.
        $table = new xmldb_table('local_kbacorp_busrole');
        if ($dbman->table_exists($table)) {
            $fields = [
                new xmldb_field(
                    'templateid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0', 'source'
                ),
                new xmldb_field(
                    'templateversion', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '1', 'templateid'
                ),
                new xmldb_field(
                    'appliedversion', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0', 'templateversion'
                ),
            ];
            foreach ($fields as $field) {
                if (!$dbman->field_exists($table, $field)) {
                    $dbman->add_field($table, $field);
                }
            }
            $index = new xmldb_index(
                'template_ix', XMLDB_INDEX_NOTUNIQUE, ['templateid', 'appliedversion']
            );
            if (!$dbman->index_exists($table, $index)) {
                $dbman->add_index($table, $index);
            }
        }
        upgrade_plugin_savepoint(true, 2026090201, 'local', 'kbacorporate');
    }

    if ($oldversion < 2026090202) {
        // v0.17.4.1: global template form routing fix; no database changes.
        upgrade_plugin_savepoint(true, 2026090202, 'local', 'kbacorporate');
    }

    if ($oldversion < 2026090203) {
        // v0.17.4.2: capability-aware navigation from My competencies; no database changes.
        upgrade_plugin_savepoint(true, 2026090203, 'local', 'kbacorporate');
    }

    if ($oldversion < 2026090204) {
        // v0.17.5: audit rename/delete actions; no database changes.
        upgrade_plugin_savepoint(true, 2026090204, 'local', 'kbacorporate');
    }

    if ($oldversion < 2026090205) {
        // v0.17.6: create global templates from company roles; no database changes.
        upgrade_plugin_savepoint(true, 2026090205, 'local', 'kbacorporate');
    }

    if ($oldversion < 2026090206) {
        // v0.17.7: template update status and direct controlled update; no database changes.
        upgrade_plugin_savepoint(true, 2026090206, 'local', 'kbacorporate');
    }

    if ($oldversion < 2026090207) {
        // v0.17.8: site-admin cross-company template rollout view; no database changes.
        upgrade_plugin_savepoint(true, 2026090207, 'local', 'kbacorporate');
    }

    if ($oldversion < 2026090208) {
        // v0.17.9: immutable template metadata and competency-profile snapshots.
        $table = new xmldb_table('local_kbacorp_rolever');
        if (!$dbman->table_exists($table)) {
            $table->add_field('id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE, null);
            $table->add_field('templateid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
            $table->add_field('version', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '1');
            $table->add_field('name', XMLDB_TYPE_CHAR, '255', null, XMLDB_NOTNULL, null, null);
            $table->add_field('description', XMLDB_TYPE_TEXT, null, null, null, null, null);
            $table->add_field('active', XMLDB_TYPE_INTEGER, '1', null, XMLDB_NOTNULL, null, '1');
            $table->add_field('changenote', XMLDB_TYPE_CHAR, '255', null, null, null, null);
            $table->add_field('createdby', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $table->add_field('timecreated', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $table->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);
            $table->add_key(
                'template_fk', XMLDB_KEY_FOREIGN, ['templateid'], 'local_kbacorp_busrole', ['id']
            );
            $table->add_index('template_version_uix', XMLDB_INDEX_UNIQUE, ['templateid', 'version']);
            $dbman->create_table($table);
        }

        $table = new xmldb_table('local_kbacorp_rolevercomp');
        if (!$dbman->table_exists($table)) {
            $table->add_field('id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE, null);
            $table->add_field('roleversionid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
            $table->add_field('competencyid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
            $table->add_field('targetlevel', XMLDB_TYPE_INTEGER, '2', null, XMLDB_NOTNULL, null, '0');
            $table->add_field('priority', XMLDB_TYPE_CHAR, '20', null, XMLDB_NOTNULL, null, 'core');
            $table->add_field('required', XMLDB_TYPE_INTEGER, '1', null, XMLDB_NOTNULL, null, '1');
            $table->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);
            $table->add_key(
                'version_fk', XMLDB_KEY_FOREIGN, ['roleversionid'], 'local_kbacorp_rolever', ['id']
            );
            $table->add_key('competency_fk', XMLDB_KEY_FOREIGN, ['competencyid'], 'competency', ['id']);
            $table->add_index('version_comp_uix', XMLDB_INDEX_UNIQUE, ['roleversionid', 'competencyid']);
            $dbman->create_table($table);
        }

        $templates = $DB->get_records('local_kbacorp_busrole', ['companyid' => 0]);
        foreach ($templates as $template) {
            \local_kbacorporate\local\business_roles::capture_template_version(
                (int)$template->id,
                'Initial snapshot created during upgrade',
                0
            );
        }
        upgrade_plugin_savepoint(true, 2026090208, 'local', 'kbacorporate');
    }

    if ($oldversion < 2026090209) {
        // v0.18.0: company competency map; no database changes.
        upgrade_plugin_savepoint(true, 2026090209, 'local', 'kbacorporate');
    }

    if ($oldversion < 2026090210) {
        // v0.18.0.1: competency-map translation and responsive table hotfix; no database changes.
        upgrade_plugin_savepoint(true, 2026090210, 'local', 'kbacorporate');
    }

    if ($oldversion < 2026090211) {
        // v0.18.0.2: unified plugin page headings; no database changes.
        upgrade_plugin_savepoint(true, 2026090211, 'local', 'kbacorporate');
    }

    if ($oldversion < 2026090212) {
        // v0.18.1: competency-map analytics, paging and CSV export; no database changes.
        upgrade_plugin_savepoint(true, 2026090212, 'local', 'kbacorporate');
    }

    if ($oldversion < 2026090213) {
        // v0.18.2: individual development plans generated from competency gaps.
        $table = new xmldb_table('local_kbacorp_devplan');
        if (!$dbman->table_exists($table)) {
            $table->add_field('id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE, null);
            $table->add_field('companyid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
            $table->add_field('userid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
            $table->add_field('title', XMLDB_TYPE_CHAR, '255', null, XMLDB_NOTNULL, null, null);
            $table->add_field('status', XMLDB_TYPE_CHAR, '20', null, XMLDB_NOTNULL, null, 'active');
            $table->add_field('createdby', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $table->add_field('startdate', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $table->add_field('targetdate', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $table->add_field('timecreated', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $table->add_field('timemodified', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $table->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);
            $table->add_key('company_fk', XMLDB_KEY_FOREIGN, ['companyid'], 'local_kbacorp_company', ['id']);
            $table->add_key('user_fk', XMLDB_KEY_FOREIGN, ['userid'], 'user', ['id']);
            $table->add_index('company_status_ix', XMLDB_INDEX_NOTUNIQUE, ['companyid', 'status']);
            $table->add_index('user_status_ix', XMLDB_INDEX_NOTUNIQUE, ['userid', 'status']);
            $dbman->create_table($table);
        }
        $table = new xmldb_table('local_kbacorp_devitem');
        if (!$dbman->table_exists($table)) {
            $table->add_field('id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE, null);
            $table->add_field('planid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
            $table->add_field('competencyid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
            $table->add_field('courseid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $table->add_field('currentlevel', XMLDB_TYPE_INTEGER, '2', null, null, null, null);
            $table->add_field('targetlevel', XMLDB_TYPE_INTEGER, '2', null, XMLDB_NOTNULL, null, '0');
            $table->add_field('priority', XMLDB_TYPE_CHAR, '20', null, XMLDB_NOTNULL, null, 'core');
            $table->add_field('status', XMLDB_TYPE_CHAR, '20', null, XMLDB_NOTNULL, null, 'planned');
            $table->add_field('duedate', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $table->add_field('timecompleted', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $table->add_field('timecreated', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $table->add_field('timemodified', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $table->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);
            $table->add_key('plan_fk', XMLDB_KEY_FOREIGN, ['planid'], 'local_kbacorp_devplan', ['id']);
            $table->add_key('competency_fk', XMLDB_KEY_FOREIGN, ['competencyid'], 'competency', ['id']);
            $table->add_index('plan_comp_uix', XMLDB_INDEX_UNIQUE, ['planid', 'competencyid']);
            $table->add_index('status_due_ix', XMLDB_INDEX_NOTUNIQUE, ['status', 'duedate']);
            $dbman->create_table($table);
        }
        // Do not assign the newly introduced capability here. Moodle updates the
        // component capabilities from db/access.php only after xmldb upgrade has
        // completed, so assign_capability() would fail on an existing site.
        // clonepermissionsfrom in db/access.php safely inherits the manager grant.
        upgrade_plugin_savepoint(true, 2026090213, 'local', 'kbacorporate');
    }

    if ($oldversion < 2026090214) {
        // v0.18.2.1: upgrade-order hotfix. The capability is registered by Moodle
        // after this upgrade function returns; no direct assignment is required.
        upgrade_plugin_savepoint(true, 2026090214, 'local', 'kbacorporate');
    }

    if ($oldversion < 2026090215) {
        // v0.18.2.2: clearer competency-map filtering (no database changes).
        upgrade_plugin_savepoint(true, 2026090215, 'local', 'kbacorporate');
    }

    if ($oldversion < 2026090216) {
        // v0.18.2.3: clearer competency-map search field (no database changes).
        upgrade_plugin_savepoint(true, 2026090216, 'local', 'kbacorporate');
    }

    if ($oldversion < 2026090217) {
        // v0.18.3: manager-controlled development-plan actions (no database changes).
        upgrade_plugin_savepoint(true, 2026090217, 'local', 'kbacorporate');
    }

    if ($oldversion < 2026090218) {
        // v0.18.4: employee agreement workflow for development plans.
        $table = new xmldb_table('local_kbacorp_devplan');
        $fields = [
            new xmldb_field('submittedby', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0', 'createdby'),
            new xmldb_field('timesubmitted', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0', 'submittedby'),
            new xmldb_field('timeacknowledged', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0', 'timesubmitted'),
            new xmldb_field('employeecomment', XMLDB_TYPE_TEXT, null, null, null, null, null, 'timeacknowledged'),
        ];
        foreach ($fields as $field) {
            if (!$dbman->field_exists($table, $field)) {
                $dbman->add_field($table, $field);
            }
        }
        upgrade_plugin_savepoint(true, 2026090218, 'local', 'kbacorporate');
    }

    if ($oldversion < 2026090219) {
        // v0.18.4.1: upgrade hotfix. Existing status indexes prevent changing
        // the column default on some databases. New plans explicitly store
        // "draft", so no DDL default change is needed for upgraded sites.
        upgrade_plugin_savepoint(true, 2026090219, 'local', 'kbacorporate');
    }

    if ($oldversion < 2026090220) {
        // v0.18.5: development-plan progress and deadline monitoring (no database changes).
        upgrade_plugin_savepoint(true, 2026090220, 'local', 'kbacorporate');
    }

    if ($oldversion < 2026090221) {
        // v0.18.6: synchronise development actions with Moodle course completion.
        upgrade_plugin_savepoint(true, 2026090221, 'local', 'kbacorporate');
    }

    if ($oldversion < 2026090222) {
        // v0.18.7: daily development-plan deadline reminders.
        $table = new xmldb_table('local_kbacorp_devplan');
        $field = new xmldb_field('lastremindersent', XMLDB_TYPE_INTEGER, '10', null,
            XMLDB_NOTNULL, null, '0', 'employeecomment');
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }
        upgrade_plugin_savepoint(true, 2026090222, 'local', 'kbacorporate');
    }

    if ($oldversion < 2026090223) {
        // v0.18.8: follow-up competency audit after a completed development plan.
        $table = new xmldb_table('local_kbacorp_devplan');
        $field = new xmldb_field('followupauditid', XMLDB_TYPE_INTEGER, '10', null,
            XMLDB_NOTNULL, null, '0', 'lastremindersent');
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }
        upgrade_plugin_savepoint(true, 2026090223, 'local', 'kbacorporate');
    }

    if ($oldversion < 2026090224) {
        // v0.18.9: measured development outcomes (no database changes).
        upgrade_plugin_savepoint(true, 2026090224, 'local', 'kbacorporate');
    }

    if ($oldversion < 2026090225) {
        // v0.19.0: company development-effectiveness analytics (no database changes).
        upgrade_plugin_savepoint(true, 2026090225, 'local', 'kbacorporate');
    }

    if ($oldversion < 2026090226) {
        // v0.19.1: development-effectiveness filters and CSV export (no database changes).
        upgrade_plugin_savepoint(true, 2026090226, 'local', 'kbacorporate');
    }

    if ($oldversion < 2026090227) {
        // v0.19.2: effectiveness breakdowns by business role and department.
        upgrade_plugin_savepoint(true, 2026090227, 'local', 'kbacorporate');
    }

    if ($oldversion < 2026090228) {
        // v0.19.3: immutable before/after development dynamics by period (no database changes).
        upgrade_plugin_savepoint(true, 2026090228, 'local', 'kbacorporate');
    }

    if ($oldversion < 2026090229) {
        // v0.19.3.1: development dashboard navigation and colour accents (no database changes).
        upgrade_plugin_savepoint(true, 2026090229, 'local', 'kbacorporate');
    }

    if ($oldversion < 2026090230) {
        // v0.19.3.2: development effectiveness visual hierarchy and responsive tables.
        upgrade_plugin_savepoint(true, 2026090230, 'local', 'kbacorporate');
    }

    if ($oldversion < 2026090231) {
        // v0.19.3.3: role-aware navigation on individual development plans.
        upgrade_plugin_savepoint(true, 2026090231, 'local', 'kbacorporate');
    }

    if ($oldversion < 2026090232) {
        // v0.19.3.4: development plan list UI and employee visibility hardening.
        upgrade_plugin_savepoint(true, 2026090232, 'local', 'kbacorporate');
    }

    if ($oldversion < 2026090233) {
        // v0.19.3.5: company directory filters (no database changes).
        upgrade_plugin_savepoint(true, 2026090233, 'local', 'kbacorporate');
    }

    if ($oldversion < 2026090234) {
        // v0.19.3.6: business role page navigation and responsive table containment.
        upgrade_plugin_savepoint(true, 2026090234, 'local', 'kbacorporate');
    }

    if ($oldversion < 2026090235) {
        // v0.19.3.7: consistent company-dashboard navigation across standalone pages.
        upgrade_plugin_savepoint(true, 2026090235, 'local', 'kbacorporate');
    }

    if ($oldversion < 2026090236) {
        // v0.19.3.8: role-aware return navigation for employee competency profiles.
        upgrade_plugin_savepoint(true, 2026090236, 'local', 'kbacorporate');
    }

    if ($oldversion < 2026090237) {
        // v0.20.0: stable provider-neutral Odoo/BMS Digital ERP External Service.
        upgrade_plugin_savepoint(true, 2026090237, 'local', 'kbacorporate');
    }

    if ($oldversion < 2026090238) {
        // v0.20.0.1: remove the duplicate personal competency card from the corporate dashboard.
        upgrade_plugin_savepoint(true, 2026090238, 'local', 'kbacorporate');
    }

    if ($oldversion < 2026090239) {
        // v0.21.0: BMS tool library, implementation audits and verified BMS Index.
        $tables = [];

        $table = new xmldb_table('local_kbacorp_bms_tool');
        $table->add_field('id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE);
        $table->add_field('companyid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $table->add_field('code', XMLDB_TYPE_CHAR, '100', null, XMLDB_NOTNULL);
        $table->add_field('name', XMLDB_TYPE_CHAR, '255', null, XMLDB_NOTNULL);
        $table->add_field('area', XMLDB_TYPE_CHAR, '30', null, XMLDB_NOTNULL, null, 'management');
        $table->add_field('description', XMLDB_TYPE_TEXT, null, null, null);
        $table->add_field('active', XMLDB_TYPE_INTEGER, '1', null, XMLDB_NOTNULL, null, '1');
        $table->add_field('timecreated', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $table->add_field('timemodified', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $table->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);
        $table->add_index('company_code_uix', XMLDB_INDEX_UNIQUE, ['companyid', 'code']);
        $table->add_index('company_active_ix', XMLDB_INDEX_NOTUNIQUE, ['companyid', 'active']);
        $tables[] = $table;

        $table = new xmldb_table('local_kbacorp_bms_crit');
        $table->add_field('id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE);
        $table->add_field('toolid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL);
        $table->add_field('code', XMLDB_TYPE_CHAR, '100', null, XMLDB_NOTNULL);
        $table->add_field('name', XMLDB_TYPE_CHAR, '255', null, XMLDB_NOTNULL);
        $table->add_field('description', XMLDB_TYPE_TEXT, null, null, null);
        $table->add_field('evidencehint', XMLDB_TYPE_TEXT, null, null, null);
        $table->add_field('weight', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '1');
        $table->add_field('required', XMLDB_TYPE_INTEGER, '1', null, XMLDB_NOTNULL, null, '1');
        $table->add_field('sortorder', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '10');
        $table->add_field('active', XMLDB_TYPE_INTEGER, '1', null, XMLDB_NOTNULL, null, '1');
        $table->add_field('timecreated', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $table->add_field('timemodified', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $table->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);
        $table->add_key('tool_fk', XMLDB_KEY_FOREIGN, ['toolid'], 'local_kbacorp_bms_tool', ['id']);
        $table->add_index('tool_code_uix', XMLDB_INDEX_UNIQUE, ['toolid', 'code']);
        $tables[] = $table;

        $table = new xmldb_table('local_kbacorp_impl_audit');
        $table->add_field('id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE);
        $table->add_field('companyid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL);
        $table->add_field('toolid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL);
        $table->add_field('title', XMLDB_TYPE_CHAR, '255', null, XMLDB_NOTNULL);
        $table->add_field('audittype', XMLDB_TYPE_CHAR, '20', null, XMLDB_NOTNULL, null, 'baseline');
        $table->add_field('status', XMLDB_TYPE_CHAR, '20', null, XMLDB_NOTNULL, null, 'draft');
        $table->add_field('auditdate', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $table->add_field('auditorid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $table->add_field('confirmedby', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $table->add_field('confirmedat', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $table->add_field('indexvalue', XMLDB_TYPE_NUMBER, '10, 2', null, null);
        $table->add_field('coverage', XMLDB_TYPE_NUMBER, '10, 2', null, XMLDB_NOTNULL, null, '0');
        $table->add_field('conclusion', XMLDB_TYPE_TEXT, null, null, null);
        $table->add_field('timecreated', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $table->add_field('timemodified', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $table->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);
        $table->add_key('company_fk', XMLDB_KEY_FOREIGN, ['companyid'], 'local_kbacorp_company', ['id']);
        $table->add_key('tool_fk', XMLDB_KEY_FOREIGN, ['toolid'], 'local_kbacorp_bms_tool', ['id']);
        $table->add_index('company_status_ix', XMLDB_INDEX_NOTUNIQUE, ['companyid', 'status']);
        $table->add_index('company_tool_ix', XMLDB_INDEX_NOTUNIQUE, ['companyid', 'toolid']);
        $tables[] = $table;

        $table = new xmldb_table('local_kbacorp_impl_item');
        $table->add_field('id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE);
        $table->add_field('auditid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL);
        $table->add_field('criterionid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL);
        $table->add_field('criterioncode', XMLDB_TYPE_CHAR, '100', null, XMLDB_NOTNULL);
        $table->add_field('criterionname', XMLDB_TYPE_CHAR, '255', null, XMLDB_NOTNULL);
        $table->add_field('evidencehint', XMLDB_TYPE_TEXT, null, null, null);
        $table->add_field('weight', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '1');
        $table->add_field('required', XMLDB_TYPE_INTEGER, '1', null, XMLDB_NOTNULL, null, '1');
        $table->add_field('score', XMLDB_TYPE_INTEGER, '2', null, XMLDB_NOTNULL, null, '-1');
        $table->add_field('evidencestatus', XMLDB_TYPE_CHAR, '20', null, XMLDB_NOTNULL, null, 'missing');
        $table->add_field('evidenceurl', XMLDB_TYPE_CHAR, '1333', null, null);
        $table->add_field('comment', XMLDB_TYPE_TEXT, null, null, null);
        $table->add_field('assessorid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $table->add_field('verifierid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $table->add_field('verifiedat', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $table->add_field('timecreated', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $table->add_field('timemodified', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $table->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);
        $table->add_key('audit_fk', XMLDB_KEY_FOREIGN, ['auditid'], 'local_kbacorp_impl_audit', ['id']);
        $table->add_key('criterion_fk', XMLDB_KEY_FOREIGN, ['criterionid'], 'local_kbacorp_bms_crit', ['id']);
        $table->add_index('audit_criterion_uix', XMLDB_INDEX_UNIQUE, ['auditid', 'criterionid']);
        $tables[] = $table;

        foreach ($tables as $newtable) {
            if (!$dbman->table_exists($newtable)) {
                $dbman->create_table($newtable);
            }
        }
        upgrade_plugin_savepoint(true, 2026090239, 'local', 'kbacorporate');
    }

    if ($oldversion < 2026090240) {
        // v0.21.1: template metadata, configurable scales and section snapshots.
        $fields = [
            ['local_kbacorp_bms_tool', new xmldb_field('templatecode', XMLDB_TYPE_CHAR, '100', null, null, null, null, 'area')],
            ['local_kbacorp_bms_tool', new xmldb_field('sourceversion', XMLDB_TYPE_CHAR, '50', null, null, null, null, 'templatecode')],
            ['local_kbacorp_bms_tool', new xmldb_field('scalemax', XMLDB_TYPE_INTEGER, '2', null, XMLDB_NOTNULL, null, '5', 'sourceversion')],
            ['local_kbacorp_bms_tool', new xmldb_field('attentionthreshold', XMLDB_TYPE_INTEGER, '2', null, XMLDB_NOTNULL, null, '3', 'scalemax')],
            ['local_kbacorp_bms_tool', new xmldb_field('indexmethod', XMLDB_TYPE_CHAR, '30', null, XMLDB_NOTNULL, null, 'weighted_verified', 'attentionthreshold')],
            ['local_kbacorp_bms_crit', new xmldb_field('sectioncode', XMLDB_TYPE_CHAR, '50', null, null, null, null, 'name')],
            ['local_kbacorp_bms_crit', new xmldb_field('sectionname', XMLDB_TYPE_CHAR, '255', null, null, null, null, 'sectioncode')],
            ['local_kbacorp_bms_crit', new xmldb_field('minlabel', XMLDB_TYPE_TEXT, null, null, null, null, null, 'sectionname')],
            ['local_kbacorp_bms_crit', new xmldb_field('maxlabel', XMLDB_TYPE_TEXT, null, null, null, null, null, 'minlabel')],
            ['local_kbacorp_impl_audit', new xmldb_field('scalemax', XMLDB_TYPE_INTEGER, '2', null, XMLDB_NOTNULL, null, '5', 'confirmedat')],
            ['local_kbacorp_impl_audit', new xmldb_field('attentionthreshold', XMLDB_TYPE_INTEGER, '2', null, XMLDB_NOTNULL, null, '3', 'scalemax')],
            ['local_kbacorp_impl_audit', new xmldb_field('indexmethod', XMLDB_TYPE_CHAR, '30', null, XMLDB_NOTNULL, null, 'weighted_verified', 'attentionthreshold')],
            ['local_kbacorp_impl_item', new xmldb_field('sectioncode', XMLDB_TYPE_CHAR, '50', null, null, null, null, 'criterionname')],
            ['local_kbacorp_impl_item', new xmldb_field('sectionname', XMLDB_TYPE_CHAR, '255', null, null, null, null, 'sectioncode')],
            ['local_kbacorp_impl_item', new xmldb_field('minlabel', XMLDB_TYPE_TEXT, null, null, null, null, null, 'sectionname')],
            ['local_kbacorp_impl_item', new xmldb_field('maxlabel', XMLDB_TYPE_TEXT, null, null, null, null, null, 'minlabel')],
        ];
        foreach ($fields as [$tablename, $field]) {
            if (!$dbman->field_exists(new xmldb_table($tablename), $field)) {
                $dbman->add_field(new xmldb_table($tablename), $field);
            }
        }
        upgrade_plugin_savepoint(true, 2026090240, 'local', 'kbacorporate');
    }

    if ($oldversion < 2026090241) {
        // v0.21.2: target snapshots and development routes for implementation gaps.
        $criteriontable = new xmldb_table('local_kbacorp_bms_crit');
        $criteriontarget = new xmldb_field('targetscore', XMLDB_TYPE_INTEGER, '2', null,
            XMLDB_NOTNULL, null, '-1', 'maxlabel');
        if (!$dbman->field_exists($criteriontable, $criteriontarget)) {
            $dbman->add_field($criteriontable, $criteriontarget);
        }
        $itemtable = new xmldb_table('local_kbacorp_impl_item');
        $itemtarget = new xmldb_field('targetscore', XMLDB_TYPE_INTEGER, '2', null,
            XMLDB_NOTNULL, null, '-1', 'maxlabel');
        if (!$dbman->field_exists($itemtable, $itemtarget)) {
            $dbman->add_field($itemtable, $itemtarget);
        }
        $routetable = new xmldb_table('local_kbacorp_bms_route');
        $routetable->add_field('id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE);
        $routetable->add_field('companyid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL);
        $routetable->add_field('criterionid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL);
        $routetable->add_field('actiontoolid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $routetable->add_field('courseid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $routetable->add_field('priority', XMLDB_TYPE_CHAR, '20', null, XMLDB_NOTNULL, null, 'core');
        $routetable->add_field('note', XMLDB_TYPE_TEXT, null, null, null);
        $routetable->add_field('active', XMLDB_TYPE_INTEGER, '1', null, XMLDB_NOTNULL, null, '1');
        $routetable->add_field('timecreated', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $routetable->add_field('timemodified', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $routetable->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);
        $routetable->add_key('company_fk', XMLDB_KEY_FOREIGN, ['companyid'], 'local_kbacorp_company', ['id']);
        $routetable->add_key('criterion_fk', XMLDB_KEY_FOREIGN, ['criterionid'], 'local_kbacorp_bms_crit', ['id']);
        $routetable->add_index('criterion_action_course_uix', XMLDB_INDEX_UNIQUE,
            ['criterionid', 'actiontoolid', 'courseid']);
        $routetable->add_index('company_active_ix', XMLDB_INDEX_NOTUNIQUE, ['companyid', 'active']);
        if (!$dbman->table_exists($routetable)) { $dbman->create_table($routetable); }
        $pointatools = $DB->get_fieldset_select('local_kbacorp_bms_tool', 'id',
            'templatecode = :templatecode', ['templatecode' => 'BMS-AUD-CHK-001']);
        if ($pointatools) {
            list($toolsql, $toolparams) = $DB->get_in_or_equal(array_map('intval', $pointatools),
                SQL_PARAMS_NAMED, 'pointatool');
            $DB->set_field_select('local_kbacorp_bms_crit', 'targetscore', 7, "toolid {$toolsql}", $toolparams);
            $auditids = $DB->get_fieldset_select('local_kbacorp_impl_audit', 'id', "toolid {$toolsql}", $toolparams);
            if ($auditids) {
                list($auditsql, $auditparams) = $DB->get_in_or_equal(array_map('intval', $auditids),
                    SQL_PARAMS_NAMED, 'pointaaudit');
                $DB->set_field_select('local_kbacorp_impl_item', 'targetscore', 7,
                    "auditid {$auditsql}", $auditparams);
            }
        }
        upgrade_plugin_savepoint(true, 2026090241, 'local', 'kbacorporate');
    }

    if ($oldversion < 2026090242) {
        // v0.21.3: managed implementation tasks generated from audit gaps.
        $table = new xmldb_table('local_kbacorp_impl_task');
        $table->add_field('id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE);
        $table->add_field('companyid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL);
        $table->add_field('auditid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL);
        $table->add_field('itemid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL);
        $table->add_field('routeid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $table->add_field('title', XMLDB_TYPE_CHAR, '255', null, XMLDB_NOTNULL);
        $table->add_field('sectionname', XMLDB_TYPE_CHAR, '255');
        $table->add_field('criterionname', XMLDB_TYPE_CHAR, '255', null, XMLDB_NOTNULL);
        $table->add_field('currentscore', XMLDB_TYPE_INTEGER, '2', null, XMLDB_NOTNULL, null, '-1');
        $table->add_field('targetscore', XMLDB_TYPE_INTEGER, '2', null, XMLDB_NOTNULL, null, '-1');
        $table->add_field('gapvalue', XMLDB_TYPE_INTEGER, '2', null, XMLDB_NOTNULL, null, '-1');
        $table->add_field('actiontoolid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $table->add_field('courseid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $table->add_field('ownerid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $table->add_field('priority', XMLDB_TYPE_CHAR, '20', null, XMLDB_NOTNULL, null, 'core');
        $table->add_field('status', XMLDB_TYPE_CHAR, '20', null, XMLDB_NOTNULL, null, 'planned');
        $table->add_field('expectedresult', XMLDB_TYPE_TEXT);
        $table->add_field('completioncomment', XMLDB_TYPE_TEXT);
        $table->add_field('startdate', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $table->add_field('duedate', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $table->add_field('completedat', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $table->add_field('createdby', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $table->add_field('modifiedby', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $table->add_field('timecreated', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $table->add_field('timemodified', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $table->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);
        $table->add_key('company_fk', XMLDB_KEY_FOREIGN, ['companyid'], 'local_kbacorp_company', ['id']);
        $table->add_key('audit_fk', XMLDB_KEY_FOREIGN, ['auditid'], 'local_kbacorp_impl_audit', ['id']);
        $table->add_key('item_fk', XMLDB_KEY_FOREIGN, ['itemid'], 'local_kbacorp_impl_item', ['id']);
        $table->add_index('item_route_uix', XMLDB_INDEX_UNIQUE, ['itemid', 'routeid']);
        $table->add_index('company_status_due_ix', XMLDB_INDEX_NOTUNIQUE, ['companyid', 'status', 'duedate']);
        $table->add_index('owner_status_ix', XMLDB_INDEX_NOTUNIQUE, ['ownerid', 'status']);
        if (!$dbman->table_exists($table)) { $dbman->create_table($table); }
        upgrade_plugin_savepoint(true, 2026090242, 'local', 'kbacorporate');
    }

    if ($oldversion < 2026090243) {
        // v0.21.3.1: corporate dashboard audit ordering and restored green visual identity.
        upgrade_plugin_savepoint(true, 2026090243, 'local', 'kbacorporate');
    }

    if ($oldversion < 2026090244) {
        // v0.21.3.2: explicitly load Moodle filelib on evidence form pages.
        upgrade_plugin_savepoint(true, 2026090244, 'local', 'kbacorporate');
    }

    if ($oldversion < 2026090245) {
        // v0.21.3.3: economic indicator snapshots for Point A / Point B comparisons.
        $table = new xmldb_table('local_kbacorp_impl_econ');
        $table->add_field('id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE);
        $table->add_field('auditid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL);
        $table->add_field('companyid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL);
        $table->add_field('periodtype', XMLDB_TYPE_CHAR, '20', null, XMLDB_NOTNULL, null, 'year');
        $table->add_field('periodstart', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $table->add_field('periodend', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $table->add_field('currency', XMLDB_TYPE_CHAR, '3', null, XMLDB_NOTNULL, null, 'RUB');
        foreach (['revenue', 'grossprofit', 'operatingprofit', 'netprofit', 'profittax', 'vat',
                'socialfunds', 'othertaxes', 'payroll', 'averagesalary'] as $fieldname) {
            $table->add_field($fieldname, XMLDB_TYPE_NUMBER, '20, 2');
        }
        $table->add_field('employees', XMLDB_TYPE_INTEGER, '10');
        $table->add_field('source', XMLDB_TYPE_CHAR, '30', null, XMLDB_NOTNULL, null, 'manual');
        $table->add_field('comment', XMLDB_TYPE_TEXT);
        $table->add_field('verified', XMLDB_TYPE_INTEGER, '1', null, XMLDB_NOTNULL, null, '0');
        $table->add_field('verifiedby', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $table->add_field('verifiedat', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $table->add_field('createdby', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $table->add_field('modifiedby', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $table->add_field('timecreated', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $table->add_field('timemodified', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $table->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);
        $table->add_key('audit_fk', XMLDB_KEY_FOREIGN, ['auditid'], 'local_kbacorp_impl_audit', ['id']);
        $table->add_key('company_fk', XMLDB_KEY_FOREIGN, ['companyid'], 'local_kbacorp_company', ['id']);
        $table->add_index('company_period_ix', XMLDB_INDEX_NOTUNIQUE, ['companyid', 'periodend']);
        if (!$dbman->table_exists($table)) {
            $dbman->create_table($table);
        }
        upgrade_plugin_savepoint(true, 2026090245, 'local', 'kbacorporate');
    }

    if ($oldversion < 2026090246) {
        // v0.21.3.4: stable company profile details reused by all audits.
        $table = new xmldb_table('local_kbacorp_company');
        $activity = new xmldb_field('primaryactivity', XMLDB_TYPE_CHAR, '255', null,
            null, null, null, 'bitrixcompanyid');
        if (!$dbman->field_exists($table, $activity)) {
            $dbman->add_field($table, $activity);
        }
        $year = new xmldb_field('foundedyear', XMLDB_TYPE_INTEGER, '4', null,
            XMLDB_NOTNULL, null, '0', 'primaryactivity');
        if (!$dbman->field_exists($table, $year)) {
            $dbman->add_field($table, $year);
        }
        upgrade_plugin_savepoint(true, 2026090246, 'local', 'kbacorporate');
    }

    if ($oldversion < 2026090247) {
        // v0.21.3.5: assessment-form labels and guidance; no schema changes.
        upgrade_plugin_savepoint(true, 2026090247, 'local', 'kbacorporate');
    }

    if ($oldversion < 2026090248) {
        // v0.22.0: commercial implementation program gates the managed plan.
        $table = new xmldb_table('local_kbacorp_impl_program');
        $table->add_field('id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE);
        $table->add_field('companyid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL);
        $table->add_field('baselineauditid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL);
        $table->add_field('finalauditid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $table->add_field('code', XMLDB_TYPE_CHAR, '100', null, XMLDB_NOTNULL);
        $table->add_field('title', XMLDB_TYPE_CHAR, '255', null, XMLDB_NOTNULL);
        $table->add_field('package', XMLDB_TYPE_CHAR, '30', null, XMLDB_NOTNULL, null, 'start');
        $table->add_field('commercialstatus', XMLDB_TYPE_CHAR, '30', null, XMLDB_NOTNULL, null, 'requested');
        $table->add_field('status', XMLDB_TYPE_CHAR, '20', null, XMLDB_NOTNULL, null, 'draft');
        foreach (['startdate', 'enddate', 'curatorid', 'companyownerid', 'requestedby', 'activatedby',
                'activatedat', 'timecreated', 'timemodified'] as $fieldname) {
            $table->add_field($fieldname, XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        }
        $table->add_field('bitrixdealid', XMLDB_TYPE_CHAR, '100');
        $table->add_field('notes', XMLDB_TYPE_TEXT);
        $table->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);
        $table->add_key('company_fk', XMLDB_KEY_FOREIGN, ['companyid'], 'local_kbacorp_company', ['id']);
        $table->add_key('baseline_fk', XMLDB_KEY_FOREIGN, ['baselineauditid'], 'local_kbacorp_impl_audit', ['id']);
        $table->add_index('company_code_uix', XMLDB_INDEX_UNIQUE, ['companyid', 'code']);
        $table->add_index('company_status_ix', XMLDB_INDEX_NOTUNIQUE, ['companyid', 'status']);
        if (!$dbman->table_exists($table)) { $dbman->create_table($table); }
        upgrade_plugin_savepoint(true, 2026090248, 'local', 'kbacorporate');
    }

    if ($oldversion < 2026090249) {
        // v0.22.1: editable implementation packages and Bitrix24 funnel configuration.
        $table = new xmldb_table('local_kbacorp_impl_package');
        $table->add_field('id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE);
        $table->add_field('code', XMLDB_TYPE_CHAR, '50', null, XMLDB_NOTNULL);
        $table->add_field('name', XMLDB_TYPE_CHAR, '255', null, XMLDB_NOTNULL);
        $table->add_field('description', XMLDB_TYPE_TEXT);
        $table->add_field('features', XMLDB_TYPE_TEXT);
        $table->add_field('price', XMLDB_TYPE_NUMBER, '20, 2');
        $table->add_field('currency', XMLDB_TYPE_CHAR, '3', null, XMLDB_NOTNULL, null, 'RUB');
        $table->add_field('durationdays', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $table->add_field('sortorder', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $table->add_field('active', XMLDB_TYPE_INTEGER, '1', null, XMLDB_NOTNULL, null, '1');
        $table->add_field('timecreated', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $table->add_field('timemodified', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $table->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);
        $table->add_index('code_uix', XMLDB_INDEX_UNIQUE, ['code']);
        $table->add_index('active_sort_ix', XMLDB_INDEX_NOTUNIQUE, ['active', 'sortorder']);
        if (!$dbman->table_exists($table)) { $dbman->create_table($table); }
        $now = time();
        $defaults = [
            ['start', 'BMS Start', 10, 'Шаблон плана, обучение и самостоятельное внедрение.'],
            ['support', 'BMS Support', 20, 'План, куратор и регулярный контроль внедрения.'],
            ['transformation', 'BMS Transformation', 30, 'Полное сопровождение, экономика, повторный аудит и подготовка к сертификации.'],
        ];
        foreach ($defaults as [$code, $name, $sortorder, $description]) {
            if (!$DB->record_exists('local_kbacorp_impl_package', ['code' => $code])) {
                $DB->insert_record('local_kbacorp_impl_package', (object)['code' => $code, 'name' => $name,
                    'description' => $description, 'features' => null, 'price' => null, 'currency' => 'RUB',
                    'durationdays' => 0, 'sortorder' => $sortorder, 'active' => 1,
                    'timecreated' => $now, 'timemodified' => $now]);
            }
        }
        upgrade_plugin_savepoint(true, 2026090249, 'local', 'kbacorporate');
    }

    if ($oldversion < 2026090250) {
        // v0.22.2: live Bitrix24 pipeline, stage and responsible-person selector; no schema changes.
        upgrade_plugin_savepoint(true, 2026090250, 'local', 'kbacorporate');
    }

    if ($oldversion < 2026090251) {
        // v0.22.2.1: encode nested Bitrix24 REST parameters using bracket notation.
        upgrade_plugin_savepoint(true, 2026090251, 'local', 'kbacorporate');
    }

    if ($oldversion < 2026090252) {
        // v0.22.2.2: isolate Bitrix reference failures and fall back to the webhook owner.
        upgrade_plugin_savepoint(true, 2026090252, 'local', 'kbacorporate');
    }

    if ($oldversion < 2026090253) {
        // v0.23.0: customer-facing BMS implementation workspace; no schema changes.
        upgrade_plugin_savepoint(true, 2026090253, 'local', 'kbacorporate');
    }

    if ($oldversion < 2026090254) {
        // v0.23.1: permanent BMS navigation and direct tariff selection; no schema changes.
        upgrade_plugin_savepoint(true, 2026090254, 'local', 'kbacorporate');
    }

    if ($oldversion < 2026090255) {
        // v0.23.1.1: always-visible multi-company BMS navigation; no schema changes.
        upgrade_plugin_savepoint(true, 2026090255, 'local', 'kbacorporate');
    }

    if ($oldversion < 2026090256) {
        // v0.23.2: administrator client search and implementation-state filters; no schema changes.
        upgrade_plugin_savepoint(true, 2026090256, 'local', 'kbacorporate');
    }

    if ($oldversion < 2026090257) {
        // v0.23.3: implementation client worklist, curator assignment and CSV; no schema changes.
        upgrade_plugin_savepoint(true, 2026090257, 'local', 'kbacorporate');
    }

    if ($oldversion < 2026090258) {
        // v0.23.3.1: reliable multi-company switching from the permanent BMS shortcut.
        upgrade_plugin_savepoint(true, 2026090258, 'local', 'kbacorporate');
    }

    if ($oldversion < 2026090259) {
        // v0.24.0: customer contact and request details for implementation programs.
        $table = new xmldb_table('local_kbacorp_impl_program');
        $fields = [
            new xmldb_field('contactname', XMLDB_TYPE_CHAR, '255', null, null, null, null, 'companyownerid'),
            new xmldb_field('contactemail', XMLDB_TYPE_CHAR, '255', null, null, null, null, 'contactname'),
            new xmldb_field('contactphone', XMLDB_TYPE_CHAR, '100', null, null, null, null, 'contactemail'),
            new xmldb_field('customercomment', XMLDB_TYPE_TEXT, null, null, null, null, null, 'contactphone'),
        ];
        foreach ($fields as $field) {
            if (!$dbman->field_exists($table, $field)) {
                $dbman->add_field($table, $field);
            }
        }
        upgrade_plugin_savepoint(true, 2026090259, 'local', 'kbacorporate');
    }

    if ($oldversion < 2026090260) {
        // v0.24.0.1: visible active-client context on implementation pages; no schema changes.
        upgrade_plugin_savepoint(true, 2026090260, 'local', 'kbacorporate');
    }

    if ($oldversion < 2026090261) {
        // v0.24.1: implementation request message provider and notifications; no schema changes.
        upgrade_plugin_savepoint(true, 2026090261, 'local', 'kbacorporate');
    }

    if ($oldversion < 2026090262) {
        // v0.24.2: customer-facing response and explicit request status-change metadata.
        $table = new xmldb_table('local_kbacorp_impl_program');
        $fields = [
            new xmldb_field('customerresponse', XMLDB_TYPE_TEXT, null, null, null, null, null,
                'customercomment'),
            new xmldb_field('statuschangedby', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0',
                'customerresponse'),
            new xmldb_field('statuschangedat', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0',
                'statuschangedby'),
        ];
        foreach ($fields as $field) {
            if (!$dbman->field_exists($table, $field)) {
                $dbman->add_field($table, $field);
            }
        }
        upgrade_plugin_savepoint(true, 2026090262, 'local', 'kbacorporate');
    }

    if ($oldversion < 2026090263) {
        // v0.25.0: non-graded discussion messages for implementation tasks.
        $table = new xmldb_table('local_kbacorp_impl_comment');
        if (!$dbman->table_exists($table)) {
            $table->add_field('id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE);
            $table->add_field('taskid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL);
            $table->add_field('userid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL);
            $table->add_field('message', XMLDB_TYPE_TEXT, null, null, XMLDB_NOTNULL);
            $table->add_field('timecreated', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $table->add_field('timemodified', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $table->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);
            $table->add_key('task_fk', XMLDB_KEY_FOREIGN, ['taskid'], 'local_kbacorp_impl_task', ['id']);
            $table->add_key('user_fk', XMLDB_KEY_FOREIGN, ['userid'], 'user', ['id']);
            $table->add_index('task_time_ix', XMLDB_INDEX_NOTUNIQUE, ['taskid', 'timecreated']);
            $table->add_index('user_time_ix', XMLDB_INDEX_NOTUNIQUE, ['userid', 'timecreated']);
            $dbman->create_table($table);
        }
        upgrade_plugin_savepoint(true, 2026090263, 'local', 'kbacorporate');
    }

    if ($oldversion < 2026090264) {
        // v0.25.1: curator workspace and per-user discussion read state.
        $table = new xmldb_table('local_kbacorp_impl_read');
        if (!$dbman->table_exists($table)) {
            $table->add_field('id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE);
            $table->add_field('taskid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL);
            $table->add_field('userid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL);
            $table->add_field('lastreadtime', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $table->add_field('timemodified', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $table->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);
            $table->add_key('task_fk', XMLDB_KEY_FOREIGN, ['taskid'], 'local_kbacorp_impl_task', ['id']);
            $table->add_key('user_fk', XMLDB_KEY_FOREIGN, ['userid'], 'user', ['id']);
            $table->add_index('task_user_uix', XMLDB_INDEX_UNIQUE, ['taskid', 'userid']);
            $table->add_index('user_read_ix', XMLDB_INDEX_NOTUNIQUE, ['userid', 'lastreadtime']);
            $dbman->create_table($table);
        }
        upgrade_plugin_savepoint(true, 2026090264, 'local', 'kbacorporate');
    }

    if ($oldversion < 2026090265) {
        // v0.25.1.1: reliable curator navigation and richer bell notifications; no schema changes.
        upgrade_plugin_savepoint(true, 2026090265, 'local', 'kbacorporate');
    }

    if ($oldversion < 2026090266) {
        // v0.25.2: rich task discussions and linked curator counters.
        $table = new xmldb_table('local_kbacorp_impl_comment');
        $field = new xmldb_field('messageformat', XMLDB_TYPE_INTEGER, '2', null,
            XMLDB_NOTNULL, null, '0', 'message');
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }
        upgrade_plugin_savepoint(true, 2026090266, 'local', 'kbacorporate');
    }

    if ($oldversion < 2026090267) {
        // v0.25.2.1: linked-counter and rich-text rendering hotfix; no schema changes.
        upgrade_plugin_savepoint(true, 2026090267, 'local', 'kbacorporate');
    }

    if ($oldversion < 2026090268) {
        // v0.25.2.2: task-title links, consistent timestamps and encoded HTML cleanup; no schema changes.
        upgrade_plugin_savepoint(true, 2026090268, 'local', 'kbacorporate');
    }

    if ($oldversion < 2026090269) {
        // v0.25.3: live curator student search and selectable suggestions; no schema changes.
        upgrade_plugin_savepoint(true, 2026090269, 'local', 'kbacorporate');
    }

    if ($oldversion < 2026090270) {
        // v0.25.3.1: live implementation-company search and selectable suggestions; no schema changes.
        upgrade_plugin_savepoint(true, 2026090270, 'local', 'kbacorporate');
    }

    if ($oldversion < 2026090271) {
        // v0.26.0: controlled implementation-task lifecycle and status history.
        $table = new xmldb_table('local_kbacorp_impl_tasklog');
        if (!$dbman->table_exists($table)) {
            $table->add_field('id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE);
            $table->add_field('taskid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL);
            $table->add_field('userid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL);
            $table->add_field('fromstatus', XMLDB_TYPE_CHAR, '20', null, XMLDB_NOTNULL);
            $table->add_field('tostatus', XMLDB_TYPE_CHAR, '20', null, XMLDB_NOTNULL);
            $table->add_field('comment', XMLDB_TYPE_TEXT, null, null, null);
            $table->add_field('timecreated', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $table->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);
            $table->add_key('task_fk', XMLDB_KEY_FOREIGN, ['taskid'], 'local_kbacorp_impl_task', ['id']);
            $table->add_key('user_fk', XMLDB_KEY_FOREIGN, ['userid'], 'user', ['id']);
            $table->add_index('task_time_ix', XMLDB_INDEX_NOTUNIQUE, ['taskid', 'timecreated']);
            $table->add_index('user_time_ix', XMLDB_INDEX_NOTUNIQUE, ['userid', 'timecreated']);
            $dbman->create_table($table);
        }
        upgrade_plugin_savepoint(true, 2026090271, 'local', 'kbacorporate');
    }

    if ($oldversion < 2026090272) {
        // v0.26.1: employee result link and supporting evidence submission.
        $table = new xmldb_table('local_kbacorp_impl_task');
        $field = new xmldb_field('resulturl', XMLDB_TYPE_CHAR, '1333', null, null, null, null,
            'completioncomment');
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }
        upgrade_plugin_savepoint(true, 2026090272, 'local', 'kbacorporate');
    }

    if ($oldversion < 2026090273) {
        // v0.26.2: deadline reminder state for implementation tasks.
        $table = new xmldb_table('local_kbacorp_impl_task');
        $fields = [
            new xmldb_field('lastremindersent', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0',
                'completedat'),
            new xmldb_field('lastreminderdue', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0',
                'lastremindersent'),
            new xmldb_field('lastremindertype', XMLDB_TYPE_CHAR, '20', null, null, null, null,
                'lastreminderdue'),
        ];
        foreach ($fields as $field) {
            if (!$dbman->field_exists($table, $field)) {
                $dbman->add_field($table, $field);
            }
        }
        upgrade_plugin_savepoint(true, 2026090273, 'local', 'kbacorporate');
    }

    if ($oldversion < 2026090274) {
        // v0.26.3: responsive curator implementation-task board; no schema changes.
        upgrade_plugin_savepoint(true, 2026090274, 'local', 'kbacorporate');
    }

    if ($oldversion < 2026090275) {
        // v0.26.3.1: Moodle 4.0 html_writer::link attribute compatibility hotfix; no schema changes.
        upgrade_plugin_savepoint(true, 2026090275, 'local', 'kbacorporate');
    }

    if ($oldversion < 2026090276) {
        // v0.26.4: checklist items inside implementation tasks.
        $table = new xmldb_table('local_kbacorp_impl_check');
        if (!$dbman->table_exists($table)) {
            $table->add_field('id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE);
            $table->add_field('taskid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL);
            $table->add_field('title', XMLDB_TYPE_CHAR, '255', null, XMLDB_NOTNULL);
            $table->add_field('isdone', XMLDB_TYPE_INTEGER, '1', null, XMLDB_NOTNULL, null, '0');
            $table->add_field('sortorder', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $table->add_field('createdby', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $table->add_field('completedby', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $table->add_field('completedat', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $table->add_field('timecreated', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $table->add_field('timemodified', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $table->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);
            $table->add_key('task_fk', XMLDB_KEY_FOREIGN, ['taskid'], 'local_kbacorp_impl_task', ['id']);
            $table->add_index('task_sort_ix', XMLDB_INDEX_NOTUNIQUE, ['taskid', 'sortorder']);
            $table->add_index('task_done_ix', XMLDB_INDEX_NOTUNIQUE, ['taskid', 'isdone']);
            $dbman->create_table($table);
        }
        upgrade_plugin_savepoint(true, 2026090276, 'local', 'kbacorporate');
    }

    if ($oldversion < 2026090277) {
        // v0.27.0: implementation effectiveness analytics and CSV; no schema changes.
        upgrade_plugin_savepoint(true, 2026090277, 'local', 'kbacorporate');
    }

    if ($oldversion < 2026090278) {
        // v0.27.1: effectiveness drill-down, monthly dynamics and BMS Index delta; no schema changes.
        upgrade_plugin_savepoint(true, 2026090278, 'local', 'kbacorporate');
    }

    if ($oldversion < 2026090279) {
        // v0.27.1.1: implementation-audit tool selector hotfix; no schema changes.
        upgrade_plugin_savepoint(true, 2026090279, 'local', 'kbacorporate');
    }

    if ($oldversion < 2026090280) {
        // v0.27.2: BMS tool library and full company-tool editor; no schema changes.
        upgrade_plugin_savepoint(true, 2026090280, 'local', 'kbacorporate');
    }

    if ($oldversion < 2026090281) {
        // v0.27.3: immutable BMS tool versions and audit snapshot pinning.
        $table = new xmldb_table('local_kbacorp_bms_tool');
        foreach ([
            new xmldb_field('currentversionid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0', 'active'),
            new xmldb_field('currentversion', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0', 'currentversionid'),
            new xmldb_field('hasdraft', XMLDB_TYPE_INTEGER, '1', null, XMLDB_NOTNULL, null, '0', 'currentversion'),
        ] as $field) {
            if (!$dbman->field_exists($table, $field)) {
                $dbman->add_field($table, $field);
            }
        }

        $versiontable = new xmldb_table('local_kbacorp_bms_toolver');
        if (!$dbman->table_exists($versiontable)) {
            $versiontable->add_field('id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE);
            $versiontable->add_field('toolid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL);
            $versiontable->add_field('versionnum', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL);
            $versiontable->add_field('versionlabel', XMLDB_TYPE_CHAR, '50', null, XMLDB_NOTNULL);
            $versiontable->add_field('status', XMLDB_TYPE_CHAR, '20', null, XMLDB_NOTNULL, null, 'published');
            $versiontable->add_field('snapshotjson', XMLDB_TYPE_TEXT, null, null, XMLDB_NOTNULL);
            $versiontable->add_field('contenthash', XMLDB_TYPE_CHAR, '64', null, XMLDB_NOTNULL);
            $versiontable->add_field('changenote', XMLDB_TYPE_TEXT, null, null, null);
            $versiontable->add_field('publishedby', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $versiontable->add_field('publishedat', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $versiontable->add_field('timecreated', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $versiontable->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);
            $versiontable->add_key('tool_fk', XMLDB_KEY_FOREIGN, ['toolid'], 'local_kbacorp_bms_tool', ['id']);
            $versiontable->add_index('tool_version_uix', XMLDB_INDEX_UNIQUE, ['toolid', 'versionnum']);
            $versiontable->add_index('tool_status_ix', XMLDB_INDEX_NOTUNIQUE, ['toolid', 'status']);
            $dbman->create_table($versiontable);
        }

        $audittable = new xmldb_table('local_kbacorp_impl_audit');
        $field = new xmldb_field('toolversionid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0', 'toolid');
        if (!$dbman->field_exists($audittable, $field)) {
            $dbman->add_field($audittable, $field);
        }
        $field = new xmldb_field('toolversionlabel', XMLDB_TYPE_CHAR, '50', null, null, null, null, 'toolversionid');
        if (!$dbman->field_exists($audittable, $field)) {
            $dbman->add_field($audittable, $field);
        }
        $index = new xmldb_index('tool_version_ix', XMLDB_INDEX_NOTUNIQUE, ['toolid', 'toolversionid']);
        if (!$dbman->index_exists($audittable, $index)) {
            $dbman->add_index($audittable, $index);
        }
        upgrade_plugin_savepoint(true, 2026090281, 'local', 'kbacorporate');
    }

    if ($oldversion < 2026090282) {
        // v0.27.4: unified BMS implementation workspace navigation; no schema changes.
        upgrade_plugin_savepoint(true, 2026090282, 'local', 'kbacorporate');
    }

    if ($oldversion < 2026090283) {
        // v0.27.5: optimized tariff, request and implementation-plan routing; no schema changes.
        upgrade_plugin_savepoint(true, 2026090283, 'local', 'kbacorporate');
    }

    if ($oldversion < 2026090284) {
        // v0.27.6: role-aware personal, company and implementation navigation; no schema changes.
        upgrade_plugin_savepoint(true, 2026090284, 'local', 'kbacorporate');
    }

    if ($oldversion < 2026090285) {
        // v0.27.6.1: responsive workspace menu layout hotfix; no schema changes.
        upgrade_plugin_savepoint(true, 2026090285, 'local', 'kbacorporate');
    }

    if ($oldversion < 2026090286) {
        // v0.28.0: versioned links between BMS criteria and Moodle competencies.
        $table = new xmldb_table('local_kbacorp_bms_comp');
        if (!$dbman->table_exists($table)) {
            $table->add_field('id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE);
            $table->add_field('criterionid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL);
            $table->add_field('competencyid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL);
            $table->add_field('targetlevel', XMLDB_TYPE_INTEGER, '2', null, XMLDB_NOTNULL, null, '0');
            $table->add_field('priority', XMLDB_TYPE_CHAR, '20', null, XMLDB_NOTNULL, null, 'core');
            $table->add_field('required', XMLDB_TYPE_INTEGER, '1', null, XMLDB_NOTNULL, null, '1');
            $table->add_field('timecreated', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $table->add_field('timemodified', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $table->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);
            $table->add_key('criterion_fk', XMLDB_KEY_FOREIGN, ['criterionid'], 'local_kbacorp_bms_crit', ['id']);
            $table->add_key('competency_fk', XMLDB_KEY_FOREIGN, ['competencyid'], 'competency', ['id']);
            $table->add_index('criterion_comp_uix', XMLDB_INDEX_UNIQUE, ['criterionid', 'competencyid']);
            $dbman->create_table($table);
        }
        $itemtable = new xmldb_table('local_kbacorp_impl_item');
        $field = new xmldb_field('competenciesjson', XMLDB_TYPE_TEXT, null, null, null, null, null, 'evidencehint');
        if (!$dbman->field_exists($itemtable, $field)) {
            $dbman->add_field($itemtable, $field);
        }
        upgrade_plugin_savepoint(true, 2026090286, 'local', 'kbacorporate');
    }

    if ($oldversion < 2026090287) {
        // v0.28.0.1: remove a duplicate explicit index already supplied by competency_fk.
        upgrade_plugin_savepoint(true, 2026090287, 'local', 'kbacorporate');
    }

    if ($oldversion < 2026090288) {
        // v0.28.1: editable BMS competency mappings; no schema changes.
        upgrade_plugin_savepoint(true, 2026090288, 'local', 'kbacorporate');
    }

    if ($oldversion < 2026090289) {
        // v0.28.2: stable-code JSON import/export for BMS competency mappings; no schema changes.
        upgrade_plugin_savepoint(true, 2026090289, 'local', 'kbacorporate');
    }

    if ($oldversion < 2026090292) {
        // v0.29.2: Competency ↔ BMS Mapping Engine UI and full JSON exports.
        // Uses the existing local_kbacorp_bms_comp mapping table introduced in v0.28.0.
        // No database schema changes are required.
        upgrade_plugin_savepoint(true, 2026090292, 'local', 'kbacorporate');
    }


    if ($oldversion < 2026090306) {
        // v0.29.6.2: process-oriented BMS workspace navigation; no schema changes.
        upgrade_plugin_savepoint(true, 2026090306, 'local', 'kbacorporate');
    }


    if ($oldversion < 2026090307) {
        // v0.29.6.3: consolidate tools and JSON exchange under BMS methodology; no schema changes.
        upgrade_plugin_savepoint(true, 2026090307, 'local', 'kbacorporate');
    }


    if ($oldversion < 2026090607) {
        // v0.31.1: link KBA role/GAP metadata to standard Moodle Learning Plans and templates.
        $roletable = new xmldb_table('local_kbacorp_busrole');
        $rolefield = new xmldb_field('moodletemplateid', XMLDB_TYPE_INTEGER, '10', null,
            XMLDB_NOTNULL, null, '0', 'appliedversion');
        if ($dbman->table_exists($roletable) && !$dbman->field_exists($roletable, $rolefield)) {
            $dbman->add_field($roletable, $rolefield);
        }
        $roleindex = new xmldb_index('moodletemplate_ix', XMLDB_INDEX_NOTUNIQUE, ['moodletemplateid']);
        if ($dbman->table_exists($roletable) && !$dbman->index_exists($roletable, $roleindex)) {
            $dbman->add_index($roletable, $roleindex);
        }

        $plantable = new xmldb_table('local_kbacorp_devplan');
        $planfield = new xmldb_field('moodleplanid', XMLDB_TYPE_INTEGER, '10', null,
            XMLDB_NOTNULL, null, '0', 'followupauditid');
        if ($dbman->table_exists($plantable) && !$dbman->field_exists($plantable, $planfield)) {
            $dbman->add_field($plantable, $planfield);
        }
        $planindex = new xmldb_index('moodleplan_ix', XMLDB_INDEX_NOTUNIQUE, ['moodleplanid']);
        if ($dbman->table_exists($plantable) && !$dbman->index_exists($plantable, $planindex)) {
            $dbman->add_index($plantable, $planindex);
        }

        // Existing KBA data is intentionally not rewritten here. The non-destructive migration
        // page creates/links Moodle core templates and plans under an authorised manager session.
        upgrade_plugin_savepoint(true, 2026090607, 'local', 'kbacorporate');
    }


    if ($oldversion < 2026090608) {
        // v0.31.2: automatic non-destructive GAP -> Moodle Learning Plan builder; no schema changes.
        upgrade_plugin_savepoint(true, 2026090608, 'local', 'kbacorporate');
    }

    if ($oldversion < 2026090609) {
        // v0.31.2.1: role-profile domain-node integrity and GAP matrix guards; no schema changes.
        upgrade_plugin_savepoint(true, 2026090609, 'local', 'kbacorporate');
    }

    if ($oldversion < 2026090610) {
        // v0.31.2.2: selective BMS-Dxx -> concrete role competency migration; no schema changes.
        upgrade_plugin_savepoint(true, 2026090610, 'local', 'kbacorporate');
    }

    if ($oldversion < 2026090611) {
        // v0.31.2.3: bulk role-profile target levels, priorities and mandatory flags; no schema changes.
        upgrade_plugin_savepoint(true, 2026090611, 'local', 'kbacorporate');
    }

    if ($oldversion < 2026090612) {
        // v0.31.2.4: global role edit return-company context hotfix; no schema changes.
        upgrade_plugin_savepoint(true, 2026090612, 'local', 'kbacorporate');
    }

    if ($oldversion < 2026090613) {
        // v0.31.3: role competency course coverage via Moodle core relations; no schema changes.
        upgrade_plugin_savepoint(true, 2026090613, 'local', 'kbacorporate');
    }

    if ($oldversion < 2026090614) {
        // v0.31.4: non-destructive course coverage integrity diagnostics; no schema changes.
        upgrade_plugin_savepoint(true, 2026090614, 'local', 'kbacorporate');
    }

    if ($oldversion < 2026090615) {
        // v0.31.5: employee business-role GAP calculation and diagnostics; no schema changes.
        upgrade_plugin_savepoint(true, 2026090615, 'local', 'kbacorporate');
    }


    if ($oldversion < 2026090801) {
        // v0.31.5.1: Russian UI terminology for competency gaps and Moodle learning plans; no schema changes.
        upgrade_plugin_savepoint(true, 2026090801, 'local', 'kbacorporate');
    }


    if ($oldversion < 2026090802) {
        // v0.31.6: selectable study-plan preview, assessment guards and deterministic deadlines; no schema changes.
        upgrade_plugin_savepoint(true, 2026090802, 'local', 'kbacorporate');
    }

    if ($oldversion < 2026090803) {
        // v0.31.7: explicit KBA -> Moodle study-plan provisioning, confirmation and verification; no schema changes.
        upgrade_plugin_savepoint(true, 2026090803, 'local', 'kbacorporate');
    }

    if ($oldversion < 2026090804) {
        // v0.31.8: read-only study-plan progress, course completion sync and reassessment workflow; no schema changes.
        upgrade_plugin_savepoint(true, 2026090804, 'local', 'kbacorporate');
    }

    if ($oldversion < 2026090805) {
        // v0.32.0: unified employee competency profile and read-only competency history; no schema changes.
        upgrade_plugin_savepoint(true, 2026090805, 'local', 'kbacorporate');
    }

    if ($oldversion < 2026090806) {
        // v0.32.1: employee assessment history, dynamics and CSV export; no schema changes.
        upgrade_plugin_savepoint(true, 2026090806, 'local', 'kbacorporate');
    }

    if ($oldversion < 2026090807) {
        // v0.32.2: explicit post-development competency reassessment workflow; no schema changes.
        upgrade_plugin_savepoint(true, 2026090807, 'local', 'kbacorporate');
    }

    if ($oldversion < 2026090808) {
        // v0.32.2.1: include the required Moodle page context in template links; no schema changes.
        upgrade_plugin_savepoint(true, 2026090808, 'local', 'kbacorporate');
    }

    if ($oldversion < 2026090809) {
        // v0.32.3: enrich individual development plans with goals, mentors,
        // checkpoints, evidence review and an immutable operational history.
        $plantable = new xmldb_table('local_kbacorp_devplan');
        if ($dbman->table_exists($plantable)) {
            $objective = new xmldb_field('objective', XMLDB_TYPE_TEXT, null, null,
                null, null, null, 'title');
            if (!$dbman->field_exists($plantable, $objective)) {
                $dbman->add_field($plantable, $objective);
            }
            $mentorid = new xmldb_field('mentorid', XMLDB_TYPE_INTEGER, '10', null,
                XMLDB_NOTNULL, null, '0', 'objective');
            if (!$dbman->field_exists($plantable, $mentorid)) {
                $dbman->add_field($plantable, $mentorid);
            }
            $reviewdate = new xmldb_field('reviewdate', XMLDB_TYPE_INTEGER, '10', null,
                XMLDB_NOTNULL, null, '0', 'targetdate');
            if (!$dbman->field_exists($plantable, $reviewdate)) {
                $dbman->add_field($plantable, $reviewdate);
            }
            // Existing plans receive a sensible first review date without changing their deadline.
            $DB->execute(
                'UPDATE {local_kbacorp_devplan} SET reviewdate = targetdate ' .
                'WHERE reviewdate = 0 AND targetdate > 0'
            );
        }

        $itemtable = new xmldb_table('local_kbacorp_devitem');
        if ($dbman->table_exists($itemtable)) {
            $fields = [
                new xmldb_field('required', XMLDB_TYPE_INTEGER, '1', null,
                    XMLDB_NOTNULL, null, '1', 'priority'),
                new xmldb_field('goal', XMLDB_TYPE_TEXT, null, null,
                    null, null, null, 'required'),
                new xmldb_field('practicaltask', XMLDB_TYPE_TEXT, null, null,
                    null, null, null, 'goal'),
                new xmldb_field('evidencecriteria', XMLDB_TYPE_TEXT, null, null,
                    null, null, null, 'practicaltask'),
                new xmldb_field('mentorid', XMLDB_TYPE_INTEGER, '10', null,
                    XMLDB_NOTNULL, null, '0', 'evidencecriteria'),
                new xmldb_field('checkpointdate', XMLDB_TYPE_INTEGER, '10', null,
                    XMLDB_NOTNULL, null, '0', 'mentorid'),
                new xmldb_field('checkpointcomment', XMLDB_TYPE_TEXT, null, null,
                    null, null, null, 'checkpointdate'),
                new xmldb_field('evidenceurl', XMLDB_TYPE_CHAR, '1333', null,
                    null, null, null, 'checkpointcomment'),
                new xmldb_field('evidencecomment', XMLDB_TYPE_TEXT, null, null,
                    null, null, null, 'evidenceurl'),
                new xmldb_field('evidencesubmittedby', XMLDB_TYPE_INTEGER, '10', null,
                    XMLDB_NOTNULL, null, '0', 'evidencecomment'),
                new xmldb_field('timeevidencesubmitted', XMLDB_TYPE_INTEGER, '10', null,
                    XMLDB_NOTNULL, null, '0', 'evidencesubmittedby'),
                new xmldb_field('reviewedby', XMLDB_TYPE_INTEGER, '10', null,
                    XMLDB_NOTNULL, null, '0', 'timeevidencesubmitted'),
                new xmldb_field('timereviewed', XMLDB_TYPE_INTEGER, '10', null,
                    XMLDB_NOTNULL, null, '0', 'reviewedby'),
                new xmldb_field('reviewcomment', XMLDB_TYPE_TEXT, null, null,
                    null, null, null, 'timereviewed'),
            ];
            $requiredfieldadded = false;
            foreach ($fields as $field) {
                if (!$dbman->field_exists($itemtable, $field)) {
                    $dbman->add_field($itemtable, $field);
                    if ($field->getName() === 'required') {
                        $requiredfieldadded = true;
                    }
                }
            }
            if ($requiredfieldadded) {
                // Preserve optional role requirements for existing plans where the current role profile
                // provides an unambiguous source. The safe fallback remains mandatory.
                $existingitems = $DB->get_records_sql(
                    "SELECT i.id, i.competencyid, p.companyid, p.userid
                       FROM {local_kbacorp_devitem} i
                       JOIN {local_kbacorp_devplan} p ON p.id = i.planid"
                );
                foreach ($existingitems as $existingitem) {
                    $requirements = $DB->get_records_sql(
                        "SELECT rc.id, rc.required
                           FROM {local_kbacorp_member_busrole} ur
                           JOIN {local_kbacorp_rolecomp} rc ON rc.roleid = ur.roleid
                          WHERE ur.companyid = :companyid AND ur.userid = :userid
                            AND ur.active = 1 AND rc.competencyid = :competencyid",
                        [
                            'companyid' => (int)$existingitem->companyid,
                            'userid' => (int)$existingitem->userid,
                            'competencyid' => (int)$existingitem->competencyid,
                        ]
                    );
                    if ($requirements) {
                        $mandatory = false;
                        foreach ($requirements as $requirement) {
                            $mandatory = $mandatory || !empty($requirement->required);
                        }
                        $DB->set_field('local_kbacorp_devitem', 'required', $mandatory ? 1 : 0,
                            ['id' => (int)$existingitem->id]);
                    }
                }
            }
            $mentorindex = new xmldb_index('mentor_status_ix', XMLDB_INDEX_NOTUNIQUE,
                ['mentorid', 'status']);
            if (!$dbman->index_exists($itemtable, $mentorindex)) {
                $dbman->add_index($itemtable, $mentorindex);
            }
            $reviewerindex = new xmldb_index('reviewer_status_ix', XMLDB_INDEX_NOTUNIQUE,
                ['reviewedby', 'status']);
            if (!$dbman->index_exists($itemtable, $reviewerindex)) {
                $dbman->add_index($itemtable, $reviewerindex);
            }
        }

        $logtable = new xmldb_table('local_kbacorp_devlog');
        if (!$dbman->table_exists($logtable)) {
            $logtable->add_field('id', XMLDB_TYPE_INTEGER, '10', null,
                XMLDB_NOTNULL, XMLDB_SEQUENCE, null);
            $logtable->add_field('planid', XMLDB_TYPE_INTEGER, '10', null,
                XMLDB_NOTNULL, null, null);
            $logtable->add_field('itemid', XMLDB_TYPE_INTEGER, '10', null,
                XMLDB_NOTNULL, null, '0');
            $logtable->add_field('actorid', XMLDB_TYPE_INTEGER, '10', null,
                XMLDB_NOTNULL, null, '0');
            $logtable->add_field('eventtype', XMLDB_TYPE_CHAR, '50', null,
                XMLDB_NOTNULL, null, null);
            $logtable->add_field('fromstatus', XMLDB_TYPE_CHAR, '20', null,
                null, null, null);
            $logtable->add_field('tostatus', XMLDB_TYPE_CHAR, '20', null,
                null, null, null);
            $logtable->add_field('comment', XMLDB_TYPE_TEXT, null, null,
                null, null, null);
            $logtable->add_field('detailsjson', XMLDB_TYPE_TEXT, null, null,
                null, null, null);
            $logtable->add_field('timecreated', XMLDB_TYPE_INTEGER, '10', null,
                XMLDB_NOTNULL, null, '0');
            $logtable->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);
            $logtable->add_key('plan_fk', XMLDB_KEY_FOREIGN, ['planid'],
                'local_kbacorp_devplan', ['id']);
            $logtable->add_index('plan_time_ix', XMLDB_INDEX_NOTUNIQUE,
                ['planid', 'timecreated']);
            $logtable->add_index('item_time_ix', XMLDB_INDEX_NOTUNIQUE,
                ['itemid', 'timecreated']);
            $logtable->add_index('actor_time_ix', XMLDB_INDEX_NOTUNIQUE,
                ['actorid', 'timecreated']);
            $dbman->create_table($logtable);
        }

        upgrade_plugin_savepoint(true, 2026090809, 'local', 'kbacorporate');
    }

    if ($oldversion < 2026090810) {
        // v0.33.0 adds an idempotent audit-to-action workflow without schema changes.
        upgrade_plugin_savepoint(true, 2026090810, 'local', 'kbacorporate');
    }

    if ($oldversion < 2026090811) {
        // v0.33.1: immutable, versioned implementation evidence with explicit review decisions.
        $evidencetable = new xmldb_table('local_kbacorp_impl_evid');
        if (!$dbman->table_exists($evidencetable)) {
            $evidencetable->add_field('id', XMLDB_TYPE_INTEGER, '10', null,
                XMLDB_NOTNULL, XMLDB_SEQUENCE, null);
            $evidencetable->add_field('taskid', XMLDB_TYPE_INTEGER, '10', null,
                XMLDB_NOTNULL, null, null);
            $evidencetable->add_field('versionno', XMLDB_TYPE_INTEGER, '10', null,
                XMLDB_NOTNULL, null, '1');
            $evidencetable->add_field('status', XMLDB_TYPE_CHAR, '20', null,
                XMLDB_NOTNULL, null, 'submitted');
            $evidencetable->add_field('summary', XMLDB_TYPE_TEXT, null, null,
                XMLDB_NOTNULL, null, null);
            $evidencetable->add_field('externalurl', XMLDB_TYPE_CHAR, '1333', null,
                null, null, null);
            $evidencetable->add_field('submitterid', XMLDB_TYPE_INTEGER, '10', null,
                XMLDB_NOTNULL, null, '0');
            $evidencetable->add_field('submittedat', XMLDB_TYPE_INTEGER, '10', null,
                XMLDB_NOTNULL, null, '0');
            $evidencetable->add_field('reviewerid', XMLDB_TYPE_INTEGER, '10', null,
                XMLDB_NOTNULL, null, '0');
            $evidencetable->add_field('reviewedat', XMLDB_TYPE_INTEGER, '10', null,
                XMLDB_NOTNULL, null, '0');
            $evidencetable->add_field('reviewcomment', XMLDB_TYPE_TEXT, null, null,
                null, null, null);
            $evidencetable->add_field('supersedesid', XMLDB_TYPE_INTEGER, '10', null,
                XMLDB_NOTNULL, null, '0');
            $evidencetable->add_field('createdby', XMLDB_TYPE_INTEGER, '10', null,
                XMLDB_NOTNULL, null, '0');
            $evidencetable->add_field('modifiedby', XMLDB_TYPE_INTEGER, '10', null,
                XMLDB_NOTNULL, null, '0');
            $evidencetable->add_field('timecreated', XMLDB_TYPE_INTEGER, '10', null,
                XMLDB_NOTNULL, null, '0');
            $evidencetable->add_field('timemodified', XMLDB_TYPE_INTEGER, '10', null,
                XMLDB_NOTNULL, null, '0');
            $evidencetable->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);
            $evidencetable->add_key('task_fk', XMLDB_KEY_FOREIGN, ['taskid'],
                'local_kbacorp_impl_task', ['id']);
            $evidencetable->add_index('task_version_uix', XMLDB_INDEX_UNIQUE,
                ['taskid', 'versionno']);
            $evidencetable->add_index('task_status_ix', XMLDB_INDEX_NOTUNIQUE,
                ['taskid', 'status']);
            $evidencetable->add_index('submitter_time_ix', XMLDB_INDEX_NOTUNIQUE,
                ['submitterid', 'submittedat']);
            $evidencetable->add_index('reviewer_time_ix', XMLDB_INDEX_NOTUNIQUE,
                ['reviewerid', 'reviewedat']);
            $dbman->create_table($evidencetable);
        }

        $logtable = new xmldb_table('local_kbacorp_impl_evlog');
        if (!$dbman->table_exists($logtable)) {
            $logtable->add_field('id', XMLDB_TYPE_INTEGER, '10', null,
                XMLDB_NOTNULL, XMLDB_SEQUENCE, null);
            $logtable->add_field('evidenceid', XMLDB_TYPE_INTEGER, '10', null,
                XMLDB_NOTNULL, null, null);
            $logtable->add_field('taskid', XMLDB_TYPE_INTEGER, '10', null,
                XMLDB_NOTNULL, null, null);
            $logtable->add_field('userid', XMLDB_TYPE_INTEGER, '10', null,
                XMLDB_NOTNULL, null, '0');
            $logtable->add_field('action', XMLDB_TYPE_CHAR, '30', null,
                XMLDB_NOTNULL, null, null);
            $logtable->add_field('fromstatus', XMLDB_TYPE_CHAR, '20', null,
                null, null, null);
            $logtable->add_field('tostatus', XMLDB_TYPE_CHAR, '20', null,
                null, null, null);
            $logtable->add_field('comment', XMLDB_TYPE_TEXT, null, null,
                null, null, null);
            $logtable->add_field('detailsjson', XMLDB_TYPE_TEXT, null, null,
                null, null, null);
            $logtable->add_field('timecreated', XMLDB_TYPE_INTEGER, '10', null,
                XMLDB_NOTNULL, null, '0');
            $logtable->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);
            $logtable->add_key('evidence_fk', XMLDB_KEY_FOREIGN, ['evidenceid'],
                'local_kbacorp_impl_evid', ['id']);
            $logtable->add_key('task_fk', XMLDB_KEY_FOREIGN, ['taskid'],
                'local_kbacorp_impl_task', ['id']);
            $logtable->add_index('evidence_time_ix', XMLDB_INDEX_NOTUNIQUE,
                ['evidenceid', 'timecreated']);
            $logtable->add_index('task_time_ix', XMLDB_INDEX_NOTUNIQUE,
                ['taskid', 'timecreated']);
            $logtable->add_index('user_time_ix', XMLDB_INDEX_NOTUNIQUE,
                ['userid', 'timecreated']);
            $dbman->create_table($logtable);
        }

        upgrade_plugin_savepoint(true, 2026090811, 'local', 'kbacorporate');
    }

    if ($oldversion < 2026090812) {
        // v0.33.2: distinguish workflow status transitions from Kanban metadata changes.
        $tasklogtable = new xmldb_table('local_kbacorp_impl_tasklog');
        if ($dbman->table_exists($tasklogtable)) {
            $eventtypefield = new xmldb_field('eventtype', XMLDB_TYPE_CHAR, '30', null,
                XMLDB_NOTNULL, null, 'status', 'tostatus');
            if (!$dbman->field_exists($tasklogtable, $eventtypefield)) {
                $dbman->add_field($tasklogtable, $eventtypefield);
            }
            $detailsfield = new xmldb_field('detailsjson', XMLDB_TYPE_TEXT, null, null,
                null, null, null, 'comment');
            if (!$dbman->field_exists($tasklogtable, $detailsfield)) {
                $dbman->add_field($tasklogtable, $detailsfield);
            }
        }

        upgrade_plugin_savepoint(true, 2026090812, 'local', 'kbacorporate');
    }


    if ($oldversion < 2026090813) {
        // v0.33.3: read-only BMS section implementation progress projection.
        // No database schema changes are required.
        upgrade_plugin_savepoint(true, 2026090813, 'local', 'kbacorporate');
    }


    if ($oldversion < 2026090814) {
        // v0.33.3.1: provider-neutral ERP terminology in the UI.
        // Keep technical provider tokens (including `odoo`) unchanged for compatibility.
        foreach ([
            'competency_template' => 'KBA-BUSROLE:',
            'competency_plan' => 'KBA-DEVPLAN:',
        ] as $tablename => $marker) {
            $table = new xmldb_table($tablename);
            if (!$dbman->table_exists($table)) {
                continue;
            }
            $select = $DB->sql_like('description', ':marker', false) .
                ' AND ' . $DB->sql_like('description', ':providername', false);
            $records = $DB->get_records_select($tablename, $select, [
                'marker' => '%' . $marker . '%',
                'providername' => '%Odoo%',
            ], '', 'id,description');
            foreach ($records as $record) {
                $description = str_replace('Odoo', 'ERP', (string)$record->description);
                if ($description !== (string)$record->description) {
                    $DB->set_field($tablename, 'description', $description, ['id' => (int)$record->id]);
                }
            }
        }

        upgrade_plugin_savepoint(true, 2026090814, 'local', 'kbacorporate');
    }


    if ($oldversion < 2026090815) {
        // v0.33.4: stable economic indicator registry, periodic values and immutable history.
        $indicatortable = new xmldb_table('local_kbacorp_econ_ind');
        if (!$dbman->table_exists($indicatortable)) {
            $indicatortable->add_field('id', XMLDB_TYPE_INTEGER, '10', null,
                XMLDB_NOTNULL, XMLDB_SEQUENCE, null);
            $indicatortable->add_field('companyid', XMLDB_TYPE_INTEGER, '10', null,
                XMLDB_NOTNULL, null, '0');
            $indicatortable->add_field('code', XMLDB_TYPE_CHAR, '64', null,
                XMLDB_NOTNULL, null, null);
            $indicatortable->add_field('name', XMLDB_TYPE_CHAR, '255', null,
                XMLDB_NOTNULL, null, null);
            $indicatortable->add_field('description', XMLDB_TYPE_TEXT, null, null,
                null, null, null);
            $indicatortable->add_field('category', XMLDB_TYPE_CHAR, '30', null,
                XMLDB_NOTNULL, null, 'finance');
            $indicatortable->add_field('valuetype', XMLDB_TYPE_CHAR, '20', null,
                XMLDB_NOTNULL, null, 'money');
            $indicatortable->add_field('unit', XMLDB_TYPE_CHAR, '30', null,
                XMLDB_NOTNULL, null, 'currency');
            $indicatortable->add_field('periodtype', XMLDB_TYPE_CHAR, '20', null,
                XMLDB_NOTNULL, null, 'month');
            $indicatortable->add_field('defaultsource', XMLDB_TYPE_CHAR, '20', null,
                XMLDB_NOTNULL, null, 'manual');
            $indicatortable->add_field('direction', XMLDB_TYPE_CHAR, '20', null,
                XMLDB_NOTNULL, null, 'up');
            $indicatortable->add_field('sortorder', XMLDB_TYPE_INTEGER, '10', null,
                XMLDB_NOTNULL, null, '0');
            $indicatortable->add_field('active', XMLDB_TYPE_INTEGER, '1', null,
                XMLDB_NOTNULL, null, '1');
            $indicatortable->add_field('systemdefined', XMLDB_TYPE_INTEGER, '1', null,
                XMLDB_NOTNULL, null, '0');
            $indicatortable->add_field('createdby', XMLDB_TYPE_INTEGER, '10', null,
                XMLDB_NOTNULL, null, '0');
            $indicatortable->add_field('modifiedby', XMLDB_TYPE_INTEGER, '10', null,
                XMLDB_NOTNULL, null, '0');
            $indicatortable->add_field('timecreated', XMLDB_TYPE_INTEGER, '10', null,
                XMLDB_NOTNULL, null, '0');
            $indicatortable->add_field('timemodified', XMLDB_TYPE_INTEGER, '10', null,
                XMLDB_NOTNULL, null, '0');
            $indicatortable->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);
            $indicatortable->add_index('company_code_uix', XMLDB_INDEX_UNIQUE,
                ['companyid', 'code']);
            $indicatortable->add_index('category_active_ix', XMLDB_INDEX_NOTUNIQUE,
                ['category', 'active', 'sortorder']);
            $indicatortable->add_index('company_active_ix', XMLDB_INDEX_NOTUNIQUE,
                ['companyid', 'active']);
            $dbman->create_table($indicatortable);
        }

        $valuetable = new xmldb_table('local_kbacorp_econ_val');
        if (!$dbman->table_exists($valuetable)) {
            $valuetable->add_field('id', XMLDB_TYPE_INTEGER, '10', null,
                XMLDB_NOTNULL, XMLDB_SEQUENCE, null);
            $valuetable->add_field('companyid', XMLDB_TYPE_INTEGER, '10', null,
                XMLDB_NOTNULL, null, null);
            $valuetable->add_field('indicatorid', XMLDB_TYPE_INTEGER, '10', null,
                XMLDB_NOTNULL, null, null);
            $valuetable->add_field('auditid', XMLDB_TYPE_INTEGER, '10', null,
                XMLDB_NOTNULL, null, '0');
            $valuetable->add_field('periodtype', XMLDB_TYPE_CHAR, '20', null,
                XMLDB_NOTNULL, null, 'month');
            $valuetable->add_field('periodstart', XMLDB_TYPE_INTEGER, '10', null,
                XMLDB_NOTNULL, null, '0');
            $valuetable->add_field('periodend', XMLDB_TYPE_INTEGER, '10', null,
                XMLDB_NOTNULL, null, '0');
            $valuetable->add_field('periodkey', XMLDB_TYPE_CHAR, '50', null,
                XMLDB_NOTNULL, null, null);
            $valuetable->add_field('scenario', XMLDB_TYPE_CHAR, '20', null,
                XMLDB_NOTNULL, null, 'actual');
            $valuetable->add_field('value', XMLDB_TYPE_NUMBER, '20, 6', null,
                XMLDB_NOTNULL, null, null);
            $valuetable->add_field('currency', XMLDB_TYPE_CHAR, '3', null,
                XMLDB_NOTNULL, null, 'RUB');
            $valuetable->add_field('source', XMLDB_TYPE_CHAR, '20', null,
                XMLDB_NOTNULL, null, 'manual');
            $valuetable->add_field('comment', XMLDB_TYPE_TEXT, null, null,
                null, null, null);
            $valuetable->add_field('verified', XMLDB_TYPE_INTEGER, '1', null,
                XMLDB_NOTNULL, null, '0');
            $valuetable->add_field('verifiedby', XMLDB_TYPE_INTEGER, '10', null,
                XMLDB_NOTNULL, null, '0');
            $valuetable->add_field('verifiedat', XMLDB_TYPE_INTEGER, '10', null,
                XMLDB_NOTNULL, null, '0');
            $valuetable->add_field('createdby', XMLDB_TYPE_INTEGER, '10', null,
                XMLDB_NOTNULL, null, '0');
            $valuetable->add_field('modifiedby', XMLDB_TYPE_INTEGER, '10', null,
                XMLDB_NOTNULL, null, '0');
            $valuetable->add_field('timecreated', XMLDB_TYPE_INTEGER, '10', null,
                XMLDB_NOTNULL, null, '0');
            $valuetable->add_field('timemodified', XMLDB_TYPE_INTEGER, '10', null,
                XMLDB_NOTNULL, null, '0');
            $valuetable->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);
            $valuetable->add_key('company_fk', XMLDB_KEY_FOREIGN, ['companyid'],
                'local_kbacorp_company', ['id']);
            $valuetable->add_key('indicator_fk', XMLDB_KEY_FOREIGN, ['indicatorid'],
                'local_kbacorp_econ_ind', ['id']);
            $valuetable->add_index('company_ind_period_scen_uix', XMLDB_INDEX_UNIQUE,
                ['companyid', 'indicatorid', 'periodkey', 'scenario']);
            $valuetable->add_index('company_periodend_ix', XMLDB_INDEX_NOTUNIQUE,
                ['companyid', 'periodend']);
            $valuetable->add_index('audit_ix', XMLDB_INDEX_NOTUNIQUE, ['auditid']);
            $valuetable->add_index('source_ix', XMLDB_INDEX_NOTUNIQUE, ['source']);
            $dbman->create_table($valuetable);
        }

        $logtable = new xmldb_table('local_kbacorp_econ_log');
        if (!$dbman->table_exists($logtable)) {
            $logtable->add_field('id', XMLDB_TYPE_INTEGER, '10', null,
                XMLDB_NOTNULL, XMLDB_SEQUENCE, null);
            $logtable->add_field('companyid', XMLDB_TYPE_INTEGER, '10', null,
                XMLDB_NOTNULL, null, '0');
            $logtable->add_field('entitytype', XMLDB_TYPE_CHAR, '20', null,
                XMLDB_NOTNULL, null, null);
            $logtable->add_field('entityid', XMLDB_TYPE_INTEGER, '10', null,
                XMLDB_NOTNULL, null, null);
            $logtable->add_field('action', XMLDB_TYPE_CHAR, '20', null,
                XMLDB_NOTNULL, null, null);
            $logtable->add_field('actorid', XMLDB_TYPE_INTEGER, '10', null,
                XMLDB_NOTNULL, null, '0');
            $logtable->add_field('oldjson', XMLDB_TYPE_TEXT, null, null,
                null, null, null);
            $logtable->add_field('newjson', XMLDB_TYPE_TEXT, null, null,
                null, null, null);
            $logtable->add_field('source', XMLDB_TYPE_CHAR, '20', null,
                XMLDB_NOTNULL, null, 'manual');
            $logtable->add_field('timecreated', XMLDB_TYPE_INTEGER, '10', null,
                XMLDB_NOTNULL, null, '0');
            $logtable->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);
            $logtable->add_index('entity_time_ix', XMLDB_INDEX_NOTUNIQUE,
                ['entitytype', 'entityid', 'timecreated']);
            $logtable->add_index('company_time_ix', XMLDB_INDEX_NOTUNIQUE,
                ['companyid', 'timecreated']);
            $logtable->add_index('actor_time_ix', XMLDB_INDEX_NOTUNIQUE,
                ['actorid', 'timecreated']);
            $dbman->create_table($logtable);
        }

        // Seed the stable global catalogue and migrate legacy audit snapshots.
        \local_kbacorporate\local\economic_indicators::seed_defaults(0);
        \local_kbacorporate\local\economic_indicators::migrate_legacy_snapshots(0);

        upgrade_plugin_savepoint(true, 2026090815, 'local', 'kbacorporate');
    }

    if ($oldversion < 2026090901) {
        // v0.34.0: read-only before/after comparison for confirmed BMS audits.
        // No database schema changes are required.
        upgrade_plugin_savepoint(true, 2026090901, 'local', 'kbacorporate');
    }

    if ($oldversion < 2026090902) {
        // v0.34.0.1: declare customer-scoped BMS implementation access.
        // Do not assign the newly declared capability here: Moodle registers
        // db/access.php definitions only after this plugin upgrade callback returns.
        // The actual grant is performed by the next idempotent upgrade step after
        // explicitly refreshing the capability definitions.
        upgrade_plugin_savepoint(true, 2026090902, 'local', 'kbacorporate');
    }

    if ($oldversion < 2026090903) {
        // v0.34.0.2: register capability definitions before assigning them to the
        // generated corporate-manager role. This also safely resumes sites where
        // the v0.34.0.1 upgrade stopped before reaching its savepoint.
        update_capabilities('local_kbacorporate');

        $roleid = (int)get_config('local_kbacorporate', 'corporatemanagerroleid');
        $role = $roleid ? $DB->get_record('role', ['id' => $roleid]) : false;
        if (!$role) {
            $role = $DB->get_record('role', ['shortname' => 'kbacorporatemanager']);
            $roleid = $role ? (int)$role->id : 0;
        }
        if ($roleid > 0) {
            $systemcontext = context_system::instance();
            foreach ([
                'local/kbacorporate:managecompanyimplementation',
                'local/kbacorporate:vieweconomicindicators',
                'local/kbacorporate:manageeconomicindicators',
            ] as $capability) {
                if ($DB->record_exists('capabilities', ['name' => $capability])) {
                    assign_capability($capability, CAP_ALLOW, $roleid, $systemcontext->id, true);
                }
            }
            set_config('corporatemanagerroleid', $roleid, 'local_kbacorporate');
        }
        upgrade_plugin_savepoint(true, 2026090903, 'local', 'kbacorporate');
    }

    if ($oldversion < 2026090904) {
        // v0.34.1: read-only economic indicator dashboard and charts.
        // No database schema changes are required.
        upgrade_plugin_savepoint(true, 2026090904, 'local', 'kbacorporate');
    }

    if ($oldversion < 2026090905) {
        // v0.34.2: read-only learning / implementation / economic linkage analytics.
        // No schema changes and no automatic causality decisions are introduced.
        upgrade_plugin_savepoint(true, 2026090905, 'local', 'kbacorporate');
    }

    if ($oldversion < 2026091001) {
        // v0.34.3: read-only role-oriented management dashboards.
        // No schema changes and no data migrations are required.
        upgrade_plugin_savepoint(true, 2026091001, 'local', 'kbacorporate');
    }

    if ($oldversion < 2026091002) {
        // v0.34.3.1: responsive curator portfolio table UI hotfix.
        // No schema changes and no data migrations are required.
        upgrade_plugin_savepoint(true, 2026091002, 'local', 'kbacorporate');
    }

    if ($oldversion < 2026091003) {
        // v0.34.4: clarify implementation routes and keep learning support separate.
        $routetable = new xmldb_table('local_kbacorp_bms_route');
        if ($dbman->table_exists($routetable)) {
            $expectedresult = new xmldb_field('expectedresult', XMLDB_TYPE_TEXT, null, null,
                null, null, null, 'courseid');
            if (!$dbman->field_exists($routetable, $expectedresult)) {
                $dbman->add_field($routetable, $expectedresult);
            }
            $evidencecriteria = new xmldb_field('evidencecriteria', XMLDB_TYPE_TEXT, null, null,
                null, null, null, 'expectedresult');
            if (!$dbman->field_exists($routetable, $evidencecriteria)) {
                $dbman->add_field($routetable, $evidencecriteria);
            }
        }
        // Existing course-only routes are preserved for compatibility. They are
        // shown as incomplete and must receive an implementation tool before reuse.
        upgrade_plugin_savepoint(true, 2026091003, 'local', 'kbacorporate');
    }

    if ($oldversion < 2026091004) {
        // v0.34.4.1: searchable company switcher on the corporate dashboard.
        // No schema changes and no data migrations are required.
        upgrade_plugin_savepoint(true, 2026091004, 'local', 'kbacorporate');
    }

    if ($oldversion < 2026091005) {
        // v0.35.0: freeze the stable ERP API v1 contract and add a read-only
        // readiness endpoint. Moodle refreshes db/services.php definitions.
        // No schema changes and no data migrations are required.
        upgrade_plugin_savepoint(true, 2026091005, 'local', 'kbacorporate');
    }

    if ($oldversion < 2026091006) {
        // v0.35.1: additive ERP employee account and membership lifecycle.
        // Existing mappings, provider tokens, Moodle users and history are preserved.
        // No schema changes and no data migrations are required.
        upgrade_plugin_savepoint(true, 2026091006, 'local', 'kbacorporate');
    }

    if ($oldversion < 2026091007) {
        // v0.35.2: additive ERP organisation structure and business-role lifecycle.
        // Existing departments, assignments, role profiles and mappings are preserved.
        // No schema changes and no data migrations are required.
        upgrade_plugin_savepoint(true, 2026091007, 'local', 'kbacorporate');
    }

    if ($oldversion < 2026091008) {
        // v0.35.3: additive competency, learning and economic ERP exchange.
        // No competency grade, learning plan, completion or BMS maturity is migrated.
        // No schema changes and no data migrations are required.
        upgrade_plugin_savepoint(true, 2026091008, 'local', 'kbacorporate');
    }

    if ($oldversion < 2026091009) {
        // v0.40.0: add a privacy-safe operational ERP request audit trail.
        // This is an additive table only. Business, learning, competency,
        // audit, evidence and economic records are not changed or deleted.
        $table = new xmldb_table('local_kbacorp_erp_log');
        if (!$dbman->table_exists($table)) {
            $table->add_field('id', XMLDB_TYPE_INTEGER, '10', null,
                XMLDB_NOTNULL, XMLDB_SEQUENCE, null);
            $table->add_field('companyid', XMLDB_TYPE_INTEGER, '10', null,
                XMLDB_NOTNULL, null, '0');
            $table->add_field('system', XMLDB_TYPE_CHAR, '30', null,
                XMLDB_NOTNULL, null, null);
            $table->add_field('entitytype', XMLDB_TYPE_CHAR, '30', null,
                XMLDB_NOTNULL, null, null);
            $table->add_field('externalidhash', XMLDB_TYPE_CHAR, '64', null,
                XMLDB_NOTNULL, null, null);
            $table->add_field('idempotencyhash', XMLDB_TYPE_CHAR, '64', null,
                null, null, null);
            $table->add_field('status', XMLDB_TYPE_CHAR, '20', null,
                XMLDB_NOTNULL, null, null);
            $table->add_field('durationms', XMLDB_TYPE_INTEGER, '10', null,
                XMLDB_NOTNULL, null, '0');
            $table->add_field('errorcode', XMLDB_TYPE_CHAR, '80', null,
                null, null, null);
            $table->add_field('timecreated', XMLDB_TYPE_INTEGER, '10', null,
                XMLDB_NOTNULL, null, '0');
            $table->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);
            $table->add_index('system_entity_time_ix', XMLDB_INDEX_NOTUNIQUE,
                ['system', 'entitytype', 'timecreated']);
            $table->add_index('status_time_ix', XMLDB_INDEX_NOTUNIQUE,
                ['status', 'timecreated']);
            $table->add_index('company_time_ix', XMLDB_INDEX_NOTUNIQUE,
                ['companyid', 'timecreated']);
            $table->add_index('idempotency_ix', XMLDB_INDEX_NOTUNIQUE,
                ['system', 'idempotencyhash']);
            $dbman->create_table($table);
        }

        upgrade_plugin_savepoint(true, 2026091009, 'local', 'kbacorporate');
    }


    if ($oldversion < 2026092111) {
        // v0.40.2: SuiteCRM-style soft deletion for implementation tasks.
        // Deleted records remain available to privacy/audit tooling but disappear
        // from operational task, board, reminder, analytics and file surfaces.
        $table = new xmldb_table('local_kbacorp_impl_task');
        if ($dbman->table_exists($table)) {
            $deleted = new xmldb_field('deleted', XMLDB_TYPE_INTEGER, '1', null,
                XMLDB_NOTNULL, null, '0', 'status');
            if (!$dbman->field_exists($table, $deleted)) {
                $dbman->add_field($table, $deleted);
            }
            $deletedat = new xmldb_field('deletedat', XMLDB_TYPE_INTEGER, '10', null,
                XMLDB_NOTNULL, null, '0', 'deleted');
            if (!$dbman->field_exists($table, $deletedat)) {
                $dbman->add_field($table, $deletedat);
            }
            $deletionkey = new xmldb_field('deletionkey', XMLDB_TYPE_INTEGER, '10', null,
                XMLDB_NOTNULL, null, '0', 'deletedat');
            if (!$dbman->field_exists($table, $deletionkey)) {
                $dbman->add_field($table, $deletionkey);
            }

            $oldindex = new xmldb_index('item_route_uix', XMLDB_INDEX_UNIQUE, ['itemid', 'routeid']);
            if ($dbman->index_exists($table, $oldindex)) {
                $dbman->drop_index($table, $oldindex);
            }
            $newindex = new xmldb_index('item_route_uix', XMLDB_INDEX_UNIQUE,
                ['itemid', 'routeid', 'deletionkey']);
            if (!$dbman->index_exists($table, $newindex)) {
                $dbman->add_index($table, $newindex);
            }
            $deletedindex = new xmldb_index('company_deleted_ix', XMLDB_INDEX_NOTUNIQUE,
                ['companyid', 'deleted']);
            if (!$dbman->index_exists($table, $deletedindex)) {
                $dbman->add_index($table, $deletedindex);
            }
        }

        upgrade_plugin_savepoint(true, 2026092111, 'local', 'kbacorporate');
    }

    // v0.40.3: read-only integration API; no schema changes.
    if ($oldversion < 2026102512) {
        upgrade_plugin_savepoint(true, 2026102512, 'local', 'kbacorporate');
    }

    // Corporate 0.40.4: read-only BMS24 navigation link, no XMLDB change.
    if ($oldversion < 2026102513) {
        upgrade_plugin_savepoint(true, 2026102513, 'local', 'kbacorporate');
    }

    // Corporate 0.40.5: read-only program bridge projection; no XMLDB change.
    if ($oldversion < 2026102514) {
        upgrade_plugin_savepoint(true, 2026102514, 'local', 'kbacorporate');
    }

    // 0.40.6 — section read API 1.24; no database schema changes.
    if ($oldversion < 2026102515) {
        upgrade_plugin_savepoint(true, 2026102515, 'local', 'kbacorporate');
    }

    // 0.40.7: read-only task/section projection API 1.25; no schema changes.
    if ($oldversion < 2026102516) {
        upgrade_plugin_savepoint(true, 2026102516, 'local', 'kbacorporate');
    }

    // 0.40.8: read-only evidence decision API 1.26, no XMLDB change.
    if ($oldversion < 2026102517) {
        upgrade_plugin_savepoint(true, 2026102517, 'local', 'kbacorporate');
    }

    // 0.40.9: register a new least-privilege curator capability via db/access.php.
    // Do not create/assign a role here: Moodle registers plugin capabilities after upgrade().
    if ($oldversion < 2026102518) {
        upgrade_plugin_savepoint(true, 2026102518, 'local', 'kbacorporate');
    }

    if ($oldversion < 2026102610) {
        upgrade_plugin_savepoint(true, 2026102610, 'local', 'kbacorporate');
    }

    // Isolated ERP compatibility patch; no schema, roles or business-data migration.
    if ($oldversion < 2026110901) {
        upgrade_plugin_savepoint(true, 2026110901, 'local', 'kbacorporate');
    }

    return true;
}
