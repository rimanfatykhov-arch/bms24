<?php
namespace local_kbacompany\local;

defined('MOODLE_INTERNAL') || die();

/** Personal Moodle review queue. This is not a company-scoped BMS registry. */
class competency_review_queue_service {
    public const PAGE_SIZE = 25;

    /** Native review permissions are evaluated for the signed-in user only. */
    public static function has_pending(): bool {
        if (!isloggedin() || isguestuser() || !\core_competency\api::is_enabled()) {
            return false;
        }
        $queue = \core_competency\api::list_user_competencies_to_review(0, 1);
        return !empty($queue['competencies']);
    }

    /** Return the current user's native queue, with optional related-evidence shortcuts. */
    public static function queue(int $page = 0): array {
        if (!isloggedin() || isguestuser() || !\core_competency\api::is_enabled()) {
            return ['count' => 0, 'rows' => []];
        }
        $native = \core_competency\api::list_user_competencies_to_review(
            max(0, $page) * self::PAGE_SIZE, self::PAGE_SIZE);
        $rows = [];
        foreach ($native['competencies'] as $item) {
            $id = (int)$item->usercompetency->get('id');
            $rows[] = [
                'id' => $id,
                'userid' => (int)$item->user->id,
                'fullname' => fullname($item->user),
                'competency' => $item->competency->get('shortname'),
                'status' => (int)$item->usercompetency->get('status'),
                'workurl' => self::work_url($id),
                'reviewurl' => new \moodle_url('/admin/tool/lp/user_competency.php', ['id' => $id]),
            ];
        }
        return ['count' => (int)$native['count'], 'rows' => $rows];
    }

    /**
     * Resolve a unique submitted individual assignment related to native evidence.
     * Moodle does not bind a review request to an evidence or submission attempt.
     * This is a contextual shortcut, never proof of the current request's origin.
     * Evidence URLs are never trusted. Uncertain evidence and unsupported grading
     * modes deliberately fall back to the native competency review screen.
     */
    public static function work_url(int $id): ?\moodle_url {
        global $DB, $CFG, $USER;
        if (!\core_competency\api::is_enabled() || $id <= 0 || !isloggedin() || isguestuser()) {
            return null;
        }
        $record = $DB->get_record('competency_usercomp', ['id' => $id]);
        if (!$record) {
            return null;
        }
        $target = $DB->get_record('user', ['id' => $record->userid, 'deleted' => 0, 'suspended' => 0]);
        if (!$target || !has_capability('moodle/competency:usercompetencyreview',
                \context_user::instance($target->id)) ||
                !\core_competency\user_competency::can_read_user($target->id)) {
            return null;
        }
        $waiting = \core_competency\user_competency::STATUS_WAITING_FOR_REVIEW;
        $inreview = \core_competency\user_competency::STATUS_IN_REVIEW;
        if ((int)$record->status !== $waiting &&
                !((int)$record->status === $inreview && (int)$record->reviewerid === (int)$USER->id)) {
            return null;
        }
        $evidence = \core_competency\api::list_evidence($target->id, $record->competencyid,
            0, 'timecreated', 'DESC', 0, 101);
        if (count($evidence) > 100) {
            return null;
        }
        $contexts = [];
        $completioncount = 0;
        $completionid = 0;
        $latestid = 0;
        $latesttime = 0;
        foreach ($evidence as $item) {
            $time = (int)$item->get('timecreated');
            $evidenceid = (int)$item->get('id');
            if ($time > $latesttime || ($time === $latesttime && $evidenceid > $latestid)) {
                $latesttime = $time;
                $latestid = $evidenceid;
            }
            if ($item->get('desccomponent') === 'core_competency' &&
                    $item->get('descidentifier') === 'evidence_coursemodulecompleted') {
                $completioncount++;
                $completionid = $evidenceid;
                $contextid = (int)$item->get('contextid');
                $contexts[$contextid] = max($contexts[$contextid] ?? 0, (int)$item->get('timecreated'));
            }
        }
        // Repeated completion of the same activity is ambiguous too. Later evidence
        // may concern a new review, so leave its selection to Moodle's review screen.
        if (count($contexts) !== 1 || $completioncount !== 1 || $completionid !== $latestid) {
            return null;
        }
        $contextid = (int)key($contexts);
        $context = \context::instance_by_id($contextid, IGNORE_MISSING);
        if (!$context || $context->contextlevel !== CONTEXT_MODULE) {
            return null;
        }
        $cmrecord = get_coursemodule_from_id('assign', $context->instanceid, 0, false, IGNORE_MISSING);
        if (!$cmrecord || !$DB->record_exists('competency_modulecomp', [
                'cmid' => $cmrecord->id, 'competencyid' => $record->competencyid,
                'ruleoutcome' => \core_competency\course_module_competency::OUTCOME_RECOMMEND])) {
            return null;
        }
        $course = get_course($cmrecord->course);
        $cm = get_fast_modinfo($course)->get_cm($cmrecord->id);
        if (!$cm->uservisible || !can_access_course($course) ||
                !has_capability('mod/assign:view', $context) ||
                !is_enrolled($context, $target->id, '', true)) {
            return null;
        }
        require_once($CFG->dirroot . '/mod/assign/locallib.php');
        $assignment = new \assign($context, $cm, $course);
        $instance = $assignment->get_instance();
        if (!empty($instance->blindmarking) || !empty($instance->teamsubmission) ||
                !empty($instance->markingallocation) || !$assignment->can_grade() ||
                !$assignment->can_view_grades() || !$assignment->can_view_submission($target->id) ||
                !$assignment->get_participant($target->id)) {
            return null;
        }
        // can_view_submission alone does not enforce separate-group membership.
        if ($cm->effectivegroupmode == SEPARATEGROUPS &&
                !has_capability('moodle/site:accessallgroups', $context)) {
            $allowed = groups_get_activity_allowed_groups($cm);
            $targetgroups = groups_get_all_groups($course->id, $target->id, $cm->groupingid);
            if (!array_intersect(array_keys($allowed), array_keys($targetgroups))) {
                return null;
            }
        }
        $submission = $assignment->get_user_submission($target->id, false);
        if (!$submission || $submission->status !== ASSIGN_SUBMISSION_STATUS_SUBMITTED ||
                empty($submission->latest) || (int)$submission->attemptnumber !== 0 ||
                $DB->count_records('assign_submission', [
                    'assignment' => $instance->id, 'userid' => $target->id,
                ]) !== 1 ||
                (int)$submission->timemodified > $contexts[$contextid]) {
            return null;
        }
        // Timestamps have second precision, so same-second edits cannot be detected.
        // The UI explicitly identifies this as related evidence, not an exact attempt binding.
        if (!$assignment->is_userid_filtered($target->id)) {
            return null;
        }
        return new \moodle_url('/mod/assign/view.php', [
            'id' => $cm->id, 'action' => 'grader', 'userid' => $target->id,
        ]);
    }
}
