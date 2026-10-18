<?php
namespace local_kbacorporate\local;

defined('MOODLE_INTERNAL') || die();

/** Role-based and manual employee competency audit service. */
class competency_audits {
    public const AUDIT_OPEN = 'open';
    public const AUDIT_COMPLETED = 'completed';
    public const AUDIT_CANCELLED = 'cancelled';
    public const AUDIT_TYPE_MANUAL = 'manual';
    public const AUDIT_TYPE_ROLE = 'role';
    public const AUDIT_TYPE_DEVELOPMENT = 'development';
    public const ITEM_MISSING = 'missing';
    public const ITEM_PENDING = 'pending';
    public const ITEM_CONFIRMED = 'confirmed';
    public const ITEM_REJECTED = 'rejected';
    public const METHODS = ['self', 'test', 'interview', 'practice', 'expert', 'ai'];

    public static function available(): bool {
        global $DB;
        $dbman = $DB->get_manager();
        foreach (['local_kbacorp_audit', 'local_kbacorp_audit_item', 'local_kbacorp_audit_ev'] as $name) {
            if (!$dbman->table_exists(new \xmldb_table($name))) {
                return false;
            }
        }
        return true;
    }

    public static function create_audit(int $companyid, int $userid, string $title,
            string $description, int $createdby, string $audittype = self::AUDIT_TYPE_MANUAL): int {
        global $DB;
        if (!$DB->record_exists('local_kbacorp_member', [
            'companyid' => $companyid, 'userid' => $userid, 'isemployee' => 1,
        ])) {
            throw new \invalid_parameter_exception('Audit subject is not an employee of this company.');
        }
        $title = trim($title);
        if ($title === '' || \core_text::strlen($title) > 255) {
            throw new \invalid_parameter_exception('Audit title is required and must not exceed 255 characters.');
        }
        if (!in_array($audittype, [self::AUDIT_TYPE_MANUAL, self::AUDIT_TYPE_ROLE,
                self::AUDIT_TYPE_DEVELOPMENT], true)) {
            throw new \invalid_parameter_exception('Unknown audit type.');
        }
        $now = time();
        return (int)$DB->insert_record('local_kbacorp_audit', (object)[
            'companyid' => $companyid, 'userid' => $userid, 'title' => $title,
            'description' => trim($description) ?: null, 'audittype' => $audittype,
            'status' => self::AUDIT_OPEN, 'createdby' => $createdby, 'completedat' => 0,
            'timecreated' => $now, 'timemodified' => $now,
        ]);
    }

    /** Create and populate an audit from the employee's current business-role profile. */
    public static function create_role_audit(int $companyid, int $userid, string $title,
            string $description, int $createdby): int {
        global $DB;
        $profile = target_profile::for_user($userid, $companyid);
        if (!$profile) {
            throw new \moodle_exception('auditnoroleprofile', 'local_kbacorporate');
        }
        $title = trim($title);
        if ($title === '') {
            $title = get_string('defaultroleaudittitle', 'local_kbacorporate', userdate(time(), '%d.%m.%Y'));
        }
        $transaction = $DB->start_delegated_transaction();
        $auditid = self::create_audit(
            $companyid, $userid, $title, $description, $createdby, self::AUDIT_TYPE_ROLE
        );
        self::populate_from_business_roles($auditid, $createdby, $profile);
        $transaction->allow_commit();
        return $auditid;
    }

    /**
     * Create a follow-up audit limited to competencies from one completed development plan.
     *
     * v0.32.2: the plan-item baseline is snapshotted and old Moodle grades are not
     * auto-confirmed as a reassessment result. New post-plan evidence may create a
     * pending proposal, but every level change still requires an explicit reviewer.
     */
    public static function create_development_audit(int $planid, int $createdby): int {
        global $DB;
        $plan = $DB->get_record('local_kbacorp_devplan', ['id' => $planid], '*', MUST_EXIST);
        if ((string)$plan->status !== development_plans::STATUS_COMPLETED) {
            throw new \moodle_exception('developmentauditplanincomplete', 'local_kbacorporate');
        }
        if ((int)$plan->followupauditid > 0 &&
                $DB->record_exists('local_kbacorp_audit', ['id' => (int)$plan->followupauditid])) {
            return (int)$plan->followupauditid;
        }
        $items = development_plans::get_items($planid);
        if (!$items) {
            throw new \moodle_exception('developmentauditnoitems', 'local_kbacorporate');
        }

        $transaction = $DB->start_delegated_transaction();
        $title = get_string('developmentaudittitle', 'local_kbacorporate', format_string($plan->title));
        $auditid = self::create_audit((int)$plan->companyid, (int)$plan->userid, $title,
            get_string('developmentauditdescription', 'local_kbacorporate', format_string($plan->title)),
            $createdby, self::AUDIT_TYPE_DEVELOPMENT);
        $now = time();

        foreach ($items as $planitem) {
            $competency = $DB->get_record_sql(
                "SELECT c.id, c.scaleid AS competencyscaleid, f.scaleid AS frameworkscaleid
                   FROM {competency} c
                   JOIN {competency_framework} f ON f.id = c.competencyframeworkid
                  WHERE c.id = :id", ['id' => (int)$planitem->competencyid], MUST_EXIST);
            $scaleid = (int)($competency->competencyscaleid ?: $competency->frameworkscaleid);
            $cutoff = max((int)$plan->startdate, (int)$planitem->timecreated);
            $collected = self::collect_reassessment_evidence((int)$plan->userid,
                (int)$plan->companyid, (int)$planitem->competencyid, $scaleid, $cutoff);

            $candidate = null;
            if ($collected['currentlevel'] !== null) {
                $candidate = (object)[
                    'level' => (int)$collected['currentlevel'],
                    'time' => (int)$collected['currenttime'],
                    'method' => 'expert',
                    'source' => 'moodle_reassessment',
                ];
            } else {
                foreach ($collected['evidence'] as $evidence) {
                    if ($evidence->proposedlevel === null) {
                        continue;
                    }
                    $candidate = (object)[
                        'level' => (int)$evidence->proposedlevel,
                        'time' => (int)$evidence->timeobserved,
                        'method' => (string)$evidence->sourcetype === 'course_result' ? 'test' : 'practice',
                        'source' => 'reassessment_evidence',
                    ];
                    break;
                }
            }

            $status = $candidate ? self::ITEM_PENDING : self::ITEM_MISSING;
            $coursecompletedat = 0;
            if ((int)$planitem->courseid > 0) {
                $coursecompletedat = (int)$DB->get_field('course_completions', 'timecompleted', [
                    'userid' => (int)$plan->userid,
                    'course' => (int)$planitem->courseid,
                ], IGNORE_MISSING);
            }
            $snapshot = [
                'workflow' => 'reassessment-v1',
                'developmentplanid' => $planid,
                'developmentitemid' => (int)$planitem->id,
                'baselinelevel' => $planitem->currentlevel === null ? null : (int)$planitem->currentlevel,
                'targetlevel' => (int)$planitem->targetlevel,
                'courseid' => (int)$planitem->courseid,
                'coursecompletedat' => $coursecompletedat,
                'evidencecutoff' => $cutoff,
                'createdat' => $now,
            ];
            $itemid = (int)$DB->insert_record('local_kbacorp_audit_item', (object)[
                'auditid' => $auditid,
                'competencyid' => (int)$planitem->competencyid,
                'method' => $candidate ? (string)$candidate->method : 'practice',
                'proposedlevel' => $candidate ? (int)$candidate->level : 0,
                'targetlevel' => (int)$planitem->targetlevel,
                'priority' => business_roles::normalise_priority((string)$planitem->priority),
                'required' => 1,
                'source' => $candidate ? (string)$candidate->source : 'development_plan',
                'rolesnapshot' => json_encode($snapshot, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                'status' => $status,
                'assessorid' => $candidate ? $createdby : 0,
                'reviewerid' => 0,
                'comment' => null,
                'evidenceurl' => null,
                'timeassessed' => $candidate ? (int)$candidate->time : 0,
                'timeconfirmed' => 0,
                'timecreated' => $now,
                'timemodified' => $now,
            ]);
            foreach ($collected['evidence'] as $evidence) {
                $evidence->audititemid = $itemid;
                $evidence->timecreated = $now;
                $DB->insert_record('local_kbacorp_audit_ev', $evidence);
            }
        }

        self::refresh_audit_status($auditid);
        $DB->set_field('local_kbacorp_devplan', 'followupauditid', $auditid, ['id' => $planid]);
        $transaction->allow_commit();
        return $auditid;
    }

    /**
     * Populate a role audit and collect evidence already held by Moodle.
     *
     * A core user-competency grade is authoritative. Course results and
     * competency evidence are proposals. Absence of evidence is "missing",
     * never an invented level zero.
     */
    public static function populate_from_business_roles(int $auditid, int $actorid,
            ?array $profile = null): int {
        global $DB;
        $audit = $DB->get_record('local_kbacorp_audit', ['id' => $auditid], '*', MUST_EXIST);
        if ($audit->status !== self::AUDIT_OPEN || $audit->audittype !== self::AUDIT_TYPE_ROLE) {
            throw new \moodle_exception('auditnotopen', 'local_kbacorporate');
        }
        if ($DB->record_exists('local_kbacorp_audit_item', ['auditid' => $auditid])) {
            throw new \moodle_exception('auditrolealreadypopulated', 'local_kbacorporate');
        }
        $profile = $profile ?? target_profile::for_user((int)$audit->userid, (int)$audit->companyid);
        if (!$profile) {
            throw new \moodle_exception('auditnoroleprofile', 'local_kbacorporate');
        }

        $now = time();
        $count = 0;
        foreach ($profile as $target) {
            $competencyid = (int)$target->competencyid;
            $scaleid = (int)($target->competencyscaleid ?: $target->frameworkscaleid);
            $collected = self::collect_moodle_evidence(
                (int)$audit->userid, (int)$audit->companyid, $competencyid, $scaleid
            );
            $levels = [];
            foreach ($collected['evidence'] as $evidence) {
                if ($evidence->proposedlevel !== null) {
                    $levels[] = (int)$evidence->proposedlevel;
                }
            }
            if ($collected['currentlevel'] !== null) {
                $status = self::ITEM_CONFIRMED;
                $proposedlevel = (int)$collected['currentlevel'];
                $source = 'moodle_current';
                $method = 'expert';
                $reviewerid = (int)($collected['reviewerid'] ?: $actorid);
                $timeconfirmed = (int)($collected['currenttime'] ?: $now);
            } else if ($levels) {
                $status = self::ITEM_PENDING;
                $proposedlevel = max($levels);
                $source = 'moodle_evidence';
                $method = $collected['hascourse'] ? 'test' : 'practice';
                $reviewerid = 0;
                $timeconfirmed = 0;
            } else {
                $status = self::ITEM_MISSING;
                $proposedlevel = 0;
                $source = 'businessrole';
                $method = 'expert';
                $reviewerid = 0;
                $timeconfirmed = 0;
            }
            $itemid = (int)$DB->insert_record('local_kbacorp_audit_item', (object)[
                'auditid' => $auditid, 'competencyid' => $competencyid, 'method' => $method,
                'proposedlevel' => $proposedlevel, 'targetlevel' => (int)$target->targetlevel,
                'priority' => business_roles::normalise_priority((string)$target->priority),
                'required' => !empty($target->required) ? 1 : 0, 'source' => $source,
                'rolesnapshot' => json_encode(array_values($target->roles), JSON_UNESCAPED_UNICODE),
                'status' => $status, 'assessorid' => $status === self::ITEM_MISSING ? 0 : $actorid,
                'reviewerid' => $reviewerid, 'comment' => null, 'evidenceurl' => null,
                'timeassessed' => $status === self::ITEM_MISSING ? 0 : $now,
                'timeconfirmed' => $timeconfirmed, 'timecreated' => $now, 'timemodified' => $now,
            ]);
            foreach ($collected['evidence'] as $evidence) {
                $evidence->audititemid = $itemid;
                $evidence->timecreated = $now;
                $DB->insert_record('local_kbacorp_audit_ev', $evidence);
            }
            $count++;
        }
        self::refresh_audit_status($auditid);
        return $count;
    }

    public static function get_audit(int $auditid): \stdClass {
        global $DB;
        $sql = "SELECT a.*, u.firstname, u.lastname, u.email, c.name AS companyname
                  FROM {local_kbacorp_audit} a
                  JOIN {user} u ON u.id = a.userid
                  JOIN {local_kbacorp_company} c ON c.id = a.companyid
                 WHERE a.id = :id";
        return $DB->get_record_sql($sql, ['id' => $auditid], MUST_EXIST);
    }

    /** Rename an audit without changing its employee, scope, results or status. */
    public static function rename_audit(int $auditid, string $title): void {
        global $DB;
        $audit = $DB->get_record('local_kbacorp_audit', ['id' => $auditid], '*', MUST_EXIST);
        $title = trim($title);
        if ($title === '' || \core_text::strlen($title) > 255) {
            throw new \invalid_parameter_exception('Audit title is required and must not exceed 255 characters.');
        }
        $audit->title = $title;
        $audit->timemodified = time();
        $DB->update_record('local_kbacorp_audit', $audit);
    }

    /** Delete one audit and its local assessment/evidence history. */
    public static function delete_audit(int $auditid): void {
        global $DB;
        $DB->get_record('local_kbacorp_audit', ['id' => $auditid], 'id', MUST_EXIST);
        $transaction = $DB->start_delegated_transaction();
        if ($DB->get_manager()->field_exists(new \xmldb_table('local_kbacorp_devplan'),
                new \xmldb_field('followupauditid'))) {
            $DB->set_field('local_kbacorp_devplan', 'followupauditid', 0, ['followupauditid' => $auditid]);
        }
        $itemids = $DB->get_fieldset_select('local_kbacorp_audit_item', 'id',
            'auditid = :auditid', ['auditid' => $auditid]);
        if ($itemids) {
            list($insql, $params) = $DB->get_in_or_equal($itemids, SQL_PARAMS_NAMED, 'audititem');
            $DB->delete_records_select('local_kbacorp_audit_ev', "audititemid {$insql}", $params);
        }
        $DB->delete_records('local_kbacorp_audit_item', ['auditid' => $auditid]);
        $DB->delete_records('local_kbacorp_audit', ['id' => $auditid]);
        $transaction->allow_commit();
    }

    public static function get_company_audits(int $companyid, int $userid = 0): array {
        global $DB;
        $params = ['companyid' => $companyid];
        $userwhere = '';
        if ($userid > 0) {
            $params['userid'] = $userid;
            $userwhere = ' AND a.userid = :userid';
        }
        $sql = "SELECT a.*, u.firstname, u.lastname, u.email,
                       COUNT(i.id) AS itemcount,
                       SUM(CASE WHEN i.status = 'pending' THEN 1 ELSE 0 END) AS pendingcount,
                       SUM(CASE WHEN i.status = 'missing' THEN 1 ELSE 0 END) AS missingcount
                  FROM {local_kbacorp_audit} a
                  JOIN {user} u ON u.id = a.userid
             LEFT JOIN {local_kbacorp_audit_item} i ON i.auditid = a.id
                 WHERE a.companyid = :companyid {$userwhere}
              GROUP BY a.id, a.companyid, a.userid, a.title, a.description, a.audittype, a.status,
                       a.createdby, a.completedat, a.timecreated, a.timemodified,
                       u.firstname, u.lastname, u.email
              ORDER BY a.timecreated DESC";
        return array_values($DB->get_records_sql($sql, $params));
    }

    public static function get_items(int $auditid): array {
        global $DB;
        $sql = "SELECT i.*, c.shortname, c.idnumber,
                       c.scaleid AS competencyscaleid, f.scaleid AS frameworkscaleid,
                       f.shortname AS frameworkname,
                       assessor.firstname AS assessorfirstname, assessor.lastname AS assessorlastname,
                       reviewer.firstname AS reviewerfirstname, reviewer.lastname AS reviewerlastname
                  FROM {local_kbacorp_audit_item} i
                  JOIN {competency} c ON c.id = i.competencyid
             LEFT JOIN {competency_framework} f ON f.id = c.competencyframeworkid
             LEFT JOIN {user} assessor ON assessor.id = i.assessorid
             LEFT JOIN {user} reviewer ON reviewer.id = i.reviewerid
                 WHERE i.auditid = :auditid
              ORDER BY i.required DESC, i.priority DESC, c.shortname ASC, i.id ASC";
        return array_values($DB->get_records_sql($sql, ['auditid' => $auditid]));
    }

    /** Return structured evidence keyed by audit item id. */
    public static function get_evidence_for_audit(int $auditid): array {
        global $DB;
        $sql = "SELECT e.*
                  FROM {local_kbacorp_audit_ev} e
                  JOIN {local_kbacorp_audit_item} i ON i.id = e.audititemid
                 WHERE i.auditid = :auditid
              ORDER BY e.timeobserved DESC, e.id DESC";
        $result = [];
        foreach ($DB->get_records_sql($sql, ['auditid' => $auditid]) as $evidence) {
            $result[(int)$evidence->audititemid][] = $evidence;
        }
        return $result;
    }

    public static function add_assessment(int $auditid, int $competencyid, string $method,
            int $proposedlevel, string $comment, string $evidenceurl, int $assessorid): int {
        global $DB;
        $audit = $DB->get_record('local_kbacorp_audit', ['id' => $auditid], '*', MUST_EXIST);
        if (!$DB->record_exists('competency', ['id' => $competencyid])) {
            throw new \invalid_parameter_exception('Unknown competency.');
        }
        $method = strtolower(trim($method));
        if (!in_array($method, self::METHODS, true)) {
            throw new \invalid_parameter_exception('Unknown audit method.');
        }
        self::level_to_grade($competencyid, $proposedlevel);
        $now = time();
        $existing = $DB->get_record_select('local_kbacorp_audit_item',
            'auditid = :auditid AND competencyid = :competencyid AND (status = :missing OR status = :rejected)', [
                'auditid' => $auditid,
                'competencyid' => $competencyid,
                'missing' => self::ITEM_MISSING,
                'rejected' => self::ITEM_REJECTED,
            ], '*', IGNORE_MULTIPLE);
        if ($audit->status !== self::AUDIT_OPEN && !$existing) {
            throw new \moodle_exception('auditnotopen', 'local_kbacorporate');
        }
        if ($existing) {
            $existing->method = $method;
            $existing->proposedlevel = $proposedlevel;
            $existing->source = 'manual_assessment';
            $existing->status = self::ITEM_PENDING;
            $existing->assessorid = $assessorid;
            $existing->reviewerid = 0;
            $existing->comment = trim($comment) ?: null;
            $existing->evidenceurl = null;
            $existing->timeassessed = $now;
            $existing->timeconfirmed = 0;
            $existing->timemodified = $now;
            $DB->update_record('local_kbacorp_audit_item', $existing);
            if ($audit->status !== self::AUDIT_OPEN) {
                $DB->set_field('local_kbacorp_audit', 'status', self::AUDIT_OPEN, ['id' => $auditid]);
                $DB->set_field('local_kbacorp_audit', 'completedat', 0, ['id' => $auditid]);
                $DB->set_field('local_kbacorp_audit', 'timemodified', $now, ['id' => $auditid]);
            }
            $itemid = (int)$existing->id;
        } else {
            $itemid = (int)$DB->insert_record('local_kbacorp_audit_item', (object)[
                'auditid' => $auditid, 'competencyid' => $competencyid, 'method' => $method,
                'proposedlevel' => $proposedlevel, 'targetlevel' => null,
                'priority' => business_roles::PRIORITY_ADDITIONAL, 'required' => 0,
                'source' => 'manual_assessment', 'rolesnapshot' => null,
                'status' => self::ITEM_PENDING, 'assessorid' => $assessorid, 'reviewerid' => 0,
                'comment' => trim($comment) ?: null, 'evidenceurl' => null,
                'timeassessed' => $now, 'timeconfirmed' => 0,
                'timecreated' => $now, 'timemodified' => $now,
            ]);
        }
        $DB->insert_record('local_kbacorp_audit_ev', (object)[
            'audititemid' => $itemid, 'sourcetype' => 'manual_assessment',
            'sourcecomponent' => 'local_kbacorporate', 'sourceitemid' => 0,
            'title' => get_string('manualassessment', 'local_kbacorporate'),
            'resultvalue' => competencies::level_label($proposedlevel), 'proposedlevel' => $proposedlevel,
            'url' => null, 'details' => trim($comment) ?: null,
            'timeobserved' => $now, 'timecreated' => $now,
        ]);
        return $itemid;
    }

    /** Confirm an assessment and write the grade through the Moodle Competencies API. */
    public static function confirm_assessment(int $itemid, int $reviewerid): void {
        global $DB;
        $sql = "SELECT i.*, a.userid, a.companyid, a.title AS audittitle
                  FROM {local_kbacorp_audit_item} i
                  JOIN {local_kbacorp_audit} a ON a.id = i.auditid
                 WHERE i.id = :id";
        $item = $DB->get_record_sql($sql, ['id' => $itemid], MUST_EXIST);
        if ($item->status !== self::ITEM_PENDING) {
            throw new \moodle_exception('audititemnotpending', 'local_kbacorporate');
        }
        $grade = self::level_to_grade((int)$item->competencyid, (int)$item->proposedlevel);
        $note = get_string('auditmoodlenote', 'local_kbacorporate', (object)[
            'audit' => $item->audittitle,
            'method' => get_string('auditmethod_' . $item->method, 'local_kbacorporate'),
            'comment' => trim((string)$item->comment),
        ]);
        $transaction = $DB->start_delegated_transaction();
        \core_competency\api::grade_competency((int)$item->userid, (int)$item->competencyid, $grade, $note);
        $item->status = self::ITEM_CONFIRMED;
        $item->reviewerid = $reviewerid;
        $item->timeconfirmed = time();
        $item->timemodified = time();
        $DB->update_record('local_kbacorp_audit_item', $item);
        self::refresh_audit_status((int)$item->auditid);
        $transaction->allow_commit();
    }

    public static function reject_assessment(int $itemid, int $reviewerid): void {
        global $DB;
        $item = $DB->get_record('local_kbacorp_audit_item', ['id' => $itemid], '*', MUST_EXIST);
        if ($item->status !== self::ITEM_PENDING) {
            throw new \moodle_exception('audititemnotpending', 'local_kbacorporate');
        }
        $item->status = self::ITEM_REJECTED;
        $item->reviewerid = $reviewerid;
        $item->timeconfirmed = time();
        $item->timemodified = time();
        $DB->update_record('local_kbacorp_audit_item', $item);
        self::refresh_audit_status((int)$item->auditid);
    }

    /** Latest audit item per competency for the selected employee and company. */
    public static function get_latest_user_items(int $companyid, int $userid): array {
        global $DB;
        if (!self::available()) {
            return [];
        }
        $sql = "SELECT i.*, a.title AS audittitle, a.companyid, a.userid,
                       c.shortname, c.idnumber, c.description, c.descriptionformat,
                       c.competencyframeworkid, c.scaleid AS competencyscaleid,
                       f.shortname AS frameworkname, f.scaleid AS frameworkscaleid,
                       assessor.firstname AS assessorfirstname, assessor.lastname AS assessorlastname
                  FROM {local_kbacorp_audit_item} i
                  JOIN {local_kbacorp_audit} a ON a.id = i.auditid
                  JOIN {competency} c ON c.id = i.competencyid
             LEFT JOIN {competency_framework} f ON f.id = c.competencyframeworkid
             LEFT JOIN {user} assessor ON assessor.id = i.assessorid
                 WHERE a.companyid = :companyid AND a.userid = :userid
              ORDER BY i.timeassessed DESC, i.id DESC";
        $items = [];
        foreach ($DB->get_records_sql($sql, ['companyid' => $companyid, 'userid' => $userid]) as $item) {
            if (!isset($items[(int)$item->competencyid])) {
                $items[(int)$item->competencyid] = $item;
            }
        }
        return $items;
    }

    public static function status_label(string $status): string {
        $key = 'auditstatus_' . $status;
        return get_string_manager()->string_exists($key, 'local_kbacorporate')
            ? get_string($key, 'local_kbacorporate') : $status;
    }

    public static function audit_type_label(string $type): string {
        $key = 'audittype_' . $type;
        return get_string_manager()->string_exists($key, 'local_kbacorporate')
            ? get_string($key, 'local_kbacorporate') : $type;
    }

    private static function refresh_audit_status(int $auditid): void {
        global $DB;
        if (!$DB->record_exists('local_kbacorp_audit_item', ['auditid' => $auditid])) {
            return;
        }
        $unfinished = $DB->record_exists_select(
            'local_kbacorp_audit_item',
            'auditid = :auditid AND (status = :pending OR status = :missing)',
            ['auditid' => $auditid, 'pending' => self::ITEM_PENDING, 'missing' => self::ITEM_MISSING]
        );
        $now = time();
        $DB->set_field('local_kbacorp_audit', 'status',
            $unfinished ? self::AUDIT_OPEN : self::AUDIT_COMPLETED, ['id' => $auditid]);
        $DB->set_field('local_kbacorp_audit', 'completedat', $unfinished ? 0 : $now, ['id' => $auditid]);
        $DB->set_field('local_kbacorp_audit', 'timemodified', $now, ['id' => $auditid]);
    }

    /** Collect current competency, core evidence, and company-course results. */
    private static function collect_moodle_evidence(int $userid, int $companyid, int $competencyid,
            int $scaleid): array {
        global $DB;
        $result = ['currentlevel' => null, 'currenttime' => 0, 'reviewerid' => 0,
            'hascourse' => false, 'evidence' => []];
        $usercompetency = $DB->get_record('competency_usercomp', [
            'userid' => $userid, 'competencyid' => $competencyid,
        ]);
        if ($usercompetency) {
            $level = self::grade_to_level($competencyid, $usercompetency->grade, $scaleid);
            if ($level !== null) {
                $result['currentlevel'] = $level;
                $result['currenttime'] = (int)$usercompetency->timemodified;
                $result['reviewerid'] = (int)($usercompetency->reviewerid ?? 0);
                $result['evidence'][] = self::evidence_record(
                    'moodle_current', 'core_competency', (int)$usercompetency->id,
                    get_string('collectedcurrentlevel', 'local_kbacorporate'),
                    competencies::level_label($level, $scaleid), $level, '', '',
                    (int)$usercompetency->timemodified
                );
            }
            if ($DB->get_manager()->table_exists(new \xmldb_table('competency_evidence'))) {
                $records = $DB->get_records('competency_evidence',
                    ['usercompetencyid' => (int)$usercompetency->id], 'timecreated DESC');
                foreach ($records as $record) {
                    $evidencelevel = self::grade_to_level($competencyid, $record->grade, $scaleid);
                    $result['evidence'][] = self::evidence_record(
                        'competency_evidence', (string)($record->desccomponent ?? 'core_competency'),
                        (int)$record->id, self::evidence_title($record),
                        $evidencelevel === null ? '' : competencies::level_label($evidencelevel, $scaleid),
                        $evidencelevel, (string)($record->url ?? ''),
                        trim(html_to_text((string)($record->note ?? ''), 0, false)),
                        (int)$record->timecreated
                    );
                }
            }
        }
        if ($DB->get_manager()->table_exists(new \xmldb_table('competency_usercompcourse'))) {
            $sql = "SELECT ucc.id, ucc.courseid, ucc.grade, ucc.proficiency, ucc.timemodified,
                           c.fullname AS coursename
                      FROM {competency_usercompcourse} ucc
                      JOIN {course} c ON c.id = ucc.courseid
                      JOIN {local_kbacorp_course} cc ON cc.courseid = ucc.courseid
                     WHERE ucc.userid = :userid
                       AND ucc.competencyid = :competencyid
                       AND cc.companyid = :companyid
                       AND cc.active = 1
                  ORDER BY ucc.timemodified DESC";
            $records = $DB->get_records_sql($sql, [
                'userid' => $userid, 'competencyid' => $competencyid, 'companyid' => $companyid,
            ]);
            foreach ($records as $record) {
                $level = self::grade_to_level($competencyid, $record->grade, $scaleid);
                $result['hascourse'] = true;
                $result['evidence'][] = self::evidence_record(
                    'course_result', 'core_competency', (int)$record->id,
                    get_string('collectedcourseresult', 'local_kbacorporate', format_string($record->coursename)),
                    $level === null ? '' : competencies::level_label($level, $scaleid), $level,
                    (new \moodle_url('/course/view.php', ['id' => (int)$record->courseid]))->out(false),
                    '', (int)$record->timemodified
                );
            }
        }
        return $result;
    }

    /**
     * Collect only Moodle evidence observed after the reassessment cutoff.
     *
     * The returned current level is a proposal, not an automatic confirmation.
     */
    public static function collect_reassessment_evidence(int $userid, int $companyid,
            int $competencyid, int $scaleid, int $cutoff): array {
        $collected = self::collect_moodle_evidence($userid, $companyid, $competencyid, $scaleid);
        $result = [
            'currentlevel' => null,
            'currenttime' => 0,
            'reviewerid' => 0,
            'hascourse' => false,
            'evidence' => [],
        ];
        if ($collected['currentlevel'] !== null && (int)$collected['currenttime'] >= $cutoff) {
            $result['currentlevel'] = (int)$collected['currentlevel'];
            $result['currenttime'] = (int)$collected['currenttime'];
            $result['reviewerid'] = (int)$collected['reviewerid'];
        }
        foreach ($collected['evidence'] as $evidence) {
            if ((int)$evidence->timeobserved < $cutoff) {
                continue;
            }
            $result['evidence'][] = $evidence;
            if ((string)$evidence->sourcetype === 'course_result') {
                $result['hascourse'] = true;
            }
        }
        usort($result['evidence'], static function($a, $b) {
            $comparison = ((int)$b->timeobserved) <=> ((int)$a->timeobserved);
            return $comparison !== 0 ? $comparison : ((int)$b->sourceitemid <=> (int)$a->sourceitemid);
        });
        return $result;
    }

    private static function evidence_record(string $type, string $component, int $sourceid, string $title,
            string $resultvalue, ?int $proposedlevel, string $url, string $details, int $timeobserved): \stdClass {
        return (object)[
            'sourcetype' => $type, 'sourcecomponent' => $component ?: null,
            'sourceitemid' => $sourceid,
            'title' => \core_text::substr(trim($title) ?: get_string('collectedevidence', 'local_kbacorporate'), 0, 255),
            'resultvalue' => trim($resultvalue) ?: null, 'proposedlevel' => $proposedlevel,
            'url' => trim($url) ?: null, 'details' => trim($details) ?: null,
            'timeobserved' => $timeobserved,
        ];
    }

    private static function evidence_title(\stdClass $evidence): string {
        if (!empty($evidence->descidentifier) && !empty($evidence->desccomponent)) {
            try {
                $a = null;
                if (!empty($evidence->desca)) {
                    $decoded = json_decode($evidence->desca);
                    $a = $decoded === null ? $evidence->desca : $decoded;
                }
                return get_string($evidence->descidentifier, $evidence->desccomponent, $a);
            } catch (\Throwable $e) {
                return (string)$evidence->descidentifier;
            }
        }
        return get_string('collectedevidence', 'local_kbacorporate');
    }

    /** Convert a Moodle grade to the zero-based target level. */
    private static function grade_to_level(int $competencyid, $grade, int $scaleid = 0): ?int {
        global $DB;
        if ($grade === null || $grade === '') {
            return null;
        }
        if ($scaleid <= 0) {
            $sql = "SELECT COALESCE(NULLIF(c.scaleid, 0), f.scaleid) AS scaleid
                      FROM {competency} c
                      JOIN {competency_framework} f ON f.id = c.competencyframeworkid
                     WHERE c.id = :id";
            $scaleid = (int)$DB->get_field_sql($sql, ['id' => $competencyid], MUST_EXIST);
        }
        $value = (int)round((float)$grade);
        if ($scaleid > 0) {
            $scale = $DB->get_record('scale', ['id' => $scaleid], 'id,scale', IGNORE_MISSING);
            if ($scale) {
                $items = array_values(array_filter(array_map('trim', explode(',', (string)$scale->scale)), 'strlen'));
                if ($value >= 1 && $value <= count($items)) {
                    return $value - 1;
                }
            }
        }
        return $value >= 0 && $value <= 3 ? $value : null;
    }

    /** Convert a zero-based displayed level to Moodle's one-based scale grade. */
    private static function level_to_grade(int $competencyid, int $level): int {
        global $DB;
        $sql = "SELECT c.id, COALESCE(NULLIF(c.scaleid, 0), f.scaleid) AS scaleid
                  FROM {competency} c
                  JOIN {competency_framework} f ON f.id = c.competencyframeworkid
                 WHERE c.id = :id";
        $competency = $DB->get_record_sql($sql, ['id' => $competencyid], MUST_EXIST);
        $scale = $DB->get_record('scale', ['id' => (int)$competency->scaleid], 'id, scale', MUST_EXIST);
        $scaleitems = array_map('trim', explode(',', (string)$scale->scale));
        if ($level < 0 || $level >= count($scaleitems)) {
            throw new \moodle_exception('auditleveloutofrange', 'local_kbacorporate', '', count($scaleitems) - 1);
        }
        return $level + 1;
    }
}
