<?php
namespace local_kbacompany;

defined('MOODLE_INTERNAL') || die();

use local_kbacompany\local\company_registry_service;
use local_kbacompany\local\deviation_service;
use local_kbacompany\local\task_service;
use local_kbacorporate\local\repository;

/** Real Moodle DB/auth acceptance: company boundaries for tasks and deviations. */
final class pr48_tenant_isolation_test extends \advanced_testcase {
    /** Create two disjoint companies with equally privileged, non-admin managers. */
    private function tenants(): array {
        global $DB, $USER;
        $this->resetAfterTest();
        $this->setAdminUser();
        $adminid = (int)$USER->id;
        $context = \context_system::instance();
        $roleid = create_role('PR48 company manager', 'pr48manager', 'Isolated acceptance fixture');
        assign_capability('local/kbacompany:viewowncompany', CAP_ALLOW, $roleid, $context->id);
        assign_capability('local/kbacompany:manageorganization', CAP_ALLOW, $roleid, $context->id);
        // Explicitly exclude global company access, even if site defaults change.
        assign_capability('local/kbacompany:viewallcompanies', CAP_PROHIBIT, $roleid, $context->id);
        $tenants = [];
        foreach (['A', 'B'] as $index => $label) {
            $user = $this->getDataGenerator()->create_user();
            role_assign($roleid, $user->id, $context->id);
            $legacy = (object)['name' => 'PR48 tenant ' . $label, 'active' => 1];
            $legacy->id = repository::save_company($legacy);
            $company = company_registry_service::adopt_legacy($legacy, $adminid);
            // Adopted companies resolve the employee directory through Corporate.
            $DB->insert_record('local_kbacorp_member', (object)[
                'companyid' => $legacy->id, 'userid' => $user->id,
                'membertype' => 'employee', 'isemployee' => 1, 'ismanager' => 1,
                'isdepartmentmanager' => 0, 'ishr' => 0,
                'timecreated' => time(), 'timemodified' => time(),
            ]);
            $DB->insert_record('local_kbacompany_member', (object)[
                'uuid' => sprintf('48000000-0000-4000-8000-%012d', $index + 1),
                'companyuuid' => $company->uuid, 'userid' => $user->id,
                'membertype' => 'employee', 'isemployee' => 1, 'ismanager' => 1,
                'active' => 1, 'timecreated' => time(), 'timemodified' => time(),
            ]);
            $this->assertFalse(is_siteadmin($user->id));
            $this->assertFalse(has_capability('local/kbacompany:viewallcompanies', $context, $user->id));
            $tenants[] = [(string)$company->uuid, $user];
        }
        return $tenants;
    }

    /** Preserve full rows, not only counts, so denied updates cannot hide in place. */
    private function business_snapshot(): array {
        global $DB;
        $snapshot = [];
        foreach (['local_kbacompany_task', 'local_kbacompany_tasklog',
                'local_kbacompany_deviation', 'local_kbacompany_aclog'] as $table) {
            $snapshot[$table] = $DB->get_records($table, null, 'id ASC');
        }
        return $snapshot;
    }

    /** Only the expected boundary failure counts as a denial; unrelated errors fail. */
    private function assert_denied_without_mutation(callable $operation, string $errorcode): void {
        $before = $this->business_snapshot();
        $caught = null;
        try {
            $operation();
        } catch (\moodle_exception $e) {
            $caught = $e;
        }
        $this->assertNotNull($caught, 'Cross-company operation must be denied');
        $this->assertSame($errorcode, $caught->errorcode);
        $this->assertEquals($before, $this->business_snapshot(), 'Denied operation mutated business or audit rows');
    }

    private function task_data(int $assignee): array {
        return ['title' => 'PR48 private task', 'description' => 'Tenant-owned evidence',
            'product' => 'Verified result', 'assigneeid' => $assignee, 'reviewerid' => 0,
            'observerid' => 0, 'priority' => 'normal', 'stream' => 'operate',
            'startdate' => '', 'duedate' => '', 'planminutes' => 0, 'parentuuid' => ''];
    }

    public function test_task_reads_and_writes_enforce_both_company_and_object_boundaries(): void {
        global $DB;
        [[$companya, $usera], [$companyb, $userb]] = $this->tenants();
        $tasks = [];
        foreach ([[$companya, $usera], [$companyb, $userb]] as [$company, $user]) {
            $this->setUser($user);
            $uuid = task_service::create($company, (int)$user->id, $this->task_data((int)$user->id));
            $this->assertSame($uuid, task_service::readable_projection($company, (int)$user->id, $uuid)['uuid']);
            $tasks[] = $uuid;
        }
        foreach ([[$companya, $usera, $companyb, $tasks[1]],
                [$companyb, $userb, $companya, $tasks[0]]] as [$own, $actor, $foreign, $target]) {
            $this->setUser($actor);
            $actorid = (int)$actor->id;
            $revision = (int)$DB->get_field('local_kbacompany_task', 'revision', ['uuid' => $target], MUST_EXIST);
            $before = $this->business_snapshot();
            $this->assertNull(task_service::readable_projection($own, $actorid, $target));
            $this->assertEquals($before, $this->business_snapshot());
            $this->assert_denied_without_mutation(static function() use ($foreign, $actorid, $target) {
                task_service::readable_projection($foreign, $actorid, $target);
            }, 'companynotavailable');
            $this->assert_denied_without_mutation(static function() use ($own, $actorid, $target, $revision) {
                task_service::transition($own, $actorid, $target, $revision, 'start');
            }, 'taskcenter_reference');
            $this->assert_denied_without_mutation(static function() use ($foreign, $actorid, $target, $revision) {
                task_service::transition($foreign, $actorid, $target, $revision, 'start');
            }, 'companynotavailable');
        }
        foreach ([[$companya, $usera, $tasks[0]], [$companyb, $userb, $tasks[1]]] as [$company, $user, $uuid]) {
            $this->setUser($user);
            $revision = (int)$DB->get_field('local_kbacompany_task', 'revision', ['uuid' => $uuid], MUST_EXIST);
            task_service::transition($company, (int)$user->id, $uuid, $revision, 'start');
            $this->assertSame('doing', $DB->get_field('local_kbacompany_task', 'status', ['uuid' => $uuid]));
        }
    }

    public function test_deviation_reads_and_writes_enforce_both_company_and_object_boundaries(): void {
        [[$companya, $usera], [$companyb, $userb]] = $this->tenants();
        $data = ['title' => 'PR48 private deviation', 'description' => 'Observed discrepancy',
            'location' => 'Tenant workspace', 'observedon' => '2020-01-01',
            'requirement' => 'Expected result', 'evidence' => 'Acceptance fixture'];
        $deviations = [];
        foreach ([[$companya, $usera], [$companyb, $userb]] as [$company, $user]) {
            $this->setUser($user);
            $uuid = deviation_service::command($company, (int)$user->id, 'report', $data);
            $row = deviation_service::read_record($company, (int)$user->id, $uuid);
            $this->assertSame($uuid, $row->uuid);
            $this->assertSame('reported', $row->status);
            $deviations[] = $row;
        }
        foreach ([[$companya, $usera, $companyb, $deviations[1]],
                [$companyb, $userb, $companya, $deviations[0]]] as [$own, $actor, $foreign, $target]) {
            $this->setUser($actor);
            $actorid = (int)$actor->id;
            $edit = array_merge($data, ['object' => $target->uuid, 'revision' => (int)$target->revision,
                'title' => 'Unauthorized change']);
            foreach ([$own => 'dv_reference', $foreign => 'companynotavailable'] as $scope => $errorcode) {
                $this->assert_denied_without_mutation(static function() use ($scope, $actorid, $target) {
                    deviation_service::read_record($scope, $actorid, $target->uuid);
                }, $errorcode);
                $this->assert_denied_without_mutation(static function() use ($scope, $actorid, $edit) {
                    deviation_service::command($scope, $actorid, 'editreport', $edit);
                }, $errorcode);
            }
        }
        foreach ([[$companya, $usera, $deviations[0]], [$companyb, $userb, $deviations[1]]] as [$company, $user, $row]) {
            $this->setUser($user);
            $edit = array_merge($data, ['object' => $row->uuid, 'revision' => (int)$row->revision,
                'title' => 'Authorized correction']);
            deviation_service::command($company, (int)$user->id, 'editreport', $edit);
            $updated = deviation_service::read_record($company, (int)$user->id, $row->uuid);
            $this->assertSame('Authorized correction', $updated->title);
            $this->assertSame((int)$row->revision + 1, (int)$updated->revision);
        }
    }
}
