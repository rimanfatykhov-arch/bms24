<?php
namespace local_kbacompany;

defined('MOODLE_INTERNAL') || die();

use local_kbacompany\local\bms_tariff_catalog_service;

/** Offline pure contract checks; BMS Cloud billing is not part of Moodle. */
final class bms_commercial_catalog_contract_test extends \advanced_testcase {
    public function test_offer_types_have_a_fulfillment_route(): void {
        $contract = bms_tariff_catalog_service::contract();
        $this->assertSame('1.1', $contract['version']);
        $this->assertSame('bms_entitlement', $contract['offer_types']['saas_subscription']);
        $this->assertSame('moodle_course_enrolment', $contract['offer_types']['education_course']);
        $this->assertSame('moodle_program_admission', $contract['offer_types']['education_program']);
        $this->assertSame('per_component', $contract['offer_types']['bundle']);
        $this->assertCount(count(array_unique(array_keys($contract['offer_types']))), $contract['offer_types']);
    }

    public function test_partner_attribution_is_separate_from_entitlement(): void {
        $contract = bms_tariff_catalog_service::contract();
        $this->assertContains('partner_ref', $contract['order_item_fields']);
        $this->assertContains('promo_code_ref', $contract['order_item_fields']);
        $this->assertContains('partner_link_does_not_grant_tenant_or_learner_data_access', $contract['fulfillment_rules']);
        $this->assertContains('subscription_and_academy_seats_are_distinct', $contract['fulfillment_rules']);
    }
}
