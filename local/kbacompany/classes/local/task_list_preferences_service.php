<?php
namespace local_kbacompany\local;

defined('MOODLE_INTERNAL') || die();

/** BMS24 build1841: bounded per-user/per-company TASK LIST presentation preferences. */
final class task_list_preferences_service {
    public const COLUMNS = [
        'title' => 'taskcenter_task',
        'assignee' => 'taskcenter_assignee',
        'creator' => 'taskcenter_creator',
        'status' => 'taskcenter_status',
        'duedate' => 'taskcenter_duedate',
        'priority' => 'taskcenter_priority',
        'sourcekind' => 'taskcenter_source',
        'reviewer' => 'taskcenter_reviewer',
        'observer' => 'taskcenter_observer',
        'stream' => 'taskcenter_stream',
        'updated' => 'bms_task1841_updated',
        'created' => 'bms_task1841_created',
    ];
    public const SORTABLE = ['title', 'assignee', 'creator', 'status', 'duedate',
        'priority', 'sourcekind', 'updated', 'created'];
    public const DEFAULT_COLUMNS = ['title', 'assignee', 'creator', 'status', 'duedate', 'priority', 'sourcekind'];

    private static function key(string $companyuuid): string {
        // Only a checked company UUID is used; read/write occurs after company_context_service::context().
        return 'local_kbacompany_tasklist_' . $companyuuid;
    }

    public static function columns(array $requested): array {
        $valid = ['title']; // The task link can never be hidden.
        foreach (array_keys(self::COLUMNS) as $key) {
            if ($key !== 'title' && in_array($key, $requested, true)) { $valid[] = $key; }
        }
        return $valid;
    }

    public static function sort(string $value): string {
        return in_array($value, self::SORTABLE, true) ? $value : 'duedate';
    }

    public static function direction(string $value): string {
        return in_array(strtolower($value), ['asc', 'desc'], true) ? strtolower($value) : 'asc';
    }

    public static function current(string $companyuuid, int $actor): array {
        $raw = (string)get_user_preferences(self::key($companyuuid), '', $actor);
        $stored = json_decode($raw, true);
        if (!is_array($stored)) { $stored = []; }
        $requested = $stored['columns'] ?? self::DEFAULT_COLUMNS;
        // Persisted preferences may be stale or damaged; arrays/objects must not
        // trigger PHP 8.1+ "Array to string conversion" warnings on page load.
        $sort = $stored['sort'] ?? null;
        $dir = $stored['dir'] ?? null;
        return [
            'columns' => self::columns(is_array($requested) ? $requested : self::DEFAULT_COLUMNS),
            'sort' => self::sort(is_string($sort) ? $sort : 'duedate'),
            'dir' => self::direction(is_string($dir) ? $dir : 'asc'),
        ];
    }

    public static function save(string $companyuuid, int $actor, array $columns, string $sort, string $dir): void {
        $value = [
            'columns' => self::columns($columns),
            'sort' => self::sort($sort),
            'dir' => self::direction($dir),
        ];
        set_user_preference(self::key($companyuuid), json_encode($value, JSON_UNESCAPED_UNICODE), $actor);
    }

    /**
     * Whitelisted SQL only; no user SQL identifier/expression is interpolated.
     *
     * Returns [JOIN, ORDER BY, bind parameters]. The effective assignee used for
     * sorting must match the name shown to the user in Task Center, including
     * active position coverage. The query is still sorted before pagination.
     */
    public static function sql_order(string $sort, string $dir): array {
        $sort = self::sort($sort);
        $direction = strtoupper(self::direction($dir));
        $join = '';
        $expr = '';
        $params = [];
        switch ($sort) {
            case 'title': $expr = 't.title'; break;
            case 'status': $expr = 't.status'; break;
            case 'priority':
                $expr = "CASE t.priority WHEN 'high' THEN 3 WHEN 'normal' THEN 2 ELSE 1 END";
                break;
            case 'sourcekind': $expr = 't.sourcekind'; break;
            case 'updated': $expr = 't.timemodified'; break;
            case 'created': $expr = 't.timecreated'; break;
            case 'assignee':
                // The list shows effective_assignee_name, not always t.assigneeid:
                // ongoing work can be delegated through position coverage.
                [$executor, $params] = position_coverage_service::effective_executor_sql('t', 'listsort');
                $join = ' LEFT JOIN {user} sortperson ON sortperson.id = ' . $executor;
                $expr = 'sortperson.lastname ' . $direction . ', sortperson.firstname';
                break;
            case 'creator':
                $join = ' LEFT JOIN {user} sortperson ON sortperson.id = t.creatorid';
                $expr = 'sortperson.lastname ' . $direction . ', sortperson.firstname';
                break;
            default:
                // Empty deadlines stay last even in descending order.
                return ['', "CASE WHEN t.duedate = '' THEN 1 ELSE 0 END ASC, t.duedate {$direction}, t.id ASC", []];
        }
        return [$join, "{$expr} {$direction}, t.id ASC", $params];
    }
}
