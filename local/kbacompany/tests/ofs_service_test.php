<?php
namespace local_kbacompany;

defined('MOODLE_INTERNAL') || die();

use local_kbacompany\local\cascade_service;
use local_kbacompany\local\identity_registry;
use local_kbacompany\local\ofs_service;
use local_kbacompany\local\organizing_board_service;
use local_kbacompany\local\product_service;
use local_kbacorporate\local\repository;

/** Product-functional OFS regression tests. */
final class ofs_service_test extends \advanced_testcase {
    public function test_ofs_projects_company_unit_function_products_and_internal_customer(): void {
        global $DB, $USER;
        $this->resetAfterTest();
        $this->setAdminUser();
        $actor = (int)$USER->id;
        $company = identity_registry::company_uuid(repository::save_company((object)[
            'name' => 'OFS company', 'active' => 1,
        ]));
        $board = organizing_board_service::install_seven_division_template($company, $actor);
        $suppliernode = $board['nodes'][0]['uuid'];
        $customernode = $board['nodes'][1]['uuid'];
        $position = '42000000-0000-4000-8000-000000000001';
        $now = time();
        $DB->insert_record('local_kbacompany_position', (object)[
            'companyuuid' => $company, 'branchuuid' => $board['selected_branch_uuid'],
            'orgnodeuuid' => $suppliernode, 'uuid' => $position, 'code' => 'OWNER-1', 'name' => 'Product owner',
            'purpose' => '', 'ismanager' => 1, 'plannedcount' => 1, 'sortorder' => 10, 'active' => 1,
            'timecreated' => $now, 'timemodified' => $now,
        ]);
        $companyproduct = product_service::create_product($company, $actor, [
            'code' => 'CKP-C', 'name' => 'Company result', 'acceptance' => 'Company acceptance',
            'targettype' => 'company', 'targetuuid' => $company,
        ]);
        $unitproduct = product_service::create_product($company, $actor, [
            'code' => 'CKP-U', 'name' => 'Unit result', 'acceptance' => 'Unit acceptance',
            'targettype' => 'node', 'targetuuid' => $suppliernode,
        ]);
        $metric = product_service::create_statistic($company, $actor, [
            'productuuid' => $unitproduct, 'code' => 'STAT-U', 'name' => 'Unit output', 'unit' => 'count',
            'periodtype' => 'weekly', 'direction' => 'up',
        ]);
        $function = cascade_service::save($company, $actor, 'function', [
            'code' => 'FN-U', 'name' => 'Produce unit result', 'description' => '', 'parentuuid' => '',
            'node' => $suppliernode, 'position' => $position, 'mainstat' => '',
        ], '', 0);
        cascade_service::save($company, $actor, 'result', [
            'code' => 'RES-U', 'name' => 'Unit result produced', 'description' => '', 'parentuuid' => $function,
            'acceptance' => 'Accepted by internal customer', 'product' => $unitproduct,
        ], '', 0);
        cascade_service::save($company, $actor, 'function', [
            'code' => 'FN-U', 'name' => 'Produce unit result', 'description' => '', 'parentuuid' => '',
            'node' => $suppliernode, 'position' => $position, 'mainstat' => $metric,
        ], $function, 1);
        ofs_service::link_internal_customer($company, $actor, $unitproduct, $customernode,
            'Customer consumes the result');

        $context = ofs_service::context($company, $actor, $board['selected_branch_uuid']);
        $this->assertSame('0.3.2', ofs_service::contract()['version']);
        $this->assertSame(1, $context['summary']['company_ckp']);
        $this->assertSame($companyproduct, $context['company_products'][0]['uuid']);
        $this->assertSame(1, $context['summary']['org_units_with_ckp']);
        $this->assertSame(1, $context['summary']['functions_with_product']);
        $this->assertSame(1, $context['summary']['internal_interfaces']);
        $functions = array_column($context['functions'], null, 'uuid');
        $this->assertSame($unitproduct, $functions[$function]['product']['uuid']);
        $products = array_column($context['products'], null, 'uuid');
        $this->assertSame($metric, $products[$unitproduct]['metrics'][0]['uuid']);
        $this->assertSame($customernode, $products[$unitproduct]['customers'][0]['orgunit_uuid']);
        $this->assertFalse($context['processes_available']);
    }

    public function test_unlink_internal_customer_retains_semantic_history(): void {
        global $DB, $USER;
        $this->resetAfterTest();
        $this->setAdminUser();
        $actor = (int)$USER->id;
        $company = identity_registry::company_uuid(repository::save_company((object)[
            'name' => 'OFS unlink company', 'active' => 1,
        ]));
        $board = organizing_board_service::install_seven_division_template($company, $actor);
        $product = product_service::create_product($company, $actor, [
            'code' => 'CKP-U', 'name' => 'Unit output', 'acceptance' => 'Accepted',
            'targettype' => 'node', 'targetuuid' => $board['nodes'][0]['uuid'],
        ]);
        $relation = ofs_service::link_internal_customer($company, $actor, $product, $board['nodes'][1]['uuid']);
        ofs_service::unlink_internal_customer($company, $actor, $relation, 1, 'Interface changed');
        $this->assertSame(2, $DB->count_records('local_kbacompany_semrel', ['uuid' => $relation]));
        $this->assertSame(0, ofs_service::context($company, $actor)['summary']['internal_interfaces']);
    }
}
