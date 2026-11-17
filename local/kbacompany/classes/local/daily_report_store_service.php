<?php
namespace local_kbacompany\local;

defined('MOODLE_INTERNAL') || die();

/**
 * ADR-0009 R1: saved daily reports.
 *
 * One report per company + employee + civil day. The draft lives on the server and is protected by an
 * optimistic revision. Submission is idempotent (request key under a per-report lock) and freezes the
 * draft into an immutable version together with a snapshot of the linked tasks and of the day data.
 * The actor is always the authenticated user: no employee id is ever taken from a request. Tasks,
 * Battle Plans and the Communication Center are read, never written. Company scope is applied in SQL.
 */
final class daily_report_store_service {
    /** Employee text fields of a version, in form order. Only "done" is required (owner decision В11). */
    public const FIELDS = ['planned', 'done', 'notdone', 'blockers', 'nextplan'];
    public const MAX_TEXT = 20000;
    public const MAX_LINKS = 50;
    public const PAGE_SIZE = 25;
    public const SNAPSHOT_ROWS = 25;

    /** Server-side switch; the read-only day summary stays available while it is off (rollback path). */
    public static function enabled(): bool {
        return (bool)get_config('local_kbacompany', 'dailyreports_enabled');
    }

    public static function require_enabled(): void {
        if (!self::enabled()) {
            throw new \moodle_exception('dailyreports_disabled', 'local_kbacompany');
        }
    }

    /** Company membership (revocation included) at the boundary, plus a valid, non-future civil day. */
    private static function scope(string $companyuuid, int $actor, string $day): string {
        $companyuuid = company_context_service::normalise_uuid($companyuuid);
        company_context_service::context($companyuuid, $actor);
        daily_report_service::bounds($day);
        if (strcmp($day, business_date_service::day()) > 0) {
            throw new \moodle_exception('dailyreports_futureday', 'local_kbacompany');
        }
        return $companyuuid;
    }

    private static function find(string $companyuuid, int $actor, string $day): ?\stdClass {
        global $DB;
        $row = $DB->get_record('local_kbacompany_dreport',
            ['companyuuid' => $companyuuid, 'userid' => $actor, 'reportday' => $day]);
        return $row ?: null;
    }

    private static function version(string $companyuuid, string $reportuuid, string $versionuuid): ?\stdClass {
        global $DB;
        if ($versionuuid === '') {
            return null;
        }
        $row = $DB->get_record('local_kbacompany_dreportver',
            ['companyuuid' => $companyuuid, 'reportuuid' => $reportuuid, 'uuid' => $versionuuid]);
        return $row ?: null;
    }

    private static function links(string $companyuuid, string $versionuuid): array {
        global $DB;
        return array_values($DB->get_records('local_kbacompany_dreportlink',
            ['companyuuid' => $companyuuid, 'versionuuid' => $versionuuid], 'id ASC'));
    }

    /** Own report of one day: report row, editable draft, submitted versions (newest first) and their links. */
    public static function view(string $companyuuid, int $actor, string $day): array {
        global $DB;
        $companyuuid = self::scope($companyuuid, $actor, $day);
        $report = self::find($companyuuid, $actor, $day);
        $out = ['report' => $report, 'draft' => null, 'draftlinks' => [], 'versions' => [], 'links' => [],
            'reviews' => [], 'late' => false];
        if (!$report) {
            return $out;
        }
        $out['draft'] = self::version($companyuuid, $report->uuid, (string)$report->draftversionuuid);
        if ($out['draft']) {
            $out['draftlinks'] = self::links($companyuuid, $out['draft']->uuid);
        }
        $versions = $DB->get_records_select('local_kbacompany_dreportver',
            'companyuuid = :c AND reportuuid = :r AND state <> :draft',
            ['c' => $companyuuid, 'r' => $report->uuid, 'draft' => 'draft'], 'versionno DESC');
        foreach ($versions as $version) {
            $out['versions'][] = $version;
            $out['links'][$version->uuid] = self::links($companyuuid, $version->uuid);
        }
        $out['reviews'] = self::reviews($companyuuid, $report->uuid);
        $current = $out['versions'][0] ?? null;
        $out['late'] = $current && (int)$current->submittedat > 0 &&
            strcmp(business_date_service::day((int)$current->submittedat), $day) > 0;
        return $out;
    }

    /** Append-only decisions per version (written by R2 review), oldest first. */
    private static function reviews(string $companyuuid, string $reportuuid): array {
        global $DB;
        $out = [];
        foreach ($DB->get_records('local_kbacompany_dreportrev',
                ['companyuuid' => $companyuuid, 'reportuuid' => $reportuuid], 'timecreated ASC, id ASC') as $row) {
            $out[$row->versionuuid][] = $row;
        }
        return $out;
    }

    /** Paged own history by date, newest first; only this company and this user. */
    public static function history(string $companyuuid, int $actor, int $page = 0): array {
        global $DB;
        $companyuuid = company_context_service::normalise_uuid($companyuuid);
        company_context_service::context($companyuuid, $actor);
        if ($page < 0 || $page > 100000) {
            throw new \moodle_exception('invalidparameter');
        }
        $where = ['companyuuid' => $companyuuid, 'userid' => $actor];
        $total = $DB->count_records('local_kbacompany_dreport', $where);
        $rows = $DB->get_records('local_kbacompany_dreport', $where, 'reportday DESC, id DESC', '*',
            $page * self::PAGE_SIZE, self::PAGE_SIZE);
        $items = [];
        foreach ($rows as $row) {
            $current = self::version($companyuuid, $row->uuid, (string)$row->currentversionuuid);
            $items[] = ['report' => $row, 'current' => $current,
                'late' => $current && (int)$current->submittedat > 0 &&
                    strcmp(business_date_service::day((int)$current->submittedat), $row->reportday) > 0];
        }
        return ['total' => $total, 'page' => $page, 'items' => $items];
    }

    /**
     * Tasks the author may link on the form: tasks of the day data (due that day, submitted that day) plus already
     * linked ones that are still readable. Keyed by task uuid, valued by the current title.
     */
    public static function link_candidates(string $companyuuid, int $actor, array $daydata, array $draftlinks): array {
        global $DB;
        $companyuuid = company_context_service::normalise_uuid($companyuuid);
        $out = [];
        foreach ($daydata['tasks'] ?? [] as $task) {
            $out[(string)$task->uuid] = (string)$task->title;
        }
        foreach ($daydata['results'] ?? [] as $event) {
            $out[(string)$event->taskuuid] = (string)$event->title;
        }
        foreach ($draftlinks as $link) {
            $taskuuid = (string)$link->taskuuid;
            if (isset($out[$taskuuid]) || task_service::readable_projection($companyuuid, $actor, $taskuuid) === null) {
                continue;
            }
            $out[$taskuuid] = (string)$DB->get_field('local_kbacompany_task', 'title',
                ['companyuuid' => $companyuuid, 'uuid' => $taskuuid]);
        }
        return $out;
    }

    /** Normalised employee text; the form returns it unchanged on any error. */
    public static function clean_values(array $values): array {
        $out = [];
        foreach (self::FIELDS as $field) {
            $text = trim(str_replace("\r\n", "\n", (string)($values[$field] ?? '')));
            if (\core_text::strlen($text) > self::MAX_TEXT) {
                throw new \moodle_exception('dailyreports_texttoolong', 'local_kbacompany');
            }
            $out[$field] = $text;
        }
        return $out;
    }

    /** Only tasks the author can open now may be linked; unknown, foreign or hidden tasks are refused alike. */
    private static function clean_links(string $companyuuid, int $actor, array $taskuuids): array {
        $out = [];
        foreach ($taskuuids as $taskuuid) {
            $taskuuid = strtolower(trim((string)$taskuuid));
            if ($taskuuid === '' || isset($out[$taskuuid])) {
                continue;
            }
            if (!preg_match('/^[0-9a-f-]{36}$/', $taskuuid) ||
                    task_service::readable_projection($companyuuid, $actor, $taskuuid) === null) {
                throw new \moodle_exception('dailyreports_taskunavailable', 'local_kbacompany');
            }
            $out[$taskuuid] = $taskuuid;
        }
        if (count($out) > self::MAX_LINKS) {
            throw new \moodle_exception('dailyreports_toomanylinks', 'local_kbacompany');
        }
        return array_values($out);
    }

    private static function lock(string $companyuuid, int $actor, string $day): \core\lock\lock {
        $lock = \core\lock\lock_config::get_lock_factory('local_kbacompany')
            ->get_lock('dreport:' . sha1($companyuuid . "\n" . $actor . "\n" . $day), 10);
        if (!$lock) {
            throw new \moodle_exception('dailyreports_busy', 'local_kbacompany');
        }
        return $lock;
    }

    private static function log(string $companyuuid, string $reportuuid, string $versionuuid, int $actor,
            string $action, array $details = []): void {
        global $DB;
        $DB->insert_record('local_kbacompany_dreportlog', (object)['companyuuid' => $companyuuid,
            'reportuuid' => $reportuuid, 'versionuuid' => $versionuuid, 'actorid' => $actor, 'action' => $action,
            'details' => json_encode($details, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'timecreated' => time()]);
    }

    private static function replace_links(string $companyuuid, string $versionuuid, array $taskuuids): void {
        global $DB;
        $DB->delete_records('local_kbacompany_dreportlink', ['companyuuid' => $companyuuid, 'versionuuid' => $versionuuid]);
        foreach ($taskuuids as $taskuuid) {
            $DB->insert_record('local_kbacompany_dreportlink', (object)['companyuuid' => $companyuuid,
                'versionuuid' => $versionuuid, 'taskuuid' => $taskuuid, 'origin' => 'manual', 'title' => '',
                'status' => '', 'duedate' => '', 'timecreated' => time()]);
        }
    }

    private static function new_version(string $companyuuid, string $reportuuid, int $actor, array $values,
            string $correctionnote = ''): \stdClass {
        global $DB;
        $next = 1 + (int)$DB->get_field_sql('SELECT MAX(versionno) FROM {local_kbacompany_dreportver}
            WHERE companyuuid = :c AND reportuuid = :r', ['c' => $companyuuid, 'r' => $reportuuid]);
        $now = time();
        $version = (object)($values + ['uuid' => \core\uuid::generate(), 'companyuuid' => $companyuuid,
            'reportuuid' => $reportuuid, 'versionno' => $next, 'state' => 'draft', 'correctionnote' => $correctionnote,
            'autosnapshot' => null, 'positionsnapshot' => null, 'reviewerid' => 0, 'reviewersource' => '',
            'requestkey' => '', 'contenthash' => '', 'revision' => 1, 'createdby' => $actor, 'submittedat' => 0,
            'timecreated' => $now, 'timemodified' => $now]);
        $version->id = $DB->insert_record('local_kbacompany_dreportver', $version);
        return $version;
    }

    private static function touch_report(\stdClass $report, array $changes): void {
        global $DB;
        foreach ($changes as $key => $value) {
            $report->$key = $value;
        }
        $report->revision = (int)$report->revision + 1;
        $report->timemodified = time();
        $DB->update_record('local_kbacompany_dreport', $report);
    }

    /**
     * Write the draft while the per-report lock is held and inside the caller's transaction.
     * $expectedrevision is the draft revision the form was rendered with (0 = no report yet). Stale = conflict.
     */
    private static function write_draft(string $companyuuid, int $actor, string $day, array $values, array $taskuuids,
            int $expectedrevision): array {
        global $DB;
        $report = self::find($companyuuid, $actor, $day);
        if (!$report) {
            if ($expectedrevision !== 0) {
                throw new \moodle_exception('dailyreports_conflict', 'local_kbacompany');
            }
            $now = time();
            $report = (object)['uuid' => \core\uuid::generate(), 'companyuuid' => $companyuuid, 'userid' => $actor,
                'reportday' => $day, 'timezone' => \core_date::get_user_timezone(), 'status' => 'draft',
                'currentversionuuid' => '', 'draftversionuuid' => '', 'revision' => 0,
                'timecreated' => $now, 'timemodified' => $now];
            $report->id = $DB->insert_record('local_kbacompany_dreport', $report);
            $draft = self::new_version($companyuuid, $report->uuid, $actor, $values);
            self::touch_report($report, ['draftversionuuid' => $draft->uuid]);
            self::log($companyuuid, $report->uuid, $draft->uuid, $actor, 'created', ['versionno' => 1]);
        } else {
            $draft = self::version($companyuuid, $report->uuid, (string)$report->draftversionuuid);
            if (!$draft || $draft->state !== 'draft') {
                throw new \moodle_exception('dailyreports_notdraft', 'local_kbacompany');
            }
            if ((int)$draft->revision !== $expectedrevision) {
                throw new \moodle_exception('dailyreports_conflict', 'local_kbacompany');
            }
            foreach ($values as $field => $text) {
                $draft->$field = $text;
            }
            $draft->revision = (int)$draft->revision + 1;
            $draft->timemodified = time();
            $DB->update_record('local_kbacompany_dreportver', $draft);
            self::touch_report($report, []);
        }
        self::replace_links($companyuuid, $draft->uuid, $taskuuids);
        self::log($companyuuid, $report->uuid, $draft->uuid, $actor, 'draft_saved', ['revision' => (int)$draft->revision]);
        return [$report, $draft];
    }

    /** Save the server draft. A stale revision raises dailyreports_conflict and changes nothing. */
    public static function save_draft(string $companyuuid, int $actor, string $day, array $values, array $taskuuids,
            int $expectedrevision): array {
        global $DB;
        self::require_enabled();
        $companyuuid = self::scope($companyuuid, $actor, $day);
        $values = self::clean_values($values);
        $taskuuids = self::clean_links($companyuuid, $actor, $taskuuids);
        $lock = self::lock($companyuuid, $actor, $day);
        try {
            $tx = $DB->start_delegated_transaction();
            try {
                [$report, $draft] = self::write_draft($companyuuid, $actor, $day, $values, $taskuuids, $expectedrevision);
                $tx->allow_commit();
            } catch (\Throwable $e) {
                $tx->rollback($e);
            }
        } finally {
            $lock->release();
        }
        return ['reportuuid' => $report->uuid, 'versionuuid' => $draft->uuid, 'revision' => (int)$draft->revision];
    }

    /**
     * Idempotent submission under one lock and one transaction. A version already created with this request key is
     * returned unchanged (double click, retry, parallel POST). With $values the form text is saved first against
     * $expectedrevision; then the draft is frozen with its task snapshot, day snapshot, reviewer and content hash.
     */
    public static function submit(string $companyuuid, int $actor, string $day, string $requestkey,
            int $expectedrevision, ?array $values = null, array $taskuuids = []): array {
        global $DB;
        self::require_enabled();
        $companyuuid = self::scope($companyuuid, $actor, $day);
        $requestkey = strtolower(trim($requestkey));
        if (!preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/', $requestkey)) {
            throw new \moodle_exception('invalidparameter');
        }
        if ($values !== null) {
            $values = self::clean_values($values);
            $taskuuids = self::clean_links($companyuuid, $actor, $taskuuids);
        }
        $lock = self::lock($companyuuid, $actor, $day);
        try {
            $report = self::find($companyuuid, $actor, $day);
            if ($report) {
                $done = $DB->get_record_select('local_kbacompany_dreportver',
                    'companyuuid = :c AND reportuuid = :r AND requestkey = :k AND state <> :draft',
                    ['c' => $companyuuid, 'r' => $report->uuid, 'k' => $requestkey, 'draft' => 'draft']);
                if ($done) {
                    return ['reportuuid' => $report->uuid, 'versionuuid' => $done->uuid, 'replayed' => true];
                }
            }
            $tx = $DB->start_delegated_transaction();
            try {
                if ($values !== null) {
                    [$report, $draft] = self::write_draft($companyuuid, $actor, $day, $values, $taskuuids,
                        $expectedrevision);
                } else {
                    $draft = $report ? self::version($companyuuid, $report->uuid, (string)$report->draftversionuuid) : null;
                    if (!$draft || $draft->state !== 'draft') {
                        throw new \moodle_exception('dailyreports_notdraft', 'local_kbacompany');
                    }
                    if ((int)$draft->revision !== $expectedrevision) {
                        throw new \moodle_exception('dailyreports_conflict', 'local_kbacompany');
                    }
                }
                if (trim((string)$draft->done) === '') {
                    throw new \moodle_exception('dailyreports_donerequired', 'local_kbacompany');
                }
                $links = self::freeze_links($companyuuid, $actor, $draft->uuid, $day);
                [$reviewerid, $source] = self::resolve_reviewer($companyuuid, $actor, $day);
                $now = time();
                $draft->autosnapshot = json_encode(self::day_snapshot($companyuuid, $actor, $day),
                    JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
                $draft->positionsnapshot = json_encode(self::position_snapshot($companyuuid, $actor),
                    JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
                $draft->reviewerid = $reviewerid;
                $draft->reviewersource = $source;
                $draft->requestkey = $requestkey;
                $draft->state = 'submitted';
                $draft->submittedat = $now;
                $draft->revision = (int)$draft->revision + 1;
                $draft->timemodified = $now;
                $draft->contenthash = self::content_hash($draft, $links);
                $DB->update_record('local_kbacompany_dreportver', $draft);
                self::touch_report($report, ['status' => 'submitted', 'currentversionuuid' => $draft->uuid,
                    'draftversionuuid' => '']);
                self::log($companyuuid, $report->uuid, $draft->uuid, $actor, 'submitted',
                    ['versionno' => (int)$draft->versionno, 'reviewersource' => $source, 'links' => count($links),
                     'late' => strcmp(business_date_service::day($now), $day) > 0]);
                $tx->allow_commit();
            } catch (\Throwable $e) {
                $tx->rollback($e);
            }
        } finally {
            $lock->release();
        }
        // R2: tell the reviewer once per version (a replayed request returned earlier and never gets here).
        if ((int)$draft->reviewerid > 0) {
            daily_report_review_service::notify($companyuuid, (int)$draft->reviewerid, 'dailyreport_submitted',
                [$draft->uuid], get_string('dailyreports_notify_submitted', 'local_kbacompany', $day),
                daily_report_ui_service::url($companyuuid, ['tab' => 'review', 'report' => $report->uuid]));
        }
        return ['reportuuid' => $report->uuid, 'versionuuid' => $draft->uuid, 'replayed' => false];
    }

    /**
     * Start the next version (ADR-0009 §5.4, §5.6). After a return the text of the returned version is copied for
     * rework; after an acceptance a correction needs an explanation (В4). The old version, its decision and rating stay
     * untouched in the history; the new version starts without a rating.
     */
    public static function start_revision(string $companyuuid, int $actor, string $day, string $note = ''): array {
        global $DB;
        self::require_enabled();
        $companyuuid = self::scope($companyuuid, $actor, $day);
        $note = trim(str_replace("\r\n", "\n", $note));
        if (\core_text::strlen($note) > self::MAX_TEXT) {
            throw new \moodle_exception('dailyreports_texttoolong', 'local_kbacompany');
        }
        $lock = self::lock($companyuuid, $actor, $day);
        try {
            $report = self::find($companyuuid, $actor, $day);
            if (!$report || (string)$report->draftversionuuid !== '' ||
                    !in_array((string)$report->status, ['returned', 'accepted'], true)) {
                throw new \moodle_exception('dailyreports_notrevisable', 'local_kbacompany');
            }
            if ($report->status === 'accepted' && $note === '') {
                throw new \moodle_exception('dailyreports_correctionrequired', 'local_kbacompany');
            }
            $current = self::version($companyuuid, $report->uuid, (string)$report->currentversionuuid);
            $tx = $DB->start_delegated_transaction();
            try {
                $values = [];
                foreach (self::FIELDS as $field) {
                    $values[$field] = (string)($current->$field ?? '');
                }
                $draft = self::new_version($companyuuid, $report->uuid, $actor, $values,
                    $report->status === 'accepted' ? $note : '');
                self::replace_links($companyuuid, $draft->uuid, array_map(static function($link): string {
                    return (string)$link->taskuuid;
                }, $current ? self::links($companyuuid, $current->uuid) : []));
                $kind = $report->status === 'accepted' ? 'correction_started' : 'rework_started';
                self::touch_report($report, ['status' => 'draft', 'draftversionuuid' => $draft->uuid]);
                self::log($companyuuid, $report->uuid, $draft->uuid, $actor, $kind, ['versionno' => (int)$draft->versionno]);
                $tx->allow_commit();
            } catch (\Throwable $e) {
                $tx->rollback($e);
            }
        } finally {
            $lock->release();
        }
        return ['versionuuid' => $draft->uuid, 'revision' => (int)$draft->revision];
    }

    /** Store the state of each linked task at submission; a task the author can no longer open is dropped. */
    private static function freeze_links(string $companyuuid, int $actor, string $versionuuid, string $day): array {
        global $DB;
        $context = daily_report_service::context($companyuuid, $actor, $day);
        $due = array_flip(array_map(static function($task): string { return (string)$task->uuid; }, $context['tasks']));
        $submitted = array_flip(array_map(static function($event): string { return (string)$event->taskuuid; },
            $context['results']));
        $kept = [];
        foreach (self::links($companyuuid, $versionuuid) as $link) {
            $task = task_service::readable_projection($companyuuid, $actor, (string)$link->taskuuid) === null ? null :
                $DB->get_record('local_kbacompany_task', ['companyuuid' => $companyuuid, 'uuid' => $link->taskuuid],
                    'uuid, title, status, duedate');
            if (!$task) {
                $DB->delete_records('local_kbacompany_dreportlink', ['id' => $link->id]);
                continue;
            }
            $link->title = \core_text::substr((string)$task->title, 0, 255);
            $link->status = (string)$task->status;
            $link->duedate = (string)$task->duedate;
            $link->origin = isset($due[$link->taskuuid]) ? 'due' : (isset($submitted[$link->taskuuid]) ? 'submit' : 'manual');
            $DB->update_record('local_kbacompany_dreportlink', $link);
            $kept[] = $link;
        }
        return $kept;
    }

    /** Read-only day data at submission (first page of each list), clearly separate from the employee text. */
    private static function day_snapshot(string $companyuuid, int $actor, string $day): array {
        $context = daily_report_service::context($companyuuid, $actor, $day);
        $tasks = [];
        foreach (array_slice($context['tasks'], 0, self::SNAPSHOT_ROWS) as $task) {
            $tasks[] = ['uuid' => (string)$task->uuid, 'title' => (string)$task->title, 'status' => (string)$task->status,
                'duedate' => (string)$task->duedate];
        }
        $results = [];
        foreach (array_slice($context['results'], 0, self::SNAPSHOT_ROWS) as $event) {
            $results[] = ['taskuuid' => (string)$event->taskuuid, 'title' => (string)$event->title,
                'newstatus' => (string)$event->newstatus, 'time' => (int)$event->timecreated];
        }
        return ['day' => $day, 'timezone' => (string)$context['timezone'], 'tasktotal' => (int)$context['tasktotal'],
            'resulttotal' => (int)$context['resulttotal'], 'tasks' => $tasks, 'results' => $results];
    }

    private static function position_snapshot(string $companyuuid, int $actor): array {
        foreach (self::org_employees($companyuuid, $actor) as $employee) {
            if ((int)$employee['user_id'] === $actor) {
                return ['position' => (string)($employee['position'] ?? ''),
                    'position_key' => (string)($employee['position_key'] ?? ''),
                    'units' => array_values(array_map(static function(array $a): string {
                        return (string)$a['org_unit_uuid'];
                    }, $employee['org_assignments'] ?? []))];
            }
        }
        return ['position' => '', 'position_key' => '', 'units' => []];
    }

    private static function org_employees(string $companyuuid, int $actor): array {
        try {
            return organization_service::organization_context($companyuuid, $actor)['employees'] ?? [];
        } catch (\moodle_exception $e) {
            return [];
        }
    }

    private static function content_hash(\stdClass $version, array $links): string {
        $data = [];
        foreach (self::FIELDS as $field) {
            $data[$field] = (string)$version->$field;
        }
        $data['links'] = array_map(static function($link): array {
            return [(string)$link->taskuuid, (string)$link->title, (string)$link->status, (string)$link->duedate];
        }, $links);
        return hash('sha256', json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }

    /** A reviewer must be another active, non-revoked member of the same company. */
    public static function eligible_reviewer(string $companyuuid, int $reviewerid, int $employee): bool {
        global $DB;
        if ($reviewerid <= 0 || $reviewerid === $employee ||
                !$DB->record_exists('user', ['id' => $reviewerid, 'deleted' => 0, 'suspended' => 0])) {
            return false;
        }
        try {
            company_context_service::context($companyuuid, $reviewerid);
            return true;
        } catch (\moodle_exception $e) {
            return false;
        }
    }

    /**
     * Owner decision В1: the Battle Plan reviewer of the reporting week, else the manager of the employee's
     * organisation unit (walking up the reporting line), else nobody ("without reviewer" until an admin assigns).
     * Self review is never returned (В1, ADR-0009 D6).
     */
    public static function resolve_reviewer(string $companyuuid, int $employee, string $day): array {
        global $DB;
        $plans = $DB->get_records_select('local_kbacompany_bplan',
            'companyuuid = :c AND userid = :u AND periodstart <= :d1 AND periodend >= :d2 AND reviewerid > 0',
            ['c' => $companyuuid, 'u' => $employee, 'd1' => $day, 'd2' => $day], 'timemodified DESC, id DESC',
            'id, reviewerid, status', 0, 10);
        foreach ($plans as $plan) {
            if (!in_array((string)$plan->status, ['cancelled', 'archived'], true) &&
                    self::eligible_reviewer($companyuuid, (int)$plan->reviewerid, $employee)) {
                return [(int)$plan->reviewerid, 'battleplan'];
            }
        }
        $manager = self::org_manager($companyuuid, $employee);
        return $manager > 0 ? [$manager, 'orgstructure'] : [0, 'none'];
    }

    private static function org_manager(string $companyuuid, int $employee): int {
        global $DB;
        $employees = self::org_employees($companyuuid, $employee);
        $managers = [];
        $units = [];
        foreach ($employees as $row) {
            foreach ($row['org_assignments'] ?? [] as $assignment) {
                $unit = (string)$assignment['org_unit_uuid'];
                if ((string)$assignment['role'] === 'manager') {
                    $managers[$unit][] = (int)$row['user_id'];
                }
                if ((int)$row['user_id'] === $employee) {
                    $units[] = $unit;
                }
            }
        }
        $visited = [];
        for ($level = 0; $units && $level < 10; $level++) {
            $parents = [];
            foreach ($units as $unit) {
                if ($unit === '' || isset($visited[$unit])) {
                    continue;
                }
                $visited[$unit] = true;
                $candidates = array_values(array_filter($managers[$unit] ?? [], static function(int $id) use ($employee) {
                    return $id !== $employee;
                }));
                sort($candidates);
                foreach ($candidates as $candidate) {
                    if (self::eligible_reviewer($companyuuid, $candidate, $employee)) {
                        return $candidate;
                    }
                }
                $parent = (string)$DB->get_field('local_kbacompany_orgreport', 'manageruuid',
                    ['companyuuid' => $companyuuid, 'nodeuuid' => $unit]);
                if ($parent === '') {
                    $parent = (string)$DB->get_field('local_kbacompany_orgnode', 'parentuuid',
                        ['companyuuid' => $companyuuid, 'uuid' => $unit]);
                }
                if ($parent !== '') {
                    $parents[] = $parent;
                }
            }
            $units = $parents;
        }
        return 0;
    }
}
