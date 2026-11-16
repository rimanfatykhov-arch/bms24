<?php
namespace local_kbacompany;

defined('MOODLE_INTERNAL') || die();

use local_kbacompany\local\bms_ui_service;
use local_kbacompany\local\bms_workspace_ux_service;

/** ADR-0009 R0: daily reports are a workspace route of their own, not a highlighted Task Center. */
class bms_daily_reports_navigation_test extends \advanced_testcase {
    public function test_daily_reports_sidebar_route_is_company_scoped_and_owns_its_active_state(): void {
        $this->resetAfterTest();
        $routesmethod = new \ReflectionMethod(bms_ui_service::class, 'routes');
        $routesmethod->setAccessible(true);
        $groupmethod = new \ReflectionMethod(bms_ui_service::class, 'navigation_groups');
        $groupmethod->setAccessible(true);

        foreach (['dailyreports', 'tasks', 'battleplan'] as $active) {
            $routes = $routesmethod->invoke(null, 'company-r0',
                ['incoming' => 0, 'tasks' => 0, 'overdue' => 0], false, false, $active);
            $groups = $groupmethod->invoke(null, $routes);
            $workspace = array_values(array_filter($groups, static function(array $group): bool {
                return $group['key'] === 'workspace';
            }))[0];
            $keys = array_column($workspace['items'], 'key');
            $this->assertSame(['workspace', 'calendar', 'tasks', 'dailyreports', 'recurring', 'battleplan',
                'communications'], $keys);
            $item = $workspace['items'][array_search('dailyreports', $keys, true)];
            $this->assertSame(get_string('dailyreports_nav', 'local_kbacompany'), $item['label']);
            $this->assertSame(get_string('dailyreports_nav_short', 'local_kbacompany'), $item['mobilelabel']);
            foreach ($workspace['items'] as $other) {
                if ($other['key'] !== 'dailyreports') {
                    $this->assertSame('', $other['mobilelabel']);
                }
            }
            $this->assertStringContainsString('daily_report.php', $item['url']);
            $this->assertStringContainsString('uuid=company-r0', $item['url']);
            $this->assertStringNotContainsString('userid', $item['url']);
            $this->assertSame($active === 'dailyreports', $item['active']);
            $tasks = $workspace['items'][array_search('tasks', $keys, true)];
            $this->assertSame($active === 'tasks', $tasks['active']);
        }
    }

    public function test_quick_navigation_marks_daily_reports_current(): void {
        $this->resetAfterTest();
        $html = bms_workspace_ux_service::contextual_nav('company-r0', 'dailyreports');
        $this->assertStringContainsString('daily_report.php?uuid=company-r0', $html);
        preg_match_all('~<a\b[^>]*>~', $html, $tags);
        $current = array_values(array_filter($tags[0], static function(string $tag): bool {
            return strpos($tag, 'aria-current="page"') !== false;
        }));
        $this->assertCount(1, $current);
        $this->assertStringContainsString('daily_report.php', $current[0]);
        $this->assertStringContainsString(get_string('dailyreports_nav', 'local_kbacompany'), $html);
    }
}
