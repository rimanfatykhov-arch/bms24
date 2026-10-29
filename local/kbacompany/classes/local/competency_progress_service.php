<?php
namespace local_kbacompany\local;

defined('MOODLE_INTERNAL') || die();

/** Reassessment recommendations and competency progress derived from approved assessments. */
class competency_progress_service {
    public const CONTRACT_VERSION = '0.7.8';

    public static function contract(): array {
        return [
            'component' => 'local_kbacompany',
            'contract' => 'bms_competency_progress',
            'version' => self::CONTRACT_VERSION,
            'company_scope' => 'required_before_fetch',
            'rules' => [
                'progress_uses_approved_assessments_only',
                'development_completion_may_recommend_reassessment_but_never_changes_competency_fact',
                'published_position_profile_change_may_recommend_reassessment',
                'no_arbitrary_competency_expiry_period_is_introduced',
                'reassessment_is_a_new_formal_assessment_and_requires_normal_approval',
                'course_completion_is_evidence_only',
            ],
        ];
    }

    public static function context(string $companyuuid, int $userid, int $actorid): array {
        global $DB;
        company_context_service::context($companyuuid, $actorid);
        $profile = employee_competency_profile_service::context($companyuuid, $userid, $actorid);
        $assessments = $DB->get_records('local_kbacompany_assess', [
            'companyuuid' => $companyuuid,
            'userid' => $userid,
            'status' => 'approved',
        ], 'decidedat ASC, id ASC');

        $catalog = [];
        foreach (competency_catalog_service::competencies($companyuuid, $actorid) as $row) {
            $catalog[(int)$row['id']] = $row;
        }

        $history = [];
        $latestassessment = null;
        foreach ($assessments as $assessment) {
            $latestassessment = $assessment;
            $items = $DB->get_records('local_kbacompany_assessitem', [
                'companyuuid' => $companyuuid,
                'assessmentuuid' => (string)$assessment->uuid,
            ], 'id ASC');
            foreach ($items as $item) {
                $cid = (int)$item->competencyid;
                if (!isset($history[$cid])) {
                    $history[$cid] = [];
                }
                $history[$cid][] = [
                    'assessment_uuid' => (string)$assessment->uuid,
                    'method' => (string)$assessment->method,
                    'decided_at' => (int)$assessment->decidedat,
                    'level' => (int)$item->decidedlevel,
                    'target_level' => (int)$item->targetlevel,
                ];
            }
        }

        $rows = [];
        $summary = ['tracked' => 0, 'improved' => 0, 'stable' => 0, 'declined' => 0, 'unassessed' => 0];
        foreach ($profile['requirements'] as $requirement) {
            $cid = (int)$requirement['competency_id'];
            $points = $history[$cid] ?? [];
            $first = $points ? reset($points) : null;
            $last = $points ? end($points) : null;
            $trend = 'unassessed';
            $delta = null;
            if ($first && $last) {
                $delta = (int)$last['level'] - (int)$first['level'];
                if ($delta > 0) {
                    $trend = 'improved';
                } else if ($delta < 0) {
                    $trend = 'declined';
                } else {
                    $trend = 'stable';
                }
                $summary['tracked']++;
            }
            $summary[$trend]++;
            $rows[] = [
                'competency_id' => $cid,
                'name' => $catalog[$cid]['shortname'] ?? $requirement['name'],
                'target_level' => $requirement['target_level'],
                'current_level' => $requirement['current_level'],
                'first_level' => $first ? (int)$first['level'] : null,
                'last_level' => $last ? (int)$last['level'] : null,
                'delta' => $delta,
                'trend' => $trend,
                'history' => $points,
            ];
        }

        $recommendation = ['recommended' => false, 'reasons' => []];
        $lastdecidedat = $latestassessment ? (int)$latestassessment->decidedat : 0;
        if (!$latestassessment) {
            $recommendation['reasons'][] = 'no_approved_assessment';
        } else {
            if (!empty($profile['position_profile'])) {
                if ((string)$latestassessment->profileuuid !== (string)$profile['position_profile']['uuid'] ||
                        (int)$latestassessment->profilerevision !== (int)$profile['position_profile']['revision']) {
                    $recommendation['recommended'] = true;
                    $recommendation['reasons'][] = 'position_profile_changed';
                }
            }

            $sql = "SELECT di.id
                      FROM {local_kbacompany_devitem} di
                      JOIN {local_kbacompany_devplan} dp ON dp.uuid = di.planuuid AND dp.companyuuid = di.companyuuid
                     WHERE di.companyuuid = :companyuuid
                       AND dp.userid = :userid
                       AND di.status = :completed
                       AND di.timemodified > :lastdecidedat";
            if ($DB->record_exists_sql($sql, [
                'companyuuid' => $companyuuid,
                'userid' => $userid,
                'completed' => 'completed',
                'lastdecidedat' => $lastdecidedat,
            ])) {
                $recommendation['recommended'] = true;
                $recommendation['reasons'][] = 'development_completed';
            }
        }

        return [
            'employee' => $profile['employee'],
            'position' => $profile['position'],
            'position_profile' => $profile['position_profile'],
            'requirements' => $profile['requirements'],
            'rows' => $rows,
            'summary' => $summary,
            'approved_assessment_count' => count($assessments),
            'latest_assessment' => $latestassessment,
            'recommendation' => $recommendation,
            'can_manage' => $profile['can_manage'],
        ];
    }

    public static function create_reassessment(string $companyuuid, int $userid, int $actorid, string $method = 'evidence_review'): string {
        return competency_assessment_service::create($companyuuid, $userid, $actorid, $method);
    }
}
