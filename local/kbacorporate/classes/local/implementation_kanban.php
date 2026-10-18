<?php
namespace local_kbacorporate\local;

defined('MOODLE_INTERNAL') || die();

/**
 * Controlled Kanban projection and card updates for implementation actions.
 *
 * The Kanban does not keep a second task entity. Every card is a projection of
 * one local_kbacorp_impl_task record, so changes made in the task, evidence
 * workspace or board are immediately visible everywhere.
 */
final class implementation_kanban {
    /** Allowed evidence filters on the board. */
    public const EVIDENCE_FILTERS = ['', 'none', 'submitted', 'accepted', 'rejected'];

    /** Allowed task priorities. */
    public const PRIORITIES = ['critical', 'core', 'additional'];

    /** Whether the user may change assignment, priority and due date from the board. */
    public static function can_manage_card(\stdClass $task, int $userid): bool {
        return implementation_task_discussion::is_curator($task, $userid) ||
            access::can_manage_company_implementation((int)$task->companyid, $userid);
    }

    /** Convert an HTML date input to the user's midnight timestamp. */
    public static function parse_due_date(string $value): int {
        $value = trim($value);
        if ($value === '') {
            return 0;
        }
        if (!preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $value, $matches)) {
            throw new \invalid_parameter_exception('Invalid Kanban due date.');
        }
        $year = (int)$matches[1];
        $month = (int)$matches[2];
        $day = (int)$matches[3];
        if (!checkdate($month, $day, $year)) {
            throw new \invalid_parameter_exception('Invalid Kanban due date.');
        }
        return make_timestamp($year, $month, $day, 0, 0, 0, 99, false);
    }

    /** Format a stored due date for an HTML date input. */
    public static function due_date_input(int $timestamp): string {
        return $timestamp > 0 ? userdate($timestamp, '%Y-%m-%d') : '';
    }

    /**
     * Update card management fields without bypassing the lifecycle/evidence workflow.
     *
     * @return \stdClass Result with changed flag, updated task and machine-readable changes.
     */
    public static function update_card(int $taskid, int $userid, int $ownerid,
            int $duedate, string $priority): \stdClass {
        global $DB;

        $task = implementation_task_discussion::get_task($taskid);
        implementation_task_discussion::require_access($task, $userid);
        if (!self::can_manage_card($task, $userid)) {
            throw new \moodle_exception('kanbanupdateforbidden', 'local_kbacorporate');
        }
        if (in_array((string)$task->status, ['completed', 'cancelled'], true)) {
            throw new \moodle_exception('kanbancardlocked', 'local_kbacorporate');
        }
        if ($ownerid > 0 && !$DB->record_exists('local_kbacorp_member', [
                'companyid' => (int)$task->companyid,
                'userid' => $ownerid,
                'isemployee' => 1,
            ])) {
            throw new \moodle_exception('implementationtaskownerinvalid', 'local_kbacorporate');
        }
        if (!in_array($priority, self::PRIORITIES, true)) {
            throw new \invalid_parameter_exception('Invalid Kanban priority.');
        }
        if ($duedate < 0) {
            throw new \invalid_parameter_exception('Invalid Kanban due date.');
        }

        $changes = [];
        if ((int)$task->ownerid !== $ownerid) {
            $changes['ownerfrom'] = (int)$task->ownerid;
            $changes['ownerto'] = $ownerid;
        }
        if ((int)$task->duedate !== $duedate) {
            $changes['duefrom'] = (int)$task->duedate;
            $changes['dueto'] = $duedate;
        }
        if ((string)$task->priority !== $priority) {
            $changes['priorityfrom'] = (string)$task->priority;
            $changes['priorityto'] = $priority;
        }
        if (!$changes) {
            return (object)[
                'changed' => false,
                'task' => $task,
                'changes' => [],
            ];
        }

        $update = (object)[
            'id' => $taskid,
            'ownerid' => $ownerid,
            'duedate' => $duedate,
            'priority' => $priority,
            'modifiedby' => $userid,
            'timemodified' => time(),
        ];
        if (array_key_exists('duefrom', $changes)) {
            // A changed deadline is a new reminder cycle.
            $update->lastremindersent = 0;
            $update->lastreminderdue = 0;
            $update->lastremindertype = null;
        }
        $transaction = $DB->start_delegated_transaction();
        $DB->update_record('local_kbacorp_impl_task', $update);

        implementation_task_lifecycle::record_kanban_update(
            $taskid,
            $userid,
            (string)$task->status,
            $changes
        );
        $transaction->allow_commit();

        $updated = implementation_task_discussion::get_task($taskid);
        implementation_task_notifier::send_kanban_update(
            $updated,
            $userid,
            $changes,
            (int)$task->ownerid
        );

        return (object)[
            'changed' => true,
            'task' => $updated,
            'changes' => $changes,
        ];
    }

    /** Return the latest evidence status used by board filtering. */
    public static function evidence_filter_value(?\stdClass $evidence): string {
        return $evidence ? (string)$evidence->status : 'none';
    }

    /** Human-readable summary of assignment/deadline/priority changes. */
    public static function describe_changes(array $changes): string {
        $parts = [];
        if (array_key_exists('ownerfrom', $changes) && array_key_exists('ownerto', $changes)) {
            $parts[] = get_string('kanbanchangeowner', 'local_kbacorporate', (object)[
                'from' => self::user_label((int)$changes['ownerfrom']),
                'to' => self::user_label((int)$changes['ownerto']),
            ]);
        }
        if (array_key_exists('duefrom', $changes) && array_key_exists('dueto', $changes)) {
            $parts[] = get_string('kanbanchangedue', 'local_kbacorporate', (object)[
                'from' => self::date_label((int)$changes['duefrom']),
                'to' => self::date_label((int)$changes['dueto']),
            ]);
        }
        if (array_key_exists('priorityfrom', $changes) && array_key_exists('priorityto', $changes)) {
            $parts[] = get_string('kanbanchangepriority', 'local_kbacorporate', (object)[
                'from' => self::priority_label((string)$changes['priorityfrom']),
                'to' => self::priority_label((string)$changes['priorityto']),
            ]);
        }
        return $parts ? implode('; ', $parts) : get_string('kanbannochange', 'local_kbacorporate');
    }

    /** Decode a persisted event payload safely. */
    public static function decode_changes(?string $json): array {
        if ($json === null || trim($json) === '') {
            return [];
        }
        $decoded = json_decode($json, true);
        return is_array($decoded) ? $decoded : [];
    }

    private static function user_label(int $userid): string {
        if ($userid <= 0) {
            return get_string('notassigned', 'local_kbacorporate');
        }
        $user = \core_user::get_user($userid);
        return $user ? fullname($user) : get_string('unknownuser');
    }

    private static function date_label(int $timestamp): string {
        return $timestamp > 0
            ? userdate($timestamp, get_string('strftimedatefullshort'))
            : get_string('kanbannoduedate', 'local_kbacorporate');
    }

    private static function priority_label(string $priority): string {
        $key = 'gappriority' . $priority;
        return get_string_manager()->string_exists($key, 'local_kbacorporate')
            ? get_string($key, 'local_kbacorporate') : s($priority);
    }
}
