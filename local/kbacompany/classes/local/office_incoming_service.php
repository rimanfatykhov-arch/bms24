<?php
namespace local_kbacompany\local;

defined('MOODLE_INTERNAL') || die();

/**
 * DOC-W3 "Office → Incoming" register (owner decisions 27.11.2026, #196; BMS-TECH-DOC-001 v0.1 approved for W3).
 *
 * Canonical owner of the incoming register card (local_kbacompany_offrec), its metadata revisions (offrev) and the
 * company registration series (offseries). Controlled ISO documents stay in document_service and are never read or
 * changed here.
 *
 * Rules:
 * - every read and write is scoped to the company UUID in SQL; another company's card is "not found";
 * - rights are explicit company role grants: office_incoming.create (draft author) and office_incoming.register
 *   (registrar); owners and managers get nothing automatically;
 * - a registrar sees every card of the company, an author with the create right only own cards;
 * - the number Вх-ГГГГ/NNNN is issued only by the server: one series per company and year in the server time zone,
 *   under a series lock and a transaction, with a unique index as the last guard; a number is never reused;
 * - repeating a create or register command (same key, or an already registered card) creates nothing new;
 * - the original file is set once and is immutable afterwards; attachments are append-only;
 * - after registration attributes change only by a registrar, with a reason, as a new revision and a journal entry
 *   with the old and new values; a registered card is cancelled only with a reason and keeps its number;
 * - every change is journalled in local_kbacompany_aclog.
 */
final class office_incoming_service {
    public const CONTRACT_VERSION = '0.1.0';
    public const SETTING = 'office_incoming_enabled';
    public const OBJECT = 'office_incoming';
    public const SERIES = 'incoming';
    public const PREFIX = 'Вх';
    public const STATUSES = ['received', 'registered', 'cancelled'];
    public const CHANNELS = ['post', 'courier', 'email', 'hand', 'edo', 'other'];
    public const MAX_ATTACHMENTS = 20;
    public const PAGE_SIZE = 50;
    public const FIELDS = ['subject', 'correspondent', 'externalnumber', 'externaldate', 'receivedon', 'channel', 'summary'];

    public static function enabled(): bool {
        return office_workspace_service::enabled() && (bool)get_config('local_kbacompany', self::SETTING);
    }

    /** The route does not exist while either switch is off. */
    public static function require_enabled(): void {
        if (!self::enabled()) {
            throw new \moodle_exception('officein_disabled', 'local_kbacompany');
        }
    }

    public static function url(string $companyuuid, array $params = []): \moodle_url {
        return new \moodle_url('/local/kbacompany/office_incoming.php', ['uuid' => $companyuuid] + $params);
    }

    private static function fail(string $key): void {
        throw new \moodle_exception('officein_err_' . $key, 'local_kbacompany');
    }

    public static function can_register(string $companyuuid, int $actor): bool {
        return access_role_service::can($companyuuid, $actor, self::OBJECT, 'register');
    }

    public static function can_create(string $companyuuid, int $actor): bool {
        return access_role_service::can($companyuuid, $actor, self::OBJECT, 'create')
            || self::can_register($companyuuid, $actor);
    }

    /** Company membership first, then the explicit rights. */
    public static function rights(string $companyuuid, int $actor): array {
        company_context_service::context($companyuuid, $actor);
        return ['create' => self::can_create($companyuuid, $actor), 'register' => self::can_register($companyuuid, $actor)];
    }

    private static function begin(string $companyuuid, int $actor): array {
        self::require_enabled();
        $companyuuid = company_context_service::normalise_uuid($companyuuid);
        company_context_service::context($companyuuid, $actor);
        return [$companyuuid, self::rights($companyuuid, $actor)];
    }

    // ---------------------------------------------------------------- validation

    private static function today(): string {
        return (new \DateTimeImmutable('now', \core_date::get_server_timezone_object()))->format('Y-m-d');
    }

    private static function date_or_null($value, bool $notfuture): ?string {
        $value = trim((string)$value);
        if ($value === '') { return null; }
        $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $value);
        if (!$date || $date->format('Y-m-d') !== $value || $value < '1900-01-01') { self::fail('date'); }
        if ($notfuture && $value > self::today()) { self::fail('futuredate'); }
        return $value;
    }

    /** Normalised metadata. Required: subject and correspondent. Dates are ISO Y-m-d. */
    public static function clean(array $data): array {
        $text = static function($value, int $max) {
            $value = trim(preg_replace('/\s+/u', ' ', (string)$value));
            return \core_text::strlen($value) > $max ? null : $value;
        };
        $out = [
            'subject' => $text($data['subject'] ?? '', 255),
            'correspondent' => $text($data['correspondent'] ?? '', 255),
            'externalnumber' => $text($data['externalnumber'] ?? '', 100),
            'externaldate' => self::date_or_null($data['externaldate'] ?? '', false),
            'receivedon' => self::date_or_null($data['receivedon'] ?? '', true),
            'channel' => (string)($data['channel'] ?? 'post'),
            'summary' => trim((string)($data['summary'] ?? '')),
        ];
        if ($out['subject'] === null || $out['subject'] === '') { self::fail('subject'); }
        if ($out['correspondent'] === null || $out['correspondent'] === '') { self::fail('correspondent'); }
        if ($out['externalnumber'] === null) { self::fail('externalnumber'); }
        if (!in_array($out['channel'], self::CHANNELS, true)) { self::fail('channel'); }
        if (\core_text::strlen($out['summary']) > 4000) { self::fail('summary'); }
        $out['externalnumber'] = $out['externalnumber'] === '' ? null : $out['externalnumber'];
        $out['summary'] = $out['summary'] === '' ? null : $out['summary'];
        return $out;
    }

    private static function key_or_fail(string $key): string {
        $key = strtolower(trim($key));
        if (!preg_match('/^[a-f0-9]{8}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{12}$/D', $key)) { self::fail('key'); }
        return $key;
    }

    private static function content_hash(array $clean): string {
        $values = [];
        foreach (self::FIELDS as $field) { $values[$field] = $clean[$field] ?? null; }
        return hash('sha256', json_encode($values, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }

    // ---------------------------------------------------------------- journal

    private static function log(string $companyuuid, string $uuid, int $actor, string $action, $old, $new): void {
        global $DB;
        $json = static function($value) {
            return $value === null ? null : json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        };
        $DB->insert_record('local_kbacompany_aclog', (object)[
            'companyuuid' => $companyuuid, 'objectuuid' => $uuid, 'action' => 'offin_' . $action,
            'actorid' => $actor, 'oldvalue' => $json($old), 'newvalue' => $json($new), 'timecreated' => time(),
        ]);
    }

    // ---------------------------------------------------------------- reads

    /** SQL visibility for the actor; never fetches other companies' rows. */
    private static function visibility(string $companyuuid, int $actor, array $rights): array {
        if ($rights['register']) {
            return ['r.companyuuid = :c', ['c' => $companyuuid]];
        }
        if ($rights['create']) {
            return ['r.companyuuid = :c AND r.createdby = :u', ['c' => $companyuuid, 'u' => $actor]];
        }
        return [null, []];
    }

    /** One readable card, or "not found" for a foreign, hidden or nonexistent card. */
    private static function readable(string $companyuuid, int $actor, array $rights, string $uuid): \stdClass {
        global $DB;
        [$where, $params] = self::visibility($companyuuid, $actor, $rights);
        if ($where === null || !preg_match('/^[a-f0-9-]{36}$/Di', $uuid)) { self::fail('notfound'); }
        $row = $DB->get_record_sql("SELECT r.* FROM {local_kbacompany_offrec} r WHERE $where AND r.uuid = :uuid",
            $params + ['uuid' => strtolower($uuid)]);
        if (!$row) { self::fail('notfound'); }
        return $row;
    }

    private static function current_revision(\stdClass $row): \stdClass {
        global $DB;
        $rev = $DB->get_record('local_kbacompany_offrev', ['companyuuid' => $row->companyuuid,
            'recorduuid' => $row->uuid, 'revision' => (int)$row->currentrev]);
        if (!$rev) { self::fail('notfound'); }
        return $rev;
    }

    public static function browse(string $companyuuid, int $actor, array $filters = [], int $page = 0): array {
        global $DB;
        [$companyuuid, $rights] = self::begin($companyuuid, $actor);
        [$where, $params] = self::visibility($companyuuid, $actor, $rights);
        if ($where === null) {
            return ['rights' => $rights, 'total' => 0, 'rows' => [], 'page' => 0];
        }
        $where .= " AND r.direction = :direction";
        $params['direction'] = 'incoming';
        $status = (string)($filters['status'] ?? '');
        if (in_array($status, self::STATUSES, true)) {
            $where .= ' AND r.status = :status';
            $params['status'] = $status;
        }
        $query = trim((string)($filters['q'] ?? ''));
        if ($query !== '') {
            $like = '%' . $DB->sql_like_escape(\core_text::substr($query, 0, 100)) . '%';
            $where .= ' AND (' . $DB->sql_like('r.subject', ':q1', false) . ' OR ' .
                $DB->sql_like('r.regnumber', ':q2', false) . ' OR ' . $DB->sql_like('v.correspondent', ':q3', false) . ')';
            $params += ['q1' => $like, 'q2' => $like, 'q3' => $like];
        }
        $from = "FROM {local_kbacompany_offrec} r
                 JOIN {local_kbacompany_offrev} v ON v.companyuuid = r.companyuuid AND v.recorduuid = r.uuid
                      AND v.revision = r.currentrev
                WHERE $where";
        $total = (int)$DB->count_records_sql("SELECT COUNT(r.id) $from", $params);
        $page = max(0, $page);
        $rows = [];
        foreach ($DB->get_records_sql("SELECT r.id, r.uuid, r.status, r.regnumber, r.registeredat, r.receivedon,
                    r.subject, r.createdby, r.timecreated, r.timemodified, v.correspondent
                $from ORDER BY r.timemodified DESC, r.id DESC", $params, $page * self::PAGE_SIZE, self::PAGE_SIZE) as $row) {
            $rows[] = [
                'uuid' => (string)$row->uuid, 'status' => (string)$row->status,
                'regnumber' => $row->regnumber === null ? null : (string)$row->regnumber,
                'registeredat' => $row->registeredat === null ? null : (int)$row->registeredat,
                'receivedon' => $row->receivedon === null ? null : (string)$row->receivedon,
                'subject' => (string)$row->subject, 'correspondent' => (string)$row->correspondent,
                'own' => (int)$row->createdby === $actor, 'timemodified' => (int)$row->timemodified,
            ];
        }
        return ['rights' => $rights, 'total' => $total, 'rows' => $rows, 'page' => $page];
    }

    /** Full authorized card model: current metadata, files, revisions, journal and the actions allowed now. */
    public static function card(string $companyuuid, int $actor, string $uuid): array {
        global $DB;
        [$companyuuid, $rights] = self::begin($companyuuid, $actor);
        $row = self::readable($companyuuid, $actor, $rights, $uuid);
        $rev = self::current_revision($row);
        $original = office_upload::manifest($row->originalmanifest);
        $revisions = [];
        foreach ($DB->get_records('local_kbacompany_offrev', ['companyuuid' => $companyuuid, 'recorduuid' => $row->uuid],
                'revision ASC') as $item) {
            $revisions[] = ['revision' => (int)$item->revision, 'reason' => $item->reason === null ? null : (string)$item->reason,
                'createdby' => (int)$item->createdby, 'timecreated' => (int)$item->timecreated,
                'contenthash' => (string)$item->contenthash];
        }
        $journal = [];
        foreach ($DB->get_records('local_kbacompany_aclog', ['companyuuid' => $companyuuid, 'objectuuid' => $row->uuid],
                'id ASC') as $item) {
            if (strpos((string)$item->action, 'offin_') !== 0) { continue; }
            $journal[] = ['action' => substr((string)$item->action, 6), 'actorid' => (int)$item->actorid,
                'old' => $item->oldvalue === null ? null : json_decode((string)$item->oldvalue, true),
                'new' => $item->newvalue === null ? null : json_decode((string)$item->newvalue, true),
                'timecreated' => (int)$item->timecreated];
        }
        $status = (string)$row->status;
        $own = (int)$row->createdby === $actor;
        $draftwriter = $status === 'received' && ($rights['register'] || ($own && $rights['create']));
        $filewriter = $draftwriter || ($status === 'registered' && $rights['register']);
        $attachments = self::attachments($row);
        $ids = [(int)$row->createdby, (int)$row->registeredby, (int)$row->cancelledby];
        foreach ($revisions as $item) { $ids[] = $item['createdby']; }
        foreach ($journal as $item) { $ids[] = $item['actorid']; }
        foreach ($attachments as $item) { $ids[] = $item['userid']; }
        $ids = array_values(array_unique(array_filter($ids)));
        $people = [];
        if ($ids) {
            $fields = 'id,' . implode(',', \core_user\fields::get_name_fields());
            foreach ($DB->get_records_list('user', 'id', $ids, '', $fields) as $user) {
                $people[(int)$user->id] = fullname($user);
            }
        }
        return [
            'people' => $people,
            'uuid' => (string)$row->uuid, 'companyuuid' => $companyuuid, 'status' => $status,
            'lockversion' => (int)$row->lockversion, 'currentrev' => (int)$row->currentrev,
            'regnumber' => $row->regnumber === null ? null : (string)$row->regnumber,
            'registeredat' => $row->registeredat === null ? null : (int)$row->registeredat,
            'registeredby' => (int)$row->registeredby,
            'cancelreason' => $row->cancelreason === null ? null : (string)$row->cancelreason,
            'cancelledat' => $row->cancelledat === null ? null : (int)$row->cancelledat,
            'createdby' => (int)$row->createdby, 'timecreated' => (int)$row->timecreated,
            'data' => [
                'subject' => (string)$rev->subject, 'correspondent' => (string)$rev->correspondent,
                'externalnumber' => $rev->externalnumber === null ? '' : (string)$rev->externalnumber,
                'externaldate' => $rev->externaldate === null ? '' : (string)$rev->externaldate,
                'receivedon' => $rev->receivedon === null ? '' : (string)$rev->receivedon,
                'channel' => (string)$rev->channel, 'summary' => $rev->summary === null ? '' : (string)$rev->summary,
            ],
            'original' => $original,
            'attachments' => $attachments,
            'revisions' => $revisions,
            'journal' => $journal,
            'can' => [
                'edit' => $draftwriter,
                'correct' => $status === 'registered' && $rights['register'],
                'original' => $filewriter && $original === null,
                'attach' => $filewriter && count($attachments) < self::MAX_ATTACHMENTS,
                'register' => $status === 'received' && $rights['register'],
                'cancel' => $draftwriter || ($status === 'registered' && $rights['register']),
            ],
        ];
    }

    // ---------------------------------------------------------------- locking

    private static function with_lock(string $key, callable $callback) {
        $lock = \core\lock\lock_config::get_lock_factory('local_kbacompany')->get_lock($key, 10);
        if (!$lock) { self::fail('lock'); }
        try {
            return $callback();
        } finally {
            $lock->release();
        }
    }

    /** Re-read the card under its lock and check the expected lockversion (optimistic concurrency). */
    private static function locked_card(string $companyuuid, int $actor, array $rights, string $uuid, ?int $expected): \stdClass {
        $row = self::readable($companyuuid, $actor, $rights, $uuid);
        if ($expected !== null && (int)$row->lockversion !== $expected) { self::fail('conflict'); }
        return $row;
    }

    private static function bump(\stdClass $row, array $changes): void {
        global $DB;
        $changes['lockversion'] = (int)$row->lockversion + 1;
        $changes['timemodified'] = time();
        $set = [];
        $params = ['id' => (int)$row->id, 'c' => $row->companyuuid, 'old' => (int)$row->lockversion];
        foreach ($changes as $field => $value) {
            $set[] = "$field = :set_$field";
            $params['set_' . $field] = $value;
            $row->$field = $value;
        }
        $DB->execute('UPDATE {local_kbacompany_offrec} SET ' . implode(', ', $set) .
            ' WHERE id = :id AND companyuuid = :c AND lockversion = :old', $params);
        $check = $DB->get_field('local_kbacompany_offrec', 'lockversion', ['id' => (int)$row->id, 'companyuuid' => $row->companyuuid]);
        if ((int)$check !== (int)$changes['lockversion']) { self::fail('conflict'); }
    }

    // ---------------------------------------------------------------- writes

    /** Create a draft card. Repeating the same create key returns the same card. */
    public static function create(string $companyuuid, int $actor, string $createkey, array $data): string {
        global $DB;
        [$companyuuid, $rights] = self::begin($companyuuid, $actor);
        if (!$rights['create']) { self::fail('denied'); }
        $createkey = self::key_or_fail($createkey);
        $clean = self::clean($data);
        return self::with_lock('officein:create:' . $companyuuid . ':' . $actor . ':' . $createkey,
            static function() use ($DB, $companyuuid, $actor, $createkey, $clean): string {
                $existing = $DB->get_field('local_kbacompany_offrec', 'uuid',
                    ['companyuuid' => $companyuuid, 'createdby' => $actor, 'createkey' => $createkey]);
                if ($existing) { return (string)$existing; }
                $now = time();
                $uuid = \core\uuid::generate();
                $tx = $DB->start_delegated_transaction();
                $DB->insert_record('local_kbacompany_offrec', (object)[
                    'companyuuid' => $companyuuid, 'uuid' => $uuid, 'direction' => 'incoming', 'kind' => 'letter',
                    'subject' => $clean['subject'], 'status' => 'received', 'currentrev' => 1, 'lockversion' => 0,
                    'createkey' => $createkey, 'receivedon' => $clean['receivedon'], 'registeredby' => 0,
                    'cancelledby' => 0, 'createdby' => $actor, 'timecreated' => $now, 'timemodified' => $now,
                ]);
                self::insert_revision($companyuuid, $uuid, 1, $clean, null, $actor);
                self::log($companyuuid, $uuid, $actor, 'created', null, $clean);
                $tx->allow_commit();
                return $uuid;
            });
    }

    private static function insert_revision(string $companyuuid, string $uuid, int $revision, array $clean,
            ?string $reason, int $actor): void {
        global $DB;
        $DB->insert_record('local_kbacompany_offrev', (object)[
            'companyuuid' => $companyuuid, 'recorduuid' => $uuid, 'revision' => $revision,
            'subject' => $clean['subject'], 'correspondent' => $clean['correspondent'],
            'externalnumber' => $clean['externalnumber'], 'externaldate' => $clean['externaldate'],
            'receivedon' => $clean['receivedon'], 'channel' => $clean['channel'], 'summary' => $clean['summary'],
            'reason' => $reason, 'contenthash' => self::content_hash($clean), 'createdby' => $actor, 'timecreated' => time(),
        ]);
    }

    /**
     * Save attributes. A draft is edited by its author (create right) or a registrar; a registered card is corrected
     * only by a registrar with a reason, and the journal stores the old and new values. Either way a new revision
     * is appended; earlier revisions are never changed. A cancelled card is read-only.
     */
    public static function save(string $companyuuid, int $actor, string $uuid, int $lockversion, array $data,
            string $reason = ''): int {
        global $DB;
        [$companyuuid, $rights] = self::begin($companyuuid, $actor);
        $clean = self::clean($data);
        $reason = trim($reason);
        if (\core_text::strlen($reason) > 1000) { self::fail('reason'); }
        return self::with_lock('officein:rec:' . $companyuuid . ':' . strtolower($uuid),
            static function() use ($DB, $companyuuid, $actor, $rights, $uuid, $lockversion, $clean, $reason): int {
                $row = self::locked_card($companyuuid, $actor, $rights, $uuid, $lockversion);
                $status = (string)$row->status;
                if ($status === 'received') {
                    if (!$rights['register'] && !((int)$row->createdby === $actor && $rights['create'])) { self::fail('denied'); }
                } else if ($status === 'registered') {
                    if (!$rights['register']) { self::fail('denied'); }
                    if ($reason === '') { self::fail('reasonrequired'); }
                } else {
                    self::fail('state');
                }
                $current = self::current_revision($row);
                $old = [];
                foreach (self::FIELDS as $field) { $old[$field] = $current->$field === null ? null : (string)$current->$field; }
                if (self::content_hash($clean) === (string)$current->contenthash) { return (int)$row->currentrev; }
                $tx = $DB->start_delegated_transaction();
                $next = (int)$row->currentrev + 1;
                self::insert_revision($companyuuid, $row->uuid, $next, $clean, $reason === '' ? null : $reason, $actor);
                self::bump($row, ['currentrev' => $next, 'subject' => $clean['subject'], 'receivedon' => $clean['receivedon']]);
                $diffold = $diffnew = [];
                foreach (self::FIELDS as $field) {
                    if (($old[$field] ?? null) !== ($clean[$field] ?? null)) {
                        $diffold[$field] = $old[$field];
                        $diffnew[$field] = $clean[$field];
                    }
                }
                if ($status === 'registered') {
                    $diffnew['reason'] = $reason;
                    $diffnew['revision'] = $next;
                    self::log($companyuuid, $row->uuid, $actor, 'corrected', $diffold, $diffnew);
                } else {
                    $diffnew['revision'] = $next;
                    self::log($companyuuid, $row->uuid, $actor, 'updated', $diffold, $diffnew);
                }
                $tx->allow_commit();
                return $next;
            });
    }

    /**
     * Register a draft: server number Вх-ГГГГ/NNNN and server time. Idempotent: the same register key, or any
     * repeat on an already registered card, returns the existing number and changes nothing.
     */
    public static function register(string $companyuuid, int $actor, string $uuid, int $lockversion,
            string $registerkey): array {
        global $DB;
        [$companyuuid, $rights] = self::begin($companyuuid, $actor);
        if (!$rights['register']) { self::fail('denied'); }
        $registerkey = self::key_or_fail($registerkey);
        return self::with_lock('officein:rec:' . $companyuuid . ':' . strtolower($uuid),
            static function() use ($DB, $companyuuid, $actor, $rights, $uuid, $lockversion, $registerkey): array {
                $row = self::readable($companyuuid, $actor, $rights, $uuid);
                if ((string)$row->status === 'registered') {
                    return ['number' => (string)$row->regnumber, 'repeated' => true];
                }
                if ((string)$row->status !== 'received') { self::fail('state'); }
                if ((int)$row->lockversion !== $lockversion) { self::fail('conflict'); }
                $rev = self::current_revision($row);
                if ($rev->receivedon === null || (string)$rev->receivedon === '') { self::fail('receivedonrequired'); }
                $now = time();
                $period = (new \DateTimeImmutable('@' . $now))->setTimezone(\core_date::get_server_timezone_object())->format('Y');
                return self::with_lock('officein:series:' . $companyuuid . ':' . self::SERIES . ':' . $period,
                    static function() use ($DB, $companyuuid, $actor, $row, $registerkey, $period, $now): array {
                        $tx = $DB->start_delegated_transaction();
                        $series = $DB->get_record('local_kbacompany_offseries',
                            ['companyuuid' => $companyuuid, 'code' => self::SERIES, 'period' => $period]);
                        if (!$series) {
                            $series = (object)['companyuuid' => $companyuuid, 'uuid' => \core\uuid::generate(),
                                'code' => self::SERIES, 'period' => $period, 'prefix' => self::PREFIX, 'lastseq' => 0,
                                'lockversion' => 0, 'timecreated' => $now, 'timemodified' => $now];
                            $series->id = $DB->insert_record('local_kbacompany_offseries', $series);
                        }
                        $seq = (int)$series->lastseq + 1;
                        $DB->execute('UPDATE {local_kbacompany_offseries} SET lastseq = :seq, lockversion = lockversion + 1,
                                timemodified = :now WHERE id = :id AND companyuuid = :c AND lastseq = :old',
                            ['seq' => $seq, 'now' => $now, 'id' => (int)$series->id, 'c' => $companyuuid,
                             'old' => (int)$series->lastseq]);
                        if ((int)$DB->get_field('local_kbacompany_offseries', 'lastseq', ['id' => (int)$series->id]) !== $seq) {
                            self::fail('lock');
                        }
                        $number = sprintf('%s-%s/%04d', (string)$series->prefix, $period, $seq);
                        self::bump($row, ['status' => 'registered', 'seriesuuid' => (string)$series->uuid,
                            'regperiod' => $period, 'regseq' => $seq, 'regnumber' => $number, 'registeredat' => $now,
                            'registeredby' => $actor, 'registerkey' => $registerkey]);
                        self::log($companyuuid, $row->uuid, $actor, 'registered', ['status' => 'received'],
                            ['status' => 'registered', 'number' => $number, 'registeredat' => $now]);
                        $tx->allow_commit();
                        return ['number' => $number, 'repeated' => false];
                    });
            });
    }

    /** Cancel: a draft by its author or a registrar (reason optional); a registered card by a registrar with a reason. */
    public static function cancel(string $companyuuid, int $actor, string $uuid, int $lockversion, string $reason): void {
        global $DB;
        [$companyuuid, $rights] = self::begin($companyuuid, $actor);
        $reason = trim($reason);
        if (\core_text::strlen($reason) > 1000) { self::fail('reason'); }
        self::with_lock('officein:rec:' . $companyuuid . ':' . strtolower($uuid),
            static function() use ($DB, $companyuuid, $actor, $rights, $uuid, $lockversion, $reason): void {
                $row = self::locked_card($companyuuid, $actor, $rights, $uuid, $lockversion);
                $status = (string)$row->status;
                if ($status === 'received') {
                    if (!$rights['register'] && !((int)$row->createdby === $actor && $rights['create'])) { self::fail('denied'); }
                } else if ($status === 'registered') {
                    if (!$rights['register']) { self::fail('denied'); }
                    if ($reason === '') { self::fail('reasonrequired'); }
                } else {
                    self::fail('state');
                }
                $tx = $DB->start_delegated_transaction();
                self::bump($row, ['status' => 'cancelled', 'cancelreason' => $reason === '' ? null : $reason,
                    'cancelledat' => time(), 'cancelledby' => $actor]);
                self::log($companyuuid, $row->uuid, $actor, 'cancelled', ['status' => $status],
                    ['status' => 'cancelled', 'reason' => $reason, 'number' => $row->regnumber]);
                $tx->allow_commit();
            });
    }

    // ---------------------------------------------------------------- files

    private static function file_path(\stdClass $row, string $sha256): string {
        return '/' . $row->companyuuid . '/' . $sha256 . '/';
    }

    private static function attachments(\stdClass $row): array {
        $out = [];
        foreach (get_file_storage()->get_area_files(\context_system::instance()->id, 'local_kbacompany',
                'office_attachment', (int)$row->id, 'timecreated ASC, id ASC', false) as $file) {
            $parts = explode('/', trim($file->get_filepath(), '/'));
            if (count($parts) !== 2 || $parts[0] !== $row->companyuuid || !preg_match('/^[a-f0-9]{64}$/D', $parts[1])) {
                continue;
            }
            $out[] = ['filename' => $file->get_filename(), 'sha256' => $parts[1], 'bytes' => (int)$file->get_filesize(),
                'mimetype' => $file->get_mimetype(), 'uploadedat' => (int)$file->get_timecreated(),
                'userid' => (int)$file->get_userid()];
        }
        return $out;
    }

    private static function store(\stdClass $row, int $actor, string $area, array $upload): \stored_file {
        $fs = get_file_storage();
        $context = \context_system::instance();
        $path = self::file_path($row, $upload['sha256']);
        $file = $fs->get_file($context->id, 'local_kbacompany', $area, (int)$row->id, $path, $upload['filename']);
        if (!$file) {
            $file = $fs->create_file_from_pathname([
                'contextid' => $context->id, 'component' => 'local_kbacompany', 'filearea' => $area,
                'itemid' => (int)$row->id, 'filepath' => $path, 'filename' => $upload['filename'],
                'mimetype' => $upload['mimetype'], 'userid' => $actor, 'source' => $upload['filename'],
            ], $upload['tmp']);
        }
        if ($file->is_directory() || (int)$file->get_filesize() !== $upload['bytes'] ||
                !hash_equals($file->get_contenthash(), hash_file('sha1', $upload['tmp']))) { self::fail('filemissing'); }
        return $file;
    }

    /**
     * Add a validated file. $kind 'original' sets the original once (it is immutable afterwards); 'attachment'
     * appends. Allowed for a draft writer, or for a registrar on a registered card. Cancelled cards are read-only.
     */
    public static function add_file(string $companyuuid, int $actor, string $uuid, int $lockversion, string $kind,
            array $upload): void {
        global $DB;
        [$companyuuid, $rights] = self::begin($companyuuid, $actor);
        if (!in_array($kind, ['original', 'attachment'], true) || empty($upload['sha256'])) { self::fail('upload'); }
        self::with_lock('officein:rec:' . $companyuuid . ':' . strtolower($uuid),
            static function() use ($DB, $companyuuid, $actor, $rights, $uuid, $lockversion, $kind, $upload): void {
                $row = self::locked_card($companyuuid, $actor, $rights, $uuid, $lockversion);
                $status = (string)$row->status;
                $allowed = ($status === 'received' && ($rights['register'] || ((int)$row->createdby === $actor && $rights['create'])))
                    || ($status === 'registered' && $rights['register']);
                if (!$allowed) { self::fail($status === 'cancelled' ? 'state' : 'denied'); }
                if ($kind === 'original' && office_upload::manifest($row->originalmanifest) !== null) { self::fail('originalset'); }
                if ($kind === 'attachment' && count(self::attachments($row)) >= self::MAX_ATTACHMENTS) { self::fail('toomany'); }
                $tx = $DB->start_delegated_transaction();
                $file = self::store($row, $actor, $kind === 'original' ? 'office_original' : 'office_attachment', $upload);
                $manifest = ['version' => 1, 'filename' => $upload['filename'], 'sha256' => $upload['sha256'],
                    'bytes' => $upload['bytes'], 'mimetype' => $upload['mimetype'],
                    'uploadedat' => (int)$file->get_timecreated()];
                $changes = [];
                if ($kind === 'original') {
                    $changes['originalmanifest'] = json_encode($manifest, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
                }
                self::bump($row, $changes);
                self::log($companyuuid, $row->uuid, $actor, $kind, null,
                    ['filename' => $manifest['filename'], 'sha256' => $manifest['sha256'], 'bytes' => $manifest['bytes']]);
                $tx->allow_commit();
            });
    }

    /** Protected download: the actor must be able to read the card; the file must match its manifest or area. */
    public static function file_for_download(string $companyuuid, int $actor, string $area, int $id, string $hash,
            string $filename): \stored_file {
        global $DB;
        [$companyuuid, $rights] = self::begin($companyuuid, $actor);
        if (!in_array($area, ['office_original', 'office_attachment'], true)) { self::fail('notfound'); }
        $ref = $DB->get_record('local_kbacompany_offrec', ['companyuuid' => $companyuuid, 'id' => $id], 'id,uuid');
        if (!$ref) { self::fail('notfound'); }
        $row = self::readable($companyuuid, $actor, $rights, (string)$ref->uuid);
        if ($area === 'office_original') {
            $m = office_upload::manifest($row->originalmanifest);
            if (!$m || !hash_equals($m['sha256'], $hash) || $m['filename'] !== $filename) { self::fail('notfound'); }
        }
        $file = get_file_storage()->get_file(\context_system::instance()->id, 'local_kbacompany', $area, (int)$row->id,
            self::file_path($row, $hash), $filename);
        if (!$file || $file->is_directory()) { self::fail('notfound'); }
        return $file;
    }

    /** Download route of the register page; the page authorizes through file_for_download() on every request. */
    public static function file_url(string $companyuuid, string $area, int $id, string $hash, string $filename): \moodle_url {
        return self::url($companyuuid, ['download' => $area === 'office_original' ? 'original' : 'attachment',
            'item' => $id, 'sha' => $hash, 'name' => $filename]);
    }

    /** Internal id for file URLs of a readable card (never exposed for hidden cards). */
    public static function record_id(string $companyuuid, int $actor, string $uuid): int {
        [$companyuuid, $rights] = self::begin($companyuuid, $actor);
        return (int)self::readable($companyuuid, $actor, $rights, $uuid)->id;
    }

    public static function contract(): array {
        return [
            'component' => 'local_kbacompany', 'contract' => 'bms_office_incoming', 'version' => self::CONTRACT_VERSION,
            'company_scope' => 'required', 'feature_switch' => 'local_kbacompany/' . self::SETTING,
            'number_format' => self::PREFIX . '-YYYY/NNNN', 'series_reset' => 'yearly_server_timezone',
            'rules' => [
                'registrar_is_explicit_role_grant_without_manager_fallback',
                'registrar_sees_company_cards_author_sees_own',
                'number_issued_by_server_under_series_lock_and_unique_index',
                'repeat_create_or_register_creates_nothing',
                'original_set_once_then_immutable',
                'correction_after_registration_needs_reason_and_appends_revision',
                'cancelled_number_is_never_reused',
                'controlled_documents_are_not_registered_correspondence',
            ],
        ];
    }
}
