<?php
namespace local_kbacorporate;

defined('MOODLE_INTERNAL') || die();

use local_kbacorporate\local\implementation_audits;
use local_kbacorporate\local\implementation_task_discussion;
use local_kbacorporate\local\point_a_template;

/** A curator's assignment is scoped by company AND baseline audit. */
final class implementation_curator_company_scope_test extends \advanced_testcase {
    public function test_curator_can_review_only_tasks_in_assigned_company_and_audit(): void {
        global $DB;
        $this->resetAfterTest();
        $this->setAdminUser();
        $actor = $this->getDataGenerator()->create_user();
        $outsider = $this->getDataGenerator()->create_user();
        $companya = $this->make_company('Curator A');
        $companyb = $this->make_company('Curator B');
        $audit = implementation_audits::create_audit((object)[
            'companyid' => $companya,
            'toolid' => point_a_template::install($companya),
            'title' => 'Curator baseline A',
            'audittype' => 'baseline',
            'auditdate' => time(),
        ], (int)get_admin()->id);
        $DB->insert_record('local_kbacorp_impl_program', (object)[
            'companyid' => $companya,
            'baselineauditid' => $audit,
            'code' => 'SCOPE-' . $audit,
            'title' => 'Curator scope program',
            'commercialstatus' => 'paid',
            'status' => 'active',
            'curatorid' => (int)$actor->id,
        ]);
        // The same baseline audit alone must NEVER grant access to another company.
        $valid = (object)['companyid' => $companya, 'auditid' => $audit, 'ownerid' => 0, 'deleted' => 0];
        $crosscompany = clone $valid;
        $crosscompany->companyid = $companyb;
        $otheraudit = clone $valid;
        $otheraudit->auditid++;
        $deleted = clone $valid;
        $deleted->deleted = 1;
        $this->assertTrue(implementation_task_discussion::is_curator($valid, (int)$actor->id));
        $this->assertTrue(implementation_task_discussion::can_access($valid, (int)$actor->id));
        $this->assertFalse(implementation_task_discussion::is_curator($crosscompany, (int)$actor->id));
        $this->assertFalse(implementation_task_discussion::can_access($crosscompany, (int)$actor->id));
        $this->assertFalse(implementation_task_discussion::is_curator($otheraudit, (int)$actor->id));
        $this->assertFalse(implementation_task_discussion::is_curator($deleted, (int)$actor->id));
        $this->assertFalse(implementation_task_discussion::is_curator($valid, (int)$outsider->id));
        $this->assertFalse(implementation_task_discussion::can_access($valid, 0));
    }

    private function make_company(string $name): int {
        global $DB;
        return (int)$DB->insert_record('local_kbacorp_company', (object)[
            'name' => $name, 'active' => 1, 'timecreated' => time(), 'timemodified' => time(),
        ]);
    }
}
