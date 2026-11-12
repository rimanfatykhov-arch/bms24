<?php
namespace local_kbacorporate\local;

defined('MOODLE_INTERNAL') || die();

/** Company-scoped courses that can help a learner develop a competency. */
class course_recommendations {
    /**
     * Return deduplicated company courses mapped to one Moodle competency.
     *
     * Course completion is learning evidence only. It never changes the
     * authoritative competency level stored in competency_usercomp.
     */
    public static function for_competency(int $userid, int $companyid, int $competencyid): array {
        global $DB;

        if ($companyid <= 0 || $competencyid <= 0) {
            return [];
        }
        // An explicit company revocation precedes every positive grant: a revoked
        // member must not receive this company's course catalogue.
        if (access::is_revoked($companyid, $userid)) {
            return [];
        }
        $dbman = $DB->get_manager();
        foreach (['local_kbacorp_course', 'competency_coursecomp'] as $tablename) {
            if (!$dbman->table_exists(new \xmldb_table($tablename))) {
                return [];
            }
        }

        $sql = "SELECT DISTINCT c.id, c.category, c.fullname, c.shortname, c.visible, c.enablecompletion
                  FROM {local_kbacorp_course} kc
                  JOIN {course} c ON c.id = kc.courseid
                  JOIN {competency_coursecomp} cc ON cc.courseid = c.id
                 WHERE kc.companyid = :companyid
                   AND kc.active = 1
                   AND cc.competencyid = :competencyid
              ORDER BY c.fullname";
        $courses = $DB->get_records_sql($sql, [
            'companyid' => $companyid,
            'competencyid' => $competencyid,
        ]);

        $result = [];
        foreach ($courses as $course) {
            $context = \context_course::instance((int)$course->id);
            $enrolled = is_enrolled($context, $userid, '', true);
            $licensed = self::has_active_licence($userid, $companyid, (int)$course->id);
            $selfavailable = self::has_open_enrolment((int)$course->id);
            // moodle/course:view means "view without participation" (managers);
            // an ordinary enrolled learner opens the course through enrolment.
            $canaccess = $enrolled && can_access_course($course, $userid, '', true);
            $canopen = is_siteadmin($userid) || (!empty($course->visible) &&
                ($canaccess || $selfavailable));

            if ($enrolled && $canopen) {
                $enrolmentstatus = 'enrolled';
            } else if (!empty($course->visible) && ($licensed || $selfavailable)) {
                $enrolmentstatus = 'available';
            } else {
                // A recommendation must be actionable for this learner or
                // assignable under the selected company's active licence.
                // Do not disclose hidden or unrelated course names.
                continue;
            }

            $completionstatus = 'notstarted';
            $progress = null;
            if ($enrolled) {
                $percentage = \core_completion\progress::get_course_progress_percentage($course, $userid);
                if ($percentage !== null) {
                    $progress = max(0, min(100, (int)round($percentage)));
                }
                if (!empty($course->enablecompletion)) {
                    $completion = new \completion_info($course);
                    if ($completion->is_course_complete($userid)) {
                        $completionstatus = 'completed';
                        $progress = 100;
                    } else if ($progress !== null && $progress > 0) {
                        $completionstatus = 'inprogress';
                    }
                } else if ($progress !== null && $progress > 0) {
                    $completionstatus = 'inprogress';
                }
            }

            $result[] = (object)[
                'courseid' => (int)$course->id,
                'fullname' => format_string($course->fullname, true, ['context' => $context]),
                'url' => $canopen
                    ? (new \moodle_url('/course/view.php', ['id' => (int)$course->id]))->out(false) : '',
                'enrolmentstatus' => $enrolmentstatus,
                'completionstatus' => $completionstatus,
                'progress' => $progress,
                'source' => 'moodle_course_competency',
            ];
        }

        usort($result, static function($a, $b) {
            $rank = static function($item): int {
                if ($item->completionstatus === 'inprogress') {
                    return 1;
                }
                if ($item->enrolmentstatus === 'enrolled' && $item->completionstatus === 'notstarted') {
                    return 2;
                }
                if ($item->enrolmentstatus === 'available') {
                    return 3;
                }
                if ($item->completionstatus === 'completed') {
                    return 4;
                }
                return 5;
            };
            $comparison = $rank($a) <=> $rank($b);
            return $comparison ?: strcasecmp((string)$a->fullname, (string)$b->fullname);
        });
        return $result;
    }

    private static function has_open_enrolment(int $courseid): bool {
        foreach (enrol_get_instances($courseid, true) as $instance) {
            if (in_array((string)$instance->enrol, ['self', 'guest'], true)) {
                return true;
            }
        }
        return false;
    }

    private static function has_active_licence(int $userid, int $companyid, int $courseid): bool {
        global $DB;
        if (!$DB->get_manager()->table_exists(new \xmldb_table('local_kbacorp_license_user'))) {
            return false;
        }
        $now = time();
        $sql = "SELECT lu.id
                  FROM {local_kbacorp_license_user} lu
                  JOIN {local_kbacorp_license} l ON l.id = lu.licenseid
                 WHERE lu.userid = :userid
                   AND lu.status <> :released
                   AND l.companyid = :companyid
                   AND l.courseid = :courseid
                   AND l.status = :active
                   AND (l.datefrom = 0 OR l.datefrom <= :nowfrom)
                   AND (l.dateto = 0 OR l.dateto >= :nowto)";
        return $DB->record_exists_sql($sql, [
            'userid' => $userid,
            'released' => 'released',
            'companyid' => $companyid,
            'courseid' => $courseid,
            'active' => 'active',
            'nowfrom' => $now,
            'nowto' => $now,
        ]);
    }
}
