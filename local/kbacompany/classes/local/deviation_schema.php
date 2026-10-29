<?php
namespace local_kbacompany\local;

defined('MOODLE_INTERNAL') || die();

/** Read-only metadata gate for the deviation schema. No tenant data is fetched or modified. */
class deviation_schema {
    /** @var array|null Request-local snapshot; never stored in a shared/persistent cache. */
    private static $snapshot = null;
    /** @var object|null Database instance associated with the request-local snapshot. */
    private static $database = null;

    /** Return technical metadata findings for a privileged diagnostic; never read business rows. */
    public static function issues(bool $refresh = false): array {
        global $DB;
        if (!$refresh && self::$database === $DB && self::$snapshot !== null) {
            return self::$snapshot;
        }
        $issues = [];
        $manager = $DB->get_manager();
        $required = [
            'local_kbacompany_deviation' => ['id', 'companyuuid', 'uuid', 'approvaluuid', 'normuuid', 'actual', 'observedon', 'evidence', 'cause', 'status', 'revision', 'createdby', 'analysedby', 'closedby', 'timecreated', 'timemodified', 'entrytype', 'code', 'title', 'description', 'requirement', 'location', 'criterion', 'basisref', 'ownerpositionuuid', 'duedate', 'confirmedby'],
            'local_kbacompany_devcheck' => ['id', 'companyuuid', 'uuid', 'deviationuuid', 'approvaluuid', 'normuuid', 'actual', 'observedon', 'evidence', 'tasksfingerprint', 'inrange', 'createdby', 'timecreated'],
            'local_kbacompany_devlink' => ['id', 'companyuuid', 'uuid', 'deviationuuid', 'taskuuid', 'reason', 'createdby', 'active', 'timecreated', 'taskengine'],
        ];
        foreach ($required as $table => $fields) {
            if (!$manager->table_exists(new \xmldb_table($table))) {
                $issues[] = 'missing_table:' . $table;
                continue;
            }
            $columns = $DB->get_columns($table, false);
            foreach ($fields as $field) {
                if (!isset($columns[$field])) {
                    $issues[] = 'missing_column:' . $table . '.' . $field;
                }
            }
            if ($table !== 'local_kbacompany_devlink') {
                foreach (['approvaluuid', 'normuuid', 'actual'] as $field) {
                    if (isset($columns[$field]) && $columns[$field]->not_null) {
                        $issues[] = 'requires_nullable:' . $table . '.' . $field;
                    }
                }
            }
            if ($table === 'local_kbacompany_deviation') {
                $indexes = $DB->get_indexes($table);
                foreach ([
                    [false, ['companyuuid', 'normuuid']],
                    [false, ['companyuuid', 'createdby', 'entrytype']],
                    [false, ['companyuuid', 'status']],
                    [true, ['companyuuid', 'code']],
                ] as $expected) {
                    $found = false;
                    foreach ($indexes as $index) {
                        if (array_values($index['columns']) === $expected[1] &&
                                (bool)$index['unique'] === $expected[0]) {
                            $found = true;
                            break;
                        }
                    }
                    if (!$found) {
                        $issues[] = 'missing_index:' . $table . '(' . implode(',', $expected[1]) . ')';
                    }
                }
            }
        }
        self::$database = $DB;
        self::$snapshot = $issues;
        return $issues;
    }

    /** Call only after authenticating and authorising the current Company/actor. */
    public static function require_ready(int $actor): void {
        if (!self::issues()) {
            return;
        }
        $admin = is_siteadmin($actor);
        $link = $admin ? (new \moodle_url('/admin/index.php'))->out(false) : '';
        // Do not expose table names, a misleading empty registry or raw SQL to an employee.
        throw new \moodle_exception($admin ? 'dv_schema_upgrade_admin' : 'dv_schema_upgrade_required',
            'local_kbacompany', $link);
    }
}
