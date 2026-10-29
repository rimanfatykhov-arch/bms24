<?php
namespace local_kbacompany\local;

defined('MOODLE_INTERNAL') || die();

/**
 * Read-only analytics based exclusively on recorded review-cycle events.
 * Period means the Battle Plan week, not the date when the reviewer clicked.
 * An access-filtered plan collection MUST be supplied by the caller.
 * Existing legacy events without a matching submission never gain a duration.
 */
class battle_plan_review_cycle_analytics_service {
    /** Pure projection: no database access and no inferred historical decisions. */
    public static function summarize(array $plans): array {
        $counts = ['plans'=>count($plans), 'tracked'=>0, 'untracked'=>0,
            'recorded_submissions'=>0, 'recorded_returns'=>0, 'recorded_approvals'=>0,
            'selfapprovals'=>0, 'measured_decisions'=>0, 'repeated_submissions'=>0,
            'repeated_returns'=>0];
        $seconds = 0;
        $units = [];
        $reviewers = [];
        $employees = [];
        $repeated = [];
        foreach ($plans as $plan) {
            $projected = battle_plan_review_cycle_service::project((string)($plan['event_payload'] ?? ''));
            $events = $projected['rows'];
            $submissions = 0;
            $returns = 0;
            $approvals = 0;
            $selfapprovals = 0;
            $measured = 0;
            $elapsed = 0;
            foreach ($events as $event) {
                $type = (string)$event['type'];
                if ($type === 'submitted') { $submissions++; }
                if ($type === 'returned') { $returns++; }
                if ($type === 'approved') { $approvals++; }
                if ($type === 'selfapproved') { $selfapprovals++; }
                if ($event['duration_seconds'] !== null && in_array($type, ['returned','approved'], true)) {
                    $measured++;
                    $elapsed += (int)$event['duration_seconds'];
                }
            }
            $counts[$events ? 'tracked' : 'untracked']++;
            $counts['recorded_submissions'] += $submissions;
            $counts['recorded_returns'] += $returns;
            $counts['recorded_approvals'] += $approvals;
            $counts['selfapprovals'] += $selfapprovals;
            $counts['measured_decisions'] += $measured;
            $counts['repeated_submissions'] += (int)($submissions >= 2);
            $counts['repeated_returns'] += (int)($returns >= 2);
            $seconds += $elapsed;
            $summary = [
                'plans'=>1, 'tracked'=>(int)!empty($events), 'submissions'=>$submissions,
                'returns'=>$returns, 'approvals'=>$approvals, 'measured'=>$measured,
                'duration_seconds'=>$elapsed, 'repeated'=>(int)($submissions >= 2),
            ];
            self::group($units, (string)($plan['unit_uuid'] ?? ''),
                (string)($plan['unit_name'] ?? ''), $summary);
            self::group($reviewers, (string)(int)($plan['reviewer_id'] ?? 0),
                (string)($plan['reviewer_name'] ?? ''), $summary);
            self::group($employees, (string)(int)($plan['employee_id'] ?? 0),
                (string)($plan['employee_name'] ?? ''), $summary);
            if ($submissions >= 2 || $returns >= 2) {
                $repeated[] = [
                    'plan_uuid'=>(string)$plan['plan_uuid'],
                    'employee_name'=>(string)($plan['employee_name'] ?? ''),
                    'reviewer_name'=>(string)($plan['reviewer_name'] ?? ''),
                    'submissions'=>$submissions, 'returns'=>$returns,
                    'measured'=>$measured, 'duration_seconds'=>$elapsed,
                ];
            }
        }
        $sort = static function(array $a, array $b): int {
            return $b['returns'] <=> $a['returns'] ?:
                $b['repeated'] <=> $a['repeated'] ?:
                $b['submissions'] <=> $a['submissions'] ?: strcmp($a['name'], $b['name']);
        };
        $unitrows = array_values($units);
        $reviewerrows = array_values($reviewers);
        $employeerows = array_values($employees);
        usort($unitrows, $sort);
        usort($reviewerrows, $sort);
        usort($employeerows, $sort);
        usort($repeated, static function(array $a, array $b): int {
            return $b['returns'] <=> $a['returns'] ?: $b['submissions'] <=> $a['submissions'] ?:
                strcmp($a['employee_name'], $b['employee_name']);
        });
        return [
            'counts'=>$counts,
            'average_seconds'=>$counts['measured_decisions'] > 0 ?
                (int)round($seconds / $counts['measured_decisions']) : null,
            'units'=>$unitrows, 'reviewers'=>$reviewerrows, 'employees'=>$employeerows,
            'repeated_total'=>count($repeated), 'repeated'=>array_slice($repeated, 0, 15),
        ];
    }

    private static function group(array &$groups, string $id, string $name, array $summary): void {
        if (!isset($groups[$id])) {
            $groups[$id] = ['id'=>$id, 'name'=>$name, 'plans'=>0, 'tracked'=>0,
                'submissions'=>0, 'returns'=>0, 'approvals'=>0,
                'measured'=>0, 'duration_seconds'=>0, 'repeated'=>0];
        }
        foreach ($summary as $key => $value) {
            $groups[$id][$key] += $value;
        }
    }
}
