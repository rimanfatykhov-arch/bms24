<?php
namespace local_kbacorporate\local;

defined('MOODLE_INTERNAL') || die();

/**
 * Company-scoped employee assessment history and dynamics projection.
 *
 * The service reuses the unified competency profile and its normalised history
 * events. It never changes Moodle competency grades, audit items, course
 * results or development plans.
 */
class employee_assessment_history {
    public const SCOPE_PROFILE = 'profile';
    public const SCOPE_ALL = 'all';

    public const SOURCE_ALL = 'all';
    public const STATUS_ALL = 'all';
    public const TREND_ALL = 'all';

    public const TREND_IMPROVED = 'improved';
    public const TREND_UNCHANGED = 'unchanged';
    public const TREND_DECLINED = 'declined';
    public const TREND_SINGLE = 'single';
    public const TREND_NO_COMPARISON = 'nocomparison';

    /**
     * Build the complete company-scoped assessment history for one employee.
     *
     * @param int $companyid Company id.
     * @param int $userid Employee user id.
     * @return \stdClass
     */
    public static function build(int $companyid, int $userid): \stdClass {
        $profile = employee_competency_profile::build($companyid, $userid);
        if (!$profile->available || !$profile->rows) {
            return (object)[
                'companyid' => $companyid,
                'userid' => $userid,
                'profile' => $profile,
                'events' => [],
                'trends' => [],
                'summary' => self::summarise([], []),
                'available' => (bool)$profile->available,
            ];
        }

        $competencyids = [];
        $rowmap = [];
        foreach ($profile->rows as $row) {
            $competencyid = (int)$row->competencyid;
            $competencyids[] = $competencyid;
            $rowmap[$competencyid] = $row;
        }

        $historymap = employee_competency_profile::history_map(
            $companyid, $userid, $competencyids
        );
        $events = [];
        foreach ($historymap as $competencyid => $history) {
            $competencyid = (int)$competencyid;
            if (!isset($rowmap[$competencyid])) {
                continue;
            }
            $row = $rowmap[$competencyid];
            foreach ($history as $event) {
                if ((string)$event->kind !== 'assessment') {
                    continue;
                }
                $copy = clone $event;
                $copy->competencyid = $competencyid;
                $copy->competencyshortname = (string)$row->shortname;
                $copy->competencyidnumber = (string)$row->idnumber;
                $copy->frameworkname = (string)$row->frameworkname;
                $copy->targeted = !empty($row->targeted);
                $copy->targetlevel = $row->targetlevel === null ? null : (int)$row->targetlevel;
                $copy->currentlevel = $row->currentlevel === null ? null : (int)$row->currentlevel;
                $copy->gap = $row->gap === null ? null : (int)$row->gap;
                $copy->required = !empty($row->required);
                $copy->priority = (string)$row->priority;
                $copy->roles = array_values((array)$row->roles);
                $copy->scaleid = (int)($row->competencyscaleid ?: $row->frameworkscaleid);
                $events[] = $copy;
            }
        }

        $events = self::deduplicate_assessments($events);
        $trends = self::build_trends($events);
        $trendmap = [];
        foreach ($trends as $trend) {
            $trendmap[(int)$trend->competencyid] = (string)$trend->trend;
        }
        foreach ($events as $event) {
            $event->trend = $trendmap[(int)$event->competencyid] ?? self::TREND_NO_COMPARISON;
        }

        return (object)[
            'companyid' => $companyid,
            'userid' => $userid,
            'profile' => $profile,
            'events' => $events,
            'trends' => $trends,
            'summary' => self::summarise($events, $trends),
            'available' => true,
        ];
    }

    /**
     * Remove duplicated synthetic current-grade events while keeping explicit
     * audit/AI/Moodle evidence assessment records.
     *
     * @param array $events Prepared assessment events.
     * @return array
     */
    public static function deduplicate_assessments(array $events): array {
        usort($events, [self::class, 'compare_events_desc']);

        $explicit = [];
        foreach ($events as $event) {
            if (strpos((string)$event->id, 'moodle-current-') !== 0) {
                $explicit[] = $event;
            }
        }

        $result = [];
        $seen = [];
        foreach ($events as $event) {
            $id = (string)$event->id;
            if ($id !== '' && isset($seen[$id])) {
                continue;
            }
            if (strpos($id, 'moodle-current-') === 0 && $event->level !== null) {
                $duplicate = false;
                foreach ($explicit as $candidate) {
                    if ((int)$candidate->competencyid !== (int)$event->competencyid ||
                            $candidate->level === null ||
                            (int)$candidate->level !== (int)$event->level) {
                        continue;
                    }
                    if (!self::is_comparable_status((string)$candidate->status)) {
                        continue;
                    }
                    if (abs((int)$candidate->timecreated - (int)$event->timecreated) <= 300) {
                        $duplicate = true;
                        break;
                    }
                }
                if ($duplicate) {
                    continue;
                }
            }
            if ($id !== '') {
                $seen[$id] = true;
            }
            $result[] = $event;
        }
        usort($result, [self::class, 'compare_events_desc']);
        return $result;
    }

    /**
     * Filter assessment events without changing the source array.
     *
     * @param array $events Prepared events.
     * @param string $scope profile|all.
     * @param string $source Source family or all.
     * @param string $status Event status or all.
     * @param string $trend Trend family or all.
     * @param int $fromtime Inclusive lower timestamp, 0 disables.
     * @param int $totime Inclusive upper timestamp, 0 disables.
     * @param string $query Text query.
     * @return array
     */
    public static function filter(array $events, string $scope = self::SCOPE_PROFILE,
            string $source = self::SOURCE_ALL, string $status = self::STATUS_ALL,
            string $trend = self::TREND_ALL, int $fromtime = 0, int $totime = 0,
            string $query = ''): array {
        $allowedscope = [self::SCOPE_PROFILE, self::SCOPE_ALL];
        $allowedsources = [self::SOURCE_ALL, employee_competency_profile::SOURCE_AUDIT,
            employee_competency_profile::SOURCE_AI, employee_competency_profile::SOURCE_MOODLE];
        $allowedstatuses = [self::STATUS_ALL, 'confirmed', 'recorded', 'pending', 'rejected', 'missing'];
        $allowedtrends = [self::TREND_ALL, self::TREND_IMPROVED, self::TREND_UNCHANGED,
            self::TREND_DECLINED, self::TREND_SINGLE, self::TREND_NO_COMPARISON];
        if (!in_array($scope, $allowedscope, true)) {
            $scope = self::SCOPE_PROFILE;
        }
        if (!in_array($source, $allowedsources, true)) {
            $source = self::SOURCE_ALL;
        }
        if (!in_array($status, $allowedstatuses, true)) {
            $status = self::STATUS_ALL;
        }
        if (!in_array($trend, $allowedtrends, true)) {
            $trend = self::TREND_ALL;
        }
        $query = \core_text::strtolower(trim($query));

        return array_values(array_filter($events, static function($event) use (
                $scope, $source, $status, $trend, $fromtime, $totime, $query) {
            if ($scope === self::SCOPE_PROFILE && empty($event->targeted)) {
                return false;
            }
            if ($source !== self::SOURCE_ALL && (string)$event->source !== $source) {
                return false;
            }
            if ($status !== self::STATUS_ALL && (string)$event->status !== $status) {
                return false;
            }
            if ($trend !== self::TREND_ALL && (string)($event->trend ?? '') !== $trend) {
                return false;
            }
            if ($fromtime > 0 && (int)$event->timecreated < $fromtime) {
                return false;
            }
            if ($totime > 0 && (int)$event->timecreated > $totime) {
                return false;
            }
            if ($query !== '') {
                $roles = implode(' ', array_map(static function($role) {
                    return (string)($role->name ?? '') . ' ' . (string)($role->code ?? '');
                }, (array)($event->roles ?? [])));
                $haystack = \core_text::strtolower(trim(
                    (string)($event->competencyshortname ?? '') . ' ' .
                    (string)($event->competencyidnumber ?? '') . ' ' .
                    (string)($event->frameworkname ?? '') . ' ' .
                    (string)($event->title ?? '') . ' ' .
                    (string)($event->details ?? '') . ' ' .
                    (string)($event->actorname ?? '') . ' ' . $roles
                ));
                if (\core_text::strpos($haystack, $query) === false) {
                    return false;
                }
            }
            return true;
        }));
    }

    /**
     * Build first-to-latest assessment dynamics for each competency.
     *
     * Only confirmed or recorded levels participate in the comparison. Pending,
     * missing and rejected rows remain visible in the event journal but do not
     * change the calculated trajectory.
     *
     * @param array $events Prepared assessment events.
     * @return array
     */
    public static function build_trends(array $events): array {
        $grouped = [];
        foreach ($events as $event) {
            $competencyid = (int)$event->competencyid;
            if (!isset($grouped[$competencyid])) {
                $grouped[$competencyid] = [];
            }
            $grouped[$competencyid][] = $event;
        }

        $trends = [];
        foreach ($grouped as $competencyid => $items) {
            usort($items, [self::class, 'compare_events_asc']);
            $comparable = array_values(array_filter($items, static function($event) {
                return $event->level !== null && self::is_comparable_status((string)$event->status);
            }));
            $first = $comparable ? reset($comparable) : null;
            $latest = $comparable ? end($comparable) : null;
            $trend = self::TREND_NO_COMPARISON;
            $delta = null;
            if (count($comparable) === 1) {
                $trend = self::TREND_SINGLE;
                $delta = 0;
            } else if (count($comparable) > 1) {
                $delta = (int)$latest->level - (int)$first->level;
                if ($delta > 0) {
                    $trend = self::TREND_IMPROVED;
                } else if ($delta < 0) {
                    $trend = self::TREND_DECLINED;
                } else {
                    $trend = self::TREND_UNCHANGED;
                }
            }
            $metadata = $latest ?: end($items);
            $trends[] = (object)[
                'competencyid' => $competencyid,
                'competencyshortname' => (string)($metadata->competencyshortname ?? ''),
                'competencyidnumber' => (string)($metadata->competencyidnumber ?? ''),
                'frameworkname' => (string)($metadata->frameworkname ?? ''),
                'targeted' => !empty($metadata->targeted),
                'required' => !empty($metadata->required),
                'priority' => (string)($metadata->priority ?? business_roles::PRIORITY_ADDITIONAL),
                'targetlevel' => $metadata->targetlevel === null ? null : (int)$metadata->targetlevel,
                'currentlevel' => $metadata->currentlevel === null ? null : (int)$metadata->currentlevel,
                'gap' => $metadata->gap === null ? null : (int)$metadata->gap,
                'scaleid' => (int)($metadata->scaleid ?? 0),
                'trend' => $trend,
                'delta' => $delta,
                'firstlevel' => $first ? (int)$first->level : null,
                'latestlevel' => $latest ? (int)$latest->level : null,
                'firsttime' => $first ? (int)$first->timecreated : 0,
                'latesttime' => $latest ? (int)$latest->timecreated : 0,
                'latestsource' => $latest ? (string)$latest->source : employee_competency_profile::SOURCE_NONE,
                'latestactorname' => $latest ? (string)$latest->actorname : '',
                'comparableevents' => count($comparable),
                'totalevents' => count($items),
            ];
        }

        usort($trends, static function($a, $b) {
            $rank = [
                self::TREND_DECLINED => 1,
                self::TREND_IMPROVED => 2,
                self::TREND_UNCHANGED => 3,
                self::TREND_SINGLE => 4,
                self::TREND_NO_COMPARISON => 5,
            ];
            $comparison = ($rank[$a->trend] ?? 9) <=> ($rank[$b->trend] ?? 9);
            if ($comparison !== 0) {
                return $comparison;
            }
            $comparison = ((int)$b->latesttime) <=> ((int)$a->latesttime);
            if ($comparison !== 0) {
                return $comparison;
            }
            return strcasecmp((string)$a->competencyshortname, (string)$b->competencyshortname);
        });
        return $trends;
    }

    /** Filter prepared trend rows. */
    public static function filter_trends(array $trends, string $trend = self::TREND_ALL): array {
        $allowed = [self::TREND_ALL, self::TREND_IMPROVED, self::TREND_UNCHANGED,
            self::TREND_DECLINED, self::TREND_SINGLE, self::TREND_NO_COMPARISON];
        if (!in_array($trend, $allowed, true) || $trend === self::TREND_ALL) {
            return array_values($trends);
        }
        return array_values(array_filter($trends, static function($row) use ($trend) {
            return (string)$row->trend === $trend;
        }));
    }

    /** Summarise assessment events and competency dynamics. */
    public static function summarise(array $events, array $trends): \stdClass {
        $summary = (object)[
            'events' => count($events),
            'competencies' => 0,
            'confirmed' => 0,
            'recorded' => 0,
            'pending' => 0,
            'rejected' => 0,
            'missing' => 0,
            'assessors' => 0,
            'firsttime' => 0,
            'latesttime' => 0,
            'improved' => 0,
            'unchanged' => 0,
            'declined' => 0,
            'single' => 0,
            'nocomparison' => 0,
        ];
        $competencies = [];
        $assessors = [];
        foreach ($events as $event) {
            $competencies[(int)$event->competencyid] = true;
            $status = (string)$event->status;
            if (property_exists($summary, $status)) {
                $summary->{$status}++;
            }
            if ((int)$event->actorid > 0) {
                $assessors['id:' . (int)$event->actorid] = true;
            } else if (trim((string)$event->actorname) !== '') {
                $assessors['name:' . \core_text::strtolower(trim((string)$event->actorname))] = true;
            }
            $time = (int)$event->timecreated;
            if ($time > 0 && ($summary->firsttime === 0 || $time < $summary->firsttime)) {
                $summary->firsttime = $time;
            }
            if ($time > $summary->latesttime) {
                $summary->latesttime = $time;
            }
        }
        $summary->competencies = count($competencies);
        $summary->assessors = count($assessors);
        foreach ($trends as $trend) {
            $key = (string)$trend->trend;
            if (property_exists($summary, $key)) {
                $summary->{$key}++;
            }
        }
        return $summary;
    }

    /** Parse YYYY-MM-DD using the current Moodle user's timezone. */
    public static function parse_date(string $value, bool $endofday = false): int {
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

    /** Human-safe value for CSV output. */
    public static function csv_value($value): string {
        $value = trim(html_to_text((string)$value, 0, false));
        return preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $value);
    }

    /** Statuses which represent an accepted level for longitudinal comparison. */
    private static function is_comparable_status(string $status): bool {
        return in_array($status, ['confirmed', 'recorded'], true);
    }

    /** Descending event sort. */
    private static function compare_events_desc($a, $b): int {
        $comparison = ((int)$b->timecreated) <=> ((int)$a->timecreated);
        if ($comparison !== 0) {
            return $comparison;
        }
        return strcasecmp((string)$a->id, (string)$b->id);
    }

    /** Ascending event sort. */
    private static function compare_events_asc($a, $b): int {
        $comparison = ((int)$a->timecreated) <=> ((int)$b->timecreated);
        if ($comparison !== 0) {
            return $comparison;
        }
        return strcasecmp((string)$a->id, (string)$b->id);
    }
}
