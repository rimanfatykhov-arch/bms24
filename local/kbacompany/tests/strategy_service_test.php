<?php
namespace local_kbacompany;

defined('MOODLE_INTERNAL') || die();

use local_kbacompany\local\identity_registry;
use local_kbacompany\local\strategy_service;
use local_kbacorporate\local\repository;

/** Ownership, Strategy and BSC regression tests. */
final class strategy_service_test extends \advanced_testcase {
    private function company(string $name): string {
        $id = repository::save_company((object)['name' => $name, 'active' => 1]);
        return identity_registry::company_uuid($id);
    }

    public function test_contract_declares_product_rules(): void {
        $contract = strategy_service::contract();
        $this->assertSame('0.5.0', $contract['version']);
        $this->assertSame(['financial', 'customer', 'process', 'learning'], $contract['perspectives']);
        $this->assertContains('long_term_goal_is_explicit', $contract['rules']);
        $this->assertContains('causal_map_is_acyclic', $contract['rules']);
        $this->assertContains('bsc_goal_indicators_reuse_indicator_registry_bindings', $contract['rules']);
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $contract['schema_hash']);
    }

    public function test_ownership_strategy_and_bsc_publish_in_dependency_order(): void {
        global $DB, $USER;
        $this->resetAfterTest();
        $this->setAdminUser();
        $company = $this->company('Strategy');
        $actor = (int)$USER->id;

        $intent = strategy_service::save_ownership_intent($company, $actor, [
            'code' => 'OWN-1', 'name' => 'Owner intent', 'ownershippurpose' => 'Create enduring value',
            'longtermgoal' => 'Reach the target scale without owner operational dependency', 'horizon' => '2030',
        ]);
        strategy_service::publish_ownership_intent($company, $actor, $intent, 1);
        $this->assertSame(2, $DB->count_records('local_kbacompany_ownerintent', ['uuid' => $intent]));

        $strategy = strategy_service::save_strategy($company, $actor, [
            'ownerintentuuid' => $intent, 'code' => 'STR-1', 'name' => 'Growth strategy',
            'thesis' => 'Scale a reproducible operating model', 'horizonstart' => '2026-01-01',
            'horizonend' => '2030-12-31',
        ]);
        strategy_service::publish_strategy($company, $actor, $strategy, 1);

        $goal = strategy_service::save_goal($company, $actor, [
            'strategyuuid' => $strategy, 'code' => 'G-FIN-1', 'name' => 'Increase profitable growth',
            'perspective' => 'financial', 'outcome' => 'Sustainable profitable growth', 'weight' => '40',
        ]);
        strategy_service::publish_goal($company, $actor, $goal, 1);
        $context = strategy_service::context($company, $actor);
        $this->assertCount(1, $context['ownership_intents']);
        $this->assertCount(1, $context['strategies']);
        $this->assertCount(1, $context['goals']);
        $this->assertSame('published', $context['goals'][0]['status']);
        $this->assertSame('OWN-0001', $context['ownership_intents'][0]['code']);
        $this->assertSame('STR-0001', $context['strategies'][0]['code']);
        $this->assertSame('GOAL-0001', $context['goals'][0]['code']);
        $this->assertArrayHasKey('bsc', $context);
        $this->assertSame(1, $context['bsc']['perspectives']['financial']['total']);
    }

    public function test_causal_map_rejects_cycle(): void {
        global $USER;
        $this->resetAfterTest();
        $this->setAdminUser();
        $company = $this->company('Causal');
        $actor = (int)$USER->id;
        $intent = strategy_service::save_ownership_intent($company, $actor, [
            'code' => 'OWN', 'name' => 'Intent', 'ownershippurpose' => 'Purpose', 'longtermgoal' => 'Long goal']);
        strategy_service::publish_ownership_intent($company, $actor, $intent, 1);
        $strategy = strategy_service::save_strategy($company, $actor, [
            'ownerintentuuid' => $intent, 'code' => 'STR', 'name' => 'Strategy', 'thesis' => 'Thesis']);
        strategy_service::publish_strategy($company, $actor, $strategy, 1);
        $a = strategy_service::save_goal($company, $actor, [
            'strategyuuid' => $strategy, 'code' => 'A', 'name' => 'People capability',
            'perspective' => 'learning', 'outcome' => 'Capable team']);
        $b = strategy_service::save_goal($company, $actor, [
            'strategyuuid' => $strategy, 'code' => 'B', 'name' => 'Process quality',
            'perspective' => 'process', 'outcome' => 'Stable process']);
        strategy_service::publish_goal($company, $actor, $a, 1);
        strategy_service::publish_goal($company, $actor, $b, 1);
        $link = strategy_service::save_causal_link($company, $actor, [
            'strategyuuid' => $strategy, 'fromgoaluuid' => $a, 'togoaluuid' => $b, 'relation' => 'enables']);
        strategy_service::publish_causal_link($company, $actor, $link, 1);

        $this->expectException(\moodle_exception::class);
        $this->expectExceptionMessage(get_string('strategy_cycle', 'local_kbacompany'));
        strategy_service::save_causal_link($company, $actor, [
            'strategyuuid' => $strategy, 'fromgoaluuid' => $b, 'togoaluuid' => $a, 'relation' => 'drives']);
    }

    public function test_strategy_requires_published_ownership_intent(): void {
        global $USER;
        $this->resetAfterTest();
        $this->setAdminUser();
        $company = $this->company('Dependencies');
        $actor = (int)$USER->id;
        $intent = strategy_service::save_ownership_intent($company, $actor, [
            'code' => 'OWN', 'name' => 'Intent', 'ownershippurpose' => 'Purpose', 'longtermgoal' => 'Long goal']);
        $this->expectException(\moodle_exception::class);
        $this->expectExceptionMessage(get_string('strategy_dependency', 'local_kbacompany'));
        strategy_service::save_strategy($company, $actor, [
            'ownerintentuuid' => $intent, 'code' => 'STR', 'name' => 'Strategy', 'thesis' => 'Thesis']);
    }
}
