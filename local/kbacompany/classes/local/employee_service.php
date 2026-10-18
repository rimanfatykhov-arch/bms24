<?php
namespace local_kbacompany\local;

defined('MOODLE_INTERNAL') || die();

/** Canonical BMS employee-membership service backed by Moodle users. */
class employee_service {
    public const CONTRACT_VERSION = '0.6.9.9.2';

    public static function contract(): array {
        return [
            'version' => self::CONTRACT_VERSION,
            'person_identity' => 'moodle_user',
            'membership_storage' => 'local_kbacompany_member',
            'external_contact_identity' => 'local_kbacompany_extmap:user',
            'rules' => [
                'employee_is_company_membership_not_second_person',
                'email_is_matching_hint_not_external_identity',
                'company_transfer_closes_old_membership_and_opens_new',
                'corporate_learning_roles_do_not_grant_bms_management_roles',
                'bms_created_employee_queues_bitrix_and_corporate_sync',
                'employee_membership_never_enrols_courses',
                'employee_profile_edit_updates_moodle_user_and_queues_sync',
                'employee_delete_is_soft_membership_close_not_person_delete',
                'inbound_bitrix_phone_fills_only_empty_or_invalid_moodle_phone',
                'moodle_company_display_projection_never_replaces_unmanaged_institution',
                'employee_list_search_is_company_scoped_server_side_for_canonical_memberships',
                'employee_organization_data_is_managed_by_staffing_not_profile_form',
            ],
        ];
    }


    /** Bounded everyday directory. Compatibility API employees_context remains unchanged. */
    public static function browse_directory(string $companyuuid, int $actorid, array $filters = [], int $page = 0): array {
        global $DB;
        $companyuuid = company_context_service::normalise_uuid($companyuuid);
        $company = company_context_service::context($companyuuid, $actorid);
        $f = self::directory_filters($filters);
        list($from, $params) = self::directory_source($companyuuid, $company);
        $where = ['1 = 1'];
        if ($f['status'] !== 'all') { $where[] = 'e.active = :directoryactive'; $params['directoryactive'] = $f['status'] === 'active' ? 1 : 0; }
        if ($f['placement'] === 'missing') { $where[] = 'p.uuid IS NULL'; }
        if ($f['placement'] === 'assigned') { $where[] = 'p.uuid IS NOT NULL'; }
        // Every word is matched in SQL, including two-part names in either order.
        foreach (preg_split('/\s+/u', $f['q'], -1, PREG_SPLIT_NO_EMPTY) as $i => $word) {
            $parts = [];
            foreach (['firstname', 'lastname', 'email', 'phone1'] as $field) {
                $key = 'dirq' . $i . $field;
                $parts[] = $DB->sql_like('u.' . $field, ':' . $key, false);
                $params[$key] = '%' . $DB->sql_like_escape($word) . '%';
            }
            $where[] = '(' . implode(' OR ', $parts) . ')';
        }
        $where = implode(' AND ', $where);
        $total = (int)$DB->count_records_sql('SELECT COUNT(1) ' . $from . ' WHERE ' . $where, $params);
        $pagesize = 25;
        $page = min(max(0, $page), max(0, (int)ceil($total / $pagesize) - 1));
        $rows = $DB->get_records_sql(self::directory_select() . $from . ' WHERE ' . $where .
            ' ORDER BY u.lastname, u.firstname, u.id', $params, $page * $pagesize, $pagesize);
        return ['company' => $company, 'employees' => array_map([self::class, 'directory_projection'], array_values($rows)),
            'total' => $total, 'page' => $page, 'pagesize' => $pagesize, 'filters' => $f,
            'can_manage' => permission_service::can_manage_organization($companyuuid, $actorid) || is_siteadmin($actorid)];
    }

    public static function directory_filters(array $filters): array {
        $status = (string)($filters['status'] ?? 'active');
        $placement = (string)($filters['placement'] ?? '');
        return ['q' => \core_text::substr(trim((string)($filters['q'] ?? '')), 0, 200),
            'status' => in_array($status, ['active', 'archived', 'all'], true) ? $status : 'active',
            'placement' => in_array($placement, ['', 'assigned', 'missing'], true) ? $placement : ''];
    }

    /** One company-scoped membership; never obtains the full directory to find a person. */
    public static function directory_record(string $companyuuid, int $userid, int $actorid, string $membershipuuid = ''): array {
        global $DB;
        $companyuuid = company_context_service::normalise_uuid($companyuuid);
        $company = company_context_service::context($companyuuid, $actorid);
        list($from, $params) = self::directory_source($companyuuid, $company, $membershipuuid);
        $params['directoryuserid'] = $userid;
        $rows = $DB->get_records_sql(self::directory_select() . $from . ' WHERE e.userid = :directoryuserid', $params, 0, 1);
        if (!$rows) { throw new \moodle_exception('bms_employee_not_found', 'local_kbacompany'); }
        $employee = self::directory_projection(reset($rows));
        $canmanage = permission_service::can_manage_organization($companyuuid, $actorid) || is_siteadmin($actorid);
        return ['company' => $company, 'employee' => $employee, 'can_manage' => $canmanage,
            'editable' => $canmanage && $employee['membership_active'] && !$employee['account_deleted'] &&
                $employee['membership_uuid'] !== '', 'expected' => self::directory_fingerprint($employee)];
    }

    /** SQL root has one most-relevant membership per user. Full membership history is separate. */
    private static function directory_source(string $companyuuid, array $company, string $membershipuuid = ''): array {
        $params = ['directorycompany' => $companyuuid, 'positioncompany' => $companyuuid];
        $fields = 'm.userid, m.uuid AS membershipuuid, m.sourcekind, m.membertype, m.isemployee, m.ismanager,
            m.isdepartmentmanager, m.ishr, m.active, m.effectivefrom, m.effectiveto, m.timemodified';
        $base = 'SELECT ' . $fields . ' FROM {local_kbacompany_member} m WHERE m.companyuuid = :directorycompany';
        if ($membershipuuid !== '') {
            $base .= ' AND m.uuid = :directorymembership'; $params['directorymembership'] = $membershipuuid;
        } else {
            $base .= ' AND NOT EXISTS (SELECT 1 FROM {local_kbacompany_member} nm
                WHERE nm.companyuuid = m.companyuuid AND nm.userid = m.userid AND
                (nm.active > m.active OR (nm.active = m.active AND
                  (nm.effectivefrom > m.effectivefrom OR (nm.effectivefrom = m.effectivefrom AND nm.id > m.id)))))';
            $legacyid = (int)($company['compatibility']['source_id'] ?? 0);
            if ($legacyid > 0 && legacy_company_adapter::is_ready()) {
                list($legacy, $legacyparams) = legacy_company_adapter::employee_directory_source($legacyid, $companyuuid);
                $base .= ' UNION ALL ' . $legacy; $params += $legacyparams;
            }
        }
        $now = time();
        $params += ['dirfrom' => $now, 'dirto' => $now, 'dirnewfrom' => $now, 'dirnewto' => $now];
        // Primary assignment wins; otherwise the newest effective assignment. No PHP post-filter.
        $from = ' FROM (' . $base . ') e JOIN {user} u ON u.id = e.userid
            LEFT JOIN {local_kbacompany_posassign} pa ON pa.companyuuid = :positioncompany AND pa.userid = e.userid
                AND pa.active = 1 AND (pa.effectivefrom = 0 OR pa.effectivefrom <= :dirfrom)
                AND (pa.effectiveto = 0 OR pa.effectiveto >= :dirto)
                AND EXISTS (SELECT 1 FROM {local_kbacompany_position} vp WHERE vp.uuid = pa.positionuuid AND vp.companyuuid = pa.companyuuid)
                AND NOT EXISTS (SELECT 1 FROM {local_kbacompany_posassign} np
                    JOIN {local_kbacompany_position} vp2 ON vp2.uuid = np.positionuuid AND vp2.companyuuid = np.companyuuid
                    WHERE np.companyuuid = pa.companyuuid AND np.userid = pa.userid AND np.active = 1
                    AND (np.effectivefrom = 0 OR np.effectivefrom <= :dirnewfrom)
                    AND (np.effectiveto = 0 OR np.effectiveto >= :dirnewto)
                    AND ((CASE WHEN np.assignmenttype = \'primary\' THEN 1 ELSE 0 END) >
                         (CASE WHEN pa.assignmenttype = \'primary\' THEN 1 ELSE 0 END)
                         OR ((CASE WHEN np.assignmenttype = \'primary\' THEN 1 ELSE 0 END) =
                             (CASE WHEN pa.assignmenttype = \'primary\' THEN 1 ELSE 0 END) AND np.id > pa.id)))
            LEFT JOIN {local_kbacompany_position} p ON p.uuid = pa.positionuuid AND p.companyuuid = pa.companyuuid
            LEFT JOIN {local_kbacompany_orgnode} n ON n.uuid = p.orgnodeuuid AND n.companyuuid = p.companyuuid';
        return [$from, $params];
    }

    private static function directory_select(): string {
        return 'SELECT e.userid AS id, e.*, u.firstname, u.lastname, u.firstnamephonetic, u.lastnamephonetic,
            u.middlename, u.alternatename, u.email, u.phone1, u.suspended, u.deleted, u.timemodified AS profilemodified,
            p.uuid AS positionuuid, p.name AS positionname, n.name AS unitname';
    }

    private static function directory_projection(\stdClass $row): array {
        return ['user_id' => (int)$row->userid, 'membership_uuid' => (string)$row->membershipuuid,
            'membership_source' => (string)$row->sourcekind, 'membership_active' => !empty($row->active),
            'full_name' => fullname($row), 'firstname' => (string)$row->firstname, 'lastname' => (string)$row->lastname,
            'email' => (string)$row->email, 'phone' => self::safe_phone((string)$row->phone1),
            'phone_needs_correction' => trim((string)$row->phone1) !== '' && self::safe_phone((string)$row->phone1) === '',
            'raw_phone' => (string)$row->phone1, 'account_suspended' => !empty($row->suspended), 'account_deleted' => !empty($row->deleted),
            'effective_from' => (int)$row->effectivefrom, 'effective_to' => (int)$row->effectiveto,
            'time_modified' => (int)$row->timemodified, 'profile_modified' => (int)$row->profilemodified,
            'roles' => self::roles($row), 'position' => empty($row->positionuuid) ? null :
                ['uuid' => (string)$row->positionuuid, 'name' => (string)$row->positionname, 'unit' => (string)($row->unitname ?? '')]];
    }

    /** Membership and global profile token. This is optimistic UI protection, not a database revision. */
    public static function directory_fingerprint(array $employee): string {
        $values = [];
        foreach (['user_id', 'membership_uuid', 'membership_source', 'membership_active', 'firstname', 'lastname',
                'email', 'raw_phone', 'account_deleted', 'account_suspended', 'effective_from', 'effective_to',
                'time_modified', 'profile_modified', 'roles'] as $key) { $values[$key] = $employee[$key]; }
        return document_request_service::digest($values);
    }

    /** Bounded history, deliberately not presented as a complete user-profile audit. */
    public static function directory_history(string $companyuuid, int $userid, int $actorid, int $page = 0): array {
        global $DB;
        self::directory_record($companyuuid, $userid, $actorid);
        $companyuuid = company_context_service::normalise_uuid($companyuuid);
        $conditions = ['companyuuid' => $companyuuid, 'userid' => $userid];
        $total = $DB->count_records('local_kbacompany_member', $conditions); $pagesize = 25;
        $page = min(max(0, $page), max(0, (int)ceil($total / $pagesize) - 1));
        return ['rows' => array_values($DB->get_records('local_kbacompany_member', $conditions,
            'effectivefrom DESC, id DESC', '*', $page * $pagesize, $pagesize)),
            'total' => $total, 'page' => $page, 'pagesize' => $pagesize];
    }

    /** Technical metadata fetched only on the explicit manager-only tab. */
    public static function directory_integration(string $companyuuid, int $userid, int $actorid): array {
        global $DB;
        $record = self::directory_record($companyuuid, $userid, $actorid);
        if (!$record['can_manage']) { throw new \required_capability_exception(\context_system::instance(),
            'local/kbacompany:manageorganization', 'nopermissions', ''); }
        $companyuuid = company_context_service::normalise_uuid($companyuuid);
        $rows = $DB->get_records_select('local_kbacompany_syncout',
            "companyuuid = :company AND userid = :userid AND commandkind IN ('employee_upsert','employee_close')",
            ['company' => $companyuuid, 'userid' => $userid], 'timecreated DESC, id DESC', '*', 0, 1);
        $last = $rows ? reset($rows) : null;
        // Legacy provider user mapping is global identity; membership is authorised above before lookup.
        $contact = integration_service::external_id(integration_service::BITRIX_PROVIDER, 'user', (string)$userid);
        return ['contact' => $contact, 'status' => self::projection_sync_status($contact, $last),
            'time' => $last ? (int)$last->timecreated : 0, 'error' => $last ? (string)$last->lasterror : ''];
    }

    /** Domain validation reused by the UI; does not clean invalid input into a different identity. */
    public static function directory_form_errors(array $data): array {
        $errors = [];
        foreach (['firstname' => 100, 'lastname' => 100, 'email' => 100, 'phone' => 20] as $field => $limit) {
            $value = trim((string)($data[$field] ?? ''));
            if ($field !== 'phone' && $value === '') { $errors[$field] = get_string('required'); }
            else if (\core_text::strlen($value) > $limit) { $errors[$field] = get_string('ecu_too_long', 'local_kbacompany', $limit); }
        }
        if (empty($errors['email']) && !validate_email(trim((string)($data['email'] ?? '')))) {
            $errors['email'] = get_string('bms_employee_invalid_email', 'local_kbacompany');
        }
        if (validate_email(trim((string)($data['phone'] ?? '')))) { $errors['phone'] = get_string('bms_employee_invalid_phone', 'local_kbacompany'); }
        return $errors;
    }

    /** UI command boundary. Existing domain writes and sync queue remain the owners. */
    public static function directory_save(string $companyuuid, int $actorid, string $operation, int $userid,
            string $membershipuuid, array $data, string $expected): array {
        global $DB;
        $companyuuid = company_context_service::normalise_uuid($companyuuid);
        company_context_service::context($companyuuid, $actorid);
        if (!permission_service::can_manage_organization($companyuuid, $actorid) && !is_siteadmin($actorid)) {
            throw new \required_capability_exception(\context_system::instance(), 'local/kbacompany:manageorganization', 'nopermissions', '');
        }
        if (!in_array($operation, ['create', 'edit', 'close'], true)) { throw new \invalid_parameter_exception('Unknown employee operation'); }
        if ($operation !== 'close' && self::directory_form_errors($data)) { throw new \moodle_exception('ecu_check_form', 'local_kbacompany'); }
        if ($operation === 'close' && empty($data['confirm'])) { throw new \moodle_exception('ecu_confirm_required', 'local_kbacompany'); }
        $factory = \core\lock\lock_config::get_lock_factory('local_kbacompany');
        // Covers this UI's commands; legacy CRM/profile writers do not take these locks.
        $lockkey = $operation === 'create' ? 'employee-email-' . hash('sha256', \core_text::strtolower(trim($data['email']))) : 'employee-user-' . $userid;
        $lock = $factory->get_lock($lockkey, 10);
        if (!$lock) { throw new \moodle_exception('ecu_busy', 'local_kbacompany'); }
        try {
            if ($operation !== 'create') {
                $record = self::directory_record($companyuuid, $userid, $actorid, $membershipuuid);
                if (!$record['editable'] || $expected === '' || !hash_equals($record['expected'], $expected)) {
                    throw new \moodle_exception('ecu_stale', 'local_kbacompany');
                }
            } else {
                // Exact account uniqueness is a Moodle-wide constraint, not a directory search.
                // Do not expose or attach an unrelated account merely because a manager knows its email.
                $email = \core_text::strtolower(trim((string)$data['email']));
                if ($DB->record_exists('user', ['email' => $email, 'deleted' => 0])) {
                    $existing = $DB->get_records_sql('SELECT u.id FROM {local_kbacompany_member} m
                        JOIN {user} u ON u.id = m.userid WHERE m.companyuuid = :company AND m.active = 1
                        AND u.email = :email AND u.deleted = 0', ['company' => $companyuuid, 'email' => $email], 0, 2);
                    if (count($existing) === 1) {
                        $record = self::directory_record($companyuuid, (int)reset($existing)->id, $actorid);
                        return ['userid' => $record['employee']['user_id'], 'membership_uuid' => $record['employee']['membership_uuid'], 'existing' => true];
                    }
                    throw new \moodle_exception('ecu_account_unavailable', 'local_kbacompany');
                }
            }
            $transaction = $DB->start_delegated_transaction();
            try {
                if ($operation === 'create') { $data['directory_newonly'] = true; $result = self::create_employee($companyuuid, $actorid, $data); }
                else if ($operation === 'edit') {
                    $result = self::update_employee($companyuuid, $userid, $actorid, $data);
                    // Keep the exact authorised membership as the UI destination when legacy duplicates exist.
                    $result['membership_uuid'] = $membershipuuid;
                }
                else {
                    self::close_membership($companyuuid, $userid, $actorid);
                    $result = ['userid' => $userid, 'membership_uuid' => $membershipuuid];
                }
                $transaction->allow_commit();
                return $result;
            } catch (\Throwable $e) { $transaction->rollback($e); throw $e; }
        } finally { $lock->release(); }
    }

    public static function employees_context(string $companyuuid, int $actorid,
            bool $includeinactive = false, array $filters = []): array {
        global $DB;
        $company = company_context_service::context($companyuuid, $actorid);
        $params = ['companyuuid' => $companyuuid];
        $where = ['m.companyuuid = :companyuuid'];

        $status = trim((string)($filters['status'] ?? ''));
        if (!$includeinactive || $status === 'active') {
            $where[] = 'm.active = 1';
        } else if ($status === 'archived') {
            $where[] = 'm.active = 0';
        }

        $query = \core_text::strtolower(trim((string)($filters['q'] ?? '')));
        if ($query !== '') {
            $like = '%' . $DB->sql_like_escape($query) . '%';
            $where[] = '(' . $DB->sql_like('LOWER(u.firstname)', ':qfirstname', false) .
                ' OR ' . $DB->sql_like('LOWER(u.lastname)', ':qlastname', false) .
                ' OR ' . $DB->sql_like('LOWER(u.email)', ':qemail', false) .
                ' OR ' . $DB->sql_like('LOWER(u.phone1)', ':qphone', false) . ')';
            $params['qfirstname'] = $like;
            $params['qlastname'] = $like;
            $params['qemail'] = $like;
            $params['qphone'] = $like;
        }

        $rows = array_values($DB->get_records_sql(
            "SELECT m.*, u.firstname, u.lastname, u.email, u.phone1, u.institution, u.suspended, u.deleted
               FROM {local_kbacompany_member} m
               JOIN {user} u ON u.id = m.userid
              WHERE " . implode(' AND ', $where) . "
           ORDER BY m.active DESC, u.lastname, u.firstname, m.id", $params));
        $positionmap = self::current_positions($companyuuid);
        $outbox = self::latest_outbox($companyuuid);
        $employees = [];
        $seen = [];
        $canonicalseen = [];
        foreach ($DB->get_fieldset_select('local_kbacompany_member', 'userid',
                'companyuuid = :companyuuid', ['companyuuid' => $companyuuid]) as $canonicaluserid) {
            $canonicalseen[(int)$canonicaluserid] = true;
        }
        foreach ($rows as $row) {
            $userid = (int)$row->userid;
            $seen[$userid] = true;
            $contactid = integration_service::external_id(integration_service::BITRIX_PROVIDER, 'user', (string)$userid);
            $sync = $outbox[$userid] ?? null;
            $employees[] = [
                'membership_uuid' => (string)$row->uuid,
                'membership_source' => (string)$row->sourcekind,
                'can_close' => true,
                'user_id' => $userid,
                'full_name' => fullname($row),
                'email' => (string)$row->email,
                'phone' => (string)$row->phone1,
                'profile_company' => (string)($row->institution ?? ''),
                'active' => !empty($row->active) && empty($row->suspended) && empty($row->deleted),
                'membership_active' => !empty($row->active),
                'effective_from' => (int)$row->effectivefrom,
                'effective_to' => (int)$row->effectiveto,
                'time_modified' => (int)($row->timemodified ?? 0),
                'roles' => self::roles($row),
                'position' => $positionmap[$userid] ?? null,
                'bitrix_contact_id' => $contactid,
                'sync_status' => self::projection_sync_status($contactid, $sync),
                'sync_error' => $sync ? (string)($sync->lasterror ?? '') : '',
            ];
        }

        // Compatibility projection: existing Corporate Learning members stay visible until
        // KBA Connect reconciles them into canonical BMS memberships. They remain read-only here.
        // The legacy adapter cannot apply SQL filters, so only matching rows are projected here.
        $legacyid = (int)($company['compatibility']['source_id'] ?? 0);
        if ($legacyid > 0 && legacy_company_adapter::is_ready() && $status !== 'archived') {
            $legacyorg = legacy_company_adapter::organization($legacyid);
            foreach ($legacyorg['members'] as $row) {
                $userid = (int)$row->userid;
                if (isset($canonicalseen[$userid])) {
                    continue;
                }
                if ($query !== '' && !self::matches_employee_query($row, $query)) {
                    continue;
                }
                $contactid = integration_service::external_id(integration_service::BITRIX_PROVIDER, 'user', (string)$userid);
                $sync = $outbox[$userid] ?? null;
                $position = $positionmap[$userid] ?? null;
                if (!$position && !empty($legacyorg['positions'][$userid])) {
                    $position = ['uuid' => '', 'name' => (string)$legacyorg['positions'][$userid], 'unit' => ''];
                }
                $employees[] = [
                    'membership_uuid' => '',
                    'membership_source' => 'local_kbacorporate_compatibility',
                    'can_close' => false,
                    'user_id' => $userid,
                    'full_name' => fullname($row),
                    'email' => (string)($row->email ?? ''),
                    'phone' => (string)($row->phone1 ?? ''),
                    'profile_company' => (string)($row->institution ?? ''),
                    'active' => true, 'membership_active' => true,
                    'effective_from' => 0, 'effective_to' => 0, 'time_modified' => 0,
                    'roles' => self::roles($row), 'position' => $position,
                    'bitrix_contact_id' => $contactid,
                    'sync_status' => self::projection_sync_status($contactid, $sync),
                    'sync_error' => $sync ? (string)($sync->lasterror ?? '') : '',
                ];
            }
        }
        usort($employees, static function(array $a, array $b): int {
            return strcasecmp((string)$a['full_name'], (string)$b['full_name']);
        });

        $summary = ['total' => 0, 'active' => 0, 'archived' => 0, 'without_position' => 0, 'sync_attention' => 0];
        foreach ($employees as $employee) {
            $summary['total']++;
            if (!empty($employee['membership_active'])) { $summary['active']++; } else { $summary['archived']++; }
            if (empty($employee['position'])) { $summary['without_position']++; }
            if (in_array((string)$employee['sync_status'], ['error', 'pending', 'waiting_company_mapping', 'not_synced'], true)) {
                $summary['sync_attention']++;
            }
        }

        return ['company' => $company, 'employees' => $employees, 'summary' => $summary,
            'filters' => ['q' => $query, 'status' => $status],
            'can_manage' => permission_service::can_manage_organization($companyuuid, $actorid),
            'external_company_id' => integration_service::external_id(integration_service::BITRIX_PROVIDER, 'company', $companyuuid)];
    }

    /** Resolve one employee projection inside a company. */
    public static function employee_context(string $companyuuid, int $userid, int $actorid,
            bool $includeinactive = true, string $membershipuuid = ''): array {
        $context = self::employees_context($companyuuid, $actorid, $includeinactive);
        foreach ($context['employees'] as $employee) {
            if ((int)$employee['user_id'] !== $userid) {
                continue;
            }
            if ($membershipuuid !== '' && (string)$employee['membership_uuid'] !== $membershipuuid) {
                continue;
            }
            return ['company' => $context['company'], 'employee' => $employee,
                'can_manage' => $context['can_manage'],
                'external_company_id' => $context['external_company_id']];
        }
        throw new \moodle_exception('bms_employee_not_found', 'local_kbacompany');
    }

    /** Company membership history for one employee. */
    public static function membership_history(string $companyuuid, int $userid, int $actorid): array {
        global $DB;
        company_context_service::context($companyuuid, $actorid);
        $rows = $DB->get_records('local_kbacompany_member', [
            'companyuuid' => $companyuuid,
            'userid' => $userid,
        ], 'effectivefrom DESC, id DESC');
        $history = [];
        foreach ($rows as $row) {
            $history[] = [
                'uuid' => (string)$row->uuid,
                'source' => (string)$row->sourcekind,
                'active' => !empty($row->active),
                'effective_from' => (int)$row->effectivefrom,
                'effective_to' => (int)$row->effectiveto,
                'time_created' => (int)($row->timecreated ?? 0),
                'time_modified' => (int)($row->timemodified ?? 0),
            ];
        }
        return $history;
    }

    /** Update the canonical Moodle user profile and queue outbound reconciliation. */
    public static function update_employee(string $companyuuid, int $userid, int $actorid, array $data): array {
        global $CFG, $DB;
        if (!permission_service::can_manage_organization($companyuuid, $actorid) && !is_siteadmin($actorid)) {
            throw new \required_capability_exception(\context_system::instance(),
                'local/kbacompany:manageorganization', 'nopermissions', '');
        }
        $company = company_context_service::context($companyuuid, $actorid);
        $member = $DB->get_record('local_kbacompany_member', [
            'companyuuid' => $companyuuid, 'userid' => $userid, 'active' => 1,
        ], '*', IGNORE_MULTIPLE);
        if (!$member) {
            throw new \moodle_exception('bms_employee_edit_requires_membership', 'local_kbacompany');
        }
        $user = $DB->get_record('user', ['id' => $userid, 'deleted' => 0], '*', MUST_EXIST);
        $firstname = trim((string)($data['firstname'] ?? ''));
        $lastname = trim((string)($data['lastname'] ?? ''));
        $email = \core_text::strtolower(trim((string)($data['email'] ?? '')));
        $phone = trim((string)($data['phone'] ?? ''));
        if ($firstname === '' || $lastname === '') {
            throw new \moodle_exception('bms_employee_name_required', 'local_kbacompany');
        }
        if (!validate_email($email)) {
            throw new \moodle_exception('bms_employee_invalid_email', 'local_kbacompany');
        }
        if ($phone !== '' && validate_email($phone)) {
            throw new \moodle_exception('bms_employee_invalid_phone', 'local_kbacompany');
        }
        if (empty($CFG->allowaccountssameemail) &&
                $email !== \core_text::strtolower((string)$user->email)) {
            $others = $DB->get_records('user', ['email' => $email, 'deleted' => 0], 'id ASC', 'id', 0, 2);
            foreach ($others as $other) {
                if ((int)$other->id !== $userid) {
                    throw new \moodle_exception('bms_employee_email_in_use', 'local_kbacompany');
                }
            }
        }
        require_once($CFG->dirroot . '/user/lib.php');
        user_update_user((object)[
            'id' => $userid, 'firstname' => $firstname, 'lastname' => $lastname,
            'email' => $email, 'phone1' => $phone,
        ], false, true);
        $user = $DB->get_record('user', ['id' => $userid], '*', MUST_EXIST);
        $commanduuid = self::queue_employee_upsert($company, $companyuuid, $user);
        $companyexternal = integration_service::external_id(
            integration_service::BITRIX_PROVIDER, 'company', $companyuuid);
        return ['userid' => $userid, 'membership_uuid' => (string)$member->uuid,
            'sync_command_uuid' => $commanduuid,
            'sync_status' => $companyexternal === '' ? 'waiting_company_mapping' : 'pending'];
    }

    /** Create or attach a Moodle user to BMS, then queue KBA Connect reconciliation. */
    public static function create_employee(string $companyuuid, int $actorid, array $data): array {
        global $CFG, $DB;
        if (!permission_service::can_manage_organization($companyuuid, $actorid) && !is_siteadmin($actorid)) {
            throw new \required_capability_exception(\context_system::instance(),
                'local/kbacompany:manageorganization', 'nopermissions', '');
        }
        $company = company_context_service::context($companyuuid, $actorid);
        self::ensure_company_registry($company, $actorid);
        $email = \core_text::strtolower(trim((string)($data['email'] ?? '')));
        if (!validate_email($email)) {
            throw new \moodle_exception('bms_employee_invalid_email', 'local_kbacompany');
        }
        $user = $DB->get_record('user', ['email' => $email, 'deleted' => 0]);
        if ($user && !empty($data['directory_newonly'])) { throw new \moodle_exception('ecu_account_unavailable', 'local_kbacompany'); }
        $createduser = false;
        $submittedphone = trim((string)($data['phone'] ?? ''));
        if ($submittedphone !== '' && validate_email($submittedphone)) {
            throw new \moodle_exception('bms_employee_invalid_phone', 'local_kbacompany');
        }
        if (!$user) {
            require_once($CFG->dirroot . '/user/lib.php');
            $firstname = trim((string)($data['firstname'] ?? ''));
            $lastname = trim((string)($data['lastname'] ?? ''));
            if ($firstname === '' || $lastname === '') {
                throw new \moodle_exception('bms_employee_name_required', 'local_kbacompany');
            }
            $username = self::unique_username($email);
            $newuser = (object)[
                'auth' => 'manual', 'confirmed' => 1, 'mnethostid' => $CFG->mnet_localhost_id,
                'username' => $username, 'password' => generate_password(20),
                'firstname' => $firstname, 'lastname' => $lastname, 'email' => $email,
                'phone1' => $submittedphone, 'city' => '', 'country' => '',
                'lang' => current_language(), 'timecreated' => time(), 'timemodified' => time(),
            ];
            $userid = user_create_user($newuser, true, false);
            set_user_preference('auth_forcepasswordchange', 1, $userid);
            $user = $DB->get_record('user', ['id' => $userid], '*', MUST_EXIST);
            $createduser = true;
        } else if ($submittedphone !== '' && trim((string)$user->phone1) !== $submittedphone) {
            require_once($CFG->dirroot . '/user/lib.php');
            user_update_user((object)['id' => (int)$user->id, 'phone1' => $submittedphone], false, true);
            $user = $DB->get_record('user', ['id' => (int)$user->id], '*', MUST_EXIST);
        }
        $member = self::ensure_membership($companyuuid, (int)$user->id, [
            'employee' => true, 'manager' => false, 'department_manager' => false, 'hr' => false,
        ], 'bms_manual');
        $companyexternal = integration_service::external_id(integration_service::BITRIX_PROVIDER, 'company', $companyuuid);
        $commanduuid = self::queue_employee_upsert($company, $companyuuid, $user);
        return ['userid' => (int)$user->id, 'membership_uuid' => (string)$member->uuid,
            'moodle_user_created' => $createduser, 'sync_command_uuid' => $commanduuid,
            'sync_status' => $companyexternal === '' ? 'waiting_company_mapping' : 'pending'];
    }

    /** Inbound KBA Connect Contact sync. */
    public static function upsert_external_employee(string $companyuuid, string $externalcontactid,
            array $userdata, array $roles = []): array {
        global $CFG, $DB;
        $map = integration_service::mapping_by_external(integration_service::BITRIX_PROVIDER, 'user', $externalcontactid);
        $user = null;
        if ($map) {
            $user = $DB->get_record('user', ['id' => (int)$map->localref, 'deleted' => 0]);
        }
        $email = \core_text::strtolower(trim((string)($userdata['email'] ?? '')));
        if (!$user && $email !== '' && validate_email($email)) {
            $user = $DB->get_record('user', ['email' => $email, 'deleted' => 0]);
        }
        $company = company_registry_service::by_uuid($companyuuid);
        $companyname = $company ? trim((string)$company->name) : '';
        $rawphone = trim((string)($userdata['phone'] ?? ''));
        $incomingphone = self::safe_inbound_phone($rawphone);
        $incomingposition = trim((string)($userdata['position'] ?? ''));
        $created = false;
        $profileposition = '';
        $phoneprojection = $rawphone !== '' && $incomingphone === '' ? 'invalid_ignored' : 'not_provided';
        $companyprojection = 'not_available';
        $positionprojection = $incomingposition === '' ? 'not_provided' : 'not_applied';
        if (!$user) {
            if (empty($userdata['allow_create_user']) || !validate_email($email)) {
                return ['status' => 'moodle_user_unresolved', 'userid' => 0, 'company_uuid' => $companyuuid,
                    'phone_projection' => $phoneprojection === 'invalid_ignored' ? 'invalid_ignored'
                        : ($incomingphone === '' ? 'not_provided' : 'not_applied'),
                    'company_projection' => $companyname === '' ? 'not_available' : 'not_applied',
                    'position_projection' => $positionprojection];
            }
            require_once($CFG->dirroot . '/user/lib.php');
            $firstname = trim((string)($userdata['firstname'] ?? ''));
            $lastname = trim((string)($userdata['lastname'] ?? ''));
            if ($firstname === '' || $lastname === '') {
                return ['status' => 'moodle_user_unresolved', 'userid' => 0, 'company_uuid' => $companyuuid,
                    'phone_projection' => $phoneprojection === 'invalid_ignored' ? 'invalid_ignored'
                        : ($incomingphone === '' ? 'not_provided' : 'not_applied'),
                    'company_projection' => $companyname === '' ? 'not_available' : 'not_applied',
                    'position_projection' => $positionprojection];
            }
            $newuser = (object)[
                'auth' => 'manual', 'confirmed' => 1, 'mnethostid' => $CFG->mnet_localhost_id,
                'username' => self::unique_username($email), 'password' => generate_password(20),
                'firstname' => $firstname, 'lastname' => $lastname, 'email' => $email,
                'phone1' => $incomingphone, 'institution' => $companyname, 'city' => '', 'country' => '',
                'lang' => current_language(), 'timecreated' => time(), 'timemodified' => time(),
            ];
            $userid = user_create_user($newuser, true, false);
            set_user_preference('auth_forcepasswordchange', 1, $userid);
            $user = $DB->get_record('user', ['id' => $userid], '*', MUST_EXIST);
            list($positionprojection, $profileposition) = self::project_inbound_position_to_profile(
                (int)$user->id, $incomingposition);
            $created = true;
            $phoneprojection = $phoneprojection === 'invalid_ignored' ? 'invalid_ignored'
                : ($incomingphone === '' ? 'not_provided' : 'created');
            $companyprojection = $companyname === '' ? 'not_available' : 'created';
        } else {
            $invalidphoneinput = $phoneprojection === 'invalid_ignored';
            list($user, $phoneprojection, $companyprojection) = self::project_inbound_contact_to_user(
                $user, $companyuuid, $companyname, $incomingphone);
            if ($invalidphoneinput && $incomingphone === '') {
                $phoneprojection = 'invalid_ignored';
            }
            list($positionprojection, $profileposition) = self::project_inbound_position_to_profile(
                (int)$user->id, $incomingposition);
        }
        integration_service::bind_external(integration_service::BITRIX_PROVIDER, 'user',
            (string)$user->id, $externalcontactid);
        self::close_other_company_memberships_for_contact((int)$user->id, $companyuuid);
        $member = self::ensure_membership($companyuuid, (int)$user->id, self::normalize_roles($roles), 'bitrix24');
        return ['status' => $created ? 'created' : 'updated', 'userid' => (int)$user->id,
            'company_uuid' => $companyuuid, 'membership_uuid' => (string)$member->uuid,
            'phone_projection' => $phoneprojection, 'company_projection' => $companyprojection,
            'position_projection' => $positionprojection,
            'profile_company' => (string)($user->institution ?? ''),
            'profile_position' => $profileposition,
            'phone' => (string)($user->phone1 ?? '')];
    }

    /** Project Bitrix Contact POST into the legacy Moodle display profile field used by KBA Corporate.
     * This never creates or assigns a canonical BMS Position.
     * Empty provider values are non-destructive.
     */
    private static function project_inbound_position_to_profile(int $userid, string $position): array {
        global $CFG, $DB;
        $position = trim($position);
        if ($position === '') {
            $fieldid = (int)$DB->get_field('user_info_field', 'id', ['shortname' => 'position']);
            if ($fieldid <= 0) {
                return ['profile_field_missing', ''];
            }
            $current = (string)($DB->get_field('user_info_data', 'data', [
                'userid' => $userid, 'fieldid' => $fieldid,
            ]) ?: '');
            return ['not_provided', trim($current)];
        }
        $field = $DB->get_record('user_info_field', ['shortname' => 'position'], 'id,datatype');
        if (!$field) {
            return ['profile_field_missing', ''];
        }
        $current = trim((string)($DB->get_field('user_info_data', 'data', [
            'userid' => $userid, 'fieldid' => (int)$field->id,
        ]) ?: ''));
        if ($current === $position) {
            return ['unchanged', $current];
        }
        require_once($CFG->dirroot . '/user/profile/lib.php');
        $record = (object)['id' => $userid, 'profile_field_position' => $position];
        profile_save_data($record);
        $saved = trim((string)($DB->get_field('user_info_data', 'data', [
            'userid' => $userid, 'fieldid' => (int)$field->id,
        ]) ?: ''));
        return [$current === '' ? 'filled' : 'updated_from_bitrix', $saved];
    }

    public static function ensure_membership(string $companyuuid, int $userid, array $roles, string $sourcekind): \stdClass {
        global $DB;
        $lock = \core\lock\lock_config::get_lock_factory('local_kbacompany')
            ->get_lock('member:' . sha1($companyuuid . "\n" . $userid), 10);
        if (!$lock) {
            throw new \moodle_exception('identitylockfailed', 'local_kbacompany');
        }
        try {
            $existing = $DB->get_record('local_kbacompany_member', [
                'companyuuid' => $companyuuid, 'userid' => $userid, 'active' => 1,
            ], '*', IGNORE_MULTIPLE);
            $now = time();
            if ($existing) {
                self::apply_roles($existing, $roles);
                $existing->sourcekind = $sourcekind;
                $existing->timemodified = $now;
                $DB->update_record('local_kbacompany_member', $existing);
                return $existing;
            }
            $record = (object)[
                'uuid' => self::uuid_v4(), 'companyuuid' => $companyuuid, 'userid' => $userid,
                'membertype' => !empty($roles['owner']) ? 'owner' : 'employee',
                'isemployee' => 1, 'ismanager' => !empty($roles['manager']) ? 1 : 0,
                'isdepartmentmanager' => !empty($roles['department_manager']) ? 1 : 0,
                'ishr' => !empty($roles['hr']) ? 1 : 0,
                'effectivefrom' => $now, 'effectiveto' => 0, 'sourcekind' => $sourcekind,
                'active' => 1, 'timecreated' => $now, 'timemodified' => $now,
            ];
            $record->id = $DB->insert_record('local_kbacompany_member', $record);
            return $record;
        } finally {
            $lock->release();
        }
    }

    public static function close_membership(string $companyuuid, int $userid, int $actorid): void {
        global $DB;
        if (!permission_service::can_manage_organization($companyuuid, $actorid) && !is_siteadmin($actorid)) {
            throw new \required_capability_exception(\context_system::instance(),
                'local/kbacompany:manageorganization', 'nopermissions', '');
        }
        $rows = $DB->get_records('local_kbacompany_member', [
            'companyuuid' => $companyuuid, 'userid' => $userid, 'active' => 1,
        ]);
        $now = time();
        foreach ($rows as $row) {
            $row->active = 0; $row->effectiveto = $now; $row->timemodified = $now;
            $DB->update_record('local_kbacompany_member', $row);
        }
        if ($rows) {
            integration_service::enqueue('employee_close', $companyuuid, $userid,
                ['company_uuid' => $companyuuid, 'moodle_user_id' => $userid,
                    'external_contact_id' => integration_service::external_id(
                        integration_service::BITRIX_PROVIDER, 'user', (string)$userid)]);
        }
    }

    private static function ensure_company_registry(array $company, int $actorid): void {
        if (company_registry_service::by_uuid((string)$company['uuid'])) {
            return;
        }
        $legacyid = (int)($company['compatibility']['source_id'] ?? 0);
        if ($legacyid > 0 && legacy_company_adapter::is_ready()) {
            $legacy = legacy_company_adapter::company($legacyid);
            if ($legacy) {
                company_registry_service::adopt_legacy($legacy, $actorid);
            }
        }
    }


    /** Accept a phone from a CRM Contact only when it is non-empty and not email-like. */
    private static function safe_inbound_phone(string $value): string {
        $value = trim($value);
        if ($value === '' || validate_email($value)) {
            return '';
        }
        return $value;
    }

    /**
     * Project provider Contact display data to Moodle User without changing canonical BMS ownership.
     *
     * Phone: fill only an empty or obviously invalid (email-like) Moodle phone. A valid non-empty
     * Moodle phone is retained to avoid a two-way sync ping-pong.
     * Company: Moodle institution is display-only. Fill when empty, or replace only when its current
     * value can be proven to be a BMS-managed company projection from this user's membership history.
     * The canonical Company relationship always remains local_kbacompany_member.
     */
    private static function project_inbound_contact_to_user(\stdClass $user, string $companyuuid,
            string $companyname, string $incomingphone): array {
        global $CFG, $DB;
        $updates = (object)['id' => (int)$user->id];
        $changed = false;
        $phoneprojection = 'not_provided';
        $companyprojection = $companyname === '' ? 'not_available' : 'kept';

        if ($incomingphone !== '') {
            $currentphone = trim((string)($user->phone1 ?? ''));
            if ($currentphone === '' || validate_email($currentphone)) {
                $updates->phone1 = $incomingphone;
                $changed = true;
                $phoneprojection = $currentphone === '' ? 'filled' : 'replaced_invalid';
            } else if ($currentphone === $incomingphone) {
                $phoneprojection = 'already_current';
            } else {
                $phoneprojection = 'kept_existing';
            }
        }

        if ($companyname !== '') {
            $currentinstitution = trim((string)($user->institution ?? ''));
            $mayreplace = $currentinstitution === '';
            if (!$mayreplace) {
                $sql = "SELECT DISTINCT c.name
                          FROM {local_kbacompany_member} m
                          JOIN {local_kbacompany_company} c ON c.uuid = m.companyuuid
                         WHERE m.userid = :userid";
                $knownnames = $DB->get_fieldset_sql($sql, ['userid' => (int)$user->id]);
                foreach ($knownnames as $knownname) {
                    if (trim((string)$knownname) !== '' && trim((string)$knownname) === $currentinstitution) {
                        $mayreplace = true;
                        break;
                    }
                }
            }
            if ($mayreplace && $currentinstitution !== $companyname) {
                $updates->institution = $companyname;
                $changed = true;
                $companyprojection = $currentinstitution === '' ? 'filled' : 'updated_managed_projection';
            } else if ($currentinstitution === $companyname) {
                $companyprojection = 'already_current';
            } else if (!$mayreplace) {
                $companyprojection = 'kept_unmanaged';
            }
        }

        if ($changed) {
            require_once($CFG->dirroot . '/user/lib.php');
            user_update_user($updates, false, true);
            $user = $DB->get_record('user', ['id' => (int)$user->id], '*', MUST_EXIST);
        }
        return [$user, $phoneprojection, $companyprojection];
    }

    private static function close_other_company_memberships_for_contact(int $userid, string $companyuuid): void {
        global $DB;
        $rows = $DB->get_records_select('local_kbacompany_member',
            "userid = :userid AND active = 1 AND sourcekind = 'bitrix24' AND companyuuid <> :companyuuid",
            ['userid' => $userid, 'companyuuid' => $companyuuid]);
        $now = time();
        foreach ($rows as $row) {
            $row->active = 0; $row->effectiveto = $now; $row->timemodified = $now;
            $DB->update_record('local_kbacompany_member', $row);
        }
    }

    private static function normalize_roles(array $roles): array {
        // Bitrix/KBA Corporate role codes are learning/access roles of another bounded context.
        // They must never silently grant BMS owner/manager/HR permissions. Explicit BMS access
        // roles remain governed inside local_kbacompany. The parameter is retained for API
        // compatibility and future explicitly namespaced BMS-role mappings.
        return ['employee' => true, 'owner' => false, 'manager' => false,
            'department_manager' => false, 'hr' => false];
    }

    private static function apply_roles(\stdClass $row, array $roles): void {
        $row->membertype = !empty($roles['owner']) ? 'owner' : 'employee';
        $row->isemployee = 1;
        $row->ismanager = !empty($roles['manager']) ? 1 : 0;
        $row->isdepartmentmanager = !empty($roles['department_manager']) ? 1 : 0;
        $row->ishr = !empty($roles['hr']) ? 1 : 0;
    }

    private static function roles(\stdClass $row): array {
        $roles = [];
        if ((string)$row->membertype === 'owner') { $roles[] = 'owner'; }
        if (!empty($row->isemployee)) { $roles[] = 'employee'; }
        if (!empty($row->ismanager)) { $roles[] = 'manager'; }
        if (!empty($row->isdepartmentmanager)) { $roles[] = 'department_manager'; }
        if (!empty($row->ishr)) { $roles[] = 'hr'; }
        return $roles;
    }

    /** Legacy compatibility filter for rows that cannot be searched server-side. */
    private static function matches_employee_query($row, string $query): bool {
        $haystack = \core_text::strtolower(trim(
            (string)($row->firstname ?? '') . ' ' .
            (string)($row->lastname ?? '') . ' ' .
            (string)($row->email ?? '') . ' ' .
            (string)($row->phone1 ?? '')
        ));
        return \core_text::strpos($haystack, $query) !== false;
    }

    private static function current_positions(string $companyuuid): array {
        global $DB;
        $now = time();
        $rows = $DB->get_records_sql(
            "SELECT pa.id, pa.userid, p.uuid AS positionuuid, p.name, n.name AS unitname
               FROM {local_kbacompany_posassign} pa
               JOIN {local_kbacompany_position} p ON p.uuid = pa.positionuuid AND p.companyuuid = pa.companyuuid
          LEFT JOIN {local_kbacompany_orgnode} n ON n.uuid = p.orgnodeuuid AND n.companyuuid = p.companyuuid
              WHERE pa.companyuuid = :companyuuid AND pa.active = 1
                AND (pa.effectivefrom = 0 OR pa.effectivefrom <= :nowfrom)
                AND (pa.effectiveto = 0 OR pa.effectiveto >= :nowto)
           ORDER BY pa.assignmenttype = 'primary' DESC, pa.id DESC",
            ['companyuuid' => $companyuuid, 'nowfrom' => $now, 'nowto' => $now]);
        $map = [];
        foreach ($rows as $row) {
            if (!isset($map[(int)$row->userid])) {
                $map[(int)$row->userid] = ['uuid' => (string)$row->positionuuid,
                    'name' => (string)$row->name, 'unit' => (string)($row->unitname ?? '')];
            }
        }
        return $map;
    }

    private static function latest_outbox(string $companyuuid): array {
        global $DB;
        $rows = $DB->get_records_sql(
            "SELECT *
               FROM {local_kbacompany_syncout}
              WHERE companyuuid = :companyuuid
                AND commandkind IN (:upsertkind, :closekind)
           ORDER BY timecreated DESC, id DESC",
            ['companyuuid' => $companyuuid, 'upsertkind' => 'employee_upsert', 'closekind' => 'employee_close']);
        $map = [];
        foreach ($rows as $row) {
            if (!isset($map[(int)$row->userid])) { $map[(int)$row->userid] = $row; }
        }
        return $map;
    }

    /** Prefer a pending/error employee command over an older external mapping. */
    private static function projection_sync_status(string $contactid, ?\stdClass $sync): string {
        if ($sync && in_array((string)$sync->status, ['pending', 'error'], true)) {
            return (string)$sync->status;
        }
        if ($contactid !== '') {
            return 'synced';
        }
        return $sync ? (string)$sync->status : 'not_synced';
    }


    /** Queue the canonical outbound employee projection for KBA Connect. */
    private static function queue_employee_upsert(array $company, string $companyuuid, \stdClass $user): string {
        $companyexternal = integration_service::external_id(
            integration_service::BITRIX_PROVIDER, 'company', $companyuuid);
        $payload = [
            'company_uuid' => $companyuuid,
            'external_company_id' => $companyexternal,
            'legacy_corporate_company_id' => (int)($company['compatibility']['source_id'] ?? 0),
            'moodle_user_id' => (int)$user->id,
            'external_contact_id' => integration_service::external_id(
                integration_service::BITRIX_PROVIDER, 'user', (string)$user->id),
            'firstname' => (string)$user->firstname,
            'lastname' => (string)$user->lastname,
            'email' => (string)$user->email,
            'phone' => self::safe_phone((string)$user->phone1),
            'required_result' => ['bitrix_contact', 'kba_corporate_member'],
        ];
        return integration_service::enqueue('employee_upsert', $companyuuid, (int)$user->id, $payload);
    }

    /** Never propagate an email-like legacy value as a telephone number. */
    private static function safe_phone(string $value): string {
        $value = trim($value);
        if ($value === '' || validate_email($value)) {
            return '';
        }
        return $value;
    }

    private static function unique_username(string $email): string {
        global $DB;
        $base = clean_param(\core_text::strtolower($email), PARAM_USERNAME);
        if ($base === '') { $base = 'bmsuser'; }
        $candidate = $base; $i = 1;
        while ($DB->record_exists('user', ['username' => $candidate])) {
            $candidate = \core_text::substr($base, 0, 90) . '_' . $i;
            $i++;
        }
        return $candidate;
    }

    private static function uuid_v4(): string {
        $bytes = random_bytes(16);
        $bytes[6] = chr((ord($bytes[6]) & 0x0f) | 0x40);
        $bytes[8] = chr((ord($bytes[8]) & 0x3f) | 0x80);
        $hex = bin2hex($bytes);
        return substr($hex, 0, 8) . '-' . substr($hex, 8, 4) . '-' . substr($hex, 12, 4) . '-' .
            substr($hex, 16, 4) . '-' . substr($hex, 20, 12);
    }
}
