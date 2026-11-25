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
 */
final class daily_report_team_service {
    public const PAGE_SIZE = 50;
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
     *                'reviewerid', 'reviewersource', 'rating']]]
     */
    public static function day(string $companyuuid, int $actor, string $day, string $scope = '', string $unit = '',
            string $status = '', int $page = 0): array {
        global $DB;
        $companyuuid = company_context_service::normalise_uuid($companyuuid);
        company_context_service::context($companyuuid, $actor);
        daily_report_store_service::require_enabled();
        if (!daily_report_review_service::can_read_all($companyuuid, $actor)) {
            throw new \moodle_exception('dailyreports_notavailable', 'local_kbacompany');
        }
        daily_report_service::bounds($day);
        if (strcmp($day, business_date_service::day()) > 0) {
            throw new \moodle_exception('dailyreports_futureday', 'local_kbacompany');
        }
        if ($page < 0 || $page > 100000 || ($status !== '' && !in_array($status, self::STATUSES, true))) {
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

        // Reports of the day for exactly these people, company and day in SQL.
        $reports = [];
        if ($people) {
            [$insql, $inparams] = $DB->get_in_or_equal(array_keys($people), SQL_PARAMS_NAMED, 'tu');
            $reports = $DB->get_records_sql("SELECT r.userid, r.uuid, r.status, r.currentversionuuid,
                    v.submittedat, v.reviewerid, v.reviewersource
                FROM {local_kbacompany_dreport} r
           LEFT JOIN {local_kbacompany_dreportver} v ON v.companyuuid = r.companyuuid AND v.uuid = r.currentversionuuid
               WHERE r.companyuuid = :c AND r.reportday = :day AND r.userid {$insql}",
                ['c' => $companyuuid, 'day' => $day] + $inparams);
        }
        $ratings = self::ratings($companyuuid, $reports);

        $counts = array_fill_keys(self::STATUSES, 0);
        $rows = [];
        foreach ($people as $id => $person) {
            $report = $reports[$id] ?? null;
            $state = self::state($report);
            $counts[$state]++;
            if ($status !== '' && $state !== $status) {
                continue;
            }
            $sent = $report && !in_array($state, ['none', 'notsent'], true);
            $submittedat = $sent ? (int)$report->submittedat : 0;
            $rows[] = $person + [
                'state' => $state,
                // Drafts stay private (T2): no card link, no reviewer, no time.
                'reportuuid' => $sent ? (string)$report->uuid : '',
                'submittedat' => $submittedat,
                'late' => $submittedat > 0 && strcmp(business_date_service::day($submittedat), $day) > 0,
                'reviewerid' => $sent ? (int)$report->reviewerid : 0,
                'reviewersource' => $sent ? (string)$report->reviewersource : '',
                'rating' => $state === 'accepted' ? ($ratings[(string)$report->currentversionuuid] ?? '') : '',
            ];
        }
        usort($rows, static function(array $left, array $right): int {
            return strcasecmp($left['name'], $right['name']) ?: $left['userid'] <=> $right['userid'];
        });
        return ['day' => $day, 'scope' => $scope, 'scopes' => $scopes, 'unit' => $unit, 'units' => $units,
            'status' => $status, 'counts' => $counts, 'total' => count($rows), 'page' => $page,
            'items' => array_slice($rows, $page * self::PAGE_SIZE, self::PAGE_SIZE)];
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
