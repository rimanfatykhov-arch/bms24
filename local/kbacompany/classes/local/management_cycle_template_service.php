<?php
namespace local_kbacompany\local;

defined('MOODLE_INTERNAL') || die();

/**
 * Canonical Management Cycle instances with code-defined standard templates.
 *
 * Templates define cadence only. Indicators, Tasks and Battle Plans remain canonical objects
 * owned by their existing services; this service stores only the cycle wrapper and checkpoints.
 */
class management_cycle_template_service {
    public const CONTRACT_VERSION = '0.9.0.3';
    public const STATUSES = ['draft', 'active', 'closed', 'cancelled'];

    public static function contract(): array {
        return [
            'component' => 'local_kbacompany',
            'contract' => 'bms_management_cycle_templates_cadence',
            'version' => self::CONTRACT_VERSION,
            'storage' => ['local_kbacompany_mcycle', 'local_kbacompany_mcyclecp'],
            'rules' => [
                'one_canonical_management_cycle_engine',
                'templates_define_cadence_not_parallel_task_or_kpi_engines',
                'indicators_remain_canonical_indicator_registry_objects',
                'execution_remains_canonical_task_and_battle_plan_objects',
                'cycles_may_be_nested_year_quarter_month_week',
                'curator_owns_cadence_while_owner_keeps_decision_rights',
                'checkpoint_completion_does_not_close_tasks_or_change_indicator_facts',
                'checkpoint_links_to_at_most_one_canonical_meeting',
                'cycle_curator_may_prepare_cadence_while_cycle_owner_keeps_decision_rights',
                'checkpoint_meeting_preparation_reads_previous_plan_indicators_quotas_and_open_commitments',
                'management_a3_reviews_live_inside_cycles_without_parallel_problem_task_or_kpi_engines',
                'rollover_keeps_open_work_as_references_without_copying_or_rewriting_deadlines',
                'year_quarter_month_week_parent_chain_is_explicit',
                'all_reads_and_writes_are_company_scoped',
            ],
        ];
    }

    public static function templates(): array {
        return [
            'week' => [
                'code'=>'week', 'labelkey'=>'mcycle_template_week', 'days'=>7,
                'checkpointkeys'=>['launch','midweek','closure'],
                'indicator_min'=>0, 'indicator_max'=>5, 'battleplan'=>true,
            ],
            'month' => [
                'code'=>'month', 'labelkey'=>'mcycle_template_month', 'days'=>30,
                'checkpointkeys'=>['launch','review1','review2','closure'],
                'indicator_min'=>1, 'indicator_max'=>5, 'battleplan'=>true,
            ],
            'quarter' => [
                'code'=>'quarter', 'labelkey'=>'mcycle_template_quarter', 'days'=>90,
                'checkpointkeys'=>['launch','midperiod','closure'],
                'indicator_min'=>1, 'indicator_max'=>10, 'battleplan'=>true,
            ],
            'year' => [
                'code'=>'year', 'labelkey'=>'mcycle_template_year', 'days'=>365,
                'checkpointkeys'=>['launch','q1','q2','q3','q4','closure'],
                'indicator_min'=>1, 'indicator_max'=>20, 'battleplan'=>false,
            ],
            'project' => [
                'code'=>'project', 'labelkey'=>'mcycle_template_project', 'days'=>90,
                'checkpointkeys'=>['launch','midperiod','closure'],
                'indicator_min'=>1, 'indicator_max'=>10, 'battleplan'=>true,
            ],
            'advisory4x90' => [
                'code'=>'advisory4x90', 'labelkey'=>'mcycle_template_advisory', 'days'=>30,
                'checkpointkeys'=>['session1','session2','session3','session4'],
                'indicator_min'=>1, 'indicator_max'=>3, 'battleplan'=>true,
            ],
        ];
    }

    private static function uuid(): string {
        $bytes = random_bytes(16);
        $bytes[6] = chr((ord($bytes[6]) & 0x0f) | 0x40);
        $bytes[8] = chr((ord($bytes[8]) & 0x3f) | 0x80);
        $hex = bin2hex($bytes);
        return substr($hex,0,8).'-'.substr($hex,8,4).'-'.substr($hex,12,4).'-'.substr($hex,16,4).'-'.substr($hex,20);
    }

    private static function require_manage(string $companyuuid, int $actor, string $action = 'view'): void {
        access_role_service::require_permission($companyuuid, $actor, 'management_cycle', $action);
    }

    private static function employee_ids(string $companyuuid): array {
        global $DB;
        $ids = $DB->get_fieldset_sql('SELECT m.userid FROM {local_kbacompany_member} m
            JOIN {user} u ON u.id = m.userid
            WHERE m.companyuuid = :companyuuid AND m.active = 1 AND u.deleted = 0 AND u.suspended = 0',
            ['companyuuid'=>$companyuuid]);
        return array_fill_keys(array_map('intval', $ids), true);
    }

    private static function validate_indicator_uuids(string $companyuuid, int $actor, array $uuids, int $max): array {
        $uuids = array_values(array_unique(array_filter(array_map('trim', $uuids))));
        if (count($uuids) > $max) {
            throw new \moodle_exception('mcycle_too_many_indicators', 'local_kbacompany');
        }
        $ctx = indicator_registry_service::context($companyuuid, $actor);
        $allowed = [];
        foreach ((array)($ctx['metrics'] ?? []) as $metric) {
            $allowed[(string)$metric['uuid']] = true;
        }
        foreach ($uuids as $uuid) {
            if (!isset($allowed[$uuid])) {
                throw new \moodle_exception('mcycle_invalid_indicator', 'local_kbacompany');
            }
        }
        return $uuids;
    }

    private static function checkpoint_dates(string $start, string $end, int $count): array {
        $s = strtotime($start . ' 12:00:00 UTC');
        $e = strtotime($end . ' 12:00:00 UTC');
        if ($s === false || $e === false || $e < $s || $count < 1) { return []; }
        if ($count === 1) { return [$s]; }
        $span = $e - $s;
        $dates = [];
        for ($i=0; $i<$count; $i++) {
            $dates[] = (int)round($s + ($span * $i / ($count - 1)));
        }
        return $dates;
    }

    /** Strict calendar validation shared by forms and writes. */
    public static function valid_date(string $date): bool {
        if (!preg_match('/^[0-9]{4}-[0-9]{2}-[0-9]{2}$/D', $date)) { return false; }
        $d = \DateTimeImmutable::createFromFormat('!Y-m-d', $date, new \DateTimeZone('UTC'));
        return $d && $d->format('Y-m-d') === $date && $date >= '1900-01-01' && $date <= '9999-12-31';
    }

    /** A fresh, company-scoped record is the authority for every record operation. */
    public static function record(string $companyuuid, int $actor, string $cycleuuid): \stdClass {
        global $DB;
        self::require_manage($companyuuid, $actor);
        $row = $DB->get_record('local_kbacompany_mcycle', ['companyuuid'=>$companyuuid, 'uuid'=>$cycleuuid]);
        if (!$row) { throw new \moodle_exception('mcycle_not_found', 'local_kbacompany'); }
        return $row;
    }

    public static function edit_token(\stdClass $row): string {
        return hash('sha256', json_encode((array)$row, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }

    /** Domain permissions; assignment never grants a Moodle capability or Company access. */
    public static function actions(string $companyuuid, int $actor, \stdClass $row): array {
        self::require_manage($companyuuid, $actor);
        if ((string)$row->companyuuid !== $companyuuid) { throw new \moodle_exception('mcycle_not_found', 'local_kbacompany'); }
        $owner = $actor === (int)$row->ownerid || is_siteadmin($actor);
        $cadence = $actor === (int)$row->ownerid || $actor === (int)$row->curatorid;
        $mayedit = access_role_service::can($companyuuid, $actor, 'management_cycle', 'edit');
        return [
            'edit' => $mayedit && ($row->status === 'draft' && ($owner || $cadence || $actor === (int)$row->createdby)),
            'start' => $mayedit && ($row->status === 'draft' && $owner),
            'close' => $mayedit && ($row->status === 'active' && $owner),
            'cancel' => $mayedit && in_array($row->status, ['draft','active'], true) && $owner,
            'checkpoint' => $mayedit && ($row->status === 'active' && $cadence),
            'checkpointmeeting' => $mayedit && ($row->status === 'active' && $cadence),
            'rollover' => $mayedit && in_array($row->status, ['active','closed'], true),
            'createchild' => $mayedit && in_array($row->status, ['active','closed'], true) &&
                isset(['year'=>1, 'quarter'=>1, 'month'=>1][$row->templatecode]),
        ];
    }

    /** Validation messages are field-addressable and are also enforced inside the write lock. */
    public static function validation_errors(string $companyuuid, int $actor, array $data,
            string $cycleuuid = ''): array {
        global $DB;
        self::require_manage($companyuuid, $actor, $cycleuuid === '' ? 'create' : 'edit');
        $errors = [];
        $tr = static function($key) { return get_string($key, 'local_kbacompany'); };
        $templatecode = trim((string)($data['templatecode'] ?? ''));
        $template = self::templates()[$templatecode] ?? null;
        if (!$template) { $errors['templatecode'] = $tr('mcycle_invalid_template'); }
        $title = trim((string)($data['title'] ?? ''));
        if ($title === '' || \core_text::strlen($title) > 255) { $errors['title'] = $tr('mcu_bad_title'); }
        foreach (['priority','outcome'] as $field) {
            if (\core_text::strlen((string)($data[$field] ?? '')) > 10000) { $errors[$field] = $tr('mcu_too_long'); }
        }
        $start = trim((string)($data['periodstart'] ?? ''));
        $end = trim((string)($data['periodend'] ?? ''));
        foreach (['periodstart'=>$start, 'periodend'=>$end] as $field=>$date) {
            if (!self::valid_date($date)) { $errors[$field] = $tr('mcu_bad_date'); }
        }
        if (self::valid_date($start) && self::valid_date($end) && $end < $start) {
            $errors['periodend'] = $tr('mcu_end_before_start');
        }
        $employees = self::employee_ids($companyuuid);
        foreach (['ownerid','curatorid'] as $field) {
            if (!isset($employees[(int)($data[$field] ?? 0)])) { $errors[$field] = $tr('mcycle_invalid_people'); }
        }
        $parentuuid = trim((string)($data['parentuuid'] ?? ''));
        if ($parentuuid !== '') {
            $parent = $DB->get_record('local_kbacompany_mcycle', ['companyuuid'=>$companyuuid, 'uuid'=>$parentuuid]);
            if (!$parent || $parentuuid === $cycleuuid || $parent->status === 'cancelled') {
                $errors['parentuuid'] = $tr('mcycle_invalid_parent');
            } else {
                $levels = ['quarter'=>'year','month'=>'quarter','week'=>'month'];
                if ($templatecode === 'year' || (isset($levels[$templatecode]) && $parent->templatecode !== $levels[$templatecode])) {
                    $errors['parentuuid'] = $tr('mcycle_invalid_parent_level');
                }
                if (self::valid_date($start) && self::valid_date($end) &&
                        ($start < $parent->periodstart || $end > $parent->periodend)) {
                    $errors['periodend'] = $tr('mcycle_invalid_parent_period');
                }
                // Inspect only scoped ancestor identities, never load all cycles to find a parent.
                $seen = $cycleuuid === '' ? [] : [$cycleuuid=>true];
                $cursor = $parent;
                for ($depth=0; $cursor; $depth++) {
                    if ($depth >= 64 || isset($seen[$cursor->uuid])) {
                        $errors['parentuuid'] = $tr('mcycle_invalid_parent'); break;
                    }
                    $seen[$cursor->uuid] = true;
                    if ($cursor->parentuuid === '') { break; }
                    $cursor = $DB->get_record('local_kbacompany_mcycle',
                        ['companyuuid'=>$companyuuid, 'uuid'=>$cursor->parentuuid]);
                    if (!$cursor) { $errors['parentuuid'] = $tr('mcycle_invalid_parent'); }
                }
            }
        }
        if ($cycleuuid !== '' && self::valid_date($start) && self::valid_date($end)) {
            if ($DB->record_exists_select('local_kbacompany_mcycle',
                    'companyuuid = :c AND parentuuid = :p AND (periodstart < :s OR periodend > :e)',
                    ['c'=>$companyuuid, 'p'=>$cycleuuid, 's'=>$start, 'e'=>$end])) {
                $errors['periodend'] = $tr('mcu_children_outside');
            }
        }
        if ($template) {
            $uuids = array_values(array_unique(array_filter(array_map('trim', (array)($data['indicatoruuids'] ?? [])))));
            if (count($uuids) < $template['indicator_min']) { $errors['indicatoruuids'] = $tr('mcycle_not_enough_indicators'); }
            if (count($uuids) > $template['indicator_max']) { $errors['indicatoruuids'] = $tr('mcycle_too_many_indicators'); }
            foreach ($uuids as $uuid) {
                if (!$DB->record_exists('local_kbacompany_stat', ['companyuuid'=>$companyuuid,'uuid'=>$uuid])) {
                    $errors['indicatoruuids'] = $tr('mcycle_invalid_indicator'); break;
                }
            }
        }
        return $errors;
    }

    private static function normalised(array $data): array {
        $out = [];
        foreach (['templatecode','title','priority','outcome','periodstart','periodend','parentuuid'] as $f) {
            $out[$f] = trim((string)($data[$f] ?? ''));
        }
        foreach (['ownerid','curatorid'] as $f) { $out[$f] = (int)($data[$f] ?? 0); }
        $out['indicatoruuids'] = array_values(array_unique(array_filter(array_map('trim', (array)($data['indicatoruuids'] ?? [])))));
        sort($out['indicatoruuids']);
        return $out;
    }

    private static function lock(string $companyuuid) {
        // Serialize hierarchy/period changes in this Company, including draft edits and checkpoints.
        $lock = \core\lock\lock_config::get_lock_factory('local_kbacompany')->get_lock('mcycle-write:'.$companyuuid, 10);
        if (!$lock) { throw new \moodle_exception('mcycle_meeting_lock', 'local_kbacompany'); }
        return $lock;
    }

    private static function check_token(\stdClass $row, string $expected): void {
        if ($expected !== '' && !hash_equals(self::edit_token($row), $expected)) {
            throw new \moodle_exception('mcu_stale', 'local_kbacompany');
        }
    }

    private static function log_change(\stdClass $row, int $actor, string $action, array $fields = []): void {
        \local_kbacompany\event\management_cycle_changed::create([
            'context'=>\context_system::instance(), 'objectid'=>(int)$row->id, 'userid'=>$actor,
            'other'=>['companyuuid'=>(string)$row->companyuuid, 'cycleuuid'=>(string)$row->uuid,
                'operation'=>$action, 'fields'=>array_values($fields)],
        ])->trigger();
    }

    public static function create(string $companyuuid, int $actor, array $data): string {
        global $DB;
        self::require_manage($companyuuid, $actor, 'create');
        $clean = self::normalised($data);
        $key = trim((string)($data['requestkey'] ?? ''));
        if ($key !== '' && !preg_match('/^[a-f0-9]{32}$/D', $key)) { throw new \moodle_exception('invalidparameter'); }
        $fingerprint = hash('sha256', json_encode($clean));
        $lock = self::lock($companyuuid);
        try {
            if ($key !== '') {
                $match = $DB->get_record_select('local_kbacompany_mcycle',
                    'companyuuid = :c AND createdby = :a AND '.$DB->sql_like('statepayload', ':k'),
                    ['c'=>$companyuuid,'a'=>$actor,'k'=>'%"requestkey":"'.$key.'"%']);
                if ($match) {
                    $previous = json_decode((string)$match->statepayload, true);
                    if (!hash_equals((string)($previous['requesthash'] ?? ''), $fingerprint)) {
                        throw new \moodle_exception('mcu_stale', 'local_kbacompany');
                    }
                    return (string)$match->uuid;
                }
            }
            if ($errors = self::validation_errors($companyuuid, $actor, $clean)) {
                throw new \moodle_exception('mcu_validation', 'local_kbacompany', '', reset($errors));
            }
            $now = time(); $uuid = self::uuid();
            $payload = ['indicatoruuids'=>$clean['indicatoruuids']];
            if ($key !== '') { $payload += ['requestkey'=>$key, 'requesthash'=>$fingerprint]; }
            unset($clean['indicatoruuids']);
            $row = (object)($clean + [
                'uuid'=>$uuid,'companyuuid'=>$companyuuid,'scopekind'=>(string)($data['scopekind'] ?? 'company'),
                'scopeuuid'=>trim((string)($data['scopeuuid'] ?? '')), 'status'=>'draft',
                'statepayload'=>json_encode($payload, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES),
                'createdby'=>$actor, 'timecreated'=>$now, 'timemodified'=>$now,
            ]);
            $tx = $DB->start_delegated_transaction();
            try {
                $row->id = $DB->insert_record('local_kbacompany_mcycle', $row);
                $keys = self::templates()[$row->templatecode]['checkpointkeys'];
                $dates = self::checkpoint_dates($row->periodstart, $row->periodend, count($keys));
                foreach ($keys as $i=>$kind) {
                    $DB->insert_record('local_kbacompany_mcyclecp', (object)[
                        'uuid'=>self::uuid(),'companyuuid'=>$companyuuid,'cycleuuid'=>$uuid,'sequence'=>$i+1,
                        'kind'=>$kind,'title'=>get_string('mcycle_checkpoint_'.$kind, 'local_kbacompany'),
                        'plannedat'=>$dates[$i],'meetinguuid'=>'','status'=>'planned','result'=>null,
                        'createdby'=>$actor,'timecreated'=>$now,'timemodified'=>$now,
                    ]);
                }
                self::log_change($row, $actor, 'created');
                $tx->allow_commit();
            } catch (\Throwable $e) { $tx->rollback($e); }
            return $uuid;
        } finally { $lock->release(); }
    }

    /** Draft-only edit; no silent rewriting of active/closed periods or checkpoint identities. */
    public static function update_draft(string $companyuuid, int $actor, string $cycleuuid,
            string $expected, array $data): void {
        global $DB;
        self::require_manage($companyuuid, $actor, 'edit');
        $lock = self::lock($companyuuid);
        try {
            $row = self::record($companyuuid, $actor, $cycleuuid);
            if (!self::actions($companyuuid, $actor, $row)['edit']) { throw new \moodle_exception('mcu_edit_draft', 'local_kbacompany'); }
            if ($expected === '') { throw new \moodle_exception('mcu_stale', 'local_kbacompany'); }
            self::check_token($row, $expected);
            $data['templatecode'] = (string)$row->templatecode;
            $clean = self::normalised($data);
            if ($errors = self::validation_errors($companyuuid, $actor, $clean, $cycleuuid)) { throw new \moodle_exception('mcu_validation', 'local_kbacompany', '', reset($errors)); }
            $checkpoints = $DB->get_records('local_kbacompany_mcyclecp', ['companyuuid'=>$companyuuid,'cycleuuid'=>$cycleuuid], 'sequence ASC');
            $dateschanged = $row->periodstart !== $clean['periodstart'] || $row->periodend !== $clean['periodend'];
            if ($dateschanged) {
                foreach ($checkpoints as $cp) {
                    if ($cp->status !== 'planned' || $cp->meetinguuid !== '') { throw new \moodle_exception('mcu_dates_locked', 'local_kbacompany'); }
                }
            }
            $payload = json_decode((string)$row->statepayload, true);
            if (!is_array($payload)) { $payload = []; }
            $changed = [];
            if (($payload['indicatoruuids'] ?? []) !== $clean['indicatoruuids']) { $changed[] = 'indicatoruuids'; }
            $payload['indicatoruuids'] = $clean['indicatoruuids']; unset($clean['indicatoruuids']);
            foreach ($clean as $key=>$value) { if ($row->$key !== $value) { $changed[] = $key; $row->$key = $value; } }
            if (!$changed) { return; }
            $row->statepayload = json_encode($payload, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
            $row->timemodified = time();
            $tx = $DB->start_delegated_transaction();
            try {
                $DB->update_record('local_kbacompany_mcycle', $row);
                if ($dateschanged) {
                    $dates = self::checkpoint_dates($row->periodstart, $row->periodend, count($checkpoints));
                    foreach (array_values($checkpoints) as $i=>$cp) {
                        $cp->plannedat = $dates[$i]; $cp->timemodified = time();
                        $DB->update_record('local_kbacompany_mcyclecp', $cp);
                    }
                }
                self::log_change($row, $actor, 'updated', $changed);
                $tx->allow_commit();
            } catch (\Throwable $e) { $tx->rollback($e); }
        } finally { $lock->release(); }
    }

    public static function transition(string $companyuuid, int $actor, string $cycleuuid, string $status,
            string $expected = ''): void {
        global $DB;
        self::require_manage($companyuuid, $actor, 'edit');
        $lock = self::lock($companyuuid);
        try {
            $row = self::record($companyuuid, $actor, $cycleuuid);
            $operation = ['active'=>'start','closed'=>'close','cancelled'=>'cancel'][$status] ?? '';
            if ($operation === '' || ($actor !== (int)$row->ownerid && !is_siteadmin($actor))) {
                throw new \moodle_exception('mcu_owner_action', 'local_kbacompany');
            }
            if ($row->status === $status) { return; } // Retry is a no-op, not a new history entry.
            self::check_token($row, $expected);
            if (!self::actions($companyuuid, $actor, $row)[$operation]) { throw new \moodle_exception('mcycle_invalid_status', 'local_kbacompany'); }
            if ($status === 'active') {
                $data = (array)$row;
                $payload = json_decode((string)$row->statepayload, true);
                $data['indicatoruuids'] = $payload['indicatoruuids'] ?? [];
                if (self::validation_errors($companyuuid, $actor, $data, $cycleuuid)) { throw new \moodle_exception('mcu_start_invalid', 'local_kbacompany'); }
            }
            $row->status = $status; $row->timemodified = time();
            $tx = $DB->start_delegated_transaction();
            try {
                $DB->update_record('local_kbacompany_mcycle', $row);
                self::log_change($row, $actor, $status, ['status']);
                $tx->allow_commit();
            } catch (\Throwable $e) { $tx->rollback($e); }
        } finally { $lock->release(); }
    }

    /**
     * Create or open the one canonical meeting attached to a management-cycle checkpoint.
     *
     * This method stores only the meeting UUID on the checkpoint. Meeting content and lifecycle
     * stay owned by meeting_service. Repeated calls are idempotent and return the same meeting.
     */
    public static function checkpoint_meeting(string $companyuuid, int $actor, string $checkpointuuid): string {
        global $DB;
        self::require_manage($companyuuid, $actor, 'edit');
        $cp = $DB->get_record('local_kbacompany_mcyclecp',
            ['companyuuid'=>$companyuuid,'uuid'=>$checkpointuuid], '*', MUST_EXIST);
        $cycle = $DB->get_record('local_kbacompany_mcycle',
            ['companyuuid'=>$companyuuid,'uuid'=>$cp->cycleuuid], '*', MUST_EXIST);
        if ((string)$cycle->status !== 'active') {
            throw new \moodle_exception('mcycle_meeting_requires_active', 'local_kbacompany');
        }
        if ($actor !== (int)$cycle->ownerid && $actor !== (int)$cycle->curatorid) {
            throw new \required_capability_exception(\context_system::instance(),
                permission_service::CAPABILITY_MANAGE, 'nopermissions', '');
        }

        $lock = self::lock($companyuuid);
        if (!$lock) {
            throw new \moodle_exception('mcycle_meeting_lock', 'local_kbacompany');
        }
        try {
            // Re-read lifecycle and assignment under the same lock as transition/edit.
            $cycle = self::record($companyuuid, $actor, $cp->cycleuuid);
            if (!self::actions($companyuuid, $actor, $cycle)['checkpointmeeting']) {
                throw new \moodle_exception('mcycle_meeting_requires_active', 'local_kbacompany');
            }
            // Re-read inside the lock so simultaneous requests cannot create duplicates.
            $cp = $DB->get_record('local_kbacompany_mcyclecp',
                ['companyuuid'=>$companyuuid,'uuid'=>$checkpointuuid], '*', MUST_EXIST);
            if ((string)$cp->meetinguuid !== '') {
                $meeting = $DB->get_record('local_kbacompany_meeting',
                    ['companyuuid'=>$companyuuid,'uuid'=>(string)$cp->meetinguuid], 'uuid');
                if (!$meeting) {
                    throw new \moodle_exception('mcycle_meeting_missing', 'local_kbacompany');
                }
                return (string)$meeting->uuid;
            }

            if ($cp->status === 'done') { throw new \moodle_exception('mcu_checkpoint_immutable', 'local_kbacompany'); }
            $participants = array_values(array_unique(array_filter([
                (int)$cycle->ownerid,
                (int)$cycle->curatorid,
            ], function($userid) use ($actor) { return $userid > 0 && $userid !== $actor; })));
            $chairid = (int)$cycle->ownerid > 0 ? (int)$cycle->ownerid : $actor;
            $purposeparts = [];
            if (trim((string)$cycle->priority) !== '') {
                $purposeparts[] = get_string('mcycle_meeting_priority_prefix', 'local_kbacompany') . ' ' . trim((string)$cycle->priority);
            }
            if (trim((string)$cycle->outcome) !== '') {
                $purposeparts[] = get_string('mcycle_meeting_outcome_prefix', 'local_kbacompany') . ' ' . trim((string)$cycle->outcome);
            }
            $purposeparts[] = get_string('mcycle_meeting_checkpoint_prefix', 'local_kbacompany') . ' ' . (string)$cp->title;
            $title = get_string('mcycle_meeting_title', 'local_kbacompany', (object)[
                'cycle'=>(string)$cycle->title,
                'checkpoint'=>(string)$cp->title,
            ]);

            $tx = $DB->start_delegated_transaction();
            try {
                $meetinguuid = meeting_service::create($companyuuid, $actor, [
                    'title'=>$title,
                    'purpose'=>implode("\n", $purposeparts),
                    'location'=>'',
                    'startsat'=>0,
                    'endsat'=>0,
                    'chairid'=>$chairid,
                    'secretaryid'=>0,
                    'participants'=>$participants,
                    'sourcekind'=>'manual',
                    'sourceuuid'=>'',
                    'sourcerevision'=>0,
                    'sourcethreaduuid'=>'',
                ]);
                $cp->meetinguuid = $meetinguuid;
                $cp->timemodified = time();
                $DB->update_record('local_kbacompany_mcyclecp', $cp);
                self::log_change($cycle, $actor, 'meeting', ['checkpoint']);
                $tx->allow_commit();
                return $meetinguuid;
            } catch (\Throwable $e) { $tx->rollback($e); }
        } finally {
            $lock->release();
        }
    }

    public static function complete_checkpoint(string $companyuuid, int $actor, string $checkpointuuid, string $result): void {
        global $DB;
        self::require_manage($companyuuid, $actor, 'edit');
        $lock = self::lock($companyuuid);
        try {
            $cp = $DB->get_record('local_kbacompany_mcyclecp', ['companyuuid'=>$companyuuid,'uuid'=>$checkpointuuid], '*', MUST_EXIST);
            $cycle = self::record($companyuuid, $actor, $cp->cycleuuid);
            if (!self::actions($companyuuid, $actor, $cycle)['checkpoint']) {
                throw new \moodle_exception('mcycle_checkpoint_requires_active', 'local_kbacompany');
            }
            $result = trim($result);
            if ($cp->status === 'done') {
                if ((string)$cp->result === $result) { return; }
                throw new \moodle_exception('mcu_checkpoint_immutable', 'local_kbacompany');
            }
            if ($result === '' || \core_text::strlen($result) > 5000) { throw new \moodle_exception('mcu_result_required', 'local_kbacompany'); }
            $cp->status = 'done'; $cp->result = $result; $cp->timemodified = time();
            $tx = $DB->start_delegated_transaction();
            try {
                $DB->update_record('local_kbacompany_mcyclecp', $cp);
                self::log_change($cycle, $actor, 'checkpoint', ['checkpoint']);
                $tx->allow_commit();
            } catch (\Throwable $e) { $tx->rollback($e); }
        } finally { $lock->release(); }
    }

    private static function user_name(int $userid): string {
        global $DB;
        if ($userid <= 0) { return get_string('mcu_not_set','local_kbacompany'); }
        $user = $DB->get_record('user', ['id'=>$userid], 'id,firstname,lastname,firstnamephonetic,lastnamephonetic,middlename,alternatename');
        return $user ? fullname($user) : (string)$userid;
    }

    private static function projection(string $companyuuid, \stdClass $row): array {
        global $DB;
        $checkpoints = array_values($DB->get_records('local_kbacompany_mcyclecp',
            ['companyuuid'=>$companyuuid,'cycleuuid'=>$row->uuid], 'sequence ASC'));
        $done = 0; $due = 0; $today = time();
        foreach ($checkpoints as $cp) {
            if ((string)$cp->status === 'done') { $done++; }
            if ((string)$cp->status !== 'done' && (int)$cp->plannedat > 0 && gmdate('Y-m-d', (int)$cp->plannedat) < gmdate('Y-m-d', $today)) { $due++; }
        }
        $payload = json_decode((string)$row->statepayload, true);
        if (!is_array($payload)) { $payload = []; }
        $plans = [];
        foreach ($DB->get_records_select('local_kbacompany_bplan',
            'companyuuid = :c AND userid = :u AND periodstart >= :s AND periodstart <= :e',
            ['c'=>$companyuuid,'u'=>(int)$row->ownerid,'s'=>(string)$row->periodstart,'e'=>(string)$row->periodend], 'periodstart ASC') as $plan) {
            $plans[] = [
                'uuid'=>(string)$plan->uuid, 'periodstart'=>(string)$plan->periodstart,
                'status'=>(string)$plan->status,
                'url'=>(new \moodle_url('/local/kbacompany/battle_plan.php',['uuid'=>$companyuuid,'plan'=>(string)$plan->uuid]))->out(false),
            ];
        }
        $carrytasks = [];
        $rollover = (array)($payload['rollover'] ?? []);
        foreach ((array)($rollover['carrytaskuuids'] ?? []) as $taskuuid) {
            $taskuuid = trim((string)$taskuuid);
            if ($taskuuid === '') { continue; }
            $task = $DB->get_record('local_kbacompany_task', ['companyuuid'=>$companyuuid,'uuid'=>$taskuuid],
                'uuid,title,product,status,duedate,assigneeid');
            if (!$task) { continue; }
            $carrytasks[] = [
                'uuid'=>(string)$task->uuid,
                'title'=>(string)$task->title,
                'product'=>(string)$task->product,
                'status'=>(string)$task->status,
                'duedate'=>(string)$task->duedate,
                'assigneeid'=>(int)$task->assigneeid,
                'url'=>(new \moodle_url('/local/kbacompany/tasks.php', ['uuid'=>$companyuuid,'task'=>(string)$task->uuid]))->out(false),
            ];
        }
        $rollover['carrytasks'] = $carrytasks;
        return [
            'edit_token'=>self::edit_token($row), 'actions'=>[], 'timecreated'=>(int)$row->timecreated,
            'timemodified'=>(int)$row->timemodified, 'createdby'=>(int)$row->createdby,
            'uuid'=>(string)$row->uuid, 'parentuuid'=>(string)$row->parentuuid, 'templatecode'=>(string)$row->templatecode,
            'title'=>(string)$row->title, 'scopekind'=>(string)$row->scopekind, 'scopeuuid'=>(string)$row->scopeuuid,
            'ownerid'=>(int)$row->ownerid, 'ownername'=>self::user_name((int)$row->ownerid),
            'curatorid'=>(int)$row->curatorid, 'curatorname'=>self::user_name((int)$row->curatorid),
            'priority'=>(string)$row->priority, 'outcome'=>(string)$row->outcome,
            'periodstart'=>(string)$row->periodstart, 'periodend'=>(string)$row->periodend, 'status'=>(string)$row->status,
            'indicatoruuids'=>(array)($payload['indicatoruuids'] ?? []),
            'checkpoints'=>$checkpoints, 'checkpoint_done'=>$done, 'checkpoint_total'=>count($checkpoints), 'checkpoint_overdue'=>$due,
            'battleplans'=>$plans, 'rollover'=>$rollover,
        ];
    }

    /** Bounded list query; filters and Company scope are applied before retrieval. */
    public static function listing(string $companyuuid, int $actor, array $filters, int $page = 0): array {
        global $DB;
        self::require_manage($companyuuid, $actor);
        $where = 'c.companyuuid = :company'; $params = ['company'=>$companyuuid];
        $q = \core_text::substr(trim((string)($filters['q'] ?? '')), 0, 100);
        if ($q !== '') {
            $where .= ' AND '.$DB->sql_like('c.title', ':q', false);
            $params['q'] = '%'.$DB->sql_like_escape($q).'%';
        }
        foreach (['status'=>self::STATUSES, 'templatecode'=>array_keys(self::templates())] as $field=>$allowed) {
            $value = (string)($filters[$field] ?? '');
            if ($value !== '' && !in_array($value, $allowed, true)) { throw new \moodle_exception('invalidparameter'); }
            if ($value !== '') { $where .= ' AND c.'.$field.' = :'.$field; $params[$field] = $value; }
        }
        $owner = (int)($filters['ownerid'] ?? 0);
        if ($owner > 0) { $where .= ' AND c.ownerid = :owner'; $params['owner'] = $owner; }
        foreach (['from'=>'c.periodend >= :fromdate', 'to'=>'c.periodstart <= :todate'] as $field=>$sql) {
            $value = (string)($filters[$field] ?? '');
            if ($value === '') { continue; }
            if (!self::valid_date($value)) { throw new \moodle_exception('mcu_bad_date','local_kbacompany'); }
            $where .= ' AND '.$sql; $params[$field.'date'] = $value;
        }
        $total = $DB->count_records_sql('SELECT COUNT(1) FROM {local_kbacompany_mcycle} c WHERE '.$where, $params);
        $size = 25; $page = max(0, min($page, max(0, (int)ceil($total / $size)-1)));
        $sql = "SELECT c.id,c.uuid,c.title,c.templatecode,c.periodstart,c.periodend,c.status,c.ownerid,c.curatorid,
                       (SELECT COUNT(1) FROM {local_kbacompany_mcyclecp} cp
                         WHERE cp.companyuuid = c.companyuuid AND cp.cycleuuid = c.uuid) AS checkpoint_total,
                       (SELECT COUNT(1) FROM {local_kbacompany_mcyclecp} cp
                         WHERE cp.companyuuid = c.companyuuid AND cp.cycleuuid = c.uuid AND cp.status = 'done') AS checkpoint_done,
                       (SELECT MIN(cp.plannedat) FROM {local_kbacompany_mcyclecp} cp
                         WHERE cp.companyuuid = c.companyuuid AND cp.cycleuuid = c.uuid AND cp.status = 'planned') AS nextat
                  FROM {local_kbacompany_mcycle} c WHERE {$where} ORDER BY c.periodstart DESC,c.id DESC";
        $rows = [];
        foreach ($DB->get_records_sql($sql, $params, $page*$size, $size) as $row) {
            $item = (array)$row;
            $item['ownername'] = self::user_name((int)$row->ownerid);
            $item['curatorname'] = self::user_name((int)$row->curatorid);
            $rows[] = $item;
        }
        $summary = [];
        foreach (self::STATUSES as $status) {
            $summary[$status] = $DB->count_records('local_kbacompany_mcycle', ['companyuuid'=>$companyuuid,'status'=>$status]);
        }
        return ['rows'=>$rows,'total'=>(int)$total,'page'=>$page,'pagesize'=>$size,'summary'=>$summary];
    }

    /** Manager-only planned checkpoints for the BMS Calendar. */
    public static function calendar_projection(string $companyuuid, int $actor, int $fromts, int $tots): array {
        global $DB;
        self::require_manage($companyuuid,$actor);
        if($fromts<=0 || $tots<$fromts){throw new \moodle_exception('invalidparameter');}
        $sql="SELECT cp.uuid,cp.cycleuuid,cp.title,cp.status,cp.plannedat,c.title AS cycletitle,c.status AS cyclestatus
                FROM {local_kbacompany_mcyclecp} cp
                JOIN {local_kbacompany_mcycle} c ON c.companyuuid=cp.companyuuid AND c.uuid=cp.cycleuuid
               WHERE cp.companyuuid=:company AND cp.plannedat BETWEEN :fromts AND :tots
            ORDER BY cp.plannedat,cp.id";
        $rows=[];
        foreach($DB->get_records_sql($sql,['company'=>$companyuuid,'fromts'=>$fromts,'tots'=>$tots]) as $row){
            $rows[]=['uuid'=>(string)$row->uuid,'cycle_uuid'=>(string)$row->cycleuuid,'title'=>(string)$row->title,
                'status'=>(string)$row->status,'planned_at'=>(int)$row->plannedat,'cycle_title'=>(string)$row->cycletitle,
                'cycle_status'=>(string)$row->cyclestatus];
        }
        return $rows;
    }

    /** Manager-only management-cycle period projection for the BMS Calendar. */
    public static function cycle_calendar_projection(string $companyuuid, int $actor, string $fromdate, string $todate): array {
        global $DB;
        self::require_manage($companyuuid, $actor);
        if (!self::valid_date($fromdate) || !self::valid_date($todate) || $todate < $fromdate) {
            throw new \moodle_exception('invalidparameter');
        }
        $types = ['week','month','quarter','year'];
        list($insql, $inparams) = $DB->get_in_or_equal($types, SQL_PARAMS_NAMED, 'ct');
        $params = ['company'=>$companyuuid, 'fromdate'=>$fromdate, 'todate'=>$todate] + $inparams;
        $sql = "SELECT c.uuid,c.parentuuid,c.templatecode,c.title,c.status,c.periodstart,c.periodend,c.ownerid,c.curatorid
                  FROM {local_kbacompany_mcycle} c
                 WHERE c.companyuuid = :company
                   AND c.templatecode $insql
                   AND c.status <> 'cancelled'
                   AND c.periodstart <= :todate
                   AND c.periodend >= :fromdate
              ORDER BY c.periodstart ASC,c.periodend ASC,c.id ASC";
        $rows = [];
        foreach ($DB->get_records_sql($sql, $params) as $row) {
            $rows[] = [
                'uuid'=>(string)$row->uuid, 'parent_uuid'=>(string)$row->parentuuid,
                'template_code'=>(string)$row->templatecode, 'title'=>(string)$row->title,
                'status'=>(string)$row->status, 'period_start'=>(string)$row->periodstart,
                'period_end'=>(string)$row->periodend, 'owner_id'=>(int)$row->ownerid,
                'curator_id'=>(int)$row->curatorid,
            ];
        }
        return $rows;
    }

    /** Pickers read only current Company data, without full indicator histories or cycle projections. */
    public static function form_options(string $companyuuid, int $actor, string $template = '', string $exclude = ''): array {
        global $DB;
        self::require_manage($companyuuid, $actor);
        $members = [];
        $sql = "SELECT DISTINCT u.id,u.firstname,u.lastname,u.firstnamephonetic,u.lastnamephonetic,u.middlename,u.alternatename
                  FROM {local_kbacompany_member} m JOIN {user} u ON u.id = m.userid
                 WHERE m.companyuuid = :c AND m.active = 1 AND u.deleted = 0 AND u.suspended = 0
              ORDER BY u.lastname,u.firstname,u.id";
        foreach ($DB->get_records_sql($sql, ['c'=>$companyuuid]) as $u) { $members[(int)$u->id] = fullname($u); }
        $where = "companyuuid = :c AND uuid <> :exclude AND status <> 'cancelled'";
        $params = ['c'=>$companyuuid, 'exclude'=>$exclude];
        $parenttype = ['quarter'=>'year','month'=>'quarter','week'=>'month'][$template] ?? '';
        if ($parenttype !== '') { $where .= ' AND templatecode = :template'; $params['template'] = $parenttype; }
        $parents = $template === 'year' ? [] : array_values($DB->get_records_select('local_kbacompany_mcycle', $where, $params,
            'periodstart DESC,id DESC', 'id,uuid,title,templatecode,periodstart,periodend,status'));
        return ['templates'=>self::templates(),'members'=>$members,'parents'=>$parents,
            'indicators'=>array_values($DB->get_records('local_kbacompany_stat', ['companyuuid'=>$companyuuid], 'code ASC', 'id,uuid,code,name'))];
    }

    /** DetailView loads exactly the requested cycle, not all Company cycle projections. */
    public static function detail(string $companyuuid, int $actor, string $cycleuuid): array {
        global $DB;
        $row = self::record($companyuuid, $actor, $cycleuuid);
        $model = self::projection($companyuuid, $row);
        $model['actions'] = self::actions($companyuuid, $actor, $row);
        $model['parent'] = $row->parentuuid === '' ? null : $DB->get_record('local_kbacompany_mcycle',
            ['companyuuid'=>$companyuuid,'uuid'=>$row->parentuuid], 'id,uuid,title,periodstart,periodend');
        $model['children'] = array_values($DB->get_records('local_kbacompany_mcycle',
            ['companyuuid'=>$companyuuid,'parentuuid'=>$cycleuuid], 'periodstart ASC,id ASC',
            'id,uuid,title,periodstart,periodend,status', 0, 50));
        $model['children_total'] = $DB->count_records('local_kbacompany_mcycle', ['companyuuid'=>$companyuuid,'parentuuid'=>$cycleuuid]);
        $model['indicators'] = [];
        foreach ($model['indicatoruuids'] as $indicatoruuid) {
            $metric = $DB->get_record('local_kbacompany_stat', ['companyuuid'=>$companyuuid,'uuid'=>$indicatoruuid], 'id,uuid,code,name');
            if ($metric) { $model['indicators'][] = (array)$metric; }
        }
        // A manager is not automatically a participant in a private Meeting.
        $meetings = $DB->get_records_sql("SELECT DISTINCT m.id,m.uuid FROM {local_kbacompany_meeting} m
            JOIN {local_kbacompany_mcyclecp} cp ON cp.companyuuid = m.companyuuid AND cp.meetinguuid = m.uuid
            WHERE m.companyuuid = :c AND cp.cycleuuid = :cycle AND EXISTS (
                SELECT p.id FROM {local_kbacompany_meetpart} p WHERE p.companyuuid = m.companyuuid
                AND p.meetinguuid = m.uuid AND p.userid = :actor AND p.active = 1)",
            ['c'=>$companyuuid,'cycle'=>$cycleuuid,'actor'=>$actor]);
        $allowed = []; foreach ($meetings as $m) { $allowed[$m->uuid] = true; }
        foreach ($model['checkpoints'] as $cp) { $cp->canopenmeeting = isset($allowed[$cp->meetinguuid]); }
        $model['createdbyname'] = self::user_name((int)$row->createdby);
        return $model;
    }

    /** Core logging owns storage/retention. Read only events for the authorised scoped object ID. */
    public static function history(string $companyuuid, int $actor, string $cycleuuid): array {
        $row = self::record($companyuuid, $actor, $cycleuuid);
        $out = ['available'=>false,'rows'=>[]];
        if (!function_exists('get_log_manager')) { return $out; }
        foreach (get_log_manager()->get_readers('\\core\\log\\sql_reader') as $reader) {
            try {
                $events = $reader->get_events_select(
                    'component = :component AND eventname = :event AND objecttable = :tbl AND objectid = :id',
                    ['component'=>'local_kbacompany','event'=>'\\local_kbacompany\\event\\management_cycle_changed',
                        'tbl'=>'local_kbacompany_mcycle','id'=>(int)$row->id], 'timecreated DESC,id DESC', 0, 50);
                $out['available'] = true;
                foreach ($events as $event) {
                    $data = $event->get_data();
                    $out['rows'][] = ['time'=>(int)$data['timecreated'],'actor'=>self::user_name((int)$data['userid']),
                        'operation'=>(string)($data['other']['operation'] ?? ''),'fields'=>(array)($data['other']['fields'] ?? [])];
                }
                break; // Do not duplicate events across log stores.
            } catch (\Exception $e) { continue; }
        }
        return $out;
    }

    public static function context(string $companyuuid, int $actor, string $cycleuuid = ''): array {
        global $DB;
        self::require_manage($companyuuid, $actor);
        $rows = [];
        foreach ($DB->get_records('local_kbacompany_mcycle', ['companyuuid'=>$companyuuid], 'periodstart DESC, id DESC') as $row) {
            $rows[] = self::projection($companyuuid, $row);
        }
        $selected = null;
        if ($cycleuuid !== '') {
            foreach ($rows as $row) {
                if ($row['uuid'] === $cycleuuid) { $selected = $row; break; }
            }
            if (!$selected) { throw new \moodle_exception('mcycle_not_found', 'local_kbacompany'); }
        }
        $members = [];
        $sql = "SELECT m.userid, u.firstname, u.lastname, u.firstnamephonetic, u.lastnamephonetic, u.middlename, u.alternatename
                  FROM {local_kbacompany_member} m
                  JOIN {user} u ON u.id = m.userid
                 WHERE m.companyuuid = :c AND m.active = 1 AND u.deleted = 0
              ORDER BY u.lastname, u.firstname";
        foreach ($DB->get_records_sql($sql, ['c'=>$companyuuid]) as $user) {
            $members[(int)$user->userid] = fullname($user);
        }
        $registry = indicator_registry_service::context($companyuuid, $actor);
        return [
            'rows'=>$rows, 'selected'=>$selected, 'templates'=>self::templates(), 'members'=>$members,
            'indicators'=>(array)($registry['metrics'] ?? []), 'contract'=>self::contract(),
        ];
    }

    public static function curator_context(string $companyuuid, int $actor): array {
        global $DB;
        self::require_manage($companyuuid, $actor);
        $rows = []; $summary = ['active'=>0,'overdue_checkpoints'=>0,'draft'=>0,'ready_to_close'=>0];
        $records = $DB->get_records_sql("SELECT c.* FROM {local_kbacompany_mcycle} c
            WHERE c.companyuuid = :company AND c.curatorid = :actor AND EXISTS (
                SELECT m.id FROM {local_kbacompany_member} m WHERE m.companyuuid = c.companyuuid
                AND m.userid = :member AND m.active = 1) ORDER BY c.periodstart DESC,c.id DESC",
            ['company'=>$companyuuid,'actor'=>$actor,'member'=>$actor]);
        foreach ($records as $record) {
            $row = self::projection($companyuuid, $record); $rows[] = $row;
            if ($row['status'] === 'active') {
                $summary['active']++;
                $summary['overdue_checkpoints'] += (int)$row['checkpoint_overdue'];
                if ($row['checkpoint_total'] > 0 && $row['checkpoint_done'] === $row['checkpoint_total']) { $summary['ready_to_close']++; }
            }
            if ($row['status'] === 'draft') { $summary['draft']++; }
        }
        return ['rows'=>$rows,'summary'=>$summary,'contract'=>self::contract()];
    }
}
