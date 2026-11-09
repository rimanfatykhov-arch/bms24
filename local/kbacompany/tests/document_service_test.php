<?php
namespace local_kbacompany;

defined('MOODLE_INTERNAL') || die();

use local_kbacompany\local\document_service;
use local_kbacompany\local\identity_registry;
use local_kbacompany\local\process_model_service;
use local_kbacompany\local\process_service;
use local_kbacompany\local\product_service;
use local_kbacompany\local\organizing_board_service;
use local_kbacompany\local\position_service;
use local_kbacompany\local\semantic_service;
use local_kbacorporate\local\repository;

/** ISO-style controlled documented-information regression tests. */
final class document_service_test extends \advanced_testcase {
    private function fixture(string $name = 'Document control company'): array {
        global $DB, $USER;
        $this->resetAfterTest();
        $this->setAdminUser();
        $actor = (int)$USER->id;
        $companyid = repository::save_company((object)['name' => $name, 'active' => 1]);
        $company = identity_registry::company_uuid($companyid);
        $reviewer = $this->getDataGenerator()->create_user();
        $DB->insert_record('local_kbacorp_member', (object)[
            'companyid' => $companyid, 'userid' => $reviewer->id, 'membertype' => 'employee',
            'isemployee' => 1, 'ismanager' => 0, 'isdepartmentmanager' => 0, 'ishr' => 0,
        ]);
        // Corporate learning membership alone is not canonical BMS membership.
        $DB->insert_record('local_kbacompany_member', (object)[
            'uuid' => '33333333-3333-4333-8333-333333333333',
            'companyuuid' => $company, 'userid' => $reviewer->id,
            'membertype' => 'employee', 'isemployee' => 1, 'active' => 1,
        ]);
        return compact('actor', 'companyid', 'company', 'reviewer');
    }

    private function release(string $company, int $actor, int $reviewerid, string $uuid): array {
        $ctx = document_service::context($company, $actor, $uuid);
        $rev = $ctx['selected']['revision'];
        document_service::add_reviewer($company, $actor, $uuid, $rev, $reviewerid);
        document_service::submit_review($company, $actor, $uuid, $rev);
        document_service::review_decision($company, $reviewerid, $uuid, $rev, 'approved', 'Requirements checked');
        document_service::approve($company, $actor, $uuid, $rev, 'Approved for issue');
        document_service::publish($company, $actor, $uuid, $rev, 0);
        document_service::activate($company, $actor, $uuid, $rev);
        return document_service::context($company, $actor, $uuid)['selected'];
    }

    public function test_lifecycle_master_list_acknowledgement_and_semantic_document(): void {
        global $DB;
        $f = $this->fixture();
        $uuid = document_service::create_manual($f['company'], $f['actor'], [
            'code' => 'DP-01.1', 'title' => 'Document control procedure', 'doctype' => 'procedure', 'doclevel' => 2,
            'content' => 'Controlled requirements', 'changereason' => 'Initial issue',
        ]);
        $effective = $this->release($f['company'], $f['actor'], (int)$f['reviewer']->id, $uuid);
        $this->assertSame('effective', $effective['status']);
        $this->assertSame(1, $effective['revision']);
        $this->assertSame('0.3.4', document_service::contract()['version']);
        $this->assertContains('released_file_bytes_are_immutable', document_service::contract()['rules']);

        $master = document_service::master_list($f['company'], $f['actor']);
        $this->assertCount(1, $master);
        $this->assertSame(1, $master[0]['effective']['revision']);
        $this->assertSame('effective', $master[0]['effective']['status']);

        document_service::require_acknowledgement($f['company'], $f['actor'], $uuid, 1, (int)$f['reviewer']->id);
        $this->setUser($f['reviewer']);
        $reader = document_service::context($f['company'], (int)$f['reviewer']->id, $uuid);
        $this->assertTrue($reader['can_acknowledge']);
        $this->assertSame('effective', $reader['selected']['status']);
        document_service::acknowledge($f['company'], (int)$f['reviewer']->id, $uuid, 1);
        $reader = document_service::context($f['company'], (int)$f['reviewer']->id, $uuid);
        $this->assertFalse($reader['can_acknowledge']);
        $this->assertSame('acknowledged', $reader['acknowledgements'][0]['status']);

        $this->setAdminUser();
        $semantic = semantic_service::context($f['company'], $f['actor']);
        $docs = array_values(array_filter($semantic['objects'], static function(array $object) use ($uuid): bool {
            return $object['type'] === 'document' && $object['uuid'] === $uuid;
        }));
        $this->assertCount(1, $docs);
        $this->assertSame('0.3.3', semantic_service::contract()['version']);
        $this->assertSame(1, $DB->count_records('local_kbacompany_docack', ['documentuuid' => $uuid]));
    }

    public function test_new_effective_revision_supersedes_old_without_rewriting_history(): void {
        global $DB;
        $f = $this->fixture('Revision company');
        $uuid = document_service::create_manual($f['company'], $f['actor'], [
            'code' => 'STP-01', 'title' => 'Sales process standard', 'doctype' => 'stp', 'doclevel' => 2,
            'content' => 'Revision one', 'changereason' => 'Initial issue',
        ]);
        $this->release($f['company'], $f['actor'], (int)$f['reviewer']->id, $uuid);
        $newrev = document_service::new_revision($f['company'], $f['actor'], $uuid, 1, 'Process changed');
        $this->assertSame(2, $newrev);
        document_service::save($f['company'], $f['actor'], $uuid, 2, [
            'code' => 'STP-01', 'title' => 'Sales process standard', 'doctype' => 'stp', 'doclevel' => 2,
            'content' => 'Revision two', 'changereason' => 'Process changed', 'ownerpositionuuid' => '',
        ]);
        $this->release($f['company'], $f['actor'], (int)$f['reviewer']->id, $uuid);
        $old = $DB->get_record('local_kbacompany_doc', ['companyuuid' => $f['company'], 'uuid' => $uuid, 'revision' => 1]);
        $current = $DB->get_record('local_kbacompany_doc', ['companyuuid' => $f['company'], 'uuid' => $uuid, 'revision' => 2]);
        $this->assertSame('superseded', $old->status);
        $this->assertSame('Revision one', $old->content);
        $this->assertGreaterThan(0, (int)$old->effectiveto);
        $this->assertSame('effective', $current->status);
        $this->assertSame('Revision two', $current->content);
        $master = document_service::master_list($f['company'], $f['actor']);
        $this->assertSame(2, $master[0]['effective']['revision']);
    }

    public function test_form_record_is_bound_to_exact_effective_form_revision(): void {
        global $DB;
        $f = $this->fixture('Record company');
        $formuuid = document_service::create_manual($f['company'], $f['actor'], [
            'code' => 'F-1.2', 'title' => 'Inspection form', 'doctype' => 'form', 'doclevel' => 5,
            'content' => 'Field A; Field B', 'changereason' => 'Initial form',
        ]);
        $this->release($f['company'], $f['actor'], (int)$f['reviewer']->id, $formuuid);
        $recorduuid = document_service::create_record($f['company'], $f['actor'], $formuuid, 1,
            'Inspection 001', 'A=OK; B=OK');
        $record = $DB->get_record('local_kbacompany_record', ['companyuuid' => $f['company'], 'uuid' => $recorduuid]);
        $this->assertSame(1, (int)$record->sourceformrevision);
        $this->assertSame(hash('sha256', 'A=OK; B=OK'), $record->payloadhash);

        document_service::new_revision($f['company'], $f['actor'], $formuuid, 1, 'Add Field C');
        document_service::save($f['company'], $f['actor'], $formuuid, 2, [
            'code' => 'F-1.2', 'title' => 'Inspection form', 'doctype' => 'form', 'doclevel' => 5,
            'content' => 'Field A; Field B; Field C', 'changereason' => 'Add Field C', 'ownerpositionuuid' => '',
        ]);
        $unchanged = $DB->get_record('local_kbacompany_record', ['id' => $record->id]);
        $this->assertSame(1, (int)$unchanged->sourceformrevision);
        $this->assertSame('A=OK; B=OK', $unchanged->payload);
    }

    public function test_stp_controlled_document_snapshots_published_process_model(): void {
        $f = $this->fixture('Controlled STP company');
        $board = organizing_board_service::install_seven_division_template($f['company'], $f['actor']);
        $product = product_service::create_product($f['company'], $f['actor'], [
            'code' => 'CKP-DOC', 'name' => 'Accepted result', 'acceptance' => 'Accepted',
            'targettype' => 'node', 'targetuuid' => $board['nodes'][0]['uuid'],
        ]);
        $position = position_service::create_position($f['company'], $f['actor'], [
            'orgnodeuuid' => $board['nodes'][0]['uuid'], 'code' => 'DOC-EXEC', 'name' => 'Executor',
            'purpose' => 'Produce result', 'ismanager' => 0, 'plannedcount' => 1,
        ]);
        $process = process_service::save($f['company'], $f['actor'], [
            'code' => 'P-DOC', 'name' => 'Controlled process', 'purpose' => 'Produce result',
            'processtype' => 'core', 'processlevel' => 'L0', 'parentuuid' => '',
            'ownerorgunituuid' => $board['nodes'][0]['uuid'], 'productuuid' => $product,
        ]);
        process_service::publish($f['company'], $f['actor'], $process, 1);
        process_service::link($f['company'], $f['actor'], $process, 'supplier', $board['nodes'][0]['uuid']);
        process_service::link($f['company'], $f['actor'], $process, 'customer', $board['nodes'][1]['uuid']);
        $modelid = process_model_service::ensure_draft($f['company'], $f['actor'], $process);
        $ctx = process_model_service::context($f['company'], $f['actor'], $process);
        process_model_service::save_element($f['company'], $f['actor'], $process, $modelid,
            $ctx['model']['change_version'], ['elementtype' => 'input', 'name' => 'Input']);
        $ctx = process_model_service::context($f['company'], $f['actor'], $process);
        $start = process_model_service::save_element($f['company'], $f['actor'], $process, $modelid,
            $ctx['model']['change_version'], ['elementtype' => 'start', 'name' => 'Start']);
        $ctx = process_model_service::context($f['company'], $f['actor'], $process);
        $task = process_model_service::save_element($f['company'], $f['actor'], $process, $modelid,
            $ctx['model']['change_version'], ['elementtype' => 'task', 'name' => 'Do work', 'description' => 'Do it',
                'executortype' => 'position', 'executoruuid' => $position, 'outputtext' => 'Result']);
        $ctx = process_model_service::context($f['company'], $f['actor'], $process);
        $end = process_model_service::save_element($f['company'], $f['actor'], $process, $modelid,
            $ctx['model']['change_version'], ['elementtype' => 'end', 'name' => 'End']);
        $ctx = process_model_service::context($f['company'], $f['actor'], $process);
        process_model_service::save_flow($f['company'], $f['actor'], $process, $modelid,
            $ctx['model']['change_version'], ['fromuuid' => $start, 'touuid' => $task]);
        $ctx = process_model_service::context($f['company'], $f['actor'], $process);
        process_model_service::save_flow($f['company'], $f['actor'], $process, $modelid,
            $ctx['model']['change_version'], ['fromuuid' => $task, 'touuid' => $end]);
        $ctx = process_model_service::context($f['company'], $f['actor'], $process);
        $this->assertSame([], $ctx['publication_issues']);
        process_model_service::publish($f['company'], $f['actor'], $process, $modelid, $ctx['model']['change_version']);

        $docuuid = document_service::create_from_stp($f['company'], $f['actor'], $process, 'Control published STP');
        $doc = document_service::context($f['company'], $f['actor'], $docuuid)['selected'];
        $this->assertSame('stp', $doc['source_kind']);
        $this->assertSame(1, $doc['source_revision']);
        $this->assertSame(64, strlen($doc['source_hash']));
        $snapshot = json_decode($doc['content'], true);
        $this->assertSame('published', $snapshot['model']['status']);
        $this->assertSame(1, $snapshot['model']['revision']);
        $this->assertSame($process, $snapshot['process']['uuid']);
    }

    public function test_cross_company_document_is_not_resolved(): void {
        $f = $this->fixture('Company A');
        $uuid = document_service::create_manual($f['company'], $f['actor'], [
            'code' => 'DP-A', 'title' => 'A only', 'doctype' => 'procedure', 'doclevel' => 2, 'content' => 'A',
        ]);
        $company2 = identity_registry::company_uuid(repository::save_company((object)['name' => 'Company B', 'active' => 1]));
        try {
            document_service::context($company2, $f['actor'], $uuid);
            $this->fail('A document from another company must not resolve.');
        } catch (\moodle_exception $e) {
            $this->assertSame('document_notavailable', $e->errorcode);
        }
    }

    public static function invalid_reviewer_cases(): array {
        return ['corporate only' => ['missing'], 'inactive BMS membership' => ['inactive'],
            'foreign BMS membership' => ['foreign'], 'suspended user' => ['suspended'],
            'deleted user' => ['deleted']];
    }

    /** @dataProvider invalid_reviewer_cases */
    public function test_invalid_reviewer_is_rejected_without_document_mutation(string $case): void {
        global $DB;
        $f = $this->fixture();
        $scope = ['companyuuid' => $f['company'], 'userid' => $f['reviewer']->id];
        if ($case === 'missing') { $DB->delete_records('local_kbacompany_member', $scope); }
        if ($case === 'inactive') { $DB->set_field('local_kbacompany_member', 'active', 0, $scope); }
        if ($case === 'foreign') {
            $other = identity_registry::company_uuid(repository::save_company((object)['name' => 'Foreign', 'active' => 1]));
            $DB->set_field('local_kbacompany_member', 'companyuuid', $other, $scope);
        }
        if ($case === 'suspended' || $case === 'deleted') {
            $DB->set_field('user', $case, 1, ['id' => $f['reviewer']->id]);
        }
        $uuid = document_service::create_manual($f['company'], $f['actor'], [
            'code' => 'NEGATIVE', 'title' => 'Protected draft', 'doctype' => 'procedure',
            'doclevel' => 2, 'content' => 'Unchanged',
        ]);
        $before = $DB->get_records('local_kbacompany_doc', ['companyuuid' => $f['company']], 'id');
        try {
            document_service::add_reviewer($f['company'], $f['actor'], $uuid, 1, $f['reviewer']->id);
            $this->fail('Reviewer must be an active canonical member and active Moodle user.');
        } catch (\moodle_exception $error) {
            $this->assertSame('document_invalidreviewer', $error->errorcode);
        }
        $this->assertEquals($before, $DB->get_records('local_kbacompany_doc', ['companyuuid' => $f['company']], 'id'));
        $this->assertFalse($DB->record_exists('local_kbacompany_docreview', ['companyuuid' => $f['company']]));
        $this->assertDebuggingNotCalled();
    }
}
