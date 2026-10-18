<?php
namespace local_kbacompany\local;

defined('MOODLE_INTERNAL') || die();

/**
 * Visual projection of an organization design into the BMS OFS poster form.
 *
 * The service does not create a second organization model. It projects the
 * immutable design version plus canonical CKP products into a presentation
 * model used by ofs_designer.php and future export providers.
 */
class ofs_visual_designer_service {
    public const CONTRACT_VERSION = '1.0.0-rc2';

    private const TIERS = [
        'investment',
        'strategic_office',
        'strategic_function',
        'management',
        'management_unit',
        'executive',
        'department',
        'unit',
    ];

    public static function contract(): array {
        return [
            'component' => 'local_kbacompany',
            'contract' => 'bms_ofs_visual_designer',
            'version' => self::CONTRACT_VERSION,
            'source_of_truth' => 'OrgDesignVersion + canonical Product registry',
            'visual_tiers' => self::TIERS,
            'rules' => [
                'visual_projection_is_not_a_second_org_model',
                'company_bottom_result_is_named_valuable_final_product',
                'department_results_are_products_not_activity_lists',
                'draft_visual_metadata_is_versioned_inside_design_payload',
                'canonical_product_uuid_is_preferred_over_legacy_text',
                'live_products_are_used_as_fallback_for_applied_source_nodes',
                'exports_must_be_generated_from_the_same_visual_model',
            ],
            'exports' => ['svg', 'pdf', 'drawio'],
        ];
    }

    public static function tiers(): array {
        return self::TIERS;
    }

    /** Build a complete visual designer context. */
    public static function context(string $companyuuid, int $userid, string $designuuid = ''): array {
        global $DB;

        $company = company_context_service::context($companyuuid, $userid);
        $canmanage = product_service::can_manage($companyuuid, $userid);
        if (!$canmanage) {
            $payload = self::live_payload($companyuuid, $userid);
            return [
                'company' => $company,
                'projects' => [],
                'selected' => null,
                'version' => null,
                'editable' => false,
                'live' => true,
                'can_manage' => false,
                'poster' => self::poster($payload, self::product_index($companyuuid)['bytarget'],
                    self::product_index($companyuuid)['company']),
                'contract' => self::contract(),
            ];
        }

        $catalog = design_service::context($companyuuid, $userid);
        if ($designuuid === '') {
            foreach ($catalog['projects'] as $project) {
                if ($project->status === 'draft') {
                    $designuuid = (string)$project->uuid;
                    break;
                }
            }
            if ($designuuid === '' && !empty($catalog['projects'])) {
                $designuuid = (string)$catalog['projects'][0]->uuid;
            }
        }

        $selected = null;
        if ($designuuid !== '') {
            $selected = design_service::context($companyuuid, $userid, $designuuid);
        }

        $productindex = self::product_index($companyuuid);
        $productsbytarget = $productindex['bytarget'];
        $companyproducts = $productindex['company'];

        $model = [
            'company' => $catalog['company'],
            'projects' => $catalog['projects'],
            'selected' => $selected['selected'] ?? null,
            'version' => $selected['version'] ?? null,
            'editable' => false,
            'live' => false,
            'can_manage' => true,
            'poster' => null,
            'payload' => null,
            'issues' => [],
            'product_catalog' => $productindex,
            'contract' => self::contract(),
        ];
        if (!$selected || !$selected['payload']) {
            return $model;
        }

        $payload = $selected['payload'];
        $model['editable'] = $selected['selected']->status === 'draft' &&
            (int)$selected['selected']->revision === (int)$selected['version']->revision;
        $model['payload'] = $payload;
        $model['issues'] = $selected['issues'] ?? [];
        $model['poster'] = self::poster($payload, $productsbytarget, $companyproducts);
        return $model;
    }



    public static function product_index(string $companyuuid): array {
        global $DB;
        $bytarget = [];
        $company = [];
        $all = [];
        $byuuid = [];
        foreach ($DB->get_records('local_kbacompany_product', ['companyuuid' => $companyuuid], 'code ASC') as $product) {
            $item = [
                'uuid' => (string)$product->uuid,
                'code' => (string)$product->code,
                'name' => (string)$product->name,
                'acceptance' => (string)$product->acceptance,
            ];
            $all[] = $item + ['targettype' => (string)$product->targettype, 'targetuuid' => (string)$product->targetuuid];
            $byuuid[(string)$product->uuid] = $item + ['targettype' => (string)$product->targettype, 'targetuuid' => (string)$product->targetuuid];
            if ($product->targettype === 'company') {
                $company[] = $item;
            }
            $bytarget[$product->targettype . ':' . $product->targetuuid][] = $item;
        }
        return ['bytarget' => $bytarget, 'company' => $company, 'all' => $all, 'byuuid' => $byuuid];
    }

    /** Current canonical OFS projected through the same poster renderer used by design mode. */
    public static function live_context(string $companyuuid, int $userid): array {
        $company = company_context_service::context($companyuuid, $userid);
        $index = self::product_index($companyuuid);
        $payload = self::live_payload($companyuuid, $userid);
        return [
            'company' => $company,
            'payload' => $payload,
            'poster' => self::poster($payload, $index['bytarget'], $index['company']),
            'product_catalog' => $index,
            'contract' => self::contract(),
        ];
    }

    /** Build a read-only design-shaped payload from the current live organization. */
    private static function live_payload(string $companyuuid, int $userid): array {
        global $DB;
        $productindex = self::product_index($companyuuid);
        $companyproduct = $productindex['company'][0] ?? null;
        $payload = [
            'schema' => 2,
            'name' => get_string('ofsdesigner_live', 'local_kbacompany'),
            'mission' => '',
            'intent' => '',
            'companyproduct' => $companyproduct ? (string)$companyproduct['name'] : '',
            'companyproductuuid' => $companyproduct ? (string)$companyproduct['uuid'] : '',
            'showmission' => 0,
            'showintent' => 0,
            'nodes' => [],
            'positions' => [],
            'functions' => [],
        ];
        foreach ($DB->get_records('local_kbacompany_orgnode',
                ['companyuuid' => $companyuuid, 'active' => 1], 'sortorder ASC, code ASC') as $node) {
            $payload['nodes'][] = [
                'uuid' => (string)$node->uuid,
                'sourceuuid' => (string)$node->uuid,
                'code' => (string)$node->code,
                'name' => (string)$node->name,
                'type' => (string)$node->nodetype,
                'layout_parent' => (string)$node->parentuuid,
                'reporting_parent' => (string)$node->parentuuid,
                'branch' => (string)$node->branchuuid,
                'visualtier' => '',
                'product' => !empty($productindex['bytarget']['node:' . $node->uuid]) ?
                    (string)$productindex['bytarget']['node:' . $node->uuid][0]['name'] : '',
                'productuuid' => !empty($productindex['bytarget']['node:' . $node->uuid]) ?
                    (string)$productindex['bytarget']['node:' . $node->uuid][0]['uuid'] : '',
                'sortorder' => (int)$node->sortorder,
                'color' => (string)$node->color,
            ];
        }
        $positiontonode = [];
        foreach ($DB->get_records('local_kbacompany_position',
                ['companyuuid' => $companyuuid, 'active' => 1], 'sortorder ASC, code ASC') as $position) {
            $positiontonode[(string)$position->uuid] = (string)$position->orgnodeuuid;
            $payload['positions'][] = [
                'uuid' => (string)$position->uuid,
                'nodeuuid' => (string)$position->orgnodeuuid,
                'code' => (string)$position->code,
                'name' => (string)$position->name,
                'plannedfte' => (string)max(1, (int)$position->plannedcount),
                'managerial' => !empty($position->ismanager) ? 1 : 0,
            ];
        }
        try {
            $functions = function_service::context($companyuuid, $userid)['functions'];
            foreach ($functions as $function) {
                $positionuuid = (string)($function['position_uuid'] ?? '');
                $nodeuuid = $positiontonode[$positionuuid] ?? '';
                if ($nodeuuid === '') {
                    continue;
                }
                $payload['functions'][] = [
                    'uuid' => (string)$function['uuid'],
                    'nodeuuid' => $nodeuuid,
                    'code' => (string)$function['code'],
                    'name' => (string)$function['name'],
                    'result' => (string)($function['product_name'] ?? $function['product_text'] ?? ''),
                    'positionuuid' => $positionuuid,
                ];
            }
        } catch (\Throwable $e) {
            // A read-only organization visual must remain available even if the function layer is unavailable.
        }
        return $payload;
    }

    /** Project one design payload into the agreed BMS OFS poster structure. */
    public static function poster(array $payload, array $productsbytarget = [], array $companyproducts = []): array {
        $nodes = [];
        foreach ($payload['nodes'] ?? [] as $node) {
            $nodes[$node['uuid']] = $node;
        }

        $tiers = [];
        foreach (self::TIERS as $tier) {
            $tiers[$tier] = [];
        }
        $positions = [];
        foreach ($payload['positions'] ?? [] as $position) {
            $positions[$position['nodeuuid']][] = $position;
        }
        $functions = [];
        foreach ($payload['functions'] ?? [] as $function) {
            $functions[$function['nodeuuid']][] = $function;
        }

        foreach ($nodes as $uuid => $node) {
            $tier = self::tier_for_node($node, $nodes);
            $sourceuuid = (string)($node['sourceuuid'] ?? '');
            $liveproducts = $sourceuuid === '' ? [] : ($productsbytarget['node:' . $sourceuuid] ?? []);
            $productuuid = trim((string)($node['productuuid'] ?? ''));
            $canonicalproduct = null;
            if ($productuuid !== '') {
                foreach ($liveproducts as $candidate) {
                    if ((string)$candidate['uuid'] === $productuuid) { $canonicalproduct = $candidate; break; }
                }
            }
            $product = $canonicalproduct ? (string)$canonicalproduct['name'] : trim((string)($node['product'] ?? ''));
            if ($product === '' && $liveproducts) {
                $canonicalproduct = $liveproducts[0];
                $productuuid = (string)$canonicalproduct['uuid'];
                $product = (string)$canonicalproduct['name'];
            }
            $tiers[$tier][] = [
                'uuid' => (string)$node['uuid'],
                'sourceuuid' => $sourceuuid,
                'code' => (string)$node['code'],
                'name' => (string)$node['name'],
                'type' => (string)$node['type'],
                'visualtier' => $tier,
                'sortorder' => (int)$node['sortorder'],
                'product' => $product,
                'product_uuid' => $productuuid,
                'product_code' => $canonicalproduct ? (string)$canonicalproduct['code'] : '',
                'product_canonical' => $canonicalproduct !== null,
                'has_product' => $product !== '',
                'positions' => array_values($positions[$uuid] ?? []),
                'functions' => array_values($functions[$uuid] ?? []),
                'layout_parent' => (string)($node['layout_parent'] ?? ''),
            ];
        }
        foreach ($tiers as &$items) {
            usort($items, static function(array $a, array $b): int {
                return [$a['sortorder'], $a['code']] <=> [$b['sortorder'], $b['code']];
            });
        }
        unset($items);

        $companyproductuuid = trim((string)($payload['companyproductuuid'] ?? ''));
        $companycanonical = null;
        if ($companyproductuuid !== '') {
            foreach ($companyproducts as $candidate) {
                if ((string)$candidate['uuid'] === $companyproductuuid) { $companycanonical = $candidate; break; }
            }
        }
        $companyproduct = $companycanonical ? (string)$companycanonical['name'] : trim((string)($payload['companyproduct'] ?? ''));
        if ($companyproduct === '' && $companyproducts) {
            $companycanonical = $companyproducts[0];
            $companyproductuuid = (string)$companycanonical['uuid'];
            $companyproduct = (string)$companycanonical['name'];
        }

        return [
            'name' => (string)($payload['name'] ?? ''),
            'companyproduct' => $companyproduct,
            'companyproduct_uuid' => $companyproductuuid,
            'companyproduct_code' => $companycanonical ? (string)$companycanonical['code'] : '',
            'companyproduct_canonical' => $companycanonical !== null,
            'has_companyproduct' => $companyproduct !== '',
            'tiers' => $tiers,
            'investment' => $tiers['investment'],
            'strategic_office' => $tiers['strategic_office'],
            'strategic_functions' => $tiers['strategic_function'],
            'management' => $tiers['management'],
            'management_units' => $tiers['management_unit'],
            'executive' => $tiers['executive'],
            'departments' => array_merge($tiers['department'], $tiers['unit']),
        ];
    }

    private static function tier_for_node(array $node, array $nodes): string {
        $explicit = (string)($node['visualtier'] ?? '');
        if (in_array($explicit, self::TIERS, true)) {
            return $explicit;
        }

        $depth = 0;
        $parent = (string)($node['layout_parent'] ?? '');
        $guard = [];
        while ($parent !== '' && isset($nodes[$parent]) && !isset($guard[$parent])) {
            $guard[$parent] = true;
            $depth++;
            $parent = (string)($nodes[$parent]['layout_parent'] ?? '');
        }
        if ($depth === 0) {
            return 'investment';
        }
        if ($depth === 1) {
            return 'strategic_office';
        }

        $parentuuid = (string)($node['layout_parent'] ?? '');
        $parenttier = '';
        if ($parentuuid !== '' && isset($nodes[$parentuuid])) {
            $parentexplicit = (string)($nodes[$parentuuid]['visualtier'] ?? '');
            if (in_array($parentexplicit, self::TIERS, true)) {
                $parenttier = $parentexplicit;
            }
        }
        if ($parenttier === 'strategic_office' && in_array($node['type'], ['office', 'service', 'section'], true)) {
            return 'strategic_function';
        }
        if ($parenttier === 'management') {
            return $node['type'] === 'leadership' ? 'executive' : 'management_unit';
        }
        if ($node['type'] === 'leadership') {
            return $depth <= 2 ? 'management' : 'executive';
        }
        if (in_array($node['type'], ['division', 'department'], true)) {
            return 'department';
        }
        return 'unit';
    }
}
