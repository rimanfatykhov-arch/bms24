<?php
namespace local_kbacompany;

defined('MOODLE_INTERNAL') || die();

use local_kbacompany\local\cascade_service;
use local_kbacompany\local\identity_registry;
use local_kbacorporate\local\repository;

/** Run in Moodle with local_kbacorporate installed. */
final class cascade_service_test extends \advanced_testcase {
    private function company(string $name): string {
        $id = repository::save_company((object)['name' => $name, 'active' => 1]);
        return identity_registry::company_uuid($id);
    }

    public function test_decimal_boundaries_preserve_precision_and_zero(): void {
        $this->assertTrue(cascade_service::decimal_valid('999999999999.999999'));
        $this->assertFalse(cascade_service::decimal_valid('1000000000000'));
        $this->assertFalse(cascade_service::decimal_valid('1e6'));
        $this->assertFalse(cascade_service::decimal_valid(''));
        $this->assertSame(0, cascade_service::decimal_compare('-0.000000', '0'));
        $this->assertLessThan(0, cascade_service::decimal_compare('999999999999.999998', '999999999999.999999'));
        $this->assertLessThan(0, cascade_service::decimal_compare('-1.1', '-1'));
    }

    public function test_drafts_keep_history_without_changing_company_records(): void {
        global $DB, $USER;
        $this->resetAfterTest();
        $this->setAdminUser();
        $uuid = $this->company('Cascade');
        $before = $DB->get_records('local_kbacorp_company');
        $intent = cascade_service::save($uuid, (int)$USER->id, 'intent',
            ['code' => 'I-1', 'name' => 'Purpose', 'purpose' => 'Deliver value']);
        $goal = cascade_service::save($uuid, (int)$USER->id, 'goal',
            ['code' => 'G-1', 'name' => 'Goal', 'parentuuid' => $intent, 'current' => 'A', 'desired' => 'B']);
        $this->assertCount(2, cascade_service::context($uuid, (int)$USER->id)['objects']);
        $this->assertSame(2, $DB->count_records('local_kbacompany_aclog'));
        $this->assertEquals($before, $DB->get_records('local_kbacorp_company'));
        cascade_service::archive($uuid, (int)$USER->id, $goal, 1);
        $this->assertSame('archived', $DB->get_field('local_kbacompany_acobj', 'status', ['uuid' => $goal]));
        $this->assertSame(2, $DB->count_records('local_kbacompany_aclog', ['objectuuid' => $goal]));
    }

    public function test_cross_company_parent_is_rejected(): void {
        global $USER;
        $this->resetAfterTest();
        $this->setAdminUser();
        $a = $this->company('A');
        $b = $this->company('B');
        $intent = cascade_service::save($a, (int)$USER->id, 'intent',
            ['code' => 'I', 'name' => 'Intent', 'purpose' => 'Value']);
        $this->expectException(\moodle_exception::class);
        $this->expectExceptionMessage(get_string('ac_reference', 'local_kbacompany'));
        cascade_service::save($b, (int)$USER->id, 'goal',
            ['code' => 'G', 'name' => 'Goal', 'parentuuid' => $intent, 'current' => 'A', 'desired' => 'B']);
    }

    public function test_stale_revision_is_rejected(): void {
        global $USER;
        $this->resetAfterTest();
        $this->setAdminUser();
        $company = $this->company('A');
        $data = ['code' => 'I', 'name' => 'Intent', 'purpose' => 'Value'];
        $intent = cascade_service::save($company, (int)$USER->id, 'intent', $data);
        cascade_service::save($company, (int)$USER->id, 'intent', $data, $intent, 1);
        $this->expectException(\moodle_exception::class);
        $this->expectExceptionMessage(get_string('ac_conflict', 'local_kbacompany'));
        cascade_service::save($company, (int)$USER->id, 'intent', $data, $intent, 1);
    }

    public function test_norm_is_separate_from_plan_and_fact_and_links_are_removable(): void {
        global $DB, $USER;
        $this->resetAfterTest();
        $this->setAdminUser();
        $company = $this->company('Norms');
        $actor = (int)$USER->id;
        $board = \local_kbacompany\local\organizing_board_service::install_seven_division_template($company, $actor);
        $node = $board['nodes'][0]['uuid'];
        $product = \local_kbacompany\local\product_service::create_product($company, $actor, [
            'code' => 'P', 'name' => 'Product', 'acceptance' => 'Accepted', 'targettype' => 'node', 'targetuuid' => $node]);
        $stat = \local_kbacompany\local\product_service::create_statistic($company, $actor, [
            'code' => 'S', 'name' => 'Count', 'productuuid' => $product, 'unit' => 'count',
            'periodtype' => 'monthly', 'direction' => 'up']);
        $fields = ['code' => 'F', 'name' => 'Function', 'node' => $node];
        $function = cascade_service::save($company, $actor, 'function', $fields);
        cascade_service::save($company, $actor, 'result', ['code' => 'R', 'name' => 'Result',
            'parentuuid' => $function, 'acceptance' => 'Accepted', 'product' => $product]);
        cascade_service::save($company, $actor, 'function', $fields + ['mainstat' => $stat], $function, 1);
        $target = cascade_service::save($company, $actor, 'target', ['code' => 'N', 'name' => 'Norm',
            'parentuuid' => $function, 'indicator' => $stat, 'periodstart' => '2026-09-01', 'amount' => '0']);
        $stored = json_decode($DB->get_field('local_kbacompany_acobj', 'payload', ['uuid' => $target]), true);
        $this->assertSame('0', $stored['amount']);
        $this->assertNull($stored['lower']);
        $this->assertSame(0, $DB->count_records('local_kbacompany_statval'));
        $intent = cascade_service::save($company, $actor, 'intent', ['code' => 'I', 'name' => 'I', 'purpose' => 'Value']);
        $goal = cascade_service::save($company, $actor, 'goal', ['code' => 'G', 'name' => 'G',
            'parentuuid' => $intent, 'current' => 'A', 'desired' => 'B']);
        $plan = cascade_service::save($company, $actor, 'strategy', ['code' => 'SP', 'name' => 'SP',
            'parentuuid' => $goal, 'idea' => 'I', 'problem' => 'P', 'solution' => 'S', 'expected' => 'R']);
        cascade_service::contribute($company, $actor, $plan, $function);
        cascade_service::contribute($company, $actor, $plan, $function);
        $this->assertSame(1, $DB->count_records('local_kbacompany_aclink'));
        cascade_service::contribute($company, $actor, $plan, $function, true);
        $this->assertSame(0, $DB->count_records('local_kbacompany_aclink'));
    }

    public function test_non_member_cannot_read_context(): void {
        $this->resetAfterTest();
        $this->setAdminUser();
        $company = $this->company('A');
        $user = $this->getDataGenerator()->create_user();
        $this->setUser($user);
        $this->expectException(\moodle_exception::class);
        cascade_service::context($company, (int)$user->id);
    }
}
