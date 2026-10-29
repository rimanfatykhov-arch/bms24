<?php
namespace local_kbacompany\local;

defined('MOODLE_INTERNAL') || die();

/** Company-scoped organizing-board service. */
class organizing_board_service {
    public const CONTRACT_VERSION = '0.2.7.3';

    public static function contract(): array {
        $contract = [
            'component' => 'local_kbacompany',
            'contract' => 'kba_organizing_board',
            'version' => self::CONTRACT_VERSION,
            'company_scope' => 'required',
            'objects' => ['OrganizationBranch', 'OrganizingBoardNode', 'DirectReporting'],
            'node_types' => ['leadership', 'division', 'department', 'section', 'service', 'office'],
            'write_policy' => 'explicit_authorised_commands',
            'template_policy' => 'never_seeded_by_upgrade',
            'reference_model' => 'seven_division_organizing_board',
            'guarantees' => [
                'stable_uuid_identity',
                'hierarchical_codes_supported',
                'multiple_branches_supported',
                'template_install_is_idempotent',
                'no_formula_import',
                'no_automatic_staffing',
            ],
        ];
        $contract['schema_hash_algorithm'] = 'sha256';
        $contract['schema_hash'] = hash('sha256', json_encode($contract,
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        return $contract;
    }

    public static function can_manage(string $companyuuid, int $userid): bool {
        return permission_service::can_manage_organization($companyuuid, $userid);
    }

    /** Return an authorised organizing-board snapshot. */
    public static function board_context(string $companyuuid, int $userid, string $branchuuid = ''): array {
        global $DB;

        $company = company_context_service::context($companyuuid, $userid);
        $branches = $DB->get_records('local_kbacompany_orgbranch',
            ['companyuuid' => $companyuuid, 'active' => 1], 'sortorder ASC, name ASC');
        $branchdtos = [];
        foreach ($branches as $branch) {
            $branchdtos[] = self::branch_dto($branch);
        }
        if ($branchuuid === '' && $branchdtos) {
            $default = array_values(array_filter($branchdtos, static function(array $branch): bool {
                return $branch['is_default'];
            }));
            $branchuuid = ($default[0] ?? $branchdtos[0])['uuid'];
        }
        if ($branchuuid !== '' && !$DB->record_exists('local_kbacompany_orgbranch', [
                'companyuuid' => $companyuuid, 'uuid' => $branchuuid, 'active' => 1])) {
            throw new \moodle_exception('branchnotavailable', 'local_kbacompany');
        }
        $nodes = [];
        if ($branchuuid !== '') {
            $reporting = $DB->get_records('local_kbacompany_orgreport', ['companyuuid' => $companyuuid], '',
                'nodeuuid, manageruuid, designuuid, designrevision');
            $records = $DB->get_records('local_kbacompany_orgnode', [
                'companyuuid' => $companyuuid, 'branchuuid' => $branchuuid, 'active' => 1,
            ], 'sortorder ASC, code ASC');
            foreach ($records as $record) {
                $dto = self::node_dto($record);
                $dto['reporting_parent_uuid'] = (string)($reporting[$record->uuid]->manageruuid ?? '');
                $dto['reporting_design_uuid'] = (string)($reporting[$record->uuid]->designuuid ?? '');
                $dto['reporting_design_revision'] = (int)($reporting[$record->uuid]->designrevision ?? 0);
                $nodes[] = $dto;
            }
        }
        return [
            'company' => $company,
            'branches' => $branchdtos,
            'selected_branch_uuid' => $branchuuid,
            'nodes' => $nodes,
            'summary' => [
                'branches' => count($branchdtos),
                'nodes' => count($nodes),
                'divisions' => count(array_filter($nodes, static function(array $node): bool {
                    return $node['type'] === 'division';
                })),
            ],
            'can_manage' => self::can_manage($companyuuid, $userid),
        ];
    }

    /** Explicitly install the idempotent seven-division template. */
    public static function install_seven_division_template(string $companyuuid, int $userid): array {
        global $DB;

        if (!self::can_manage($companyuuid, $userid)) {
            throw new \required_capability_exception(\context_system::instance(),
                'local/kbacompany:manageorganization', 'nopermissions', '');
        }
        $lock = \core\lock\lock_config::get_lock_factory('local_kbacompany')
            ->get_lock('organizing_board:' . $companyuuid, 10);
        if (!$lock) {
            throw new \moodle_exception('organizingboardlockfailed', 'local_kbacompany');
        }
        try {
            $transaction = $DB->start_delegated_transaction();
            $now = time();
            $branch = $DB->get_record('local_kbacompany_orgbranch',
                ['companyuuid' => $companyuuid, 'code' => 'main']);
            if (!$branch) {
                $branch = (object)[
                    'companyuuid' => $companyuuid,
                    'uuid' => self::uuid_v4(),
                    'code' => 'main',
                    'name' => get_string('mainbranch', 'local_kbacompany'),
                    'isdefault' => 1,
                    'active' => 1,
                    'sortorder' => 10,
                    'timecreated' => $now,
                    'timemodified' => $now,
                ];
                $branch->id = $DB->insert_record('local_kbacompany_orgbranch', $branch);
            }
            foreach (self::seven_divisions() as $division) {
                if (!$DB->record_exists('local_kbacompany_orgnode', [
                        'companyuuid' => $companyuuid,
                        'branchuuid' => $branch->uuid,
                        'code' => $division['code']])) {
                    $DB->insert_record('local_kbacompany_orgnode', (object)[
                        'companyuuid' => $companyuuid,
                        'branchuuid' => $branch->uuid,
                        'uuid' => self::uuid_v4(),
                        'parentuuid' => '',
                        'code' => $division['code'],
                        'nodetype' => 'division',
                        'name' => get_string($division['string'], 'local_kbacompany'),
                        'sortorder' => $division['sortorder'],
                        'color' => $division['color'],
                        'sourcecomponent' => '',
                        'sourceentity' => '',
                        'sourceid' => 0,
                        'active' => 1,
                        'timecreated' => $now,
                        'timemodified' => $now,
                    ]);
                }
            }
            $transaction->allow_commit();
        } finally {
            $lock->release();
        }
        return self::board_context($companyuuid, $userid, (string)$branch->uuid);
    }

    private static function seven_divisions(): array {
        return [
            ['code' => '7.0.0', 'string' => 'division7', 'sortorder' => 10, 'color' => '#75A7FF'],
            ['code' => '1.0.0', 'string' => 'division1', 'sortorder' => 20, 'color' => '#FF9F1C'],
            ['code' => '2.0.0', 'string' => 'division2', 'sortorder' => 30, 'color' => '#A47BEA'],
            ['code' => '3.0.0', 'string' => 'division3', 'sortorder' => 40, 'color' => '#FF7D73'],
            ['code' => '4.0.0', 'string' => 'division4', 'sortorder' => 50, 'color' => '#44B7B3'],
            ['code' => '5.0.0', 'string' => 'division5', 'sortorder' => 60, 'color' => '#9B7ED7'],
            ['code' => '6.0.0', 'string' => 'division6', 'sortorder' => 70, 'color' => '#F4C430'],
        ];
    }

    private static function branch_dto(\stdClass $branch): array {
        return ['uuid' => (string)$branch->uuid, 'code' => (string)$branch->code,
            'name' => (string)$branch->name, 'is_default' => !empty($branch->isdefault),
            'sort_order' => (int)$branch->sortorder];
    }

    private static function node_dto(\stdClass $node): array {
        return ['uuid' => (string)$node->uuid, 'parent_uuid' => (string)$node->parentuuid,
            'code' => (string)$node->code, 'type' => (string)$node->nodetype,
            'name' => (string)$node->name, 'sort_order' => (int)$node->sortorder,
            'color' => (string)$node->color];
    }

    private static function uuid_v4(): string {
        $bytes = random_bytes(16);
        $bytes[6] = chr((ord($bytes[6]) & 0x0f) | 0x40);
        $bytes[8] = chr((ord($bytes[8]) & 0x3f) | 0x80);
        $hex = bin2hex($bytes);
        return substr($hex, 0, 8) . '-' . substr($hex, 8, 4) . '-' .
            substr($hex, 12, 4) . '-' . substr($hex, 16, 4) . '-' . substr($hex, 20);
    }
}
