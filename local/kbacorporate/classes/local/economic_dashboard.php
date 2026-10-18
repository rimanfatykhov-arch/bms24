<?php
// This file is part of Moodle - http://moodle.org/

namespace local_kbacorporate\local;

defined('MOODLE_INTERNAL') || die();

/**
 * Read-only economic indicator dashboard model.
 *
 * The service keeps chart preparation outside the page controller and never
 * changes indicator definitions or values. It deliberately treats monetary
 * values with different currencies as non-comparable.
 */
final class economic_dashboard {
    /** Maximum number of charts rendered at once. */
    public const MAX_INDICATORS = 8;

    /** Default number of indicators shown when the user has not selected any. */
    public const DEFAULT_INDICATORS = 6;

    /** Maximum number of historical actual points in one trend chart. */
    public const MAX_TREND_POINTS = 18;

    /** Preferred business indicators for the initial dashboard. */
    private const PREFERRED_CODES = [
        'REV',
        'PROFIT',
        'MARGIN',
        'CLIENTS',
        'AVG_CHECK',
        'REVENUE_PER_EMPLOYEE',
        'DEBT',
        'TURNOVER',
    ];

    /** Default start date: first day of the month eleven months ago. */
    public static function default_from(): int {
        $now = usergetdate(time());
        $start = make_timestamp((int)$now['year'], (int)$now['mon'], 1, 0, 0, 0);
        return strtotime('-11 months', $start);
    }

    /** Default end date: last second of the current calendar year. */
    public static function default_to(): int {
        $now = usergetdate(time());
        return make_timestamp((int)$now['year'] + 1, 1, 1, 0, 0, 0) - 1;
    }

    /**
     * Parse a YYYY-MM-DD date input without silently accepting malformed dates.
     */
    public static function parse_date_input(string $value, bool $endofday = false): int {
        $value = trim($value);
        if ($value === '' || !preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $value, $matches)) {
            return 0;
        }
        $year = (int)$matches[1];
        $month = (int)$matches[2];
        $day = (int)$matches[3];
        if (!checkdate($month, $day, $year)) {
            return 0;
        }
        return make_timestamp($year, $month, $day,
            $endofday ? 23 : 0, $endofday ? 59 : 0, $endofday ? 59 : 0);
    }

    /**
     * Build the complete dashboard model.
     *
     * Supported filters: category, periodtype, from, to, verifiedonly,
     * indicatorids, auditid, datesexplicit.
     */
    public static function build(int $companyid, array $filters = []): \stdClass {
        $category = trim((string)($filters['category'] ?? ''));
        if (!in_array($category, economic_indicators::categories(), true)) {
            $category = '';
        }
        $periodtype = trim((string)($filters['periodtype'] ?? ''));
        if (!in_array($periodtype, economic_indicators::period_types(), true)) {
            $periodtype = '';
        }
        $from = max(0, (int)($filters['from'] ?? 0));
        $to = max(0, (int)($filters['to'] ?? 0));
        $datesexplicit = !empty($filters['datesexplicit']);
        if ($from <= 0) {
            $from = self::default_from();
        }
        if ($to <= 0) {
            $to = self::default_to();
        }
        if ($to < $from) {
            $swap = $from;
            $from = $to;
            $to = $swap;
        }
        $verifiedonly = !empty($filters['verifiedonly']);
        $auditid = max(0, (int)($filters['auditid'] ?? 0));
        $requestedids = self::normalise_indicator_ids($filters['indicatorids'] ?? []);

        $catalogfilters = ['active' => 1];
        if ($category !== '') {
            $catalogfilters['category'] = $category;
        }
        $catalog = economic_indicators::get_catalog($companyid, $catalogfilters);
        $catalogbyid = [];
        foreach ($catalog as $indicator) {
            $catalogbyid[(int)$indicator->id] = $indicator;
        }

        $valuefilters = [
            'indicatoractive' => 1,
            'from' => $from,
            'to' => $to,
        ];
        if ($category !== '') {
            $valuefilters['category'] = $category;
        }
        if ($periodtype !== '') {
            $valuefilters['periodtype'] = $periodtype;
        }
        if ($verifiedonly) {
            $valuefilters['verified'] = 1;
        }
        $values = economic_indicators::get_values($companyid, $valuefilters);

        // Values explicitly linked to an audit are always considered when the
        // initial KPI set is chosen. When dates were not selected manually,
        // include those values in the visible range as well so that opening the
        // dashboard from an older audit does not hide its economic snapshot.
        $auditvalues = [];
        if ($auditid > 0) {
            $auditvaluefilters = [
                'auditid' => $auditid,
                'indicatoractive' => 1,
            ];
            if ($category !== '') {
                $auditvaluefilters['category'] = $category;
            }
            if ($periodtype !== '') {
                $auditvaluefilters['periodtype'] = $periodtype;
            }
            if ($verifiedonly) {
                $auditvaluefilters['verified'] = 1;
            }
            $auditvalues = economic_indicators::get_values($companyid, $auditvaluefilters);
        }

        // A first visit should still show historical data when the company has
        // no values in the default time window. Explicit date filters never
        // trigger this fallback.
        if (!$values && !$datesexplicit) {
            unset($valuefilters['from'], $valuefilters['to']);
            $values = economic_indicators::get_values($companyid, $valuefilters);
            if ($values) {
                $periodstarts = array_map(static function($value) {
                    return (int)$value->periodstart;
                }, $values);
                $periodends = array_map(static function($value) {
                    return (int)$value->periodend;
                }, $values);
                $from = min($periodstarts);
                $to = max($periodends);
            }
        } else if ($auditvalues && !$datesexplicit) {
            $valuesbyid = [];
            foreach (array_merge($values, $auditvalues) as $value) {
                $valuesbyid[(int)$value->id] = $value;
            }
            $values = array_values($valuesbyid);
            $periodstarts = array_map(static function($value) {
                return (int)$value->periodstart;
            }, $auditvalues);
            $periodends = array_map(static function($value) {
                return (int)$value->periodend;
            }, $auditvalues);
            $from = min($from, min($periodstarts));
            $to = max($to, max($periodends));
        }

        $selectionvalues = $values;
        if ($auditvalues) {
            $selectionbyid = [];
            foreach (array_merge($selectionvalues, $auditvalues) as $value) {
                $selectionbyid[(int)$value->id] = $value;
            }
            $selectionvalues = array_values($selectionbyid);
        }
        $selectedids = self::select_indicator_ids($catalog, $selectionvalues, $requestedids, $auditid);
        $valuesbyindicator = [];
        foreach ($values as $value) {
            $indicatorid = (int)$value->indicatorid;
            if (!in_array($indicatorid, $selectedids, true)) {
                continue;
            }
            if (!isset($valuesbyindicator[$indicatorid])) {
                $valuesbyindicator[$indicatorid] = [];
            }
            $valuesbyindicator[$indicatorid][] = $value;
        }

        $panels = [];
        foreach ($selectedids as $indicatorid) {
            if (!isset($catalogbyid[$indicatorid])) {
                continue;
            }
            $panels[] = self::build_indicator_panel(
                $catalogbyid[$indicatorid],
                $valuesbyindicator[$indicatorid] ?? []
            );
        }

        $totalpoints = 0;
        $actualpoints = 0;
        $verifiedpoints = 0;
        $indicatorswithactual = 0;
        foreach ($panels as $panel) {
            $totalpoints += (int)$panel->pointcount;
            $actualpoints += (int)$panel->actualcount;
            $verifiedpoints += (int)$panel->verifiedcount;
            if ($panel->latestactual) {
                $indicatorswithactual++;
            }
        }

        $selectedcodes = [];
        foreach ($selectedids as $indicatorid) {
            if (isset($catalogbyid[$indicatorid])) {
                $selectedcodes[] = (string)$catalogbyid[$indicatorid]->code;
            }
        }
        $auditcontext = self::build_audit_context($companyid, $auditid, $selectedcodes);

        return (object)[
            'companyid' => $companyid,
            'category' => $category,
            'periodtype' => $periodtype,
            'from' => $from,
            'to' => $to,
            'verifiedonly' => $verifiedonly,
            'auditid' => $auditid,
            'catalog' => $catalog,
            'selectedids' => $selectedids,
            'requestedcount' => count($requestedids),
            'selectiontruncated' => count($requestedids) > self::MAX_INDICATORS,
            'panels' => $panels,
            'auditcontext' => $auditcontext,
            'summary' => (object)[
                'indicators' => count($panels),
                'indicatorswithactual' => $indicatorswithactual,
                'totalpoints' => $totalpoints,
                'actualpoints' => $actualpoints,
                'verifiedpoints' => $verifiedpoints,
                'verifiedshare' => $totalpoints > 0
                    ? round($verifiedpoints / $totalpoints * 100, 1) : 0.0,
                'coverage' => count($panels) > 0
                    ? round($indicatorswithactual / count($panels) * 100, 1) : 0.0,
            ],
        ];
    }

    /**
     * Build one indicator panel from already authorised value records.
     */
    public static function build_indicator_panel(\stdClass $indicator, array $values): \stdClass {
        usort($values, static function($left, $right) {
            $leftkey = sprintf('%012d:%012d', (int)$left->periodend, (int)$left->id);
            $rightkey = sprintf('%012d:%012d', (int)$right->periodend, (int)$right->id);
            return strcmp($leftkey, $rightkey);
        });

        $byscenario = [
            economic_indicators::SCENARIO_ACTUAL => [],
            economic_indicators::SCENARIO_PLAN => [],
            economic_indicators::SCENARIO_FORECAST => [],
        ];
        foreach ($values as $value) {
            $scenario = (string)$value->scenario;
            if (!isset($byscenario[$scenario])) {
                continue;
            }
            $byscenario[$scenario][(string)$value->periodkey] = $value;
        }

        $actualrecords = array_values($byscenario[economic_indicators::SCENARIO_ACTUAL]);
        usort($actualrecords, static function($left, $right) {
            return ((int)$left->periodend <=> (int)$right->periodend) ?:
                ((int)$left->id <=> (int)$right->id);
        });
        if (count($actualrecords) > self::MAX_TREND_POINTS) {
            $actualrecords = array_slice($actualrecords, -self::MAX_TREND_POINTS);
        }

        $latestactual = $actualrecords ? end($actualrecords) : null;
        $previousactual = count($actualrecords) > 1
            ? $actualrecords[count($actualrecords) - 2] : null;
        $latestperiodkey = $latestactual ? (string)$latestactual->periodkey : '';
        if ($latestperiodkey === '') {
            $latestrecord = $values ? end($values) : null;
            $latestperiodkey = $latestrecord ? (string)$latestrecord->periodkey : '';
        }
        $latestplan = $latestperiodkey !== ''
            ? ($byscenario[economic_indicators::SCENARIO_PLAN][$latestperiodkey] ?? null) : null;
        $latestforecast = $latestperiodkey !== ''
            ? ($byscenario[economic_indicators::SCENARIO_FORECAST][$latestperiodkey] ?? null) : null;

        $direction = (string)($indicator->direction ?? economic_indicators::DIRECTION_TARGET);
        $trend = self::records_are_comparable($indicator, $latestactual, $previousactual)
            ? self::calculate_change(
                $latestactual ? (float)$latestactual->value : null,
                $previousactual ? (float)$previousactual->value : null,
                $direction
            )
            : self::calculate_change(null, null, $direction);
        $planvariance = self::records_are_comparable($indicator, $latestactual, $latestplan)
            ? self::calculate_change(
                $latestactual ? (float)$latestactual->value : null,
                $latestplan ? (float)$latestplan->value : null,
                $direction
            )
            : self::calculate_change(null, null, $direction);
        $forecastvariance = self::records_are_comparable($indicator, $latestactual, $latestforecast)
            ? self::calculate_change(
                $latestactual ? (float)$latestactual->value : null,
                $latestforecast ? (float)$latestforecast->value : null,
                $direction
            )
            : self::calculate_change(null, null, $direction);

        $currencies = [];
        if ((string)$indicator->valuetype === economic_indicators::TYPE_MONEY ||
                (string)$indicator->unit === 'currency') {
            foreach ($values as $value) {
                $currency = strtoupper(trim((string)$value->currency));
                if ($currency !== '') {
                    $currencies[$currency] = true;
                }
            }
        }
        $currencylist = array_keys($currencies);
        sort($currencylist);

        $chartlabels = [];
        $chartvalues = [];
        foreach ($actualrecords as $value) {
            $chartlabels[] = economic_indicators::period_label($value);
            $chartvalues[] = (float)$value->value;
        }

        $scenariolabels = [];
        $scenariovalues = [];
        $scenariorecords = [];
        foreach ([
            economic_indicators::SCENARIO_ACTUAL => $latestactual,
            economic_indicators::SCENARIO_PLAN => $latestplan,
            economic_indicators::SCENARIO_FORECAST => $latestforecast,
        ] as $scenario => $record) {
            if (!$record) {
                continue;
            }
            $scenariolabels[] = $scenario;
            $scenariovalues[] = (float)$record->value;
            $scenariorecords[$scenario] = $record;
        }

        $verifiedcount = 0;
        $sources = [];
        $lastupdated = 0;
        foreach ($values as $value) {
            if (!empty($value->verified)) {
                $verifiedcount++;
            }
            $source = trim((string)$value->source);
            if ($source !== '') {
                $sources[$source] = true;
            }
            $lastupdated = max($lastupdated, (int)$value->timemodified);
        }

        return (object)[
            'indicator' => $indicator,
            'values' => array_values($values),
            'pointcount' => count($values),
            'actualcount' => count($byscenario[economic_indicators::SCENARIO_ACTUAL]),
            'plancount' => count($byscenario[economic_indicators::SCENARIO_PLAN]),
            'forecastcount' => count($byscenario[economic_indicators::SCENARIO_FORECAST]),
            'verifiedcount' => $verifiedcount,
            'verifiedshare' => count($values) > 0
                ? round($verifiedcount / count($values) * 100, 1) : 0.0,
            'sources' => array_keys($sources),
            'lastupdated' => $lastupdated,
            'latestactual' => $latestactual ?: null,
            'previousactual' => $previousactual ?: null,
            'latestplan' => $latestplan ?: null,
            'latestforecast' => $latestforecast ?: null,
            'latestperiodkey' => $latestperiodkey,
            'trend' => $trend,
            'planvariance' => $planvariance,
            'forecastvariance' => $forecastvariance,
            'currencies' => $currencylist,
            'mixedcurrencies' => count($currencylist) > 1,
            'chartlabels' => $chartlabels,
            'chartvalues' => $chartvalues,
            'scenariolabels' => $scenariolabels,
            'scenariovalues' => $scenariovalues,
            'scenariorecords' => $scenariorecords,
        ];
    }


    /** Monetary records are comparable only when their currencies match. */
    private static function records_are_comparable(\stdClass $indicator, $current, $reference): bool {
        if (!$current || !$reference) {
            return false;
        }
        $ismoney = (string)$indicator->valuetype === economic_indicators::TYPE_MONEY ||
            (string)$indicator->unit === 'currency';
        return !$ismoney || (string)$current->currency === (string)$reference->currency;
    }

    /**
     * Calculate a direction-aware delta without claiming causality.
     */
    public static function calculate_change(?float $current, ?float $reference,
            string $direction): \stdClass {
        if ($current === null || $reference === null) {
            return (object)[
                'available' => false,
                'delta' => null,
                'percent' => null,
                'state' => 'unknown',
            ];
        }
        $delta = $current - $reference;
        $percent = abs($reference) > 0.0000001
            ? round($delta / abs($reference) * 100, 2) : null;
        if (abs($delta) < 0.0000001) {
            $state = 'neutral';
        } else if ($direction === economic_indicators::DIRECTION_UP) {
            $state = $delta > 0 ? 'positive' : 'negative';
        } else if ($direction === economic_indicators::DIRECTION_DOWN) {
            $state = $delta < 0 ? 'positive' : 'negative';
        } else {
            $state = 'neutral';
        }
        return (object)[
            'available' => true,
            'delta' => round($delta, 6),
            'percent' => $percent,
            'state' => $state,
        ];
    }

    /**
     * Choose a compact, deterministic set of indicators for the dashboard.
     *
     * Explicit selections are honoured first. Otherwise audit-linked and
     * preferred indicators with data are prioritised before the remaining
     * catalogue entries.
     */
    public static function select_indicator_ids(array $catalog, array $values,
            array $requestedids = [], int $auditid = 0): array {
        $catalogbyid = [];
        $catalogorder = [];
        $codes = [];
        foreach ($catalog as $indicator) {
            $id = (int)$indicator->id;
            $catalogbyid[$id] = $indicator;
            $catalogorder[] = $id;
            $codes[(string)$indicator->code] = $id;
        }

        $selected = [];
        foreach (self::normalise_indicator_ids($requestedids) as $id) {
            if (isset($catalogbyid[$id])) {
                $selected[] = $id;
            }
            if (count($selected) >= self::MAX_INDICATORS) {
                return $selected;
            }
        }
        if ($selected) {
            return $selected;
        }

        $hasvalues = [];
        $audithasvalues = [];
        foreach ($values as $value) {
            $indicatorid = (int)$value->indicatorid;
            if (!isset($catalogbyid[$indicatorid])) {
                continue;
            }
            $hasvalues[$indicatorid] = true;
            if ($auditid > 0 && (int)$value->auditid === $auditid) {
                $audithasvalues[$indicatorid] = true;
            }
        }

        $append = static function(int $id) use (&$selected, $catalogbyid): void {
            if (isset($catalogbyid[$id]) && !in_array($id, $selected, true)) {
                $selected[] = $id;
            }
        };
        foreach ($catalogorder as $id) {
            if (isset($audithasvalues[$id])) {
                $append($id);
            }
            if (count($selected) >= self::DEFAULT_INDICATORS) {
                return $selected;
            }
        }
        foreach (self::PREFERRED_CODES as $code) {
            if (isset($codes[$code]) && isset($hasvalues[$codes[$code]])) {
                $append($codes[$code]);
            }
            if (count($selected) >= self::DEFAULT_INDICATORS) {
                return $selected;
            }
        }
        foreach ($catalogorder as $id) {
            if (isset($hasvalues[$id])) {
                $append($id);
            }
            if (count($selected) >= self::DEFAULT_INDICATORS) {
                return $selected;
            }
        }
        foreach ($catalogorder as $id) {
            $append($id);
            if (count($selected) >= self::DEFAULT_INDICATORS) {
                break;
            }
        }
        return array_slice($selected, 0, self::MAX_INDICATORS);
    }

    /** Normalise and de-duplicate numeric indicator ids. */
    public static function normalise_indicator_ids($ids): array {
        if (!is_array($ids)) {
            $ids = [$ids];
        }
        $normalised = [];
        foreach ($ids as $id) {
            $id = (int)$id;
            if ($id > 0 && !in_array($id, $normalised, true)) {
                $normalised[] = $id;
            }
        }
        return $normalised;
    }

    /** Build an audit-linked economic snapshot and nearest previous context. */
    private static function build_audit_context(int $companyid, int $auditid,
            array $selectedcodes): ?\stdClass {
        global $DB;

        if ($auditid <= 0) {
            return null;
        }
        $audit = $DB->get_record('local_kbacorp_impl_audit', ['id' => $auditid], '*', MUST_EXIST);
        if ((int)$audit->companyid !== $companyid) {
            throw new \moodle_exception('companynotallowed', 'local_kbacorporate');
        }

        $previous = $DB->get_record_sql(
            "SELECT a.*
               FROM {local_kbacorp_impl_audit} a
              WHERE a.companyid = :companyid
                AND a.toolid = :toolid
                AND a.status = :status
                AND (a.auditdate < :auditdate OR
                    (a.auditdate = :auditdate2 AND a.id < :auditid))
           ORDER BY a.auditdate DESC, a.id DESC",
            [
                'companyid' => $companyid,
                'toolid' => (int)$audit->toolid,
                'status' => implementation_audits::STATUS_CONFIRMED,
                'auditdate' => (int)$audit->auditdate,
                'auditdate2' => (int)$audit->auditdate,
                'auditid' => (int)$audit->id,
            ],
            IGNORE_MULTIPLE
        );

        $currentvalues = economic_indicators::get_values($companyid, [
            'auditid' => $auditid,
            'scenario' => economic_indicators::SCENARIO_ACTUAL,
        ]);
        $previousvalues = $previous ? economic_indicators::get_values($companyid, [
            'auditid' => (int)$previous->id,
            'scenario' => economic_indicators::SCENARIO_ACTUAL,
        ]) : [];
        if ($selectedcodes) {
            $selectedmap = array_fill_keys($selectedcodes, true);
            $currentvalues = array_values(array_filter($currentvalues, static function($value) use ($selectedmap) {
                return isset($selectedmap[(string)$value->indicatorcode]);
            }));
            $previousvalues = array_values(array_filter($previousvalues, static function($value) use ($selectedmap) {
                return isset($selectedmap[(string)$value->indicatorcode]);
            }));
        }

        $comparison = implementation_audit_comparison::compare_economic_records(
            $previousvalues,
            $currentvalues
        );
        return (object)[
            'audit' => $audit,
            'previousaudit' => $previous ?: null,
            'currentvalues' => $currentvalues,
            'previousvalues' => $previousvalues,
            'comparison' => $comparison,
        ];
    }
}
