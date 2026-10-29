<?php
namespace local_kbacompany\local;

defined('MOODLE_INTERNAL') || die();

/**
 * Read-only elapsed time in the CURRENT review cycle, not an SLA compliance report.
 * Return-to-draft clears submittedat, so old returned-cycle elapsed times are unknown.
 */
class battle_plan_review_timeliness_service {
    public const ATTENTION_SECONDS = 48 * 3600;

    /** Pure projection, with a fixed clock to make edge cases testable. */
    public static function summarize(array $plans, int $now): array {
        $counts = ['waiting'=>0, 'waiting_long'=>0, 'waiting_unknown'=>0,
            'approved_known'=>0, 'approved_unknown'=>0, 'selfapproved'=>0];
        $approvedseconds = 0;
        $units = [];
        $reviewers = [];
        $waiting = [];
        $recentapproved = [];
        foreach ($plans as $p) {
            $status = (string)$p['status'];
            if (!in_array($status, ['submitted', 'active', 'closed'], true)) {
                continue; // Returned drafts have no recoverable submitted timestamp.
            }
            $unitid = (string)$p['unit_uuid'];
            $reviewerid = (int)$p['reviewer_id'];
            if (!isset($units[$unitid])) {
                $units[$unitid] = self::empty_group($unitid, (string)$p['unit_name']);
            }
            if (!isset($reviewers[$reviewerid])) {
                $reviewers[$reviewerid] = self::empty_group($reviewerid, (string)$p['reviewer_name']);
            }
            $kind = '';
            $seconds = null;
            $submittedat = (int)$p['submitted_at'];
            $approvedat = (int)$p['approved_at'];
            $employeeid = (int)$p['employee_id'];
            $approvedby = (int)$p['approved_by'];
            if ($status === 'submitted') {
                $kind = 'waiting';
                if ($submittedat > 0 && $submittedat <= $now) {
                    $seconds = $now - $submittedat;
                    if ($seconds >= self::ATTENTION_SECONDS) {
                        $counts['waiting_long']++;
                        $units[$unitid]['waiting_long']++;
                        $reviewers[$reviewerid]['waiting_long']++;
                    }
                } else {
                    $counts['waiting_unknown']++;
                }
            } else if ($approvedby > 0 && $employeeid === $approvedby && $approvedat > 0) {
                // Self-approved plans must not lower the manager approval mean to 0.
                $kind = 'selfapproved';
            } else if ($submittedat > 0 && $approvedat >= $submittedat && $approvedat <= $now && $approvedby > 0) {
                $kind = 'approved_known';
                $seconds = $approvedat - $submittedat;
                $approvedseconds += $seconds;
                $units[$unitid]['approved_seconds'] += $seconds;
                $reviewers[$reviewerid]['approved_seconds'] += $seconds;
            } else {
                $kind = 'approved_unknown';
            }
            $counts[$kind]++;
            $units[$unitid][$kind]++;
            $reviewers[$reviewerid][$kind]++;
            $row = $p + ['elapsed_seconds'=>$seconds];
            if ($kind === 'waiting') {
                $waiting[] = $row;
            } else if ($kind === 'approved_known') {
                $recentapproved[] = $row;
            }
        }
        usort($waiting, static function(array $a, array $b): int {
            // Unknown timestamps last; then oldest submission first.
            $at = (int)$a['submitted_at'];
            $bt = (int)$b['submitted_at'];
            if ($at <= 0 || $bt <= 0) { return ($at <= 0 ? 1 : 0) <=> ($bt <= 0 ? 1 : 0); }
            return $at <=> $bt;
        });
        usort($recentapproved, static function(array $a, array $b): int {
            return (int)$b['approved_at'] <=> (int)$a['approved_at'];
        });
        $unitrows = array_values($units);
        $reviewerrows = array_values($reviewers);
        $grouporder = static function(array $a, array $b): int {
            return $b['waiting_long'] <=> $a['waiting_long'] ?:
                $b['waiting'] <=> $a['waiting'] ?: strcmp($a['name'], $b['name']);
        };
        usort($unitrows, $grouporder);
        usort($reviewerrows, $grouporder);
        return [
            'counts'=>$counts,
            'avg_seconds'=>$counts['approved_known'] ? (int)round($approvedseconds / $counts['approved_known']) : null,
            'units'=>$unitrows, 'reviewers'=>$reviewerrows,
            'waiting'=>array_slice($waiting, 0, 20), 'waiting_total'=>count($waiting),
            'recent_approved'=>array_slice($recentapproved, 0, 10),
        ];
    }

    private static function empty_group($id, string $name): array {
        return ['id'=>$id, 'name'=>$name, 'waiting'=>0, 'waiting_long'=>0,
            'approved_known'=>0, 'approved_seconds'=>0, 'approved_unknown'=>0, 'selfapproved'=>0];
    }

    /** Return a company-scoped and access-filtered model for the chosen PLAN week. */
    public static function context(string $companyuuid, int $actor, string $week,
            string $orgnodefilter = '', int $reviewerfilter = 0): array {
        global $DB;
        company_context_service::context($companyuuid, $actor);
        $week = battle_plan_service::week_start($week);
        $canmanage = task_service::can_manage($companyuuid, $actor);
        $positionunits = [];
        foreach ($DB->get_records('local_kbacompany_position', ['companyuuid'=>$companyuuid]) as $position) {
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
        $cycleplans = [];
        foreach ($DB->get_records('local_kbacompany_bplan',
                ['companyuuid'=>$companyuuid, 'periodstart'=>$week], 'id ASC') as $plan) {
            // Match the existing approval outcome report's plan ACL, BEFORE projecting details.
            if (!$canmanage && (int)$plan->userid !== $actor && (int)$plan->reviewerid !== $actor &&
                    (int)$plan->observerid !== $actor) { continue; }
            if ($reviewerfilter > 0 && (int)$plan->reviewerid !== $reviewerfilter) { continue; }
            $unit = $positionunits[(string)$plan->positionuuid] ?? '';
            if ($orgnodefilter !== '' && $unit !== $orgnodefilter) { continue; }
            // Scope review-cycle analytics to precisely the same company/week/ACL filters.
            // No raw statepayload is exposed to the view or rendered in HTML.
            $cycleplans[] = [
                'plan_uuid'=>(string)$plan->uuid,
                'unit_uuid'=>$unit, 'unit_name'=>$nodes[$unit] ?? '',
                'reviewer_id'=>(int)$plan->reviewerid,
                'reviewer_name'=>$names[(int)$plan->reviewerid] ?? '',
                'employee_id'=>(int)$plan->userid,
                'employee_name'=>$names[(int)$plan->userid] ?? '',
                'event_payload'=>(string)($plan->statepayload ?? ''),
                'status'=>(string)$plan->status,
            ];
            $plans[] = [
                'plan_uuid'=>(string)$plan->uuid, 'status'=>(string)$plan->status,
                'employee_id'=>(int)$plan->userid,
                'employee_name'=>$names[(int)$plan->userid] ?? '',
                'unit_uuid'=>$unit, 'unit_name'=>$nodes[$unit] ?? '',
                'reviewer_id'=>(int)$plan->reviewerid,
                'reviewer_name'=>$names[(int)$plan->reviewerid] ?? '',
                'approved_by'=>(int)$plan->approvedby,
                'approved_by_name'=>$names[(int)$plan->approvedby] ?? '',
                'submitted_at'=>(int)$plan->submittedat,
                'approved_at'=>(int)$plan->approvedat,
            ];
        }
        return self::summarize($plans, time()) + [
            'cycle_analytics'=>battle_plan_review_cycle_analytics_service::summarize($cycleplans),
            'stage_delays'=>battle_plan_review_stage_delay_service::summarize($cycleplans, time()),
        ];
    }
}
