<?php
namespace local_kbacompany\local;

defined('MOODLE_INTERNAL') || die();

/** Read-only BMS Cloud catalogue contract. No second local subscription registry. */
final class bms_tariff_catalog_service {
    public const CONTRACT_VERSION = '1.1';
    /** Commercial product categories; BMS Cloud owns pricing and fulfillment. */
    public const OFFER_TYPES = [
        'saas_subscription' => 'bms_entitlement',
        'education_course' => 'moodle_course_enrolment',
        'education_program' => 'moodle_program_admission',
        'implementation_service' => 'service_project',
        'consulting_service' => 'service_project',
        'bundle' => 'per_component',
    ];
    /** Fulfillment is independent of order attribution and commission. */
    public const COMMERCIAL_FIELDS = [
        'offer_id', 'offer_type', 'product_code', 'sku', 'title', 'revision', 'status',
        'currency', 'unit_price_minor', 'tax_policy_code', 'fulfillment_type',
        'external_catalogue_ref', 'commission_category',
    ];
    public const ORDER_ITEM_FIELDS = [
        'order_item_id', 'offer_id', 'offer_revision', 'quantity', 'unit_price_minor',
        'discount_minor', 'total_minor', 'fulfillment_type', 'beneficiary_kind',
        'beneficiary_ref', 'partner_ref', 'promo_code_ref', 'attribution_rule_revision',
    ];
    public const PRODUCTS = [
        'management' => ['title' => 'BMS Management', 'features' => ['management.tasks', 'management.calendar',
            'management.battle_plans', 'management.indicators', 'management.processes']],
        'hire' => ['title' => 'BMS Hire', 'features' => ['hire.vacancies', 'hire.candidates']],
        'academy' => ['title' => 'BMS Academy', 'features' => ['academy.learning']],
        'erp' => ['title' => 'BMS ERP', 'features' => ['erp.operations']],
    ];
    public const LIMITS = ['bms.company_members', 'bms.storage_bytes', 'bms.ai_operations'];
    public const PERIODS = ['month', 'year'];

    public static function contract(): array {
        return [
            'version' => self::CONTRACT_VERSION,
            'offer_types' => self::OFFER_TYPES,
            'commercial_offer_fields' => self::COMMERCIAL_FIELDS,
            'order_item_fields' => self::ORDER_ITEM_FIELDS,
            'fulfillment_rules' => [
                'payment_confirmed_is_required_before_paid_fulfillment',
                'education_course_requires_named_moodle_course_and_entitled_seat',
                'education_program_admission_is_not_course_completion',
                'bundle_fulfills_each_paid_component_independently',
                'subscription_and_academy_seats_are_distinct',
                'partner_link_does_not_grant_tenant_or_learner_data_access',
                'promo_code_may_attribute_without_customer_discount',
                'partner_commission_is_separate_from_payout',
                'no_commission_double_count_on_provider_event_retry',
                'legacy_sdelka_promo_code_mapping_requires_unique_external_ref',
            ],
            'owner' => 'bms_cloud_control_plane',
            'runtime_owner' => 'entitlement_service',
            'snapshot_owner' => 'entitlement_snapshot_service',
            'plan_fields' => ['code', 'product_code', 'name', 'billing_period', 'currency',
                'price_minor_units', 'feature_entitlements', 'limits', 'revision', 'status'],
            'products' => self::PRODUCTS,
            'limits' => self::LIMITS,
            'rules' => [
                'catalogue_is_a_contract_not_a_second_billing_database',
                'plan_and_subscription_are_distinct_objects',
                'entitlement_does_not_override_bms_rbac',
                'never_infer_price_or_seat_limit',
                'legacy_company_access_is_unchanged',
                'academy_course_seats_are_not_company_member_seats',
                'pending_invitation_reservations_require_an_invitation_registry',
                'cloud_is_source_of_truth_for_subscription_and_plan_revisions',
            ],
        ];
    }

    /** Status is deliberately informational. It never authorizes new access. */
    public static function company_status(string $companyuuid, int $userid): array {
        company_context_service::context($companyuuid, $userid);
        $workspace = workspace_mapping_service::status($companyuuid);
        $mode = (string)$workspace['entitlement_mode'];
        return [
            'companyuuid' => $companyuuid,
            'mode' => $mode,
            'cloud_status' => (string)$workspace['cloud_status'],
            'snapshot_revision' => (string)$workspace['snapshot_revision'],
            'pricing_configured' => false,
            'seat_limit' => null,
            'pending_reserved_seats' => null,
            'note' => 'Tariff catalog/plan subscription is owned by BMS Cloud and is not configured locally.',
        ];
    }
}
