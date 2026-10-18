<?php
namespace local_kbacompany\local;

defined('MOODLE_INTERNAL') || die();

/**
 * Product-functional view of the organising functional scheme (OFS).
 *
 * OFS does not duplicate canonical organization, product, function or metric records. It projects
 * the existing sources into the question "who produces which result and for whom?".
 */
class ofs_service {
    public const CONTRACT_VERSION = '0.3.2';

    public static function contract(): array {
        $contract = [
            'component' => 'local_kbacompany',
            'contract' => 'bms_ofs_ckp',
            'version' => self::CONTRACT_VERSION,
            'company_scope' => 'required',
            'views' => ['organizational', 'product_functional'],
            'product_levels' => ['company', 'orgunit', 'position', 'function'],
            'interface_model' => [
                'supplier' => 'canonical product target',
                'internal_customer' => 'semantic relation orgunit consumes product',
            ],
            'sources' => [
                'organization' => 'local_kbacompany_orgnode',
                'positions' => 'local_kbacompany_position',
                'products' => 'local_kbacompany_product',
                'metrics' => 'local_kbacompany_stat',
                'functions' => 'function_service + local_kbacompany_acobj:function compatibility',
                'function_result' => 'canonical function product + legacy local_kbacompany_acobj:result',
                'interfaces' => 'local_kbacompany_semrel',
            ],
            'rules' => [
                'ofs_is_not_only_reporting_hierarchy',
                'define_product_before_actions',
                'canonical_objects_are_not_duplicated',
                'company_and_orgunit_ckp_use_product_registry',
                'function_product_uses_canonical_function_product_or_legacy_function_result',
                'internal_customer_links_are_explicit_and_append_only',
                'process_owner_and_product_links_are_canonical',
                'process_derived_functions_project_to_position_orgunit_when_assigned',
            ],
        ];
        $contract['schema_hash_algorithm'] = 'sha256';
        $contract['schema_hash'] = hash('sha256', json_encode($contract,
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        return $contract;
    }

    public static function can_manage_ckp(string $companyuuid, int $userid): bool {
        return permission_service::can_manage_organization($companyuuid, $userid);
    }

    public static function can_manage_interfaces(string $companyuuid, int $userid): bool {
        return permission_service::can_manage_management($companyuuid, $userid);
    }

    /** Add an explicit internal-customer relation without copying product or unit data. */
    public static function link_internal_customer(string $companyuuid, int $userid, string $productuuid,
            string $customerorgunituuid, string $reason = ''): string {
        if (!self::can_manage_interfaces($companyuuid, $userid)) {
            throw new \required_capability_exception(\context_system::instance(),
                'local/kbacompany:manageorganization', 'nopermissions', '');
        }
        return semantic_service::command_relation($companyuuid, $userid, 'create', [
            'fromtype' => 'orgunit',
            'fromuuid' => $customerorgunituuid,
            'relation' => 'consumes',
            'totype' => 'product',
            'touuid' => $productuuid,
            'reason' => trim($reason),
        ]);
    }

    /** Archive an existing internal-customer relation; history is retained by Semantic Core. */
    public static function unlink_internal_customer(string $companyuuid, int $userid, string $relationuuid,
            int $revision, string $reason): void {
        if (!self::can_manage_interfaces($companyuuid, $userid)) {
            throw new \required_capability_exception(\context_system::instance(),
                'local/kbacompany:manageorganization', 'nopermissions', '');
        }
        semantic_service::command_relation($companyuuid, $userid, 'archive', [
            'object' => $relationuuid,
            'revision' => $revision,
            'reason' => $reason,
        ]);
    }

    /** Latest active explicit semantic relations. */
    private static function active_relations(string $companyuuid): array {
        global $DB;
        $latest = [];
        foreach ($DB->get_records('local_kbacompany_semrel', ['companyuuid' => $companyuuid], 'revision ASC') as $row) {
            $latest[$row->uuid] = $row;
        }
        return array_values(array_filter($latest, static function(\stdClass $row): bool {
            return $row->status === 'active';
        }));
    }

    /** Return the canonical supplier label for one CKP without inventing a reporting relation. */
    private static function supplier(array $product, array $nodes, array $positions, array $company): array {
        if ($product['target_type'] === 'company') {
            return ['type' => 'company', 'uuid' => $company['uuid'], 'name' => $company['name']];
        }
        if ($product['target_type'] === 'node' && isset($nodes[$product['target_uuid']])) {
            $node = $nodes[$product['target_uuid']];
            return ['type' => 'orgunit', 'uuid' => $node['uuid'], 'name' => $node['name']];
        }
        if ($product['target_type'] === 'position' && isset($positions[$product['target_uuid']])) {
            $position = $positions[$product['target_uuid']];
            return ['type' => 'position', 'uuid' => $position['uuid'], 'name' => $position['name']];
        }
        return ['type' => 'unknown', 'uuid' => $product['target_uuid'], 'name' => ''];
    }

    /**
     * Product-functional OFS context.
     *
     * The context derives views from current canonical stores. It does not persist a second OFS model.
     */
    public static function context(string $companyuuid, int $userid, string $branchuuid = ''): array {
        global $DB;
        $board = organizing_board_service::board_context($companyuuid, $userid, $branchuuid);
        $company = $board['company'];

        $nodes = [];
        foreach ($board['nodes'] as $node) {
            $node['products'] = [];
            $node['position_products'] = [];
            $node['functions'] = [];
            $node['internal_inputs'] = [];
            $nodes[$node['uuid']] = $node;
        }

        $positionrows = $DB->get_records('local_kbacompany_position',
            ['companyuuid' => $companyuuid, 'active' => 1], 'sortorder ASC, code ASC');
        $positions = [];
        foreach ($positionrows as $row) {
            $positions[$row->uuid] = [
                'uuid' => (string)$row->uuid,
                'node_uuid' => (string)$row->orgnodeuuid,
                'code' => (string)$row->code,
                'name' => (string)$row->name,
                'is_manager' => !empty($row->ismanager),
            ];
        }

        $metricrows = $DB->get_records('local_kbacompany_stat', ['companyuuid' => $companyuuid], 'code ASC');
        $metricsbyproduct = [];
        foreach ($metricrows as $row) {
            $metricsbyproduct[$row->productuuid][] = [
                'uuid' => (string)$row->uuid,
                'code' => (string)$row->code,
                'name' => (string)$row->name,
                'unit' => (string)$row->unit,
                'period' => (string)$row->periodtype,
                'direction' => (string)$row->direction,
            ];
        }

        $products = [];
        $companyproducts = [];
        foreach ($DB->get_records('local_kbacompany_product', ['companyuuid' => $companyuuid], 'code ASC') as $row) {
            $product = [
                'uuid' => (string)$row->uuid,
                'code' => (string)$row->code,
                'name' => (string)$row->name,
                'acceptance' => (string)$row->acceptance,
                'target_type' => (string)$row->targettype,
                'target_uuid' => (string)$row->targetuuid,
                'metrics' => $metricsbyproduct[$row->uuid] ?? [],
                'customers' => [],
                'functions' => [],
                'processes' => [],
            ];
            $products[$row->uuid] = $product;
        }

        // Current function/result objects remain draft foundation until a later Function release. Reuse them here.
        $acobjects = $DB->get_records('local_kbacompany_acobj', ['companyuuid' => $companyuuid], 'code ASC');
        $functions = [];
        $results = [];
        foreach ($acobjects as $row) {
            if ($row->status === 'archived') {
                continue;
            }
            $payload = json_decode((string)$row->payload, true) ?: [];
            if ($row->kind === 'function') {
                $functions[$row->uuid] = [
                    'uuid' => (string)$row->uuid,
                    'code' => (string)$row->code,
                    'name' => (string)$row->name,
                    'node_uuid' => (string)($payload['node'] ?? ''),
                    'position_uuid' => (string)($payload['position'] ?? ''),
                    'main_metric_uuid' => (string)($payload['mainstat'] ?? ''),
                    'product' => null,
                    'result_name' => '',
                    'acceptance' => '',
                ];
            } else if ($row->kind === 'result') {
                $results[$row->parentuuid] = [
                    'name' => (string)$row->name,
                    'product_uuid' => (string)($payload['product'] ?? ''),
                    'acceptance' => (string)($payload['acceptance'] ?? ''),
                ];
            }
        }
        foreach ($functions as $functionuuid => &$function) {
            if (isset($results[$functionuuid])) {
                $result = $results[$functionuuid];
                $function['result_name'] = $result['name'];
                $function['acceptance'] = $result['acceptance'];
                if ($result['product_uuid'] !== '' && isset($products[$result['product_uuid']])) {
                    $function['product'] = $products[$result['product_uuid']];
                    $products[$result['product_uuid']]['functions'][] = [
                        'uuid' => $function['uuid'], 'code' => $function['code'], 'name' => $function['name'],
                    ];
                }
            }
            if (isset($nodes[$function['node_uuid']])) {
                $nodes[$function['node_uuid']]['functions'][] = $function;
            }
        }
        unset($function);

        // v0.3.2 canonical Process-derived Functions coexist with legacy administrative-cascade functions.
        foreach (function_service::semantic_functions($companyuuid, $userid) as $canonical) {
            if (isset($functions[$canonical['uuid']])) { continue; }
            $nodeuuid = '';
            if ($canonical['position_uuid'] !== '' && isset($positions[$canonical['position_uuid']])) {
                $nodeuuid = (string)$positions[$canonical['position_uuid']]['node_uuid'];
            }
            $productview = null;
            if ($canonical['product_uuid'] !== '' && isset($products[$canonical['product_uuid']])) {
                $productview = $products[$canonical['product_uuid']];
                $products[$canonical['product_uuid']]['functions'][] = [
                    'uuid' => $canonical['uuid'], 'code' => $canonical['code'], 'name' => $canonical['name'],
                ];
            } else if ($canonical['product_name'] !== '') {
                $productview = ['uuid' => '', 'code' => '', 'name' => $canonical['product_name'],
                    'acceptance' => '', 'target_type' => '', 'target_uuid' => '', 'metrics' => [],
                    'customers' => [], 'functions' => [], 'processes' => []];
            }
            $function = [
                'uuid' => $canonical['uuid'], 'code' => $canonical['code'], 'name' => $canonical['name'],
                'node_uuid' => $nodeuuid, 'position_uuid' => $canonical['position_uuid'], 'main_metric_uuid' => '',
                'product' => $productview, 'result_name' => $canonical['product_name'], 'acceptance' => '',
                'source' => 'process_model', 'source_process_uuid' => $canonical['source_process_uuid'],
                'source_activity_uuid' => $canonical['source_activity_uuid'], 'status' => $canonical['status'],
            ];
            $functions[$canonical['uuid']] = $function;
            if ($nodeuuid !== '' && isset($nodes[$nodeuuid])) { $nodes[$nodeuuid]['functions'][] = $function; }
        }

        // Explicit internal-customer links: consuming orgunit -> product. Producer remains canonical target.
        $interfaces = [];
        foreach (self::active_relations($companyuuid) as $relation) {
            if ($relation->fromtype === 'orgunit' && $relation->relation === 'consumes' &&
                    $relation->totype === 'product' && isset($nodes[$relation->fromuuid]) &&
                    isset($products[$relation->touuid])) {
                $customer = [
                    'relation_uuid' => (string)$relation->uuid,
                    'relation_revision' => (int)$relation->revision,
                    'orgunit_uuid' => (string)$relation->fromuuid,
                    'orgunit_name' => (string)$nodes[$relation->fromuuid]['name'],
                ];
                $products[$relation->touuid]['customers'][] = $customer;
                $nodes[$relation->fromuuid]['internal_inputs'][] = [
                    'product_uuid' => (string)$relation->touuid,
                    'product_code' => (string)$products[$relation->touuid]['code'],
                    'product_name' => (string)$products[$relation->touuid]['name'],
                    'relation_uuid' => (string)$relation->uuid,
                    'relation_revision' => (int)$relation->revision,
                ];
                $interfaces[] = [
                    'relation_uuid' => (string)$relation->uuid,
                    'revision' => (int)$relation->revision,
                    'product_uuid' => (string)$relation->touuid,
                    'customer_orgunit_uuid' => (string)$relation->fromuuid,
                ];
            }
        }

        // Canonical Process Architecture is available from v0.2.9.2. Link by owner and product, not by forced department=process identity.
        $processcontext = process_service::context($companyuuid, $userid);
        $processes = $processcontext['processes'];
        foreach ($processes as $process) {
            $brief = [
                'uuid' => $process['uuid'], 'code' => $process['code'], 'name' => $process['name'],
                'type' => $process['type'], 'level' => $process['level'], 'status' => $process['status'],
            ];
            if ($process['product_uuid'] !== '' && isset($products[$process['product_uuid']])) {
                $products[$process['product_uuid']]['processes'][] = $brief;
            }
            if ($process['owner_orgunit_uuid'] !== '' && isset($nodes[$process['owner_orgunit_uuid']])) {
                if (!isset($nodes[$process['owner_orgunit_uuid']]['processes'])) {
                    $nodes[$process['owner_orgunit_uuid']]['processes'] = [];
                }
                $nodes[$process['owner_orgunit_uuid']]['processes'][] = $brief;
            }
        }

        // Reassign enriched products into their canonical OFS targets.
        foreach ($products as &$product) {
            $product['supplier'] = self::supplier($product, $nodes, $positions, $company);
            if ($product['target_type'] === 'company' && $product['target_uuid'] === $companyuuid) {
                $companyproducts[] = $product;
            } else if ($product['target_type'] === 'node' && isset($nodes[$product['target_uuid']])) {
                $nodes[$product['target_uuid']]['products'][] = $product;
            } else if ($product['target_type'] === 'position' && isset($positions[$product['target_uuid']])) {
                $position = $positions[$product['target_uuid']];
                if (isset($nodes[$position['node_uuid']])) {
                    $nodes[$position['node_uuid']]['position_products'][] = [
                        'position' => $position, 'product' => $product,
                    ];
                }
            }
        }
        unset($product);

        $nodeswithproduct = 0;
        foreach ($nodes as $node) {
            if (!empty($node['products'])) {
                $nodeswithproduct++;
            }
        }
        $functionswithproduct = count(array_filter($functions, static function(array $function): bool {
            return $function['product'] !== null;
        }));

        return [
            'company' => $company,
            'branches' => $board['branches'],
            'selected_branch_uuid' => $board['selected_branch_uuid'],
            'company_products' => $companyproducts,
            'nodes' => array_values($nodes),
            'positions' => array_values($positions),
            'products' => array_values($products),
            'functions' => array_values($functions),
            'interfaces' => $interfaces,
            'processes_available' => true,
            'can_manage_ckp' => self::can_manage_ckp($companyuuid, $userid),
            'can_manage_interfaces' => self::can_manage_interfaces($companyuuid, $userid),
            'summary' => [
                'company_ckp' => count($companyproducts),
                'org_units' => count($nodes),
                'org_units_with_ckp' => $nodeswithproduct,
                'org_units_without_ckp' => count($nodes) - $nodeswithproduct,
                'functions' => count($functions),
                'functions_with_product' => $functionswithproduct,
                'functions_without_product' => count($functions) - $functionswithproduct,
                'internal_interfaces' => count($interfaces),
                'metrics' => count($metricrows),
                'processes' => count($processes),
            ],
        ];
    }
}
