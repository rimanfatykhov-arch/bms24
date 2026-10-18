<?php
namespace local_kbaai;

defined('MOODLE_INTERNAL') || die();

use local_kbaai\service\bms_profile_registry;
use local_kbaai\service\bms_assistant_service;

/** Profile unit tests; run in the Moodle PHPUnit environment. */
final class bms_profile_registry_test extends \advanced_testcase {
    public function test_only_draft_can_replace_a_field(): void {
        foreach (bms_profile_registry::OPERATIONS as $operation) {
            $result = bms_profile_registry::normalise([
                'suggested_value' => 'Draft', 'rationale' => 'Reason', 'questions' => [],
                'issues' => [], 'warnings' => [], 'evidence_suggestions' => [],
            ], $operation);
            $this->assertSame($operation === 'draft' ? 'Draft' : '', $result['suggested_value']);
        }
    }
    public function test_excessive_questions_are_rejected(): void {
        $this->expectException(\invalid_parameter_exception::class);
        bms_profile_registry::normalise([
            'suggested_value' => '', 'rationale' => '', 'questions' => array_fill(0, 51, 'Question'),
            'issues' => [], 'warnings' => [], 'evidence_suggestions' => [],
        ], 'interview');
    }
    public function test_unset_provider_is_not_implicitly_mock(): void {
        $this->resetAfterTest(true);
        unset_config('defaultprovider', 'local_kbaai');
        $this->assertFalse(bms_assistant_service::status()['available']);
        set_config('defaultprovider', 'mock', 'local_kbaai');
        $this->assertTrue(bms_assistant_service::status()['test_mode']);
    }
    public function test_bms_domain_contract_is_explicit(): void {
        $contract = bms_assistant_service::contract();
        $this->assertSame('proposal_only', $contract['write_policy']);
        foreach (['task', 'battleplan', 'ckp', 'metric', 'employee', 'position', 'role'] as $domain) {
            $this->assertContains($domain, $contract['domains']);
        }
    }
    public function test_profile_contract_is_proposal_only(): void {
        foreach (bms_profile_registry::OPERATIONS as $operation) {
            $profile = bms_profile_registry::profile($operation);
            $this->assertSame('bms_' . $operation, $profile['id']);
            $this->assertSame('0.3', $profile['version']);
            $this->assertSame('proposal_only', $profile['write_policy']);
        }
    }
}
