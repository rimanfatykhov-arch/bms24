<?php
namespace local_kbacompany\local;

defined('MOODLE_INTERNAL') || die();

/** Company-scoped BMS24 access roles, separate from business/competency roles. */
final class access_role_service {
    const ROLE_TABLE = 'local_kbacompany_aclrole';
    const GRANT_TABLE = 'local_kbacompany_aclgrant';
    const ASSIGN_TABLE = 'local_kbacompany_aclassign';
    const AUDIT_TABLE = 'local_kbacompany_aclaudit';
    const ACTIONS = ['view', 'create', 'edit', 'comment', 'approve', 'delete', 'export'];
    const OBJECTS = [
        'management_cycle' => 'Управленческие циклы',
        'task' => 'Задачи', 'indicator' => 'Показатели', 'battle_plan' => 'Боевые планы',
        'process' => 'Процессы', 'org_structure' => 'Структура', 'employee' => 'Сотрудники',
        'document' => 'Документы', 'communication' => 'Коммуникации',
        'work_calendar' => 'Рабочий календарь', 'integration' => 'Интеграции',
    ];
    // Only management_cycle is enforced end to end by the first release.
    const ENFORCED_OBJECTS = ['management_cycle', 'task', 'battle_plan'];
    // task/battle_plan: only company-wide 'edit' is wired to existing server manager predicates.
    // Other actions must not be exposed as functioning until object-scoped checks are completed.

    /** Department scope comes only from active organization assignments marked manager. */
    public static function department_userids(string $companyuuid, int $actor): array {
        $org = organization_service::organization_context($companyuuid, $actor);
        $managed = [];
        foreach ($org['employees'] as $employee) {
            if ((int)$employee['user_id'] !== $actor) { continue; }
            foreach ($employee['org_assignments'] as $assignment) {
                if ((string)$assignment['role'] === 'manager') {
                    $managed[(string)$assignment['org_unit_uuid']] = true;
                }
            }
        }
        if (!$managed) { return []; }
        $ids = [];
        foreach ($org['employees'] as $employee) {
            foreach ($employee['org_assignments'] as $assignment) {
                if (isset($managed[(string)$assignment['org_unit_uuid']])) {
                    $ids[(int)$employee['user_id']] = (int)$employee['user_id'];
                    break;
                }
            }
        }
        return array_values($ids);
    }

    /** Scoped read grant does not imply task or plan mutation rights. */
    public static function department_view_userids(string $companyuuid, int $actor, string $object): array {
        global $DB;
        company_context_service::context($companyuuid, $actor);
        if (!in_array($object, ['task', 'battle_plan'], true)) { return []; }
        if (!has_capability('local/kbacompany:viewowncompany', \context_system::instance(), $actor)
                && !is_siteadmin($actor)) { return []; }
        $sql = 'SELECT g.id FROM {local_kbacompany_aclgrant} g
                  JOIN {local_kbacompany_aclrole} r ON r.id=g.roleid AND r.companyuuid=g.companyuuid
                  JOIN {local_kbacompany_aclassign} a ON a.roleid=r.id AND a.companyuuid=r.companyuuid
                 WHERE g.companyuuid=:company AND a.userid=:userid AND r.active=1
                   AND g.objecttype=:object AND g.actionname=:action AND g.scope=:scope';
        if (!$DB->record_exists_sql($sql, ['company'=>$companyuuid, 'userid'=>$actor,
                'object'=>$object, 'action'=>'view', 'scope'=>'department'])) { return []; }
        return self::department_userids($companyuuid, $actor);
    }

    /**
     * Task listing must include department work which this actor may view, edit or
     * approve. All three grants are checked in this company, for active roles,
     * and expanded ONLY to employees in organisation units managed by the actor.
     * This makes SQL list/readable_projection match can_view_row(), not broader.
     */
    public static function department_task_visibility_userids(string $companyuuid, int $actor): array {
        global $DB;
        company_context_service::context($companyuuid, $actor);
        if (!has_capability('local/kbacompany:viewowncompany', \context_system::instance(), $actor)
                && !is_siteadmin($actor)) { return []; }
        $sql = "SELECT g.id FROM {local_kbacompany_aclgrant} g
                  JOIN {local_kbacompany_aclrole} r ON r.id=g.roleid AND r.companyuuid=g.companyuuid
                  JOIN {local_kbacompany_aclassign} a ON a.roleid=r.id AND a.companyuuid=r.companyuuid
                 WHERE g.companyuuid=:company AND a.userid=:userid AND r.active=1
                   AND g.objecttype='task' AND g.actionname IN ('view','edit','approve')
                   AND g.scope='department'";
        if (!$DB->record_exists_sql($sql, ['company'=>$companyuuid, 'userid'=>$actor])) {
            return [];
        }
        return self::department_userids($companyuuid, $actor);
    }

    /** A department-scoped creation grant never implies editing existing plans. */
    public static function department_plan_create_userids(string $companyuuid, int $actor): array {
        global $DB;
        company_context_service::context($companyuuid, $actor);
        if (!has_capability('local/kbacompany:viewowncompany', \context_system::instance(), $actor)
                && !is_siteadmin($actor)) { return []; }
        $sql = 'SELECT g.id FROM {local_kbacompany_aclgrant} g
                  JOIN {local_kbacompany_aclrole} r ON r.id=g.roleid AND r.companyuuid=g.companyuuid
                  JOIN {local_kbacompany_aclassign} a ON a.roleid=r.id AND a.companyuuid=r.companyuuid
                 WHERE g.companyuuid=:company AND a.userid=:userid AND r.active=1
                   AND g.objecttype=:object AND g.actionname=:action AND g.scope=:scope';
        if (!$DB->record_exists_sql($sql, ['company'=>$companyuuid, 'userid'=>$actor,
                'object'=>'battle_plan', 'action'=>'create', 'scope'=>'department'])) { return []; }
        return array_values(array_diff(self::department_userids($companyuuid, $actor), [$actor]));
    }

    /** Limited department editing of plan settings and weekly goal; no lifecycle/task mutation. */
    public static function may_edit_department_plan(string $companyuuid, int $actor, int $ownerid): bool {
        global $DB;
        company_context_service::context($companyuuid, $actor);
        if ($ownerid <= 0 || $ownerid === $actor) { return false; }
        if (!has_capability('local/kbacompany:viewowncompany', \context_system::instance(), $actor)
                && !is_siteadmin($actor)) { return false; }
        $sql = 'SELECT g.id FROM {local_kbacompany_aclgrant} g
                  JOIN {local_kbacompany_aclrole} r ON r.id=g.roleid AND r.companyuuid=g.companyuuid
                  JOIN {local_kbacompany_aclassign} a ON a.roleid=r.id AND a.companyuuid=r.companyuuid
                 WHERE g.companyuuid=:company AND a.userid=:userid AND r.active=1
                   AND g.objecttype=:object AND g.actionname=:action AND g.scope=:scope';
        if (!$DB->record_exists_sql($sql, ['company'=>$companyuuid, 'userid'=>$actor,
                'object'=>'battle_plan', 'action'=>'edit', 'scope'=>'department'])) { return false; }
        return in_array($ownerid, self::department_userids($companyuuid, $actor), true);
    }

    /** Task rights are checked against the assignee, not merely the author's role. */
    public static function may_act_on_department_task(string $companyuuid, int $actor, int $assigneeid,
            string $action): bool {
        global $DB;
        company_context_service::context($companyuuid, $actor);
        if ($assigneeid <= 0 || $assigneeid === $actor || !in_array($action, ['edit', 'approve'], true)) { return false; }
        if (!has_capability('local/kbacompany:viewowncompany', \context_system::instance(), $actor)
                && !is_siteadmin($actor)) { return false; }
        $sql = 'SELECT g.id FROM {local_kbacompany_aclgrant} g
                  JOIN {local_kbacompany_aclrole} r ON r.id=g.roleid AND r.companyuuid=g.companyuuid
                  JOIN {local_kbacompany_aclassign} a ON a.roleid=r.id AND a.companyuuid=r.companyuuid
                 WHERE g.companyuuid=:company AND a.userid=:userid AND r.active=1
                   AND g.objecttype=:object AND g.actionname=:action AND g.scope=:scope';
        if (!$DB->record_exists_sql($sql, ['company'=>$companyuuid, 'userid'=>$actor,
                'object'=>'task', 'action'=>$action, 'scope'=>'department'])) { return false; }
        return in_array($assigneeid, self::department_userids($companyuuid, $actor), true);
    }

    /** Department manager approval is limited to plans owned by employees of managed units. */
    public static function may_approve_department_plan(string $companyuuid, int $actor, int $ownerid): bool {
        global $DB;
        company_context_service::context($companyuuid, $actor);
        if ($ownerid <= 0 || !has_capability('local/kbacompany:viewowncompany', \context_system::instance(), $actor)
                && !is_siteadmin($actor)) { return false; }
        $sql = 'SELECT g.id FROM {local_kbacompany_aclgrant} g
                  JOIN {local_kbacompany_aclrole} r ON r.id=g.roleid AND r.companyuuid=g.companyuuid
                  JOIN {local_kbacompany_aclassign} a ON a.roleid=r.id AND a.companyuuid=r.companyuuid
                 WHERE g.companyuuid=:company AND a.userid=:userid AND r.active=1
                   AND g.objecttype=:object AND g.actionname=:action AND g.scope=:scope';
        if (!$DB->record_exists_sql($sql, ['company'=>$companyuuid, 'userid'=>$actor,
                'object'=>'battle_plan', 'action'=>'approve', 'scope'=>'department'])) { return false; }
        return in_array($ownerid, self::department_userids($companyuuid, $actor), true);
    }

    /** IDs eligible for department-plan review, verified per company and active manager assignment. */
    public static function department_approve_userids(string $companyuuid, int $actor): array {
        $ids = self::department_userids($companyuuid, $actor);
        $result = [];
        foreach ($ids as $userid) {
            if (self::may_approve_department_plan($companyuuid, $actor, (int)$userid)) {
                $result[] = (int)$userid;
            }
        }
        return $result;
    }

    /** Custom BMS role management requires verified company owner or site administrator. */
    public static function can_administer(string $companyuuid, int $actor): bool {
        $company = company_context_service::context($companyuuid, $actor);
        if (is_siteadmin($actor)) { return true; }
        return has_capability(permission_service::CAPABILITY_MANAGE, \context_system::instance(), $actor)
            && in_array('owner', (array)$company['roles'], true);
    }

    public static function require_administrator(string $companyuuid, int $actor): void {
        if (!self::can_administer($companyuuid, $actor)) {
            throw new \required_capability_exception(\context_system::instance(),
                permission_service::CAPABILITY_MANAGE, 'nopermissions', '');
        }
    }

    public static function can(string $companyuuid, int $actor, string $object, string $action): bool {
        global $DB;
        // Must establish company membership and base Moodle visibility before examining grants.
        company_context_service::context($companyuuid, $actor);
        if (!has_capability('local/kbacompany:viewowncompany', \context_system::instance(), $actor)
                && !is_siteadmin($actor)) { return false; }
        if (!in_array($object, self::ENFORCED_OBJECTS, true) || !in_array($action, self::ACTIONS, true)) {
            return false;
        }
        // Existing owner/manager semantics retained for backward compatibility.
        if ($object === 'management_cycle' && permission_service::can_manage_management($companyuuid, $actor)) {
            return true;
        }
        if ($object === 'task' && permission_service::can_manage_tasks($companyuuid, $actor)) { return true; }
        if ($object === 'battle_plan' && permission_service::can_manage_tasks($companyuuid, $actor)) { return true; }
        $sql = 'SELECT g.id FROM {local_kbacompany_aclgrant} g
                   JOIN {local_kbacompany_aclrole} r ON r.id = g.roleid AND r.companyuuid = g.companyuuid
                   JOIN {local_kbacompany_aclassign} a ON a.roleid = r.id AND a.companyuuid = r.companyuuid
                  WHERE r.companyuuid = :company AND a.userid = :userid AND r.active = 1
                    AND g.objecttype = :object AND g.actionname = :action AND g.scope = :scope';
        $params = ['company' => $companyuuid, 'userid' => $actor, 'object' => $object,
            'action' => $action, 'scope' => 'company'];
        if ($DB->record_exists_sql($sql, $params)) { return true; }
        // A user who can edit/create still needs the corresponding read projection.
        if ($action === 'view') {
            foreach (['create', 'edit', 'approve'] as $write) {
                $params['action'] = $write;
                if ($DB->record_exists_sql($sql, $params)) { return true; }
            }
        }
        return false;
    }

    public static function require_permission(string $companyuuid, int $actor, string $object, string $action): void {
        if (!self::can($companyuuid, $actor, $object, $action)) {
            throw new \required_capability_exception(\context_system::instance(),
                'local/kbacompany:viewowncompany', 'nopermissions', '');
        }
    }

    private static function audit(string $companyuuid, int $actor, string $kind, int $objectid, string $details): void {
        global $DB;
        $DB->insert_record(self::AUDIT_TABLE, (object)[
            'companyuuid' => $companyuuid, 'actorid' => $actor, 'eventkind' => $kind,
            'objectid' => $objectid, 'details' => $details, 'timecreated' => time(),
        ]);
    }

    public static function list_roles(string $companyuuid, int $actor): array {
        global $DB;
        company_context_service::context($companyuuid, $actor);
        return array_values($DB->get_records(self::ROLE_TABLE, ['companyuuid' => $companyuuid], 'name ASC'));
    }

    public static function create_role(string $companyuuid, int $actor, string $name, string $description): int {
        global $DB;
        self::require_administrator($companyuuid, $actor);
        $name = trim($name);
        if ($name === '' || \core_text::strlen($name) > 100 || \core_text::strlen($description) > 1000) {
            throw new \invalid_parameter_exception('Invalid BMS access role name or description');
        }
        $tx = $DB->start_delegated_transaction();
        $id = $DB->insert_record(self::ROLE_TABLE, (object)[
            'companyuuid' => $companyuuid, 'name' => $name, 'description' => trim($description),
            'active' => 1, 'createdby' => $actor, 'timecreated' => time(), 'timemodified' => time(),
        ]);
        self::audit($companyuuid, $actor, 'role_created', (int)$id, $name);
        $tx->allow_commit();
        return (int)$id;
    }

    private static function own_role(string $companyuuid, int $roleid): \stdClass {
        global $DB;
        $role = $DB->get_record(self::ROLE_TABLE, ['companyuuid' => $companyuuid, 'id' => $roleid]);
        if (!$role) { throw new \moodle_exception('invalidparameter'); }
        return $role;
    }

    /** Update a single action. Empty scope revokes. Only company-wide management cycles supported yet. */
    public static function set_grant(string $companyuuid, int $actor, int $roleid,
            string $object, string $action, bool $enabled, string $scope = 'company'): void {
        global $DB;
        self::require_administrator($companyuuid, $actor);
        self::own_role($companyuuid, $roleid);
        if (!in_array($object, self::ENFORCED_OBJECTS, true) || !in_array($action, self::ACTIONS, true)) {
            throw new \invalid_parameter_exception('Object permission is not enforced in this release');
        }
        if (in_array($object, ['task', 'battle_plan'], true) && !($action === 'edit' && $scope === 'company')
                && !($action === 'edit' && $scope === 'department')
                && !($object === 'task' && $action === 'approve' && $scope === 'department')
                && !($action === 'view' && $scope === 'department')
                && !($action === 'create' && $scope === 'company')
                && !($object === 'battle_plan' && $action === 'create' && $scope === 'department')
                && !($object === 'battle_plan' && $action === 'approve' && $scope === 'department')) {
            throw new \invalid_parameter_exception('Action/scope not enforced for this object');
        }
        if (!in_array($action, ['view', 'create', 'edit', 'approve'], true) || !in_array($scope, ['company', 'department'], true)
                || ($object === 'management_cycle' && $scope !== 'company')) {
            throw new \invalid_parameter_exception('Action not yet supported for management cycles');
        }
        $conditions = ['companyuuid' => $companyuuid, 'roleid' => $roleid,
            'objecttype' => $object, 'actionname' => $action];
        $tx = $DB->start_delegated_transaction();
        $existing = $DB->get_record(self::GRANT_TABLE, $conditions);
        if ($enabled && !$existing) {
            $DB->insert_record(self::GRANT_TABLE, (object)($conditions + ['scope' => $scope]));
        } else if ($enabled && $existing && (string)$existing->scope !== $scope) {
            $existing->scope = $scope;
            $DB->update_record(self::GRANT_TABLE, $existing);
        } else if (!$enabled && $existing) {
            $DB->delete_records(self::GRANT_TABLE, $conditions);
        }
        self::audit($companyuuid, $actor, $enabled ? 'grant_allowed' : 'grant_revoked', $roleid,
            $object . ':' . $action);
        $tx->allow_commit();
    }

    public static function role_grants(string $companyuuid, int $actor): array {
        global $DB;
        company_context_service::context($companyuuid, $actor);
        $result = [];
        foreach ($DB->get_records(self::GRANT_TABLE, ['companyuuid' => $companyuuid]) as $row) {
            $result[(int)$row->roleid][(string)$row->objecttype][(string)$row->actionname] = (string)$row->scope;
        }
        return $result;
    }

    /** Create safe default templates only. No user is assigned automatically. */
    public static function seed_templates(string $companyuuid, int $actor): void {
        self::require_administrator($companyuuid, $actor);
        $names = [
            'Архитектор', 'Генеральный директор (CEO)',
            'Руководитель (CEO-1)', 'Сотрудник', 'Бизнес-инженер BMS',
        ];
        $existing = [];
        foreach (self::list_roles($companyuuid, $actor) as $role) {
            $existing[\core_text::strtolower((string)$role->name)] = true;
        }
        foreach ($names as $name) {
            if (isset($existing[\core_text::strtolower($name)])) { continue; }
            $id = self::create_role($companyuuid, $actor, $name,
                'Роль BMS24 в пределах компании. Не предоставляет права администратора Moodle. Разрешения на новые объекты активируются только после серверной интеграции ACL.');
            // Only CEO template has explicit company-wide management-cycle permissions.
            // Template is inert until it is manually assigned to a verified Moodle user.
            if ($name === 'Генеральный директор (CEO)') {
                foreach (['view', 'create', 'edit'] as $action) {
                    self::set_grant($companyuuid, $actor, $id, 'management_cycle', $action, true);
                }
            }
        }
    }

    public static function assign(string $companyuuid, int $actor, int $roleid, int $userid, bool $enabled): void {
        global $DB;
        self::require_administrator($companyuuid, $actor);
        self::own_role($companyuuid, $roleid);
        // Enabling access requires an active, verified member. Disabling access
        // must also work for suspended/deleted users or withdrawn memberships;
        // otherwise a stale ACL assignment could survive until reactivation.
        if ($userid <= 0 || ($enabled && (!$DB->record_exists('user',
                ['id' => $userid, 'deleted' => 0, 'suspended' => 0]) ||
                !self::is_company_member($companyuuid, $userid)))) {
            throw new \invalid_parameter_exception('User is not an active member of this company');
        }
        $conditions = ['companyuuid' => $companyuuid, 'roleid' => $roleid, 'userid' => $userid];
        $tx = $DB->start_delegated_transaction();
        $existing = $DB->get_record(self::ASSIGN_TABLE, $conditions);
        if ($enabled && !$existing) {
            $DB->insert_record(self::ASSIGN_TABLE, (object)($conditions + ['assignedby' => $actor, 'timecreated' => time()]));
        } else if (!$enabled && $existing) {
            $DB->delete_records(self::ASSIGN_TABLE, $conditions);
        } else {
            // Repeating the same assignment state is idempotent. Scope belongs
            // to aclgrant, not aclassign; do not write a nonexistent field.
            $tx->allow_commit();
            return;
        }
        self::audit($companyuuid, $actor, $enabled ? 'assignment_created' : 'assignment_revoked', $roleid,
            'userid=' . $userid);
        $tx->allow_commit();
    }

    public static function assigned_members(string $companyuuid, int $actor): array {
        global $DB;
        company_context_service::context($companyuuid, $actor);
        return array_values($DB->get_records(self::ASSIGN_TABLE, ['companyuuid' => $companyuuid], 'id DESC'));
    }

    /** Both canonical and historical Corporate memberships are authoritative. */
    private static function is_company_member(string $companyuuid, int $userid): bool {
        global $DB;
        if ($DB->record_exists('local_kbacompany_member', [
                'companyuuid' => $companyuuid, 'userid' => $userid, 'active' => 1])) {
            return true;
        }
        $company = company_context_service::context($companyuuid, $userid);
        $sourceid = (int)($company['compatibility']['source_id'] ?? 0);
        return $sourceid > 0 && legacy_company_adapter::is_ready() &&
            legacy_company_adapter::membership($sourceid, $userid) !== null;
    }

    public static function member_options(string $companyuuid, int $actor): array {
        global $DB;
        self::require_administrator($companyuuid, $actor);
        $sql = 'SELECT u.id, u.firstname, u.lastname FROM {local_kbacompany_member} m
                JOIN {user} u ON u.id = m.userid AND u.deleted = 0 AND u.suspended = 0
                WHERE m.companyuuid = :company AND m.active = 1 ORDER BY u.lastname, u.firstname';
        $options = [];
        foreach ($DB->get_records_sql($sql, ['company' => $companyuuid]) as $user) {
            $options[(int)$user->id] = fullname($user) . ' (Moodle ID ' . $user->id . ')';
        }
        $company = company_context_service::context($companyuuid, $actor);
        $sourceid = (int)($company['compatibility']['source_id'] ?? 0);
        if ($sourceid > 0 && legacy_company_adapter::is_ready()) {
            $legacysql = 'SELECT u.id, u.firstname, u.lastname FROM {local_kbacorp_member} m
                JOIN {user} u ON u.id = m.userid AND u.deleted = 0 AND u.suspended = 0
                WHERE m.companyid = :companyid ORDER BY u.lastname, u.firstname';
            foreach ($DB->get_records_sql($legacysql, ['companyid' => $sourceid]) as $user) {
                $options[(int)$user->id] = fullname($user) . ' (Moodle ID ' . $user->id . ')';
            }
        }
        asort($options);
        return $options;
    }
    /** Company-scoped unique active Moodle accounts. Not Academy enrolment seats. */
    public static function seat_snapshot(string $companyuuid, int $actor): array {
        self::require_administrator($companyuuid, $actor);
        // member_options merges canonical and legacy membership keyed by Moodle userid.
        // Suspended/deleted Moodle users are excluded by both queries.
        $members = self::member_options($companyuuid, $actor);
        return [
            'activeusers' => count($members),
            'limit' => null, // Future BMS Cloud subscription resolver, not Moodle course seats.
            'reserved' => null, // No invitation registry is enabled in this release.
            'available' => null,
            'mode' => 'INFORMATION_ONLY',
        ];
    }

}
