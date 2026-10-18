<?php
namespace local_kbacompany;

defined('MOODLE_INTERNAL') || die();

use local_kbacompany\local\identity_registry;
use local_kbacompany\local\document_service;
use local_kbacompany\local\semantic_service;
use local_kbacorporate\local\repository;

/** BMS Semantic Core canonical-object and relation regression tests. */
final class semantic_service_test extends \advanced_testcase {
    private function fixture(string $name = 'Semantic company'): array {
        global $DB, $USER;
        $this->resetAfterTest();
        $this->setAdminUser();
        $actor = (int)$USER->id;
        $company = identity_registry::company_uuid(repository::save_company((object)[
            'name' => $name, 'active' => 1,
        ]));
        $now = time();
        $branch = '41000000-0000-4000-8000-000000000001';
        $node = '41000000-0000-4000-8000-000000000002';
        $position = '41000000-0000-4000-8000-000000000003';
        $product = '41000000-0000-4000-8000-000000000004';
        $metric = '41000000-0000-4000-8000-000000000005';
        $goal = '41000000-0000-4000-8000-000000000006';
        $function = '41000000-0000-4000-8000-000000000007';
        $DB->insert_record('local_kbacompany_orgbranch', (object)[
            'companyuuid' => $company, 'uuid' => $branch, 'code' => 'MAIN', 'name' => 'Main',
            'isdefault' => 1, 'active' => 1, 'sortorder' => 10, 'timecreated' => $now, 'timemodified' => $now,
        ]);
        $DB->insert_record('local_kbacompany_orgnode', (object)[
            'companyuuid' => $company, 'branchuuid' => $branch, 'uuid' => $node, 'parentuuid' => '',
            'code' => 'SALES', 'nodetype' => 'department', 'name' => 'Sales', 'sortorder' => 10,
            'color' => '#00843D', 'sourcecomponent' => '', 'sourceentity' => '', 'sourceid' => 0,
            'active' => 1, 'timecreated' => $now, 'timemodified' => $now,
        ]);
        $DB->insert_record('local_kbacompany_position', (object)[
            'companyuuid' => $company, 'branchuuid' => $branch, 'orgnodeuuid' => $node, 'uuid' => $position,
            'code' => 'ROP', 'name' => 'Sales manager', 'purpose' => '', 'ismanager' => 1,
            'plannedcount' => 1, 'sortorder' => 10, 'active' => 1, 'timecreated' => $now, 'timemodified' => $now,
        ]);
        $DB->insert_record('local_kbacompany_product', (object)[
            'companyuuid' => $company, 'uuid' => $product, 'code' => 'CKP-SALES',
            'name' => 'Paid target-customer orders', 'targettype' => 'node', 'targetuuid' => $node,
            'acceptance' => 'Payment received', 'timecreated' => $now,
        ]);
        $DB->insert_record('local_kbacompany_stat', (object)[
            'companyuuid' => $company, 'uuid' => $metric, 'productuuid' => $product, 'code' => 'REV',
            'name' => 'Revenue', 'unit' => 'RUB', 'periodtype' => 'weekly', 'direction' => 'up',
            'timecreated' => $now,
        ]);
        foreach ([['goal', $goal, 'GOAL-1', 'Grow sales'], ['function', $function, 'FN-1', 'Manage sales']] as $row) {
            $DB->insert_record('local_kbacompany_acobj', (object)[
                'companyuuid' => $company, 'uuid' => $row[1], 'kind' => $row[0], 'code' => $row[2],
                'name' => $row[3], 'description' => '', 'parentuuid' => '', 'payload' => '{}',
                'revision' => 1, 'status' => 'draft', 'timecreated' => $now, 'timemodified' => $now,
            ]);
        }
        return compact('company', 'actor', 'branch', 'node', 'position', 'product', 'metric', 'goal', 'function');
    }

    public function test_contract_declares_current_and_reserved_semantic_types(): void {
        $contract = semantic_service::contract();
        $this->assertSame('0.3.3', $contract['version']);
        $this->assertSame('current', $contract['objects']['product']['availability']);
        $this->assertSame('current', $contract['objects']['process']['availability']);
        $this->assertSame('current', $contract['objects']['activity']['availability']);
        $this->assertSame('current', $contract['objects']['document']['availability']);
        $this->assertContains('produces', $contract['relations']);
        $this->assertContains('canonical_objects_are_not_duplicated', $contract['rules']);
        $this->assertSame(64, strlen($contract['schema_hash']));
    }

    public function test_context_projects_canonical_objects_without_copying_them(): void {
        global $DB;
        $f = $this->fixture();
        $before = [
            'node' => $DB->count_records('local_kbacompany_orgnode'),
            'position' => $DB->count_records('local_kbacompany_position'),
            'product' => $DB->count_records('local_kbacompany_product'),
            'stat' => $DB->count_records('local_kbacompany_stat'),
            'acobj' => $DB->count_records('local_kbacompany_acobj'),
        ];
        $context = semantic_service::context($f['company'], $f['actor']);
        $this->assertGreaterThanOrEqual(7, $context['summary']['objects']);
        $this->assertSame(1, $context['summary']['by_type']['company']);
        $this->assertSame(1, $context['summary']['by_type']['orgunit']);
        $this->assertSame(1, $context['summary']['by_type']['position']);
        $this->assertSame(1, $context['summary']['by_type']['product']);
        $this->assertSame(1, $context['summary']['by_type']['metric']);
        $this->assertSame(1, $context['summary']['by_type']['goal']);
        $this->assertSame(1, $context['summary']['by_type']['function']);
        $this->assertSame($before['node'], $DB->count_records('local_kbacompany_orgnode'));
        $this->assertSame($before['position'], $DB->count_records('local_kbacompany_position'));
        $this->assertSame($before['product'], $DB->count_records('local_kbacompany_product'));
        $this->assertSame($before['stat'], $DB->count_records('local_kbacompany_stat'));
        $this->assertSame($before['acobj'], $DB->count_records('local_kbacompany_acobj'));
    }

    public function test_relation_create_duplicate_and_archive_are_append_only(): void {
        global $DB;
        $f = $this->fixture();
        $edge = [
            'fromtype' => 'orgunit', 'fromuuid' => $f['node'], 'relation' => 'produces',
            'totype' => 'product', 'touuid' => $f['product'], 'reason' => 'Model the department result',
        ];
        $uuid = semantic_service::command_relation($f['company'], $f['actor'], 'create', $edge);
        $context = semantic_service::context($f['company'], $f['actor']);
        $this->assertCount(1, $context['relations']);
        $this->assertSame('produces', $context['relations'][0]['relation']);

        try {
            semantic_service::command_relation($f['company'], $f['actor'], 'create', $edge);
            $this->fail('Duplicate active relation must be rejected.');
        } catch (\moodle_exception $e) {
            $this->assertSame(get_string('semantic_duplicate', 'local_kbacompany'), $e->getMessage());
        }

        semantic_service::command_relation($f['company'], $f['actor'], 'archive', [
            'object' => $uuid, 'revision' => 1, 'reason' => 'Model changed',
        ]);
        $this->assertSame(2, $DB->count_records('local_kbacompany_semrel', ['uuid' => $uuid]));
        $this->assertCount(0, semantic_service::context($f['company'], $f['actor'])['relations']);
    }

    public function test_effective_document_is_a_writable_semantic_endpoint(): void {
        $f = $this->fixture('Semantic document company');
        $doc = document_service::create_manual($f['company'], $f['actor'], [
            'code' => 'DP-SEM', 'title' => 'Semantic controlled document',
            'doctype' => 'procedure', 'doclevel' => 2, 'content' => 'Controlled',
        ]);
        document_service::add_reviewer($f['company'], $f['actor'], $doc, 1, $f['actor']);
        document_service::submit_review($f['company'], $f['actor'], $doc, 1);
        document_service::review_decision($f['company'], $f['actor'], $doc, 1, 'approved');
        document_service::approve($f['company'], $f['actor'], $doc, 1);
        document_service::publish($f['company'], $f['actor'], $doc, 1, 0);
        document_service::activate($f['company'], $f['actor'], $doc, 1);
        $rel = semantic_service::command_relation($f['company'], $f['actor'], 'create', [
            'fromtype' => 'document', 'fromuuid' => $doc,
            'relation' => 'governed_by', 'totype' => 'product', 'touuid' => $f['product'],
            'reason' => 'Document controls the product requirements',
        ]);
        $this->assertNotEmpty($rel);
        $context = semantic_service::context($f['company'], $f['actor']);
        $docs = array_values(array_filter($context['objects'], static function(array $object) use ($doc): bool {
            return $object['type'] === 'document' && $object['uuid'] === $doc;
        }));
        $this->assertCount(1, $docs);
    }
}
