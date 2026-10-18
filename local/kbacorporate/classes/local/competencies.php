<?php
namespace local_kbacorporate\local;

defined('MOODLE_INTERNAL') || die();

/**
 * Read-only competency projection for KBA Corporate Dashboard.
 *
 * Moodle core competency tables remain the source of truth. No competency
 * grades or evidence are duplicated by this plugin.
 */
class competencies {
    /** @return bool */
    public static function available(): bool {
        global $DB;
        $dbman = $DB->get_manager();
        foreach (['competency', 'competency_usercomp', 'competency_plan', 'competency_plancomp'] as $name) {
            if (!$dbman->table_exists(new \xmldb_table($name))) {
                return false;
            }
        }
        return true;
    }

    /**
     * Return competency plans and competency rows for a user.
     *
     * @param int $userid
     * @param int $companyid Explicit company context; zero disables role targets.
     * @param bool $selfview Whether the learner is viewing their own profile.
     * @return array
     */
    public static function get_user_snapshot(int $userid, int $companyid = 0, bool $selfview = false): array {
        global $DB;
        if (!self::available()) {
            return [
                'available' => false,
                'companyid' => $companyid,
                'plans' => [],
                'courses' => [],
                'businessroles' => [],
                'competencies' => [],
                'summary' => self::empty_summary(),
            ];
        }

        $plans = $DB->get_records('competency_plan', ['userid' => $userid], 'status ASC, timemodified DESC');
        $planids = array_map('intval', array_keys($plans));
        $rowsbycompetency = [];

        // Source 1: competencies explicitly assigned through Moodle Learning Plans.
        if ($planids) {
            list($insql, $params) = $DB->get_in_or_equal($planids, SQL_PARAMS_NAMED, 'plan');
            $sql = "SELECT pc.id AS plancompid, pc.planid, pc.sortorder,
                           c.id AS competencyid, c.shortname, c.idnumber, c.description,
                           c.descriptionformat, c.competencyframeworkid AS frameworkid, c.scaleid AS competencyscaleid,
                           f.shortname AS frameworkname, f.scaleid AS frameworkscaleid,
                           uc.id AS usercompetencyid, uc.grade, uc.proficiency, uc.status AS userstatus,
                           uc.reviewerid, uc.timemodified AS assessedat
                      FROM {competency_plancomp} pc
                      JOIN {competency} c ON c.id = pc.competencyid
                      LEFT JOIN {competency_framework} f ON f.id = c.competencyframeworkid
                      LEFT JOIN {competency_usercomp} uc
                             ON uc.competencyid = c.id AND uc.userid = :userid
                     WHERE pc.planid {$insql}
                  ORDER BY pc.planid, pc.sortorder, c.shortname";
            $params['userid'] = $userid;
            foreach ($DB->get_records_sql($sql, $params) as $record) {
                $cid = (int)$record->competencyid;
                if (isset($rowsbycompetency[$cid])) {
                    if (!in_array((int)$record->planid, $rowsbycompetency[$cid]->sourceplans, true)) {
                        $rowsbycompetency[$cid]->sourceplans[] = (int)$record->planid;
                    }
                } else {
                    $row = self::normalise_record($record);
                    $row->sourceplans = [(int)$record->planid];
                    $row->sourcecourses = [];
                    $row->targetroles = [];
                    $rowsbycompetency[$cid] = $row;
                }
            }
        }

        // Source 2: competencies attached to courses in which the user is enrolled.
        // This is the primary KBA flow: Bitrix24 -> Moodle user -> course enrolment ->
        // course competencies. A Learning Plan is therefore optional, not required.
        $courses = enrol_get_users_courses($userid, true, 'id,fullname,shortname,idnumber,visible', 'fullname ASC');
        if (!$selfview) {
            if ($companyid > 0) {
                $companycourseids = $DB->get_fieldset_select(
                    'local_kbacorp_course',
                    'courseid',
                    'companyid = :companyid AND active = 1',
                    ['companyid' => $companyid]
                );
                $courses = array_intersect_key($courses, array_flip(array_map('intval', $companycourseids)));
            } else {
                // Without a selected company, cross-user course names could
                // reveal another customer's learning portfolio.
                $courses = [];
            }
        }
        if ($courses && $DB->get_manager()->table_exists(new \xmldb_table('competency_coursecomp'))) {
            $courseids = array_map('intval', array_keys($courses));
            list($courseinsql, $courseparams) = $DB->get_in_or_equal($courseids, SQL_PARAMS_NAMED, 'course');
            $courseparams['userid'] = $userid;
            $sql = "SELECT cc.id AS coursecompid, cc.courseid, cc.sortorder,
                           c.id AS competencyid, c.shortname, c.idnumber, c.description,
                           c.descriptionformat, c.competencyframeworkid AS frameworkid, c.scaleid AS competencyscaleid,
                           f.shortname AS frameworkname, f.scaleid AS frameworkscaleid,
                           uc.id AS usercompetencyid, uc.grade, uc.proficiency, uc.status AS userstatus,
                           uc.reviewerid, uc.timemodified AS assessedat
                      FROM {competency_coursecomp} cc
                      JOIN {competency} c ON c.id = cc.competencyid
                      LEFT JOIN {competency_framework} f ON f.id = c.competencyframeworkid
                      LEFT JOIN {competency_usercomp} uc
                             ON uc.competencyid = c.id AND uc.userid = :userid
                     WHERE cc.courseid {$courseinsql}
                  ORDER BY cc.courseid, cc.sortorder, c.shortname";
            foreach ($DB->get_records_sql($sql, $courseparams) as $record) {
                $cid = (int)$record->competencyid;
                if (isset($rowsbycompetency[$cid])) {
                    if (!in_array((int)$record->courseid, $rowsbycompetency[$cid]->sourcecourses, true)) {
                        $rowsbycompetency[$cid]->sourcecourses[] = (int)$record->courseid;
                    }
                } else {
                    $record->planid = 0;
                    $record->plancompid = 0;
                    $row = self::normalise_record($record);
                    $row->sourceplans = [];
                    $row->sourcecourses = [(int)$record->courseid];
                    $row->targetroles = [];
                    $rowsbycompetency[$cid] = $row;
                }
            }
        }

        // Source 3: genuine user competency results/evidence not currently linked
        // to either a visible enrolled course or a Learning Plan.
        $sql = "SELECT uc.id AS usercompetencyid, uc.competencyid, uc.grade, uc.proficiency,
                       uc.status AS userstatus, uc.reviewerid, uc.timemodified AS assessedat,
                       c.shortname, c.idnumber, c.description, c.descriptionformat,
                       c.competencyframeworkid AS frameworkid, c.scaleid AS competencyscaleid,
                       f.shortname AS frameworkname, f.scaleid AS frameworkscaleid
                  FROM {competency_usercomp} uc
                  JOIN {competency} c ON c.id = uc.competencyid
                  LEFT JOIN {competency_framework} f ON f.id = c.competencyframeworkid
                 WHERE uc.userid = :userid
              ORDER BY f.shortname, c.shortname";
        foreach ($DB->get_records_sql($sql, ['userid' => $userid]) as $record) {
            $cid = (int)$record->competencyid;
            if (!isset($rowsbycompetency[$cid])) {
                $record->planid = 0;
                $record->plancompid = 0;
                $record->sortorder = 999999;
                $row = self::normalise_record($record);
                $row->sourceplans = [];
                $row->sourcecourses = [];
                $row->targetroles = [];
                $rowsbycompetency[$cid] = $row;
            }
        }

        // Source 4: target competencies required by the user's KBA business roles.
        // A role requirement must be visible even before a learner is enrolled in
        // a course that develops the competency.
        $roleprofile = target_profile::for_user($userid, $companyid);
        foreach ($roleprofile as $cid => $target) {
            $cid = (int)$cid;
            if (!isset($rowsbycompetency[$cid])) {
                $record = (object)[
                    'competencyid' => $cid,
                    'shortname' => $target->shortname,
                    'idnumber' => $target->idnumber,
                    'description' => $target->description,
                    'descriptionformat' => $target->descriptionformat,
                    'frameworkid' => $target->frameworkid,
                    'competencyscaleid' => $target->competencyscaleid,
                    'frameworkname' => $target->frameworkname,
                    'frameworkscaleid' => $target->frameworkscaleid,
                    'usercompetencyid' => 0,
                    'grade' => null,
                    'proficiency' => null,
                    'userstatus' => null,
                    'reviewerid' => 0,
                    'assessedat' => 0,
                    'planid' => 0,
                    'plancompid' => 0,
                    'sortorder' => 999999,
                ];
                $row = self::normalise_record($record);
                $row->sourceplans = [];
                $row->sourcecourses = [];
                $row->targetroles = [];
                $rowsbycompetency[$cid] = $row;
            }
            $rowsbycompetency[$cid]->targetroles = $target->roles;
            $rowsbycompetency[$cid]->rolepriority = $target->priority;
            $rowsbycompetency[$cid]->rolerequired = $target->required;
            $rowsbycompetency[$cid]->roletargetlevel = (int)$target->targetlevel;
        }

        // Source 5: the latest locally managed audit assessment. Pending audit
        // levels are deliberately provisional; only the confirmation action
        // writes an authoritative grade through Moodle's Competency API.
        $audititems = $companyid > 0
            ? competency_audits::get_latest_user_items($companyid, $userid) : [];
        foreach ($audititems as $cid => $audititem) {
            $cid = (int)$cid;
            if (in_array($audititem->status, [
                    competency_audits::ITEM_REJECTED,
                    competency_audits::ITEM_MISSING,
                ], true)) {
                continue;
            }
            if (!isset($rowsbycompetency[$cid])) {
                $record = (object)[
                    'competencyid' => $cid,
                    'shortname' => $audititem->shortname,
                    'idnumber' => $audititem->idnumber,
                    'description' => $audititem->description,
                    'descriptionformat' => $audititem->descriptionformat,
                    'frameworkid' => $audititem->competencyframeworkid,
                    'competencyscaleid' => $audititem->competencyscaleid,
                    'frameworkname' => $audititem->frameworkname,
                    'frameworkscaleid' => $audititem->frameworkscaleid,
                    'usercompetencyid' => 0,
                    'grade' => null,
                    'proficiency' => null,
                    'userstatus' => null,
                    'reviewerid' => 0,
                    'assessedat' => 0,
                    'planid' => 0,
                    'plancompid' => 0,
                    'sortorder' => 999999,
                ];
                $row = self::normalise_record($record);
                $row->sourceplans = [];
                $row->sourcecourses = [];
                $row->targetroles = [];
                $rowsbycompetency[$cid] = $row;
            }
            $rowsbycompetency[$cid]->latestaudititem = $audititem;
        }

        $rows = array_values($rowsbycompetency);
        usort($rows, static function($a, $b) {
            return strcasecmp((string)$a->shortname, (string)$b->shortname);
        });

        foreach ($rows as $row) {
            if ($selfview && !empty($row->usercompetencyid)) {
                $row->evidence = self::get_evidence((int)$row->usercompetencyid);
            } else {
                $row->evidence = [];
            }

            // Moodle also keeps course-scoped competency results. They are useful
            // while a learner is still progressing through a course and before a
            // final user competency grade/proficiency is confirmed.
            $row->courseresults = self::get_course_results($userid, (int)$row->competencyid, $row->sourcecourses ?? []);
            foreach ($row->courseresults as $result) {
                $row->evidence[] = (object)[
                    'id' => 'course-' . $result->id,
                    'type' => 'learning',
                    'text' => $result->coursename,
                    'grade' => $result->grade,
                    'timecreated' => $result->timemodified,
                    'url' => '',
                    'sourcecomponent' => 'core_competency',
                    'sourceidentifier' => 'course_result',
                    'assessorid' => 0,
                    'assessorname' => '',
                    'confirmed' => false,
                ];
            }
            if (!empty($row->latestaudititem)) {
                $audititem = $row->latestaudititem;
                $assessmenttext = format_string($audititem->audittitle);
                if (!empty($audititem->comment)) {
                    $assessmenttext .= ': ' . trim((string)$audititem->comment);
                }
                $row->evidence[] = (object)[
                    'id' => 'audit-' . $audititem->id,
                    'type' => $audititem->method === 'ai' ? 'ai' : 'audit',
                    'text' => $assessmenttext,
                    'grade' => (int)$audititem->proposedlevel + 1,
                    'timecreated' => (int)$audititem->timeassessed,
                    'url' => (string)($audititem->evidenceurl ?? ''),
                    'sourcecomponent' => 'local_kbacorporate',
                    'sourceidentifier' => 'auditmethod_' . $audititem->method,
                    'assessorid' => (int)$audititem->assessorid,
                    'assessorname' => trim($audititem->assessorfirstname . ' ' . $audititem->assessorlastname),
                    'confirmed' => $audititem->status === competency_audits::ITEM_CONFIRMED,
                ];
            }
            usort($row->evidence, static function($a, $b) {
                return ((int)$b->timecreated <=> (int)$a->timecreated);
            });

            if (isset($row->roletargetlevel)) {
                $row->targetlevel = (int)$row->roletargetlevel;
                $row->targetsource = 'businessrole';
            } else {
                $row->targetlevel = self::infer_target_level($row, $plans);
                $row->targetsource = $row->targetlevel === null ? null : 'pilot';
            }
            $scaleid = (int)($row->competencyscaleid ?: $row->frameworkscaleid);
            $row->currentlevel = self::grade_to_level($row->grade, $scaleid);
            $levels = [];
            foreach ($row->courseresults as $result) {
                $level = self::grade_to_level($result->grade, $scaleid);
                if ($level !== null) {
                    $levels[] = $level;
                }
            }
            if (!empty($row->latestaudititem) &&
                    $row->latestaudititem->status === competency_audits::ITEM_PENDING) {
                $levels[] = (int)$row->latestaudititem->proposedlevel;
            }
            $row->provisionallevel = $levels ? max($levels) : null;
            if ($row->targetlevel === null) {
                $row->gap = null;
                $row->gapreason = 'target_unknown';
            } else if ($row->currentlevel === null) {
                $row->gap = null;
                $row->gapreason = 'current_unknown';
            } else {
                $row->gap = max(0, $row->targetlevel - $row->currentlevel);
                $row->gapreason = '';
            }
            $row->evidenceprogress = self::evidence_progress($row->evidence);
            $row->recommendedcourses = $companyid > 0
                ? course_recommendations::for_competency($userid, $companyid, (int)$row->competencyid) : [];
            $row->latestassessment = self::latest_assessment($row);
            $row->activelearning = $row->provisionallevel !== null || $row->evidenceprogress->total > 0 ||
                self::has_learning_recommendation($row->recommendedcourses);
            $row->displaystatus = self::status_for($row);
            $row->attentionrequired = !empty($row->rolerequired) &&
                ($row->rolepriority ?? '') === business_roles::PRIORITY_CRITICAL &&
                ($row->currentlevel === null || ($row->gap !== null && $row->gap > 0));
        }

        return [
            'available' => true,
            'plans' => $plans,
            'courses' => $courses,
            'companyid' => $companyid,
            'businessroles' => business_roles::get_user_roles($userid, $companyid),
            'competencies' => $rows,
            'summary' => self::summary($rows),
        ];
    }

    private static function normalise_record(\stdClass $record): \stdClass {
        $record->grade = isset($record->grade) && $record->grade !== null ? (float)$record->grade : null;
        $record->proficiency = isset($record->proficiency) && $record->proficiency !== null ? (int)$record->proficiency : null;
        $record->planid = isset($record->planid) ? (int)$record->planid : 0;
        $record->usercompetencyid = isset($record->usercompetencyid) ? (int)$record->usercompetencyid : 0;
        $record->rolepriority = $record->rolepriority ?? null;
        $record->rolerequired = !empty($record->rolerequired);
        return $record;
    }

    private static function get_evidence(int $usercompetencyid): array {
        global $DB;
        if (!$DB->get_manager()->table_exists(new \xmldb_table('competency_evidence'))) {
            return [];
        }
        $records = $DB->get_records('competency_evidence', ['usercompetencyid' => $usercompetencyid], 'timecreated DESC');
        $result = [];
        foreach ($records as $evidence) {
            $text = '';
            if (!empty($evidence->descidentifier) && !empty($evidence->desccomponent)) {
                try {
                    $a = null;
                    if (!empty($evidence->desca)) {
                        $decoded = json_decode($evidence->desca);
                        $a = $decoded === null ? $evidence->desca : $decoded;
                    }
                    $text = get_string($evidence->descidentifier, $evidence->desccomponent, $a);
                } catch (\Throwable $e) {
                    $text = $evidence->descidentifier;
                }
            }
            if ($text === '' && !empty($evidence->note)) {
                $text = html_to_text($evidence->note, 0, false);
            }
            $haystack = strtolower(($evidence->desccomponent ?? '') . ' ' . ($evidence->descidentifier ?? '') . ' ' . $text);
            if (strpos($haystack, 'audit') !== false || strpos($haystack, 'аудит') !== false) {
                $type = 'audit';
            } else if (strpos($haystack, 'aiassessor') !== false || strpos($haystack, 'ai assessor') !== false ||
                    strpos($haystack, 'ai оцен') !== false) {
                $type = 'ai';
            } else if (strpos($haystack, 'review') !== false || strpos($haystack, 'assessor') !== false ||
                    strpos($haystack, 'manual') !== false || strpos($haystack, 'провер') !== false ||
                    strpos($haystack, 'оценщик') !== false || strpos($haystack, 'supervisor') !== false) {
                $type = 'check';
            } else {
                $type = 'learning';
            }
            $result[] = (object)[
                'id' => (int)$evidence->id,
                'type' => $type,
                'text' => trim($text),
                'grade' => isset($evidence->grade) && $evidence->grade !== null ? (float)$evidence->grade : null,
                'timecreated' => (int)$evidence->timecreated,
                'url' => !empty($evidence->url) ? $evidence->url : '',
                'sourcecomponent' => (string)($evidence->desccomponent ?? ''),
                'sourceidentifier' => (string)($evidence->descidentifier ?? ''),
                'assessorid' => (int)($evidence->actionuserid ?? 0),
                'assessorname' => self::assessor_name((int)($evidence->actionuserid ?? 0)),
                'confirmed' => (int)($evidence->action ?? 0) === 2,
            ];
        }
        return $result;
    }

    /**
     * Read course-scoped competency results for the learner.
     *
     * @param int $userid
     * @param int $competencyid
     * @param array $sourcecourses
     * @return array
     */
    private static function get_course_results(int $userid, int $competencyid, array $sourcecourses): array {
        global $DB;
        if (!$DB->get_manager()->table_exists(new \xmldb_table('competency_usercompcourse'))) {
            return [];
        }
        $params = ['userid' => $userid, 'competencyid' => $competencyid];
        $coursefilter = '';
        $courseids = array_values(array_filter(array_map('intval', $sourcecourses)));
        if (!$courseids) {
            return [];
        }
        list($insql, $inparams) = $DB->get_in_or_equal($courseids, SQL_PARAMS_NAMED, 'ucrcourse');
        $coursefilter = " AND ucc.courseid {$insql}";
        $params += $inparams;
        $sql = "SELECT ucc.id, ucc.courseid, ucc.grade, ucc.proficiency, ucc.timemodified,
                       crs.fullname AS coursename
                  FROM {competency_usercompcourse} ucc
                  JOIN {course} crs ON crs.id = ucc.courseid
                 WHERE ucc.userid = :userid
                   AND ucc.competencyid = :competencyid{$coursefilter}
              ORDER BY ucc.timemodified DESC";
        $records = $DB->get_records_sql($sql, $params);
        foreach ($records as $record) {
            $record->id = (int)$record->id;
            $record->courseid = (int)$record->courseid;
            $record->grade = isset($record->grade) && $record->grade !== null ? (float)$record->grade : null;
            $record->proficiency = isset($record->proficiency) && $record->proficiency !== null ? (int)$record->proficiency : null;
            $record->timemodified = (int)$record->timemodified;
            $record->coursename = format_string($record->coursename);
        }
        return array_values($records);
    }

    /**
     * Summarise real evidence by user-facing category.
     */
    private static function evidence_progress(array $evidence): \stdClass {
        $progress = (object)[
            'total' => 0,
            'learning' => 0,
            'checks' => 0,
            'ai' => 0,
        ];
        foreach ($evidence as $item) {
            $progress->total++;
            if ($item->type === 'ai') {
                $progress->ai++;
            } else if (in_array($item->type, ['check', 'audit'], true)) {
                $progress->checks++;
            } else {
                $progress->learning++;
            }
        }
        return $progress;
    }

    /** Convert a Moodle competency grade to the zero-based KBA level. */
    public static function level_from_grade($grade, int $scaleid = 0): ?int {
        return self::grade_to_level($grade, $scaleid);
    }

    /**
     * The initial KBA pilot uses the documented 0-3 scale. Moodle stores scale
     * grades as numeric positions; mapping them to 0-3 keeps the UI stable while
     * the framework is being piloted. Values outside the pilot scale are shown
     * without inventing a target.
     */
    private static function grade_to_level($grade, int $scaleid = 0): ?int {
        global $DB;
        if ($grade === null || $grade === '') {
            return null;
        }
        $value = (int)round((float)$grade);

        // Moodle stores custom-scale item positions as 1-based grades. KBA
        // target levels are zero-based, so map every configured scale item to
        // its zero-based target level instead of assuming a fixed four-item
        // scale.
        if ($scaleid > 0) {
            $scale = $DB->get_record('scale', ['id' => $scaleid], 'id,scale', IGNORE_MISSING);
            if ($scale) {
                $items = array_values(array_filter(array_map('trim', explode(',', (string)$scale->scale)), 'strlen'));
                if ($value >= 1 && $value <= count($items)) {
                    return $value - 1;
                }
            }
        }

        // Fallback for numeric/custom grades already written as KBA levels.
        if ($value >= 0 && $value <= 3) {
            return $value;
        }
        return null;
    }

    /**
     * Pilot target convention from BMS-L0 methodology: C02,C03,C07,C08,C10,C11
     * target level 3; remaining C01-C12 target level 2. Other frameworks remain
     * without an invented target until Role Profiles are introduced.
     */
    private static function infer_target_level(\stdClass $row, array $plans): ?int {
        $idnumber = strtoupper(trim((string)$row->idnumber));

        // Pilot course BMS-CRS-OWN-MBS-001 "Масштаб бизнеса через систему".
        // Its blueprint explicitly sets targetlevel=3 for all seven course competencies.
        $masshtab = [
            'BMS-C00-01', 'BMS-C03-01', 'BMS-C04-06', 'BMS-C05-02',
            'BMS-C07-01', 'BMS-C10-01', 'BMS-C06-02',
        ];
        if (in_array($idnumber, $masshtab, true)) {
            return 3;
        }

        // Earlier BMS-L0 pilot convention.
        $source = trim((string)$row->idnumber) . ' ' . trim((string)$row->shortname);
        if (!preg_match('/(?:^|[^A-Z0-9])C(0[1-9]|1[0-2])(?:$|[^A-Z0-9])/i', $source, $m)) {
            return null;
        }
        $code = 'C' . strtoupper($m[1]);
        return in_array($code, ['C02', 'C03', 'C07', 'C08', 'C10', 'C11'], true) ? 3 : 2;
    }

    private static function status_for(\stdClass $row): string {
        if ($row->targetlevel === null) {
            return 'outsideprofile';
        }
        if (!empty($row->latestaudititem) &&
                $row->latestaudititem->status === competency_audits::ITEM_PENDING) {
            return 'needsconfirmation';
        }
        if ($row->currentlevel === null && $row->provisionallevel !== null) {
            return 'needsconfirmation';
        }
        if ($row->currentlevel === null) {
            return 'notassessed';
        }
        if ($row->currentlevel === 0 && empty($row->activelearning)) {
            return 'notstarted';
        }
        if ($row->currentlevel >= $row->targetlevel) {
            return 'confirmed';
        }
        if ($row->currentlevel < $row->targetlevel && !empty($row->activelearning)) {
            return 'developing';
        }
        return 'notstarted';
    }

    private static function summary(array $rows): \stdClass {
        $s = self::empty_summary();
        $s->total = count($rows);
        foreach ($rows as $row) {
            if ($row->targetlevel !== null) {
                $s->targeted++;
            }
            if (property_exists($s, $row->displaystatus)) {
                $s->{$row->displaystatus}++;
            }
            if (!empty($row->attentionrequired)) {
                $s->attention++;
            }
        }
        return $s;
    }

    private static function empty_summary(): \stdClass {
        return (object)[
            'total' => 0,
            'targeted' => 0,
            'confirmed' => 0,
            'developing' => 0,
            'notassessed' => 0,
            'notstarted' => 0,
            'needsconfirmation' => 0,
            'outsideprofile' => 0,
            'attention' => 0,
        ];
    }

    /** Latest explicit assessment/check, falling back to the user competency grade event. */
    private static function latest_assessment(\stdClass $row): ?\stdClass {
        foreach ($row->evidence as $evidence) {
            if (in_array($evidence->type, ['audit', 'check', 'ai'], true)) {
                return (object)[
                    'type' => $evidence->type,
                    'text' => $evidence->text,
                    'grade' => $evidence->grade,
                    'timecreated' => $evidence->timecreated,
                    'assessorid' => $evidence->assessorid,
                    'assessorname' => $evidence->assessorname,
                    'confirmed' => $evidence->confirmed,
                ];
            }
        }
        if ($row->currentlevel === null || empty($row->assessedat)) {
            return null;
        }
        return (object)[
            'type' => 'assessment',
            'text' => '',
            'grade' => $row->grade,
            'timecreated' => (int)$row->assessedat,
            'assessorid' => (int)($row->reviewerid ?? 0),
            'assessorname' => self::assessor_name((int)($row->reviewerid ?? 0)),
            'confirmed' => (int)($row->proficiency ?? 0) === 1,
        ];
    }

    private static function assessor_name(int $userid): string {
        if ($userid <= 0) {
            return '';
        }
        $user = \core_user::get_user($userid, 'id,firstname,lastname', IGNORE_MISSING);
        return $user ? fullname($user) : '';
    }

    private static function has_learning_recommendation(array $recommendations): bool {
        foreach ($recommendations as $recommendation) {
            if (in_array($recommendation->completionstatus, ['inprogress', 'completed'], true)) {
                return true;
            }
        }
        return false;
    }

    public static function level_label(?int $level, int $scaleid = 0): string {
        global $DB;
        if ($level === null) {
            return get_string('competencynotassessed', 'local_kbacorporate');
        }
        if ($scaleid > 0) {
            $scale = $DB->get_record('scale', ['id' => $scaleid], 'id,scale', IGNORE_MISSING);
            if ($scale) {
                $items = array_values(array_filter(array_map('trim', explode(',', (string)$scale->scale)), 'strlen'));
                if (isset($items[$level])) {
                    $label = (string)$items[$level];
                    return preg_match('/^\s*\d+\s*[—:.-]/u', $label)
                        ? $label : $level . ' — ' . $label;
                }
            }
        }
        $key = 'competencylevel' . $level;
        return get_string_manager()->string_exists($key, 'local_kbacorporate')
            ? get_string($key, 'local_kbacorporate') : (string)$level;
    }
}
