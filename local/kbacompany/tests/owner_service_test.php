<?php
namespace local_kbacompany;

defined('MOODLE_INTERNAL') || die();

use local_kbacompany\local\owner_service;

/** Owner intent guided workflow regression tests. */
final class owner_service_test extends \advanced_testcase {
    public function test_contract_separates_owner_answers_from_consultant_review(): void {
        $contract = owner_service::contract();
        $this->assertSame('0.6.9.4', $contract['version']);
        $this->assertContains('explicitly_authorised_consultants_can_review_but_not_edit_owner_answers', $contract['rules']);
        $this->assertContains('review_feedback_is_append_only_and_section_scoped', $contract['rules']);
        $this->assertContains('approved_requirements_are_the_only_owner_data_exposed_to_business_architecture', $contract['rules']);
        $this->assertCount(14, owner_service::SECTION_KEYS);
    }

    public function test_section_hash_changes_when_owner_wording_changes(): void {
        $section = [
            'statement' => 'Goal A',
            'horizonmetric' => '2029 / metric',
            'constraintsprice' => 'Limit',
            'businessevidence' => 'Evidence',
        ];
        $hash1 = owner_service::section_hash($section);
        $section['statement'] = 'Goal B';
        $hash2 = owner_service::section_hash($section);
        $this->assertNotSame($hash1, $hash2);
    }

    public function test_legacy_payload_is_projected_into_fourteen_sections(): void {
        $sections = owner_service::sections_from_payload([
            'lpf1' => 'One year',
            'lpf3' => 'Three years',
            'lpf5' => 'Five years',
            'professional_role' => 'Chair',
            'ownership_goals' => 'Autonomous business',
        ]);
        $this->assertCount(14, $sections);
        $this->assertStringContainsString('One year', $sections['personal']['statement']);
        $this->assertSame('Chair', $sections['professional']['statement']);
        $this->assertSame('Autonomous business', $sections['ownership']['statement']);
    }
}
