<?php
namespace local_kbacompany\local;

defined('MOODLE_INTERNAL') || die();

/**
 * Built-in normalized OFS template library.
 *
 * Templates are code-owned starting points for new OrgDesign projects. They do
 * not depend on Google Drive at runtime and do not modify the methodology
 * source library. BMS remains the source of truth after a template is chosen.
 */
class ofs_template_library_service {
    public const CONTRACT_VERSION = '0.4.6.14';

    /** @var array|null */
    private static $definitions = null;

    public static function contract(): array {
        return [
            'component' => 'local_kbacompany',
            'contract' => 'bms_ofs_template_library',
            'version' => self::CONTRACT_VERSION,
            'source_of_truth' => 'BMS built-in normalized templates',
            'rules' => [
                'methodology_source_library_is_read_only',
                'template_copy_becomes_company_owned_draft',
                'templates_use_existing_orgdesign_version_model',
                'no_runtime_dependency_on_google_drive',
                'company_ckp_is_suggested_not_final',
                'industry_template_is_starting_point_not_prescription',
            ],
        ];
    }

    /** Stable built-in template keys. */
    public static function keys(): array {
        return array_keys(self::definitions());
    }

    public static function has(string $key): bool {
        return array_key_exists($key, self::definitions());
    }

    /** Presentation-safe catalogue metadata. */
    public static function templates(): array {
        $out = [];
        foreach (self::definitions() as $key => $def) {
            $out[] = [
                'key' => $key,
                'name' => get_string($def['namekey'], 'local_kbacompany'),
                'description' => get_string($def['desckey'], 'local_kbacompany'),
                'category' => get_string($def['categorykey'], 'local_kbacompany'),
                'companyproduct' => get_string($def['productkey'], 'local_kbacompany'),
                'departments' => count($def['departments']),
                'management_units' => count($def['managementunits']),
                'source_label' => get_string('ofstemplates_source_internal', 'local_kbacompany'),
            ];
        }
        return $out;
    }

    /** One catalogue item or null. */
    public static function template_info(string $key): ?array {
        foreach (self::templates() as $item) {
            if ($item['key'] === $key) {
                return $item;
            }
        }
        return null;
    }

    /**
     * Return a normalized OrgDesign payload for a library template.
     *
     * The universal BMS OFS template provides management semantics and visual
     * tiers; only industry-sensitive management units/departments and their
     * suggested VFPs are replaced here.
     */
    public static function payload(string $key): array {
        if (!self::has($key)) {
            throw new \moodle_exception('design_reference', 'local_kbacompany');
        }
        $def = self::definitions()[$key];
        $payload = design_service::template('bms_ofs');
        $payload['companyproduct'] = get_string($def['productkey'], 'local_kbacompany');

        $kept = [];
        foreach ($payload['nodes'] as $node) {
            $tier = (string)($node['visualtier'] ?? '');
            if ($tier === 'department' || $tier === 'management_unit') {
                continue;
            }
            $kept[] = $node;
        }
        $payload['nodes'] = $kept;

        $sort = 120;
        $i = 1;
        foreach ($def['managementunits'] as $unitkey) {
            $payload['nodes'][] = self::node(
                'lib_' . $key . '_m' . $i,
                'M' . $i,
                get_string($unitkey, 'local_kbacompany'),
                'office',
                'management',
                'management_unit',
                $sort,
                ''
            );
            $sort += 10;
            $i++;
        }

        $sort = 200;
        $i = 1;
        foreach ($def['departments'] as $department) {
            $payload['nodes'][] = self::node(
                'lib_' . $key . '_d' . $i,
                (string)$i . '.0',
                get_string($department[0], 'local_kbacompany'),
                'department',
                'executive',
                'department',
                $sort,
                get_string($department[1], 'local_kbacompany')
            );
            $sort += 10;
            $i++;
        }
        return $payload;
    }

    /** Compact preview model for the catalogue page. */
    public static function preview(string $key): array {
        if (!self::has($key)) {
            throw new \moodle_exception('design_reference', 'local_kbacompany');
        }
        $def = self::definitions()[$key];
        $departments = [];
        foreach ($def['departments'] as $department) {
            $departments[] = [
                'name' => get_string($department[0], 'local_kbacompany'),
                'product' => get_string($department[1], 'local_kbacompany'),
            ];
        }
        $units = [];
        foreach ($def['managementunits'] as $unitkey) {
            $units[] = get_string($unitkey, 'local_kbacompany');
        }
        return [
            'key' => $key,
            'name' => get_string($def['namekey'], 'local_kbacompany'),
            'description' => get_string($def['desckey'], 'local_kbacompany'),
            'category' => get_string($def['categorykey'], 'local_kbacompany'),
            'companyproduct' => get_string($def['productkey'], 'local_kbacompany'),
            'departments' => $departments,
            'management_units' => $units,
        ];
    }

    private static function node(string $uuid, string $code, string $name, string $type, string $parent,
            string $tier, int $sortorder, string $product): array {
        return [
            'uuid' => $uuid,
            'sourceuuid' => '',
            'code' => $code,
            'name' => $name,
            'type' => $type,
            'layout_parent' => $parent,
            'reporting_parent' => $parent,
            'branch' => '',
            'visualtier' => $tier,
            'product' => $product,
            'sortorder' => $sortorder,
        ];
    }

    /** Methodology-derived normalized templates. */
    private static function definitions(): array {
        if (self::$definitions !== null) {
            return self::$definitions;
        }
        self::$definitions = [
            'tpl_distribution' => [
                'namekey' => 'ofstpl_distribution_name',
                'desckey' => 'ofstpl_distribution_desc',
                'categorykey' => 'ofstpl_category_trade',
                'productkey' => 'ofstpl_distribution_product',
                'managementunits' => ['ofstpl_unit_regions', 'ofstpl_unit_newmarkets'],
                'departments' => [
                    ['ofstpl_dept_procurement', 'ofstpl_res_procurement'],
                    ['ofstpl_dept_sales', 'ofstpl_res_sales'],
                    ['ofstpl_dept_keyaccounts', 'ofstpl_res_keyaccounts'],
                    ['ofstpl_dept_logistics', 'ofstpl_res_logistics'],
                    ['ofstpl_dept_finance', 'ofstpl_res_finance'],
                    ['ofstpl_dept_people', 'ofstpl_res_people'],
                ],
            ],
            'tpl_dental' => [
                'namekey' => 'ofstpl_dental_name',
                'desckey' => 'ofstpl_dental_desc',
                'categorykey' => 'ofstpl_category_services',
                'productkey' => 'ofstpl_dental_product',
                'managementunits' => ['ofstpl_unit_clinics', 'ofstpl_unit_newclinics'],
                'departments' => [
                    ['ofstpl_dept_clinical', 'ofstpl_res_clinical'],
                    ['ofstpl_dept_patientservice', 'ofstpl_res_patientservice'],
                    ['ofstpl_dept_dentalsales', 'ofstpl_res_dentalsales'],
                    ['ofstpl_dept_people', 'ofstpl_res_people'],
                    ['ofstpl_dept_finance', 'ofstpl_res_finance'],
                    ['ofstpl_dept_qualitysterile', 'ofstpl_res_qualitysterile'],
                ],
            ],
            'tpl_custombuild' => [
                'namekey' => 'ofstpl_custombuild_name',
                'desckey' => 'ofstpl_custombuild_desc',
                'categorykey' => 'ofstpl_category_construction',
                'productkey' => 'ofstpl_custombuild_product',
                'managementunits' => ['ofstpl_unit_projects', 'ofstpl_unit_regions'],
                'departments' => [
                    ['ofstpl_dept_constructionsales', 'ofstpl_res_constructionsales'],
                    ['ofstpl_dept_designestimate', 'ofstpl_res_designestimate'],
                    ['ofstpl_dept_procurement', 'ofstpl_res_procurement'],
                    ['ofstpl_dept_projectdelivery', 'ofstpl_res_projectdelivery'],
                    ['ofstpl_dept_siteworks', 'ofstpl_res_siteworks'],
                    ['ofstpl_dept_qualitysupervision', 'ofstpl_res_qualitysupervision'],
                ],
            ],
            'tpl_meatretail' => [
                'namekey' => 'ofstpl_meatretail_name',
                'desckey' => 'ofstpl_meatretail_desc',
                'categorykey' => 'ofstpl_category_manufacturing',
                'productkey' => 'ofstpl_meatretail_product',
                'managementunits' => ['ofstpl_unit_production', 'ofstpl_unit_retailnetwork'],
                'departments' => [
                    ['ofstpl_dept_rawprocurement', 'ofstpl_res_rawprocurement'],
                    ['ofstpl_dept_foodproduction', 'ofstpl_res_foodproduction'],
                    ['ofstpl_dept_foodquality', 'ofstpl_res_foodquality'],
                    ['ofstpl_dept_logistics', 'ofstpl_res_logistics'],
                    ['ofstpl_dept_retail', 'ofstpl_res_retail'],
                    ['ofstpl_dept_finance', 'ofstpl_res_finance'],
                ],
            ],
            'tpl_boat' => [
                'namekey' => 'ofstpl_boat_name',
                'desckey' => 'ofstpl_boat_desc',
                'categorykey' => 'ofstpl_category_manufacturing',
                'productkey' => 'ofstpl_boat_product',
                'managementunits' => ['ofstpl_unit_projects', 'ofstpl_unit_production'],
                'departments' => [
                    ['ofstpl_dept_sales', 'ofstpl_res_sales'],
                    ['ofstpl_dept_engineering', 'ofstpl_res_engineering'],
                    ['ofstpl_dept_procurement', 'ofstpl_res_procurement'],
                    ['ofstpl_dept_boatproduction', 'ofstpl_res_boatproduction'],
                    ['ofstpl_dept_qualitysupervision', 'ofstpl_res_qualitysupervision'],
                    ['ofstpl_dept_deliveryservice', 'ofstpl_res_deliveryservice'],
                ],
            ],
            'tpl_apparel' => [
                'namekey' => 'ofstpl_apparel_name',
                'desckey' => 'ofstpl_apparel_desc',
                'categorykey' => 'ofstpl_category_retail',
                'productkey' => 'ofstpl_apparel_product',
                'managementunits' => ['ofstpl_unit_stores', 'ofstpl_unit_ecommerce'],
                'departments' => [
                    ['ofstpl_dept_assortment', 'ofstpl_res_assortment'],
                    ['ofstpl_dept_procurement', 'ofstpl_res_procurement'],
                    ['ofstpl_dept_logistics', 'ofstpl_res_logistics'],
                    ['ofstpl_dept_retailops', 'ofstpl_res_retailops'],
                    ['ofstpl_dept_marketingecom', 'ofstpl_res_marketingecom'],
                    ['ofstpl_dept_people', 'ofstpl_res_people'],
                ],
            ],
            'tpl_electrical' => [
                'namekey' => 'ofstpl_electrical_name',
                'desckey' => 'ofstpl_electrical_desc',
                'categorykey' => 'ofstpl_category_services',
                'productkey' => 'ofstpl_electrical_product',
                'managementunits' => ['ofstpl_unit_projects', 'ofstpl_unit_regions'],
                'departments' => [
                    ['ofstpl_dept_tendersales', 'ofstpl_res_tendersales'],
                    ['ofstpl_dept_designestimate', 'ofstpl_res_designestimate'],
                    ['ofstpl_dept_procurement', 'ofstpl_res_procurement'],
                    ['ofstpl_dept_projectdelivery', 'ofstpl_res_projectdelivery'],
                    ['ofstpl_dept_installation', 'ofstpl_res_installation'],
                    ['ofstpl_dept_hseq', 'ofstpl_res_hseq'],
                ],
            ],
            'tpl_cafe' => [
                'namekey' => 'ofstpl_cafe_name',
                'desckey' => 'ofstpl_cafe_desc',
                'categorykey' => 'ofstpl_category_hospitality',
                'productkey' => 'ofstpl_cafe_product',
                'managementunits' => ['ofstpl_unit_cafes', 'ofstpl_unit_newcafes'],
                'departments' => [
                    ['ofstpl_dept_productkitchen', 'ofstpl_res_productkitchen'],
                    ['ofstpl_dept_procurement', 'ofstpl_res_procurement'],
                    ['ofstpl_dept_cafeops', 'ofstpl_res_cafeops'],
                    ['ofstpl_dept_guestservice', 'ofstpl_res_guestservice'],
                    ['ofstpl_dept_marketingecom', 'ofstpl_res_marketingecom'],
                    ['ofstpl_dept_qualitysupervision', 'ofstpl_res_qualitysupervision'],
                ],
            ],
        ];
        return self::$definitions;
    }
}
