<?php
// Unit query-boundary spy; actual projection executes, Moodle access/DB are doubles.
namespace local_kbacorporate\local {
    class access {
        public static $manager = false;
        public static function can_manage_company_implementation($company, $actor) {
            return self::$manager && $company === 10 && $actor === 7;
        }
    }
}
namespace {
    define('MOODLE_INTERNAL', true);
    require_once dirname(__DIR__) . '/local/kbacorporate/classes/local/implementation_task_projection.php';
    class ScopeDatabaseSpy {
        public $employee = false;
        public $queries = [];
        public function record_exists($table, $params) {
            if ($table === 'local_kbacorp_member') {
                return $this->employee && $params['companyid'] === 10 && $params['userid'] === 7;
            }
            return true;
        }
        public function get_record_sql($sql, $params) {
            $this->queries[] = [$sql, $params];
            return false;
        }
    }
    function verify_scope($condition, $message) {
        if (!$condition) { throw new \RuntimeException($message); }
    }
    $DB = new ScopeDatabaseSpy();
    $projection = '\\local_kbacorporate\\local\\implementation_task_projection';
    verify_scope($projection::get_task(10, 7, 9) === null && !$DB->queries,
        'Outsider must not query task content');
    $DB->employee = true;
    $projection::get_task(10, 7, 9);
    [$sql, $params] = $DB->queries[0];
    verify_scope(strpos($sql, 't.ownerid = :actorid') !== false && $params['actorid'] === 7,
        'Employee query must be owner-scoped');
    verify_scope(strpos($sql, 't.companyid = :companyid') !== false && $params['companyid'] === 10,
        'Query must retain explicit company scope');
    $DB->queries = [];
    $projection::get_task(20, 7, 9);
    verify_scope(!$DB->queries, 'Foreign-company employee must not query tasks');
    \local_kbacorporate\local\access::$manager = true;
    $projection::get_task(10, 7, 9);
    [$sql, $params] = $DB->queries[0];
    verify_scope(!isset($params['actorid']) && $params['companyid'] === 10,
        'Authorized manager retains company-scoped access');
    echo "PASS: outsider, employee, foreign company and manager query-boundary regressions\n";
}
