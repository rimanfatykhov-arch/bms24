<?php
namespace local_kbacompany;

defined('MOODLE_INTERNAL') || die();

use local_kbacompany\local\bms_workspace_ux_service;

/** Presentation-only regressions. Run with the Moodle PHPUnit harness. */
class bms_navigation_test extends \advanced_testcase {
    public function test_management_navigation_has_five_sections_and_contextual_peers(): void {
        $model = bms_workspace_ux_service::management_nav_model('company-a', 'history', true, '2026-09-21');
        $this->assertSame(['overview', 'planning', 'indicators', 'decisions', 'workspaces'],
            array_column($model['sections'], 'key'));
        $this->assertSame(['loop', 'review', 'history', 'patterns'], array_column($model['items'], 'key'));
        $this->assertSame('page', $model['items'][2]['current']);
        $this->assertTrue($model['sections'][3]['active']);
    }

    public function test_overview_has_no_duplicate_second_row(): void {
        $model = bms_workspace_ux_service::management_nav_model('company-a', 'overview', true);
        $this->assertSame([], $model['items']);
        $this->assertSame('page', $model['sections'][0]['current']);
    }

    public function test_non_manager_does_not_see_manager_destinations(): void {
        $model = bms_workspace_ux_service::management_nav_model('company-a', 'battleplan', false);
        $this->assertSame(['planning', 'indicators', 'workspaces'], array_column($model['sections'], 'key'));
        $this->assertSame(['week', 'battleplan'], array_column($model['items'], 'key'));
    }

    public function test_only_allowlisted_context_is_propagated(): void {
        $model = bms_workspace_ux_service::management_nav_model('company-b', 'week', true, '2026-09-21');
        foreach ($model['items'] as $item) {
            parse_str(parse_url($item['url'], PHP_URL_QUERY), $params);
            $this->assertSame('company-b', $params['uuid']);
            $this->assertSame(in_array($item['key'], ['coordweek', 'week', 'battleplan'], true), isset($params['week']));
        }
        $model = bms_workspace_ux_service::management_nav_model('company-b', 'week', true, '2026-02-30');
        foreach ($model['items'] as $item) {
            parse_str(parse_url($item['url'], PHP_URL_QUERY), $params);
            $this->assertArrayNotHasKey('week', $params);
        }
    }
}
