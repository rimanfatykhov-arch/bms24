<?php
namespace local_kbacompany\local;
defined('MOODLE_INTERNAL') || die();

/** Isolated organizing-board projects with append-only versions. */
class design_service {
    private static function fail(string $key): void { throw new \moodle_exception('design_' . $key, 'local_kbacompany'); }
    private static function uuid(): string {
        $h = bin2hex(random_bytes(16));
        return substr($h, 0, 8) . '-' . substr($h, 8, 4) . '-4' . substr($h, 13, 3) . '-8' . substr($h, 17, 3) . '-' . substr($h, 20);
    }
    private static function access(string $company, int $actor): array {
        require_capability('local/kbacompany:viewowncompany', \context_system::instance(), $actor);
        $c = company_context_service::context($company, $actor);
        if (!product_service::can_manage($company, $actor)) { self::fail('permission'); }
        return $c;
    }
    public static function contract(): array {
        return ['version' => '0.4.6.14', 'objects' => ['OrgDesignProject', 'OrgDesignVersion', 'OrgTemplate', 'ImportedOrgTemplate', 'ImportedOrgTemplateVersion',
            'DesignedFunction', 'DesignedPosition', 'StaffingDemand', 'OrgDesignApproval', 'DirectReporting'],
            'rules' => ['isolated_drafts', 'append_only_versions', 'explicit_approval',
                'additive_live_apply', 'optimistic_live_conflict_detection', 'no_employee_copy', 'no_live_staffing_apply'],
            'templates' => array_merge(['bms_ofs', 'seven', 'directors', 'custom', 'node_fragments', 'importtpl'], ofs_template_library_service::keys()),
            'editor' => ['visual_levels', 'layout_parent', 'reporting_parent', 'sort_order', 'header_visibility'],
            'design' => ['functions', 'positions', 'planned_fte', 'responsibility_checks'],
            'delivery' => ['stable_code_comparison', 'review', 'approval', 'safe_apply', 'pdf', 'svg', 'drawio'],
            'visual_ofs' => ['visual_tier', 'node_product', 'company_product', 'poster_projection'],
            'deferred' => ['drag_and_drop', 'live_function_apply', 'live_staffing_apply']];
    }
    private static function text(array $data, string $key, int $max, bool $required = true): string {
        $s = trim((string)($data[$key] ?? ''));
        if (($required && $s === '') || \core_text::strlen($s) > $max) { self::fail('invalid'); }
        return $s;
    }
    private static function project(string $company, string $uuid): \stdClass {
        global $DB;
        $r = $DB->get_record('local_kbacompany_design', ['companyuuid' => $company, 'uuid' => $uuid]);
        if (!$r) { self::fail('reference'); }
        return $r;
    }
    public static function template(string $key): array {
        if (ofs_template_library_service::has($key)) {
            return ofs_template_library_service::payload($key);
        }
        if (!in_array($key, ['bms_ofs', 'seven', 'directors'], true)) { self::fail('reference'); }
        $nodes = [];
        $add = static function(string $id, string $code, string $name, string $type, string $parent) use (&$nodes): void {
            $nodes[] = ['uuid' => $id, 'sourceuuid' => '', 'code' => $code, 'name' => $name,
                'type' => $type, 'layout_parent' => $parent, 'reporting_parent' => '', 'branch' => ''];
        };
        if ($key === 'bms_ofs') {
            $nodes = [];
            $addvisual = static function(string $id, string $code, string $name, string $type, string $parent,
                    string $tier, int $sortorder) use (&$nodes): void {
                $nodes[] = ['uuid' => $id, 'sourceuuid' => '', 'code' => $code, 'name' => $name,
                    'type' => $type, 'layout_parent' => $parent, 'reporting_parent' => $parent, 'branch' => '',
                    'visualtier' => $tier, 'product' => '', 'sortorder' => $sortorder];
            };
            $addvisual('investment', 'INV', get_string('ofsdesigner_template_investment', 'local_kbacompany'),
                'leadership', '', 'investment', 10);
            $addvisual('strategic', 'STRAT', get_string('ofsdesigner_template_strategic', 'local_kbacompany'),
                'leadership', 'investment', 'strategic_office', 20);
            $strategic = [
                ['sf1', 'S1', 'ofsdesigner_template_strategydev'],
                ['sf2', 'S2', 'ofsdesigner_template_marketingproduct'],
                ['sf3', 'S3', 'ofsdesigner_template_fincontrol'],
                ['sf4', 'S4', 'ofsdesigner_template_legal'],
                ['sf5', 'S5', 'ofsdesigner_template_projectoffice'],
                ['sf6', 'S6', 'ofsdesigner_template_security'],
                ['sf7', 'S7', 'ofsdesigner_template_infrastructure'],
            ];
            $order = 30;
            foreach ($strategic as $item) {
                $addvisual($item[0], $item[1], get_string($item[2], 'local_kbacompany'),
                    'service', 'strategic', 'strategic_function', $order);
                $order += 10;
            }
            $addvisual('management', 'MGMT', get_string('ofsdesigner_template_management', 'local_kbacompany'),
                'leadership', 'strategic', 'management', 110);
            for ($i = 1; $i <= 4; $i++) {
                $addvisual('region' . $i, 'R' . $i, get_string('ofsdesigner_template_region', 'local_kbacompany', $i),
                    'office', 'management', 'management_unit', 110 + ($i * 10));
            }
            $addvisual('newmarkets', 'NEW', get_string('ofsdesigner_template_newmarkets', 'local_kbacompany'),
                'office', 'management', 'management_unit', 160);
            $addvisual('executive', 'EXEC', get_string('ofsdesigner_template_executive', 'local_kbacompany'),
                'leadership', 'management', 'executive', 170);
            $departments = [
                ['d1', '1.0', 'ofsdesigner_template_hr'],
                ['d2', '2.0', 'ofsdesigner_template_sales'],
                ['d3', '3.0', 'ofsdesigner_template_finance'],
                ['d4', '4.0', 'ofsdesigner_template_delivery'],
                ['d5', '5.0', 'ofsdesigner_template_quality'],
                ['d6', '6.0', 'ofsdesigner_template_growth'],
            ];
            $order = 200;
            foreach ($departments as $item) {
                $addvisual($item[0], $item[1], get_string($item[2], 'local_kbacompany'),
                    'department', 'executive', 'department', $order);
                $order += 10;
            }
            return self::normalise(['schema' => 2, 'mission' => '', 'intent' => '', 'companyproduct' => '',
                'showmission' => 1, 'showintent' => 1, 'nodes' => $nodes]);
        }

        $add('admin', '7.0.0', get_string('division7', 'local_kbacompany'), 'division', '');
        $add('executive', 'EXEC', get_string('design_executive', 'local_kbacompany'), 'leadership', 'admin');
        if ($key === 'directors') {
            foreach (['hr', 'commercial', 'operations'] as $name) {
                $add($name, strtoupper($name), get_string('design_' . $name, 'local_kbacompany'), 'leadership', 'executive');
            }
        }
        $parents = [1 => 'hr', 2 => 'commercial', 3 => 'executive', 4 => 'operations', 5 => 'operations', 6 => 'commercial'];
        for ($i = 1; $i <= 6; $i++) {
            $add('d' . $i, $i . '.0.0', get_string('division' . $i, 'local_kbacompany'), 'division',
                $key === 'directors' ? $parents[$i] : 'executive');
        }
        return self::normalise(['schema' => 2, 'mission' => '', 'intent' => '',
            'showmission' => 1, 'showintent' => 1, 'nodes' => $nodes]);
    }

    private static function normalise(array $payload): array {
        $payload['schema'] = 2;
        $payload['mission'] = (string)($payload['mission'] ?? '');
        $payload['intent'] = (string)($payload['intent'] ?? '');
        $payload['companyproduct'] = (string)($payload['companyproduct'] ?? '');
        $payload['companyproductuuid'] = (string)($payload['companyproductuuid'] ?? '');
        $payload['showmission'] = array_key_exists('showmission', $payload) ? (int)!empty($payload['showmission']) : 1;
        $payload['showintent'] = array_key_exists('showintent', $payload) ? (int)!empty($payload['showintent']) : 1;
        $payload['functions'] = isset($payload['functions']) && is_array($payload['functions']) ? $payload['functions'] : [];
        $payload['positions'] = isset($payload['positions']) && is_array($payload['positions']) ? $payload['positions'] : [];
        $payload['nodes'] = isset($payload['nodes']) && is_array($payload['nodes']) ? $payload['nodes'] : [];
        foreach ($payload['nodes'] as $i => &$node) {
            if (!is_array($node)) { self::fail('invalid'); }
            $node['sourceuuid'] = (string)($node['sourceuuid'] ?? '');
            $node['layout_parent'] = (string)($node['layout_parent'] ?? '');
            $node['reporting_parent'] = (string)($node['reporting_parent'] ?? '');
            $node['branch'] = (string)($node['branch'] ?? '');
            $node['sourcehash'] = (string)($node['sourcehash'] ?? '');
            $node['visualtier'] = (string)($node['visualtier'] ?? '');
            $node['product'] = (string)($node['product'] ?? '');
            $node['productuuid'] = (string)($node['productuuid'] ?? '');
            $node['sortorder'] = isset($node['sortorder']) ? (int)$node['sortorder'] : (($i + 1) * 10);
            $node['color'] = (string)($node['color'] ?? ($node['type'] === 'leadership' ? '#173B6C' : '#00843D'));
        }
        unset($node);
        foreach ($payload['functions'] as &$function) {
            if (!is_array($function)) { self::fail('invalid'); }
            $function['uuid'] = (string)($function['uuid'] ?? '');
            $function['nodeuuid'] = (string)($function['nodeuuid'] ?? '');
            $function['code'] = (string)($function['code'] ?? '');
            $function['name'] = (string)($function['name'] ?? '');
            $function['result'] = (string)($function['result'] ?? '');
            $function['positionuuid'] = (string)($function['positionuuid'] ?? '');
        }
        unset($function);
        foreach ($payload['positions'] as &$position) {
            if (!is_array($position)) { self::fail('invalid'); }
            $position['uuid'] = (string)($position['uuid'] ?? '');
            $position['nodeuuid'] = (string)($position['nodeuuid'] ?? '');
            $position['code'] = (string)($position['code'] ?? '');
            $position['name'] = (string)($position['name'] ?? '');
            $position['plannedfte'] = str_replace(',', '.', (string)($position['plannedfte'] ?? '1'));
            $position['managerial'] = (int)!empty($position['managerial']);
        }
        unset($position);
        return $payload;
    }

    private static function validate_payload(array $payload): void {
        if (empty($payload['nodes']) || \core_text::strlen($payload['mission']) > 4000 ||
                \core_text::strlen($payload['intent']) > 4000 || \core_text::strlen($payload['companyproduct']) > 4000 ||
                ($payload['companyproductuuid'] !== '' && !preg_match('/^[a-f0-9-]{36}$/D', $payload['companyproductuuid']))) {
            self::fail('invalid');
        }
        $ids = [];
        $codes = [];
        foreach ($payload['nodes'] as $node) {
            if (empty($node['uuid']) || isset($ids[$node['uuid']]) || empty($node['code']) ||
                    isset($codes[\core_text::strtolower($node['code'])]) || empty($node['name']) ||
                    !in_array($node['type'], ['leadership', 'division', 'department', 'section', 'service', 'office'], true) ||
                    !preg_match('/^#[0-9A-Fa-f]{6}$/', $node['color']) ||
                    ($node['sourcehash'] !== '' && !preg_match('/^[0-9a-f]{64}$/', $node['sourcehash'])) ||
                    ($node['visualtier'] !== '' && !in_array($node['visualtier'], ['investment', 'strategic_office',
                        'strategic_function', 'management', 'management_unit', 'executive', 'department', 'unit'], true)) ||
                    \core_text::strlen($node['product']) > 4000 ||
                    ($node['productuuid'] !== '' && !preg_match('/^[a-f0-9-]{36}$/D', $node['productuuid'])) ||
                    $node['sortorder'] < 0 || $node['sortorder'] > 9999) {
                self::fail('invalid');
            }
            $ids[$node['uuid']] = $node;
            $codes[\core_text::strtolower($node['code'])] = true;
        }
        foreach (['layout_parent', 'reporting_parent'] as $field) {
            foreach ($ids as $uuid => $node) {
                $seen = [$uuid => true];
                $parent = $node[$field];
                while ($parent !== '') {
                    if (!isset($ids[$parent]) || isset($seen[$parent])) { self::fail('cycle'); }
                    $seen[$parent] = true;
                    $parent = $ids[$parent][$field];
                }
            }
        }
        $positionids = [];
        $positioncodes = [];
        foreach ($payload['positions'] as $position) {
            $code = \core_text::strtolower($position['code']);
            if ($position['uuid'] === '' || isset($positionids[$position['uuid']]) || $position['name'] === '' ||
                    $position['code'] === '' || isset($positioncodes[$code]) || !isset($ids[$position['nodeuuid']]) ||
                    !preg_match('/^(?:0\.(?:0[1-9]|[1-9][0-9])|[1-9][0-9]{0,2}(?:\.[0-9]{1,2})?)$/', $position['plannedfte'])) {
                self::fail('invalid');
            }
            $positionids[$position['uuid']] = $position;
            $positioncodes[$code] = true;
        }
        $functionids = [];
        $functioncodes = [];
        foreach ($payload['functions'] as $function) {
            $code = \core_text::strtolower($function['code']);
            if ($function['uuid'] === '' || isset($functionids[$function['uuid']]) || $function['name'] === '' ||
                    $function['result'] === '' || $function['code'] === '' || isset($functioncodes[$code]) ||
                    !isset($ids[$function['nodeuuid']]) || ($function['positionuuid'] !== '' &&
                    (!isset($positionids[$function['positionuuid']]) ||
                    $positionids[$function['positionuuid']]['nodeuuid'] !== $function['nodeuuid']))) {
                self::fail('invalid');
            }
            $functionids[$function['uuid']] = true;
            $functioncodes[$code] = true;
        }
    }

    public static function responsibility_issues(array $payload): array {
        $payload = self::normalise($payload);
        self::validate_payload($payload);
        $issues = [];
        foreach ($payload['nodes'] as $node) {
            $positions = array_values(array_filter($payload['positions'], static function(array $position) use ($node): bool {
                return $position['nodeuuid'] === $node['uuid'];
            }));
            $functions = array_values(array_filter($payload['functions'], static function(array $function) use ($node): bool {
                return $function['nodeuuid'] === $node['uuid'];
            }));
            if (!$positions) { $issues[] = ['key' => 'issue_node_position', 'label' => $node['code'] . ' — ' . $node['name']]; }
            if ($node['type'] !== 'leadership' && !$functions) {
                $issues[] = ['key' => 'issue_node_function', 'label' => $node['code'] . ' — ' . $node['name']];
            }
            $managers = array_filter($positions, static function(array $position): bool { return !empty($position['managerial']); });
            if (count($managers) === 0) { $issues[] = ['key' => 'issue_node_manager', 'label' => $node['code'] . ' — ' . $node['name']]; }
            if (count($managers) > 1) { $issues[] = ['key' => 'issue_node_multimanager', 'label' => $node['code'] . ' — ' . $node['name']]; }
        }
        foreach ($payload['functions'] as $function) {
            if ($function['positionuuid'] === '') {
                $issues[] = ['key' => 'issue_function_owner', 'label' => $function['code'] . ' — ' . $function['name']];
            }
        }
        return $issues;
    }

    private static function compare_collection(array $left, array $right, array $fields): array {
        $map = static function(array $items): array {
            $result = [];
            foreach ($items as $item) { $result[\core_text::strtolower($item['code'])] = $item; }
            return $result;
        };
        $a = $map($left); $b = $map($right); $result = [];
        foreach (array_unique(array_merge(array_keys($a), array_keys($b))) as $key) {
            if (!isset($a[$key])) { $result[] = ['status' => 'added', 'code' => $b[$key]['code'], 'fields' => []]; continue; }
            if (!isset($b[$key])) { $result[] = ['status' => 'removed', 'code' => $a[$key]['code'], 'fields' => []]; continue; }
            $changed = [];
            foreach ($fields as $field) {
                if (($a[$key][$field] ?? null) !== ($b[$key][$field] ?? null)) { $changed[] = $field; }
            }
            if ($changed) { $result[] = ['status' => 'changed', 'code' => $b[$key]['code'], 'fields' => $changed]; }
        }
        usort($result, static function(array $x, array $y): int { return $x['code'] <=> $y['code']; });
        return $result;
    }

    private static function comparison_view(array $payload): array {
        $nodes = array_column($payload['nodes'], 'code', 'uuid');
        $positions = array_column($payload['positions'], 'code', 'uuid');
        foreach ($payload['nodes'] as &$node) {
            $node['layout_parent'] = $nodes[$node['layout_parent']] ?? '';
            $node['reporting_parent'] = $nodes[$node['reporting_parent']] ?? '';
        }
        unset($node);
        foreach ($payload['positions'] as &$position) {
            $position['nodeuuid'] = $nodes[$position['nodeuuid']] ?? '';
        }
        unset($position);
        foreach ($payload['functions'] as &$function) {
            $function['nodeuuid'] = $nodes[$function['nodeuuid']] ?? '';
            $function['positionuuid'] = $positions[$function['positionuuid']] ?? '';
        }
        unset($function);
        return $payload;
    }

    public static function compare_versions(string $company, int $actor, string $uuid, int $from, int $to): array {
        $left = self::comparison_view(self::normalise(
            json_decode(self::version($company, $actor, $uuid, $from)->payload, true)));
        $right = self::comparison_view(self::normalise(
            json_decode(self::version($company, $actor, $uuid, $to)->payload, true)));
        return ['from' => $from, 'to' => $to,
            'header' => array_values(array_filter(['name', 'mission', 'intent', 'showmission', 'showintent', 'companyproduct', 'companyproductuuid'],
                static function(string $field) use ($left, $right): bool {
                    return ($left[$field] ?? null) !== ($right[$field] ?? null);
                })),
            'nodes' => self::compare_collection($left['nodes'], $right['nodes'],
                ['name', 'type', 'layout_parent', 'reporting_parent', 'sortorder', 'color', 'product', 'productuuid']),
            'positions' => self::compare_collection($left['positions'], $right['positions'],
                ['name', 'nodeuuid', 'plannedfte', 'managerial']),
            'functions' => self::compare_collection($left['functions'], $right['functions'],
                ['name', 'nodeuuid', 'result', 'positionuuid'])];
    }

    /** Fingerprint the live structural fields that an isolated design is allowed to overwrite. */
    private static function live_node_fingerprint(\stdClass $node, string $reportingparent): string {
        $data = [
            'branchuuid' => (string)$node->branchuuid,
            'parentuuid' => (string)$node->parentuuid,
            'reportingparent' => $reportingparent,
            'code' => (string)$node->code,
            'nodetype' => (string)$node->nodetype,
            'name' => (string)$node->name,
            'sortorder' => (int)$node->sortorder,
            'color' => (string)$node->color,
            'active' => (int)$node->active,
        ];
        return hash('sha256', json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }

    private static function live_reporting_parent(string $company, \stdClass $node): string {
        global $DB;
        $report = $DB->get_record('local_kbacompany_orgreport',
            ['companyuuid' => $company, 'nodeuuid' => $node->uuid], 'manageruuid', IGNORE_MISSING);
        return $report ? (string)$report->manageruuid : (string)$node->parentuuid;
    }

    private static function validate_product_reference(string $company, string $productuuid, string $targettype,
            string $targetuuid): void {
        global $DB;
        if ($productuuid === '') {
            return;
        }
        $product = $DB->get_record('local_kbacompany_product', [
            'companyuuid' => $company, 'uuid' => $productuuid,
        ], '*', MUST_EXIST);
        if ((string)$product->targettype !== $targettype || (string)$product->targetuuid !== $targetuuid) {
            self::fail('reference');
        }
    }

    private static function apply_payload(string $company, array $payload, \stdClass $project,
            int $revision, string $targetbranch): array {
        global $DB;
        self::validate_payload($payload);
        if (self::responsibility_issues($payload)) { self::fail('notready'); }
        $branch = $DB->get_record('local_kbacompany_orgbranch',
            ['companyuuid' => $company, 'uuid' => $targetbranch, 'active' => 1]);
        if (!$branch) { self::fail('branch'); }
        $nodes = array_column($payload['nodes'], null, 'uuid');
        $live = []; $branches = [];
        foreach ($nodes as $uuid => $node) {
            $record = $node['sourceuuid'] === '' ? false : $DB->get_record('local_kbacompany_orgnode',
                ['companyuuid' => $company, 'uuid' => $node['sourceuuid']]);
            if ($node['sourceuuid'] !== '' && !$record) {
                self::fail('applyconflict');
            }
            if ($record) {
                $reportingparent = self::live_reporting_parent($company, $record);
                if ($node['sourcehash'] !== '') {
                    $currenthash = self::live_node_fingerprint($record, $reportingparent);
                    if (!hash_equals($node['sourcehash'], $currenthash)) {
                        self::fail('applyconflict');
                    }
                } else {
                    // Legacy v0.2.7.x/v0.2.8.0 snapshots do not contain sourcehash.
                    // Protect them using modification timestamps whenever the live node or its
                    // direct-reporting relation changed after the design project was created.
                    $report = $DB->get_record('local_kbacompany_orgreport',
                        ['companyuuid' => $company, 'nodeuuid' => $record->uuid],
                        'timemodified', IGNORE_MISSING);
                    if ((int)$record->timemodified > (int)$project->timecreated ||
                            ($report && (int)$report->timemodified > (int)$project->timecreated)) {
                        self::fail('applyconflict');
                    }
                }
            }
            $live[$uuid] = $record ?: null;
        }
        $resolvebranch = static function(string $uuid) use (&$resolvebranch, &$branches, $nodes, $live, $targetbranch): string {
            if (isset($branches[$uuid])) { return $branches[$uuid]; }
            if ($live[$uuid]) { return $branches[$uuid] = (string)$live[$uuid]->branchuuid; }
            $parent = $nodes[$uuid]['layout_parent'];
            return $branches[$uuid] = $parent === '' ? $targetbranch : $resolvebranch($parent);
        };
        foreach ($nodes as $uuid => $node) { $resolvebranch($uuid); }
        foreach ($nodes as $uuid => $node) {
            if ($node['layout_parent'] !== '' && $branches[$node['layout_parent']] !== $branches[$uuid]) {
                self::fail('applyconflict');
            }
            $collision = $DB->get_record('local_kbacompany_orgnode', ['companyuuid' => $company,
                'branchuuid' => $branches[$uuid], 'code' => $node['code']]);
            if ($collision && (!$live[$uuid] || $collision->uuid !== $live[$uuid]->uuid)) { self::fail('applyconflict'); }
        }
        $ids = [];
        foreach ($nodes as $uuid => $node) { $ids[$uuid] = $live[$uuid] ? (string)$live[$uuid]->uuid : self::uuid(); }
        $now = time();
        foreach ($nodes as $uuid => $node) {
            $record = $live[$uuid] ?: (object)['companyuuid' => $company, 'uuid' => $ids[$uuid],
                'sourcecomponent' => 'local_kbacompany', 'sourceentity' => 'design', 'sourceid' => 0,
                'timecreated' => $now];
            $record->branchuuid = $branches[$uuid];
            $record->parentuuid = $node['layout_parent'] === '' ? '' : $ids[$node['layout_parent']];
            $record->code = $node['code']; $record->nodetype = $node['type']; $record->name = $node['name'];
            $record->sortorder = $node['sortorder']; $record->color = $node['color'];
            $record->active = 1; $record->timemodified = $now;
            if ($live[$uuid]) { $DB->update_record('local_kbacompany_orgnode', $record); }
            else { $DB->insert_record('local_kbacompany_orgnode', $record); }
            $report = $DB->get_record('local_kbacompany_orgreport', ['companyuuid' => $company, 'nodeuuid' => $ids[$uuid]]);
            $report = $report ?: (object)['companyuuid' => $company, 'nodeuuid' => $ids[$uuid], 'timecreated' => $now];
            $report->manageruuid = $node['reporting_parent'] === '' ? '' : $ids[$node['reporting_parent']];
            $report->designuuid = $project->uuid; $report->designrevision = $revision;
            $report->timemodified = $now;
            if (!empty($report->id)) { $DB->update_record('local_kbacompany_orgreport', $report); }
            else { $DB->insert_record('local_kbacompany_orgreport', $report); }
            $index = array_search($uuid, array_column($payload['nodes'], 'uuid'), true);
            $payload['nodes'][$index]['sourceuuid'] = $ids[$uuid];
            $payload['nodes'][$index]['branch'] = $branches[$uuid];
            $payload['nodes'][$index]['sourcehash'] = self::live_node_fingerprint($record, (string)$report->manageruuid);
        }
        return $payload;
    }
    private static function remap(array $payload): array {
        $ids = [];
        foreach ($payload['nodes'] as $n) { $ids[$n['uuid']] = self::uuid(); }
        $positionids = [];
        foreach ($payload['positions'] ?? [] as $position) { $positionids[$position['uuid']] = self::uuid(); }
        foreach ($payload['nodes'] as &$n) {
            $n['uuid'] = $ids[$n['uuid']];
            foreach (['layout_parent', 'reporting_parent'] as $key) {
                $n[$key] = $n[$key] === '' ? '' : ($ids[$n[$key]] ?? '');
            }
        }
        unset($n);
        if (!empty($payload['positions'])) {
            foreach ($payload['positions'] as &$position) {
                $position['uuid'] = $positionids[$position['uuid']];
                $position['nodeuuid'] = $ids[$position['nodeuuid']] ?? '';
            }
            unset($position);
        }
        if (!empty($payload['functions'])) {
            foreach ($payload['functions'] as &$function) {
                $function['uuid'] = self::uuid();
                $function['nodeuuid'] = $ids[$function['nodeuuid']] ?? '';
                $function['positionuuid'] = $function['positionuuid'] === '' ? '' :
                    ($positionids[$function['positionuuid']] ?? '');
            }
            unset($function);
        }
        return $payload;
    }
    public static function version(string $company, int $actor, string $uuid, int $revision): \stdClass {
        global $DB;
        self::access($company, $actor);
        self::project($company, $uuid);
        $r = $DB->get_record('local_kbacompany_designver', ['companyuuid' => $company,
            'designuuid' => $uuid, 'revision' => $revision]);
        if (!$r || !hash_equals($r->digest, hash('sha256', $r->payload))) { self::fail('reference'); }
        return $r;
    }
    public static function command(string $company, int $actor, string $action, array $data): string {
        global $DB;
        self::access($company, $actor);
        // Same lock as the existing board seeder when capturing a live board snapshot.
        $lock = \core\lock\lock_config::get_lock_factory('local_kbacompany')->get_lock('organizing_board:' . $company, 10);
        if (!$lock) { self::fail('lock'); }
        try {
            $tx = $DB->start_delegated_transaction();
            $old = null;
            if ($action === 'create') {
                $source = self::text($data, 'source', 36);
                $payload = null;
                $sourceuuid = '';
                $sourcerevision = 0;
                if (in_array($source, ['bms_ofs', 'seven', 'directors'], true)) { $payload = self::template($source); }
                else if ($source === 'library') {
                    $templatekey = self::text($data, 'templatekey', 36);
                    if (!ofs_template_library_service::has($templatekey)) { self::fail('reference'); }
                    $payload = ofs_template_library_service::payload($templatekey);
                    $sourceuuid = $templatekey;
                    $sourcerevision = 1;
                } else if ($source === 'importtpl') {
                    $sourceuuid = self::text($data, 'sourceuuid', 36);
                    $imported = legacy_drawio_import_service::available_payload($company, $actor, $sourceuuid);
                    $payload = $imported['payload'];
                    $sourcerevision = (int)$imported['revision'];
                }
                else if ($source === 'live') {
                    $companyproduct = $DB->get_record('local_kbacompany_product', [
                        'companyuuid' => $company, 'targettype' => 'company', 'targetuuid' => $company,
                    ], '*', IGNORE_MULTIPLE);
                    $nodeproducts = [];
                    foreach ($DB->get_records('local_kbacompany_product', [
                            'companyuuid' => $company, 'targettype' => 'node'], 'code ASC') as $product) {
                        if (!isset($nodeproducts[$product->targetuuid])) {
                            $nodeproducts[$product->targetuuid] = $product;
                        }
                    }
                    $payload = ['schema' => 2, 'mission' => '', 'intent' => '',
                        'companyproduct' => $companyproduct ? (string)$companyproduct->name : '',
                        'companyproductuuid' => $companyproduct ? (string)$companyproduct->uuid : '',
                        'showmission' => 1, 'showintent' => 1, 'nodes' => []];
                    foreach ($DB->get_records('local_kbacompany_orgnode', ['companyuuid' => $company, 'active' => 1], 'sortorder ASC, code ASC') as $n) {
                        $reportingparent = self::live_reporting_parent($company, $n);
                        $payload['nodes'][] = ['uuid' => $n->uuid, 'sourceuuid' => $n->uuid, 'code' => $n->code,
                            'name' => $n->name, 'type' => $n->nodetype, 'layout_parent' => $n->parentuuid,
                            'reporting_parent' => $reportingparent, 'branch' => $n->branchuuid,
                            'sourcehash' => self::live_node_fingerprint($n, $reportingparent),
                            'product' => isset($nodeproducts[$n->uuid]) ? (string)$nodeproducts[$n->uuid]->name : '',
                            'productuuid' => isset($nodeproducts[$n->uuid]) ? (string)$nodeproducts[$n->uuid]->uuid : '',
                            'sortorder' => (int)$n->sortorder, 'color' => $n->color];
                    }
                } else if ($source === 'version') {
                    $sourceuuid = self::text($data, 'sourceuuid', 36);
                    $sourcerevision = (int)($data['sourcerevision'] ?? 0);
                    $payload = json_decode(self::version($company, $actor, $sourceuuid, $sourcerevision)->payload, true);
                } else if ($source === 'custom') {
                    $sourceuuid = self::text($data, 'sourceuuid', 36);
                    $template = self::project($company, $sourceuuid);
                    if ($template->status !== 'template') { self::fail('reference'); }
                    $sourcerevision = (int)$template->revision;
                    $payload = json_decode(self::version($company, $actor, $sourceuuid, $sourcerevision)->payload, true);
                } else { self::fail('reference'); }
                $payload = self::normalise(self::remap($payload));
                self::validate_payload($payload);
                $new = (object)['companyuuid' => $company, 'uuid' => self::uuid(),
                    'name' => self::text($data, 'name', 255), 'sourcekind' => $source,
                    'sourceuuid' => $sourceuuid, 'sourcerevision' => $sourcerevision,
                    'status' => 'draft', 'revision' => 1, 'timecreated' => time(), 'timemodified' => time()];
                $DB->insert_record('local_kbacompany_design', $new);
            } else {
                $old = self::project($company, self::text($data, 'object', 36));
                $states = ['draft' => ['save', 'rename', 'node', 'insert', 'position', 'function',
                    'visualnode', 'visualsettings', 'maketemplate', 'archive', 'submit'], 'review' => ['approve', 'return'],
                    'approved' => ['apply']];
                if (!in_array($action, $states[$old->status] ?? [], true)) { self::fail('state'); }
                if ((int)$old->revision !== (int)($data['revision'] ?? 0)) { self::fail('conflict'); }
                $payload = self::normalise(json_decode(self::version($company, $actor,
                    $old->uuid, (int)$old->revision)->payload, true));
                $new = clone $old;
                if ($action === 'save') {
                    $new->name = self::text($data, 'name', 255);
                    $payload['mission'] = self::text($data, 'mission', 4000, false);
                    $payload['intent'] = self::text($data, 'intent', 4000, false);
                    $payload['showmission'] = !empty($data['showmission']) ? 1 : 0;
                    $payload['showintent'] = !empty($data['showintent']) ? 1 : 0;
                } else if ($action === 'visualsettings') {
                    $payload['companyproduct'] = self::text($data, 'companyproduct', 4000, false);
                    $payload['companyproductuuid'] = self::text($data, 'companyproductuuid', 36, false);
                    self::validate_product_reference($company, $payload['companyproductuuid'], 'company', $company);
                } else if ($action === 'visualnode') {
                    $found = false;
                    $tiers = ['investment', 'strategic_office', 'strategic_function', 'management',
                        'management_unit', 'executive', 'department', 'unit'];
                    $tier = self::text($data, 'visualtier', 32);
                    if (!in_array($tier, $tiers, true)) { self::fail('invalid'); }
                    foreach ($payload['nodes'] as &$node) {
                        if ($node['uuid'] !== ($data['node'] ?? '')) { continue; }
                        $node['name'] = self::text($data, 'name', 255);
                        $node['visualtier'] = $tier;
                        $node['product'] = self::text($data, 'product', 4000, false);
                        $node['productuuid'] = self::text($data, 'productuuid', 36, false);
                        if ($node['productuuid'] !== '') {
                            if ($node['sourceuuid'] === '') { self::fail('reference'); }
                            self::validate_product_reference($company, $node['productuuid'], 'node', $node['sourceuuid']);
                        }
                        $found = true;
                    }
                    unset($node);
                    if (!$found) { self::fail('reference'); }
                } else if ($action === 'rename') {
                    $found = false;
                    foreach ($payload['nodes'] as &$node) {
                        if ($node['uuid'] === ($data['node'] ?? '')) {
                            $node['name'] = self::text($data, 'name', 255);
                            $found = true;
                        }
                    }
                    unset($node);
                    if (!$found) { self::fail('reference'); }
                } else if ($action === 'node') {
                    $found = false;
                    foreach ($payload['nodes'] as &$node) {
                        if ($node['uuid'] !== ($data['node'] ?? '')) { continue; }
                        $node['code'] = self::text($data, 'code', 50);
                        $node['name'] = self::text($data, 'name', 255);
                        $node['type'] = self::text($data, 'type', 20);
                        $node['layout_parent'] = self::text($data, 'layout_parent', 36, false);
                        $node['reporting_parent'] = self::text($data, 'reporting_parent', 36, false);
                        $node['sortorder'] = (int)($data['sortorder'] ?? -1);
                        $node['color'] = self::text($data, 'color', 7);
                        $found = true;
                    }
                    unset($node);
                    if (!$found) { self::fail('reference'); }
                } else if ($action === 'insert') {
                    $type = self::text($data, 'type', 20);
                    if (!in_array($type, ['leadership', 'division', 'department', 'section', 'service', 'office'], true)) {
                        self::fail('invalid');
                    }
                    $payload['nodes'][] = ['uuid' => self::uuid(), 'sourceuuid' => '',
                        'code' => self::text($data, 'code', 50), 'name' => self::text($data, 'name', 255),
                        'type' => $type, 'layout_parent' => self::text($data, 'layout_parent', 36, false),
                        'reporting_parent' => self::text($data, 'reporting_parent', 36, false), 'branch' => '',
                        'visualtier' => self::text($data, 'visualtier', 32, false),
                        'productuuid' => '',
                        'product' => self::text($data, 'product', 4000, false),
                        'sortorder' => (int)($data['sortorder'] ?? 100),
                        'color' => self::text($data, 'color', 7)];
                } else if ($action === 'position') {
                    $item = self::text($data, 'item', 36, false);
                    $record = ['uuid' => $item === '' ? self::uuid() : $item,
                        'nodeuuid' => self::text($data, 'targetnode', 36),
                        'code' => self::text($data, 'code', 50), 'name' => self::text($data, 'name', 255),
                        'plannedfte' => str_replace(',', '.', self::text($data, 'plannedfte', 8)),
                        'managerial' => !empty($data['managerial']) ? 1 : 0];
                    $found = false;
                    foreach ($payload['positions'] as &$position) {
                        if ($item !== '' && $position['uuid'] === $item) { $position = $record; $found = true; }
                    }
                    unset($position);
                    if ($item !== '' && !$found) { self::fail('reference'); }
                    if ($item === '') { $payload['positions'][] = $record; }
                    foreach ($payload['functions'] as &$function) {
                        if ($function['positionuuid'] === $record['uuid'] &&
                                $function['nodeuuid'] !== $record['nodeuuid']) {
                            $function['positionuuid'] = '';
                        }
                    }
                    unset($function);
                } else if ($action === 'function') {
                    $item = self::text($data, 'item', 36, false);
                    $record = ['uuid' => $item === '' ? self::uuid() : $item,
                        'nodeuuid' => self::text($data, 'targetnode', 36),
                        'code' => self::text($data, 'code', 50), 'name' => self::text($data, 'name', 255),
                        'result' => self::text($data, 'result', 4000),
                        'positionuuid' => self::text($data, 'position', 36, false)];
                    $found = false;
                    foreach ($payload['functions'] as &$function) {
                        if ($item !== '' && $function['uuid'] === $item) { $function = $record; $found = true; }
                    }
                    unset($function);
                    if ($item !== '' && !$found) { self::fail('reference'); }
                    if ($item === '') { $payload['functions'][] = $record; }
                } else if ($action === 'maketemplate') {
                    self::text($data, 'reason', 4000);
                    $new->status = 'template';
                } else if ($action === 'submit') {
                    self::text($data, 'reason', 4000);
                    if (self::responsibility_issues($payload)) { self::fail('notready'); }
                    $new->status = 'review';
                } else if ($action === 'return') {
                    self::text($data, 'reason', 4000);
                    $new->status = 'draft';
                } else if ($action === 'approve') {
                    self::text($data, 'reason', 4000);
                    $new->status = 'approved';
                } else if ($action === 'apply') {
                    self::text($data, 'reason', 4000);
                    $payload = self::apply_payload($company, $payload, $new, (int)$new->revision,
                        self::text($data, 'branch', 36));
                    $new->status = 'applied';
                } else if ($action === 'archive') { self::text($data, 'reason', 4000); $new->status = 'archived'; }
                else { self::fail('invalid'); }
                self::validate_payload($payload);
                $new->revision++;
                $new->timemodified = time();
                $DB->update_record('local_kbacompany_design', $new);
            }
            $payload['name'] = $new->name;
            $payload['status'] = $new->status;
            $json = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            $DB->insert_record('local_kbacompany_designver', (object)['companyuuid' => $company, 'uuid' => self::uuid(),
                'designuuid' => $new->uuid, 'revision' => $new->revision, 'payload' => $json,
                'digest' => hash('sha256', $json), 'timecreated' => time()]);
            $DB->insert_record('local_kbacompany_aclog', (object)['companyuuid' => $company, 'objectuuid' => $new->uuid,
                'actorid' => $actor, 'action' => 'design_' . $action, 'oldvalue' => json_encode($old),
                'newvalue' => json_encode(['record' => $new, 'reason' => self::text($data, 'reason', 4000, false)], JSON_UNESCAPED_UNICODE),
                'timecreated' => time()]);
            $tx->allow_commit();
            return $new->uuid;
        } catch (\Throwable $e) {
            if (isset($tx)) { $tx->rollback($e); }
            throw $e;
        } finally { $lock->release(); }
    }
    public static function context(string $company, int $actor, string $uuid = '', int $revision = 0): array {
        global $DB;
        $c = self::access($company, $actor);
        $selected = $uuid === '' ? null : self::project($company, $uuid);
        $v = $selected ? self::version($company, $actor, $uuid, $revision ?: (int)$selected->revision) : null;
        $projects = array_values($DB->get_records('local_kbacompany_design',
            ['companyuuid' => $company], 'timemodified DESC, id DESC'));
        $branches = array_values($DB->get_records('local_kbacompany_orgbranch',
            ['companyuuid' => $company, 'active' => 1], 'sortorder ASC, name ASC', 'uuid, code, name, isdefault'));
        return ['company' => $c, 'projects' => $projects, 'branches' => $branches,
            'templates' => array_values(array_filter($projects, static function(\stdClass $project): bool {
                return $project->status === 'template';
            })), 'selected' => $selected,
            'version' => $v, 'payload' => $v ? self::normalise(json_decode($v->payload, true)) : null,
            'issues' => $v ? self::responsibility_issues(json_decode($v->payload, true)) : [],
            'versions' => $selected ? array_values($DB->get_records('local_kbacompany_designver',
                ['companyuuid' => $company, 'designuuid' => $uuid], 'revision DESC', 'id, revision, digest, timecreated')) : []];
    }
}
