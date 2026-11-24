<?php
namespace local_kbacompany\local;

defined('MOODLE_INTERNAL') || die();

/** Read-only personal day projection. No report records, inferred work time or duplicated tasks. */
final class daily_report_service {
    public const PAGE_SIZE = 25;

    /** Civil day in the active Moodle user's timezone; exclusive end handles DST. */
    public static function bounds(string $day): array {
        $zone = \core_date::get_user_timezone_object();
        $start = \DateTimeImmutable::createFromFormat('!Y-m-d', $day, $zone);
        if (!$start || $start->format('Y-m-d') !== $day) {
            throw new \moodle_exception('dailyreport_invaliddate', 'local_kbacompany');
        }
        return [$start->getTimestamp(), $start->modify('+1 day')->getTimestamp()];
    }

    /** Actor is the authenticated employee, never a requested employee id. Current ACL applies to old events. */
    public static function context(string $companyuuid, int $actor, string $day,
            int $taskpage = 0, int $resultpage = 0): array {
        global $DB;
        $companyuuid = company_context_service::normalise_uuid($companyuuid);
        [$where, $params] = task_service::own_query_conditions($companyuuid, $actor);
        [$from, $until] = self::bounds($day);
        if ($taskpage < 0 || $resultpage < 0 || $taskpage > 100000 || $resultpage > 100000) {
            throw new \moodle_exception('invalidparameter');
        }
        $duewhere = $where . ' AND t.duedate = :reportday';
        $dueparams = $params + ['reportday' => $day];
        $total = $DB->count_records_sql("SELECT COUNT(t.id) FROM {local_kbacompany_task} t WHERE {$duewhere}", $dueparams);
        $tasks = array_values($DB->get_records_sql("SELECT t.id,t.uuid,t.title,t.status,t.duedate,t.factminutes
            FROM {local_kbacompany_task} t WHERE {$duewhere} ORDER BY t.id",
            $dueparams, $taskpage * self::PAGE_SIZE, self::PAGE_SIZE));
        [$join, $eventwhere, $eventparams] = self::result_query($companyuuid, $actor, $where, $params, $from, $until);
        $eventtotal = $DB->count_records_sql("SELECT COUNT(l.id) {$join} WHERE {$eventwhere}", $eventparams);
        $events = array_values($DB->get_records_sql("SELECT " . self::RESULT_FIELDS . " {$join} WHERE {$eventwhere}
            ORDER BY l.timecreated,l.id", $eventparams, $resultpage * self::PAGE_SIZE, self::PAGE_SIZE));
        foreach ($events as $event) {
            self::decode_result($event);
        }
        return ['day' => $day, 'timezone' => \core_date::get_user_timezone(), 'tasks' => $tasks,
            'tasktotal' => (int)$total, 'taskpage' => $taskpage, 'results' => $events,
            'resulttotal' => (int)$eventtotal, 'resultpage' => $resultpage];
    }

    private const RESULT_FIELDS = 'l.id,l.taskuuid,l.details,l.newstatus,l.timecreated,t.title,t.status AS currentstatus';

    /**
     * Own submitted results of a civil day. Company is matched on BOTH the log and the canonical task before fetching;
     * the author is the session user; the task conditions are the current "my tasks" ACL. A submit is not an acceptance.
     */
    private static function result_query(string $companyuuid, int $actor, string $where, array $params, int $from,
            int $until): array {
        $eventwhere = $where . " AND l.companyuuid = :logcompany AND l.actorid = :logactor
            AND l.action = 'submit' AND l.timecreated >= :logfrom AND l.timecreated < :loguntil";
        $eventparams = $params + ['logcompany' => $companyuuid, 'logactor' => $actor,
            'logfrom' => $from, 'loguntil' => $until];
        $join = 'FROM {local_kbacompany_tasklog} l JOIN {local_kbacompany_task} t
            ON t.companyuuid = l.companyuuid AND t.uuid = l.taskuuid';
        return [$join, $eventwhere, $eventparams];
    }

    /** The result text is the one stored in the submit event (snapshot), never the mutable current task result. */
    private static function decode_result(\stdClass $event): void {
        $details = json_decode((string)$event->details, true);
        $event->result = is_array($details) && is_string($details['result'] ?? null) ? $details['result'] : '';
        unset($event->details);
    }

    /**
     * Day feed -> report draft: exactly one own submitted result of the given day, under the same conditions as the day
     * summary. Null for an unknown id, another company, another author, another day, a non-submit event or a task the
     * actor can no longer open; the caller cannot tell these cases apart.
     */
    public static function result(string $companyuuid, int $actor, string $day, int $eventid): ?\stdClass {
        global $DB;
        $companyuuid = company_context_service::normalise_uuid($companyuuid);
        [$where, $params] = task_service::own_query_conditions($companyuuid, $actor);
        [$from, $until] = self::bounds($day);
        if ($eventid <= 0) {
            return null;
        }
        [$join, $eventwhere, $eventparams] = self::result_query($companyuuid, $actor, $where, $params, $from, $until);
        $event = $DB->get_record_sql("SELECT " . self::RESULT_FIELDS . " {$join} WHERE {$eventwhere} AND l.id = :resultid",
            $eventparams + ['resultid' => $eventid]);
        if (!$event) {
            return null;
        }
        self::decode_result($event);
        return $event;
    }
}
