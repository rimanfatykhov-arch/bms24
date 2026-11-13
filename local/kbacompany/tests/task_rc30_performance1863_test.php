<?php
namespace local_kbacompany;

defined('MOODLE_INTERNAL') || die();

use local_kbacompany\local\task_service;
use local_kbacompany\local\identity_registry;
use local_kbacorporate\local\repository;

/** Regression: production read paths stay indexed and recent activity reuses a preloaded directory. */
final class task_rc30_performance1863_test extends \advanced_testcase {
    public function test_task_read_path_indexes_exist_after_install_or_upgrade(): void {
        global $DB;
        $this->resetAfterTest();
        $manager = $DB->get_manager();
        foreach ([
            ['local_kbacompany_task', 'status_due_ix', ['companyuuid', 'status', 'duedate']],
            ['local_kbacompany_tasklog', 'lastlog_ix', ['companyuuid', 'taskuuid', 'id']],
            ['local_kbacompany_bplanitem', 'legacy_task_ix', ['companyuuid', 'provider', 'taskuuid']],
            ['local_kbacompany_bplanitem', 'provider_ref_ix', ['companyuuid', 'provider', 'externalid']],
        ] as [$table, $name, $fields]) {
            $matches = array_filter($DB->get_indexes($table, false), static function(array $index) use ($fields): bool {
                return !(bool)$index['unique'] && array_values($index['columns']) === $fields;
            });
            $this->assertNotEmpty($matches, 'Missing ordered nonunique read index: ' . $table . '.' . $name);
        }
    }

    public function test_preloaded_employee_names_do_not_cross_company_boundary(): void {
        global $DB, $USER;
        $this->resetAfterTest();
        $this->setAdminUser();
        $companya = identity_registry::company_uuid(repository::save_company(
            (object)['name'=>'Perf 1863 A','active'=>1]));
        $companyb = identity_registry::company_uuid(repository::save_company(
            (object)['name'=>'Perf 1863 B','active'=>1]));
        $taska = '10000000-0000-4000-8000-000000001863';
        $taskb = '20000000-0000-4000-8000-000000001863';
        foreach ([[$companya, $taska], [$companyb, $taskb]] as [$company, $taskuuid]) {
            $DB->insert_record('local_kbacompany_task', (object)[
                'companyuuid'=>$company, 'uuid'=>$taskuuid, 'title'=>'Performance scope',
                'product'=>'Valid task', 'assigneeid'=>(int)$USER->id, 'status'=>'open',
            ]);
            $DB->insert_record('local_kbacompany_tasklog', (object)[
                'companyuuid'=>$company, 'taskuuid'=>$taskuuid,
                'actorid'=>8653, 'action'=>'create', 'oldstatus'=>'',
                'newstatus'=>'open', 'timecreated'=>time(),
            ]);
        }
        // Include an out-of-company UUID deliberately: SQL must filter it out.
        $result = task_service::latest_page_activity($companya, (int)$USER->id,
            [['uuid'=>$taska], ['uuid'=>$taskb]], [8653=>'Preloaded author']);
        $this->assertCount(1, $result);
        $this->assertSame('Preloaded author', $result[$taska]['actor_name']);
        $this->assertArrayNotHasKey($taskb, $result);
    }

    public function test_recent_activity_with_empty_page_needs_no_employee_lookup(): void {
        // An empty page should not cause a company-wide employee directory fetch.
        global $USER;
        $this->resetAfterTest();
        $this->setAdminUser();
        $id = repository::save_company((object)['name'=>'RC30 perf 1863','active'=>1]);
        $company = identity_registry::company_uuid($id);
        $this->assertSame([], task_service::latest_page_activity($company, (int)$USER->id, [], []));
    }
}
