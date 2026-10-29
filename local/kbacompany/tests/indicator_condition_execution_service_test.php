<?php
namespace local_kbacompany;

defined('MOODLE_INTERNAL') || die();

use local_kbacompany\local\identity_registry;
use local_kbacompany\local\indicator_condition_execution_service;
use local_kbacorporate\local\repository;

/** Governance bridge regression tests for v0.5.4. */
final class indicator_condition_execution_service_test extends \advanced_testcase {
    private function fixture(): array {
        global $DB, $USER;
        $this->resetAfterTest();
        $this->setAdminUser();
        $admin = (int)$USER->id;
        $companyid = repository::save_company((object)['name' => 'Condition execution', 'active' => 1]);
        $company = identity_registry::company_uuid($companyid);
        $employee = $this->getDataGenerator()->create_user();
        $now = time();
        $DB->insert_record('local_kbacorp_member', (object)[
            'companyid' => $companyid, 'userid' => $employee->id, 'membertype' => 'employee',
            'isemployee' => 1, 'ismanager' => 0, 'isdepartmentmanager' => 0, 'ishr' => 0,
            'timecreated' => $now, 'timemodified' => $now,
        ]);
        $statuuid = '11111111-1111-4111-8111-111111111111';
        $binduuid = '22222222-2222-4222-8222-222222222222';
        $DB->insert_record('local_kbacompany_stat', (object)[
            'companyuuid' => $company, 'uuid' => $statuuid, 'productuuid' => '', 'code' => 'REV',
            'name' => 'Revenue', 'unit' => 'RUB', 'periodtype' => 'weekly', 'direction' => 'up', 'timecreated' => $now,
        ]);
        $DB->insert_record('local_kbacompany_statbind', (object)[
            'companyuuid' => $company, 'uuid' => $binduuid, 'revision' => 1, 'status' => 'active',
            'statuuid' => $statuuid, 'contextkind' => 'company', 'contextuuid' => $company,
            'contextrevision' => 0, 'isprimary' => 1, 'critical' => 1, 'weight' => 1,
            'sortorder' => 0, 'createdby' => $admin, 'timecreated' => $now,
        ]);
        $DB->insert_record('local_kbacompany_statpolicy', (object)[
            'companyuuid' => $company, 'uuid' => '33333333-3333-4333-8333-333333333333',
            'revision' => 1, 'status' => 'active', 'binduuid' => $binduuid, 'bindrevision' => 1,
            'dangerdecline' => 20, 'dangerperiods' => 3, 'affluencegrowth' => 15,
            'powerlevel' => null, 'powerperiods' => 0, 'effectivefrom' => '2026-01-01', 'effectiveto' => '',
            'createdby' => $admin, 'timecreated' => $now,
        ]);
        foreach ([['2026-09-01', 100], ['2026-09-08', 105], ['2026-09-15', 110]] as $item) {
            $DB->insert_record('local_kbacompany_statval', (object)[
                'companyuuid' => $company, 'statuuid' => $statuuid, 'periodstart' => $item[0], 'kind' => 'fact',
                'amount' => $item[1], 'revision' => 1, 'reason' => '', 'writerid' => $admin, 'timecreated' => $now,
            ]);
        }
        return compact('admin', 'company', 'employee', 'statuuid', 'binduuid');
    }

    public function test_confirmation_creates_event_and_canonical_task_without_copying_stat_history(): void {
        global $DB;
        $f = $this->fixture();
        $beforefacts = $DB->count_records('local_kbacompany_statval', ['companyuuid' => $f['company'], 'statuuid' => $f['statuuid']]);
        $result = indicator_condition_execution_service::confirm_to_execution($f['company'], $f['admin'], $f['binduuid'],
            'normal', '', [
                'title' => 'Preserve working sales actions', 'description' => '',
                'product' => 'Working actions documented and continued', 'stream' => 'change', 'priority' => 'normal',
                'assigneeid' => (int)$f['employee']->id, 'reviewerid' => 0, 'observerid' => 0,
                'startdate' => '', 'duedate' => '', 'planminutes' => 0, 'parentuuid' => '',
                'contextkind' => '', 'contextuuid' => '', 'contextrevision' => 0,
            ]);
        $event = $DB->get_record('local_kbacompany_stateevt', ['uuid' => $result['event_uuid']], '*', MUST_EXIST);
        $task = $DB->get_record('local_kbacompany_task', ['uuid' => $result['task_uuid']], '*', MUST_EXIST);
        $this->assertSame('confirm', $event->eventkind);
        $this->assertSame('normal', $event->conditioncode);
        $this->assertSame('2026-09-15', $event->periodkey);
        $this->assertSame('condition', $task->sourcekind);
        $this->assertSame((string)$event->uuid, (string)$task->sourceuuid);
        $this->assertSame($beforefacts, $DB->count_records('local_kbacompany_statval', [
            'companyuuid' => $f['company'], 'statuuid' => $f['statuuid'],
        ]));
    }

    public function test_context_override_requires_comment(): void {
        $f = $this->fixture();
        $this->expectException(\moodle_exception::class);
        indicator_condition_execution_service::confirm($f['company'], $f['admin'], $f['binduuid'], 'danger', '');
    }
}
