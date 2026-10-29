<?php
namespace local_kbacompany\local;

defined('MOODLE_INTERNAL') || die();

/** Provider-neutral Organization Core read service. */
class organization_service {
    public const CONTRACT_VERSION = '0.2.0';

    /** Machine-readable Organization Core contract. */
    public static function contract(): array {
        $contract = [
            'component' => 'local_kbacompany',
            'contract' => 'kba_organization_core',
            'version' => self::CONTRACT_VERSION,
            'stability' => 'foundation',
            'company_scope' => 'required',
            'write_policy' => 'read_only_projection',
            'objects' => ['OrgUnit', 'Position', 'EmployeeOrgAssignment'],
            'source_of_truth' => [
                'org_unit' => 'local_kbacorporate.department',
                'org_unit_membership' => 'local_kbacorporate.department_member',
                'position_label' => 'moodle.custom_profile.position',
                'stable_org_unit_identity' => 'local_kbacompany.identity_registry',
            ],
            'null_policy' => [
                'missing_position' => 'unknown',
                'missing_org_unit' => 'unassigned',
            ],
            'guarantees' => [
                'no_organization_duplication',
                'no_position_fabrication',
                'company_access_checked_before_projection',
                'source_ids_confined_to_compatibility_metadata',
                'erp_provider_neutral_contract',
            ],
        ];
        $contract['schema_hash_algorithm'] = 'sha256';
        $contract['schema_hash'] = hash('sha256', json_encode($contract,
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        return $contract;
    }

    /** Resolve the authorised organisation snapshot for one company UUID. */
    public static function organization_context(string $companyuuid, int $userid): array {
        $company = company_context_service::context($companyuuid, $userid);
        $companyid = (int)$company['compatibility']['source_id'];
        if ($companyid <= 0 || !legacy_company_adapter::is_ready()) {
            return self::native_context($companyuuid, $userid, $company);
        }
        $source = legacy_company_adapter::organization($companyid);

        $assignmentmap = [];
        foreach ($source['assignments'] as $assignment) {
            $assignmentuserid = (int)$assignment->userid;
            $unituuid = identity_registry::org_unit_uuid((int)$assignment->departmentid);
            $role = self::department_role((string)$assignment->deptrole);
            if (!isset($assignmentmap[$assignmentuserid][$unituuid]) || $role === 'manager') {
                $assignmentmap[$assignmentuserid][$unituuid] = [
                    'org_unit_uuid' => $unituuid,
                    'role' => $role,
                ];
            }
        }

        $members = [];
        $positioncounts = [];
        foreach ($source['members'] as $member) {
            $useridvalue = (int)$member->userid;
            $position = $source['positions'][$useridvalue] ?? null;
            if ($position !== null) {
                $positionkey = self::position_key($position);
                if (!isset($positioncounts[$positionkey])) {
                    $positioncounts[$positionkey] = ['key' => $positionkey, 'name' => $position, 'employees' => 0];
                }
                $positioncounts[$positionkey]['employees']++;
            }
            $members[] = [
                'user_id' => $useridvalue,
                'full_name' => fullname($member),
                'position' => $position,
                'position_key' => $position !== null ? self::position_key($position) : null,
                'org_assignments' => isset($assignmentmap[$useridvalue]) ?
                    array_values($assignmentmap[$useridvalue]) : [],
                'company_roles' => self::company_roles($member),
            ];
        }

        $unitcounts = [];
        foreach ($source['assignments'] as $assignment) {
            $departmentid = (int)$assignment->departmentid;
            if (!isset($unitcounts[$departmentid])) {
                $unitcounts[$departmentid] = ['users' => [], 'managers' => []];
            }
            $unitcounts[$departmentid]['users'][(int)$assignment->userid] = true;
            if (self::department_role((string)$assignment->deptrole) === 'manager') {
                $unitcounts[$departmentid]['managers'][(int)$assignment->userid] = true;
            }
        }

        $units = [];
        foreach ($source['departments'] as $department) {
            $counts = $unitcounts[(int)$department->id] ?? ['users' => [], 'managers' => []];
            $units[] = [
                'uuid' => identity_registry::org_unit_uuid((int)$department->id),
                'name' => (string)$department->name,
                'code' => (string)($department->code ?? ''),
                'type' => 'department',
                'active' => !empty($department->active),
                'employee_count' => count($counts['users']),
                'manager_count' => count($counts['managers']),
                'compatibility' => [
                    'source_component' => identity_registry::SOURCE_COMPONENT,
                    'source_entity' => identity_registry::ORG_UNIT_ENTITY,
                    'source_id' => (int)$department->id,
                ],
            ];
        }

        usort($positioncounts, static function(array $left, array $right): int {
            return strcasecmp($left['name'], $right['name']);
        });
        $unassigned = count(array_filter($members, static function(array $member): bool {
            return !$member['org_assignments'];
        }));

        return [
            'company' => $company,
            'summary' => [
                'org_units' => count($units),
                'positions' => count($positioncounts),
                'employees' => count($members),
                'without_org_unit' => $unassigned,
            ],
            'org_units' => $units,
            'positions' => array_values($positioncounts),
            'employees' => $members,
            'source_status' => [
                'departments_available' => (bool)$source['departments_available'],
                'position_profile_field' => (bool)$source['position_field_available'],
            ],
        ];
    }


    /** Native organization projection for BMS companies that have no Corporate-learning company row. */
    private static function native_context(string $companyuuid, int $userid, array $company): array {
        global $DB;
        $members = array_values($DB->get_records_sql(
            "SELECT m.id AS membershipid, m.userid, m.membertype, m.isemployee, m.ismanager,
                    m.isdepartmentmanager, m.ishr, u.firstname, u.lastname, u.firstnamephonetic,
                    u.lastnamephonetic, u.middlename, u.alternatename
               FROM {local_kbacompany_member} m
               JOIN {user} u ON u.id = m.userid
              WHERE m.companyuuid = :companyuuid AND m.active = 1 AND m.isemployee = 1 AND u.deleted = 0
           ORDER BY u.lastname, u.firstname, u.id", ['companyuuid' => $companyuuid]));
        $nodes = $DB->get_records('local_kbacompany_orgnode', ['companyuuid' => $companyuuid, 'active' => 1],
            'sortorder ASC, code ASC');
        $positions = $DB->get_records('local_kbacompany_position', ['companyuuid' => $companyuuid, 'active' => 1],
            'sortorder ASC, code ASC');
        $positionbyuuid = [];
        foreach ($positions as $position) { $positionbyuuid[(string)$position->uuid] = $position; }
        $now = time();
        $assignments = $DB->get_records_select('local_kbacompany_posassign',
            'companyuuid = :companyuuid AND active = 1 AND (effectivefrom = 0 OR effectivefrom <= :nowfrom) AND (effectiveto = 0 OR effectiveto >= :nowto)',
            ['companyuuid' => $companyuuid, 'nowfrom' => $now, 'nowto' => $now], 'id ASC');
        $userpositions = [];
        $unitusers = [];
        foreach ($assignments as $assignment) {
            $position = $positionbyuuid[(string)$assignment->positionuuid] ?? null;
            if (!$position) { continue; }
            $uid = (int)$assignment->userid;
            if (!isset($userpositions[$uid]) || (string)$assignment->assignmenttype === 'primary') {
                $userpositions[$uid] = $position;
            }
            $unituuid = (string)$position->orgnodeuuid;
            if ($unituuid !== '') { $unitusers[$unituuid][$uid] = true; }
        }
        $employeedtos = [];
        foreach ($members as $member) {
            $uid = (int)$member->userid;
            $position = $userpositions[$uid] ?? null;
            $orgassignments = [];
            if ($position && (string)$position->orgnodeuuid !== '') {
                $orgassignments[] = ['org_unit_uuid' => (string)$position->orgnodeuuid,
                    'role' => !empty($position->ismanager) ? 'manager' : 'employee'];
            }
            $employeedtos[] = [
                'user_id' => $uid, 'full_name' => fullname($member),
                'position' => $position ? (string)$position->name : null,
                'position_key' => $position ? (string)$position->uuid : null,
                'org_assignments' => $orgassignments,
                'company_roles' => self::company_roles($member),
            ];
        }
        $units = [];
        foreach ($nodes as $node) {
            $users = $unitusers[(string)$node->uuid] ?? [];
            $units[] = [
                'uuid' => (string)$node->uuid, 'name' => (string)$node->name, 'code' => (string)$node->code,
                'type' => (string)$node->nodetype, 'active' => true,
                'employee_count' => count($users), 'manager_count' => 0,
                'compatibility' => ['source_component' => 'local_kbacompany',
                    'source_entity' => 'orgnode', 'source_id' => (int)$node->id],
            ];
        }
        $positiondtos = [];
        foreach ($positions as $position) {
            $count = 0;
            foreach ($userpositions as $userposition) {
                if ((string)$userposition->uuid === (string)$position->uuid) { $count++; }
            }
            $positiondtos[] = ['key' => (string)$position->uuid, 'name' => (string)$position->name, 'employees' => $count];
        }
        $unassigned = count(array_filter($employeedtos, static function(array $member): bool {
            return !$member['org_assignments'];
        }));
        return [
            'company' => $company,
            'summary' => ['org_units' => count($units), 'positions' => count($positiondtos),
                'employees' => count($employeedtos), 'without_org_unit' => $unassigned],
            'org_units' => $units, 'positions' => $positiondtos, 'employees' => $employeedtos,
            'source_status' => ['departments_available' => true, 'position_profile_field' => true,
                'native_bms' => true],
        ];
    }

    private static function position_key(string $position): string {
        $normalised = \core_text::strtolower(trim(preg_replace('/\s+/u', ' ', $position)));
        return 'pos_' . substr(hash('sha256', $normalised), 0, 24);
    }

    private static function department_role(string $role): string {
        return strtolower(trim($role)) === 'manager' ? 'manager' : 'employee';
    }

    private static function company_roles(\stdClass $member): array {
        $roles = [];
        if (strtolower(trim((string)$member->membertype)) === 'owner') {
            $roles[] = 'owner';
        }
        foreach (['isemployee' => 'employee', 'ismanager' => 'manager',
                'ishr' => 'hr', 'isdepartmentmanager' => 'department_manager'] as $field => $role) {
            if (!empty($member->{$field})) {
                $roles[] = $role;
            }
        }
        return array_values(array_unique($roles));
    }
}
