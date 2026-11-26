<?php
namespace local_kbacompany\local;

defined('MOODLE_INTERNAL') || die();

/**
 * Daily reports R5c (owner decision K4, 24.11.2026: "Разрешаю рассылку"): one morning reminder to an employee who has
 * not sent the report of the last required working day, on the morning its deadline falls (K3), before the deadline.
 *
 * - Only companies whose reporting calendar is switched on (K6), only days from the calendar start (K5), only required
 *   working days (no day off, no absence), only employees without any submitted version of that day. Nothing is sent
 *   when nothing is missing — there are no "zero" reminders.
 * - Idempotency key: company + employee + day. The in-app notification has a deterministic identity
 *   (communication_service::ensure_system_notification), so a repeated or concurrent run never sends twice.
 * - Recipients are explicit company members (BMS membership or the Corporate employee directory), each re-checked as
 *   an active, unrevoked member; site administrators are never reminded for companies they only oversee.
 * - Existing reminders and scheduled tasks are untouched; this runs as its own task (send_daily_report_reminders).
 */
final class daily_report_reminder_service {
    public const KIND = 'dailyreport_reminder';
    /** Morning start of the reminder window, minutes after local midnight (08:00), unless the deadline is earlier. */
    public const MORNING = 480;

    /** One pass over every company with the calendar on. @return array counters for the task log */
    public static function run_due(int $now = 0): array {
        global $DB;
        $now = $now ?: time();
        $stats = ['companies' => 0, 'checked' => 0, 'sent' => 0, 'failed' => 0];
        if (!daily_report_store_service::enabled()) {
            return $stats;
        }
        $lock = \core\lock\lock_config::get_lock_factory('local_kbacompany')->get_lock('dailyreport_reminders', 0);
        if (!$lock) {
            return $stats;
        }
        try {
            foreach ($DB->get_fieldset_select('local_kbacompany_rptcalendar', 'companyuuid', 'enabled = 1') as $company) {
                $stats['companies']++;
                try {
                    self::company((string)$company, $now, $stats);
                } catch (\Throwable $e) {
                    $stats['failed']++;
                    mtrace('BMS daily report reminders skipped a company: ' . get_class($e));
                }
            }
        } finally {
            $lock->release();
        }
        return $stats;
    }

    /**
     * The report day an employee in $timezone is reminded about at $now, with its deadline, or null outside the window.
     * The day is the last working day before today whose deadline falls today; the window runs from 08:00 local time
     * (or one hour before an earlier deadline) until the deadline.
     * @return array|null [string day, int deadline]
     */
    public static function window(string $companyuuid, \stdClass $cal, string $timezone, int $now): ?array {
        $zone = new \DateTimeZone(\core_date::normalise_timezone($timezone));
        $today = (new \DateTimeImmutable('@' . $now))->setTimezone($zone)->setTime(0, 0);
        $day = '';
        for ($i = 1; $i <= 31; $i++) {
            $candidate = $today->modify('-' . $i . ' day')->format('Y-m-d');
            if (daily_report_calendar_service::working_day($companyuuid, $candidate, $cal)) {
                $day = $candidate;
                break;
            }
        }
        if ($day === '') {
            return null;
        }
        $deadline = daily_report_calendar_service::deadline($companyuuid, $day, $zone->getName());
        if ((new \DateTimeImmutable('@' . $deadline))->setTimezone($zone)->format('Y-m-d') !== $today->format('Y-m-d')) {
            return null;
        }
        $start = $today->getTimestamp() + 60 * min(self::MORNING, max(0, (int)$cal->deadlineminutes - 60));
        return ($now >= $start && $now < $deadline) ? [$day, $deadline] : null;
    }

    /** Explicit members of the company: BMS memberships and the Corporate employee directory (company in SQL). */
    public static function members(string $companyuuid): array {
        global $DB;
        $ids = $DB->get_fieldset_select('local_kbacompany_member', 'userid', 'companyuuid = :c AND active = 1',
            ['c' => $companyuuid]);
        $legacyid = 0;
        $canonical = company_registry_service::by_uuid($companyuuid);
        if ($canonical) {
            $legacyid = (int)($canonical->legacycompanyid ?? 0);
        } else {
            $identity = identity_registry::resolve($companyuuid);
            if ($identity && (string)$identity->sourcecomponent === identity_registry::SOURCE_COMPONENT &&
                    (string)$identity->sourceentity === identity_registry::COMPANY_ENTITY) {
                $legacyid = (int)$identity->sourceid;
            }
        }
        if ($legacyid > 0 && legacy_company_adapter::is_ready()) {
            [$sql, $params] = legacy_company_adapter::employee_directory_source($legacyid, $companyuuid);
            foreach ($DB->get_records_sql($sql, $params) as $row) {
                $ids[] = $row->userid;
            }
        }
        $ids = array_values(array_unique(array_map('intval', $ids)));
        sort($ids);
        return $ids;
    }

    private static function company(string $companyuuid, int $now, array &$stats): void {
        global $DB;
        $companyuuid = company_context_service::require_exists($companyuuid);
        $cal = daily_report_calendar_service::settings($companyuuid);
        if (empty($cal->enabled)) {
            return;
        }
        $ids = array_values(array_filter(self::members($companyuuid), static function(int $id) use ($companyuuid): bool {
            return daily_report_store_service::eligible_reviewer($companyuuid, $id, 0);
        }));
        if (!$ids) {
            return;
        }
        // Group the members by the day they are reminded about (it depends on their time zone).
        $groups = [];
        $windows = [];
        foreach ($DB->get_records_list('user', 'id', $ids, 'id ASC', 'id, timezone, lang') as $user) {
            $zone = \core_date::get_user_timezone($user);
            if (!array_key_exists($zone, $windows)) {
                $windows[$zone] = self::window($companyuuid, $cal, $zone, $now);
            }
            if ($windows[$zone]) {
                [$day, $deadline] = $windows[$zone];
                $groups[$day . '|' . $deadline . '|' . $zone][] = $user;
            }
        }
        foreach ($groups as $group => $users) {
            [$day, $deadline, $zone] = explode('|', $group, 3);
            $userids = array_map(static function(\stdClass $u): int { return (int)$u->id; }, $users);
            $reasons = daily_report_calendar_service::requirements($companyuuid, $userids, $day);
            [$insql, $params] = $DB->get_in_or_equal($userids, SQL_PARAMS_NAMED, 'rm');
            // A report with any submitted version (submitted, returned, accepted) needs no reminder.
            $sent = array_flip(array_map('intval', $DB->get_fieldset_select('local_kbacompany_dreport', 'userid',
                "companyuuid = :c AND reportday = :d AND currentversionuuid <> '' AND userid {$insql}",
                ['c' => $companyuuid, 'd' => $day] + $params)));
            foreach ($users as $user) {
                $stats['checked']++;
                if (($reasons[(int)$user->id] ?? '') !== 'workday' || isset($sent[(int)$user->id])) {
                    continue;
                }
                try {
                    if (self::remind($companyuuid, $user, $day, (int)$deadline, $zone)) {
                        $stats['sent']++;
                    }
                } catch (\Throwable $e) {
                    $stats['failed']++;
                    mtrace('BMS daily report reminder skipped: ' . get_class($e));
                }
            }
        }
    }

    /** Send one reminder (idempotent per company, employee and day); false when it was already sent. */
    private static function remind(string $companyuuid, \stdClass $user, string $day, int $deadline, string $zone): bool {
        global $DB;
        $before = $DB->count_records('local_kbacompany_commnotif', ['userid' => (int)$user->id, 'kind' => self::KIND]);
        $strings = get_string_manager();
        $lang = (string)($user->lang ?? '') !== '' && $strings->translation_exists((string)$user->lang) ?
            (string)$user->lang : current_language();
        $daystart = (new \DateTimeImmutable($day, new \DateTimeZone($zone)))->getTimestamp();
        $title = $strings->get_string('dailyreports_remind_title', 'local_kbacompany', (object)[
            'day' => userdate($daystart, $strings->get_string('strftimedaydate', 'langconfig', null, $lang), $zone),
            'due' => userdate($deadline, $strings->get_string('strftimedatetimeshort', 'langconfig', null, $lang), $zone),
        ], $lang);
        daily_report_review_service::notify($companyuuid, (int)$user->id, self::KIND, [$day], $title,
            daily_report_ui_service::url($companyuuid, ['day' => $day]));
        return $DB->count_records('local_kbacompany_commnotif', ['userid' => (int)$user->id, 'kind' => self::KIND]) > $before;
    }
}
