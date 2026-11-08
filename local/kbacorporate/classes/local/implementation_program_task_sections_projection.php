<?php
namespace local_kbacorporate\local;

defined('MOODLE_INTERNAL') || die();

/** Minimal stable task-to-audit-section identities for weekly management projections. */
final class implementation_program_task_sections_projection {
    public const MAX_TASKS = 5000;
    private const VALID = ['planned', 'inprogress', 'review', 'completed', 'cancelled'];

    /** Pure, strict outbound contract, safe for PHP CLI fixture tests. */
    public static function project_rows(array $records, int $companyid, int $programid,
            int $auditid, bool $complete = true): array {
        $rows = [];
        $seen = [];
        foreach ($records as $task) {
            if ((int)$task->companyid !== $companyid || (int)$task->auditid !== $auditid ||
                    (int)$task->id < 1 || isset($seen[(int)$task->id])) {
                continue;
            }
            $seen[(int)$task->id] = true;
            $status = (string)$task->status;
            if (!in_array($status, self::VALID, true)) { continue; }
            $code = trim((string)($task->sectioncode ?? ''));
            $rows[] = [
                'external_id' => 'impltask:' . (int)$task->id,
                'section_code' => $code !== '' ? $code : 'general',
                'status' => $status,
                'title' => (string)($task->title ?? ''),
                'assignee_id' => (int)($task->ownerid ?? 0),
                'due_at' => (int)($task->duedate ?? 0),
            ];
        }
        return ['company_id' => $companyid, 'program_id' => $programid,
            'audit_id' => $auditid, 'complete' => $complete, 'tasks' => $complete ? $rows : [],
            'reason' => $complete ? '' : 'limit_exceeded'];
    }

    /** Always authorize first, and never infer company/program from a task ID. */
    public static function get(int $companyid, int $actorid, int $programid): ?array {
        global $DB;
        $program = implementation_program_projection::get_program($companyid, $actorid, $programid);
        if (!$program) { return null; }
        $auditid = (int)$program['audit_id'];
        if ($auditid <= 0 || !$DB->record_exists('local_kbacorp_impl_audit', [
                'id' => $auditid, 'companyid' => $companyid,
                'status' => implementation_audits::STATUS_CONFIRMED])) {
            return ['company_id'=>$companyid, 'program_id'=>$programid, 'audit_id'=>$auditid,
                'complete'=>false, 'tasks'=>[], 'reason'=>'unconfirmed_baseline'];
        }
        // Task provider 1.22 resolves a single primary paid program for a shared audit.
        // If a second program references the same audit, do not attribute tasks twice.
        $canonical = $DB->get_field_sql(
            "SELECT MIN(id) FROM {local_kbacorp_impl_program}
              WHERE companyid = :companyid AND baselineauditid = :auditid
                AND commercialstatus = 'paid' AND status IN ('active','paused','completed')",
            ['companyid'=>$companyid, 'auditid'=>$auditid]);
        if ((int)$canonical !== $programid) {
            return ['company_id'=>$companyid, 'program_id'=>$programid, 'audit_id'=>$auditid,
                'complete'=>false, 'tasks'=>[], 'reason'=>'shared_baseline'];
        }
        $records = array_values($DB->get_records_sql(
            "SELECT t.id, t.companyid, t.auditid, t.title, t.status, t.ownerid, t.duedate,
                    i.sectioncode
               FROM {local_kbacorp_impl_task} t
               JOIN {local_kbacorp_impl_item} i
                 ON i.id = t.itemid AND i.auditid = t.auditid
              WHERE t.companyid = :companyid AND t.auditid = :auditid AND t.deleted = 0
           ORDER BY t.id ASC",
            ['companyid'=>$companyid, 'auditid'=>$auditid], 0, self::MAX_TASKS + 1));
        return self::project_rows($records, $companyid, $programid, $auditid,
            count($records) <= self::MAX_TASKS);
    }
}
