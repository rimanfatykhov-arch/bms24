<?php
namespace local_kbacompany;

defined('MOODLE_INTERNAL') || die();

use local_kbacompany\local\document_service;
use local_kbacompany\local\employee_service;
use local_kbacompany\local\employee_workspace_service;

/** Service-boundary integration regressions; browser/session ACL remains separate. */
final class rc30_integrated_access_test extends \advanced_testcase {
    private const A = '11111111-1111-4111-8111-111111111111';
    private const B = '22222222-2222-4222-8222-222222222222';

    private function fixture(): array {
        global $DB;
        $this->resetAfterTest();
        $this->setAdminUser();
        $employee = $this->getDataGenerator()->create_user();
        $peer = $this->getDataGenerator()->create_user();
        foreach ([self::A, self::B] as $index => $company) {
            $DB->insert_record('local_kbacompany_company', (object)[
                'uuid' => $company, 'name' => 'Integrated tenant ' . $index,
                'sourcekind' => 'bms', 'bmsclient' => 1, 'active' => 1,
            ]);
        }
        foreach ([$employee, $peer] as $index => $user) {
            $DB->insert_record('local_kbacompany_member', (object)[
                'uuid' => sprintf('33333333-3333-4333-8333-%012d', $index + 1),
                'companyuuid' => self::A, 'userid' => $user->id, 'active' => 1, 'isemployee' => 1,
            ]);
        }
        $DB->insert_record('local_kbacompany_member', (object)[
            'uuid' => '44444444-4444-4444-8444-444444444444',
            'companyuuid' => self::B, 'userid' => $peer->id, 'active' => 1, 'isemployee' => 1,
        ]);
        return [$employee, $peer];
    }

    private function denied(callable $operation, string $code): void {
        try {
            $operation();
            $this->fail('Operation must be rejected');
        } catch (\moodle_exception $error) {
            $this->assertSame($code, $error->errorcode);
        }
    }

    public static function revoked_provider(): array {
        return ['inactive' => [false], 'removed' => [true]];
    }

    /** @dataProvider revoked_provider */
    public function test_membership_revocation_is_rechecked_without_new_session(bool $remove): void {
        global $DB;
        [$employee] = $this->fixture();
        $this->setUser($employee);
        $before = employee_workspace_service::tab_context(self::A, $employee->id, $employee->id, 'tasks', []);
        $this->assertTrue($before['can_inspect']);
        $scope = ['companyuuid' => self::A, 'userid' => $employee->id];
        if ($remove) { $DB->delete_records('local_kbacompany_member', $scope); }
        else { $DB->set_field('local_kbacompany_member', 'active', 0, $scope); }
        // Deliberately do not reset session or capability caches after revocation.
        $this->denied(static function() use ($employee): void {
            employee_workspace_service::tab_context(self::A, $employee->id, $employee->id, 'tasks', []);
        }, 'companynotavailable');
        $this->assertDebuggingNotCalled();
    }

    public function test_target_and_membership_identifiers_cannot_expand_employee_access(): void {
        [$employee, $peer] = $this->fixture();
        $this->setUser($employee);
        $context = employee_workspace_service::tab_context(self::A, $peer->id, $employee->id, 'competencies',
            ['user_id' => $employee->id, 'can_manage' => true]);
        $this->assertFalse($context['can_inspect']);
        $this->assertTrue($context['restricted']);
        $this->assertArrayNotHasKey('competencies', $context);
        $this->assertArrayNotHasKey('development_courses', $context);
        $this->denied(static function() use ($employee): void {
            employee_workspace_service::tab_context(self::B, $employee->id, $employee->id, 'competencies', []);
        }, 'companynotavailable');
        // An administrator may inspect both tenants, but a foreign membership UUID
        // must still not resolve as an employee membership in company A.
        $this->setAdminUser();
        $admin = (int)get_admin()->id;
        $this->denied(static function() use ($peer, $admin): void {
            employee_service::employee_context(self::A, $peer->id, $admin, true,
                '44444444-4444-4444-8444-444444444444');
        }, 'bms_employee_not_found');
        $this->assertDebuggingNotCalled();
    }

    public function test_foreign_document_and_wrong_revision_preserve_document_data(): void {
        global $DB;
        $this->fixture();
        $admin = (int)get_admin()->id;
        $uuid = document_service::create_manual(self::A, $admin, [
            'code' => 'INTEGRATION-A', 'title' => 'Private tenant A draft',
            'doctype' => 'procedure', 'doclevel' => 2, 'content' => 'Unchanged protected content',
        ]);
        $own = document_service::context(self::A, $admin, $uuid);
        $revision = (int)$own['selected']['revision'];
        $before = $DB->get_records('local_kbacompany_doc', null, 'id');
        $this->denied(static function() use ($admin, $uuid): void {
            document_service::context(self::B, $admin, $uuid);
        }, 'document_notavailable');
        $this->denied(static function() use ($admin, $uuid, $revision): void {
            document_service::save(self::B, $admin, $uuid, $revision, ['title' => 'Foreign edit']);
        }, 'document_reference');
        $this->denied(static function() use ($admin, $uuid, $revision): void {
            document_service::save(self::A, $admin, $uuid, $revision + 1, ['title' => 'Wrong revision']);
        }, 'document_conflict');
        $this->assertEquals($before, $DB->get_records('local_kbacompany_doc', null, 'id'));
        $this->assertSame([], document_service::context(self::B, $admin)['master_list']);
        $this->assertDebuggingNotCalled();
    }
}
