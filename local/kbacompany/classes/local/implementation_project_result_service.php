<?php
namespace local_kbacompany\local;

defined('MOODLE_INTERNAL') || die();

/** Read-only result / curator verification overlay for linked implementation stages. */
final class implementation_project_result_service {
    private const BRIDGE = '\\local_kbacorporate\\local\\bridge';
    public const STATES = ['not_started', 'in_work', 'awaiting_review', 'review_without_evidence',
        'returned', 'accepted', 'completed_unverified', 'cancelled', 'inconsistent'];

    /**
     * Aggregate unique Corporate task identities; optionally reconcile against the weekly
     * task snapshot to avoid displaying mutually contradictory states as reliable figures.
     */
    public static function summarize(array $sections, array $taskrows, array $weekly = [],
            bool $checkweekly = false): array {
        $result = [];
        $expected = [];
        $weeklylinks = [];
        $seen = [];
        foreach ($sections as $section) {
            $key = (string)$section['code'];
            $result[$key] = ['counts'=>array_fill_keys(self::STATES, 0), 'tasks'=>[]];
        }
        if ($checkweekly) {
            foreach ($weekly as $code => $section) {
                foreach ($section['tasks'] ?? [] as $task) {
                    $id = (string)($task['external_id'] ?? '');
                    if (isset($result[(string)$code]) && preg_match('/^impltask:[1-9][0-9]*$/D', $id)) {
                        $expected[$id] = [(string)$code, (string)$task['status']];
                        $weeklylinks[$id] = $task['plan_links'] ?? [];
                    }
                }
            }
        }
        foreach ($taskrows as $task) {
            $id = (string)($task['external_id'] ?? '');
            $code = (string)($task['section_code'] ?? '');
            if (!preg_match('/^impltask:[1-9][0-9]*$/D', $id) || isset($seen[$id])) {
                throw new \UnexpectedValueException('Duplicate or invalid implementation task identity');
            }
            $seen[$id] = true;
            if (!isset($result[$code])) {
                // Corporate can classify some tasks into a section not in this audit's public snapshot.
                // Those rows do not contribute to the displayed known section totals.
                continue;
            }
            $status = (string)($task['task_status'] ?? '');
            $state = (string)($task['result_state'] ?? '');
            if (!in_array($state, self::STATES, true) ||
                    !in_array($status, ['planned','inprogress','review','completed','cancelled'], true)) {
                throw new \UnexpectedValueException('Invalid implementation task state');
            }
            $requiredstatus = [
                'not_started'=>'planned', 'in_work'=>'inprogress', 'returned'=>'inprogress',
                'awaiting_review'=>'review', 'review_without_evidence'=>'review',
                'accepted'=>'completed', 'completed_unverified'=>'completed',
                'cancelled'=>'cancelled',
            ];
            if (isset($requiredstatus[$state]) && $status !== $requiredstatus[$state]) {
                throw new \UnexpectedValueException('Impossible curator result state for task status');
            }
            if ($checkweekly && (!isset($expected[$id]) ||
                    $expected[$id] !== [$code, $status])) {
                throw new \UnexpectedValueException('Corporate weekly/result snapshots disagree');
            }
            $record = $task;
            // O(1) lookup; the weekly service has already checked plan ownership.
            $record['plan_links'] = $checkweekly ? ($weeklylinks[$id] ?? []) : [];
            $result[$code]['counts'][$state]++;
            $result[$code]['tasks'][] = $record;
        }
        if ($checkweekly) {
            foreach ($expected as $id => $identity) {
                if (!isset($seen[$id])) {
                    throw new \UnexpectedValueException('Missing result for a weekly task');
                }
            }
        }
        return $result;
    }

    public static function model(string $uuid, int $actor, int $programid, string $week = ''): array {
        // All BMS project, company and actor ACL is enforced by the weekly/stage service.
        $base = implementation_project_weekly_service::model($uuid, $actor, $programid, $week);
        $base['result_complete'] = false;
        $base['result_reason'] = '';
        $base['result_sections'] = [];
        if (empty($base['stages']['available'])) {
            $base['result_reason'] = 'unconfirmed_baseline';
            return $base;
        }
        $bridge = self::BRIDGE;
        if (!class_exists($bridge) || !method_exists($bridge, 'get_implementation_program_result_statuses') ||
                version_compare((string)$bridge::API_RELEASE, '1.26', '<')) {
            $base['result_reason'] = 'upgrade_corporate';
            return $base;
        }
        $source = $bridge::get_implementation_program_result_statuses(
            (int)$base['companyid'], $actor, $programid);
        if (!$source || (int)($source['company_id'] ?? 0) !== (int)$base['companyid'] ||
                (int)($source['program_id'] ?? 0) !== $programid ||
                (int)($source['audit_id'] ?? 0) !== (int)$base['stages']['audit_id'] ||
                empty($source['complete'])) {
            $base['result_reason'] = (string)($source['reason'] ?? 'incomplete');
            return $base;
        }
        try {
            $base['result_sections'] = self::summarize($base['stages']['sections'],
                $source['tasks'], $base['weekly_sections'], (bool)$base['weekly_complete']);
            $base['result_complete'] = true;
        } catch (\UnexpectedValueException $e) {
            // Do not show potentially stale or conflicting decisions as current status.
            $base['result_sections'] = [];
            $base['result_reason'] = 'changed';
        }
        return $base;
    }
}
