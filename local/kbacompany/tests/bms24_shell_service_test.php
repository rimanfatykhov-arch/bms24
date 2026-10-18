<?php
namespace local_kbacompany;

defined('MOODLE_INTERNAL') || die();

use local_kbacompany\local\bms24_shell_service;

/** Opt-in owned UI must never silently replace the legacy KBA presentation. */
final class bms24_shell_service_test extends \advanced_testcase {
    public function test_owned_shell_is_opt_in_and_rollbackable(): void {
        $this->resetAfterTest();
        set_config('bms24_shell_mode', 'legacy', 'local_kbacompany');
        $this->assertFalse(bms24_shell_service::is_enabled());
        set_config('bms24_shell_mode', 'owned', 'local_kbacompany');
        $this->assertTrue(bms24_shell_service::is_enabled());
        set_config('bms24_shell_mode', 'legacy', 'local_kbacompany');
        $this->assertFalse(bms24_shell_service::is_enabled());
    }

    public function test_unknown_component_is_rejected(): void {
        $this->expectException(\coding_exception::class);
        bms24_shell_service::fragment('../../theme_kba/bms/app_shell', []);
    }

    public function test_plugin_templates_exist(): void {
        global $CFG;
        foreach (['app_shell', 'nav_groups', 'card', 'stat_card', 'badge', 'empty_state',
                'task_list', 'tree_navigator', 'split_view'] as $component) {
            $this->assertFileExists($CFG->dirroot . '/local/kbacompany/templates/bms24_' . $component . '.mustache');
        }
    }
}
