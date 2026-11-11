<?php
namespace local_kbacompany\local;

defined('MOODLE_INTERNAL') || die();

/** Provider-neutral Company Core service boundary. */
class company_service {
    public const CONTRACT_VERSION = '0.6.7';
    public const STORAGE_MODE = 'canonical_with_compatibility_adapter';

    /** Machine-readable foundation contract. */
    public static function contract(): array {
        $contract = [
            'component' => 'local_kbacompany',
            'product' => 'KBA Business System',
            'contract' => 'kba_company_core',
            'version' => self::CONTRACT_VERSION,
            'stability' => 'organization_foundation',
            'company_identity' => 'uuid_v4',
            'membership' => 'many_to_many',
            'storage_mode' => self::STORAGE_MODE,
            'dependency_policy' => 'runtime_optional_adapter',
            'source_of_truth' => [
                'company_profile' => 'local_kbacompany.company',
                'company_membership' => 'local_kbacompany.member',
                'external_identity' => 'local_kbacompany.extmap',
                'integration_outbox' => 'local_kbacompany.syncout', // Legacy compatibility alias.
                'legacy_integration_command_outbox' => 'local_kbacompany.syncout',
                'provider_neutral_event_outbox' => 'local_kbacompany.eventout',
                'company_context_boundary' => 'local_kbacompany.company_context_service',
                'cloud_workspace_projection' => 'local_kbacompany.workspace_mapping_service',
                'entitlement_boundary' => 'local_kbacompany.entitlement_service',
                'entitlement_snapshot_cache' => 'local_kbacompany.entitlement_snapshot_service',
                'platform_event_envelope' => 'local_kbacompany.platform_event_envelope',
                'legacy_corporate_learning' => 'local_kbacorporate',
                'stable_identity' => 'local_kbacompany.identity_registry',
                'organizing_board' => 'local_kbacompany.organizing_board',
                'positions_staffing' => 'local_kbacompany.positions_staffing',
                'staffing_plan_placement' => 'local_kbacompany.staffing_plan_placement',
                'policies_directives' => 'local_kbacompany.policy_service',
                'semantic_relations' => 'local_kbacompany.semantic_service',
                'ofs_ckp' => 'local_kbacompany.ofs_service',
                'process_architecture' => 'local_kbacompany.process_service',
                'process_model' => 'local_kbacompany.process_model_service',
                'stp_raci' => 'local_kbacompany.stp_service',
                'function_model' => 'local_kbacompany.function_service',
                'document_control' => 'local_kbacompany.document_service',
                'position_operating_system' => 'local_kbacompany.position_operating_service',
                'market_business_model' => 'local_kbacompany.market_model_service',
                'owner_lpf_model' => 'local_kbacompany.owner_service',
                'ownership_strategy_bsc' => 'local_kbacompany.strategy_service',
                'administrative_scale' => 'local_kbacompany.administrative_scale_service',
                'administrative_cycle' => 'local_kbacompany.administrative_cycle_service',
                'meetings_decisions' => 'local_kbacompany.meeting_service',
                'task_center' => 'local_kbacompany.task_service',
                'recurring_tasks' => 'local_kbacompany.recurring_task_service',
                'recurring_task_library' => 'local_kbacompany.recurring_task_library_service',
                'recurring_task_library_admin' => 'local_kbacompany.recurring_task_library_admin_service',
                'battle_plan' => 'local_kbacompany.battle_plan_service',
                'battle_plan_indicator_projection' => 'local_kbacompany.battle_plan_indicator_service',
                'battle_plan_management_overview' => 'local_kbacompany.battle_plan_management_overview_service',
                'battle_plan_templates_quotas' => 'local_kbacompany.battle_plan_template_service',
                'indicator_registry' => 'local_kbacompany.indicator_registry_service',
                'indicator_management_cycle' => 'local_kbacompany.indicator_management_cycle_service',
                'communication_core' => 'local_kbacompany.communication_service',
                'unified_task_sources' => 'local_kbacompany.task_provider_service',
                'communication_center' => 'local_kbacompany.communication_center_service',
                'company_employee_sync' => 'local_kbacompany.integration_service',
                'financial_model' => 'local_kbacompany.financial_model_service',
                'financial_statements' => 'local_kbacompany.financial_statement_service',
                'budgeting' => 'local_kbacompany.budgeting_service',
                'resource_gate' => 'local_kbacompany.resource_gate_service',
            ],
            'write_policy' => 'explicit_company_scoped_commands',
            'operations' => ['contract', 'my_companies', 'company_context',
                'organization_context', 'business_roles_context', 'organizing_board_context',
                'positions_context', 'staffing_plan_context', 'staffing_placement_context', 'products_context',
                'cascade_context', 'execution_context', 'deviations_context', 'flows_context', 'policies_context',
                'semantic_context', 'ofs_context', 'process_context', 'process_model_context', 'stp_context', 'functions_context', 'documents_context', 'position_operating_context', 'market_model_context', 'owner_model_context', 'strategy_context', 'administrative_scale_context', 'administrative_cycle_context', 'meetings_context', 'task_center_context', 'recurring_task_context', 'unified_task_context', 'battle_plan_context', 'battle_plan_management_overview_context', 'battle_plan_template_context', 'communication_context', 'communication_center_context', 'indicator_registry_context', 'indicator_management_exception_context', 'indicator_management_cycle_context', 'financial_model_context', 'financial_statement_context', 'budgeting_context', 'resource_gate_context', 'employees_context', 'integration_diagnostics', 'platform_context'],
            'objects' => ['Company', 'CompanyMembership', 'OrgUnit', 'Position',
                'EmployeeOrgAssignment', 'BusinessRole', 'EmployeeRoleAssignment',
                'RoleCompetencyRequirement', 'OrganizationBranch', 'OrganizingBoardNode',
                'PositionDefinition', 'StaffingPlan', 'StaffingPlanLine', 'StaffingSlot', 'PositionAssignment',
                'Policy', 'ManagementDirective', 'SemanticRelation', 'ValuableFinalProduct', 'OFSView', 'Process', 'Activity', 'ProcessModel', 'GeneratedSTP', 'RACIAssignment', 'CanonicalFunction', 'ControlledDocument', 'DocumentRecord', 'PositionWorkProgramItem', 'DigitalJobFolder', 'Market', 'CustomerSegment', 'Offering', 'BusinessModel', 'OwnerFrame', 'OwnerRequirement', 'OwnershipIntent', 'Strategy', 'StrategicGoal', 'StrategicCausalLink', 'AdministrativeScaleItem', 'AdministrativeCycle', 'Meeting', 'Decision', 'Task', 'RecurringTaskDefinition', 'RecurringTaskTemplate', 'TaskLibraryCategory', 'TaskLibraryTemplateRevision', 'TaskChecklistItem', 'WeeklyBattlePlan', 'BattlePlanTemplate', 'BattlePlanQuotaSet', 'BattlePlanCondition', 'CommunicationThread', 'CommunicationMessage', 'CommunicationNotification', 'TaskProjection', 'TaskSourceBinding', 'IndicatorPassport', 'IndicatorBinding', 'IndicatorNorm', 'FinancialScenario', 'FinancialResponsibilityCenter', 'FinancialStatement', 'FinancialStatementLine', 'Budget', 'BudgetLine', 'BudgetFactSnapshot', 'ResourceGate', 'ResourceGateItem', 'ExternalIdentity', 'IntegrationCommand', 'IntegrationEvent', 'EntitlementSnapshot'],
            'future_objects' => [],
            'subcontracts' => [
                'organization' => organization_service::contract(),
                'business_roles' => business_role_service::contract(),
                'organizing_board' => organizing_board_service::contract(),
                'positions_staffing' => position_service::contract(),
                'staffing_plan_placement' => staffing_service::contract(),
                'products_statistics' => product_service::contract(),
                'product_flows' => flow_service::contract(),
                'org_design' => design_service::contract(),
                'administrative_cascade' => cascade_service::contract(),
                'cascade_execution' => workflow_service::contract(),
                'deviation_management' => deviation_service::contract(),
                'policies_directives' => policy_service::contract(),
                'permissions' => permission_service::contract(),
                'semantic_core' => semantic_service::contract(),
                'ofs_ckp' => ofs_service::contract(),
                'process_architecture' => process_service::contract(),
                'process_model' => process_model_service::contract(),
                'stp_raci' => stp_service::contract(),
                'function_model' => function_service::contract(),
                'document_control' => document_service::contract(),
                'position_operating_system' => position_operating_service::contract(),
                'market_business_model' => market_model_service::contract(),
                'owner_lpf_model' => owner_service::contract(),
                'ownership_strategy_bsc' => strategy_service::contract(),
                'administrative_scale' => administrative_scale_service::contract(),
                'administrative_cycle' => administrative_cycle_service::contract(),
                'meetings_decisions' => meeting_service::contract(),
                'task_center' => task_service::contract(),
                'recurring_tasks' => recurring_task_service::contract(),
                'recurring_task_library' => recurring_task_library_service::contract(),
                'recurring_task_library_admin' => recurring_task_library_admin_service::contract(),
                'battle_plan' => battle_plan_service::contract(),
                'battle_plan_indicator_projection' => battle_plan_indicator_service::contract(),
                'battle_plan_management_overview' => battle_plan_management_overview_service::contract(),
                'battle_plan_templates_quotas' => battle_plan_template_service::contract(),
                'indicator_registry' => indicator_registry_service::contract(),
                'indicator_management_exceptions' => indicator_management_exception_service::contract(),
                'indicator_management_cycle' => indicator_management_cycle_service::contract(),
                'communication_core' => communication_service::contract(),
                'unified_task_sources' => task_provider_service::contract(),
                'communication_center' => communication_center_service::contract(),
                'ui_presentation_model' => bms_ui_service::contract(),
                'company_registry' => company_registry_service::contract(),
                'employees' => employee_service::contract(),
                'integration' => integration_service::contract(),
                'integration_event_outbox' => integration_event_service::contract(),
                'financial_model' => financial_model_service::contract(),
                'financial_statements' => financial_statement_service::contract(),
                'budgeting' => budgeting_service::contract(),
                'resource_gate' => resource_gate_service::contract(),
                'company_context_boundary' => company_context_service::contract(),
                'workspace_projection' => workspace_mapping_service::contract(),
                'entitlement_boundary' => entitlement_service::contract(),
                'platform_event_envelope' => platform_event_envelope::contract(),
                'platform_boundary' => platform_boundary_service::contract(),
                'public_api' => public_api_service::contract(),
            ],
            'guarantees' => [
                'no_company_duplication',
                'no_membership_duplication',
                'company_scope_required',
                'ui_contains_no_source_storage_logic',
                'erp_provider_neutral_contract',
                'missing_position_remains_unknown',
                'no_automatic_organizing_board_seed',
                'unfilled_position_remains_unfilled',
                'position_assignment_does_not_change_competency',
                'staffing_plan_is_separate_from_staffing_placement',
                'vacancy_is_not_a_fabricated_employee',
                'centralised_company_permissions',
                'public_api_hides_internal_storage_and_provider_specific_ids',
                'public_api_is_read_only_foundation_for_cloud_and_erp_consumers',
                'provider_neutral_event_outbox_is_separate_from_legacy_bitrix_command_outbox',
                'provider_neutral_events_do_not_copy_provider_ids_or_employee_contact_pii',
                'optimistic_live_org_apply',
                'canonical_semantic_objects_are_not_duplicated',
                'ofs_is_product_functional_not_only_reporting',
                'process_is_not_forced_to_equal_department',
                'sipoc_and_bpmn_are_one_versioned_process_model',
                'stp_is_generated_from_process_model_not_duplicated',
                'raci_is_version_scoped_and_responsible_derives_from_task_executor',
                'process_activity_function_position_traceability_is_explicit',
                'legacy_cascade_functions_are_not_destructively_migrated',
                'controlled_documents_and_records_are_separate',
                'obsolete_document_revisions_are_not_current',
                'master_list_is_generated_not_manually_maintained',
                'job_description_is_generated_from_current_bms_model',
                'position_work_program_items_pin_published_function_revision',
                'ownership_intent_and_long_term_goal_are_explicit',
                'bsc_goals_reuse_existing_metrics_processes_and_positions',
                'strategic_causal_map_is_acyclic',
                'administrative_scale_links_strategy_to_execution',
                'administrative_cycle_projects_link_to_canonical_meetings_decisions_tasks_and_results',
                'administrative_cycle_does_not_duplicate_decisions_or_tasks',
                'administrative_scale_children_pin_published_parent_revisions',
                'legacy_administrative_cascade_is_not_destructively_migrated',
                'task_center_is_canonical_execution_workspace',
                'recurring_task_definition_is_not_execution_fact',
                'recurring_occurrences_are_canonical_tasks',
                'recurring_task_library_is_snapshot_provenance_not_drive_runtime_dependency',
                'one_indicator_registry_projects_into_strategy_and_organization_trees',
                'indicator_norms_are_versioned_separately_from_observations',
                'battle_plan_consumes_indicators_but_does_not_own_indicator_definitions',
                'battle_plan_position_indicator_header_is_read_model_over_canonical_indicator_history',
                'battle_plan_management_overview_is_read_model_over_canonical_plans_tasks_and_indicator_exceptions',
                'management_exceptions_are_read_model_not_parallel_incident_storage',
                'management_cycle_reuses_canonical_decisions_tasks_battle_plans_and_facts',
                'battle_plan_references_tasks_instead_of_duplicating_them',
                'administrative_scale_and_position_work_remain_task_sources_not_execution_facts',
                'communication_threads_are_participant_scoped',
                'message_and_task_remain_separate_objects_with_traceable_conversion',
                'task_providers_preserve_external_source_of_truth',
                'battle_plan_can_reference_external_provider_tasks_without_copying',
                'kbaelement_learning_tasks_use_explicit_company_course_bindings',
                'theme_is_optional_presentation_not_business_dependency',
                'new_bitrix_companies_require_explicit_bms_client_flag',
                'bitrix_rest_is_owned_only_by_kba_connect',
                'employee_is_moodle_user_plus_company_membership',
                'corporate_learning_roles_do_not_grant_bms_management_permissions',
                'employee_creation_does_not_enrol_courses',
                'local_kbacorporate_remains_learning_bounded_context',
                'financial_model_centres_reference_canonical_ofs_targets',
                'financial_model_does_not_duplicate_accounting_or_erp_facts',
                'financial_statements_are_planning_forecasts_not_accounting_facts',
                'financial_statement_revisions_pin_scenario_and_optional_centre_revisions',
                'budgeting_pins_scenario_and_management_target_revisions',
                'budget_plan_fact_uses_aggregate_snapshots_not_ledger_transactions',
                'resource_gate_reuses_budget_staffing_roles_documents_and_does_not_duplicate_them',
                'resource_gate_requires_explicit_seven_dimension_launch_readiness',
                'company_uuid_is_platform_security_boundary',
                'workspace_identity_is_external_control_plane_projection',
                'entitlement_is_separate_from_acl_and_feature_flags',
                'legacy_clients_remain_entitlement_compatible_until_explicit_cloud_cutover',
                'provider_neutral_event_envelope_precedes_new_provider_integrations',
                'cloud_registry_and_erp_runtime_are_not_owned_by_local_kbacompany',
            ],
        ];
        $contract['schema_hash_algorithm'] = 'sha256';
        $contract['schema_hash'] = hash('sha256', json_encode($contract,
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        return $contract;
    }

    /** Return all active companies available to one user. */
    public static function companies_for_user(int $userid): array {
        global $DB;

        $result = [];
        $revocationwhere = membership_access_service::canonical_sql('c.uuid', $userid);
        if (company_registry_service::ready()) {
            if (is_siteadmin($userid) || has_capability('local/kbacompany:viewallcompanies',
                    \context_system::instance(), $userid)) {
                $records = $DB->get_records_sql("SELECT c.* FROM {local_kbacompany_company} c WHERE c.active = 1 AND {$revocationwhere} ORDER BY c.name, c.id");
            } else {
                $records = $DB->get_records_sql(
                    "SELECT c.*
                       FROM {local_kbacompany_company} c
                       JOIN {local_kbacompany_member} m ON m.companyuuid = c.uuid AND m.userid = :userid AND m.active = 1
                      WHERE c.active = 1 AND {$revocationwhere}
                   ORDER BY c.name, c.id", ['userid' => $userid]);
            }
            foreach ($records as $company) {
                $result[(string)$company->uuid] = self::canonical_company_dto($company, $userid);
            }
        }

        // Preserve all pre-0.5.16 Corporate-backed companies until KBA Connect explicitly adopts them.
        if (legacy_company_adapter::is_ready()) {
            foreach (legacy_company_adapter::companies_for_user($userid) as $legacy) {
                $uuid = identity_registry::company_uuid((int)$legacy->id);
                if (!isset($result[$uuid])) {
                    $result[$uuid] = self::legacy_company_dto($legacy, $userid);
                }
            }
        }
        return array_values($result);
    }

    /** Resolve one authorised company context by stable UUID. */
    public static function company_context(string $uuid, int $userid): array {
        $uuid = strtolower(trim($uuid));
        membership_access_service::require_access($uuid, $userid);
        $canonical = company_registry_service::by_uuid($uuid);
        if ($canonical) {
            if (!self::can_access_canonical($canonical, $userid)) {
                throw new \moodle_exception('companynotavailable', 'local_kbacompany');
            }
            return self::canonical_company_dto($canonical, $userid);
        }

        $identity = identity_registry::resolve($uuid);
        if (!$identity || (string)$identity->sourcecomponent !== identity_registry::SOURCE_COMPONENT ||
                (string)$identity->sourceentity !== identity_registry::COMPANY_ENTITY) {
            throw new \moodle_exception('companynotavailable', 'local_kbacompany');
        }
        $companyid = (int)$identity->sourceid;
        if (!legacy_company_adapter::can_access($companyid, $userid)) {
            throw new \moodle_exception('companynotavailable', 'local_kbacompany');
        }
        $company = legacy_company_adapter::company($companyid);
        if (!$company) {
            throw new \moodle_exception('companynotavailable', 'local_kbacompany');
        }
        return self::legacy_company_dto($company, $userid);
    }

    public static function status(): array {
        global $DB;

        $identityready = $DB->get_manager()->table_exists(new \xmldb_table('local_kbacompany_idreg'));
        return [
            'ready' => $identityready && (company_registry_service::ready() || legacy_company_adapter::is_ready()),
            'contract_version' => self::CONTRACT_VERSION,
            'storage_mode' => self::STORAGE_MODE,
            'identity_registry' => $identityready,
            'canonical_registry' => company_registry_service::ready(),
            'corporate_adapter' => legacy_company_adapter::is_ready(),
            'corporate_build' => (int)get_config('local_kbacorporate', 'version'),
        ];
    }

    private static function can_access_canonical(\stdClass $company, int $userid): bool {
        global $DB;
        // Archived companies are omitted from operational company lists but remain resolvable
        // for authorised historical links and immutable BMS history.
        $context = \context_system::instance();
        if (is_siteadmin($userid) || has_capability('local/kbacompany:viewallcompanies', $context, $userid)) {
            return true;
        }
        if ($DB->record_exists('local_kbacompany_member', [
                'companyuuid' => (string)$company->uuid, 'userid' => $userid, 'active' => 1])) {
            return true;
        }
        return (int)$company->legacycompanyid > 0 && legacy_company_adapter::is_ready() &&
            legacy_company_adapter::can_access((int)$company->legacycompanyid, $userid);
    }

    private static function canonical_company_dto(\stdClass $company, int $userid): array {
        global $DB;
        $membership = $DB->get_record('local_kbacompany_member', [
            'companyuuid' => (string)$company->uuid, 'userid' => $userid, 'active' => 1,
        ], '*', IGNORE_MULTIPLE) ?: null;
        $legacy = null;
        if ((int)$company->legacycompanyid > 0 && legacy_company_adapter::is_ready()) {
            $legacy = legacy_company_adapter::membership((int)$company->legacycompanyid, $userid);
        }
        $roles = array_values(array_unique(array_merge(
            self::membership_roles($membership), self::membership_roles($legacy))));
        return [
            'uuid' => (string)$company->uuid,
            'name' => (string)$company->name,
            'code' => (string)$company->code,
            'primary_activity' => (string)($company->primaryactivity ?? ''),
            'founded_year' => (int)$company->foundedyear,
            'active' => !empty($company->active),
            'bms_client' => !empty($company->bmsclient),
            'sync_state' => (string)$company->syncstate,
            'external_company_id' => company_registry_service::external_id((string)$company->uuid),
            'roles' => $roles,
            'compatibility' => [
                'source_component' => (int)$company->legacycompanyid > 0 ?
                    identity_registry::SOURCE_COMPONENT : 'local_kbacompany',
                'source_entity' => identity_registry::COMPANY_ENTITY,
                'source_id' => (int)$company->legacycompanyid,
            ],
        ];
    }

    private static function legacy_company_dto(\stdClass $company, int $userid): array {
        $membership = legacy_company_adapter::membership((int)$company->id, $userid);
        return [
            'uuid' => identity_registry::company_uuid((int)$company->id),
            'name' => (string)$company->name,
            'code' => (string)($company->code ?? ''),
            'primary_activity' => (string)($company->primaryactivity ?? ''),
            'founded_year' => (int)($company->foundedyear ?? 0),
            'active' => !empty($company->active),
            'bms_client' => false,
            'sync_state' => 'legacy',
            'external_company_id' => '',
            'roles' => self::membership_roles($membership),
            'compatibility' => [
                'source_component' => identity_registry::SOURCE_COMPONENT,
                'source_entity' => identity_registry::COMPANY_ENTITY,
                'source_id' => (int)$company->id,
            ],
        ];
    }

    private static function membership_roles(?\stdClass $membership): array {
        if (!$membership) {
            return [];
        }
        $roles = [];
        $membertype = strtolower(trim((string)($membership->membertype ?? '')));
        if ($membertype === 'owner') {
            $roles[] = 'owner';
        }
        if (!empty($membership->isemployee)) {
            $roles[] = 'employee';
        }
        if (!empty($membership->ismanager)) {
            $roles[] = 'manager';
        }
        if (!empty($membership->ishr)) {
            $roles[] = 'hr';
        }
        if (!empty($membership->isdepartmentmanager)) {
            $roles[] = 'department_manager';
        }
        return array_values(array_unique($roles));
    }
}
