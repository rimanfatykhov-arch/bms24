<?php
namespace local_kbacompany\local;

defined('MOODLE_INTERNAL') || die();

/** Unified read model for current and projected OFS states. */
class ofs_workspace_service {
    public const CONTRACT_VERSION = '1.0.0-rc2';

    public static function contract(): array {
        return [
            'component' => 'local_kbacompany',
            'contract' => 'bms_ofs_workspace',
            'version' => self::CONTRACT_VERSION,
            'source_of_truth' => 'canonical OFS + OrgDesignVersion projection',
            'modes' => ['current', 'design', 'compare', 'check'],
            'rules' => [
                'one_workspace_two_states',
                'current_state_uses_canonical_ofs',
                'design_state_uses_versioned_orgdesign',
                'canonical_product_uuid_preferred',
                'legacy_product_text_preserved_but_flagged',
                'comparison_is_read_only',
                'preapply_check_does_not_mutate_live_state',
            ],
        ];
    }

    public static function context(string $companyuuid, int $userid, string $designuuid = '', string $branchuuid = ''): array {
        $live = ofs_service::context($companyuuid, $userid, $branchuuid);
        $livevisual = ofs_visual_designer_service::live_context($companyuuid, $userid);
        $design = ['projects' => [], 'selected' => null, 'version' => null, 'editable' => false,
            'poster' => null, 'payload' => null, 'issues' => [], 'product_catalog' => ofs_visual_designer_service::product_index($companyuuid)];
        $designerror = '';
        if (!empty($live['can_manage_ckp'])) {
            try {
                $design = ofs_visual_designer_service::context($companyuuid, $userid, $designuuid);
            } catch (\moodle_exception $e) {
                $designerror = $e->getMessage();
            }
        }
        $comparison = null;
        $check = null;
        if (!empty($design['selected']) && !empty($design['payload'])) {
            $comparelive = self::live_compare_view($livevisual);
            $comparison = self::compare($comparelive, $design);
            $check = self::preapply_check($comparelive, $design, $comparison);
        }
        return [
            'company' => $live['company'],
            'live' => $live,
            'live_visual' => $livevisual,
            'design' => $design,
            'comparison' => $comparison,
            'check' => $check,
            'can_manage' => !empty($live['can_manage_ckp']),
            'design_error' => $designerror,
            'contract' => self::contract(),
        ];
    }

    private static function live_compare_view(array $livevisual): array {
        $nodes = [];
        $bytarget = $livevisual['product_catalog']['bytarget'] ?? [];
        foreach (($livevisual['payload']['nodes'] ?? []) as $node) {
            $uuid = (string)($node['sourceuuid'] ?? $node['uuid']);
            $products = $bytarget['node:' . $uuid] ?? [];
            $nodes[] = [
                'uuid' => $uuid,
                'code' => (string)$node['code'],
                'name' => (string)$node['name'],
                'parent_uuid' => (string)($node['layout_parent'] ?? ''),
                'products' => $products,
            ];
        }
        return ['nodes' => $nodes];
    }

    private static function compare(array $live, array $design): array {
        $livenodes = [];
        foreach ($live['nodes'] as $node) {
            $livenodes[(string)$node['uuid']] = $node;
        }
        $designnodes = [];
        $represented = [];
        foreach (($design['payload']['nodes'] ?? []) as $node) {
            $designnodes[(string)$node['uuid']] = $node;
            if ((string)($node['sourceuuid'] ?? '') !== '') {
                $represented[(string)$node['sourceuuid']] = true;
            }
        }

        $changes = [];
        $counts = ['added' => 0, 'changed' => 0, 'removed' => 0, 'unchanged' => 0, 'product_warnings' => 0];
        foreach ($designnodes as $node) {
            $sourceuuid = (string)($node['sourceuuid'] ?? '');
            if ($sourceuuid === '' || !isset($livenodes[$sourceuuid])) {
                $counts['added']++;
                $changes[] = ['status' => 'added', 'code' => (string)$node['code'], 'name' => (string)$node['name'],
                    'fields' => ['structure']];
                if ((string)($node['productuuid'] ?? '') === '') { $counts['product_warnings']++; }
                continue;
            }
            $liveitem = $livenodes[$sourceuuid];
            $fields = [];
            if ((string)$liveitem['code'] !== (string)$node['code']) { $fields[] = 'code'; }
            if ((string)$liveitem['name'] !== (string)$node['name']) { $fields[] = 'name'; }
            $desiredparent = '';
            if ((string)($node['layout_parent'] ?? '') !== '' && isset($designnodes[$node['layout_parent']])) {
                $desiredparent = (string)($designnodes[$node['layout_parent']]['sourceuuid'] ?? '');
                if ($desiredparent === '') { $fields[] = 'parent'; }
            }
            if ($desiredparent !== '' && (string)$liveitem['parent_uuid'] !== $desiredparent) { $fields[] = 'parent'; }
            $liveproduct = !empty($liveitem['products']) ? (string)$liveitem['products'][0]['uuid'] : '';
            $designproduct = (string)($node['productuuid'] ?? '');
            if ($designproduct !== '' && $designproduct !== $liveproduct) { $fields[] = 'product'; }
            if ($designproduct === '' && trim((string)($node['product'] ?? '')) !== '') { $counts['product_warnings']++; }
            if ($fields) {
                $counts['changed']++;
                $changes[] = ['status' => 'changed', 'code' => (string)$node['code'], 'name' => (string)$node['name'],
                    'fields' => array_values(array_unique($fields))];
            } else {
                $counts['unchanged']++;
            }
        }
        foreach ($livenodes as $uuid => $node) {
            if (!isset($represented[$uuid])) {
                $counts['removed']++;
                $changes[] = ['status' => 'removed', 'code' => (string)$node['code'], 'name' => (string)$node['name'],
                    'fields' => ['structure']];
            }
        }

        usort($changes, static function(array $a, array $b): int {
            $weight = ['removed' => 0, 'changed' => 1, 'added' => 2];
            return [$weight[$a['status']] ?? 9, $a['code']] <=> [$weight[$b['status']] ?? 9, $b['code']];
        });
        return ['counts' => $counts, 'changes' => $changes];
    }

    private static function preapply_check(array $live, array $design, array $comparison): array {
        $payload = $design['payload'];
        $structural = $design['issues'] ?? [];
        $productissues = [];
        if ((string)($payload['companyproductuuid'] ?? '') === '') {
            $productissues[] = ['kind' => 'company_product', 'label' => get_string('ofsworkspace_issue_companyproduct', 'local_kbacompany')];
        }
        foreach (($payload['nodes'] ?? []) as $node) {
            if ((string)$node['type'] === 'leadership') { continue; }
            if ((string)($node['productuuid'] ?? '') === '') {
                $productissues[] = ['kind' => 'node_product', 'label' => (string)$node['code'] . ' — ' . (string)$node['name']];
            }
        }
        $canonicalcount = 0;
        foreach (($payload['nodes'] ?? []) as $node) {
            if ((string)($node['productuuid'] ?? '') !== '') { $canonicalcount++; }
        }
        return [
            'structural_issues' => $structural,
            'product_issues' => $productissues,
            'comparison' => $comparison,
            'summary' => [
                'nodes' => count($payload['nodes'] ?? []),
                'canonical_products' => $canonicalcount,
                'structural_issues' => count($structural),
                'product_issues' => count($productissues),
                'changes' => $comparison['counts']['added'] + $comparison['counts']['changed'] + $comparison['counts']['removed'],
            ],
            'ready' => count($structural) === 0 && count($productissues) === 0,
        ];
    }
}
