<?php
namespace local_kbacompany;

defined('MOODLE_INTERNAL') || die();

use local_kbacompany\local\communication_service;
use local_kbacompany\local\communication_write_gate;
use local_kbacompany\local\employee_service;
use local_kbacompany\local\identity_registry;
use local_kbacompany\local\membership_access_service;
use local_kbacorporate\local\repository;

/**
 * RC33 PR-1: shared communication write gate, revocation re-checks and notification eligibility.
 *
 * These are single-process regressions. Parallel processes (send racing a revoke) are exercised by the
 * separate fake-provider acceptance harness, not here.
 */
final class communication_write_gate_test extends \advanced_testcase {
    private function fixture(): array {
        $this->resetAfterTest();
        $this->setAdminUser();
        $admin = (int)get_admin()->id;
        $companyid = repository::save_company((object)['name' => 'Gate A', 'active' => 1]);
        $otherid = repository::save_company((object)['name' => 'Gate B', 'active' => 1]);
        $company = identity_registry::company_uuid($companyid);
        $other = identity_registry::company_uuid($otherid);
        $gen = $this->getDataGenerator();
        $author = $gen->create_user(['firstname' => 'Alla', 'lastname' => 'Author']);
        $peer = $gen->create_user(['firstname' => 'Petr', 'lastname' => 'Peer']);
        $third = $gen->create_user(['firstname' => 'Tanya', 'lastname' => 'Third']);
        foreach ([$author, $peer, $third] as $user) {
            repository::add_member($companyid, (int)$user->id, 'employee');
            employee_service::ensure_membership($company, (int)$user->id, [], 'bms');
            repository::add_member($otherid, (int)$user->id, 'employee');
            employee_service::ensure_membership($other, (int)$user->id, [], 'bms');
        }
        $this->setUser($author);
        $thread = communication_service::create_thread($company, (int)$author->id, [
            'threadtype' => 'group', 'title' => 'Gate group',
            'participants' => [(int)$peer->id, (int)$third->id],
        ]);
        return compact('admin', 'company', 'other', 'author', 'peer', 'third', 'thread');
    }

    private function notifications(string $messageuuid, int $userid): int {
        global $DB;
        return $DB->count_records('local_kbacompany_commnotif', ['messageuuid' => $messageuuid, 'userid' => $userid]);
    }

    public function test_gate_is_reentrant_and_released_once(): void {
        $this->resetAfterTest();
        $company = '11111111-1111-4111-8111-111111111111';
        $this->assertSame('communication:' . $company, communication_write_gate::lock_key($company));
        $this->assertFalse(communication_write_gate::is_held($company));
        $outer = communication_write_gate::acquire($company);
        $inner = communication_write_gate::acquire($company);
        $this->assertTrue(communication_write_gate::is_held($company));
        $inner->release();
        $inner->release();
        $this->assertTrue(communication_write_gate::is_held($company), 'Inner release must not free the outer hold');
        $outer->release();
        $this->assertFalse(communication_write_gate::is_held($company));
        // A fresh acquisition after full release works (the named lock was really freed).
        $again = communication_write_gate::acquire($company);
        $again->release();
        $this->assertFalse(communication_write_gate::is_held($company));
    }

    public function test_failed_send_releases_the_gate_and_writes_nothing(): void {
        global $DB;
        $f = $this->fixture();
        $before = $DB->count_records('local_kbacompany_commmsg');
        try {
            communication_service::send_message($f['company'], (int)$f['author']->id, $f['thread'], '   ');
            $this->fail('Empty message must fail');
        } catch (\moodle_exception $e) {
            $this->assertSame('communication_invalid', $e->errorcode);
        }
        $this->assertFalse(communication_write_gate::is_held($f['company']));
        $this->assertSame($before, $DB->count_records('local_kbacompany_commmsg'));
    }

    public function test_revoked_author_cannot_send_or_change_and_other_company_is_unaffected(): void {
        global $DB;
        $f = $this->fixture();
        $author = (int)$f['author']->id;
        $message = communication_service::send_message($f['company'], $author, $f['thread'], 'Before revoke');
        $body = (string)$DB->get_field('local_kbacompany_commmsg', 'body', ['uuid' => $message]);
        $this->setAdminUser();
        employee_service::close_membership($f['company'], $author, $f['admin']);
        $this->setUser($f['author']);
        $count = $DB->count_records('local_kbacompany_commmsg', ['companyuuid' => $f['company']]);
        try {
            communication_service::send_message($f['company'], $author, $f['thread'], 'After revoke');
            $this->fail('Revoked author must not publish');
        } catch (\moodle_exception $e) {
            $this->assertSame('companynotavailable', $e->errorcode);
        }
        try {
            communication_service::change_message($f['company'], $author, $f['thread'], $message, 'edit',
                'Rewritten after revoke', hash('sha256', $body . "\nactive"));
            $this->fail('Revoked author must not edit');
        } catch (\moodle_exception $e) {
            $this->assertSame('companynotavailable', $e->errorcode);
        }
        $this->assertFalse(communication_write_gate::is_held($f['company']));
        $this->assertSame($count, $DB->count_records('local_kbacompany_commmsg', ['companyuuid' => $f['company']]));
        $this->assertSame($body, (string)$DB->get_field('local_kbacompany_commmsg', 'body', ['uuid' => $message]));
        $this->assertFalse($DB->record_exists('local_kbacompany_commrev', ['messageuuid' => $message]));
        // The other company of the same user keeps working.
        $otherthread = communication_service::create_thread($f['other'], $author, [
            'threadtype' => 'group', 'title' => 'Other company', 'participants' => [(int)$f['peer']->id],
        ]);
        $this->assertNotEmpty(communication_service::send_message($f['other'], $author, $otherthread, 'Still allowed'));
    }

    public function test_revoked_suspended_and_deleted_recipients_receive_no_notification(): void {
        global $DB;
        $f = $this->fixture();
        $author = (int)$f['author']->id;
        $peer = (int)$f['peer']->id;
        $third = (int)$f['third']->id;
        $control = communication_service::send_message($f['company'], $author, $f['thread'], 'Control');
        $this->assertSame(1, $this->notifications($control, $peer));
        $this->assertSame(1, $this->notifications($control, $third));

        $this->setAdminUser();
        membership_access_service::change($f['company'], $peer, $f['admin']);
        $DB->set_field('user', 'suspended', 1, ['id' => $third]);
        $this->setUser($f['author']);
        $message = communication_service::send_message($f['company'], $author, $f['thread'], 'After changes');
        $this->assertTrue($DB->record_exists('local_kbacompany_commmsg', ['uuid' => $message, 'status' => 'active']));
        $this->assertSame(0, $this->notifications($message, $peer));
        $this->assertSame(0, $this->notifications($message, $third));

        $DB->set_field('user', 'suspended', 0, ['id' => $third]);
        $DB->set_field('user', 'deleted', 1, ['id' => $third]);
        $deleted = communication_service::send_message($f['company'], $author, $f['thread'], 'Deleted recipient');
        $this->assertSame(0, $this->notifications($deleted, $third));
    }

    public function test_suspended_author_cannot_send(): void {
        global $DB;
        $f = $this->fixture();
        $DB->set_field('user', 'suspended', 1, ['id' => (int)$f['author']->id]);
        $count = $DB->count_records('local_kbacompany_commmsg');
        try {
            communication_service::send_message($f['company'], (int)$f['author']->id, $f['thread'], 'Suspended');
            $this->fail('Suspended author must not publish');
        } catch (\moodle_exception $e) {
            $this->assertSame('communication_permission', $e->errorcode);
        }
        $this->assertSame($count, $DB->count_records('local_kbacompany_commmsg'));
    }

    public function test_revoked_user_cannot_be_added_to_a_thread(): void {
        global $DB;
        $f = $this->fixture();
        $author = (int)$f['author']->id;
        $peer = (int)$f['peer']->id;
        communication_service::remove_participant($f['company'], $author, $f['thread'], $peer);
        $this->setAdminUser();
        membership_access_service::change($f['company'], $peer, $f['admin']);
        $this->setUser($f['author']);
        $invites = $DB->count_records('local_kbacompany_commnotif', ['userid' => $peer, 'kind' => 'thread_invite']);
        try {
            communication_service::add_participant($f['company'], $author, $f['thread'], $peer);
            $this->fail('A revoked user must not regain thread access');
        } catch (\moodle_exception $e) {
            $this->assertContains($e->errorcode, ['communication_employee', 'companynotavailable']);
        }
        $this->assertFalse(communication_write_gate::is_held($f['company']));
        $this->assertFalse($DB->record_exists('local_kbacompany_commpart',
            ['threaduuid' => $f['thread'], 'userid' => $peer, 'active' => 1]));
        $this->assertSame($invites, $DB->count_records('local_kbacompany_commnotif',
            ['userid' => $peer, 'kind' => 'thread_invite']));
    }

    public function test_removed_participant_is_not_notified_of_later_messages(): void {
        $f = $this->fixture();
        $author = (int)$f['author']->id;
        $peer = (int)$f['peer']->id;
        communication_service::remove_participant($f['company'], $author, $f['thread'], $peer);
        $this->assertFalse(communication_write_gate::is_held($f['company']));
        $message = communication_service::send_message($f['company'], $author, $f['thread'], 'After removal');
        $this->assertSame(0, $this->notifications($message, $peer));
        $this->assertSame(1, $this->notifications($message, (int)$f['third']->id));
    }

    public function test_inline_mentions_can_be_kept_literal_without_changing_the_default(): void {
        global $DB;
        $f = $this->fixture();
        $author = (int)$f['author']->id;
        $peer = (int)$f['peer']->id;
        $text = 'Please check @' . fullname($f['peer']);
        $default = communication_service::send_message($f['company'], $author, $f['thread'], $text);
        $this->assertTrue($DB->record_exists('local_kbacompany_commmention', ['messageuuid' => $default, 'userid' => $peer]));
        $this->assertTrue($DB->record_exists('local_kbacompany_commnotif',
            ['messageuuid' => $default, 'userid' => $peer, 'kind' => 'mention']));

        $literal = communication_service::send_message($f['company'], $author, $f['thread'], $text, [], '', false);
        $this->assertSame($text, (string)$DB->get_field('local_kbacompany_commmsg', 'body', ['uuid' => $literal]));
        $this->assertFalse($DB->record_exists('local_kbacompany_commmention', ['messageuuid' => $literal]));
        $this->assertTrue($DB->record_exists('local_kbacompany_commnotif',
            ['messageuuid' => $literal, 'userid' => $peer, 'kind' => 'message']));
        $this->assertFalse($DB->record_exists('local_kbacompany_commnotif',
            ['messageuuid' => $literal, 'kind' => 'mention']));
    }
}
