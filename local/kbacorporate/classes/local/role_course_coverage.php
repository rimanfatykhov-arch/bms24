<?php
namespace local_kbacorporate\local;

defined('MOODLE_INTERNAL') || die();

/** Company-scoped view, diagnostics and safe writer for business-role course coverage. */
class role_course_coverage {
    /** Return active visible company courses, keyed by Moodle course id. */
    public static function company_courses(int $companyid): array {
        global $DB;
        if ($companyid <= 0 || !$DB->get_manager()->table_exists(new \xmldb_table('local_kbacorp_course'))) {
            return [];
        }
        $sql = "SELECT DISTINCT c.id, c.fullname, c.shortname, c.idnumber
                  FROM {local_kbacorp_course} kc
                  JOIN {course} c ON c.id = kc.courseid
                 WHERE kc.companyid = :companyid
                   AND kc.active = 1
                   AND c.visible = 1
              ORDER BY c.fullname";
        return $DB->get_records_sql($sql, ['companyid' => $companyid]);
    }

    /** Return non-domain role requirements. */
    public static function role_requirements(int $roleid): array {
        return array_values(array_filter(business_roles::get_role_competencies($roleid), static function($row) {
            return !business_roles::is_domain_competency_code((string)$row->idnumber);
        }));
    }

    /** Return current company-course mappings for the supplied role requirements. */
    public static function mappings(int $companyid, int $roleid): array {
        global $DB;
        $requirements = self::role_requirements($roleid);
        $courses = self::company_courses($companyid);
        if (!$requirements || !$courses ||
                !$DB->get_manager()->table_exists(new \xmldb_table('competency_coursecomp'))) {
            return [];
        }

        $competencyids = array_values(array_unique(array_map(static function($row) {
            return (int)$row->competencyid;
        }, $requirements)));
        $courseids = array_values(array_map('intval', array_keys($courses)));
        list($compsql, $compparams) = $DB->get_in_or_equal($competencyids, SQL_PARAMS_NAMED, 'rcccomp');
        list($coursesql, $courseparams) = $DB->get_in_or_equal($courseids, SQL_PARAMS_NAMED, 'rcccourse');
        $params = $compparams + $courseparams;
        $sql = "SELECT cc.id, cc.competencyid, cc.courseid, c.fullname, c.shortname
                  FROM {competency_coursecomp} cc
                  JOIN {course} c ON c.id = cc.courseid
                 WHERE cc.competencyid {$compsql}
                   AND cc.courseid {$coursesql}
              ORDER BY cc.competencyid, c.fullname";
        $result = [];
        foreach ($DB->get_records_sql($sql, $params) as $row) {
            $result[(int)$row->competencyid][(int)$row->courseid] = $row;
        }
        return $result;
    }

    /** Return all Moodle course mappings for the working competencies of one role, regardless of company scope. */
    public static function all_role_mappings(int $roleid): array {
        global $DB;
        $requirements = self::role_requirements($roleid);
        if (!$requirements || !$DB->get_manager()->table_exists(new \xmldb_table('competency_coursecomp'))) {
            return [];
        }
        $competencyids = array_values(array_unique(array_map(static function($row) {
            return (int)$row->competencyid;
        }, $requirements)));
        list($compsql, $params) = $DB->get_in_or_equal($competencyids, SQL_PARAMS_NAMED, 'rccallcomp');
        $sql = "SELECT cc.id, cc.competencyid, cc.courseid,
                       c.fullname, c.shortname, c.visible,
                       comp.shortname AS competencyname, comp.idnumber AS competencycode
                  FROM {competency_coursecomp} cc
                  JOIN {course} c ON c.id = cc.courseid
                  JOIN {competency} comp ON comp.id = cc.competencyid
                 WHERE cc.competencyid {$compsql}
              ORDER BY comp.shortname, c.fullname, cc.id";
        return array_values($DB->get_records_sql($sql, $params));
    }

    /** Return active company courses that currently have no Moodle course↔competency relations at all. */
    public static function company_courses_without_competencies(int $companyid): array {
        global $DB;
        $courses = self::company_courses($companyid);
        if (!$courses || !$DB->get_manager()->table_exists(new \xmldb_table('competency_coursecomp'))) {
            return $courses;
        }
        $courseids = array_values(array_map('intval', array_keys($courses)));
        list($coursesql, $params) = $DB->get_in_or_equal($courseids, SQL_PARAMS_NAMED, 'rccemptycourse');
        $sql = "SELECT cc.courseid, COUNT(cc.id) AS competencycount
                  FROM {competency_coursecomp} cc
                 WHERE cc.courseid {$coursesql}
              GROUP BY cc.courseid";
        $counts = $DB->get_records_sql($sql, $params);
        $result = [];
        foreach ($courses as $courseid => $course) {
            if (empty($counts[(int)$courseid]) || (int)$counts[(int)$courseid]->competencycount === 0) {
                $result[(int)$courseid] = $course;
            }
        }
        return $result;
    }

    /** Return duplicate Moodle course↔competency rows inside the current role/company coverage scope. */
    public static function duplicate_mappings(int $companyid, int $roleid): array {
        global $DB;
        $requirements = self::role_requirements($roleid);
        $courses = self::company_courses($companyid);
        if (!$requirements || !$courses ||
                !$DB->get_manager()->table_exists(new \xmldb_table('competency_coursecomp'))) {
            return [];
        }
        $competencyids = array_values(array_unique(array_map(static function($row) {
            return (int)$row->competencyid;
        }, $requirements)));
        $courseids = array_values(array_map('intval', array_keys($courses)));
        list($compsql, $compparams) = $DB->get_in_or_equal($competencyids, SQL_PARAMS_NAMED, 'rccdupcomp');
        list($coursesql, $courseparams) = $DB->get_in_or_equal($courseids, SQL_PARAMS_NAMED, 'rccdupcourse');
        $params = $compparams + $courseparams;
        $sql = "SELECT MIN(cc.id) AS id, cc.courseid, cc.competencyid,
                       COUNT(cc.id) AS relationcount,
                       c.fullname, comp.shortname AS competencyname, comp.idnumber AS competencycode
                  FROM {competency_coursecomp} cc
                  JOIN {course} c ON c.id = cc.courseid
                  JOIN {competency} comp ON comp.id = cc.competencyid
                 WHERE cc.competencyid {$compsql}
                   AND cc.courseid {$coursesql}
              GROUP BY cc.courseid, cc.competencyid, c.fullname, comp.shortname, comp.idnumber
                HAVING COUNT(cc.id) > 1
              ORDER BY c.fullname, comp.shortname";
        return array_values($DB->get_records_sql($sql, $params));
    }

    /** Return role mappings that do not belong to the active visible course catalogue of the selected company. */
    public static function out_of_scope_mappings(int $companyid, int $roleid): array {
        global $DB;
        $activecourses = self::company_courses($companyid);
        $activecourseids = array_map('intval', array_keys($activecourses));
        $allmappings = self::all_role_mappings($roleid);
        if (!$allmappings) {
            return [];
        }

        $companyassignmentmap = [];
        if ($companyid > 0 && $DB->get_manager()->table_exists(new \xmldb_table('local_kbacorp_course'))) {
            foreach ($DB->get_records('local_kbacorp_course', ['companyid' => $companyid]) as $assignment) {
                $courseid = (int)$assignment->courseid;
                if (!isset($companyassignmentmap[$courseid])) {
                    $companyassignmentmap[$courseid] = false;
                }
                if (!empty($assignment->active)) {
                    $companyassignmentmap[$courseid] = true;
                }
            }
        }

        $result = [];
        foreach ($allmappings as $mapping) {
            $courseid = (int)$mapping->courseid;
            if (in_array($courseid, $activecourseids, true)) {
                continue;
            }
            // A mapping to a course that was never assigned to this company is not an anomaly here:
            // Moodle course↔competency relations are global and the course may legitimately belong to another tenant.
            if (!array_key_exists($courseid, $companyassignmentmap)) {
                continue;
            }
            if (empty($mapping->visible)) {
                $mapping->scopereason = 'hidden';
            } else if (!$companyassignmentmap[$courseid]) {
                $mapping->scopereason = 'inactive';
            } else {
                // An active visible assignment would already be present in $activecourseids.
                continue;
            }
            $result[] = $mapping;
        }
        return $result;
    }

    /** Summarise structural course coverage of one role inside one company. */
    public static function summary(int $companyid, int $roleid): \stdClass {
        $requirements = self::role_requirements($roleid);
        $mappings = self::mappings($companyid, $roleid);
        $mandatory = 0;
        $covered = 0;
        $mandatorycovered = 0;
        foreach ($requirements as $row) {
            $ismandatory = !empty($row->required);
            if ($ismandatory) {
                $mandatory++;
            }
            if (!empty($mappings[(int)$row->competencyid])) {
                $covered++;
                if ($ismandatory) {
                    $mandatorycovered++;
                }
            }
        }
        return (object)[
            'total' => count($requirements),
            'mandatory' => $mandatory,
            'covered' => $covered,
            'mandatorycovered' => $mandatorycovered,
            'uncovered' => max(0, count($requirements) - $covered),
            'mandatoryuncovered' => max(0, $mandatory - $mandatorycovered),
        ];
    }

    /** Build a non-destructive integrity report for Role → Competency → Course coverage. */
    public static function integrity_report(int $companyid, int $roleid): \stdClass {
        $requirements = self::role_requirements($roleid);
        $mappings = self::mappings($companyid, $roleid);
        $summary = self::summary($companyid, $roleid);
        $mandatoryuncovered = [];
        $supportinguncovered = [];
        foreach ($requirements as $requirement) {
            if (!empty($mappings[(int)$requirement->competencyid])) {
                continue;
            }
            if (!empty($requirement->required)) {
                $mandatoryuncovered[] = $requirement;
            } else {
                $supportinguncovered[] = $requirement;
            }
        }

        $duplicates = self::duplicate_mappings($companyid, $roleid);
        $outofscope = self::out_of_scope_mappings($companyid, $roleid);
        $courseswithoutcompetencies = self::company_courses_without_competencies($companyid);
        $hasrequirements = $summary->total > 0;
        $learningready = $hasrequirements && $summary->mandatoryuncovered === 0 && !$duplicates;
        $complete = $hasrequirements && $summary->uncovered === 0 && !$duplicates;

        if (!$hasrequirements) {
            $status = 'empty';
        } else if ($duplicates) {
            $status = 'integrityerror';
        } else if ($summary->mandatoryuncovered > 0) {
            $status = 'requirescourses';
        } else if (!$complete || $outofscope) {
            $status = 'partial';
        } else {
            $status = 'ready';
        }

        return (object)[
            'summary' => $summary,
            'mandatoryuncovered' => $mandatoryuncovered,
            'supportinguncovered' => $supportinguncovered,
            'courseswithoutcompetencies' => $courseswithoutcompetencies,
            'duplicates' => $duplicates,
            'outofscope' => $outofscope,
            'learningready' => $learningready,
            'complete' => $complete,
            'status' => $status,
        ];
    }

    /**
     * Add a Moodle course↔competency relation after validating company and role boundaries.
     * Existing relations are idempotent and are never duplicated.
     */
    public static function add_mapping(int $companyid, int $roleid, int $competencyid, int $courseid): string {
        global $DB;
        if ($companyid <= 0 || $roleid <= 0 || $competencyid <= 0 || $courseid <= 0) {
            throw new \invalid_parameter_exception('Invalid role course coverage mapping parameters.');
        }

        $role = business_roles::get_role($roleid);
        if ((int)$role->companyid !== 0 && (int)$role->companyid !== $companyid) {
            throw new \moodle_exception('companynotallowed', 'local_kbacorporate');
        }
        $validrequirement = false;
        foreach (self::role_requirements($roleid) as $row) {
            if ((int)$row->competencyid === $competencyid) {
                $validrequirement = true;
                break;
            }
        }
        if (!$validrequirement) {
            throw new \invalid_parameter_exception('Competency is not a working requirement of this role.');
        }
        if (!isset(self::company_courses($companyid)[$courseid])) {
            throw new \invalid_parameter_exception('Course is not an active visible course of this company.');
        }
        if ($DB->record_exists('competency_coursecomp', [
            'courseid' => $courseid,
            'competencyid' => $competencyid,
        ])) {
            return 'unchanged';
        }

        $coursecontext = \context_course::instance($courseid);
        require_capability('moodle/competency:coursecompetencymanage', $coursecontext);

        if (class_exists('\\core_competency\\api') &&
                method_exists('\\core_competency\\api', 'add_competency_to_course')) {
            \core_competency\api::add_competency_to_course($courseid, $competencyid);
        } else {
            // Compatibility fallback for Moodle installations where the public API method is unavailable.
            $sortorder = (int)$DB->count_records('competency_coursecomp', ['courseid' => $courseid]);
            $DB->insert_record('competency_coursecomp', (object)[
                'courseid' => $courseid,
                'competencyid' => $competencyid,
                'sortorder' => $sortorder,
                'ruleoutcome' => 0,
            ]);
        }
        return 'created';
    }
}
