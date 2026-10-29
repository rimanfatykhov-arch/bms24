<?php
namespace local_kbacompany\local;

defined('MOODLE_INTERNAL') || die();

/**
 * Read-only BMS learning contour aggregator.
 *
 * BMS owns the navigation/company context only. KBA Corporate owns corporate
 * learning and licences; Moodle Core owns enrolment/completion facts. This
 * service never writes Corporate or Moodle learning state.
 */
class learning_contour_service {
    public const CONTRACT_VERSION = '0.7.6';

    public static function contract(): array {
        return [
            'version' => self::CONTRACT_VERSION,
            'owner' => 'local_kbacompany_navigation_and_development_context',
            'sources' => [
                'corporate_learning' => 'local_kbacorporate_public_bridge',
                'enrolment_completion' => 'moodle_core',
                'company_employee_context' => 'local_kbacompany',
            ],
            'rules' => [
                'read_only_aggregation',
                'company_uuid_is_security_boundary',
                'company_course_catalogue_is_not_inferred_from_user_enrolments',
                'moodle_enrolment_is_not_a_competency_assessment',
                'course_completion_is_not_competency_attainment',
                'bms_does_not_write_local_kbacorp_tables',
                'corporate_drilldowns_keep_corporate_acl',
                'other_moodle_courses_are_shown_separately_from_company_courses',
                'development_plan_links_only_to_company_catalogue_courses_or_explicit_external_urls',
                'learning_status_is_read_live_and_not_persisted_as_competency_fact',
            ],
        ];
    }

    /** Lightweight workspace counter for the signed-in user. */
    public static function workspace_learning_count(string $companyuuid, int $userid, int $fallback = 0): int {
        try {
            $context = self::context($companyuuid, $userid, false);
            if (empty($context['corporate']['available'])) {
                return $fallback;
            }
            return (int)$context['summary']['my_active_company_courses'];
        } catch (\Throwable $e) {
            return $fallback;
        }
    }

    /** Build the company-scoped learning workspace model. */
    public static function context(string $companyuuid, int $userid, bool $includeteam = true): array {
        $company = company_context_service::context($companyuuid, $userid);
        $canmanage = permission_service::can_manage_organization($companyuuid, $userid) || is_siteadmin($userid);
        $legacycompanyid = (int)($company['compatibility']['source_id'] ?? 0);
        $bitrixcompanyid = (int)($company['external_company_id'] ?? 0);
        if ($bitrixcompanyid <= 0 && $legacycompanyid > 0 && legacy_company_adapter::is_ready()) {
            $bitrixcompanyid = legacy_company_adapter::bitrix_company_id($legacycompanyid);
        }

        $corporate = [
            'available' => false,
            'found' => false,
            'company_id' => 0,
            'bitrix_company_id' => $bitrixcompanyid,
            'company_courses' => [],
            'licenses' => [],
            'license_summary' => [],
            'license_alerts' => [],
            'management_summary' => [],
            'error' => '',
        ];

        if (class_exists('\\local_kbacorporate\\local\\bridge') && $bitrixcompanyid > 0) {
            try {
                $snapshot = \local_kbacorporate\local\bridge::get_company_snapshot($bitrixcompanyid);
                $licenses = \local_kbacorporate\local\bridge::get_license_snapshot($bitrixcompanyid);
                $management = [];
                if ($includeteam && $canmanage &&
                        method_exists('\\local_kbacorporate\\local\\bridge', 'get_management_report_snapshot')) {
                    $management = \local_kbacorporate\local\bridge::get_management_report_snapshot($bitrixcompanyid, 'month');
                }
                $corporate['available'] = true;
                $corporate['found'] = !empty($snapshot['found']);
                $corporate['company_id'] = (int)($snapshot['companyid'] ?? $legacycompanyid);
                $corporate['company_courses'] = array_values(array_filter(
                    (array)($snapshot['courses'] ?? []),
                    static function(array $row): bool { return !empty($row['active']); }
                ));
                $corporate['licenses'] = (array)($licenses['licenses'] ?? []);
                $corporate['license_summary'] = (array)($licenses['summary'] ?? []);
                $corporate['license_alerts'] = (array)($licenses['alerts'] ?? []);
                $corporate['management_summary'] = is_array($management) ? $management : [];
            } catch (\Throwable $e) {
                $corporate['error'] = $e->getMessage();
            }
        }

        $companycourses = [];
        $courseids = [];
        foreach ($corporate['company_courses'] as $course) {
            $courseid = (int)($course['courseid'] ?? 0);
            if ($courseid <= 1) {
                continue;
            }
            $courseids[$courseid] = true;
            $companycourses[$courseid] = [
                'course_id' => $courseid,
                'name' => (string)($course['fullname'] ?? ''),
                'shortname' => (string)($course['shortname'] ?? ''),
                'group_id' => (int)($course['groupid'] ?? 0),
                'url' => (new \moodle_url('/course/view.php', ['id' => $courseid]))->out(false),
                'my_status' => 'not_started',
                'my_enrolled' => false,
                'my_completed' => false,
                'team_enrolled' => 0,
                'team_training' => 0,
                'team_completed' => 0,
                'purchased_seats' => 0,
                'allocated_seats' => 0,
                'free_seats' => 0,
                'has_license_packages' => false,
                'packages' => [],
            ];
        }
        $courseids = array_keys($courseids);

        $employeescontext = $includeteam && $canmanage
            ? employee_service::employees_context($companyuuid, $userid, false)
            : ['employees' => []];
        $teamemployeeids = [];
        foreach ($employeescontext['employees'] as $employee) {
            if (!empty($employee['membership_active'])) {
                $teamemployeeids[] = (int)$employee['user_id'];
            }
        }
        $teamemployeeids = array_values(array_unique(array_filter($teamemployeeids)));

        // The signed-in user is queried for personal mode even when a site admin is
        // inspecting a company without being a company member.
        $queryuserids = $teamemployeeids;
        if (!in_array($userid, $queryuserids, true)) {
            $queryuserids[] = $userid;
        }

        $enrolled = self::active_enrolments($courseids, $queryuserids);
        $completed = self::course_completions($courseids, $queryuserids);

        foreach ($companycourses as $courseid => &$row) {
            $row['my_enrolled'] = !empty($enrolled[$userid][$courseid]);
            $row['my_completed'] = !empty($completed[$userid][$courseid]);
            if ($row['my_completed']) {
                $row['my_status'] = 'completed';
            } else if ($row['my_enrolled']) {
                $row['my_status'] = 'in_progress';
            }
        }
        unset($row);

        foreach ($corporate['licenses'] as $license) {
            $courseid = (int)($license['courseid'] ?? 0);
            if (!isset($companycourses[$courseid])) {
                continue;
            }
            $seats = isset($license['seats']) ? (int)$license['seats'] : 0;
            $used = isset($license['used']) ? (int)$license['used'] : 0;
            $available = isset($license['available']) && $license['available'] !== null
                ? (int)$license['available'] : max(0, $seats - $used);
            $companycourses[$courseid]['has_license_packages'] = true;
            $companycourses[$courseid]['purchased_seats'] += max(0, $seats);
            $companycourses[$courseid]['allocated_seats'] += max(0, $used);
            $companycourses[$courseid]['free_seats'] += max(0, $available);
            $companycourses[$courseid]['packages'][] = $license;
        }

        $team = [];
        $trainingemployees = [];
        $completedemployees = [];
        if ($includeteam && $canmanage) {
            foreach ($employeescontext['employees'] as $employee) {
                if (empty($employee['membership_active'])) {
                    continue;
                }
                $employeeid = (int)$employee['user_id'];
                $active = 0;
                $done = 0;
                foreach ($courseids as $courseid) {
                    $iscompleted = !empty($completed[$employeeid][$courseid]);
                    $isenrolled = !empty($enrolled[$employeeid][$courseid]);
                    if ($isenrolled) {
                        $companycourses[$courseid]['team_enrolled']++;
                    }
                    if ($isenrolled && !$iscompleted) {
                        $active++;
                        $companycourses[$courseid]['team_training']++;
                    }
                    if ($iscompleted) {
                        $done++;
                        $companycourses[$courseid]['team_completed']++;
                    }
                }
                if ($active > 0) {
                    $trainingemployees[$employeeid] = true;
                }
                if ($done > 0) {
                    $completedemployees[$employeeid] = true;
                }
                $status = $active > 0 ? 'in_progress' : ($done > 0 ? 'completed' : 'not_started');
                $team[] = [
                    'user_id' => $employeeid,
                    'full_name' => (string)$employee['full_name'],
                    'position' => !empty($employee['position']['name']) ? (string)$employee['position']['name'] : '',
                    'status' => $status,
                    'active_courses' => $active,
                    'completed_courses' => $done,
                    'corporate_url' => self::employee_link($corporate['company_id'], $employeeid, $userid),
                ];
            }
        }

        $othercourses = [];
        if ($includeteam) {
            foreach (enrol_get_users_courses($userid, true, 'id,fullname,shortname,visible') as $course) {
                $courseid = (int)$course->id;
                if ($courseid <= 1 || in_array($courseid, $courseids, true)) {
                    continue;
                }
                $othercourses[] = [
                    'course_id' => $courseid,
                    'name' => format_string($course->fullname),
                    'shortname' => (string)$course->shortname,
                    'url' => (new \moodle_url('/course/view.php', ['id' => $courseid]))->out(false),
                ];
            }
        }

        $myactive = 0;
        $mycompleted = 0;
        $mynotstarted = 0;
        $continueurl = '';
        foreach ($companycourses as $course) {
            if (!empty($course['my_completed'])) {
                $mycompleted++;
            } else if (!empty($course['my_enrolled'])) {
                $myactive++;
                if ($continueurl === '') {
                    $continueurl = (string)$course['url'];
                }
            } else {
                $mynotstarted++;
            }
        }

        $warnings = [];
        foreach ($corporate['license_alerts'] as $alert) {
            $warnings[] = [
                'type' => 'license',
                'severity' => (string)($alert['severity'] ?? 'warning'),
                'course_name' => (string)($alert['coursename'] ?? ''),
                'reason' => (string)($alert['reason'] ?? ''),
            ];
        }
        $attentioncount = (int)($corporate['management_summary']['attentioncount'] ?? 0);
        if ($attentioncount > 0) {
            $warnings[] = [
                'type' => 'attention',
                'severity' => 'warning',
                'count' => $attentioncount,
                'course_name' => '',
                'reason' => '',
            ];
        }

        // Count-level reconciliation signal only. Detailed user-level allocation ↔
        // enrolment reconciliation remains in KBA Corporate. This deliberately
        // avoids reading Corporate learning tables from this BMS learning service.
        $drift = [];
        if ($canmanage) {
            foreach ($companycourses as $course) {
                if (empty($course['has_license_packages'])) {
                    continue;
                }
                $allocated = (int)$course['allocated_seats'];
                $enrolledcount = (int)$course['team_enrolled'];
                if ($allocated !== $enrolledcount) {
                    $drift[] = [
                        'course_id' => (int)$course['course_id'],
                        'course_name' => (string)$course['name'],
                        'allocated' => $allocated,
                        'enrolled' => $enrolledcount,
                        'delta' => $enrolledcount - $allocated,
                    ];
                }
            }
        }

        $licensesummary = $corporate['license_summary'];
        return [
            'company' => $company,
            'can_manage' => $canmanage,
            'mode' => $canmanage ? 'team' : 'mine',
            'corporate' => $corporate,
            'company_courses' => array_values($companycourses),
            'other_courses' => $othercourses,
            'team' => $team,
            'warnings' => $warnings,
            'drift' => $drift,
            'continue_url' => $continueurl,
            'summary' => [
                'employees' => count($team),
                'training_employees' => count($trainingemployees),
                'completed_employees' => count($completedemployees),
                'company_courses' => count($companycourses),
                'my_active_company_courses' => $myactive,
                'my_not_started_company_courses' => $mynotstarted,
                'my_completed_company_courses' => $mycompleted,
                'my_other_courses' => count($othercourses),
                'purchased_seats' => (int)($licensesummary['purchasedseats'] ?? 0),
                'allocated_seats' => (int)($licensesummary['allocatedseats'] ?? 0),
                'free_seats' => (int)($licensesummary['freeseats'] ?? 0),
                'warning_count' => count($warnings),
                'drift_count' => count($drift),
            ],
            'links' => self::corporate_links($corporate['company_id'], $userid, $canmanage && $includeteam),
            'contract' => self::contract(),
        ];
    }

    /** Company-scoped corporate course catalogue available for development-plan linkage. */
    public static function company_course_catalogue(string $companyuuid, int $actorid): array {
        $context = self::context($companyuuid, $actorid, false);
        return array_values((array)$context['corporate']['company_courses']);
    }

    /** Live Moodle enrolment/completion snapshot for one employee and one company course. */
    public static function course_status_for_employee(string $companyuuid, int $actorid, int $employeeid, int $courseid): array {
        employee_service::employee_context($companyuuid, $employeeid, $actorid, true);
        $catalogue = self::company_course_catalogue($companyuuid, $actorid);
        $allowed = [];
        foreach ($catalogue as $course) {
            $id = (int)($course['courseid'] ?? 0);
            if ($id > 1) {
                $allowed[$id] = $course;
            }
        }
        if (!isset($allowed[$courseid])) {
            throw new \invalid_parameter_exception('Course is not in the current company learning catalogue');
        }
        $enrolled = self::active_enrolments([$courseid], [$employeeid]);
        $completed = self::course_completions([$courseid], [$employeeid]);
        $iscompleted = !empty($completed[$employeeid][$courseid]);
        $isenrolled = !empty($enrolled[$employeeid][$courseid]);
        return [
            'course_id' => $courseid,
            'name' => (string)($allowed[$courseid]['fullname'] ?? ''),
            'shortname' => (string)($allowed[$courseid]['shortname'] ?? ''),
            'url' => (new \moodle_url('/course/view.php', ['id' => $courseid]))->out(false),
            'enrolled' => $isenrolled,
            'completed' => $iscompleted,
            'status' => $iscompleted ? 'completed' : ($isenrolled ? 'in_progress' : 'not_started'),
        ];
    }

    private static function active_enrolments(array $courseids, array $userids): array {
        global $DB;
        if (!$courseids || !$userids) {
            return [];
        }
        list($coursesql, $courseparams) = $DB->get_in_or_equal($courseids, SQL_PARAMS_NAMED, 'lcourse');
        list($usersql, $userparams) = $DB->get_in_or_equal($userids, SQL_PARAMS_NAMED, 'luser');
        $params = array_merge($courseparams, $userparams, ['now1' => time(), 'now2' => time()]);
        $sql = "SELECT ue.id, ue.userid, e.courseid
                  FROM {user_enrolments} ue
                  JOIN {enrol} e ON e.id = ue.enrolid
                 WHERE e.courseid {$coursesql}
                   AND ue.userid {$usersql}
                   AND ue.status = 0 AND e.status = 0
                   AND (ue.timestart = 0 OR ue.timestart <= :now1)
                   AND (ue.timeend = 0 OR ue.timeend >= :now2)";
        $out = [];
        foreach ($DB->get_records_sql($sql, $params) as $row) {
            $out[(int)$row->userid][(int)$row->courseid] = true;
        }
        return $out;
    }

    private static function course_completions(array $courseids, array $userids): array {
        global $DB;
        if (!$courseids || !$userids) {
            return [];
        }
        list($coursesql, $courseparams) = $DB->get_in_or_equal($courseids, SQL_PARAMS_NAMED, 'ccourse');
        list($usersql, $userparams) = $DB->get_in_or_equal($userids, SQL_PARAMS_NAMED, 'cuser');
        $params = array_merge($courseparams, $userparams);
        $sql = "SELECT id, userid, course, timecompleted
                  FROM {course_completions}
                 WHERE course {$coursesql} AND userid {$usersql} AND timecompleted IS NOT NULL";
        $out = [];
        foreach ($DB->get_records_sql($sql, $params) as $row) {
            $out[(int)$row->userid][(int)$row->course] = true;
        }
        return $out;
    }

    /** Corporate drill-down links are UI conveniences; Corporate pages re-check ACL. */
    private static function corporate_links(int $companyid, int $userid, bool $canmanage): array {
        if ($companyid <= 0 || !$canmanage) {
            return [];
        }
        $context = \context_system::instance();
        $links = [];
        if (has_capability('local/kbacorporate:viewdashboard', $context, $userid)) {
            $links['dashboard'] = (new \moodle_url('/local/kbacorporate/index.php', ['companyid' => $companyid]))->out(false);
        }
        if (has_capability('local/kbacorporate:viewprogress', $context, $userid)) {
            $links['company_courses'] = (new \moodle_url('/local/kbacorporate/company_courses.php', ['companyid' => $companyid]))->out(false);
            $links['progress'] = (new \moodle_url('/local/kbacorporate/progress.php', ['companyid' => $companyid]))->out(false);
        }
        if (has_capability('local/kbacorporate:viewlicenses', $context, $userid)) {
            $links['licenses'] = (new \moodle_url('/local/kbacorporate/licenses.php', ['companyid' => $companyid]))->out(false);
        }
        return $links;
    }

    private static function employee_link(int $companyid, int $employeeid, int $userid): string {
        if ($companyid <= 0) {
            return '';
        }
        $context = \context_system::instance();
        if (!has_capability('local/kbacorporate:viewemployees', $context, $userid)) {
            return '';
        }
        return (new \moodle_url('/local/kbacorporate/employee.php', [
            'companyid' => $companyid,
            'userid' => $employeeid,
        ]))->out(false);
    }
}
