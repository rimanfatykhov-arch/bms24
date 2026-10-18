<?php
namespace local_kbacorporate\local;

defined('MOODLE_INTERNAL') || die();

/**
 * Follow-up competency reassessment workflow for completed development plans.
 *
 * The service keeps the plan-item baseline immutable, requires an explicit
 * reviewer confirmation before changing a Moodle competency grade, and builds
 * the before/after/target projection without inventing level zero for missing
 * assessments.
 */
class competency_reassessment {
    public const OUTCOME_MISSING = 'missing';
    public const OUTCOME_PENDING = 'pending';
    public const OUTCOME_REJECTED = 'rejected';
    public const OUTCOME_NEWLY_ASSESSED = 'newlyassessed';
    public const OUTCOME_IMPROVED = 'improved';
    public const OUTCOME_UNCHANGED = 'unchanged';
    public const OUTCOME_DECLINED = 'declined';
    public const OUTCOME_TARGET_REACHED = 'targetreached';

    /** Whether the required local tables are available. */
    public static function available(): bool {
        return competency_audits::available() && development_plans::available();
    }

    /** Return the development plan linked to a development audit. */
    public static function plan_for_audit(int $auditid): \stdClass {
        global $DB;

        $plan = $DB->get_record('local_kbacorp_devplan', ['followupauditid' => $auditid], '*', IGNORE_MULTIPLE);
        if ($plan) {
            return development_plans::get_plan((int)$plan->id);
        }

        // Backward-compatible fallback for audits created before followupauditid was linked.
        $snapshots = $DB->get_fieldset_select('local_kbacorp_audit_item', 'rolesnapshot',
            'auditid = :auditid AND rolesnapshot IS NOT NULL', ['auditid' => $auditid]);
        foreach ($snapshots as $rawsnapshot) {
            $snapshot = self::decode_snapshot((string)$rawsnapshot);
            $planid = (int)($snapshot->developmentplanid ?? 0);
            if ($planid > 0 && $DB->record_exists('local_kbacorp_devplan', ['id' => $planid])) {
                return development_plans::get_plan($planid);
            }
        }
        throw new \moodle_exception('reassessmentplannotfound', 'local_kbacorporate');
    }

    /** Build the complete reassessment projection. */
    public static function build(int $auditid): \stdClass {
        global $DB;

        $audit = competency_audits::get_audit($auditid);
        if ((string)$audit->audittype !== competency_audits::AUDIT_TYPE_DEVELOPMENT) {
            throw new \moodle_exception('reassessmentwrongaudittype', 'local_kbacorporate');
        }
        $plan = self::plan_for_audit($auditid);
        if ((int)$plan->companyid !== (int)$audit->companyid || (int)$plan->userid !== (int)$audit->userid) {
            throw new \moodle_exception('reassessmentplanmismatch', 'local_kbacorporate');
        }

        $planitems = [];
        foreach (development_plans::get_items((int)$plan->id) as $item) {
            $planitems[(int)$item->competencyid] = $item;
        }
        $evidencebyitem = competency_audits::get_evidence_for_audit($auditid);
        $rows = [];

        foreach (competency_audits::get_items($auditid) as $item) {
            $competencyid = (int)$item->competencyid;
            $planitem = $planitems[$competencyid] ?? null;
            $snapshot = self::decode_snapshot((string)$item->rolesnapshot);
            $baseline = property_exists($snapshot, 'baselinelevel')
                ? ($snapshot->baselinelevel === null ? null : (int)$snapshot->baselinelevel)
                : ($planitem && $planitem->currentlevel !== null ? (int)$planitem->currentlevel : null);
            $target = $item->targetlevel === null
                ? ($planitem ? (int)$planitem->targetlevel : null)
                : (int)$item->targetlevel;
            $final = (string)$item->status === competency_audits::ITEM_CONFIRMED
                ? (int)$item->proposedlevel : null;
            $proposal = (string)$item->status === competency_audits::ITEM_PENDING
                ? (int)$item->proposedlevel : null;
            $delta = ($baseline === null || $final === null) ? null : $final - $baseline;
            $gap = ($target === null || $final === null) ? null : max($target - $final, 0);
            $reached = $target !== null && $final !== null && $final >= $target;
            $outcome = self::outcome_state((string)$item->status, $baseline, $final, $target);
            $courseid = (int)($snapshot->courseid ?? ($planitem->courseid ?? 0));
            $course = null;
            if ($courseid > 0) {
                $course = $DB->get_record('course', ['id' => $courseid], 'id,fullname,visible', IGNORE_MISSING);
            }
            $evidence = array_values($evidencebyitem[(int)$item->id] ?? []);
            $cutoff = (int)($snapshot->evidencecutoff ?? ($planitem->timecreated ?? $plan->startdate ?? 0));

            $rows[] = (object)[
                'itemid' => (int)$item->id,
                'auditid' => $auditid,
                'competencyid' => $competencyid,
                'shortname' => (string)$item->shortname,
                'idnumber' => (string)$item->idnumber,
                'frameworkname' => (string)$item->frameworkname,
                'scaleid' => (int)($item->competencyscaleid ?: $item->frameworkscaleid),
                'baseline' => $baseline,
                'proposal' => $proposal,
                'final' => $final,
                'target' => $target,
                'delta' => $delta,
                'gap' => $gap,
                'reached' => $reached,
                'outcome' => $outcome,
                'status' => (string)$item->status,
                'method' => (string)$item->method,
                'source' => (string)$item->source,
                'priority' => business_roles::normalise_priority((string)$item->priority),
                'required' => !empty($item->required),
                'assessorid' => (int)$item->assessorid,
                'reviewerid' => (int)$item->reviewerid,
                'assessorname' => trim((string)$item->assessorfirstname . ' ' . (string)$item->assessorlastname),
                'reviewername' => trim((string)$item->reviewerfirstname . ' ' . (string)$item->reviewerlastname),
                'comment' => (string)$item->comment,
                'timeassessed' => (int)$item->timeassessed,
                'timeconfirmed' => (int)$item->timeconfirmed,
                'evidence' => $evidence,
                'evidencecount' => count($evidence),
                'evidencecutoff' => $cutoff,
                'courseid' => $courseid,
                'coursename' => $course ? (string)$course->fullname : (string)($planitem->coursename ?? ''),
                'coursevisible' => $course ? !empty($course->visible) : false,
                'coursecompletedat' => (int)($snapshot->coursecompletedat ?? ($planitem->timecompleted ?? 0)),
                'developmentitemid' => (int)($snapshot->developmentitemid ?? ($planitem->id ?? 0)),
            ];
        }

        usort($rows, static function($a, $b) {
            $priorityrank = [
                business_roles::PRIORITY_CRITICAL => 1,
                business_roles::PRIORITY_CORE => 2,
                business_roles::PRIORITY_ADDITIONAL => 3,
            ];
            $comparison = ($priorityrank[$a->priority] ?? 9) <=> ($priorityrank[$b->priority] ?? 9);
            if ($comparison !== 0) {
                return $comparison;
            }
            return strcasecmp($a->shortname, $b->shortname);
        });

        $summary = self::summarise($rows);
        return (object)[
            'audit' => $audit,
            'plan' => $plan,
            'rows' => $rows,
            'summary' => $summary,
            'state' => self::workflow_state((string)$audit->status, $summary),
        ];
    }

    /** Determine one display outcome for an audit item. */
    public static function outcome_state(string $status, ?int $baseline, ?int $final,
            ?int $target): string {
        if ($status === competency_audits::ITEM_MISSING) {
            return self::OUTCOME_MISSING;
        }
        if ($status === competency_audits::ITEM_PENDING) {
            return self::OUTCOME_PENDING;
        }
        if ($status === competency_audits::ITEM_REJECTED) {
            return self::OUTCOME_REJECTED;
        }
        if ($final === null) {
            return self::OUTCOME_MISSING;
        }
        if ($target !== null && $final >= $target) {
            return self::OUTCOME_TARGET_REACHED;
        }
        if ($baseline === null) {
            return self::OUTCOME_NEWLY_ASSESSED;
        }
        if ($final > $baseline) {
            return self::OUTCOME_IMPROVED;
        }
        if ($final < $baseline) {
            return self::OUTCOME_DECLINED;
        }
        return self::OUTCOME_UNCHANGED;
    }

    /** Summarise prepared reassessment rows. Public for regression tests. */
    public static function summarise(array $rows): \stdClass {
        $summary = (object)[
            'total' => count($rows),
            'missing' => 0,
            'pending' => 0,
            'confirmed' => 0,
            'rejected' => 0,
            'improved' => 0,
            'unchanged' => 0,
            'declined' => 0,
            'newlyassessed' => 0,
            'targetsreached' => 0,
            'remaininggaps' => 0,
            'evidence' => 0,
            'assessmentcoverage' => 0,
            'targetcoverage' => 0,
            'unresolved' => 0,
        ];
        foreach ($rows as $row) {
            $status = (string)$row->status;
            if (property_exists($summary, $status)) {
                $summary->{$status}++;
            }
            $summary->evidence += (int)($row->evidencecount ?? 0);
            if ($status === competency_audits::ITEM_CONFIRMED) {
                if ($row->baseline === null) {
                    $summary->newlyassessed++;
                } else if ($row->delta !== null && (int)$row->delta > 0) {
                    $summary->improved++;
                } else if ($row->delta !== null && (int)$row->delta < 0) {
                    $summary->declined++;
                } else {
                    $summary->unchanged++;
                }
                if (!empty($row->reached)) {
                    $summary->targetsreached++;
                } else {
                    $summary->remaininggaps++;
                }
            }
        }
        $summary->unresolved = $summary->missing + $summary->pending;
        if ($summary->total > 0) {
            $summary->assessmentcoverage = (int)round(($summary->confirmed / $summary->total) * 100);
            $summary->targetcoverage = (int)round(($summary->targetsreached / $summary->total) * 100);
        }
        return $summary;
    }

    /** Human workflow state based on audit status and summary. */
    public static function workflow_state(string $auditstatus, \stdClass $summary): string {
        if ($summary->total === 0) {
            return 'empty';
        }
        if ($auditstatus !== competency_audits::AUDIT_COMPLETED) {
            if ($summary->pending > 0) {
                return 'awaitingconfirmation';
            }
            return 'needsassessment';
        }
        if ($summary->rejected > 0) {
            return 'completedwithrejections';
        }
        if ($summary->remaininggaps > 0) {
            return 'completedwithgaps';
        }
        return 'completed';
    }

    /**
     * Collect new Moodle evidence for open reassessment items.
     *
     * Confirmed and rejected items are never overwritten. A newly discovered
     * level becomes a pending proposal and still requires explicit review.
     */
    public static function refresh_evidence(int $auditid, int $actorid): \stdClass {
        global $DB;

        $projection = self::build($auditid);
        if ((string)$projection->audit->status !== competency_audits::AUDIT_OPEN) {
            return (object)['items' => 0, 'evidence' => 0, 'proposals' => 0, 'unchanged' => count($projection->rows)];
        }

        $result = (object)['items' => 0, 'evidence' => 0, 'proposals' => 0, 'unchanged' => 0];
        $transaction = $DB->start_delegated_transaction();
        foreach ($projection->rows as $row) {
            if (in_array((string)$row->status, [
                    competency_audits::ITEM_CONFIRMED,
                    competency_audits::ITEM_REJECTED,
                ], true)) {
                $result->unchanged++;
                continue;
            }

            $collected = competency_audits::collect_reassessment_evidence(
                (int)$projection->audit->userid,
                (int)$projection->audit->companyid,
                (int)$row->competencyid,
                (int)$row->scaleid,
                (int)$row->evidencecutoff
            );

            $existing = [];
            foreach ($DB->get_records('local_kbacorp_audit_ev', ['audititemid' => (int)$row->itemid]) as $evidence) {
                $existing[self::evidence_key($evidence)] = true;
            }
            foreach ($collected['evidence'] as $evidence) {
                $key = self::evidence_key($evidence);
                if (isset($existing[$key])) {
                    continue;
                }
                $evidence->audititemid = (int)$row->itemid;
                $evidence->timecreated = time();
                $DB->insert_record('local_kbacorp_audit_ev', $evidence);
                $existing[$key] = true;
                $result->evidence++;
            }

            $candidate = self::candidate_from_collected($collected);
            if ($candidate === null) {
                $result->unchanged++;
                continue;
            }

            $record = $DB->get_record('local_kbacorp_audit_item', ['id' => (int)$row->itemid], '*', MUST_EXIST);
            if ((string)$record->status === competency_audits::ITEM_PENDING &&
                    (string)$record->source === 'manual_assessment') {
                $result->unchanged++;
                continue;
            }
            $record->method = (string)$candidate->method;
            $record->proposedlevel = (int)$candidate->level;
            $record->source = (string)$candidate->source;
            $record->status = competency_audits::ITEM_PENDING;
            $record->assessorid = $actorid;
            $record->reviewerid = 0;
            $record->timeassessed = (int)$candidate->time;
            $record->timeconfirmed = 0;
            $record->timemodified = time();
            $DB->update_record('local_kbacorp_audit_item', $record);
            $result->items++;
            $result->proposals++;
        }
        if ($result->items > 0) {
            $DB->set_field('local_kbacorp_audit', 'status', competency_audits::AUDIT_OPEN, ['id' => $auditid]);
            $DB->set_field('local_kbacorp_audit', 'completedat', 0, ['id' => $auditid]);
            $DB->set_field('local_kbacorp_audit', 'timemodified', time(), ['id' => $auditid]);
        }
        $transaction->allow_commit();
        return $result;
    }

    /** Parse the versioned JSON snapshot stored in audit_item.rolesnapshot. */
    public static function decode_snapshot(string $json): \stdClass {
        $decoded = json_decode($json);
        return is_object($decoded) ? $decoded : (object)[];
    }

    /** Select the newest level-bearing source without auto-confirming it. */
    private static function candidate_from_collected(array $collected): ?\stdClass {
        if ($collected['currentlevel'] !== null) {
            return (object)[
                'level' => (int)$collected['currentlevel'],
                'time' => (int)$collected['currenttime'],
                'method' => 'expert',
                'source' => 'moodle_reassessment',
            ];
        }
        foreach ($collected['evidence'] as $evidence) {
            if ($evidence->proposedlevel === null) {
                continue;
            }
            return (object)[
                'level' => (int)$evidence->proposedlevel,
                'time' => (int)$evidence->timeobserved,
                'method' => (string)$evidence->sourcetype === 'course_result' ? 'test' : 'practice',
                'source' => 'reassessment_evidence',
            ];
        }
        return null;
    }

    /** Stable deduplication key for evidence snapshots. */
    private static function evidence_key($evidence): string {
        $sourceid = (int)($evidence->sourceitemid ?? 0);
        if ($sourceid > 0) {
            return (string)($evidence->sourcetype ?? '') . '|' .
                (string)($evidence->sourcecomponent ?? '') . '|' . $sourceid;
        }
        return (string)($evidence->sourcetype ?? '') . '|' .
            (string)($evidence->title ?? '') . '|' . (int)($evidence->timeobserved ?? 0);
    }
}
