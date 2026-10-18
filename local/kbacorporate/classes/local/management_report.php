<?php
namespace local_kbacorporate\local;

defined('MOODLE_INTERNAL') || die();

/**
 * Aggregated management report used by the report centre, XLSX and PDF exports.
 *
 * The report intentionally combines current learning state (progress/grades)
 * with period-bound activity metrics (learning time and webinar attendance).
 */
class management_report {
    /**
     * Build an executive corporate report.
     *
     * @param int $companyid
     * @param string $preset week|month|quarter|custom
     * @param string $from YYYY-MM-DD for custom
     * @param string $to YYYY-MM-DD for custom
     * @return \stdClass
     */
    public static function build(int $companyid, string $preset = 'month', string $from = '', string $to = '', ?array $userids = null): \stdClass {
        global $DB;

        $company = $DB->get_record('local_kbacorp_company', ['id' => $companyid], '*', MUST_EXIST);
        $period = trainingtime::period_for_preset($preset, $from, $to);
        $employees = report::get_employees($companyid, $userids);
        $progressrows = report::get_rows($companyid, $userids);
        $progresssummary = report::summary($progressrows);
        $progresssummary->employees = count($employees);

        $timereport = trainingtime::build_report($companyid, $period->start, $period->endexclusive, 0, $userids);

        $bbbavailable = bigbluebutton::is_available();
        $allwebinarrows = $bbbavailable ? bigbluebutton::get_company_rows($companyid, $userids) : [];
        $webinarrows = self::filter_webinars_by_period($allwebinarrows, $period->start, $period->endexclusive);
        $webinarsummary = bigbluebutton::summary($webinarrows);
        $webinarsbyemployee = bigbluebutton::aggregate_by_employee($webinarrows);

        $licenseavailable = licenses::is_available();
        $licenserows = $licenseavailable ? licenses::get_company_licenses($companyid, true) : [];
        $licensesummary = licenses::summary($licenserows);
        $licensealerts = licenses::alerts($licenserows);

        $certavailable = customcert::is_available();
        $certificaterows = $certavailable ? customcert::get_company_rows($companyid, $userids) : [];
        $certificatesummary = customcert::summary($certificaterows);
        $certificatesissuedinperiod = 0;
        foreach ($certificaterows as $certrow) {
            if (!empty($certrow->issuedat) && $certrow->issuedat >= $period->start && $certrow->issuedat < $period->endexclusive) {
                $certificatesissuedinperiod++;
            }
        }

        $byemployee = [];
        foreach ($employees as $employee) {
            $uid = (int)$employee->id;
            $byemployee[$uid] = (object)[
                'userid' => $uid,
                'fullname' => fullname($employee),
                'email' => (string)$employee->email,
                'position' => (string)($employee->position ?? ''),
                'departments' => '',
                'courses' => 0,
                'completedcourses' => 0,
                'avgprogress' => null,
                'avggrade' => null,
                'lastaccess' => 0,
                'planminutes' => 0.0,
                'trainingminutes' => 0.0,
                'trainingpercent' => null,
                'trainingvariance' => 0.0,
                'webinareligible' => 0,
                'webinarattended' => 0,
                'webinarattendancepercent' => null,
                'webinarcoveragepercent' => null,
                'latewebinars' => 0,
                'certificatesissued' => 0,
                'certificatesnotissued' => 0,
                'missingcertificateaftercompletion' => false,
                '_completedcourseids' => [],
                '_missingcertificatecourses' => [],
                'riskscore' => 0,
                'riskreasons' => [],
            ];
        }

        $departmentnames = departments::get_department_names_for_users($companyid, array_keys($byemployee));
        foreach ($byemployee as $uid => $employee) {
            $employee->departments = !empty($departmentnames[$uid]) ? implode(', ', $departmentnames[$uid]) : '';
        }

        $progressvalues = [];
        $gradevalues = [];
        foreach ($progressrows as $row) {
            $uid = (int)$row->userid;
            if (!isset($byemployee[$uid])) {
                continue;
            }
            $byemployee[$uid]->courses++;
            if ($row->status === 'completed') {
                $byemployee[$uid]->completedcourses++;
                $byemployee[$uid]->_completedcourseids[(int)$row->courseid] = true;
            }
            if ($row->progress !== null) {
                $progressvalues[$uid][] = (float)$row->progress;
            }
            if ($row->grade !== null) {
                $gradevalues[$uid][] = (float)$row->grade;
            }
            if ((int)$row->lastaccess > $byemployee[$uid]->lastaccess) {
                $byemployee[$uid]->lastaccess = (int)$row->lastaccess;
            }
        }
        foreach ($byemployee as $uid => $employee) {
            if (!empty($progressvalues[$uid])) {
                $employee->avgprogress = round(array_sum($progressvalues[$uid]) / count($progressvalues[$uid]), 1);
            }
            if (!empty($gradevalues[$uid])) {
                $employee->avggrade = round(array_sum($gradevalues[$uid]) / count($gradevalues[$uid]), 1);
            }
        }

        foreach ($timereport->employees as $timerow) {
            $uid = (int)$timerow->userid;
            if (!isset($byemployee[$uid])) {
                continue;
            }
            $byemployee[$uid]->planminutes = (float)$timerow->planminutes;
            $byemployee[$uid]->trainingminutes = (float)$timerow->totalminutes;
            $byemployee[$uid]->trainingpercent = $timerow->percent;
            $byemployee[$uid]->trainingvariance = (float)$timerow->varianceminutes;
        }

        foreach ($webinarsbyemployee as $uid => $webrow) {
            $uid = (int)$uid;
            if (!isset($byemployee[$uid])) {
                continue;
            }
            $byemployee[$uid]->webinareligible = (int)$webrow->eligible;
            $byemployee[$uid]->webinarattended = (int)$webrow->attended;
            $byemployee[$uid]->webinarattendancepercent = $webrow->attendancepercent;
            $byemployee[$uid]->webinarcoveragepercent = $webrow->avgcoveragepercent;
            $byemployee[$uid]->latewebinars = (int)$webrow->late;
        }

        foreach ($certificaterows as $certrow) {
            $uid = (int)$certrow->userid;
            if (!isset($byemployee[$uid])) {
                continue;
            }
            if (!empty($certrow->issued)) {
                $byemployee[$uid]->certificatesissued++;
            } else {
                $byemployee[$uid]->certificatesnotissued++;
                if (!empty($byemployee[$uid]->_completedcourseids[(int)$certrow->courseid])) {
                    $byemployee[$uid]->missingcertificateaftercompletion = true;
                    $byemployee[$uid]->_missingcertificatecourses[] = [
                        'courseid' => (int)$certrow->courseid,
                        'coursename' => (string)$certrow->coursename,
                    ];
                }
            }
        }

        $attentionresult = attention::evaluate($byemployee, $progressrows);
        foreach ($byemployee as $employee) {
            unset($employee->_completedcourseids, $employee->_missingcertificatecourses);
        }
        $attention = $attentionresult->employees;

        $courses = self::course_summary($progressrows, $timereport->courses);

        return (object)[
            'company' => $company,
            'period' => $period,
            'employees' => array_values($byemployee),
            'attention' => $attention,
            'alerts' => $attentionresult->alerts,
            'attentionsummary' => $attentionresult->summary,
            'attentionthresholds' => $attentionresult->thresholds,
            'courses' => $courses,
            'progressrows' => $progressrows,
            'progresssummary' => $progresssummary,
            'timereport' => $timereport,
            'webinarrows' => $webinarrows,
            'webinarsummary' => $webinarsummary,
            'bbbavailable' => $bbbavailable,
            'certificaterows' => $certificaterows,
            'certificatesummary' => $certificatesummary,
            'certificatesissuedinperiod' => $certificatesissuedinperiod,
            'customcertavailable' => $certavailable,
            'licenseavailable' => $licenseavailable,
            'licenserows' => $licenserows,
            'licensesummary' => $licensesummary,
            'licensealerts' => $licensealerts,
        ];
    }

    /** Filter BBB rows to events relevant to the selected management period. */
    private static function filter_webinars_by_period(array $rows, int $start, int $endexclusive): array {
        return array_values(array_filter($rows, static function($row) use ($start, $endexclusive) {
            $timestamp = 0;
            if (!empty($row->openingtime)) {
                $timestamp = (int)$row->openingtime;
            } else if (!empty($row->lastsummary)) {
                $timestamp = (int)$row->lastsummary;
            } else if (!empty($row->firstjoin)) {
                $timestamp = (int)$row->firstjoin;
            }
            if (!$timestamp) {
                return false;
            }
            return $timestamp >= $start && $timestamp < $endexclusive;
        }));
    }

    /** Build course-level executive metrics. */
    private static function course_summary(array $progressrows, array $timecourses): array {
        $courses = [];
        foreach ($progressrows as $row) {
            $cid = (int)$row->courseid;
            if (!isset($courses[$cid])) {
                $courses[$cid] = (object)[
                    'courseid' => $cid,
                    'coursename' => (string)$row->coursename,
                    'employees' => 0,
                    'completed' => 0,
                    'avgprogress' => null,
                    'avggrade' => null,
                    'trainingminutes' => 0.0,
                    '_users' => [],
                    '_progress' => [],
                    '_grades' => [],
                ];
            }
            $courses[$cid]->_users[(int)$row->userid] = true;
            if ($row->status === 'completed') {
                $courses[$cid]->completed++;
            }
            if ($row->progress !== null) {
                $courses[$cid]->_progress[] = (float)$row->progress;
            }
            if ($row->grade !== null) {
                $courses[$cid]->_grades[] = (float)$row->grade;
            }
        }
        foreach ($timecourses as $timerow) {
            $cid = (int)$timerow->courseid;
            if (!isset($courses[$cid])) {
                $courses[$cid] = (object)[
                    'courseid' => $cid,
                    'coursename' => (string)$timerow->coursename,
                    'employees' => 0,
                    'completed' => 0,
                    'avgprogress' => null,
                    'avggrade' => null,
                    'trainingminutes' => 0.0,
                    '_users' => [],
                    '_progress' => [],
                    '_grades' => [],
                ];
            }
            $courses[$cid]->trainingminutes = (float)$timerow->totalminutes;
        }
        foreach ($courses as $course) {
            $course->employees = count($course->_users);
            $course->avgprogress = $course->_progress
                ? round(array_sum($course->_progress) / count($course->_progress), 1) : null;
            $course->avggrade = $course->_grades
                ? round(array_sum($course->_grades) / count($course->_grades), 1) : null;
            unset($course->_users, $course->_progress, $course->_grades);
        }
        uasort($courses, static function($a, $b) {
            return strcasecmp($a->coursename, $b->coursename);
        });
        return array_values($courses);
    }
}
