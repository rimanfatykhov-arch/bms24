<?php
namespace local_kbacorporate\local;

defined('MOODLE_INTERNAL') || die();

/**
 * Read-only progress projection for one KBA development plan and its Moodle courses.
 *
 * Course completion is treated as learning evidence. It may complete a plan action,
 * but it never changes the authoritative Moodle competency level. Confirmed levels
 * remain the only source for deciding whether the target competency level is reached.
 */
class study_plan_progress {
    public const FILTER_ALL = 'all';
    public const FILTER_ATTENTION = 'attention';
    public const FILTER_OVERDUE = 'overdue';
    public const FILTER_REASSESSMENT = 'reassessment';
    public const FILTER_INPROGRESS = 'inprogress';
    public const FILTER_COMPLETED = 'completed';
    public const FILTER_NOCOURSE = 'nocourse';

    /** Build the current progress projection for one development plan. */
    public static function calculate(int $planid, ?int $now = null): \stdClass {
        global $DB;

        $now = $now ?? time();
        $plan = development_plans::get_plan($planid);
        $items = development_plans::get_items($planid);
        $outcomes = development_plans::get_outcome_summary($planid);
        $today = usergetmidnight($now);
        $soon = $today + (14 * DAYSECS);
        $rows = [];

        foreach ($items as $item) {
            $course = self::course_state((int)$item->courseid, (int)$plan->userid);
            $outcome = $outcomes->rows[(int)$item->competencyid] ?? (object)[
                'baseline' => $item->currentlevel === null ? null : (int)$item->currentlevel,
                'current' => null,
                'target' => (int)$item->targetlevel,
                'delta' => null,
                'gap' => null,
                'reached' => false,
            ];

            $duedate = (int)$item->duedate;
            $deadline = 'none';
            if ((string)$item->status === development_plans::ITEM_COMPLETED) {
                $deadline = 'completed';
            } else if ($duedate > 0 && $duedate < $today) {
                $deadline = 'overdue';
            } else if ($duedate >= $today && $duedate <= $soon) {
                $deadline = 'duesoon';
            } else if ($duedate > 0) {
                $deadline = 'ontime';
            }

            $reassessment = self::reassessment_state($item, $course, $outcome);
            $nextaction = self::next_action($item, $course, $outcome, $deadline);
            $needslearningattention = empty($outcome->reached) &&
                (in_array((string)$course->status, ['notenrolled', 'missing', 'error'], true) ||
                    (int)$item->courseid <= 0);
            $needsattention = $deadline === 'overdue' ||
                (string)$item->status === development_plans::ITEM_PENDING_REVIEW ||
                in_array($reassessment, ['required', 'missingassessment'], true) ||
                $needslearningattention;

            $rows[] = (object)[
                'itemid' => (int)$item->id,
                'competencyid' => (int)$item->competencyid,
                'competencyname' => (string)$item->competencyname,
                'idnumber' => (string)$item->idnumber,
                'priority' => business_roles::normalise_priority((string)$item->priority),
                'itemstatus' => (string)$item->status,
                'duedate' => $duedate,
                'deadline' => $deadline,
                'courseid' => (int)$item->courseid,
                'coursename' => (string)($item->coursename ?? ''),
                'course' => $course,
                'baseline' => $outcome->baseline,
                'current' => $outcome->current,
                'target' => (int)$outcome->target,
                'delta' => $outcome->delta,
                'gap' => $outcome->gap,
                'reached' => !empty($outcome->reached),
                'reassessment' => $reassessment,
                'nextaction' => $nextaction,
                'needsattention' => $needsattention,
            ];
        }

        return (object)[
            'plan' => $plan,
            'rows' => $rows,
            'summary' => self::summarise($rows),
            'outcomes' => $outcomes,
        ];
    }

    /**
     * Deterministic summary over prepared rows. Kept public for regression tests.
     */
    public static function summarise(array $rows): \stdClass {
        $summary = (object)[
            'total' => count($rows),
            'planned' => 0,
            'inprogress' => 0,
            'pendingreview' => 0,
            'completed' => 0,
            'overdue' => 0,
            'duesoon' => 0,
            'withcourse' => 0,
            'withoutcourse' => 0,
            'enrolled' => 0,
            'coursescompleted' => 0,
            'courseprogresscount' => 0,
            'courseprogresssum' => 0,
            'averagecourseprogress' => null,
            'assessed' => 0,
            'unassessed' => 0,
            'improved' => 0,
            'targetsreached' => 0,
            'remaininggaps' => 0,
            'needsreassessment' => 0,
            'attention' => 0,
            'actionprogress' => 0,
            'targetprogress' => 0,
            'assessmentcoverage' => 0,
        ];

        foreach ($rows as $row) {
            $itemstatus = (string)$row->itemstatus;
            if ($itemstatus === development_plans::ITEM_COMPLETED) {
                $summary->completed++;
            } else if ($itemstatus === development_plans::ITEM_INPROGRESS) {
                $summary->inprogress++;
            } else if ($itemstatus === development_plans::ITEM_PENDING_REVIEW) {
                $summary->pendingreview++;
                $summary->inprogress++;
            } else {
                $summary->planned++;
            }

            if ((string)$row->deadline === 'overdue') {
                $summary->overdue++;
            } else if ((string)$row->deadline === 'duesoon') {
                $summary->duesoon++;
            }

            if ((int)$row->courseid > 0) {
                $summary->withcourse++;
                if (!empty($row->course->enrolled)) {
                    $summary->enrolled++;
                }
                if ((string)$row->course->status === 'completed') {
                    $summary->coursescompleted++;
                }
                if ($row->course->progress !== null) {
                    $summary->courseprogresscount++;
                    $summary->courseprogresssum += (float)$row->course->progress;
                }
            } else {
                $summary->withoutcourse++;
            }

            if ($row->current === null) {
                $summary->unassessed++;
            } else {
                $summary->assessed++;
                if ($row->delta !== null && (int)$row->delta > 0) {
                    $summary->improved++;
                }
                if (!empty($row->reached)) {
                    $summary->targetsreached++;
                } else {
                    $summary->remaininggaps++;
                }
            }
            if (in_array((string)$row->reassessment, ['required', 'missingassessment'], true)) {
                $summary->needsreassessment++;
            }
            if (!empty($row->needsattention)) {
                $summary->attention++;
            }
        }

        if ($summary->courseprogresscount > 0) {
            $summary->averagecourseprogress = round(
                $summary->courseprogresssum / $summary->courseprogresscount,
                1
            );
        }
        if ($summary->total > 0) {
            $summary->actionprogress = (int)round(($summary->completed / $summary->total) * 100);
            $summary->targetprogress = (int)round(($summary->targetsreached / $summary->total) * 100);
            $summary->assessmentcoverage = (int)round(($summary->assessed / $summary->total) * 100);
        }
        unset($summary->courseprogresssum, $summary->courseprogresscount);
        return $summary;
    }

    /** Apply one supported table filter without changing source rows. */
    public static function filter(array $rows, string $filter, string $query = ''): array {
        $allowed = [self::FILTER_ALL, self::FILTER_ATTENTION, self::FILTER_OVERDUE,
            self::FILTER_REASSESSMENT, self::FILTER_INPROGRESS, self::FILTER_COMPLETED,
            self::FILTER_NOCOURSE];
        if (!in_array($filter, $allowed, true)) {
            $filter = self::FILTER_ALL;
        }
        $query = \core_text::strtolower(trim($query));

        return array_values(array_filter($rows, static function($row) use ($filter, $query) {
            if ($filter === self::FILTER_ATTENTION && empty($row->needsattention)) {
                return false;
            }
            if ($filter === self::FILTER_OVERDUE && (string)$row->deadline !== 'overdue') {
                return false;
            }
            if ($filter === self::FILTER_REASSESSMENT &&
                    !in_array((string)$row->reassessment, ['required', 'missingassessment'], true)) {
                return false;
            }
            if ($filter === self::FILTER_INPROGRESS &&
                    (string)$row->course->status !== 'inprogress' &&
                    !in_array((string)$row->itemstatus, [development_plans::ITEM_INPROGRESS,
                        development_plans::ITEM_PENDING_REVIEW], true)) {
                return false;
            }
            if ($filter === self::FILTER_COMPLETED &&
                    (string)$row->itemstatus !== development_plans::ITEM_COMPLETED) {
                return false;
            }
            if ($filter === self::FILTER_NOCOURSE && (int)$row->courseid > 0) {
                return false;
            }
            if ($query !== '') {
                $haystack = \core_text::strtolower((string)$row->competencyname . ' ' .
                    (string)$row->idnumber . ' ' . (string)$row->coursename);
                if (strpos($haystack, $query) === false) {
                    return false;
                }
            }
            return true;
        }));
    }

    /** Current course state for the plan subject. */
    private static function course_state(int $courseid, int $userid): \stdClass {
        global $DB, $CFG;
        require_once($CFG->libdir . '/completionlib.php');

        $state = (object)[
            'exists' => false,
            'visible' => false,
            'enrolled' => false,
            'status' => $courseid > 0 ? 'missing' : 'nocourse',
            'progress' => null,
            'completed' => false,
            'lastaccess' => 0,
            'url' => '',
        ];
        if ($courseid <= 0 || !$DB->record_exists('course', ['id' => $courseid])) {
            return $state;
        }

        try {
            $course = get_course($courseid);
            $context = \context_course::instance($courseid);
            $state->exists = true;
            $state->visible = !empty($course->visible);
            $state->enrolled = is_enrolled($context, $userid, '', true);
            $state->url = (new \moodle_url('/course/view.php', ['id' => $courseid]))->out(false);
            $state->lastaccess = (int)$DB->get_field('user_lastaccess', 'timeaccess', [
                'userid' => $userid,
                'courseid' => $courseid,
            ]);

            if (!$state->enrolled) {
                $state->status = 'notenrolled';
                return $state;
            }

            $percentage = \core_completion\progress::get_course_progress_percentage($course, $userid);
            if ($percentage !== null) {
                $state->progress = max(0, min(100, (int)round($percentage)));
            }

            $completion = new \completion_info($course);
            if ($completion->is_enabled() && $completion->is_course_complete($userid)) {
                $state->status = 'completed';
                $state->completed = true;
                $state->progress = 100;
            } else if (($state->progress ?? 0) > 0 || $state->lastaccess > 0) {
                $state->status = 'inprogress';
            } else {
                $state->status = 'notstarted';
            }
        } catch (\Throwable $e) {
            $state->status = 'error';
            debugging('Unable to read study-plan course #' . $courseid . ': ' . $e->getMessage(), DEBUG_DEVELOPER);
        }
        return $state;
    }

    /** Determine whether a confirmed reassessment is needed after the learning action. */
    private static function reassessment_state(\stdClass $item, \stdClass $course, \stdClass $outcome): string {
        if (!empty($outcome->reached)) {
            return 'closed';
        }
        $requiresevidence = trim((string)($item->practicaltask ?? '')) !== '' ||
            trim((string)($item->evidencecriteria ?? '')) !== '';
        $learningfinished = (string)$item->status === development_plans::ITEM_COMPLETED ||
            ((string)$course->status === 'completed' && !$requiresevidence);
        if ($learningfinished && $outcome->current === null) {
            return 'missingassessment';
        }
        if ($learningfinished) {
            return 'required';
        }
        if ((string)$course->status === 'inprogress') {
            return 'aftercourse';
        }
        return 'notready';
    }

    /** Choose one unambiguous next action for display. */
    private static function next_action(\stdClass $item, \stdClass $course, \stdClass $outcome,
            string $deadline): string {
        if (!empty($outcome->reached)) {
            return 'targetreached';
        }
        if ((string)$item->status === development_plans::ITEM_PENDING_REVIEW) {
            return 'reviewevidence';
        }
        $requiresevidence = trim((string)($item->practicaltask ?? '')) !== '' ||
            trim((string)($item->evidencecriteria ?? '')) !== '';
        if ($requiresevidence && (string)$item->status !== development_plans::ITEM_COMPLETED &&
                ((string)$course->status === 'completed' || (int)$item->courseid <= 0)) {
            return 'submitevidence';
        }
        if (in_array((string)$item->status, [development_plans::ITEM_COMPLETED], true) ||
                (string)$course->status === 'completed') {
            return 'reassess';
        }
        if ((int)$item->courseid <= 0 || (string)$course->status === 'missing') {
            return 'assigncourse';
        }
        if ((string)$course->status === 'notenrolled') {
            return 'enrol';
        }
        if ((string)$course->status === 'inprogress') {
            return $deadline === 'overdue' ? 'continueoverdue' : 'continuecourse';
        }
        if ((string)$course->status === 'notstarted') {
            return $deadline === 'overdue' ? 'startoverdue' : 'startcourse';
        }
        return 'review';
    }
}
