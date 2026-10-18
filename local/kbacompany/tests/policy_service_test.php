<?php
namespace local_kbacompany;

defined('MOODLE_INTERNAL') || die();

use local_kbacompany\local\identity_registry;
use local_kbacompany\local\policy_service as policies;
use local_kbacorporate\local\repository;

/** Policy revisions remain explicit and immutable. */
final class policy_service_test extends \advanced_testcase {
    private function fixture(): array {
        global $DB, $USER;
        $this->resetAfterTest();
        $this->setAdminUser();
        $actor = (int)$USER->id;
        $company = identity_registry::company_uuid(repository::save_company((object)[
            'name' => 'Governance', 'active' => 1,
        ]));
        $branch = '20000000-0000-4000-8000-000000000001';
        $node = '20000000-0000-4000-8000-000000000002';
        $position = '20000000-0000-4000-8000-000000000003';
        $now = time();
        $DB->insert_record('local_kbacompany_orgbranch', (object)['companyuuid' => $company, 'uuid' => $branch,
            'code' => 'MAIN', 'name' => 'Main', 'isdefault' => 1, 'active' => 1, 'sortorder' => 1,
            'timecreated' => $now, 'timemodified' => $now]);
        $DB->insert_record('local_kbacompany_orgnode', (object)['companyuuid' => $company, 'branchuuid' => $branch,
            'uuid' => $node, 'parentuuid' => '', 'code' => '7', 'nodetype' => 'division', 'name' => 'Administration',
            'sortorder' => 1, 'color' => '', 'sourcecomponent' => '', 'sourceentity' => '', 'sourceid' => 0,
            'active' => 1, 'timecreated' => $now, 'timemodified' => $now]);
        $DB->insert_record('local_kbacompany_position', (object)['companyuuid' => $company, 'branchuuid' => $branch,
            'orgnodeuuid' => $node, 'uuid' => $position, 'code' => 'CEO', 'name' => 'CEO', 'purpose' => '',
            'ismanager' => 1, 'plannedcount' => 1, 'sortorder' => 1, 'active' => 1,
            'timecreated' => $now, 'timemodified' => $now]);
        return compact('company', 'actor', 'branch', 'node', 'position');
    }

    private function draft(array $f, array $overrides = []): array {
        return $overrides + ['kind' => 'policy', 'code' => 'POL-001', 'title' => 'Information policy',
            'purpose' => 'Define the expected company behaviour.', 'scopetype' => 'company', 'scopeuuid' => '',
            'ownertype' => 'position', 'owneruuid' => $f['position'], 'effectivefrom' => '2026-09-15',
            'effectiveto' => '', 'documenturl' => 'https://example.test/policies/POL-001',
            'basis' => 'Executive council decision', 'reason' => 'Initial draft'];
    }

    public function test_publish_and_new_version_are_append_only(): void {
        global $DB;
        $f = $this->fixture();
        $uuid = policies::command($f['company'], $f['actor'], 'save', $this->draft($f));
        policies::command($f['company'], $f['actor'], 'publish',
            ['object' => $uuid, 'revision' => 1, 'reason' => 'Approved by executive council']);
        policies::command($f['company'], $f['actor'], 'newversion',
            ['object' => $uuid, 'revision' => 2, 'reason' => 'Annual review']);
        $rows = array_values($DB->get_records('local_kbacompany_policy', ['uuid' => $uuid], 'revision ASC'));
        $this->assertCount(3, $rows);
        $this->assertSame(['draft', 'published', 'draft'], array_column($rows, 'status'));
        $this->assertSame('Information policy', $rows[1]->title);
    }

    public function test_publication_requires_document_and_effective_date(): void {
        $f = $this->fixture();
        $uuid = policies::command($f['company'], $f['actor'], 'save',
            $this->draft($f, ['effectivefrom' => '', 'documenturl' => '']));
        $this->expectException(\moodle_exception::class);
        $this->expectExceptionMessage(get_string('policy_publishrequired', 'local_kbacompany'));
        policies::command($f['company'], $f['actor'], 'publish',
            ['object' => $uuid, 'revision' => 1, 'reason' => 'Approve']);
    }

    public function test_withdrawal_does_not_delete_published_revision(): void {
        global $DB;
        $f = $this->fixture();
        $uuid = policies::command($f['company'], $f['actor'], 'save', $this->draft($f));
        policies::command($f['company'], $f['actor'], 'publish',
            ['object' => $uuid, 'revision' => 1, 'reason' => 'Approve']);
        policies::command($f['company'], $f['actor'], 'withdraw',
            ['object' => $uuid, 'revision' => 2, 'reason' => 'Replaced by another policy']);
        $rows = array_values($DB->get_records('local_kbacompany_policy', ['uuid' => $uuid], 'revision ASC'));
        $this->assertCount(3, $rows);
        $this->assertSame('published', $rows[1]->status);
        $this->assertSame('withdrawn', $rows[2]->status);
    }

    /** Added regression specification; requires real Moodle PHPUnit to execute. */
    public function test_automatic_code_is_preserved_on_later_draft_save(): void {
        $f = $this->fixture();
        $uuid = policies::command($f['company'], $f['actor'], 'save', $this->draft($f, ['code' => '']));
        $first = policies::read_record($f['company'], $f['actor'], $uuid);
        $this->assertSame(1, preg_match('/^POL-[0-9]{4,}$/', $first->code));
        policies::command($f['company'], $f['actor'], 'save',
            $this->draft($f, ['code' => $first->code, 'object' => $uuid, 'revision' => 1, 'title' => 'Updated']));
        $next = policies::read_record($f['company'], $f['actor'], $uuid);
        $this->assertSame($first->code, $next->code);
        $this->assertSame(2, (int)$next->revision);
        $this->assertSame('Information policy', policies::read_record($f['company'], $f['actor'], $uuid, 1)->title);
    }

    public function test_existing_code_cannot_be_changed(): void {
        $f = $this->fixture();
        $uuid = policies::command($f['company'], $f['actor'], 'save', $this->draft($f));
        $this->expectException(\moodle_exception::class);
        $this->expectExceptionMessage(get_string('policy_codeimmutable', 'local_kbacompany'));
        policies::command($f['company'], $f['actor'], 'save',
            $this->draft($f, ['object' => $uuid, 'revision' => 1, 'code' => 'OTHER']));
    }

    public function test_impossible_calendar_date_is_a_field_error(): void {
        $f = $this->fixture();
        $errors = policies::validation_errors($f['company'], $f['actor'],
            $this->draft($f, ['effectivefrom' => '2026-02-30']));
        $this->assertArrayHasKey('effectivefrom', $errors);
    }

    public function test_external_document_url_has_no_active_scheme_or_credentials(): void {
        $this->assertFalse(policies::safe_url('javascript:alert(1)'));
        $this->assertFalse(policies::safe_url('https://user:password@example.test/document'));
        $this->assertTrue(policies::safe_url('https://example.test/document'));
    }

}
