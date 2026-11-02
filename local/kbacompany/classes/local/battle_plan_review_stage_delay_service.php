<?php
namespace local_kbacompany\local;

defined('MOODLE_INTERNAL') || die();

/**
 * Read-only, event-evidenced durations for the two review stages.
 * Input MUST already be filtered by company, plan ACL, week, unit and reviewer.
 * No inferred events, workflow transitions, database writes or SLA classification.
 */
class battle_plan_review_stage_delay_service {
    /**
     * Projects a single plan from append-only review_cycle_events.
     * Malformed/out-of-order transitions invalidate the affected open segment. A later
     * valid submission can start a fresh reliable segment (never bridge a gap).
     */
    public static function project_plan(string $payload, string $status, int $now): array {
        $decoded = $payload === '' ? [] : json_decode($payload, true);
        $events = is_array($decoded) ? ($decoded['review_cycle_events'] ?? []) : null;
        $result = ['recorded'=>false, 'invalid'=>0, 'duplicate'=>0,
            'manager'=>[], 'employee'=>[], 'open'=>null, 'selfapproved'=>0];
        if (!is_array($events) || !array_is_list($events)) {
            $result['invalid'] = 1;
            return $result;
        }
        $result['recorded'] = count($events) > 0;
        $stage = ''; // '' | manager | employee.
        $start = 0;
        $lastat = 0;
        $cycle = 0;
        $seen = [];
        foreach ($events as $event) {
            if (!is_array($event)) { $result['invalid']++; $stage = ''; continue; }
            $type = (string)($event['type'] ?? '');
            $actor = $event['actorid'] ?? null;
            $timestamp = $event['at'] ?? null;
            $eventcycle = $event['cycle'] ?? null;
            if (!in_array($type, battle_plan_review_cycle_service::TYPES, true) ||
                    !is_int($actor) || $actor <= 0 || !is_int($timestamp) ||
                    $timestamp <= 0 || $timestamp > $now ||
                    ($eventcycle !== null && (!is_int($eventcycle) || $eventcycle < 1))) {
                $result['invalid']++;
                $stage = '';
                continue;
            }
            // Deduplicate identical records only. Same-second DIFFERENT events are valid.
            $identity = $type . ':' . $actor . ':' . $timestamp . ':' . (string)$eventcycle;
            if (isset($seen[$identity])) { $result['duplicate']++; continue; }
            $seen[$identity] = true;
            if ($timestamp < $lastat) {
                $result['invalid']++;
                $stage = '';
                // Retain the high-water mark: never bridge a reversed clock.
                continue;
            }
            $lastat = $timestamp;
            if ($type === 'selfapproved') {
                $result['selfapproved']++;
                if ($stage !== '') { $result['invalid']++; }
                $stage = '';
                $cycle = max($cycle + 1, (int)$eventcycle);
                continue;
            }
            if ($type === 'submitted') {
                if ($stage === 'manager') { $result['invalid']++; }
                if ($stage === 'employee') {
                    $result['employee'][] = ['seconds'=>$timestamp - $start, 'start'=>$start, 'end'=>$timestamp];
                }
                $stage = 'manager';
                $start = $timestamp;
                $cycle = max($cycle + 1, (int)$eventcycle);
                continue;
            }
            // A return/approval requires the current submitted cycle and its timestamp.
            if ($stage !== 'manager' || ($eventcycle !== null && $eventcycle !== $cycle)) {
                $result['invalid']++;
                $stage = '';
                continue;
            }
            $result['manager'][] = ['seconds'=>$timestamp - $start, 'start'=>$start,
                'end'=>$timestamp, 'actor_id'=>$actor, 'decision'=>$type];
            if ($type === 'returned') {
                $stage = 'employee';
                $start = $timestamp;
            } else {
                $stage = '';
            }
        }
        // Only live status corroborated by event state may show a running duration.
        if ($stage === 'manager' && $status === 'submitted') {
            $result['open'] = ['stage'=>'manager', 'seconds'=>$now - $start, 'start'=>$start];
        } else if ($stage === 'employee' && $status === 'draft') {
            $result['open'] = ['stage'=>'employee', 'seconds'=>$now - $start, 'start'=>$start];
        } else if ($stage !== '') {
            $result['invalid']++;
        }
        return $result;
    }

    /** Pure aggregation, before any UI pagination. */
    public static function summarize(array $plans, int $now): array {
        $counts = ['plans'=>0, 'recorded'=>0, 'evidenced'=>0, 'missing'=>0,
            'partial'=>0, 'duplicate'=>0, 'selfapproved'=>0,
            'manager_cycles'=>0, 'employee_cycles'=>0, 'manager_open'=>0, 'employee_open'=>0];
        $managerseconds = 0;
        $employeeseconds = 0;
        $groups = ['units'=>[], 'reviewers'=>[], 'employees'=>[]];
        $rows = [];
        foreach ($plans as $p) {
            $projection = self::project_plan((string)($p['event_payload'] ?? ''),
                (string)($p['status'] ?? ''), $now);
            $mcount = count($projection['manager']);
            $ecount = count($projection['employee']);
            $mseconds = array_sum(array_column($projection['manager'], 'seconds'));
            $eseconds = array_sum(array_column($projection['employee'], 'seconds'));
            $open = $projection['open'];
            $counts['plans']++;
            $counts[$projection['recorded'] ? 'recorded' : 'missing']++;
            $counts['evidenced'] += (int)($mcount + $ecount > 0 || $open !== null);
            $counts['partial'] += (int)($projection['invalid'] > 0);
            $counts['duplicate'] += $projection['duplicate'];
            $counts['selfapproved'] += $projection['selfapproved'];
            $counts['manager_cycles'] += $mcount;
            $counts['employee_cycles'] += $ecount;
            $managerseconds += $mseconds;
            $employeeseconds += $eseconds;
            if ($open !== null) { $counts[$open['stage'] . '_open']++; }
            $summary = ['plans'=>1, 'manager_cycles'=>$mcount, 'employee_cycles'=>$ecount,
                'manager_seconds'=>$mseconds, 'employee_seconds'=>$eseconds,
                'manager_open'=>(int)($open !== null && $open['stage'] === 'manager'),
                'employee_open'=>(int)($open !== null && $open['stage'] === 'employee')];
            foreach (['units'=>['unit_uuid', 'unit_name'], 'reviewers'=>['reviewer_id', 'reviewer_name'],
                    'employees'=>['employee_id', 'employee_name']] as $key=>$fields) {
                self::group($groups[$key], (string)($p[$fields[0]] ?? ''),
                    (string)($p[$fields[1]] ?? ''), $summary);
            }
            // The plan drilldown shows both finished and open stages, including legacy exclusions.
            $rows[] = [
                'plan_uuid'=>(string)($p['plan_uuid'] ?? ''),
                'employee_name'=>(string)($p['employee_name'] ?? ''),
                'reviewer_name'=>(string)($p['reviewer_name'] ?? ''),
                'unit_name'=>(string)($p['unit_name'] ?? ''),
                'status'=>(string)($p['status'] ?? ''),
                'manager_cycles'=>$mcount, 'employee_cycles'=>$ecount,
                'manager_avg'=>$mcount ? (int)round($mseconds / $mcount) : null,
                'employee_avg'=>$ecount ? (int)round($eseconds / $ecount) : null,
                'open'=>$open, 'invalid'=>$projection['invalid'],
                'recorded'=>$projection['recorded'],
            ];
        }
        foreach ($groups as &$category) {
            $category = array_values($category);
            usort($category, static function(array $a, array $b): int {
                return ($b['manager_open'] + $b['employee_open']) <=> ($a['manager_open'] + $a['employee_open']) ?:
                    ($b['manager_cycles'] + $b['employee_cycles']) <=> ($a['manager_cycles'] + $a['employee_cycles']) ?:
                    strcmp($a['name'], $b['name']);
            });
        }
        unset($category);
        usort($rows, static function(array $a, array $b): int {
            return (int)($b['open']['seconds'] ?? -1) <=> (int)($a['open']['seconds'] ?? -1) ?:
                strcmp($a['employee_name'], $b['employee_name']) ?: strcmp($a['plan_uuid'], $b['plan_uuid']);
        });
        return [
            'counts'=>$counts,
            'manager_average'=>$counts['manager_cycles'] ? (int)round($managerseconds / $counts['manager_cycles']) : null,
            'employee_average'=>$counts['employee_cycles'] ? (int)round($employeeseconds / $counts['employee_cycles']) : null,
            'units'=>$groups['units'], 'reviewers'=>$groups['reviewers'], 'employees'=>$groups['employees'],
            'rows'=>$rows,
        ];
    }

    private static function group(array &$groups, string $id, string $name, array $summary): void {
        if (!isset($groups[$id])) {
            $groups[$id] = ['id'=>$id, 'name'=>$name, 'plans'=>0,
                'manager_cycles'=>0, 'employee_cycles'=>0, 'manager_seconds'=>0,
                'employee_seconds'=>0, 'manager_open'=>0, 'employee_open'=>0];
        }
        foreach ($summary as $key=>$value) { $groups[$id][$key] += $value; }
    }
}
