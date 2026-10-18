<?php
namespace local_kbacorporate\local;

defined('MOODLE_INTERNAL') || die();

/**
 * Corporate learning-time report.
 *
 * BigBlueButton time is read from BBB Summary duration data. For normal
 * asynchronous Moodle learning there is no authoritative "time on task"
 * field, so this class estimates active time from standard log interactions.
 * Consecutive events are grouped into sessions and long inactivity gaps are
 * not counted. The UI always labels this part as an estimate.
 */
class trainingtime {
    /** Maximum range accepted by the interactive report. */
    const MAX_PERIOD_DAYS = 366;

    /**
     * Whether Moodle standard logging is available.
     *
     * @return bool
     */
    public static function is_standard_log_available(): bool {
        global $DB;
        return $DB->get_manager()->table_exists('logstore_standard_log');
    }

    /**
     * Convert YYYY-MM-DD in the current user's timezone to a timestamp.
     *
     * @param string $date
     * @return int
     */
    public static function date_to_timestamp(string $date): int {
        if (!preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $date, $matches)) {
            return 0;
        }
        $year = (int)$matches[1];
        $month = (int)$matches[2];
        $day = (int)$matches[3];
        if (!checkdate($month, $day, $year)) {
            return 0;
        }
        return make_timestamp($year, $month, $day, 0, 0, 0, 99, false);
    }

    /**
     * Timestamp as YYYY-MM-DD in the current user's timezone.
     *
     * @param int $timestamp
     * @return string
     */
    public static function date_key(int $timestamp): string {
        $parts = usergetdate($timestamp);
        return sprintf('%04d-%02d-%02d', $parts['year'], $parts['mon'], $parts['mday']);
    }

    /**
     * Default report dates: current Monday through today.
     *
     * @return \stdClass
     */
    public static function default_period(): \stdClass {
        $now = time();
        $todayparts = usergetdate($now);
        $today = make_timestamp(
            $todayparts['year'], $todayparts['mon'], $todayparts['mday'], 0, 0, 0, 99, false
        );
        $isoday = ((int)$todayparts['wday'] === 0) ? 7 : (int)$todayparts['wday'];
        $start = $today - (($isoday - 1) * DAYSECS);
        return (object)[
            'from' => self::date_key($start),
            'to' => self::date_key($today),
        ];
    }


    /**
     * Resolve a named report period using the current user's timezone.
     *
     * Supported presets: week, month, quarter and custom. Named periods end
     * today rather than at a future calendar boundary, so plan/fact KPIs do
     * not penalise employees for days that have not happened yet.
     *
     * @param string $preset
     * @param string $from Custom start date.
     * @param string $to Custom end date.
     * @return \stdClass
     */
    public static function period_for_preset(string $preset, string $from = '', string $to = ''): \stdClass {
        $allowed = ['week', 'month', 'quarter', 'custom'];
        if (!in_array($preset, $allowed, true)) {
            $preset = 'week';
        }

        if ($preset === 'custom') {
            $period = self::normalize_period($from, $to);
            $period->preset = 'custom';
            return $period;
        }

        $now = time();
        $parts = usergetdate($now);
        $today = make_timestamp($parts['year'], $parts['mon'], $parts['mday'], 0, 0, 0, 99, false);
        if ($preset === 'week') {
            $isoday = ((int)$parts['wday'] === 0) ? 7 : (int)$parts['wday'];
            $start = $today - (($isoday - 1) * DAYSECS);
        } else if ($preset === 'month') {
            $start = make_timestamp($parts['year'], $parts['mon'], 1, 0, 0, 0, 99, false);
        } else {
            $quartermonth = ((int)floor(((int)$parts['mon'] - 1) / 3) * 3) + 1;
            $start = make_timestamp($parts['year'], $quartermonth, 1, 0, 0, 0, 99, false);
        }

        $period = self::normalize_period(self::date_key($start), self::date_key($today));
        $period->preset = $preset;
        return $period;
    }

    /**
     * Validate and normalize report range. End timestamp is exclusive.
     *
     * @param string $from
     * @param string $to
     * @return \stdClass
     */
    public static function normalize_period(string $from, string $to): \stdClass {
        $defaults = self::default_period();
        $start = self::date_to_timestamp($from);
        $endday = self::date_to_timestamp($to);
        if (!$start || !$endday || $endday < $start) {
            $from = $defaults->from;
            $to = $defaults->to;
            $start = self::date_to_timestamp($from);
            $endday = self::date_to_timestamp($to);
        }

        $maxend = $start + ((self::MAX_PERIOD_DAYS - 1) * DAYSECS);
        if ($endday > $maxend) {
            $endday = $maxend;
            $to = self::date_key($endday);
        }

        return (object)[
            'from' => $from,
            'to' => $to,
            'start' => $start,
            'endexclusive' => $endday + DAYSECS,
            'days' => (int)floor(($endday - $start) / DAYSECS) + 1,
        ];
    }

    /**
     * Build the complete corporate time report.
     *
     * @param int $companyid
     * @param int $start Inclusive timestamp.
     * @param int $endexclusive Exclusive timestamp.
     * @param int $userid Optional employee filter.
     * @return \stdClass
     */
    public static function build_report(int $companyid, int $start, int $endexclusive, int $userid = 0, ?array $userids = null): \stdClass {
        global $DB;

        $company = $DB->get_record('local_kbacorp_company', ['id' => $companyid], '*', MUST_EXIST);
        $employees = report::get_employees($companyid, $userids);
        if ($userid) {
            $employees = array_filter($employees, function($employee) use ($userid) {
                return (int)$employee->id === $userid;
            });
        }

        $employeeids = array_map('intval', array_keys($employees));
        $allowed = self::allowed_user_courses($companyid, $employeeids);
        $courses = $allowed->courses;
        $pairs = $allowed->pairs;

        $asyncbydaycourse = [];
        $asyncavailable = self::is_standard_log_available();
        if ($asyncavailable && $employeeids && $courses) {
            $asyncbydaycourse = self::standard_log_time($employeeids, array_keys($courses), $pairs, $start, $endexclusive);
        }

        $bbbbydaycourse = [];
        $bbbavailable = bigbluebutton::is_available();
        if ($bbbavailable && $employeeids && $courses) {
            $bbbbydaycourse = self::bbb_time($companyid, $employeeids, $pairs, $start, $endexclusive);
        }

        $weekdays = array_values(array_filter(array_map('intval', explode(',', (string)$company->trainingweekdays))));
        $weekdayset = array_fill_keys($weekdays, true);
        $dailyplanminutes = max(0, (int)$company->trainingminutesday);

        $days = [];
        $employeeagg = [];
        $courseagg = [];
        foreach ($employees as $employee) {
            $employeeagg[(int)$employee->id] = (object)[
                'userid' => (int)$employee->id,
                'fullname' => fullname($employee),
                'position' => $employee->position ?? '',
                'planminutes' => 0.0,
                'asyncminutes' => 0.0,
                'bbbminutes' => 0.0,
                'totalminutes' => 0.0,
                'varianceminutes' => 0.0,
                'percent' => null,
                'planstate' => 'noplannedtime',
            ];
        }
        foreach ($courses as $courseid => $coursename) {
            $courseagg[(int)$courseid] = (object)[
                'courseid' => (int)$courseid,
                'coursename' => $coursename,
                'asyncminutes' => 0.0,
                'bbbminutes' => 0.0,
                'totalminutes' => 0.0,
                'sharepercent' => 0.0,
            ];
        }

        $cumulativeplan = 0.0;
        $cumulativefact = 0.0;
        for ($cursor = $start; $cursor < $endexclusive; $cursor += DAYSECS) {
            $datekey = self::date_key($cursor);
            $parts = usergetdate($cursor);
            $isoday = ((int)$parts['wday'] === 0) ? 7 : (int)$parts['wday'];
            $dayplanperemployee = isset($weekdayset[$isoday]) ? $dailyplanminutes : 0;
            $dayplan = $dayplanperemployee * count($employees);
            $dayasync = 0.0;
            $daybbb = 0.0;

            foreach ($employees as $employee) {
                $uid = (int)$employee->id;
                $employeeagg[$uid]->planminutes += $dayplanperemployee;
                foreach ($courses as $courseid => $coursename) {
                    if (empty($pairs[$uid][(int)$courseid])) {
                        continue;
                    }
                    $key = $uid . ':' . (int)$courseid . ':' . $datekey;
                    $async = isset($asyncbydaycourse[$key]) ? (float)$asyncbydaycourse[$key] : 0.0;
                    $bbb = isset($bbbbydaycourse[$key]) ? (float)$bbbbydaycourse[$key] : 0.0;
                    if ($async) {
                        $employeeagg[$uid]->asyncminutes += $async;
                        $courseagg[(int)$courseid]->asyncminutes += $async;
                        $dayasync += $async;
                    }
                    if ($bbb) {
                        $employeeagg[$uid]->bbbminutes += $bbb;
                        $courseagg[(int)$courseid]->bbbminutes += $bbb;
                        $daybbb += $bbb;
                    }
                }
            }

            $daytotal = $dayasync + $daybbb;
            $cumulativeplan += $dayplan;
            $cumulativefact += $daytotal;
            $days[] = (object)[
                'date' => $datekey,
                'timestamp' => $cursor,
                'planminutes' => round($dayplan, 1),
                'asyncminutes' => round($dayasync, 1),
                'bbbminutes' => round($daybbb, 1),
                'totalminutes' => round($daytotal, 1),
                'cumulativeplanminutes' => round($cumulativeplan, 1),
                'cumulativetotalminutes' => round($cumulativefact, 1),
            ];
        }

        $plantotal = 0.0;
        $asynctotal = 0.0;
        $bbbtotal = 0.0;
        $metplanemployees = 0;
        $belowplanemployees = 0;
        foreach ($employeeagg as $row) {
            $row->totalminutes = round($row->asyncminutes + $row->bbbminutes, 1);
            $row->planminutes = round($row->planminutes, 1);
            $row->asyncminutes = round($row->asyncminutes, 1);
            $row->bbbminutes = round($row->bbbminutes, 1);
            $row->varianceminutes = round($row->totalminutes - $row->planminutes, 1);
            $row->percent = $row->planminutes > 0 ? round(($row->totalminutes / $row->planminutes) * 100, 1) : null;
            if ($row->percent === null) {
                $row->planstate = 'noplannedtime';
            } else if ($row->percent >= 100) {
                $row->planstate = 'planmet';
                $metplanemployees++;
            } else {
                $row->planstate = 'planbelow';
                $belowplanemployees++;
            }
            $plantotal += $row->planminutes;
            $asynctotal += $row->asyncminutes;
            $bbbtotal += $row->bbbminutes;
        }
        $total = $asynctotal + $bbbtotal;
        foreach ($courseagg as $row) {
            $row->asyncminutes = round($row->asyncminutes, 1);
            $row->bbbminutes = round($row->bbbminutes, 1);
            $row->totalminutes = round($row->asyncminutes + $row->bbbminutes, 1);
            $row->sharepercent = $total > 0 ? round(($row->totalminutes / $total) * 100, 1) : 0.0;
        }

        uasort($employeeagg, function($a, $b) {
            return strcasecmp($a->fullname, $b->fullname);
        });
        uasort($courseagg, function($a, $b) {
            return strcasecmp($a->coursename, $b->coursename);
        });

        $weeks = self::aggregate_weeks($days);
        return (object)[
            'company' => $company,
            'employees' => array_values($employeeagg),
            'courses' => array_values($courseagg),
            'days' => $days,
            'weeks' => $weeks,
            'summary' => (object)[
                'employees' => count($employees),
                'planminutes' => round($plantotal, 1),
                'asyncminutes' => round($asynctotal, 1),
                'bbbminutes' => round($bbbtotal, 1),
                'totalminutes' => round($total, 1),
                'varianceminutes' => round($total - $plantotal, 1),
                'percent' => $plantotal > 0 ? round(($total / $plantotal) * 100, 1) : null,
                'avgminutes' => count($employees) ? round($total / count($employees), 1) : 0.0,
                'metplanemployees' => $metplanemployees,
                'belowplanemployees' => $belowplanemployees,
            ],
            'asyncavailable' => $asyncavailable,
            'bbbavailable' => $bbbavailable,
            'dailyplanminutes' => $dailyplanminutes,
            'weekdays' => $weekdays,
        ];
    }

    /**
     * Aggregate daily report rows into Monday-based weekly dynamics.
     *
     * @param array $days
     * @return array
     */
    public static function aggregate_weeks(array $days): array {
        $weeks = [];
        foreach ($days as $day) {
            $parts = usergetdate((int)$day->timestamp);
            $isoday = ((int)$parts['wday'] === 0) ? 7 : (int)$parts['wday'];
            $weekstart = (int)$day->timestamp - (($isoday - 1) * DAYSECS);
            $key = self::date_key($weekstart);
            if (!isset($weeks[$key])) {
                $weeks[$key] = (object)[
                    'weekstart' => $weekstart,
                    'weekend' => (int)$day->timestamp,
                    'planminutes' => 0.0,
                    'asyncminutes' => 0.0,
                    'bbbminutes' => 0.0,
                    'totalminutes' => 0.0,
                    'varianceminutes' => 0.0,
                    'percent' => null,
                ];
            }
            $weeks[$key]->weekend = (int)$day->timestamp;
            $weeks[$key]->planminutes += (float)$day->planminutes;
            $weeks[$key]->asyncminutes += (float)$day->asyncminutes;
            $weeks[$key]->bbbminutes += (float)$day->bbbminutes;
            $weeks[$key]->totalminutes += (float)$day->totalminutes;
        }
        foreach ($weeks as $week) {
            $week->planminutes = round($week->planminutes, 1);
            $week->asyncminutes = round($week->asyncminutes, 1);
            $week->bbbminutes = round($week->bbbminutes, 1);
            $week->totalminutes = round($week->totalminutes, 1);
            $week->varianceminutes = round($week->totalminutes - $week->planminutes, 1);
            $week->percent = $week->planminutes > 0
                ? round(($week->totalminutes / $week->planminutes) * 100, 1)
                : null;
        }
        return array_values($weeks);
    }

    /**
     * Eligible user/course pairs respecting a company's optional group mappings.
     *
     * @param int $companyid
     * @param array $employeeids
     * @return \stdClass
     */
    private static function allowed_user_courses(int $companyid, array $employeeids): \stdClass {
        global $DB;

        $pairs = [];
        $courses = [];
        if (!$employeeids) {
            return (object)['pairs' => $pairs, 'courses' => $courses];
        }
        $employeeset = array_fill_keys(array_map('intval', $employeeids), true);
        $mappings = $DB->get_records_sql(
            "SELECT cc.id, cc.courseid, cc.groupid, c.fullname
               FROM {local_kbacorp_course} cc
               JOIN {course} c ON c.id = cc.courseid
              WHERE cc.companyid = :companyid AND cc.active = 1
           ORDER BY c.fullname",
            ['companyid' => $companyid]
        );
        foreach ($mappings as $mapping) {
            $courseid = (int)$mapping->courseid;
            $courses[$courseid] = format_string($mapping->fullname);
            if (!(int)$mapping->groupid) {
                foreach ($employeeids as $uid) {
                    $pairs[(int)$uid][$courseid] = true;
                }
                continue;
            }
            $members = $DB->get_fieldset_select('groups_members', 'userid', 'groupid = :groupid', [
                'groupid' => (int)$mapping->groupid,
            ]);
            foreach ($members as $uid) {
                $uid = (int)$uid;
                if (isset($employeeset[$uid])) {
                    $pairs[$uid][$courseid] = true;
                }
            }
        }
        return (object)['pairs' => $pairs, 'courses' => $courses];
    }

    /**
     * Estimate asynchronous active learning minutes from Moodle standard logs.
     *
     * @param array $employeeids
     * @param array $courseids
     * @param array $pairs
     * @param int $start
     * @param int $endexclusive
     * @return array keyed userid:courseid:YYYY-MM-DD, values minutes
     */
    private static function standard_log_time(
        array $employeeids, array $courseids, array $pairs, int $start, int $endexclusive
    ): array {
        global $DB;

        list($usersql, $userparams) = $DB->get_in_or_equal($employeeids, SQL_PARAMS_NAMED, 'ttu');
        list($coursesql, $courseparams) = $DB->get_in_or_equal($courseids, SQL_PARAMS_NAMED, 'ttc');
        $params = array_merge($userparams, $courseparams, [
            'timestart' => $start,
            'timeend' => $endexclusive,
            'bbbcomponent' => 'mod_bigbluebuttonbn',
        ]);
        $sql = "SELECT id, userid, courseid, timecreated, component, origin
                  FROM {logstore_standard_log}
                 WHERE userid $usersql
                   AND courseid $coursesql
                   AND timecreated >= :timestart
                   AND timecreated < :timeend
                   AND (component IS NULL OR component <> :bbbcomponent)
              ORDER BY userid, courseid, timecreated, id";
        $records = $DB->get_recordset_sql($sql, $params);
        $eventgroups = [];
        foreach ($records as $record) {
            $uid = (int)$record->userid;
            $courseid = (int)$record->courseid;
            if (empty($pairs[$uid][$courseid])) {
                continue;
            }
            // User-facing web and mobile/web-service requests are useful
            // signals. Ignore background CLI/restore events.
            if ($record->origin && $record->origin !== 'web' && $record->origin !== 'ws') {
                continue;
            }
            $key = $uid . ':' . $courseid . ':' . self::date_key((int)$record->timecreated);
            $eventgroups[$key][] = (int)$record->timecreated;
        }
        $records->close();

        $gapminutes = (int)get_config('local_kbacorporate', 'sessiongapminutes');
        $tailminutes = (int)get_config('local_kbacorporate', 'eventtailminutes');
        if ($gapminutes <= 0) {
            $gapminutes = 30;
        }
        if ($tailminutes <= 0) {
            $tailminutes = 5;
        }
        $gapminutes = min(120, max(5, $gapminutes));
        $tailminutes = min(30, max(1, $tailminutes));

        $result = [];
        foreach ($eventgroups as $key => $times) {
            $result[$key] = round(self::estimate_session_seconds($times, $gapminutes * 60, $tailminutes * 60) / 60, 1);
        }
        return $result;
    }

    /**
     * Estimate time represented by event timestamps.
     *
     * Each session receives a small tail allowance for reading/working after
     * its final logged action. Gaps above the inactivity threshold start a new
     * session instead of being counted as learning time.
     *
     * @param array $times
     * @param int $gaplimitseconds
     * @param int $tailseconds
     * @return int
     */
    private static function estimate_session_seconds(array $times, int $gaplimitseconds, int $tailseconds): int {
        if (!$times) {
            return 0;
        }
        sort($times, SORT_NUMERIC);
        $seconds = $tailseconds;
        $previous = (int)reset($times);
        $count = count($times);
        for ($i = 1; $i < $count; $i++) {
            $current = (int)$times[$i];
            $gap = max(0, $current - $previous);
            if ($gap <= $gaplimitseconds) {
                $seconds += $gap;
            } else {
                $seconds += $tailseconds;
            }
            $previous = $current;
        }
        return min(DAYSECS, $seconds);
    }

    /**
     * Exact BBB Summary duration for the selected period, assigned to the date
     * of the Summary record.
     *
     * @param int $companyid
     * @param array $employeeids
     * @param array $pairs
     * @param int $start
     * @param int $endexclusive
     * @return array keyed userid:courseid:YYYY-MM-DD, values minutes
     */
    private static function bbb_time(
        int $companyid, array $employeeids, array $pairs, int $start, int $endexclusive
    ): array {
        global $DB;

        $activities = bigbluebutton::get_company_activities($companyid);
        if (!$activities) {
            return [];
        }
        $activitiesbybbb = [];
        $bbbids = [];
        foreach ($activities as $activity) {
            $activitiesbybbb[(int)$activity->bbbid][] = $activity;
            $bbbids[(int)$activity->bbbid] = (int)$activity->bbbid;
        }

        list($usersql, $userparams) = $DB->get_in_or_equal($employeeids, SQL_PARAMS_NAMED, 'btu');
        list($bbbsql, $bbbparams) = $DB->get_in_or_equal(array_values($bbbids), SQL_PARAMS_NAMED, 'btb');
        $params = array_merge($userparams, $bbbparams, [
            'summarylog' => 'Summary',
            'timestart' => $start,
            'timeend' => $endexclusive,
        ]);
        $sql = "SELECT id, courseid, bigbluebuttonbnid, userid, meetingid, meta, timecreated
                  FROM {bigbluebuttonbn_logs}
                 WHERE userid $usersql
                   AND bigbluebuttonbnid $bbbsql
                   AND log = :summarylog
                   AND timecreated >= :timestart
                   AND timecreated < :timeend
              ORDER BY timecreated, id";
        $logs = $DB->get_recordset_sql($sql, $params);
        $result = [];
        foreach ($logs as $log) {
            $uid = (int)$log->userid;
            $courseid = (int)$log->courseid;
            if (empty($pairs[$uid][$courseid])) {
                continue;
            }
            $candidates = $activitiesbybbb[(int)$log->bigbluebuttonbnid] ?? [];
            $matched = false;
            foreach ($candidates as $activity) {
                if ((int)$activity->courseid !== $courseid) {
                    continue;
                }
                if ((int)$activity->groupid && (int)$activity->groupmode) {
                    if (strpos((string)$log->meetingid, '[' . (int)$activity->groupid . ']') === false) {
                        continue;
                    }
                }
                $matched = true;
                break;
            }
            if (!$matched) {
                continue;
            }
            $meta = json_decode((string)$log->meta);
            $seconds = 0;
            if (is_object($meta) && isset($meta->data) && isset($meta->data->duration)) {
                $seconds = max(0, (int)round((float)$meta->data->duration));
            }
            $key = $uid . ':' . $courseid . ':' . self::date_key((int)$log->timecreated);
            if (!isset($result[$key])) {
                $result[$key] = 0.0;
            }
            $result[$key] += $seconds / 60;
        }
        $logs->close();
        foreach ($result as $key => $minutes) {
            $result[$key] = round($minutes, 1);
        }
        return $result;
    }
}
