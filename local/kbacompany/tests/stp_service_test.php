<?php
namespace local_kbacompany;

defined('MOODLE_INTERNAL') || die();

use local_kbacompany\local\identity_registry;
use local_kbacompany\local\organizing_board_service;
use local_kbacompany\local\position_service;
use local_kbacompany\local\process_model_service;
use local_kbacompany\local\process_service;
use local_kbacompany\local\product_service;
use local_kbacompany\local\stp_service;
use local_kbacorporate\local\repository;

/** Generated STP and RACI regression tests. */
final class stp_service_test extends \advanced_testcase {
    private function fixture(string $name = 'STP company'): array {
        global $USER;
        $this->resetAfterTest();
        $this->setAdminUser();
        $actor = (int)$USER->id;
        $company = identity_registry::company_uuid(repository::save_company((object)['name' => $name, 'active' => 1]));
        $board = organizing_board_service::install_seven_division_template($company, $actor);
        $product = product_service::create_product($company, $actor, [
            'code' => 'CKP-STP', 'name' => 'Accepted paid order', 'acceptance' => 'Customer paid and order accepted',
            'targettype' => 'node', 'targetuuid' => $board['nodes'][0]['uuid'],
        ]);
        $executor = position_service::create_position($company, $actor, [
            'orgnodeuuid' => $board['nodes'][0]['uuid'], 'code' => 'EXEC', 'name' => 'Process executor',
            'purpose' => 'Produce the activity product', 'ismanager' => 0, 'plannedcount' => 1,
        ]);
        $accountable = position_service::create_position($company, $actor, [
            'orgnodeuuid' => $board['nodes'][0]['uuid'], 'code' => 'OWNER', 'name' => 'Process accountable',
            'purpose' => 'Own the activity result', 'ismanager' => 1, 'plannedcount' => 1,
        ]);
        $process = process_service::save($company, $actor, [
            'code' => 'P-STP', 'name' => 'STP test process', 'purpose' => 'Produce controlled process output',
            'processtype' => 'core', 'processlevel' => 'L0', 'parentuuid' => '',
            'ownerorgunituuid' => $board['nodes'][0]['uuid'], 'productuuid' => $product,
        ]);
        process_service::publish($company, $actor, $process, 1);
        process_service::link($company, $actor, $process, 'supplier', $board['nodes'][1]['uuid']);
        process_service::link($company, $actor, $process, 'customer', $board['nodes'][2]['uuid']);
        return compact('actor', 'company', 'board', 'product', 'executor', 'accountable', 'process');
    }

    private function model(array $f): array {
        return process_model_service::context($f['company'], $f['actor'], $f['process']);
    }

    private function build(array $f): array {
        $modelid = process_model_service::ensure_draft($f['company'], $f['actor'], $f['process']);
        $ctx = $this->model($f);
        process_model_service::save_element($f['company'], $f['actor'], $f['process'], $modelid,
            $ctx['model']['change_version'], ['elementtype' => 'input', 'name' => 'Approved request']);
        $ctx = $this->model($f);
        $start = process_model_service::save_element($f['company'], $f['actor'], $f['process'], $modelid,
            $ctx['model']['change_version'], ['elementtype' => 'start', 'name' => 'Request accepted']);
        $ctx = $this->model($f);
        $task = process_model_service::save_element($f['company'], $f['actor'], $f['process'], $modelid,
            $ctx['model']['change_version'], [
                'elementtype' => 'task', 'name' => 'Execute work', 'description' => 'Perform the controlled work',
                'executortype' => 'position', 'executoruuid' => $f['executor'], 'outputtext' => 'Controlled result',
            ]);
        $ctx = $this->model($f);
        $end = process_model_service::save_element($f['company'], $f['actor'], $f['process'], $modelid,
            $ctx['model']['change_version'], ['elementtype' => 'end', 'name' => 'Result accepted']);
        $ctx = $this->model($f);
        process_model_service::save_flow($f['company'], $f['actor'], $f['process'], $modelid,
            $ctx['model']['change_version'], ['fromuuid' => $start, 'touuid' => $task]);
        $ctx = $this->model($f);
        process_model_service::save_flow($f['company'], $f['actor'], $f['process'], $modelid,
            $ctx['model']['change_version'], ['fromuuid' => $task, 'touuid' => $end]);
        return compact('modelid', 'start', 'task', 'end');
    }

    public function test_stp_is_generated_and_responsible_derives_from_task_executor(): void {
        $f = $this->fixture();
        $built = $this->build($f);
        $ctx = $this->model($f);
        process_model_service::save_raci($f['company'], $f['actor'], $f['process'], $built['modelid'],
            $ctx['model']['change_version'], $built['task'], 'position', $f['accountable'], 'A');
        $ctx = $this->model($f);
        process_model_service::save_element($f['company'], $f['actor'], $f['process'], $built['modelid'],
            $ctx['model']['change_version'], [
                'elementtype' => 'resource', 'name' => 'CRM access', 'activityuuid' => $built['task'],
            ]);
        $ctx = $this->model($f);
        process_model_service::save_element($f['company'], $f['actor'], $f['process'], $built['modelid'],
            $ctx['model']['change_version'], [
                'elementtype' => 'record', 'name' => 'Acceptance record', 'activityuuid' => $built['task'],
            ]);
        $stp = stp_service::context($f['company'], $f['actor'], $f['process']);
        $this->assertSame('0.3.1', stp_service::contract()['version']);
        $this->assertTrue($stp['document']['generated']);
        $this->assertFalse($stp['document']['controlled_document']);
        $this->assertCount(1, $stp['steps']);
        $this->assertContains('Process executor', $stp['steps'][0]['raci']['R']);
        $this->assertContains('Process accountable', $stp['steps'][0]['raci']['A']);
        $this->assertSame('CRM access', $stp['steps'][0]['resources'][0]['name']);
        $this->assertSame('Acceptance record', $stp['steps'][0]['records'][0]['name']);
    }

    public function test_duplicate_raci_and_foreign_company_party_are_rejected(): void {
        $f = $this->fixture('RACI validation company');
        $built = $this->build($f);
        $ctx = $this->model($f);
        process_model_service::save_raci($f['company'], $f['actor'], $f['process'], $built['modelid'],
            $ctx['model']['change_version'], $built['task'], 'position', $f['accountable'], 'C');
        $ctx = $this->model($f);
        try {
            process_model_service::save_raci($f['company'], $f['actor'], $f['process'], $built['modelid'],
                $ctx['model']['change_version'], $built['task'], 'position', $f['accountable'], 'C');
            $this->fail('Duplicate RACI assignment must be rejected.');
        } catch (\moodle_exception $e) {
            $this->assertSame('procmodel_raciduplicate', $e->errorcode);
        }

        $othercompany = identity_registry::company_uuid(repository::save_company((object)['name' => 'Foreign company', 'active' => 1]));
        $otherboard = organizing_board_service::install_seven_division_template($othercompany, $f['actor']);
        $foreignposition = position_service::create_position($othercompany, $f['actor'], [
            'orgnodeuuid' => $otherboard['nodes'][0]['uuid'], 'code' => 'FOREIGN', 'name' => 'Foreign position',
            'purpose' => 'Foreign', 'ismanager' => 0, 'plannedcount' => 1,
        ]);
        $ctx = $this->model($f);
        try {
            process_model_service::save_raci($f['company'], $f['actor'], $f['process'], $built['modelid'],
                $ctx['model']['change_version'], $built['task'], 'position', $foreignposition, 'I');
            $this->fail('Foreign company participant must be rejected.');
        } catch (\moodle_exception $e) {
            $this->assertSame('procmodel_invalidexecutor', $e->errorcode);
        }
    }

    public function test_published_model_blocks_raci_change_and_new_draft_clones_raci(): void {
        $f = $this->fixture('RACI version company');
        $built = $this->build($f);
        $ctx = $this->model($f);
        $raciuuid = process_model_service::save_raci($f['company'], $f['actor'], $f['process'], $built['modelid'],
            $ctx['model']['change_version'], $built['task'], 'position', $f['accountable'], 'A');
        $ctx = $this->model($f);
        process_model_service::publish($f['company'], $f['actor'], $f['process'], $built['modelid'],
            $ctx['model']['change_version']);
        $published = $this->model($f);
        try {
            process_model_service::remove_raci($f['company'], $f['actor'], $f['process'], $built['modelid'],
                $published['model']['change_version'], $raciuuid);
            $this->fail('Published model must be immutable.');
        } catch (\moodle_exception $e) {
            $this->assertSame('procmodel_immutable', $e->errorcode);
        }
        process_model_service::ensure_draft($f['company'], $f['actor'], $f['process']);
        $draft = $this->model($f);
        $this->assertSame('draft', $draft['model']['status']);
        $this->assertCount(1, $draft['raci']);
        $this->assertSame($raciuuid, $draft['raci'][0]['uuid']);
        $this->assertSame($built['task'], $draft['raci'][0]['activity_uuid']);
    }

    public function test_stp_is_projection_and_has_no_duplicate_stp_table(): void {
        global $DB;
        $f = $this->fixture('Projection company');
        $this->build($f);
        $this->assertFalse($DB->get_manager()->table_exists(new \xmldb_table('local_kbacompany_stp')));
        $stp = stp_service::context($f['company'], $f['actor'], $f['process']);
        $this->assertStringStartsWith('STP-', $stp['document']['code']);
    }
}
