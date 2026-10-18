<?php
namespace local_kbacompany\local;

defined('MOODLE_INTERNAL') || die();

/**
 * Stable read-only BMS Management Public API DTO boundary.
 *
 * The public API intentionally returns provider-neutral snapshots built through
 * domain services. External consumers must not depend on Moodle table names,
 * legacy source ids, Bitrix ids or mutable UI models.
 */
class public_api_service {
    public const CONTRACT_VERSION = '0.6.5';
    public const API_VERSION = 'v1';
    public const SCHEMA_VERSION = '1.0';

    public static function contract(): array {
        $contract = [
            'version' => self::CONTRACT_VERSION,
            'api_version' => self::API_VERSION,
            'schema_version' => self::SCHEMA_VERSION,
            'owner' => 'bms_management_cloud',
            'transport' => 'moodle_external_functions_json_envelope',
            'service_shortname' => 'local_kbacompany_public_v1',
            'service_policy' => 'disabled_by_default_restricted_users',
            'authentication' => 'moodle_web_service_token_or_authenticated_user',
            'company_scope' => 'required_for_all_company_resources',
            'resources' => [
                'company' => 'authorised management company snapshot',
                'employees' => 'company membership and current position projection',
                'organization' => 'organization branches and hierarchical nodes',
                'positions' => 'position definitions and current assignments',
                'indicators' => 'management indicator definitions without operational ledger ownership',
                'budgets' => 'effective approved management plan and limits only',
            ],
            'envelope' => [
                'api_version', 'schema_version', 'resource', 'company_uuid', 'workspace_uuid',
                'generated_at', 'snapshot_hash', 'data',
            ],
            'rules' => [
                'company_uuid_is_the_management_security_boundary',
                'workspace_uuid_is_projection_only_and_owned_by_bms_cloud',
                'responses_are_built_via_domain_services_not_direct_external_table_access',
                'provider_specific_ids_are_not_part_of_public_resource_dtos',
                'legacy_storage_ids_are_not_part_of_public_resource_dtos',
                'public_api_is_read_only_in_v1_foundation',
                'employee_contact_fields_are_opt_in_and_manager_scoped',
                'budget_export_uses_effective_approved_revision_only',
                'budget_export_contains_plan_and_limits_not_operational_actual_ledger',
                'course_completion_is_not_competency_attainment',
                'unknown_future_resources_must_not_be_inferred_from_current_internal_tables',
            ],
        ];
        $contract['schema_hash_algorithm'] = 'sha256';
        $contract['schema_hash'] = hash('sha256', self::json($contract));
        return $contract;
    }

    public static function company_snapshot(string $companyuuid, int $userid): array {
        $company = company_context_service::context($companyuuid, $userid);
        $workspace = workspace_mapping_service::status((string)$company['uuid']);
        $data = [
            'company_uuid' => (string)$company['uuid'],
            'name' => (string)$company['name'],
            'code' => (string)$company['code'],
            'primary_activity' => (string)$company['primary_activity'],
            'founded_year' => (int)$company['founded_year'],
            'active' => !empty($company['active']),
            'bms_client' => !empty($company['bms_client']),
            'workspace' => [
                'mapped' => !empty($workspace['mapped']),
                'workspace_uuid' => (string)$workspace['workspace_uuid'],
                'cloud_status' => (string)$workspace['cloud_status'],
                'entitlement_mode' => (string)$workspace['entitlement_mode'],
                'snapshot_revision' => (string)$workspace['snapshot_revision'],
                'last_sync' => (int)$workspace['last_sync'],
            ],
        ];
        return self::envelope('company', (string)$company['uuid'], $data);
    }

    public static function employees_snapshot(string $companyuuid, int $userid,
            bool $includeinactive = false, bool $includecontact = false): array {
        $context = employee_service::employees_context($companyuuid, $userid, $includeinactive);
        $companyuuid = (string)$context['company']['uuid'];
        if ($includecontact && !permission_service::can_manage_organization($companyuuid, $userid) &&
                !is_siteadmin($userid)) {
            throw new \required_capability_exception(\context_system::instance(),
                permission_service::CAPABILITY_MANAGE, 'nopermissions', '');
        }
        $employees = [];
        foreach ($context['employees'] as $employee) {
            $position = null;
            if (!empty($employee['position'])) {
                $position = [
                    'position_uuid' => (string)($employee['position']['uuid'] ?? ''),
                    'name' => (string)($employee['position']['name'] ?? ''),
                    'org_unit_name' => (string)($employee['position']['unit'] ?? ''),
                ];
            }
            $dto = [
                'membership_uuid' => (string)$employee['membership_uuid'],
                'membership_kind' => (string)$employee['membership_uuid'] !== '' ? 'canonical' : 'compatibility',
                'moodle_user_id' => (int)$employee['user_id'],
                'full_name' => (string)$employee['full_name'],
                'active' => !empty($employee['active']),
                'membership_active' => !empty($employee['membership_active']),
                'effective_from' => (int)$employee['effective_from'],
                'effective_to' => (int)$employee['effective_to'],
                'roles' => array_values($employee['roles']),
                'position' => $position,
            ];
            if ($includecontact) {
                $dto['contact'] = [
                    'email' => (string)$employee['email'],
                    'phone' => (string)$employee['phone'],
                ];
            }
            $employees[] = $dto;
        }
        return self::envelope('employees', $companyuuid, [
            'include_inactive' => $includeinactive,
            'include_contact' => $includecontact,
            'employees' => $employees,
            'count' => count($employees),
        ]);
    }

    public static function organization_snapshot(string $companyuuid, int $userid,
            string $branchuuid = ''): array {
        $context = organizing_board_service::board_context($companyuuid, $userid, trim($branchuuid));
        $companyuuid = (string)$context['company']['uuid'];
        $branches = [];
        foreach ($context['branches'] as $branch) {
            $branches[] = [
                'uuid' => (string)$branch['uuid'],
                'code' => (string)$branch['code'],
                'name' => (string)$branch['name'],
                'is_default' => !empty($branch['is_default']),
                'sort_order' => (int)$branch['sort_order'],
            ];
        }
        $nodes = [];
        foreach ($context['nodes'] as $node) {
            $nodes[] = [
                'uuid' => (string)$node['uuid'],
                'parent_uuid' => (string)$node['parent_uuid'],
                'code' => (string)$node['code'],
                'type' => (string)$node['type'],
                'name' => (string)$node['name'],
                'sort_order' => (int)$node['sort_order'],
                'reporting_parent_uuid' => (string)($node['reporting_parent_uuid'] ?? ''),
                'reporting_design_uuid' => (string)($node['reporting_design_uuid'] ?? ''),
                'reporting_design_revision' => (int)($node['reporting_design_revision'] ?? 0),
                'compatibility' => false,
            ];
        }
        $projectionmode = 'organizing_board';
        if (!$nodes) {
            // Pre-organizing-board companies remain readable through the legacy/native
            // organization projection. We do not fabricate hierarchy that the source
            // model does not contain.
            $legacy = organization_service::organization_context($companyuuid, $userid);
            foreach ($legacy['org_units'] as $unit) {
                $nodes[] = [
                    'uuid' => (string)$unit['uuid'],
                    'parent_uuid' => '',
                    'code' => (string)$unit['code'],
                    'type' => (string)$unit['type'],
                    'name' => (string)$unit['name'],
                    'sort_order' => 0,
                    'reporting_parent_uuid' => '',
                    'reporting_design_uuid' => '',
                    'reporting_design_revision' => 0,
                    'compatibility' => true,
                ];
            }
            $projectionmode = 'organization_compatibility';
        }
        return self::envelope('organization', $companyuuid, [
            'projection_mode' => $projectionmode,
            'branches' => $branches,
            'selected_branch_uuid' => (string)$context['selected_branch_uuid'],
            'nodes' => $nodes,
            'summary' => [
                'branches' => count($branches),
                'nodes' => count($nodes),
            ],
        ]);
    }

    public static function positions_snapshot(string $companyuuid, int $userid,
            string $orgnodeuuid = ''): array {
        $context = position_service::positions_context($companyuuid, $userid, trim($orgnodeuuid));
        $companyuuid = (string)$context['company']['uuid'];
        $positions = [];
        foreach ($context['positions'] as $position) {
            $assignments = [];
            foreach ($position['assignments'] as $assignment) {
                $assignments[] = [
                    'moodle_user_id' => (int)$assignment['user_id'],
                    'employee_name' => (string)($assignment['employee_name'] ?? ''),
                    'type' => (string)$assignment['type'],
                    'effective_from' => (int)$assignment['effective_from'],
                    'effective_to' => (int)$assignment['effective_to'],
                    'current' => !empty($assignment['current']),
                ];
            }
            $positions[] = [
                'uuid' => (string)$position['uuid'],
                'branch_uuid' => (string)$position['branch_uuid'],
                'org_node_uuid' => (string)$position['org_node_uuid'],
                'code' => (string)$position['code'],
                'name' => (string)$position['name'],
                'purpose' => (string)$position['purpose'],
                'is_manager' => !empty($position['is_manager']),
                'planned_count' => (int)$position['planned_count'],
                'current_count' => (int)$position['current_count'],
                'vacant_count' => (int)$position['vacant_count'],
                'assignments' => $assignments,
            ];
        }
        return self::envelope('positions', $companyuuid, [
            'org_node_uuid' => (string)$context['org_node_uuid'],
            'positions' => $positions,
            'summary' => [
                'positions' => count($positions),
                'planned_places' => (int)$context['summary']['planned_places'],
                'current_assignments' => (int)$context['summary']['current_assignments'],
                'vacant_places' => (int)$context['summary']['vacant_places'],
            ],
        ]);
    }

    public static function indicators_snapshot(string $companyuuid, int $userid): array {
        $context = indicator_registry_service::context($companyuuid, $userid);
        $companyuuid = (string)$context['company']['uuid'];
        $metrics = [];
        foreach ($context['metrics'] as $metric) {
            $metrics[] = [
                'uuid' => (string)$metric['uuid'],
                'code' => (string)$metric['code'],
                'name' => (string)$metric['name'],
                'unit' => (string)$metric['unit'],
                'period_type' => (string)$metric['periodtype'],
                'direction' => (string)$metric['direction'],
                'product_uuid' => (string)$metric['productuuid'],
                'description' => (string)$metric['description'],
                'source_kind' => (string)$metric['sourcekind'],
                'aggregation' => (string)$metric['aggregation'],
                'passport_revision' => (int)$metric['passport_revision'],
            ];
        }
        $bindings = [];
        foreach ($context['bindings'] as $binding) {
            $bindings[] = [
                'uuid' => (string)($binding['uuid'] ?? ''),
                'revision' => (int)($binding['revision'] ?? 0),
                'indicator_uuid' => (string)($binding['statuuid'] ?? ''),
                'context_kind' => (string)($binding['contextkind'] ?? ''),
                'context_uuid' => (string)($binding['contextuuid'] ?? ''),
                'context_revision' => (int)($binding['contextrevision'] ?? 0),
                'is_primary' => !empty($binding['isprimary']),
                'critical' => !empty($binding['critical']),
                'weight' => (string)($binding['weight'] ?? '1'),
                'compatibility' => !empty($binding['compatibility']),
            ];
        }
        return self::envelope('indicators', $companyuuid, [
            'indicators' => $metrics,
            'bindings' => $bindings,
            'count' => count($metrics),
        ]);
    }

    public static function budgets_snapshot(string $companyuuid, int $userid): array {
        $context = budgeting_service::context($companyuuid, $userid);
        $companyuuid = (string)$context['company']['uuid'];
        $budgets = [];
        foreach ($context['budgets'] as $budget) {
            $approvedrevision = (int)($budget['effectiveapprovedrevision'] ?? 0);
            if ($approvedrevision <= 0) {
                continue;
            }
            $detail = budgeting_service::budget($companyuuid, $userid, (string)$budget['uuid'], $approvedrevision);
            $header = $detail['budget'];
            $lines = [];
            foreach ($detail['lines'] as $line) {
                $lines[] = [
                    'line_code' => (string)$line['linecode'],
                    'name' => (string)$line['name'],
                    'line_type' => (string)$line['linetype'],
                    'period_start' => (string)$line['periodstart'],
                    'period_end' => (string)$line['periodend'],
                    'plan_amount' => (float)$line['planamount'],
                    'limit_amount' => (float)$line['limitamount'],
                    'owner_position_uuid' => (string)$line['ownerpositionuuid'],
                    'indicator_uuid' => (string)$line['indicatoruuid'],
                    'source_info' => (string)$line['sourceinfo'],
                ];
            }
            $budgets[] = [
                'uuid' => (string)$header['uuid'],
                'revision' => (int)$header['revision'],
                'status' => (string)$header['status'],
                'code' => (string)$header['code'],
                'name' => (string)$header['name'],
                'budget_type' => (string)$header['budgettype'],
                'scenario_uuid' => (string)$header['scenariouuid'],
                'scenario_revision' => (int)$header['scenariorevision'],
                'center_uuid' => (string)$header['centeruuid'],
                'center_revision' => (int)$header['centerrevision'],
                'target_type' => (string)$header['budgettype'],
                'target_uuid' => (string)$header['targetuuid'],
                'target_revision' => (int)$header['targetrevision'],
                'period_start' => (string)$header['periodstart'],
                'period_end' => (string)$header['periodend'],
                'currency' => (string)$header['currency'],
                'notes' => (string)$header['notes'],
                'lines' => $lines,
            ];
        }
        return self::envelope('budgets', $companyuuid, [
            'projection' => 'effective_approved_management_plan',
            'actual_ledger_included' => false,
            'budgets' => $budgets,
            'count' => count($budgets),
        ]);
    }

    private static function envelope(string $resource, string $companyuuid, array $data): array {
        $companyuuid = company_context_service::require_exists($companyuuid);
        $workspace = workspace_mapping_service::status($companyuuid);
        $hashinput = [
            'api_version' => self::API_VERSION,
            'schema_version' => self::SCHEMA_VERSION,
            'resource' => $resource,
            'company_uuid' => $companyuuid,
            'workspace_uuid' => (string)$workspace['workspace_uuid'],
            'data' => $data,
        ];
        return [
            'api_version' => self::API_VERSION,
            'schema_version' => self::SCHEMA_VERSION,
            'resource' => $resource,
            'company_uuid' => $companyuuid,
            'workspace_uuid' => (string)$workspace['workspace_uuid'],
            'generated_at' => gmdate('c'),
            'snapshot_hash' => hash('sha256', self::json($hashinput)),
            'data' => $data,
        ];
    }

    private static function json(array $value): string {
        $json = json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION);
        if ($json === false) {
            throw new \coding_exception('Unable to encode BMS Public API contract.');
        }
        return $json;
    }
}
