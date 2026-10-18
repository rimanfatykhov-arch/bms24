<?php
namespace local_kbacorporate\local;

defined('MOODLE_INTERNAL') || die();

class report {
    /**
     * Returns all employees assigned to a company, including employees who do
     * not yet have any corporate course mapping. This keeps the Employees view
     * useful while a company is still being configured.
     *
     * @param int $companyid
     * @return array
     */
    public static function get_employees(int $companyid, ?array $userids = null): array {
        global $DB;

        $params = ['companyid' => $companyid, 'isemployee' => 1];
        $usersql = '';
        if ($userids !== null) {
            if (!$userids) {
                return [];
            }
            list($insql, $inparams) = $DB->get_in_or_equal(array_values(array_map('intval', $userids)), SQL_PARAMS_NAMED, 'emp');
            $usersql = " AND u.id {$insql}";
            $params += $inparams;
        }
        $employees = $DB->get_records_sql(
            "SELECT u.*
               FROM {local_kbacorp_member} m
               JOIN {user} u ON u.id = m.userid
              WHERE m.companyid = :companyid
                AND m.isemployee = :isemployee
                AND u.deleted = 0{$usersql}
           ORDER BY u.lastname, u.firstname",
            $params
        );

        foreach ($employees as $employee) {
            $employee->position = self::get_profile_field($employee->id, 'position');
        }
        return $employees;
    }

    public static function get_rows(int $companyid, ?array $userids = null): array {
        global $DB;

        $params = ['companyid' => $companyid, 'isemployee' => 1];
        $usersql = '';
        if ($userids !== null) {
            if (!$userids) {
                return [];
            }
            list($insql, $inparams) = $DB->get_in_or_equal(array_values(array_map('intval', $userids)), SQL_PARAMS_NAMED, 'rowemp');
            $usersql = " AND u.id {$insql}";
            $params += $inparams;
        }
        $members = $DB->get_records_sql(
            "SELECT m.id AS membershipid, u.*
               FROM {local_kbacorp_member} m
               JOIN {user} u ON u.id = m.userid
              WHERE m.companyid = :companyid
                AND m.isemployee = :isemployee
                AND u.deleted = 0{$usersql}
           ORDER BY u.lastname, u.firstname",
            $params
        );
        $courses = $DB->get_records_sql(
            "SELECT cc.id AS mappingid, cc.courseid, cc.groupid, c.fullname, c.shortname
               FROM {local_kbacorp_course} cc
               JOIN {course} c ON c.id = cc.courseid
              WHERE cc.companyid = :companyid AND cc.active = 1
           ORDER BY c.fullname",
            ['companyid' => $companyid]
        );

        $rows = [];
        foreach ($members as $user) {
            foreach ($courses as $coursemap) {
                if ($coursemap->groupid && !$DB->record_exists('groups_members', [
                    'groupid' => $coursemap->groupid,
                    'userid' => $user->id,
                ])) {
                    continue;
                }
                $course = get_course($coursemap->courseid);
                $coursecontext = \context_course::instance($course->id);
                $enrolled = is_enrolled($coursecontext, $user, '', true);
                $metrics = self::course_metrics($course, $user, $enrolled);
                $rows[] = (object)[
                    'userid' => $user->id,
                    'fullname' => fullname($user),
                    'firstname' => $user->firstname,
                    'lastname' => $user->lastname,
                    'email' => $user->email,
                    'phone' => $user->phone1 ?: $user->phone2,
                    'position' => self::get_profile_field($user->id, 'position'),
                    'courseid' => $course->id,
                    'coursename' => $course->fullname,
                    'groupid' => $coursemap->groupid,
                    'enrolled' => $enrolled,
                    'progress' => $metrics->progress,
                    'grade' => $metrics->grade,
                    'status' => $metrics->status,
                    'enrolledat' => $metrics->enrolledat,
                    'lastaccess' => $metrics->lastaccess,
                    'completed' => $metrics->completed,
                ];
            }
        }
        return $rows;
    }

    private static function course_metrics(\stdClass $course, \stdClass $user, bool $enrolled): \stdClass {
        global $DB, $CFG;
        require_once($CFG->libdir . '/completionlib.php');

        $progress = null;
        $completed = false;
        $lastaccess = 0;
        $enrolledat = 0;
        $grade = null;

        if ($enrolled) {
            $enrolledat = (int)$DB->get_field_sql(
                "SELECT MIN(ue.timecreated)
                   FROM {user_enrolments} ue
                   JOIN {enrol} e ON e.id = ue.enrolid
                  WHERE ue.userid = :userid AND e.courseid = :courseid",
                ['userid' => $user->id, 'courseid' => $course->id]
            );
            $lastaccess = (int)$DB->get_field('user_lastaccess', 'timeaccess', [
                'userid' => $user->id,
                'courseid' => $course->id,
            ]);

            $completion = new \completion_info($course);
            if ($completion->is_enabled()) {
                $activities = $completion->get_activities();
                $total = 0;
                $done = 0;
                foreach ($activities as $cm) {
                    if (empty($cm->completion)) {
                        continue;
                    }
                    $total++;
                    $data = $completion->get_data($cm, false, $user->id);
                    if (!empty($data->completionstate)) {
                        $done++;
                    }
                }
                if ($total > 0) {
                    $progress = round(($done / $total) * 100, 1);
                }
                $completed = $completion->is_course_complete($user->id);
            }
            $grade = self::get_course_grade($course->id, $user->id);
        }

        if (!$enrolled) {
            $status = 'notenrolled';
        } else if ($completed) {
            $status = 'completed';
            if ($progress === null) {
                $progress = 100.0;
            }
        } else if (($progress ?? 0) > 0 || $lastaccess > 0) {
            $status = 'inprogress';
        } else {
            $status = 'notstarted';
        }

        return (object)[
            'progress' => $progress,
            'grade' => $grade,
            'status' => $status,
            'enrolledat' => $enrolledat,
            'lastaccess' => $lastaccess,
            'completed' => $completed,
        ];
    }

    private static function get_course_grade(int $courseid, int $userid): ?float {
        global $DB;
        $sql = "SELECT gg.finalgrade, gi.grademin, gi.grademax
                  FROM {grade_items} gi
                  JOIN {grade_grades} gg ON gg.itemid = gi.id
                 WHERE gi.courseid = :courseid
                   AND gi.itemtype = 'course'
                   AND gg.userid = :userid";
        $record = $DB->get_record_sql($sql, ['courseid' => $courseid, 'userid' => $userid]);
        if (!$record || $record->finalgrade === null) {
            return null;
        }
        $range = (float)$record->grademax - (float)$record->grademin;
        if ($range <= 0) {
            return null;
        }
        return round((((float)$record->finalgrade - (float)$record->grademin) / $range) * 100, 1);
    }

    private static function get_profile_field(int $userid, string $shortname): string {
        global $DB;
        $sql = "SELECT d.data
                  FROM {user_info_data} d
                  JOIN {user_info_field} f ON f.id = d.fieldid
                 WHERE d.userid = :userid AND f.shortname = :shortname";
        return (string)($DB->get_field_sql($sql, ['userid' => $userid, 'shortname' => $shortname]) ?: '');
    }

    public static function summary(array $rows): \stdClass {
        $users = [];
        $trainingusers = [];
        $completedusers = [];
        $progressvalues = [];
        $gradevalues = [];
        foreach ($rows as $row) {
            $users[$row->userid] = true;
            if ($row->status === 'inprogress' || $row->status === 'completed') {
                $trainingusers[$row->userid] = true;
            }
            if ($row->status === 'completed') {
                $completedusers[$row->userid] = true;
            }
            if ($row->progress !== null) {
                $progressvalues[] = $row->progress;
            }
            if ($row->grade !== null) {
                $gradevalues[] = $row->grade;
            }
        }
        return (object)[
            'employees' => count($users),
            'training' => count($trainingusers),
            'completed' => count($completedusers),
            'avgprogress' => $progressvalues ? round(array_sum($progressvalues) / count($progressvalues), 1) : null,
            'avggrade' => $gradevalues ? round(array_sum($gradevalues) / count($gradevalues), 1) : null,
        ];
    }
}
