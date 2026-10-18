<?php
namespace local_kbacorporate\local;

defined('MOODLE_INTERNAL') || die();

/**
 * Read-only adapter for Moodle's BigBlueButton activity.
 *
 * Moodle 4.0 stores attendance/engagement summaries in {bigbluebuttonbn_logs}
 * Summary rows. This adapter never changes BigBlueButton data. Duration and
 * engagement are factual BBB summary metrics; first join/logout timestamps are
 * Moodle BBB log events and may be absent depending on the meeting flow.
 */
class bigbluebutton {
    /** @var array Cached activity lists by course. */
    private static $activitycache = [];

    /** @var array Cached per-user BBB log lookups. */
    private static $userlogcache = [];

    /** @var array Cached held-state lookups. */
    private static $heldcache = [];

    /**
     * Whether the expected Moodle BigBlueButton tables exist.
     *
     * @return bool
     */
    public static function is_available(): bool {
        global $DB;

        $dbman = $DB->get_manager();
        return $dbman->table_exists('bigbluebuttonbn')
            && $dbman->table_exists('bigbluebuttonbn_logs');
    }

    /**
     * Return company-course mappings with live BigBlueButton activities.
     *
     * @param int $companyid
     * @return array
     */
    public static function get_company_activities(int $companyid): array {
        global $DB;

        if (!self::is_available()) {
            return [];
        }

        $mappings = $DB->get_records_sql(
            "SELECT cc.id AS mappingid, cc.courseid, cc.groupid,
                    c.fullname AS coursename, c.shortname AS courseshortname,
                    g.name AS groupname
               FROM {local_kbacorp_course} cc
               JOIN {course} c ON c.id = cc.courseid
          LEFT JOIN {groups} g ON g.id = cc.groupid
              WHERE cc.companyid = :companyid
                AND cc.active = 1
           ORDER BY c.fullname, cc.groupid",
            ['companyid' => $companyid]
        );

        $result = [];
        foreach ($mappings as $mapping) {
            foreach (self::get_course_activities((int)$mapping->courseid) as $activity) {
                $key = $mapping->mappingid . ':' . $activity->bbbid;
                $result[$key] = (object)[
                    'key' => $key,
                    'mappingid' => (int)$mapping->mappingid,
                    'courseid' => (int)$mapping->courseid,
                    'coursename' => $mapping->coursename,
                    'groupid' => (int)$mapping->groupid,
                    'groupname' => $mapping->groupname ?: '',
                    'bbbid' => (int)$activity->bbbid,
                    'cmid' => (int)$activity->cmid,
                    'webinarname' => $activity->webinarname,
                    'openingtime' => (int)$activity->openingtime,
                    'closingtime' => (int)$activity->closingtime,
                    'requiredminutes' => (int)$activity->completionattendance,
                    'groupmode' => (int)$activity->groupmode,
                    'visible' => (int)$activity->visible,
                ];
            }
        }
        return $result;
    }

    /**
     * Number of assigned BBB webinar activities for a company.
     *
     * @param int $companyid
     * @return int
     */
    public static function get_company_activity_count(int $companyid): int {
        return count(self::get_company_activities($companyid));
    }

    /**
     * Build employee x webinar attendance rows for a company.
     *
     * @param int $companyid
     * @return array
     */
    public static function get_company_rows(int $companyid, ?array $userids = null): array {
        global $DB;

        if (!self::is_available()) {
            return [];
        }

        $employees = report::get_employees($companyid, $userids);
        if (!$employees) {
            return [];
        }

        $activities = self::get_company_activities($companyid);
        if (!$activities) {
            return [];
        }

        $groupmembers = [];
        $rows = [];
        foreach ($activities as $activity) {
            if ($activity->groupid && !isset($groupmembers[$activity->groupid])) {
                $ids = $DB->get_fieldset_select('groups_members', 'userid', 'groupid = :groupid', [
                    'groupid' => $activity->groupid,
                ]);
                $groupmembers[$activity->groupid] = array_fill_keys(array_map('intval', $ids), true);
            }

            foreach ($employees as $employee) {
                if ($activity->groupid && empty($groupmembers[$activity->groupid][(int)$employee->id])) {
                    continue;
                }

                $metrics = self::get_user_activity_metrics($activity, (int)$employee->id);
                $rows[] = (object)[
                    'userid' => (int)$employee->id,
                    'fullname' => fullname($employee),
                    'firstname' => $employee->firstname,
                    'lastname' => $employee->lastname,
                    'email' => $employee->email,
                    'position' => $employee->position ?? '',
                    'mappingid' => $activity->mappingid,
                    'courseid' => $activity->courseid,
                    'coursename' => $activity->coursename,
                    'groupid' => $activity->groupid,
                    'groupname' => $activity->groupname,
                    'bbbid' => $activity->bbbid,
                    'cmid' => $activity->cmid,
                    'webinarname' => $activity->webinarname,
                    'openingtime' => $activity->openingtime,
                    'closingtime' => $activity->closingtime,
                    'requiredminutes' => $activity->requiredminutes,
                    'minutes' => $metrics->minutes,
                    'seconds' => $metrics->seconds,
                    'sessions' => $metrics->sessions,
                    'lastsummary' => $metrics->lastsummary,
                    'attended' => $metrics->attended,
                    'thresholdmet' => $metrics->thresholdmet,
                    'state' => $metrics->state,
                    'firstjoin' => $metrics->firstjoin,
                    'lastlogout' => $metrics->lastlogout,
                    'joincount' => $metrics->joincount,
                    'logoutcount' => $metrics->logoutcount,
                    'scheduledminutes' => $metrics->scheduledminutes,
                    'coveragepercent' => $metrics->coveragepercent,
                    'latenessminutes' => $metrics->latenessminutes,
                    'late' => $metrics->late,
                    'earlyleaveminutes' => $metrics->earlyleaveminutes,
                    'earlyleave' => $metrics->earlyleave,
                    'engagement' => $metrics->engagement,
                    'engagementtotal' => $metrics->engagementtotal,
                    'engaged' => $metrics->engaged,
                ];
            }
        }

        usort($rows, function($a, $b) {
            $cmp = strcasecmp($a->lastname, $b->lastname);
            if ($cmp !== 0) {
                return $cmp;
            }
            $cmp = strcasecmp($a->firstname, $b->firstname);
            if ($cmp !== 0) {
                return $cmp;
            }
            $cmp = strcasecmp($a->coursename, $b->coursename);
            if ($cmp !== 0) {
                return $cmp;
            }
            return strcasecmp($a->webinarname, $b->webinarname);
        });

        return $rows;
    }

    /**
     * Return rows for one employee.
     *
     * @param int $companyid
     * @param int $userid
     * @return array
     */
    public static function get_user_rows(int $companyid, int $userid): array {
        return array_values(array_filter(self::get_company_rows($companyid), function($row) use ($userid) {
            return (int)$row->userid === $userid;
        }));
    }

    /**
     * Aggregate webinar attendance and analytics rows.
     *
     * @param array $rows
     * @return \stdClass
     */
    public static function summary(array $rows): \stdClass {
        $webinars = [];
        $heldwebinars = [];
        $employees = [];
        $attendedemployees = [];
        $eligible = 0;
        $attended = 0;
        $totalminutes = 0.0;
        $attendedrowcount = 0;
        $coveragecount = 0;
        $coveragesum = 0.0;
        $lateeligible = 0;
        $latecount = 0;
        $earlyeligible = 0;
        $earlycount = 0;
        $engagedcount = 0;
        $engagementtotals = self::empty_engagement();

        foreach ($rows as $row) {
            $webinarkey = $row->mappingid . ':' . $row->bbbid;
            $webinars[$webinarkey] = true;
            $employees[$row->userid] = true;
            $totalminutes += (float)$row->minutes;

            if ($row->state === 'attended' || $row->state === 'partial' || $row->state === 'missed') {
                $eligible++;
                $heldwebinars[$webinarkey] = true;
                if ($row->attended) {
                    $attended++;
                    $attendedrowcount++;
                    $attendedemployees[$row->userid] = true;
                }
            }

            if ($row->coveragepercent !== null) {
                $coveragecount++;
                $coveragesum += (float)$row->coveragepercent;
            }
            if ($row->latenessminutes !== null) {
                $lateeligible++;
                if ($row->late) {
                    $latecount++;
                }
            }
            if ($row->earlyleaveminutes !== null) {
                $earlyeligible++;
                if ($row->earlyleave) {
                    $earlycount++;
                }
            }
            if (!empty($row->engaged)) {
                $engagedcount++;
            }
            foreach ($engagementtotals as $key => $value) {
                $engagementtotals[$key] += (int)($row->engagement[$key] ?? 0);
            }
        }

        return (object)[
            'webinars' => count($webinars),
            'heldwebinars' => count($heldwebinars),
            'employees' => count($employees),
            'attendedemployees' => count($attendedemployees),
            'eligibleassignments' => $eligible,
            'attendedassignments' => $attended,
            'attendancepercent' => $eligible ? round(($attended / $eligible) * 100, 1) : null,
            'totalminutes' => round($totalminutes, 1),
            'avgminutes' => $attendedrowcount ? round($totalminutes / $attendedrowcount, 1) : null,
            'coverageeligible' => $coveragecount,
            'avgcoveragepercent' => $coveragecount ? round($coveragesum / $coveragecount, 1) : null,
            'lateeligible' => $lateeligible,
            'lateassignments' => $latecount,
            'latepercent' => $lateeligible ? round(($latecount / $lateeligible) * 100, 1) : null,
            'earlyleaveeligible' => $earlyeligible,
            'earlyleaveassignments' => $earlycount,
            'earlyleavepercent' => $earlyeligible ? round(($earlycount / $earlyeligible) * 100, 1) : null,
            'engagedassignments' => $engagedcount,
            'engagedpercent' => $attendedrowcount ? round(($engagedcount / $attendedrowcount) * 100, 1) : null,
            'engagement' => $engagementtotals,
            'engagementtotal' => array_sum($engagementtotals),
        ];
    }

    /**
     * Aggregate attendance by employee and course for the general CSV export.
     *
     * @param array $rows
     * @return array key userid:courseid
     */
    public static function aggregate_by_user_course(array $rows): array {
        $groups = [];
        foreach ($rows as $row) {
            $key = $row->userid . ':' . $row->courseid;
            if (!isset($groups[$key])) {
                $groups[$key] = (object)[
                    'assigned' => 0,
                    'eligible' => 0,
                    'attended' => 0,
                    'minutes' => 0.0,
                    'attendancepercent' => null,
                    'coveragecount' => 0,
                    'coveragesum' => 0.0,
                    'avgcoveragepercent' => null,
                    'late' => 0,
                    'engagementtotal' => 0,
                ];
            }
            $groups[$key]->assigned++;
            $groups[$key]->minutes += (float)$row->minutes;
            $groups[$key]->engagementtotal += (int)$row->engagementtotal;
            if ($row->coveragepercent !== null) {
                $groups[$key]->coveragecount++;
                $groups[$key]->coveragesum += (float)$row->coveragepercent;
            }
            if (!empty($row->late)) {
                $groups[$key]->late++;
            }
            if ($row->state === 'attended' || $row->state === 'partial' || $row->state === 'missed') {
                $groups[$key]->eligible++;
                if ($row->attended) {
                    $groups[$key]->attended++;
                }
            }
        }
        foreach ($groups as $group) {
            $group->minutes = round($group->minutes, 1);
            $group->attendancepercent = $group->eligible
                ? round(($group->attended / $group->eligible) * 100, 1)
                : null;
            $group->avgcoveragepercent = $group->coveragecount
                ? round($group->coveragesum / $group->coveragecount, 1)
                : null;
            unset($group->coveragesum);
        }
        return $groups;
    }

    /**
     * Aggregate all webinar analytics by employee.
     *
     * @param array $rows
     * @return array key userid
     */
    public static function aggregate_by_employee(array $rows): array {
        $groups = [];
        foreach ($rows as $row) {
            if (!isset($groups[$row->userid])) {
                $groups[$row->userid] = (object)[
                    'userid' => (int)$row->userid,
                    'fullname' => $row->fullname,
                    'assigned' => 0,
                    'eligible' => 0,
                    'attended' => 0,
                    'minutes' => 0.0,
                    'coveragecount' => 0,
                    'coveragesum' => 0.0,
                    'avgcoveragepercent' => null,
                    'late' => 0,
                    'lateeligible' => 0,
                    'engagementtotal' => 0,
                ];
            }
            $group = $groups[$row->userid];
            $group->assigned++;
            $group->minutes += (float)$row->minutes;
            $group->engagementtotal += (int)$row->engagementtotal;
            if ($row->coveragepercent !== null) {
                $group->coveragecount++;
                $group->coveragesum += (float)$row->coveragepercent;
            }
            if ($row->latenessminutes !== null) {
                $group->lateeligible++;
                if ($row->late) {
                    $group->late++;
                }
            }
            if ($row->state === 'attended' || $row->state === 'partial' || $row->state === 'missed') {
                $group->eligible++;
                if ($row->attended) {
                    $group->attended++;
                }
            }
        }
        foreach ($groups as $group) {
            $group->minutes = round($group->minutes, 1);
            $group->attendancepercent = $group->eligible
                ? round(($group->attended / $group->eligible) * 100, 1) : null;
            $group->avgcoveragepercent = $group->coveragecount
                ? round($group->coveragesum / $group->coveragecount, 1) : null;
            $group->latepercent = $group->lateeligible
                ? round(($group->late / $group->lateeligible) * 100, 1) : null;
            unset($group->coveragesum);
        }
        return $groups;
    }

    /**
     * Course BBB activities, cached.
     *
     * @param int $courseid
     * @return array
     */
    private static function get_course_activities(int $courseid): array {
        global $DB;

        if (isset(self::$activitycache[$courseid])) {
            return self::$activitycache[$courseid];
        }

        $sql = "SELECT b.id AS bbbid, cm.id AS cmid, cm.groupmode, cm.visible,
                       b.name AS webinarname, b.openingtime, b.closingtime,
                       b.completionattendance
                  FROM {bigbluebuttonbn} b
                  JOIN {course_modules} cm ON cm.instance = b.id
                  JOIN {modules} md ON md.id = cm.module AND md.name = :modname
                 WHERE b.course = :courseid
                   AND b.type <> :recordingonly
              ORDER BY b.openingtime, b.name";
        self::$activitycache[$courseid] = $DB->get_records_sql($sql, [
            'modname' => 'bigbluebuttonbn',
            'courseid' => $courseid,
            'recordingonly' => 2,
        ]);
        return self::$activitycache[$courseid];
    }

    /**
     * Calculate one user's BBB attendance and engagement metrics.
     *
     * @param \stdClass $activity
     * @param int $userid
     * @return \stdClass
     */
    private static function get_user_activity_metrics(\stdClass $activity, int $userid): \stdClass {
        $logs = self::get_user_logs($activity, $userid);
        $seconds = 0;
        $sessions = 0;
        $lastsummary = 0;
        $engagement = self::empty_engagement();
        $firstjoin = 0;
        $lastlogout = 0;
        $joincount = 0;
        $logoutcount = 0;

        foreach ($logs as $log) {
            if ($log->log === 'Join') {
                $joincount++;
                if (!$firstjoin || (int)$log->timecreated < $firstjoin) {
                    $firstjoin = (int)$log->timecreated;
                }
                continue;
            }
            if ($log->log === 'Logout') {
                $logoutcount++;
                $lastlogout = max($lastlogout, (int)$log->timecreated);
                continue;
            }
            if ($log->log !== 'Summary') {
                continue;
            }

            $summary = json_decode((string)$log->meta);
            $duration = 0;
            if (is_object($summary) && isset($summary->data) && isset($summary->data->duration)) {
                $duration = max(0, (int)round((float)$summary->data->duration));
            }
            $sessions++;
            $seconds += $duration;
            $lastsummary = max($lastsummary, (int)$log->timecreated);

            if (is_object($summary) && isset($summary->data) && isset($summary->data->engagement)) {
                foreach ($engagement as $key => $value) {
                    if (isset($summary->data->engagement->{$key})) {
                        $engagement[$key] += max(0, (int)$summary->data->engagement->{$key});
                    }
                }
            }
        }

        $minutes = round($seconds / 60, 1);
        $attended = $sessions > 0;
        $thresholdmet = null;
        if ($activity->requiredminutes > 0) {
            $thresholdmet = $minutes >= $activity->requiredminutes;
        }

        $scheduledminutes = self::get_scheduled_minutes($activity);
        $coveragepercent = null;
        $latenessminutes = null;
        $earlyleaveminutes = null;
        $late = false;
        $earlyleave = false;

        if ($attended && $scheduledminutes !== null && $scheduledminutes > 0) {
            $coveragepercent = round(min(100, ($minutes / $scheduledminutes) * 100), 1);
        }
        if ($attended && $firstjoin && $scheduledminutes !== null && $activity->openingtime > 0) {
            $latenessminutes = round(max(0, ($firstjoin - $activity->openingtime) / 60), 1);
            $late = $latenessminutes > self::late_tolerance_minutes();
        }
        if ($attended && $lastlogout && $scheduledminutes !== null && $activity->closingtime > 0) {
            $earlyleaveminutes = round(max(0, ($activity->closingtime - $lastlogout) / 60), 1);
            $earlyleave = $earlyleaveminutes > self::early_leave_tolerance_minutes();
        }

        $now = time();
        if ($attended) {
            $state = ($thresholdmet === false) ? 'partial' : 'attended';
        } else if ($activity->openingtime > $now) {
            $state = 'upcoming';
        } else if (self::activity_has_summary($activity)
                || ($activity->closingtime > 0 && $activity->closingtime < $now)) {
            $state = 'missed';
        } else {
            $state = 'nodata';
        }

        return (object)[
            'seconds' => $seconds,
            'minutes' => $minutes,
            'sessions' => $sessions,
            'lastsummary' => $lastsummary,
            'attended' => $attended,
            'thresholdmet' => $thresholdmet,
            'state' => $state,
            'firstjoin' => $firstjoin,
            'lastlogout' => $lastlogout,
            'joincount' => $joincount,
            'logoutcount' => $logoutcount,
            'scheduledminutes' => $scheduledminutes,
            'coveragepercent' => $coveragepercent,
            'latenessminutes' => $latenessminutes,
            'late' => $late,
            'earlyleaveminutes' => $earlyleaveminutes,
            'earlyleave' => $earlyleave,
            'engagement' => $engagement,
            'engagementtotal' => array_sum($engagement),
            'engaged' => array_sum($engagement) > 0,
        ];
    }

    /**
     * Return a plausible scheduled webinar duration from the activity window.
     * Long access windows are deliberately not interpreted as webinar length.
     *
     * @param \stdClass $activity
     * @return float|null minutes
     */
    private static function get_scheduled_minutes(\stdClass $activity) {
        if (empty($activity->openingtime) || empty($activity->closingtime)
                || $activity->closingtime <= $activity->openingtime) {
            return null;
        }
        $minutes = ($activity->closingtime - $activity->openingtime) / 60;
        if ($minutes > self::max_window_minutes()) {
            return null;
        }
        return round($minutes, 1);
    }

    /** @return int */
    private static function late_tolerance_minutes(): int {
        $raw = get_config('local_kbacorporate', 'bbblatetoleranceminutes');
        return ($raw === false || $raw === '') ? 10 : max(0, (int)$raw);
    }

    /** @return int */
    private static function early_leave_tolerance_minutes(): int {
        $raw = get_config('local_kbacorporate', 'bbbearlyleavetoleranceminutes');
        return ($raw === false || $raw === '') ? 10 : max(0, (int)$raw);
    }

    /** @return int */
    private static function max_window_minutes(): int {
        $value = (int)get_config('local_kbacorporate', 'bbbmaxwindowminutes');
        return $value > 0 ? $value : 720;
    }

    /**
     * BBB engagement keys used by Moodle 4.0 completion rules.
     *
     * @return array
     */
    private static function empty_engagement(): array {
        return [
            'chats' => 0,
            'talks' => 0,
            'raisehand' => 0,
            'poll_votes' => 0,
            'emojis' => 0,
        ];
    }

    /**
     * Fetch relevant BBB logs for one user/activity in a single query.
     *
     * Moodle completion uses Summary rows; Join/Logout are optional timing
     * signals. Fetching them together avoids tripling query count per row.
     *
     * @param \stdClass $activity
     * @param int $userid
     * @return array
     */
    private static function get_user_logs(\stdClass $activity, int $userid): array {
        global $DB;

        $cachekey = $activity->bbbid . ':' . $userid . ':' . $activity->groupid . ':' . $activity->groupmode;
        if (isset(self::$userlogcache[$cachekey])) {
            return self::$userlogcache[$cachekey];
        }

        [$insql, $inparams] = $DB->get_in_or_equal(['Summary', 'Join', 'Logout'], SQL_PARAMS_NAMED, 'bbbevent');
        $params = [
            'courseid' => $activity->courseid,
            'bbbid' => $activity->bbbid,
            'userid' => $userid,
        ] + $inparams;
        $where = 'courseid = :courseid AND bigbluebuttonbnid = :bbbid AND userid = :userid AND log ' . $insql;
        self::apply_group_log_filter($where, $params, $activity);

        self::$userlogcache[$cachekey] = $DB->get_records_select(
            'bigbluebuttonbn_logs',
            $where,
            $params,
            'timecreated ASC',
            'id, timecreated, meetingid, meta, log'
        );
        return self::$userlogcache[$cachekey];
    }

    /**
     * Whether BBB has produced any Summary data for this activity/group.
     *
     * @param \stdClass $activity
     * @return bool
     */
    /**
     * Apply a group filter to BBB logs when the installed BBB schema supports it.
     *
     * Moodle/BigBlueButtonBN releases differ in the columns available in the
     * log table. Older installations do not expose groupid, so filtering by a
     * non-existent column would break the corporate dashboard. Employee scope
     * is already restricted by Moodle group membership; this helper adds the
     * stronger DB-level filter only when it is supported by the live schema.
     *
     * @param string $where SQL select fragment, modified in place.
     * @param array $params SQL parameters, modified in place.
     * @param \stdClass $activity Corporate BBB activity descriptor.
     * @return void
     */
    private static function apply_group_log_filter(string &$where, array &$params, \stdClass $activity): void {
        global $DB;

        if (empty($activity->groupid) || empty($activity->groupmode)) {
            return;
        }

        $dbman = $DB->get_manager();
        $table = new \xmldb_table('bigbluebuttonbn_logs');
        if (!$dbman->table_exists($table)) {
            return;
        }

        $groupfield = new \xmldb_field('groupid');
        if ($dbman->field_exists($table, $groupfield)) {
            $where .= ' AND groupid = :kbacorpbbbgroupid';
            $params['kbacorpbbbgroupid'] = (int)$activity->groupid;
        }
    }

    private static function activity_has_summary(\stdClass $activity): bool {
        global $DB;

        $cachekey = $activity->bbbid . ':' . $activity->groupid . ':' . $activity->groupmode;
        if (array_key_exists($cachekey, self::$heldcache)) {
            return self::$heldcache[$cachekey];
        }

        $params = [
            'courseid' => $activity->courseid,
            'bbbid' => $activity->bbbid,
            'summary' => 'Summary',
        ];
        $where = 'courseid = :courseid AND bigbluebuttonbnid = :bbbid AND log = :summary';
        self::apply_group_log_filter($where, $params, $activity);

        self::$heldcache[$cachekey] = $DB->record_exists_select('bigbluebuttonbn_logs', $where, $params);
        return self::$heldcache[$cachekey];
    }
}
