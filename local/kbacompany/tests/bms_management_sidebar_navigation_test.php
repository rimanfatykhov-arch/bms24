<?php
namespace local_kbacompany;

defined('MOODLE_INTERNAL') || die();

use local_kbacompany\local\bms_ui_service;

/** RC29: management section icon and company-scoped Information Centre entry. */
final class bms_management_sidebar_navigation_test extends \advanced_testcase {
    public function test_management_sidebar_contains_personal_cycle_icon_and_information_centre(): void {
        $routesmethod = new \ReflectionMethod(bms_ui_service::class, 'routes');
        $routesmethod->setAccessible(true);
        $groupsmethod = new \ReflectionMethod(bms_ui_service::class, 'navigation_groups');
        $groupsmethod->setAccessible(true);
        $treemethod = new \ReflectionMethod(bms_ui_service::class, 'tree_model');
        $treemethod->setAccessible(true);

        foreach ([['personalcycle', false], ['informationcenter', false],
                ['informationcenter', true]] as $case) {
            list($active, $canmanagement) = $case;
            // This UI route is available regardless of the global management flag.
            $routes = $routesmethod->invoke(null, 'company-navigation',
                ['incoming' => 0, 'tasks' => 0, 'overdue' => 0], $canmanagement, false, $active);
            $groups = $groupsmethod->invoke(null, $routes);
            $management = array_values(array_filter($groups, static function(array $group): bool {
                return $group['key'] === 'management';
            }))[0];
            $items = array_column($management['items'], null, 'key');
            $keys = array_column($management['items'], 'key');

            $this->assertArrayHasKey('personalcycle', $items);
            $this->assertArrayHasKey('informationcenter', $items);
            $this->assertStringContainsString('my_management_cycle.php', $items['personalcycle']['url']);
            $this->assertStringContainsString('information_center.php', $items['informationcenter']['url']);
            $this->assertStringContainsString('uuid=company-navigation', $items['informationcenter']['url']);
            $this->assertStringContainsString('<svg', $items['personalcycle']['iconhtml']);
            $this->assertStringContainsString('<svg', $items['informationcenter']['iconhtml']);
            if ($canmanagement) {
                $this->assertSame(array_search('management', $keys, true) + 1,
                    array_search('informationcenter', $keys, true));
            } else {
                $this->assertSame(array_search('personalcycle', $keys, true) + 1,
                    array_search('informationcenter', $keys, true));
            }
            $this->assertSame($active === 'informationcenter', $items['informationcenter']['active']);
            $this->assertSame($active === 'personalcycle', $items['personalcycle']['active']);
            $this->assertTrue($management['expanded']);

            // The guided Architecture Center must keep the existing destination.
            $architecturetree = $treemethod->invoke(null, $routes);
            $this->assertContains('information_center.php', array_map(static function(array $node): string {
                return basename(parse_url($node['url'], PHP_URL_PATH));
            }, $architecturetree['nodes']));
        }
    }
}
