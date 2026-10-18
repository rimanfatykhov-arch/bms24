<?php
namespace local_kbacompany;

defined('MOODLE_INTERNAL') || die();

use local_kbacompany\local\identity_registry;
use local_kbacompany\local\task_provider_service;
use local_kbacompany\local\task_service;
use local_kbacorporate\local\repository;

/** Unified task-provider regression tests. */
final class task_provider_service_test extends \advanced_testcase {
    private function fixture(): array {
        global $DB, $USER;
        $this->resetAfterTest();
        $this->setAdminUser();
        $admin = (int)$USER->id;
        $companyid = repository::save_company((object)['name' => 'Unified Tasks', 'active' => 1]);
        $company = identity_registry::company_uuid($companyid);
        $employee = $this->getDataGenerator()->create_user();
        $now = time();
        $DB->insert_record('local_kbacorp_member', (object)[
            'companyid' => $companyid, 'userid' => $employee->id, 'membertype' => 'employee',
            'isemployee' => 1, 'ismanager' => 0, 'isdepartmentmanager' => 0, 'ishr' => 0,
            'timecreated' => $now, 'timemodified' => $now,
        ]);
        return compact('admin', 'companyid', 'company', 'employee');
    }

    public function test_contract_declares_provider_ownership_boundary(): void {
        $contract = task_provider_service::contract();
        $this->assertSame('0.4.2.2', $contract['version']);
        $this->assertSame(['bms', 'legacy', 'kbaelement'], $contract['providers']);
        $this->assertContains('task_center_aggregates_without_stealing_source_ownership', $contract['rules']);
        $this->assertContains('external_tasks_may_be_referenced_by_battle_plan_without_copying', $contract['rules']);
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $contract['schema_hash']);
    }

    public function test_unified_context_projects_bms_and_legacy_without_copying_legacy(): void {
        global $DB;
        $f = $this->fixture();
        $canonical = task_service::create($f['company'], $f['admin'], [
            'title' => 'Canonical BMS task', 'description' => '', 'product' => 'BMS result',
            'assigneeid' => (int)$f['employee']->id, 'reviewerid' => 0, 'priority' => 'normal',
            'stream' => 'operate', 'startdate' => '', 'duedate' => '2026-09-20', 'planminutes' => 30,
            'parentuuid' => '',
        ]);
        $legacyuuid = '90000000-0000-4000-8000-000000000001';
        $now = time();
        $DB->insert_record('local_kbacompany_wfwork', (object)[
            'companyuuid' => $f['company'], 'uuid' => $legacyuuid, 'kind' => 'task', 'parentuuid' => '',
            'functionuuid' => '', 'approvaluuid' => '', 'normuuid' => '', 'assignmentuuid' => '',
            'userid' => (int)$f['employee']->id, 'reviewerid' => 0, 'name' => 'Legacy task',
            'description' => 'Old execution workflow', 'acceptance' => 'Legacy accepted product',
            'deadline' => '2026-09-21', 'status' => 'doing', 'revision' => 1, 'attempt' => 0,
            'timecreated' => $now, 'timemodified' => $now,
        ]);

        $context = task_provider_service::context($f['company'], $f['admin'], 'all', 'all', 0);
        $byprovider = [];
        foreach ($context['tasks'] as $task) { $byprovider[$task['provider']][] = $task; }
        $this->assertArrayHasKey('bms', $byprovider);
        $this->assertArrayHasKey('legacy', $byprovider);
        $this->assertSame($canonical, $byprovider['bms'][0]['canonical_task_uuid']);
        $this->assertSame('', $byprovider['legacy'][0]['canonical_task_uuid']);
        $this->assertSame($legacyuuid, $byprovider['legacy'][0]['external_id']);
        $this->assertSame(1, $DB->count_records('local_kbacompany_task', ['companyuuid' => $f['company']]));
        $this->assertSame(1, $DB->count_records('local_kbacompany_wfwork', ['companyuuid' => $f['company'], 'kind' => 'task']));
    }

    public function test_employee_only_receives_own_legacy_projection(): void {
        global $DB;
        $f = $this->fixture();
        $other = $this->getDataGenerator()->create_user();
        $now = time();
        $DB->insert_record('local_kbacorp_member', (object)[
            'companyid' => $f['companyid'], 'userid' => $other->id, 'membertype' => 'employee',
            'isemployee' => 1, 'ismanager' => 0, 'isdepartmentmanager' => 0, 'ishr' => 0,
            'timecreated' => $now, 'timemodified' => $now,
        ]);
        foreach ([[1, $f['employee']->id], [2, $other->id]] as [$n, $userid]) {
            $DB->insert_record('local_kbacompany_wfwork', (object)[
                'companyuuid' => $f['company'], 'uuid' => sprintf('90000000-0000-4000-8000-%012d', $n),
                'kind' => 'task', 'parentuuid' => '', 'functionuuid' => '', 'approvaluuid' => '',
                'normuuid' => '', 'assignmentuuid' => '', 'userid' => (int)$userid, 'reviewerid' => 0,
                'name' => 'Legacy ' . $n, 'description' => '', 'acceptance' => 'Product', 'deadline' => '',
                'status' => 'open', 'revision' => 1, 'attempt' => 0, 'timecreated' => $now, 'timemodified' => $now,
            ]);
        }
        $this->setUser($f['employee']);
        $context = task_provider_service::context($f['company'], (int)$f['employee']->id, 'my', 'legacy', (int)$f['employee']->id);
        $this->assertCount(1, $context['tasks']);
        $this->assertSame((int)$f['employee']->id, (int)$context['tasks'][0]['assignee_id']);
    }
}
