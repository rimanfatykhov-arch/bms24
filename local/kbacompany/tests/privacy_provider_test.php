<?php
namespace local_kbacompany;

defined('MOODLE_INTERNAL') || die();

use local_kbacompany\privacy\provider;

/** Privacy coverage for employee position assignments. */
final class privacy_provider_test extends \advanced_testcase {
    public function test_statistic_author_can_be_anonymised_without_losing_value(): void {
        global $DB;
        $this->resetAfterTest();
        $user = $this->getDataGenerator()->create_user();
        $id = $DB->insert_record('local_kbacompany_statval', (object)[
            'companyuuid' => '11111111-1111-4111-8111-111111111111',
            'statuuid' => '22222222-2222-4222-8222-222222222222',
            'periodstart' => '2026-09-01', 'kind' => 'fact', 'amount' => '5',
            'revision' => 1, 'writerid' => $user->id, 'reason' => 'Personal note', 'timecreated' => time(),
        ]);
        $contexts = provider::get_contexts_for_userid((int)$user->id);
        $this->assertContains(\context_system::instance()->id, array_map('intval', $contexts->get_contextids()));
        $approved = new \core_privacy\local\request\approved_contextlist(
            $user, 'local_kbacompany', [\context_system::instance()->id]);
        provider::delete_data_for_user($approved);
        $record = $DB->get_record('local_kbacompany_statval', ['id' => $id], '*', MUST_EXIST);
        $this->assertEquals(0, $record->writerid);
        $this->assertSame('', $record->reason);
        $this->assertEquals(5, $record->amount);
    }

    public function test_assignment_context_is_returned_for_user(): void {
        global $DB;
        $this->resetAfterTest();
        $user = $this->getDataGenerator()->create_user();
        $DB->insert_record('local_kbacompany_posassign', (object)[
            'companyuuid' => '11111111-1111-4111-8111-111111111111',
            'positionuuid' => '22222222-2222-4222-8222-222222222222',
            'userid' => $user->id, 'assignmenttype' => 'primary', 'effectivefrom' => time(),
            'effectiveto' => 0, 'active' => 1, 'timecreated' => time(), 'timemodified' => time(),
        ]);
        $contexts = provider::get_contexts_for_userid((int)$user->id);
        $this->assertContains(\context_system::instance()->id, array_map('intval', $contexts->get_contextids()));
    }
    public function test_communication_user_data_is_anonymised_without_deleting_other_participants_thread(): void {
        global $DB;
        $this->resetAfterTest();
        $user = $this->getDataGenerator()->create_user();
        $other = $this->getDataGenerator()->create_user();
        $company = '11111111-1111-4111-8111-111111111111';
        $thread = '22222222-2222-4222-8222-222222222222';
        $message = '33333333-3333-4333-8333-333333333333';
        $DB->insert_record('local_kbacompany_commthread', (object)[
            'companyuuid' => $company, 'uuid' => $thread, 'threadtype' => 'group', 'title' => 'Private title',
            'contextkind' => '', 'contextuuid' => '', 'contextrevision' => 0, 'createdby' => $user->id,
            'status' => 'active', 'lastmessageat' => time(), 'timecreated' => time(), 'timemodified' => time(),
        ]);
        foreach ([$user, $other] as $person) {
            $DB->insert_record('local_kbacompany_commpart', (object)[
                'companyuuid' => $company, 'threaduuid' => $thread, 'userid' => $person->id,
                'role' => $person->id === $user->id ? 'owner' : 'member', 'active' => 1, 'muted' => 0,
                'joinedat' => time(), 'leftat' => 0, 'timecreated' => time(),
            ]);
        }
        $DB->insert_record('local_kbacompany_commmsg', (object)[
            'companyuuid' => $company, 'uuid' => $message, 'threaduuid' => $thread, 'authorid' => $user->id,
            'body' => 'Personal communication body', 'replytouuid' => '', 'taskuuid' => '',
            'status' => 'active', 'timecreated' => time(),
        ]);
        $contexts = provider::get_contexts_for_userid((int)$user->id);
        $this->assertContains(\context_system::instance()->id, array_map('intval', $contexts->get_contextids()));
        $approved = new \core_privacy\local\request\approved_contextlist(
            $user, 'local_kbacompany', [\context_system::instance()->id]);
        provider::delete_data_for_user($approved);
        $threadrow = $DB->get_record('local_kbacompany_commthread', ['uuid' => $thread], '*', MUST_EXIST);
        $messagerow = $DB->get_record('local_kbacompany_commmsg', ['uuid' => $message], '*', MUST_EXIST);
        $this->assertEquals(0, $threadrow->createdby);
        $this->assertEquals(0, $messagerow->authorid);
        $this->assertSame('', $messagerow->body);
        $this->assertSame('withdrawn', $messagerow->status);
        $this->assertTrue($DB->record_exists('local_kbacompany_commpart', [
            'companyuuid' => $company, 'threaduuid' => $thread, 'userid' => $other->id,
        ]));
        $this->assertFalse($DB->record_exists('local_kbacompany_commpart', [
            'companyuuid' => $company, 'threaduuid' => $thread, 'userid' => $user->id,
        ]));
    }

    public function test_budgeting_actor_ids_are_anonymised_without_deleting_budget_data(): void {
        global $DB;
        $this->resetAfterTest();
        $user = $this->getDataGenerator()->create_user();
        $company = '62000000-0000-4000-8000-000000000010';
        $budget = '62000000-0000-4000-8000-000000000011';
        $now = time();
        $DB->insert_record('local_kbacompany_finbudget', (object)[
            'companyuuid' => $company, 'uuid' => $budget, 'revision' => 1, 'status' => 'approved',
            'code' => 'BUD', 'name' => 'Budget', 'budgettype' => 'orgunit',
            'scenariouuid' => '62000000-0000-4000-8000-000000000012', 'scenariorevision' => 1,
            'centeruuid' => '', 'centerrevision' => 0, 'targetuuid' => '62000000-0000-4000-8000-000000000013',
            'targetrevision' => 0, 'periodstart' => '2027-01-01', 'periodend' => '2027-01-31',
            'currency' => 'RUB', 'approvalrule' => '', 'notes' => '', 'submittedby' => $user->id,
            'approvedby' => $user->id, 'decisionnote' => 'Approved', 'createdby' => $user->id,
            'modifiedby' => $user->id, 'timecreated' => $now, 'timemodified' => $now,
        ]);
        $DB->insert_record('local_kbacompany_finbudline', (object)[
            'companyuuid' => $company, 'budgetuuid' => $budget, 'budgetrevision' => 1,
            'linecode' => 'EXP', 'name' => 'Expense', 'linetype' => 'expense',
            'periodstart' => '2027-01-01', 'periodend' => '2027-01-31', 'planamount' => 100,
            'limitamount' => 100, 'ownerpositionuuid' => '', 'indicatoruuid' => '', 'sourceinfo' => 'Plan',
            'createdby' => $user->id, 'timecreated' => $now,
        ]);
        $DB->insert_record('local_kbacompany_finbudfact', (object)[
            'companyuuid' => $company, 'budgetuuid' => $budget, 'budgetrevision' => 1,
            'linecode' => 'EXP', 'asofdate' => '2027-01-31', 'actualamount' => 90,
            'sourcekind' => 'manual', 'sourceinfo' => 'Close', 'reportedby' => $user->id, 'timecreated' => $now,
        ]);
        $contexts = provider::get_contexts_for_userid((int)$user->id);
        $this->assertContains(\context_system::instance()->id, array_map('intval', $contexts->get_contextids()));
        $approved = new \core_privacy\local\request\approved_contextlist(
            $user, 'local_kbacompany', [\context_system::instance()->id]);
        provider::delete_data_for_user($approved);
        $row = $DB->get_record('local_kbacompany_finbudget', ['uuid' => $budget, 'revision' => 1], '*', MUST_EXIST);
        $this->assertEquals(0, $row->createdby);
        $this->assertEquals(0, $row->modifiedby);
        $this->assertEquals(0, $row->submittedby);
        $this->assertEquals(0, $row->approvedby);
        $this->assertTrue($DB->record_exists('local_kbacompany_finbudline', ['budgetuuid' => $budget]));
        $this->assertEquals(0, $DB->get_field('local_kbacompany_finbudfact', 'reportedby', ['budgetuuid' => $budget]));
    }

    public function test_resource_gate_actor_and_evidence_are_anonymised_without_deleting_gate(): void {
        global $DB;
        $this->resetAfterTest();
        $user = $this->getDataGenerator()->create_user();
        $company = '63000000-0000-4000-8000-000000000020';
        $gate = '63000000-0000-4000-8000-000000000021';
        $target = '63000000-0000-4000-8000-000000000022';
        $now = time();
        $DB->insert_record('local_kbacompany_resgate', (object)[
            'companyuuid' => $company, 'uuid' => $gate, 'revision' => 1, 'status' => 'ready',
            'code' => 'RG', 'name' => 'Resource Gate', 'targettype' => 'project', 'targetuuid' => $target,
            'targetrevision' => 1, 'scenariouuid' => '', 'scenariorevision' => 0, 'plannedstart' => '',
            'ownerpositionuuid' => '', 'submittedby' => $user->id, 'decidedby' => $user->id,
            'decisionnote' => 'Personal decision note', 'createdby' => $user->id, 'modifiedby' => $user->id,
            'timecreated' => $now, 'timemodified' => $now,
        ]);
        $DB->insert_record('local_kbacompany_resitem', (object)[
            'companyuuid' => $company, 'gateuuid' => $gate, 'gaterevision' => 1, 'itemcode' => 'PEOPLE',
            'resourcekind' => 'people', 'name' => 'People', 'required' => 1, 'status' => 'pass',
            'referencekind' => 'none', 'referenceuuid' => '', 'referencerevision' => 0,
            'expected' => 'Team', 'available' => 'Named person', 'gap' => 'Personal gap',
            'evidence' => 'Personal evidence', 'evidenceurl' => 'https://example.test/private',
            'ownerpositionuuid' => '', 'duedate' => '', 'createdby' => $user->id, 'timecreated' => $now,
        ]);
        $contexts = provider::get_contexts_for_userid((int)$user->id);
        $this->assertContains(\context_system::instance()->id, array_map('intval', $contexts->get_contextids()));
        $approved = new \core_privacy\local\request\approved_contextlist(
            $user, 'local_kbacompany', [\context_system::instance()->id]);
        provider::delete_data_for_user($approved);
        $gaterow = $DB->get_record('local_kbacompany_resgate', ['uuid' => $gate], '*', MUST_EXIST);
        $itemrow = $DB->get_record('local_kbacompany_resitem', ['gateuuid' => $gate], '*', MUST_EXIST);
        $this->assertEquals(0, $gaterow->createdby);
        $this->assertEquals(0, $gaterow->modifiedby);
        $this->assertEquals(0, $gaterow->submittedby);
        $this->assertEquals(0, $gaterow->decidedby);
        $this->assertSame('', $gaterow->decisionnote);
        $this->assertEquals(0, $itemrow->createdby);
        $this->assertSame('', $itemrow->available);
        $this->assertSame('', $itemrow->gap);
        $this->assertSame('', $itemrow->evidence);
        $this->assertSame('', $itemrow->evidenceurl);
        $this->assertSame('Team', $itemrow->expected);
    }

    public function test_bmc_item_and_comment_actor_data_are_anonymised(): void {
        global $DB;
        $this->resetAfterTest();
        $user = $this->getDataGenerator()->create_user();
        $company = '64000000-0000-4000-8000-000000000020';
        $canvas = '64000000-0000-4000-8000-000000000021';
        $item = '64000000-0000-4000-8000-000000000022';
        $now = time();
        $DB->insert_record('local_kbacompany_bmcitem', (object)[
            'companyuuid' => $company, 'canvasuuid' => $canvas, 'canvasrevision' => 1,
            'itemuuid' => $item, 'itemrevision' => 1, 'stablecode' => 'BMCI-0001', 'status' => 'draft',
            'blockkey' => 'segments', 'statekey' => 'asis', 'marker' => 'fact', 'intent' => '', 'horizon' => '',
            'title' => 'Top customer', 'formulation' => 'Top customer is 22 percent',
            'evidencenote' => 'Management report', 'evidencesource' => 'P&L', 'testcriteria' => '',
            'ownerpositionuuid' => '', 'sortorder' => 0, 'createdby' => $user->id, 'modifiedby' => $user->id,
            'timecreated' => $now, 'timemodified' => $now,
        ]);
        $DB->insert_record('local_kbacompany_bmccomment', (object)[
            'companyuuid' => $company, 'itemuuid' => $item, 'itemrevision' => 1,
            'authoruserid' => $user->id, 'comment' => 'Private user comment', 'timecreated' => $now,
        ]);
        $contexts = provider::get_contexts_for_userid((int)$user->id);
        $this->assertContains(\context_system::instance()->id, array_map('intval', $contexts->get_contextids()));
        $approved = new \core_privacy\local\request\approved_contextlist(
            $user, 'local_kbacompany', [\context_system::instance()->id]);
        provider::delete_data_for_user($approved);
        $itemrow = $DB->get_record('local_kbacompany_bmcitem', ['itemuuid' => $item], '*', MUST_EXIST);
        $commentrow = $DB->get_record('local_kbacompany_bmccomment', ['itemuuid' => $item], '*', MUST_EXIST);
        $this->assertEquals(0, $itemrow->createdby);
        $this->assertEquals(0, $itemrow->modifiedby);
        $this->assertEquals(0, $commentrow->authoruserid);
        $this->assertSame('', $commentrow->comment);
        $this->assertSame('Top customer is 22 percent', $itemrow->formulation);
    }

}
