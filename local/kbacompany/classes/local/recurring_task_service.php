<?php
namespace local_kbacompany\local;

defined('MOODLE_INTERNAL') || die();

/** Company-scoped recurring task definitions that generate canonical Task occurrences. */
class recurring_task_service {
    public const CONTRACT_VERSION = '0.4.13';
    public const STATUSES = ['draft', 'active', 'paused', 'archived'];
    public const CADENCES = ['daily', 'weekly', 'biweekly', 'twiceweekly', 'monthly', 'twicemonthly',
        'quarterly', 'semiannual', 'annual', 'review'];

    public static function contract(): array {
        return [
            'contract' => 'bms_recurring_tasks', 'version' => self::CONTRACT_VERSION,
            'objects' => ['RecurringTaskDefinition', 'TaskOccurrence', 'RecurringTaskTemplate'],
            'states' => self::STATUSES, 'cadences' => self::CADENCES,
            'rules' => [
                'recurring_definition_is_not_execution_task',
                'occurrence_is_canonical_task',
                'generated_occurrence_pins_definition_revision',
                'one_occurrence_per_company_series_period_independent_of_assignee',
                'series_position_binding_requires_manager_and_primary_assignment',
                'coverage_changes_effective_executor_not_canonical_task_assignee',
                'library_install_creates_draft_not_active_series',
                'expected_product_required_before_activation',
                'ambiguous_library_frequency_requires_human_review',
                'google_drive_is_provenance_not_runtime_dependency',
                'weekday_mask_preserves_exact_source_schedule',
                'month_week_preserves_first_week_of_month_schedule',
                'series_revision_change_actor_is_recorded_separately_from_original_creator',
                'legacy_revision_actor_is_not_guessed',
            ],
        ];
    }

    private static function fail(string $key): void {
        throw new \moodle_exception('recurringtask_' . $key, 'local_kbacompany');
    }

    private static function uuid(): string {
        $bytes = random_bytes(16);
        $bytes[6] = chr((ord($bytes[6]) & 0x0f) | 0x40);
        $bytes[8] = chr((ord($bytes[8]) & 0x3f) | 0x80);
        $hex = bin2hex($bytes);
        return substr($hex, 0, 8) . '-' . substr($hex, 8, 4) . '-' . substr($hex, 12, 4) .
            '-' . substr($hex, 16, 4) . '-' . substr($hex, 20);
    }

    private static function text(array $data, string $key, int $max, bool $required = false): string {
        $value = trim((string)($data[$key] ?? ''));
        if (($required && $value === '') || \core_text::strlen($value) > $max) { self::fail('invalid'); }
        return $value;
    }

    private static function date(array $data, string $key): string {
        $value = self::text($data, $key, 10, false);
        if ($value === '') { return ''; }
        $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $value, new \DateTimeZone('UTC'));
        if (!$date || $date->format('Y-m-d') !== $value) { self::fail('invalid'); }
        return $value;
    }

    private static function employees(string $companyuuid, int $actor): array {
        $organization = organization_service::organization_context($companyuuid, $actor);
        $employees = [];
        foreach ($organization['employees'] as $employee) {
            $employees[(int)$employee['user_id']] = (string)$employee['full_name'];
        }
        return $employees;
    }

    private static function latest(string $companyuuid, string $uuid): ?\stdClass {
        global $DB;
        $rows = $DB->get_records('local_kbacompany_taskrec', ['companyuuid' => $companyuuid, 'uuid' => $uuid],
            'revision DESC', '*', 0, 1);
        return $rows ? reset($rows) : null;
    }

    public static function exact(string $companyuuid, string $uuid, int $revision): ?\stdClass {
        global $DB;
        return $DB->get_record('local_kbacompany_taskrec', [
            'companyuuid' => $companyuuid, 'uuid' => $uuid, 'revision' => $revision,
        ]) ?: null;
    }

    /** A stable list of audited fields; provenance is intentionally not exposed as a diff. */
    private const HISTORY_FIELDS = [
        'title', 'product', 'description', 'assigneeid', 'reviewerid', 'cadence', 'weekdaymask',
        'monthweek', 'startdate', 'enddate', 'dueoffsetdays', 'planminutes', 'priority', 'stream',
        'dutypositionuuid', 'status',
    ];

    /** Pure revision comparison (also used by unit tests). */
    public static function compare_revisions(?\stdClass $before, \stdClass $after): array {
        if ($before === null) { return []; }
        $result = [];
        foreach (self::HISTORY_FIELDS as $field) {
            $old = (string)($before->$field ?? '');
            $new = (string)($after->$field ?? '');
            if ($old === $new) { continue; }
            // Never leak imported SHA256 provenance via description diffs.
            if ($field === 'description') {
                $result[] = ['field' => $field, 'before' => '', 'after' => '', 'redacted' => true];
            } else {
                $result[] = ['field' => $field, 'before' => $old, 'after' => $new, 'redacted' => false];
            }
        }
        return $result;
    }

    /**
     * Read-only history of immutable definition revisions. No extra history table is created.
     * Older revisions with an unknown editor show an explicit unknown instead of creatorid.
     * Actor names are resolved only after company-scoped series authorization succeeds.
     */
    public static function revision_history(string $companyuuid, int $actor, string $seriesuuid,
            int $page = 0, int $pagesize = 10): array {
        global $DB;
        $definition = self::context($companyuuid, $actor, $seriesuuid)['selected'];
        if (!$definition) { self::fail('permission'); }
        $page = max(0, min(100, $page));
        $pagesize = max(1, min(25, $pagesize));
        $rows = array_values($DB->get_records_sql(
            "SELECT * FROM {local_kbacompany_taskrec} WHERE companyuuid = :company AND uuid = :series
             ORDER BY revision DESC",
            ['company' => $companyuuid, 'series' => $seriesuuid], $page * $pagesize, $pagesize + 1));
        $hasnext = count($rows) > $pagesize;
        $rows = array_slice($rows, 0, $pagesize);
        $actors = [];
        foreach ($rows as $row) {
            $id = (int)($row->changedby ?? 0);
            if ($id > 0) { $actors[$id] = true; }
        }
        $names = [];
        if ($actors) {
            list($where, $args) = $DB->get_in_or_equal(array_keys($actors), SQL_PARAMS_NAMED, 'historyactor');
            foreach ($DB->get_records_select('user', "id $where", $args, '', 'id,firstname,lastname,firstnamephonetic,lastnamephonetic,middlename,alternatename,deleted') as $user) {
                if (empty($user->deleted)) { $names[(int)$user->id] = fullname($user); }
            }
        }
        $items = [];
        foreach ($rows as $row) {
            $prior = (int)$row->revision > 1 ? self::exact($companyuuid, $seriesuuid, (int)$row->revision - 1) : null;
            $editor = (int)($row->changedby ?? 0);
            $items[] = [
                'revision' => (int)$row->revision,
                'changed_at' => (int)$row->timecreated,
                'changed_by' => $editor,
                'changed_by_name' => $names[$editor] ?? '',
                'is_initial' => (int)$row->revision === 1,
                'changes' => self::compare_revisions($prior, $row),
            ];
        }
        return ['items' => $items, 'page' => $page, 'has_next' => $hasnext];
    }

    private static function can_edit(\stdClass $row, int $actor, bool $canmanage): bool {
        return $canmanage || (int)$row->creatorid === $actor;
    }

    private static function validate_definition(string $companyuuid, int $actor, array $data, bool $activating = false, string $existingpositionuuid = ''): array {
        $employees = self::employees($companyuuid, $actor);
        $canmanage = task_service::can_manage($companyuuid, $actor);
        $title = self::text($data, 'title', 255, true);
        $description = self::text($data, 'description', 12000, false);
        $product = self::text($data, 'product', 12000, false);
        $stream = self::text($data, 'stream', 16, true);
        $priority = self::text($data, 'priority', 10, true);
        $cadence = self::text($data, 'cadence', 20, true);
        if (!in_array($stream, task_service::STREAMS, true) || !in_array($priority, task_service::PRIORITIES, true) ||
                !in_array($cadence, self::CADENCES, true)) { self::fail('invalid'); }
        $assigneeid = (int)($data['assigneeid'] ?? 0);
        $dutypositionuuid = trim((string)($data['dutypositionuuid'] ?? ''));
        if ($dutypositionuuid !== '') {
            // Managers classify only positions currently held by the original assignee.
            // Non-managers may edit their own already-classified series without changing that classification.
            if (!$canmanage && $dutypositionuuid !== $existingpositionuuid) { self::fail('permission'); }
            if ($canmanage && !array_key_exists($dutypositionuuid,
                    task_service::duty_position_options($companyuuid, $actor, $assigneeid))) {
                self::fail('permission');
            }
        }
        $reviewerid = (int)($data['reviewerid'] ?? 0);
        if ($assigneeid > 0 && !isset($employees[$assigneeid])) { self::fail('employee'); }
        if (!$canmanage && $assigneeid > 0 && $assigneeid !== $actor) { self::fail('permission'); }
        if ($reviewerid > 0 && !isset($employees[$reviewerid])) { self::fail('employee'); }
        if ($reviewerid > 0 && $reviewerid === $assigneeid) { self::fail('reviewer'); }
        $startdate = self::date($data, 'startdate');
        $enddate = self::date($data, 'enddate');
        if ($startdate !== '' && $enddate !== '' && $enddate < $startdate) { self::fail('date'); }
        $dueoffsetdays = max(0, min(365, (int)($data['dueoffsetdays'] ?? 0)));
        $planminutes = max(0, min(525600, (int)($data['planminutes'] ?? 0)));
        $weekdaymask = max(0, min(127, (int)($data['weekdaymask'] ?? 0)));
        $monthweek = max(0, min(5, (int)($data['monthweek'] ?? 0)));
        if ($monthweek > 0 && ($cadence !== 'monthly' || $weekdaymask <= 0)) { self::fail('invalid'); }
        if ($activating) {
            if ($product === '' || $assigneeid <= 0 || $startdate === '' || $cadence === 'review') {
                self::fail('activateerror');
            }
        }
        return compact('title', 'description', 'product', 'stream', 'priority', 'cadence', 'assigneeid',
            'reviewerid', 'startdate', 'enddate', 'dueoffsetdays', 'planminutes', 'weekdaymask',
            'monthweek', 'dutypositionuuid');
    }

    private static function insert_revision(string $companyuuid, string $uuid, int $revision, int $actor,
            string $status, string $templatekey, string $category, array $values, int $changedby = 0): void {
        global $DB;
        $DB->insert_record('local_kbacompany_taskrec', (object)array_merge($values, [
            'companyuuid' => $companyuuid, 'uuid' => $uuid, 'revision' => $revision, 'status' => $status,
            'templatekey' => $templatekey, 'category' => $category, 'creatorid' => $actor,
            'changedby' => $changedby > 0 ? $changedby : $actor, 'timecreated' => time(),
        ]));
    }

    public static function create(string $companyuuid, int $actor, array $data): string {
        company_context_service::context($companyuuid, $actor);
        $values = self::validate_definition($companyuuid, $actor, $data, false);
        $uuid = self::uuid();
        self::insert_revision($companyuuid, $uuid, 1, $actor, 'draft', '', '', $values);
        return $uuid;
    }

    private static function template_startdate(array $template): string {
        $today = new \DateTimeImmutable(gmdate('Y-m-d'), new \DateTimeZone('UTC'));
        $mask = (int)($template['weekdaymask'] ?? 0);
        $monthweek = (int)($template['monthweek'] ?? 0);
        if ((string)($template['cadence'] ?? '') === 'review' || $mask <= 0) { return $today->format('Y-m-d'); }
        for ($offset = 0; $offset <= 62; $offset++) {
            $candidate = $today->modify('+' . $offset . ' days');
            $weekday = (int)$candidate->format('N');
            if (($mask & (1 << ($weekday - 1))) === 0) { continue; }
            if ($monthweek > 0) {
                $day = (int)$candidate->format('j');
                $from = (($monthweek - 1) * 7) + 1;
                $to = min($monthweek * 7, (int)$candidate->format('t'));
                if ($day < $from || $day > $to) { continue; }
            }
            return $candidate->format('Y-m-d');
        }
        return $today->format('Y-m-d');
    }

    public static function install_library_template(string $companyuuid, int $actor, string $templatekey): string {
        company_context_service::context($companyuuid, $actor);
        $template = recurring_task_library_service::template($templatekey, $companyuuid);
        if (!$template) { self::fail('template'); }
        $employees = self::employees($companyuuid, $actor);
        $assigneeid = isset($employees[$actor]) ? $actor : 0;
        $source = (string)($template['source_label'] ?? $template['source_file_id']);
        $sourcehash = (string)($template['source_hash'] ?? '');
        $sourceid = $sourcehash !== '' ? $sourcehash : (string)($template['source_file_id'] ?? '');
        $sourcemeta = [];
        if ((int)($template['source_score'] ?? 0) > 0) { $sourcemeta[] = 'score=' . (int)$template['source_score']; }
        if ((string)($template['source_time'] ?? '') !== '') { $sourcemeta[] = 'time=' . (string)$template['source_time']; }
        $provenance = get_string('recurringtask_library_provenance_description_v2', 'local_kbacompany',
            (object)['cadence' => (string)$template['source_cadence'], 'source' => $source,
                'hash' => $sourceid, 'meta' => implode(', ', $sourcemeta)]);
        $templatedescription = trim((string)($template['description'] ?? ''));
        $values = [
            'title' => (string)$template['title'],
            'description' => $templatedescription !== '' ? $templatedescription . "\n\n" . $provenance : $provenance,
            'product' => trim((string)($template['product'] ?? '')),
            'stream' => (string)($template['stream'] ?? 'operate'),
            'priority' => (string)($template['priority'] ?? 'normal'),
            'cadence' => (string)$template['cadence'], 'assigneeid' => $assigneeid, 'reviewerid' => 0,
            'startdate' => self::template_startdate($template), 'enddate' => '', 'dueoffsetdays' => 0,
            'planminutes' => max(0, (int)($template['planminutes'] ?? 0)),
            'weekdaymask' => (int)($template['weekdaymask'] ?? 0), 'monthweek' => (int)($template['monthweek'] ?? 0),
            'dutypositionuuid' => '',
        ];
        $uuid = self::uuid();
        self::insert_revision($companyuuid, $uuid, 1, $actor, 'draft', $templatekey,
            (string)$template['category'], $values);
        return $uuid;
    }

    public static function save(string $companyuuid, int $actor, string $uuid, int $expectedrevision, array $data): void {
        global $DB;
        company_context_service::context($companyuuid, $actor);
        $canmanage = task_service::can_manage($companyuuid, $actor);
        $lock = \core\lock\lock_config::get_lock_factory('local_kbacompany')->get_lock('taskrec:' . $companyuuid . ':' . $uuid, 10);
        if (!$lock) { self::fail('lock'); }
        try {
            $latest = self::latest($companyuuid, $uuid);
            if (!$latest) { self::fail('reference'); }
            if ((int)$latest->revision !== $expectedrevision) { self::fail('conflict'); }
            if (!self::can_edit($latest, $actor, $canmanage) || $latest->status === 'archived') { self::fail('permission'); }
            $previousposition = (string)($latest->dutypositionuuid ?? '');
            if (!$canmanage && ((string)($data['dutypositionuuid'] ?? '') !== $previousposition ||
                    ($previousposition !== '' && (int)($data['assigneeid'] ?? 0) !== (int)$latest->assigneeid))) {
                self::fail('permission');
            }
            $values = self::validate_definition($companyuuid, $actor, $data,
                $latest->status === 'active', $previousposition);
            self::insert_revision($companyuuid, $uuid, $expectedrevision + 1, (int)$latest->creatorid,
                (string)$latest->status, (string)$latest->templatekey, (string)$latest->category, $values, $actor);
        } finally { $lock->release(); }
    }

    public static function transition(string $companyuuid, int $actor, string $uuid, int $expectedrevision, string $action): void {
        company_context_service::context($companyuuid, $actor);
        $canmanage = task_service::can_manage($companyuuid, $actor);
        $lock = \core\lock\lock_config::get_lock_factory('local_kbacompany')->get_lock('taskrec:' . $companyuuid . ':' . $uuid, 10);
        if (!$lock) { self::fail('lock'); }
        try {
            $latest = self::latest($companyuuid, $uuid);
            if (!$latest) { self::fail('reference'); }
            if ((int)$latest->revision !== $expectedrevision) { self::fail('conflict'); }
            if (!self::can_edit($latest, $actor, $canmanage)) { self::fail('permission'); }
            $newstatus = '';
            if ($action === 'activate' && in_array($latest->status, ['draft', 'paused'], true)) { $newstatus = 'active'; }
            else if ($action === 'pause' && $latest->status === 'active') { $newstatus = 'paused'; }
            else if ($action === 'archive' && $latest->status !== 'archived') { $newstatus = 'archived'; }
            else { self::fail('state'); }
            $values = [
                'title' => (string)$latest->title, 'description' => (string)$latest->description,
                'product' => (string)$latest->product, 'stream' => (string)$latest->stream,
                'priority' => (string)$latest->priority, 'cadence' => (string)$latest->cadence,
                'assigneeid' => (int)$latest->assigneeid, 'reviewerid' => (int)$latest->reviewerid,
                'startdate' => (string)$latest->startdate, 'enddate' => (string)$latest->enddate,
                'dueoffsetdays' => (int)$latest->dueoffsetdays, 'planminutes' => (int)$latest->planminutes,
                'weekdaymask' => isset($latest->weekdaymask) ? (int)$latest->weekdaymask : 0,
                'monthweek' => isset($latest->monthweek) ? (int)$latest->monthweek : 0,
                'dutypositionuuid' => (string)($latest->dutypositionuuid ?? ''),
            ];
            if ($newstatus === 'active') {
                self::validate_definition($companyuuid, $actor, $values, true);
            }
            self::insert_revision($companyuuid, $uuid, $expectedrevision + 1, (int)$latest->creatorid,
                $newstatus, (string)$latest->templatekey, (string)$latest->category, $values, $actor);
        } finally { $lock->release(); }
    }

    private static function parse_date(string $value): \DateTimeImmutable {
        $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $value, new \DateTimeZone('UTC'));
        if (!$date) { self::fail('invalid'); }
        return $date;
    }

    private static function clamped_day(\DateTimeImmutable $date, int $day): int {
        return min($day, (int)$date->format('t'));
    }

    public static function is_due_on(\stdClass $row, string $datevalue): bool {
        if ($row->status !== 'active' || $row->startdate === '' || $row->cadence === 'review') { return false; }
        $date = self::parse_date($datevalue);
        $start = self::parse_date((string)$row->startdate);
        if ($date < $start) { return false; }
        if ($row->enddate !== '' && $date > self::parse_date((string)$row->enddate)) { return false; }
        $diffdays = (int)$start->diff($date)->format('%a');
        $cadence = (string)$row->cadence;
        $weekdaymask = isset($row->weekdaymask) ? (int)$row->weekdaymask : 0;
        $monthweek = isset($row->monthweek) ? (int)$row->monthweek : 0;
        $weekday = (int)$date->format('N');
        $weekdayselected = $weekdaymask > 0 && (($weekdaymask & (1 << ($weekday - 1))) !== 0);
        if ($cadence === 'daily') { return $weekdaymask > 0 ? $weekdayselected : true; }
        if ($cadence === 'weekly') { return $weekdaymask > 0 ? $weekdayselected : $diffdays % 7 === 0; }
        if ($cadence === 'biweekly') {
            if ($weekdaymask > 0) { return $weekdayselected && ((int)floor($diffdays / 7)) % 2 === 0; }
            return $diffdays % 14 === 0;
        }
        if ($cadence === 'twiceweekly') {
            if ($weekdaymask > 0) { return $weekdayselected; }
            $anchor = (int)$start->format('N');
            $second = (($anchor + 2) % 7) + 1;
            return $weekday === $anchor || $weekday === $second;
        }
        if ($cadence === 'twicemonthly') {
            $first = self::clamped_day($date, (int)$start->format('j'));
            $second = self::clamped_day($date, (int)$start->format('j') + 14);
            $day = (int)$date->format('j');
            return $day === $first || $day === $second;
        }
        $monthdiff = (((int)$date->format('Y') - (int)$start->format('Y')) * 12) +
            ((int)$date->format('n') - (int)$start->format('n'));
        if ($cadence === 'monthly' && $monthweek > 0 && $weekdaymask > 0) {
            if ($monthdiff < 0 || !$weekdayselected) { return false; }
            $day = (int)$date->format('j');
            $from = (($monthweek - 1) * 7) + 1;
            $to = min($monthweek * 7, (int)$date->format('t'));
            return $day >= $from && $day <= $to;
        }
        $targetday = self::clamped_day($date, (int)$start->format('j'));
        if ((int)$date->format('j') !== $targetday) { return false; }
        if ($cadence === 'monthly') { return $monthdiff >= 0; }
        if ($cadence === 'quarterly') { return $monthdiff >= 0 && $monthdiff % 3 === 0; }
        if ($cadence === 'semiannual') { return $monthdiff >= 0 && $monthdiff % 6 === 0; }
        if ($cadence === 'annual') { return $monthdiff >= 0 && $monthdiff % 12 === 0; }
        return false;
    }

    public static function generate_due_for_series(string $companyuuid, int $actor, string $uuid, string $datevalue): ?string {
        company_context_service::context($companyuuid, $actor);
        $latest = self::latest($companyuuid, $uuid);
        if (!$latest || !self::is_due_on($latest, $datevalue)) { return null; }
        return task_service::ensure_from_recurrence($companyuuid, $actor, $uuid, (int)$latest->revision, $datevalue);
    }

    /** Generate due occurrences for every latest active series. Idempotency is enforced by Task Center. */
    public static function generate_due_all(string $datevalue): array {
        global $DB;
        self::parse_date($datevalue);
        $latest = [];
        foreach ($DB->get_records('local_kbacompany_taskrec', [], 'companyuuid ASC, uuid ASC, revision DESC') as $row) {
            $key = $row->companyuuid . ':' . $row->uuid;
            if (isset($latest[$key])) { continue; }
            $latest[$key] = $row;
        }
        $result = ['checked' => 0, 'due' => 0, 'generated' => 0, 'errors' => 0];
        foreach ($latest as $row) {
            $result['checked']++;
            if (!self::is_due_on($row, $datevalue)) { continue; }
            $result['due']++;
            $actor = (int)$row->creatorid > 0 ? (int)$row->creatorid : (int)$row->assigneeid;
            if ($actor <= 0) { $result['errors']++; continue; }
            try {
                $before = $DB->record_exists('local_kbacompany_task', [
                    'companyuuid' => $row->companyuuid, 'sourcekind' => 'recurring', 'sourceuuid' => $row->uuid,
                    'periodkey' => $datevalue,
                ]);
                task_service::ensure_from_recurrence((string)$row->companyuuid, $actor, (string)$row->uuid,
                    (int)$row->revision, $datevalue);
                if (!$before) { $result['generated']++; }
            } catch (\Throwable $e) {
                $result['errors']++;
                debugging('Recurring task generation failed for ' . $row->uuid . ': ' . $e->getMessage(), DEBUG_DEVELOPER);
            }
        }
        return $result;
    }

    /**
     * Read-only journal of canonical occurrences; never generates or attaches tasks.
     * Visibility is checked independently for each task and for each linked Battle Plan.
     * Filtering precedes pagination so private tasks never count towards visible pages.
     */
    public static function occurrence_journal(string $companyuuid, int $actor, string $seriesuuid,
            int $page = 0, int $pagesize = 20, int $revisionfilter = 0): array {
        global $DB;
        // A foreign series UUID must not expose task existence or plan metadata.
        $definition = self::context($companyuuid, $actor, $seriesuuid)['selected'];
        if (!$definition) { self::fail('permission'); }
        $page = max(0, min(100, $page));
        $revisionfilter = max(0, min((int)$definition['revision'], $revisionfilter));
        $pagesize = max(1, min(50, $pagesize));
        $needed = ($page + 1) * $pagesize + 1;
        $visible = [];
        $offset = 0;
        $batchsize = 60;
        $filterclause = $revisionfilter > 0 ? ' AND t.sourcerevision = :source_revision' : '';
        $params = ['company' => $companyuuid, 'sourcekind' => 'recurring', 'sourceuuid' => $seriesuuid];
        if ($revisionfilter > 0) { $params['source_revision'] = $revisionfilter; }
        do {
            $rows = $DB->get_records_sql("SELECT t.* FROM {local_kbacompany_task} t
                WHERE t.companyuuid = :company AND t.sourcekind = :sourcekind AND t.sourceuuid = :sourceuuid" .
                $filterclause . " ORDER BY t.periodkey DESC, t.id DESC", $params,
                $offset, $batchsize);
            foreach ($rows as $row) {
                if (task_service::can_view_task($companyuuid, $actor, (string)$row->uuid)) {
                    $visible[] = $row;
                    if (count($visible) >= $needed) { break; }
                }
            }
            $count = count($rows);
            $offset += $count;
        } while ($count === $batchsize && count($visible) < $needed);

        $start = $page * $pagesize;
        $hasnext = count($visible) > $start + $pagesize;
        $pageitems = array_slice($visible, $start, $pagesize);
        $plansbytask = [];
        if ($pageitems) {
            $uuids = array_map(static function($row): string { return (string)$row->uuid; }, $pageitems);
            list($insql, $inparams) = $DB->get_in_or_equal($uuids, SQL_PARAMS_NAMED, 'rtocc');
            $sql = "SELECT bi.id, bi.taskuuid, bp.uuid AS planuuid, bp.periodstart, bp.periodend,
                           bp.status AS planstatus
                      FROM {local_kbacompany_bplanitem} bi
                      JOIN {local_kbacompany_bplan} bp ON bp.companyuuid = bi.companyuuid
                                                    AND bp.uuid = bi.planuuid
                     WHERE bi.companyuuid = :company AND bi.provider = :provider
                       AND bi.taskuuid $insql
                     ORDER BY bp.periodstart DESC, bi.id DESC";
            $references = $DB->get_records_sql($sql,
                ['company' => $companyuuid, 'provider' => 'bms'] + $inparams);
            $checkedplans = [];
            foreach ($references as $reference) {
                $planuuid = (string)$reference->planuuid;
                if (!array_key_exists($planuuid, $checkedplans)) {
                    // Reuse the authoritative Battle Plan read ACL (including department scope).
                    try {
                        battle_plan_service::context($companyuuid, $actor, $planuuid, 0, '', '',
                            ['load_plans' => false, 'load_sources' => false]);
                        $checkedplans[$planuuid] = true;
                    } catch (\moodle_exception $ex) {
                        $checkedplans[$planuuid] = false;
                    }
                }
                if (!$checkedplans[$planuuid]) { continue; }
                $plansbytask[(string)$reference->taskuuid][] = [
                    'uuid' => $planuuid, 'period_start' => (string)$reference->periodstart,
                    'period_end' => (string)$reference->periodend, 'status' => (string)$reference->planstatus,
                ];
            }
        }
        $employees = self::employees($companyuuid, $actor);
        $items = [];
        foreach ($pageitems as $row) {
            $items[] = [
                'uuid' => (string)$row->uuid, 'title' => (string)$row->title,
                'period_key' => (string)$row->periodkey, 'due_date' => (string)$row->duedate,
                'status' => (string)$row->status, 'revision' => (int)$row->sourcerevision,
                'assignee_name' => $employees[(int)$row->assigneeid] ?? '',
                'time_created' => (int)$row->timecreated,
                'plans' => $plansbytask[(string)$row->uuid] ?? [],
            ];
        }
        return ['items' => $items, 'page' => $page, 'has_next' => $hasnext];
    }

    /** Human-readable preview; does not generate tasks or modify data. */
    public static function upcoming_dates(array $definition, string $fromdate, int $limit = 5): array {
        $limit = max(1, min(10, $limit));
        $startdate = (string)($definition['start_date'] ?? '');
        if ($startdate === '' || (string)($definition['cadence'] ?? '') === 'review') { return []; }
        $from = self::parse_date($fromdate);
        $start = self::parse_date($startdate);
        if ($from < $start) { $from = $start; }
        $enddate = (string)($definition['end_date'] ?? '');
        $end = $enddate !== '' ? self::parse_date($enddate) : null;
        $row = (object)[
            'status' => 'active', 'startdate' => $startdate, 'enddate' => $enddate,
            'cadence' => (string)($definition['cadence'] ?? ''),
            'weekdaymask' => (int)($definition['weekday_mask'] ?? 0),
            'monthweek' => (int)($definition['month_week'] ?? 0),
        ];
        $result = [];
        // Cover five annual occurrences without an unbounded scan.
        for ($i = 0; $i < 2200 && count($result) < $limit; $i++) {
            $day = $from->modify('+' . $i . ' days');
            if ($end !== null && $day > $end) { break; }
            $datevalue = $day->format('Y-m-d');
            if (self::is_due_on($row, $datevalue)) { $result[] = $datevalue; }
        }
        return $result;
    }

    public static function context(string $companyuuid, int $actor, string $selected = ''): array {
        global $DB;
        $company = company_context_service::context($companyuuid, $actor);
        $canmanage = task_service::can_manage($companyuuid, $actor);
        $employees = self::employees($companyuuid, $actor);
        $latest = [];
        foreach ($DB->get_records('local_kbacompany_taskrec', ['companyuuid' => $companyuuid], 'uuid ASC, revision DESC') as $row) {
            if (!isset($latest[$row->uuid])) { $latest[$row->uuid] = $row; }
        }
        $series = [];
        foreach ($latest as $row) {
            if (!$canmanage && (int)$row->creatorid !== $actor && (int)$row->assigneeid !== $actor &&
                    (int)$row->reviewerid !== $actor) { continue; }
            $series[] = self::dto($row, $employees);
        }
        $selecteddto = null;
        if ($selected !== '' && isset($latest[$selected])) {
            $row = $latest[$selected];
            if (!$canmanage && (int)$row->creatorid !== $actor && (int)$row->assigneeid !== $actor &&
                    (int)$row->reviewerid !== $actor) { self::fail('permission'); }
            $selecteddto = self::dto($row, $employees);
        }
        return ['company' => $company, 'can_manage' => $canmanage, 'employees' => $employees,
            'series' => $series, 'selected' => $selecteddto];
    }

    private static function dto(\stdClass $row, array $employees): array {
        return [
            'uuid' => (string)$row->uuid, 'revision' => (int)$row->revision, 'status' => (string)$row->status,
            'template_key' => (string)$row->templatekey, 'category' => (string)$row->category,
            'title' => (string)$row->title, 'description' => (string)$row->description, 'product' => (string)$row->product,
            'stream' => (string)$row->stream, 'duty_position_uuid' => (string)($row->dutypositionuuid ?? ''),
            'assignee_id' => (int)$row->assigneeid,
            'assignee_name' => $employees[(int)$row->assigneeid] ?? '', 'creator_id' => (int)$row->creatorid,
            'reviewer_id' => (int)$row->reviewerid, 'reviewer_name' => $employees[(int)$row->reviewerid] ?? '',
            'priority' => (string)$row->priority, 'cadence' => (string)$row->cadence,
            'weekday_mask' => isset($row->weekdaymask) ? (int)$row->weekdaymask : 0,
            'month_week' => isset($row->monthweek) ? (int)$row->monthweek : 0,
            'start_date' => (string)$row->startdate, 'end_date' => (string)$row->enddate,
            'due_offset_days' => (int)$row->dueoffsetdays, 'plan_minutes' => (int)$row->planminutes,
            'time_created' => (int)$row->timecreated,
        ];
    }
}
