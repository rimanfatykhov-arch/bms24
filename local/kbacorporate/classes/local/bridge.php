<?php
namespace local_kbacorporate\local;

defined('MOODLE_INTERNAL') || die();

/**
 * Stable public integration API for optional external Moodle plugins.
 *
 * BMS Connect and other integrations must use this bridge instead of reading or
 * writing local_kbacorporate database tables directly. Public methods in API v1
 * are backwards-compatible for the lifetime of the v1 contract.
 */
class bridge {
    /** Public API major version. */
    public const API_VERSION = 1;

    /** Human-readable public API release. */
    public const API_RELEASE = '1.26';

    /** Component name exposed to integrations. */
    public const COMPONENT = 'local_kbacorporate';

    /**
     * Return the machine-readable public API contract.
     *
     * @return array
     */
    public static function contract(): array {
        return [
            'component' => self::COMPONENT,
            'api_version' => self::API_VERSION,
            'api_release' => self::API_RELEASE,
            'backwards_compatible' => true,
            'methods' => [
                'contract' => ['type' => 'read', 'since' => '1.0'],
                'list_implementation_task_projections' => ['type' => 'read', 'since' => '1.22',
                    'company_scope' => 'explicit', 'read_only' => true, 'max_limit' => 100],
                'get_implementation_task_projection' => ['type' => 'read', 'since' => '1.22',
                    'company_scope' => 'explicit', 'read_only' => true],
                'list_implementation_program_projections' => ['type' => 'read', 'since' => '1.23',
                    'company_scope' => 'explicit', 'read_only' => true, 'max_limit' => 100],
                'get_implementation_program_projection' => ['type' => 'read', 'since' => '1.23',
                    'company_scope' => 'explicit', 'read_only' => true],
                'get_implementation_program_sections' => ['type' => 'read', 'since' => '1.24',
                    'company_scope' => 'explicit', 'read_only' => true],
                'get_implementation_program_task_sections' => ['type' => 'read', 'since' => '1.25',
                    'company_scope' => 'explicit', 'read_only' => true, 'max_tasks' => 5000],
                'get_implementation_program_result_statuses' => ['type' => 'read', 'since' => '1.26',
                    'company_scope' => 'explicit', 'read_only' => true, 'max_tasks' => 5000],
                'status' => ['type' => 'read', 'since' => '1.0'],
                'get_company_by_bitrix_id' => ['type' => 'read', 'since' => '1.0'],
                'sync_company' => ['type' => 'write', 'since' => '1.0'],
                'sync_employee' => ['type' => 'write', 'since' => '1.0'],
                'sync_member' => ['type' => 'write', 'since' => '0.4.2', 'alias_of' => 'sync_employee'],
                'remove_employee' => ['type' => 'write', 'since' => '1.0'],
                'assign_course' => ['type' => 'write', 'since' => '1.0'],
                'sync_training_assignment' => ['type' => 'write', 'since' => '1.2'],
                'sync_company_courses' => ['type' => 'write', 'since' => '1.2'],
                'unassign_course' => ['type' => 'write', 'since' => '1.2'],
                'get_company_snapshot' => ['type' => 'read', 'since' => '1.1'],
                'list_company_snapshots' => ['type' => 'read', 'since' => '1.1'],
                'get_training_time_snapshot' => ['type' => 'read', 'since' => '1.3'],
                'get_webinar_analytics_snapshot' => ['type' => 'read', 'since' => '1.4'],
                'get_certificate_registry_snapshot' => ['type' => 'read', 'since' => '1.5'],
                'get_management_report_snapshot' => ['type' => 'read', 'since' => '1.6'],
                'get_company_structure_snapshot' => ['type' => 'read', 'since' => '1.7'],
                'get_attention_snapshot' => ['type' => 'read', 'since' => '1.8'],
                'sync_course_license' => ['type' => 'write', 'since' => '1.9'],
                'get_license' => ['type' => 'read', 'since' => '1.9'],
                'assign_license_user' => ['type' => 'write', 'since' => '1.9'],
                'release_license_user' => ['type' => 'write', 'since' => '1.9'],
                'get_license_snapshot' => ['type' => 'read', 'since' => '1.9'],
                'get_module_info' => ['type' => 'read', 'since' => '1.10'],
                'get_bitrix_role_schema' => ['type' => 'read', 'since' => '1.10'],
                'get_kba_connect_pairing_contract' => ['type' => 'read', 'since' => '1.11'],
                'get_health_status' => ['type' => 'read', 'since' => '1.12'],
                'merge_employee_roles' => ['type' => 'write', 'since' => '1.13', 'strategy' => 'merge'],
                'get_business_role_catalog' => ['type' => 'read', 'since' => '1.14', 'company_scope' => 'explicit'],
                'assign_business_role' => ['type' => 'write', 'since' => '1.14'],
                'get_employee_target_profile' => ['type' => 'read', 'since' => '1.14', 'company_scope' => 'explicit_or_unique'],
                'get_erp_exchange_contract' => ['type' => 'read', 'since' => '1.16', 'transport' => 'external_service'],
                'get_erp_exchange_status' => ['type' => 'read', 'since' => '1.17', 'transport' => 'external_service'],
                'push_erp_entity' => ['type' => 'write', 'since' => '1.16', 'strategy' => 'upsert_and_merge'],
                'sync_erp_employee' => ['type' => 'write', 'since' => '1.18',
                    'strategy' => 'resolve_create_update_membership'],
                'sync_erp_department' => ['type' => 'write', 'since' => '1.19',
                    'strategy' => 'upsert_preserve_manual'],
                'sync_erp_department_membership' => ['type' => 'write', 'since' => '1.19',
                    'strategy' => 'assign_preserve_manual'],
                'sync_erp_business_role' => ['type' => 'write', 'since' => '1.19',
                    'strategy' => 'metadata_only_preserve_competencies'],
                'sync_erp_learning_catalog_assignment' => ['type' => 'write', 'since' => '1.20',
                    'strategy' => 'catalog_only_no_enrolment_or_plan_mutation'],
                'sync_erp_economic_value' => ['type' => 'write', 'since' => '1.20',
                    'strategy' => 'unverified_erp_value_upsert'],
                'get_erp_company_snapshot' => ['type' => 'read', 'since' => '1.16', 'pagination' => 'userid_cursor'],
            ],
        ];
    }

    /**
     * Versioned read-only implementation tasks for an already-authorized integration.
     * The caller MUST additionally validate its own BMS UUID/company/user permissions.
     *
     * @return array[] Sanitized task projections.
     */
    public static function list_implementation_task_projections(int $corporatecompanyid, int $actorid,
            int $targetuserid, string $scope = 'mine', int $limit = 50, int $offset = 0): array {
        return implementation_task_projection::list_tasks($corporatecompanyid, $actorid,
            $targetuserid, $scope, $limit, $offset);
    }

    /** Return one task from the same explicit Corporate company, or null. */
    public static function get_implementation_task_projection(int $corporatecompanyid, int $actorid,
            int $taskid): ?array {
        return implementation_task_projection::get_task($corporatecompanyid, $actorid, $taskid);
    }

    /** Published program snapshot; Corporate verifies manager's company access. */
    public static function list_implementation_program_projections(int $corporatecompanyid, int $actorid,
            int $limit = 50, int $offset = 0): array {
        return implementation_program_projection::list_programs($corporatecompanyid, $actorid, $limit, $offset);
    }

    /** One paid program, or null when missing/not visible in the authorised company. */
    public static function get_implementation_program_projection(int $corporatecompanyid, int $actorid,
            int $programid): ?array {
        return implementation_program_projection::get_program($corporatecompanyid, $actorid, $programid);
    }

    /** Sanitized section summaries from one authorized paid program's confirmed audit. */
    public static function get_implementation_program_sections(int $corporatecompanyid, int $actorid,
            int $programid): ?array {
        return implementation_program_sections_projection::get($corporatecompanyid, $actorid, $programid);
    }

    /** Strict, read-only task-to-section mapping after Corporate company and program authorization. */
    public static function get_implementation_program_task_sections(int $corporatecompanyid, int $actorid,
            int $programid): ?array {
        return implementation_program_task_sections_projection::get($corporatecompanyid, $actorid, $programid);
    }

    /** Latest evidence decision only, without evidence body, attachments or comments. */
    public static function get_implementation_program_result_statuses(int $corporatecompanyid, int $actorid,
            int $programid): ?array {
        return implementation_program_result_projection::get($corporatecompanyid, $actorid, $programid);
    }

    /** Return the provider-neutral ERP exchange contract. */
    public static function get_erp_exchange_contract(): array {
        return erp_exchange::contract();
    }

    /** Return provider-neutral ERP exchange readiness and contract fingerprint. */
    public static function get_erp_exchange_status(): array {
        return erp_exchange::status();
    }

    /** Idempotently push one provider-neutral ERP entity. */
    public static function push_erp_entity(string $system, string $entitytype, string $externalid,
            array $payload, string $idempotencykey = ''): array {
        return erp_exchange::push_entity($system, $entitytype, $externalid, $payload, $idempotencykey);
    }

    /** Synchronise one ERP employee through the stable generic exchange boundary. */
    public static function sync_erp_employee(string $system, string $externalid,
            array $payload, string $idempotencykey = ''): array {
        return erp_exchange::push_entity($system, 'employee', $externalid, $payload, $idempotencykey);
    }

    /** Synchronise one ERP department through the stable exchange boundary. */
    public static function sync_erp_department(string $system, string $externalid,
            array $payload, string $idempotencykey = ''): array {
        return erp_exchange::push_entity($system, 'department', $externalid, $payload, $idempotencykey);
    }

    /** Synchronise one employee placement in an ERP department. */
    public static function sync_erp_department_membership(string $system, string $externalid,
            array $payload, string $idempotencykey = ''): array {
        return erp_exchange::push_entity(
            $system, 'department_membership', $externalid, $payload, $idempotencykey
        );
    }

    /** Synchronise company business-role metadata without changing its competency profile. */
    public static function sync_erp_business_role(string $system, string $externalid,
            array $payload, string $idempotencykey = ''): array {
        return erp_exchange::push_entity($system, 'business_role', $externalid, $payload, $idempotencykey);
    }

    /** Synchronise a company learning-catalog entry without enrolment or Learning Plan changes. */
    public static function sync_erp_learning_catalog_assignment(string $system, string $externalid,
            array $payload, string $idempotencykey = ''): array {
        return erp_exchange::push_entity(
            $system, 'learning_catalog_assignment', $externalid, $payload, $idempotencykey
        );
    }

    /** Synchronise one unverified ERP economic value. */
    public static function sync_erp_economic_value(string $system, string $externalid,
            array $payload, string $idempotencykey = ''): array {
        return erp_exchange::push_entity($system, 'economic_value', $externalid, $payload, $idempotencykey);
    }

    /** Return one page of a provider-neutral company snapshot. */
    public static function get_erp_company_snapshot(string $system, string $companyexternalid,
            int $afteruserid = 0, int $limit = 100): array {
        return erp_exchange::company_snapshot($system, $companyexternalid, $afteruserid, $limit);
    }


    /**
     * Return module/runtime information for integration compatibility checks.
     *
     * @return array
     */
    public static function get_module_info(): array {
        global $CFG;

        $diskinfo = (static function(): \stdClass {
            $plugin = new \stdClass();
            require(__DIR__ . '/../../version.php');
            return $plugin;
        })();

        return [
            'component' => self::COMPONENT,
            'release' => isset($diskinfo->release) ? (string)$diskinfo->release : '',
            'build' => isset($diskinfo->version) ? (int)$diskinfo->version : 0,
            'dbbuild' => (int)(get_config('local_kbacorporate', 'version') ?: 0),
            'api_version' => self::API_VERSION,
            'api_release' => self::API_RELEASE,
            'moodle' => isset($CFG->release) ? (string)$CFG->release : '',
            'php' => PHP_VERSION,
        ];
    }

    /**
     * Desired Bitrix24 Contact user-field schema for corporate learning roles.
     *
     * KBA Connect is responsible for translating this declarative contract into
     * Bitrix24 REST calls. Corporate Dashboard never receives Bitrix credentials.
     *
     * @return array
     */
    /**
     * KBA Connect pairing contract used by the administrator UI.
     *
     * @return array
     */
    public static function get_kba_connect_pairing_contract(): array {
        return [
            'contract_version' => 1,
            'pair_endpoint' => '/corporate/pair',
            'status_endpoint' => '/corporate/connection/status',
            'schema_status_endpoint' => '/corporate/schema/status',
            'schema_provision_endpoint' => '/corporate/schema/provision',
            'authentication' => 'bearer_after_pairing',
            'pairing_code_persisted' => false,
        ];
    }

    /** Return current Integration Health Center state without writing history. */
    public static function get_health_status(): array {
        return health::check(false);
    }

    public static function get_bitrix_role_schema(): array {
        return [
            'schema_version' => 1,
            'entity' => 'contact',
            'logical_key' => 'kba_corporate_roles',
            'label_ru' => 'KBA — Роли корпоративного обучения',
            'label_en' => 'KBA — Corporate learning roles',
            'type' => 'enumeration',
            'multiple' => true,
            'required' => false,
            'values' => [
                ['xml_id' => 'employee', 'label_ru' => 'Сотрудник', 'label_en' => 'Employee', 'sort' => 100],
                ['xml_id' => 'corporate_manager', 'label_ru' => 'Корпоративный менеджер', 'label_en' => 'Corporate manager', 'sort' => 200],
                ['xml_id' => 'department_manager', 'label_ru' => 'Руководитель подразделения', 'label_en' => 'Department manager', 'sort' => 300],
                ['xml_id' => 'hr', 'label_ru' => 'HR', 'label_en' => 'HR', 'sort' => 400],
            ],
        ];
    }

    /**
     * Read-only bridge and schema diagnostics.
     *
     * The method is intentionally defensive: diagnostics should remain callable
     * even when a table or field is missing after a failed/partial upgrade.
     *
     * @return array
     */
    public static function status(): array {
        global $DB;

        $schema = self::schema_status();
        $tablesok = !empty($schema['ok']);
        $counts = [
            'companies' => 0,
            'members' => 0,
            'managers' => 0,
            'courseassignments' => 0,
            'inactivecourseassignments' => 0,
            'departments' => 0,
            'departmentmanagers' => 0,
            'alertsenabledcompanies' => 0,
            'licenses' => 0,
            'licenseallocations' => 0,
            'activelicenses' => 0,
        ];

        if (!empty($schema['tables']['local_kbacorp_company']['exists'])) {
            $counts['companies'] = (int)$DB->count_records('local_kbacorp_company');
            if (!empty($schema['tables']['local_kbacorp_company']['fields']['alertsenabled'])) {
                $counts['alertsenabledcompanies'] = (int)$DB->count_records('local_kbacorp_company', [
                    'active' => 1,
                    'alertsenabled' => 1,
                ]);
            }
        }
        if (!empty($schema['tables']['local_kbacorp_member']['exists'])) {
            $memberfields = $schema['tables']['local_kbacorp_member']['fields'] ?? [];
            if (!empty($memberfields['isemployee']) && !empty($memberfields['ismanager'])) {
                $counts['members'] = (int)$DB->count_records('local_kbacorp_member', ['isemployee' => 1]);
                $counts['managers'] = (int)$DB->count_records('local_kbacorp_member', ['ismanager' => 1]);
            } else {
                // Pre-v0.14.2.1 fallback keeps diagnostics callable before an
                // administrator has completed the database upgrade.
                $counts['members'] = (int)$DB->count_records('local_kbacorp_member', ['membertype' => 'employee']);
                $counts['managers'] = (int)$DB->count_records('local_kbacorp_member', ['membertype' => 'manager']);
            }
        }
        if (!empty($schema['tables']['local_kbacorp_course']['exists'])) {
            $counts['courseassignments'] = (int)$DB->count_records('local_kbacorp_course', ['active' => 1]);
            $counts['inactivecourseassignments'] = (int)$DB->count_records('local_kbacorp_course', ['active' => 0]);
        }
        if (!empty($schema['tables']['local_kbacorp_department']['exists'])) {
            $counts['departments'] = (int)$DB->count_records('local_kbacorp_department', ['active' => 1]);
        }
        if (!empty($schema['tables']['local_kbacorp_dept_member']['exists'])) {
            $counts['departmentmanagers'] = (int)$DB->count_records('local_kbacorp_dept_member', ['deptrole' => 'manager']);
        }
        if (!empty($schema['tables']['local_kbacorp_license']['exists'])) {
            $counts['licenses'] = (int)$DB->count_records('local_kbacorp_license');
            $counts['activelicenses'] = (int)$DB->count_records('local_kbacorp_license', ['status' => 'active']);
        }
        if (!empty($schema['tables']['local_kbacorp_license_user']['exists'])) {
            $counts['licenseallocations'] = (int)$DB->count_records_select(
                'local_kbacorp_license_user',
                'status <> :released',
                ['released' => 'released']
            );
        }

        $duplicatecount = self::count_duplicate_bitrix_companies($schema);
        $duplicatemembers = self::count_duplicate_members($schema);
        $duplicatelicenserefs = self::count_duplicate_license_refs($schema);
        $attentionavailable = $tablesok &&
            !empty($schema['tables']['local_kbacorp_company']['fields']['alertsenabled']) &&
            !empty($schema['tables']['local_kbacorp_company']['fields']['alertfrequency']) &&
            !empty($schema['tables']['local_kbacorp_company']['fields']['lastalertsent']);
        $erpservice = $DB->get_record('external_services', ['shortname' => erp_exchange::SERVICE_SHORTNAME]);
        $erpcontract = erp_exchange::contract();

        return [
            'enabled' => $tablesok,
            'success' => $tablesok,
            'component' => self::COMPONENT,
            'api_version' => self::API_VERSION,
            'api_release' => self::API_RELEASE,
            'erpexchangeversion' => erp_exchange::CONTRACT_VERSION,
            'erpexchangerelease' => erp_exchange::CONTRACT_RELEASE,
            'erpcontractstability' => erp_exchange::CONTRACT_STABILITY,
            'erpcontracthash' => $erpcontract['schema_hash'],
            'erpserviceinstalled' => (bool)$erpservice,
            'erpserviceenabled' => $erpservice && !empty($erpservice->enabled),
            'pluginversion' => (int)(get_config('local_kbacorporate', 'version') ?: 0),
            'schemaok' => $tablesok,
            'schema' => $schema,
            'companies' => $counts['companies'],
            'members' => $counts['members'],
            'managers' => $counts['managers'],
            'courseassignments' => $counts['courseassignments'],
            'inactivecourseassignments' => $counts['inactivecourseassignments'],
            'departments' => $counts['departments'],
            'departmentmanagers' => $counts['departmentmanagers'],
            'alertsenabledcompanies' => $counts['alertsenabledcompanies'],
            'licenses' => $counts['licenses'],
            'activelicenses' => $counts['activelicenses'],
            'licenseallocations' => $counts['licenseallocations'],
            'licenseavailable' => licenses::is_available(),
            'attentionavailable' => $attentionavailable,
            'duplicatebitrixcompanies' => $duplicatecount,
            'duplicatemembers' => $duplicatemembers,
            'duplicatelicenserefs' => $duplicatelicenserefs,
            'writesafe' => $tablesok && $duplicatecount === 0 && $duplicatemembers === 0 && $duplicatelicenserefs === 0,
            'bigbluebuttonavailable' => bigbluebutton::is_available(),
            'customcertavailable' => customcert::is_available(),
            'kbaconnectconfigured' => trim((string)get_config('local_kbacorporate', 'kbaconnectbaseurl')) !== '' && trim((string)get_config('local_kbacorporate', 'kbaconnecttoken')) !== '',
            'bitrixrolefieldcode' => (string)get_config('local_kbacorporate', 'bitrixrolefieldcode'),
            'bitrixroleschemastatus' => (string)get_config('local_kbacorporate', 'bitrixroleschemastatus'),
        ];
    }

    /**
     * Return a corporate company by Bitrix24 Company ID without exposing DB rows.
     *
     * @param int $bitrixcompanyid
     * @return array
     */
    public static function get_company_by_bitrix_id(int $bitrixcompanyid): array {
        if ($bitrixcompanyid <= 0) {
            throw new \moodle_exception('bridgeinvalidcompanyid', 'local_kbacorporate');
        }

        $company = repository::get_company_by_bitrix_id($bitrixcompanyid);
        if (!$company) {
            return [
                'success' => true,
                'found' => false,
                'bitrixcompanyid' => $bitrixcompanyid,
            ];
        }

        return [
            'success' => true,
            'found' => true,
        ] + self::company_to_public($company);
    }

    /**
     * Create or update a company identified by Bitrix24 Company ID.
     *
     * @param int $bitrixcompanyid
     * @param string $companytitle
     * @return array
     */
    public static function sync_company(int $bitrixcompanyid, string $companytitle): array {
        self::validate_company_parameters($bitrixcompanyid, $companytitle);
        $companytitle = trim($companytitle);

        $lock = self::get_company_lock($bitrixcompanyid);
        try {
            $companyresult = repository::ensure_company_by_bitrix_id($bitrixcompanyid, $companytitle);
            return [
                'success' => true,
                'created' => !empty($companyresult['created']),
                'changed' => !empty($companyresult['changed']),
            ] + self::company_to_public($companyresult['company']);
        } finally {
            $lock->release();
        }
    }

    /**
     * Ensure that a Moodle user is an employee of a Bitrix24 company.
     *
     * This is the canonical API v1 method. The legacy sync_member() method below
     * remains as a backwards-compatible alias for existing BMS Connect builds.
     *
     * @param int $userid Moodle user ID.
     * @param int $bitrixcompanyid Bitrix24 Company ID from Deal.COMPANY_ID.
     * @param string $companytitle Bitrix24 company TITLE.
     * @param int $bitrixcontactid Optional Bitrix24 Contact ID (not persisted yet).
     * @return array
     */
    /**
     * Additively merge normalised KBA corporate rolecodes into one membership.
     *
     * The public contract accepts machine rolecodes only. Existing/manual roles
     * are never removed in API 1.13.
     *
     * @param int $userid Moodle user ID.
     * @param int $companyid Local Corporate Dashboard company ID.
     * @param array $roles Incoming machine rolecodes.
     * @return array Structured merge result.
     */
    public static function merge_employee_roles(int $userid, int $companyid, array $roles): array {
        global $DB;

        self::validate_user($userid);
        if ($companyid <= 0 || !$DB->record_exists('local_kbacorp_company', ['id' => $companyid])) {
            return [
                'success' => false,
                'status' => 'company_not_found',
                'strategy' => 'merge',
                'userid' => $userid,
                'companyid' => $companyid,
            ];
        }

        $allowed = ['employee', 'corporate_manager', 'department_manager', 'hr'];
        $incoming = [];
        $ignored = [];

        foreach ($roles as $role) {
            if (!is_scalar($role)) {
                $ignored[] = '[non-scalar]';
                continue;
            }
            $normalised = strtolower(trim((string)$role));
            if ($normalised === '') {
                continue;
            }
            if (in_array($normalised, $allowed, true)) {
                $incoming[] = $normalised;
            } else {
                $ignored[] = $normalised;
            }
        }

        $incoming = array_values(array_unique($incoming));
        $ignored = array_values(array_unique($ignored));

        $result = repository::merge_member_roles($companyid, $userid, $incoming);
        $result['strategy'] = 'merge';
        $result['incoming_roles'] = $incoming;
        $result['ignored_roles'] = $ignored;

        // A successful/no-op merge is a normal result, not a developer error.
        // Return the structured role outcome; ERP calls retain their privacy-safe audit.

        return $result;
    }


    /** Return active KBA business roles and their competency requirements. */
    public static function get_business_role_catalog(int $companyid = 0): array {
        global $DB;
        if (!business_roles::available()) {
            return ['available' => false, 'roles' => []];
        }
        $params = [];
        $where = 'active = 1 AND companyid = 0';
        if ($companyid > 0) {
            $where = 'active = 1 AND (companyid = 0 OR companyid = :companyid)';
            $params['companyid'] = $companyid;
        }
        $roles = $DB->get_records_select('local_kbacorp_busrole', $where, $params, 'name ASC');
        $result = [];
        foreach ($roles as $role) {
            $requirements = $DB->get_records_sql(
                "SELECT rc.competencyid, rc.targetlevel, rc.priority, rc.required,
                        c.idnumber, c.shortname
                   FROM {local_kbacorp_rolecomp} rc
                   JOIN {competency} c ON c.id = rc.competencyid
                  WHERE rc.roleid = :roleid
               ORDER BY c.shortname",
                ['roleid' => (int)$role->id]
            );
            $result[] = [
                'id' => (int)$role->id,
                'companyid' => (int)$role->companyid,
                'code' => (string)$role->code,
                'uuid' => (string)$role->uuid,
                'name' => (string)$role->name,
                'requirements' => array_values(array_map(static function($item) {
                    return [
                        'competencyid' => (int)$item->competencyid,
                        'competencycode' => (string)$item->idnumber,
                        'name' => (string)$item->shortname,
                        'targetlevel' => (int)$item->targetlevel,
                        'priority' => (string)$item->priority,
                        'required' => !empty($item->required),
                    ];
                }, $requirements)),
            ];
        }
        return ['available' => true, 'roles' => $result];
    }

    /** Assign a canonical KBA business role to a company member. */
    public static function assign_business_role(int $userid, int $companyid, string $rolecode,
            string $source = 'manual', string $externalref = ''): array {
        global $DB;
        self::validate_user($userid);
        $rolecode = business_roles::normalise_code($rolecode);
        $role = $DB->get_record('local_kbacorp_busrole', [
            'companyid' => $companyid,
            'code' => $rolecode,
            'active' => 1,
        ]);
        if (!$role) {
            $role = $DB->get_record('local_kbacorp_busrole', [
                'companyid' => 0,
                'code' => $rolecode,
                'active' => 1,
            ]);
        }
        if (!$role) {
            return [
                'success' => false,
                'status' => 'business_role_not_found',
                'userid' => $userid,
                'companyid' => $companyid,
                'rolecode' => $rolecode,
            ];
        }
        $assignmentid = business_roles::assign_user_role(
            $companyid, $userid, (int)$role->id, $source, $externalref
        );
        return [
            'success' => true,
            'status' => 'assigned',
            'assignmentid' => $assignmentid,
            'userid' => $userid,
            'companyid' => $companyid,
            'roleid' => (int)$role->id,
            'rolecode' => (string)$role->code,
        ];
    }

    /** Return the aggregated target profile for an employee's active business roles. */
    public static function get_employee_target_profile(int $userid, int $companyid = 0): array {
        global $DB;
        self::validate_user($userid);
        if ($companyid <= 0) {
            $companyids = $DB->get_fieldset_sql(
                "SELECT m.companyid
                   FROM {local_kbacorp_member} m
                   JOIN {local_kbacorp_company} c ON c.id = m.companyid
                  WHERE m.userid = :userid
                    AND m.isemployee = 1
                    AND c.active = 1",
                ['userid' => $userid]
            );
            $companyids = array_values(array_unique(array_map('intval', $companyids)));
            if (!$companyids) {
                return [
                    'success' => false,
                    'status' => 'membership_not_found',
                    'userid' => $userid,
                    'companyid' => 0,
                    'competencies' => [],
                ];
            }
            if (count($companyids) > 1) {
                return [
                    'success' => false,
                    'status' => 'company_context_required',
                    'userid' => $userid,
                    'companyid' => 0,
                    'companyids' => $companyids,
                    'competencies' => [],
                ];
            }
            $companyid = reset($companyids);
        } else if (!$DB->record_exists_sql(
            "SELECT m.id
               FROM {local_kbacorp_member} m
               JOIN {local_kbacorp_company} c ON c.id = m.companyid
              WHERE m.userid = :userid
                AND m.companyid = :companyid
                AND m.isemployee = 1
                AND c.active = 1",
            ['userid' => $userid, 'companyid' => $companyid]
        )) {
            return [
                'success' => false,
                'status' => 'membership_not_found',
                'userid' => $userid,
                'companyid' => $companyid,
                'competencies' => [],
            ];
        }
        $profile = target_profile::for_user($userid, $companyid);
        $items = [];
        foreach ($profile as $item) {
            $items[] = [
                'competencyid' => (int)$item->competencyid,
                'competencycode' => (string)$item->idnumber,
                'name' => (string)$item->shortname,
                'targetlevel' => (int)$item->targetlevel,
                'priority' => (string)$item->priority,
                'required' => (bool)$item->required,
                'roles' => array_map(static function($role) {
                    return [
                        'id' => (int)$role->id,
                        'code' => (string)$role->code,
                        'name' => (string)$role->name,
                        'targetlevel' => (int)$role->targetlevel,
                    ];
                }, $item->roles),
            ];
        }
        return [
            'success' => true,
            'status' => 'ok',
            'userid' => $userid,
            'companyid' => $companyid,
            'competencies' => $items,
        ];
    }

    public static function sync_employee(
        int $userid,
        int $bitrixcompanyid,
        string $companytitle,
        int $bitrixcontactid = 0
    ): array {
        self::validate_user($userid);
        self::validate_company_parameters($bitrixcompanyid, $companytitle);
        $companytitle = trim($companytitle);

        $lock = self::get_company_lock($bitrixcompanyid);
        try {
            $companyresult = repository::ensure_company_by_bitrix_id($bitrixcompanyid, $companytitle);
            $company = $companyresult['company'];

            $existingmember = repository::get_member((int)$company->id, $userid, 'employee');
            $membershipid = repository::add_member((int)$company->id, $userid, 'employee');
            $membercreated = !$existingmember;

            return [
                'skipped' => false,
                'success' => true,
                'companyid' => (int)$company->id,
                'companyname' => (string)$company->name,
                'companycreated' => !empty($companyresult['created']),
                'membershipid' => (int)$membershipid,
                // Legacy key used by existing BMS Connect builds.
                'created' => $membercreated,
                // Explicit API v1 key.
                'membershipcreated' => $membercreated,
                'changed' => !empty($companyresult['changed']) || $membercreated,
                'membertype' => 'employee',
                'bitrixcompanyid' => $bitrixcompanyid,
                'bitrixcontactid' => max(0, $bitrixcontactid),
            ];
        } finally {
            $lock->release();
        }
    }

    /**
     * Backwards-compatible alias used by BMS Connect v0.x integrations.
     *
     * @param int $userid
     * @param int $bitrixcompanyid
     * @param string $companytitle
     * @param int $bitrixcontactid
     * @return array
     */
    public static function sync_member(
        int $userid,
        int $bitrixcompanyid,
        string $companytitle,
        int $bitrixcontactid = 0
    ): array {
        return self::sync_employee($userid, $bitrixcompanyid, $companytitle, $bitrixcontactid);
    }

    /**
     * Remove an employee relation from a Bitrix24 company.
     *
     * The company itself is never deleted by this call.
     *
     * @param int $userid
     * @param int $bitrixcompanyid
     * @return array
     */
    public static function remove_employee(int $userid, int $bitrixcompanyid): array {
        self::validate_user($userid);
        if ($bitrixcompanyid <= 0) {
            throw new \moodle_exception('bridgeinvalidcompanyid', 'local_kbacorporate');
        }

        $lock = self::get_company_lock($bitrixcompanyid);
        try {
            $company = repository::get_company_by_bitrix_id($bitrixcompanyid);
            if (!$company) {
                return [
                    'success' => true,
                    'changed' => false,
                    'companyfound' => false,
                    'membershipremoved' => false,
                    'bitrixcompanyid' => $bitrixcompanyid,
                    'userid' => $userid,
                ];
            }

            $member = repository::get_member((int)$company->id, $userid, 'employee');
            if (!$member) {
                return [
                    'success' => true,
                    'changed' => false,
                    'companyfound' => true,
                    'companyid' => (int)$company->id,
                    'membershipremoved' => false,
                    'bitrixcompanyid' => $bitrixcompanyid,
                    'userid' => $userid,
                ];
            }

            repository::remove_member_role((int)$member->id, (int)$company->id, 'employee');
            return [
                'success' => true,
                'changed' => true,
                'companyfound' => true,
                'companyid' => (int)$company->id,
                'membershipremoved' => true,
                'bitrixcompanyid' => $bitrixcompanyid,
                'userid' => $userid,
            ];
        } finally {
            $lock->release();
        }
    }

    /**
     * Assign a Moodle course/group to a corporate company.
     *
     * This does not enrol users. Enrolment remains the responsibility of BMS
     * Connect/Moodle enrolment logic; the mapping only tells the corporate
     * dashboard which purchased/assigned courses belong to the company.
     *
     * @param int $bitrixcompanyid
     * @param string $companytitle
     * @param int $courseid
     * @param int $groupid Optional Moodle group, 0 means all company employees.
     * @return array
     */
    public static function assign_course(
        int $bitrixcompanyid,
        string $companytitle,
        int $courseid,
        int $groupid = 0
    ): array {
        self::validate_company_parameters($bitrixcompanyid, $companytitle);
        self::validate_course_group($courseid, $groupid);

        $lock = self::get_company_lock($bitrixcompanyid);
        try {
            $companyresult = repository::ensure_company_by_bitrix_id($bitrixcompanyid, trim($companytitle));
            $result = self::sync_course_mapping_for_company($companyresult['company'], $courseid, $groupid);
            $result['companycreated'] = !empty($companyresult['created']);
            $result['changed'] = !empty($companyresult['changed']) || !empty($result['changed']);
            return $result;
        } finally {
            $lock->release();
        }
    }


    /**
     * Create or update one Bitrix24 corporate course licence package.
     *
     * Bitrix24 remains the source of truth for purchased quantity and contract
     * dates. Corporate Dashboard stores the package and calculates seat use.
     *
     * @param int $bitrixcompanyid
     * @param string $companytitle
     * @param int $courseid
     * @param int $groupid
     * @param int $seats Zero means unlimited.
     * @param int $datefrom Unix timestamp, zero means no lower boundary.
     * @param int $dateto Unix timestamp, zero means no upper boundary.
     * @param string $externaldealid Bitrix24 Deal ID.
     * @param string $externalrowid Bitrix24 product-row ID.
     * @return array
     */
    public static function sync_course_license(
        int $bitrixcompanyid,
        string $companytitle,
        int $courseid,
        int $groupid,
        int $seats,
        int $datefrom = 0,
        int $dateto = 0,
        string $externaldealid = '',
        string $externalrowid = ''
    ): array {
        global $DB;

        self::validate_company_parameters($bitrixcompanyid, $companytitle);
        self::validate_course_group($courseid, $groupid);
        if ($seats < 0) {
            throw new \moodle_exception('licenseinvalidseats', 'local_kbacorporate');
        }
        if (!licenses::is_available()) {
            throw new \moodle_exception('licenseapiunavailable', 'local_kbacorporate');
        }

        $lock = self::get_company_lock($bitrixcompanyid);
        try {
            $transaction = $DB->start_delegated_transaction();
            $companyresult = repository::ensure_company_by_bitrix_id($bitrixcompanyid, trim($companytitle));
            $result = licenses::sync_external_license(
                (int)$companyresult['company']->id,
                $courseid,
                max(0, $groupid),
                $seats,
                max(0, $datefrom),
                max(0, $dateto),
                trim($externaldealid),
                trim($externalrowid)
            );
            $transaction->allow_commit();
            return [
                'success' => true,
                'changed' => !empty($companyresult['changed']) || !empty($result['changed']),
                'companycreated' => !empty($companyresult['created']),
                'created' => !empty($result['created']),
                'migratedallocations' => (int)$result['migratedallocations'],
                'bitrixcompanyid' => $bitrixcompanyid,
                'companyid' => (int)$companyresult['company']->id,
                'companyname' => (string)$companyresult['company']->name,
                'license' => licenses::to_public($result['license']),
            ];
        } finally {
            $lock->release();
        }
    }

    /**
     * Read one Bitrix24 licence package by external reference.
     *
     * @param int $bitrixcompanyid
     * @param string $externaldealid
     * @param string $externalrowid
     * @param int $courseid Optional fallback key when Product Row ID is absent.
     * @param int $groupid
     * @return array
     */
    public static function get_license(
        int $bitrixcompanyid,
        string $externaldealid,
        string $externalrowid = '',
        int $courseid = 0,
        int $groupid = 0
    ): array {
        if ($bitrixcompanyid <= 0) {
            throw new \moodle_exception('bridgeinvalidcompanyid', 'local_kbacorporate');
        }
        if (trim($externaldealid) === '' && trim($externalrowid) === '') {
            throw new \moodle_exception('licenseexternalrefrequired', 'local_kbacorporate');
        }
        if (!licenses::is_available()) {
            return [
                'success' => false,
                'found' => false,
                'available' => false,
                'bitrixcompanyid' => $bitrixcompanyid,
            ];
        }
        $company = repository::get_company_by_bitrix_id($bitrixcompanyid);
        if (!$company) {
            return [
                'success' => true,
                'found' => false,
                'companyfound' => false,
                'bitrixcompanyid' => $bitrixcompanyid,
            ];
        }
        $license = licenses::find_external_license(
            (int)$company->id,
            trim($externaldealid),
            trim($externalrowid),
            0,
            max(0, $courseid),
            max(0, $groupid)
        );
        if (!$license) {
            return [
                'success' => true,
                'found' => false,
                'companyfound' => true,
                'companyid' => (int)$company->id,
                'bitrixcompanyid' => $bitrixcompanyid,
            ];
        }
        if ($courseid > 0 && ((int)$license->courseid !== $courseid ||
                (int)$license->groupid !== max(0, $groupid))) {
            throw new \moodle_exception('licenseexternalcoursemismatch', 'local_kbacorporate');
        }
        $license = licenses::get_license((int)$license->id, (int)$company->id);
        return [
            'success' => true,
            'found' => true,
            'companyfound' => true,
            'bitrixcompanyid' => $bitrixcompanyid,
            'companyid' => (int)$company->id,
            'license' => licenses::to_public($license),
        ];
    }

    /**
     * Allocate one purchased seat to a Moodle employee.
     *
     * A specific Bitrix package is selected when an external reference is
     * supplied; otherwise the best available package for course/group is used.
     *
     * @param int $userid
     * @param int $bitrixcompanyid
     * @param int $courseid
     * @param int $groupid
     * @param string $externaldealid
     * @param string $externalrowid
     * @return array
     */
    public static function assign_license_user(
        int $userid,
        int $bitrixcompanyid,
        int $courseid,
        int $groupid = 0,
        string $externaldealid = '',
        string $externalrowid = ''
    ): array {
        global $DB;

        self::validate_user($userid);
        self::validate_course_group($courseid, $groupid);
        if (!licenses::is_available()) {
            throw new \moodle_exception('licenseapiunavailable', 'local_kbacorporate');
        }
        if ($bitrixcompanyid <= 0) {
            throw new \moodle_exception('bridgeinvalidcompanyid', 'local_kbacorporate');
        }
        $company = repository::get_company_by_bitrix_id($bitrixcompanyid);
        if (!$company) {
            return [
                'success' => false,
                'companyfound' => false,
                'bitrixcompanyid' => $bitrixcompanyid,
                'userid' => $userid,
            ];
        }

        $lock = self::get_company_lock($bitrixcompanyid);
        try {
            $transaction = $DB->start_delegated_transaction();
            $dealid = trim($externaldealid);
            $rowid = trim($externalrowid);
            if ($dealid !== '' || $rowid !== '') {
                $license = licenses::find_external_license(
                    (int)$company->id,
                    $dealid,
                    $rowid,
                    0,
                    $courseid,
                    $groupid
                );
                if (!$license) {
                    throw new \moodle_exception('licensenotfound', 'local_kbacorporate');
                }
                if ((int)$license->courseid !== $courseid || (int)$license->groupid !== max(0, $groupid)) {
                    throw new \moodle_exception('licenseexternalcoursemismatch', 'local_kbacorporate');
                }
                $result = licenses::assign_user((int)$license->id, $userid, 0);
            } else {
                $result = licenses::assign_best_available(
                    (int)$company->id,
                    $courseid,
                    max(0, $groupid),
                    $userid,
                    0
                );
            }
            $transaction->allow_commit();
            return [
                'success' => true,
                'companyfound' => true,
                'changed' => !empty($result['changed']),
                'created' => !empty($result['created']),
                'bitrixcompanyid' => $bitrixcompanyid,
                'companyid' => (int)$company->id,
                'userid' => $userid,
                'allocationid' => (int)$result['allocation']->id,
                'license' => licenses::to_public($result['license']),
            ];
        } finally {
            $lock->release();
        }
    }

    /**
     * Release an unused seat allocation. Started/completed training is retained.
     *
     * @param int $userid
     * @param int $bitrixcompanyid
     * @param int $courseid
     * @param int $groupid
     * @param string $externaldealid
     * @param string $externalrowid
     * @return array
     */
    public static function release_license_user(
        int $userid,
        int $bitrixcompanyid,
        int $courseid,
        int $groupid = 0,
        string $externaldealid = '',
        string $externalrowid = ''
    ): array {
        self::validate_user($userid);
        self::validate_course_group($courseid, $groupid);
        if (!licenses::is_available()) {
            throw new \moodle_exception('licenseapiunavailable', 'local_kbacorporate');
        }
        if ($bitrixcompanyid <= 0) {
            throw new \moodle_exception('bridgeinvalidcompanyid', 'local_kbacorporate');
        }
        $company = repository::get_company_by_bitrix_id($bitrixcompanyid);
        if (!$company) {
            return [
                'success' => true,
                'changed' => false,
                'companyfound' => false,
                'bitrixcompanyid' => $bitrixcompanyid,
                'userid' => $userid,
            ];
        }

        $lock = self::get_company_lock($bitrixcompanyid);
        try {
            $dealid = trim($externaldealid);
            $rowid = trim($externalrowid);
            if ($dealid !== '' || $rowid !== '') {
                $license = licenses::find_external_license(
                    (int)$company->id,
                    $dealid,
                    $rowid,
                    0,
                    max(0, $courseid),
                    max(0, $groupid)
                );
                if (!$license) {
                    return [
                        'success' => true,
                        'changed' => false,
                        'companyfound' => true,
                        'licensefound' => false,
                        'bitrixcompanyid' => $bitrixcompanyid,
                        'userid' => $userid,
                    ];
                }
                if ((int)$license->courseid !== $courseid || (int)$license->groupid !== max(0, $groupid)) {
                    throw new \moodle_exception('licenseexternalcoursemismatch', 'local_kbacorporate');
                }
                $licenseid = (int)$license->id;
            } else {
                $allocation = licenses::get_active_course_allocation(
                    (int)$company->id,
                    max(0, $courseid),
                    max(0, $groupid),
                    $userid
                );
                if (!$allocation) {
                    return [
                        'success' => true,
                        'changed' => false,
                        'companyfound' => true,
                        'licensefound' => true,
                        'allocationfound' => false,
                        'bitrixcompanyid' => $bitrixcompanyid,
                        'userid' => $userid,
                    ];
                }
                $licenseid = (int)$allocation->licenseid;
            }
            $result = licenses::release_user($licenseid, $userid);
            return [
                'success' => true,
                'changed' => !empty($result['changed']),
                'released' => !empty($result['released']),
                'companyfound' => true,
                'licensefound' => true,
                'allocationfound' => true,
                'bitrixcompanyid' => $bitrixcompanyid,
                'companyid' => (int)$company->id,
                'userid' => $userid,
                'license' => licenses::to_public($result['license']),
            ];
        } finally {
            $lock->release();
        }
    }

    /**
     * Return commercial contract, capacity and warning analytics for one company.
     *
     * @param int $bitrixcompanyid
     * @return array
     */
    public static function get_license_snapshot(int $bitrixcompanyid): array {
        if ($bitrixcompanyid <= 0) {
            throw new \moodle_exception('bridgeinvalidcompanyid', 'local_kbacorporate');
        }
        if (!licenses::is_available()) {
            return [
                'success' => false,
                'found' => false,
                'available' => false,
                'bitrixcompanyid' => $bitrixcompanyid,
                'licenses' => [],
            ];
        }
        $company = repository::get_company_by_bitrix_id($bitrixcompanyid);
        if (!$company) {
            return [
                'success' => true,
                'found' => false,
                'bitrixcompanyid' => $bitrixcompanyid,
                'available' => licenses::is_available(),
                'licenses' => [],
            ];
        }
        $rows = licenses::get_company_licenses((int)$company->id, true);
        $summary = licenses::summary($rows);
        $publicrows = [];
        foreach ($rows as $row) {
            $publicrows[] = licenses::to_public($row);
        }
        $alerts = [];
        foreach (licenses::alerts($rows) as $alert) {
            $alerts[] = [
                'licenseid' => (int)$alert->licenseid,
                'courseid' => (int)$alert->courseid,
                'coursename' => (string)$alert->coursename,
                'type' => (string)$alert->type,
                'severity' => (string)$alert->severity,
                'reason' => (string)$alert->reason,
                'used' => (int)$alert->used,
                'seats' => (int)$alert->seats,
                'available' => $alert->available === null ? null : (int)$alert->available,
                'daysleft' => $alert->daysleft === null ? null : (int)$alert->daysleft,
            ];
        }
        return [
            'success' => true,
            'found' => true,
            'available' => true,
            'bitrixcompanyid' => $bitrixcompanyid,
            'companyid' => (int)$company->id,
            'companyname' => (string)$company->name,
            'summary' => [
                'packages' => (int)$summary->licenses,
                'activepackages' => (int)$summary->activelicenses,
                'purchasedseats' => (int)$summary->totalseats,
                'usedseats' => (int)$summary->usedfinite,
                'allocatedseats' => (int)$summary->used,
                'unlimitedallocations' => (int)$summary->usedunlimited,
                'freeseats' => (int)$summary->free,
                'utilizationpercent' => $summary->utilizationpercent === null
                    ? null : (float)$summary->utilizationpercent,
                'unlimitedpackages' => (int)$summary->unlimitedlicenses,
                'fullpackages' => (int)$summary->fulllicenses,
                'overbookedpackages' => (int)$summary->overbookedlicenses,
                'expiringpackages' => (int)$summary->expiringlicenses,
                'expiredpackages' => (int)$summary->expiredlicenses,
            ],
            'alerts' => $alerts,
            'licenses' => $publicrows,
        ];
    }

    /**
     * Atomically synchronise the complete BMS corporate training assignment.
     *
     * One idempotent call ensures the Bitrix24 company, Moodle employee relation
     * and corporate course/group mapping. Moodle course enrolment itself remains
     * BMS Connect's responsibility. Repeating the same webhook does not create
     * duplicate companies, members or course mappings.
     *
     * @param int $userid Moodle user ID.
     * @param int $bitrixcompanyid Bitrix24 Company ID.
     * @param string $companytitle Bitrix24 company TITLE.
     * @param int $courseid Moodle course ID.
     * @param int $groupid Optional Moodle group ID, 0 for no specific group.
     * @param int $bitrixcontactid Optional Bitrix24 Contact ID (not persisted yet).
     * @return array
     */
    public static function sync_training_assignment(
        int $userid,
        int $bitrixcompanyid,
        string $companytitle,
        int $courseid,
        int $groupid = 0,
        int $bitrixcontactid = 0
    ): array {
        global $DB;

        self::validate_user($userid);
        self::validate_company_parameters($bitrixcompanyid, $companytitle);
        self::validate_course_group($courseid, $groupid);

        $lock = self::get_company_lock($bitrixcompanyid);
        try {
            // Commit the core company/member/course assignment first. Licence
            // accounting is deliberately a separate, non-blocking operation.
            $transaction = $DB->start_delegated_transaction();
            $companyresult = repository::ensure_company_by_bitrix_id($bitrixcompanyid, trim($companytitle));
            $company = $companyresult['company'];

            $existingmember = repository::get_member((int)$company->id, $userid, 'employee');
            $membershipid = repository::add_member((int)$company->id, $userid, 'employee');
            $membercreated = !$existingmember;
            $courseresult = self::sync_course_mapping_for_company($company, $courseid, $groupid);
            $transaction->allow_commit();

            $licenseresult = [
                'available' => licenses::is_available(),
                'success' => false,
                'changed' => false,
            ];
            if ($licenseresult['available']) {
                try {
                    $allocationresult = licenses::assign_best_available(
                        (int)$company->id,
                        $courseid,
                        $groupid,
                        $userid,
                        0
                    );
                    $licenseresult = [
                        'available' => true,
                        'success' => true,
                        'changed' => !empty($allocationresult['changed']),
                        'created' => !empty($allocationresult['created']),
                        'allocationid' => (int)$allocationresult['allocation']->id,
                        'license' => licenses::to_public($allocationresult['license']),
                    ];
                } catch (\Throwable $e) {
                    // A full/expired package is reported independently and must
                    // never undo a successful BMS enrolment workflow.
                    $licenseresult = [
                        'available' => true,
                        'success' => false,
                        'changed' => false,
                        'errorcode' => $e instanceof \moodle_exception ? (string)$e->errorcode : 'licenseerror',
                        'message' => $e->getMessage(),
                    ];
                }
            }

            return [
                'success' => true,
                'changed' => !empty($companyresult['changed']) || $membercreated ||
                    !empty($courseresult['changed']) || !empty($licenseresult['changed']),
                'bitrixcompanyid' => $bitrixcompanyid,
                'bitrixcontactid' => max(0, $bitrixcontactid),
                'company' => [
                    'companyid' => (int)$company->id,
                    'companyname' => (string)$company->name,
                    'created' => !empty($companyresult['created']),
                    'changed' => !empty($companyresult['changed']),
                ],
                'employee' => [
                    'userid' => $userid,
                    'membershipid' => (int)$membershipid,
                    'created' => $membercreated,
                    'membertype' => 'employee',
                ],
                'course' => [
                    'mappingid' => (int)$courseresult['mappingid'],
                    'courseid' => $courseid,
                    'groupid' => $groupid,
                    'created' => !empty($courseresult['created']),
                    'reactivated' => !empty($courseresult['reactivated']),
                    'changed' => !empty($courseresult['changed']),
                ],
                'license' => $licenseresult,
            ];
        } finally {
            $lock->release();
        }
    }

    /**
     * Synchronise the complete corporate course catalogue for one company.
     *
     * Each item is either a Moodle course ID or an array with courseid/groupid.
     * When $deactivatemissing is true, existing mappings absent from the supplied
     * set are soft-disabled instead of deleted, so a later sync can reactivate
     * them without creating duplicate history rows.
     *
     * @param int $bitrixcompanyid
     * @param string $companytitle
     * @param array $courses
     * @param bool $deactivatemissing
     * @return array
     */
    public static function sync_company_courses(
        int $bitrixcompanyid,
        string $companytitle,
        array $courses,
        bool $deactivatemissing = false
    ): array {
        global $DB;

        self::validate_company_parameters($bitrixcompanyid, $companytitle);
        if (count($courses) > 500) {
            throw new \moodle_exception('bridgebatchtoolarge', 'local_kbacorporate', '', 500);
        }

        $normalised = [];
        foreach ($courses as $item) {
            if (is_array($item)) {
                $courseid = isset($item['courseid']) ? (int)$item['courseid'] : 0;
                $groupid = isset($item['groupid']) ? (int)$item['groupid'] : 0;
            } else {
                $courseid = (int)$item;
                $groupid = 0;
            }
            self::validate_course_group($courseid, $groupid);
            $normalised[$courseid . ':' . $groupid] = [
                'courseid' => $courseid,
                'groupid' => $groupid,
            ];
        }

        $lock = self::get_company_lock($bitrixcompanyid);
        try {
            $transaction = $DB->start_delegated_transaction();
            $companyresult = repository::ensure_company_by_bitrix_id($bitrixcompanyid, trim($companytitle));
            $company = $companyresult['company'];
            $stats = [
                'created' => 0,
                'reactivated' => 0,
                'unchanged' => 0,
                'deactivated' => 0,
            ];
            $mappings = [];

            foreach ($normalised as $key => $item) {
                $result = self::sync_course_mapping_for_company(
                    $company,
                    (int)$item['courseid'],
                    (int)$item['groupid']
                );
                if (!empty($result['created'])) {
                    $stats['created']++;
                } else if (!empty($result['reactivated'])) {
                    $stats['reactivated']++;
                } else {
                    $stats['unchanged']++;
                }
                $mappings[] = [
                    'mappingid' => (int)$result['mappingid'],
                    'courseid' => (int)$item['courseid'],
                    'groupid' => (int)$item['groupid'],
                    'active' => true,
                ];
            }

            if ($deactivatemissing) {
                foreach (repository::get_company_course_mappings((int)$company->id) as $mapping) {
                    $key = (int)$mapping->courseid . ':' . (int)$mapping->groupid;
                    if (!isset($normalised[$key]) && !empty($mapping->active)) {
                        $deactivated = repository::deactivate_course(
                            (int)$company->id,
                            (int)$mapping->courseid,
                            (int)$mapping->groupid
                        );
                        if (!empty($deactivated['changed'])) {
                            $stats['deactivated']++;
                        }
                    }
                }
            }

            $response = [
                'success' => true,
                'changed' => !empty($companyresult['changed']) || $stats['created'] > 0 ||
                    $stats['reactivated'] > 0 || $stats['deactivated'] > 0,
                'bitrixcompanyid' => $bitrixcompanyid,
                'companyid' => (int)$company->id,
                'companyname' => (string)$company->name,
                'companycreated' => !empty($companyresult['created']),
                'deactivatemissing' => $deactivatemissing,
                'stats' => $stats,
                'mappings' => $mappings,
            ];
            $transaction->allow_commit();
            return $response;
        } finally {
            $lock->release();
        }
    }

    /**
     * Soft-disable one corporate course/group mapping.
     *
     * @param int $bitrixcompanyid
     * @param int $courseid
     * @param int $groupid
     * @return array
     */
    public static function unassign_course(int $bitrixcompanyid, int $courseid, int $groupid = 0): array {
        if ($bitrixcompanyid <= 0) {
            throw new \moodle_exception('bridgeinvalidcompanyid', 'local_kbacorporate');
        }
        if ($courseid <= 0) {
            throw new \moodle_exception('bridgecoursenotfound', 'local_kbacorporate', '', $courseid);
        }
        if ($groupid < 0) {
            throw new \moodle_exception('bridgeinvalidgroupid', 'local_kbacorporate');
        }

        $lock = self::get_company_lock($bitrixcompanyid);
        try {
            $company = repository::get_company_by_bitrix_id($bitrixcompanyid);
            if (!$company) {
                return [
                    'success' => true,
                    'changed' => false,
                    'companyfound' => false,
                    'bitrixcompanyid' => $bitrixcompanyid,
                    'courseid' => $courseid,
                    'groupid' => $groupid,
                ];
            }
            $result = repository::deactivate_course((int)$company->id, $courseid, $groupid);
            return [
                'success' => true,
                'changed' => !empty($result['changed']),
                'companyfound' => true,
                'companyid' => (int)$company->id,
                'bitrixcompanyid' => $bitrixcompanyid,
                'courseid' => $courseid,
                'groupid' => $groupid,
                'mappingfound' => !empty($result['found']),
                'mappingid' => !empty($result['mappingid']) ? (int)$result['mappingid'] : 0,
                'active' => false,
            ];
        } finally {
            $lock->release();
        }
    }

    /**
     * Read one company and its explicitly assigned corporate courses.
     *
     * This is a read-only Public API v1.1 method intended for BMS Connect CRM
     * publishing. The corporate course catalogue is sourced exclusively from
     * local_kbacorp_course; individual employee enrolments are not used to infer
     * which courses belong to the company.
     *
     * @param int $bitrixcompanyid Bitrix24 Company ID.
     * @return array Stable company/course snapshot.
     */
    public static function get_company_snapshot(int $bitrixcompanyid): array {
        if ($bitrixcompanyid <= 0) {
            throw new \moodle_exception('bridgeinvalidcompanyid', 'local_kbacorporate');
        }

        $company = repository::get_company_by_bitrix_id($bitrixcompanyid);
        if (!$company) {
            return [
                'success' => true,
                'found' => false,
                'bitrixcompanyid' => $bitrixcompanyid,
                'companyid' => 0,
                'name' => '',
                'active' => false,
                'courses' => [],
                'coursecount' => 0,
                'activecoursecount' => 0,
                'employeecount' => 0,
                'managercount' => 0,
            ];
        }

        return ['success' => true] + self::snapshot_from_company($company);
    }

    /**
     * Return corporate learning-time analytics for CRM/report integrations.
     *
     * Moodle asynchronous time is explicitly estimated from standard logs;
     * BigBlueButton duration is factual when Summary data is available.
     *
     * @param int $bitrixcompanyid Bitrix24 Company ID.
     * @param string $preset week, month, quarter or custom.
     * @param string $from YYYY-MM-DD for custom periods.
     * @param string $to YYYY-MM-DD for custom periods.
     * @return array
     */
    public static function get_training_time_snapshot(
        int $bitrixcompanyid,
        string $preset = 'month',
        string $from = '',
        string $to = ''
    ): array {
        if ($bitrixcompanyid <= 0) {
            throw new \moodle_exception('bridgeinvalidcompanyid', 'local_kbacorporate');
        }
        $company = repository::get_company_by_bitrix_id($bitrixcompanyid);
        if (!$company) {
            return [
                'success' => true,
                'found' => false,
                'bitrixcompanyid' => $bitrixcompanyid,
            ];
        }

        $period = trainingtime::period_for_preset($preset, $from, $to);
        $report = trainingtime::build_report(
            (int)$company->id, (int)$period->start, (int)$period->endexclusive
        );

        $employees = [];
        foreach ($report->employees as $row) {
            $employees[] = [
                'userid' => (int)$row->userid,
                'fullname' => (string)$row->fullname,
                'position' => (string)$row->position,
                'planminutes' => (float)$row->planminutes,
                'moodleestimatedminutes' => (float)$row->asyncminutes,
                'webinarminutes' => (float)$row->bbbminutes,
                'factminutes' => (float)$row->totalminutes,
                'varianceminutes' => (float)$row->varianceminutes,
                'percent' => $row->percent === null ? null : (float)$row->percent,
                'planstate' => (string)$row->planstate,
            ];
        }
        $weeks = [];
        foreach ($report->weeks as $row) {
            $weeks[] = [
                'from' => trainingtime::date_key((int)$row->weekstart),
                'to' => trainingtime::date_key((int)$row->weekend),
                'planminutes' => (float)$row->planminutes,
                'moodleestimatedminutes' => (float)$row->asyncminutes,
                'webinarminutes' => (float)$row->bbbminutes,
                'factminutes' => (float)$row->totalminutes,
                'varianceminutes' => (float)$row->varianceminutes,
                'percent' => $row->percent === null ? null : (float)$row->percent,
            ];
        }

        return [
            'success' => true,
            'found' => true,
            'bitrixcompanyid' => $bitrixcompanyid,
            'companyid' => (int)$company->id,
            'companyname' => (string)$company->name,
            'period' => [
                'preset' => (string)$period->preset,
                'from' => (string)$period->from,
                'to' => (string)$period->to,
                'days' => (int)$period->days,
            ],
            'method' => [
                'moodle' => 'estimated_from_standard_log',
                'bigbluebutton' => 'summary_duration',
                'moodleavailable' => !empty($report->asyncavailable),
                'bigbluebuttonavailable' => !empty($report->bbbavailable),
            ],
            'summary' => [
                'employees' => (int)$report->summary->employees,
                'planminutes' => (float)$report->summary->planminutes,
                'moodleestimatedminutes' => (float)$report->summary->asyncminutes,
                'webinarminutes' => (float)$report->summary->bbbminutes,
                'factminutes' => (float)$report->summary->totalminutes,
                'varianceminutes' => (float)$report->summary->varianceminutes,
                'percent' => $report->summary->percent === null ? null : (float)$report->summary->percent,
                'avgminutes' => (float)$report->summary->avgminutes,
                'metplanemployees' => (int)$report->summary->metplanemployees,
                'belowplanemployees' => (int)$report->summary->belowplanemployees,
            ],
            'employees' => $employees,
            'weeks' => $weeks,
        ];
    }

    /**
     * Return BigBlueButton attendance and engagement analytics for CRM/report integrations.
     *
     * Duration/engagement are sourced from BBB Summary logs. First-join and logout
     * timestamps are Moodle BBB log events and can be absent. Presence percentage
     * is only calculated for plausible activity windows configured as a webinar.
     *
     * @param int $bitrixcompanyid Bitrix24 Company ID.
     * @return array
     */
    public static function get_webinar_analytics_snapshot(int $bitrixcompanyid): array {
        if ($bitrixcompanyid <= 0) {
            throw new \moodle_exception('bridgeinvalidcompanyid', 'local_kbacorporate');
        }

        $company = repository::get_company_by_bitrix_id($bitrixcompanyid);
        if (!$company) {
            return [
                'success' => true,
                'found' => false,
                'bitrixcompanyid' => $bitrixcompanyid,
                'available' => bigbluebutton::is_available(),
            ];
        }

        if (!bigbluebutton::is_available()) {
            return [
                'success' => true,
                'found' => true,
                'bitrixcompanyid' => $bitrixcompanyid,
                'companyid' => (int)$company->id,
                'name' => (string)$company->name,
                'available' => false,
                'summary' => [],
                'employees' => [],
            ];
        }

        $rows = bigbluebutton::get_company_rows((int)$company->id);
        $summary = bigbluebutton::summary($rows);
        $employeeaggregates = bigbluebutton::aggregate_by_employee($rows);
        $employees = [];
        foreach ($employeeaggregates as $row) {
            $employees[] = [
                'userid' => (int)$row->userid,
                'fullname' => (string)$row->fullname,
                'assigned' => (int)$row->assigned,
                'eligible' => (int)$row->eligible,
                'attended' => (int)$row->attended,
                'attendancepercent' => $row->attendancepercent === null ? null : (float)$row->attendancepercent,
                'minutes' => (float)$row->minutes,
                'avgcoveragepercent' => $row->avgcoveragepercent === null ? null : (float)$row->avgcoveragepercent,
                'late' => (int)$row->late,
                'lateeligible' => (int)$row->lateeligible,
                'latepercent' => $row->latepercent === null ? null : (float)$row->latepercent,
                'engagementtotal' => (int)$row->engagementtotal,
            ];
        }

        return [
            'success' => true,
            'found' => true,
            'bitrixcompanyid' => $bitrixcompanyid,
            'companyid' => (int)$company->id,
            'name' => (string)$company->name,
            'available' => true,
            'method' => [
                'duration' => 'bigbluebutton_summary_duration',
                'engagement' => 'bigbluebutton_summary_engagement',
                'firstjoin' => 'moodle_bigbluebutton_join_log',
                'presencepercent' => 'duration_divided_by_plausible_activity_window',
            ],
            'summary' => [
                'webinars' => (int)$summary->webinars,
                'heldwebinars' => (int)$summary->heldwebinars,
                'employees' => (int)$summary->employees,
                'attendedemployees' => (int)$summary->attendedemployees,
                'eligibleassignments' => (int)$summary->eligibleassignments,
                'attendedassignments' => (int)$summary->attendedassignments,
                'attendancepercent' => $summary->attendancepercent === null ? null : (float)$summary->attendancepercent,
                'totalminutes' => (float)$summary->totalminutes,
                'avgminutes' => $summary->avgminutes === null ? null : (float)$summary->avgminutes,
                'avgcoveragepercent' => $summary->avgcoveragepercent === null ? null : (float)$summary->avgcoveragepercent,
                'lateassignments' => (int)$summary->lateassignments,
                'lateeligible' => (int)$summary->lateeligible,
                'latepercent' => $summary->latepercent === null ? null : (float)$summary->latepercent,
                'earlyleaveassignments' => (int)$summary->earlyleaveassignments,
                'earlyleaveeligible' => (int)$summary->earlyleaveeligible,
                'engagedassignments' => (int)$summary->engagedassignments,
                'engagedpercent' => $summary->engagedpercent === null ? null : (float)$summary->engagedpercent,
                'engagementtotal' => (int)$summary->engagementtotal,
                'engagement' => $summary->engagement,
            ],
            'employees' => $employees,
        ];
    }

    /**
     * Return an executive management snapshot for BMS/CRM integrations.
     *
     * @param int $bitrixcompanyid
     * @param string $preset week|month|quarter|custom
     * @param string $from
     * @param string $to
     * @return array
     */
    public static function get_management_report_snapshot(
        int $bitrixcompanyid,
        string $preset = 'month',
        string $from = '',
        string $to = ''
    ): array {
        if ($bitrixcompanyid <= 0) {
            throw new \moodle_exception('bridgeinvalidcompanyid', 'local_kbacorporate');
        }
        $company = repository::get_company_by_bitrix_id($bitrixcompanyid);
        if (!$company) {
            return [
                'success' => true,
                'found' => false,
                'bitrixcompanyid' => $bitrixcompanyid,
            ];
        }

        $report = management_report::build((int)$company->id, $preset, $from, $to);
        return [
            'success' => true,
            'found' => true,
            'companyid' => (int)$company->id,
            'bitrixcompanyid' => (int)$bitrixcompanyid,
            'companyname' => (string)$company->name,
            'period' => [
                'preset' => (string)$report->period->preset,
                'from' => (string)$report->period->from,
                'to' => (string)$report->period->to,
            ],
            'employees' => count($report->employees),
            'avgprogress' => $report->progresssummary->avgprogress,
            'avggrade' => $report->progresssummary->avggrade,
            'training' => [
                'planminutes' => (float)$report->timereport->summary->planminutes,
                'factminutes' => (float)$report->timereport->summary->totalminutes,
                'percent' => $report->timereport->summary->percent,
                'belowplanemployees' => (int)$report->timereport->summary->belowplanemployees,
            ],
            'webinars' => [
                'attendancepercent' => $report->webinarsummary->attendancepercent,
                'avgcoveragepercent' => $report->webinarsummary->avgcoveragepercent,
                'lateassignments' => (int)$report->webinarsummary->lateassignments,
            ],
            'certificates' => [
                'issued' => (int)$report->certificatesummary->issued,
                'issuedinperiod' => (int)$report->certificatesissuedinperiod,
                'issuedpercent' => $report->certificatesummary->issuedpercent,
            ],
            'licenses' => [
                'available' => !empty($report->licenseavailable),
                'packages' => (int)$report->licensesummary->licenses,
                'purchasedseats' => (int)$report->licensesummary->totalseats,
                'usedseats' => (int)$report->licensesummary->usedfinite,
                'allocatedseats' => (int)$report->licensesummary->used,
                'unlimitedallocations' => (int)$report->licensesummary->usedunlimited,
                'freeseats' => (int)$report->licensesummary->free,
                'utilizationpercent' => $report->licensesummary->utilizationpercent,
                'expiringpackages' => (int)$report->licensesummary->expiringlicenses,
                'warningcount' => count($report->licensealerts),
            ],
            'attentioncount' => count($report->attention),
        ];
    }

    /**
     * Return the company certificate/document registry for CRM/report integrations.
     *
     * The registry is read from mod_customcert issue records. No certificate is
     * created by this method. Rows without an issue represent currently enrolled
     * employees for whom the corporate course contains a certificate activity but
     * no issue has yet been recorded.
     *
     * @param int $bitrixcompanyid Bitrix24 Company ID.
     * @return array
     */
    public static function get_certificate_registry_snapshot(int $bitrixcompanyid): array {
        if ($bitrixcompanyid <= 0) {
            throw new \moodle_exception('bridgeinvalidcompanyid', 'local_kbacorporate');
        }

        $company = repository::get_company_by_bitrix_id($bitrixcompanyid);
        if (!$company) {
            return [
                'success' => true,
                'found' => false,
                'bitrixcompanyid' => $bitrixcompanyid,
                'available' => customcert::is_available(),
            ];
        }

        if (!customcert::is_available()) {
            return [
                'success' => true,
                'found' => true,
                'bitrixcompanyid' => $bitrixcompanyid,
                'companyid' => (int)$company->id,
                'name' => (string)$company->name,
                'available' => false,
                'summary' => [],
                'documents' => [],
            ];
        }

        $rows = customcert::get_company_rows((int)$company->id);
        $summary = customcert::summary($rows);
        $documents = [];
        foreach ($rows as $row) {
            $documents[] = [
                'userid' => (int)$row->userid,
                'fullname' => (string)$row->fullname,
                'email' => (string)$row->email,
                'position' => (string)$row->position,
                'courseid' => (int)$row->courseid,
                'coursename' => (string)$row->coursename,
                'customcertid' => (int)$row->customcertid,
                'cmid' => (int)$row->cmid,
                'certificatename' => (string)$row->certificatename,
                'issued' => !empty($row->issued),
                'issueid' => (int)$row->issueid,
                'issuedat' => (int)$row->issuedat,
                'code' => (string)$row->code,
                'emailed' => !empty($row->emailed),
                'issuecount' => (int)$row->issuecount,
            ];
        }

        return [
            'success' => true,
            'found' => true,
            'bitrixcompanyid' => $bitrixcompanyid,
            'companyid' => (int)$company->id,
            'name' => (string)$company->name,
            'available' => true,
            'method' => 'mod_customcert_issues',
            'summary' => [
                'certificateactivities' => (int)$summary->certificateactivities,
                'rows' => (int)$summary->rows,
                'issued' => (int)$summary->issued,
                'notissued' => (int)$summary->notissued,
                'issuedpercent' => $summary->issuedpercent === null ? null : (float)$summary->issuedpercent,
                'employees' => (int)$summary->employees,
                'employeeswithissued' => (int)$summary->employeeswithissued,
            ],
            'documents' => $documents,
        ];
    }

    /**
     * Return a bounded batch of company/course snapshots for scheduled CRM sync.
     *
     * Pagination uses the local Corporate Dashboard company ID as a cursor.
     * Integrations should pass the highest companyid received as $aftercompanyid
     * for the next page.
     *
     * @param int $aftercompanyid Local company ID cursor.
     * @param int $limit Maximum number of companies, clamped to 1..500.
     * @return array List of stable company/course snapshots.
     */
    public static function list_company_snapshots(int $aftercompanyid = 0, int $limit = 100): array {
        $rows = repository::get_companies_batch($aftercompanyid, $limit);
        $result = [];
        foreach ($rows as $company) {
            $result[] = self::snapshot_from_company($company);
        }
        return $result;
    }

    /**
     * Convert one company row to the Public API v1.1 CRM snapshot.
     *
     * A course is active for CRM visibility only when the Corporate Dashboard
     * mapping is active and the underlying Moodle course is visible.
     *
     * @param \stdClass $company
     * @return array
     */
    private static function snapshot_from_company(\stdClass $company): array {
        $courses = [];
        $activecount = 0;

        foreach (repository::get_company_courses((int)$company->id) as $row) {
            $mappingactive = !empty($row->active);
            $coursevisible = !empty($row->visible);
            $active = $mappingactive && $coursevisible;
            if ($active) {
                $activecount++;
            }

            $courses[] = [
                'mappingid' => (int)$row->mappingid,
                'courseid' => (int)$row->courseid,
                'groupid' => (int)$row->groupid,
                'active' => $active,
                'mappingactive' => $mappingactive,
                'fullname' => format_string($row->fullname),
                'shortname' => (string)$row->shortname,
                'idnumber' => (string)$row->idnumber,
                'visible' => $coursevisible,
            ];
        }

        $membercounts = repository::get_company_member_counts((int)$company->id);
        $licenserows = licenses::is_available()
            ? licenses::get_company_licenses((int)$company->id, true) : [];
        $licensesummary = licenses::summary($licenserows);
        $licenseitems = [];
        foreach ($licenserows as $licenserow) {
            $licenseitems[] = licenses::to_public($licenserow);
        }

        return [
            'found' => true,
            'bitrixcompanyid' => (int)$company->bitrixcompanyid,
            'companyid' => (int)$company->id,
            'name' => (string)$company->name,
            'active' => !empty($company->active),
            'courses' => $courses,
            'coursecount' => count($courses),
            'activecoursecount' => $activecount,
            'employeecount' => (int)$membercounts['employees'],
            'managercount' => (int)$membercounts['managers'],
            'licenses' => $licenseitems,
            'licensecount' => (int)$licensesummary->licenses,
            'purchasedseats' => (int)$licensesummary->totalseats,
            'usedseats' => (int)$licensesummary->usedfinite,
            'allocatedseats' => (int)$licensesummary->used,
            'unlimitedallocations' => (int)$licensesummary->usedunlimited,
            'freeseats' => (int)$licensesummary->free,
            'licenseutilizationpercent' => $licensesummary->utilizationpercent === null
                ? null : (float)$licensesummary->utilizationpercent,
        ];
    }

    /**
     * Validate a Moodle course and optional group for bridge writes.
     *
     * @param int $courseid
     * @param int $groupid
     */
    private static function validate_course_group(int $courseid, int $groupid): void {
        global $DB;

        if ($courseid <= 0 || !$DB->record_exists('course', ['id' => $courseid])) {
            throw new \moodle_exception('bridgecoursenotfound', 'local_kbacorporate', '', $courseid);
        }
        if ($groupid < 0) {
            throw new \moodle_exception('bridgeinvalidgroupid', 'local_kbacorporate');
        }
        if ($groupid > 0) {
            $group = $DB->get_record('groups', ['id' => $groupid], 'id,courseid', IGNORE_MISSING);
            if (!$group || (int)$group->courseid !== $courseid) {
                throw new \moodle_exception('bridgegroupnotincourse', 'local_kbacorporate', '', $groupid);
            }
        }
    }

    /**
     * Ensure one course mapping while the caller holds the company lock.
     *
     * @param \stdClass $company
     * @param int $courseid
     * @param int $groupid
     * @return array
     */
    private static function sync_course_mapping_for_company(\stdClass $company, int $courseid, int $groupid): array {
        $existing = repository::get_course_mapping((int)$company->id, $courseid, $groupid);
        $wasactive = $existing && !empty($existing->active);
        $mappingid = repository::add_course((int)$company->id, $courseid, $groupid);

        return [
            'success' => true,
            'companyid' => (int)$company->id,
            'companyname' => (string)$company->name,
            'mappingid' => (int)$mappingid,
            'courseid' => $courseid,
            'groupid' => $groupid,
            'created' => !$existing,
            'reactivated' => $existing && !$wasactive,
            'changed' => !$existing || ($existing && !$wasactive),
            'bitrixcompanyid' => (int)$company->bitrixcompanyid,
        ];
    }

    /**
     * Return the organisational structure of one company for optional integrations.
     *
     * @param int $bitrixcompanyid
     * @return array
     */
    public static function get_company_structure_snapshot(int $bitrixcompanyid): array {
        global $DB;
        if ($bitrixcompanyid <= 0) {
            throw new \moodle_exception('bridgeinvalidcompanyid', 'local_kbacorporate');
        }
        $company = repository::get_company_by_bitrix_id($bitrixcompanyid);
        if (!$company) {
            return ['success' => true, 'found' => false, 'bitrixcompanyid' => $bitrixcompanyid, 'departments' => []];
        }
        $items = [];
        $departments = $DB->get_records('local_kbacorp_department', ['companyid' => $company->id], 'name ASC');
        foreach ($departments as $department) {
            $members = departments::get_department_members((int)$department->id);
            $managers = [];
            $employees = [];
            foreach ($members as $member) {
                $public = [
                    'userid' => (int)$member->id,
                    'fullname' => fullname((object)['firstname' => $member->firstname, 'lastname' => $member->lastname]),
                ];
                if ($member->deptrole === 'manager') {
                    $managers[] = $public;
                } else {
                    $employees[] = $public;
                }
            }
            $items[] = [
                'departmentid' => (int)$department->id,
                'name' => (string)$department->name,
                'code' => (string)($department->code ?? ''),
                'active' => !empty($department->active),
                'employeecount' => count($employees),
                'managercount' => count($managers),
                'managers' => $managers,
                'employees' => $employees,
            ];
        }
        return [
            'success' => true,
            'found' => true,
            'companyid' => (int)$company->id,
            'companyname' => (string)$company->name,
            'bitrixcompanyid' => (int)$company->bitrixcompanyid,
            'departmentcount' => count($items),
            'departments' => $items,
        ];
    }

    /**
     * Return calculated learning-control signals for CRM and reporting integrations.
     *
     * @param int $bitrixcompanyid Bitrix24 Company ID.
     * @param string $preset week|month|quarter|custom
     * @param string $from YYYY-MM-DD for a custom period.
     * @param string $to YYYY-MM-DD for a custom period.
     * @return array
     */
    public static function get_attention_snapshot(
        int $bitrixcompanyid,
        string $preset = 'month',
        string $from = '',
        string $to = ''
    ): array {
        if ($bitrixcompanyid <= 0) {
            throw new \moodle_exception('bridgeinvalidcompanyid', 'local_kbacorporate');
        }

        $company = repository::get_company_by_bitrix_id($bitrixcompanyid);
        if (!$company) {
            return [
                'success' => true,
                'found' => false,
                'bitrixcompanyid' => $bitrixcompanyid,
                'employees' => [],
                'alerts' => [],
            ];
        }

        $report = management_report::build((int)$company->id, $preset, $from, $to);
        $summary = $report->attentionsummary;
        $employees = [];
        foreach ($report->attention as $employee) {
            $employees[] = [
                'userid' => (int)$employee->userid,
                'fullname' => (string)$employee->fullname,
                'email' => (string)$employee->email,
                'position' => (string)$employee->position,
                'departments' => (string)$employee->departments,
                'severity' => (string)$employee->riskseverity,
                'score' => (int)$employee->riskscore,
                'alertcount' => (int)$employee->alertcount,
                'types' => array_values($employee->risktypes),
                'reasons' => array_values($employee->riskreasons),
                'avgprogress' => $employee->avgprogress === null ? null : (float)$employee->avgprogress,
                'avggrade' => $employee->avggrade === null ? null : (float)$employee->avggrade,
                'trainingpercent' => $employee->trainingpercent === null ? null : (float)$employee->trainingpercent,
                'webinarattendancepercent' => $employee->webinarattendancepercent === null
                    ? null : (float)$employee->webinarattendancepercent,
                'lastaccess' => (int)$employee->lastaccess,
            ];
        }

        $alerts = [];
        foreach ($report->alerts as $alert) {
            $alerts[] = [
                'key' => (string)$alert->alertkey,
                'userid' => (int)$alert->userid,
                'fullname' => (string)$alert->fullname,
                'type' => (string)$alert->type,
                'severity' => (string)$alert->severity,
                'score' => (int)$alert->score,
                'reason' => (string)$alert->reason,
                'courseid' => (int)$alert->courseid,
                'coursename' => (string)$alert->coursename,
                'currentvalue' => $alert->currentvalue === null ? null : (float)$alert->currentvalue,
                'thresholdvalue' => $alert->thresholdvalue === null ? null : (float)$alert->thresholdvalue,
                'lastaccess' => (int)$alert->lastaccess,
            ];
        }

        return [
            'success' => true,
            'found' => true,
            'bitrixcompanyid' => $bitrixcompanyid,
            'companyid' => (int)$company->id,
            'name' => (string)$company->name,
            'period' => [
                'preset' => (string)$report->period->preset,
                'from' => (string)$report->period->from,
                'to' => (string)$report->period->to,
                'start' => (int)$report->period->start,
                'endexclusive' => (int)$report->period->endexclusive,
            ],
            'summary' => [
                'alerts' => (int)$summary->alerts,
                'employees' => (int)$summary->employees,
                'high' => (int)$summary->high,
                'medium' => (int)$summary->medium,
                'low' => (int)$summary->low,
                'highriskemployees' => (int)$summary->highriskemployees,
                'mediumriskemployees' => (int)$summary->mediumriskemployees,
                'lowriskemployees' => (int)$summary->lowriskemployees,
                'types' => $summary->types,
            ],
            'thresholds' => $report->attentionthresholds,
            'notifications' => attention_notifier::status($company),
            'employees' => $employees,
            'alerts' => $alerts,
        ];
    }

    private static function schema_status(): array {
        global $DB;

        $dbman = $DB->get_manager();
        $required = [
            'local_kbacorp_company' => [
                'id', 'name', 'bitrixcompanyid', 'active', 'trainingminutesday',
                'trainingweekdays', 'alertsenabled', 'alertfrequency', 'lastalertsent',
                'timecreated', 'timemodified',
            ],
            'local_kbacorp_member' => [
                'id', 'companyid', 'userid', 'membertype', 'isemployee', 'ismanager', 'isdepartmentmanager', 'ishr', 'timecreated', 'timemodified',
            ],
            'local_kbacorp_course' => [
                'id', 'companyid', 'courseid', 'groupid', 'active', 'timecreated', 'timemodified',
            ],
            'local_kbacorp_department' => [
                'id', 'companyid', 'name', 'code', 'active', 'timecreated', 'timemodified',
            ],
            'local_kbacorp_dept_member' => [
                'id', 'departmentid', 'userid', 'deptrole', 'timecreated', 'timemodified',
            ],
            'local_kbacorp_license' => [
                'id', 'companyid', 'courseid', 'groupid', 'seats', 'datefrom', 'dateto',
                'source', 'externaldealid', 'externalrowid', 'status', 'timecreated', 'timemodified',
            ],
            'local_kbacorp_license_user' => [
                'id', 'licenseid', 'userid', 'status', 'assignedby', 'timeassigned',
                'timestarted', 'timecompleted', 'timereleased', 'timecreated', 'timemodified',
            ],
            'local_kbacorp_busrole' => [
                'id', 'companyid', 'code', 'uuid', 'name', 'description', 'active', 'source',
                'templateid', 'templateversion', 'appliedversion', 'timecreated', 'timemodified',
            ],
            'local_kbacorp_rolecomp' => [
                'id', 'roleid', 'competencyid', 'targetlevel', 'priority', 'required', 'source', 'timecreated', 'timemodified',
            ],
            'local_kbacorp_bms_comp' => [
                'id', 'criterionid', 'competencyid', 'targetlevel', 'priority', 'required',
                'timecreated', 'timemodified',
            ],
            'local_kbacorp_rolever' => [
                'id', 'templateid', 'version', 'name', 'description', 'active', 'changenote',
                'createdby', 'timecreated',
            ],
            'local_kbacorp_rolevercomp' => [
                'id', 'roleversionid', 'competencyid', 'targetlevel', 'priority', 'required',
            ],
            'local_kbacorp_member_busrole' => [
                'id', 'companyid', 'userid', 'roleid', 'source', 'externalref', 'active', 'effectivefrom', 'effectiveto', 'timecreated', 'timemodified',
            ],
            'local_kbacorp_extmap' => [
                'id', 'companyid', 'system', 'entitytype', 'localentity', 'localid', 'externalid', 'externalcode', 'status', 'metadata', 'lastsync', 'timecreated', 'timemodified',
            ],
        ];

        $result = ['ok' => true, 'tables' => []];
        foreach ($required as $tablename => $fields) {
            $table = new \xmldb_table($tablename);
            $exists = $dbman->table_exists($table);
            $tableinfo = [
                'exists' => $exists,
                'fields' => [],
                'missingfields' => [],
            ];
            if (!$exists) {
                $result['ok'] = false;
                $tableinfo['missingfields'] = $fields;
                $result['tables'][$tablename] = $tableinfo;
                continue;
            }

            $columns = $DB->get_columns($tablename);
            foreach ($fields as $field) {
                $present = isset($columns[$field]);
                $tableinfo['fields'][$field] = $present;
                if (!$present) {
                    $tableinfo['missingfields'][] = $field;
                    $result['ok'] = false;
                }
            }
            $result['tables'][$tablename] = $tableinfo;
        }
        return $result;
    }

    /**
     * Count duplicated non-empty Bitrix24 company IDs for diagnostics.
     *
     * @param array $schema
     * @return int
     */
    private static function count_duplicate_bitrix_companies(array $schema): int {
        global $DB;

        if (empty($schema['tables']['local_kbacorp_company']['exists']) ||
                empty($schema['tables']['local_kbacorp_company']['fields']['bitrixcompanyid'])) {
            return 0;
        }

        $sql = "SELECT bitrixcompanyid, COUNT(1) AS duplicatecount
                  FROM {local_kbacorp_company}
                 WHERE bitrixcompanyid IS NOT NULL
                   AND bitrixcompanyid <> :emptyid
              GROUP BY bitrixcompanyid
                HAVING COUNT(1) > 1";
        $records = $DB->get_records_sql($sql, ['emptyid' => '']);
        return count($records);
    }


    /** Count duplicated company/user memberships for diagnostics. */
    private static function count_duplicate_members(array $schema): int {
        global $DB;

        if (empty($schema['tables']['local_kbacorp_member']['exists'])) {
            return 0;
        }
        $sql = "SELECT companyid, userid, COUNT(1) AS duplicatecount
                  FROM {local_kbacorp_member}
              GROUP BY companyid, userid
                HAVING COUNT(1) > 1";
        return count($DB->get_records_sql($sql));
    }

    /** Count duplicated non-empty Bitrix24 licence references. */
    private static function count_duplicate_license_refs(array $schema): int {
        global $DB;

        if (empty($schema['tables']['local_kbacorp_license']['exists'])) {
            return 0;
        }
        $sqlwithrow = "SELECT companyid, externaldealid, externalrowid, COUNT(1) AS duplicatecount
                         FROM {local_kbacorp_license}
                        WHERE source = :source
                          AND externalrowid <> :emptyrow
                     GROUP BY companyid, externaldealid, externalrowid
                       HAVING COUNT(1) > 1";
        $withrow = $DB->get_records_sql($sqlwithrow, [
            'source' => licenses::SOURCE_BITRIX,
            'emptyrow' => '',
        ]);
        $sqlwithoutrow = "SELECT companyid, externaldealid, courseid, groupid, COUNT(1) AS duplicatecount
                            FROM {local_kbacorp_license}
                           WHERE source = :source
                             AND externalrowid = :emptyrow
                             AND externaldealid <> :emptydeal
                        GROUP BY companyid, externaldealid, courseid, groupid
                          HAVING COUNT(1) > 1";
        $withoutrow = $DB->get_records_sql($sqlwithoutrow, [
            'source' => licenses::SOURCE_BITRIX,
            'emptyrow' => '',
            'emptydeal' => '',
        ]);
        return count($withrow) + count($withoutrow);
    }

    /**
     * Validate a Moodle user for bridge writes.
     *
     * @param int $userid
     */
    private static function validate_user(int $userid): void {
        global $DB;

        if ($userid <= 0) {
            throw new \moodle_exception('bridgeinvaliduserid', 'local_kbacorporate');
        }
        if (!$DB->record_exists('user', ['id' => $userid, 'deleted' => 0])) {
            throw new \moodle_exception('bridgeusernotfound', 'local_kbacorporate', '', $userid);
        }
    }

    /**
     * Validate company parameters.
     *
     * @param int $bitrixcompanyid
     * @param string $companytitle
     */
    private static function validate_company_parameters(int $bitrixcompanyid, string $companytitle): void {
        if ($bitrixcompanyid <= 0) {
            throw new \moodle_exception('bridgeinvalidcompanyid', 'local_kbacorporate');
        }
        if (trim($companytitle) === '') {
            throw new \moodle_exception('bridgeemptycompanytitle', 'local_kbacorporate');
        }
    }

    /**
     * Acquire a company-scoped lock to keep idempotent syncs safe under concurrency.
     *
     * @param int $bitrixcompanyid
     * @return \core\lock\lock
     */
    private static function get_company_lock(int $bitrixcompanyid) {
        $factory = \core\lock\lock_config::get_lock_factory('local_kbacorporate');
        $lock = $factory->get_lock('bitrixcompany_' . $bitrixcompanyid, 10);
        if (!$lock) {
            throw new \moodle_exception('bridgelockfailed', 'local_kbacorporate', '', $bitrixcompanyid);
        }
        return $lock;
    }

    /**
     * Convert an internal company row to a stable public representation.
     *
     * @param \stdClass $company
     * @return array
     */
    private static function company_to_public(\stdClass $company): array {
        return [
            'companyid' => (int)$company->id,
            'companyname' => (string)$company->name,
            'bitrixcompanyid' => (int)$company->bitrixcompanyid,
            'active' => !empty($company->active),
        ];
    }
}
