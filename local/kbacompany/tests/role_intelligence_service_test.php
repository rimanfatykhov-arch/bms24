<?php
namespace local_kbacompany;

defined('MOODLE_INTERNAL') || die();

use local_kbacompany\local\identity_registry;
use local_kbacompany\local\organizing_board_service;
use local_kbacompany\local\position_service;
use local_kbacompany\local\repository;
use local_kbacompany\local\role_intelligence_service;

class role_intelligence_service_test extends \advanced_testcase {
    public function test_contract_preserves_boundaries(): void {
        $contract = role_intelligence_service::contract();
        $this->assertSame('0.1.0', $contract['version']);
        $this->assertTrue($contract['read_only']);
        $this->assertSame('required_before_fetch', $contract['company_scope']);
        $this->assertContains('no_duplicate_role_catalogue', $contract['rules']);
        $this->assertContains('no_employee_or_competency_mutation', $contract['rules']);
    }

    public function test_revision_selection_and_missing_links_are_deterministic(): void {
        $positions = [
            (object)['uuid' => 'position-a', 'code' => 'P-A', 'name' => 'Manager',
                'purpose' => 'Own accepted result'],
            (object)['uuid' => 'position-b', 'code' => 'P-B', 'name' => 'Specialist',
                'purpose' => '  '],
        ];
        $functions = [
            (object)['uuid' => 'function-1', 'revision' => 1, 'status' => 'published',
                'positionuuid' => 'position-a'],
            // A newer draft does not erase a published function.
            (object)['uuid' => 'function-1', 'revision' => 2, 'status' => 'draft',
                'positionuuid' => 'position-a'],
            (object)['uuid' => 'function-2', 'revision' => 1, 'status' => 'published',
                'positionuuid' => 'position-b'],
            // The latest archived revision removes this function.
            (object)['uuid' => 'function-2', 'revision' => 2, 'status' => 'archived',
                'positionuuid' => 'position-b'],
        ];
        $profiles = [
            (object)['uuid' => 'dna-1', 'revision' => 1, 'status' => 'published',
                'positionuuid' => 'position-a'],
            (object)['uuid' => 'dna-1', 'revision' => 2, 'status' => 'draft',
                'positionuuid' => 'position-a'],
            (object)['uuid' => 'dna-2', 'revision' => 1, 'status' => 'draft',
                'positionuuid' => 'position-b'],
        ];

        $result = role_intelligence_service::summarise($positions, $functions, $profiles);
        $this->assertSame([
            'positions' => 2,
            'basic_links_present' => 1,
            'needs_description' => 1,
            'missing_purpose' => 1,
            'missing_published_functions' => 1,
            'missing_published_role_dna' => 1,
        ], $result['summary']);
        $this->assertSame('basic_links_present', $result['positions'][0]['design_status']);
        $this->assertSame(1, $result['positions'][0]['published_function_count']);
        $this->assertSame(1, $result['positions'][0]['published_role_dna_count']);
        $this->assertSame('needs_description', $result['positions'][1]['design_status']);
        $this->assertSame([
            'purpose_missing', 'published_functions_missing', 'published_role_dna_missing',
        ], $result['positions'][1]['gaps']);
    }

    public function test_snapshot_does_not_include_another_company_positions(): void {
        global $USER;
        $this->resetAfterTest();
        $this->setAdminUser();
        $actor = (int)$USER->id;

        $first = identity_registry::company_uuid(repository::save_company(
            (object)['name' => 'Readiness A', 'active' => 1]));
        $other = identity_registry::company_uuid(repository::save_company(
            (object)['name' => 'Readiness B', 'active' => 1]));
        $boarda = organizing_board_service::install_seven_division_template($first, $actor);
        $boardb = organizing_board_service::install_seven_division_template($other, $actor);
        $positiona = position_service::create_position($first, $actor, [
            'orgnodeuuid' => $boarda['nodes'][0]['uuid'], 'code' => 'ROLE-A',
            'name' => 'Position in A', 'purpose' => '',
        ]);
        position_service::create_position($other, $actor, [
            'orgnodeuuid' => $boardb['nodes'][0]['uuid'], 'code' => 'ROLE-B',
            'name' => 'Position in B', 'purpose' => 'Foreign position',
        ]);

        $result = role_intelligence_service::snapshot($first, $actor);
        $this->assertSame($first, $result['company_uuid']);
        $this->assertSame(1, $result['summary']['positions']);
        $this->assertSame($positiona, $result['positions'][0]['uuid']);
        $this->assertSame('ROLE-A', $result['positions'][0]['code']);
    }

    public function test_company_outsider_cannot_read_snapshot(): void {
        global $USER;
        $this->resetAfterTest();
        $this->setAdminUser();
        $company = identity_registry::company_uuid(repository::save_company(
            (object)['name' => 'Protected readiness', 'active' => 1]));
        $outsider = $this->getDataGenerator()->create_user();
        $this->setUser($outsider);
        $this->expectException(\moodle_exception::class);
        role_intelligence_service::snapshot($company, (int)$USER->id);
    }
}
