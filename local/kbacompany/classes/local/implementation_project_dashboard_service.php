<?php
namespace local_kbacompany\local;

defined('MOODLE_INTERNAL') || die();

/** Read-only per-program implementation cockpit. Never mutate Corporate evidence/tasks. */
final class implementation_project_dashboard_service {
    /** A total only has meaning when the corresponding source snapshot is complete. */
    public static function summarize(array $source): array {
        $stages = $source['stages'] ?? [];
        $stageavailable = !empty($stages['available']);
        $weekcomplete = $stageavailable && !empty($source['weekly_complete']);
        $resultcomplete = $stageavailable && !empty($source['result_complete']);
        $out = [
            'stage_available' => $stageavailable,
            'week_complete' => $weekcomplete,
            'result_complete' => $resultcomplete,
            'sections' => [],
            'counts' => [
                'stages' => $stageavailable ? count($stages['sections'] ?? []) : null,
                'tasks' => $stageavailable ? (int)($stages['summary']['tasks'] ?? 0) : null,
                'overdue' => $stageavailable ? (int)($stages['summary']['overdue'] ?? 0) : null,
                'planned' => $weekcomplete ? 0 : null,
                'unplanned' => $weekcomplete ? 0 : null,
                'awaiting_review' => $resultcomplete ? 0 : null,
                'returned' => $resultcomplete ? 0 : null,
                'accepted' => $resultcomplete ? 0 : null,
                'unverified' => $resultcomplete ? 0 : null,
                'inconsistent' => $resultcomplete ? 0 : null,
            ],
            'attention' => [],
        ];
        if (!$stageavailable) {
            return $out;
        }
        foreach ($stages['sections'] ?? [] as $stage) {
            $code = (string)($stage['code'] ?? '');
            if ($code === '' || isset($out['sections'][$code])) {
                throw new \UnexpectedValueException('Duplicate or empty implementation section');
            }
            $name = trim((string)($stage['name'] ?? ''));
            $row = [
                'code'=>$code, 'name'=>$name === '' ? $code : $name,
                'tasks'=>(int)($stage['task_count'] ?? 0),
                'overdue'=>(int)($stage['overdue_count'] ?? 0),
                'planned'=>null,'unplanned'=>null,
                'awaiting_review'=>null,'returned'=>null,'accepted'=>null,
                'unverified'=>null,'inconsistent'=>null,
            ];
            if ($weekcomplete) {
                $weekly = $source['weekly_sections'][$code] ?? null;
                if ($weekly === null) {
                    throw new \UnexpectedValueException('Missing weekly section');
                }
                foreach (['planned','unplanned'] as $field) {
                    $row[$field] = (int)($weekly[$field] ?? 0);
                    $out['counts'][$field] += $row[$field];
                }
            }
            if ($resultcomplete) {
                $results = $source['result_sections'][$code] ?? null;
                if ($results === null) {
                    throw new \UnexpectedValueException('Missing result section');
                }
                $counts = $results['counts'] ?? [];
                $row['awaiting_review'] = (int)($counts['awaiting_review'] ?? 0) +
                    (int)($counts['review_without_evidence'] ?? 0);
                $row['returned'] = (int)($counts['returned'] ?? 0);
                $row['accepted'] = (int)($counts['accepted'] ?? 0);
                $row['unverified'] = (int)($counts['completed_unverified'] ?? 0);
                $row['inconsistent'] = (int)($counts['inconsistent'] ?? 0);
                foreach (['awaiting_review','returned','accepted','unverified','inconsistent'] as $field) {
                    $out['counts'][$field] += $row[$field];
                }
                foreach ($results['tasks'] ?? [] as $task) {
                    $id = (string)($task['external_id'] ?? '');
                    $state = (string)($task['result_state'] ?? '');
                    if (!preg_match('/^impltask:[1-9][0-9]*$/D', $id) ||
                            !in_array($state, ['returned','awaiting_review','review_without_evidence',
                                'inconsistent','completed_unverified'], true)) {
                        continue;
                    }
                    $out['attention'][$id] = ['external_id'=>$id, 'state'=>$state,
                        'section_code'=>$code, 'section_name'=>$row['name'],
                        'evidence_version'=>(int)($task['evidence_version'] ?? 0),
                        'reviewed_at'=>(int)($task['reviewed_at'] ?? 0)];
                }
            }
            $out['sections'][$code] = $row;
        }
        $order = ['returned'=>0,'awaiting_review'=>1,'review_without_evidence'=>2,
            'inconsistent'=>3,'completed_unverified'=>4];
        uasort($out['attention'], static function(array $a, array $b) use ($order): int {
            $bypriority = $order[$a['state']] <=> $order[$b['state']];
            if ($bypriority !== 0) { return $bypriority; }
            return strcmp($a['external_id'], $b['external_id']);
        });
        $out['attention_total'] = count($out['attention']);
        $out['attention'] = array_slice(array_values($out['attention']), 0, 12);
        return $out;
    }

    /** Delegates ACL and company isolation to the existing published project service and Corporate bridge. */
    public static function model(string $uuid, int $actor, int $programid, string $week = ''): array {
        global $DB;
        $base = implementation_project_result_service::model($uuid, $actor, $programid, $week);
        $bridge = '\\local_kbacorporate\\local\\bridge';
        if (!class_exists($bridge) || !method_exists($bridge, 'get_implementation_program_projection')) {
            throw new \moodle_exception('implproj_unavailable', 'local_kbacompany');
        }
        $program = $bridge::get_implementation_program_projection(
            (int)$base['companyid'], $actor, $programid);
        if (!$program || (int)($program['company_id'] ?? 0) !== (int)$base['companyid'] ||
                (int)($program['program_id'] ?? 0) !== $programid ||
                (int)($program['audit_id'] ?? 0) !== (int)($base['stages']['audit_id'] ?? 0)) {
            throw new \moodle_exception('implproj_program', 'local_kbacompany');
        }
        $curatorid = (int)($program['curator_id'] ?? 0);
        $curatorname = '';
        if ($curatorid > 0) {
            $u = $DB->get_record('user', ['id'=>$curatorid, 'deleted'=>0, 'suspended'=>0]);
            if ($u) { $curatorname = fullname($u); }
        }
        $base['curator_id'] = $curatorid;
        $base['curator_name'] = $curatorname;
        $base['dashboard'] = self::summarize($base);
        return $base;
    }
}
