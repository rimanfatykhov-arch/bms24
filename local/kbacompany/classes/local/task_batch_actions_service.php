<?php
namespace local_kbacompany\local;

defined('MOODLE_INTERNAL') || die();

/** Build1842: bounded, ACL-checked batch command adapter over the canonical task service. */
final class task_batch_actions_service {
    public const MAX_ITEMS = 25;
    public const OPERATIONS = ['start', 'accept', 'duedate', 'assignee'];

    /** Never trust checkbox names or revisions; reject the entire malformed selection. */
    public static function selection(array $raw): array {
        if (!$raw || count($raw) > self::MAX_ITEMS) {
            throw new \moodle_exception('bms_task1842_invalid_selection', 'local_kbacompany');
        }
        $valid = [];
        foreach ($raw as $uuid => $revision) {
            if (!is_string($uuid) || !preg_match('/^[a-f0-9]{8}-(?:[a-f0-9]{4}-){3}[a-f0-9]{12}$/Di', $uuid) ||
                    !is_scalar($revision) || !ctype_digit((string)$revision) || (int)$revision < 1) {
                throw new \moodle_exception('bms_task1842_invalid_selection', 'local_kbacompany');
            }
            $valid[$uuid] = (int)$revision;
        }
        return $valid;
    }

    /** Check all company links, not just plans visible to the current employee. */
    private static function has_plan_link(string $company, string $taskuuid): bool {
        global $DB;
        return $DB->record_exists_sql("SELECT 1 FROM {local_kbacompany_bplanitem}
            WHERE companyuuid=:company AND provider='bms'
              AND (externalid=:externalid OR taskuuid=:legacyid)",
            ['company'=>$company, 'externalid'=>$taskuuid, 'legacyid'=>$taskuuid]);
    }

    public static function run(string $company, int $actor, array $selected, string $operation,
            string $duedate = '', int $assignee = 0): array {
        company_context_service::context($company, $actor);
        if (!in_array($operation, self::OPERATIONS, true)) {
            throw new \moodle_exception('taskcenter_invalid', 'local_kbacompany');
        }
        $selected = self::selection($selected);
        if ($operation === 'duedate') {
            $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $duedate, new \DateTimeZone('UTC'));
            if (!$date || $date->format('Y-m-d') !== $duedate) {
                throw new \moodle_exception('taskcenter_date', 'local_kbacompany');
            }
        }
        if ($operation === 'assignee' && $assignee <= 0) {
            throw new \moodle_exception('taskcenter_employee', 'local_kbacompany');
        }
        $manager = task_service::can_manage($company, $actor);
        $result = ['updated'=>0, 'skipped'=>0, 'total'=>count($selected)];
        foreach ($selected as $uuid => $revision) {
            try {
                // Read permission is necessary but not sufficient. Domain operations recheck
                // company, specific edit/approve rights, optimistic revision, locks and state.
                $task = task_service::readable_projection($company, $actor, $uuid);
                if (!$task || (int)$task['revision'] !== $revision) {
                    throw new \moodle_exception('taskcenter_conflict', 'local_kbacompany');
                }
                $permissions = task_service::record_actions($task, $actor, $manager, $company);
                if ($operation === 'start' || $operation === 'accept') {
                    if (empty($permissions[$operation])) {
                        throw new \moodle_exception('taskcenter_permission', 'local_kbacompany');
                    }
                    task_service::transition($company, $actor, $uuid, $revision, $operation);
                } else {
                    if (empty($permissions['save'])) {
                        throw new \moodle_exception('taskcenter_permission', 'local_kbacompany');
                    }
                    // A reassignment must never silently break an existing Battle Plan,
                    // or transfer a position-bound task to an unrelated employee.
                    if ($operation === 'assignee' && (self::has_plan_link($company, $uuid) ||
                            (string)($task['duty_position_uuid'] ?? '') !== '' ||
                            (string)$task['source_kind'] !== 'manual')) {
                        throw new \moodle_exception('bms_task1842_plan_locked', 'local_kbacompany');
                    }
                    $data = [
                        'title'=>$task['title'], 'description'=>$task['description'],
                        'product'=>$task['product'], 'stream'=>$task['stream'],
                        'priority'=>$task['priority'], 'assigneeid'=>$task['assignee_id'],
                        'reviewerid'=>$task['reviewer_id'], 'observerid'=>$task['observer_id'],
                        'parentuuid'=>$task['parent_uuid'], 'contextkind'=>$task['context_kind'],
                        'contextuuid'=>$task['context_uuid'], 'contextrevision'=>$task['context_revision'],
                        'startdate'=>$task['start_date'], 'starttime'=>$task['start_time'],
                        'duedate'=>$task['due_date'], 'duetime'=>$task['due_time'],
                        'planminutes'=>$task['plan_minutes'],
                    ];
                    if ($operation === 'duedate') { $data['duedate'] = $duedate; }
                    if ($operation === 'assignee') { $data['assigneeid'] = $assignee; }
                    // A no-op is not a task edit and must not inflate the revision/history.
                    if (($operation === 'duedate' && $data['duedate'] === $task['due_date']) ||
                            ($operation === 'assignee' && (int)$task['assignee_id'] === $assignee)) {
                        $result['skipped']++;
                        continue;
                    }
                    task_service::save($company, $actor, $uuid, $revision, $data);
                }
                $result['updated']++;
            } catch (\moodle_exception $e) {
                // One inaccessible/conflicting row cannot grant rights over other rows.
                // A per-task transaction has already rolled back in the domain service.
                $result['skipped']++;
            }
        }
        return $result;
    }
}
