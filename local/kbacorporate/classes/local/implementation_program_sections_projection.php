<?php
namespace local_kbacorporate\local;

defined('MOODLE_INTERNAL') || die();

/** Safe, read-only section summaries for an authorized paid implementation program. */
final class implementation_program_sections_projection {
    /** Only non-sensitive counters from the confirmed baseline section progress model. */
    public static function sanitize_model(\stdClass $model, array $program): array {
        $sections = [];
        foreach ($model->sections as $section) {
            $sections[] = [
                'code' => (string)$section->code,
                'name' => (string)$section->name,
                'status' => (string)$section->status,
                'criteria_count' => (int)$section->totalcriteria,
                'gap_count' => (int)$section->gapcriteria,
                'covered_count' => (int)$section->coveredcriteria,
                'task_count' => (int)$section->tasktotal,
                'completed_count' => (int)$section->completedtasks,
                'review_count' => (int)$section->reviewtasks,
                'overdue_count' => (int)$section->overduetasks,
                'unassigned_count' => (int)$section->unassignedtasks,
                'accepted_evidence_count' => (int)$section->acceptedevidence,
                // Completion of tasks is not a measure of verified methodology adoption.
                'task_completion_percent' => $section->tasktotal > 0
                    ? round(100 * $section->completedtasks / $section->tasktotal, 1) : null,
            ];
        }
        return [
            'company_id' => (int)$program['company_id'],
            'program_id' => (int)$program['program_id'],
            'audit_id' => (int)$program['audit_id'],
            'available' => true,
            'reason' => '',
            'sections' => $sections,
            'summary' => [
                'section_count' => count($sections),
                'tasks' => (int)$model->summary->tasktotal,
                'completed' => (int)$model->summary->completedtasks,
                'overdue' => (int)$model->summary->overduetasks,
                'accepted_evidence' => (int)$model->summary->acceptedevidence,
            ],
        ];
    }

    /** Corporate ACL precedes all section reads; never return audit items or evidence payloads. */
    public static function get(int $companyid, int $actorid, int $programid): ?array {
        $program = implementation_program_projection::get_program($companyid, $actorid, $programid);
        if (!$program) {
            return null;
        }
        $auditid = (int)$program['audit_id'];
        // Corporate section model requires a confirmed snapshot; a missing baseline is not zero progress.
        if ($auditid <= 0) {
            return self::unavailable($program, 'unconfirmed_baseline');
        }
        global $DB;
        if (!$DB->record_exists('local_kbacorp_impl_audit', [
            'id' => $auditid, 'companyid' => $companyid,
            'status' => implementation_audits::STATUS_CONFIRMED,
        ])) {
            return self::unavailable($program, 'unconfirmed_baseline');
        }
        $model = implementation_section_progress::build($companyid, $auditid);
        return self::sanitize_model($model, $program);
    }

    private static function unavailable(array $program, string $reason): array {
        return [
            'company_id' => (int)$program['company_id'],
            'program_id' => (int)$program['program_id'],
            'audit_id' => (int)$program['audit_id'],
            'available' => false,
            'reason' => $reason,
            'sections' => [],
            'summary' => null,
        ];
    }
}
