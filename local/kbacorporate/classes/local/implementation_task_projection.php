<?php
namespace local_kbacorporate\local;

defined('MOODLE_INTERNAL') || die();

/**
 * Read-only, company-scoped BMS task projections.
 *
 * All authorization, program visibility and data minimization happen inside
 * Corporate. External callers must additionally authorize the BMS company
 * context: the numeric Corporate company ID is not a BMS UUID.
 *
 * No comments, evidence, member details or task mutation are exposed here.
 */
final class implementation_task_projection {
    public const MAX_LIMIT = 100;
    public const MAX_OFFSET = 10000;
    public const SCOPES = ['mine', 'employee', 'company'];
    private const STATUSES = ['planned', 'inprogress', 'review', 'completed', 'cancelled'];

    /**
     * Return a deterministic, bounded page of accessible implementation tasks.
     *
     * 'mine': targetuserid MUST equal actorid and be an active employee member;
     * 'employee': cross-user access requires company implementation management;
     * 'company': targetuserid MUST be zero and company management is required.
     * The caller is responsible for BMS-side membership and UUID validation.
     *
     * @return array[] Safe projection rows (never raw Corporate records).
     */
    public static function list_tasks(int $companyid, int $actorid, int $targetuserid,
            string $scope = 'mine', int $limit = 50, int $offset = 0): array {
        global $DB;
        self::validate_paging($limit, $offset);
        self::require_scope($companyid, $actorid, $targetuserid, $scope);
        $params = ['companyid' => $companyid];
        $ownerfilter = '';
        if ($scope !== 'company') {
            $ownerfilter = ' AND t.ownerid = :ownerid';
            $params['ownerid'] = $targetuserid;
        }
        $sql = "SELECT t.id, t.companyid, t.auditid, t.ownerid, t.createdby,
                       t.title, t.expectedresult, t.status, t.priority,
                       t.startdate, t.duedate, t.completedat, t.timemodified,
                       p.id AS programid, p.title AS programtitle,
                       p.curatorid AS reviewerid, p.status AS programstatus
                  FROM {local_kbacorp_impl_task} t
                  JOIN {local_kbacorp_impl_program} p
                    ON p.baselineauditid = t.auditid AND p.companyid = t.companyid
                 WHERE t.companyid = :companyid AND t.deleted = 0
                   AND p.commercialstatus = 'paid'
                   AND p.status IN ('active', 'paused', 'completed')
                   AND p.id = (SELECT MIN(p2.id)
                                 FROM {local_kbacorp_impl_program} p2
                                WHERE p2.baselineauditid = t.auditid
                                  AND p2.companyid = t.companyid
                                  AND p2.commercialstatus = 'paid'
                                  AND p2.status IN ('active', 'paused', 'completed'))
                       $ownerfilter
              ORDER BY t.timemodified DESC, t.id DESC";
        $records = $DB->get_records_sql($sql, $params, $offset, $limit);
        $result = [];
        foreach ($records as $task) {
            $result[] = self::project($task);
        }
        return $result;
    }

    /**
     * Return a single task only if it is visible in this exact Corporate company.
     * A missing, deleted, unpaid or unauthorized task is indistinguishable to the caller.
     */
    public static function get_task(int $companyid, int $actorid, int $taskid): ?array {
        global $DB;
        if ($companyid <= 0 || $actorid <= 0 || $taskid <= 0 ||
                !self::is_active_company($companyid) || !self::is_active_user($actorid)) {
            throw new \moodle_exception('companynotallowed', 'local_kbacorporate');
        }
        $sql = "SELECT t.id, t.companyid, t.auditid, t.ownerid, t.createdby,
                       t.title, t.expectedresult, t.status, t.priority,
                       t.startdate, t.duedate, t.completedat, t.timemodified,
                       p.id AS programid, p.title AS programtitle,
                       p.curatorid AS reviewerid, p.status AS programstatus
                  FROM {local_kbacorp_impl_task} t
                  JOIN {local_kbacorp_impl_program} p
                    ON p.baselineauditid = t.auditid AND p.companyid = t.companyid
                 WHERE t.id = :taskid AND t.companyid = :companyid
                   AND t.deleted = 0 AND p.commercialstatus = 'paid'
                   AND p.status IN ('active', 'paused', 'completed')
                   AND p.id = (SELECT MIN(p2.id)
                                 FROM {local_kbacorp_impl_program} p2
                                WHERE p2.baselineauditid = t.auditid
                                  AND p2.companyid = t.companyid
                                  AND p2.commercialstatus = 'paid'
                                  AND p2.status IN ('active', 'paused', 'completed'))";
        $task = $DB->get_record_sql($sql, ['taskid' => $taskid, 'companyid' => $companyid]);
        if (!$task) {
            return null;
        }
        // Deliberately exclude curator-only access from this BMS-oriented bridge.
        // A curator may visit the original Corporate UI, but BMS access is separate.
        $owns = (int)$task->ownerid === $actorid && self::is_employee($companyid, $actorid);
        $manages = access::can_manage_company_implementation($companyid, $actorid);
        if (!$owns && !$manages) {
            return null;
        }
        return self::project($task);
    }

    /** Restrict scopes before SQL; unauthorized company is never queried for tasks. */
    private static function require_scope(int $companyid, int $actorid, int $targetuserid,
            string $scope): void {
        if ($companyid <= 0 || $actorid <= 0 || !in_array($scope, self::SCOPES, true) ||
                !self::is_active_company($companyid) || !self::is_active_user($actorid)) {
            throw new \moodle_exception('companynotallowed', 'local_kbacorporate');
        }
        if ($scope === 'company') {
            if ($targetuserid !== 0 || !access::can_manage_company_implementation($companyid, $actorid)) {
                throw new \moodle_exception('companynotallowed', 'local_kbacorporate');
            }
            return;
        }
        if ($targetuserid <= 0 || !self::is_employee($companyid, $targetuserid)) {
            throw new \moodle_exception('companynotallowed', 'local_kbacorporate');
        }
        if ($scope === 'mine') {
            if ($targetuserid !== $actorid) {
                throw new \moodle_exception('companynotallowed', 'local_kbacorporate');
            }
        } else if (!access::can_manage_company_implementation($companyid, $actorid)) {
            throw new \moodle_exception('companynotallowed', 'local_kbacorporate');
        }
    }

    private static function is_active_company(int $companyid): bool {
        global $DB;
        return $DB->record_exists('local_kbacorp_company', ['id' => $companyid, 'active' => 1]);
    }

    private static function is_active_user(int $userid): bool {
        global $DB;
        return $userid > 0 && $DB->record_exists('user', [
            'id' => $userid, 'deleted' => 0, 'suspended' => 0,
        ]);
    }

    private static function is_employee(int $companyid, int $userid): bool {
        global $DB;
        return self::is_active_user($userid) && $DB->record_exists('local_kbacorp_member', [
            'companyid' => $companyid, 'userid' => $userid, 'isemployee' => 1,
        ]);
    }

    private static function validate_paging(int $limit, int $offset): void {
        if ($limit < 1 || $limit > self::MAX_LIMIT || $offset < 0 || $offset > self::MAX_OFFSET) {
            throw new \invalid_parameter_exception('Invalid implementation task pagination');
        }
    }

    /** Only minimal, explicitly whitelisted, primitive fields. */
    private static function project(\stdClass $task): array {
        $status = (string)$task->status;
        if (!in_array($status, self::STATUSES, true)) {
            $status = 'unknown';
        }
        return [
            'provider' => 'kbacorporate_impl',
            'external_id' => 'impltask:' . (int)$task->id,
            'company_id' => (int)$task->companyid,
            'program_id' => (int)$task->programid,
            'program_title' => (string)$task->programtitle,
            'program_status' => (string)$task->programstatus,
            'source_kind' => 'implementation',
            'title' => (string)$task->title,
            'expected_result' => (string)$task->expectedresult,
            'assignee_id' => (int)$task->ownerid,
            'reviewer_id' => (int)$task->reviewerid,
            'creator_id' => (int)$task->createdby,
            'status' => $status,
            'priority' => (string)$task->priority,
            'start_at' => (int)$task->startdate,
            'due_at' => (int)$task->duedate,
            'completed_at' => (int)$task->completedat,
            'time_modified' => (int)$task->timemodified,
            'attachable' => $task->programstatus === 'active' &&
                in_array($status, ['planned', 'inprogress'], true) && (int)$task->ownerid > 0,
            'editable' => false,
            'source_url' => '/local/kbacorporate/implementation_task.php?id=' . (int)$task->id,
        ];
    }
}
