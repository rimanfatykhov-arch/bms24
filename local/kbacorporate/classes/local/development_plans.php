<?php
namespace local_kbacorporate\local;

defined('MOODLE_INTERNAL') || die();

/** Individual development plans generated from company-scoped role gaps. */
class development_plans {
    public const STATUS_DRAFT = 'draft';
    public const STATUS_PENDING = 'pending';
    public const STATUS_ACTIVE = 'active';
    public const STATUS_COMPLETED = 'completed';
    public const STATUS_CANCELLED = 'cancelled';
    public const ITEM_PLANNED = 'planned';
    public const ITEM_INPROGRESS = 'inprogress';
    public const ITEM_PENDING_REVIEW = 'pendingreview';
    public const ITEM_COMPLETED = 'completed';

    /** Select every Moodle name field from an already-scoped user join. */
    private static function name_fields_sql(string $alias, string $prefix = ''): string {
        return implode(', ', array_map(static function(string $field) use ($alias, $prefix): string {
            return $alias . '.' . $field . ($prefix === '' ? '' : ' AS ' . $prefix . $field);
        }, \core_user\fields::get_name_fields()));
    }

    /** Render actual projected fields, including phonetic/middle/alternate names. */
    private static function joined_fullname(\stdClass $record, string $prefix): string {
        $user = new \stdClass();
        foreach (\core_user\fields::get_name_fields() as $field) {
            $user->$field = (string)($record->{$prefix . $field} ?? '');
        }
        return fullname($user);
    }

    public static function available(): bool {
        global $DB;
        $dbman = $DB->get_manager();
        return $dbman->table_exists(new \xmldb_table('local_kbacorp_devplan')) &&
            $dbman->table_exists(new \xmldb_table('local_kbacorp_devitem'));
    }

    /** Whether the v0.32.3 operational history table is available. */
    public static function history_available(): bool {
        global $DB;
        return $DB->get_manager()->table_exists(new \xmldb_table('local_kbacorp_devlog'));
    }

    /** Status labels supported by the individual development action workflow. */
    public static function item_statuses(): array {
        return [
            self::ITEM_PLANNED,
            self::ITEM_INPROGRESS,
            self::ITEM_PENDING_REVIEW,
            self::ITEM_COMPLETED,
        ];
    }

    /** Company members who may be assigned as a plan or action mentor. */
    public static function mentor_options(int $companyid): array {
        global $DB;
        $usernames = self::name_fields_sql('u');
        $sql = "SELECT DISTINCT u.id, {$usernames}, u.email
                  FROM {local_kbacorp_member} m
                  JOIN {user} u ON u.id = m.userid
                 WHERE m.companyid = :companyid
                   AND u.deleted = 0
                   AND u.suspended = 0
              ORDER BY u.lastname, u.firstname, u.id";
        $options = [];
        foreach ($DB->get_records_sql($sql, ['companyid' => $companyid]) as $user) {
            $options[(int)$user->id] = fullname($user);
        }
        return $options;
    }

    /** Build a readable default development goal for a competency. */
    public static function default_item_goal(string $competencyname, int $targetlevel): string {
        return get_string('iprdefaultitemgoal', 'local_kbacorporate', (object)[
            'competency' => $competencyname,
            'level' => competencies::level_label($targetlevel),
        ]);
    }

    /** Append an immutable operational event to the individual development-plan history. */
    public static function log_event(int $planid, int $itemid, int $actorid, string $eventtype,
            string $fromstatus = '', string $tostatus = '', string $comment = '', array $details = []): void {
        global $DB;
        if (!self::history_available()) {
            return;
        }
        $eventtype = trim($eventtype);
        if ($eventtype === '') {
            return;
        }
        if ($actorid > 0 && !$DB->record_exists('user', ['id' => $actorid, 'deleted' => 0])) {
            $actorid = 0;
        }
        $detailsjson = $details ? json_encode($details, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : null;
        $DB->insert_record('local_kbacorp_devlog', (object)[
            'planid' => $planid,
            'itemid' => max(0, $itemid),
            'actorid' => max(0, $actorid),
            'eventtype' => \core_text::substr($eventtype, 0, 50),
            'fromstatus' => $fromstatus === '' ? null : \core_text::substr($fromstatus, 0, 20),
            'tostatus' => $tostatus === '' ? null : \core_text::substr($tostatus, 0, 20),
            'comment' => trim($comment) === '' ? null : trim($comment),
            'detailsjson' => $detailsjson ?: null,
            'timecreated' => time(),
        ]);
    }

    /** Return newest-first operational history for one individual development plan. */
    public static function get_history(int $planid): array {
        global $DB, $USER;
        if (!self::history_available()) {
            return [];
        }
        $usernames = self::name_fields_sql('u');
        $revocationwhere = access::not_revoked_sql('rp.companyid', (int)($USER->id ?? 0));
        $sql = "SELECT l.*, {$usernames}, u.email
                  FROM {local_kbacorp_devlog} l
                  JOIN {local_kbacorp_devplan} rp ON rp.id = l.planid
             LEFT JOIN {user} u ON u.id = l.actorid
                 WHERE {$revocationwhere} AND l.planid = :planid
              ORDER BY l.timecreated DESC, l.id DESC";
        $records = array_values($DB->get_records_sql($sql, ['planid' => $planid]));
        foreach ($records as $record) {
            $record->details = [];
            if (!empty($record->detailsjson)) {
                $decoded = json_decode($record->detailsjson, true);
                if (is_array($decoded)) {
                    $record->details = $decoded;
                }
            }
        }
        return $records;
    }

    /** Update the plan-level management context of an editable individual development plan. */
    public static function update_ipr_plan(int $planid, string $title, string $objective,
            int $mentorid, int $targetdate, int $reviewdate, int $actorid): void {
        global $DB;
        $plan = $DB->get_record('local_kbacorp_devplan', ['id' => $planid], '*', MUST_EXIST);
        access::require_not_revoked((int)$plan->companyid, $actorid);
        self::require_draft($plan);
        $title = trim($title);
        $objective = trim($objective);
        if ($title === '' || \core_text::strlen($title) > 255) {
            throw new \invalid_parameter_exception('Invalid individual development-plan title.');
        }
        if (\core_text::strlen($objective) > 20000) {
            throw new \invalid_parameter_exception('Individual development-plan objective is too long.');
        }
        if ($targetdate <= 0 || $targetdate < (int)$plan->startdate) {
            throw new \invalid_parameter_exception('Individual development-plan target date is invalid.');
        }
        $latestitemduedate = (int)$DB->get_field_sql(
            'SELECT MAX(duedate) FROM {local_kbacorp_devitem} WHERE planid = :planid',
            ['planid' => $planid]
        );
        if ($latestitemduedate > 0 && $targetdate < $latestitemduedate) {
            throw new \moodle_exception('iprplantargetbeforeitems', 'local_kbacorporate');
        }
        if ($reviewdate > 0 && ($reviewdate < (int)$plan->startdate || $reviewdate > $targetdate)) {
            throw new \invalid_parameter_exception('Individual development-plan review date is invalid.');
        }
        self::validate_mentor((int)$plan->companyid, $mentorid);

        $before = [
            'title' => (string)$plan->title,
            'objective' => (string)($plan->objective ?? ''),
            'mentorid' => (int)($plan->mentorid ?? 0),
            'targetdate' => (int)$plan->targetdate,
            'reviewdate' => (int)($plan->reviewdate ?? 0),
        ];
        $plan->title = $title;
        $plan->objective = $objective === '' ? null : $objective;
        $plan->mentorid = $mentorid;
        $plan->targetdate = $targetdate;
        $plan->reviewdate = $reviewdate;
        $plan->timemodified = time();
        $DB->update_record('local_kbacorp_devplan', $plan);
        self::log_event($planid, 0, $actorid, 'plan_updated', '', '', '', [
            'before' => $before,
            'after' => [
                'title' => $title,
                'objective' => $objective,
                'mentorid' => $mentorid,
                'targetdate' => $targetdate,
                'reviewdate' => $reviewdate,
            ],
        ]);
        self::sync_moodle_plan_if_available($planid);
    }

    /** Update development goal, practice, evidence requirements and responsibility for one draft action. */
    public static function update_ipr_item(int $itemid, int $courseid, int $duedate, string $status,
            string $goal, string $practicaltask, string $evidencecriteria, int $mentorid,
            bool $required, int $checkpointdate, int $actorid): void {
        global $DB;
        $item = $DB->get_record('local_kbacorp_devitem', ['id' => $itemid], '*', MUST_EXIST);
        $plan = $DB->get_record('local_kbacorp_devplan', ['id' => $item->planid], '*', MUST_EXIST);
        access::require_not_revoked((int)$plan->companyid, $actorid);
        self::require_draft($plan);
        self::validate_course((int)$plan->companyid, $courseid);
        self::validate_mentor((int)$plan->companyid, $mentorid);
        if ($duedate <= 0 || $duedate < (int)$plan->startdate || $duedate > (int)$plan->targetdate) {
            throw new \invalid_parameter_exception('Individual development action due date is invalid.');
        }
        if ($checkpointdate > 0 && ($checkpointdate < (int)$plan->startdate || $checkpointdate > $duedate)) {
            throw new \invalid_parameter_exception('Individual development action checkpoint date is invalid.');
        }
        if (!in_array($status, [self::ITEM_PLANNED, self::ITEM_INPROGRESS], true)) {
            throw new \invalid_parameter_exception('Unknown editable individual development action status.');
        }
        $goal = trim($goal);
        $practicaltask = trim($practicaltask);
        $evidencecriteria = trim($evidencecriteria);
        if ($goal === '') {
            throw new \moodle_exception('iprgoalrequired', 'local_kbacorporate');
        }
        if (\core_text::strlen($goal) > 5000 || \core_text::strlen($practicaltask) > 20000 ||
                \core_text::strlen($evidencecriteria) > 10000) {
            throw new \invalid_parameter_exception('Individual development action text is too long.');
        }

        $fromstatus = (string)$item->status;
        $before = [
            'courseid' => (int)$item->courseid,
            'duedate' => (int)$item->duedate,
            'status' => $fromstatus,
            'goal' => (string)($item->goal ?? ''),
            'practicaltask' => (string)($item->practicaltask ?? ''),
            'evidencecriteria' => (string)($item->evidencecriteria ?? ''),
            'mentorid' => (int)($item->mentorid ?? 0),
            'required' => (int)($item->required ?? 1),
            'checkpointdate' => (int)($item->checkpointdate ?? 0),
        ];
        $item->courseid = $courseid;
        $item->duedate = $duedate;
        $item->status = $status;
        $item->goal = $goal;
        $item->practicaltask = $practicaltask === '' ? null : $practicaltask;
        $item->evidencecriteria = $evidencecriteria === '' ? null : $evidencecriteria;
        $item->mentorid = $mentorid;
        $item->required = $required ? 1 : 0;
        $item->checkpointdate = $checkpointdate;
        $item->timecompleted = 0;
        $item->timemodified = time();
        $DB->update_record('local_kbacorp_devitem', $item);
        self::log_event((int)$item->planid, $itemid, $actorid, 'item_updated',
            $fromstatus, $status, '', [
                'competencyid' => (int)$item->competencyid,
                'before' => $before,
                'after' => [
                    'courseid' => $courseid,
                    'duedate' => $duedate,
                    'status' => $status,
                    'goal' => $goal,
                    'practicaltask' => $practicaltask,
                    'evidencecriteria' => $evidencecriteria,
                    'mentorid' => $mentorid,
                    'required' => $required ? 1 : 0,
                    'checkpointdate' => $checkpointdate,
                ],
            ]);
        self::refresh_plan((int)$item->planid);
        self::sync_moodle_plan_if_available((int)$item->planid);
    }

    /** Start an active development action without changing competency evidence. */
    public static function mark_item_in_progress(int $itemid, int $actorid): void {
        global $DB;
        $item = $DB->get_record('local_kbacorp_devitem', ['id' => $itemid], '*', MUST_EXIST);
        $plan = $DB->get_record('local_kbacorp_devplan', ['id' => $item->planid], '*', MUST_EXIST);
        access::require_not_revoked((int)$plan->companyid, $actorid);
        if ((string)$plan->status !== self::STATUS_ACTIVE) {
            throw new \moodle_exception('developmentplanstatuslocked', 'local_kbacorporate');
        }
        if ((string)$item->status === self::ITEM_INPROGRESS) {
            return;
        }
        if ((string)$item->status !== self::ITEM_PLANNED) {
            throw new \moodle_exception('ipritemtransitioninvalid', 'local_kbacorporate');
        }
        $fromstatus = (string)$item->status;
        $item->status = self::ITEM_INPROGRESS;
        $item->timemodified = time();
        $DB->update_record('local_kbacorp_devitem', $item);
        self::log_event((int)$item->planid, $itemid, $actorid, 'item_started',
            $fromstatus, self::ITEM_INPROGRESS, '', ['competencyid' => (int)$item->competencyid]);
        self::refresh_plan((int)$item->planid);
    }

    /** Submit practical evidence for manager confirmation. */
    public static function submit_item_evidence(int $itemid, int $actorid,
            string $evidenceurl, string $evidencecomment): void {
        global $DB;
        $item = $DB->get_record('local_kbacorp_devitem', ['id' => $itemid], '*', MUST_EXIST);
        $plan = $DB->get_record('local_kbacorp_devplan', ['id' => $item->planid], '*', MUST_EXIST);
        access::require_not_revoked((int)$plan->companyid, $actorid);
        if ((string)$plan->status !== self::STATUS_ACTIVE) {
            throw new \moodle_exception('developmentplanstatuslocked', 'local_kbacorporate');
        }
        if (!in_array((string)$item->status, [self::ITEM_PLANNED, self::ITEM_INPROGRESS], true)) {
            throw new \moodle_exception('iprevidencecannotsubmit', 'local_kbacorporate');
        }
        $evidenceurl = trim($evidenceurl);
        $evidencecomment = trim($evidencecomment);
        if ($evidenceurl === '' && $evidencecomment === '') {
            throw new \moodle_exception('iprevidencerequired', 'local_kbacorporate');
        }
        if (\core_text::strlen($evidenceurl) > 1333 || \core_text::strlen($evidencecomment) > 20000) {
            throw new \invalid_parameter_exception('Individual development evidence is too long.');
        }
        $fromstatus = (string)$item->status;
        $now = time();
        $item->evidenceurl = $evidenceurl === '' ? null : $evidenceurl;
        $item->evidencecomment = $evidencecomment === '' ? null : $evidencecomment;
        $item->evidencesubmittedby = $actorid;
        $item->timeevidencesubmitted = $now;
        $item->reviewedby = 0;
        $item->timereviewed = 0;
        $item->reviewcomment = null;
        $item->status = self::ITEM_PENDING_REVIEW;
        $item->timecompleted = 0;
        $item->timemodified = $now;
        $DB->update_record('local_kbacorp_devitem', $item);
        self::log_event((int)$item->planid, $itemid, $actorid, 'evidence_submitted',
            $fromstatus, self::ITEM_PENDING_REVIEW, $evidencecomment, [
                'competencyid' => (int)$item->competencyid,
                'evidenceurl' => $evidenceurl,
            ]);
        self::refresh_plan((int)$item->planid);
    }

    /** Approve or return submitted practical evidence. */
    public static function review_item_evidence(int $itemid, int $actorid,
            bool $approved, string $reviewcomment): void {
        global $DB;
        $item = $DB->get_record('local_kbacorp_devitem', ['id' => $itemid], '*', MUST_EXIST);
        $plan = $DB->get_record('local_kbacorp_devplan', ['id' => $item->planid], '*', MUST_EXIST);
        access::require_not_revoked((int)$plan->companyid, $actorid);
        if ((string)$plan->status !== self::STATUS_ACTIVE ||
                (string)$item->status !== self::ITEM_PENDING_REVIEW) {
            throw new \moodle_exception('iprevidencecannotreview', 'local_kbacorporate');
        }
        if ($actorid > 0 && (int)$item->evidencesubmittedby === $actorid) {
            throw new \moodle_exception('iprevidenceselfreview', 'local_kbacorporate');
        }
        $reviewcomment = trim($reviewcomment);
        if (!$approved && $reviewcomment === '') {
            throw new \moodle_exception('iprreviewcommentrequired', 'local_kbacorporate');
        }
        if (\core_text::strlen($reviewcomment) > 20000) {
            throw new \invalid_parameter_exception('Individual development evidence review is too long.');
        }
        $now = time();
        $newstatus = $approved ? self::ITEM_COMPLETED : self::ITEM_INPROGRESS;
        $item->reviewedby = $actorid;
        $item->timereviewed = $now;
        $item->reviewcomment = $reviewcomment === '' ? null : $reviewcomment;
        $item->status = $newstatus;
        $item->timecompleted = $approved ? $now : 0;
        $item->timemodified = $now;
        $DB->update_record('local_kbacorp_devitem', $item);
        self::log_event((int)$item->planid, $itemid, $actorid,
            $approved ? 'evidence_approved' : 'evidence_rejected',
            self::ITEM_PENDING_REVIEW, $newstatus, $reviewcomment, [
                'competencyid' => (int)$item->competencyid,
            ]);
        self::refresh_plan((int)$item->planid);
    }

    /** Record the next control point and its management note. */
    public static function record_checkpoint(int $itemid, int $actorid,
            int $checkpointdate, string $comment): void {
        global $DB;
        $item = $DB->get_record('local_kbacorp_devitem', ['id' => $itemid], '*', MUST_EXIST);
        $plan = $DB->get_record('local_kbacorp_devplan', ['id' => $item->planid], '*', MUST_EXIST);
        access::require_not_revoked((int)$plan->companyid, $actorid);
        if (!in_array((string)$plan->status, [self::STATUS_DRAFT, self::STATUS_ACTIVE], true)) {
            throw new \moodle_exception('developmentplanstatuslocked', 'local_kbacorporate');
        }
        $comment = trim($comment);
        if ($checkpointdate <= 0 || $checkpointdate < (int)$plan->startdate ||
                $checkpointdate > (int)$item->duedate) {
            throw new \invalid_parameter_exception('Individual development checkpoint date is invalid.');
        }
        if ($comment === '') {
            throw new \moodle_exception('iprcheckpointcommentrequired', 'local_kbacorporate');
        }
        $item->checkpointdate = $checkpointdate;
        $item->checkpointcomment = $comment;
        $item->timemodified = time();
        $DB->update_record('local_kbacorp_devitem', $item);
        self::log_event((int)$item->planid, $itemid, $actorid, 'checkpoint_recorded',
            (string)$item->status, (string)$item->status, $comment, [
                'competencyid' => (int)$item->competencyid,
                'checkpointdate' => $checkpointdate,
            ]);
    }

    /** Whether a user is still eligible to act as a mentor in this company. */
    public static function is_valid_mentor_assignment(int $companyid, int $mentorid): bool {
        global $DB;
        if ($companyid <= 0 || $mentorid <= 0) {
            return false;
        }
        return $DB->record_exists('local_kbacorp_member', [
                'companyid' => $companyid,
                'userid' => $mentorid,
            ]) && $DB->record_exists('user', [
                'id' => $mentorid,
                'deleted' => 0,
                'suspended' => 0,
            ]);
    }

    /** Validate one mentor against the current company membership. */
    private static function validate_mentor(int $companyid, int $mentorid): void {
        if ($mentorid <= 0) {
            return;
        }
        if (!self::is_valid_mentor_assignment($companyid, $mentorid)) {
            throw new \moodle_exception('iprmentorunavailable', 'local_kbacorporate');
        }
    }

    /**
     * Return the deterministic learning-plan phase and suggested deadline for a role priority.
     *
     * The helper is public so the schedule contract can be covered by PHPUnit without database fixtures.
     */
    public static function learning_plan_schedule(string $priority): \stdClass {
        $priority = business_roles::normalise_priority($priority);
        $config = [
            business_roles::PRIORITY_CRITICAL => ['phase' => 1, 'days' => 30],
            business_roles::PRIORITY_CORE => ['phase' => 2, 'days' => 60],
            business_roles::PRIORITY_ADDITIONAL => ['phase' => 3, 'days' => 90],
        ];
        $selected = $config[$priority] ?? $config[business_roles::PRIORITY_CORE];
        return (object)[
            'priority' => $priority,
            'phase' => (int)$selected['phase'],
            'days' => (int)$selected['days'],
        ];
    }

    /**
     * Preview a non-destructive study-plan build from confirmed employee competency gaps.
     *
     * Confirmed gaps are eligible for selection. Unassessed and pending competencies stay visible
     * as blocked rows and are never silently treated as level zero. Existing plan items are not removed.
     */
    public static function preview_gap_learning_plan(int $companyid, int $userid): \stdClass {
        global $DB;
        if (!$DB->record_exists('local_kbacorp_member', [
            'companyid' => $companyid, 'userid' => $userid, 'isemployee' => 1,
        ])) {
            throw new \invalid_parameter_exception('Learning-plan subject is not a company employee.');
        }

        $projection = employee_gap::calculate($companyid, $userid);
        $requirements = array_values(array_filter($projection->rows, static function($row) {
            return in_array((string)$row->status, [
                employee_gap::ROW_GAP,
                employee_gap::ROW_UNASSESSED,
                employee_gap::ROW_PENDING,
            ], true);
        }));

        $plan = $DB->get_record_select('local_kbacorp_devplan',
            'companyid = :companyid AND userid = :userid AND status IN (:draft, :pending, :active)', [
                'companyid' => $companyid,
                'userid' => $userid,
                'draft' => self::STATUS_DRAFT,
                'pending' => self::STATUS_PENDING,
                'active' => self::STATUS_ACTIVE,
            ], '*', IGNORE_MULTIPLE);

        $existingitems = [];
        if ($plan) {
            foreach (self::get_items((int)$plan->id) as $item) {
                $existingitems[(int)$item->competencyid] = $item;
            }
        }

        $now = time();
        $planstart = $plan && !empty($plan->startdate) ? (int)$plan->startdate : usergetmidnight($now);
        $plantarget = $plan && !empty($plan->targetdate) ? (int)$plan->targetdate : $planstart + (90 * DAYSECS);
        $planlocked = $plan && (string)$plan->status !== self::STATUS_DRAFT;

        $rows = [];
        $counts = (object)[
            'gaps' => 0,
            'eligible' => 0,
            'unassessed' => 0,
            'pending' => 0,
            'blocked' => 0,
            'withcourse' => 0,
            'withoutcourse' => 0,
            'mandatorywithoutcourse' => 0,
            'create' => 0,
            'update' => 0,
            'unchanged' => 0,
            'locked' => 0,
            'retained' => 0,
        ];
        $currentgapids = [];
        $sequence = 0;

        foreach ($requirements as $row) {
            $competencyid = (int)$row->competencyid;
            $status = (string)$row->status;
            $eligible = $status === employee_gap::ROW_GAP && $row->gap !== null && (int)$row->gap > 0;
            $priority = business_roles::normalise_priority((string)$row->priority);
            $required = !empty($row->required);
            $schedule = self::learning_plan_schedule($priority);
            $existing = $existingitems[$competencyid] ?? null;

            $course = !empty($row->courses) ? reset($row->courses) : null;
            $courseid = $course ? (int)$course->courseid : 0;
            $coursename = $course ? (string)$course->fullname : '';
            $courseurl = $course ? (string)$course->url : '';
            $courseprogress = $course ? $course->progress : null;
            $courseenrolmentstatus = $course ? (string)$course->enrolmentstatus : '';
            if ($courseid <= 0 && $existing && (int)$existing->courseid > 0) {
                // Preserve an explicitly selected course already stored in the draft even when
                // the current recommendation query cannot expose it to this user.
                $courseid = (int)$existing->courseid;
                $coursename = (string)($existing->coursename ?? '');
                $courseurl = (new \moodle_url('/course/view.php', ['id' => $courseid]))->out(false);
                $courseenrolmentstatus = 'existing';
            }
            $coursecovered = $courseid > 0;

            $action = 'blocked';
            $warning = '';
            $rowsequence = 0;
            if ($eligible) {
                $counts->gaps++;
                $counts->eligible++;
                $currentgapids[$competencyid] = true;
                $rowsequence = ++$sequence;
                if ($coursecovered) {
                    $counts->withcourse++;
                } else {
                    $counts->withoutcourse++;
                    if ($required) {
                        $counts->mandatorywithoutcourse++;
                        $warning = 'mandatorynocourse';
                    } else {
                        $warning = 'nocourse';
                    }
                }

                $action = 'create';
                if ($existing) {
                    $samecurrent = ($existing->currentlevel === null && $row->currentlevel === null) ||
                        ($existing->currentlevel !== null && $row->currentlevel !== null &&
                            (int)$existing->currentlevel === (int)$row->currentlevel);
                    $samecourse = (int)$existing->courseid === $courseid ||
                        ((int)$existing->courseid > 0 && $courseid === 0);
                    $changed = !$samecurrent || (int)$existing->targetlevel !== (int)$row->targetlevel ||
                        (string)$existing->priority !== $priority || !$samecourse;
                    if (!$changed) {
                        $action = 'unchanged';
                    } else if ($planlocked) {
                        $action = 'locked';
                    } else {
                        $action = 'update';
                    }
                } else if ($planlocked) {
                    $action = 'locked';
                }
            } else {
                $counts->blocked++;
                if ($status === employee_gap::ROW_PENDING) {
                    $counts->pending++;
                    $warning = 'pendingassessment';
                } else {
                    $counts->unassessed++;
                    $warning = 'needsassessment';
                }
            }
            $counts->{$action}++;

            $suggestedduedate = $planstart + ((int)$schedule->days * DAYSECS);
            if ($plantarget > 0) {
                $suggestedduedate = min($suggestedduedate, $plantarget);
            }
            $duedate = $existing && !empty($existing->duedate) ? (int)$existing->duedate : $suggestedduedate;

            $rows[] = (object)[
                'competencyid' => $competencyid,
                'shortname' => (string)$row->shortname,
                'idnumber' => (string)$row->idnumber,
                'currentlevel' => $row->currentlevel,
                'provisionallevel' => $row->provisionallevel,
                'targetlevel' => (int)$row->targetlevel,
                'gap' => $row->gap,
                'priority' => $priority,
                'required' => $required,
                'roles' => (array)$row->roles,
                'courseid' => $courseid,
                'coursename' => $coursename,
                'courseurl' => $courseurl,
                'courseprogress' => $courseprogress,
                'courseenrolmentstatus' => $courseenrolmentstatus,
                'eligible' => $eligible,
                'sequence' => $rowsequence,
                'phase' => (int)$schedule->phase,
                'action' => $action,
                'warning' => $warning,
                'duedate' => $duedate,
            ];
        }

        foreach ($existingitems as $competencyid => $item) {
            if (!isset($currentgapids[$competencyid])) {
                $counts->retained++;
            }
        }

        if ($planlocked) {
            $readiness = 'locked';
        } else if ($projection->state === employee_gap::STATE_NO_ROLES) {
            $readiness = 'noroles';
        } else if ($projection->state === employee_gap::STATE_EMPTY_PROFILE) {
            $readiness = 'emptyprofile';
        } else if (!$requirements) {
            $readiness = 'nogaps';
        } else if ($counts->eligible === 0 && $counts->blocked > 0) {
            $readiness = 'needsassessment';
        } else if ($counts->mandatorywithoutcourse > 0) {
            $readiness = 'missingmandatorycourses';
        } else if ($counts->blocked > 0) {
            $readiness = 'partialassessment';
        } else if ($counts->withoutcourse > 0) {
            $readiness = 'missingcourses';
        } else {
            $readiness = 'ready';
        }

        return (object)[
            'companyid' => $companyid,
            'userid' => $userid,
            'plan' => $plan ?: null,
            'locked' => (bool)$planlocked,
            'planstart' => $planstart,
            'plantarget' => $plantarget,
            'rows' => $rows,
            'counts' => $counts,
            'readiness' => $readiness,
            'projection' => $projection,
        ];
    }

    /**
     * Create or refresh a draft KBA development plan from current GAPs and synchronise the
     * competency membership into a standard Moodle Learning Plan.
     *
     * This operation is deliberately non-destructive: items that are no longer current GAPs are retained.
     */
    public static function build_gap_learning_plan(int $companyid, int $userid, int $createdby,
            ?array $selectedcompetencyids = null): \stdClass {
        global $DB;
        $preview = self::preview_gap_learning_plan($companyid, $userid);
        if (!$preview->rows) {
            throw new \moodle_exception('developmentplannogaps', 'local_kbacorporate');
        }
        if ($preview->locked) {
            throw new \moodle_exception('gaplearningplanlocked', 'local_kbacorporate');
        }

        $selectedmap = null;
        if ($selectedcompetencyids !== null) {
            $selectedmap = [];
            foreach ($selectedcompetencyids as $selectedid) {
                $selectedid = (int)$selectedid;
                if ($selectedid > 0) {
                    $selectedmap[$selectedid] = true;
                }
            }
        }
        $buildrows = array_values(array_filter($preview->rows, static function($row) use ($selectedmap) {
            if (empty($row->eligible)) {
                return false;
            }
            return $selectedmap === null || isset($selectedmap[(int)$row->competencyid]);
        }));
        if (!$buildrows) {
            throw new \moodle_exception('gaplearningplannoselection', 'local_kbacorporate');
        }

        $now = time();
        $transaction = $DB->start_delegated_transaction();
        $plan = $preview->plan;
        $created = false;
        if (!$plan) {
            $planid = (int)$DB->insert_record('local_kbacorp_devplan', (object)[
                'companyid' => $companyid,
                'userid' => $userid,
                'title' => get_string('developmentplantitledefault', 'local_kbacorporate', userdate($now, '%d.%m.%Y')),
                'objective' => null,
                'mentorid' => 0,
                'status' => self::STATUS_DRAFT,
                'createdby' => $createdby,
                'submittedby' => 0,
                'timesubmitted' => 0,
                'timeacknowledged' => 0,
                'employeecomment' => null,
                'lastremindersent' => 0,
                'followupauditid' => 0,
                'moodleplanid' => 0,
                'startdate' => (int)$preview->planstart,
                'targetdate' => max(array_map(static function($row) {
                    return (int)$row->duedate;
                }, $buildrows)),
                'reviewdate' => min((int)$preview->planstart + (30 * DAYSECS),
                    max(array_map(static function($row) {
                        return (int)$row->duedate;
                    }, $buildrows))),
                'timecreated' => $now,
                'timemodified' => $now,
            ]);
            $plan = $DB->get_record('local_kbacorp_devplan', ['id' => $planid], '*', MUST_EXIST);
            $created = true;
        } else {
            $planid = (int)$plan->id;
        }

        $existingitems = [];
        foreach ($DB->get_records('local_kbacorp_devitem', ['planid' => $planid]) as $item) {
            $existingitems[(int)$item->competencyid] = $item;
        }

        $added = 0;
        $updated = 0;
        $unchanged = 0;
        foreach ($buildrows as $row) {
            $existing = $existingitems[(int)$row->competencyid] ?? null;
            if (!$existing) {
                $DB->insert_record('local_kbacorp_devitem', (object)[
                    'planid' => $planid,
                    'competencyid' => (int)$row->competencyid,
                    'courseid' => (int)$row->courseid,
                    'currentlevel' => $row->currentlevel,
                    'targetlevel' => (int)$row->targetlevel,
                    'priority' => (string)$row->priority,
                    'required' => !empty($row->required) ? 1 : 0,
                    'goal' => self::default_item_goal((string)$row->shortname, (int)$row->targetlevel),
                    'practicaltask' => null,
                    'evidencecriteria' => null,
                    'mentorid' => (int)($plan->mentorid ?? 0),
                    'checkpointdate' => min((int)$row->duedate,
                        (int)$preview->planstart + (14 * DAYSECS)),
                    'checkpointcomment' => null,
                    'evidenceurl' => null,
                    'evidencecomment' => null,
                    'evidencesubmittedby' => 0,
                    'timeevidencesubmitted' => 0,
                    'reviewedby' => 0,
                    'timereviewed' => 0,
                    'reviewcomment' => null,
                    'status' => self::ITEM_PLANNED,
                    'duedate' => (int)$row->duedate,
                    'timecompleted' => 0,
                    'timecreated' => $now,
                    'timemodified' => $now,
                ]);
                $added++;
                continue;
            }

            $desiredcourseid = (int)$existing->courseid > 0 ? (int)$existing->courseid : (int)$row->courseid;
            $samecurrent = ($existing->currentlevel === null && $row->currentlevel === null) ||
                ($existing->currentlevel !== null && $row->currentlevel !== null &&
                    (int)$existing->currentlevel === (int)$row->currentlevel);
            $desiredrequired = !empty($row->required) ? 1 : 0;
            $changed = !$samecurrent || (int)$existing->targetlevel !== (int)$row->targetlevel ||
                (string)$existing->priority !== (string)$row->priority ||
                (int)$existing->required !== $desiredrequired ||
                (int)$existing->courseid !== $desiredcourseid ||
                trim((string)($existing->goal ?? '')) === '';
            if (!$changed) {
                $unchanged++;
                continue;
            }
            $existing->currentlevel = $row->currentlevel;
            $existing->targetlevel = (int)$row->targetlevel;
            $existing->priority = (string)$row->priority;
            $existing->required = $desiredrequired;
            $existing->courseid = $desiredcourseid;
            if (trim((string)($existing->goal ?? '')) === '') {
                $existing->goal = self::default_item_goal((string)$row->shortname, (int)$row->targetlevel);
            }
            if (empty($existing->mentorid) && !empty($plan->mentorid)) {
                $existing->mentorid = (int)$plan->mentorid;
            }
            if (empty($existing->checkpointdate)) {
                $existing->checkpointdate = min((int)$row->duedate,
                    (int)$preview->planstart + (14 * DAYSECS));
            }
            if (empty($existing->duedate)) {
                $existing->duedate = (int)$row->duedate;
            }
            $existing->timemodified = $now;
            $DB->update_record('local_kbacorp_devitem', $existing);
            $updated++;
        }
        $DB->set_field('local_kbacorp_devplan', 'timemodified', $now, ['id' => $planid]);
        $transaction->allow_commit();
        self::log_event($planid, 0, $createdby, $created ? 'plan_created' : 'plan_regenerated',
            '', self::STATUS_DRAFT, '', [
                'selected' => count($buildrows),
                'added' => $added,
                'updated' => $updated,
                'unchanged' => $unchanged,
            ]);

        $moodlesync = null;
        $moodleerror = '';
        if (class_exists('\\local_kbacorporate\\local\\moodle_learning_plans') && moodle_learning_plans::available()) {
            try {
                $moodlesync = moodle_learning_plans::ensure_plan($planid);
            } catch (\Throwable $e) {
                // KBA draft remains valid and can be synchronised later from the integration page.
                $moodleerror = $e->getMessage();
            }
        }

        return (object)[
            'planid' => $planid,
            'created' => $created,
            'added' => $added,
            'updated' => $updated,
            'unchanged' => $unchanged,
            'selected' => count($buildrows),
            'skipped' => max(0, (int)$preview->counts->eligible - count($buildrows)),
            'retained' => (int)$preview->counts->retained,
            'moodle' => $moodlesync,
            'moodleerror' => $moodleerror,
        ];
    }

    public static function create_from_gaps(int $companyid, int $userid, int $createdby): int {
        global $DB;
        if (!$DB->record_exists('local_kbacorp_member', [
            'companyid' => $companyid, 'userid' => $userid, 'isemployee' => 1,
        ])) {
            throw new \invalid_parameter_exception('Development-plan subject is not a company employee.');
        }
        $existing = $DB->get_record_select('local_kbacorp_devplan',
            'companyid = :companyid AND userid = :userid AND status IN (:draft, :pending, :active)', [
                'companyid' => $companyid, 'userid' => $userid, 'draft' => self::STATUS_DRAFT,
                'pending' => self::STATUS_PENDING, 'active' => self::STATUS_ACTIVE,
            ], 'id', IGNORE_MULTIPLE);
        if ($existing) {
            return (int)$existing->id;
        }
        $snapshot = competencies::get_user_snapshot($userid, $companyid, false);
        $requirements = array_values(array_filter($snapshot['competencies'], static function($row) {
            return ($row->targetsource ?? '') === 'businessrole' &&
                ($row->currentlevel === null || ($row->gap !== null && $row->gap > 0));
        }));
        if (!$requirements) {
            throw new \moodle_exception('developmentplannogaps', 'local_kbacorporate');
        }
        $now = time();
        $transaction = $DB->start_delegated_transaction();
        $planid = (int)$DB->insert_record('local_kbacorp_devplan', (object)[
            'companyid' => $companyid, 'userid' => $userid,
            'title' => get_string('developmentplantitledefault', 'local_kbacorporate', userdate($now, '%d.%m.%Y')),
            'objective' => null, 'mentorid' => 0,
            'status' => self::STATUS_DRAFT, 'createdby' => $createdby,
            'submittedby' => 0, 'timesubmitted' => 0, 'timeacknowledged' => 0,
            'employeecomment' => null, 'lastremindersent' => 0, 'followupauditid' => 0,
            'moodleplanid' => 0,
            'startdate' => $now, 'targetdate' => $now + (90 * DAYSECS),
            'reviewdate' => $now + (30 * DAYSECS),
            'timecreated' => $now, 'timemodified' => $now,
        ]);
        foreach ($requirements as $row) {
            $course = !empty($row->recommendedcourses) ? reset($row->recommendedcourses) : null;
            $DB->insert_record('local_kbacorp_devitem', (object)[
                'planid' => $planid, 'competencyid' => (int)$row->competencyid,
                'courseid' => $course ? (int)$course->courseid : 0,
                'currentlevel' => $row->currentlevel, 'targetlevel' => (int)$row->targetlevel,
                'priority' => business_roles::normalise_priority((string)$row->rolepriority),
                'required' => !empty($row->rolerequired) ? 1 : 0,
                'goal' => self::default_item_goal((string)$row->shortname, (int)$row->targetlevel),
                'practicaltask' => null, 'evidencecriteria' => null, 'mentorid' => 0,
                'checkpointdate' => $now + (14 * DAYSECS), 'checkpointcomment' => null,
                'evidenceurl' => null, 'evidencecomment' => null, 'evidencesubmittedby' => 0,
                'timeevidencesubmitted' => 0, 'reviewedby' => 0, 'timereviewed' => 0,
                'reviewcomment' => null,
                'status' => self::ITEM_PLANNED, 'duedate' => $now + (90 * DAYSECS),
                'timecompleted' => 0, 'timecreated' => $now, 'timemodified' => $now,
            ]);
        }
        $transaction->allow_commit();
        self::log_event($planid, 0, $createdby, 'plan_created', '', self::STATUS_DRAFT, '', [
            'items' => count($requirements),
            'source' => 'role_gaps',
        ]);
        self::sync_moodle_plan_if_available($planid);
        return $planid;
    }

    public static function get_plan(int $planid): \stdClass {
        global $DB;
        global $USER;
        $revocationwhere = access::not_revoked_sql('p.companyid', (int)($USER->id ?? 0));
        $usernames = self::name_fields_sql('u');
        $mentornames = self::name_fields_sql('mu', 'mentor');
        $sql = "SELECT p.*, {$usernames}, u.email, c.name AS companyname,
                       {$mentornames},
                       mu.email AS mentoremail
                  FROM {local_kbacorp_devplan} p
                  JOIN {user} u ON u.id = p.userid
                  JOIN {local_kbacorp_company} c ON c.id = p.companyid
             LEFT JOIN {user} mu ON mu.id = p.mentorid
                 WHERE p.id = :id AND {$revocationwhere}";
        $plan = $DB->get_record_sql($sql, ['id' => $planid], MUST_EXIST);
        $plan->mentorname = (int)($plan->mentorid ?? 0) > 0 ?
            self::joined_fullname($plan, 'mentor') : '';
        return $plan;
    }

    public static function get_company_plans(int $companyid): array {
        global $DB;
        global $USER;
        access::require_not_revoked($companyid, (int)($USER->id ?? 0));
        $usernames = self::name_fields_sql('u');
        $sql = "SELECT p.*, {$usernames}, u.email,
                       COUNT(i.id) AS itemcount,
                       SUM(CASE WHEN i.status = :completed THEN 1 ELSE 0 END) AS completedcount
                  FROM {local_kbacorp_devplan} p
                  JOIN {user} u ON u.id = p.userid
             LEFT JOIN {local_kbacorp_devitem} i ON i.planid = p.id
                 WHERE p.companyid = :companyid
              GROUP BY p.id, {$usernames}, u.email
              ORDER BY p.timemodified DESC";
        return array_values($DB->get_records_sql($sql, [
            'companyid' => $companyid, 'completed' => self::ITEM_COMPLETED,
        ]));
    }

    /** Company-level IPR effectiveness metrics, restricted to visible employees when supplied. */
    public static function get_company_effectiveness(int $companyid, ?array $visibleuserids = null,
            string $statusfilter = 'all', string $query = ''): \stdClass {
        $plans = self::get_company_plans($companyid);
        if ($visibleuserids !== null) {
            $visible = array_flip(array_map('intval', $visibleuserids));
            $plans = array_values(array_filter($plans, static function($plan) use ($visible) {
                return isset($visible[(int)$plan->userid]);
            }));
        }
        if ($statusfilter !== 'all') {
            $plans = array_values(array_filter($plans, static function($plan) use ($statusfilter) {
                return (string)$plan->status === $statusfilter;
            }));
        }
        $query = \core_text::strtolower(trim($query));
        if ($query !== '') {
            $plans = array_values(array_filter($plans, static function($plan) use ($query) {
                $haystack = \core_text::strtolower(fullname($plan) . ' ' . (string)$plan->email . ' ' .
                    (string)$plan->title);
                return strpos($haystack, $query) !== false;
            }));
        }
        $result = (object)[
            'plans' => count($plans), 'draft' => 0, 'pending' => 0, 'active' => 0,
            'completed' => 0, 'cancelled' => 0, 'actions' => 0, 'completedactions' => 0,
            'overdue' => 0, 'progress' => 0, 'followupaudits' => 0,
            'outcometotal' => 0, 'outcomeassessed' => 0, 'improved' => 0,
            'targetsreached' => 0, 'remaininggaps' => 0, 'rows' => [],
        ];
        foreach ($plans as $plan) {
            $status = (string)$plan->status;
            if (property_exists($result, $status)) {
                $result->{$status}++;
            }
            $items = self::get_items((int)$plan->id);
            $progress = self::get_progress_summary($items);
            $result->actions += $progress->total;
            $result->completedactions += $progress->completed;
            $result->overdue += $status === self::STATUS_ACTIVE ? $progress->overdue : 0;
            $row = (object)[
                'plan' => $plan, 'progress' => $progress, 'outcome' => null,
            ];
            if ($status === self::STATUS_COMPLETED) {
                if (!empty($plan->followupauditid)) {
                    $result->followupaudits++;
                }
                $outcome = self::get_outcome_summary((int)$plan->id);
                $row->outcome = $outcome;
                $result->outcometotal += $outcome->total;
                $result->outcomeassessed += $outcome->assessed;
                $result->improved += $outcome->improved;
                $result->targetsreached += $outcome->reached;
                $result->remaininggaps += $outcome->remaininggaps;
            }
            $result->rows[] = $row;
        }
        $result->progress = $result->actions ?
            (int)round(($result->completedactions / $result->actions) * 100) : 0;
        $result->auditcoverage = $result->completed ?
            (int)round(($result->followupaudits / $result->completed) * 100) : 0;
        $result->assessmentcoverage = $result->outcometotal ?
            (int)round(($result->outcomeassessed / $result->outcometotal) * 100) : 0;
        return $result;
    }

    /** Aggregate the already scoped effectiveness rows by current business role and department. */
    public static function get_effectiveness_breakdowns(int $companyid, array $rows): \stdClass {
        $roles = [];
        $departments = [];
        foreach ($rows as $row) {
            $plan = $row->plan;
            $userroles = business_roles::get_user_roles((int)$plan->userid, $companyid);
            $userdepartments = departments::get_user_departments($companyid, (int)$plan->userid, 'employee');
            if (!$userroles) {
                $userroles = [(object)['id' => 0, 'name' => get_string('withoutbusinessrole', 'local_kbacorporate')]];
            }
            if (!$userdepartments) {
                $userdepartments = [(object)['id' => 0, 'name' => get_string('withoutdepartment', 'local_kbacorporate')]];
            }
            foreach ($userroles as $role) {
                self::add_effectiveness_bucket($roles, (int)$role->id, format_string($role->name), $row);
            }
            foreach ($userdepartments as $department) {
                self::add_effectiveness_bucket($departments, (int)$department->id,
                    format_string($department->name), $row);
            }
        }
        self::finalise_effectiveness_buckets($roles);
        self::finalise_effectiveness_buckets($departments);
        uasort($roles, static function($a, $b) { return strcasecmp($a->name, $b->name); });
        uasort($departments, static function($a, $b) { return strcasecmp($a->name, $b->name); });
        return (object)['roles' => array_values($roles), 'departments' => array_values($departments)];
    }

    /**
     * Build immutable before/after dynamics from follow-up audits linked to the already scoped plans.
     *
     * The baseline is the level stored in the development-plan item. The final value is the
     * confirmed level in that plan's follow-up audit, so later Moodle grades cannot rewrite history.
     */
    public static function get_effectiveness_timeline(array $rows, int $from = 0, int $to = 0): \stdClass {
        global $DB;
        $planids = [];
        foreach ($rows as $row) {
            if ((string)$row->plan->status === self::STATUS_COMPLETED && !empty($row->plan->followupauditid)) {
                $planids[] = (int)$row->plan->id;
            }
        }
        if (!$planids) {
            return self::summarise_effectiveness_timeline([]);
        }
        list($insql, $params) = $DB->get_in_or_equal($planids, SQL_PARAMS_NAMED, 'timelineplan');
        $params['confirmed'] = competency_audits::ITEM_CONFIRMED;
        $timewhere = '';
        if ($from > 0) {
            $timewhere .= ' AND ai.timeconfirmed >= :timelinefrom';
            $params['timelinefrom'] = $from;
        }
        if ($to > 0) {
            $timewhere .= ' AND ai.timeconfirmed <= :timelineto';
            $params['timelineto'] = $to;
        }
        $sql = "SELECT ai.id, p.id AS planid, p.userid, ai.competencyid,
                       di.currentlevel AS baseline, di.targetlevel,
                       ai.proposedlevel AS finallevel, ai.timeconfirmed
                  FROM {local_kbacorp_devplan} p
                  JOIN {local_kbacorp_devitem} di ON di.planid = p.id
                  JOIN {local_kbacorp_audit_item} ai
                    ON ai.auditid = p.followupauditid AND ai.competencyid = di.competencyid
                 WHERE p.id {$insql} AND ai.status = :confirmed
                       AND ai.timeconfirmed > 0{$timewhere}
              ORDER BY ai.timeconfirmed ASC, ai.id ASC";
        return self::summarise_effectiveness_timeline(array_values($DB->get_records_sql($sql, $params)));
    }

    /** Aggregate confirmed before/after records into calendar-month buckets. */
    public static function summarise_effectiveness_timeline(array $records): \stdClass {
        $buckets = [];
        $totals = self::new_timeline_bucket('total', 0);
        foreach ($records as $record) {
            $timeconfirmed = (int)$record->timeconfirmed;
            $key = userdate($timeconfirmed, '%Y-%m');
            if (!isset($buckets[$key])) {
                $buckets[$key] = self::new_timeline_bucket($key, $timeconfirmed);
            }
            self::add_timeline_record($buckets[$key], $record);
            self::add_timeline_record($totals, $record);
        }
        foreach ($buckets as $bucket) {
            self::finalise_timeline_bucket($bucket);
        }
        self::finalise_timeline_bucket($totals);
        return (object)['periods' => array_values($buckets), 'totals' => $totals];
    }

    private static function new_timeline_bucket(string $key, int $periodtime): \stdClass {
        return (object)[
            'key' => $key, 'periodtime' => $periodtime, 'assessed' => 0, 'comparable' => 0,
            'improved' => 0, 'reached' => 0, 'baselinesum' => 0, 'finalsum' => 0,
            'averagebefore' => null, 'averageafter' => null, 'averagegrowth' => null,
        ];
    }

    private static function add_timeline_record(\stdClass $bucket, \stdClass $record): void {
        $bucket->assessed++;
        $final = (int)$record->finallevel;
        if ($final >= (int)$record->targetlevel) {
            $bucket->reached++;
        }
        if ($record->baseline !== null) {
            $baseline = (int)$record->baseline;
            $bucket->comparable++;
            $bucket->baselinesum += $baseline;
            $bucket->finalsum += $final;
            if ($final > $baseline) {
                $bucket->improved++;
            }
        }
    }

    private static function finalise_timeline_bucket(\stdClass $bucket): void {
        if ($bucket->comparable) {
            $bucket->averagebefore = round($bucket->baselinesum / $bucket->comparable, 2);
            $bucket->averageafter = round($bucket->finalsum / $bucket->comparable, 2);
            $bucket->averagegrowth = round($bucket->averageafter - $bucket->averagebefore, 2);
        }
    }

    private static function add_effectiveness_bucket(array &$buckets, int $id, string $name, \stdClass $row): void {
        if (!isset($buckets[$id])) {
            $buckets[$id] = (object)[
                'id' => $id, 'name' => $name, 'plans' => 0, 'active' => 0, 'completed' => 0,
                'actions' => 0, 'completedactions' => 0, 'overdue' => 0,
                'improved' => 0, 'targetsreached' => 0, 'progress' => 0,
            ];
        }
        $bucket = $buckets[$id];
        $bucket->plans++;
        $bucket->active += (string)$row->plan->status === self::STATUS_ACTIVE ? 1 : 0;
        $bucket->completed += (string)$row->plan->status === self::STATUS_COMPLETED ? 1 : 0;
        $bucket->actions += (int)$row->progress->total;
        $bucket->completedactions += (int)$row->progress->completed;
        $bucket->overdue += (string)$row->plan->status === self::STATUS_ACTIVE ? (int)$row->progress->overdue : 0;
        if ($row->outcome) {
            $bucket->improved += (int)$row->outcome->improved;
            $bucket->targetsreached += (int)$row->outcome->reached;
        }
    }

    private static function finalise_effectiveness_buckets(array &$buckets): void {
        foreach ($buckets as $bucket) {
            $bucket->progress = $bucket->actions ?
                (int)round(($bucket->completedactions / $bucket->actions) * 100) : 0;
        }
    }

    public static function get_items(int $planid): array {
        global $DB, $USER;
        $mentornames = self::name_fields_sql('mu', 'mentor');
        $evidencenames = self::name_fields_sql('eu', 'evidence');
        $reviewernames = self::name_fields_sql('ru', 'reviewer');
        $revocationwhere = access::not_revoked_sql('rp.companyid', (int)($USER->id ?? 0));
        $sql = "SELECT i.*, c.shortname AS competencyname, c.idnumber,
                       crs.fullname AS coursename,
                       {$mentornames},
                       {$evidencenames},
                       {$reviewernames}
                  FROM {local_kbacorp_devitem} i
                  JOIN {local_kbacorp_devplan} rp ON rp.id = i.planid
                  JOIN {competency} c ON c.id = i.competencyid
             LEFT JOIN {course} crs ON crs.id = i.courseid
             LEFT JOIN {user} mu ON mu.id = i.mentorid
             LEFT JOIN {user} eu ON eu.id = i.evidencesubmittedby
             LEFT JOIN {user} ru ON ru.id = i.reviewedby
                 WHERE {$revocationwhere} AND i.planid = :planid
              ORDER BY CASE i.priority WHEN 'critical' THEN 1 WHEN 'core' THEN 2 ELSE 3 END,
                       c.shortname";
        $records = array_values($DB->get_records_sql($sql, ['planid' => $planid]));
        foreach ($records as $record) {
            $record->mentorname = (int)($record->mentorid ?? 0) > 0 ?
                self::joined_fullname($record, 'mentor') : '';
            $record->evidencesubmittername = (int)($record->evidencesubmittedby ?? 0) > 0 ?
                self::joined_fullname($record, 'evidence') : '';
            $record->reviewername = (int)($record->reviewedby ?? 0) > 0 ?
                self::joined_fullname($record, 'reviewer') : '';
            if (trim((string)($record->goal ?? '')) === '') {
                $record->goal = self::default_item_goal(
                    (string)$record->competencyname, (int)$record->targetlevel);
            }
        }
        return $records;
    }

    /** Return one individual development action with display metadata. */
    public static function get_item(int $itemid): \stdClass {
        global $DB, $USER;
        $mentornames = self::name_fields_sql('mu', 'mentor');
        $evidencenames = self::name_fields_sql('eu', 'evidence');
        $reviewernames = self::name_fields_sql('ru', 'reviewer');
        $revocationwhere = access::not_revoked_sql('rp.companyid', (int)($USER->id ?? 0));
        $sql = "SELECT i.*, c.shortname AS competencyname, c.idnumber,
                       crs.fullname AS coursename,
                       {$mentornames},
                       {$evidencenames},
                       {$reviewernames}
                  FROM {local_kbacorp_devitem} i
                  JOIN {local_kbacorp_devplan} rp ON rp.id = i.planid
                  JOIN {competency} c ON c.id = i.competencyid
             LEFT JOIN {course} crs ON crs.id = i.courseid
             LEFT JOIN {user} mu ON mu.id = i.mentorid
             LEFT JOIN {user} eu ON eu.id = i.evidencesubmittedby
             LEFT JOIN {user} ru ON ru.id = i.reviewedby
                 WHERE {$revocationwhere} AND i.id = :itemid";
        $record = $DB->get_record_sql($sql, ['itemid' => $itemid], MUST_EXIST);
        $record->mentorname = (int)($record->mentorid ?? 0) > 0 ?
            self::joined_fullname($record, 'mentor') : '';
        $record->evidencesubmittername = (int)($record->evidencesubmittedby ?? 0) > 0 ?
            self::joined_fullname($record, 'evidence') : '';
        $record->reviewername = (int)($record->reviewedby ?? 0) > 0 ?
            self::joined_fullname($record, 'reviewer') : '';
        if (trim((string)($record->goal ?? '')) === '') {
            $record->goal = self::default_item_goal(
                (string)$record->competencyname, (int)$record->targetlevel);
        }
        return $record;
    }

    /** Build a deterministic progress and deadline summary for a plan. */
    public static function get_progress_summary(array $items, ?int $now = null): \stdClass {
        $now = $now ?? time();
        $today = usergetmidnight($now);
        $soon = $today + (14 * DAYSECS);
        $summary = (object)[
            'total' => count($items),
            'planned' => 0,
            'inprogress' => 0,
            'pendingreview' => 0,
            'completed' => 0,
            'overdue' => 0,
            'duesoon' => 0,
            'percent' => 0,
        ];
        foreach ($items as $item) {
            $status = (string)$item->status;
            if ($status === self::ITEM_COMPLETED) {
                $summary->completed++;
                continue;
            }
            if ($status === self::ITEM_INPROGRESS) {
                $summary->inprogress++;
            } else if ($status === self::ITEM_PENDING_REVIEW) {
                $summary->pendingreview++;
            } else {
                $summary->planned++;
            }
            $duedate = (int)$item->duedate;
            if ($duedate > 0 && $duedate < $today) {
                $summary->overdue++;
            } else if ($duedate >= $today && $duedate <= $soon) {
                $summary->duesoon++;
            }
        }
        $summary->percent = $summary->total ? (int)round(($summary->completed / $summary->total) * 100) : 0;
        return $summary;
    }

    /** Read authoritative current levels and summarise the measured result of one plan. */
    public static function get_outcome_summary(int $planid): \stdClass {
        $plan = self::get_plan($planid);
        $items = self::get_items($planid);
        $snapshot = competencies::get_user_snapshot((int)$plan->userid, (int)$plan->companyid, false);
        $currentlevels = [];
        foreach ($snapshot['competencies'] as $competency) {
            $currentlevels[(int)$competency->competencyid] = $competency->currentlevel;
        }
        return self::summarise_outcomes($items, $currentlevels);
    }

    /** Deterministic outcome calculation for display and regression tests. */
    public static function summarise_outcomes(array $items, array $currentlevels): \stdClass {
        $summary = (object)[
            'total' => count($items), 'assessed' => 0, 'newlyassessed' => 0,
            'improved' => 0, 'reached' => 0, 'remaininggaps' => 0,
            'rows' => [],
        ];
        foreach ($items as $item) {
            $competencyid = (int)$item->competencyid;
            $current = array_key_exists($competencyid, $currentlevels) ? $currentlevels[$competencyid] : null;
            $current = $current === null ? null : (int)$current;
            $baseline = $item->currentlevel === null ? null : (int)$item->currentlevel;
            $target = (int)$item->targetlevel;
            $delta = ($baseline === null || $current === null) ? null : $current - $baseline;
            $gap = $current === null ? null : max($target - $current, 0);
            if ($current !== null) {
                $summary->assessed++;
                if ($baseline === null) {
                    $summary->newlyassessed++;
                } else if ($delta > 0) {
                    $summary->improved++;
                }
                if ($current >= $target) {
                    $summary->reached++;
                } else {
                    $summary->remaininggaps++;
                }
            }
            $summary->rows[$competencyid] = (object)[
                'baseline' => $baseline, 'current' => $current, 'target' => $target,
                'delta' => $delta, 'gap' => $gap, 'reached' => $current !== null && $current >= $target,
            ];
        }
        return $summary;
    }

    /** Complete active plan actions whose assigned Moodle course is complete. */
    public static function sync_course_completions(int $planid = 0): \stdClass {
        global $DB;
        $params = ['active' => self::STATUS_ACTIVE, 'completed' => self::ITEM_COMPLETED];
        $plansql = '';
        if ($planid > 0) {
            $plansql = ' AND p.id = :planid';
            $params['planid'] = $planid;
        }
        $sql = "SELECT i.id, i.planid, i.competencyid, i.status,
                       i.practicaltask, i.evidencecriteria, cc.timecompleted
                  FROM {local_kbacorp_devitem} i
                  JOIN {local_kbacorp_devplan} p ON p.id = i.planid
                  JOIN {course_completions} cc ON cc.course = i.courseid AND cc.userid = p.userid
                 WHERE p.status = :active
                       AND i.courseid > 0
                       AND i.status <> :completed
                       AND cc.timecompleted IS NOT NULL
                       AND cc.timecompleted > 0{$plansql}";
        $records = $DB->get_records_sql($sql, $params);
        $affectedplans = [];
        $completed = 0;
        $awaitingproof = 0;
        $now = time();
        $transaction = $DB->start_delegated_transaction();
        foreach ($records as $record) {
            $requiresevidence = trim((string)($record->practicaltask ?? '')) !== '' ||
                trim((string)($record->evidencecriteria ?? '')) !== '';
            if ($requiresevidence) {
                $awaitingproof++;
                if ((string)$record->status === self::ITEM_PLANNED) {
                    $DB->update_record('local_kbacorp_devitem', (object)[
                        'id' => (int)$record->id,
                        'status' => self::ITEM_INPROGRESS,
                        'timemodified' => $now,
                    ]);
                    self::log_event((int)$record->planid, (int)$record->id, 0,
                        'course_completed_evidence_required', self::ITEM_PLANNED,
                        self::ITEM_INPROGRESS, '', [
                            'competencyid' => (int)$record->competencyid,
                            'coursecompletedat' => (int)$record->timecompleted,
                        ]);
                }
                $affectedplans[(int)$record->planid] = true;
                continue;
            }
            $DB->update_record('local_kbacorp_devitem', (object)[
                'id' => (int)$record->id,
                'status' => self::ITEM_COMPLETED,
                'timecompleted' => (int)$record->timecompleted,
                'timemodified' => $now,
            ]);
            self::log_event((int)$record->planid, (int)$record->id, 0,
                'course_completed', (string)$record->status, self::ITEM_COMPLETED, '', [
                    'competencyid' => (int)$record->competencyid,
                    'coursecompletedat' => (int)$record->timecompleted,
                ]);
            $completed++;
            $affectedplans[(int)$record->planid] = true;
        }
        foreach (array_keys($affectedplans) as $affectedplanid) {
            self::refresh_plan($affectedplanid);
        }
        $transaction->allow_commit();
        return (object)[
            'actions' => $completed,
            'plans' => count($affectedplans),
            'awaitingproof' => $awaitingproof,
        ];
    }

    public static function set_item_status(int $itemid, string $status, int $actorid = 0): void {
        global $DB;
        if (!in_array($status, [self::ITEM_PLANNED, self::ITEM_INPROGRESS, self::ITEM_COMPLETED], true)) {
            throw new \invalid_parameter_exception('Unknown development item status.');
        }
        $item = $DB->get_record('local_kbacorp_devitem', ['id' => $itemid], '*', MUST_EXIST);
        $plan = $DB->get_record('local_kbacorp_devplan', ['id' => $item->planid], 'id,status,companyid', MUST_EXIST);
        access::require_not_revoked((int)$plan->companyid, $actorid);
        if ((string)$plan->status !== self::STATUS_ACTIVE) {
            throw new \moodle_exception('developmentplanstatuslocked', 'local_kbacorporate');
        }
        if ($status === self::ITEM_COMPLETED &&
                (trim((string)($item->practicaltask ?? '')) !== '' ||
                 trim((string)($item->evidencecriteria ?? '')) !== '')) {
            throw new \moodle_exception('iprevidenceapprovalrequired', 'local_kbacorporate');
        }
        $fromstatus = (string)$item->status;
        $item->status = $status;
        $item->timecompleted = $status === self::ITEM_COMPLETED ? time() : 0;
        $item->timemodified = time();
        $DB->update_record('local_kbacorp_devitem', $item);
        self::log_event((int)$item->planid, $itemid, $actorid, 'item_status_changed',
            $fromstatus, $status, '', ['competencyid' => (int)$item->competencyid]);
        self::refresh_plan((int)$item->planid);
    }

    /** Update manager-controlled plan metadata. */
    public static function update_plan(int $planid, string $title, int $targetdate, int $actorid = 0): void {
        global $DB;
        $plan = $DB->get_record('local_kbacorp_devplan', ['id' => $planid], '*', MUST_EXIST);
        access::require_not_revoked((int)$plan->companyid, $actorid);
        self::require_draft($plan);
        $title = trim($title);
        if ($title === '' || \core_text::strlen($title) > 255) {
            throw new \invalid_parameter_exception('Invalid development-plan title.');
        }
        if ($targetdate <= 0 || $targetdate < (int)$plan->startdate) {
            throw new \invalid_parameter_exception('Development-plan target date is invalid.');
        }
        $latestitemduedate = (int)$DB->get_field_sql(
            'SELECT MAX(duedate) FROM {local_kbacorp_devitem} WHERE planid = :planid',
            ['planid' => $planid]
        );
        if ($latestitemduedate > 0 && $targetdate < $latestitemduedate) {
            throw new \moodle_exception('iprplantargetbeforeitems', 'local_kbacorporate');
        }
        $before = ['title' => (string)$plan->title, 'targetdate' => (int)$plan->targetdate];
        $plan->title = $title;
        $plan->targetdate = $targetdate;
        if (empty($plan->reviewdate) || (int)$plan->reviewdate > $targetdate) {
            $plan->reviewdate = $targetdate;
        }
        $plan->timemodified = time();
        $DB->update_record('local_kbacorp_devplan', $plan);
        self::log_event($planid, 0, $actorid, 'plan_updated', '', '', '', [
            'before' => $before,
            'after' => ['title' => $title, 'targetdate' => $targetdate],
        ]);
        self::sync_moodle_plan_if_available($planid);
    }

    /** Update the actionable fields of one plan item. */
    public static function update_item(int $itemid, int $courseid, int $duedate, string $status, int $actorid = 0): void {
        global $DB;
        $item = $DB->get_record('local_kbacorp_devitem', ['id' => $itemid], '*', MUST_EXIST);
        $plan = $DB->get_record('local_kbacorp_devplan', ['id' => $item->planid], '*', MUST_EXIST);
        access::require_not_revoked((int)$plan->companyid, $actorid);
        self::require_draft($plan);
        self::validate_course((int)$plan->companyid, $courseid);
        if ($duedate <= 0 || $duedate < (int)$plan->startdate || $duedate > (int)$plan->targetdate) {
            throw new \invalid_parameter_exception('Development item due date is invalid.');
        }
        if (!in_array($status, [self::ITEM_PLANNED, self::ITEM_INPROGRESS], true)) {
            throw new \invalid_parameter_exception('Unknown editable development item status.');
        }
        $fromstatus = (string)$item->status;
        $before = ['courseid' => (int)$item->courseid, 'duedate' => (int)$item->duedate, 'status' => $fromstatus];
        $item->courseid = $courseid;
        $item->duedate = $duedate;
        $item->status = $status;
        $item->timecompleted = $status === self::ITEM_COMPLETED ?
            ((int)$item->timecompleted ?: time()) : 0;
        $item->timemodified = time();
        $DB->update_record('local_kbacorp_devitem', $item);
        self::log_event((int)$item->planid, $itemid, $actorid, 'item_updated',
            $fromstatus, $status, '', [
                'competencyid' => (int)$item->competencyid,
                'before' => $before,
                'after' => ['courseid' => $courseid, 'duedate' => $duedate, 'status' => $status],
            ]);
        self::refresh_plan((int)$item->planid);
    }

    /** Add a role-profile competency that is not yet present in the plan. */
    public static function add_profile_item(int $planid, int $competencyid, int $courseid, int $duedate, int $actorid = 0): int {
        global $DB;
        $plan = $DB->get_record('local_kbacorp_devplan', ['id' => $planid], '*', MUST_EXIST);
        access::require_not_revoked((int)$plan->companyid, $actorid);
        self::require_draft($plan);
        if ($DB->record_exists('local_kbacorp_devitem', [
            'planid' => $planid, 'competencyid' => $competencyid,
        ])) {
            throw new \moodle_exception('developmentitemexists', 'local_kbacorporate');
        }
        self::validate_course((int)$plan->companyid, $courseid);
        if ($duedate <= 0) {
            throw new \invalid_parameter_exception('Development item due date is invalid.');
        }
        $snapshot = competencies::get_user_snapshot((int)$plan->userid, (int)$plan->companyid, false);
        $requirement = null;
        foreach ($snapshot['competencies'] as $row) {
            if ((int)$row->competencyid === $competencyid && ($row->targetsource ?? '') === 'businessrole') {
                $requirement = $row;
                break;
            }
        }
        if (!$requirement) {
            throw new \moodle_exception('developmentcompetencynotinrole', 'local_kbacorporate');
        }
        $now = time();
        $itemid = (int)$DB->insert_record('local_kbacorp_devitem', (object)[
            'planid' => $planid,
            'competencyid' => $competencyid,
            'courseid' => $courseid,
            'currentlevel' => $requirement->currentlevel,
            'targetlevel' => (int)$requirement->targetlevel,
            'priority' => business_roles::normalise_priority((string)$requirement->rolepriority),
            'required' => !empty($requirement->rolerequired) ? 1 : 0,
            'goal' => self::default_item_goal((string)$requirement->shortname, (int)$requirement->targetlevel),
            'practicaltask' => null,
            'evidencecriteria' => null,
            'mentorid' => (int)($plan->mentorid ?? 0),
            'checkpointdate' => min($duedate, time() + (14 * DAYSECS)),
            'checkpointcomment' => null,
            'evidenceurl' => null,
            'evidencecomment' => null,
            'evidencesubmittedby' => 0,
            'timeevidencesubmitted' => 0,
            'reviewedby' => 0,
            'timereviewed' => 0,
            'reviewcomment' => null,
            'status' => self::ITEM_PLANNED,
            'duedate' => $duedate,
            'timecompleted' => 0,
            'timecreated' => $now,
            'timemodified' => $now,
        ]);
        self::log_event($planid, $itemid, $actorid, 'item_added', '', self::ITEM_PLANNED, '', [
            'competencyid' => $competencyid,
            'courseid' => $courseid,
            'duedate' => $duedate,
        ]);
        self::refresh_plan($planid);
        self::sync_moodle_plan_if_available($planid);
        return $itemid;
    }

    /** Delete one development action without changing Moodle competency evidence. */
    public static function delete_item(int $itemid, int $actorid = 0): void {
        global $DB;
        $item = $DB->get_record('local_kbacorp_devitem', ['id' => $itemid], '*', MUST_EXIST);
        $plan = $DB->get_record('local_kbacorp_devplan', ['id' => $item->planid], '*', MUST_EXIST);
        access::require_not_revoked((int)$plan->companyid, $actorid);
        self::require_draft($plan);
        self::log_event((int)$item->planid, $itemid, $actorid, 'item_deleted',
            (string)$item->status, '', '', [
                'competencyid' => (int)$item->competencyid,
                'courseid' => (int)$item->courseid,
                'goal' => (string)($item->goal ?? ''),
            ]);
        $DB->delete_records('local_kbacorp_devitem', ['id' => $itemid]);
        self::refresh_plan((int)$item->planid);
        self::sync_moodle_plan_if_available((int)$item->planid);
    }

    /** Ensure a selected course belongs to the active company catalogue. */
    private static function validate_course(int $companyid, int $courseid): void {
        global $DB;
        if ($courseid <= 0) {
            return;
        }
        if (!$DB->record_exists('local_kbacorp_course', [
            'companyid' => $companyid, 'courseid' => $courseid, 'active' => 1,
        ])) {
            throw new \moodle_exception('developmentcourseunavailable', 'local_kbacorporate');
        }
    }

    /** Submit a completed draft to the employee for agreement. */
    public static function submit_for_agreement(int $planid, int $actorid): void {
        global $DB;
        $plan = $DB->get_record('local_kbacorp_devplan', ['id' => $planid], '*', MUST_EXIST);
        access::require_not_revoked((int)$plan->companyid, $actorid);
        self::require_draft($plan);
        if (!$DB->record_exists('local_kbacorp_devitem', ['planid' => $planid])) {
            throw new \moodle_exception('developmentplancannotsubmitempty', 'local_kbacorporate');
        }
        $plan->status = self::STATUS_PENDING;
        $plan->submittedby = $actorid;
        $plan->timesubmitted = time();
        $plan->timeacknowledged = 0;
        $plan->employeecomment = null;
        $plan->timemodified = time();
        $DB->update_record('local_kbacorp_devplan', $plan);
        self::log_event($planid, 0, $actorid, 'plan_submitted',
            self::STATUS_DRAFT, self::STATUS_PENDING);
    }

    /** Record the employee's agreement or request for changes. */
    public static function employee_decision(int $planid, int $userid, bool $accepted, string $comment): void {
        global $DB;
        $plan = $DB->get_record('local_kbacorp_devplan', ['id' => $planid], '*', MUST_EXIST);
        access::require_not_revoked((int)$plan->companyid, $userid);
        if ((int)$plan->userid !== $userid || (string)$plan->status !== self::STATUS_PENDING) {
            throw new \moodle_exception('developmentplandecisionnotallowed', 'local_kbacorporate');
        }
        $comment = trim($comment);
        if (!$accepted && $comment === '') {
            throw new \moodle_exception('developmentplancommentrequired', 'local_kbacorporate');
        }
        $plan->status = $accepted ? self::STATUS_ACTIVE : self::STATUS_DRAFT;
        $plan->timeacknowledged = $accepted ? time() : 0;
        $plan->employeecomment = $comment === '' ? null : $comment;
        $plan->timemodified = time();
        $DB->update_record('local_kbacorp_devplan', $plan);
        self::log_event($planid, 0, $userid,
            $accepted ? 'plan_accepted' : 'plan_changes_requested',
            self::STATUS_PENDING, (string)$plan->status, $comment);
    }

    /** Cancel a plan or return it to an editable draft. */
    public static function manager_transition(int $planid, string $action, int $actorid = 0): void {
        global $DB;
        $plan = $DB->get_record('local_kbacorp_devplan', ['id' => $planid], '*', MUST_EXIST);
        access::require_not_revoked((int)$plan->companyid, $actorid);
        if ($action === 'cancel' && !in_array((string)$plan->status,
                [self::STATUS_DRAFT, self::STATUS_PENDING, self::STATUS_ACTIVE], true)) {
            throw new \moodle_exception('developmentplantransitioninvalid', 'local_kbacorporate');
        }
        if ($action === 'reopen' && !in_array((string)$plan->status,
                [self::STATUS_PENDING, self::STATUS_ACTIVE, self::STATUS_COMPLETED, self::STATUS_CANCELLED], true)) {
            throw new \moodle_exception('developmentplantransitioninvalid', 'local_kbacorporate');
        }
        if (!in_array($action, ['cancel', 'reopen'], true)) {
            throw new \moodle_exception('developmentplantransitioninvalid', 'local_kbacorporate');
        }
        $fromstatus = (string)$plan->status;
        $plan->status = $action === 'cancel' ? self::STATUS_CANCELLED : self::STATUS_DRAFT;
        if ($action === 'reopen') {
            $plan->timeacknowledged = 0;
        }
        $plan->timemodified = time();
        $DB->update_record('local_kbacorp_devplan', $plan);
        self::log_event($planid, 0, $actorid,
            $action === 'cancel' ? 'plan_cancelled' : 'plan_reopened',
            $fromstatus, (string)$plan->status);
    }

    private static function require_draft(\stdClass $plan): void {
        if ((string)$plan->status !== self::STATUS_DRAFT) {
            throw new \moodle_exception('developmentplanstatuslocked', 'local_kbacorporate');
        }
    }

    /** Keep the standard Moodle Learning Plan aligned without breaking legacy KBA workflows on permission errors. */
    private static function sync_moodle_plan_if_available(int $planid): void {
        if (!class_exists('\local_kbacorporate\local\moodle_learning_plans') ||
                !moodle_learning_plans::available()) {
            return;
        }
        try {
            moodle_learning_plans::ensure_plan($planid);
        } catch (\Throwable $e) {
            // Do not roll back already-valid KBA corporate metadata when the current actor cannot
            // manage Moodle core Learning Plans. Managers can retry safely from the integration page.
            debugging('Синхронизация учебного плана Moodle отложена для плана KBA #' . $planid . ': ' . $e->getMessage(), DEBUG_DEVELOPER);
        }
    }

    private static function refresh_plan(int $planid): void {
        global $DB;
        $plan = $DB->get_record('local_kbacorp_devplan', ['id' => $planid], '*', MUST_EXIST);
        $remaining = $DB->count_records_select('local_kbacorp_devitem',
            'planid = :planid AND status <> :completed', ['planid' => $planid, 'completed' => self::ITEM_COMPLETED]);
        $fromstatus = (string)$plan->status;
        if ((string)$plan->status === self::STATUS_ACTIVE) {
            $plan->status = $remaining ? self::STATUS_ACTIVE : self::STATUS_COMPLETED;
        }
        $plan->timemodified = time();
        $DB->update_record('local_kbacorp_devplan', $plan);
        if ($fromstatus !== (string)$plan->status) {
            self::log_event($planid, 0, 0, 'plan_completed',
                $fromstatus, (string)$plan->status);
        }
    }
}
