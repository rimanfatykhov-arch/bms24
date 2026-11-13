<?php
namespace local_kbacorporate\local;

defined('MOODLE_INTERNAL') || die();

/**
 * Provider-neutral exchange boundary for Odoo/BMS Digital ERP.
 *
 * Moodle remains authoritative for learning, competency grades and evidence.
 * ERP systems own their external identifiers and organisational master data.
 */
class erp_exchange {
    public const CONTRACT_VERSION = 1;
    public const CONTRACT_RELEASE = '1.3.1';
    public const CONTRACT_STABILITY = 'stable';
    public const SERVICE_SHORTNAME = 'local_kbacorporate_erp_v1';
    public const MAX_PAYLOAD_BYTES = 65535;
    public const MAX_PAGE_SIZE = 200;
    public const MAX_PAYLOAD_DEPTH = 8;
    public const MAX_PAYLOAD_NODES = 500;

    /** Return the stable, machine-readable ERP contract. */
    public static function contract(): array {
        $contract = [
            'component' => 'local_kbacorporate',
            'contract' => 'kba_erp_exchange',
            'version' => self::CONTRACT_VERSION,
            'release' => self::CONTRACT_RELEASE,
            'stability' => self::CONTRACT_STABILITY,
            'transport' => 'moodle_external_service',
            'service_shortname' => self::SERVICE_SHORTNAME,
            'encoding' => 'json_utf8',
            'authentication' => 'moodle_webservice_token',
            'company_scope' => 'explicit_external_id',
            'idempotency' => 'system_entity_external_id',
            'write_strategy' => 'upsert_and_merge',
            'compatibility' => [
                'major_version' => 'backwards_compatible',
                'additive_fields' => 'allowed',
                'breaking_changes' => 'new_major_version',
                'unknown_response_fields' => 'clients_must_ignore',
                'provider_token_policy' => 'literal_immutable',
                'legacy_provider_tokens' => ['odoo'],
            ],
            'authorities' => [
                'erp' => [
                    'company_identity', 'employee_identity', 'employee_profile',
                    'organisation_structure', 'role_metadata', 'role_assignments',
                    'learning_catalog_assignments', 'economic_values',
                ],
                'moodle' => ['competency_levels', 'evidence', 'learning_progress', 'development_plans'],
            ],
            'semantic_guards' => [
                'course_completion_does_not_confirm_competency',
                'course_completion_does_not_raise_bms_maturity',
                'implementation_action_does_not_raise_bms_maturity',
                'accepted_evidence_requires_confirmed_reaudit_for_maturity_change',
                'unassessed_competency_is_null_not_zero',
                'analytics_correlation_is_not_causality',
            ],
            'operations' => [
                'contract' => ['function' => 'local_kbacorporate_erp_contract', 'type' => 'read'],
                'status' => ['function' => 'local_kbacorporate_erp_status', 'type' => 'read'],
                'push_entity' => ['function' => 'local_kbacorporate_erp_push_entity', 'type' => 'write'],
                'pull_company_snapshot' => [
                    'function' => 'local_kbacorporate_erp_pull_company_snapshot',
                    'type' => 'read',
                    'pagination' => 'exclusive_moodle_user_id_cursor',
                ],
            ],
            'push_entities' => [
                'company' => ['required' => ['name']],
                'employee' => [
                    'required' => ['company_external_id'],
                    'identity' => ['moodle_user_id', 'existing_username_or_email', 'create_if_missing'],
                    'create_required' => ['username', 'firstname', 'lastname', 'email'],
                    'optional' => ['active', 'access_roles', 'code'],
                    'profile_update_policy' => 'erp_created_accounts_only',
                    'deactivation_policy' => 'company_membership_only',
                ],
                'department' => [
                    'required' => ['company_external_id'],
                    'create_required' => ['name'],
                    'optional' => ['code', 'active'],
                    'update_policy' => 'erp_managed_records_only',
                    'deactivation_policy' => 'soft_without_assignment_deletion',
                ],
                'department_membership' => [
                    'required' => [
                        'company_external_id', 'department_external_id',
                        'employee_external_id', 'department_role',
                    ],
                    'department_roles' => ['employee', 'manager'],
                    'optional' => ['active'],
                    'deactivation_policy' => 'erp_managed_assignment_only',
                ],
                'business_role' => [
                    'required' => ['company_external_id', 'role_code'],
                    'create_required' => ['name'],
                    'optional' => ['description', 'active'],
                    'scope' => 'company_only',
                    'competency_profile_policy' => 'moodle_kba_owned_unchanged',
                    'update_policy' => 'erp_managed_records_only',
                ],
                'learning_catalog_assignment' => [
                    'required' => ['company_external_id'],
                    'course_identity' => ['moodle_course_id', 'course_idnumber'],
                    'optional' => ['group_id', 'active'],
                    'scope' => 'company_course_catalog_only',
                    'enrolment_policy' => 'unchanged',
                    'learning_plan_policy' => 'unchanged',
                    'completion_policy' => 'moodle_read_only',
                ],
                'economic_value' => [
                    'required' => [
                        'company_external_id', 'indicator_code', 'period_type',
                        'period_start', 'value',
                    ],
                    'optional' => ['period_end', 'scenario', 'currency'],
                    'verification_policy' => 'always_unverified_until_moodle_review',
                    'causality_policy' => 'not_established',
                    'manual_value_policy' => 'never_overwrite',
                ],
                'business_role_assignment' => [
                    'required' => ['company_external_id', 'employee_external_id', 'role_code'],
                    'optional' => ['business_role_external_id', 'active'],
                ],
            ],
            'pull_resources' => [
                'company_snapshot', 'moodle_competency_state',
                'moodle_learning_progress', 'latest_economic_context',
            ],
            'limits' => [
                'payload_bytes' => self::MAX_PAYLOAD_BYTES,
                'page_size' => self::MAX_PAGE_SIZE,
            ],
            'privacy' => [
                'employee_pii_exported' => false,
                'employee_pii_accepted_for_provisioning' => true,
                'secrets_in_mapping_metadata' => false,
            ],
            'errors' => [
                'transport' => 'moodle_rest_exception',
                'validation' => 'invalid_parameter_exception',
                'retry_policy' => 'retry_only_transient_failures',
            ],
            'hardening' => [
                'entity_locking' => true,
                'transactional_writes' => true,
                'payload_max_depth' => self::MAX_PAYLOAD_DEPTH,
                'payload_max_nodes' => self::MAX_PAYLOAD_NODES,
                'operational_audit' => 'hashed_identifiers_no_payload_or_pii',
                'company_isolation' => 'mapping_target_verified',
            ],
        ];
        $contract['schema_hash_algorithm'] = 'sha256';
        $contract['schema_hash'] = hash('sha256', self::encode_for_hash($contract));
        return $contract;
    }

    /** Return a safe readiness response for ERP clients and monitoring. */
    public static function status(): array {
        global $DB;

        $contract = self::contract();
        $service = $DB->get_record('external_services', ['shortname' => self::SERVICE_SHORTNAME]);
        $audit = erp_audit::statistics(DAYSECS);
        return [
            'success' => true,
            'component' => 'local_kbacorporate',
            'contract' => 'kba_erp_exchange',
            'contract_version' => self::CONTRACT_VERSION,
            'contract_release' => self::CONTRACT_RELEASE,
            'stability' => self::CONTRACT_STABILITY,
            'schema_hash' => $contract['schema_hash'],
            'service_shortname' => self::SERVICE_SHORTNAME,
            'service_installed' => (bool)$service,
            'service_enabled' => $service && !empty($service->enabled),
            'operational_audit' => $audit,
            'hardening_ready' => !empty($audit['available']),
            'server_time' => time(),
        ];
    }

    /**
     * Idempotently import one supported ERP entity.
     *
     * @param string $system Stable provider token, for example odoo or bms_erp.
     * @param string $entitytype company, employee or business_role_assignment.
     * @param string $externalid Provider-owned immutable ID.
     * @param array $payload Validated entity payload.
     * @param string $idempotencykey Optional request trace key.
     * @return array
     */
    public static function push_entity(string $system, string $entitytype, string $externalid,
            array $payload, string $idempotencykey = ''): array {
        global $DB;

        $system = self::token($system, 30, 'system');
        $entitytype = self::token($entitytype, 30, 'entity type');
        $externalid = self::external_id($externalid);
        $idempotencykey = trim($idempotencykey);
        $startedat = microtime(true);
        $lock = null;
        try {
            if (strlen($idempotencykey) > 128) {
                throw new \invalid_parameter_exception('Idempotency key is too long.');
            }
            self::assert_payload_limits($payload);
            $lock = self::get_lock($system, $entitytype, $externalid);
            $transaction = null;
            try {
                $transaction = $DB->start_delegated_transaction();
                switch ($entitytype) {
                    case 'company':
                        $result = self::push_company($system, $externalid, $payload, $idempotencykey);
                        break;
                    case 'employee':
                        $result = self::push_employee($system, $externalid, $payload, $idempotencykey);
                        break;
                    case 'department':
                        $result = self::push_department($system, $externalid, $payload, $idempotencykey);
                        break;
                    case 'department_membership':
                        $result = self::push_department_membership(
                            $system, $externalid, $payload, $idempotencykey
                        );
                        break;
                    case 'business_role':
                        $result = self::push_business_role($system, $externalid, $payload, $idempotencykey);
                        break;
                    case 'learning_catalog_assignment':
                        $result = self::push_learning_catalog_assignment(
                            $system, $externalid, $payload, $idempotencykey
                        );
                        break;
                    case 'economic_value':
                        $result = self::push_economic_value($system, $externalid, $payload, $idempotencykey);
                        break;
                    case 'business_role_assignment':
                        $result = self::push_business_role_assignment(
                            $system, $externalid, $payload, $idempotencykey
                        );
                        break;
                    default:
                        throw new \invalid_parameter_exception('Unsupported ERP entity type.');
                }
                $transaction->allow_commit();
            } catch (\Exception $error) {
                if ($transaction) {
                    $transaction->rollback($error);
                }
                throw $error;
            }

            $response = [
                'success' => true,
                'contract_version' => self::CONTRACT_VERSION,
                'system' => $system,
                'entity_type' => $entitytype,
                'external_id' => $externalid,
                'server_time' => time(),
            ] + $result;
            erp_audit::record($system, $entitytype, $externalid, $idempotencykey,
                erp_audit::STATUS_SUCCESS, $startedat, (int)($result['moodle_company_id'] ?? 0));
            return $response;
        } catch (\Throwable $error) {
            erp_audit::record($system, $entitytype, $externalid, $idempotencykey,
                erp_audit::STATUS_FAILURE, $startedat, 0, $error);
            throw $error;
        } finally {
            if ($lock) {
                $lock->release();
            }
        }
    }

    /** Return a deterministic, paged company snapshot without employee PII. */
    public static function company_snapshot(string $system, string $companyexternalid,
            int $afteruserid = 0, int $limit = 100): array {
        global $DB;

        $system = self::token($system, 30, 'system');
        $companyexternalid = self::external_id($companyexternalid);
        $limit = max(1, min(self::MAX_PAGE_SIZE, $limit));
        $company = self::resolve_company($system, $companyexternalid);
        if (!$company) {
            return [
                'success' => true,
                'found' => false,
                'contract_version' => self::CONTRACT_VERSION,
                'system' => $system,
                'company_external_id' => $companyexternalid,
                'organisation' => ['departments' => [], 'business_roles' => []],
                'economic_context' => [
                    'values' => [],
                    'causality_established' => false,
                ],
                'employees' => [],
                'server_time' => time(),
            ];
        }

        $sql = "SELECT m.*, u.suspended, u.deleted
                  FROM {local_kbacorp_member} m
                  JOIN {user} u ON u.id = m.userid
                 WHERE m.companyid = :companyid
                   AND m.isemployee = 1
                   AND m.userid > :afteruserid
              ORDER BY m.userid ASC";
        $members = array_values($DB->get_records_sql($sql, [
            'companyid' => (int)$company->id,
            'afteruserid' => max(0, $afteruserid),
        ], 0, $limit + 1));
        $hasmore = count($members) > $limit;
        if ($hasmore) {
            array_pop($members);
        }

        $employees = [];
        foreach ($members as $member) {
            $employees[] = self::employee_snapshot($system, $company, $member);
        }
        $lastemployee = $employees ? end($employees) : null;
        $next = $lastemployee ? (int)$lastemployee['moodle_user_id'] : 0;

        return [
            'success' => true,
            'found' => true,
            'contract_version' => self::CONTRACT_VERSION,
            'system' => $system,
            'company_external_id' => $companyexternalid,
            'company' => [
                'moodle_company_id' => (int)$company->id,
                'name' => (string)$company->name,
                'code' => (string)($company->code ?? ''),
                'primary_activity' => (string)($company->primaryactivity ?? ''),
                'founded_year' => (int)($company->foundedyear ?? 0),
                'active' => !empty($company->active),
                'modified_at' => (int)$company->timemodified,
            ],
            'organisation' => self::organisation_snapshot($system, $company),
            'economic_context' => self::economic_snapshot($company),
            'page' => [
                'after_moodle_user_id' => max(0, $afteruserid),
                'limit' => $limit,
                'returned' => count($employees),
                'has_more' => $hasmore,
                'next_after_moodle_user_id' => $hasmore ? $next : 0,
            ],
            'employees' => $employees,
            'server_time' => time(),
        ];
    }

    private static function push_company(string $system, string $externalid, array $payload,
            string $idempotencykey): array {
        global $DB;

        $name = trim((string)($payload['name'] ?? ''));
        if ($name === '' || \core_text::strlen($name) > 255) {
            throw new \invalid_parameter_exception('Company name is required and must not exceed 255 characters.');
        }
        $mapping = self::find_mapping(0, $system, 'company', $externalid);
        $created = false;
        $changed = false;
        $now = time();
        if ($mapping) {
            global $USER;
            access::require_not_revoked((int)$mapping->localid, (int)($USER->id ?? 0));
            $company = $DB->get_record('local_kbacorp_company', ['id' => (int)$mapping->localid], '*', MUST_EXIST);
        } else {
            $company = (object)[
                'name' => $name,
                'code' => null,
                'bitrixcompanyid' => null,
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
            $company->id = (int)$DB->insert_record('local_kbacorp_company', $company);
            $created = true;
            $changed = true;
        }
        $updates = ['name' => $name];
        if (array_key_exists('code', $payload)) {
            $updates['code'] = self::bounded_optional($payload['code'], 100);
        }
        if (array_key_exists('active', $payload)) {
            $updates['active'] = !empty($payload['active']) ? 1 : 0;
        }
        if (array_key_exists('primary_activity', $payload)) {
            $updates['primaryactivity'] = self::bounded_optional($payload['primary_activity'], 255);
        }
        if (array_key_exists('founded_year', $payload)) {
            $year = (int)$payload['founded_year'];
            if ($year !== 0 && ($year < 1800 || $year > (int)date('Y'))) {
                throw new \invalid_parameter_exception('Founded year is outside the allowed range.');
            }
            $updates['foundedyear'] = $year;
        }
        foreach ($updates as $field => $value) {
            if ((string)$company->{$field} !== (string)$value) {
                $company->{$field} = $value;
                $changed = true;
            }
        }
        if ($changed) {
            $company->timemodified = $now;
            $DB->update_record('local_kbacorp_company', $company);
        }
        external_mapping::upsert(0, $system, 'company', 'company', (int)$company->id,
            $externalid, (string)($payload['code'] ?? ''), self::mapping_metadata($idempotencykey));

        return [
            'status' => $created ? 'created' : ($changed ? 'updated' : 'unchanged'),
            'created' => $created,
            'changed' => $changed,
            'moodle_company_id' => (int)$company->id,
        ];
    }

    private static function push_employee(string $system, string $externalid, array $payload,
            string $idempotencykey): array {
        global $DB;

        $companyexternalid = self::external_id((string)($payload['company_external_id'] ?? ''));
        $company = self::resolve_company($system, $companyexternalid);
        if (!$company) {
            throw new \invalid_parameter_exception('Mapped company was not found. Push the company first.');
        }

        $mapping = self::find_mapping((int)$company->id, $system, 'employee', $externalid);
        $active = !array_key_exists('active', $payload) || !empty($payload['active']);
        if (!$active) {
            return self::deactivate_employee($company, $mapping, $system, $externalid);
        }

        $identity = self::resolve_employee_identity($mapping, $payload);
        $userid = (int)$identity['user']->id;
        $accountcreated = !empty($identity['account_created']);
        $managedaccount = !empty($identity['managed_account']);
        $profilechanged = false;
        if (!$accountcreated && $managedaccount) {
            $profilechanged = self::update_managed_employee_profile($identity['user'], $payload);
        }

        $existing = $DB->get_record('local_kbacorp_member', [
            'companyid' => (int)$company->id,
            'userid' => $userid,
        ]);
        $wasemployee = $existing && !empty($existing->isemployee);
        $membershipid = repository::add_member((int)$company->id, $userid, 'employee');
        $roles = isset($payload['access_roles']) && is_array($payload['access_roles'])
            ? $payload['access_roles'] : [];
        $mergeresult = bridge::merge_employee_roles($userid, (int)$company->id, $roles);
        $oldmetadata = self::mapping_metadata_values($mapping);
        $managedmembership = array_key_exists('managed_membership', $oldmetadata)
            ? !empty($oldmetadata['managed_membership']) : !$wasemployee;
        external_mapping::upsert((int)$company->id, $system, 'employee', 'user', $userid,
            $externalid, (string)($payload['code'] ?? ''), self::mapping_metadata($idempotencykey, [
                'managed_account' => $managedaccount ? 1 : 0,
                'managed_membership' => $managedmembership ? 1 : 0,
            ]));

        $membershipcreated = !$wasemployee;
        $changed = $accountcreated || $profilechanged || $membershipcreated ||
            ($mergeresult['status'] ?? '') === 'merged';

        return [
            'status' => $accountcreated ? 'account_created' : ($changed ? 'updated' : 'unchanged'),
            // Backwards-compatible meaning: company membership was created/reactivated.
            'created' => $membershipcreated,
            'changed' => $changed,
            'moodle_company_id' => (int)$company->id,
            'moodle_user_id' => $userid,
            'membership_id' => (int)$membershipid,
            'membership_created' => $membershipcreated,
            'account_created' => $accountcreated,
            'profile_changed' => $profilechanged,
            'managed_account' => $managedaccount,
            'password_reset_required' => $accountcreated,
            'active' => true,
            'access_roles' => $mergeresult['roles'] ?? ['employee'],
        ];
    }

    /** Resolve, link or explicitly create the Moodle account for one employee. */
    private static function resolve_employee_identity(?\stdClass $mapping, array $payload): array {
        global $CFG, $DB;

        $requesteduserid = (int)($payload['moodle_user_id'] ?? 0);
        $metadata = self::mapping_metadata_values($mapping);
        if ($mapping) {
            if ((string)$mapping->localentity !== 'user') {
                throw new \invalid_parameter_exception('Employee mapping does not reference a Moodle user.');
            }
            $user = $DB->get_record('user', ['id' => (int)$mapping->localid, 'deleted' => 0]);
            if (!$user) {
                throw new \invalid_parameter_exception('Mapped Moodle user was not found.');
            }
            if ($requesteduserid > 0 && $requesteduserid !== (int)$user->id) {
                throw new \invalid_parameter_exception('Employee external ID is already mapped to another user.');
            }
            return [
                'user' => $user,
                'account_created' => false,
                'managed_account' => !empty($metadata['managed_account']),
            ];
        }

        if ($requesteduserid > 0) {
            $user = $DB->get_record('user', ['id' => $requesteduserid, 'deleted' => 0]);
            if (!$user) {
                throw new \invalid_parameter_exception('Active Moodle user was not found.');
            }
            return ['user' => $user, 'account_created' => false, 'managed_account' => false];
        }

        $username = self::employee_username((string)($payload['username'] ?? ''));
        $email = self::employee_email((string)($payload['email'] ?? ''), false);
        $user = null;
        if ($username !== '') {
            $user = $DB->get_record('user', [
                'username' => $username,
                'mnethostid' => (int)$CFG->mnet_localhost_id,
                'deleted' => 0,
            ]);
            if ($user && $email !== '' && \core_text::strtolower((string)$user->email) !==
                    \core_text::strtolower($email)) {
                throw new \invalid_parameter_exception('Username and email resolve to different employee identity.');
            }
        }
        if (!$user && $email !== '') {
            $matches = $DB->get_records('user', [
                'email' => $email,
                'mnethostid' => (int)$CFG->mnet_localhost_id,
                'deleted' => 0,
            ], 'id ASC');
            if (count($matches) > 1) {
                throw new \invalid_parameter_exception('Employee email matches more than one Moodle account.');
            }
            $user = $matches ? reset($matches) : null;
            if ($user && $username !== '' && (string)$user->username !== $username) {
                throw new \invalid_parameter_exception('Username and email resolve to different employee identity.');
            }
        }
        if ($user) {
            return ['user' => $user, 'account_created' => false, 'managed_account' => false];
        }
        if (empty($payload['create_if_missing'])) {
            throw new \invalid_parameter_exception(
                'Moodle user was not found. Provide moodle_user_id or explicitly enable create_if_missing.'
            );
        }
        if ($username === '') {
            throw new \invalid_parameter_exception('Username is required to create an employee account.');
        }
        $firstname = self::employee_name((string)($payload['firstname'] ?? ''), 'First name');
        $lastname = self::employee_name((string)($payload['lastname'] ?? ''), 'Last name');
        $email = self::employee_email((string)($payload['email'] ?? ''), true);
        if ($DB->record_exists('user', [
                'username' => $username,
                'mnethostid' => (int)$CFG->mnet_localhost_id,
            ])) {
            throw new \invalid_parameter_exception('Username is already used by another Moodle account.');
        }
        if (empty($CFG->allowaccountssameemail) && $DB->record_exists('user', [
                'email' => $email,
                'mnethostid' => (int)$CFG->mnet_localhost_id,
                'deleted' => 0,
            ])) {
            throw new \invalid_parameter_exception('Email is already used by another Moodle account.');
        }

        require_once($CFG->dirroot . '/user/lib.php');
        $record = (object)[
            'auth' => 'manual',
            'confirmed' => 1,
            'suspended' => 0,
            'mnethostid' => (int)$CFG->mnet_localhost_id,
            'username' => $username,
            'password' => generate_password(32),
            'firstname' => $firstname,
            'lastname' => $lastname,
            'email' => $email,
        ];
        $record->id = user_create_user($record, true, false);
        set_user_preference('auth_forcepasswordchange', 1, (int)$record->id);
        $user = $DB->get_record('user', ['id' => (int)$record->id], '*', MUST_EXIST);
        return ['user' => $user, 'account_created' => true, 'managed_account' => true];
    }

    /** Update only accounts originally created by this ERP employee mapping. */
    private static function update_managed_employee_profile(\stdClass $user, array $payload): bool {
        global $CFG, $DB;

        $updates = [];
        if (array_key_exists('firstname', $payload)) {
            $updates['firstname'] = self::employee_name((string)$payload['firstname'], 'First name');
        }
        if (array_key_exists('lastname', $payload)) {
            $updates['lastname'] = self::employee_name((string)$payload['lastname'], 'Last name');
        }
        if (array_key_exists('email', $payload)) {
            $updates['email'] = self::employee_email((string)$payload['email'], true);
            if (empty($CFG->allowaccountssameemail)) {
                $matches = $DB->get_records('user', [
                    'email' => $updates['email'],
                    'mnethostid' => (int)$CFG->mnet_localhost_id,
                    'deleted' => 0,
                ]);
                foreach ($matches as $match) {
                    if ((int)$match->id !== (int)$user->id) {
                        throw new \invalid_parameter_exception('Email is already used by another Moodle account.');
                    }
                }
            }
        }
        $changed = false;
        $record = (object)['id' => (int)$user->id];
        foreach ($updates as $field => $value) {
            if ((string)$user->{$field} !== (string)$value) {
                $record->{$field} = $value;
                $changed = true;
            }
        }
        if ($changed) {
            require_once($CFG->dirroot . '/user/lib.php');
            user_update_user($record, false, false);
        }
        return $changed;
    }

    /** Soft-deactivate only an ERP-owned company employee role. */
    private static function deactivate_employee(\stdClass $company, ?\stdClass $mapping,
            string $system, string $externalid): array {
        global $DB;

        if (!$mapping || (string)$mapping->localentity !== 'user') {
            return [
                'status' => 'unmapped_inactive',
                'created' => false,
                'changed' => false,
                'moodle_company_id' => (int)$company->id,
                'moodle_user_id' => 0,
                'membership_id' => 0,
                'membership_created' => false,
                'account_created' => false,
                'profile_changed' => false,
                'managed_account' => false,
                'password_reset_required' => false,
                'active' => false,
                'access_roles' => [],
            ];
        }
        $metadata = self::mapping_metadata_values($mapping);
        $member = $DB->get_record('local_kbacorp_member', [
            'companyid' => (int)$company->id,
            'userid' => (int)$mapping->localid,
        ]);
        $changed = false;
        $managedmembership = !empty($metadata['managed_membership']);
        if ($managedmembership && $member && !empty($member->isemployee)) {
            $member->isemployee = 0;
            $member->membertype = (!empty($member->ismanager) || !empty($member->isdepartmentmanager) ||
                !empty($member->ishr)) ? 'manager' : 'employee';
            $member->timemodified = time();
            $DB->update_record('local_kbacorp_member', $member);
            $changed = true;
        }
        if ((string)$mapping->status !== 'inactive') {
            external_mapping::set_status((int)$mapping->id, 'inactive');
            $changed = true;
        }
        $roles = $member ? repository::get_member_roles($member) : [];
        return [
            'status' => $managedmembership ? ($changed ? 'employee_deactivated' : 'unchanged')
                : 'manual_membership_preserved',
            'created' => false,
            'changed' => $changed,
            'moodle_company_id' => (int)$company->id,
            'moodle_user_id' => (int)$mapping->localid,
            'membership_id' => $member ? (int)$member->id : 0,
            'membership_created' => false,
            'account_created' => false,
            'profile_changed' => false,
            'managed_account' => !empty($metadata['managed_account']),
            'password_reset_required' => false,
            'active' => false,
            'access_roles' => $roles,
        ];
    }

    /** Upsert one company department without taking ownership of a manual record. */
    private static function push_department(string $system, string $externalid, array $payload,
            string $idempotencykey): array {
        global $DB;

        $companyexternalid = self::external_id((string)($payload['company_external_id'] ?? ''));
        $company = self::resolve_company($system, $companyexternalid);
        if (!$company) {
            throw new \invalid_parameter_exception('Mapped company was not found. Push the company first.');
        }
        $active = !array_key_exists('active', $payload) || !empty($payload['active']);
        $name = trim(clean_param((string)($payload['name'] ?? ''), PARAM_TEXT));
        if ($active && ($name === '' || \core_text::strlen($name) > 255)) {
            throw new \invalid_parameter_exception('Department name is required and must not exceed 255 characters.');
        }
        $code = array_key_exists('code', $payload) ? self::bounded_optional($payload['code'], 100) : null;
        $mapping = self::find_mapping((int)$company->id, $system, 'department', $externalid);
        $metadata = self::mapping_metadata_values($mapping);
        $managed = $mapping ? !empty($metadata['managed']) : true;
        $created = false;
        $changed = false;

        if ($mapping) {
            if ((string)$mapping->localentity !== 'department') {
                throw new \invalid_parameter_exception('Department mapping has an invalid local target.');
            }
            $department = $DB->get_record('local_kbacorp_department', [
                'id' => (int)$mapping->localid,
                'companyid' => (int)$company->id,
            ]);
            if (!$department) {
                throw new \invalid_parameter_exception('Mapped department was not found.');
            }
        } else {
            $department = null;
            if ($code !== null) {
                $matches = array_values($DB->get_records('local_kbacorp_department', [
                    'companyid' => (int)$company->id,
                    'code' => $code,
                ], 'id ASC'));
                if (count($matches) > 1) {
                    throw new \invalid_parameter_exception('Department code matches more than one record.');
                }
                if ($matches) {
                    $department = reset($matches);
                    $managed = false;
                }
            }
            if (!$department) {
                if (!$active) {
                    return self::unmapped_inactive_result('department', (int)$company->id);
                }
                $departmentid = repository::save_department((object)[
                    'companyid' => (int)$company->id,
                    'name' => $name,
                    'code' => $code,
                    'active' => 1,
                ]);
                $department = $DB->get_record(
                    'local_kbacorp_department', ['id' => $departmentid], '*', MUST_EXIST
                );
                $created = true;
                $changed = true;
            }
        }

        if ($managed) {
            $updates = ['active' => $active ? 1 : 0];
            if ($active) {
                $updates['name'] = $name;
                if (array_key_exists('code', $payload)) {
                    $updates['code'] = $code;
                }
            }
            foreach ($updates as $field => $value) {
                if ((string)$department->{$field} !== (string)$value) {
                    $department->{$field} = $value;
                    $changed = true;
                }
            }
            if ($changed && !$created) {
                $department->timemodified = time();
                $DB->update_record('local_kbacorp_department', $department);
            }
        }
        external_mapping::upsert((int)$company->id, $system, 'department', 'department',
            (int)$department->id, $externalid, (string)($department->code ?? ''),
            self::mapping_metadata($idempotencykey, ['managed' => $managed ? 1 : 0]));
        $currentmapping = self::find_mapping((int)$company->id, $system, 'department', $externalid);
        if (!$active && $currentmapping) {
            external_mapping::set_status((int)$currentmapping->id, 'inactive');
        }

        return [
            'status' => !$managed ? 'manual_department_preserved' :
                ($created ? 'created' : ($changed ? ($active ? 'updated' : 'deactivated') : 'unchanged')),
            'created' => $created,
            'changed' => $changed || !$mapping,
            'moodle_company_id' => (int)$company->id,
            'department_id' => (int)$department->id,
            'managed' => $managed,
            'active' => $active && !empty($department->active),
        ];
    }

    /** Synchronise one immutable employee-to-department placement. */
    private static function push_department_membership(string $system, string $externalid, array $payload,
            string $idempotencykey): array {
        global $DB;

        $companyexternalid = self::external_id((string)($payload['company_external_id'] ?? ''));
        $departmentexternalid = self::external_id((string)($payload['department_external_id'] ?? ''));
        $employeeexternalid = self::external_id((string)($payload['employee_external_id'] ?? ''));
        $active = !array_key_exists('active', $payload) || !empty($payload['active']);
        $company = self::resolve_company($system, $companyexternalid);
        if (!$company) {
            throw new \invalid_parameter_exception('Mapped company was not found.');
        }
        $departmentmap = self::find_mapping(
            (int)$company->id, $system, 'department', $departmentexternalid
        );
        $employeemap = self::find_mapping((int)$company->id, $system, 'employee', $employeeexternalid);
        if (!$departmentmap || (string)$departmentmap->localentity !== 'department') {
            throw new \invalid_parameter_exception('Mapped department was not found.');
        }
        if (!$employeemap || (string)$employeemap->localentity !== 'user') {
            throw new \invalid_parameter_exception('Mapped employee was not found.');
        }
        $department = $DB->get_record('local_kbacorp_department', [
            'id' => (int)$departmentmap->localid,
            'companyid' => (int)$company->id,
        ]);
        if (!$department) {
            throw new \invalid_parameter_exception('Mapped department is missing.');
        }
        if ($active && ((string)$departmentmap->status !== 'active' ||
                (string)$employeemap->status !== 'active' || empty($department->active))) {
            throw new \invalid_parameter_exception('Department and employee must be active for assignment.');
        }
        $role = strtolower(trim((string)($payload['department_role'] ?? '')));
        if (!in_array($role, ['employee', 'manager'], true)) {
            throw new \invalid_parameter_exception('Department role must be employee or manager.');
        }
        $mapping = self::find_mapping(
            (int)$company->id, $system, 'department_membership', $externalid
        );
        $metadata = self::mapping_metadata_values($mapping);
        if ($mapping && ((int)($metadata['department_id'] ?? 0) !== (int)$department->id ||
                (int)($metadata['user_id'] ?? 0) !== (int)$employeemap->localid ||
                (string)($metadata['department_role'] ?? '') !== $role)) {
            throw new \invalid_parameter_exception(
                'Department membership external ID cannot be moved to another employee, department or role.'
            );
        }
        $assignment = $DB->get_record('local_kbacorp_dept_member', [
            'departmentid' => (int)$department->id,
            'userid' => (int)$employeemap->localid,
            'deptrole' => $role,
        ]);
        $managed = $mapping ? !empty($metadata['managed']) : !$assignment;
        $created = false;
        $changed = false;
        $assignmentid = $assignment ? (int)$assignment->id : 0;

        if ($active) {
            if (!$assignment) {
                if ($mapping && !$managed) {
                    throw new \invalid_parameter_exception('Preserved manual department assignment no longer exists.');
                }
                $assignmentid = repository::add_department_member(
                    (int)$department->id, (int)$employeemap->localid, $role
                );
                $created = true;
                $changed = true;
            }
            external_mapping::upsert((int)$company->id, $system, 'department_membership',
                'department_membership', $assignmentid, $externalid, '',
                self::mapping_metadata($idempotencykey, [
                    'managed' => $managed ? 1 : 0,
                    'department_id' => (int)$department->id,
                    'user_id' => (int)$employeemap->localid,
                    'department_role' => $role,
                ]));
        } else if ($mapping) {
            if ($managed && $assignment) {
                repository::delete_department_member((int)$assignment->id, (int)$department->id);
                $changed = true;
            }
            if ((string)$mapping->status !== 'inactive') {
                external_mapping::set_status((int)$mapping->id, 'inactive');
                $changed = true;
            }
        }

        return [
            'status' => !$active ? ($managed ? ($changed ? 'deactivated' : 'unchanged') :
                'manual_assignment_preserved') : ($created ? 'assigned' :
                ($managed ? 'unchanged' : 'manual_assignment_preserved')),
            'created' => $created,
            'changed' => $changed || (!$mapping && $active),
            'moodle_company_id' => (int)$company->id,
            'moodle_user_id' => (int)$employeemap->localid,
            'department_id' => (int)$department->id,
            'assignment_id' => $assignmentid,
            'department_role' => $role,
            'managed' => $managed,
            'active' => $active,
        ];
    }

    /** Upsert company-scoped business-role metadata, never competency requirements. */
    private static function push_business_role(string $system, string $externalid, array $payload,
            string $idempotencykey): array {
        global $DB;

        $companyexternalid = self::external_id((string)($payload['company_external_id'] ?? ''));
        $company = self::resolve_company($system, $companyexternalid);
        if (!$company) {
            throw new \invalid_parameter_exception('Mapped company was not found.');
        }
        $rolecode = business_roles::normalise_code((string)($payload['role_code'] ?? ''));
        $active = !array_key_exists('active', $payload) || !empty($payload['active']);
        $name = trim(clean_param((string)($payload['name'] ?? ''), PARAM_TEXT));
        if ($active && ($name === '' || \core_text::strlen($name) > 255)) {
            throw new \invalid_parameter_exception('Business role name is required and must not exceed 255 characters.');
        }
        $description = array_key_exists('description', $payload)
            ? trim(clean_param((string)$payload['description'], PARAM_TEXT)) : null;
        $mapping = self::find_mapping((int)$company->id, $system, 'business_role', $externalid);
        $metadata = self::mapping_metadata_values($mapping);
        $managed = $mapping ? !empty($metadata['managed']) : true;
        $created = false;
        $changed = false;

        if ($mapping) {
            if ((string)$mapping->localentity !== 'business_role') {
                throw new \invalid_parameter_exception('Business role mapping has an invalid local target.');
            }
            $role = $DB->get_record('local_kbacorp_busrole', [
                'id' => (int)$mapping->localid,
                'companyid' => (int)$company->id,
            ]);
            if (!$role || (string)$role->code !== $rolecode) {
                throw new \invalid_parameter_exception('Mapped business role was not found or its code changed.');
            }
        } else {
            $role = $DB->get_record('local_kbacorp_busrole', [
                'companyid' => (int)$company->id,
                'code' => $rolecode,
            ]);
            if ($role) {
                $managed = false;
            } else {
                if (!$active) {
                    return self::unmapped_inactive_result('business_role', (int)$company->id);
                }
                $roleid = business_roles::save_role((object)[
                    'companyid' => (int)$company->id,
                    'code' => $rolecode,
                    'name' => $name,
                    'description' => $description,
                    'active' => 1,
                    'source' => $system,
                ]);
                $role = business_roles::get_role($roleid);
                $created = true;
                $changed = true;
            }
        }

        if ($managed) {
            $desiredactive = $active ? 1 : 0;
            $changed = $changed || (int)$role->active !== $desiredactive ||
                ($active && ((string)$role->name !== $name ||
                    (array_key_exists('description', $payload) &&
                        (string)$role->description !== (string)$description)));
            if ($changed && !$created) {
                business_roles::save_role((object)[
                    'id' => (int)$role->id,
                    'companyid' => (int)$company->id,
                    'code' => $rolecode,
                    'name' => $active ? $name : (string)$role->name,
                    'description' => $active && array_key_exists('description', $payload)
                        ? $description : $role->description,
                    'active' => $desiredactive,
                    'source' => $system,
                ]);
                $role = business_roles::get_role((int)$role->id);
            }
        }
        external_mapping::upsert((int)$company->id, $system, 'business_role', 'business_role',
            (int)$role->id, $externalid, $rolecode,
            self::mapping_metadata($idempotencykey, ['managed' => $managed ? 1 : 0]));
        $currentmapping = self::find_mapping((int)$company->id, $system, 'business_role', $externalid);
        if (!$active && $currentmapping) {
            external_mapping::set_status((int)$currentmapping->id, 'inactive');
        }

        return [
            'status' => !$managed ? 'manual_business_role_preserved' :
                ($created ? 'created' : ($changed ? ($active ? 'updated' : 'deactivated') : 'unchanged')),
            'created' => $created,
            'changed' => $changed || !$mapping,
            'moodle_company_id' => (int)$company->id,
            'business_role_id' => (int)$role->id,
            'role_code' => $rolecode,
            'managed' => $managed,
            'competency_profile_changed' => false,
            'active' => $active && !empty($role->active),
        ];
    }

    /** Synchronise one company course-catalog entry without enrolment or plan mutation. */
    private static function push_learning_catalog_assignment(string $system, string $externalid,
            array $payload, string $idempotencykey): array {
        global $DB;

        $companyexternalid = self::external_id((string)($payload['company_external_id'] ?? ''));
        $company = self::resolve_company($system, $companyexternalid);
        if (!$company) {
            throw new \invalid_parameter_exception('Mapped company was not found.');
        }
        $course = self::resolve_course($payload);
        $groupid = max(0, (int)($payload['group_id'] ?? 0));
        if ($groupid > 0) {
            $group = $DB->get_record('groups', ['id' => $groupid], 'id,courseid');
            if (!$group || (int)$group->courseid !== (int)$course->id) {
                throw new \invalid_parameter_exception('Learning group does not belong to the selected course.');
            }
        }
        $active = !array_key_exists('active', $payload) || !empty($payload['active']);
        $mapping = self::find_mapping(
            (int)$company->id, $system, 'learning_catalog_assignment', $externalid
        );
        $metadata = self::mapping_metadata_values($mapping);
        $catalogue = repository::get_course_mapping((int)$company->id, (int)$course->id, $groupid);
        if ($mapping) {
            if ((string)$mapping->localentity !== 'course_mapping') {
                throw new \invalid_parameter_exception('Learning assignment mapping has an invalid local target.');
            }
            if ((int)($metadata['course_id'] ?? 0) !== (int)$course->id ||
                    (int)($metadata['group_id'] ?? 0) !== $groupid) {
                throw new \invalid_parameter_exception(
                    'Learning assignment external ID cannot be moved to another course or group.'
                );
            }
            if ($catalogue && (int)$mapping->localid !== (int)$catalogue->id) {
                throw new \invalid_parameter_exception('Learning assignment mapping points to another catalogue row.');
            }
        }
        $managed = $mapping ? !empty($metadata['managed']) : !$catalogue;
        if (!$mapping && $catalogue) {
            $othermap = $DB->get_record_sql('SELECT em.* FROM {local_kbacorp_extmap} em ' .
                'WHERE em.companyid = :companyid AND em.system = :system AND em.entitytype = :entitytype ' .
                'AND em.localentity = :localentity AND em.localid = :localid', [
                'companyid' => (int)$company->id,
                'system' => $system,
                'entitytype' => 'learning_catalog_assignment',
                'localentity' => 'course_mapping',
                'localid' => (int)$catalogue->id,
            ]);
            if ($othermap) {
                throw new \invalid_parameter_exception(
                    'Course catalogue row is already mapped to another external assignment ID.'
                );
            }
        }
        $created = false;
        $changed = false;
        $mappingid = $catalogue ? (int)$catalogue->id : 0;

        if ($active && $managed) {
            $wasactive = $catalogue && !empty($catalogue->active);
            $mappingid = repository::add_course((int)$company->id, (int)$course->id, $groupid);
            $created = !$catalogue;
            $changed = $created || !$wasactive;
            $catalogue = repository::get_course_mapping((int)$company->id, (int)$course->id, $groupid);
        } else if (!$active && $managed && $catalogue) {
            $result = repository::deactivate_course((int)$company->id, (int)$course->id, $groupid);
            $changed = !empty($result['changed']);
        }

        if ($active && !$catalogue) {
            throw new \invalid_parameter_exception('Preserved manual learning catalogue row no longer exists.');
        }
        if ($catalogue) {
            external_mapping::upsert((int)$company->id, $system, 'learning_catalog_assignment',
                'course_mapping', (int)$catalogue->id, $externalid, (string)($course->idnumber ?? ''),
                self::mapping_metadata($idempotencykey, [
                    'managed' => $managed ? 1 : 0,
                    'course_id' => (int)$course->id,
                    'group_id' => $groupid,
                ]));
            $currentmapping = self::find_mapping(
                (int)$company->id, $system, 'learning_catalog_assignment', $externalid
            );
            if (!$active && $currentmapping) {
                external_mapping::set_status((int)$currentmapping->id, 'inactive');
            }
        }

        return [
            'status' => !$managed && $catalogue ? 'manual_catalogue_assignment_preserved' :
                ($created ? 'created' : ($changed ? ($active ? 'reactivated' : 'deactivated') : 'unchanged')),
            'created' => $created,
            'changed' => $changed || (!$mapping && (bool)$catalogue),
            'moodle_company_id' => (int)$company->id,
            'catalogue_mapping_id' => $catalogue ? (int)$catalogue->id : $mappingid,
            'moodle_course_id' => (int)$course->id,
            'course_idnumber' => (string)($course->idnumber ?? ''),
            'group_id' => $groupid,
            'managed' => $managed,
            'active' => $active && $catalogue && !empty($catalogue->active),
            'enrolment_changed' => false,
            'learning_plan_changed' => false,
            'competency_level_changed' => false,
        ];
    }

    /** Upsert one ERP-owned economic value while preserving manual values and verification. */
    private static function push_economic_value(string $system, string $externalid, array $payload,
            string $idempotencykey): array {
        global $DB;

        $companyexternalid = self::external_id((string)($payload['company_external_id'] ?? ''));
        $company = self::resolve_company($system, $companyexternalid);
        if (!$company) {
            throw new \invalid_parameter_exception('Mapped company was not found.');
        }
        $indicatorcode = strtoupper(trim((string)($payload['indicator_code'] ?? '')));
        if (!preg_match('/^[A-Z0-9][A-Z0-9_-]{1,63}$/', $indicatorcode)) {
            throw new \invalid_parameter_exception('Invalid economic indicator code.');
        }
        $indicators = array_values($DB->get_records_select('local_kbacorp_econ_ind',
            '(companyid = 0 OR companyid = :companyid) AND code = :code AND active = 1', [
                'companyid' => (int)$company->id,
                'code' => $indicatorcode,
            ], 'companyid DESC, id ASC'));
        if (count($indicators) !== 1) {
            throw new \invalid_parameter_exception(
                count($indicators) ? 'Economic indicator code is ambiguous.' : 'Economic indicator was not found.'
            );
        }
        $indicator = reset($indicators);
        $periodtype = strtolower(trim((string)($payload['period_type'] ?? '')));
        if (!in_array($periodtype, economic_indicators::period_types(), true)) {
            throw new \invalid_parameter_exception('Unsupported economic period type.');
        }
        $periodstart = max(0, (int)($payload['period_start'] ?? 0));
        $periodend = max(0, (int)($payload['period_end'] ?? $periodstart));
        $period = economic_indicators::normalise_period($periodtype, $periodstart, $periodend);
        $scenario = strtolower(trim((string)($payload['scenario'] ?? economic_indicators::SCENARIO_ACTUAL)));
        if (!in_array($scenario, economic_indicators::scenarios(), true)) {
            throw new \invalid_parameter_exception('Unsupported economic scenario.');
        }
        $currency = strtoupper(trim((string)($payload['currency'] ?? 'RUB')));
        if (!in_array($currency, ['RUB', 'USD', 'EUR', 'KZT'], true)) {
            throw new \invalid_parameter_exception('Unsupported currency.');
        }
        if (!array_key_exists('value', $payload) || !is_scalar($payload['value'])) {
            throw new \invalid_parameter_exception('A numeric economic value is required.');
        }
        $number = economic_indicators::parse_decimal((string)$payload['value']);
        if ($number === null) {
            throw new \invalid_parameter_exception('A numeric economic value is required.');
        }
        $mapping = self::find_mapping((int)$company->id, $system, 'economic_value', $externalid);
        $existing = $DB->get_record('local_kbacorp_econ_val', [
            'companyid' => (int)$company->id,
            'indicatorid' => (int)$indicator->id,
            'periodkey' => (string)$period->periodkey,
            'scenario' => $scenario,
        ]);
        if ($mapping) {
            if ((string)$mapping->localentity !== 'economic_value' || !$existing ||
                    (int)$mapping->localid !== (int)$existing->id) {
                throw new \invalid_parameter_exception('Economic value mapping identity does not match the payload.');
            }
        } else if ($existing) {
            throw new \invalid_parameter_exception(
                'Economic period already contains a manual or differently mapped value.'
            );
        }
        if ($existing && (string)$existing->source !== economic_indicators::SOURCE_ERP) {
            throw new \invalid_parameter_exception('ERP cannot overwrite a manually managed economic value.');
        }
        $created = !$existing;
        $changed = !$existing || (float)$existing->value !== (float)$number ||
            (string)$existing->currency !== $currency;
        if ($changed) {
            $valueid = economic_indicators::upsert_external_value(
                (int)$company->id, $indicatorcode, $periodtype,
                (int)$period->periodstart, (int)$period->periodend, (string)$number,
                economic_indicators::SOURCE_ERP, 0, 0, $currency, '', $scenario
            );
            $existing = $DB->get_record('local_kbacorp_econ_val', ['id' => $valueid], '*', MUST_EXIST);
        }
        external_mapping::upsert((int)$company->id, $system, 'economic_value', 'economic_value',
            (int)$existing->id, $externalid, $indicatorcode,
            self::mapping_metadata($idempotencykey, [
                'managed' => 1,
                'indicator_code' => $indicatorcode,
                'period_key' => (string)$period->periodkey,
                'scenario' => $scenario,
            ]));

        return [
            'status' => $created ? 'created' : ($changed ? 'updated' : 'unchanged'),
            'created' => $created,
            'changed' => $changed,
            'moodle_company_id' => (int)$company->id,
            'economic_value_id' => (int)$existing->id,
            'indicator_code' => $indicatorcode,
            'period_key' => (string)$period->periodkey,
            'scenario' => $scenario,
            'value' => (float)$existing->value,
            'currency' => (string)$existing->currency,
            'verified' => !empty($existing->verified),
            'causality_established' => false,
            'competency_level_changed' => false,
            'bms_maturity_changed' => false,
        ];
    }

    private static function push_business_role_assignment(string $system, string $externalid,
            array $payload, string $idempotencykey): array {
        global $DB;

        $companyexternalid = self::external_id((string)($payload['company_external_id'] ?? ''));
        $employeeexternalid = self::external_id((string)($payload['employee_external_id'] ?? ''));
        $active = !array_key_exists('active', $payload) || !empty($payload['active']);
        $company = self::resolve_company($system, $companyexternalid);
        if (!$company) {
            throw new \invalid_parameter_exception('Mapped company was not found.');
        }
        $employeemap = self::find_mapping((int)$company->id, $system, 'employee', $employeeexternalid);
        if (!$employeemap || (string)$employeemap->localentity !== 'user' ||
                ($active && (string)$employeemap->status !== 'active')) {
            throw new \invalid_parameter_exception('Mapped employee was not found. Push the employee first.');
        }
        $rolecode = business_roles::normalise_code((string)($payload['role_code'] ?? ''));
        $roleconditions = ['companyid' => (int)$company->id, 'code' => $rolecode];
        if ($active) {
            $roleconditions['active'] = 1;
        }
        $role = $DB->get_record('local_kbacorp_busrole', $roleconditions);
        if (!$role) {
            $roleconditions = ['companyid' => 0, 'code' => $rolecode];
            if ($active) {
                $roleconditions['active'] = 1;
            }
            $role = $DB->get_record('local_kbacorp_busrole', $roleconditions);
        }
        if (!$role) {
            throw new \invalid_parameter_exception('Business role was not found.');
        }
        $mapping = self::find_mapping((int)$company->id, $system, 'business_role_assignment', $externalid);
        $changed = false;
        if ($active) {
            $existingassignment = $DB->get_record('local_kbacorp_member_busrole', [
                'companyid' => (int)$company->id,
                'userid' => (int)$employeemap->localid,
                'roleid' => (int)$role->id,
            ]);
            $managed = !$existingassignment ||
                ((string)$existingassignment->source === $system &&
                    (string)$existingassignment->externalref === $externalid);
            $assignmentid = business_roles::assign_user_role((int)$company->id, (int)$employeemap->localid,
                (int)$role->id, $system, $externalid, !$managed);
            $changed = !$mapping || (string)$mapping->status !== 'active' || (int)$mapping->localid !== $assignmentid;
            external_mapping::upsert((int)$company->id, $system, 'business_role_assignment',
                'business_role_assignment', $assignmentid, $externalid, $rolecode,
                self::mapping_metadata($idempotencykey, ['managed' => $managed ? 1 : 0]));
            $status = $managed ? ($changed ? 'assigned' : 'unchanged') : 'already_assigned_preserved';
        } else {
            $assignmentid = $mapping ? (int)$mapping->localid : 0;
            $metadata = $mapping && !empty($mapping->metadata) ? json_decode($mapping->metadata, true) : [];
            $managed = !empty($metadata['managed']);
            if ($managed && $assignmentid > 0 && $DB->record_exists('local_kbacorp_member_busrole', [
                    'id' => $assignmentid, 'companyid' => (int)$company->id, 'active' => 1,
                ])) {
                business_roles::deactivate_user_role((int)$company->id, $assignmentid);
                $changed = true;
            }
            if ($mapping) {
                external_mapping::set_status((int)$mapping->id, 'inactive');
            }
            $status = $changed ? 'deactivated' : 'unchanged';
        }

        return [
            'status' => $status,
            'created' => $active && !$mapping,
            'changed' => $changed,
            'moodle_company_id' => (int)$company->id,
            'moodle_user_id' => (int)$employeemap->localid,
            'business_role_id' => (int)$role->id,
            'assignment_id' => $assignmentid,
            'active' => $active,
        ];
    }

    private static function employee_snapshot(string $system, \stdClass $company, \stdClass $member): array {
        global $DB;

        $mapping = $DB->get_record_sql('SELECT em.* FROM {local_kbacorp_extmap} em ' .
            'WHERE em.companyid = :companyid AND em.system = :system AND em.entitytype = :entitytype ' .
            'AND em.localentity = :localentity AND em.localid = :localid AND em.status = :status', [
            'companyid' => (int)$company->id,
            'system' => $system,
            'entitytype' => 'employee',
            'localentity' => 'user',
            'localid' => (int)$member->userid,
            'status' => 'active',
        ]);
        $businessroles = [];
        foreach (business_roles::get_user_roles((int)$member->userid, (int)$company->id) as $role) {
            $businessroles[] = [
                'code' => (string)$role->code,
                'uuid' => (string)$role->uuid,
                'name' => (string)$role->name,
            ];
        }
        $departments = [];
        foreach (departments::get_user_departments((int)$company->id, (int)$member->userid, 'employee') as $department) {
            $departments[] = [
                'code' => (string)($department->code ?? ''),
                'name' => (string)$department->name,
            ];
        }
        $competencies = [];
        $snapshot = competencies::get_user_snapshot((int)$member->userid, (int)$company->id, false);
        foreach ($snapshot['competencies'] as $item) {
            $currentlevel = $item->currentlevel === null ? null : (int)$item->currentlevel;
            $competencies[] = [
                'code' => (string)$item->idnumber,
                'name' => (string)$item->shortname,
                'current_level' => $currentlevel,
                'target_level' => $item->targetlevel === null ? null : (int)$item->targetlevel,
                'gap' => $item->gap === null ? null : (int)$item->gap,
                'status' => (string)$item->displaystatus,
                'proficient' => !empty($item->proficiency),
                'assessment_status' => $currentlevel === null ? 'unassessed' :
                    (!empty($item->proficiency) ? 'confirmed' : 'recorded'),
                'learning_completion_confirms_level' => false,
                'assessed_at' => (int)($item->assessedat ?? 0),
            ];
        }
        $plans = [];
        foreach (development_plans::get_company_plans((int)$company->id) as $plan) {
            if ((int)$plan->userid !== (int)$member->userid) {
                continue;
            }
            $progress = development_plans::get_progress_summary(development_plans::get_items((int)$plan->id));
            $plans[] = [
                'id' => (int)$plan->id,
                'status' => (string)$plan->status,
                'start_date' => (int)$plan->startdate,
                'target_date' => (int)$plan->targetdate,
                'progress_percent' => (int)$progress->percent,
                'modified_at' => (int)$plan->timemodified,
            ];
        }

        return [
            'external_id' => $mapping ? (string)$mapping->externalid : '',
            'external_code' => $mapping ? (string)$mapping->externalcode : '',
            'moodle_user_id' => (int)$member->userid,
            'active' => empty($member->deleted) && empty($member->suspended),
            'access_roles' => repository::get_member_roles($member),
            'business_roles' => $businessroles,
            'departments' => $departments,
            'competencies' => $competencies,
            'learning' => self::employee_learning_snapshot(
                $system, (int)$company->id, (int)$member->userid
            ),
            'development_plans' => $plans,
            'modified_at' => (int)$member->timemodified,
        ];
    }

    /** Return active organisation metadata without employee PII. */
    private static function organisation_snapshot(string $system, \stdClass $company): array {
        global $DB;

        $maps = $DB->get_records_sql('SELECT em.* FROM {local_kbacorp_extmap} em ' .
            'WHERE em.companyid = :companyid AND em.system = :system AND em.status = :status ' .
                'AND em.entitytype IN (:department, :businessrole)', [
                'companyid' => (int)$company->id,
                'system' => $system,
                'status' => 'active',
                'department' => 'department',
                'businessrole' => 'business_role',
            ]);
        $external = [];
        foreach ($maps as $map) {
            $external[(string)$map->localentity . ':' . (int)$map->localid] = [
                'external_id' => (string)$map->externalid,
                'external_code' => (string)($map->externalcode ?? ''),
            ];
        }
        $departments = [];
        foreach ($DB->get_records('local_kbacorp_department', [
                'companyid' => (int)$company->id,
                'active' => 1,
            ], 'name ASC') as $department) {
            $map = $external['department:' . (int)$department->id] ?? [];
            $departments[] = [
                'external_id' => (string)($map['external_id'] ?? ''),
                'external_code' => (string)($map['external_code'] ?? ''),
                'moodle_department_id' => (int)$department->id,
                'code' => (string)($department->code ?? ''),
                'name' => (string)$department->name,
                'employee_count' => (int)$DB->count_records('local_kbacorp_dept_member', [
                    'departmentid' => (int)$department->id, 'deptrole' => 'employee',
                ]),
                'manager_count' => (int)$DB->count_records('local_kbacorp_dept_member', [
                    'departmentid' => (int)$department->id, 'deptrole' => 'manager',
                ]),
            ];
        }
        $roles = [];
        foreach ($DB->get_records('local_kbacorp_busrole', [
                'companyid' => (int)$company->id,
                'active' => 1,
            ], 'name ASC') as $role) {
            $map = $external['business_role:' . (int)$role->id] ?? [];
            $roles[] = [
                'external_id' => (string)($map['external_id'] ?? ''),
                'external_code' => (string)($map['external_code'] ?? ''),
                'moodle_business_role_id' => (int)$role->id,
                'code' => (string)$role->code,
                'uuid' => (string)$role->uuid,
                'name' => (string)$role->name,
                'assignment_count' => (int)$DB->count_records('local_kbacorp_member_busrole', [
                    'companyid' => (int)$company->id, 'roleid' => (int)$role->id, 'active' => 1,
                ]),
                'competency_requirement_count' => (int)$DB->count_records('local_kbacorp_rolecomp', [
                    'roleid' => (int)$role->id,
                ]),
            ];
        }
        return ['departments' => $departments, 'business_roles' => $roles];
    }

    /** Return Moodle-owned learning state for the company catalogue without PII. */
    private static function employee_learning_snapshot(string $system, int $companyid, int $userid): array {
        global $DB;

        $sql = "SELECT cc.id AS mappingid, cc.courseid, cc.groupid,
                       c.fullname, c.shortname, c.idnumber
                  FROM {local_kbacorp_course} cc
                  JOIN {course} c ON c.id = cc.courseid
                 WHERE cc.companyid = :companyid
                   AND cc.active = 1
              ORDER BY c.fullname ASC, cc.id ASC";
        $result = [];
        foreach ($DB->get_records_sql($sql, ['companyid' => $companyid]) as $course) {
            if ((int)$course->groupid > 0 && !$DB->record_exists('groups_members', [
                    'groupid' => (int)$course->groupid,
                    'userid' => $userid,
                ])) {
                continue;
            }
            $externalmap = $DB->get_record_sql('SELECT em.* FROM {local_kbacorp_extmap} em ' .
                'WHERE em.companyid = :companyid AND em.system = :system AND em.entitytype = :entitytype ' .
                'AND em.localentity = :localentity AND em.localid = :localid AND em.status = :status', [
                'companyid' => $companyid,
                'system' => $system,
                'entitytype' => 'learning_catalog_assignment',
                'localentity' => 'course_mapping',
                'localid' => (int)$course->mappingid,
                'status' => 'active',
            ]);
            $enrolled = is_enrolled(\context_course::instance((int)$course->courseid), $userid, '', true);
            $completedat = (int)$DB->get_field('course_completions', 'timecompleted', [
                'course' => (int)$course->courseid,
                'userid' => $userid,
            ]);
            $lastaccess = (int)$DB->get_field('user_lastaccess', 'timeaccess', [
                'courseid' => (int)$course->courseid,
                'userid' => $userid,
            ]);
            $status = !$enrolled ? 'not_enrolled' : ($completedat > 0 ? 'completed' :
                ($lastaccess > 0 ? 'in_progress' : 'not_started'));
            $result[] = [
                'external_assignment_id' => $externalmap ? (string)$externalmap->externalid : '',
                'moodle_course_id' => (int)$course->courseid,
                'course_idnumber' => (string)($course->idnumber ?? ''),
                'shortname' => (string)$course->shortname,
                'name' => (string)$course->fullname,
                'group_id' => (int)$course->groupid,
                'enrolled' => $enrolled,
                'completion_status' => $status,
                'completed_at' => $completedat,
                'last_access' => $lastaccess,
                'competency_level_changed_by_completion' => false,
            ];
        }
        return $result;
    }

    /** Return the latest value per indicator/scenario as non-causal economic context. */
    private static function economic_snapshot(\stdClass $company): array {
        global $DB;

        $sql = "SELECT v.*, i.code, i.name, i.valuetype, i.unit, i.direction
                  FROM {local_kbacorp_econ_val} v
                  JOIN {local_kbacorp_econ_ind} i ON i.id = v.indicatorid
                 WHERE v.companyid = :companyid
                   AND i.active = 1
              ORDER BY v.periodend DESC, v.id DESC";
        $latest = [];
        foreach ($DB->get_records_sql($sql, ['companyid' => (int)$company->id]) as $value) {
            $key = (string)$value->code . ':' . (string)$value->scenario;
            if (isset($latest[$key])) {
                continue;
            }
            $latest[$key] = [
                'indicator_code' => (string)$value->code,
                'indicator_name' => (string)$value->name,
                'value_type' => (string)$value->valuetype,
                'unit' => (string)$value->unit,
                'direction' => (string)$value->direction,
                'period_type' => (string)$value->periodtype,
                'period_key' => (string)$value->periodkey,
                'period_start' => (int)$value->periodstart,
                'period_end' => (int)$value->periodend,
                'scenario' => (string)$value->scenario,
                'value' => (float)$value->value,
                'currency' => (string)$value->currency,
                'source' => (string)$value->source,
                'verified' => !empty($value->verified),
            ];
        }
        return [
            'values' => array_values($latest),
            'causality_established' => false,
            'interpretation' => 'correlation_only',
        ];
    }

    private static function resolve_company(string $system, string $externalid) {
        global $DB;
        $mapping = self::find_mapping(0, $system, 'company', $externalid);
        if (!$mapping || (string)$mapping->localentity !== 'company') {
            return false;
        }
        global $USER;
        access::require_not_revoked((int)$mapping->localid, (int)($USER->id ?? 0));
        return $DB->get_record('local_kbacorp_company', ['id' => (int)$mapping->localid]);
    }

    private static function find_mapping(int $companyid, string $system, string $entitytype,
            string $externalid): ?\stdClass {
        global $DB;
        // Qualified identifiers also work when SYSTEM is reserved (MySQL 8).
        // Include inactive mappings: lifecycle handlers decide whether to reactivate.
        $mapping = $DB->get_record_sql('SELECT em.* FROM {local_kbacorp_extmap} em ' .
            'WHERE em.companyid = :companyid AND em.system = :system ' .
            'AND em.entitytype = :entitytype AND em.externalid = :externalid', [
            'companyid' => $companyid,
            'system' => $system,
            'entitytype' => $entitytype,
            'externalid' => $externalid,
        ]);
        // Moodle represents a missing record as false; typed consumers expect null.
        return $mapping ?: null;
    }

    private static function mapping_metadata(string $idempotencykey, array $extra = []): array {
        $metadata = $idempotencykey === '' ? ['contract_version' => self::CONTRACT_VERSION] : [
            'contract_version' => self::CONTRACT_VERSION,
            'idempotency_key' => $idempotencykey,
        ];
        return $metadata + $extra;
    }

    /** Decode technical mapping metadata without accepting non-object JSON. */
    private static function mapping_metadata_values(?\stdClass $mapping): array {
        if (!$mapping || trim((string)($mapping->metadata ?? '')) === '') {
            return [];
        }
        $metadata = json_decode((string)$mapping->metadata, true);
        return is_array($metadata) ? $metadata : [];
    }

    /** Resolve one Moodle course by ID or unique idnumber. */
    private static function resolve_course(array $payload): \stdClass {
        global $DB;

        $courseid = max(0, (int)($payload['moodle_course_id'] ?? 0));
        $idnumber = trim((string)($payload['course_idnumber'] ?? ''));
        if (\core_text::strlen($idnumber) > 100) {
            throw new \invalid_parameter_exception('Course idnumber is too long.');
        }
        $course = $courseid > 0 ? $DB->get_record('course', ['id' => $courseid]) : null;
        if ($courseid > 0 && (!$course || (int)$course->id === SITEID)) {
            throw new \invalid_parameter_exception('Moodle course was not found.');
        }
        if (!$course && $idnumber !== '') {
            $matches = array_values($DB->get_records('course', ['idnumber' => $idnumber], 'id ASC'));
            $matches = array_values(array_filter($matches, static function($item) {
                return (int)$item->id !== SITEID;
            }));
            if (count($matches) !== 1) {
                throw new \invalid_parameter_exception(
                    count($matches) ? 'Course idnumber is ambiguous.' : 'Moodle course was not found.'
                );
            }
            $course = reset($matches);
        }
        if (!$course) {
            throw new \invalid_parameter_exception('Provide moodle_course_id or a unique course_idnumber.');
        }
        if ($idnumber !== '' && (string)$course->idnumber !== $idnumber) {
            throw new \invalid_parameter_exception('Course ID and idnumber resolve to different courses.');
        }
        return $course;
    }

    /** Standard no-op response for an inactive entity that has never been mapped. */
    private static function unmapped_inactive_result(string $entitytype, int $companyid): array {
        return [
            'status' => 'unmapped_inactive',
            'created' => false,
            'changed' => false,
            'entity_type' => $entitytype,
            'moodle_company_id' => $companyid,
            'managed' => false,
            'active' => false,
        ];
    }

    /** Validate a Moodle username supplied by ERP. */
    private static function employee_username(string $value): string {
        $value = \core_text::strtolower(trim($value));
        if ($value === '') {
            return '';
        }
        $clean = clean_param($value, PARAM_USERNAME);
        if ($clean !== $value || \core_text::strlen($value) > 100) {
            throw new \invalid_parameter_exception('Invalid employee username.');
        }
        return $value;
    }

    /** Validate a required employee name field. */
    private static function employee_name(string $value, string $label): string {
        $value = trim(clean_param($value, PARAM_TEXT));
        if ($value === '' || \core_text::strlen($value) > 100) {
            throw new \invalid_parameter_exception($label . ' is required and must not exceed 100 characters.');
        }
        return $value;
    }

    /** Validate an employee email, optionally allowing it to be omitted for lookup. */
    private static function employee_email(string $value, bool $required): string {
        $value = trim($value);
        if ($value === '' && !$required) {
            return '';
        }
        $clean = clean_param($value, PARAM_EMAIL);
        if ($clean === '' || $clean !== $value || !validate_email($clean) || \core_text::strlen($clean) > 100) {
            throw new \invalid_parameter_exception('A valid employee email is required.');
        }
        return $clean;
    }

    private static function token(string $value, int $max, string $label): string {
        $value = strtolower(trim($value));
        if ($value === '' || strlen($value) > $max || !preg_match('/^[a-z0-9_.-]+$/', $value)) {
            throw new \invalid_parameter_exception('Invalid ERP ' . $label . '.');
        }
        return $value;
    }

    private static function external_id(string $value): string {
        $value = trim($value);
        if ($value === '' || strlen($value) > 255) {
            throw new \invalid_parameter_exception('External ID is required and must not exceed 255 bytes.');
        }
        return $value;
    }

    private static function bounded_optional($value, int $max): ?string {
        $value = trim((string)$value);
        if (\core_text::strlen($value) > $max) {
            throw new \invalid_parameter_exception('External code is too long.');
        }
        return $value === '' ? null : $value;
    }

    /** Encode the ordered contract definition for a deterministic fingerprint. */
    private static function encode_for_hash(array $value): string {
        $json = json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($json === false) {
            throw new \coding_exception('Unable to fingerprint ERP API contract.');
        }
        return $json;
    }

    /** Bound nested array complexity for bridge callers as well as REST callers. */
    private static function assert_payload_limits(array $payload): void {
        $encoded = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($encoded === false || strlen($encoded) > self::MAX_PAYLOAD_BYTES) {
            throw new \invalid_parameter_exception('ERP payload is invalid or too large.');
        }
        $nodes = 0;
        $walk = static function($value, int $depth) use (&$walk, &$nodes): void {
            if ($depth > self::MAX_PAYLOAD_DEPTH) {
                throw new \invalid_parameter_exception('ERP payload nesting is too deep.');
            }
            $nodes++;
            if ($nodes > self::MAX_PAYLOAD_NODES) {
                throw new \invalid_parameter_exception('ERP payload contains too many values.');
            }
            if (is_array($value)) {
                foreach ($value as $key => $item) {
                    if (is_string($key) && strlen($key) > 100) {
                        throw new \invalid_parameter_exception('ERP payload key is too long.');
                    }
                    $walk($item, $depth + 1);
                }
            } else if (!is_scalar($value) && $value !== null) {
                throw new \invalid_parameter_exception('ERP payload contains an unsupported value.');
            }
        };
        $walk($payload, 1);
    }

    /** Serialize duplicate deliveries for one provider-owned entity. */
    private static function get_lock(string $system, string $entitytype, string $externalid) {
        $factory = \core\lock\lock_config::get_lock_factory('local_kbacorporate');
        $key = 'erp_' . sha1($system . "\n" . $entitytype . "\n" . $externalid);
        $lock = $factory->get_lock($key, 10);
        if (!$lock) {
            throw new \moodle_exception('bridgelockfailed', 'local_kbacorporate', '', $externalid);
        }
        return $lock;
    }
}
