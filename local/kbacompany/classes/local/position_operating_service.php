<?php
namespace local_kbacompany\local;

defined('MOODLE_INTERNAL') || die();

/** Position Operating System: generated job description, work program and digital job folder. */
class position_operating_service {
    public const CONTRACT_VERSION = '0.3.4';
    public const FREQUENCIES = ['daily', 'weekly', 'monthly', 'quarterly', 'yearly', 'event'];
    public const STATES = ['draft', 'published', 'archived'];

    public static function contract(): array {
        $contract = [
            'component' => 'local_kbacompany',
            'contract' => 'bms_position_operating_system',
            'version' => self::CONTRACT_VERSION,
            'company_scope' => 'required',
            'frequencies' => self::FREQUENCIES,
            'rules' => [
                'position_result_precedes_actions',
                'job_description_is_generated_from_bms_model',
                'work_program_is_versioned_and_function_sourced',
                'published_work_item_is_immutable',
                'digital_job_folder_shows_only_effective_controlled_documents',
                'source_function_revision_is_pinned_and_outdated_source_is_visible',
                'no_employee_or_competency_mutation',
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

    private static function authorise_manage(string $companyuuid, int $userid): void {
        company_context_service::context($companyuuid, $userid);
        if (!self::can_manage($companyuuid, $userid)) {
            throw new \required_capability_exception(\context_system::instance(),
                'local/kbacompany:manageorganization', 'nopermissions', '');
        }
    }

    private static function fail(string $key): void {
        throw new \moodle_exception('positionos_' . $key, 'local_kbacompany');
    }

    private static function uuid(): string {
        $bytes = random_bytes(16);
        $bytes[6] = chr((ord($bytes[6]) & 0x0f) | 0x40);
        $bytes[8] = chr((ord($bytes[8]) & 0x3f) | 0x80);
        $hex = bin2hex($bytes);
        return substr($hex, 0, 8) . '-' . substr($hex, 8, 4) . '-' . substr($hex, 12, 4) . '-' .
            substr($hex, 16, 4) . '-' . substr($hex, 20);
    }

    private static function position(string $companyuuid, string $positionuuid): \stdClass {
        global $DB;
        $position = $DB->get_record('local_kbacompany_position', [
            'companyuuid' => $companyuuid, 'uuid' => $positionuuid, 'active' => 1,
        ]);
        if (!$position) { self::fail('position'); }
        return $position;
    }

    /** Current published Function revisions assigned to a Position. */
    private static function published_functions(string $companyuuid, int $userid, string $positionuuid): array {
        global $DB;
        $grouped = [];
        foreach ($DB->get_records('local_kbacompany_function', [
                'companyuuid' => $companyuuid, 'positionuuid' => $positionuuid,
            ], 'uuid ASC, revision ASC') as $row) {
            $grouped[$row->uuid][] = $row;
        }
        $all = [];
        foreach (function_service::context($companyuuid, $userid)['functions'] as $dto) {
            $all[$dto['uuid'] . ':' . $dto['revision']] = $dto;
        }
        $result = [];
        foreach ($grouped as $versions) {
            $published = null;
            foreach (array_reverse($versions) as $candidate) {
                if ($candidate->status === 'published') { $published = $candidate; break; }
            }
            if (!$published) { continue; }
            $key = $published->uuid . ':' . $published->revision;
            if (isset($all[$key])) { $result[] = $all[$key]; continue; }
            // A manager may currently see a later draft, so build a stable minimal snapshot for the published revision.
            $productname = '';
            if ($published->productuuid !== '') {
                $product = $DB->get_record('local_kbacompany_product', [
                    'companyuuid' => $companyuuid, 'uuid' => $published->productuuid,
                ]);
                $productname = $product ? (string)$product->name : '';
            }
            $process = $DB->get_records('local_kbacompany_process', [
                'companyuuid' => $companyuuid, 'uuid' => $published->sourceprocessuuid,
            ], 'revision DESC', '*', 0, 1);
            $process = $process ? reset($process) : null;
            $result[] = [
                'uuid' => (string)$published->uuid, 'revision' => (int)$published->revision,
                'status' => 'published', 'code' => (string)$published->code, 'name' => (string)$published->name,
                'purpose' => (string)$published->purpose, 'product_uuid' => (string)$published->productuuid,
                'product_name' => $productname !== '' ? $productname : (string)$published->producttext,
                'product_text' => (string)$published->producttext, 'position_uuid' => (string)$published->positionuuid,
                'source_process_uuid' => (string)$published->sourceprocessuuid,
                'source_process_name' => $process ? (string)$process->name : '',
                'source_activity_uuid' => (string)$published->sourceactivityuuid,
                'source_model_revision' => (int)$published->sourcemodelrevision,
                'source_action' => (string)$published->sourceaction, 'source_output' => (string)$published->sourceoutput,
                'source_outdated' => false,
            ];
        }
        usort($result, static function(array $a, array $b): int { return [$a['code'], $a['uuid']] <=> [$b['code'], $b['uuid']]; });
        return $result;
    }

    private static function latest_work(string $companyuuid, string $uuid): \stdClass {
        global $DB;
        $rows = $DB->get_records('local_kbacompany_poswork', [
            'companyuuid' => $companyuuid, 'uuid' => $uuid,
        ], 'revision DESC', '*', 0, 1);
        if (!$rows) { self::fail('reference'); }
        return reset($rows);
    }

    private static function current_function_revision(string $companyuuid, string $functionuuid): int {
        global $DB;
        $rows = $DB->get_records('local_kbacompany_function', [
            'companyuuid' => $companyuuid, 'uuid' => $functionuuid, 'status' => 'published',
        ], 'revision DESC', 'id,revision', 0, 1);
        return $rows ? (int)reset($rows)->revision : 0;
    }

    private static function validate_frequency(string $frequency): void {
        if (!in_array($frequency, self::FREQUENCIES, true)) { self::fail('frequency'); }
    }

    private static function function_for_position(string $companyuuid, int $userid, string $positionuuid,
            string $functionuuid): array {
        foreach (self::published_functions($companyuuid, $userid, $positionuuid) as $function) {
            if ($function['uuid'] === $functionuuid) { return $function; }
        }
        self::fail('function');
    }

    private static function work_dto(\stdClass $row): array {
        $currentrevision = self::current_function_revision($row->companyuuid, $row->functionuuid);
        return [
            'uuid' => (string)$row->uuid, 'revision' => (int)$row->revision, 'status' => (string)$row->status,
            'position_uuid' => (string)$row->positionuuid, 'function_uuid' => (string)$row->functionuuid,
            'function_revision' => (int)$row->functionrevision, 'action' => (string)$row->action,
            'product' => (string)$row->product, 'frequency' => (string)$row->frequency,
            'schedule' => (string)$row->schedule, 'sort_order' => (int)$row->sortorder,
            'change_reason' => (string)$row->changereason,
            'source_current_revision' => $currentrevision,
            'source_outdated' => $currentrevision > 0 && $currentrevision !== (int)$row->functionrevision,
            'created_by' => (int)$row->createdby, 'modified_by' => (int)$row->modifiedby,
            'time_created' => (int)$row->timecreated, 'time_modified' => (int)$row->timemodified,
        ];
    }

    public static function create_work_item(string $companyuuid, int $userid, string $positionuuid,
            string $functionuuid, string $frequency, string $schedule = '', string $action = '', string $product = ''): string {
        global $DB;
        self::authorise_manage($companyuuid, $userid);
        self::position($companyuuid, $positionuuid);
        self::validate_frequency($frequency);
        $function = self::function_for_position($companyuuid, $userid, $positionuuid, $functionuuid);
        $action = trim($action) !== '' ? trim($action) : trim((string)$function['source_action']);
        if ($action === '') { $action = (string)$function['name']; }
        $product = trim($product) !== '' ? trim($product) : trim((string)$function['product_name']);
        if ($product === '') { $product = trim((string)$function['source_output']); }
        if ($action === '' || $product === '' || \core_text::strlen($schedule) > 255) { self::fail('invalid'); }
        $lock = \core\lock\lock_config::get_lock_factory('local_kbacompany')
            ->get_lock('position_os:' . $companyuuid . ':' . $positionuuid, 10);
        if (!$lock) { self::fail('lock'); }
        try {
            $tx = $DB->start_delegated_transaction();
            $uuid = self::uuid();
            $now = time();
            $row = (object)[
                'companyuuid' => $companyuuid, 'uuid' => $uuid, 'revision' => 1, 'status' => 'draft',
                'positionuuid' => $positionuuid, 'functionuuid' => $functionuuid,
                'functionrevision' => (int)$function['revision'], 'action' => $action, 'product' => $product,
                'frequency' => $frequency, 'schedule' => trim($schedule), 'sortorder' => 0, 'changereason' => '',
                'createdby' => $userid, 'modifiedby' => $userid, 'timecreated' => $now, 'timemodified' => $now,
            ];
            $DB->insert_record('local_kbacompany_poswork', $row);
            $tx->allow_commit();
            return $uuid;
        } catch (\Throwable $e) {
            if (isset($tx)) { $tx->rollback($e); }
            throw $e;
        } finally { $lock->release(); }
    }

    public static function save_work_item(string $companyuuid, int $userid, string $uuid, int $expectedrevision,
            string $frequency, string $schedule, string $action, string $product, string $reason = ''): void {
        global $DB;
        self::authorise_manage($companyuuid, $userid);
        self::validate_frequency($frequency);
        $action = trim($action); $product = trim($product); $schedule = trim($schedule); $reason = trim($reason);
        if ($action === '' || $product === '' || \core_text::strlen($schedule) > 255 || \core_text::strlen($reason) > 4000) {
            self::fail('invalid');
        }
        $lock = \core\lock\lock_config::get_lock_factory('local_kbacompany')->get_lock('position_os:' . $companyuuid, 10);
        if (!$lock) { self::fail('lock'); }
        try {
            $tx = $DB->start_delegated_transaction();
            $old = self::latest_work($companyuuid, $uuid);
            if ((int)$old->revision !== $expectedrevision) { self::fail('conflict'); }
            if ($old->status !== 'draft') { self::fail('immutable'); }
            self::position($companyuuid, $old->positionuuid);
            self::function_for_position($companyuuid, $userid, $old->positionuuid, $old->functionuuid);
            $row = clone $old; unset($row->id);
            $row->revision = $expectedrevision + 1; $row->frequency = $frequency; $row->schedule = $schedule;
            $row->action = $action; $row->product = $product; $row->changereason = $reason;
            $row->modifiedby = $userid; $row->timemodified = time();
            $DB->insert_record('local_kbacompany_poswork', $row);
            $tx->allow_commit();
        } catch (\Throwable $e) {
            if (isset($tx)) { $tx->rollback($e); }
            throw $e;
        } finally { $lock->release(); }
    }

    public static function publish_work_item(string $companyuuid, int $userid, string $uuid, int $expectedrevision): void {
        global $DB;
        self::authorise_manage($companyuuid, $userid);
        $lock = \core\lock\lock_config::get_lock_factory('local_kbacompany')->get_lock('position_os:' . $companyuuid, 10);
        if (!$lock) { self::fail('lock'); }
        try {
            $tx = $DB->start_delegated_transaction();
            $old = self::latest_work($companyuuid, $uuid);
            if ((int)$old->revision !== $expectedrevision) { self::fail('conflict'); }
            if ($old->status !== 'draft') { self::fail('state'); }
            $current = self::function_for_position($companyuuid, $userid, $old->positionuuid, $old->functionuuid);
            if ((int)$current['revision'] !== (int)$old->functionrevision) { self::fail('sourceoutdated'); }
            $row = clone $old; unset($row->id); $row->revision = $expectedrevision + 1; $row->status = 'published';
            $row->modifiedby = $userid; $row->timemodified = time();
            $DB->insert_record('local_kbacompany_poswork', $row);
            $tx->allow_commit();
        } catch (\Throwable $e) {
            if (isset($tx)) { $tx->rollback($e); }
            throw $e;
        } finally { $lock->release(); }
    }

    public static function archive_work_item(string $companyuuid, int $userid, string $uuid, int $expectedrevision,
            string $reason): void {
        global $DB;
        self::authorise_manage($companyuuid, $userid);
        $reason = trim($reason);
        if ($reason === '' || \core_text::strlen($reason) > 4000) { self::fail('reason'); }
        $lock = \core\lock\lock_config::get_lock_factory('local_kbacompany')->get_lock('position_os:' . $companyuuid, 10);
        if (!$lock) { self::fail('lock'); }
        try {
            $tx = $DB->start_delegated_transaction();
            $old = self::latest_work($companyuuid, $uuid);
            if ((int)$old->revision !== $expectedrevision) { self::fail('conflict'); }
            if ($old->status === 'archived') { self::fail('state'); }
            $row = clone $old; unset($row->id); $row->revision = $expectedrevision + 1; $row->status = 'archived';
            $row->changereason = $reason; $row->modifiedby = $userid; $row->timemodified = time();
            $DB->insert_record('local_kbacompany_poswork', $row);
            $tx->allow_commit();
        } catch (\Throwable $e) {
            if (isset($tx)) { $tx->rollback($e); }
            throw $e;
        } finally { $lock->release(); }
    }

    private static function current_work_items(string $companyuuid, bool $canmanage, string $positionuuid): array {
        global $DB;
        $grouped = [];
        foreach ($DB->get_records('local_kbacompany_poswork', [
            'companyuuid' => $companyuuid, 'positionuuid' => $positionuuid,
        ], 'uuid ASC, revision ASC') as $row) { $grouped[$row->uuid][] = $row; }
        $result = [];
        foreach ($grouped as $versions) {
            $latest = end($versions);
            if ($latest->status === 'archived') { continue; }
            if ($canmanage) { $result[] = self::work_dto($latest); continue; }
            foreach (array_reverse($versions) as $candidate) {
                if ($candidate->status === 'published') { $result[] = self::work_dto($candidate); break; }
            }
        }
        usort($result, static function(array $a, array $b): int {
            return [array_search($a['frequency'], self::FREQUENCIES, true), $a['sort_order'], $a['action']] <=>
                [array_search($b['frequency'], self::FREQUENCIES, true), $b['sort_order'], $b['action']];
        });
        return $result;
    }

    private static function effective_documents(string $companyuuid, int $userid, string $positionuuid, array $functions): array {
        $processes = [];
        foreach ($functions as $function) { if ($function['source_process_uuid'] !== '') { $processes[$function['source_process_uuid']] = true; } }
        $documents = [];
        foreach (document_service::master_list($companyuuid, $userid) as $entry) {
            $doc = $entry['effective'] ?? null;
            if (!$doc) { continue; }
            $direct = $doc['owner_position_uuid'] === $positionuuid;
            $processrule = $doc['source_kind'] === 'stp' && isset($processes[$doc['source_uuid']]);
            if ($direct || $processrule) { $documents[] = $doc; }
        }
        return $documents;
    }

    public static function context(string $companyuuid, int $userid, string $positionuuid = ''): array {
        global $DB;
        $company = company_context_service::context($companyuuid, $userid);
        $positionscontext = position_service::positions_context($companyuuid, $userid);
        $positions = $positionscontext['positions'];
        if ($positionuuid === '' && $positions) { $positionuuid = (string)$positions[0]['uuid']; }
        $selected = null;
        foreach ($positions as $position) { if ($position['uuid'] === $positionuuid) { $selected = $position; break; } }
        if ($positionuuid !== '' && !$selected) { self::fail('position'); }
        $canmanage = self::can_manage($companyuuid, $userid);
        if (!$selected) {
            return ['company' => $company, 'positions' => $positions, 'selected' => null, 'ckp' => [], 'functions' => [],
                'work_program' => [], 'unconfigured_functions' => [], 'documents' => [], 'can_manage' => $canmanage,
                'frequencies' => self::FREQUENCIES];
        }
        $ckp = [];
        foreach ($DB->get_records('local_kbacompany_product', [
            'companyuuid' => $companyuuid, 'targettype' => 'position', 'targetuuid' => $positionuuid,
        ], 'code ASC') as $product) {
            $ckp[] = ['uuid' => $product->uuid, 'code' => $product->code, 'name' => $product->name,
                'acceptance' => (string)$product->acceptance];
        }
        $functions = self::published_functions($companyuuid, $userid, $positionuuid);
        $workitems = self::current_work_items($companyuuid, $canmanage, $positionuuid);
        $configured = [];
        foreach ($workitems as $item) { if ($item['status'] !== 'archived') { $configured[$item['function_uuid']] = true; } }
        $unconfigured = array_values(array_filter($functions, static function(array $fn) use ($configured): bool {
            return !isset($configured[$fn['uuid']]);
        }));
        $grouped = array_fill_keys(self::FREQUENCIES, []);
        foreach ($workitems as $item) { $grouped[$item['frequency']][] = $item; }
        $node = $DB->get_record('local_kbacompany_orgnode', [
            'companyuuid' => $companyuuid, 'uuid' => $selected['org_node_uuid'], 'active' => 1,
        ]);
        return [
            'company' => $company, 'positions' => $positions,
            'selected' => array_merge($selected, ['org_node_name' => $node ? (string)$node->name : '']),
            'ckp' => $ckp, 'functions' => $functions, 'work_program' => $grouped,
            'unconfigured_functions' => $unconfigured,
            'documents' => self::effective_documents($companyuuid, $userid, $positionuuid, $functions),
            'can_manage' => $canmanage, 'frequencies' => self::FREQUENCIES,
        ];
    }
}
