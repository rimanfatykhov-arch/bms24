<?php
namespace local_kbacompany;

defined('MOODLE_INTERNAL') || die();

use local_kbacompany\local\function_service;
use local_kbacompany\local\identity_registry;
use local_kbacompany\local\organizing_board_service;
use local_kbacompany\local\position_service;
use local_kbacompany\local\process_model_service;
use local_kbacompany\local\process_service;
use local_kbacompany\local\product_service;
use local_kbacompany\local\semantic_service;
use local_kbacorporate\local\repository;

/** Process → Activity → Function → Position regression tests. */
final class function_service_test extends \advanced_testcase {
    private function fixture(string $name = 'Function company'): array {
        global $USER;
        $this->resetAfterTest();
        $this->setAdminUser();
        $actor = (int)$USER->id;
        $company = identity_registry::company_uuid(repository::save_company((object)['name' => $name, 'active' => 1]));
        $board = organizing_board_service::install_seven_division_template($company, $actor);
        $product = product_service::create_product($company, $actor, [
            'code' => 'CKP-FN', 'name' => 'Accepted function product', 'acceptance' => 'Result accepted',
            'targettype' => 'node', 'targetuuid' => $board['nodes'][0]['uuid'],
        ]);
        $position = position_service::create_position($company, $actor, [
            'orgnodeuuid' => $board['nodes'][0]['uuid'], 'code' => 'FN-POS', 'name' => 'Function owner position',
            'purpose' => 'Produce the function product', 'ismanager' => 0, 'plannedcount' => 1,
        ]);
        $process = process_service::save($company, $actor, [
            'code' => 'P-FN', 'name' => 'Function source process', 'purpose' => 'Create a business result',
            'processtype' => 'core', 'processlevel' => 'L0', 'parentuuid' => '',
            'ownerorgunituuid' => $board['nodes'][0]['uuid'], 'productuuid' => $product,
        ]);
        process_service::publish($company, $actor, $process, 1);
        process_service::link($company, $actor, $process, 'supplier', $board['nodes'][1]['uuid']);
        process_service::link($company, $actor, $process, 'customer', $board['nodes'][2]['uuid']);
        return compact('actor', 'company', 'board', 'product', 'position', 'process');
    }

    private function build_process_model(array $f, string $executortype = 'position', string $executoruuid = ''): array {
        $executoruuid = $executoruuid ?: $f['position'];
        $modelid = process_model_service::ensure_draft($f['company'], $f['actor'], $f['process']);
        $ctx = process_model_service::context($f['company'], $f['actor'], $f['process']);
        process_model_service::save_element($f['company'], $f['actor'], $f['process'], $modelid,
            $ctx['model']['change_version'], ['elementtype' => 'input', 'name' => 'Required input']);
        $ctx = process_model_service::context($f['company'], $f['actor'], $f['process']);
        $start = process_model_service::save_element($f['company'], $f['actor'], $f['process'], $modelid,
            $ctx['model']['change_version'], ['elementtype' => 'start', 'name' => 'Start']);
        $ctx = process_model_service::context($f['company'], $f['actor'], $f['process']);
        $task = process_model_service::save_element($f['company'], $f['actor'], $f['process'], $modelid,
            $ctx['model']['change_version'], [
                'elementtype' => 'task', 'code' => 'A-1', 'name' => 'Produce result',
                'description' => 'Perform the activity that creates the result',
                'executortype' => $executortype, 'executoruuid' => $executoruuid,
                'productuuid' => $f['product'], 'outputtext' => '',
            ]);
        $ctx = process_model_service::context($f['company'], $f['actor'], $f['process']);
        $end = process_model_service::save_element($f['company'], $f['actor'], $f['process'], $modelid,
            $ctx['model']['change_version'], ['elementtype' => 'end', 'name' => 'End']);
        $ctx = process_model_service::context($f['company'], $f['actor'], $f['process']);
        process_model_service::save_flow($f['company'], $f['actor'], $f['process'], $modelid,
            $ctx['model']['change_version'], ['fromuuid' => $start, 'touuid' => $task]);
        $ctx = process_model_service::context($f['company'], $f['actor'], $f['process']);
        process_model_service::save_flow($f['company'], $f['actor'], $f['process'], $modelid,
            $ctx['model']['change_version'], ['fromuuid' => $task, 'touuid' => $end]);
        return compact('modelid', 'task');
    }

    private function publish_model(array $f): void {
        $ctx = process_model_service::context($f['company'], $f['actor'], $f['process']);
        process_model_service::publish($f['company'], $f['actor'], $f['process'],
            $ctx['model']['id'], $ctx['model']['change_version']);
    }

    public function test_activity_creates_canonical_function_and_position_trace(): void {
        $f = $this->fixture();
        $m = $this->build_process_model($f);
        $functionuuid = function_service::create_from_activity($f['company'], $f['actor'], $f['process'], $m['task'], [
            'code' => 'FN-SALES', 'name' => 'Produce accepted result', 'purpose' => 'Own the stable result responsibility',
        ]);
        $draft = function_service::context($f['company'], $f['actor'], $functionuuid)['selected'];
        $this->assertSame($m['task'], $draft['source_activity_uuid']);
        $this->assertSame($f['position'], $draft['position_uuid']);
        $this->assertSame($f['product'], $draft['product_uuid']);
        $this->assertSame('draft', $draft['status']);
        try {
            function_service::publish($f['company'], $f['actor'], $functionuuid, $draft['revision']);
            $this->fail('Function must not publish before its source Process Model revision is published.');
        } catch (\moodle_exception $e) {
            $this->assertSame('function_sourcenotpublished', $e->errorcode);
        }
        $this->publish_model($f);
        $draft = function_service::context($f['company'], $f['actor'], $functionuuid)['selected'];
        function_service::publish($f['company'], $f['actor'], $functionuuid, $draft['revision']);
        $published = function_service::context($f['company'], $f['actor'], $functionuuid)['selected'];
        $this->assertSame('published', $published['status']);
        $this->assertSame('0.3.2', function_service::contract()['version']);

        $semantic = semantic_service::context($f['company'], $f['actor']);
        $matches = array_values(array_filter($semantic['objects'], static function(array $object) use ($functionuuid): bool {
            return $object['type'] === 'function' && $object['uuid'] === $functionuuid;
        }));
        $this->assertCount(1, $matches);
        $this->assertSame('0.3.3', semantic_service::contract()['version']);
    }

    public function test_duplicate_source_and_cross_company_position_are_rejected(): void {
        $f = $this->fixture('Duplicate source company');
        $m = $this->build_process_model($f);
        function_service::create_from_activity($f['company'], $f['actor'], $f['process'], $m['task'], [
            'code' => 'FN-ONE', 'name' => 'First Function',
        ]);
        try {
            function_service::create_from_activity($f['company'], $f['actor'], $f['process'], $m['task'], [
                'code' => 'FN-TWO', 'name' => 'Second Function',
            ]);
            $this->fail('One active canonical Function per source Task is required in v0.3.2.');
        } catch (\moodle_exception $e) {
            $this->assertSame('function_duplicatesource', $e->errorcode);
        }

        $othercompany = identity_registry::company_uuid(repository::save_company((object)['name' => 'Foreign position company', 'active' => 1]));
        $otherboard = organizing_board_service::install_seven_division_template($othercompany, $f['actor']);
        $foreignposition = position_service::create_position($othercompany, $f['actor'], [
            'orgnodeuuid' => $otherboard['nodes'][0]['uuid'], 'code' => 'F-POS', 'name' => 'Foreign Position',
            'purpose' => 'Foreign', 'ismanager' => 0, 'plannedcount' => 1,
        ]);
        $ctx = function_service::context($f['company'], $f['actor']);
        $function = $ctx['functions'][0];
        try {
            function_service::save($f['company'], $f['actor'], $function['uuid'], $function['revision'], [
                'code' => $function['code'], 'name' => $function['name'], 'purpose' => $function['purpose'],
                'positionuuid' => $foreignposition, 'productuuid' => $function['product_uuid'],
                'producttext' => $function['product_text'],
            ]);
            $this->fail('Cross-company Position must be rejected.');
        } catch (\moodle_exception $e) {
            $this->assertSame('function_invalidposition', $e->errorcode);
        }
    }

    public function test_function_detects_source_change_and_refreshes_explicitly(): void {
        $f = $this->fixture('Impact seed company');
        $m = $this->build_process_model($f);
        $functionuuid = function_service::create_from_activity($f['company'], $f['actor'], $f['process'], $m['task'], [
            'code' => 'FN-IMPACT', 'name' => 'Stable Function',
        ]);
        $this->publish_model($f);
        $draft = function_service::context($f['company'], $f['actor'], $functionuuid)['selected'];
        function_service::publish($f['company'], $f['actor'], $functionuuid, $draft['revision']);

        process_model_service::ensure_draft($f['company'], $f['actor'], $f['process']);
        $ctx = process_model_service::context($f['company'], $f['actor'], $f['process']);
        $task = null;
        foreach ($ctx['bpmn']['nodes'] as $node) { if ($node['uuid'] === $m['task']) { $task = $node; break; } }
        $this->assertNotNull($task);
        process_model_service::save_element($f['company'], $f['actor'], $f['process'], $ctx['model']['id'],
            $ctx['model']['change_version'], [
                'elementtype' => 'task', 'code' => $task['code'], 'name' => $task['name'],
                'description' => 'Changed source action', 'executortype' => $task['executor_type'],
                'executoruuid' => $task['executor_uuid'], 'laneuuid' => $task['lane_uuid'],
                'productuuid' => $task['product_uuid'], 'outputtext' => $task['output'],
                'sortorder' => $task['sort_order'],
            ], $task['uuid']);
        $function = function_service::context($f['company'], $f['actor'], $functionuuid)['selected'];
        $this->assertTrue($function['source_outdated']);
        $oldproduct = $function['product_name'];
        function_service::refresh_source($f['company'], $f['actor'], $functionuuid, $function['revision']);
        $refreshed = function_service::context($f['company'], $f['actor'], $functionuuid)['selected'];
        $this->assertFalse($refreshed['source_outdated']);
        $this->assertSame('Changed source action', $refreshed['source_action']);
        $this->assertSame($oldproduct, $refreshed['product_name']);
    }

    public function test_non_position_executor_requires_manual_position_before_publish(): void {
        $f = $this->fixture('Manual position company');
        $m = $this->build_process_model($f, 'orgunit', $f['board']['nodes'][0]['uuid']);
        $functionuuid = function_service::create_from_activity($f['company'], $f['actor'], $f['process'], $m['task'], [
            'code' => 'FN-MANUAL', 'name' => 'Manual assignment Function',
        ]);
        $this->publish_model($f);
        $function = function_service::context($f['company'], $f['actor'], $functionuuid)['selected'];
        $this->assertSame('', $function['position_uuid']);
        try {
            function_service::publish($f['company'], $f['actor'], $functionuuid, $function['revision']);
            $this->fail('Function without accountable Position must not publish.');
        } catch (\moodle_exception $e) {
            $this->assertSame('function_incomplete', $e->errorcode);
        }
    }
}
