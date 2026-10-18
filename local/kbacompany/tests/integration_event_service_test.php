<?php
namespace local_kbacompany;

defined('MOODLE_INTERNAL') || die();

use local_kbacompany\local\integration_event_service;
use local_kbacompany\local\integration_service;

/** v0.6.6 provider-neutral outbox regression tests. */
final class integration_event_service_test extends \advanced_testcase {
    public function test_legacy_employee_command_dual_writes_sanitised_provider_neutral_event(): void {
        global $DB;
        $this->resetAfterTest();
        $companyuuid = '66000000-0000-4000-8000-000000000001';
        $this->create_company($companyuuid);

        $commanduuid = integration_service::enqueue('employee_upsert', $companyuuid, 77, [
            'company_uuid' => $companyuuid,
            'moodle_user_id' => 77,
            'external_company_id' => 'BITRIX-COMPANY-1',
            'external_contact_id' => 'BITRIX-CONTACT-2',
            'email' => 'person@example.test',
            'phone' => '+79990000000',
            'required_result' => ['bitrix_contact', 'kba_corporate_member'],
        ]);

        $this->assertSame(1, $DB->count_records('local_kbacompany_syncout'));
        $this->assertSame(1, $DB->count_records('local_kbacompany_eventout'));
        $event = $DB->get_record('local_kbacompany_eventout', ['sourcecommanduuid' => $commanduuid], '*', MUST_EXIST);
        $envelope = json_decode((string)$event->envelope, true);
        $this->assertSame('people.employee_projection.changed', $envelope['event_type']);
        $this->assertSame('company', $envelope['entity_type']);
        $this->assertSame($companyuuid, $envelope['entity_uuid']);
        $this->assertSame($commanduuid, $envelope['correlation_id']);
        $this->assertSame('employees', $envelope['payload']['resource']);
        $this->assertSame('upsert', $envelope['payload']['change']);
        $json = (string)$event->envelope;
        $this->assertStringNotContainsString('BITRIX-COMPANY-1', $json);
        $this->assertStringNotContainsString('BITRIX-CONTACT-2', $json);
        $this->assertStringNotContainsString('person@example.test', $json);
        $this->assertStringNotContainsString('+79990000000', $json);
    }

    public function test_duplicate_legacy_command_does_not_duplicate_event(): void {
        global $DB;
        $this->resetAfterTest();
        $companyuuid = '66000000-0000-4000-8000-000000000002';
        $this->create_company($companyuuid);
        $payload = ['company_uuid' => $companyuuid, 'moodle_user_id' => 88];

        $first = integration_service::enqueue('employee_upsert', $companyuuid, 88, $payload);
        $second = integration_service::enqueue('employee_upsert', $companyuuid, 88, $payload);

        $this->assertSame($first, $second);
        $this->assertSame(1, $DB->count_records('local_kbacompany_syncout'));
        $this->assertSame(1, $DB->count_records('local_kbacompany_eventout'));
    }

    public function test_event_outbox_lifecycle_is_independent_from_legacy_command_status(): void {
        global $DB;
        $this->resetAfterTest();
        $companyuuid = '66000000-0000-4000-8000-000000000003';
        $this->create_company($companyuuid);
        $commanduuid = integration_service::enqueue('company_reconcile', $companyuuid, 0,
            ['company_uuid' => $companyuuid, 'scope' => 'company_and_employees']);
        $event = $DB->get_record('local_kbacompany_eventout', ['sourcecommanduuid' => $commanduuid], '*', MUST_EXIST);

        integration_event_service::fail_event((string)$event->eventuuid, 'temporary');
        $event = $DB->get_record('local_kbacompany_eventout', ['id' => $event->id], '*', MUST_EXIST);
        $command = $DB->get_record('local_kbacompany_syncout', ['commanduuid' => $commanduuid], '*', MUST_EXIST);
        $this->assertSame('error', $event->status);
        $this->assertSame('pending', $command->status);

        integration_event_service::retry_event((string)$event->eventuuid);
        integration_event_service::complete_event((string)$event->eventuuid);
        $event = $DB->get_record('local_kbacompany_eventout', ['id' => $event->id], '*', MUST_EXIST);
        $this->assertSame('done', $event->status);
        $this->assertSame(1, (int)$event->attempts);
    }

    public function test_direct_publish_rejects_provider_specific_payload_fields(): void {
        $this->resetAfterTest();
        $companyuuid = '66000000-0000-4000-8000-000000000005';
        $entityuuid = '66000000-0000-4000-8000-000000000105';
        $this->create_company($companyuuid);
        $this->expectException(\invalid_parameter_exception::class);
        integration_event_service::publish('people.employee.changed', $companyuuid,
            'employee_membership', $entityuuid, 1, ['external_contact_id' => '123']);
    }

    public function test_direct_publish_is_idempotent(): void {
        global $DB;
        $this->resetAfterTest();
        $companyuuid = '66000000-0000-4000-8000-000000000004';
        $entityuuid = '66000000-0000-4000-8000-000000000104';
        $this->create_company($companyuuid);
        $payload = ['status' => 'published'];

        $first = integration_event_service::publish('organization.position.published', $companyuuid,
            'position', $entityuuid, 3, $payload, 'change-123', 1800000000);
        $second = integration_event_service::publish('organization.position.published', $companyuuid,
            'position', $entityuuid, 3, $payload, 'change-123', 1800000000);

        $this->assertSame($first, $second);
        $this->assertSame(1, $DB->count_records('local_kbacompany_eventout'));
    }

    private function create_company(string $uuid): void {
        global $DB;
        $now = time();
        $DB->insert_record('local_kbacompany_company', (object)[
            'uuid' => $uuid,
            'name' => 'Integration Test',
            'code' => '',
            'primaryactivity' => null,
            'foundedyear' => 0,
            'bmsclient' => 1,
            'sourcekind' => 'manual',
            'legacycompanyid' => 0,
            'syncstate' => 'local',
            'active' => 1,
            'archivedat' => 0,
            'createdby' => 0,
            'modifiedby' => 0,
            'timecreated' => $now,
            'timemodified' => $now,
        ]);
    }
}
