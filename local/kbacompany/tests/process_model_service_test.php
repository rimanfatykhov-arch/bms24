<?php
namespace local_kbacompany;

defined('MOODLE_INTERNAL') || die();

use local_kbacompany\local\identity_registry;
use local_kbacompany\local\organizing_board_service;
use local_kbacompany\local\position_service;
use local_kbacompany\local\process_model_service;
use local_kbacompany\local\process_service;
use local_kbacompany\local\product_service;
use local_kbacompany\local\semantic_service;
use local_kbacorporate\local\repository;

/** SIPOC + BPMN process model regression tests. */
final class process_model_service_test extends \advanced_testcase {
    private function fixture(string $name = 'Process model company'): array {
        global $USER;
        $this->resetAfterTest();
        $this->setAdminUser();
        $actor = (int)$USER->id;
        $company = identity_registry::company_uuid(repository::save_company((object)['name' => $name, 'active' => 1]));
        $board = organizing_board_service::install_seven_division_template($company, $actor);
        $product = product_service::create_product($company, $actor, [
            'code' => 'CKP-MODEL', 'name' => 'Paid customer order', 'acceptance' => 'Payment received',
            'targettype' => 'node', 'targetuuid' => $board['nodes'][0]['uuid'],
        ]);
        $position = position_service::create_position($company, $actor, [
            'orgnodeuuid' => $board['nodes'][0]['uuid'], 'code' => 'SALES-MGR', 'name' => 'Sales manager',
            'purpose' => 'Produce paid orders', 'ismanager' => 0, 'plannedcount' => 1,
        ]);
        $process = process_service::save($company, $actor, [
            'code' => 'SALE', 'name' => 'Sell to customer', 'purpose' => 'Convert demand to paid order',
            'processtype' => 'core', 'processlevel' => 'L0', 'parentuuid' => '',
            'ownerorgunituuid' => $board['nodes'][0]['uuid'], 'productuuid' => $product,
        ]);
        process_service::publish($company, $actor, $process, 1);
        process_service::link($company, $actor, $process, 'supplier', $board['nodes'][1]['uuid']);
        process_service::link($company, $actor, $process, 'customer', $board['nodes'][2]['uuid']);
        return compact('actor', 'company', 'board', 'product', 'position', 'process');
    }

    private function cv(array $f): array {
        $ctx = process_model_service::context($f['company'], $f['actor'], $f['process']);
        return [$ctx['model']['id'], $ctx['model']['change_version']];
    }

    private function build_valid_model(array $f): array {
        $modelid = process_model_service::ensure_draft($f['company'], $f['actor'], $f['process']);
        [$modelid, $cv] = $this->cv($f);
        process_model_service::save_element($f['company'], $f['actor'], $f['process'], $modelid, $cv, [
            'elementtype' => 'input', 'name' => 'Qualified lead', 'description' => 'Lead ready for sales',
        ]);
        [$modelid, $cv] = $this->cv($f);
        $start = process_model_service::save_element($f['company'], $f['actor'], $f['process'], $modelid, $cv, [
            'elementtype' => 'start', 'name' => 'Lead accepted',
        ]);
        [$modelid, $cv] = $this->cv($f);
        $task = process_model_service::save_element($f['company'], $f['actor'], $f['process'], $modelid, $cv, [
            'elementtype' => 'task', 'name' => 'Qualify customer',
            'description' => 'Clarify need and confirm qualification', 'executortype' => 'position',
            'executoruuid' => $f['position'], 'outputtext' => 'Qualified customer',
        ]);
        [$modelid, $cv] = $this->cv($f);
        $end = process_model_service::save_element($f['company'], $f['actor'], $f['process'], $modelid, $cv, [
            'elementtype' => 'end', 'name' => 'Order produced',
        ]);
        [$modelid, $cv] = $this->cv($f);
        process_model_service::save_flow($f['company'], $f['actor'], $f['process'], $modelid, $cv, [
            'fromuuid' => $start, 'touuid' => $task,
        ]);
        [$modelid, $cv] = $this->cv($f);
        process_model_service::save_flow($f['company'], $f['actor'], $f['process'], $modelid, $cv, [
            'fromuuid' => $task, 'touuid' => $end,
        ]);
        return compact('modelid', 'start', 'task', 'end');
    }

    public function test_complete_sipoc_bpmn_model_publishes_and_activity_becomes_semantic(): void {
        global $DB;
        $f = $this->fixture();
        $built = $this->build_valid_model($f);
        $ctx = process_model_service::context($f['company'], $f['actor'], $f['process']);
        $this->assertSame([], $ctx['publication_issues']);
        $this->assertSame('0.4.4', process_model_service::contract()['version']);
        process_model_service::publish($f['company'], $f['actor'], $f['process'],
            $ctx['model']['id'], $ctx['model']['change_version']);
        $ctx = process_model_service::context($f['company'], $f['actor'], $f['process']);
        $this->assertSame('published', $ctx['model']['status']);
        $this->assertCount(1, $ctx['sipoc']['inputs']);
        $this->assertCount(3, $ctx['bpmn']['nodes']);
        $this->assertCount(2, $ctx['bpmn']['flows']);
        $activity = process_model_service::semantic_activity($f['company'], $f['actor'], $built['task']);
        $this->assertSame('activity', $activity['type']);
        $this->assertSame('published', $activity['status']);
        $semantic = semantic_service::contract();
        $this->assertSame('0.3.3', $semantic['version']);
        $this->assertSame('current', $semantic['objects']['activity']['availability']);
        $this->assertSame(3, $DB->count_records('local_kbacompany_procelem', ['modelid' => $built['modelid']]) - 1 + 1);
    }

    public function test_published_model_is_immutable_and_new_draft_clones_stable_activity_uuid(): void {
        $f = $this->fixture('Clone model');
        $built = $this->build_valid_model($f);
        $ctx = process_model_service::context($f['company'], $f['actor'], $f['process']);
        process_model_service::publish($f['company'], $f['actor'], $f['process'],
            $ctx['model']['id'], $ctx['model']['change_version']);
        $publishedid = $ctx['model']['id'];
        try {
            process_model_service::save_metadata($f['company'], $f['actor'], $f['process'], $publishedid,
                $ctx['model']['change_version'], ['scope' => 'illegal']);
            $this->fail('Published model must be immutable.');
        } catch (\moodle_exception $e) {
            $this->assertSame(get_string('procmodel_immutable', 'local_kbacompany'), $e->getMessage());
        }
        $newmodelid = process_model_service::ensure_draft($f['company'], $f['actor'], $f['process']);
        $ctx = process_model_service::context($f['company'], $f['actor'], $f['process']);
        $this->assertSame(2, $ctx['model']['revision']);
        $this->assertNotSame($publishedid, $newmodelid);
        $tasks = array_values(array_filter($ctx['bpmn']['nodes'], static function(array $node): bool {
            return $node['type'] === 'task';
        }));
        $this->assertSame($built['task'], $tasks[0]['uuid']);
    }

    public function test_publish_rejects_disconnected_bpmn(): void {
        $f = $this->fixture('Disconnected model');
        $this->build_valid_model($f);
        [$modelid, $cv] = $this->cv($f);
        process_model_service::save_element($f['company'], $f['actor'], $f['process'], $modelid, $cv, [
            'elementtype' => 'event', 'name' => 'Disconnected event',
        ]);
        $ctx = process_model_service::context($f['company'], $f['actor'], $f['process']);
        $this->assertContains('reachability', $ctx['publication_issues']);
        $this->expectException(\moodle_exception::class);
        process_model_service::publish($f['company'], $f['actor'], $f['process'],
            $ctx['model']['id'], $ctx['model']['change_version']);
    }

    public function test_task_rejects_executor_from_another_company(): void {
        $f = $this->fixture('Company one');
        $modelid = process_model_service::ensure_draft($f['company'], $f['actor'], $f['process']);
        $other = identity_registry::company_uuid(repository::save_company((object)['name' => 'Company two', 'active' => 1]));
        $board2 = organizing_board_service::install_seven_division_template($other, $f['actor']);
        $foreignposition = position_service::create_position($other, $f['actor'], [
            'orgnodeuuid' => $board2['nodes'][0]['uuid'], 'code' => 'FOREIGN', 'name' => 'Foreign position',
            'purpose' => 'Other company', 'plannedcount' => 1,
        ]);
        $ctx = process_model_service::context($f['company'], $f['actor'], $f['process']);
        $this->expectException(\moodle_exception::class);
        $this->expectExceptionMessage(get_string('procmodel_invalidexecutor', 'local_kbacompany'));
        process_model_service::save_element($f['company'], $f['actor'], $f['process'], $modelid,
            $ctx['model']['change_version'], [
                'elementtype' => 'task', 'name' => 'Bad task', 'description' => 'Work',
                'executortype' => 'position', 'executoruuid' => $foreignposition, 'outputtext' => 'Result',
            ]);
    }
}
