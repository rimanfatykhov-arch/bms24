<?php
namespace local_kbacorporate\local;

defined('MOODLE_INTERNAL') || die();

class repository {
    public static function save_company(\stdClass $data): int {
        global $DB;
        $now = time();
        $record = (object)[
            'name' => trim($data->name),
            'code' => trim((string)($data->code ?? '')) ?: null,
            'bitrixcompanyid' => trim((string)($data->bitrixcompanyid ?? '')) ?: null,
            'primaryactivity' => trim((string)($data->primaryactivity ?? '')) ?: null,
            'foundedyear' => max(0, (int)($data->foundedyear ?? 0)),
            'active' => empty($data->active) ? 0 : 1,
            'trainingminutesday' => max(0, (int)round(((float)($data->traininghoursday ?? 1.5)) * 60)),
            'trainingweekdays' => self::training_weekdays_from_form($data),
            'alertsenabled' => empty($data->alertsenabled) ? 0 : 1,
            'alertfrequency' => attention_notifier::normalise_frequency((string)($data->alertfrequency ?? 'weekly')),
            'timemodified' => $now,
        ];
        if (!empty($data->id)) {
            $record->id = (int)$data->id;
            $DB->update_record('local_kbacorp_company', $record);
            return $record->id;
        }
        $record->timecreated = $now;
        $record->lastalertsent = 0;
        return $DB->insert_record('local_kbacorp_company', $record);
    }


    /**
     * Find a company by Bitrix24 Company ID.
     *
     * The database index is intentionally non-unique for backwards-compatible
     * upgrades, therefore the oldest matching record is returned if historical
     * duplicates already exist.
     *
     * @param int $bitrixcompanyid
     * @return \stdClass|false
     */
    public static function get_company_by_bitrix_id(int $bitrixcompanyid) {
        global $DB;

        if ($bitrixcompanyid <= 0) {
            return false;
        }
        $records = $DB->get_records(
            'local_kbacorp_company',
            ['bitrixcompanyid' => (string)$bitrixcompanyid],
            'id ASC',
            '*',
            0,
            1
        );
        if (!$records) {
            return false;
        }
        return reset($records);
    }

    /**
     * Create or update a company identified by Bitrix24 Company ID.
     *
     * This API does not use save_company() because that method is form-oriented
     * and expects weekday checkbox fields. New integration-created companies use
     * the same defaults as install.xml: active, 90 min/day, Monday-Friday.
     *
     * @param int $bitrixcompanyid
     * @param string $companytitle
     * @return array company, created, changed
     */
    public static function ensure_company_by_bitrix_id(int $bitrixcompanyid, string $companytitle): array {
        global $DB;

        $companytitle = trim($companytitle);
        if ($bitrixcompanyid <= 0) {
            throw new \invalid_parameter_exception('Bitrix24 company ID must be greater than zero.');
        }
        if ($companytitle === '') {
            throw new \invalid_parameter_exception('Company title must not be empty.');
        }

        $company = self::get_company_by_bitrix_id($bitrixcompanyid);
        if ($company) {
            $changed = false;
            if ((string)$company->name !== $companytitle) {
                $company->name = $companytitle;
                $company->timemodified = time();
                $DB->update_record('local_kbacorp_company', (object)[
                    'id' => (int)$company->id,
                    'name' => $companytitle,
                    'timemodified' => (int)$company->timemodified,
                ]);
                $changed = true;
            }
            return [
                'company' => $company,
                'created' => false,
                'changed' => $changed,
            ];
        }

        $now = time();
        $record = (object)[
            'name' => $companytitle,
            'code' => null,
            'bitrixcompanyid' => (string)$bitrixcompanyid,
            'primaryactivity' => null,
            'foundedyear' => 0,
            'active' => 1,
            'trainingminutesday' => 90,
            'trainingweekdays' => '1,2,3,4,5',
            'alertsenabled' => 0,
            'alertfrequency' => 'weekly',
            'lastalertsent' => 0,
            'timecreated' => $now,
            'timemodified' => $now,
        ];
        $record->id = $DB->insert_record('local_kbacorp_company', $record);

        return [
            'company' => $record,
            'created' => true,
            'changed' => true,
        ];
    }

    /**
     * Return one existing member relation, or false when none exists.
     *
     * @param int $companyid
     * @param int $userid
     * @param string $membertype
     * @return \stdClass|false
     */
    public static function get_member(int $companyid, int $userid, string $membertype = 'employee') {
        global $DB;
        $member = $DB->get_record('local_kbacorp_member', [
            'companyid' => $companyid,
            'userid' => $userid,
        ]);
        if (!$member) {
            return false;
        }
        $membertype = $membertype === 'manager' ? 'manager' : 'employee';
        if ($membertype === 'manager' && empty($member->ismanager)) {
            return false;
        }
        if ($membertype === 'employee' && empty($member->isemployee)) {
            return false;
        }
        return $member;
    }

    /**
     * Convert weekday checkboxes (1=Monday ... 7=Sunday) to DB string.
     *
     * @param \stdClass $data
     * @return string
     */
    /**
     * Return machine rolecodes stored on a unified company membership.
     *
     * department_manager here is a company-level business role only. Scoped
     * access to a concrete department remains controlled by local_kbacorp_dept_member.
     *
     * @param \stdClass $member
     * @return array
     */
    public static function get_member_roles(\stdClass $member): array {
        $roles = [];
        if (!empty($member->isemployee)) {
            $roles[] = 'employee';
        }
        if (!empty($member->ismanager)) {
            $roles[] = 'corporate_manager';
        }
        if (!empty($member->isdepartmentmanager)) {
            $roles[] = 'department_manager';
        }
        if (!empty($member->ishr)) {
            $roles[] = 'hr';
        }
        return $roles;
    }

    /**
     * Merge external business roles into one existing company membership.
     *
     * This method is intentionally additive. It never removes an existing role.
     *
     * @param int $companyid
     * @param int $userid
     * @param array $roles Normalised rolecodes.
     * @return array
     */
    public static function merge_member_roles(int $companyid, int $userid, array $roles): array {
        global $DB;

        $member = $DB->get_record('local_kbacorp_member', [
            'companyid' => $companyid,
            'userid' => $userid,
        ]);
        if (!$member) {
            return [
                'success' => false,
                'status' => 'membership_not_found',
                'companyid' => $companyid,
                'userid' => $userid,
            ];
        }

        $existingroles = self::get_member_roles($member);
        $changed = false;

        foreach ($roles as $role) {
            switch ($role) {
                case 'employee':
                    if (empty($member->isemployee)) {
                        $member->isemployee = 1;
                        $member->membertype = 'employee';
                        $changed = true;
                    }
                    break;
                case 'corporate_manager':
                    if (empty($member->ismanager)) {
                        $member->ismanager = 1;
                        $changed = true;
                    }
                    break;
                case 'department_manager':
                    if (empty($member->isdepartmentmanager)) {
                        $member->isdepartmentmanager = 1;
                        $changed = true;
                    }
                    break;
                case 'hr':
                    if (empty($member->ishr)) {
                        $member->ishr = 1;
                        $changed = true;
                    }
                    break;
            }
        }

        if ($changed) {
            $member->timemodified = time();
            $DB->update_record('local_kbacorp_member', $member);
        }

        if (!empty($member->ismanager)) {
            self::assign_manager_role($member);
        }

        return [
            'success' => true,
            'status' => $changed ? 'merged' : 'unchanged',
            'companyid' => $companyid,
            'userid' => $userid,
            'existing_roles' => $existingroles,
            'roles' => self::get_member_roles($member),
        ];
    }

    private static function training_weekdays_from_form(\stdClass $data): string {
        $days = [];
        for ($day = 1; $day <= 7; $day++) {
            $field = 'trainingday' . $day;
            if (!empty($data->{$field})) {
                $days[] = $day;
            }
        }
        return implode(',', $days);
    }

    public static function delete_company(int $companyid): void {
        global $DB;
        $transaction = $DB->start_delegated_transaction();
        $dbman = $DB->get_manager();
        $managerrows = $DB->get_records('local_kbacorp_member', ['companyid' => $companyid, 'ismanager' => 1]);
        foreach ($managerrows as $member) {
            self::unassign_manager_role($member);
        }
        $departments = $DB->get_records('local_kbacorp_department', ['companyid' => $companyid]);
        foreach ($departments as $department) {
            self::delete_department((int)$department->id, $companyid);
        }
        if (licenses::is_available()) {
            licenses::delete_company_data($companyid);
        }
        self::delete_competency_audits($companyid);
        self::delete_development_plans($companyid);
        if ($dbman->table_exists(new \xmldb_table('local_kbacorp_impl_audit'))) {
            $auditids = $DB->get_fieldset_select('local_kbacorp_impl_audit', 'id',
                'companyid = :companyid', ['companyid' => $companyid]);
            if ($dbman->table_exists(new \xmldb_table('local_kbacorp_impl_program'))) {
                $DB->delete_records('local_kbacorp_impl_program', ['companyid' => $companyid]);
            }
            if ($dbman->table_exists(new \xmldb_table('local_kbacorp_impl_task'))) {
                $taskids = $DB->get_fieldset_select('local_kbacorp_impl_task', 'id',
                    'companyid = :companyid', ['companyid' => $companyid]);
                foreach ($taskids as $taskid) {
                    get_file_storage()->delete_area_files(\context_system::instance()->id,
                        'local_kbacorporate', 'implementation_task_evidence', (int)$taskid);
                    if ($dbman->table_exists(new \xmldb_table('local_kbacorp_impl_evid'))) {
                        $evidenceids = $DB->get_fieldset_select('local_kbacorp_impl_evid', 'id',
                            'taskid = :taskid', ['taskid' => (int)$taskid]);
                        foreach ($evidenceids as $evidenceid) {
                            get_file_storage()->delete_area_files(\context_system::instance()->id,
                                'local_kbacorporate', 'implementation_evidence_record', (int)$evidenceid);
                        }
                    }
                }
                if ($taskids) {
                    list($taskinsql, $taskparams) = $DB->get_in_or_equal(
                        array_map('intval', $taskids), SQL_PARAMS_NAMED, 'impltask');
                    foreach (['local_kbacorp_impl_evlog', 'local_kbacorp_impl_evid',
                            'local_kbacorp_impl_check', 'local_kbacorp_impl_comment',
                            'local_kbacorp_impl_read', 'local_kbacorp_impl_tasklog'] as $childtable) {
                        if ($dbman->table_exists(new \xmldb_table($childtable))) {
                            $DB->delete_records_select($childtable, "taskid {$taskinsql}", $taskparams);
                        }
                    }
                }
                $DB->delete_records('local_kbacorp_impl_task', ['companyid' => $companyid]);
            }
            if ($dbman->table_exists(new \xmldb_table('local_kbacorp_impl_econ'))) {
                $snapshotids = $DB->get_fieldset_select('local_kbacorp_impl_econ', 'id',
                    'companyid = :companyid', ['companyid' => $companyid]);
                foreach ($snapshotids as $snapshotid) {
                    get_file_storage()->delete_area_files(\context_system::instance()->id,
                        'local_kbacorporate', 'implementation_economic_evidence', (int)$snapshotid);
                }
                $DB->delete_records('local_kbacorp_impl_econ', ['companyid' => $companyid]);
            }
            if ($auditids && $dbman->table_exists(new \xmldb_table('local_kbacorp_impl_item'))) {
                list($insql, $params) = $DB->get_in_or_equal(array_map('intval', $auditids), SQL_PARAMS_NAMED, 'implaudit');
                $itemids = $DB->get_fieldset_select('local_kbacorp_impl_item', 'id', "auditid {$insql}", $params);
                $fs = get_file_storage();
                foreach ($itemids as $itemid) {
                    $fs->delete_area_files(\context_system::instance()->id, 'local_kbacorporate',
                        'implementation_evidence', (int)$itemid);
                }
                $DB->delete_records_select('local_kbacorp_impl_item', "auditid {$insql}", $params);
            }
            $DB->delete_records('local_kbacorp_impl_audit', ['companyid' => $companyid]);
        }
        if ($dbman->table_exists(new \xmldb_table('local_kbacorp_bms_tool'))) {
            if ($dbman->table_exists(new \xmldb_table('local_kbacorp_bms_route'))) {
                $DB->delete_records('local_kbacorp_bms_route', ['companyid' => $companyid]);
            }
            $toolids = $DB->get_fieldset_select('local_kbacorp_bms_tool', 'id',
                'companyid = :companyid', ['companyid' => $companyid]);
            if ($toolids && $dbman->table_exists(new \xmldb_table('local_kbacorp_bms_toolver'))) {
                list($insql, $params) = $DB->get_in_or_equal(array_map('intval', $toolids), SQL_PARAMS_NAMED,
                    'bmsversiontool');
                $DB->delete_records_select('local_kbacorp_bms_toolver', "toolid {$insql}", $params);
            }
            if ($toolids && $dbman->table_exists(new \xmldb_table('local_kbacorp_bms_crit'))) {
                list($insql, $params) = $DB->get_in_or_equal(array_map('intval', $toolids), SQL_PARAMS_NAMED, 'bmstool');
                if ($dbman->table_exists(new \xmldb_table('local_kbacorp_bms_comp'))) {
                    $criterionids = $DB->get_fieldset_select('local_kbacorp_bms_crit', 'id',
                        "toolid {$insql}", $params);
                    if ($criterionids) {
                        list($criterioninsql, $criterionparams) = $DB->get_in_or_equal(
                            array_map('intval', $criterionids), SQL_PARAMS_NAMED, 'bmscriterion');
                        $DB->delete_records_select('local_kbacorp_bms_comp',
                            "criterionid {$criterioninsql}", $criterionparams);
                    }
                }
                $DB->delete_records_select('local_kbacorp_bms_crit', "toolid {$insql}", $params);
            }
            $DB->delete_records('local_kbacorp_bms_tool', ['companyid' => $companyid]);
        }
        if ($dbman->table_exists(new \xmldb_table('local_kbacorp_member_busrole'))) {
            $DB->delete_records('local_kbacorp_member_busrole', ['companyid' => $companyid]);
        }
        if ($dbman->table_exists(new \xmldb_table('local_kbacorp_extmap'))) {
            $DB->delete_records_select(
                'local_kbacorp_extmap',
                'companyid = :companyid OR (localentity = :companyentity AND localid = :localid)',
                ['companyid' => $companyid, 'companyentity' => 'company', 'localid' => $companyid]
            );
        }
        if ($dbman->table_exists(new \xmldb_table('local_kbacorp_busrole'))) {
            $roleids = $DB->get_fieldset_select(
                'local_kbacorp_busrole',
                'id',
                'companyid = :companyid',
                ['companyid' => $companyid]
            );
            if ($roleids && $dbman->table_exists(new \xmldb_table('local_kbacorp_rolecomp'))) {
                list($insql, $params) = $DB->get_in_or_equal(
                    array_map('intval', $roleids),
                    SQL_PARAMS_NAMED,
                    'companyrole'
                );
                $DB->delete_records_select('local_kbacorp_rolecomp', "roleid {$insql}", $params);
            }
            $DB->delete_records('local_kbacorp_busrole', ['companyid' => $companyid]);
        }
        $DB->delete_records('local_kbacorp_course', ['companyid' => $companyid]);
        $DB->delete_records('local_kbacorp_member', ['companyid' => $companyid]);
        $DB->delete_records('local_kbacorp_company', ['id' => $companyid]);
        $transaction->allow_commit();
    }

    public static function add_member(int $companyid, int $userid, string $membertype): int {
        global $DB;
        $membertype = $membertype === 'manager' ? 'manager' : 'employee';
        $existing = $DB->get_record('local_kbacorp_member', [
            'companyid' => $companyid,
            'userid' => $userid,
        ]);
        $now = time();

        if ($existing) {
            $changed = false;
            if ($membertype === 'manager' && empty($existing->ismanager)) {
                $existing->ismanager = 1;
                $changed = true;
            }
            if ($membertype === 'employee' && empty($existing->isemployee)) {
                $existing->isemployee = 1;
                $existing->membertype = 'employee';
                $changed = true;
            }
            if ($changed) {
                $existing->timemodified = $now;
                $DB->update_record('local_kbacorp_member', $existing);
            }
            if ($membertype === 'manager') {
                self::assign_manager_role($existing);
            }
            return (int)$existing->id;
        }

        $record = (object)[
            'companyid' => $companyid,
            'userid' => $userid,
            'membertype' => $membertype,
            'isemployee' => $membertype === 'employee' ? 1 : 0,
            'ismanager' => $membertype === 'manager' ? 1 : 0,
            'timecreated' => $now,
            'timemodified' => $now,
        ];
        $id = $DB->insert_record('local_kbacorp_member', $record);
        $record->id = $id;
        if ($membertype === 'manager') {
            self::assign_manager_role($record);
        }
        return $id;
    }

    public static function delete_member(int $memberid, int $companyid): void {
        global $DB;
        $transaction = $DB->start_delegated_transaction();
        $member = $DB->get_record('local_kbacorp_member', ['id' => $memberid, 'companyid' => $companyid], '*', MUST_EXIST);
        if ($DB->get_manager()->table_exists(new \xmldb_table('local_kbacorp_impl_task'))) {
            $DB->set_field('local_kbacorp_impl_task', 'ownerid', 0,
                ['companyid' => $companyid, 'ownerid' => (int)$member->userid]);
        }
        if (!empty($member->ismanager)) {
            self::unassign_manager_role($member);
        }
        if (!empty($member->isemployee)) {
            // Remove employee department assignments when company membership is removed.
            $departmentids = $DB->get_fieldset_select('local_kbacorp_department', 'id', 'companyid = :companyid', ['companyid' => $companyid]);
            if ($departmentids) {
                list($insql, $params) = $DB->get_in_or_equal(array_map('intval', $departmentids), SQL_PARAMS_NAMED, 'deldep');
                $params['userid'] = (int)$member->userid;
                $params['deptrole'] = 'employee';
                $DB->delete_records_select('local_kbacorp_dept_member', "departmentid {$insql} AND userid = :userid AND deptrole = :deptrole", $params);
            }
        }
        if ($DB->get_manager()->table_exists(new \xmldb_table('local_kbacorp_member_busrole'))) {
            $assignmentids = $DB->get_fieldset_select(
                'local_kbacorp_member_busrole',
                'id',
                'companyid = :companyid AND userid = :userid',
                ['companyid' => $companyid, 'userid' => (int)$member->userid]
            );
            if ($assignmentids && $DB->get_manager()->table_exists(new \xmldb_table('local_kbacorp_extmap'))) {
                list($assignmentsql, $assignmentparams) = $DB->get_in_or_equal(
                    array_map('intval', $assignmentids), SQL_PARAMS_NAMED, 'extassignment'
                );
                $assignmentparams['localentity'] = 'business_role_assignment';
                $DB->delete_records_select('local_kbacorp_extmap',
                    "localentity = :localentity AND localid {$assignmentsql}", $assignmentparams);
            }
            $DB->delete_records('local_kbacorp_member_busrole', [
                'companyid' => $companyid,
                'userid' => (int)$member->userid,
            ]);
        }
        if ($DB->get_manager()->table_exists(new \xmldb_table('local_kbacorp_extmap'))) {
            $DB->delete_records('local_kbacorp_extmap', [
                'companyid' => $companyid,
                'localentity' => 'user',
                'localid' => (int)$member->userid,
            ]);
        }
        self::delete_competency_audits($companyid, (int)$member->userid);
        self::delete_development_plans($companyid, (int)$member->userid);
        $DB->delete_records('local_kbacorp_member', ['id' => $memberid]);
        $transaction->allow_commit();
    }

    /**
     * Remove one role from a unified company membership.
     *
     * The physical membership row is deleted only when no employee/manager
     * roles remain. This is used by integration APIs so removing an employee
     * relation cannot accidentally revoke an independent manager relation.
     *
     * @param int $memberid
     * @param int $companyid
     * @param string $membertype employee|manager
     * @return bool Whether anything changed.
     */
    public static function remove_member_role(int $memberid, int $companyid, string $membertype): bool {
        global $DB;

        $member = $DB->get_record('local_kbacorp_member', [
            'id' => $memberid,
            'companyid' => $companyid,
        ], '*', MUST_EXIST);
        $membertype = $membertype === 'manager' ? 'manager' : 'employee';
        $changed = false;

        if ($membertype === 'manager') {
            if (empty($member->ismanager)) {
                return false;
            }
            self::unassign_manager_role($member);
            $member->ismanager = 0;
            $changed = true;
        } else {
            if (empty($member->isemployee)) {
                return false;
            }
            $member->isemployee = 0;
            $changed = true;

            $departmentids = $DB->get_fieldset_select(
                'local_kbacorp_department', 'id', 'companyid = :companyid', ['companyid' => $companyid]
            );
            if ($departmentids) {
                list($insql, $params) = $DB->get_in_or_equal(
                    array_map('intval', $departmentids), SQL_PARAMS_NAMED, 'delroledep'
                );
                $params['userid'] = (int)$member->userid;
                $params['deptrole'] = 'employee';
                $DB->delete_records_select(
                    'local_kbacorp_dept_member',
                    "departmentid {$insql} AND userid = :userid AND deptrole = :deptrole",
                    $params
                );
            }
        }

        if (empty($member->isemployee) && empty($member->ismanager) &&
                empty($member->isdepartmentmanager) && empty($member->ishr)) {
            if ($DB->get_manager()->table_exists(new \xmldb_table('local_kbacorp_member_busrole'))) {
                $DB->delete_records('local_kbacorp_member_busrole', [
                    'companyid' => $companyid,
                    'userid' => (int)$member->userid,
                ]);
            }
            self::delete_competency_audits($companyid, (int)$member->userid);
            self::delete_development_plans($companyid, (int)$member->userid);
            $DB->delete_records('local_kbacorp_member', ['id' => (int)$member->id]);
            return $changed;
        }

        $member->membertype = !empty($member->isemployee) ? 'employee' : 'manager';
        $member->timemodified = time();
        $DB->update_record('local_kbacorp_member', $member);
        return $changed;
    }

    /** Delete audit children before their company/member parent is removed. */
    private static function delete_competency_audits(int $companyid, int $userid = 0): void {
        global $DB;
        $dbman = $DB->get_manager();
        if (!$dbman->table_exists(new \xmldb_table('local_kbacorp_audit'))) {
            return;
        }
        $conditions = ['companyid' => $companyid];
        if ($userid > 0) {
            $conditions['userid'] = $userid;
        }
        $auditids = $DB->get_fieldset_select(
            'local_kbacorp_audit',
            'id',
            $userid > 0 ? 'companyid = :companyid AND userid = :userid' : 'companyid = :companyid',
            $conditions
        );
        if ($auditids && $dbman->table_exists(new \xmldb_table('local_kbacorp_audit_item'))) {
            list($insql, $params) = $DB->get_in_or_equal(array_map('intval', $auditids), SQL_PARAMS_NAMED, 'audit');
            if ($dbman->table_exists(new \xmldb_table('local_kbacorp_audit_ev'))) {
                $itemids = $DB->get_fieldset_select('local_kbacorp_audit_item', 'id', "auditid {$insql}", $params);
                if ($itemids) {
                    list($itemsql, $itemparams) = $DB->get_in_or_equal(
                        array_map('intval', $itemids), SQL_PARAMS_NAMED, 'auditevidenceitem'
                    );
                    $DB->delete_records_select('local_kbacorp_audit_ev',
                        "audititemid {$itemsql}", $itemparams);
                }
            }
            $DB->delete_records_select('local_kbacorp_audit_item', "auditid {$insql}", $params);
        }
        $DB->delete_records_select(
            'local_kbacorp_audit',
            $userid > 0 ? 'companyid = :companyid AND userid = :userid' : 'companyid = :companyid',
            $conditions
        );
    }

    /** Delete development-plan items before their company/member parent. */
    private static function delete_development_plans(int $companyid, int $userid = 0): void {
        global $DB;
        $dbman = $DB->get_manager();
        if (!$dbman->table_exists(new \xmldb_table('local_kbacorp_devplan'))) {
            return;
        }
        $where = $userid > 0 ? 'companyid = :companyid AND userid = :userid' : 'companyid = :companyid';
        $params = ['companyid' => $companyid];
        if ($userid > 0) {
            $params['userid'] = $userid;
        }
        $planids = $DB->get_fieldset_select('local_kbacorp_devplan', 'id', $where, $params);
        if ($planids) {
            list($insql, $inparams) = $DB->get_in_or_equal(array_map('intval', $planids), SQL_PARAMS_NAMED, 'devplan');
            if ($dbman->table_exists(new \xmldb_table('local_kbacorp_devlog'))) {
                $DB->delete_records_select('local_kbacorp_devlog', "planid {$insql}", $inparams);
            }
            if ($dbman->table_exists(new \xmldb_table('local_kbacorp_devitem'))) {
                $DB->delete_records_select('local_kbacorp_devitem', "planid {$insql}", $inparams);
            }
        }
        $DB->delete_records_select('local_kbacorp_devplan', $where, $params);
    }

    private static function ensure_manager_role(): int {
        global $DB;

        $roleid = (int)get_config('local_kbacorporate', 'corporatemanagerroleid');
        $role = $roleid ? $DB->get_record('role', ['id' => $roleid]) : false;
        if (!$role) {
            $role = $DB->get_record('role', ['shortname' => 'kbacorporatemanager']);
        }

        $created = false;
        if (!$role) {
            $roleid = create_role(
                get_string('corporatemanagerrole', 'local_kbacorporate'),
                'kbacorporatemanager',
                get_string('corporatemanagerroledesc', 'local_kbacorporate')
            );
            set_role_contextlevels($roleid, [CONTEXT_SYSTEM]);
            $created = true;
        } else {
            $roleid = (int)$role->id;
        }

        $context = \context_system::instance();
        $caps = [
            'local/kbacorporate:viewdashboard',
            'local/kbacorporate:managecompanyimplementation',
            'local/kbacorporate:vieweconomicindicators',
            'local/kbacorporate:manageeconomicindicators',
            'local/kbacorporate:viewcompetencies',
            'local/kbacorporate:managedevelopmentplans',
            'local/kbacorporate:viewemployees',
            'local/kbacorporate:viewprogress',
            'local/kbacorporate:viewgrades',
            'local/kbacorporate:viewattendance',
            'local/kbacorporate:viewtrainingtime',
            'local/kbacorporate:viewcertificates',
            'local/kbacorporate:viewdepartments',
            'local/kbacorporate:viewalerts',
            'local/kbacorporate:viewlicenses',
            'local/kbacorporate:viewgamification',
            'local/kbacorporate:exportreport',
        ];
        foreach ($caps as $cap) {
            assign_capability($cap, CAP_ALLOW, $roleid, $context->id, true);
        }

        set_config('corporatemanagerroleid', $roleid, 'local_kbacorporate');
        if ($created) {
            set_config('corporatemanagerrolecreated', 1, 'local_kbacorporate');
        } else if (get_config('local_kbacorporate', 'corporatemanagerrolecreated') === false) {
            set_config('corporatemanagerrolecreated', 0, 'local_kbacorporate');
        }
        return $roleid;
    }

    private static function assign_manager_role(\stdClass $member): void {
        $roleid = self::ensure_manager_role();
        role_assign($roleid, $member->userid, \context_system::instance()->id, 'local_kbacorporate', $member->id);
    }

    private static function unassign_manager_role(\stdClass $member): void {
        global $DB;
        $roleid = (int)get_config('local_kbacorporate', 'corporatemanagerroleid');
        if (!$roleid) {
            $role = $DB->get_record('role', ['shortname' => 'kbacorporatemanager']);
            $roleid = $role ? (int)$role->id : 0;
        }
        if ($roleid) {
            role_unassign($roleid, $member->userid, \context_system::instance()->id, 'local_kbacorporate', $member->id);
        }
    }

    /**
     * Return an existing company/course/group mapping, or false when none exists.
     *
     * @param int $companyid
     * @param int $courseid
     * @param int $groupid
     * @return \stdClass|false
     */
    public static function get_course_mapping(int $companyid, int $courseid, int $groupid = 0) {
        global $DB;
        return $DB->get_record('local_kbacorp_course', [
            'companyid' => $companyid,
            'courseid' => $courseid,
            'groupid' => $groupid,
        ]);
    }

    public static function add_course(int $companyid, int $courseid, int $groupid = 0): int {
        global $DB;
        $existing = $DB->get_record('local_kbacorp_course', [
            'companyid' => $companyid,
            'courseid' => $courseid,
            'groupid' => $groupid,
        ]);
        if ($existing) {
            if (!$existing->active) {
                $existing->active = 1;
                $existing->timemodified = time();
                $DB->update_record('local_kbacorp_course', $existing);
            }
            return $existing->id;
        }
        $now = time();
        return $DB->insert_record('local_kbacorp_course', (object)[
            'companyid' => $companyid,
            'courseid' => $courseid,
            'groupid' => $groupid,
            'active' => 1,
            'timecreated' => $now,
            'timemodified' => $now,
        ]);
    }

    public static function delete_course(int $mappingid, int $companyid): void {
        global $DB;
        $DB->delete_records('local_kbacorp_course', ['id' => $mappingid, 'companyid' => $companyid]);
    }

    /**
     * Soft-disable an existing company/course/group mapping.
     *
     * @param int $companyid
     * @param int $courseid
     * @param int $groupid
     * @return array
     */
    public static function deactivate_course(int $companyid, int $courseid, int $groupid = 0): array {
        global $DB;

        $mapping = self::get_course_mapping($companyid, $courseid, $groupid);
        if (!$mapping) {
            return [
                'found' => false,
                'changed' => false,
                'mappingid' => 0,
            ];
        }
        if (empty($mapping->active)) {
            return [
                'found' => true,
                'changed' => false,
                'mappingid' => (int)$mapping->id,
            ];
        }

        $mapping->active = 0;
        $mapping->timemodified = time();
        $DB->update_record('local_kbacorp_course', $mapping);
        return [
            'found' => true,
            'changed' => true,
            'mappingid' => (int)$mapping->id,
        ];
    }

    /**
     * Return all local course mappings for a company, including inactive rows.
     *
     * @param int $companyid
     * @return array
     */
    public static function get_company_course_mappings(int $companyid): array {
        global $DB;
        if ($companyid <= 0) {
            return [];
        }
        return $DB->get_records(
            'local_kbacorp_course',
            ['companyid' => $companyid],
            'id ASC'
        );
    }

    /**
     * Return employee/manager counts without exposing internal member rows.
     *
     * @param int $companyid
     * @return array
     */
    public static function get_company_member_counts(int $companyid): array {
        global $DB;
        return [
            'employees' => (int)$DB->count_records('local_kbacorp_member', [
                'companyid' => $companyid,
                'isemployee' => 1,
            ]),
            'managers' => (int)$DB->count_records('local_kbacorp_member', [
                'companyid' => $companyid,
                'ismanager' => 1,
            ]),
        ];
    }

    /**
     * Return a bounded batch of companies for optional external integrations.
     *
     * @param int $afterid Local company ID cursor.
     * @param int $limit Maximum rows, clamped to 1..500.
     * @return array
     */
    public static function get_companies_batch(int $afterid = 0, int $limit = 100): array {
        global $DB;

        return $DB->get_records_select(
            'local_kbacorp_company',
            'id > :afterid',
            ['afterid' => max(0, $afterid)],
            'id ASC',
            '*',
            0,
            max(1, min(500, $limit))
        );
    }

    /**
     * Return courses explicitly assigned to one corporate company.
     *
     * local_kbacorp_course is the source of truth for the company's corporate
     * catalogue. Employee enrolments are deliberately not used here.
     *
     * @param int $companyid
     * @return array
     */
    public static function get_company_courses(int $companyid): array {
        global $DB;

        if ($companyid <= 0) {
            return [];
        }

        $sql = "SELECT cc.id AS mappingid, cc.courseid, cc.groupid, cc.active,
                       c.fullname, c.shortname, c.idnumber, c.visible
                  FROM {local_kbacorp_course} cc
                  JOIN {course} c ON c.id = cc.courseid
                 WHERE cc.companyid = :companyid
              ORDER BY cc.active DESC, c.sortorder ASC, c.fullname ASC";

        return $DB->get_records_sql($sql, ['companyid' => $companyid]);
    }


    public static function save_department(\stdClass $data): int {
        global $DB, $USER;
        access::require_not_revoked((int)$data->companyid, (int)($USER->id ?? 0));
        if (!empty($data->id)) {
            $existing = $DB->get_record('local_kbacorp_department',
                ['id'=>(int)$data->id,'companyid'=>(int)$data->companyid], '*', MUST_EXIST);
            access::require_not_revoked((int)$existing->companyid, (int)($USER->id ?? 0));
        }
        $now = time();
        $record = (object)[
            'companyid' => (int)$data->companyid,
            'name' => trim((string)$data->name),
            'code' => trim((string)($data->code ?? '')) ?: null,
            'active' => empty($data->active) ? 0 : 1,
            'timemodified' => $now,
        ];
        if ($record->companyid <= 0 || $record->name === '') {
            throw new \invalid_parameter_exception('Department company and name are required.');
        }
        if (!empty($data->id)) {
            $record->id = (int)$data->id;
            $DB->update_record('local_kbacorp_department', $record);
            return $record->id;
        }
        $record->timecreated = $now;
        return $DB->insert_record('local_kbacorp_department', $record);
    }

    public static function delete_department(int $departmentid, int $companyid): void {
        global $DB;
        $department = $DB->get_record('local_kbacorp_department', ['id' => $departmentid, 'companyid' => $companyid], '*', MUST_EXIST);
        $assignments = $DB->get_records('local_kbacorp_dept_member', ['departmentid' => $department->id, 'deptrole' => 'manager']);
        foreach ($assignments as $assignment) {
            self::unassign_department_manager_role($assignment);
        }
        $DB->delete_records('local_kbacorp_dept_member', ['departmentid' => $department->id]);
        $DB->delete_records('local_kbacorp_department', ['id' => $department->id]);
    }

    public static function add_department_member(int $departmentid, int $userid, string $deptrole): int {
        global $DB;
        $department = $DB->get_record('local_kbacorp_department', ['id' => $departmentid], '*', MUST_EXIST);
        $deptrole = $deptrole === 'manager' ? 'manager' : 'employee';
        if ($deptrole === 'employee') {
            // Department employees are always real employees of the parent company.
            self::add_member((int)$department->companyid, $userid, 'employee');
        }
        $existing = $DB->get_record('local_kbacorp_dept_member', [
            'departmentid' => $departmentid,
            'userid' => $userid,
            'deptrole' => $deptrole,
        ]);
        if ($existing) {
            return (int)$existing->id;
        }
        $now = time();
        $record = (object)[
            'departmentid' => $departmentid,
            'userid' => $userid,
            'deptrole' => $deptrole,
            'timecreated' => $now,
            'timemodified' => $now,
        ];
        $record->id = $DB->insert_record('local_kbacorp_dept_member', $record);
        if ($deptrole === 'manager') {
            self::assign_department_manager_role($record);
        }
        return (int)$record->id;
    }

    public static function delete_department_member(int $assignmentid, int $departmentid): void {
        global $DB;
        $assignment = $DB->get_record('local_kbacorp_dept_member', [
            'id' => $assignmentid,
            'departmentid' => $departmentid,
        ], '*', MUST_EXIST);
        if ($assignment->deptrole === 'manager') {
            self::unassign_department_manager_role($assignment);
        }
        $DB->delete_records('local_kbacorp_dept_member', ['id' => $assignmentid]);
    }

    private static function ensure_department_manager_role(): int {
        global $DB;
        $roleid = (int)get_config('local_kbacorporate', 'departmentmanagerroleid');
        $role = $roleid ? $DB->get_record('role', ['id' => $roleid]) : false;
        if (!$role) {
            $role = $DB->get_record('role', ['shortname' => 'kbadepartmentmanager']);
        }
        $created = false;
        if (!$role) {
            $roleid = create_role(
                get_string('departmentmanagerrole', 'local_kbacorporate'),
                'kbadepartmentmanager',
                get_string('departmentmanagerroledesc', 'local_kbacorporate')
            );
            set_role_contextlevels($roleid, [CONTEXT_SYSTEM]);
            $created = true;
        } else {
            $roleid = (int)$role->id;
        }
        $context = \context_system::instance();
        $caps = [
            'local/kbacorporate:viewdashboard',
            'local/kbacorporate:viewcompetencies',
            'local/kbacorporate:managedevelopmentplans',
            'local/kbacorporate:viewemployees',
            'local/kbacorporate:viewprogress',
            'local/kbacorporate:viewgrades',
            'local/kbacorporate:viewattendance',
            'local/kbacorporate:viewtrainingtime',
            'local/kbacorporate:viewcertificates',
            'local/kbacorporate:viewdepartments',
            'local/kbacorporate:viewalerts',
            'local/kbacorporate:viewlicenses',
            'local/kbacorporate:viewgamification',
            'local/kbacorporate:exportreport',
        ];
        foreach ($caps as $cap) {
            assign_capability($cap, CAP_ALLOW, $roleid, $context->id, true);
        }
        set_config('departmentmanagerroleid', $roleid, 'local_kbacorporate');
        if ($created) {
            set_config('departmentmanagerrolecreated', 1, 'local_kbacorporate');
        } else if (get_config('local_kbacorporate', 'departmentmanagerrolecreated') === false) {
            set_config('departmentmanagerrolecreated', 0, 'local_kbacorporate');
        }
        return $roleid;
    }

    private static function assign_department_manager_role(\stdClass $assignment): void {
        $roleid = self::ensure_department_manager_role();
        role_assign($roleid, $assignment->userid, \context_system::instance()->id, 'local_kbacorporate', $assignment->id);
    }

    private static function unassign_department_manager_role(\stdClass $assignment): void {
        global $DB;
        $roleid = (int)get_config('local_kbacorporate', 'departmentmanagerroleid');
        if (!$roleid) {
            $role = $DB->get_record('role', ['shortname' => 'kbadepartmentmanager']);
            $roleid = $role ? (int)$role->id : 0;
        }
        if ($roleid) {
            role_unassign($roleid, $assignment->userid, \context_system::instance()->id, 'local_kbacorporate', $assignment->id);
        }
    }

}
