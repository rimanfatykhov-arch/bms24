<?php
namespace local_kbacompany\local;

defined('MOODLE_INTERNAL') || die();

/**
 * ADR-0011 (block 1, block 2 S5): monthly or weekly summary of daily reports for managers and company administrators.
 *
 * A read-only grid: unit -> employee -> day of the month, with the state of each report and two ratios per employee
 * (share of rated reports, share of positive ratings). Readers are the ADR-0010 D4 readers (administrators and managers
 * of the company); "my staff" narrows the grid to employees whose organisation chart chain contains the reader. Drafts
 * are never revealed: a report that was never submitted shows as "no report". Every read is scoped to one company in
 * SQL; nothing here writes.
 */
final class daily_report_summary_service {
    public const SCOPES = ['all', 'mine'];
    /** ADR-0011 S5: a month or a Monday-to-Sunday week of daily reports (no separate period report). */
    public const PERIODS = ['month', 'week'];
    /**
     * Cell states; 'accepted' carries the rating of the latest decision on the current version. Without a submitted
     * report (S5): 'weekend' on a day off, 'missed' on a past working day, 'none' today and later.
     */
    public const STATES = ['none', 'submitted', 'returned', 'accepted', 'weekend', 'missed'];
    public const NO_UNIT = '';

    /** 'YYYY-MM' of the business day when the parameter is empty or malformed. */
    public static function month(string $month): string {
        if (preg_match('/^(\d{4})-(\d{2})$/', $month, $m) && (int)$m[1] >= 2000 && (int)$m[1] <= 2100 &&
                (int)$m[2] >= 1 && (int)$m[2] <= 12) {
            return $month;
        }
        return substr(business_date_service::day(), 0, 7);
    }

    /** Civil days of the month as 'YYYY-MM-DD' with the ISO weekday (1 = Monday ... 7 = Sunday). */
    public static function days(string $month): array {
        $first = \DateTimeImmutable::createFromFormat('!Y-m-d', self::month($month) . '-01', new \DateTimeZone('UTC'));
        $out = [];
        for ($d = $first; $d->format('Y-m') === $first->format('Y-m'); $d = $d->modify('+1 day')) {
            $out[$d->format('Y-m-d')] = (int)$d->format('N');
        }
        return $out;
    }

    /** Monday of the week of 'YYYY-MM-DD'; the business day's week when the value is malformed. */
    public static function week(string $day): string {
        $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $day, new \DateTimeZone('UTC'));
        if (!$date || $date->format('Y-m-d') !== $day || (int)$date->format('Y') < 2000 || (int)$date->format('Y') > 2100) {
            $date = new \DateTimeImmutable(business_date_service::day() . ' 00:00:00', new \DateTimeZone('UTC'));
        }
        return $date->modify('-' . ((int)$date->format('N') - 1) . ' days')->format('Y-m-d');
    }

    /** The seven days of the week starting on Monday $monday, with the ISO weekday. */
    public static function week_days(string $monday): array {
        $date = new \DateTimeImmutable(self::week($monday) . ' 00:00:00', new \DateTimeZone('UTC'));
        $out = [];
        for ($i = 0; $i < 7; $i++, $date = $date->modify('+1 day')) {
            $out[$date->format('Y-m-d')] = (int)$date->format('N');
        }
        return $out;
    }

    public static function shift_week(string $monday, int $delta): string {
        $date = new \DateTimeImmutable(self::week($monday) . ' 00:00:00', new \DateTimeZone('UTC'));
        return $date->modify(($delta >= 0 ? '+' : '') . (7 * $delta) . ' days')->format('Y-m-d');
    }

    public static function shift(string $month, int $delta): string {
        $first = \DateTimeImmutable::createFromFormat('!Y-m-d', self::month($month) . '-01', new \DateTimeZone('UTC'));
        return $first->modify(($delta >= 0 ? '+' : '') . $delta . ' month')->format('Y-m');
    }

    /** Readers of the summary: the ADR-0010 D4 readers of every report of the company. */
    public static function can_view(string $companyuuid, int $actor): bool {
        return daily_report_review_service::can_read_all($companyuuid, $actor);
    }

    /** Active units of the company for the filter, ordered by sort order and name. */
    public static function units(string $companyuuid): array {
        global $DB;
        $out = [];
        foreach ($DB->get_records('local_kbacompany_orgnode', ['companyuuid' => $companyuuid, 'active' => 1],
                'sortorder ASC, name ASC, id ASC', 'id, uuid, name') as $node) {
            $out[(string)$node->uuid] = (string)$node->name;
        }
        return $out;
    }

    /**
     * The grid of one month or one week ($period 'week' takes the week of $week, any day of it).
     * @return array ['period', 'month', 'week', 'today', 'days' => [day => weekday], 'scope', 'unit',
     *   'units' => [uuid => name], 'groups' => [['unit' => uuid|'', 'name' => string, 'rows' => [row, ...]], ...]]
     *   row = ['userid', 'name', 'cells' => [day => ['state', 'rating', 'report']], 'total', 'rated', 'positive',
     *          'missed', 'ratedpct' => int|null, 'positivepct' => int|null]
     */
    public static function grid(string $companyuuid, int $actor, string $month, string $scope = 'all', string $unit = '',
            string $period = 'month', string $week = ''): array {
        global $DB;
        $companyuuid = company_context_service::normalise_uuid($companyuuid);
        company_context_service::context($companyuuid, $actor);
        if (!daily_report_store_service::enabled() || !self::can_view($companyuuid, $actor)) {
            throw new \moodle_exception('dailyreports_notavailable', 'local_kbacompany');
        }
        $period = in_array($period, self::PERIODS, true) ? $period : 'month';
        $week = self::week($week);
        $month = $period === 'week' ? substr($week, 0, 7) : self::month($month);
        $today = business_date_service::day();
        $scope = in_array($scope, self::SCOPES, true) ? $scope : 'all';
        $units = self::units($companyuuid);
        $unit = isset($units[$unit]) ? $unit : '';
        $days = $period === 'week' ? self::week_days($week) : self::days($month);
        $graph = daily_report_reviewer_service::graph($companyuuid, $actor);
        [$unitsof] = $graph;

        // Employees: current members of the company, plus authors of this month's submitted reports.
        $people = [];
        try {
            $employees = organization_service::organization_context($companyuuid, $actor)['employees'] ?? [];
        } catch (\moodle_exception $e) {
            $employees = [];
        }
        foreach ($employees as $employee) {
            $people[(int)$employee['user_id']] = (string)$employee['full_name'];
        }
        $first = array_key_first($days);
        $last = array_key_last($days);
        $reports = $DB->get_records_sql("SELECT r.id, r.uuid, r.userid, r.reportday, r.status, r.currentversionuuid
                FROM {local_kbacompany_dreport} r
               WHERE r.companyuuid = :c AND r.reportday >= :first AND r.reportday <= :last
                 AND r.currentversionuuid <> :empty
            ORDER BY r.reportday ASC, r.id ASC", ['c' => $companyuuid, 'first' => $first, 'last' => $last, 'empty' => '']);
        foreach ($reports as $report) {
            $uid = (int)$report->userid;
            if (!isset($people[$uid])) {
                $user = \core_user::get_user($uid);
                $people[$uid] = $user ? fullname($user) : '#' . $uid;
            }
        }
        unset($people[0]);
        if ($scope === 'mine') {
            foreach (array_keys($people) as $uid) {
                $chain = $uid === $actor ? [] : daily_report_reviewer_service::chain($companyuuid, $uid, $graph);
                if (!in_array($actor, array_column($chain, 'userid'), true)) {
                    unset($people[$uid]);
                }
            }
        }
        if ($unit !== '') {
            foreach (array_keys($people) as $uid) {
                if (!in_array($unit, $unitsof[$uid] ?? [], true)) {
                    unset($people[$uid]);
                }
            }
        }

        // Latest decision on each current version (rating of an accepted report), company-scoped.
        $ratings = [];
        $versions = array_values(array_filter(array_map(static function($r): string {
            return (string)$r->currentversionuuid;
        }, $reports)));
        foreach (array_chunk($versions, 500) as $chunk) {
            [$in, $params] = $DB->get_in_or_equal($chunk, SQL_PARAMS_NAMED, 'sv');
            foreach ($DB->get_records_select('local_kbacompany_dreportrev', "companyuuid = :c AND versionuuid {$in}",
                    ['c' => $companyuuid] + $params, 'timecreated ASC, id ASC', 'id, versionuuid, decision, rating') as $row) {
                $ratings[(string)$row->versionuuid] = (string)$row->rating;
            }
        }
        $cells = [];
        foreach ($reports as $report) {
            $uid = (int)$report->userid;
            if (!isset($people[$uid])) {
                continue;
            }
            $status = (string)$report->status;
            // A report reworked after a return is a draft with a current version: it shows as returned.
            $state = $status === 'draft' ? 'returned' : (in_array($status, self::STATES, true) ? $status : 'submitted');
            $rating = $state === 'accepted' ? ($ratings[(string)$report->currentversionuuid] ?? '') : '';
            $cells[$uid][(string)$report->reportday] = ['state' => $state,
                'rating' => in_array($rating, daily_report_review_service::RATINGS, true) ? $rating : 'none',
                'report' => (string)$report->uuid];
        }

        // Rows grouped by unit: an employee appears under every unit they work in, like the organisation chart.
        $groups = [];
        $order = array_keys($units);
        foreach ($people as $uid => $name) {
            $row = self::row($uid, $name, $days, $cells[$uid] ?? [], $today);
            $memberof = array_values(array_intersect($order, $unitsof[$uid] ?? []));
            if ($unit !== '') {
                $memberof = [$unit];
            }
            foreach ($memberof ?: [self::NO_UNIT] as $u) {
                $groups[$u][] = $row;
            }
        }
        $out = [];
        foreach (array_merge($order, [self::NO_UNIT]) as $u) {
            if (empty($groups[$u])) {
                continue;
            }
            usort($groups[$u], static function(array $a, array $b): int {
                return [\core_text::strtolower($a['name']), $a['userid']] <=> [\core_text::strtolower($b['name']), $b['userid']];
            });
            $out[] = ['unit' => $u, 'name' => $u === self::NO_UNIT ? '' : $units[$u], 'rows' => $groups[$u]];
        }
        return ['period' => $period, 'month' => $month, 'week' => $week, 'today' => $today, 'days' => $days,
            'scope' => $scope, 'unit' => $unit, 'units' => $units,
            'groups' => $out];
    }

    /** State of a day without a submitted report (S5): day off, past working day, or not yet due. */
    public static function empty_state(string $day, string $today): string {
        if (!daily_report_schedule_service::required($day)) {
            return 'weekend';
        }
        return strcmp($day, $today) < 0 ? 'missed' : 'none';
    }

    /**
     * One employee row with the two ratios (rated / submitted reports, positive / rated reports) and the number of past
     * working days without a submitted report. Days off never count.
     */
    private static function row(int $uid, string $name, array $days, array $cells, string $today): array {
        $total = 0; $rated = 0; $positive = 0; $missed = 0; $out = [];
        foreach (array_keys($days) as $day) {
            $cell = $cells[$day] ?? ['state' => self::empty_state($day, $today), 'rating' => 'none', 'report' => ''];
            if (in_array($cell['state'], ['submitted', 'returned', 'accepted'], true)) {
                $total++;
            }
            $missed += $cell['state'] === 'missed' ? 1 : 0;
            if ($cell['state'] === 'accepted' && in_array($cell['rating'], ['positive', 'negative'], true)) {
                $rated++;
                $positive += $cell['rating'] === 'positive' ? 1 : 0;
            }
            $out[$day] = $cell;
        }
        return ['userid' => $uid, 'name' => $name, 'cells' => $out, 'total' => $total, 'rated' => $rated,
            'positive' => $positive, 'missed' => $missed, 'ratedpct' => $total ? (int)round(100 * $rated / $total) : null,
            'positivepct' => $rated ? (int)round(100 * $positive / $rated) : null];
    }
}
