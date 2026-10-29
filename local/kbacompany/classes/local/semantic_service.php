<?php
namespace local_kbacompany\local;

defined('MOODLE_INTERNAL') || die();

/** Provider-neutral semantic vocabulary and explicit cross-domain relations for BMS. */
class semantic_service {
    public const CONTRACT_VERSION = '0.3.3';
    public const OBJECT_TYPES = [
        'company', 'product', 'goal', 'orgunit', 'position', 'function', 'metric', 'policy',
        'process', 'activity', 'document',
    ];
    public const RELATIONS = [
        'produces', 'owns', 'executes', 'belongs_to', 'measures', 'requires', 'supports',
        'governed_by', 'derived_from', 'impacts', 'supplies', 'consumes', 'responsible_for',
        'contributes_to',
    ];
    private const RESERVED_TYPES = [];

    public static function contract(): array {
        $contract = [
            'component' => 'local_kbacompany',
            'contract' => 'bms_semantic_core',
            'version' => self::CONTRACT_VERSION,
            'company_scope' => 'required',
            'objects' => [
                'company' => ['source' => 'company_service', 'availability' => 'current'],
                'product' => ['source' => 'local_kbacompany_product', 'availability' => 'current'],
                'goal' => ['source' => 'local_kbacompany_acobj:goal', 'availability' => 'current'],
                'orgunit' => ['source' => 'local_kbacompany_orgnode', 'availability' => 'current'],
                'position' => ['source' => 'local_kbacompany_position', 'availability' => 'current'],
                'function' => ['source' => 'function_service + legacy_acobj_compat', 'availability' => 'current'],
                'metric' => ['source' => 'local_kbacompany_stat', 'availability' => 'current'],
                'policy' => ['source' => 'local_kbacompany_policy', 'availability' => 'current'],
                'process' => ['source' => 'local_kbacompany_process', 'availability' => 'current'],
                'activity' => ['source' => 'process_model_service:BPMN-task', 'availability' => 'current'],
                'document' => ['source' => 'document_service', 'availability' => 'current'],
            ],
            'relations' => self::RELATIONS,
            'relation_states' => ['active', 'archived'],
            'rules' => [
                'canonical_objects_are_not_duplicated',
                'explicit_relations_are_append_only',
                'company_scope_required',
                'controlled_document_endpoints_require_effective_revision',
                'relation_endpoints_are_validated',
                'archived_relations_remain_history',
                'no_automatic_business_effect',
            ],
        ];
        $contract['schema_hash_algorithm'] = 'sha256';
        $contract['schema_hash'] = hash('sha256', json_encode($contract,
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        return $contract;
    }

    private static function fail(string $key): void {
        throw new \moodle_exception('semantic_' . $key, 'local_kbacompany');
    }

    private static function uuid(): string {
        $bytes = random_bytes(16);
        $bytes[6] = chr((ord($bytes[6]) & 0x0f) | 0x40);
        $bytes[8] = chr((ord($bytes[8]) & 0x3f) | 0x80);
        $hex = bin2hex($bytes);
        return substr($hex, 0, 8) . '-' . substr($hex, 8, 4) . '-' . substr($hex, 12, 4) .
            '-' . substr($hex, 16, 4) . '-' . substr($hex, 20);
    }

    private static function token(array $data, string $key, int $max, bool $required = true): string {
        $value = trim((string)($data[$key] ?? ''));
        if (($required && $value === '') || \core_text::strlen($value) > $max) {
            self::fail('invalid');
        }
        return $value;
    }

    private static function latest_relation(string $company, string $uuid): \stdClass {
        global $DB;
        $rows = $DB->get_records('local_kbacompany_semrel',
            ['companyuuid' => $company, 'uuid' => $uuid], 'revision DESC', '*', 0, 1);
        if (!$rows) {
            self::fail('reference');
        }
        return reset($rows);
    }

    /** Validate an endpoint against the canonical source without copying business fields. */
    private static function endpoint(string $company, string $type, string $uuid, int $actor): array {
        global $DB;
        if (!in_array($type, self::OBJECT_TYPES, true) || $uuid === '') {
            self::fail('invalid');
        }
        if (in_array($type, self::RESERVED_TYPES, true)) {
            self::fail('unavailable');
        }
        if ($type === 'company') {
            if ($uuid !== $company) {
                self::fail('reference');
            }
            $context = company_context_service::context($company, $actor);
            return ['type' => $type, 'uuid' => $company, 'code' => $context['code'],
                'name' => $context['name'], 'status' => $context['active'] ? 'active' : 'inactive'];
        }
        if ($type === 'product') {
            $row = $DB->get_record('local_kbacompany_product', ['companyuuid' => $company, 'uuid' => $uuid]);
            if (!$row) { self::fail('reference'); }
            return ['type' => $type, 'uuid' => $uuid, 'code' => $row->code,
                'name' => $row->name, 'status' => 'active'];
        }
        if ($type === 'metric') {
            $row = $DB->get_record('local_kbacompany_stat', ['companyuuid' => $company, 'uuid' => $uuid]);
            if (!$row) { self::fail('reference'); }
            return ['type' => $type, 'uuid' => $uuid, 'code' => $row->code,
                'name' => $row->name, 'status' => 'active'];
        }
        if ($type === 'process') {
            $rows = $DB->get_records('local_kbacompany_process',
                ['companyuuid' => $company, 'uuid' => $uuid], 'revision DESC', '*', 0, 1);
            if (!$rows) { self::fail('reference'); }
            $row = reset($rows);
            if ($row->status === 'archived') { self::fail('reference'); }
            return ['type' => $type, 'uuid' => $uuid, 'code' => $row->code,
                'name' => $row->name, 'status' => $row->status];
        }
        if ($type === 'activity') {
            $activity = process_model_service::semantic_activity($company, $actor, $uuid);
            return ['type' => $type, 'uuid' => $activity['uuid'], 'code' => $activity['code'],
                'name' => $activity['name'], 'status' => $activity['status']];
        }
        if ($type === 'orgunit') {
            $row = $DB->get_record('local_kbacompany_orgnode',
                ['companyuuid' => $company, 'uuid' => $uuid, 'active' => 1]);
            if (!$row) { self::fail('reference'); }
            return ['type' => $type, 'uuid' => $uuid, 'code' => $row->code,
                'name' => $row->name, 'status' => 'active'];
        }
        if ($type === 'position') {
            $row = $DB->get_record('local_kbacompany_position',
                ['companyuuid' => $company, 'uuid' => $uuid, 'active' => 1]);
            if (!$row) { self::fail('reference'); }
            return ['type' => $type, 'uuid' => $uuid, 'code' => $row->code,
                'name' => $row->name, 'status' => 'active'];
        }
        if ($type === 'function') {
            try {
                $function = function_service::semantic_function($company, $actor, $uuid);
                return ['type' => $type, 'uuid' => $function['uuid'], 'code' => $function['code'],
                    'name' => $function['name'], 'status' => $function['status']];
            } catch (\moodle_exception $e) {
                // Backward compatibility: administrative-cascade functions created before v0.3.2 remain valid endpoints.
            }
            $row = $DB->get_record('local_kbacompany_acobj',
                ['companyuuid' => $company, 'uuid' => $uuid, 'kind' => 'function']);
            if (!$row || $row->status === 'archived') { self::fail('reference'); }
            return ['type' => $type, 'uuid' => $uuid, 'code' => $row->code,
                'name' => $row->name, 'status' => $row->status];
        }
        if ($type === 'goal') {
            $row = $DB->get_record('local_kbacompany_acobj',
                ['companyuuid' => $company, 'uuid' => $uuid, 'kind' => 'goal']);
            if (!$row || $row->status === 'archived') { self::fail('reference'); }
            return ['type' => $type, 'uuid' => $uuid, 'code' => $row->code,
                'name' => $row->name, 'status' => $row->status];
        }
        if ($type === 'document') {
            $document = document_service::semantic_document($company, $actor, $uuid);
            return ['type' => $type, 'uuid' => $document['uuid'], 'code' => $document['code'],
                'name' => $document['name'], 'status' => $document['status']];
        }
        if ($type === 'policy') {
            $rows = $DB->get_records('local_kbacompany_policy',
                ['companyuuid' => $company, 'uuid' => $uuid], 'revision DESC', '*', 0, 1);
            if (!$rows) { self::fail('reference'); }
            $row = reset($rows);
            if ($row->status === 'withdrawn') { self::fail('reference'); }
            return ['type' => $type, 'uuid' => $uuid, 'code' => $row->code,
                'name' => $row->title, 'status' => $row->status];
        }
        self::fail('unavailable');
    }

    private static function duplicate_active(string $company, array $edge): bool {
        global $DB;
        $latest = [];
        foreach ($DB->get_records('local_kbacompany_semrel', ['companyuuid' => $company], 'revision ASC') as $row) {
            $latest[$row->uuid] = $row;
        }
        foreach ($latest as $row) {
            if ($row->status === 'active' && $row->fromtype === $edge['fromtype'] &&
                    $row->fromuuid === $edge['fromuuid'] && $row->relation === $edge['relation'] &&
                    $row->totype === $edge['totype'] && $row->touuid === $edge['touuid']) {
                return true;
            }
        }
        return false;
    }

    /** Create or archive an explicit semantic relation. No linked business object is mutated. */
    public static function command_relation(string $company, int $actor, string $action, array $data): string {
        global $DB;
        require_capability('local/kbacompany:viewowncompany', \context_system::instance(), $actor);
        company_context_service::context($company, $actor);
        if (!permission_service::can_manage_management($company, $actor)) {
            self::fail('permission');
        }
        $lock = \core\lock\lock_config::get_lock_factory('local_kbacompany')
            ->get_lock('semantic:' . $company, 10);
        if (!$lock) { self::fail('lock'); }
        try {
            $tx = $DB->start_delegated_transaction();
            if ($action === 'create') {
                $edge = [
                    'fromtype' => self::token($data, 'fromtype', 20),
                    'fromuuid' => self::token($data, 'fromuuid', 36),
                    'relation' => self::token($data, 'relation', 30),
                    'totype' => self::token($data, 'totype', 20),
                    'touuid' => self::token($data, 'touuid', 36),
                ];
                if (!in_array($edge['relation'], self::RELATIONS, true)) { self::fail('invalid'); }
                self::endpoint($company, $edge['fromtype'], $edge['fromuuid'], $actor);
                self::endpoint($company, $edge['totype'], $edge['touuid'], $actor);
                if ($edge['fromtype'] === $edge['totype'] && $edge['fromuuid'] === $edge['touuid']) {
                    self::fail('invalid');
                }
                if (self::duplicate_active($company, $edge)) { self::fail('duplicate'); }
                $uuid = self::uuid();
                $row = (object)array_merge($edge, [
                    'companyuuid' => $company, 'uuid' => $uuid, 'revision' => 1,
                    'status' => 'active', 'timecreated' => time(),
                ]);
                $DB->insert_record('local_kbacompany_semrel', $row);
                $old = null;
                $reason = self::token($data, 'reason', 4000, false);
            } else if ($action === 'archive') {
                $uuid = self::token($data, 'object', 36);
                $old = self::latest_relation($company, $uuid);
                if ((int)$old->revision !== (int)($data['revision'] ?? 0)) { self::fail('conflict'); }
                if ($old->status !== 'active') { self::fail('state'); }
                $reason = self::token($data, 'reason', 4000);
                $row = clone $old;
                unset($row->id);
                $row->revision++;
                $row->status = 'archived';
                $row->timecreated = time();
                $DB->insert_record('local_kbacompany_semrel', $row);
            } else {
                self::fail('invalid');
            }
            $DB->insert_record('local_kbacompany_aclog', (object)[
                'companyuuid' => $company, 'objectuuid' => $uuid, 'actorid' => $actor,
                'action' => 'semrel_' . $action,
                'oldvalue' => $old ? json_encode($old, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : null,
                'newvalue' => json_encode(['record' => $row, 'reason' => $reason],
                    JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                'timecreated' => time(),
            ]);
            $tx->allow_commit();
            return $uuid;
        } catch (\Throwable $e) {
            if (isset($tx)) { $tx->rollback($e); }
            throw $e;
        } finally {
            $lock->release();
        }
    }

    private static function canonical_objects(string $company, int $actor): array {
        global $DB;
        $objects = [];
        $c = company_context_service::context($company, $actor);
        $objects[] = ['type' => 'company', 'uuid' => $company, 'code' => $c['code'],
            'name' => $c['name'], 'status' => $c['active'] ? 'active' : 'inactive'];
        foreach ($DB->get_records('local_kbacompany_orgnode', ['companyuuid' => $company, 'active' => 1],
                'code ASC') as $row) {
            $objects[] = ['type' => 'orgunit', 'uuid' => $row->uuid, 'code' => $row->code,
                'name' => $row->name, 'status' => 'active'];
        }
        foreach ($DB->get_records('local_kbacompany_position', ['companyuuid' => $company, 'active' => 1],
                'code ASC') as $row) {
            $objects[] = ['type' => 'position', 'uuid' => $row->uuid, 'code' => $row->code,
                'name' => $row->name, 'status' => 'active'];
        }
        foreach ($DB->get_records('local_kbacompany_product', ['companyuuid' => $company], 'code ASC') as $row) {
            $objects[] = ['type' => 'product', 'uuid' => $row->uuid, 'code' => $row->code,
                'name' => $row->name, 'status' => 'active'];
        }
        foreach ($DB->get_records('local_kbacompany_stat', ['companyuuid' => $company], 'code ASC') as $row) {
            $objects[] = ['type' => 'metric', 'uuid' => $row->uuid, 'code' => $row->code,
                'name' => $row->name, 'status' => 'active'];
        }
        $processversions = [];
        foreach ($DB->get_records('local_kbacompany_process', ['companyuuid' => $company],
                'uuid ASC, revision ASC') as $row) {
            $processversions[$row->uuid][] = $row;
        }
        $canmanage = permission_service::can_manage_management($company, $actor);
        foreach ($processversions as $versions) {
            $latest = end($versions);
            if ($latest->status === 'archived') { continue; }
            $visible = $latest;
            if (!$canmanage && $latest->status !== 'published') {
                $visible = null;
                foreach (array_reverse($versions) as $candidate) {
                    if ($candidate->status === 'published') { $visible = $candidate; break; }
                }
                if (!$visible) { continue; }
            }
            $objects[] = ['type' => 'process', 'uuid' => $visible->uuid, 'code' => $visible->code,
                'name' => $visible->name, 'status' => $visible->status];
        }

        foreach (process_model_service::semantic_activities($company, $actor) as $activity) {
            $objects[] = ['type' => 'activity', 'uuid' => $activity['uuid'], 'code' => $activity['code'],
                'name' => $activity['name'], 'status' => $activity['status']];
        }
        foreach ($DB->get_records('local_kbacompany_acobj', ['companyuuid' => $company, 'kind' => 'goal'],
                'code ASC') as $row) {
            if ($row->status === 'archived') { continue; }
            $objects[] = ['type' => 'goal', 'uuid' => $row->uuid, 'code' => $row->code,
                'name' => $row->name, 'status' => $row->status];
        }
        $canonicalfunctionuuids = [];
        foreach (function_service::semantic_functions($company, $actor) as $function) {
            $canonicalfunctionuuids[$function['uuid']] = true;
            $objects[] = ['type' => 'function', 'uuid' => $function['uuid'], 'code' => $function['code'],
                'name' => $function['name'], 'status' => $function['status']];
        }
        // Legacy administrative-cascade functions remain visible until their future explicit migration.
        foreach ($DB->get_records('local_kbacompany_acobj', ['companyuuid' => $company, 'kind' => 'function'],
                'code ASC') as $row) {
            if ($row->status === 'archived' || isset($canonicalfunctionuuids[$row->uuid])) { continue; }
            $objects[] = ['type' => 'function', 'uuid' => $row->uuid, 'code' => $row->code,
                'name' => $row->name, 'status' => $row->status];
        }
        foreach (document_service::semantic_documents($company, $actor) as $document) {
            $objects[] = ['type' => 'document', 'uuid' => $document['uuid'], 'code' => $document['code'],
                'name' => $document['name'], 'status' => $document['status']];
        }
        $policies = [];
        foreach ($DB->get_records('local_kbacompany_policy', ['companyuuid' => $company], 'revision ASC') as $row) {
            $policies[$row->uuid] = $row;
        }
        foreach ($policies as $row) {
            if ($row->status === 'withdrawn' || (!$canmanage && $row->status !== 'published')) { continue; }
            $objects[] = ['type' => 'policy', 'uuid' => $row->uuid, 'code' => $row->code,
                'name' => $row->title, 'status' => $row->status];
        }
        usort($objects, static function(array $a, array $b): int {
            return [$a['type'], $a['code'], $a['uuid']] <=> [$b['type'], $b['code'], $b['uuid']];
        });
        return $objects;
    }

    /** Read a company-scoped semantic graph without duplicating canonical object data. */
    public static function context(string $company, int $actor): array {
        global $DB;
        require_capability('local/kbacompany:viewowncompany', \context_system::instance(), $actor);
        $companycontext = company_context_service::context($company, $actor);
        $objects = self::canonical_objects($company, $actor);
        $latest = [];
        foreach ($DB->get_records('local_kbacompany_semrel', ['companyuuid' => $company], 'revision ASC') as $row) {
            $latest[$row->uuid] = $row;
        }
        $visibleobjects = [];
        foreach ($objects as $object) {
            $visibleobjects[$object['type'] . ':' . $object['uuid']] = true;
        }
        $relations = [];
        foreach ($latest as $row) {
            if ($row->status !== 'active') { continue; }
            if (!isset($visibleobjects[$row->fromtype . ':' . $row->fromuuid]) ||
                    !isset($visibleobjects[$row->totype . ':' . $row->touuid])) {
                continue;
            }
            $relations[] = [
                'uuid' => $row->uuid, 'revision' => (int)$row->revision,
                'from_type' => $row->fromtype, 'from_uuid' => $row->fromuuid,
                'relation' => $row->relation, 'to_type' => $row->totype, 'to_uuid' => $row->touuid,
            ];
        }
        usort($relations, static function(array $a, array $b): int {
            return [$a['from_type'], $a['from_uuid'], $a['relation'], $a['to_type'], $a['to_uuid']]
                <=> [$b['from_type'], $b['from_uuid'], $b['relation'], $b['to_type'], $b['to_uuid']];
        });
        $counts = [];
        foreach ($objects as $object) { $counts[$object['type']] = ($counts[$object['type']] ?? 0) + 1; }
        foreach (self::OBJECT_TYPES as $type) { $counts[$type] = $counts[$type] ?? 0; }
        ksort($counts);
        return [
            'company' => $companycontext,
            'contract_version' => self::CONTRACT_VERSION,
            'objects' => $objects,
            'relations' => $relations,
            'reserved_types' => self::RESERVED_TYPES,
            'can_manage' => permission_service::can_manage_management($company, $actor),
            'summary' => ['objects' => count($objects), 'relations' => count($relations), 'by_type' => $counts],
        ];
    }
}
