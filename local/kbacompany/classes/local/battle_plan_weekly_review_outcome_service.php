<?php
namespace local_kbacompany\local;

defined('MOODLE_INTERNAL') || die();

/**
 * Read-only weekly approval results. Week refers to the PLAN period, not the event timestamp.
 * A resubmitted plan is counted as waiting, rather than as its preceding return-to-draft.
 * No status is inferred as "approved" from an active or closed plan lacking an approved review event.
 */
class battle_plan_weekly_review_outcome_service {
    public const OUTCOMES = ['waiting', 'approved', 'returned', 'other'];

    /** Pure projection (also tested outside Moodle). Event values are latest per plan. */
    public static function summarize(array $plans, array $latestdecisions): array {
        $total = ['all'=>0, 'waiting'=>0, 'approved'=>0, 'returned'=>0, 'other'=>0];
        $units = [];
        $reviewers = [];
        foreach ($plans as $plan) {
            $uuid = (string)$plan['plan_uuid'];
            $status = (string)$plan['status'];
            $event = (string)($latestdecisions[$uuid] ?? '');
            $outcome = 'other';
            if ($status === 'submitted') {
                $outcome = 'waiting';
            } else if ($event === 'approved' && in_array($status, ['active', 'closed'], true)) {
                $outcome = 'approved';
            } else if ($event === 'changes' && $status === 'draft') {
                $outcome = 'returned';
            }
            $total['all']++;
            $total[$outcome]++;
            $unitid = (string)$plan['unit_uuid'];
            $reviewerid = (int)$plan['reviewer_id'];
            if (!isset($units[$unitid])) {
                $units[$unitid] = ['uuid'=>$unitid, 'name'=>(string)$plan['unit_name']] +
                    array_fill_keys(['all', 'waiting', 'approved', 'returned', 'other'], 0);
            }
            if (!isset($reviewers[$reviewerid])) {
                $reviewers[$reviewerid] = ['id'=>$reviewerid, 'name'=>(string)$plan['reviewer_name']] +
                    array_fill_keys(['all', 'waiting', 'approved', 'returned', 'other'], 0);
            }
            $units[$unitid]['all']++;
            $units[$unitid][$outcome]++;
            $reviewers[$reviewerid]['all']++;
            $reviewers[$reviewerid][$outcome]++;
        }
        $sort = static function(array $a, array $b): int {
            return $b['waiting'] <=> $a['waiting'] ?: $b['all'] <=> $a['all'] ?: strcmp($a['name'], $b['name']);
        };
        $units = array_values($units);
        $reviewers = array_values($reviewers);
        usort($units, $sort);
        usort($reviewers, $sort);
        return ['counts'=>$total, 'units'=>$units, 'reviewers'=>$reviewers];
    }

    /** Company + plan ACL filtering MUST happen before accessing the append-only review history. */
    public static function context(string $companyuuid, int $actor, string $week,
            string $orgnodefilter = '', int $reviewerfilter = 0): array {
        global $DB;
        company_context_service::context($companyuuid, $actor);
        $week = battle_plan_service::week_start($week);
        $canmanage = task_service::can_manage($companyuuid, $actor);
        $positions = $DB->get_records('local_kbacompany_position', ['companyuuid'=>$companyuuid]);
        $positionunits = [];
        foreach ($positions as $position) {
            $positionunits[(string)$position->uuid] = (string)$position->orgnodeuuid;
        }
        $nodes = [];
        foreach ($DB->get_records('local_kbacompany_orgnode', ['companyuuid'=>$companyuuid]) as $node) {
            $nodes[(string)$node->uuid] = (string)$node->name;
        }
        $names = [];
        $organization = organization_service::organization_context($companyuuid, $actor);
        foreach ($organization['employees'] as $employee) {
            $names[(int)$employee['user_id']] = (string)$employee['full_name'];
        }
        $plans = [];
        foreach ($DB->get_records('local_kbacompany_bplan',
                ['companyuuid'=>$companyuuid, 'periodstart'=>$week], 'id ASC') as $plan) {
            // Same plan-visibility boundary as the management overview, including closed plans.
            if (!$canmanage && (int)$plan->userid !== $actor && (int)$plan->reviewerid !== $actor &&
                    (int)$plan->observerid !== $actor) {
                continue;
            }
            if ($reviewerfilter > 0 && (int)$plan->reviewerid !== $reviewerfilter) { continue; }
            $unit = $positionunits[(string)$plan->positionuuid] ?? '';
            if ($orgnodefilter !== '' && $unit !== $orgnodefilter) { continue; }
            $plans[] = [
                'plan_uuid'=>(string)$plan->uuid, 'status'=>(string)$plan->status,
                'unit_uuid'=>$unit, 'unit_name'=>$nodes[$unit] ?? '',
                'reviewer_id'=>(int)$plan->reviewerid,
                'reviewer_name'=>$names[(int)$plan->reviewerid] ?? '',
            ];
        }
        $decisions = [];
        // Batch reads to keep bounded query parameters for larger companies. Multiple item-specific
        // "changes" rows from one review are reduced to a single latest per-plan decision.
        $planids = array_column($plans, 'plan_uuid');
        foreach (array_chunk($planids, 150) as $chunk) {
            list($insql, $params) = $DB->get_in_or_equal($chunk, SQL_PARAMS_NAMED, 'bpout');
            $params['bpcompany'] = $companyuuid;
            $rs = $DB->get_recordset_sql('SELECT r.id, r.planuuid, r.decision
                  FROM {local_kbacompany_bplanrev} r
                 WHERE r.companyuuid = :bpcompany AND r.planuuid ' . $insql . '
              ORDER BY r.timecreated DESC, r.id DESC', $params);
            try {
                foreach ($rs as $event) {
                    $planid = (string)$event->planuuid;
                    if (!isset($decisions[$planid])) {
                        $decisions[$planid] = (string)$event->decision;
                    }
                }
            } finally {
                $rs->close();
            }
        }
        return self::summarize($plans, $decisions);
    }
}
