<?php
namespace local_kbacompany;

defined('MOODLE_INTERNAL') || die();

use local_kbacompany\local\identity_registry;
use local_kbacompany\local\task_service;
use local_kbacompany\local\task_bundle_service;
use local_kbacorporate\local\repository;

/** Regression tests for company-scoped task templates and atomic launches. */
final class task_bundle_service_test extends \advanced_testcase {
    private function fixture(): array {
        global $DB, $USER;
        $this->resetAfterTest();
        $this->setAdminUser();
        $actor = (int)$USER->id;
        $companyid = repository::save_company((object)['name' => 'Template test', 'active' => 1]);
        $company = identity_registry::company_uuid($companyid);
        $employee = $this->getDataGenerator()->create_user();
        $DB->insert_record('local_kbacorp_member', (object)[
            'companyid' => $companyid, 'userid' => (int)$employee->id, 'membertype' => 'employee',
            'isemployee' => 1, 'ismanager' => 0, 'isdepartmentmanager' => 0, 'ishr' => 0,
            'timecreated' => time(), 'timemodified' => time(),
        ]);
        return compact('actor', 'company', 'employee');
    }

    private function task(array $f, string $title, string $parent = ''): string {
        return task_service::create($f['company'], $f['actor'], [
            'title' => $title, 'description' => 'Work definition', 'product' => 'Verified result',
            'stream' => 'operate', 'priority' => 'normal', 'assigneeid' => (int)$f['employee']->id,
            'reviewerid' => 0, 'startdate' => '2026-10-20', 'duedate' => '2026-10-23',
            'planminutes' => 60, 'parentuuid' => $parent,
        ]);
    }

    public function test_snapshot_and_launch_preserve_relative_dates_and_reset_checkmarks(): void {
        global $DB;
        $f = $this->fixture();
        $root = $this->task($f, 'Original parent');
        $child = $this->task($f, 'Original child', $root);
        $check = task_service::add_checklist($f['company'], $f['actor'], $child, 'Verification step');
        task_service::toggle_checklist($f['company'], $f['actor'], $child, $check);
        $template = task_bundle_service::capture($f['company'], $f['actor'], $root, 'Weekly audit');
        $key = bin2hex(random_bytes(16));
        $new = task_bundle_service::launch($f['company'], $f['actor'], $template, $key,
            (int)$f['employee']->id, 0, '2026-11-01');
        $parent = $DB->get_record('local_kbacompany_task', ['companyuuid' => $f['company'], 'uuid' => $new]);
        $this->assertSame('manual', $parent->sourcekind);
        $this->assertSame('2026-11-01', $parent->startdate);
        $this->assertSame('2026-11-04', $parent->duedate);
        $children = $DB->get_records('local_kbacompany_task', ['companyuuid' => $f['company'], 'parentuuid' => $new]);
        $this->assertCount(1, $children);
        $createdchild = reset($children);
        $this->assertSame('open', $createdchild->status);
        $checks = $DB->get_records('local_kbacompany_taskcheck',
            ['companyuuid' => $f['company'], 'taskuuid' => $createdchild->uuid]);
        $this->assertCount(1, $checks);
        $this->assertSame(0, (int)reset($checks)->done);
        $same = task_bundle_service::launch($f['company'], $f['actor'], $template, $key,
            (int)$f['employee']->id, 0, '2026-11-01');
        $this->assertSame($new, $same);
        $this->assertCount(1, $DB->get_records('local_kbacompany_task',
            ['companyuuid' => $f['company'], 'parentuuid' => $new]));
    }

    public function test_replay_with_changed_payload_is_rejected(): void {
        $f = $this->fixture();
        $root = $this->task($f, 'Template base');
        $template = task_bundle_service::capture($f['company'], $f['actor'], $root, 'Reusable work');
        $key = bin2hex(random_bytes(16));
        task_bundle_service::launch($f['company'], $f['actor'], $template, $key,
            (int)$f['employee']->id, 0, '2026-11-01');
        $this->expectException(\moodle_exception::class);
        task_bundle_service::launch($f['company'], $f['actor'], $template, $key,
            (int)$f['employee']->id, 0, '2026-11-02');
    }

    public function test_deeper_nested_tasks_are_not_silently_omitted(): void {
        $f = $this->fixture();
        $root = $this->task($f, 'Top');
        $child = $this->task($f, 'Middle', $root);
        $this->task($f, 'Deep', $child);
        $this->expectException(\moodle_exception::class);
        task_bundle_service::capture($f['company'], $f['actor'], $root, 'Should reject');
    }

    public function test_archived_template_is_hidden_from_active_library(): void {
        $f = $this->fixture();
        $root = $this->task($f, 'Base');
        $template = task_bundle_service::capture($f['company'], $f['actor'], $root, 'Disposable template');
        $this->assertCount(1, task_bundle_service::library($f['company'], $f['actor']));
        task_bundle_service::archive($f['company'], $f['actor'], $template);
        $this->assertCount(0, task_bundle_service::library($f['company'], $f['actor']));
    }
}
