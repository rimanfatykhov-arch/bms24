<?php
namespace local_kbacompany\local;

defined('MOODLE_INTERNAL') || die();

/**
 * Company-scoped communication core.
 *
 * Threads aggregate human communication around BMS objects, but messages, tasks,
 * notifications and future decisions remain separate business objects.
 */
class communication_service {
    public const CONTRACT_VERSION = '0.6.0';
    public const THREAD_TYPES = ['direct', 'group', 'object'];
    public const THREAD_STATUSES = ['active', 'archived'];
    public const CONTEXT_KINDS = ['company', 'task', 'project', 'process', 'document', 'goal', 'statistic',
        'kbaelement', 'improvement', 'meeting'];
    public const NOTIFICATION_KINDS = ['thread_invite', 'message', 'mention', 'task_created',
        'calendar_day', 'calendar_meeting_hour', 'calendar_task_due', 'calendar_checkpoint_tomorrow', 'bp_decision'];
    public const ATTACHMENT_KINDS = ['document', 'moodlefile', 'url', 'external'];

    public static function contract(): array {
        $contract = [
            'component' => 'local_kbacompany',
            'contract' => 'bms_communication_core',
            'version' => self::CONTRACT_VERSION,
            'company_scope' => 'required',
            'objects' => ['CommunicationThread', 'CommunicationParticipant', 'CommunicationMessage',
                'CommunicationReadState', 'CommunicationMention', 'CommunicationNotification',
                'CommunicationAttachmentReference'],
            'thread_types' => self::THREAD_TYPES,
            'context_kinds' => self::CONTEXT_KINDS,
            'rules' => [
                'message_is_not_task',
                'notification_is_not_message',
                'thread_content_is_participant_scoped',
                'manager_role_does_not_implicitly_deanonymise_or_read_private_threads',
                'object_discussion_keeps_explicit_context_reference',
                'message_can_create_traceable_task_without_becoming_the_task',
                'message_original_versions_are_audited_for_author_edits_and_withdrawals',
                'read_state_is_per_participant',
                'mentions_are_explicit_and_participant_scoped',
                'attachment_core_stores_references_and_protected_moodle_files',
                'meeting_discussion_message_can_propose_traceable_canonical_decision',
            ],
        ];
        $contract['schema_hash_algorithm'] = 'sha256';
        $contract['schema_hash'] = hash('sha256', json_encode($contract,
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        return $contract;
    }

    private static function fail(string $key): void {
        throw new \moodle_exception('communication_' . $key, 'local_kbacompany');
    }

    private static function uuid(): string {
        $bytes = random_bytes(16);
        $bytes[6] = chr((ord($bytes[6]) & 0x0f) | 0x40);
        $bytes[8] = chr((ord($bytes[8]) & 0x3f) | 0x80);
        $hex = bin2hex($bytes);
        return substr($hex, 0, 8) . '-' . substr($hex, 8, 4) . '-' . substr($hex, 12, 4) .
            '-' . substr($hex, 16, 4) . '-' . substr($hex, 20);
    }

    private static function employees(string $companyuuid, int $actor): array {
        $organization = organization_service::organization_context($companyuuid, $actor);
        $employees = [];
        foreach ($organization['employees'] as $employee) {
            $employees[(int)$employee['user_id']] = (string)$employee['full_name'];
        }
        // Company owners/managers may not be represented as active staffing rows yet; current actor is always a valid participant.
        if (!isset($employees[$actor])) {
            global $DB;
            if ($user = $DB->get_record('user', ['id' => $actor], 'id,firstname,lastname')) {
                $employees[$actor] = fullname($user);
            }
        }
        return $employees;
    }

    private static function require_employee(array $employees, int $userid): void {
        if ($userid <= 0 || !isset($employees[$userid])) { self::fail('employee'); }
    }

    private static function text(array $data, string $key, int $max, bool $required = false): string {
        $value = trim((string)($data[$key] ?? ''));
        if (($required && $value === '') || \core_text::strlen($value) > $max) { self::fail('invalid'); }
        return $value;
    }

    private static function thread(string $companyuuid, string $threaduuid): \stdClass {
        global $DB;
        $row = $DB->get_record('local_kbacompany_commthread', ['companyuuid' => $companyuuid, 'uuid' => $threaduuid]);
        if (!$row) { self::fail('reference'); }
        return $row;
    }

    private static function participant(string $companyuuid, string $threaduuid, int $userid): ?\stdClass {
        global $DB;
        return $DB->get_record('local_kbacompany_commpart', [
            'companyuuid' => $companyuuid, 'threaduuid' => $threaduuid, 'userid' => $userid, 'active' => 1,
        ]) ?: null;
    }

    private static function require_participant(string $companyuuid, string $threaduuid, int $userid): \stdClass {
        $part = self::participant($companyuuid, $threaduuid, $userid);
        if (!$part) { self::fail('permission'); }
        return $part;
    }

    private static function require_owner(string $companyuuid, string $threaduuid, int $userid): \stdClass {
        $part = self::require_participant($companyuuid, $threaduuid, $userid);
        if ($part->role !== 'owner') { self::fail('permission'); }
        return $part;
    }

    private static function message(string $companyuuid, string $messageuuid): \stdClass {
        global $DB;
        $row = $DB->get_record('local_kbacompany_commmsg', ['companyuuid' => $companyuuid, 'uuid' => $messageuuid]);
        if (!$row) { self::fail('reference'); }
        return $row;
    }

    /** Resolve the canonical object behind an object discussion using the actor's current permissions. */
    private static function context_descriptor(string $companyuuid, int $actor, \stdClass $thread): ?array {
        if ((string)$thread->threadtype !== 'object') { return null; }
        return communication_object_service::describe($companyuuid, $actor, (string)$thread->contextkind,
            (string)$thread->contextuuid, (int)$thread->contextrevision);
    }

    /** Thread membership alone must never reveal an object that the actor can no longer access. */
    private static function require_thread_context(string $companyuuid, int $actor, \stdClass $thread): ?array {
        try {
            return self::context_descriptor($companyuuid, $actor, $thread);
        } catch (\moodle_exception $e) {
            self::fail('permission');
        }
        return null;
    }

    private static function create_notification(string $companyuuid, int $userid, string $kind, string $threaduuid,
            string $messageuuid, string $taskuuid, string $title): void {
        global $DB;
        if (!in_array($kind, self::NOTIFICATION_KINDS, true)) { self::fail('invalid'); }
        $DB->insert_record('local_kbacompany_commnotif', (object)[
            'companyuuid' => $companyuuid, 'uuid' => self::uuid(), 'userid' => $userid, 'kind' => $kind,
            'threaduuid' => $threaduuid, 'messageuuid' => $messageuuid, 'taskuuid' => $taskuuid,
            'title' => \core_text::substr($title, 0, 255), 'isread' => 0, 'timecreated' => time(), 'timeread' => 0,
        ]);
    }

    /**
     * Idempotently create a system notification using a deterministic UUID.
     *
     * The idempotency key is never persisted as a second identifier: it is
     * deterministically folded into the existing unique notification UUID.
     * This keeps the established privacy/export surface intact while making
     * scheduled reminder generation replay-safe.
     */
    public static function ensure_system_notification(string $companyuuid, int $userid, string $kind,
            string $idempotencykey, string $title, string $taskuuid = ''): bool {
        global $DB;
        company_context_service::require_exists($companyuuid);
        if ($userid <= 0 || !in_array($kind, self::NOTIFICATION_KINDS, true)) { self::fail('invalid'); }
        $idempotencykey = trim($idempotencykey);
        if (!preg_match('/^[a-f0-9]{64}$/D', $idempotencykey)) { self::fail('invalid'); }
        $hex = hash('sha256', "bms-notification\n" . $companyuuid . "\n" . $userid . "\n" . $kind . "\n" . $idempotencykey);
        // Deterministic RFC-4122-shaped UUID. Identity comes from the full SHA-256 input above.
        $hex[12] = '5';
        $variant = hexdec($hex[16]);
        $hex[16] = dechex(($variant & 0x3) | 0x8);
        $uuid = substr($hex, 0, 8) . '-' . substr($hex, 8, 4) . '-' . substr($hex, 12, 4) . '-' .
            substr($hex, 16, 4) . '-' . substr($hex, 20, 12);
        if ($DB->record_exists('local_kbacompany_commnotif', ['uuid' => $uuid])) { return false; }
        try {
            $DB->insert_record('local_kbacompany_commnotif', (object)[
                'companyuuid' => $companyuuid, 'uuid' => $uuid, 'userid' => $userid, 'kind' => $kind,
                'threaduuid' => '', 'messageuuid' => '', 'taskuuid' => $taskuuid,
                'title' => \core_text::substr(trim($title), 0, 255), 'isread' => 0,
                'timecreated' => time(), 'timeread' => 0,
            ]);
            return true;
        } catch (\dml_write_exception $e) {
            // A concurrent cron worker may have claimed the same deterministic UUID.
            if ($DB->record_exists('local_kbacompany_commnotif', ['uuid' => $uuid])) { return false; }
            throw $e;
        }
    }

    /** Create a private direct/group thread or an object-scoped discussion. */
    public static function create_thread(string $companyuuid, int $actor, array $data): string {
        global $DB;
        company_context_service::context($companyuuid, $actor);
        $employees = self::employees($companyuuid, $actor);
        self::require_employee($employees, $actor);
        $type = self::text($data, 'threadtype', 16, true);
        if (!in_array($type, self::THREAD_TYPES, true)) { self::fail('invalid'); }
        $title = self::text($data, 'title', 255, $type === 'group');
        $contextkind = self::text($data, 'contextkind', 32, false);
        $contextuuid = self::text($data, 'contextuuid', 100, false);
        $contextrevision = max(0, (int)($data['contextrevision'] ?? 0));
        if ($type === 'object') {
            if (!in_array($contextkind, self::CONTEXT_KINDS, true) || $contextuuid === '') { self::fail('contexterror'); }
            $descriptor = communication_object_service::describe($companyuuid, $actor, $contextkind,
                $contextuuid, $contextrevision);
            $contextrevision = (int)$descriptor['revision'];
            if ($title === '') {
                $title = \core_text::substr($descriptor['label'] . ': ' . $descriptor['title'], 0, 255);
            }
        } else {
            $contextkind = ''; $contextuuid = ''; $contextrevision = 0;
        }
        $participantids = array_values(array_unique(array_filter(array_map('intval', $data['participants'] ?? []))));
        $participantids = array_values(array_diff($participantids, [$actor]));
        foreach ($participantids as $userid) {
            self::require_employee($employees, $userid);
            if ($type === 'object') {
                try {
                    communication_object_service::describe($companyuuid, $userid, $contextkind, $contextuuid, $contextrevision);
                } catch (\moodle_exception $e) {
                    self::fail('permission');
                }
            }
        }
        if ($type === 'direct' && count($participantids) !== 1) { self::fail('directparticipants'); }
        if ($type === 'group' && !$participantids) { self::fail('participantserror'); }
        if ($type === 'direct' && $title === '') {
            $title = ($employees[$actor] ?? get_string('communication_direct', 'local_kbacompany')) .
                ' ↔ ' . ($employees[$participantids[0]] ?? get_string('communication_direct', 'local_kbacompany'));
        }

        $lock = \core\lock\lock_config::get_lock_factory('local_kbacompany')
            ->get_lock('communication:' . $companyuuid, 10);
        if (!$lock) { self::fail('lock'); }
        try {
            $tx = $DB->start_delegated_transaction();
            if ($type === 'object') {
                $existingthreads = $DB->get_records('local_kbacompany_commthread', [
                    'companyuuid' => $companyuuid, 'threadtype' => 'object', 'contextkind' => $contextkind,
                    'contextuuid' => $contextuuid, 'status' => 'active',
                ], 'id ASC', '*', 0, 1);
                if ($existingthreads) {
                    $existing = reset($existingthreads);
                    if (!self::participant($companyuuid, (string)$existing->uuid, $actor)) {
                        // Object access is the security boundary for the shared history. An authorised viewer may
                        // join the canonical object discussion, while communication_object_service has already
                        // made inaccessible/non-existent objects indistinguishable.
                        $now = time();
                        $part = $DB->get_record('local_kbacompany_commpart', [
                            'companyuuid' => $companyuuid, 'threaduuid' => (string)$existing->uuid, 'userid' => $actor,
                        ]);
                        if ($part) {
                            $part->active = 1; $part->leftat = 0; $part->joinedat = $now;
                            $DB->update_record('local_kbacompany_commpart', $part);
                        } else {
                            $DB->insert_record('local_kbacompany_commpart', (object)[
                                'companyuuid' => $companyuuid, 'threaduuid' => (string)$existing->uuid, 'userid' => $actor,
                                'role' => 'member', 'active' => 1, 'muted' => 0, 'joinedat' => $now, 'leftat' => 0,
                                'timecreated' => $now,
                            ]);
                        }
                        $read = $DB->get_record('local_kbacompany_commread', [
                            'companyuuid' => $companyuuid, 'threaduuid' => (string)$existing->uuid, 'userid' => $actor,
                        ]);
                        if ($read) {
                            $read->timeread = $now;
                            $DB->update_record('local_kbacompany_commread', $read);
                        } else {
                            $DB->insert_record('local_kbacompany_commread', (object)[
                                'companyuuid' => $companyuuid, 'threaduuid' => (string)$existing->uuid, 'userid' => $actor,
                                'lastmessageid' => 0, 'timeread' => $now,
                            ]);
                        }
                    }
                    $tx->allow_commit();
                    return (string)$existing->uuid;
                }
            }
            $uuid = self::uuid(); $now = time();
            $DB->insert_record('local_kbacompany_commthread', (object)[
                'companyuuid' => $companyuuid, 'uuid' => $uuid, 'threadtype' => $type, 'title' => $title,
                'contextkind' => $contextkind, 'contextuuid' => $contextuuid, 'contextrevision' => $contextrevision,
                'createdby' => $actor, 'status' => 'active', 'lastmessageat' => 0,
                'timecreated' => $now, 'timemodified' => $now,
            ]);
            $allparticipants = array_merge([$actor], $participantids);
            foreach ($allparticipants as $userid) {
                $DB->insert_record('local_kbacompany_commpart', (object)[
                    'companyuuid' => $companyuuid, 'threaduuid' => $uuid, 'userid' => $userid,
                    'role' => $userid === $actor ? 'owner' : 'member', 'active' => 1, 'muted' => 0,
                    'joinedat' => $now, 'leftat' => 0, 'timecreated' => $now,
                ]);
                $DB->insert_record('local_kbacompany_commread', (object)[
                    'companyuuid' => $companyuuid, 'threaduuid' => $uuid, 'userid' => $userid,
                    'lastmessageid' => 0, 'timeread' => $now,
                ]);
                if ($userid !== $actor) {
                    self::create_notification($companyuuid, $userid, 'thread_invite', $uuid, '', '', $title);
                }
            }
            $tx->allow_commit();
            return $uuid;
        } catch (\Throwable $e) {
            if (isset($tx)) { $tx->rollback($e); }
            throw $e;
        } finally { $lock->release(); }
    }

    /** Add a participant. Thread owners only; management role alone never grants access to private content. */
    public static function add_participant(string $companyuuid, int $actor, string $threaduuid, int $userid): void {
        global $DB;
        company_context_service::context($companyuuid, $actor);
        self::require_owner($companyuuid, $threaduuid, $actor);
        $thread = self::thread($companyuuid, $threaduuid);
        self::require_thread_context($companyuuid, $actor, $thread);
        if ($thread->status !== 'active' || $thread->threadtype === 'direct') { self::fail('state'); }
        $employees = self::employees($companyuuid, $actor); self::require_employee($employees, $userid);
        if ($thread->threadtype === 'object') {
            try {
                communication_object_service::describe($companyuuid, $userid, (string)$thread->contextkind,
                    (string)$thread->contextuuid, (int)$thread->contextrevision);
            } catch (\moodle_exception $e) {
                self::fail('permission');
            }
        }
        $existing = $DB->get_record('local_kbacompany_commpart', [
            'companyuuid' => $companyuuid, 'threaduuid' => $threaduuid, 'userid' => $userid,
        ]);
        $now = time();
        if ($existing) {
            $existing->active = 1; $existing->leftat = 0; $existing->joinedat = $now;
            $DB->update_record('local_kbacompany_commpart', $existing);
        } else {
            $DB->insert_record('local_kbacompany_commpart', (object)[
                'companyuuid' => $companyuuid, 'threaduuid' => $threaduuid, 'userid' => $userid,
                'role' => 'member', 'active' => 1, 'muted' => 0, 'joinedat' => $now, 'leftat' => 0, 'timecreated' => $now,
            ]);
            $DB->insert_record('local_kbacompany_commread', (object)[
                'companyuuid' => $companyuuid, 'threaduuid' => $threaduuid, 'userid' => $userid,
                'lastmessageid' => 0, 'timeread' => $now,
            ]);
        }
        self::create_notification($companyuuid, $userid, 'thread_invite', $threaduuid, '', '', (string)$thread->title);
    }

    /** Append one message and notify other active participants. */
    public static function send_message(string $companyuuid, int $actor, string $threaduuid, string $body,
            array $mentionids = [], string $replytouuid = ''): string {
        global $DB;
        company_context_service::context($companyuuid, $actor);
        self::require_participant($companyuuid, $threaduuid, $actor);
        $thread = self::thread($companyuuid, $threaduuid);
        self::require_thread_context($companyuuid, $actor, $thread);
        if ($thread->status !== 'active') { self::fail('state'); }
        $body = trim($body);
        if ($body === '' || \core_text::strlen($body) > 20000) { self::fail('invalid'); }
        if ($replytouuid !== '') {
            $reply = self::message($companyuuid, $replytouuid);
            if ($reply->threaduuid !== $threaduuid || $reply->status !== 'active') { self::fail('reference'); }
        }
        $participants = $DB->get_records('local_kbacompany_commpart', [
            'companyuuid' => $companyuuid, 'threaduuid' => $threaduuid, 'active' => 1,
        ]);
        $participantids = [];
        foreach ($participants as $part) { $participantids[(int)$part->userid] = true; }
        $mentions = array_values(array_unique(array_filter(array_map('intval', $mentionids))));
        foreach ($mentions as $userid) {
            if (!isset($participantids[$userid])) { self::fail('mention'); }
        }
        $tx = $DB->start_delegated_transaction();
        $uuid = self::uuid(); $now = time();
        $messageid = $DB->insert_record('local_kbacompany_commmsg', (object)[
            'companyuuid' => $companyuuid, 'uuid' => $uuid, 'threaduuid' => $threaduuid,
            'authorid' => $actor, 'body' => $body, 'replytouuid' => $replytouuid, 'taskuuid' => '',
            'status' => 'active', 'timecreated' => $now,
        ]);
        foreach ($mentions as $userid) {
            $DB->insert_record('local_kbacompany_commmention', (object)[
                'companyuuid' => $companyuuid, 'threaduuid' => $threaduuid, 'messageuuid' => $uuid,
                'userid' => $userid, 'timecreated' => $now,
            ]);
        }
        foreach (array_keys($participantids) as $userid) {
            if ($userid === $actor) { continue; }
            if ((string)$thread->threadtype === 'object') {
                try {
                    communication_object_service::describe($companyuuid, (int)$userid, (string)$thread->contextkind,
                        (string)$thread->contextuuid, (int)$thread->contextrevision);
                } catch (\moodle_exception $e) {
                    continue;
                }
            }
            $kind = in_array($userid, $mentions, true) ? 'mention' : 'message';
            self::create_notification($companyuuid, $userid, $kind, $threaduuid, $uuid, '', (string)$thread->title);
        }
        $thread->lastmessageat = $now; $thread->timemodified = $now;
        $DB->update_record('local_kbacompany_commthread', $thread);
        $read = $DB->get_record('local_kbacompany_commread', [
            'companyuuid' => $companyuuid, 'threaduuid' => $threaduuid, 'userid' => $actor,
        ]);
        if ($read) {
            $read->lastmessageid = $messageid; $read->timeread = $now; $DB->update_record('local_kbacompany_commread', $read);
        }
        $tx->allow_commit();
        return $uuid;
    }

    /**
     * Author-only, audited correction or withdrawal of an active message.
     * Original content remains in a restricted revision log, never in the thread UI.
     * Tasks/decisions derived from a message stay immutable to preserve traceability.
     */
    public static function change_message(string $companyuuid, int $actor, string $threaduuid,
            string $messageuuid, string $operation, string $newbody, string $expectedhash): void {
        global $DB;
        company_context_service::context($companyuuid, $actor);
        if (!in_array($operation, ['edit', 'withdraw'], true) ||
                !preg_match('/^[a-f0-9]{64}$/D', $expectedhash)) {
            self::fail('invalid');
        }
        self::require_participant($companyuuid, $threaduuid, $actor);
        $thread = self::thread($companyuuid, $threaduuid);
        self::require_thread_context($companyuuid, $actor, $thread);
        if ($thread->status !== 'active') { self::fail('state'); }
        $newbody = trim($newbody);
        if ($operation === 'edit' && ($newbody === '' || \core_text::strlen($newbody) > 20000)) {
            self::fail('invalid');
        }
        // Serialize concurrent edits using the same named lock as other communication writes.
        $factory = \core\lock\lock_config::get_lock_factory('local_kbacompany');
        $lock = $factory->get_lock('commmsg:' . $companyuuid . ':' . $messageuuid, 10);
        if (!$lock) { self::fail('lock'); }
        try {
            $tx = $DB->start_delegated_transaction();
            $message = self::message($companyuuid, $messageuuid);
            if ($message->threaduuid !== $threaduuid || (int)$message->authorid !== $actor) {
                self::fail('permission');
            }
            if ($message->status !== 'active') { self::fail('state'); }
            $currenthash = hash('sha256', (string)$message->body . "\n" . (string)$message->status);
            if (!hash_equals($currenthash, $expectedhash)) { self::fail('conflict'); }
            if ($message->taskuuid !== '') { self::fail('linkedmessage'); }
            // rationale is an XMLDB TEXT column. The record_exists() conditions API
            // cannot compare TEXT values directly; use Moodle's cross-DB cast.
            // Preserve both company isolation and the immutable decision trace.
            $linkeddecision = $DB->record_exists_sql('SELECT 1
                    FROM {local_kbacompany_decision}
                    WHERE companyuuid = :decisioncompany
                      AND ' . $DB->sql_compare_text('rationale', 255) . ' = :decisiontrace', [
                'decisioncompany' => $companyuuid,
                'decisiontrace' => 'communication:' . $messageuuid,
            ]);
            if ($linkeddecision) { self::fail('linkedmessage'); }
            if ($operation === 'edit' && $newbody === (string)$message->body) {
                $tx->allow_commit();
                return;
            }
            $DB->insert_record('local_kbacompany_commrev', (object)[
                'companyuuid' => $companyuuid, 'messageuuid' => $messageuuid,
                'actorid' => $actor, 'action' => $operation,
                'oldbody' => (string)$message->body, 'timecreated' => time(),
            ]);
            if ($operation === 'edit') {
                $message->body = $newbody;
            } else {
                $message->status = 'withdrawn';
                $message->body = ''; // Only restricted revision audit retains the original.
                // Mentions of withdrawn content must not remain actionable.
                $DB->delete_records('local_kbacompany_commmention', [
                    'companyuuid' => $companyuuid, 'messageuuid' => $messageuuid]);
                $notifcriteria = ['companyuuid' => $companyuuid, 'messageuuid' => $messageuuid, 'isread' => 0];
                $DB->set_field('local_kbacompany_commnotif', 'timeread', time(), $notifcriteria);
                $DB->set_field('local_kbacompany_commnotif', 'isread', 1, $notifcriteria);
            }
            $DB->update_record('local_kbacompany_commmsg', $message);
            // A deterministic monotonic change token even for edits in the same second.
            $thread->timemodified = max(time(), (int)$thread->timemodified + 1);
            $DB->update_record('local_kbacompany_commthread', $thread);
            $tx->allow_commit();
        } finally {
            $lock->release();
        }
    }

    public static function mark_read(string $companyuuid, int $actor, string $threaduuid): void {
        global $DB;
        company_context_service::context($companyuuid, $actor);
        self::require_participant($companyuuid, $threaduuid, $actor);
        $lastid = (int)$DB->get_field_sql('SELECT MAX(id) FROM {local_kbacompany_commmsg} WHERE companyuuid = :c AND threaduuid = :t',
            ['c' => $companyuuid, 't' => $threaduuid]);
        $read = $DB->get_record('local_kbacompany_commread', [
            'companyuuid' => $companyuuid, 'threaduuid' => $threaduuid, 'userid' => $actor,
        ]);
        if ($read) {
            $read->lastmessageid = $lastid; $read->timeread = time(); $DB->update_record('local_kbacompany_commread', $read);
        }
    }

    public static function mark_notification_read(string $companyuuid, int $actor, string $notificationuuid): void {
        global $DB;
        company_context_service::context($companyuuid, $actor);
        $row = $DB->get_record('local_kbacompany_commnotif', [
            'companyuuid' => $companyuuid, 'uuid' => $notificationuuid, 'userid' => $actor,
        ]);
        if (!$row) { self::fail('reference'); }
        if (!empty($row->isread)) { return; } // A repeat does not change the original read timestamp.
        $row->isread = 1; $row->timeread = time(); $DB->update_record('local_kbacompany_commnotif', $row);
    }

    public static function add_attachment_reference(string $companyuuid, int $actor, string $messageuuid,
            string $kind, string $value, string $label = ''): string {
        global $DB;
        company_context_service::context($companyuuid, $actor);
        $message = self::message($companyuuid, $messageuuid);
        self::require_participant($companyuuid, (string)$message->threaduuid, $actor);
        if ((int)$message->authorid !== $actor || $message->status !== 'active') { self::fail('permission'); }
        if (!in_array($kind, self::ATTACHMENT_KINDS, true)) { self::fail('invalid'); }
        $value = trim($value); $label = trim($label);
        if ($value === '' || \core_text::strlen($value) > 4000 || \core_text::strlen($label) > 255) { self::fail('invalid'); }
        $uuid = self::uuid();
        $DB->insert_record('local_kbacompany_commattach', (object)[
            'companyuuid' => $companyuuid, 'uuid' => $uuid, 'messageuuid' => $messageuuid,
            'refkind' => $kind, 'refvalue' => $value, 'label' => $label, 'timecreated' => time(),
        ]);
        return $uuid;
    }

    /** Participant-scoped visibility check used by pluginfile for protected communication files. */
    public static function can_view_message(string $companyuuid, int $actor, string $messageuuid): bool {
        try {
            company_context_service::context($companyuuid, $actor);
            $message = self::message($companyuuid, $messageuuid);
            if (self::participant($companyuuid, (string)$message->threaduuid, $actor) === null) { return false; }
            $thread = self::thread($companyuuid, (string)$message->threaduuid);
            self::require_thread_context($companyuuid, $actor, $thread);
            return $message->status === 'active';
        } catch (\moodle_exception $e) {
            return false;
        }
    }

    /** Store one protected Moodle File API attachment owned by the communication message. */
    public static function add_uploaded_attachment(string $companyuuid, int $actor, string $messageuuid,
            array $upload): void {
        global $DB;
        company_context_service::context($companyuuid, $actor);
        $message = self::message($companyuuid, $messageuuid);
        self::require_participant($companyuuid, (string)$message->threaduuid, $actor);
        if ((int)$message->authorid !== $actor || $message->status !== 'active') { self::fail('permission'); }
        $error = (int)($upload['error'] ?? UPLOAD_ERR_NO_FILE);
        if ($error === UPLOAD_ERR_NO_FILE) { return; }
        if ($error !== UPLOAD_ERR_OK) { self::fail('upload'); }
        $tmp = (string)($upload['tmp_name'] ?? '');
        $name = clean_filename((string)($upload['name'] ?? ''));
        $size = (int)($upload['size'] ?? 0);
        if ($tmp === '' || $name === '' || $size < 0 || !is_uploaded_file($tmp)) { self::fail('upload'); }
        $maxbytes = get_max_upload_file_size();
        if ($maxbytes > 0 && $size > $maxbytes) { self::fail('filesize'); }
        $context = \context_system::instance();
        $fs = get_file_storage();
        $filename = $name;
        $pathinfo = pathinfo($name);
        $base = (string)($pathinfo['filename'] ?? 'file');
        $ext = isset($pathinfo['extension']) && $pathinfo['extension'] !== '' ? '.' . $pathinfo['extension'] : '';
        $n = 2;
        while ($fs->file_exists($context->id, 'local_kbacompany', 'communication_attachment', (int)$message->id, '/', $filename)) {
            $filename = $base . ' (' . $n . ')' . $ext; $n++;
        }
        $fs->create_file_from_pathname([
            'contextid' => $context->id, 'component' => 'local_kbacompany', 'filearea' => 'communication_attachment',
            'itemid' => (int)$message->id, 'filepath' => '/', 'filename' => $filename, 'userid' => $actor,
            'source' => $name, 'timecreated' => time(), 'timemodified' => time(),
        ], $tmp);
        try {
            self::add_attachment_reference($companyuuid, $actor, $messageuuid, 'moodlefile', $filename, $name);
        } catch (\Throwable $e) {
            $stored = $fs->get_file($context->id, 'local_kbacompany', 'communication_attachment', (int)$message->id, '/', $filename);
            if ($stored) { $stored->delete(); }
            throw $e;
        }
    }

    /** Propose one canonical Meeting Decision from a message in that meeting's discussion. */
    public static function create_decision_from_message(string $companyuuid, int $actor, string $messageuuid): string {
        global $DB;
        company_context_service::context($companyuuid, $actor);
        $message = self::message($companyuuid, $messageuuid);
        self::require_participant($companyuuid, (string)$message->threaduuid, $actor);
        if ($message->status !== 'active') { self::fail('state'); }
        $thread = self::thread($companyuuid, (string)$message->threaduuid);
        self::require_thread_context($companyuuid, $actor, $thread);
        if ($thread->threadtype !== 'object' || $thread->contextkind !== 'meeting' || $thread->contextuuid === '') {
            self::fail('decisioncontext');
        }
        $meeting = $DB->get_record('local_kbacompany_meeting', [
            'companyuuid' => $companyuuid, 'uuid' => (string)$thread->contextuuid,
        ], 'uuid,status,threaduuid');
        if (!$meeting || in_array((string)$meeting->status, ['closed', 'cancelled'], true)) { self::fail('state'); }
        $trace = 'communication:' . $messageuuid;
        // rationale is TEXT; equality through get_record() would throw a Moodle
        // DML exception on MySQL/MariaDB (same fix as the withdrawal guard).
        $existing = $DB->get_record_sql('SELECT uuid
                FROM {local_kbacompany_decision}
                WHERE companyuuid = :decisioncompany
                  AND meetinguuid = :decisionmeeting
                  AND ' . $DB->sql_compare_text('rationale', 255) . ' = :decisiontrace', [
            'decisioncompany' => $companyuuid,
            'decisionmeeting' => (string)$meeting->uuid,
            'decisiontrace' => $trace,
        ], IGNORE_MULTIPLE);
        if ($existing) { return (string)$existing->uuid; }
        $title = trim(preg_replace('/\s+/u', ' ', (string)$message->body));
        $title = \core_text::substr($title, 0, 120);
        return meeting_service::propose_decision($companyuuid, $actor, (string)$meeting->uuid, [
            'title' => $title, 'statement' => (string)$message->body, 'rationale' => $trace,
            'expectedresult' => '', 'agendauuid' => '', 'ownerid' => $actor,
        ]);
    }

    /** Convert a message into a traceable canonical BMS task without changing the message object. */
    public static function create_task_from_message(string $companyuuid, int $actor, string $messageuuid, array $taskdata): string {
        global $DB;
        company_context_service::context($companyuuid, $actor);
        $message = self::message($companyuuid, $messageuuid);
        self::require_participant($companyuuid, (string)$message->threaduuid, $actor);
        $sourcethread = self::thread($companyuuid, (string)$message->threaduuid);
        self::require_thread_context($companyuuid, $actor, $sourcethread);
        if ($message->status !== 'active') { self::fail('state'); }
        if ((string)$sourcethread->threadtype === 'object' &&
                in_array((string)$sourcethread->contextkind, task_service::CONTEXT_KINDS, true) &&
                trim((string)($taskdata['contextkind'] ?? '')) === '') {
            $taskdata['contextkind'] = (string)$sourcethread->contextkind;
            $taskdata['contextuuid'] = (string)$sourcethread->contextuuid;
            $taskdata['contextrevision'] = (int)$sourcethread->contextrevision;
        }

        // A message has one canonical task link. Lock the message conversion so retries or concurrent clicks
        // cannot create different tasks for different assignees from the same source message.
        $lock = \core\lock\lock_config::get_lock_factory('local_kbacompany')
            ->get_lock('communication-task:' . $companyuuid . ':' . $messageuuid, 10);
        if (!$lock) { self::fail('lock'); }
        try {
            $message = self::message($companyuuid, $messageuuid);
            if ($message->taskuuid !== '') {
                return (string)$message->taskuuid;
            }
            $taskuuid = task_service::create_from_communication($companyuuid, $actor, $messageuuid, $taskdata);
            $message->taskuuid = $taskuuid;
            $DB->update_record('local_kbacompany_commmsg', $message);
            $task = $DB->get_record('local_kbacompany_task',
                ['companyuuid' => $companyuuid, 'uuid' => $taskuuid], '*', MUST_EXIST);
            self::create_notification($companyuuid, (int)$task->assigneeid, 'task_created', (string)$message->threaduuid,
                $messageuuid, $taskuuid, (string)$task->title);
            return $taskuuid;
        } finally {
            $lock->release();
        }
    }

    private static function thread_dto(\stdClass $thread, int $actor, array $employees): array {
        global $DB;
        $participants = [];
        foreach ($DB->get_records('local_kbacompany_commpart', [
            'companyuuid' => $thread->companyuuid, 'threaduuid' => $thread->uuid, 'active' => 1,
        ], 'id ASC') as $part) {
            $participants[] = ['user_id' => (int)$part->userid, 'name' => $employees[(int)$part->userid] ?? '',
                'role' => (string)$part->role, 'muted' => !empty($part->muted)];
        }
        $read = $DB->get_record('local_kbacompany_commread', [
            'companyuuid' => $thread->companyuuid, 'threaduuid' => $thread->uuid, 'userid' => $actor,
        ]);
        $lastread = $read ? (int)$read->lastmessageid : 0;
        $params = ['c' => $thread->companyuuid, 't' => $thread->uuid, 'a' => $actor, 'last' => $lastread];
        $unread = $DB->count_records_sql('SELECT COUNT(1) FROM {local_kbacompany_commmsg}
            WHERE companyuuid = :c AND threaduuid = :t AND id > :last AND authorid <> :a AND status = \'active\'', $params);
        return [
            'uuid' => (string)$thread->uuid, 'thread_type' => (string)$thread->threadtype,
            'title' => (string)$thread->title, 'context_kind' => (string)$thread->contextkind,
            'context_uuid' => (string)$thread->contextuuid, 'context_revision' => (int)$thread->contextrevision,
            'created_by' => (int)$thread->createdby, 'created_by_name' => $employees[(int)$thread->createdby] ?? '',
            'status' => (string)$thread->status, 'last_message_at' => (int)$thread->lastmessageat,
            'unread_count' => (int)$unread, 'participants' => $participants,
            'time_created' => (int)$thread->timecreated, 'time_modified' => (int)$thread->timemodified,
        ];
    }

    /** Participant-scoped communication context. Managers do not receive private threads unless they participate. */
    public static function context(string $companyuuid, int $actor, string $selectedthread = '', string $scope = 'inbox',
            ?array $history = null): array {
        global $DB;
        $company = company_context_service::context($companyuuid, $actor);
        $employees = self::employees($companyuuid, $actor);
        $parts = $DB->get_records('local_kbacompany_commpart', [
            'companyuuid' => $companyuuid, 'userid' => $actor, 'active' => 1,
        ]);
        $threads = [];
        $visiblethreaduuids = [];
        $visibleunreadthreads = 0;
        foreach ($parts as $part) {
            $thread = $DB->get_record('local_kbacompany_commthread', [
                'companyuuid' => $companyuuid, 'uuid' => $part->threaduuid,
            ]);
            if (!$thread) { continue; }
            $descriptor = null;
            if ((string)$thread->threadtype === 'object') {
                try {
                    $descriptor = self::context_descriptor($companyuuid, $actor, $thread);
                } catch (\moodle_exception $e) {
                    continue;
                }
            }
            $dto = self::thread_dto($thread, $actor, $employees);
            $dto['context'] = $descriptor;
            $visiblethreaduuids[] = (string)$thread->uuid;
            if ($dto['unread_count'] > 0) { $visibleunreadthreads++; }
            if ($scope === 'unread' && $dto['unread_count'] < 1) { continue; }
            if ($scope === 'mentions') {
                $count = $DB->count_records_sql('SELECT COUNT(1) FROM {local_kbacompany_commmention} m
                    JOIN {local_kbacompany_commmsg} msg ON msg.uuid = m.messageuuid AND msg.companyuuid = m.companyuuid
                   WHERE m.companyuuid = :c AND m.threaduuid = :t AND m.userid = :u AND msg.status = \'active\'',
                    ['c' => $companyuuid, 't' => $thread->uuid, 'u' => $actor]);
                if (!$count) { continue; }
            } else if (!in_array($scope, ['inbox', 'unread', 'all'], true)) { $scope = 'inbox'; }
            $threads[] = $dto;
        }
        usort($threads, static function(array $a, array $b): int { return ($b['last_message_at'] <=> $a['last_message_at']) ?: ($b['time_created'] <=> $a['time_created']); });

        $selected = null; $messages = []; $historymeta = null;
        if ($selectedthread !== '') {
            self::require_participant($companyuuid, $selectedthread, $actor);
            $thread = self::thread($companyuuid, $selectedthread);
            $descriptor = self::require_thread_context($companyuuid, $actor, $thread);
            $selected = self::thread_dto($thread, $actor, $employees);
            $selected['context'] = $descriptor;
            // Optional bounded history projection for Communication Center only; legacy clients unchanged.
            $historymeta = null;
            if ($history !== null) {
                $needle = trim(\core_text::substr((string)($history['search'] ?? ''), 0, 200));
                $requestedpage = max(0, min(100000, (int)($history['page'] ?? 0)));
                $perpage = 30;
                $where = "companyuuid = :hc AND threaduuid = :ht";
                $params = ['hc' => $companyuuid, 'ht' => $selectedthread];
                if ($needle !== '') {
                    $where .= " AND status = :hs AND " . $DB->sql_like('body', ':hq', false);
                    $params['hs'] = 'active';
                    $params['hq'] = '%' . $DB->sql_like_escape($needle) . '%';
                }
                $total = (int)$DB->count_records_sql(
                    "SELECT COUNT(id) FROM {local_kbacompany_commmsg} WHERE $where", $params);
                $pages = max(1, (int)ceil($total / $perpage));
                $page = min($requestedpage, $pages - 1);
                // Page 0 = most recent messages. Reverse only the bounded page for chronological display.
                $records = $DB->get_records_sql("SELECT * FROM {local_kbacompany_commmsg} WHERE $where ORDER BY id DESC",
                    $params, $page * $perpage, $perpage);
                $records = array_reverse(array_values($records));
                $historymeta = ['total' => $total, 'page' => $page, 'pages' => $pages, 'perpage' => $perpage];
            } else {
                $records = $DB->get_records('local_kbacompany_commmsg', [
                    'companyuuid' => $companyuuid, 'threaduuid' => $selectedthread,
                ], 'id ASC');
            }
            $editeduuids = [];
            $recorduuids = array_map(static function($row): string { return (string)$row->uuid; },
                array_values($records));
            if ($recorduuids) {
                list($insql, $inparams) = $DB->get_in_or_equal($recorduuids, SQL_PARAMS_NAMED, 'msgrev');
                foreach ($DB->get_fieldset_sql('SELECT DISTINCT messageuuid
                    FROM {local_kbacompany_commrev}
                    WHERE companyuuid = :revcompany AND action = :revaction AND messageuuid ' . $insql,
                    ['revcompany' => $companyuuid, 'revaction' => 'edit'] + $inparams) as $editeduuid) {
                    $editeduuids[(string)$editeduuid] = true;
                }
            }
            foreach ($records as $message) {
                $mentions = array_map(static function($m) { return (int)$m->userid; }, array_values($DB->get_records(
                    'local_kbacompany_commmention', ['companyuuid' => $companyuuid, 'messageuuid' => $message->uuid])));
                $attachments = [];
                foreach ($message->status === 'active' ? $DB->get_records('local_kbacompany_commattach', [
                    'companyuuid' => $companyuuid, 'messageuuid' => $message->uuid,
                ], 'id ASC') : [] as $attachment) {
                    $attachmentdto = ['uuid' => (string)$attachment->uuid, 'kind' => (string)$attachment->refkind,
                        'value' => (string)$attachment->refvalue, 'label' => (string)$attachment->label, 'url' => ''];
                    if ((string)$attachment->refkind === 'moodlefile') {
                        $attachmentdto['url'] = \moodle_url::make_pluginfile_url(\context_system::instance()->id,
                            'local_kbacompany', 'communication_attachment', (int)$message->id, '/',
                            (string)$attachment->refvalue, true)->out(false);
                    } else if ((string)$attachment->refkind === 'url') {
                        $attachmentdto['url'] = (string)$attachment->refvalue;
                    }
                    $attachments[] = $attachmentdto;
                }
                $messages[] = [
                    'uuid' => (string)$message->uuid, 'author_id' => (int)$message->authorid,
                    'author_name' => $employees[(int)$message->authorid] ?? '', 'body' => (string)$message->body,
                    'reply_to_uuid' => (string)$message->replytouuid, 'task_uuid' => (string)$message->taskuuid,
                    'status' => (string)$message->status, 'mentions' => $mentions, 'attachments' => $attachments,
                    'time_created' => (int)$message->timecreated,
                    'edited' => isset($editeduuids[(string)$message->uuid]),
                ];
            }
            self::mark_read($companyuuid, $actor, $selectedthread);
        }
        $notifications = [];
        foreach ($DB->get_records('local_kbacompany_commnotif', [
            'companyuuid' => $companyuuid, 'userid' => $actor,
        ], 'id DESC', '*', 0, 30) as $notification) {
            $notificationthread = (string)$notification->threaduuid;
            $notificationtitle = (string)$notification->title;
            if ($notificationthread !== '') {
                $thread = $DB->get_record('local_kbacompany_commthread', [
                    'companyuuid' => $companyuuid, 'uuid' => $notificationthread,
                ]);
                $allowed = $thread && self::participant($companyuuid, $notificationthread, $actor);
                if ($allowed && (string)$thread->threadtype === 'object') {
                    try { self::context_descriptor($companyuuid, $actor, $thread); }
                    catch (\moodle_exception $e) { $allowed = false; }
                }
                if (!$allowed) {
                    // Do not reveal even the existence of a notification whose source object is no longer visible.
                    continue;
                }
            }
            $notifications[] = ['uuid' => (string)$notification->uuid, 'kind' => (string)$notification->kind,
                'thread_uuid' => $notificationthread, 'message_uuid' => (string)$notification->messageuuid,
                'task_uuid' => (string)$notification->taskuuid, 'title' => $notificationtitle,
                'is_read' => !empty($notification->isread), 'time_created' => (int)$notification->timecreated];
        }
        $counts = ['unread_threads' => $visibleunreadthreads, 'unread_notifications' => 0, 'mentions' => 0];
        foreach ($DB->get_records('local_kbacompany_commnotif', [
            'companyuuid' => $companyuuid, 'userid' => $actor, 'isread' => 0,
        ]) as $unreadnotification) {
            $sourceuuid = (string)$unreadnotification->threaduuid;
            if ($sourceuuid === '') { $counts['unread_notifications']++; continue; }
            $sourcethread = $DB->get_record('local_kbacompany_commthread', [
                'companyuuid' => $companyuuid, 'uuid' => $sourceuuid,
            ]);
            $allowed = $sourcethread && self::participant($companyuuid, $sourceuuid, $actor);
            if ($allowed && (string)$sourcethread->threadtype === 'object') {
                try { self::context_descriptor($companyuuid, $actor, $sourcethread); }
                catch (\moodle_exception $e) { $allowed = false; }
            }
            if ($allowed) { $counts['unread_notifications']++; }
        }
        foreach ($visiblethreaduuids as $visiblethreaduuid) {
            $counts['mentions'] += (int)$DB->count_records_sql('SELECT COUNT(1)
                  FROM {local_kbacompany_commmention} mn
                  JOIN {local_kbacompany_commmsg} msg
                    ON msg.companyuuid=mn.companyuuid AND msg.uuid=mn.messageuuid AND msg.status=\'active\'
                 WHERE mn.companyuuid=:mc AND mn.threaduuid=:mt AND mn.userid=:mu',
                ['mc'=>$companyuuid,'mt'=>$visiblethreaduuid,'mu'=>$actor]);
        }
        return ['company' => $company, 'scope' => $scope, 'threads' => $threads, 'selected' => $selected,
            'messages' => $messages, 'history' => $historymeta, 'notifications' => $notifications, 'counts' => $counts, 'employees' => $employees,
            'can_manage' => permission_service::can_manage_communications($companyuuid, $actor)];
    }
}
