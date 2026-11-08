<?php
namespace local_kbacorporate\local;

defined('MOODLE_INTERNAL') || die();

/**
 * Read-only evidence decision status for implementation tasks in one paid program.
 * No evidence content, review comments, attachments, contact data, or task mutation.
 */
final class implementation_program_result_projection {
    private const VALID_EVIDENCE = ['submitted', 'accepted', 'rejected'];
    private const BATCH = 250;

    /** Classify a task without equating status=completed with accepted evidence. */
    public static function state(string $taskstatus, string $evidencestatus): string {
        if ($taskstatus === 'cancelled') {
            return 'cancelled';
        }
        if ($taskstatus === 'completed') {
            return $evidencestatus === 'accepted' ? 'accepted' :
                ($evidencestatus === 'none' ? 'completed_unverified' : 'inconsistent');
        }
        if ($taskstatus === 'review') {
            return $evidencestatus === 'submitted' ? 'awaiting_review' :
                ($evidencestatus === 'none' ? 'review_without_evidence' : 'inconsistent');
        }
        if ($taskstatus === 'inprogress') {
            return $evidencestatus === 'rejected' ? 'returned' :
                ($evidencestatus === 'none' ? 'in_work' : 'inconsistent');
        }
        if ($taskstatus === 'planned') {
            return $evidencestatus === 'none' ? 'not_started' : 'inconsistent';
        }
        return 'inconsistent';
    }

    /** Pure projection for standalone contract tests. Evidence indexed by numeric task id. */
    public static function project_rows(array $tasks, array $evidence, int $companyid,
            int $programid, int $auditid): array {
        $result = [];
        $seen = [];
        foreach ($tasks as $task) {
            $idstr = (string)($task['external_id'] ?? '');
            if (!preg_match('/^impltask:([1-9][0-9]*)$/D', $idstr, $matches) ||
                    isset($seen[$idstr])) {
                continue;
            }
            $seen[$idstr] = true;
            $id = (int)$matches[1];
            $latest = $evidence[$id] ?? null;
            $status = $latest ? (string)$latest->status : 'none';
            if ($status !== 'none' && !in_array($status, self::VALID_EVIDENCE, true)) {
                $status = 'invalid';
            }
            $result[] = [
                'external_id' => $idstr,
                'section_code' => (string)$task['section_code'],
                'task_status' => (string)$task['status'],
                'result_state' => self::state((string)$task['status'], $status),
                'evidence_state' => $status,
                'evidence_version' => $latest ? (int)$latest->versionno : 0,
                'submitted_at' => $latest ? (int)$latest->submittedat : 0,
                'reviewed_at' => $latest ? (int)$latest->reviewedat : 0,
            ];
        }
        return ['company_id' => $companyid, 'program_id' => $programid, 'audit_id' => $auditid,
            'complete' => true, 'reason' => '', 'tasks' => $result];
    }

    /**
     * Reuse Corporate's authorisation, canonical program selection, confirmed audit,
     * deleted-task filter, and 5000-task hard cap before any evidence query.
     */
    public static function get(int $companyid, int $actorid, int $programid): ?array {
        global $DB;
        $source = implementation_program_task_sections_projection::get($companyid, $actorid, $programid);
        if ($source === null || empty($source['complete'])) {
            return $source === null ? null : [
                'company_id' => $companyid, 'program_id' => $programid,
                'audit_id' => (int)($source['audit_id'] ?? 0), 'complete' => false,
                'reason' => (string)($source['reason'] ?? 'incomplete'), 'tasks' => [],
            ];
        }
        $auditid = (int)$source['audit_id'];
        if ((int)$source['company_id'] !== $companyid || (int)$source['program_id'] !== $programid ||
                $auditid <= 0) {
            return null;
        }
        $table = new \xmldb_table('local_kbacorp_impl_evid');
        if (!$DB->get_manager()->table_exists($table)) {
            return ['company_id'=>$companyid, 'program_id'=>$programid, 'audit_id'=>$auditid,
                'complete'=>false, 'reason'=>'evidence_unavailable', 'tasks'=>[]];
        }
        $ids = [];
        foreach ($source['tasks'] as $task) {
            $ids[] = (int)substr((string)$task['external_id'], strlen('impltask:'));
        }
        // Every query is bounded and returns at most one latest version per task.
        // The (taskid,versionno) unique XMLDB index enforces that property.
        $evidence = [];
        foreach (array_chunk($ids, self::BATCH) as $chunk) {
            list($insql, $params) = $DB->get_in_or_equal($chunk, SQL_PARAMS_NAMED, 'ev');
            $sql = "SELECT e.taskid, e.versionno, e.status, e.submittedat, e.reviewedat
                      FROM {local_kbacorp_impl_evid} e
                      JOIN (SELECT taskid, MAX(versionno) AS maxversion
                              FROM {local_kbacorp_impl_evid}
                             WHERE taskid $insql GROUP BY taskid) latest
                        ON latest.taskid = e.taskid AND latest.maxversion = e.versionno";
            foreach ($DB->get_records_sql($sql, $params) as $row) {
                $evidence[(int)$row->taskid] = $row;
            }
        }
        return self::project_rows($source['tasks'], $evidence, $companyid, $programid, $auditid);
    }
}
