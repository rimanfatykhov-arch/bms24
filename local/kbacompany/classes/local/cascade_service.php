<?php
namespace local_kbacompany\local;

defined('MOODLE_INTERNAL') || die();

/** Company-scoped draft foundation; does not claim approvals or task execution. */
class cascade_service {
    public const KINDS = ['intent', 'goal', 'strategy', 'function', 'result', 'target'];
    public const PARENTS = ['intent' => '', 'goal' => 'intent', 'strategy' => 'goal',
        'function' => 'function', 'result' => 'function', 'target' => 'function'];

    public static function contract(): array {
        return [
            'version' => '0.2.5.0',
            'maturity' => 'draft_foundation',
            'objects' => self::KINDS,
            'states' => ['draft', 'archived'],
            'relations' => ['parent', 'strategic_plan_contribution'],
            'rules' => ['function_is_not_employee', 'okr_is_expected_final_result',
                'targets_are_drafts_not_approved_norms', 'existing_plan_values_are_not_imported',
                'no_competency_or_bms_maturity_mutation', 'null_is_not_zero',
                'company_scope_required', 'revision_conflicts_rejected'],
            'execution_service' => 'workflow_service',
            'not_implemented' => ['recurrence', 'escalation', 'ai_predictions'],
        ];
    }

    /** Shared field definitions drive human-facing forms and server validation. */
    public static function fields(string $kind): array {
        $fields = [
            'intent' => ['purpose' => ['text', true]],
            'goal' => ['current' => ['text', true], 'desired' => ['text', true]],
            'strategy' => ['idea' => ['text', true], 'problem' => ['text', true],
                'solution' => ['text', true], 'expected' => ['text', true],
                'start' => ['date', false], 'deadline' => ['date', false]],
            'function' => ['node' => ['node', true], 'position' => ['position', false],
                'mainstat' => ['statistic', false]],
            'result' => ['acceptance' => ['text', true], 'product' => ['product', false]],
            'target' => ['indicator' => ['statistic', true], 'periodstart' => ['date', true],
                'amount' => ['number', true], 'lower' => ['number', false], 'upper' => ['number', false]],
        ];
        if (!isset($fields[$kind])) {
            throw new \moodle_exception('ac_invalid', 'local_kbacompany');
        }
        return $fields[$kind];
    }

    public static function can_manage(string $companyuuid, int $userid): bool {
        return permission_service::can_manage_management($companyuuid, $userid);
    }

    private static function authorise(string $companyuuid, int $userid): void {
        if (!self::can_manage($companyuuid, $userid)) {
            throw new \required_capability_exception(\context_system::instance(),
                'local/kbacompany:manageorganization', 'nopermissions', '');
        }
    }

    private static function uuid(): string {
        $b = random_bytes(16);
        $b[6] = chr((ord($b[6]) & 15) | 64);
        $b[8] = chr((ord($b[8]) & 63) | 128);
        $h = bin2hex($b);
        return substr($h, 0, 8) . '-' . substr($h, 8, 4) . '-' . substr($h, 12, 4) .
            '-' . substr($h, 16, 4) . '-' . substr($h, 20);
    }

    public static function decimal_valid(string $value): bool {
        return (bool)preg_match('/^-?\d{1,12}(\.\d{1,6})?$/D', $value);
    }

    /** Compare decimals without float rounding; values have already been validated. */
    public static function decimal_compare(string $a, string $b): int {
        $normalise = static function(string $value): array {
            $negative = strpos($value, '-') === 0;
            $parts = explode('.', ltrim($value, '-'), 2);
            $digits = str_pad($parts[0], 12, '0', STR_PAD_LEFT) .
                str_pad($parts[1] ?? '', 6, '0', STR_PAD_RIGHT);
            return [$negative && trim($digits, '0') !== '', $digits];
        };
        [$aneg, $av] = $normalise($a);
        [$bneg, $bv] = $normalise($b);
        if ($aneg !== $bneg) {
            return $aneg ? -1 : 1;
        }
        $cmp = strcmp($av, $bv);
        return $aneg ? -$cmp : $cmp;
    }

    private static function object(string $companyuuid, string $uuid, string $kind = ''): \stdClass {
        global $DB;
        $where = ['companyuuid' => $companyuuid, 'uuid' => $uuid, 'status' => 'draft'];
        if ($kind !== '') {
            $where['kind'] = $kind;
        }
        $obj = $DB->get_record('local_kbacompany_acobj', $where);
        if (!$obj) {
            throw new \moodle_exception('ac_reference', 'local_kbacompany');
        }
        return $obj;
    }

    /** Writes and their audit snapshot are committed atomically under a company lock. */
    public static function save(string $companyuuid, int $userid, string $kind, array $data,
            string $uuid = '', int $expectedrevision = 0): string {
        global $DB;
        self::authorise($companyuuid, $userid);
        $definitions = self::fields($kind);
        $lock = self::lock($companyuuid);
        try {
            $transaction = $DB->start_delegated_transaction();
            $old = $uuid === '' ? null : self::object($companyuuid, $uuid, $kind);
            if (($old ? (int)$old->revision : 0) !== $expectedrevision) {
                throw new \moodle_exception('ac_conflict', 'local_kbacompany');
            }
            $code = trim((string)($data['code'] ?? ''));
            $name = trim((string)($data['name'] ?? ''));
            $description = trim((string)($data['description'] ?? ''));
            if ($code === '' || $name === '' || \core_text::strlen($code) > 50 ||
                    \core_text::strlen($name) > 255 || \core_text::strlen($description) > 4000) {
                throw new \moodle_exception('ac_invalid', 'local_kbacompany');
            }
            $duplicate = $DB->get_record('local_kbacompany_acobj', ['companyuuid' => $companyuuid, 'code' => $code]);
            if ($duplicate && (!$old || $duplicate->uuid !== $old->uuid)) {
                throw new \moodle_exception('ac_codeexists', 'local_kbacompany');
            }
            $parent = trim((string)($data['parentuuid'] ?? ''));
            $parentkind = self::PARENTS[$kind];
            if ($parent !== '') {
                if ($parentkind === '') {
                    throw new \moodle_exception('ac_reference', 'local_kbacompany');
                }
                $cursor = self::object($companyuuid, $parent, $parentkind);
                $seen = [];
                while ($cursor) {
                    if ($cursor->uuid === $uuid || isset($seen[$cursor->uuid])) {
                        throw new \moodle_exception('ac_cycle', 'local_kbacompany');
                    }
                    $seen[$cursor->uuid] = true;
                    $cursor = $cursor->parentuuid === '' ? null : self::object($companyuuid, $cursor->parentuuid);
                }
            } else if (in_array($kind, ['goal', 'strategy', 'result', 'target'], true)) {
                throw new \moodle_exception('ac_reference', 'local_kbacompany');
            }
            $payload = [];
            foreach ($definitions as $field => [$type, $required]) {
                $value = trim((string)($data[$field] ?? ''));
                if (($required && $value === '') || \core_text::strlen($value) > 4000) {
                    throw new \moodle_exception('ac_invalid', 'local_kbacompany');
                }
                if ($value === '') {
                    $payload[$field] = null;
                    continue;
                }
                if ($type === 'number' && !self::decimal_valid($value)) {
                    throw new \moodle_exception('ac_invalid', 'local_kbacompany');
                }
                if ($type === 'date') {
                    product_service::validate_period($value, 'daily');
                }
                $tables = ['node' => 'local_kbacompany_orgnode', 'position' => 'local_kbacompany_position',
                    'statistic' => 'local_kbacompany_stat', 'product' => 'local_kbacompany_product'];
                if (isset($tables[$type])) {
                    $where = ['companyuuid' => $companyuuid, 'uuid' => $value];
                    if ($type === 'node' || $type === 'position') {
                        $where['active'] = 1;
                    }
                    if (!$DB->record_exists($tables[$type], $where)) {
                        throw new \moodle_exception('ac_reference', 'local_kbacompany');
                    }
                }
                $payload[$field] = $value;
            }
            if ($kind === 'strategy' && $payload['start'] !== null && $payload['deadline'] !== null &&
                    $payload['start'] > $payload['deadline']) {
                throw new \moodle_exception('ac_invalid', 'local_kbacompany');
            }
            if ($kind === 'function' && $payload['position'] !== null) {
                $position = $DB->get_record('local_kbacompany_position',
                    ['companyuuid' => $companyuuid, 'uuid' => $payload['position']], '*', MUST_EXIST);
                if ($position->orgnodeuuid !== $payload['node']) {
                    throw new \moodle_exception('ac_positionnode', 'local_kbacompany');
                }
            }
            if ($kind === 'result') {
                $existing = $DB->get_records('local_kbacompany_acobj',
                    ['companyuuid' => $companyuuid, 'kind' => 'result', 'parentuuid' => $parent, 'status' => 'draft']);
                foreach ($existing as $result) {
                    if ($result->uuid !== $uuid) {
                        throw new \moodle_exception('ac_resultunique', 'local_kbacompany');
                    }
                }
            }
            if ($kind === 'target') {
                $stat = $DB->get_record('local_kbacompany_stat',
                    ['companyuuid' => $companyuuid, 'uuid' => $payload['indicator']], '*', MUST_EXIST);
                product_service::validate_period($payload['periodstart'], $stat->periodtype);
                if (($payload['lower'] !== null && self::decimal_compare($payload['amount'], $payload['lower']) < 0) ||
                        ($payload['upper'] !== null && self::decimal_compare($payload['amount'], $payload['upper']) > 0)) {
                    throw new \moodle_exception('ac_bounds', 'local_kbacompany');
                }
                foreach ($DB->get_records('local_kbacompany_acobj',
                        ['companyuuid' => $companyuuid, 'kind' => 'target', 'status' => 'draft']) as $other) {
                    $p = json_decode($other->payload, true);
                    if ($other->uuid !== $uuid && $p['indicator'] === $payload['indicator'] &&
                            $p['periodstart'] === $payload['periodstart']) {
                        throw new \moodle_exception('ac_targetunique', 'local_kbacompany');
                    }
                }
            }
            $now = time();
            $record = (object)[
                'companyuuid' => $companyuuid, 'uuid' => $old ? $old->uuid : self::uuid(),
                'kind' => $kind, 'code' => $code, 'name' => $name, 'description' => $description,
                'parentuuid' => $parent, 'payload' => json_encode($payload, JSON_UNESCAPED_UNICODE),
                'revision' => $expectedrevision + 1, 'status' => 'draft',
                'timecreated' => $old ? $old->timecreated : $now, 'timemodified' => $now,
            ];
            if ($old) {
                $record->id = $old->id;
                $DB->update_record('local_kbacompany_acobj', $record);
            } else {
                $record->id = $DB->insert_record('local_kbacompany_acobj', $record);
            }
            self::validate_links($companyuuid);
            self::log($companyuuid, $record->uuid, $userid, $old ? 'update' : 'create', $old, $record);
            $transaction->allow_commit();
            return $record->uuid;
        } catch (\Throwable $e) {
            if (isset($transaction)) {
                $transaction->rollback($e);
            }
            throw $e;
        } finally {
            $lock->release();
        }
    }

    /** Reject a change that would make an existing OKR/indicator/target relation contradictory. */
    private static function validate_links(string $companyuuid): void {
        global $DB;
        $records = $DB->get_records('local_kbacompany_acobj', ['companyuuid' => $companyuuid, 'status' => 'draft']);
        $byuuid = [];
        $results = [];
        foreach ($records as $r) {
            $r->data = json_decode($r->payload, true);
            $byuuid[$r->uuid] = $r;
            if ($r->kind === 'result') {
                $results[$r->parentuuid] = $r;
            }
        }
        foreach ($records as $r) {
            if ($r->kind === 'function' && $r->data['mainstat'] !== null) {
                $stat = $DB->get_record('local_kbacompany_stat',
                    ['companyuuid' => $companyuuid, 'uuid' => $r->data['mainstat']], '*', MUST_EXIST);
                if (!isset($results[$r->uuid]) || $results[$r->uuid]->data['product'] !== $stat->productuuid) {
                    throw new \moodle_exception('ac_statresult', 'local_kbacompany');
                }
            }
            if ($r->kind === 'target') {
                $function = $byuuid[$r->parentuuid];
                if ($function->data['mainstat'] !== $r->data['indicator']) {
                    throw new \moodle_exception('ac_targetstat', 'local_kbacompany');
                }
            }
        }
    }

    public static function contribute(string $companyuuid, int $userid, string $plan, string $function,
            bool $remove = false): void {
        global $DB;
        self::authorise($companyuuid, $userid);
        $lock = self::lock($companyuuid);
        try {
            $transaction = $DB->start_delegated_transaction();
            self::object($companyuuid, $plan, 'strategy');
            self::object($companyuuid, $function, 'function');
            $where = ['companyuuid' => $companyuuid, 'planuuid' => $plan, 'functionuuid' => $function];
            if ($remove && $DB->record_exists('local_kbacompany_aclink', $where)) {
                $DB->delete_records('local_kbacompany_aclink', $where);
                self::log($companyuuid, $function, $userid, 'unlink', $where, null);
            } else if (!$remove && !$DB->record_exists('local_kbacompany_aclink', $where)) {
                $DB->insert_record('local_kbacompany_aclink', (object)($where + ['timecreated' => time()]));
                self::log($companyuuid, $function, $userid, 'contribute', null, $where);
            }
            $transaction->allow_commit();
        } catch (\Throwable $e) {
            if (isset($transaction)) {
                $transaction->rollback($e);
            }
            throw $e;
        } finally {
            $lock->release();
        }
    }

    public static function archive(string $companyuuid, int $userid, string $uuid, int $revision): void {
        global $DB;
        self::authorise($companyuuid, $userid);
        $lock = self::lock($companyuuid);
        try {
            $transaction = $DB->start_delegated_transaction();
            $old = self::object($companyuuid, $uuid);
            if ((int)$old->revision !== $revision) {
                throw new \moodle_exception('ac_conflict', 'local_kbacompany');
            }
            if ($DB->record_exists('local_kbacompany_acobj',
                    ['companyuuid' => $companyuuid, 'parentuuid' => $uuid, 'status' => 'draft']) ||
                    $DB->record_exists('local_kbacompany_aclink', ['companyuuid' => $companyuuid, 'planuuid' => $uuid]) ||
                    $DB->record_exists('local_kbacompany_aclink', ['companyuuid' => $companyuuid, 'functionuuid' => $uuid])) {
                throw new \moodle_exception('ac_referenced', 'local_kbacompany');
            }
            $new = clone $old;
            $new->status = 'archived';
            $new->revision++;
            $new->timemodified = time();
            $DB->update_record('local_kbacompany_acobj', $new);
            self::validate_links($companyuuid);
            self::log($companyuuid, $uuid, $userid, 'archive', $old, $new);
            $transaction->allow_commit();
        } catch (\Throwable $e) {
            if (isset($transaction)) {
                $transaction->rollback($e);
            }
            throw $e;
        } finally {
            $lock->release();
        }
    }

    private static function lock(string $companyuuid) {
        $lock = \core\lock\lock_config::get_lock_factory('local_kbacompany')
            ->get_lock('cascade:' . $companyuuid, 10);
        if (!$lock) {
            throw new \moodle_exception('staffinglockfailed', 'local_kbacompany');
        }
        return $lock;
    }

    private static function log(string $companyuuid, string $uuid, int $userid,
            string $action, $old, $new): void {
        global $DB;
        $DB->insert_record('local_kbacompany_aclog', (object)[
            'companyuuid' => $companyuuid, 'objectuuid' => $uuid, 'action' => $action,
            'actorid' => $userid, 'oldvalue' => json_encode($old, JSON_UNESCAPED_UNICODE),
            'newvalue' => json_encode($new, JSON_UNESCAPED_UNICODE), 'timecreated' => time(),
        ]);
    }

    public static function context(string $companyuuid, int $userid): array {
        global $DB;
        $company = company_context_service::context($companyuuid, $userid);
        $objects = [];
        foreach ($DB->get_records('local_kbacompany_acobj', ['companyuuid' => $companyuuid], 'code ASC') as $r) {
            $r->data = json_decode($r->payload, true);
            unset($r->payload);
            $objects[$r->uuid] = $r;
        }
        $links = array_values($DB->get_records('local_kbacompany_aclink', ['companyuuid' => $companyuuid]));
        $gaps = [];
        foreach ($objects as $obj) {
            if ($obj->kind !== 'function' || $obj->status === 'archived') {
                continue;
            }
            $result = false;
            $strategy = false;
            foreach ($objects as $child) {
                if ($child->kind === 'result' && $child->parentuuid === $obj->uuid && $child->status === 'draft') {
                    $result = true;
                }
            }
            foreach ($links as $link) {
                if ($link->functionuuid === $obj->uuid) {
                    $strategy = true;
                }
            }
            $gaps[$obj->uuid] = ['missing_result' => !$result,
                'missing_mainstat' => empty($obj->data['mainstat']), 'missing_strategy' => !$strategy];
        }
        return ['company' => $company, 'objects' => array_values($objects), 'links' => $links,
            'gaps' => $gaps, 'can_manage' => self::can_manage($companyuuid, $userid),
            'scope' => 'draft_foundation'];
    }

    public static function history(string $companyuuid, int $userid, string $uuid): array {
        global $DB;
        company_context_service::context($companyuuid, $userid);
        if (!$DB->record_exists('local_kbacompany_acobj', ['companyuuid' => $companyuuid, 'uuid' => $uuid])) {
            throw new \moodle_exception('ac_reference', 'local_kbacompany');
        }
        return array_values($DB->get_records('local_kbacompany_aclog',
            ['companyuuid' => $companyuuid, 'objectuuid' => $uuid], 'id DESC', '*', 0, 50));
    }
}
