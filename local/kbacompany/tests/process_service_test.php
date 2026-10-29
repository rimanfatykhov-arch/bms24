<?php
namespace local_kbacompany;

defined('MOODLE_INTERNAL') || die();

use local_kbacompany\local\identity_registry;
use local_kbacompany\local\organizing_board_service;
use local_kbacompany\local\process_service;
use local_kbacompany\local\product_service;
use local_kbacompany\local\semantic_service;
use local_kbacorporate\local\repository;

/** Process Architecture regression tests. */
final class process_service_test extends \advanced_testcase {
    private function fixture(string $name = 'Process company'): array {
        global $USER;
        $this->resetAfterTest();
        $this->setAdminUser();
        $actor = (int)$USER->id;
        $company = identity_registry::company_uuid(repository::save_company((object)[
            'name' => $name, 'active' => 1,
        ]));
        $board = organizing_board_service::install_seven_division_template($company, $actor);
        $product = product_service::create_product($company, $actor, [
            'code' => 'CKP-PROC', 'name' => 'Process output', 'acceptance' => 'Accepted output',
            'targettype' => 'node', 'targetuuid' => $board['nodes'][0]['uuid'],
        ]);
        $metric = product_service::create_statistic($company, $actor, [
            'productuuid' => $product, 'code' => 'PROC-STAT', 'name' => 'Process output rate',
            'unit' => 'count', 'periodtype' => 'weekly', 'direction' => 'up',
        ]);
        return compact('actor', 'company', 'board', 'product', 'metric');
    }

    public function test_process_hierarchy_publish_and_cross_functional_links(): void {
        global $DB;
        $f = $this->fixture();
        $root = process_service::save($f['company'], $f['actor'], [
            'code' => 'CORE-L0', 'name' => 'Create customer value', 'purpose' => 'End-to-end core value stream',
            'processtype' => 'core', 'processlevel' => 'L0', 'parentuuid' => '',
            'ownerorgunituuid' => $f['board']['nodes'][0]['uuid'], 'productuuid' => $f['product'],
        ]);
        process_service::publish($f['company'], $f['actor'], $root, 1);
        $child = process_service::save($f['company'], $f['actor'], [
            'code' => 'CORE-L1', 'name' => 'Sell', 'purpose' => 'Convert demand to paid order',
            'processtype' => 'core', 'processlevel' => 'L1', 'parentuuid' => $root,
            'ownerorgunituuid' => $f['board']['nodes'][0]['uuid'], 'productuuid' => $f['product'],
        ]);
        process_service::publish($f['company'], $f['actor'], $child, 1);
        process_service::link($f['company'], $f['actor'], $child, 'supplier', $f['board']['nodes'][1]['uuid']);
        process_service::link($f['company'], $f['actor'], $child, 'customer', $f['board']['nodes'][2]['uuid']);
        process_service::link($f['company'], $f['actor'], $child, 'participant', $f['board']['nodes'][3]['uuid']);
        process_service::link($f['company'], $f['actor'], $child, 'metric', $f['metric']);

        $context = process_service::context($f['company'], $f['actor'], $child);
        $this->assertSame('0.3.0', process_service::contract()['version']);
        $this->assertSame(2, $context['summary']['total']);
        $this->assertSame(2, $context['summary']['core']);
        $this->assertSame('published', $context['selected']['status']);
        $this->assertCount(1, $context['selected']['links']['supplier']);
        $this->assertCount(1, $context['selected']['links']['customer']);
        $this->assertCount(1, $context['selected']['links']['participant']);
        $this->assertCount(1, $context['selected']['links']['metric']);
        $this->assertSame(4, $DB->count_records('local_kbacompany_semrel'));
    }

    public function test_publish_requires_owner_product_and_published_parent(): void {
        $f = $this->fixture('Process validation');
        $root = process_service::save($f['company'], $f['actor'], [
            'code' => 'ROOT-DRAFT', 'name' => 'Root draft', 'purpose' => '',
            'processtype' => 'management', 'processlevel' => 'L0', 'parentuuid' => '',
            'ownerorgunituuid' => $f['board']['nodes'][0]['uuid'], 'productuuid' => $f['product'],
        ]);
        $child = process_service::save($f['company'], $f['actor'], [
            'code' => 'CHILD', 'name' => 'Child', 'purpose' => '',
            'processtype' => 'management', 'processlevel' => 'L1', 'parentuuid' => $root,
            'ownerorgunituuid' => $f['board']['nodes'][0]['uuid'], 'productuuid' => $f['product'],
        ]);
        try {
            process_service::publish($f['company'], $f['actor'], $child, 1);
            $this->fail('Child publication must require a published parent.');
        } catch (\moodle_exception $e) {
            $this->assertSame(get_string('process_parentnotpublished', 'local_kbacompany'), $e->getMessage());
        }
        $incomplete = process_service::save($f['company'], $f['actor'], [
            'code' => 'INC', 'name' => 'Incomplete', 'purpose' => '',
            'processtype' => 'support', 'processlevel' => 'L0', 'parentuuid' => '',
            'ownerorgunituuid' => '', 'productuuid' => '',
        ]);
        $this->expectException(\moodle_exception::class);
        $this->expectExceptionMessage(get_string('process_incomplete', 'local_kbacompany'));
        process_service::publish($f['company'], $f['actor'], $incomplete, 1);
    }

    public function test_process_revisions_are_append_only_and_semantic_type_is_current(): void {
        global $DB;
        $f = $this->fixture('Process revisions');
        $uuid = process_service::save($f['company'], $f['actor'], [
            'code' => 'DEV', 'name' => 'Develop system', 'purpose' => 'Initial',
            'processtype' => 'development', 'processlevel' => 'L0', 'parentuuid' => '',
            'ownerorgunituuid' => $f['board']['nodes'][0]['uuid'], 'productuuid' => $f['product'],
        ]);
        process_service::save($f['company'], $f['actor'], [
            'code' => 'DEV', 'name' => 'Develop system', 'purpose' => 'Refined',
            'processtype' => 'development', 'processlevel' => 'L0', 'parentuuid' => '',
            'ownerorgunituuid' => $f['board']['nodes'][0]['uuid'], 'productuuid' => $f['product'],
        ], $uuid, 1);
        $this->assertSame(2, $DB->count_records('local_kbacompany_process', ['uuid' => $uuid]));
        $semantic = semantic_service::contract();
        $this->assertSame('0.3.3', $semantic['version']);
        $this->assertSame('current', $semantic['objects']['process']['availability']);
        $this->assertSame('current', $semantic['objects']['activity']['availability']);
    }

    public function test_invalid_level_parent_relationship_is_rejected(): void {
        $f = $this->fixture('Process parent');
        $root = process_service::save($f['company'], $f['actor'], [
            'code' => 'ROOT', 'name' => 'Root', 'purpose' => '', 'processtype' => 'core',
            'processlevel' => 'L0', 'parentuuid' => '', 'ownerorgunituuid' => $f['board']['nodes'][0]['uuid'],
            'productuuid' => $f['product'],
        ]);
        $this->expectException(\moodle_exception::class);
        $this->expectExceptionMessage(get_string('process_parent', 'local_kbacompany'));
        process_service::save($f['company'], $f['actor'], [
            'code' => 'BAD-L2', 'name' => 'Bad L2', 'purpose' => '', 'processtype' => 'core',
            'processlevel' => 'L2', 'parentuuid' => $root, 'ownerorgunituuid' => $f['board']['nodes'][0]['uuid'],
            'productuuid' => $f['product'],
        ]);
    }
}
