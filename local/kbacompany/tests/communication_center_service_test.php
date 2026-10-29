<?php
namespace local_kbacompany;

defined('MOODLE_INTERNAL') || die();

use local_kbacompany\local\communication_center_service;
use local_kbacompany\local\communication_service;
use local_kbacompany\local\identity_registry;
use local_kbacompany\local\task_service;
use local_kbacorporate\local\repository;

/** Unified Communication Center regression tests. */
final class communication_center_service_test extends \advanced_testcase {
    private function fixture(): array {
        global $DB, $USER;
        $this->resetAfterTest();
        $this->setAdminUser();
        $admin = (int)$USER->id;
        $companyid = repository::save_company((object)['name' => 'Communication Center', 'active' => 1]);
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

    public function test_contract_keeps_message_task_and_notification_distinct(): void {
        $contract = communication_center_service::contract();
        $this->assertSame('0.5.0', $contract['version']);
        $this->assertContains('message_task_notification_remain_distinct_objects', $contract['rules']);
        $this->assertContains('task_provider_source_of_truth_is_preserved', $contract['rules']);
        $this->assertContains('mentions_are_first_class_inbox_projection', $contract['rules']);
        $this->assertContains('mentions', $contract['tabs']);
        $this->assertContains('unread', $contract['tabs']);
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $contract['schema_hash']);
    }

    public function test_unread_tab_projects_threads_without_opening_them(): void {
        $f = $this->fixture();
        $thread = communication_service::create_thread($f['company'], $f['admin'], [
            'threadtype' => 'direct', 'title' => '', 'participants' => [(int)$f['employee']->id],
        ]);
        communication_service::send_message($f['company'], $f['admin'], $thread, 'Unread source message.');
        $this->setUser($f['employee']);
        $screen = communication_center_service::screen_context(
            $f['company'], (int)$f['employee']->id, 'unread', '', 0);
        $this->assertSame(1, $screen['total']);
        $this->assertSame(1, $screen['rows'][0]['unread']);
        $after = communication_center_service::screen_context(
            $f['company'], (int)$f['employee']->id, 'unread', '', 0);
        $this->assertSame(1, $after['total'], 'Listing unread threads must not mark them read.');
    }

    public function test_mentions_tab_projects_only_explicit_mentions(): void {
        $f = $this->fixture();
        $thread = communication_service::create_thread($f['company'], $f['admin'], [
            'threadtype' => 'group', 'title' => 'Weekly coordination',
            'participants' => [(int)$f['employee']->id],
        ]);
        communication_service::send_message($f['company'], $f['admin'], $thread,
            'Please review the weekly numbers.', [(int)$f['employee']->id]);
        $this->setUser($f['employee']);
        $screen = communication_center_service::screen_context(
            $f['company'], (int)$f['employee']->id, 'mentions', '', 0);
        $this->assertSame(1, $screen['total']);
        $this->assertCount(1, $screen['rows']);
        $this->assertSame($thread, $screen['rows'][0]['thread_uuid']);
        $this->assertStringContainsString('weekly numbers', $screen['rows'][0]['body']);
    }

    public function test_employee_inbox_combines_unread_communication_and_due_task(): void {
        $f = $this->fixture();
        $thread = communication_service::create_thread($f['company'], $f['admin'], [
            'threadtype' => 'direct', 'title' => '', 'participants' => [(int)$f['employee']->id],
        ]);
        communication_service::send_message($f['company'], $f['admin'], $thread, 'Please complete the task.');
        task_service::create($f['company'], $f['admin'], [
            'title' => 'Due task', 'description' => '', 'product' => 'Accepted result',
            'assigneeid' => (int)$f['employee']->id, 'reviewerid' => 0, 'priority' => 'normal',
            'stream' => 'operate', 'startdate' => '', 'duedate' => '2026-09-20', 'planminutes' => 0,
            'parentuuid' => '',
        ]);

        $this->setUser($f['employee']);
        $context = communication_center_service::context($f['company'], (int)$f['employee']->id, 'inbox', 'my', 'all');
        $this->assertSame(1, $context['counts']['unread_notifications']);
        $this->assertSame(1, $context['counts']['tasks']);
        $kinds = array_column($context['inbox'], 'kind');
        $this->assertContains('notification', $kinds);
        $this->assertContains('task_due', $kinds);
    }
}
