<?php
namespace local_kbacorporate;

defined('MOODLE_INTERNAL') || die();

use core_privacy\local\metadata\collection;
use local_kbacorporate\privacy\provider;

/** Privacy metadata coverage for role assignments and user mappings. */
final class privacy_provider_test extends \advanced_testcase {
    public function test_new_personal_data_tables_are_declared(): void {
        $collection = provider::get_metadata(new collection('local_kbacorporate'));
        $metadata = $collection->get_collection();
        $this->assertArrayHasKey('local_kbacorp_member_busrole', $metadata);
        $this->assertArrayHasKey('local_kbacorp_rolever', $metadata);
        $this->assertArrayHasKey('local_kbacorp_extmap', $metadata);
        $this->assertArrayHasKey('local_kbacorp_erp_log', $metadata);
        $this->assertArrayHasKey('local_kbacorp_audit', $metadata);
        $this->assertArrayHasKey('local_kbacorp_audit_item', $metadata);
        $this->assertArrayHasKey('local_kbacorp_audit_ev', $metadata);
        $this->assertArrayHasKey('local_kbacorp_devplan', $metadata);
        $this->assertArrayHasKey('local_kbacorp_devitem', $metadata);
        $this->assertArrayHasKey('local_kbacorp_econ_ind', $metadata);
        $this->assertArrayHasKey('local_kbacorp_econ_val', $metadata);
        $this->assertArrayHasKey('local_kbacorp_econ_log', $metadata);
    }
}
