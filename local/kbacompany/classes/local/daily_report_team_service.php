<?php
namespace local_kbacompany\local;

defined('MOODLE_INTERNAL') || die();

/**
 * Daily reports R4 "team for the day" (owner decisions T1–T5, 24.11.2026; docs/daily-reports/R4_TEAM_DAY_PROPOSAL.md).
 *
 * A read-only reviewer list of every current employee of the company for one civil day, including those without a
 * report. Who may open it: the administrators and managers who already read every report of the company (ADR-0010
 * D4). T1: managers start on "my team" — the employees whose nearest manager up the organisation chart is the actor
 * (ADR-0010 D2) — and may switch to the whole company; an administrator who manages nobody sees the company. T2: a draft
 * that was never sent is "not sent", without any of its content. T3: no "missing" or "overdue": without a reporting
 * calendar the neutral state is "no report". T4: deleted, suspended and revoked users are not listed. T5: the list is
 * behind the existing dailyreports_enabled switch.
 *
 * Employees come from the authorised organisation snapshot of this company (organization_service), each one re-checked
 * against company membership and revocation; reports are read with the company and the day in SQL. Nothing is written.
 *
 * R5b (owner decisions K3, K5): once the company reporting calendar is on, every row also carries a due state
 * (daily_report_calendar_service::due) and the deadline in the employee's time zone; before the calendar start and
 * while it is off the due state is "neutral" and the R4 view is unchanged (T3).
 *
 * R6 (roadmap #75, "reports of my team"): an optional period up to MAX_DAYS days (one row per employee and day), a filter
 * by one employee of the listed people, and the ordered sequence of the sent reports of the whole filtered list, so that
 * a reviewer can read them one after another; every card is still opened through the per-report access check.
 */
final class daily_report_team_service {
    public const PAGE_SIZE = 50;
    /** R6: longest period of the list, days (inclusive). */
    public const MAX_DAYS = 31;
    public const SCOPES = ['team', 'company'];
    /** Day states, in display order; 'notsent' is a draft without any submitted version (T2). */
    public const STATUSES = ['none', 'notsent', 'submitted', 'returned', 'accepted'];

    /** Same audience as the company-wide report list (ADR-0010 D4), behind the reports switch (T5). */
    public static function available(string $companyuuid, int $actor): bool {
        return daily_report_store_service::enabled() && $actor > 0 &&
            daily_report_review_service::can_read_all($companyuuid, $actor);
    }

    /** "My team" exists only for someone who manages a unit or is flagged manager; otherwise the company is shown. */
    public static function scopes(string $companyuuid, int $actor, ?array $graph = null): array {
        return daily_report_reviewer_service::is_manager($companyuuid, $actor, $graph) ? self::SCOPES : ['company'];
    }

    /** Day state of one report row (or none). A returned report being reworked stays "returned", as in the R2 queue. */
    public static function state(?\stdClass $report): string {
        if (!$report) {
            return 'none';
        }
        $status = (string)$report->status;
        if ($status === 'draft') {
            return (string)$report->currentversionuuid === '' ? 'notsent' : 'returned';
        }
        return in_array($status, ['submitted', 'returned', 'accepted'], true) ? $status : 'none';
    }

    /**
     * One page of the team for the day.
     * @return array ['day', 'scope', 'scopes', 'unit', 'units' => [uuid => name], 'status', 'counts' => [state => int],
     *                'total', 'page', 'items' => [['userid', 'name', 'unit', 'state', 'reportuuid', 'submittedat', 'late',
     *                'reviewerid', 'reviewersource', 'rating', 'due', 'deadline']], 'due' => filter,
     *                'duecounts' => [due => int], 'calendar' => bool, 'dayto', 'days' => int, 'employee' => int,
     *                'people' => [userid => name], 'sequence' => [reportuuid, ...]]; each item also has its 'day'.
     */
    public static function day(string $companyuuid, int $actor, string $day, string $scope = '', string $unit = '',
            string $status = '', int $page = 0, string $due = '', int $employee = 0, string $dayto = ''): array {
        global $DB;
        $companyuuid = company_context_service::normalise_uuid($companyuuid);
        company_context_service::context($companyuuid, $actor);
        daily_report_store_service::require_enabled();
        if (!daily_report_review_service::can_read_all($companyuuid, $actor)) {
            throw new \moodle_exception('dailyreports_notavailable', 'local_kbacompany');
        }
        daily_report_service::bounds($day);
        $dayto = $dayto === '' ? $day : $dayto;
        daily_report_service::bounds($dayto);
        if (strcmp($day, business_date_service::day()) > 0 || strcmp($dayto, business_date_service::day()) > 0) {
            throw new \moodle_exception('dailyreports_futureday', 'local_kbacompany');
        }
        $days = [];
        for ($cursor = new \DateTimeImmutable($dayto, new \DateTimeZone('UTC'));
                count($days) <= self::MAX_DAYS && strcmp($cursor->format('Y-m-d'), $day) >= 0;
                $cursor = $cursor->modify('-1 day')) {
            $days[] = $cursor->format('Y-m-d');
        }
        if (!$days || count($days) > self::MAX_DAYS || $employee < 0) {
            throw new \moodle_exception('dailyreports_teamperiod', 'local_kbacompany', '', self::MAX_DAYS);
        }
        if ($page < 0 || $page > 100000 || ($status !== '' && !in_array($status, self::STATUSES, true)) ||
                ($due !== '' && !in_array($due, daily_report_calendar_service::DUE, true))) {
            throw new \moodle_exception('invalidparameter');
        }
        $graph = daily_report_reviewer_service::graph($companyuuid, $actor);
        $scopes = self::scopes($companyuuid, $actor, $graph);
        if ($scope === '') {
            $scope = $scopes[0];
        }
        if (!in_array($scope, $scopes, true)) {
            throw new \moodle_exception('invalidparameter');
        }
        [$unitsof] = $graph;

        // Employees: authorised snapshot of this company, each one still an active, unrevoked member (T4).
        try {
            $snapshot = organization_service::organization_context($companyuuid, $actor);
        } catch (\moodle_exception $e) {
            $snapshot = ['employees' => [], 'org_units' => []];
        }
        $people = [];
        foreach ($snapshot['employees'] ?? [] as $employee) {
            $id = (int)$employee['user_id'];
            if ($id > 0 && !isset($people[$id]) && daily_report_store_service::eligible_reviewer($companyuuid, $id, 0)) {
                $people[$id] = ['userid' => $id, 'name' => (string)$employee['full_name'],
                    'unit' => (string)(($unitsof[$id] ?? [])[0] ?? '')];
            }
        }
        if ($scope === 'team') {
            foreach (array_keys($people) as $id) {
                $nearest = daily_report_reviewer_service::chain($companyuuid, $id, $graph)[0]['userid'] ?? 0;
                if ($id === $actor || (int)$nearest !== $actor) {
                    unset($people[$id]);
                }
            }
        }

        $units = self::unit_names($companyuuid, $snapshot['org_units'] ?? [], $people);
        if ($unit !== '' && !isset($units[$unit]) && $unit !== 'none') {
            throw new \moodle_exception('invalidparameter');
        }
        if ($unit !== '') {
            $people = array_filter($people, static function(array $p) use ($unit): bool {
                return $unit === 'none' ? $p['unit'] === '' : $p['unit'] === $unit;
            });
        }
        // R6: one employee of exactly the people this list may show; anyone else is refused, never looked up.
        $options = [];
        foreach ($people as $id => $person) {
            $options[$id] = $person['name'];
        }
        \core_collator::asort($options);
        if ($employee > 0) {
            if (!isset($people[$employee])) {
                throw new \moodle_exception('invalidparameter');
            }
            $people = [$employee => $people[$employee]];
        }

        // Reports of the period for exactly these people, company and days in SQL.
        $byday = [];
        $all = [];
        if ($people) {
            [$insql, $inparams] = $DB->get_in_or_equal(array_keys($people), SQL_PARAMS_NAMED, 'tu');
            $all = $DB->get_records_sql("SELECT r.id, r.userid, r.reportday, r.uuid, r.status, r.currentversionuuid,
                    v.submittedat, v.reviewerid, v.reviewersource
                FROM {local_kbacompany_dreport} r
           LEFT JOIN {local_kbacompany_dreportver} v ON v.companyuuid = r.companyuuid AND v.uuid = r.currentversionuuid
               WHERE r.companyuuid = :c AND r.reportday >= :dfrom AND r.reportday <= :dto AND r.userid {$insql}",
                ['c' => $companyuuid, 'dfrom' => $day, 'dto' => $dayto] + $inparams);
            foreach ($all as $row) {
                $byday[(string)$row->reportday][(int)$row->userid] = $row;
            }
        }
        $ratings = self::ratings($companyuuid, $all);

        $counts = array_fill_keys(self::STATUSES, 0);
        $duecounts = array_fill_keys(daily_report_calendar_service::DUE, 0);
        $rows = [];
        foreach ($days as $rowday) {
          $reports = $byday[$rowday] ?? [];
          $schedule = self::schedule($companyuuid, array_keys($people), $rowday, $reports);
          foreach ($people as $id => $person) {
            $report = $reports[$id] ?? null;
            $state = self::state($report);
            [$duestate, $deadline] = $schedule[$id];
            $counts[$state]++;
            $duecounts[$duestate]++;
            if (($status !== '' && $state !== $status) || ($due !== '' && $duestate !== $due)) {
                continue;
            }
            $sent = $report && !in_array($state, ['none', 'notsent'], true);
            $submittedat = $sent ? (int)$report->submittedat : 0;
            $rows[] = $person + [
                'state' => $state,
                // Drafts stay private (T2): no card link, no reviewer, no time.
                'reportuuid' => $sent ? (string)$report->uuid : '',
                'submittedat' => $submittedat,
                // With the calendar on, lateness is judged by the deadline (K3), otherwise by the report day (R1).
                'late' => in_array($duestate, ['ontime', 'late'], true) ? $duestate === 'late' :
                    $submittedat > 0 && strcmp(business_date_service::day($submittedat), $rowday) > 0,
                'reviewerid' => $sent ? (int)$report->reviewerid : 0,
                'reviewersource' => $sent ? (string)$report->reviewersource : '',
                'rating' => $state === 'accepted' ? ($ratings[(string)$report->currentversionuuid] ?? '') : '',
                'due' => $duestate,
                'deadline' => $deadline,
                'day' => $rowday,
            ];
          }
        }
        // Newest day first, then by name: for one day this is the R4 order.
        usort($rows, static function(array $left, array $right): int {
            return strcmp($right['day'], $left['day']) ?: strcasecmp($left['name'], $right['name']) ?:
                $left['userid'] <=> $right['userid'];
        });
        $sequence = [];
        foreach ($rows as $row) {
            if ($row['reportuuid'] !== '') {
                $sequence[] = $row['reportuuid'];
            }
        }
        return ['day' => $day, 'scope' => $scope, 'scopes' => $scopes, 'unit' => $unit, 'units' => $units,
            'status' => $status, 'counts' => $counts, 'total' => count($rows), 'page' => $page,
            'due' => $due, 'duecounts' => $duecounts, 'calendar' => $duecounts['neutral'] < count($people) * count($days),
            'dayto' => $dayto, 'days' => count($days), 'employee' => $employee, 'people' => $options,
            'sequence' => $sequence, 'items' => array_slice($rows, $page * self::PAGE_SIZE, self::PAGE_SIZE)];
    }

    /** Names of the units the listed people belong to: Corporate departments and BMS chart nodes of this company. */
    private static function unit_names(string $companyuuid, array $orgunits, array $people): array {
        global $DB;
        $wanted = [];
        foreach ($people as $person) {
            if ($person['unit'] !== '') {
                $wanted[$person['unit']] = true;
            }
        }
        $names = [];
        foreach ($orgunits as $orgunit) {
            $uuid = (string)($orgunit['uuid'] ?? '');
            if (isset($wanted[$uuid])) {
                $names[$uuid] = (string)$orgunit['name'];
            }
        }
        $missing = array_keys(array_diff_key($wanted, $names));
        if ($missing) {
            [$insql, $inparams] = $DB->get_in_or_equal($missing, SQL_PARAMS_NAMED, 'tn');
            foreach ($DB->get_records_select('local_kbacompany_orgnode', "companyuuid = :c AND uuid {$insql}",
                    ['c' => $companyuuid] + $inparams, '', 'uuid, name') as $node) {
                $names[(string)$node->uuid] = (string)$node->name;
            }
        }
        foreach (array_keys($wanted) as $uuid) {
            $names[$uuid] = $names[$uuid] ?? $uuid;
        }
        \core_collator::asort($names);
        return $names;
    }

    /**
     * R5b: [userid => [due state, deadline]] for the listed people. Lateness counts from the first submitted version
     * of the report (company and reports in SQL); the deadline uses each employee's own time zone.
     */
    private static function schedule(string $companyuuid, array $userids, string $day, array $reports): array {
        global $DB;
        $reasons = daily_report_calendar_service::requirements($companyuuid, $userids, $day);
        $first = [];
        $uuids = [];
        foreach ($reports as $report) {
            $uuids[] = (string)$report->uuid;
        }
        if ($uuids && in_array('workday', $reasons, true)) {
            [$insql, $inparams] = $DB->get_in_or_equal($uuids, SQL_PARAMS_NAMED, 'tf');
            foreach ($DB->get_records_sql("SELECT reportuuid, MIN(submittedat) AS firstsubmitted
                    FROM {local_kbacompany_dreportver}
                   WHERE companyuuid = :c AND submittedat > 0 AND reportuuid {$insql}
                GROUP BY reportuuid", ['c' => $companyuuid] + $inparams) as $row) {
                $first[(string)$row->reportuuid] = (int)$row->firstsubmitted;
            }
        }
        $zones = [];
        $required = array_keys(array_filter($reasons, static function(string $reason): bool {
            return $reason === 'workday';
        }));
        if ($required) {
            foreach ($DB->get_records_list('user', 'id', $required, '', 'id, timezone') as $user) {
                $zones[(int)$user->id] = \core_date::get_user_timezone($user);
            }
        }
        $deadlines = [];
        $now = time();
        $out = [];
        foreach ($userids as $userid) {
            $reason = $reasons[$userid] ?? 'disabled';
            $deadline = 0;
            if ($reason === 'workday') {
                $zone = $zones[$userid] ?? \core_date::get_server_timezone();
                $deadline = $deadlines[$zone] ?? ($deadlines[$zone] = daily_report_calendar_service::deadline($companyuuid,
                    $day, $zone));
            }
            $report = $reports[$userid] ?? null;
            $firstsubmitted = $report ? ($first[(string)$report->uuid] ?? 0) : 0;
            $out[$userid] = [daily_report_calendar_service::due($reason, $firstsubmitted, $deadline, $now), $deadline];
        }
        return $out;
    }

    /** Rating of the last decision on each accepted current version (append-only rows, the latest wins). */
    private static function ratings(string $companyuuid, array $reports): array {
        global $DB;
        $versions = [];
        foreach ($reports as $report) {
            if ((string)$report->status === 'accepted' && (string)$report->currentversionuuid !== '') {
                $versions[] = (string)$report->currentversionuuid;
            }
        }
        if (!$versions) {
            return [];
        }
        [$insql, $inparams] = $DB->get_in_or_equal($versions, SQL_PARAMS_NAMED, 'tv');
        $out = [];
        foreach ($DB->get_records_select('local_kbacompany_dreportrev', "companyuuid = :c AND versionuuid {$insql}",
                ['c' => $companyuuid] + $inparams, 'id ASC', 'id, versionuuid, decision, rating') as $row) {
            $out[(string)$row->versionuuid] = (string)$row->decision === 'accepted' ? (string)$row->rating : '';
        }
        return $out;
    }
}
