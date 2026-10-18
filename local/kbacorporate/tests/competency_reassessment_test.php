<?php
namespace local_kbacorporate;

defined('MOODLE_INTERNAL') || die();

use local_kbacorporate\local\competency_audits;
use local_kbacorporate\local\competency_reassessment;
use local_kbacorporate\local\repository;

/** Regression guards for the post-development competency reassessment workflow. */
final class competency_reassessment_test extends \advanced_testcase {
    /** Prepare one normalised reassessment row for pure service tests. */
    private function row(array $values = []): \stdClass {
        $defaults = [
            'status' => competency_audits::ITEM_MISSING,
            'baseline' => null,
            'final' => null,
            'target' => 3,
            'delta' => null,
            'gap' => null,
            'reached' => false,
            'outcome' => competency_reassessment::OUTCOME_MISSING,
            'evidencecount' => 0,
        ];
        return (object)array_merge($defaults, $values);
    }

    public function test_outcome_state_distinguishes_workflow_and_level_changes(): void {
        $this->assertSame(competency_reassessment::OUTCOME_MISSING,
            competency_reassessment::outcome_state(competency_audits::ITEM_MISSING, 1, null, 3));
        $this->assertSame(competency_reassessment::OUTCOME_PENDING,
            competency_reassessment::outcome_state(competency_audits::ITEM_PENDING, 1, null, 3));
        $this->assertSame(competency_reassessment::OUTCOME_REJECTED,
            competency_reassessment::outcome_state(competency_audits::ITEM_REJECTED, 1, null, 3));
        $this->assertSame(competency_reassessment::OUTCOME_NEWLY_ASSESSED,
            competency_reassessment::outcome_state(competency_audits::ITEM_CONFIRMED, null, 2, 3));
        $this->assertSame(competency_reassessment::OUTCOME_IMPROVED,
            competency_reassessment::outcome_state(competency_audits::ITEM_CONFIRMED, 1, 2, 3));
        $this->assertSame(competency_reassessment::OUTCOME_UNCHANGED,
            competency_reassessment::outcome_state(competency_audits::ITEM_CONFIRMED, 2, 2, 3));
        $this->assertSame(competency_reassessment::OUTCOME_DECLINED,
            competency_reassessment::outcome_state(competency_audits::ITEM_CONFIRMED, 2, 1, 3));
        $this->assertSame(competency_reassessment::OUTCOME_TARGET_REACHED,
            competency_reassessment::outcome_state(competency_audits::ITEM_CONFIRMED, 1, 3, 3));
    }

    public function test_summary_does_not_treat_missing_or_pending_as_confirmed_levels(): void {
        $rows = [
            $this->row(['status' => competency_audits::ITEM_MISSING]),
            $this->row(['status' => competency_audits::ITEM_PENDING, 'evidencecount' => 2]),
            $this->row([
                'status' => competency_audits::ITEM_CONFIRMED,
                'baseline' => 1,
                'final' => 2,
                'target' => 3,
                'delta' => 1,
                'gap' => 1,
                'reached' => false,
                'outcome' => competency_reassessment::OUTCOME_IMPROVED,
                'evidencecount' => 1,
            ]),
            $this->row([
                'status' => competency_audits::ITEM_CONFIRMED,
                'baseline' => 2,
                'final' => 3,
                'target' => 3,
                'delta' => 1,
                'gap' => 0,
                'reached' => true,
                'outcome' => competency_reassessment::OUTCOME_TARGET_REACHED,
            ]),
            $this->row(['status' => competency_audits::ITEM_REJECTED]),
        ];

        $summary = competency_reassessment::summarise($rows);
        $this->assertSame(5, $summary->total);
        $this->assertSame(1, $summary->missing);
        $this->assertSame(1, $summary->pending);
        $this->assertSame(2, $summary->confirmed);
        $this->assertSame(1, $summary->rejected);
        $this->assertSame(2, $summary->improved);
        $this->assertSame(1, $summary->targetsreached);
        $this->assertSame(1, $summary->remaininggaps);
        $this->assertSame(3, $summary->evidence);
        $this->assertSame(40, $summary->assessmentcoverage);
        $this->assertSame(20, $summary->targetcoverage);
        $this->assertSame(2, $summary->unresolved);
    }

    public function test_workflow_state_requires_explicit_resolution(): void {
        $empty = competency_reassessment::summarise([]);
        $this->assertSame('empty', competency_reassessment::workflow_state(
            competency_audits::AUDIT_OPEN, $empty));

        $missing = competency_reassessment::summarise([
            $this->row(['status' => competency_audits::ITEM_MISSING]),
        ]);
        $this->assertSame('needsassessment', competency_reassessment::workflow_state(
            competency_audits::AUDIT_OPEN, $missing));

        $pending = competency_reassessment::summarise([
            $this->row(['status' => competency_audits::ITEM_PENDING]),
        ]);
        $this->assertSame('awaitingconfirmation', competency_reassessment::workflow_state(
            competency_audits::AUDIT_OPEN, $pending));

        $withgap = competency_reassessment::summarise([
            $this->row([
                'status' => competency_audits::ITEM_CONFIRMED,
                'baseline' => 1,
                'final' => 2,
                'target' => 3,
                'delta' => 1,
                'gap' => 1,
                'reached' => false,
            ]),
        ]);
        $this->assertSame('completedwithgaps', competency_reassessment::workflow_state(
            competency_audits::AUDIT_COMPLETED, $withgap));

        $rejected = competency_reassessment::summarise([
            $this->row(['status' => competency_audits::ITEM_REJECTED]),
        ]);
        $this->assertSame('completedwithrejections', competency_reassessment::workflow_state(
            competency_audits::AUDIT_COMPLETED, $rejected));

        $complete = competency_reassessment::summarise([
            $this->row([
                'status' => competency_audits::ITEM_CONFIRMED,
                'baseline' => 1,
                'final' => 3,
                'target' => 3,
                'delta' => 2,
                'gap' => 0,
                'reached' => true,
            ]),
        ]);
        $this->assertSame('completed', competency_reassessment::workflow_state(
            competency_audits::AUDIT_COMPLETED, $complete));
    }


    public function test_old_moodle_grade_is_excluded_by_reassessment_cutoff(): void {
        global $DB;
        $this->resetAfterTest();
        $this->setAdminUser();

        $now = time();
        $companyid = (int)$DB->insert_record('local_kbacorp_company', (object)[
            'name' => 'Reassessment company',
            'active' => 1,
            'timecreated' => $now,
            'timemodified' => $now,
        ]);
        $user = $this->getDataGenerator()->create_user();
        repository::add_member($companyid, (int)$user->id, 'employee');
        $generator = $this->getDataGenerator()->get_plugin_generator('core_competency');
        $framework = $generator->create_framework();
        $competency = $generator->create_competency([
            'competencyframeworkid' => $framework->get('id'),
        ]);
        \core_competency\api::grade_competency(
            (int)$user->id, (int)$competency->get('id'), 2, 'Baseline before development'
        );

        $all = competency_audits::collect_reassessment_evidence(
            (int)$user->id, $companyid, (int)$competency->get('id'),
            (int)$framework->get('scaleid'), 0
        );
        $this->assertNotNull($all['currentlevel']);
        $this->assertNotEmpty($all['evidence']);

        $postplan = competency_audits::collect_reassessment_evidence(
            (int)$user->id, $companyid, (int)$competency->get('id'),
            (int)$framework->get('scaleid'), time() + 10
        );
        $this->assertNull($postplan['currentlevel']);
        $this->assertSame([], $postplan['evidence']);
    }


    public function test_completed_plan_snapshots_baseline_without_auto_confirming_old_grade(): void {
        global $DB;
        $this->resetAfterTest();
        $this->setAdminUser();

        $now = time();
        $oldtime = $now - (30 * DAYSECS);
        $companyid = (int)$DB->insert_record('local_kbacorp_company', (object)[
            'name' => 'Completed plan company',
            'active' => 1,
            'timecreated' => $oldtime,
            'timemodified' => $now,
        ]);
        $user = $this->getDataGenerator()->create_user();
        repository::add_member($companyid, (int)$user->id, 'employee');
        $generator = $this->getDataGenerator()->get_plugin_generator('core_competency');
        $framework = $generator->create_framework();
        $competency = $generator->create_competency([
            'competencyframeworkid' => $framework->get('id'),
        ]);
        \core_competency\api::grade_competency(
            (int)$user->id, (int)$competency->get('id'), 2, 'Old baseline'
        );
        $usercompetency = $DB->get_record('competency_usercomp', [
            'userid' => (int)$user->id,
            'competencyid' => (int)$competency->get('id'),
        ], '*', MUST_EXIST);
        $DB->set_field('competency_usercomp', 'timemodified', $oldtime, ['id' => (int)$usercompetency->id]);
        if ($DB->get_manager()->table_exists(new \xmldb_table('competency_evidence'))) {
            $DB->set_field('competency_evidence', 'timecreated', $oldtime,
                ['usercompetencyid' => (int)$usercompetency->id]);
        }

        $planid = (int)$DB->insert_record('local_kbacorp_devplan', (object)[
            'companyid' => $companyid,
            'userid' => (int)$user->id,
            'title' => 'Completed development plan',
            'status' => \local_kbacorporate\local\development_plans::STATUS_COMPLETED,
            'createdby' => 2,
            'submittedby' => 2,
            'timesubmitted' => $oldtime,
            'timeacknowledged' => $oldtime,
            'lastremindersent' => 0,
            'followupauditid' => 0,
            'moodleplanid' => 0,
            'startdate' => $now - (7 * DAYSECS),
            'targetdate' => $now,
            'timecreated' => $now - (7 * DAYSECS),
            'timemodified' => $now,
        ]);
        $itemid = (int)$DB->insert_record('local_kbacorp_devitem', (object)[
            'planid' => $planid,
            'competencyid' => (int)$competency->get('id'),
            'courseid' => 0,
            'currentlevel' => 1,
            'targetlevel' => 3,
            'priority' => 'critical',
            'status' => \local_kbacorporate\local\development_plans::ITEM_COMPLETED,
            'duedate' => $now,
            'timecompleted' => $now,
            'timecreated' => $now - (7 * DAYSECS),
            'timemodified' => $now,
        ]);

        $auditid = competency_audits::create_development_audit($planid, 2);
        $audititems = competency_audits::get_items($auditid);
        $this->assertCount(1, $audititems);
        $this->assertSame(competency_audits::ITEM_MISSING, $audititems[0]->status);
        $this->assertSame(0, (int)$audititems[0]->timeconfirmed);
        $this->assertSame(0, (int)$audititems[0]->reviewerid);
        $snapshot = competency_reassessment::decode_snapshot((string)$audititems[0]->rolesnapshot);
        $this->assertSame('reassessment-v1', $snapshot->workflow);
        $this->assertSame($planid, (int)$snapshot->developmentplanid);
        $this->assertSame($itemid, (int)$snapshot->developmentitemid);
        $this->assertSame(1, (int)$snapshot->baselinelevel);
        $this->assertSame(3, (int)$snapshot->targetlevel);
        $this->assertSame($auditid,
            competency_audits::create_development_audit($planid, 2));
    }

    public function test_snapshot_decoder_is_backward_compatible(): void {
        $snapshot = competency_reassessment::decode_snapshot(json_encode([
            'workflow' => 'reassessment-v1',
            'developmentplanid' => 12,
            'baselinelevel' => 1,
            'targetlevel' => 3,
        ]));
        $this->assertSame('reassessment-v1', $snapshot->workflow);
        $this->assertSame(12, $snapshot->developmentplanid);
        $this->assertSame(1, $snapshot->baselinelevel);
        $this->assertSame(3, $snapshot->targetlevel);

        $this->assertSame([], (array)competency_reassessment::decode_snapshot('not-json'));
        $this->assertSame([], (array)competency_reassessment::decode_snapshot(''));
    }
}
