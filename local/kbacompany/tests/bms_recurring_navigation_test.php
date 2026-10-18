<?php
namespace local_kbacompany;

defined('MOODLE_INTERNAL') || die();

use local_kbacompany\local\bms_ui_service;

/** RC29: recurring work is a first-class workspace route, not a theme menu hardcode. */
class bms_recurring_navigation_test extends \advanced_testcase {
    public function test_recurring_sidebar_route_is_near_task_center_and_company_scoped(): void {
        $routesmethod = new \ReflectionMethod(bms_ui_service::class, 'routes');
        $routesmethod->setAccessible(true);
        $groupmethod = new \ReflectionMethod(bms_ui_service::class, 'navigation_groups');
        $groupmethod->setAccessible(true);

        foreach (['recurring', 'tasks', 'battleplan'] as $active) {
            $routes = $routesmethod->invoke(null, 'company-rc30',
                ['incoming' => 0, 'tasks' => 0, 'overdue' => 0], false, false, $active);
            $groups = $groupmethod->invoke(null, $routes);
            $workspace = array_values(array_filter($groups, static function(array $group): bool {
                return $group['key'] === 'workspace';
            }))[0];
            $keys = array_column($workspace['items'], 'key');
            $position = array_search('recurring', $keys, true);
            $this->assertNotFalse($position);
            $this->assertSame('tasks', $keys[$position - 1]);
            $this->assertSame('battleplan', $keys[$position + 1]);
            $item = $workspace['items'][$position];
            $this->assertStringContainsString('recurring_tasks.php', $item['url']);
            $this->assertStringContainsString('uuid=company-rc30', $item['url']);
            $this->assertStringContainsString('view=list', $item['url']);
            $this->assertSame($active === 'recurring', $item['active']);
        }
    }
}
