<?php
namespace local_kbacorporate\local;

defined('MOODLE_INTERNAL') || die();

/** Operational checklist attached to one implementation task. */
final class implementation_task_checklist {
    public static function get_items(int $taskid): array {
        global $DB;
        return array_values($DB->get_records('local_kbacorp_impl_check', ['taskid' => $taskid],
            'sortorder ASC, id ASC'));
    }

    public static function progress(int $taskid): \stdClass {
        global $DB;
        $total = $DB->count_records('local_kbacorp_impl_check', ['taskid' => $taskid]);
        $done = $DB->count_records('local_kbacorp_impl_check', ['taskid' => $taskid, 'isdone' => 1]);
        return (object)['total' => $total, 'done' => $done,
            'percent' => $total ? (int)round(($done * 100) / $total) : 0];
    }

    public static function add(\stdClass $task, int $userid, string $title): int {
        global $DB;
        self::require_mutation_access($task, $userid);
        $title = trim(clean_param($title, PARAM_TEXT));
        if ($title === '') {
            throw new \moodle_exception('implementationchecklistempty', 'local_kbacorporate');
        }
        $title = \core_text::substr($title, 0, 255);
        $sortorder = (int)$DB->get_field_sql(
            'SELECT COALESCE(MAX(sortorder), 0) FROM {local_kbacorp_impl_check} WHERE taskid = :taskid',
            ['taskid' => (int)$task->id]);
        $now = time();
        $id = $DB->insert_record('local_kbacorp_impl_check', (object)[
            'taskid' => (int)$task->id, 'title' => $title, 'isdone' => 0,
            'sortorder' => $sortorder + 10, 'createdby' => $userid, 'completedby' => 0,
            'completedat' => 0, 'timecreated' => $now, 'timemodified' => $now,
        ]);
        self::touch_task((int)$task->id, $userid, $now);
        return (int)$id;
    }

    public static function toggle(\stdClass $task, int $itemid, int $userid): void {
        global $DB;
        self::require_mutation_access($task, $userid);
        $item = $DB->get_record('local_kbacorp_impl_check',
            ['id' => $itemid, 'taskid' => (int)$task->id], '*', MUST_EXIST);
        $now = time();
        $done = empty($item->isdone);
        $DB->update_record('local_kbacorp_impl_check', (object)[
            'id' => (int)$item->id, 'isdone' => $done ? 1 : 0,
            'completedby' => $done ? $userid : 0, 'completedat' => $done ? $now : 0,
            'timemodified' => $now,
        ]);
        self::touch_task((int)$task->id, $userid, $now);
    }

    public static function delete(\stdClass $task, int $itemid, int $userid): void {
        global $DB;
        self::require_mutation_access($task, $userid);
        $item = $DB->get_record('local_kbacorp_impl_check',
            ['id' => $itemid, 'taskid' => (int)$task->id], '*', MUST_EXIST);
        $isreviewer = implementation_task_discussion::is_curator($task, $userid) ||
            access::can_manage_company_implementation((int)$task->companyid, $userid);
        if ((int)$item->createdby !== $userid && !$isreviewer) {
            throw new \moodle_exception('implementationchecklistdeleteforbidden', 'local_kbacorporate');
        }
        $DB->delete_records('local_kbacorp_impl_check', ['id' => (int)$item->id]);
        self::touch_task((int)$task->id, $userid, time());
    }

    private static function require_mutation_access(\stdClass $task, int $userid): void {
        implementation_task_discussion::require_access($task, $userid);
        if (in_array((string)$task->status, ['completed', 'cancelled'], true)) {
            throw new \moodle_exception('implementationchecklistlocked', 'local_kbacorporate');
        }
    }

    private static function touch_task(int $taskid, int $userid, int $now): void {
        global $DB;
        $DB->update_record('local_kbacorp_impl_task', (object)[
            'id' => $taskid, 'modifiedby' => $userid, 'timemodified' => $now,
        ]);
    }
}
