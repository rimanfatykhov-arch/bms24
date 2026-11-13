<?php
/** Standalone contract assertions, no Moodle database required. */
namespace local_kbacorporate\local {
    final class access {
        public static array $revoked = [];
        public static function is_revoked(int $companyid, int $userid): bool {
            return !empty(self::$revoked[$companyid][$userid]);
        }
        public static function can_manage_company_implementation(int $companyid, int $userid): bool {
            return false;
        }
    }
}
namespace {
    define('MOODLE_INTERNAL', true);
    final class curator_fake_db {
        public array $programs = [
            ['companyid'=>10, 'baselineauditid'=>100, 'curatorid'=>7],
            ['companyid'=>20, 'baselineauditid'=>200, 'curatorid'=>8],
        ];
        public function record_exists(string $table, array $conditions): bool {
            if ($table === 'local_kbacorp_impl_program') {
                foreach ($this->programs as $row) {
                    if (!array_diff_assoc($conditions, $row)) {return true;}
                }
            }
            return false;
        }
    }
    $DB = new curator_fake_db();
    require_once dirname(__DIR__) . '/classes/local/implementation_task_discussion.php';
    use local_kbacorporate\local\implementation_task_discussion as S;
    $asserts = 0;
    $check = static function(bool $actual, bool $expected, string $label) use (&$asserts): void {
        $asserts++;
        if ($actual !== $expected) {throw new \RuntimeException($label);}
    };
    $task = (object)['companyid'=>10, 'auditid'=>100, 'ownerid'=>13, 'deleted'=>0];
    $check(S::is_curator($task,7),true,'assigned');
    $check(S::can_access($task,7),true,'assigned task access');
    \local_kbacorporate\local\access::$revoked[10][7] = true;
    $check(S::is_curator($task,7),false,'revoked curator');
    $check(S::can_access($task,7),false,'revoked curator task access');
    \local_kbacorporate\local\access::$revoked[10][13] = true;
    $check(S::can_access($task,13),false,'revoked owner task access');
    \local_kbacorporate\local\access::$revoked = [];
    $check(S::can_access($task,7),true,'reopened curator retains positive grant');
    $check(S::is_curator($task,8),false,'other curator');
    $check(S::can_access($task,8),false,'foreign curator access');
    $other = clone $task;
    $other->companyid=20;
    $check(S::is_curator($other,7),false,'shared audit id wrong company');
    $check(S::can_access($other,7),false,'no wrong company access');
    $other->auditid=200;
    $check(S::is_curator($other,8),true,'other company own curator');
    $other->deleted=1;
    $check(S::is_curator($other,8),false,'deleted assignment ignored');
    $check(S::can_access($other,8),false,'deleted access ignored');
    $check(S::is_curator($task,0),false,'anonymous');
    $check(S::can_access($task,0),false,'anonymous cannot access');
    $other=clone $task;
    $other->auditid=200;
    $check(S::is_curator($other,7),false,'wrong audit even with valid company');
    foreach (['implementation_task_discussion.php','implementation_analytics.php'] as $file) {
        $source=file_get_contents(dirname(__DIR__).'/classes/local/'.$file);
        $check(str_contains($source, 't.companyid = p.companyid'), true, 'scope join in '.$file);
    }
    $source=file_get_contents(dirname(__DIR__).'/classes/local/implementation_section_progress.php');
    $check(str_contains($source,'p.companyid = a.companyid'),true,'audit scope');
    $source=file_get_contents(dirname(__DIR__).'/implementation_curator.php');
    $check(str_contains($source,"html_writer::div(s(\$preview), 'kbacorp-curator-preview')"),true,'safe preview');
    $check(str_contains($source,"curatoropenreview"),true,'review action');
    echo "PASS curator scope 1822: $asserts assertions\n";
}
