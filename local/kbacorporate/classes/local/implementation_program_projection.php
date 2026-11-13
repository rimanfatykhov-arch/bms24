<?php
namespace local_kbacorporate\local;

defined('MOODLE_INTERNAL') || die();

/** Read-only, manager-authorised implementation program snapshot for BMS integrations. */
final class implementation_program_projection {
    public const MAX_LIMIT = 100;
    public const MAX_OFFSET = 10000;
    private const VISIBLE = ['active', 'paused', 'completed'];

    private static function authorize(int $companyid, int $actorid): void {
        global $DB;
        if ($companyid <= 0 || $actorid <= 0 ||
                !$DB->record_exists('local_kbacorp_company', ['id' => $companyid, 'active' => 1]) ||
                !$DB->record_exists('user', ['id' => $actorid, 'deleted' => 0, 'suspended' => 0]) ||
                !access::can_manage_company_implementation($companyid, $actorid)) {
            throw new \moodle_exception('companynotallowed', 'local_kbacorporate');
        }
    }

    /** Only active/paused/completed paid programs, never commercial requests. */
    public static function list_programs(int $companyid, int $actorid, int $limit = 50,
            int $offset = 0): array {
        global $DB;
        self::authorize($companyid, $actorid);
        if ($limit < 1 || $limit > self::MAX_LIMIT || $offset < 0 || $offset > self::MAX_OFFSET) {
            throw new \invalid_parameter_exception('Invalid implementation program pagination');
        }
        [$insql, $inparams] = $DB->get_in_or_equal(self::VISIBLE, SQL_PARAMS_NAMED, 'pstatus');
        $params = array_merge(['companyid' => $companyid], $inparams);
        $sql = "SELECT p.id, p.companyid, p.baselineauditid, p.title, p.code,
                       p.status, p.startdate, p.enddate, p.curatorid, p.companyownerid,
                       p.timemodified
                  FROM {local_kbacorp_impl_program} p
                 WHERE p.companyid = :companyid AND p.commercialstatus = 'paid'
                   AND p.status $insql
              ORDER BY p.id DESC";
        $programs = array_values($DB->get_records_sql($sql, $params, $offset, $limit));
        return self::projections($companyid, $programs);
    }

    public static function get_program(int $companyid, int $actorid, int $programid): ?array {
        global $DB;
        self::authorize($companyid, $actorid);
        if ($programid <= 0) { return null; }
        $p = $DB->get_record('local_kbacorp_impl_program', [
            'id' => $programid, 'companyid' => $companyid, 'commercialstatus' => 'paid',
        ]);
        if (!$p || !in_array((string)$p->status, self::VISIBLE, true)) { return null; }
        $out = self::projections($companyid, [$p]);
        return $out[0] ?? null;
    }

    /** One grouped query for all visible programs on this page, no N+1. */
    private static function projections(int $companyid, array $programs): array {
        global $DB;
        if (!$programs) { return []; }
        $auditids = array_values(array_unique(array_map(static function($p): int {
            return (int)$p->baselineauditid;
        }, $programs)));
        [$insql, $params] = $DB->get_in_or_equal($auditids, SQL_PARAMS_NAMED, 'audit');
        $params['companyid'] = $companyid;
        $params['nowts'] = time();
        $sql = "SELECT t.auditid,
                       COUNT(t.id) AS totalcount,
                       SUM(CASE WHEN t.status = 'completed' THEN 1 ELSE 0 END) AS donecount,
                       SUM(CASE WHEN t.duedate > 0 AND t.duedate < :nowts
                                     AND t.status NOT IN ('completed','cancelled') THEN 1 ELSE 0 END) AS overduecount
                  FROM {local_kbacorp_impl_task} t
                 WHERE t.companyid = :companyid AND t.deleted = 0 AND t.auditid $insql
              GROUP BY t.auditid";
        $counts = $DB->get_records_sql($sql, $params);
        $out = [];
        foreach ($programs as $p) {
            $count = $counts[(int)$p->baselineauditid] ?? null;
            $out[] = [
                'company_id' => (int)$p->companyid,
                'program_id' => (int)$p->id,
                'audit_id' => (int)$p->baselineauditid,
                'title' => (string)$p->title,
                'code' => (string)$p->code,
                'status' => (string)$p->status,
                'start_at' => (int)$p->startdate,
                'end_at' => (int)$p->enddate,
                'curator_id' => (int)$p->curatorid,
                'owner_id' => (int)$p->companyownerid,
                'task_count' => $count ? (int)$count->totalcount : 0,
                'completed_count' => $count ? (int)$count->donecount : 0,
                'overdue_count' => $count ? (int)$count->overduecount : 0,
                'time_modified' => (int)$p->timemodified,
            ];
        }
        return $out;
    }
}
