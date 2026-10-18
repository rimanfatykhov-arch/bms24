<?php
namespace local_kbacompany;

defined('MOODLE_INTERNAL') || die();

use local_kbacompany\local\company_registry_service;
use local_kbacompany\local\integration_service;
use local_kbacompany\local\employee_service;

/** Regression source for the v0.5.16 Company & Employee Sync contract. */
final class company_employee_sync_service_test extends \advanced_testcase {
    public function test_company_requires_explicit_bms_client_flag_and_is_idempotent(): void {
        global $DB;
        $this->resetAfterTest();
        $ignored = integration_service::upsert_company_from_external('973', false, ['name' => 'Ignored']);
        $this->assertSame('ignored_not_bms_client', $ignored['status']);
        $this->assertSame(0, $DB->count_records('local_kbacompany_company'));

        $created = integration_service::upsert_company_from_external('973', true, ['name' => 'ООО Ромашка']);
        $updated = integration_service::upsert_company_from_external('973', true, ['name' => 'ООО Ромашка Новое']);
        $this->assertSame('created', $created['status']);
        $this->assertSame('updated', $updated['status']);
        $this->assertSame($created['company_uuid'], $updated['company_uuid']);
        $this->assertSame(1, $DB->count_records('local_kbacompany_company'));
        $this->assertSame('ООО Ромашка Новое', $DB->get_field('local_kbacompany_company', 'name',
            ['uuid' => $created['company_uuid']]));
    }

    public function test_removing_bms_flag_archives_company_without_deleting_it(): void {
        global $DB;
        $this->resetAfterTest();
        $created = integration_service::upsert_company_from_external('1001', true, ['name' => 'Archive me']);
        $archived = integration_service::upsert_company_from_external('1001', false, ['name' => 'Archive me']);
        $this->assertSame('archived', $archived['status']);
        $row = $DB->get_record('local_kbacompany_company', ['uuid' => $created['company_uuid']], '*', MUST_EXIST);
        $this->assertEquals(0, $row->active);
        $this->assertEquals(0, $row->bmsclient);
        $this->assertGreaterThan(0, $row->archivedat);
    }

    public function test_external_contact_uses_moodle_user_and_does_not_create_course_enrolment(): void {
        global $DB;
        $this->resetAfterTest();
        $user = $this->getDataGenerator()->create_user(['email' => 'worker@example.test']);
        $company = integration_service::upsert_company_from_external('2001', true, ['name' => 'BMS Client']);
        $result = integration_service::upsert_employee_from_external('2001', '3001', [
            'email' => 'worker@example.test', 'firstname' => 'Worker', 'lastname' => 'One',
            'allow_create_user' => false,
        ], ['employee']);
        $this->assertSame('updated', $result['status']);
        $this->assertSame((int)$user->id, $result['userid']);
        $this->assertTrue($DB->record_exists('local_kbacompany_member', [
            'companyuuid' => $company['company_uuid'], 'userid' => $user->id, 'active' => 1,
        ]));
        $this->assertTrue($DB->record_exists('local_kbacompany_extmap', [
            'provider' => 'bitrix24', 'entitykind' => 'user', 'externalid' => '3001',
        ]));
        $this->assertSame(0, $DB->count_records('user_enrolments', ['userid' => $user->id]));
    }


    public function test_external_contact_fills_missing_phone_and_company_display_projection(): void {
        global $DB;
        $this->resetAfterTest();
        $user = $this->getDataGenerator()->create_user([
            'email' => 'projection@example.test', 'phone1' => '', 'institution' => '',
        ]);
        $company = integration_service::upsert_company_from_external('2201', true, ['name' => 'ООО Проекция']);
        $result = integration_service::upsert_employee_from_external('2201', '3201', [
            'email' => 'projection@example.test', 'phone' => '+7 999 111-22-33', 'allow_create_user' => false,
        ], ['employee']);

        $updated = $DB->get_record('user', ['id' => $user->id], '*', MUST_EXIST);
        $this->assertSame('+7 999 111-22-33', (string)$updated->phone1);
        $this->assertSame('ООО Проекция', (string)$updated->institution);
        $this->assertSame('filled', $result['phone_projection']);
        $this->assertSame('filled', $result['company_projection']);
        $this->assertSame($company['company_uuid'], $result['company_uuid']);
    }

    public function test_external_contact_does_not_overwrite_valid_phone_or_unmanaged_company_display(): void {
        global $DB;
        $this->resetAfterTest();
        $user = $this->getDataGenerator()->create_user([
            'email' => 'keep@example.test', 'phone1' => '+7 900 000-00-01', 'institution' => 'Ручное значение',
        ]);
        integration_service::upsert_company_from_external('2202', true, ['name' => 'ООО Новая Компания']);
        $result = integration_service::upsert_employee_from_external('2202', '3202', [
            'email' => 'keep@example.test', 'phone' => '+7 900 000-00-02', 'allow_create_user' => false,
        ], ['employee']);

        $updated = $DB->get_record('user', ['id' => $user->id], '*', MUST_EXIST);
        $this->assertSame('+7 900 000-00-01', (string)$updated->phone1);
        $this->assertSame('Ручное значение', (string)$updated->institution);
        $this->assertSame('kept_existing', $result['phone_projection']);
        $this->assertSame('kept_unmanaged', $result['company_projection']);
    }

    public function test_external_contact_replaces_email_like_phone_but_never_uses_email_as_phone(): void {
        global $DB;
        $this->resetAfterTest();
        $user = $this->getDataGenerator()->create_user([
            'email' => 'invalidphone@example.test', 'phone1' => 'badphone@example.test', 'institution' => '',
        ]);
        integration_service::upsert_company_from_external('2203', true, ['name' => 'ООО Телефон']);
        $result = integration_service::upsert_employee_from_external('2203', '3203', [
            'email' => 'invalidphone@example.test', 'phone' => '+7 901 555-44-33', 'allow_create_user' => false,
        ], ['employee']);
        $updated = $DB->get_record('user', ['id' => $user->id], '*', MUST_EXIST);
        $this->assertSame('+7 901 555-44-33', (string)$updated->phone1);
        $this->assertSame('replaced_invalid', $result['phone_projection']);

        $result2 = integration_service::upsert_employee_from_external('2203', '3203', [
            'email' => 'invalidphone@example.test', 'phone' => 'other@example.test', 'allow_create_user' => false,
        ], ['employee']);
        $updated2 = $DB->get_record('user', ['id' => $user->id], '*', MUST_EXIST);
        $this->assertSame('+7 901 555-44-33', (string)$updated2->phone1);
        $this->assertSame('invalid_ignored', $result2['phone_projection']);
    }


    public function test_external_contact_projects_bitrix_position_to_moodle_profile_field(): void {
        global $DB;
        $this->resetAfterTest();
        $categoryid = (int)$DB->get_field_sql("SELECT MIN(id) FROM {user_info_category}");
        if ($categoryid <= 0) {
            $categoryid = (int)$DB->insert_record('user_info_category', (object)[
                'name' => 'Test', 'sortorder' => 1,
            ]);
        }
        $fieldid = (int)$DB->get_field('user_info_field', 'id', ['shortname' => 'position']);
        if ($fieldid <= 0) {
            $fieldid = (int)$DB->insert_record('user_info_field', (object)[
                'shortname' => 'position', 'name' => 'Position', 'datatype' => 'text',
                'description' => '', 'descriptionformat' => FORMAT_HTML, 'categoryid' => $categoryid,
                'sortorder' => 1, 'required' => 0, 'locked' => 0, 'visible' => 2, 'forceunique' => 0,
                'signup' => 0, 'defaultdata' => '', 'defaultdataformat' => FORMAT_HTML,
                'param1' => '30', 'param2' => '2048', 'param3' => '0', 'param4' => '', 'param5' => '',
            ]);
        }
        $user = $this->getDataGenerator()->create_user(['email' => 'position@example.test']);
        integration_service::upsert_company_from_external('2291', true, ['name' => 'ООО Должность']);
        $first = integration_service::upsert_employee_from_external('2291', '3291', [
            'email' => 'position@example.test', 'position' => 'Коммерческий директор', 'allow_create_user' => false,
        ], ['employee']);
        $this->assertSame('filled', $first['position_projection']);
        $this->assertSame('Коммерческий директор', $DB->get_field('user_info_data', 'data', [
            'userid' => $user->id, 'fieldid' => $fieldid,
        ]));
        $second = integration_service::upsert_employee_from_external('2291', '3291', [
            'email' => 'position@example.test', 'position' => 'Директор по продажам', 'allow_create_user' => false,
        ], ['employee']);
        $this->assertSame('updated_from_bitrix', $second['position_projection']);
        $this->assertSame('Директор по продажам', $DB->get_field('user_info_data', 'data', [
            'userid' => $user->id, 'fieldid' => $fieldid,
        ]));
        $third = integration_service::upsert_employee_from_external('2291', '3291', [
            'email' => 'position@example.test', 'position' => '', 'allow_create_user' => false,
        ], ['employee']);
        $this->assertSame('not_provided', $third['position_projection']);
        $this->assertSame('Директор по продажам', $DB->get_field('user_info_data', 'data', [
            'userid' => $user->id, 'fieldid' => $fieldid,
        ]));
    }

    public function test_corporate_learning_roles_do_not_grant_bms_management_permissions(): void {
        global $DB;
        $this->resetAfterTest();
        $user = $this->getDataGenerator()->create_user(['email' => 'corpmanager@example.test']);
        $company = integration_service::upsert_company_from_external('2101', true, ['name' => 'Role Boundary']);
        $result = integration_service::upsert_employee_from_external('2101', '3101', [
            'email' => 'corpmanager@example.test', 'allow_create_user' => false,
        ], ['corporate_manager', 'department_manager', 'hr']);
        $this->assertSame('updated', $result['status']);
        $member = $DB->get_record('local_kbacompany_member', [
            'companyuuid' => $company['company_uuid'], 'userid' => $user->id, 'active' => 1,
        ], '*', MUST_EXIST);
        $this->assertEquals(1, $member->isemployee);
        $this->assertEquals(0, $member->ismanager);
        $this->assertEquals(0, $member->isdepartmentmanager);
        $this->assertEquals(0, $member->ishr);
    }

    public function test_employee_command_cannot_complete_before_both_external_reconciliations(): void {
        global $DB;
        $this->resetAfterTest();
        $companyuuid = '22222222-2222-4222-8222-222222222222';
        $commanduuid = integration_service::enqueue('employee_upsert', $companyuuid, 45, [
            'company_uuid' => $companyuuid, 'moodle_user_id' => 45,
            'required_result' => ['bitrix_contact', 'kba_corporate_member'],
        ]);
        $this->expectException(\invalid_parameter_exception::class);
        integration_service::complete_command($commanduuid, ['external_contact_id' => '4001']);
    }

    public function test_outbound_employee_command_is_durable_and_idempotent(): void {
        global $DB;
        $this->resetAfterTest();
        $payload = ['company_uuid' => '11111111-1111-4111-8111-111111111111',
            'moodle_user_id' => 44, 'required_result' => ['bitrix_contact', 'kba_corporate_member']];
        $first = integration_service::enqueue('employee_upsert', $payload['company_uuid'], 44, $payload);
        $second = integration_service::enqueue('employee_upsert', $payload['company_uuid'], 44, $payload);
        $this->assertSame($first, $second);
        $this->assertSame(1, $DB->count_records('local_kbacompany_syncout'));
        $contract = integration_service::contract();
        $this->assertSame('local_bitrixsync', $contract['owner_of_bitrix_rest']);
        $this->assertSame('existing_deal_product_course_flow', $contract['course_enrolment_owner']);
    }
    public function test_external_company_reconciles_fresh_integration_duplicate_to_legacy_uuid(): void {
        global $DB;
        $this->resetAfterTest();
        $now = time();
        $legacyuuid = '33333333-3333-4333-8333-333333333333';
        $duplicateuuid = '44444444-4444-4444-8444-444444444444';
        $userid = (int)$this->getDataGenerator()->create_user(['email' => 'adopt@example.test'])->id;

        $DB->insert_record('local_kbacompany_company', (object)[
            'uuid' => $legacyuuid, 'name' => 'Legacy Company', 'code' => '', 'primaryactivity' => null,
            'foundedyear' => 0, 'bmsclient' => 0, 'sourcekind' => 'legacy_grandfathered',
            'legacycompanyid' => 77, 'syncstate' => 'legacy', 'active' => 1, 'archivedat' => 0,
            'createdby' => 0, 'modifiedby' => 0, 'timecreated' => $now, 'timemodified' => $now,
        ]);
        $DB->insert_record('local_kbacompany_company', (object)[
            'uuid' => $duplicateuuid, 'name' => 'Integration Copy', 'code' => '', 'primaryactivity' => null,
            'foundedyear' => 0, 'bmsclient' => 1, 'sourcekind' => 'integration',
            'legacycompanyid' => 0, 'syncstate' => 'synced', 'active' => 1, 'archivedat' => 0,
            'createdby' => 0, 'modifiedby' => 0, 'timecreated' => $now, 'timemodified' => $now,
        ]);
        $DB->insert_record('local_kbacompany_extmap', (object)[
            'provider' => 'bitrix24', 'entitykind' => 'company', 'localref' => $duplicateuuid,
            'externalid' => '8843', 'active' => 1, 'timecreated' => $now, 'timemodified' => $now,
        ]);
        $memberuuid = '55555555-5555-4555-8555-555555555555';
        $DB->insert_record('local_kbacompany_member', (object)[
            'uuid' => $memberuuid, 'companyuuid' => $duplicateuuid, 'userid' => $userid,
            'membertype' => 'employee', 'isemployee' => 1, 'ismanager' => 0,
            'isdepartmentmanager' => 0, 'ishr' => 0, 'effectivefrom' => $now, 'effectiveto' => 0,
            'sourcekind' => 'bitrix24', 'active' => 1, 'timecreated' => $now, 'timemodified' => $now,
        ]);
        $commanduuid = integration_service::enqueue('employee_upsert', $duplicateuuid, $userid, [
            'company_uuid' => $duplicateuuid, 'moodle_user_id' => $userid,
        ]);

        $result = integration_service::upsert_company_from_external('8843', true, [
            'name' => 'Бизнес Академия', 'legacycompanyid' => 77,
        ]);

        $this->assertSame('updated', $result['status']);
        $this->assertSame($legacyuuid, $result['company_uuid']);
        $map = integration_service::mapping_by_external('bitrix24', 'company', '8843');
        $this->assertSame($legacyuuid, (string)$map->localref);
        $this->assertTrue($DB->record_exists('local_kbacompany_member', [
            'uuid' => $memberuuid, 'companyuuid' => $legacyuuid, 'active' => 1,
        ]));
        $command = $DB->get_record('local_kbacompany_syncout', ['commanduuid' => $commanduuid], '*', MUST_EXIST);
        $this->assertSame($legacyuuid, (string)$command->companyuuid);
        $payload = json_decode((string)$command->payload, true);
        $this->assertSame($legacyuuid, $payload['company_uuid']);
        $this->assertSame('8843', (string)$payload['external_company_id']);
        $duplicate = company_registry_service::by_uuid($duplicateuuid);
        $this->assertEquals(0, $duplicate->active);
        $this->assertSame('superseded', (string)$duplicate->syncstate);
    }

}
