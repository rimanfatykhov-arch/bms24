<?php
namespace local_kbacompany;

defined('MOODLE_INTERNAL') || die();

use local_kbacompany\local\design_service;
use local_kbacompany\local\identity_registry;
use local_kbacorporate\local\repository;

/** Organization design optimistic-apply regression tests. */
final class design_service_test extends \advanced_testcase {
    private function fixture(): array {
        global $DB, $USER;
        $this->resetAfterTest();
        $this->setAdminUser();
        $actor = (int)$USER->id;
        $company = identity_registry::company_uuid(repository::save_company((object)[
            'name' => 'Design conflict', 'active' => 1,
        ]));
        $branch = '31000000-0000-4000-8000-000000000001';
        $node = '31000000-0000-4000-8000-000000000002';
        $now = time();
        $DB->insert_record('local_kbacompany_orgbranch', (object)[
            'companyuuid' => $company, 'uuid' => $branch, 'code' => 'MAIN', 'name' => 'Main',
            'isdefault' => 1, 'active' => 1, 'sortorder' => 10,
            'timecreated' => $now, 'timemodified' => $now,
        ]);
        $DB->insert_record('local_kbacompany_orgnode', (object)[
            'companyuuid' => $company, 'branchuuid' => $branch, 'uuid' => $node, 'parentuuid' => '',
            'code' => 'EXEC', 'nodetype' => 'leadership', 'name' => 'Executive office',
            'sortorder' => 10, 'color' => '#173B6C', 'sourcecomponent' => '', 'sourceentity' => '',
            'sourceid' => 0, 'active' => 1, 'timecreated' => $now, 'timemodified' => $now,
        ]);
        return compact('company', 'actor', 'branch', 'node');
    }

    /** Create a reviewable one-node design captured from the current live organization. */
    private function approved_design(array $f): string {
        $uuid = design_service::command($f['company'], $f['actor'], 'create', [
            'source' => 'live', 'name' => 'Target organization',
        ]);
        $payload = json_decode(design_service::version($f['company'], $f['actor'], $uuid, 1)->payload, true);
        $nodeuuid = (string)$payload['nodes'][0]['uuid'];
        design_service::command($f['company'], $f['actor'], 'position', [
            'object' => $uuid, 'revision' => 1, 'item' => '', 'targetnode' => $nodeuuid,
            'code' => 'EXEC-P01', 'name' => 'Executive director', 'plannedfte' => '1', 'managerial' => 1,
        ]);
        design_service::command($f['company'], $f['actor'], 'submit', [
            'object' => $uuid, 'revision' => 2, 'reason' => 'Ready for review',
        ]);
        design_service::command($f['company'], $f['actor'], 'approve', [
            'object' => $uuid, 'revision' => 3, 'reason' => 'Approved',
        ]);
        return $uuid;
    }

    public function test_first_department_stays_in_design_until_approved_apply(): void {
        global $DB, $USER;
        $this->resetAfterTest();
        $this->setAdminUser();
        $actor = (int)$USER->id;
        $company = identity_registry::company_uuid(repository::save_company((object)['name' => 'Factory', 'active' => 1]));
        $scope = ['companyuuid' => $company];
        $draft = design_service::command($company, $actor, 'create', ['source' => 'firstunit', 'name' => 'Production']);
        $payload = json_decode(design_service::version($company, $actor, $draft, 1)->payload, true);
        $this->assertCount(1, $payload['nodes']);
        $node = $payload['nodes'][0]['uuid'];
        design_service::command($company, $actor, 'position', ['object' => $draft, 'revision' => 1,
            'targetnode' => $node, 'code' => 'P01', 'name' => 'Manager', 'plannedfte' => '1', 'managerial' => 1]);
        $payload = json_decode(design_service::version($company, $actor, $draft, 2)->payload, true);
        design_service::command($company, $actor, 'function', ['object' => $draft, 'revision' => 2,
            'targetnode' => $node, 'code' => 'F01', 'name' => 'Plan', 'result' => 'Schedule',
            'position' => $payload['positions'][0]['uuid']]);
        design_service::command($company, $actor, 'submit', ['object' => $draft, 'revision' => 3, 'reason' => 'Reviewed']);
        design_service::command($company, $actor, 'approve', ['object' => $draft, 'revision' => 4, 'reason' => 'Approved']);
        $this->assertFalse($DB->record_exists('local_kbacompany_orgbranch', $scope));
        $this->assertFalse($DB->record_exists('local_kbacompany_orgnode', $scope));
        design_service::command($company, $actor, 'apply', ['object' => $draft, 'revision' => 5,
            'branch' => 'create-main', 'reason' => 'Open first department']);
        $branch = $DB->get_record('local_kbacompany_orgbranch', $scope, '*', MUST_EXIST);
        $unit = $DB->get_record('local_kbacompany_orgnode', $scope, '*', MUST_EXIST);
        $this->assertSame($branch->uuid, $unit->branchuuid);
        $this->assertSame('Production', $unit->name);
        $this->assertEquals(1, $unit->active);
        $this->expectException(\moodle_exception::class);
        $this->expectExceptionMessage(get_string('design_firstunitexists', 'local_kbacompany'));
        design_service::command($company, $actor, 'create', ['source' => 'firstunit', 'name' => 'Duplicate']);
    }

    public function test_apply_rejects_live_change_after_capture(): void {
        global $DB;
        $f = $this->fixture();
        $design = $this->approved_design($f);

        $live = $DB->get_record('local_kbacompany_orgnode', [
            'companyuuid' => $f['company'], 'uuid' => $f['node'],
        ], '*', MUST_EXIST);
        $live->name = 'Changed outside design';
        $live->timemodified = time() + 2;
        $DB->update_record('local_kbacompany_orgnode', $live);

        $this->expectException(\moodle_exception::class);
        $this->expectExceptionMessage(get_string('design_applyconflict', 'local_kbacompany'));
        design_service::command($f['company'], $f['actor'], 'apply', [
            'object' => $design, 'revision' => 4, 'branch' => $f['branch'], 'reason' => 'Apply target',
        ]);
    }

    public function test_apply_unchanged_live_node_succeeds_and_refreshes_fingerprint(): void {
        global $DB;
        $f = $this->fixture();
        $design = $this->approved_design($f);

        design_service::command($f['company'], $f['actor'], 'apply', [
            'object' => $design, 'revision' => 4, 'branch' => $f['branch'], 'reason' => 'Apply target',
        ]);

        $project = $DB->get_record('local_kbacompany_design', [
            'companyuuid' => $f['company'], 'uuid' => $design,
        ], '*', MUST_EXIST);
        $this->assertSame('applied', $project->status);
        $this->assertSame(5, (int)$project->revision);
        $payload = json_decode(design_service::version($f['company'], $f['actor'], $design, 5)->payload, true);
        $this->assertMatchesRegularExpression('/^[0-9a-f]{64}$/', (string)$payload['nodes'][0]['sourcehash']);
    }
}
