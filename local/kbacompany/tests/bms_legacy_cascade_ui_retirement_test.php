<?php

namespace local_kbacompany;

defined('MOODLE_INTERNAL') || die();

final class bms_legacy_cascade_ui_retirement_test extends \advanced_testcase {
    public function test_customer_navigation_does_not_expose_legacy_cascade(): void {
        $source = file_get_contents(__DIR__ . '/../classes/local/bms_ui_service.php');
        $this->assertStringContainsString("'group' => 'legacy', 'sidebar' => false, 'tree' => false", $source);
        $this->assertStringNotContainsString(
            "['route' => 'cascade', 'stage' => 'strategy', 'labelkey' => 'ac_title']",
            $source
        );
    }

    public function test_architecture_and_canonical_pages_do_not_link_to_legacy_cascade(): void {
        foreach (['positions.php', 'products.php', 'ofs.php', 'functions.php', 'administrative_scale.php', 'deviations.php'] as $file) {
            $source = file_get_contents(__DIR__ . '/../' . $file);
            $this->assertStringNotContainsString('/local/kbacompany/cascade.php', $source, $file);
        }
    }
}
