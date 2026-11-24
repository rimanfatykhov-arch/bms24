<?php
namespace local_kbacompany;

defined('MOODLE_INTERNAL') || die();

use local_kbacompany\external\inbound_api;
use local_kbacompany\local\communication_service;
use local_kbacompany\local\communication_write_gate;
use local_kbacompany\local\employee_service;
use local_kbacompany\local\identity_registry;
use local_kbacompany\local\inbound_exception;
use local_kbacompany\local\inbound_json;
use local_kbacompany\local\max_inbound_registry_service as registry;
use local_kbacompany\local\max_inbound_service as inbound;
use local_kbacorporate\local\repository;

/**
 * RC33 PR-2: server side of the fake-provider MAX → corporate feed (owner D1–D4 24.11.2026, ADR-0012).
 *
 * Single-process negative matrix of docs/architecture/RC33_MAX_FEED_INBOUND_CONTRACT.md §9 for N02–N16 and
 * N19–N32 at the BMS boundary. N01 (webhook secret), N09/N21/N27/N28 parallel races, N20 HTTP envelopes and the
 * localhost fake adapter belong to PR-3; N17/N18 (files) are out of the text-only slice.
 * All identifiers below are synthetic.
 */
final class max_inbound_service_test extends \advanced_testcase {
    private const CHAT = '-7000000000001';
    private const CHAT2 = '-7000000000002';
    private const SENDER = '5000000000001';
    private const SENDER2 = '5000000000002';

    protected function tearDown(): void {
        inbound::set_test_fault(null);
        parent::tearDown();
    }

    private function grant(int $userid): void {
        $sys = \context_system::instance();
        $roleid = create_role('Inbound import ' . $userid, 'inboundimport' . $userid, 'RC33 synthetic test role');
        assign_capability('local/kbacompany:importinbound', CAP_ALLOW, $roleid, $sys->id, true);
        role_assign($roleid, $userid, $sys->id);
        accesslib_clear_all_caches_for_unit_testing();
    }

    private function member(int $companyid, string $company, int $userid): void {
        repository::add_member($companyid, $userid, 'employee');
        employee_service::ensure_membership($company, $userid, [], 'bms');
    }

    private function fixture(): array {
        global $DB;
        $this->resetAfterTest();
        $this->setAdminUser();
        set_config(inbound::CONFIG, 1, 'local_kbacompany');
        $admin = (int)get_admin()->id;
        $companyid = repository::save_company((object)['name' => 'Inbound A', 'active' => 1]);
        $otherid = repository::save_company((object)['name' => 'Inbound B', 'active' => 1]);
        $company = identity_registry::company_uuid($companyid);
        $other = identity_registry::company_uuid($otherid);
        $gen = $this->getDataGenerator();
        $author = $gen->create_user(['firstname' => 'Alla', 'lastname' => 'Author']);
        $peer = $gen->create_user(['firstname' => 'Petr', 'lastname' => 'Peer']);
        $outsider = $gen->create_user(['firstname' => 'Olga', 'lastname' => 'Outsider']);
        $principal = $gen->create_user(['firstname' => 'Integration', 'lastname' => 'Account A']);
        $principalb = $gen->create_user(['firstname' => 'Integration', 'lastname' => 'Account B']);
        foreach ([$admin, (int)$author->id, (int)$peer->id, (int)$outsider->id] as $userid) {
            $this->member($companyid, $company, $userid);
            $this->member($otherid, $other, $userid);
        }
        $this->grant((int)$principal->id);
        $this->grant((int)$principalb->id);
        $thread = communication_service::create_thread($company, $admin, [
            'threadtype' => 'group', 'title' => 'Imported group', 'participants' => [(int)$author->id, (int)$peer->id],
        ]);
        $connection = registry::create_connection($company, $admin, 'synthetic-bot-a', (int)$principal->id);
        registry::set_connection_status($company, $admin, $connection, 'enabled');
        $binding = registry::bind_group($company, $admin, $connection, self::CHAT, $thread, 'p1', time() - 3600);
        $sender = registry::bind_sender($company, $admin, $connection, self::SENDER, (int)$author->id, $binding, 'p1',
            'synthetic:consent-0001', time() - 3600);
        // Company B has its own connection, binding and thread.
        $threadb = communication_service::create_thread($other, $admin, [
            'threadtype' => 'group', 'title' => 'Other group', 'participants' => [(int)$author->id],
        ]);
        $connectionb = registry::create_connection($other, $admin, 'synthetic-bot-b', (int)$principalb->id);
        registry::set_connection_status($other, $admin, $connectionb, 'enabled');
        $bindingb = registry::bind_group($other, $admin, $connectionb, self::CHAT, $threadb, 'p1', time() - 3600);
        $this->assertFalse(communication_write_gate::is_held($company));
        $this->assertSame(0, $DB->count_records('local_kbacompany_inbreceipt'));
        return compact('admin', 'company', 'other', 'author', 'peer', 'outsider', 'principal', 'principalb', 'thread',
            'connection', 'binding', 'sender', 'threadb', 'connectionb', 'bindingb');
    }

    private function dto(array $f, array $over = []): array {
        return array_merge([
            'schema_version' => inbound::SCHEMA, 'connection_uuid' => $f['connection'], 'binding_uuid' => $f['binding'],
            'binding_revision' => 1, 'company_uuid' => $f['company'], 'thread_uuid' => $f['thread'],
            'event_type' => 'message_created', 'chat_type' => 'chat', 'sender_is_bot' => false, 'link_type' => 'none',
            'external_chat_id' => self::CHAT, 'external_sender_id' => self::SENDER,
            'external_message_id' => 'mid.synthetic.0001', 'source_timestamp_ms' => time() * 1000,
            'text' => 'Synthetic imported text', 'attachments' => [], 'reply_to_external_message_id' => null,
        ], $over);
    }

    private function json(array $value): string {
        return json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    private function send(array $f, array $over = [], ?int $principal = null): array {
        return inbound::receive_message($principal ?? (int)$f['principal']->id, $this->json($this->dto($f, $over)));
    }

    private function control(array $f, string $type, array $over = []): array {
        $dto = array_merge([
            'schema_version' => inbound::SCHEMA, 'connection_uuid' => $f['connection'], 'binding_uuid' => $f['binding'],
            'binding_revision' => 1, 'external_chat_id' => self::CHAT, 'external_message_id' => 'mid.synthetic.0001',
            'event_type' => $type, 'source_timestamp_ms' => time() * 1000,
        ], $over);
        return inbound::receive_control((int)$f['principal']->id, $this->json($dto));
    }

    private function refused(string $category, callable $call): void {
        try {
            $call();
            $this->fail('Expected inbound refusal ' . $category);
        } catch (inbound_exception $e) {
            $this->assertSame($category, $e->category);
            $this->assertSame('inbound_' . $category, $e->errorcode);
        }
    }

    private function counts(): array {
        global $DB;
        return [
            'messages' => $DB->count_records('local_kbacompany_commmsg'),
            'receipts' => $DB->count_records('local_kbacompany_inbreceipt'),
            'outbox' => $DB->count_records('local_kbacompany_eventout', ['eventtype' => inbound::EVENT]),
            'notifications' => $DB->count_records('local_kbacompany_commnotif'),
        ];
    }

    private function review(array $f, string $reason, array $over = []): array {
        global $DB;
        $before = $DB->count_records('local_kbacompany_commmsg');
        $result = $this->send($f, $over);
        $this->assertSame('review_required', $result['outcome']);
        $this->assertSame($reason, $result['reason']);
        $this->assertArrayNotHasKey('message_uuid', $result);
        $this->assertSame($before, $DB->count_records('local_kbacompany_commmsg'), 'review never publishes');
        $receipt = $DB->get_record('local_kbacompany_inbreceipt', ['uuid' => $result['receipt_uuid']], '*', MUST_EXIST);
        $this->assertSame('', (string)$receipt->messageuuid);
        return $result;
    }

    public function test_capability_and_service_are_closed_by_default(): void {
        global $DB;
        $this->resetAfterTest();
        $this->assertFalse((bool)get_config('local_kbacompany', inbound::CONFIG));
        $this->assertSame(0, $DB->count_records('role_capabilities',
            ['capability' => 'local/kbacompany:importinbound', 'permission' => CAP_ALLOW]));
        $service = $DB->get_record('external_services', ['shortname' => 'local_kbacompany_public_inbound_v1'],
            '*', MUST_EXIST);
        $this->assertSame(0, (int)$service->enabled);
        $this->assertSame(1, (int)$service->restrictedusers);
        $functions = $DB->get_fieldset_select('external_services_functions', 'functionname', 'externalserviceid = ?',
            [$service->id]);
        sort($functions);
        $this->assertSame(['local_kbacompany_public_inbound_control', 'local_kbacompany_public_inbound_message'],
            $functions);
        $public = $DB->get_record('external_services', ['shortname' => 'local_kbacompany_public_v1']);
        if ($public) {
            $this->assertFalse($DB->record_exists('external_services_functions', ['externalserviceid' => $public->id,
                'functionname' => 'local_kbacompany_public_inbound_message']));
        }
    }

    public function test_created_once_with_dual_actor_audit_and_neutral_outbox_then_duplicate(): void {
        global $DB;
        $f = $this->fixture();
        $result = $this->send($f);
        $this->assertSame('created', $result['outcome']);
        $this->assertSame(inbound::SCHEMA, $result['schema_version']);
        $message = $DB->get_record('local_kbacompany_commmsg', ['uuid' => $result['message_uuid']], '*', MUST_EXIST);
        $this->assertSame((int)$f['author']->id, (int)$message->authorid, 'the human author, never the principal');
        $this->assertSame($f['thread'], (string)$message->threaduuid);
        $this->assertSame('Synthetic imported text', (string)$message->body);
        $receipt = $DB->get_record('local_kbacompany_inbreceipt', ['uuid' => $result['receipt_uuid']], '*', MUST_EXIST);
        $this->assertSame('created', (string)$receipt->outcome);
        $this->assertSame($result['message_uuid'], (string)$receipt->messageuuid);
        $this->assertSame($f['sender'], (string)$receipt->senderbindinguuid);
        $this->assertGreaterThan(0, (int)$receipt->timecommitted);
        $audit = $DB->get_record('local_kbacompany_inbaudit', ['receiptuuid' => $result['receipt_uuid'],
            'action' => 'create'], '*', MUST_EXIST);
        $this->assertSame((int)$f['principal']->id, (int)$audit->principaluserid);
        $this->assertSame((int)$f['author']->id, (int)$audit->actoruserid);
        $this->assertSame($result['message_uuid'], (string)$audit->objectuuid);
        $events = $DB->get_records('local_kbacompany_eventout', ['eventtype' => inbound::EVENT]);
        $this->assertCount(1, $events);
        $event = reset($events);
        $this->assertSame($f['company'], (string)$event->companyuuid);
        $this->assertSame($result['message_uuid'], (string)$event->entityuuid);
        foreach ([self::CHAT, self::SENDER, 'mid.synthetic.0001', 'Synthetic imported text'] as $leak) {
            $this->assertStringNotContainsString($leak, (string)$event->envelope, 'no MAX identifiers or text in the outbox');
        }
        $this->assertTrue($DB->record_exists('local_kbacompany_commnotif', ['messageuuid' => $result['message_uuid'],
            'userid' => (int)$f['peer']->id]));
        $before = $this->counts();
        $again = $this->send($f);
        $this->assertSame('duplicate', $again['outcome']);
        $this->assertSame($result['receipt_uuid'], $again['receipt_uuid']);
        $this->assertSame($result['message_uuid'], $again['message_uuid']);
        $this->assertSame($before, $this->counts(), 'a retry sends, notifies and publishes nothing again');
        $this->assertFalse(communication_write_gate::is_held($f['company']));
    }

    public function test_n02_n25_strict_payload_rejected_without_defaults(): void {
        global $DB;
        $f = $this->fixture();
        $valid = $this->json($this->dto($f));
        $missing = $this->dto($f);
        unset($missing['sender_is_bot']);
        $extra = $this->dto($f, ['unexpected' => 1]);
        $cases = [
            'malformed' => '{"schema_version":',
            'not object' => '[1,2]',
            'duplicate key' => substr($valid, 0, -1) . ',"text":"Second text"}',
            'missing security flag' => $this->json($missing),
            'unknown field' => $this->json($extra),
            'numeric chat id' => str_replace('"' . self::CHAT . '"', self::CHAT, $valid),
            'float revision' => str_replace('"binding_revision":1', '"binding_revision":1.0', $valid),
            'string bot flag' => $this->json($this->dto($f, ['sender_is_bot' => 'false'])),
            'wrong schema' => $this->json($this->dto($f, ['schema_version' => 'rc33.inbound/2'])),
            'id overflow' => $this->json($this->dto($f, ['external_sender_id' => '9223372036854775808'])),
            'minus zero' => $this->json($this->dto($f, ['external_chat_id' => '-0'])),
            'control char mid' => $this->json($this->dto($f, ['external_message_id' => "mid\n1"])),
            'empty text' => $this->json($this->dto($f, ['text' => "  \n "])),
            'oversize text' => $this->json($this->dto($f, ['text' => str_repeat('a', inbound::MAX_TEXT + 1)])),
            'attachments object' => $this->json($this->dto($f, ['attachments' => ['a' => 1]])),
        ];
        foreach ($cases as $name => $payload) {
            try {
                inbound::receive_message((int)$f['principal']->id, $payload);
                $this->fail('Payload must be refused: ' . $name);
            } catch (inbound_exception $e) {
                $this->assertSame('payload_invalid', $e->category, $name);
            }
        }
        $this->assertSame(0, $DB->count_records('local_kbacompany_inbreceipt'));
        $this->assertSame(0, $DB->count_records('local_kbacompany_eventout', ['eventtype' => inbound::EVENT]));
        $this->assertSame(count($cases), $DB->count_records('local_kbacompany_inbaudit',
            ['action' => 'deny', 'outcome' => 'payload_invalid']));
        // The strict decoder itself.
        $this->assertSame(['a' => '1'], inbound_json::decode('{"a":"1"}'));
        foreach (['{"a":1e3}', '{"a":01}', '{"a":-0}', '{"a":1,"a":2}', '{"a":1234567890123456}'] as $bad) {
            try {
                inbound_json::decode($bad);
                $this->fail('Decoder must refuse ' . $bad);
            } catch (inbound_exception $e) {
                $this->assertSame('payload_invalid', $e->category);
            }
        }
    }

    public function test_principal_switch_and_connection_assertions(): void {
        global $DB;
        $f = $this->fixture();
        set_config(inbound::CONFIG, 0, 'local_kbacompany');
        $this->refused('retryable_unavailable', function() use ($f) { $this->send($f); });
        set_config(inbound::CONFIG, 1, 'local_kbacompany');
        // An ordinary employee, a site administrator and an unknown account are never the principal.
        $this->refused('unauthenticated', function() use ($f) { $this->send($f, [], (int)$f['author']->id); });
        $this->refused('unauthenticated', function() use ($f) { $this->send($f, [], (int)$f['admin']); });
        $this->refused('unauthenticated', function() use ($f) { $this->send($f, [], 0); });
        // The DTO connection is only an assertion of the authenticated principal's own connection.
        $this->refused('scope_denied', function() use ($f) {
            $this->send($f, ['connection_uuid' => $f['connectionb']]);
        });
        // Capability removed → unauthenticated, even with an enabled connection.
        $roleid = (int)$DB->get_field('role', 'id', ['shortname' => 'inboundimport' . (int)$f['principal']->id]);
        unassign_capability('local/kbacompany:importinbound', $roleid);
        accesslib_clear_all_caches_for_unit_testing();
        $this->refused('unauthenticated', function() use ($f) { $this->send($f); });
        $this->assertSame(0, $DB->count_records('local_kbacompany_inbreceipt'));
        $this->assertSame(0, $DB->count_records('local_kbacompany_commmsg', ['threaduuid' => $f['thread'],
            'authorid' => (int)$f['author']->id]));
    }

    public function test_n03_n04_unknown_or_foreign_binding_is_scope_denied_without_writes_in_b(): void {
        global $DB;
        $f = $this->fixture();
        $before = $DB->count_records('local_kbacompany_commmsg', ['companyuuid' => $f['other']]);
        $this->refused('scope_denied', function() use ($f) {
            $this->send($f, ['binding_uuid' => inbound::uuid()]);
        });
        $this->refused('scope_denied', function() use ($f) {
            $this->send($f, ['binding_uuid' => $f['bindingb']]);
        });
        $this->refused('scope_denied', function() use ($f) {
            $this->send($f, ['company_uuid' => $f['other']]);
        });
        $this->refused('scope_denied', function() use ($f) {
            $this->send($f, ['thread_uuid' => $f['threadb']]);
        });
        $this->refused('scope_denied', function() use ($f) {
            $this->send($f, ['external_chat_id' => self::CHAT2]);
        });
        $this->assertSame($before, $DB->count_records('local_kbacompany_commmsg', ['companyuuid' => $f['other']]));
        $this->assertSame(0, $DB->count_records('local_kbacompany_inbreceipt'));
        $this->assertSame(0, $DB->count_records('local_kbacompany_inbaudit', ['companyuuid' => $f['other'],
            'action' => 'deny']));
        // A channel or dialog never auto-binds; it is a durable review decision without body.
        $this->review($f, 'unsupported_chat', ['chat_type' => 'channel']);
    }

    public function test_n05_unknown_or_bot_sender_creates_no_user_or_participant(): void {
        global $DB;
        $f = $this->fixture();
        $users = $DB->count_records('user');
        $parts = $DB->count_records('local_kbacompany_commpart');
        $this->review($f, 'sender_unverified', ['external_sender_id' => '5000000000099']);
        $this->review($f, 'sender_bot', ['sender_is_bot' => true, 'external_message_id' => 'mid.synthetic.0002']);
        $this->assertSame($users, $DB->count_records('user'));
        $this->assertSame($parts, $DB->count_records('local_kbacompany_commpart'));
    }

    public function test_n06_consent_missing_revoked_or_before_effective_goes_to_review(): void {
        $f = $this->fixture();
        $this->review($f, 'before_effective', ['source_timestamp_ms' => (time() - 7200) * 1000]);
        // A rebind under a new policy raises the revision; the old consent no longer covers it.
        registry::bind_group($f['company'], $f['admin'], $f['connection'], self::CHAT, $f['thread'], 'p2', time() - 3600);
        $this->review($f, 'binding_stale', ['external_message_id' => 'mid.synthetic.0002']);
        $this->review($f, 'consent_required', ['binding_revision' => 2, 'external_message_id' => 'mid.synthetic.0003']);
        registry::revoke_sender($f['company'], $f['admin'], $f['sender']);
        $this->review($f, 'sender_unverified', ['binding_revision' => 2, 'external_message_id' => 'mid.synthetic.0004']);
    }

    public function test_n07_revoked_suspended_or_removed_author_is_refused_and_stays_refused_on_retry(): void {
        global $DB;
        $f = $this->fixture();
        $author = (int)$f['author']->id;
        communication_service::remove_participant($f['company'], $f['admin'], $f['thread'], $author);
        $first = $this->review($f, 'thread_unavailable');
        // The decision is durable for the source event: a later retry never turns it into a publication.
        $retry = $this->send($f);
        $this->assertSame('review_required', $retry['outcome']);
        $this->assertSame($first['receipt_uuid'], $retry['receipt_uuid']);
        $DB->set_field('user', 'suspended', 1, ['id' => $author]);
        $this->review($f, 'author_unavailable', ['external_message_id' => 'mid.synthetic.0002']);
        $DB->set_field('user', 'suspended', 0, ['id' => $author]);
        employee_service::close_membership($f['company'], $author, $f['admin']);
        $this->review($f, 'author_unavailable', ['external_message_id' => 'mid.synthetic.0003']);
        $this->assertSame(0, $DB->count_records('local_kbacompany_commmsg', ['authorid' => $author,
            'threaduuid' => $f['thread']]));
    }

    public function test_n08_n32_archived_thread_suspended_binding_and_revoked_connection(): void {
        global $DB;
        $f = $this->fixture();
        registry::set_binding_status($f['company'], $f['admin'], $f['binding'], 'suspended');
        $this->review($f, 'binding_stale');
        registry::set_binding_status($f['company'], $f['admin'], $f['binding'], 'active');
        $DB->set_field('local_kbacompany_commthread', 'status', 'archived', ['uuid' => $f['thread']]);
        $this->review($f, 'thread_unavailable', ['external_message_id' => 'mid.synthetic.0002']);
        registry::set_connection_status($f['company'], $f['admin'], $f['connection'], 'revoked');
        $this->refused('unauthenticated', function() use ($f) {
            $this->send($f, ['external_message_id' => 'mid.synthetic.0003']);
        });
        try {
            registry::set_connection_status($f['company'], $f['admin'], $f['connection'], 'enabled');
            $this->fail('Revoked connection is terminal');
        } catch (\moodle_exception $e) {
            $this->assertSame('inbound_registry_state', $e->errorcode);
        }
    }

    public function test_n11_changed_content_for_same_source_is_conflict_and_message_unchanged(): void {
        global $DB;
        $f = $this->fixture();
        $created = $this->send($f);
        $before = $this->counts();
        foreach ([['text' => 'Changed synthetic text'], ['sender_is_bot' => true], ['link_type' => 'forward'],
                ['source_timestamp_ms' => time() * 1000 - 1000]] as $change) {
            $this->refused('idempotency_conflict', function() use ($f, $change) { $this->send($f, $change); });
        }
        $this->assertSame($before, $this->counts());
        $this->assertSame('Synthetic imported text', (string)$DB->get_field('local_kbacompany_commmsg', 'body',
            ['uuid' => $created['message_uuid']]));
    }

    public function test_n12_n26_namespaces_rotation_and_rebind_do_not_bypass_dedup(): void {
        global $DB;
        $f = $this->fixture();
        $created = $this->send($f);
        // The same mid in another chat of the same namespace is an independent source.
        $thread2 = communication_service::create_thread($f['company'], $f['admin'], [
            'threadtype' => 'group', 'title' => 'Second imported group', 'participants' => [(int)$f['peer']->id],
        ]);
        $binding2 = registry::bind_group($f['company'], $f['admin'], $f['connection'], self::CHAT2, $thread2, 'p1',
            time() - 3600);
        registry::bind_sender($f['company'], $f['admin'], $f['connection'], self::SENDER2, (int)$f['peer']->id,
            $binding2, 'p1', 'synthetic:consent-0002', time() - 3600);
        $other = $this->send($f, ['binding_uuid' => $binding2, 'thread_uuid' => $thread2,
            'external_chat_id' => self::CHAT2, 'external_sender_id' => self::SENDER2]);
        $this->assertSame('created', $other['outcome']);
        $this->assertNotSame($created['message_uuid'], $other['message_uuid']);
        // Credential rotation keeps the namespace: the old source is a duplicate, never a second publication.
        $rotated = $this->getDataGenerator()->create_user(['firstname' => 'Integration', 'lastname' => 'Rotated']);
        $this->grant((int)$rotated->id);
        registry::rotate_principal($f['company'], $f['admin'], $f['connection'], (int)$rotated->id);
        $this->refused('unauthenticated', function() use ($f) { $this->send($f); });
        $before = $this->counts();
        $replay = $this->send($f, [], (int)$rotated->id);
        $this->assertSame('duplicate', $replay['outcome']);
        $this->assertSame($created['message_uuid'], $replay['message_uuid']);
        $this->assertSame($before, $this->counts());
        // The same bot identity cannot open a new namespace in another company or a second connection here.
        $fresh = $this->getDataGenerator()->create_user(['firstname' => 'Integration', 'lastname' => 'Fresh']);
        foreach ([[$f['other'], 'synthetic-bot-a', 'inbound_registry_exists'],
                [$f['company'], 'synthetic-bot-c', 'inbound_registry_exists']] as [$company, $bot, $code]) {
            try {
                registry::create_connection($company, $f['admin'], $bot, (int)$fresh->id);
                $this->fail('Second connection must be refused');
            } catch (\moodle_exception $e) {
                $this->assertSame($code, $e->errorcode);
            }
        }
        // A third company cannot re-attach the existing source namespace of bot A (receipts stay with their source).
        $thirdid = repository::save_company((object)['name' => 'Inbound C', 'active' => 1]);
        $third = identity_registry::company_uuid($thirdid);
        $this->member($thirdid, $third, $f['admin']);
        try {
            registry::create_connection($third, $f['admin'], 'synthetic-bot-a', (int)$fresh->id);
            $this->fail('An existing source namespace is never reused');
        } catch (\moodle_exception $e) {
            $this->assertSame('inbound_registry_namespace', $e->errorcode);
        }
        $this->assertSame(2, $DB->count_records('local_kbacompany_inbns'));
    }

    public function test_n13_n29_any_failure_in_the_transaction_rolls_everything_back(): void {
        global $DB;
        $f = $this->fixture();
        foreach (['after_message', 'after_audit', 'after_outbox'] as $stage) {
            $before = $this->counts();
            inbound::set_test_fault($stage);
            $this->refused('retryable_unavailable', function() use ($f) { $this->send($f); });
            inbound::set_test_fault(null);
            $this->assertSame($before, $this->counts(), 'no message, receipt, notification or event after ' . $stage);
            $this->assertFalse(communication_write_gate::is_held($f['company']));
            $this->assertTrue($DB->record_exists('local_kbacompany_inbaudit', ['action' => 'deny',
                'outcome' => 'retryable_unavailable', 'reason' => 'publish_failed']));
        }
        $created = $this->send($f);
        $this->assertSame('created', $created['outcome']);
        $this->assertSame(1, $DB->count_records('local_kbacompany_inbreceipt'));
        // A refusal whose audit cannot be written is retryable, never a terminal answer.
        inbound::set_test_fault('deny_audit');
        $this->refused('retryable_unavailable', function() use ($f) {
            inbound::receive_message((int)$f['principal']->id, '{');
        });
        $this->refused('retryable_unavailable', function() use ($f) { $this->send($f, ['text' => 'Different']); });
    }

    public function test_n14_n16_reply_forward_and_attachment_are_held_whole(): void {
        $f = $this->fixture();
        $this->review($f, 'reply_held', ['link_type' => 'reply', 'reply_to_external_message_id' => 'mid.other.1']);
        $this->review($f, 'reply_held', ['reply_to_external_message_id' => 'mid.other.2',
            'external_message_id' => 'mid.synthetic.0002']);
        $this->review($f, 'forward_held', ['link_type' => 'forward', 'external_message_id' => 'mid.synthetic.0003']);
        $this->review($f, 'unsupported_attachment', ['attachments' => [['kind' => 'file', 'digest' => 'synthetic']],
            'external_message_id' => 'mid.synthetic.0004']);
    }

    public function test_n15_markup_is_literal_and_inline_mentions_do_not_notify(): void {
        global $DB;
        $f = $this->fixture();
        $text = '<script>alert(1)</script> @Petr Peer <b>bold</b>';
        $created = $this->send($f, ['text' => $text]);
        $this->assertSame('created', $created['outcome']);
        $this->assertSame($text, (string)$DB->get_field('local_kbacompany_commmsg', 'body',
            ['uuid' => $created['message_uuid']]), 'plain text stored as is and escaped by the renderers');
        $this->assertFalse($DB->record_exists('local_kbacompany_commnotif', ['messageuuid' => $created['message_uuid'],
            'kind' => 'mention']));
    }

    public function test_n19_n27_control_events_hold_and_never_resurrect_or_edit(): void {
        global $DB;
        $f = $this->fixture();
        $held = $this->control($f, 'message_removed');
        $this->assertSame('held', $held['outcome']);
        $this->control($f, 'message_removed');
        $hold = $DB->get_record('local_kbacompany_inbhold', ['sourcekey' => inbound::source_key(
            (string)$DB->get_field('local_kbacompany_inbconn', 'namespaceuuid', ['uuid' => $f['connection']]),
            self::CHAT, 'mid.synthetic.0001')], '*', MUST_EXIST);
        $this->assertSame(2, (int)$hold->eventcount);
        $this->assertSame(1, (int)$hold->removed);
        // A create after a committed hold is never published.
        $this->review($f, 'source_held');
        // A control for a published message only marks it for review; the message is unchanged.
        $created = $this->send($f, ['external_message_id' => 'mid.synthetic.0002']);
        $edited = $this->control($f, 'message_edited', ['external_message_id' => 'mid.synthetic.0002']);
        $this->assertSame('review_required', $edited['outcome']);
        $this->assertSame('Synthetic imported text', (string)$DB->get_field('local_kbacompany_commmsg', 'body',
            ['uuid' => $created['message_uuid']]));
        $this->assertSame('active', (string)$DB->get_field('local_kbacompany_commmsg', 'status',
            ['uuid' => $created['message_uuid']]));
        $this->refused('payload_invalid', function() use ($f) { $this->control($f, 'message_pinned'); });
        $this->refused('scope_denied', function() use ($f) {
            $this->control($f, 'message_removed', ['binding_uuid' => $f['bindingb']]);
        });
    }

    public function test_n21_n28_revocations_committed_before_intake_win(): void {
        global $DB;
        $f = $this->fixture();
        registry::revoke_sender($f['company'], $f['admin'], $f['sender']);
        $this->review($f, 'sender_unverified');
        registry::set_connection_status($f['company'], $f['admin'], $f['connection'], 'disabled');
        $this->refused('unauthenticated', function() use ($f) {
            $this->send($f, ['external_message_id' => 'mid.synthetic.0002']);
        });
        // Registry writers serialize on the same company gate as the import.
        $this->assertFalse(communication_write_gate::is_held($f['company']));
        $this->assertSame(0, $DB->count_records('local_kbacompany_commmsg', ['threaduuid' => $f['thread'],
            'authorid' => (int)$f['author']->id]));
    }

    public function test_n22_n24_responses_and_audit_carry_no_body_or_foreign_identifiers(): void {
        global $DB;
        $f = $this->fixture();
        $review = $this->review($f, 'sender_bot', ['sender_is_bot' => true, 'text' => 'Secret synthetic body']);
        $encoded = json_encode($review);
        foreach ([self::SENDER, self::CHAT, (string)$f['author']->id, 'Secret synthetic body', $f['other']] as $leak) {
            $this->assertStringNotContainsString($leak, $encoded);
        }
        foreach (['local_kbacompany_inbreceipt', 'local_kbacompany_inbaudit', 'local_kbacompany_inbhold'] as $table) {
            foreach ($DB->get_records($table) as $row) {
                $this->assertStringNotContainsString('Secret synthetic body', json_encode($row), $table);
            }
        }
        // Principal of company B cannot reach company A sources, not even as a duplicate probe.
        $this->send($f, ['external_message_id' => 'mid.synthetic.0002']);
        $this->refused('scope_denied', function() use ($f) {
            $this->send($f, ['external_message_id' => 'mid.synthetic.0002'], (int)$f['principalb']->id);
        });
    }

    public function test_n30_fixed_audience_and_suspended_recipient_without_notification(): void {
        global $DB;
        $f = $this->fixture();
        try {
            communication_service::add_participant($f['company'], $f['admin'], $f['thread'], (int)$f['outsider']->id);
            $this->fail('A bound thread admits no new readers');
        } catch (\moodle_exception $e) {
            $this->assertSame('communication_importaudience', $e->errorcode);
        }
        $DB->set_field('user', 'suspended', 1, ['id' => (int)$f['peer']->id]);
        $created = $this->send($f);
        $this->assertSame('created', $created['outcome']);
        $this->assertFalse($DB->record_exists('local_kbacompany_commnotif', ['messageuuid' => $created['message_uuid'],
            'userid' => (int)$f['peer']->id]));
        // After the binding is revoked the audience can change again.
        registry::set_binding_status($f['company'], $f['admin'], $f['binding'], 'revoked');
        $DB->set_field('user', 'suspended', 0, ['id' => (int)$f['peer']->id]);
        communication_service::add_participant($f['company'], $f['admin'], $f['thread'], (int)$f['outsider']->id);
        $this->assertTrue($DB->record_exists('local_kbacompany_commpart', ['threaduuid' => $f['thread'],
            'userid' => (int)$f['outsider']->id, 'active' => 1]));
    }

    public function test_n31_old_future_and_delayed_duplicate(): void {
        $f = $this->fixture();
        $created = $this->send($f);
        $this->review($f, 'age_window', ['source_timestamp_ms' => (time() - inbound::MAX_AGE - 60) * 1000,
            'external_message_id' => 'mid.synthetic.0002']);
        $this->review($f, 'age_window', ['source_timestamp_ms' => (time() + inbound::MAX_SKEW + 60) * 1000,
            'external_message_id' => 'mid.synthetic.0003']);
        $delayed = $this->send($f);
        $this->assertSame('duplicate', $delayed['outcome']);
        $this->assertSame($created['message_uuid'], $delayed['message_uuid']);
    }

    public function test_registry_rules_for_managers_principals_threads_and_evidence(): void {
        $f = $this->fixture();
        $author = (int)$f['author']->id;
        $expect = function(string $code, callable $call): void {
            try {
                $call();
                $this->fail('Registry must refuse: ' . $code);
            } catch (\required_capability_exception $e) {
                $this->assertSame('nopermissions', $code);
            } catch (\moodle_exception $e) {
                $this->assertSame($code, $e->errorcode);
            }
        };
        $this->setUser($f['author']);
        $expect('nopermissions', function() use ($f, $author) {
            registry::bind_sender($f['company'], $author, $f['connection'], '5000000000077', $author, $f['binding'],
                'p1', 'synthetic:self', 0);
        });
        $this->setAdminUser();
        $expect('inbound_registry_invalid', function() use ($f, $author) {
            registry::bind_sender($f['company'], $f['admin'], $f['connection'], '5000000000077', $author,
                $f['binding'], 'p1', 'name-match:Alla Author', 0);
        });
        $expect('inbound_registry_principal', function() use ($f) {
            registry::rotate_principal($f['company'], $f['admin'], $f['connection'], $f['admin']);
        });
        $expect('inbound_registry_principal', function() use ($f) {
            registry::rotate_principal($f['company'], $f['admin'], $f['connection'], (int)$f['principalb']->id);
        });
        $direct = communication_service::create_thread($f['company'], $f['admin'], [
            'threadtype' => 'direct', 'participants' => [(int)$f['peer']->id],
        ]);
        $expect('inbound_registry_thread', function() use ($f, $direct) {
            registry::bind_group($f['company'], $f['admin'], $f['connection'], self::CHAT2, $direct, 'p1', 0);
        });
        // One thread is fed by one chat only.
        $expect('inbound_registry_thread', function() use ($f) {
            registry::bind_group($f['company'], $f['admin'], $f['connection'], self::CHAT2, $f['thread'], 'p1', 0);
        });
        $expect('inbound_registry_reference', function() use ($f) {
            registry::set_binding_status($f['company'], $f['admin'], $f['bindingb'], 'suspended');
        });
    }

    public function test_external_api_requires_capability_and_returns_safe_json(): void {
        $f = $this->fixture();
        $payload = $this->json($this->dto($f));
        $this->setUser($f['author']);
        try {
            inbound_api::message($payload);
            $this->fail('An employee without the capability cannot import');
        } catch (\required_capability_exception $e) {
            $this->assertSame('nopermissions', $e->errorcode);
        }
        $this->setUser($f['principal']);
        $result = json_decode(inbound_api::message($payload), true);
        $this->assertSame('created', $result['outcome']);
        $this->assertSame(['schema_version', 'outcome', 'receipt_uuid', 'correlation_id', 'message_uuid'],
            array_keys($result));
        $control = json_decode(inbound_api::control($this->json([
            'schema_version' => inbound::SCHEMA, 'connection_uuid' => $f['connection'], 'binding_uuid' => $f['binding'],
            'binding_revision' => 1, 'external_chat_id' => self::CHAT, 'external_message_id' => 'mid.synthetic.0001',
            'event_type' => 'message_edited', 'source_timestamp_ms' => time() * 1000,
        ])), true);
        $this->assertSame('review_required', $control['outcome']);
    }

    public function test_privacy_erasure_of_the_principal_closes_the_import_path(): void {
        global $DB;
        $f = $this->fixture();
        $this->send($f);
        $principal = (int)$f['principal']->id;
        $contextlist = new \core_privacy\local\request\approved_contextlist($f['principal'], 'local_kbacompany',
            [\context_system::instance()->id]);
        \local_kbacompany\privacy\provider::delete_data_for_user($contextlist);
        $conn = $DB->get_record('local_kbacompany_inbconn', ['uuid' => $f['connection']], '*', MUST_EXIST);
        $this->assertSame('revoked', (string)$conn->status);
        $this->assertSame(0, (int)$conn->principaluserid);
        $this->assertFalse($DB->record_exists('local_kbacompany_inbaudit', ['principaluserid' => $principal]));
        $this->assertSame(1, $DB->count_records('local_kbacompany_inbreceipt'), 'source identity kept for dedup');
        $this->refused('unauthenticated', function() use ($f) { $this->send($f); });
    }
}
