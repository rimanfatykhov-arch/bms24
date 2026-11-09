<?php
namespace local_kbacorporate;

defined('MOODLE_INTERNAL') || die();
global $CFG;
require_once($CFG->libdir . '/filelib.php');

use local_kbacorporate\local\implementation_actions;
use local_kbacorporate\local\implementation_audits;
use local_kbacorporate\local\implementation_evidence;
use local_kbacorporate\local\point_a_template;
use local_kbacorporate\local\repository;

/** Regression tests for immutable implementation evidence versions and review decisions. */
final class implementation_evidence_test extends \advanced_testcase {
    public function test_rejected_evidence_is_resubmitted_as_new_version_and_accepted(): void {
        global $DB;
        $this->resetAfterTest();
        $this->setAdminUser();

        [$taskid, $ownerid] = $this->create_inprogress_task();
        $this->setUser(\core_user::get_user($ownerid));
        $draftid = file_get_unused_draft_itemid();
        get_file_storage()->create_file_from_string((object)[
            'contextid' => \context_user::instance($ownerid)->id,
            'component' => 'user',
            'filearea' => 'draft',
            'itemid' => $draftid,
            'filepath' => '/',
            'filename' => 'evidence-v1.txt',
        ], 'Evidence version one');

        $first = implementation_evidence::submit(
            $taskid,
            $ownerid,
            'First implementation result',
            'https://example.test/evidence-v1',
            $draftid
        );
        $this->assertSame(1, (int)$first->versionno);
        $this->assertSame(implementation_evidence::STATUS_SUBMITTED, $first->status);
        $this->assertCount(1, implementation_evidence::get_files((int)$first->id));
        $this->assertSame('review', $DB->get_field('local_kbacorp_impl_task', 'status', ['id' => $taskid]));

        $this->setAdminUser();
        implementation_evidence::review((int)$first->id, (int)get_admin()->id, false, 'Add the approved procedure.');
        $first = implementation_evidence::get_record((int)$first->id);
        $this->assertSame(implementation_evidence::STATUS_REJECTED, $first->status);
        $this->assertSame('inprogress', $DB->get_field('local_kbacorp_impl_task', 'status', ['id' => $taskid]));

        $this->setUser(\core_user::get_user($ownerid));
        $second = implementation_evidence::submit(
            $taskid,
            $ownerid,
            'Revised implementation result',
            'https://example.test/evidence-v2',
            file_get_unused_draft_itemid()
        );
        $this->assertSame(2, (int)$second->versionno);
        $this->assertSame((int)$first->id, (int)$second->supersedesid);
        $this->assertSame('review', $DB->get_field('local_kbacorp_impl_task', 'status', ['id' => $taskid]));

        $this->setAdminUser();
        implementation_evidence::review((int)$second->id, (int)get_admin()->id, true, 'Accepted.');
        $second = implementation_evidence::get_record((int)$second->id);
        $this->assertSame(implementation_evidence::STATUS_ACCEPTED, $second->status);
        $this->assertSame('completed', $DB->get_field('local_kbacorp_impl_task', 'status', ['id' => $taskid]));
        $this->assertCount(2, implementation_evidence::get_versions($taskid));
        $this->assertCount(4, implementation_evidence::get_history($taskid));
    }

    public function test_pending_version_blocks_duplicate_submission_and_direct_review(): void {
        [$taskid, $ownerid] = $this->create_inprogress_task();
        $this->setUser(\core_user::get_user($ownerid));
        $evidence = implementation_evidence::submit(
            $taskid,
            $ownerid,
            'Pending result',
            '',
            file_get_unused_draft_itemid()
        );

        $task = implementation_evidence::get_task($taskid);
        $this->assertFalse(implementation_evidence::can_submit($task, $ownerid));

        try {
            implementation_evidence::review((int)$evidence->id, $ownerid, true, '');
            $this->fail('Self-review must be rejected.');
        } catch (\moodle_exception $exception) {
            $this->assertSame('implevidenceselfreview', $exception->errorcode);
        }
    }

    /** @return array{0:int,1:int} task id and owner id */
    private function create_inprogress_task(): array {
        global $DB;

        $owner = $this->getDataGenerator()->create_user();
        $adminid = (int)get_admin()->id;
        $companyid = (int)$DB->insert_record('local_kbacorp_company', (object)[
            'name' => 'Evidence company',
            'active' => 1,
            'timecreated' => time(),
            'timemodified' => time(),
        ]);
        repository::add_member($companyid, (int)$owner->id, 'employee');
        $toolid = point_a_template::install($companyid);
        $auditid = implementation_audits::create_audit((object)[
            'companyid' => $companyid,
            'toolid' => $toolid,
            'title' => 'Evidence baseline',
            'audittype' => 'baseline',
            'auditdate' => time(),
        ], $adminid);
        $audititems = implementation_audits::get_items($auditid);
        $item = reset($audititems);
        $DB->update_record('local_kbacorp_impl_item', (object)[
            'id' => (int)$item->id,
            'score' => 2,
            'evidencestatus' => implementation_audits::EVIDENCE_SUBMITTED,
        ]);
        $DB->set_field('local_kbacorp_impl_audit', 'status',
            implementation_audits::STATUS_CONFIRMED, ['id' => $auditid]);
        $DB->insert_record('local_kbacorp_impl_program', (object)[
            'companyid' => $companyid,
            'baselineauditid' => $auditid,
            'code' => 'BMS-PROG-' . $companyid . '-' . $auditid,
            'title' => 'Evidence programme',
            'package' => 'start',
            'commercialstatus' => 'paid',
            'status' => 'active',
            'startdate' => time(),
            'requestedby' => $adminid,
            'activatedby' => $adminid,
            'activatedat' => time(),
            'timecreated' => time(),
            'timemodified' => time(),
        ]);
        $created = implementation_actions::create_selected(
            $companyid,
            $auditid,
            [(int)$item->id],
            [],
            (int)$owner->id,
            $adminid
        );
        $taskid = (int)reset($created->taskids);
        $DB->set_field('local_kbacorp_impl_task', 'status', 'inprogress', ['id' => $taskid]);
        return [$taskid, (int)$owner->id];
    }
}
