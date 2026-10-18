<?php
namespace local_kbacorporate\local;

defined('MOODLE_INTERNAL') || die();

/**
 * Company-scoped employee competency profile and history projection.
 *
 * Moodle core competency tables remain the authoritative source for confirmed
 * levels and evidence. KBA adds business-role targets, locally managed audits,
 * development plans and course recommendations without duplicating the source
 * competency grades.
 */
class employee_competency_profile {
    public const SCOPE_PROFILE = 'profile';
    public const SCOPE_ALL = 'all';

    public const STATUS_GAP = 'gap';
    public const STATUS_UNASSESSED = 'unassessed';
    public const STATUS_PENDING = 'pending';
    public const STATUS_MET = 'met';
    public const STATUS_OUTSIDE = 'outsideprofile';

    public const SOURCE_AUDIT = 'audit';
    public const SOURCE_AI = 'ai';
    public const SOURCE_MOODLE = 'moodle';
    public const SOURCE_COURSE = 'course';
    public const SOURCE_PLAN = 'plan';
    public const SOURCE_NONE = 'none';

    /**
     * Build one unified employee competency profile.
     *
     * @param int $companyid Company context.
     * @param int $userid Employee user id.
     * @return \stdClass
     */
    public static function build(int $companyid, int $userid): \stdClass {
        global $DB;

        if ($companyid <= 0 || $userid <= 0) {
            throw new \invalid_parameter_exception('Необходимо выбрать компанию и сотрудника.');
        }
        if (!$DB->record_exists('local_kbacorp_member', [
                'companyid' => $companyid,
                'userid' => $userid,
                'isemployee' => 1,
            ])) {
            throw new \invalid_parameter_exception('Выбранный пользователь не является сотрудником указанной компании.');
        }

        $snapshot = competencies::get_user_snapshot($userid, $companyid, false);
        if (!$snapshot['available']) {
            return (object)[
                'companyid' => $companyid,
                'userid' => $userid,
                'roles' => [],
                'rows' => [],
                'summary' => self::summarise([]),
                'available' => false,
            ];
        }

        $gapprojection = employee_gap::calculate($companyid, $userid);
        $gapmap = [];
        foreach ($gapprojection->rows as $row) {
            $gapmap[(int)$row->competencyid] = $row;
        }

        // Moodle competency records are global per user. Restrict the company-facing
        // profile to competencies that are connected to this company through a role,
        // company course, company audit or company development plan. This prevents a
        // manager from seeing learning data that belongs only to another customer.
        $relevantids = self::company_relevant_competency_ids(
            $companyid, $userid, $snapshot['competencies'], $gapmap
        );
        $snapshot['competencies'] = array_values(array_filter(
            $snapshot['competencies'],
            static function($row) use ($relevantids) {
                return isset($relevantids[(int)$row->competencyid]);
            }
        ));

        $competencyids = array_values(array_unique(array_map(static function($row) {
            return (int)$row->competencyid;
        }, $snapshot['competencies'])));
        $historymap = self::load_history_map($companyid, $userid, $competencyids);
        $planmap = self::load_plan_map($companyid, $userid, $competencyids);

        $rows = [];
        foreach ($snapshot['competencies'] as $snapshotrow) {
            $competencyid = (int)$snapshotrow->competencyid;
            $targetrow = $gapmap[$competencyid] ?? null;
            $targeted = $targetrow !== null;
            $history = $historymap[$competencyid] ?? [];
            $latestassessment = self::latest_assessment_event($history, $snapshotrow);
            $plan = $planmap[$competencyid] ?? null;

            if ($targeted) {
                $targetlevel = (int)$targetrow->targetlevel;
                $currentlevel = $targetrow->currentlevel === null ? null : (int)$targetrow->currentlevel;
                $provisionallevel = $targetrow->provisionallevel === null
                    ? null : (int)$targetrow->provisionallevel;
                $gap = $targetrow->gap === null ? null : (int)$targetrow->gap;
                $status = (string)$targetrow->status;
                $priority = business_roles::normalise_priority((string)$targetrow->priority);
                $required = !empty($targetrow->required);
                $roles = array_values((array)$targetrow->roles);
                $courses = array_values((array)$targetrow->courses);
            } else {
                $targetlevel = null;
                $currentlevel = $snapshotrow->currentlevel === null ? null : (int)$snapshotrow->currentlevel;
                $provisionallevel = $snapshotrow->provisionallevel === null
                    ? null : (int)$snapshotrow->provisionallevel;
                $gap = null;
                $status = self::STATUS_OUTSIDE;
                $priority = business_roles::PRIORITY_ADDITIONAL;
                $required = false;
                $roles = [];
                $courses = array_values((array)($snapshotrow->recommendedcourses ?? []));
            }

            $rows[] = (object)[
                'competencyid' => $competencyid,
                'shortname' => (string)$snapshotrow->shortname,
                'idnumber' => (string)$snapshotrow->idnumber,
                'description' => (string)$snapshotrow->description,
                'descriptionformat' => (int)$snapshotrow->descriptionformat,
                'frameworkname' => (string)($snapshotrow->frameworkname ?? ''),
                'competencyscaleid' => (int)($snapshotrow->competencyscaleid ?? 0),
                'frameworkscaleid' => (int)($snapshotrow->frameworkscaleid ?? 0),
                'targeted' => $targeted,
                'targetlevel' => $targetlevel,
                'currentlevel' => $currentlevel,
                'provisionallevel' => $provisionallevel,
                'gap' => $gap,
                'priority' => $priority,
                'required' => $required,
                'roles' => $roles,
                'courses' => $courses,
                'coursecovered' => !empty($courses),
                'status' => $status,
                'assessment_source' => $latestassessment ? (string)$latestassessment->source : self::SOURCE_NONE,
                'latestassessment' => $latestassessment,
                'historycount' => count($history),
                'lastevent' => $history ? reset($history) : null,
                'plan' => $plan,
                'sourceplans' => array_values((array)($snapshotrow->sourceplans ?? [])),
                'sourcecourses' => array_values((array)($snapshotrow->sourcecourses ?? [])),
            ];
        }

        usort($rows, static function($a, $b) {
            if ($a->targeted !== $b->targeted) {
                return $a->targeted ? -1 : 1;
            }
            $priorityrank = [
                business_roles::PRIORITY_CRITICAL => 1,
                business_roles::PRIORITY_CORE => 2,
                business_roles::PRIORITY_ADDITIONAL => 3,
            ];
            $statusrank = [
                self::STATUS_GAP => 1,
                self::STATUS_PENDING => 2,
                self::STATUS_UNASSESSED => 3,
                self::STATUS_MET => 4,
                self::STATUS_OUTSIDE => 5,
            ];
            $comparison = ($priorityrank[$a->priority] ?? 9) <=> ($priorityrank[$b->priority] ?? 9);
            if ($comparison !== 0) {
                return $comparison;
            }
            $comparison = ((int)!$a->required) <=> ((int)!$b->required);
            if ($comparison !== 0) {
                return $comparison;
            }
            $comparison = ($statusrank[$a->status] ?? 9) <=> ($statusrank[$b->status] ?? 9);
            if ($comparison !== 0) {
                return $comparison;
            }
            $comparison = ((int)($b->gap ?? -1)) <=> ((int)($a->gap ?? -1));
            return $comparison !== 0 ? $comparison : strcasecmp($a->shortname, $b->shortname);
        });

        return (object)[
            'companyid' => $companyid,
            'userid' => $userid,
            'roles' => array_values($gapprojection->roles),
            'rows' => $rows,
            'summary' => self::summarise($rows),
            'available' => true,
        ];
    }

    /**
     * Return the complete ordered history for one competency.
     *
     * @param int $companyid Company context.
     * @param int $userid Employee id.
     * @param int $competencyid Competency id.
     * @return array
     */
    public static function history(int $companyid, int $userid, int $competencyid): array {
        if ($competencyid <= 0) {
            throw new \invalid_parameter_exception('Необходимо выбрать компетенцию.');
        }
        $map = self::load_history_map($companyid, $userid, [$competencyid]);
        return $map[$competencyid] ?? [];
    }

    /**
     * Return the normalised history map for an already company-scoped list of competencies.
     *
     * Callers must obtain the competency ids from build() or another company-scoped
     * projection. This method exists to avoid N+1 history queries in reporting pages.
     *
     * @param int $companyid Company context.
     * @param int $userid Employee id.
     * @param array $competencyids Company-visible competency ids.
     * @return array
     */
    public static function history_map(int $companyid, int $userid, array $competencyids): array {
        $competencyids = array_values(array_unique(array_filter(array_map('intval', $competencyids))));
        if (!$competencyids) {
            return [];
        }
        return self::load_history_map($companyid, $userid, $competencyids);
    }

    /** Summarise prepared rows. Public for deterministic regression tests. */
    public static function summarise(array $rows): \stdClass {
        $summary = (object)[
            'total' => count($rows),
            'profile' => 0,
            'outsideprofile' => 0,
            'mandatory' => 0,
            'supporting' => 0,
            'assessed' => 0,
            'unassessed' => 0,
            'pending' => 0,
            'gaps' => 0,
            'met' => 0,
            'withcourses' => 0,
            'withoutcourses' => 0,
            'withhistory' => 0,
            'historyevents' => 0,
            'assessmentcoverage' => 0,
            'targetreadiness' => 0,
        ];

        foreach ($rows as $row) {
            if (!empty($row->targeted)) {
                $summary->profile++;
                if (!empty($row->required)) {
                    $summary->mandatory++;
                } else {
                    $summary->supporting++;
                }
                if ($row->status === self::STATUS_PENDING) {
                    $summary->pending++;
                    $summary->unassessed++;
                } else if ($row->status === self::STATUS_UNASSESSED) {
                    $summary->unassessed++;
                } else {
                    $summary->assessed++;
                }
                if ($row->status === self::STATUS_GAP) {
                    $summary->gaps++;
                } else if ($row->status === self::STATUS_MET) {
                    $summary->met++;
                }
                if (!empty($row->coursecovered)) {
                    $summary->withcourses++;
                } else {
                    $summary->withoutcourses++;
                }
            } else {
                $summary->outsideprofile++;
            }
            $summary->historyevents += (int)($row->historycount ?? 0);
            if (!empty($row->historycount)) {
                $summary->withhistory++;
            }
        }

        $summary->assessmentcoverage = $summary->profile > 0
            ? (int)round(($summary->assessed / $summary->profile) * 100) : 0;
        $summary->targetreadiness = $summary->profile > 0
            ? (int)round(($summary->met / $summary->profile) * 100) : 0;
        return $summary;
    }

    /** Filter prepared rows without recalculating Moodle data. */
    public static function filter(array $rows, string $scope = self::SCOPE_PROFILE,
            string $status = 'all', string $source = 'all', string $kind = 'all',
            string $query = ''): array {
        $allowedscope = [self::SCOPE_PROFILE, self::SCOPE_ALL];
        $allowedstatus = ['all', self::STATUS_GAP, self::STATUS_UNASSESSED,
            self::STATUS_PENDING, self::STATUS_MET, self::STATUS_OUTSIDE];
        $allowedsources = ['all', self::SOURCE_AUDIT, self::SOURCE_AI,
            self::SOURCE_MOODLE, self::SOURCE_COURSE, self::SOURCE_PLAN, self::SOURCE_NONE];
        $allowedkinds = ['all', 'mandatory', 'supporting', 'outsideprofile'];
        if (!in_array($scope, $allowedscope, true)) {
            $scope = self::SCOPE_PROFILE;
        }
        if (!in_array($status, $allowedstatus, true)) {
            $status = 'all';
        }
        if (!in_array($source, $allowedsources, true)) {
            $source = 'all';
        }
        if (!in_array($kind, $allowedkinds, true)) {
            $kind = 'all';
        }
        $query = \core_text::strtolower(trim($query));

        return array_values(array_filter($rows, static function($row) use (
                $scope, $status, $source, $kind, $query) {
            if ($scope === self::SCOPE_PROFILE && empty($row->targeted)) {
                return false;
            }
            if ($status !== 'all' && (string)$row->status !== $status) {
                return false;
            }
            if ($source !== 'all' && (string)$row->assessment_source !== $source) {
                return false;
            }
            if ($kind === 'mandatory' && (empty($row->targeted) || empty($row->required))) {
                return false;
            }
            if ($kind === 'supporting' && (empty($row->targeted) || !empty($row->required))) {
                return false;
            }
            if ($kind === 'outsideprofile' && !empty($row->targeted)) {
                return false;
            }
            if ($query !== '') {
                $roles = implode(' ', array_map(static function($role) {
                    return (string)($role->name ?? '') . ' ' . (string)($role->code ?? '');
                }, (array)$row->roles));
                $haystack = \core_text::strtolower(trim((string)$row->shortname . ' ' .
                    (string)$row->idnumber . ' ' . (string)$row->frameworkname . ' ' . $roles));
                if (\core_text::strpos($haystack, $query) === false) {
                    return false;
                }
            }
            return true;
        }));
    }

    /**
     * Sort and de-duplicate prepared history events. Public for regression tests.
     *
     * Core competency evidence written immediately after a confirmed local audit
     * represents the same business event. The audit row is retained and the
     * duplicate core-evidence row is omitted.
     */
    public static function deduplicate_events(array $events): array {
        usort($events, static function($a, $b) {
            $comparison = ((int)$b->timecreated) <=> ((int)$a->timecreated);
            if ($comparison !== 0) {
                return $comparison;
            }
            return strcasecmp((string)$a->id, (string)$b->id);
        });

        $auditevents = array_values(array_filter($events, static function($event) {
            return in_array((string)$event->source, [self::SOURCE_AUDIT, self::SOURCE_AI], true) &&
                (string)$event->kind === 'assessment';
        }));

        $result = [];
        $seen = [];
        foreach ($events as $event) {
            $id = (string)$event->id;
            if ($id !== '' && isset($seen[$id])) {
                continue;
            }
            if ((string)$event->kind === 'evidence' &&
                    in_array((string)$event->source, [self::SOURCE_AUDIT, self::SOURCE_AI], true)) {
                $duplicate = false;
                foreach ($auditevents as $audit) {
                    if ($event->level !== null && $audit->level !== null &&
                            (int)$event->level === (int)$audit->level &&
                            abs((int)$event->timecreated - (int)$audit->timecreated) <= 300) {
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
        return $result;
    }

    /**
     * Return competency ids that are explicitly connected to the selected company.
     *
     * Core Moodle plans and user competency grades are user-global. Company managers
     * must only see records that are relevant through a company role, course, audit
     * or KBA development plan.
     *
     * @param int $companyid
     * @param int $userid
     * @param array $snapshotrows
     * @param array $gapmap
     * @return array Associative set keyed by competency id.
     */
    private static function company_relevant_competency_ids(int $companyid, int $userid,
            array $snapshotrows, array $gapmap): array {
        global $DB;

        $ids = [];
        foreach (array_keys($gapmap) as $competencyid) {
            $ids[(int)$competencyid] = true;
        }
        foreach ($snapshotrows as $row) {
            if (!empty($row->sourcecourses) || !empty($row->targetroles) ||
                    !empty($row->latestaudititem)) {
                $ids[(int)$row->competencyid] = true;
            }
        }

        $dbman = $DB->get_manager();
        if ($dbman->table_exists(new \xmldb_table('local_kbacorp_devplan')) &&
                $dbman->table_exists(new \xmldb_table('local_kbacorp_devitem'))) {
            $sql = "SELECT DISTINCT item.competencyid
                      FROM {local_kbacorp_devitem} item
                      JOIN {local_kbacorp_devplan} plan ON plan.id = item.planid
                     WHERE plan.companyid = :companyid
                       AND plan.userid = :userid";
            foreach ($DB->get_fieldset_sql($sql, [
                    'companyid' => $companyid,
                    'userid' => $userid,
                ]) as $competencyid) {
                $ids[(int)$competencyid] = true;
            }
        }

        if ($dbman->table_exists(new \xmldb_table('local_kbacorp_audit')) &&
                $dbman->table_exists(new \xmldb_table('local_kbacorp_audit_item'))) {
            $sql = "SELECT DISTINCT item.competencyid
                      FROM {local_kbacorp_audit_item} item
                      JOIN {local_kbacorp_audit} audit ON audit.id = item.auditid
                     WHERE audit.companyid = :companyid
                       AND audit.userid = :userid";
            foreach ($DB->get_fieldset_sql($sql, [
                    'companyid' => $companyid,
                    'userid' => $userid,
                ]) as $competencyid) {
                $ids[(int)$competencyid] = true;
            }
        }

        return $ids;
    }

    /** Load all supported history sources in bulk and group by competency id. */
    private static function load_history_map(int $companyid, int $userid, array $competencyids): array {
        global $DB;

        $competencyids = array_values(array_unique(array_filter(array_map('intval', $competencyids))));
        if (!$competencyids) {
            return [];
        }
        $history = [];
        foreach ($competencyids as $competencyid) {
            $history[$competencyid] = [];
        }

        list($compinsql, $compparams) = $DB->get_in_or_equal($competencyids, SQL_PARAMS_NAMED, 'ecpcomp');

        // Current confirmed competency state in Moodle.
        $params = ['userid' => $userid] + $compparams;
        $sql = "SELECT uc.id, uc.competencyid, uc.grade, uc.proficiency, uc.reviewerid,
                       uc.timemodified, c.scaleid AS competencyscaleid,
                       f.scaleid AS frameworkscaleid,
                       reviewer.firstname AS reviewerfirstname,
                       reviewer.lastname AS reviewerlastname
                  FROM {competency_usercomp} uc
                  JOIN {competency} c ON c.id = uc.competencyid
             LEFT JOIN {competency_framework} f ON f.id = c.competencyframeworkid
             LEFT JOIN {user} reviewer ON reviewer.id = uc.reviewerid
                 WHERE uc.userid = :userid
                   AND uc.competencyid {$compinsql}";
        $usercompetencies = $DB->get_records_sql($sql, $params);
        $usercompetencyids = [];
        foreach ($usercompetencies as $record) {
            $competencyid = (int)$record->competencyid;
            $usercompetencyids[(int)$record->id] = $competencyid;
            if ($record->grade === null || $record->grade === '') {
                continue;
            }
            $scaleid = (int)($record->competencyscaleid ?: $record->frameworkscaleid);
            $level = competencies::level_from_grade($record->grade, $scaleid);
            $history[$competencyid][] = self::event([
                'id' => 'moodle-current-' . (int)$record->id,
                'competencyid' => $competencyid,
                'kind' => 'assessment',
                'source' => self::SOURCE_MOODLE,
                'title' => get_string('employeeprofileeventcurrentgrade', 'local_kbacorporate'),
                'details' => '',
                'level' => $level,
                'status' => !empty($record->proficiency) ? 'confirmed' : 'recorded',
                'timecreated' => (int)$record->timemodified,
                'actorid' => (int)($record->reviewerid ?? 0),
                'actorname' => self::name_from_parts($record->reviewerfirstname ?? '',
                    $record->reviewerlastname ?? ''),
            ]);
        }

        // Moodle competency evidence.
        if ($usercompetencyids && $DB->get_manager()->table_exists(new \xmldb_table('competency_evidence'))) {
            list($ucinsql, $ucparams) = $DB->get_in_or_equal(array_keys($usercompetencyids),
                SQL_PARAMS_NAMED, 'ecpuc');
            $sql = "SELECT e.*, actor.firstname AS actorfirstname, actor.lastname AS actorlastname
                      FROM {competency_evidence} e
                 LEFT JOIN {user} actor ON actor.id = e.actionuserid
                     WHERE e.usercompetencyid {$ucinsql}
                  ORDER BY e.timecreated DESC, e.id DESC";
            foreach ($DB->get_records_sql($sql, $ucparams) as $record) {
                $competencyid = $usercompetencyids[(int)$record->usercompetencyid] ?? 0;
                if (!$competencyid) {
                    continue;
                }
                $scaleid = 0;
                foreach ($usercompetencies as $uc) {
                    if ((int)$uc->id === (int)$record->usercompetencyid) {
                        $scaleid = (int)($uc->competencyscaleid ?: $uc->frameworkscaleid);
                        break;
                    }
                }
                $text = self::core_evidence_text($record);
                $source = self::classify_evidence_source(
                    (string)($record->desccomponent ?? ''),
                    (string)($record->descidentifier ?? ''),
                    $text
                );
                $note = trim(html_to_text((string)($record->note ?? ''), 0, false));
                $history[$competencyid][] = self::event([
                    'id' => 'moodle-evidence-' . (int)$record->id,
                    'competencyid' => $competencyid,
                    'kind' => 'evidence',
                    'source' => $source,
                    'title' => $text !== '' ? $text : get_string('employeeprofileeventevidence', 'local_kbacorporate'),
                    'details' => $note,
                    'level' => competencies::level_from_grade($record->grade ?? null, $scaleid),
                    'status' => (int)($record->action ?? 0) === 2 ? 'confirmed' : 'recorded',
                    'timecreated' => (int)$record->timecreated,
                    'actorid' => (int)($record->actionuserid ?? 0),
                    'actorname' => self::name_from_parts($record->actorfirstname ?? '',
                        $record->actorlastname ?? ''),
                    'url' => self::safe_url((string)($record->url ?? '')),
                ]);
            }
        }

        // All locally managed competency audit items, not only the latest one.
        $dbman = $DB->get_manager();
        if ($dbman->table_exists(new \xmldb_table('local_kbacorp_audit')) &&
                $dbman->table_exists(new \xmldb_table('local_kbacorp_audit_item'))) {
            list($auditinsql, $auditparams) = $DB->get_in_or_equal($competencyids,
                SQL_PARAMS_NAMED, 'ecpauditcomp');
            $auditparams['companyid'] = $companyid;
            $auditparams['userid'] = $userid;
            $sql = "SELECT i.id, i.auditid, i.competencyid, i.method, i.proposedlevel,
                           i.status AS itemstatus, i.comment, i.evidenceurl,
                           i.assessorid, i.reviewerid, i.timeassessed, i.timeconfirmed,
                           i.timecreated, i.timemodified,
                           a.title AS audittitle, a.audittype, a.status AS auditstatus,
                           assessor.firstname AS assessorfirstname,
                           assessor.lastname AS assessorlastname,
                           reviewer.firstname AS reviewerfirstname,
                           reviewer.lastname AS reviewerlastname
                      FROM {local_kbacorp_audit_item} i
                      JOIN {local_kbacorp_audit} a ON a.id = i.auditid
                 LEFT JOIN {user} assessor ON assessor.id = i.assessorid
                 LEFT JOIN {user} reviewer ON reviewer.id = i.reviewerid
                     WHERE a.companyid = :companyid
                       AND a.userid = :userid
                       AND i.competencyid {$auditinsql}
                  ORDER BY i.timemodified DESC, i.id DESC";
            foreach ($DB->get_records_sql($sql, $auditparams) as $record) {
                $competencyid = (int)$record->competencyid;
                $confirmed = in_array((string)$record->itemstatus,
                    [competency_audits::ITEM_CONFIRMED, competency_audits::ITEM_REJECTED], true);
                $actorid = $confirmed && (int)$record->reviewerid > 0
                    ? (int)$record->reviewerid : (int)$record->assessorid;
                $actorname = $confirmed && (int)$record->reviewerid > 0
                    ? self::name_from_parts($record->reviewerfirstname ?? '', $record->reviewerlastname ?? '')
                    : self::name_from_parts($record->assessorfirstname ?? '', $record->assessorlastname ?? '');
                $timecreated = (int)$record->timeconfirmed ?: (int)$record->timeassessed ?:
                    (int)$record->timemodified ?: (int)$record->timecreated;
                $methodkey = 'auditmethod_' . (string)$record->method;
                $methodlabel = get_string_manager()->string_exists($methodkey, 'local_kbacorporate')
                    ? get_string($methodkey, 'local_kbacorporate') : (string)$record->method;
                $details = $methodlabel;
                if (trim((string)$record->comment) !== '') {
                    $details .= ': ' . trim((string)$record->comment);
                }
                $history[$competencyid][] = self::event([
                    'id' => 'kba-audit-' . (int)$record->id,
                    'competencyid' => $competencyid,
                    'kind' => 'assessment',
                    'source' => (string)$record->method === 'ai' ? self::SOURCE_AI : self::SOURCE_AUDIT,
                    'title' => format_string((string)$record->audittitle),
                    'details' => $details,
                    'level' => (int)$record->proposedlevel,
                    'status' => (string)$record->itemstatus,
                    'timecreated' => $timecreated,
                    'actorid' => $actorid,
                    'actorname' => $actorname,
                    'url' => (new \moodle_url('/local/kbacorporate/competency_audit.php', [
                        'id' => (int)$record->auditid,
                    ]))->out(false),
                    'externalurl' => self::safe_url((string)($record->evidenceurl ?? '')),
                ]);
            }
        }

        // Course-scoped competency results for company courses.
        if ($dbman->table_exists(new \xmldb_table('competency_usercompcourse')) &&
                $dbman->table_exists(new \xmldb_table('local_kbacorp_course'))) {
            list($courseinsql, $courseparams) = $DB->get_in_or_equal($competencyids,
                SQL_PARAMS_NAMED, 'ecpcoursecomp');
            $courseparams['userid'] = $userid;
            $courseparams['companyid'] = $companyid;
            $sql = "SELECT ucc.id, ucc.competencyid, ucc.courseid, ucc.grade,
                           ucc.proficiency, ucc.timemodified,
                           course.fullname AS coursename,
                           competency.scaleid AS competencyscaleid,
                           framework.scaleid AS frameworkscaleid
                      FROM {competency_usercompcourse} ucc
                      JOIN {course} course ON course.id = ucc.courseid
                      JOIN {local_kbacorp_course} companycourse
                        ON companycourse.courseid = ucc.courseid
                       AND companycourse.companyid = :companyid
                       AND companycourse.active = 1
                      JOIN {competency} competency ON competency.id = ucc.competencyid
                 LEFT JOIN {competency_framework} framework
                        ON framework.id = competency.competencyframeworkid
                     WHERE ucc.userid = :userid
                       AND ucc.competencyid {$courseinsql}
                  ORDER BY ucc.timemodified DESC, ucc.id DESC";
            foreach ($DB->get_records_sql($sql, $courseparams) as $record) {
                $competencyid = (int)$record->competencyid;
                $scaleid = (int)($record->competencyscaleid ?: $record->frameworkscaleid);
                $history[$competencyid][] = self::event([
                    'id' => 'course-result-' . (int)$record->id,
                    'competencyid' => $competencyid,
                    'kind' => 'course',
                    'source' => self::SOURCE_COURSE,
                    'title' => format_string((string)$record->coursename),
                    'details' => get_string('employeeprofileeventcourseresult', 'local_kbacorporate'),
                    'level' => competencies::level_from_grade($record->grade ?? null, $scaleid),
                    'status' => !empty($record->proficiency) ? 'proficient' : 'provisional',
                    'timecreated' => (int)$record->timemodified,
                    'url' => (new \moodle_url('/course/view.php', ['id' => (int)$record->courseid]))->out(false),
                ]);
            }
        }

        // KBA development-plan participation and status.
        if ($dbman->table_exists(new \xmldb_table('local_kbacorp_devplan')) &&
                $dbman->table_exists(new \xmldb_table('local_kbacorp_devitem'))) {
            list($planinsql, $planparams) = $DB->get_in_or_equal($competencyids,
                SQL_PARAMS_NAMED, 'ecpplancomp');
            $planparams['companyid'] = $companyid;
            $planparams['userid'] = $userid;
            $sql = "SELECT item.id, item.planid, item.competencyid, item.courseid,
                           item.currentlevel, item.targetlevel, item.priority,
                           item.status AS itemstatus, item.duedate, item.timecompleted,
                           item.timecreated, item.timemodified,
                           plan.title AS plantitle, plan.status AS planstatus,
                           course.fullname AS coursename
                      FROM {local_kbacorp_devitem} item
                      JOIN {local_kbacorp_devplan} plan ON plan.id = item.planid
                 LEFT JOIN {course} course ON course.id = item.courseid
                     WHERE plan.companyid = :companyid
                       AND plan.userid = :userid
                       AND item.competencyid {$planinsql}
                  ORDER BY item.timemodified DESC, item.id DESC";
            foreach ($DB->get_records_sql($sql, $planparams) as $record) {
                $competencyid = (int)$record->competencyid;
                $details = get_string('employeeprofileeventplantarget', 'local_kbacorporate',
                    competencies::level_label((int)$record->targetlevel));
                if (!empty($record->coursename)) {
                    $details .= ' · ' . format_string((string)$record->coursename);
                }
                $history[$competencyid][] = self::event([
                    'id' => 'study-plan-' . (int)$record->id,
                    'competencyid' => $competencyid,
                    'kind' => 'plan',
                    'source' => self::SOURCE_PLAN,
                    'title' => format_string((string)$record->plantitle),
                    'details' => $details,
                    'level' => null,
                    'targetlevel' => (int)$record->targetlevel,
                    'status' => (string)$record->itemstatus,
                    'timecreated' => (int)$record->timecompleted ?: (int)$record->timemodified ?:
                        (int)$record->timecreated,
                    'url' => (new \moodle_url('/local/kbacorporate/development_plan.php', [
                        'id' => (int)$record->planid,
                    ]))->out(false),
                ]);
            }
        }

        foreach ($history as $competencyid => $events) {
            $history[$competencyid] = self::deduplicate_events($events);
        }
        return $history;
    }

    /** Current KBA development-plan item per competency. */
    private static function load_plan_map(int $companyid, int $userid, array $competencyids): array {
        global $DB;

        $competencyids = array_values(array_unique(array_filter(array_map('intval', $competencyids))));
        if (!$competencyids) {
            return [];
        }
        $dbman = $DB->get_manager();
        if (!$dbman->table_exists(new \xmldb_table('local_kbacorp_devplan')) ||
                !$dbman->table_exists(new \xmldb_table('local_kbacorp_devitem'))) {
            return [];
        }

        list($insql, $params) = $DB->get_in_or_equal($competencyids, SQL_PARAMS_NAMED, 'ecpplanmap');
        $params['companyid'] = $companyid;
        $params['userid'] = $userid;
        $sql = "SELECT item.id, item.planid, item.competencyid, item.courseid,
                       item.targetlevel, item.priority, item.status, item.duedate,
                       item.timecompleted, plan.title, plan.status AS planstatus,
                       plan.moodleplanid, course.fullname AS coursename
                  FROM {local_kbacorp_devitem} item
                  JOIN {local_kbacorp_devplan} plan ON plan.id = item.planid
             LEFT JOIN {course} course ON course.id = item.courseid
                 WHERE plan.companyid = :companyid
                   AND plan.userid = :userid
                   AND item.competencyid {$insql}
              ORDER BY CASE plan.status
                         WHEN 'active' THEN 1
                         WHEN 'pending' THEN 2
                         WHEN 'draft' THEN 3
                         ELSE 4
                       END,
                       plan.timemodified DESC,
                       item.timemodified DESC";
        $records = $DB->get_records_sql($sql, $params);
        $result = [];
        foreach ($records as $record) {
            $competencyid = (int)$record->competencyid;
            if (!isset($result[$competencyid])) {
                $result[$competencyid] = $record;
            }
        }
        return $result;
    }

    /** Choose the latest explicit assessment event for the row. */
    private static function latest_assessment_event(array $history, \stdClass $snapshotrow): ?\stdClass {
        foreach ($history as $event) {
            if ((string)$event->kind !== 'assessment') {
                continue;
            }
            if (in_array((string)$event->status, [competency_audits::ITEM_REJECTED,
                    competency_audits::ITEM_MISSING], true)) {
                continue;
            }
            return $event;
        }

        if (!empty($snapshotrow->latestassessment)) {
            $latest = $snapshotrow->latestassessment;
            return self::event([
                'id' => 'snapshot-latest-' . (int)$snapshotrow->competencyid,
                'competencyid' => (int)$snapshotrow->competencyid,
                'kind' => 'assessment',
                'source' => in_array((string)$latest->type, ['audit', 'ai'], true)
                    ? (string)$latest->type : self::SOURCE_MOODLE,
                'title' => (string)($latest->text ?? ''),
                'details' => '',
                'level' => null,
                'status' => !empty($latest->confirmed) ? 'confirmed' : 'recorded',
                'timecreated' => (int)($latest->timecreated ?? 0),
                'actorid' => (int)($latest->assessorid ?? 0),
                'actorname' => (string)($latest->assessorname ?? ''),
            ]);
        }
        return null;
    }

    /** Create one normalised history event. */
    private static function event(array $values): \stdClass {
        $defaults = [
            'id' => '',
            'competencyid' => 0,
            'kind' => 'evidence',
            'source' => self::SOURCE_NONE,
            'title' => '',
            'details' => '',
            'level' => null,
            'targetlevel' => null,
            'status' => 'recorded',
            'timecreated' => 0,
            'actorid' => 0,
            'actorname' => '',
            'url' => '',
            'externalurl' => '',
        ];
        return (object)array_merge($defaults, $values);
    }

    /** Resolve a translated Moodle evidence description without leaking raw JSON. */
    private static function core_evidence_text(\stdClass $record): string {
        if (!empty($record->descidentifier) && !empty($record->desccomponent)) {
            try {
                $a = null;
                if (!empty($record->desca)) {
                    $decoded = json_decode((string)$record->desca);
                    $a = $decoded === null ? (string)$record->desca : $decoded;
                }
                return trim((string)get_string((string)$record->descidentifier,
                    (string)$record->desccomponent, $a));
            } catch (\Throwable $e) {
                // Fall through to the note or identifier.
            }
        }
        $note = trim(html_to_text((string)($record->note ?? ''), 0, false));
        if ($note !== '') {
            return $note;
        }
        return trim((string)($record->descidentifier ?? ''));
    }

    /** Classify a core evidence row into a user-facing source family. */
    private static function classify_evidence_source(string $component, string $identifier,
            string $text): string {
        $haystack = \core_text::strtolower($component . ' ' . $identifier . ' ' . $text);
        if (strpos($haystack, 'aiassessor') !== false || strpos($haystack, 'ai assessor') !== false ||
                strpos($haystack, 'ai оцен') !== false) {
            return self::SOURCE_AI;
        }
        if (strpos($haystack, 'audit') !== false || strpos($haystack, 'аудит') !== false) {
            return self::SOURCE_AUDIT;
        }
        if (strpos($haystack, 'course') !== false || strpos($haystack, 'курс') !== false ||
                strpos($haystack, 'completion') !== false || strpos($component, 'mod_') === 0) {
            return self::SOURCE_COURSE;
        }
        return self::SOURCE_MOODLE;
    }

    private static function name_from_parts(string $firstname, string $lastname): string {
        return trim($firstname . ' ' . $lastname);
    }

    private static function safe_url(string $url): string {
        $url = trim($url);
        if ($url === '') {
            return '';
        }
        return clean_param($url, PARAM_URL) ?: '';
    }
}
