<?php
namespace local_kbacompany\local;

defined('MOODLE_INTERNAL') || die();

/**
 * R5a: reporting calendar of a company (owner decisions K1–K6, 24.11.2026).
 *
 * K1: a company administrator sets the working weekdays (default Mon–Fri) and calendar exceptions (non-working dates and
 * working weekend dates). K2: absence periods hold only the kind (vacation / sick / other) and the dates; they are written
 * by a company administrator or by the employee's nearest manager (ADR-0010 D2). K3: the deadline is a time of the next
 * working day (default 12:00). K5/K6: the calendar has its own company switch (default off) and counts only from the day it
 * was switched on, so earlier days stay neutral. Every change is optimistic-revision checked, company-scoped in SQL and
 * journaled in dreportlog. Requirement is computed on read, never stored. Statuses and reminders come in R5b/R5c.
 */
final class daily_report_calendar_service {
    public const ABSENCE_KINDS = ['vacation', 'sick', 'other'];
    public const OFFDAY_KINDS = ['holiday', 'workday'];
    public const DEFAULT_WORKDAYS = '1111100';
    public const DEFAULT_DEADLINE = 720;
    /** Longest single absence period, days (inclusive). */
    public const MAX_ABSENCE_DAYS = 366;
    public const PAGE_SIZE = 50;
    /** R5b due states, in display order (owner decisions K3, K5; docs/daily-reports/R5B_DUE.md). */
    public const DUE = ['neutral', 'notrequired', 'expected', 'missed', 'ontime', 'late'];

    private static function fail(string $key = 'notavailable'): void {
        throw new \moodle_exception('dailyreports_' . $key, 'local_kbacompany');
    }

    private static function member(string $companyuuid, int $actor): string {
        daily_report_store_service::require_enabled();
        $companyuuid = company_context_service::normalise_uuid($companyuuid);
        company_context_service::context($companyuuid, $actor);
        return $companyuuid;
    }

    private static function require_admin(string $companyuuid, int $actor): void {
        if (!daily_report_review_service::is_admin($companyuuid, $actor)) { self::fail(); }
    }

    /** Strict civil date Y-m-d (no normalisation of 2026-02-30). */
    public static function valid_day(string $day): bool {
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $day)) { return false; }
        [$y, $m, $d] = array_map('intval', explode('-', $day));
        return $y >= 2000 && $y <= 2100 && checkdate($m, $d, $y);
    }

    private static function lock(string $key): \core\lock\lock {
        $lock = \core\lock\lock_config::get_lock_factory('local_kbacompany')->get_lock('dreportcal:' . sha1($key), 10);
        if (!$lock) { self::fail('busy'); }
        return $lock;
    }

    private static function log(string $companyuuid, int $actor, string $action, array $details): void {
        global $DB;
        $DB->insert_record('local_kbacompany_dreportlog', (object)['companyuuid' => $companyuuid, 'reportuuid' => '',
            'versionuuid' => '', 'actorid' => $actor, 'action' => $action,
            'details' => json_encode($details, JSON_UNESCAPED_UNICODE), 'timecreated' => time()]);
    }

    /** Company calendar; safe defaults (switched off) when the company never saved one. No access check: read model. */
    public static function settings(string $companyuuid): \stdClass {
        global $DB;
        $row = $DB->get_record('local_kbacompany_dreportcal', ['companyuuid' => $companyuuid]);
        return $row ?: (object)['id' => 0, 'companyuuid' => $companyuuid, 'enabled' => 0, 'enabledfrom' => '',
            'workdays' => self::DEFAULT_WORKDAYS, 'deadlineminutes' => self::DEFAULT_DEADLINE, 'revision' => 0,
            'modifiedby' => 0, 'timecreated' => 0, 'timemodified' => 0];
    }

    /**
     * An administrator saves the calendar. Switching it on stores today as the first counted day (K5); switching it off
     * and on again moves that day, so the period while it was off never becomes "not submitted".
     */
    public static function save_settings(string $companyuuid, int $actor, bool $enabled, string $workdays,
            int $deadlineminutes, int $expectedrevision): \stdClass {
        global $DB;
        $companyuuid = self::member($companyuuid, $actor);
        self::require_admin($companyuuid, $actor);
        if (!preg_match('/^[01]{7}$/', $workdays) || strpos($workdays, '1') === false ||
                $deadlineminutes < 0 || $deadlineminutes > 1439) {
            self::fail('calendar_invalid');
        }
        $lock = self::lock('settings:' . $companyuuid);
        try {
            $current = self::settings($companyuuid);
            if ((int)$current->revision !== $expectedrevision) { self::fail('conflict'); }
            $now = time();
            $from = (string)$current->enabledfrom;
            if ($enabled && empty($current->enabled)) {
                $from = business_date_service::day($now);
            }
            $row = (object)['companyuuid' => $companyuuid, 'enabled' => $enabled ? 1 : 0, 'enabledfrom' => $from,
                'workdays' => $workdays, 'deadlineminutes' => $deadlineminutes, 'revision' => (int)$current->revision + 1,
                'modifiedby' => $actor, 'timemodified' => $now];
            $tx = $DB->start_delegated_transaction();
            try {
                if ((int)$current->id > 0) {
                    $row->id = (int)$current->id;
                    $DB->update_record('local_kbacompany_dreportcal', $row);
                } else {
                    $row->timecreated = $now;
                    $row->id = $DB->insert_record('local_kbacompany_dreportcal', $row);
                }
                self::log($companyuuid, $actor, 'calendar_changed', ['enabled' => (bool)$enabled, 'enabledfrom' => $from,
                    'workdays' => $workdays, 'deadlineminutes' => $deadlineminutes, 'revision' => (int)$row->revision]);
                $tx->allow_commit();
            } catch (\Throwable $e) {
                $tx->rollback($e);
            }
            return self::settings($companyuuid);
        } finally {
            $lock->release();
        }
    }

    /** Calendar exceptions in [from, to], ordered by day (company-scoped in SQL). */
    public static function offdays(string $companyuuid, string $from, string $to): array {
        global $DB;
        return array_values($DB->get_records_select('local_kbacompany_dreportoff',
            'companyuuid = :c AND offday >= :f AND offday <= :t', ['c' => $companyuuid, 'f' => $from, 't' => $to], 'offday ASC'));
    }

    /** An administrator adds one exception; one row per company and day. */
    public static function add_offday(string $companyuuid, int $actor, string $day, string $kind, string $name): string {
        global $DB;
        $companyuuid = self::member($companyuuid, $actor);
        self::require_admin($companyuuid, $actor);
        $name = trim($name);
        if (!self::valid_day($day) || !in_array($kind, self::OFFDAY_KINDS, true) || \core_text::strlen($name) > 255) {
            self::fail('calendar_invalid');
        }
        $lock = self::lock('offday:' . $companyuuid);
        try {
            if ($DB->record_exists('local_kbacompany_dreportoff', ['companyuuid' => $companyuuid, 'offday' => $day])) {
                self::fail('calendar_dayexists');
            }
            $uuid = \core\uuid::generate();
            $tx = $DB->start_delegated_transaction();
            try {
                $DB->insert_record('local_kbacompany_dreportoff', (object)['companyuuid' => $companyuuid, 'uuid' => $uuid,
                    'offday' => $day, 'kind' => $kind, 'name' => $name, 'createdby' => $actor, 'timecreated' => time()]);
                self::log($companyuuid, $actor, 'offday_added', ['uuid' => $uuid, 'day' => $day, 'kind' => $kind]);
                $tx->allow_commit();
            } catch (\Throwable $e) {
                $tx->rollback($e);
            }
            return $uuid;
        } finally {
            $lock->release();
        }
    }

    public static function remove_offday(string $companyuuid, int $actor, string $uuid): void {
        global $DB;
        $companyuuid = self::member($companyuuid, $actor);
        self::require_admin($companyuuid, $actor);
        $row = $DB->get_record('local_kbacompany_dreportoff', ['companyuuid' => $companyuuid, 'uuid' => trim($uuid)]);
        if (!$row) { self::fail(); }
        $tx = $DB->start_delegated_transaction();
        try {
            $DB->delete_records('local_kbacompany_dreportoff', ['id' => $row->id]);
            self::log($companyuuid, $actor, 'offday_removed', ['uuid' => (string)$row->uuid, 'day' => (string)$row->offday,
                'kind' => (string)$row->kind]);
            $tx->allow_commit();
        } catch (\Throwable $e) {
            $tx->rollback($e);
        }
    }

    /** Administrator: everyone; nearest manager (ADR-0010 D2): own direct team; nobody else. */
    public static function can_manage_absence(string $companyuuid, int $actor, int $userid, ?array $graph = null): bool {
        if ($actor <= 0 || $userid <= 0 || !daily_report_store_service::eligible_reviewer($companyuuid, $userid, 0)) {
            return false;
        }
        if (daily_report_review_service::is_admin($companyuuid, $actor)) { return true; }
        if ($userid === $actor) { return false; }
        $chain = daily_report_reviewer_service::chain($companyuuid, $userid, $graph);
        return $chain && (int)$chain[0]['userid'] === $actor;
    }

    /** Whether the actor may open the absence screen at all (administrator or a manager with a direct team). */
    public static function can_open_absences(string $companyuuid, int $actor): bool {
        try {
            $companyuuid = self::member($companyuuid, $actor);
        } catch (\moodle_exception $e) {
            return false;
        }
        return daily_report_review_service::is_admin($companyuuid, $actor) ||
            daily_report_reviewer_service::is_manager($companyuuid, $actor);
    }

    /** People whose absences the actor may manage, name-sorted [userid => full name]. */
    public static function manageable_people(string $companyuuid, int $actor): array {
        $companyuuid = self::member($companyuuid, $actor);
        $graph = daily_report_reviewer_service::graph($companyuuid, $actor);
        $out = [];
        foreach (daily_report_reviewer_service::people($companyuuid, $actor, 0) as $userid => $name) {
            if (self::can_manage_absence($companyuuid, $actor, (int)$userid, $graph)) { $out[(int)$userid] = $name; }
        }
        return $out;
    }

    /** Active absences of the people the actor manages, newest start first, paged; filtered in SQL by company and users. */
    public static function absences(string $companyuuid, int $actor, int $page = 0): array {
        global $DB;
        $companyuuid = self::member($companyuuid, $actor);
        if ($page < 0 || $page > 100000) { throw new \moodle_exception('invalidparameter'); }
        $people = self::manageable_people($companyuuid, $actor);
        if (!$people) {
            return ['items' => [], 'total' => 0, 'page' => $page, 'people' => []];
        }
        [$in, $params] = $DB->get_in_or_equal(array_keys($people), SQL_PARAMS_NAMED, 'au');
        $where = "companyuuid = :c AND status = 'active' AND userid {$in}";
        $params['c'] = $companyuuid;
        $total = $DB->count_records_select('local_kbacompany_dreportabs', $where, $params);
        $items = array_values($DB->get_records_select('local_kbacompany_dreportabs', $where, $params,
            'startday DESC, id DESC', '*', $page * self::PAGE_SIZE, self::PAGE_SIZE));
        return ['items' => $items, 'total' => (int)$total, 'page' => $page, 'people' => $people];
    }

    private static function absence(string $companyuuid, string $uuid): \stdClass {
        global $DB;
        $row = $DB->get_record('local_kbacompany_dreportabs', ['companyuuid' => $companyuuid, 'uuid' => trim($uuid)]);
        if (!$row) { self::fail(); }
        return $row;
    }

    private static function validate_period(string $kind, string $start, string $end): void {
        if (!in_array($kind, self::ABSENCE_KINDS, true) || !self::valid_day($start) || !self::valid_day($end) ||
                strcmp($start, $end) > 0) {
            self::fail('absence_invalid');
        }
        $days = (new \DateTimeImmutable($start, new \DateTimeZone('UTC')))
            ->diff(new \DateTimeImmutable($end, new \DateTimeZone('UTC')))->days + 1;
        if ($days > self::MAX_ABSENCE_DAYS) { self::fail('absence_invalid'); }
    }

    private static function require_no_overlap(string $companyuuid, int $userid, string $start, string $end,
            string $except = ''): void {
        global $DB;
        $where = "companyuuid = :c AND userid = :u AND status = 'active' AND startday <= :e AND endday >= :s";
        $params = ['c' => $companyuuid, 'u' => $userid, 'e' => $end, 's' => $start];
        if ($except !== '') {
            $where .= ' AND uuid <> :x';
            $params['x'] = $except;
        }
        if ($DB->record_exists_select('local_kbacompany_dreportabs', $where, $params)) { self::fail('absence_overlap'); }
    }

    /** Add an absence period for one employee (administrator or nearest manager). Periods of one person never overlap. */
    public static function add_absence(string $companyuuid, int $actor, int $userid, string $kind, string $start,
            string $end): string {
        global $DB;
        $companyuuid = self::member($companyuuid, $actor);
        if (!self::can_manage_absence($companyuuid, $actor, $userid)) { self::fail(); }
        self::validate_period($kind, $start, $end);
        $lock = self::lock('absence:' . $companyuuid . ':' . $userid);
        try {
            self::require_no_overlap($companyuuid, $userid, $start, $end);
            $uuid = \core\uuid::generate();
            $now = time();
            $tx = $DB->start_delegated_transaction();
            try {
                $DB->insert_record('local_kbacompany_dreportabs', (object)['companyuuid' => $companyuuid, 'uuid' => $uuid,
                    'userid' => $userid, 'kind' => $kind, 'startday' => $start, 'endday' => $end, 'status' => 'active',
                    'revision' => 1, 'createdby' => $actor, 'modifiedby' => $actor, 'timecreated' => $now,
                    'timemodified' => $now]);
                self::log($companyuuid, $actor, 'absence_added', ['uuid' => $uuid, 'userid' => $userid, 'kind' => $kind,
                    'startday' => $start, 'endday' => $end]);
                $tx->allow_commit();
            } catch (\Throwable $e) {
                $tx->rollback($e);
            }
            return $uuid;
        } finally {
            $lock->release();
        }
    }

    /** Change the kind or dates of an active period, or cancel it; optimistic revision; journaled (K5). */
    public static function change_absence(string $companyuuid, int $actor, string $uuid, int $expectedrevision,
            string $kind, string $start, string $end, bool $cancel = false): void {
        global $DB;
        $companyuuid = self::member($companyuuid, $actor);
        $row = self::absence($companyuuid, $uuid);
        if (!self::can_manage_absence($companyuuid, $actor, (int)$row->userid)) { self::fail(); }
        if (!$cancel) { self::validate_period($kind, $start, $end); }
        $lock = self::lock('absence:' . $companyuuid . ':' . (int)$row->userid);
        try {
            $row = self::absence($companyuuid, $uuid);
            if ((string)$row->status !== 'active') { self::fail('absence_invalid'); }
            if ((int)$row->revision !== $expectedrevision) { self::fail('conflict'); }
            if (!$cancel) { self::require_no_overlap($companyuuid, (int)$row->userid, $start, $end, (string)$row->uuid); }
            $before = ['kind' => (string)$row->kind, 'startday' => (string)$row->startday, 'endday' => (string)$row->endday];
            $update = (object)['id' => $row->id, 'revision' => (int)$row->revision + 1, 'modifiedby' => $actor,
                'timemodified' => time()];
            if ($cancel) {
                $update->status = 'cancelled';
            } else {
                $update->kind = $kind; $update->startday = $start; $update->endday = $end;
            }
            $tx = $DB->start_delegated_transaction();
            try {
                $DB->update_record('local_kbacompany_dreportabs', $update);
                self::log($companyuuid, $actor, $cancel ? 'absence_cancelled' : 'absence_changed', ['uuid' => (string)$row->uuid,
                    'userid' => (int)$row->userid, 'before' => $before,
                    'after' => $cancel ? null : ['kind' => $kind, 'startday' => $start, 'endday' => $end]]);
                $tx->allow_commit();
            } catch (\Throwable $e) {
                $tx->rollback($e);
            }
        } finally {
            $lock->release();
        }
    }

    /**
     * Whether a report of this employee is required for the civil day (read model for R5b/R5c; no access check, the
     * caller has authorised the actor). Reasons: disabled, beforestart, dayoff, absence, workday.
     */
    public static function requirement(string $companyuuid, int $userid, string $day): array {
        $reason = self::requirements($companyuuid, [$userid], $day)[$userid] ?? 'disabled';
        return ['required' => $reason === 'workday', 'reason' => $reason];
    }

    /** Working day of the company calendar: an exception wins over the weekday pattern. */
    public static function working_day(string $companyuuid, string $day, ?\stdClass $cal = null): bool {
        global $DB;
        $cal = $cal ?? self::settings($companyuuid);
        $exception = $DB->get_field('local_kbacompany_dreportoff', 'kind', ['companyuuid' => $companyuuid, 'offday' => $day]);
        if ($exception !== false) {
            return (string)$exception === 'workday';
        }
        $weekday = (int)(new \DateTimeImmutable($day, new \DateTimeZone('UTC')))->format('N');
        return substr((string)$cal->workdays, $weekday - 1, 1) === '1';
    }

    /**
     * K3 deadline of the report for $day: the configured time of the next working day in the employee's time zone,
     * as a UTC timestamp. The next working day is searched up to 31 days ahead (a long holiday period).
     */
    public static function deadline(string $companyuuid, string $day, string $timezone): int {
        $cal = self::settings($companyuuid);
        $zone = new \DateTimeZone(\core_date::normalise_timezone($timezone));
        $next = new \DateTimeImmutable($day, $zone);
        for ($i = 0; $i < 31; $i++) {
            $next = $next->modify('+1 day');
            if (self::working_day($companyuuid, $next->format('Y-m-d'), $cal)) { break; }
        }
        $minutes = (int)$cal->deadlineminutes;
        return $next->setTime(intdiv($minutes, 60), $minutes % 60)->getTimestamp();
    }

    /**
     * R5b: requirement reasons of many employees for one company day — one calendar read, one exception read and one
     * absence query (company and day in SQL). Same reasons as requirement(): disabled, beforestart, dayoff, absence,
     * workday. Computed on read, so a later change of the calendar or of an absence re-evaluates past days (K5).
     * @return array [userid => reason]
     */
    public static function requirements(string $companyuuid, array $userids, string $day): array {
        global $DB;
        $userids = array_values(array_unique(array_filter(array_map('intval', $userids), static function(int $id): bool {
            return $id > 0;
        })));
        $cal = self::settings($companyuuid);
        if (empty($cal->enabled) || !self::valid_day($day)) {
            $reason = 'disabled';
        } else if ((string)$cal->enabledfrom === '' || strcmp($day, (string)$cal->enabledfrom) < 0) {
            $reason = 'beforestart';
        } else if (!self::working_day($companyuuid, $day, $cal)) {
            $reason = 'dayoff';
        } else {
            $reason = 'workday';
        }
        $out = array_fill_keys($userids, $reason);
        if ($reason === 'workday' && $userids) {
            [$insql, $params] = $DB->get_in_or_equal($userids, SQL_PARAMS_NAMED, 'ru');
            $absent = $DB->get_fieldset_select('local_kbacompany_dreportabs', 'userid',
                "companyuuid = :c AND status = 'active' AND startday <= :d1 AND endday >= :d2 AND userid {$insql}",
                ['c' => $companyuuid, 'd1' => $day, 'd2' => $day] + $params);
            foreach ($absent as $userid) {
                $out[(int)$userid] = 'absence';
            }
        }
        return $out;
    }

    /**
     * R5b due state of one employee day. neutral: calendar off or the day is before it was switched on — the R4 view
     * stays unchanged; notrequired: day off or absence; for a required day: ontime / late by the first submission
     * (a resubmission after a return is not lateness), otherwise expected until the deadline and missed after it.
     */
    public static function due(string $reason, int $firstsubmitted, int $deadline, int $now): string {
        if ($reason === 'disabled' || $reason === 'beforestart') {
            return 'neutral';
        }
        if ($reason !== 'workday') {
            return 'notrequired';
        }
        if ($firstsubmitted > 0) {
            return $firstsubmitted > $deadline ? 'late' : 'ontime';
        }
        return $now >= $deadline ? 'missed' : 'expected';
    }
}
