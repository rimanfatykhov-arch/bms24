<?php
namespace local_kbacompany\local;

defined('MOODLE_INTERNAL') || die();

/** Provider-neutral Business Role and assignment projection. */
class business_role_service {
    public const CONTRACT_VERSION = '0.2.1';

    public static function contract(): array {
        $contract = [
            'component' => 'local_kbacompany',
            'contract' => 'kba_business_role_core',
            'version' => self::CONTRACT_VERSION,
            'stability' => 'foundation',
            'company_scope' => 'required',
            'write_policy' => 'read_only_projection',
            'objects' => ['BusinessRole', 'EmployeeRoleAssignment', 'RoleCompetencyRequirement'],
            'source_of_truth' => [
                'business_role' => 'local_kbacorporate.business_role',
                'employee_role_assignment' => 'local_kbacorporate.member_business_role',
                'competency_requirement' => 'local_kbacorporate.role_competency',
                'stable_role_identity' => 'local_kbacompany.identity_registry',
            ],
            'rules' => [
                'bms_domain_nodes_are_not_working_competencies',
                'course_completion_does_not_confirm_competency_level',
                'role_target_does_not_change_employee_assessment',
                'unassessed_competency_does_not_become_level_zero',
                'unconfigured_target_level_remains_unknown',
            ],
            'guarantees' => [
                'no_business_role_duplication',
                'company_access_checked_before_projection',
                'read_only_assignments',
                'erp_provider_neutral_contract',
            ],
        ];
        $contract['schema_hash_algorithm'] = 'sha256';
        $contract['schema_hash'] = hash('sha256', json_encode($contract,
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        return $contract;
    }

    /** Whether the user holds one of the named active Business Roles in this company. */
    public static function user_has_named_role(string $companyuuid, int $userid, array $rolenames): bool {
        $company = company_context_service::context($companyuuid, $userid);
        $companyid = (int)$company['compatibility']['source_id'];
        if ($companyid <= 0 || !legacy_company_adapter::business_roles_ready()) {
            return false;
        }
        $source = legacy_company_adapter::business_roles($companyid);
        if (!$source['available']) {
            return false;
        }

        $wanted = [];
        foreach ($rolenames as $name) {
            $normalized = self::normalize_role_name((string)$name);
            if ($normalized !== '') {
                $wanted[$normalized] = true;
            }
        }
        if (!$wanted) {
            return false;
        }

        $roleids = [];
        foreach ($source['roles'] as $role) {
            $normalized = self::normalize_role_name((string)$role->name);
            if (isset($wanted[$normalized])) {
                $roleids[(int)$role->id] = true;
            }
        }
        if (!$roleids) {
            return false;
        }

        foreach ($source['assignments'] as $assignment) {
            if ((int)$assignment->userid === $userid && isset($roleids[(int)$assignment->roleid])) {
                return true;
            }
        }
        return false;
    }

    private static function normalize_role_name(string $name): string {
        $name = trim($name);
        if ($name === '') {
            return '';
        }
        return \core_text::strtolower(preg_replace('/\s+/u', ' ', $name));
    }

    /** Return business roles and active assignments for one authorised company. */
    public static function business_roles_context(string $companyuuid, int $userid): array {
        $company = company_context_service::context($companyuuid, $userid);
        $companyid = (int)$company['compatibility']['source_id'];
        if ($companyid <= 0 || !legacy_company_adapter::business_roles_ready()) {
            return self::empty_context($company);
        }
        $source = legacy_company_adapter::business_roles($companyid);
        if (!$source['available']) {
            return self::empty_context($company);
        }

        $requirements = [];
        foreach ($source['requirements'] as $requirement) {
            $code = trim((string)$requirement->idnumber);
            if (preg_match('/^BMS-D\d+/i', $code)) {
                continue;
            }
            $targetlevel = (int)$requirement->targetlevel;
            $requirements[(int)$requirement->roleid][] = [
                'competency_id' => (int)$requirement->competencyid,
                'code' => $code,
                'name' => (string)$requirement->shortname,
                'target_level' => $targetlevel > 0 ? $targetlevel : null,
                'priority' => (string)$requirement->priority,
                'required' => !empty($requirement->required),
                'source' => (string)$requirement->source,
                'assessment_effect' => 'none',
            ];
        }

        $roleassignments = [];
        $employeeassignments = [];
        foreach ($source['assignments'] as $assignment) {
            $roleid = (int)$assignment->roleid;
            $assignmentdto = [
                'user_id' => (int)$assignment->userid,
                'full_name' => fullname($assignment),
                'source' => (string)$assignment->source,
                'effective_from' => (int)$assignment->effectivefrom,
                'effective_to' => (int)$assignment->effectiveto,
            ];
            $roleassignments[$roleid][] = $assignmentdto;
            $employeeassignments[(int)$assignment->userid][] = $roleid;
        }

        $roles = [];
        $rolemap = [];
        foreach ($source['roles'] as $role) {
            $roleid = (int)$role->id;
            $uuid = identity_registry::business_role_uuid($roleid);
            $dto = [
                'uuid' => $uuid,
                'code' => (string)$role->code,
                'name' => (string)$role->name,
                'description' => (string)($role->description ?? ''),
                'scope' => (int)$role->companyid === 0 ? 'global' : 'company',
                'source' => (string)$role->source,
                'employee_count' => count($roleassignments[$roleid] ?? []),
                'competency_count' => count($requirements[$roleid] ?? []),
                'competency_requirements' => $requirements[$roleid] ?? [],
                'assignments' => $roleassignments[$roleid] ?? [],
                'compatibility' => [
                    'source_id' => $roleid,
                    'source_uuid' => (string)$role->uuid,
                ],
            ];
            $roles[] = $dto;
            $rolemap[$roleid] = ['uuid' => $uuid, 'code' => $dto['code'], 'name' => $dto['name']];
        }

        $employees = [];
        $withoutrole = 0;
        foreach ($source['members'] as $member) {
            $assignedroles = [];
            foreach ($employeeassignments[(int)$member->userid] ?? [] as $roleid) {
                if (isset($rolemap[$roleid])) {
                    $assignedroles[] = $rolemap[$roleid];
                }
            }
            if (!$assignedroles) {
                $withoutrole++;
            }
            $employees[] = [
                'user_id' => (int)$member->userid,
                'full_name' => fullname($member),
                'roles' => $assignedroles,
            ];
        }

        return [
            'company' => $company,
            'available' => true,
            'summary' => [
                'roles' => count($roles),
                'assigned_employees' => count($employees) - $withoutrole,
                'without_role' => $withoutrole,
                'competency_requirements' => array_sum(array_map(static function(array $role): int {
                    return $role['competency_count'];
                }, $roles)),
            ],
            'roles' => $roles,
            'employees' => $employees,
        ];
    }

    private static function empty_context(array $company): array {
        return [
            'company' => $company,
            'available' => false,
            'summary' => ['roles' => 0, 'assigned_employees' => 0,
                'without_role' => 0, 'competency_requirements' => 0],
            'roles' => [],
            'employees' => [],
        ];
    }
}
