<?php
namespace local_kbacompany\local;

defined('MOODLE_INTERNAL') || die();

/** Canonical versioned BMS Function: stable responsibility for a product, derived from process activity. */
class function_service {
    public const CONTRACT_VERSION = '0.3.2';
    public const STATES = ['draft', 'published', 'archived'];

    public static function contract(): array {
        $contract = [
            'component' => 'local_kbacompany',
            'contract' => 'bms_function_model',
            'version' => self::CONTRACT_VERSION,
            'company_scope' => 'required',
            'states' => self::STATES,
            'source_chain' => 'process -> activity -> function -> position',
            'rules' => [
                'function_is_stable_responsibility_not_task_copy',
                'function_has_product_or_result',
                'source_activity_is_bpmn_task',
                'source_snapshot_is_revision_traceable',
                'position_assignment_is_company_scoped',
                'published_revision_is_immutable',
                'legacy_administrative_cascade_functions_remain_compatible',
                'no_employee_assignment_or_competency_mutation',
            ],
        ];
        $contract['schema_hash_algorithm'] = 'sha256';
        $contract['schema_hash'] = hash('sha256', json_encode($contract,
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        return $contract;
    }

    public static function can_manage(string $companyuuid, int $userid): bool {
        return permission_service::can_manage_management($companyuuid, $userid);
    }

    private static function authorise(string $companyuuid, int $userid): void {
        company_context_service::context($companyuuid, $userid);
        if (!self::can_manage($companyuuid, $userid)) {
            throw new \required_capability_exception(\context_system::instance(),
                'local/kbacompany:manageorganization', 'nopermissions', '');
        }
    }

    private static function fail(string $key): void {
        throw new \moodle_exception('function_' . $key, 'local_kbacompany');
    }

    private static function uuid(): string {
        $bytes = random_bytes(16);
        $bytes[6] = chr((ord($bytes[6]) & 0x0f) | 0x40);
        $bytes[8] = chr((ord($bytes[8]) & 0x3f) | 0x80);
        $hex = bin2hex($bytes);
        return substr($hex, 0, 8) . '-' . substr($hex, 8, 4) . '-' . substr($hex, 12, 4) .
            '-' . substr($hex, 16, 4) . '-' . substr($hex, 20);
    }

    private static function text(array $data, string $field, int $max, bool $required = false): string {
        $value = trim((string)($data[$field] ?? ''));
        if (($required && $value === '') || \core_text::strlen($value) > $max) {
            self::fail('invalid');
        }
        return $value;
    }

    private static function latest(string $companyuuid, string $uuid): \stdClass {
        global $DB;
        $rows = $DB->get_records('local_kbacompany_function',
            ['companyuuid' => $companyuuid, 'uuid' => $uuid], 'revision DESC', '*', 0, 1);
        if (!$rows) { self::fail('reference'); }
        return reset($rows);
    }

    private static function rows_by_uuid(string $companyuuid): array {
        global $DB;
        $grouped = [];
        foreach ($DB->get_records('local_kbacompany_function', ['companyuuid' => $companyuuid],
                'uuid ASC, revision ASC') as $row) {
            $grouped[$row->uuid][] = $row;
        }
        return $grouped;
    }

    private static function visible_rows(string $companyuuid, bool $canmanage): array {
        $visible = [];
        foreach (self::rows_by_uuid($companyuuid) as $uuid => $versions) {
            $latest = end($versions);
            if ($latest->status === 'archived') { continue; }
            if ($canmanage) {
                $visible[$uuid] = $latest;
                continue;
            }
            foreach (array_reverse($versions) as $candidate) {
                if ($candidate->status === 'published') {
                    $visible[$uuid] = $candidate;
                    break;
                }
            }
        }
        return $visible;
    }

    private static function validate_position(string $companyuuid, string $positionuuid): void {
        global $DB;
        if ($positionuuid !== '' && !$DB->record_exists('local_kbacompany_position', [
                'companyuuid' => $companyuuid, 'uuid' => $positionuuid, 'active' => 1])) {
            self::fail('invalidposition');
        }
    }

    private static function validate_product(string $companyuuid, string $productuuid): void {
        global $DB;
        if ($productuuid !== '' && !$DB->record_exists('local_kbacompany_product', [
                'companyuuid' => $companyuuid, 'uuid' => $productuuid])) {
            self::fail('invalidproduct');
        }
    }

    /** Current visible BPMN Task source for interactive creation/refresh. */
    private static function current_activity(string $companyuuid, int $userid, string $processuuid,
            string $activityuuid): array {
        $context = process_model_service::context($companyuuid, $userid, $processuuid);
        if (!$context['model']) { self::fail('invalidsource'); }
        foreach ($context['bpmn']['nodes'] as $node) {
            if ($node['type'] === 'task' && $node['uuid'] === $activityuuid) {
                return ['model' => $context['model'], 'process' => $context['process'], 'activity' => $node];
            }
        }
        self::fail('invalidsource');
    }

    /** Exact historical Task snapshot used by a Function revision. */
    private static function source_snapshot(string $companyuuid, string $processuuid, string $activityuuid,
            int $modelrevision): ?array {
        global $DB;
        $model = $DB->get_record('local_kbacompany_procmodel', [
            'companyuuid' => $companyuuid, 'processuuid' => $processuuid, 'revision' => $modelrevision,
        ]);
        if (!$model) { return null; }
        $task = $DB->get_record('local_kbacompany_procelem', [
            'companyuuid' => $companyuuid, 'modelid' => $model->id, 'uuid' => $activityuuid,
            'elementtype' => 'task', 'active' => 1,
        ]);
        if (!$task) { return null; }
        return ['model' => $model, 'activity' => $task];
    }

    private static function code_available(string $companyuuid, string $code, string $uuid = ''): void {
        foreach (self::visible_rows($companyuuid, true) as $row) {
            if ($row->code === $code && $row->uuid !== $uuid) { self::fail('duplicatecode'); }
        }
    }

    private static function source_available(string $companyuuid, string $activityuuid, string $uuid = ''): void {
        foreach (self::visible_rows($companyuuid, true) as $row) {
            if ($row->sourceactivityuuid === $activityuuid && $row->uuid !== $uuid) { self::fail('duplicatesource'); }
        }
    }

    private static function log(string $companyuuid, string $uuid, int $actor, string $action, $old, $new): void {
        global $DB;
        $DB->insert_record('local_kbacompany_aclog', (object)[
            'companyuuid' => $companyuuid, 'objectuuid' => $uuid, 'action' => $action, 'actorid' => $actor,
            'oldvalue' => $old === null ? null : json_encode($old, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'newvalue' => $new === null ? null : json_encode($new, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'timecreated' => time(),
        ]);
    }

    private static function make_row(string $companyuuid, int $userid, string $uuid, int $revision,
            array $data, array $source): \stdClass {
        $activity = $source['activity'];
        $positionuuid = self::text($data, 'positionuuid', 36);
        if ($positionuuid === '' && ($activity['executor_type'] ?? '') === 'position') {
            $positionuuid = (string)$activity['executor_uuid'];
        }
        self::validate_position($companyuuid, $positionuuid);
        $productuuid = self::text($data, 'productuuid', 36);
        if ($productuuid === '') { $productuuid = (string)($activity['product_uuid'] ?? ''); }
        self::validate_product($companyuuid, $productuuid);
        $producttext = self::text($data, 'producttext', 4000);
        if ($producttext === '') {
            $producttext = (string)(($activity['product_name'] ?? '') !== '' ? $activity['product_name'] : ($activity['output'] ?? ''));
        }
        $now = time();
        return (object)[
            'companyuuid' => $companyuuid, 'uuid' => $uuid, 'revision' => $revision, 'status' => 'draft',
            'code' => self::text($data, 'code', 50, true), 'name' => self::text($data, 'name', 255, true),
            'purpose' => self::text($data, 'purpose', 4000), 'productuuid' => $productuuid,
            'producttext' => $producttext, 'positionuuid' => $positionuuid,
            'sourceprocessuuid' => (string)$source['process']['uuid'],
            'sourceactivityuuid' => (string)$activity['uuid'],
            'sourcemodelrevision' => (int)$source['model']['revision'],
            'sourceaction' => (string)$activity['description'],
            'sourceoutput' => (string)(($activity['product_name'] ?? '') !== '' ? $activity['product_name'] : ($activity['output'] ?? '')),
            'createdby' => $userid, 'modifiedby' => $userid, 'timecreated' => $now, 'timemodified' => $now,
        ];
    }

    /** Create a canonical Function draft from one BPMN Task. */
    public static function create_from_activity(string $companyuuid, int $userid, string $processuuid,
            string $activityuuid, array $data): string {
        global $DB;
        self::authorise($companyuuid, $userid);
        $source = self::current_activity($companyuuid, $userid, $processuuid, $activityuuid);
        $lock = \core\lock\lock_config::get_lock_factory('local_kbacompany')
            ->get_lock('function_model:' . $companyuuid, 10);
        if (!$lock) { self::fail('lock'); }
        try {
            $tx = $DB->start_delegated_transaction();
            $uuid = self::uuid();
            self::code_available($companyuuid, trim((string)($data['code'] ?? '')), $uuid);
            self::source_available($companyuuid, $activityuuid, $uuid);
            $row = self::make_row($companyuuid, $userid, $uuid, 1, $data, $source);
            $DB->insert_record('local_kbacompany_function', $row);
            self::log($companyuuid, $uuid, $userid, 'function_create_from_activity', null, $row);
            $tx->allow_commit();
            return $uuid;
        } catch (\Throwable $e) {
            if (isset($tx)) { $tx->rollback($e); }
            throw $e;
        } finally { $lock->release(); }
    }

    /** Append a draft revision while preserving the immutable source reference. */
    public static function save(string $companyuuid, int $userid, string $uuid, int $expectedrevision,
            array $data): void {
        global $DB;
        self::authorise($companyuuid, $userid);
        $lock = \core\lock\lock_config::get_lock_factory('local_kbacompany')
            ->get_lock('function_model:' . $companyuuid, 10);
        if (!$lock) { self::fail('lock'); }
        try {
            $tx = $DB->start_delegated_transaction();
            $old = self::latest($companyuuid, $uuid);
            if ((int)$old->revision !== $expectedrevision) { self::fail('conflict'); }
            if ($old->status === 'archived') { self::fail('state'); }
            $source = self::source_snapshot($companyuuid, $old->sourceprocessuuid, $old->sourceactivityuuid,
                (int)$old->sourcemodelrevision);
            if (!$source) { self::fail('invalidsource'); }
            $source['process'] = ['uuid' => $old->sourceprocessuuid];
            $source['model'] = ['revision' => (int)$old->sourcemodelrevision];
            $activity = $source['activity'];
            $source['activity'] = [
                'uuid' => (string)$activity->uuid, 'description' => (string)$activity->description,
                'executor_type' => (string)$activity->executortype, 'executor_uuid' => (string)$activity->executoruuid,
                'product_uuid' => (string)$activity->productuuid, 'product_name' => '', 'output' => (string)$activity->outputtext,
            ];
            $row = self::make_row($companyuuid, $userid, $uuid, $expectedrevision + 1, $data, $source);
            // Preserve source snapshot verbatim on ordinary function edits.
            $row->sourceprocessuuid = $old->sourceprocessuuid;
            $row->sourceactivityuuid = $old->sourceactivityuuid;
            $row->sourcemodelrevision = (int)$old->sourcemodelrevision;
            $row->sourceaction = $old->sourceaction;
            $row->sourceoutput = $old->sourceoutput;
            $row->timecreated = (int)$old->timecreated;
            self::code_available($companyuuid, $row->code, $uuid);
            self::source_available($companyuuid, $row->sourceactivityuuid, $uuid);
            $DB->insert_record('local_kbacompany_function', $row);
            self::log($companyuuid, $uuid, $userid, 'function_save', $old, $row);
            $tx->allow_commit();
        } catch (\Throwable $e) {
            if (isset($tx)) { $tx->rollback($e); }
            throw $e;
        } finally { $lock->release(); }
    }

    /** Explicitly accept the current Task revision as the Function source snapshot without silently changing Function semantics. */
    public static function refresh_source(string $companyuuid, int $userid, string $uuid, int $expectedrevision): void {
        global $DB;
        self::authorise($companyuuid, $userid);
        $lock = \core\lock\lock_config::get_lock_factory('local_kbacompany')
            ->get_lock('function_model:' . $companyuuid, 10);
        if (!$lock) { self::fail('lock'); }
        try {
            $tx = $DB->start_delegated_transaction();
            $old = self::latest($companyuuid, $uuid);
            if ((int)$old->revision !== $expectedrevision) { self::fail('conflict'); }
            if ($old->status === 'archived') { self::fail('state'); }
            $source = self::current_activity($companyuuid, $userid, $old->sourceprocessuuid, $old->sourceactivityuuid);
            $row = clone $old;
            unset($row->id);
            $row->revision = $expectedrevision + 1;
            $row->status = 'draft';
            $row->sourcemodelrevision = (int)$source['model']['revision'];
            $row->sourceaction = (string)$source['activity']['description'];
            $row->sourceoutput = (string)($source['activity']['product_name'] !== ''
                ? $source['activity']['product_name'] : $source['activity']['output']);
            $row->modifiedby = $userid;
            $row->timemodified = time();
            $DB->insert_record('local_kbacompany_function', $row);
            self::log($companyuuid, $uuid, $userid, 'function_refresh_source', $old, $row);
            $tx->allow_commit();
        } catch (\Throwable $e) {
            if (isset($tx)) { $tx->rollback($e); }
            throw $e;
        } finally { $lock->release(); }
    }

    public static function publish(string $companyuuid, int $userid, string $uuid, int $expectedrevision): void {
        global $DB;
        self::authorise($companyuuid, $userid);
        $lock = \core\lock\lock_config::get_lock_factory('local_kbacompany')
            ->get_lock('function_model:' . $companyuuid, 10);
        if (!$lock) { self::fail('lock'); }
        try {
            $tx = $DB->start_delegated_transaction();
            $old = self::latest($companyuuid, $uuid);
            if ((int)$old->revision !== $expectedrevision) { self::fail('conflict'); }
            if ($old->status !== 'draft') { self::fail('state'); }
            if ($old->positionuuid === '' || ($old->productuuid === '' && trim((string)$old->producttext) === '')) {
                self::fail('incomplete');
            }
            self::validate_position($companyuuid, $old->positionuuid);
            self::validate_product($companyuuid, $old->productuuid);
            $sourcesnapshot = self::source_snapshot($companyuuid, $old->sourceprocessuuid, $old->sourceactivityuuid,
                (int)$old->sourcemodelrevision);
            if (!$sourcesnapshot || $sourcesnapshot['model']->status !== 'published') { self::fail('sourcenotpublished'); }
            $row = clone $old;
            unset($row->id);
            $row->revision = $expectedrevision + 1;
            $row->status = 'published';
            $row->createdby = $userid;
            $row->modifiedby = $userid;
            $row->timemodified = time();
            $DB->insert_record('local_kbacompany_function', $row);
            self::log($companyuuid, $uuid, $userid, 'function_publish', $old, $row);
            $tx->allow_commit();
        } catch (\Throwable $e) {
            if (isset($tx)) { $tx->rollback($e); }
            throw $e;
        } finally { $lock->release(); }
    }

    public static function archive(string $companyuuid, int $userid, string $uuid, int $expectedrevision, string $reason): void {
        global $DB;
        self::authorise($companyuuid, $userid);
        $reason = trim($reason);
        if ($reason === '' || \core_text::strlen($reason) > 4000) { self::fail('reason'); }
        $lock = \core\lock\lock_config::get_lock_factory('local_kbacompany')
            ->get_lock('function_model:' . $companyuuid, 10);
        if (!$lock) { self::fail('lock'); }
        try {
            $tx = $DB->start_delegated_transaction();
            $old = self::latest($companyuuid, $uuid);
            if ((int)$old->revision !== $expectedrevision) { self::fail('conflict'); }
            if ($old->status === 'archived') { self::fail('state'); }
            $row = clone $old;
            unset($row->id);
            $row->revision = $expectedrevision + 1;
            $row->status = 'archived';
            $row->createdby = $userid;
            $row->modifiedby = $userid;
            $row->timemodified = time();
            $DB->insert_record('local_kbacompany_function', $row);
            self::log($companyuuid, $uuid, $userid, 'function_archive', $old, ['record' => $row, 'reason' => $reason]);
            $tx->allow_commit();
        } catch (\Throwable $e) {
            if (isset($tx)) { $tx->rollback($e); }
            throw $e;
        } finally { $lock->release(); }
    }

    private static function dto(\stdClass $row, string $companyuuid, int $userid, array $positions,
            array $products): array {
        global $DB;
        $processrows = $DB->get_records('local_kbacompany_process', [
            'companyuuid' => $companyuuid, 'uuid' => $row->sourceprocessuuid,
        ], 'revision DESC', '*', 0, 1);
        $process = $processrows ? reset($processrows) : null;
        $currentrevision = 0;
        try {
            $current = process_model_service::context($companyuuid, $userid, $row->sourceprocessuuid);
            $currentrevision = $current['model'] ? (int)$current['model']['revision'] : 0;
        } catch (\Throwable $e) {
            $currentrevision = 0;
        }
        return [
            'uuid' => (string)$row->uuid, 'revision' => (int)$row->revision, 'status' => (string)$row->status,
            'code' => (string)$row->code, 'name' => (string)$row->name, 'purpose' => (string)$row->purpose,
            'product_uuid' => (string)$row->productuuid,
            'product_name' => $row->productuuid !== '' && isset($products[$row->productuuid])
                ? $products[$row->productuuid] : (string)$row->producttext,
            'product_text' => (string)$row->producttext, 'position_uuid' => (string)$row->positionuuid,
            'position_name' => $positions[$row->positionuuid] ?? '',
            'source_process_uuid' => (string)$row->sourceprocessuuid,
            'source_process_name' => $process ? (string)$process->name : '',
            'source_activity_uuid' => (string)$row->sourceactivityuuid,
            'source_model_revision' => (int)$row->sourcemodelrevision,
            'source_current_model_revision' => $currentrevision,
            'source_outdated' => $currentrevision > 0 && $currentrevision !== (int)$row->sourcemodelrevision,
            'source_action' => (string)$row->sourceaction, 'source_output' => (string)$row->sourceoutput,
            'created_by' => (int)$row->createdby, 'modified_by' => (int)$row->modifiedby,
            'time_created' => (int)$row->timecreated, 'time_modified' => (int)$row->timemodified,
        ];
    }

    public static function context(string $companyuuid, int $userid, string $functionuuid = ''): array {
        global $DB;
        $company = company_context_service::context($companyuuid, $userid);
        $canmanage = self::can_manage($companyuuid, $userid);
        $positions = [];
        foreach ($DB->get_records('local_kbacompany_position', ['companyuuid' => $companyuuid, 'active' => 1], 'code ASC') as $row) {
            $positions[$row->uuid] = (string)$row->name;
        }
        $products = [];
        foreach ($DB->get_records('local_kbacompany_product', ['companyuuid' => $companyuuid], 'code ASC') as $row) {
            $products[$row->uuid] = (string)$row->name;
        }
        $functions = [];
        foreach (self::visible_rows($companyuuid, $canmanage) as $row) {
            $functions[] = self::dto($row, $companyuuid, $userid, $positions, $products);
        }
        usort($functions, static function(array $a, array $b): int { return [$a['code'], $a['uuid']] <=> [$b['code'], $b['uuid']]; });
        $selected = null;
        foreach ($functions as $function) {
            if ($function['uuid'] === $functionuuid) { $selected = $function; break; }
        }
        $legacy = 0;
        foreach ($DB->get_records('local_kbacompany_acobj', ['companyuuid' => $companyuuid, 'kind' => 'function']) as $row) {
            if ($row->status !== 'archived') { $legacy++; }
        }
        return [
            'company' => $company, 'functions' => $functions, 'selected' => $selected,
            'positions' => array_map(static function(string $uuid, string $name): array { return ['uuid' => $uuid, 'name' => $name]; },
                array_keys($positions), array_values($positions)),
            'products' => array_map(static function(string $uuid, string $name): array { return ['uuid' => $uuid, 'name' => $name]; },
                array_keys($products), array_values($products)),
            'legacy_function_count' => $legacy, 'can_manage' => $canmanage,
        ];
    }

    /** Canonical functions for Semantic Core. Legacy cascade functions remain a compatibility source in semantic_service. */
    public static function semantic_functions(string $companyuuid, int $userid): array {
        return self::context($companyuuid, $userid)['functions'];
    }

    public static function semantic_function(string $companyuuid, int $userid, string $functionuuid): array {
        foreach (self::semantic_functions($companyuuid, $userid) as $function) {
            if ($function['uuid'] === $functionuuid) { return $function; }
        }
        self::fail('reference');
    }
}
