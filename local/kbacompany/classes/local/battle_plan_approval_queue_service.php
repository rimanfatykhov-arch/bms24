<?php
namespace local_kbacompany\local;

defined('MOODLE_INTERNAL') || die();

/** Read-only reviewer queue projections; all access checks occur before a plan reaches this service. */
class battle_plan_approval_queue_service {
    /** Returns the status of remarks in the latest returned-review batch, without guessing from task edits. */
    public static function correction_counts(array $reviews, array $statepayload): array {
        $out = ['total'=>0, 'pending'=>0, 'claimed'=>0, 'accepted'=>0];
        if (!$reviews) { return $out; }
        // Data is in descending timecreated/id order. An approved event starts a new cycle.
        $latest = reset($reviews);
        if ((string)($latest->decision ?? '') !== 'changes') { return $out; }
        $time = (int)($latest->timecreated ?? 0);
        $actor = (int)($latest->actorid ?? 0);
        $comment = (string)($latest->comment ?? '');
        $batch = $time . ':' . $actor . ':' . $comment;
        $targets = [];
        foreach ($reviews as $review) {
            if ((string)($review->decision ?? '') !== 'changes' ||
                    (int)($review->timecreated ?? 0) !== $time ||
                    (int)($review->actorid ?? 0) !== $actor ||
                    (string)($review->comment ?? '') !== $comment) { break; }
            $targets[(string)($review->itemuuid ?? '')] = true;
        }
        if (!$targets) { return $out; }
        $states = [];
        foreach ((array)($statepayload['review_correction_events'] ?? []) as $event) {
            if (!is_array($event) || (string)($event['batch'] ?? '') !== $batch) { continue; }
            $target = (string)($event['target'] ?? '');
            if (isset($targets[$target])) { $states[$target] = (string)($event['action'] ?? ''); }
        }
        foreach ($targets as $target => $unused) {
            $action = $states[$target] ?? '';
            $out[$action === 'accepted' ? 'accepted' : ($action === 'claimed' ? 'claimed' : 'pending')]++;
        }
        $out['total'] = count($targets);
        return $out;
    }

    /**
     * Pure read-model for the reviewer queue. Count ALL ACL-filtered plans before
     * page slicing. The active quick filter MUST NOT affect totals by unit or chips.
     * Never interpret "ready" as authorisation to approve: assessment is advisory.
     *
     * @param array $details Already authorised plans enriched with review information.
     * @param string $filter all|ready|review
     * @return array With filtered rows, chip counts, and department rollup.
     */
    public static function summarize(array $details, string $filter = 'all'): array {
        if (!in_array($filter, ['all', 'ready', 'review'], true)) { $filter = 'all'; }
        $counts = ['all' => 0, 'ready' => 0, 'review' => 0];
        $filtered = [];
        $byunit = [];
        foreach ($details as $detail) {
            $row = $detail['row'];
            $needsreview = !empty($detail['assessment']['advisory']);
            $status = $needsreview ? 'review' : 'ready';
            $counts['all']++;
            $counts[$status]++;
            $unituuid = (string)($row['orgnode']['uuid'] ?? '');
            $unitname = (string)($row['orgnode']['name'] ?? '');
            // Use uuid as a key to avoid merging distinct departments of equal name.
            if (!isset($byunit[$unituuid])) {
                $byunit[$unituuid] = ['uuid' => $unituuid, 'name' => $unitname,
                    'all' => 0, 'ready' => 0, 'review' => 0];
            }
            $byunit[$unituuid]['all']++;
            $byunit[$unituuid][$status]++;
            if ($filter === 'all' || $status === $filter) { $filtered[] = $detail; }
        }
        $units = array_values($byunit);
        usort($units, static function(array $a, array $b): int {
            return ($b['all'] <=> $a['all']) ?: strcmp($a['name'], $b['name']);
        });
        return ['filter' => $filter, 'counts' => $counts, 'rows' => $filtered, 'units' => $units];
    }

    /** Status for the overview: a review aid, NOT a server-side approval gate. */
    public static function assessment(array $row, array $corrections): array {
        $taskcount = (int)($row['tasks']['total'] ?? 0);
        $metrics = (array)($row['performance'] ?? []);
        $totalmetrics = (int)($metrics['metric_total'] ?? 0);
        $evaluated = (int)($metrics['metric_evaluated'] ?? 0);
        $reviewerok = !empty($row['reviewer_valid']);
        $outstanding = (int)($corrections['pending'] ?? 0) + (int)($corrections['claimed'] ?? 0);
        $advisory = !$reviewerok || $taskcount === 0 || $totalmetrics === 0 || $evaluated < $totalmetrics || $outstanding > 0;
        return [
            'task_count'=>$taskcount, 'metrics_total'=>$totalmetrics,
            'metrics_missing'=>max(0, $totalmetrics - $evaluated),
            'outstanding'=>$outstanding, 'reviewer_assigned'=>$reviewerok,
            'advisory'=>$advisory,
        ];
    }
}
