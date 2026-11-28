<?php
namespace local_kbacompany\local;

defined('MOODLE_INTERNAL') || die();

/**
 * RC33 fake-provider MAX → corporate feed, first slice (owner D1–D4 24.11.2026; ADR-0012).
 *
 * One company service_integration principal imports plain text for a verified human author into one fixed-audience
 * group thread. The principal is the transport account, never the author: the canonical communication_service is
 * called with the mapped human actor, and the audit keeps both (dual-actor). Disabled by default
 * (config inbound_max_enabled plus the disabled restricted service); no MAX network, token or files.
 *
 * Lock order: inbsrc:{source key} → communication_write_gate (company) → canonical writers. Every revocation writer
 * (ADR-0006 membership, participants, connection/binding/sender registry) takes the same company gate, so a
 * publication commits either before a revocation or not at all.
 */
final class max_inbound_service {
    public const SCHEMA = 'rc33.inbound/1';
    public const PROVIDER = 'max';
    public const CHANNEL = 'max';
    public const CONFIG = 'inbound_max_enabled';
    public const EVENT = 'communication.message.created';
    public const MAX_TEXT = 20000;
    /** Project policy, not a MAX guarantee (contract §5, D8 still open): new events older than 24 h are held. */
    public const MAX_AGE = 86400;
    /** Future skew allowance in seconds. */
    public const MAX_SKEW = 300;
    public const REVIEW_REASONS = ['binding_stale', 'source_held', 'unsupported_chat', 'sender_bot',
        'unsupported_attachment', 'reply_held', 'forward_held', 'age_window', 'before_effective',
        'sender_unverified', 'consent_required', 'author_unavailable', 'thread_unavailable'];

    private const MESSAGE_FIELDS = ['schema_version', 'connection_uuid', 'binding_uuid', 'binding_revision',
        'company_uuid', 'thread_uuid', 'event_type', 'chat_type', 'sender_is_bot', 'link_type', 'external_chat_id',
        'external_sender_id', 'external_message_id', 'source_timestamp_ms', 'text', 'attachments',
        'reply_to_external_message_id'];
    private const CONTROL_FIELDS = ['schema_version', 'connection_uuid', 'binding_uuid', 'binding_revision',
        'external_chat_id', 'external_message_id', 'event_type', 'source_timestamp_ms'];
    private const LOCK_TIMEOUT = 10;

    /** @var string|null PHPUnit-only fault stage (see set_test_fault). */
    private static $fault = null;

    public static function enabled(): bool {
        return (bool)get_config('local_kbacompany', self::CONFIG);
    }

    /**
     * PHPUnit rollback seam (N13/N29): makes the named stage throw. Refused outside PHPUnit.
     * Stages: after_message, after_audit, after_outbox, deny_audit.
     */
    public static function set_test_fault(?string $stage): void {
        if (!defined('PHPUNIT_TEST') || !PHPUNIT_TEST) {
            throw new \coding_exception('Inbound fault injection is available only in PHPUnit');
        }
        self::$fault = $stage;
    }

    private static function fault(string $stage): void {
        if (self::$fault === $stage) {
            throw new \dml_write_exception('Injected inbound fault: ' . $stage);
        }
    }

    /** Import one message_created event. Returns the safe response contract of §5. */
    public static function receive_message(int $principal, string $payload): array {
        try {
            $dto = self::validate_message(inbound_json::decode($payload));
        } catch (inbound_exception $e) {
            self::deny($principal, null, 'message', 'payload_invalid');
            throw $e;
        }
        $conn = self::connection($principal, $dto['connection_uuid'], 'message');
        $binding = self::binding($conn, $dto, 'message');
        if ($dto['company_uuid'] !== (string)$conn->companyuuid || $dto['thread_uuid'] !== (string)$binding->threaduuid) {
            self::deny($principal, $conn, 'message', 'scope_denied');
            throw new inbound_exception('scope_denied');
        }
        $sourcekey = self::source_key((string)$conn->namespaceuuid, $dto['external_chat_id'], $dto['external_message_id']);
        $fingerprint = self::fingerprint($dto);
        $srclock = self::source_lock($sourcekey, $principal, $conn);
        try {
            $gate = communication_write_gate::acquire((string)$conn->companyuuid);
            try {
                // Re-read under the gate: connection, binding and sender writers serialize on it.
                $conn = self::connection($principal, $dto['connection_uuid'], 'message');
                $binding = self::binding($conn, $dto, 'message');
                $existing = self::receipt($sourcekey);
                if ($existing) {
                    return self::replay($existing, $conn, $principal, $fingerprint);
                }
                $sender = null;
                $author = 0;
                $reason = self::decide($conn, $binding, $dto, $sourcekey, $sender, $author);
                if ($reason !== '') {
                    return self::review($conn, $binding, $dto, $sourcekey, $fingerprint, $principal, $sender, $author,
                        $reason);
                }
                return self::publish($conn, $binding, $dto, $sourcekey, $fingerprint, $principal, $sender, $author);
            } finally {
                $gate->release();
            }
        } finally {
            $srclock->release();
        }
    }

    /**
     * Record message_edited / message_removed as a hold (tombstone) for the source event (contract §7). Never edits
     * or removes a published message: a control on a published message only marks it for review.
     */
    public static function receive_control(int $principal, string $payload): array {
        global $DB;
        try {
            $dto = self::validate_control(inbound_json::decode($payload));
        } catch (inbound_exception $e) {
            self::deny($principal, null, 'control', 'payload_invalid');
            throw $e;
        }
        $conn = self::connection($principal, $dto['connection_uuid'], 'control');
        // Control stays possible for the pinned source after a sender or binding revocation (it only blocks).
        $binding = $DB->get_record('local_kbacompany_inbgroup', ['uuid' => $dto['binding_uuid'],
            'connectionuuid' => (string)$conn->uuid, 'externalchatid' => $dto['external_chat_id']]);
        if (!$binding) {
            self::deny($principal, $conn, 'control', 'scope_denied');
            throw new inbound_exception('scope_denied');
        }
        $sourcekey = self::source_key((string)$conn->namespaceuuid, $dto['external_chat_id'], $dto['external_message_id']);
        $srclock = self::source_lock($sourcekey, $principal, $conn);
        try {
            $gate = communication_write_gate::acquire((string)$conn->companyuuid);
            try {
                $now = time();
                $removed = $dto['event_type'] === 'message_removed';
                $stale = (int)$binding->revision !== $dto['binding_revision'] || (string)$binding->status !== 'active';
                $correlation = self::uuid();
                $tx = $DB->start_delegated_transaction();
                $hold = $DB->get_record('local_kbacompany_inbhold', ['sourcekey' => $sourcekey]);
                if ($hold) {
                    $hold->edited = $hold->edited || !$removed ? 1 : 0;
                    $hold->removed = $hold->removed || $removed ? 1 : 0;
                    $hold->eventcount = (int)$hold->eventcount + 1;
                    $hold->timemodified = $now;
                    $DB->update_record('local_kbacompany_inbhold', $hold);
                } else {
                    $DB->insert_record('local_kbacompany_inbhold', (object)[
                        'sourcekey' => $sourcekey, 'companyuuid' => (string)$conn->companyuuid,
                        'connectionuuid' => (string)$conn->uuid, 'bindinguuid' => (string)$binding->uuid,
                        'edited' => $removed ? 0 : 1, 'removed' => $removed ? 1 : 0, 'eventcount' => 1,
                        'timecreated' => $now, 'timemodified' => $now,
                    ]);
                }
                $receipt = self::receipt($sourcekey);
                $outcome = $receipt && (string)$receipt->outcome === 'created' ? 'review_required' : 'held';
                self::audit((string)$conn->companyuuid, (string)$conn->uuid, $principal, 0, 'control', $outcome,
                    $stale ? 'binding_stale' : ($removed ? 'removed' : 'edited'),
                    $receipt ? (string)$receipt->uuid : '', '', $correlation, (int)$binding->revision, '');
                $tx->allow_commit();
            } catch (\Throwable $e) {
                if (isset($tx)) { self::rollback($tx, $e); }
                if ($e instanceof inbound_exception) { throw $e; }
                throw new inbound_exception('retryable_unavailable');
            } finally {
                $gate->release();
            }
        } finally {
            $srclock->release();
        }
        return ['schema_version' => self::SCHEMA, 'outcome' => $outcome, 'correlation_id' => $correlation];
    }

    /** Length-prefixed (provider, namespace, chat, mid) + operation; never connection, revision or retry data. */
    public static function source_key(string $namespaceuuid, string $chatid, string $messageid): string {
        $tuple = '';
        foreach ([self::PROVIDER, $namespaceuuid, $chatid, $messageid] as $part) {
            $tuple .= strlen($part) . ':' . $part . ';';
        }
        return hash('sha256', $tuple . 'create');
    }

    /** SHA-256 of the canonical semantic DTO, including the security flags (contract §6.2). */
    public static function fingerprint(array $dto): string {
        $canonical = [
            'event_type' => $dto['event_type'], 'chat_type' => $dto['chat_type'],
            'sender_is_bot' => $dto['sender_is_bot'], 'link_type' => $dto['link_type'],
            'external_sender_id' => $dto['external_sender_id'], 'source_timestamp_ms' => $dto['source_timestamp_ms'],
            'text' => $dto['text'], 'reply_to_external_message_id' => $dto['reply_to_external_message_id'],
            'attachments' => $dto['attachments'],
        ];
        $json = json_encode($canonical, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION);
        if ($json === false) {
            throw new inbound_exception('payload_invalid', 'fingerprint');
        }
        return hash('sha256', $json);
    }

    private static function validate_message(array $value): array {
        self::require_fields($value, self::MESSAGE_FIELDS);
        foreach (['connection_uuid', 'binding_uuid', 'company_uuid', 'thread_uuid'] as $field) {
            self::require_uuid($value[$field]);
        }
        self::require_common($value);
        if ($value['event_type'] !== 'message_created' ||
                !in_array($value['chat_type'], ['chat', 'channel', 'dialog'], true) ||
                !is_bool($value['sender_is_bot']) ||
                !in_array($value['link_type'], ['none', 'reply', 'forward'], true) ||
                !is_string($value['text']) || !is_array($value['attachments']) ||
                ($value['attachments'] && array_keys($value['attachments']) !== range(0, count($value['attachments']) - 1)) ||
                !($value['reply_to_external_message_id'] === null || is_string($value['reply_to_external_message_id']))) {
            throw new inbound_exception('payload_invalid', 'field');
        }
        self::require_external_id($value['external_sender_id']);
        if ($value['reply_to_external_message_id'] !== null) {
            self::require_message_id($value['reply_to_external_message_id']);
        }
        // Plain text only; never interpreted as HTML (escaped on output by the canonical renderers).
        $text = trim($value['text']);
        if ($text === '' || \core_text::strlen($text) > self::MAX_TEXT) {
            throw new inbound_exception('payload_invalid', 'text');
        }
        $value['text'] = $text;
        return $value;
    }

    private static function validate_control(array $value): array {
        self::require_fields($value, self::CONTROL_FIELDS);
        self::require_uuid($value['connection_uuid']);
        self::require_uuid($value['binding_uuid']);
        self::require_common($value);
        if (!in_array($value['event_type'], ['message_edited', 'message_removed'], true)) {
            throw new inbound_exception('payload_invalid', 'event');
        }
        return $value;
    }

    private static function require_fields(array $value, array $fields): void {
        $keys = array_keys($value);
        sort($keys);
        $expected = $fields;
        sort($expected);
        if ($keys !== $expected) {
            throw new inbound_exception('payload_invalid', 'fields');
        }
    }

    private static function require_common(array $value): void {
        if ($value['schema_version'] !== self::SCHEMA || !is_int($value['binding_revision']) ||
                $value['binding_revision'] < 1 || !is_int($value['source_timestamp_ms']) ||
                $value['source_timestamp_ms'] <= 0 || $value['source_timestamp_ms'] >= 100000000000000) {
            throw new inbound_exception('payload_invalid', 'common');
        }
        self::require_external_id($value['external_chat_id']);
        self::require_message_id($value['external_message_id']);
    }

    private static function require_uuid($value): void {
        if (!is_string($value) || !preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/D', $value)) {
            throw new inbound_exception('payload_invalid', 'uuid');
        }
    }

    /** Canonical signed decimal string within int64, no float round-trip (contract §3). */
    private static function require_external_id($value): void {
        if (!is_string($value) || !preg_match('/^-?(?:0|[1-9][0-9]{0,18})$/D', $value) || $value === '-0') {
            throw new inbound_exception('payload_invalid', 'external id');
        }
        $digits = ltrim($value, '-');
        $limit = $value[0] === '-' ? '9223372036854775808' : '9223372036854775807';
        if (strlen($digits) === 19 && strcmp($digits, $limit) > 0) {
            throw new inbound_exception('payload_invalid', 'external id range');
        }
    }

    /** Opaque, case-sensitive, not trimmed; printable without control characters. */
    private static function require_message_id($value): void {
        if (!is_string($value) || $value === '' || strlen($value) > 128 || preg_match('/[\x00-\x20\x7f]/', $value)) {
            throw new inbound_exception('payload_invalid', 'message id');
        }
    }

    /** The authenticated principal resolves its own connection; the DTO value is only an assertion. */
    private static function connection(int $principal, string $connectionuuid, string $action): \stdClass {
        global $DB;
        if (!self::enabled()) {
            self::deny($principal, null, $action, 'retryable_unavailable', 'disabled');
            throw new inbound_exception('retryable_unavailable', 'disabled');
        }
        $conns = $principal > 0 ? $DB->get_records('local_kbacompany_inbconn',
            ['principaluserid' => $principal, 'provider' => self::PROVIDER, 'status' => 'enabled']) : [];
        $live = $principal > 0 && $DB->record_exists('user', ['id' => $principal, 'deleted' => 0, 'suspended' => 0]);
        if (count($conns) !== 1 || !$live || is_siteadmin($principal) ||
                !has_capability('local/kbacompany:importinbound', \context_system::instance(), $principal)) {
            self::deny($principal, null, $action, 'unauthenticated');
            throw new inbound_exception('unauthenticated');
        }
        $conn = reset($conns);
        if (!hash_equals((string)$conn->uuid, $connectionuuid)) {
            self::deny($principal, $conn, $action, 'scope_denied');
            throw new inbound_exception('scope_denied');
        }
        return $conn;
    }

    /** A binding of another connection or company is indistinguishable from a missing one (N03/N04). */
    private static function binding(\stdClass $conn, array $dto, string $action): \stdClass {
        global $DB;
        $binding = $DB->get_record('local_kbacompany_inbgroup', ['uuid' => $dto['binding_uuid'],
            'connectionuuid' => (string)$conn->uuid, 'companyuuid' => (string)$conn->companyuuid]);
        if (!$binding || (string)$binding->externalchatid !== $dto['external_chat_id']) {
            self::deny((int)$conn->principaluserid, $conn, $action, 'scope_denied');
            throw new inbound_exception('scope_denied');
        }
        return $binding;
    }

    private static function receipt(string $sourcekey): ?\stdClass {
        global $DB;
        return $DB->get_record('local_kbacompany_inbreceipt', ['sourcekey' => $sourcekey]) ?: null;
    }

    private static function source_lock(string $sourcekey, int $principal, \stdClass $conn): \core\lock\lock {
        $lock = \core\lock\lock_config::get_lock_factory('local_kbacompany')
            ->get_lock('inbsrc:' . $sourcekey, self::LOCK_TIMEOUT);
        if (!$lock) {
            self::deny($principal, $conn, 'lock', 'retryable_unavailable', 'lock');
            throw new inbound_exception('retryable_unavailable', 'lock');
        }
        return $lock;
    }

    /** Committed receipt: same result, no second send/publish; changed content is a conflict (N10/N11). */
    private static function replay(\stdClass $receipt, \stdClass $conn, int $principal, string $fingerprint): array {
        if ((string)$receipt->companyuuid !== (string)$conn->companyuuid) {
            self::deny($principal, $conn, 'message', 'scope_denied');
            throw new inbound_exception('scope_denied');
        }
        if (!hash_equals((string)$receipt->fingerprint, $fingerprint)) {
            self::deny($principal, $conn, 'message', 'idempotency_conflict', '', (string)$receipt->uuid, $fingerprint);
            throw new inbound_exception('idempotency_conflict');
        }
        self::deny($principal, $conn, 'replay', (string)$receipt->outcome, (string)$receipt->reason,
            (string)$receipt->uuid);
        return self::response($receipt, true);
    }

    /** Every check repeats on each attempt, including retries; fails closed. Returns '' or a review reason. */
    private static function decide(\stdClass $conn, \stdClass $binding, array $dto, string $sourcekey,
            ?\stdClass &$sender, int &$author): string {
        global $DB;
        if ((string)$binding->status !== 'active' || (int)$binding->revision !== $dto['binding_revision']) {
            return 'binding_stale';
        }
        if ($DB->record_exists('local_kbacompany_inbhold', ['sourcekey' => $sourcekey])) {
            return 'source_held';
        }
        if ($dto['chat_type'] !== 'chat') { return 'unsupported_chat'; }
        if ($dto['sender_is_bot']) { return 'sender_bot'; }
        if ($dto['attachments']) { return 'unsupported_attachment'; }
        if ($dto['link_type'] === 'forward') { return 'forward_held'; }
        if ($dto['link_type'] === 'reply' || $dto['reply_to_external_message_id'] !== null) { return 'reply_held'; }
        $now = time();
        $sourcetime = intdiv($dto['source_timestamp_ms'], 1000);
        if ($sourcetime < $now - self::MAX_AGE || $sourcetime > $now + self::MAX_SKEW) { return 'age_window'; }
        if ($sourcetime < (int)$binding->effectivefrom) { return 'before_effective'; }
        $sender = $DB->get_record('local_kbacompany_inbsender', ['connectionuuid' => (string)$conn->uuid,
            'externalsenderid' => $dto['external_sender_id']]) ?: null;
        if (!$sender || (string)$sender->companyuuid !== (string)$conn->companyuuid || (int)$sender->revokedat > 0 ||
                ((int)$sender->expiresat > 0 && (int)$sender->expiresat <= $now)) {
            return 'sender_unverified';
        }
        $author = (int)$sender->userid;
        if ((string)$sender->bindinguuid !== (string)$binding->uuid ||
                (string)$sender->consentversion !== (string)$binding->policyversion ||
                $sourcetime < (int)$sender->effectivefrom || $now < (int)$sender->effectivefrom) {
            return 'consent_required';
        }
        if (!$DB->record_exists('user', ['id' => $author, 'deleted' => 0, 'suspended' => 0])) {
            return 'author_unavailable';
        }
        try {
            company_context_service::context((string)$conn->companyuuid, $author);
        } catch (\moodle_exception $e) {
            return 'author_unavailable';
        }
        if ((string)$sender->membershipuuid !== '' && !$DB->record_exists('local_kbacompany_member',
                ['uuid' => (string)$sender->membershipuuid, 'companyuuid' => (string)$conn->companyuuid,
                 'userid' => $author, 'active' => 1])) {
            return 'author_unavailable';
        }
        $thread = $DB->get_record('local_kbacompany_commthread', ['companyuuid' => (string)$conn->companyuuid,
            'uuid' => (string)$binding->threaduuid]);
        if (!$thread || (string)$thread->status !== 'active' || (string)$thread->threadtype !== 'group' ||
                !$DB->record_exists('local_kbacompany_commpart', ['companyuuid' => (string)$conn->companyuuid,
                    'threaduuid' => (string)$binding->threaduuid, 'userid' => $author, 'active' => 1])) {
            return 'thread_unavailable';
        }
        return '';
    }

    /** Durable review decision without content (no body stored); never a publication. */
    private static function review(\stdClass $conn, \stdClass $binding, array $dto, string $sourcekey,
            string $fingerprint, int $principal, ?\stdClass $sender, int $author, string $reason): array {
        global $DB;
        if (!in_array($reason, self::REVIEW_REASONS, true)) {
            throw new \coding_exception('Unknown inbound review reason');
        }
        try {
            $tx = $DB->start_delegated_transaction();
            $receipt = self::insert_receipt($conn, $binding, $dto, $sourcekey, $fingerprint, $principal, $sender,
                'review_required', $reason);
            self::audit((string)$conn->companyuuid, (string)$conn->uuid, $principal, $author, 'review',
                'review_required', $reason, (string)$receipt->uuid, '', (string)$receipt->correlationid,
                (int)$binding->revision, $fingerprint);
            $tx->allow_commit();
        } catch (\Throwable $e) {
            if (isset($tx)) { self::rollback($tx, $e); }
            throw new inbound_exception('retryable_unavailable', 'review');
        }
        return self::response($receipt, false);
    }

    /**
     * One delegated transaction: receipt reservation, canonical send_message by the human author, provenance,
     * dual-actor audit and the neutral outbox event. Any failure rolls all of it back (N13).
     */
    private static function publish(\stdClass $conn, \stdClass $binding, array $dto, string $sourcekey,
            string $fingerprint, int $principal, \stdClass $sender, int $author): array {
        global $DB;
        try {
            $tx = $DB->start_delegated_transaction();
            $receipt = self::insert_receipt($conn, $binding, $dto, $sourcekey, $fingerprint, $principal, $sender,
                'created', '');
            // Imported text never becomes a Moodle mention (contract §5): inline mentions stay literal.
            $messageuuid = communication_service::send_message((string)$conn->companyuuid, $author,
                (string)$binding->threaduuid, $dto['text'], [], '', false);
            self::fault('after_message');
            $receipt->messageuuid = $messageuuid;
            $receipt->timecommitted = time();
            $DB->update_record('local_kbacompany_inbreceipt', $receipt);
            self::audit((string)$conn->companyuuid, (string)$conn->uuid, $principal, $author, 'create', 'created', '',
                (string)$receipt->uuid, $messageuuid, (string)$receipt->correlationid, (int)$binding->revision,
                $fingerprint);
            self::fault('after_audit');
            integration_event_service::publish(self::EVENT, (string)$conn->companyuuid, 'communication_message',
                $messageuuid, 1, ['change' => 'created', 'thread_uuid' => (string)$binding->threaduuid],
                (string)$receipt->correlationid, (int)$receipt->timecommitted);
            self::fault('after_outbox');
            $tx->allow_commit();
        } catch (\Throwable $e) {
            if (isset($tx)) { self::rollback($tx, $e); }
            $committed = self::receipt($sourcekey);
            if ($committed) {
                return self::replay($committed, $conn, $principal, $fingerprint);
            }
            self::deny($principal, $conn, 'message', 'retryable_unavailable', 'publish_failed', '', $fingerprint);
            throw new inbound_exception('retryable_unavailable', 'publish');
        }
        return self::response($receipt, false);
    }

    private static function insert_receipt(\stdClass $conn, \stdClass $binding, array $dto, string $sourcekey,
            string $fingerprint, int $principal, ?\stdClass $sender, string $outcome, string $reason): \stdClass {
        global $DB;
        $receipt = (object)[
            'uuid' => self::uuid(), 'sourcekey' => $sourcekey, 'companyuuid' => (string)$conn->companyuuid,
            'connectionuuid' => (string)$conn->uuid, 'namespaceuuid' => (string)$conn->namespaceuuid,
            'bindinguuid' => (string)$binding->uuid, 'bindingrevision' => (int)$binding->revision,
            'senderbindinguuid' => $sender ? (string)$sender->uuid : '', 'principaluserid' => $principal,
            'fingerprint' => $fingerprint, 'externalchatid' => $dto['external_chat_id'],
            'externalmessageid' => $dto['external_message_id'], 'messageuuid' => '', 'outcome' => $outcome,
            'reason' => $reason, 'correlationid' => self::uuid(), 'sourcetimems' => $dto['source_timestamp_ms'],
            'timereceived' => time(), 'timecommitted' => $outcome === 'created' ? 0 : time(),
        ];
        $receipt->id = $DB->insert_record('local_kbacompany_inbreceipt', $receipt);
        return $receipt;
    }

    /** No body, file URL, user ID or external ID in the response. */
    private static function response(\stdClass $receipt, bool $replay): array {
        $created = (string)$receipt->outcome === 'created';
        $result = ['schema_version' => self::SCHEMA,
            'outcome' => $created ? ($replay ? 'duplicate' : 'created') : 'review_required',
            'receipt_uuid' => (string)$receipt->uuid, 'correlation_id' => (string)$receipt->correlationid];
        if ($created) {
            $result['message_uuid'] = (string)$receipt->messageuuid;
        } else {
            $result['reason'] = (string)$receipt->reason;
        }
        return $result;
    }

    /**
     * Minimal durable refusal/access audit outside any business transaction. If even this cannot be written the
     * caller gets a retryable failure, never a terminal success or review.
     */
    private static function deny(int $principal, ?\stdClass $conn, string $action, string $outcome,
            string $reason = '', string $receiptuuid = '', string $fingerprint = ''): void {
        try {
            self::fault('deny_audit');
            self::audit($conn ? (string)$conn->companyuuid : '', $conn ? (string)$conn->uuid : '', $principal, 0,
                $action === 'replay' ? 'replay' : 'deny', $outcome, $reason, $receiptuuid, '', '', 0, $fingerprint);
        } catch (\Throwable $e) {
            throw new inbound_exception('retryable_unavailable', 'audit');
        }
    }

    private static function audit(string $companyuuid, string $connectionuuid, int $principal, int $actor,
            string $action, string $outcome, string $reason, string $receiptuuid, string $objectuuid,
            string $correlation, int $revision, string $fingerprint): void {
        global $DB;
        $DB->insert_record('local_kbacompany_inbaudit', (object)[
            'uuid' => self::uuid(), 'companyuuid' => $companyuuid, 'connectionuuid' => $connectionuuid,
            'channel' => self::CHANNEL, 'principaluserid' => $principal, 'actoruserid' => $actor,
            'action' => $action, 'outcome' => $outcome, 'reason' => $reason, 'receiptuuid' => $receiptuuid,
            'objectuuid' => $objectuuid, 'correlationid' => $correlation, 'bindingrevision' => $revision,
            'fingerprint' => $fingerprint, 'timecreated' => time(),
        ]);
    }

    private static function rollback(\moodle_transaction $tx, \Throwable $e): void {
        try {
            $tx->rollback($e);
        } catch (\Throwable $ignored) {
            // moodle_transaction::rollback() rethrows the cause after rolling back; the caller maps it.
        }
    }

    public static function uuid(): string {
        $bytes = random_bytes(16);
        $bytes[6] = chr((ord($bytes[6]) & 0x0f) | 0x40);
        $bytes[8] = chr((ord($bytes[8]) & 0x3f) | 0x80);
        $hex = bin2hex($bytes);
        return substr($hex, 0, 8) . '-' . substr($hex, 8, 4) . '-' . substr($hex, 12, 4) . '-' .
            substr($hex, 16, 4) . '-' . substr($hex, 20);
    }
}
