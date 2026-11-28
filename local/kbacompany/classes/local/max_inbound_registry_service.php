<?php
namespace local_kbacompany\local;

defined('MOODLE_INTERNAL') || die();

/**
 * RC33 inbound registry writers: connection, source namespace, group binding and sender binding (contract §4).
 *
 * Only a company organisation manager (or site administrator) may change the registry; the integration principal
 * has no endpoint here, so it can never approve bindings or consent for itself. Every writer takes the company
 * communication_write_gate, the same gate the import holds through its commit, and appends an audit record.
 * No UI in this slice: callers are tests and later administrative screens.
 */
final class max_inbound_registry_service {
    /** In the fake-provider slice only verified synthetic mappings exist (contract §4, D1/D2). */
    public const EVIDENCE_PREFIX = 'synthetic:';

    private static function fail(string $key): void {
        throw new \moodle_exception('inbound_registry_' . $key, 'local_kbacompany');
    }

    private static function require_manager(string $companyuuid, int $actor): void {
        company_context_service::context($companyuuid, $actor);
        if (!is_siteadmin($actor) && !permission_service::can_manage_organization($companyuuid, $actor)) {
            throw new \required_capability_exception(\context_system::instance(),
                'local/kbacompany:manageorganization', 'nopermissions', '');
        }
    }

    /** Run one registry change under the company gate in one transaction, with its audit row. */
    private static function write(string $companyuuid, callable $change) {
        global $DB;
        $gate = communication_write_gate::acquire($companyuuid);
        try {
            $tx = $DB->start_delegated_transaction();
            $result = $change();
            $tx->allow_commit();
            return $result;
        } catch (\Throwable $e) {
            if (isset($tx)) { $tx->rollback($e); }
            throw $e;
        } finally {
            $gate->release();
        }
    }

    private static function audit(string $companyuuid, string $connectionuuid, int $actor, string $action,
            string $objectuuid, int $revision = 0): void {
        global $DB;
        $DB->insert_record('local_kbacompany_inbaudit', (object)[
            'uuid' => max_inbound_service::uuid(), 'companyuuid' => $companyuuid, 'connectionuuid' => $connectionuuid,
            'channel' => max_inbound_service::CHANNEL, 'principaluserid' => 0, 'actoruserid' => $actor,
            'action' => $action, 'outcome' => 'done', 'reason' => '', 'receiptuuid' => '', 'objectuuid' => $objectuuid,
            'correlationid' => '', 'bindingrevision' => $revision, 'fingerprint' => '', 'timecreated' => time(),
        ]);
    }

    private static function connection(string $companyuuid, string $connectionuuid): \stdClass {
        global $DB;
        $conn = $DB->get_record('local_kbacompany_inbconn', ['uuid' => $connectionuuid, 'companyuuid' => $companyuuid]);
        if (!$conn) { self::fail('reference'); }
        return $conn;
    }

    /** A dedicated live account that is not a site administrator and serves no other connection. */
    private static function require_principal(int $principal, int $actor, string $exceptconnection = ''): void {
        global $DB;
        if ($principal <= 0 || $principal === $actor || is_siteadmin($principal) ||
                !$DB->record_exists('user', ['id' => $principal, 'deleted' => 0, 'suspended' => 0])) {
            self::fail('principal');
        }
        $used = $DB->get_records_select('local_kbacompany_inbconn', 'principaluserid = :p AND uuid <> :c',
            ['p' => $principal, 'c' => $exceptconnection]);
        if ($used) { self::fail('principal'); }
    }

    /**
     * Create the company's disabled MAX connection. The bot identity yields one BMS-assigned namespace; a namespace
     * already serving a connection cannot be reused by another connection or company (N26).
     */
    public static function create_connection(string $companyuuid, int $actor, string $botidentity, int $principal): string {
        global $DB;
        self::require_manager($companyuuid, $actor);
        $botidentity = trim($botidentity);
        if ($botidentity === '' || \core_text::strlen($botidentity) > 255) { self::fail('invalid'); }
        self::require_principal($principal, $actor);
        return self::write($companyuuid, function() use ($DB, $companyuuid, $actor, $botidentity, $principal): string {
            $now = time();
            if ($DB->record_exists('local_kbacompany_inbconn', ['companyuuid' => $companyuuid,
                    'provider' => max_inbound_service::PROVIDER])) {
                self::fail('exists');
            }
            $hash = hash('sha256', max_inbound_service::PROVIDER . "\n" . $botidentity);
            $namespace = $DB->get_record('local_kbacompany_inbns',
                ['provider' => max_inbound_service::PROVIDER, 'identityhash' => $hash]);
            if ($namespace) {
                // Never re-attach an old source namespace to a new connection: receipts stay with their source.
                self::fail('namespace');
            }
            $namespaceuuid = max_inbound_service::uuid();
            $DB->insert_record('local_kbacompany_inbns', (object)['uuid' => $namespaceuuid,
                'provider' => max_inbound_service::PROVIDER, 'identityhash' => $hash, 'createdby' => $actor,
                'timecreated' => $now]);
            $uuid = max_inbound_service::uuid();
            $DB->insert_record('local_kbacompany_inbconn', (object)['uuid' => $uuid, 'companyuuid' => $companyuuid,
                'provider' => max_inbound_service::PROVIDER, 'namespaceuuid' => $namespaceuuid,
                'principaluserid' => $principal, 'status' => 'disabled', 'createdby' => $actor,
                'timecreated' => $now, 'timemodified' => $now]);
            self::audit($companyuuid, $uuid, $actor, 'connection_create', $uuid);
            return $uuid;
        });
    }

    /** enabled / disabled / revoked (kill switch). Revoked is terminal. */
    public static function set_connection_status(string $companyuuid, int $actor, string $connectionuuid,
            string $status): void {
        global $DB;
        self::require_manager($companyuuid, $actor);
        if (!in_array($status, ['enabled', 'disabled', 'revoked'], true)) { self::fail('invalid'); }
        self::write($companyuuid, function() use ($DB, $companyuuid, $actor, $connectionuuid, $status): void {
            $conn = self::connection($companyuuid, $connectionuuid);
            if ((string)$conn->status === 'revoked') { self::fail('state'); }
            $conn->status = $status;
            $conn->timemodified = time();
            $DB->update_record('local_kbacompany_inbconn', $conn);
            self::audit($companyuuid, $connectionuuid, $actor, 'connection_' . $status, $connectionuuid);
        });
    }

    /** Credential rotation: a new principal for the same connection; namespace and receipts are unchanged. */
    public static function rotate_principal(string $companyuuid, int $actor, string $connectionuuid, int $principal): void {
        global $DB;
        self::require_manager($companyuuid, $actor);
        self::require_principal($principal, $actor, $connectionuuid);
        self::write($companyuuid, function() use ($DB, $companyuuid, $actor, $connectionuuid, $principal): void {
            $conn = self::connection($companyuuid, $connectionuuid);
            if ((string)$conn->status === 'revoked') { self::fail('state'); }
            $conn->principaluserid = $principal;
            $conn->timemodified = time();
            $DB->update_record('local_kbacompany_inbconn', $conn);
            self::audit($companyuuid, $connectionuuid, $actor, 'connection_rotate', $connectionuuid);
        });
    }

    /**
     * Bind one external chat to one active group thread whose owner is the approving manager. A rebind of the same
     * chat raises the revision (old events then go to review, never to the new target); the company never changes.
     */
    public static function bind_group(string $companyuuid, int $actor, string $connectionuuid, string $externalchatid,
            string $threaduuid, string $policyversion, int $effectivefrom): string {
        global $DB;
        self::require_manager($companyuuid, $actor);
        if (!preg_match('/^-?(?:0|[1-9][0-9]{0,18})$/D', $externalchatid) || $externalchatid === '-0' ||
                !preg_match('/^[A-Za-z0-9._:-]{1,32}$/D', $policyversion) || $effectivefrom < 0) {
            self::fail('invalid');
        }
        return self::write($companyuuid, function() use ($DB, $companyuuid, $actor, $connectionuuid, $externalchatid,
                $threaduuid, $policyversion, $effectivefrom): string {
            $conn = self::connection($companyuuid, $connectionuuid);
            if ((string)$conn->status === 'revoked') { self::fail('state'); }
            $thread = $DB->get_record('local_kbacompany_commthread', ['companyuuid' => $companyuuid, 'uuid' => $threaduuid]);
            if (!$thread || (string)$thread->threadtype !== 'group' || (string)$thread->status !== 'active' ||
                    !$DB->record_exists('local_kbacompany_commpart', ['companyuuid' => $companyuuid,
                        'threaduuid' => $threaduuid, 'userid' => $actor, 'active' => 1, 'role' => 'owner'])) {
                self::fail('thread');
            }
            $other = $DB->get_records_select('local_kbacompany_inbgroup',
                "companyuuid = :c AND threaduuid = :t AND status <> 'revoked' AND
                 NOT (namespaceuuid = :n AND externalchatid = :e)",
                ['c' => $companyuuid, 't' => $threaduuid, 'n' => (string)$conn->namespaceuuid, 'e' => $externalchatid]);
            if ($other) { self::fail('thread'); }
            $now = time();
            $existing = $DB->get_record('local_kbacompany_inbgroup',
                ['namespaceuuid' => (string)$conn->namespaceuuid, 'externalchatid' => $externalchatid]);
            if ($existing) {
                if ((string)$existing->companyuuid !== $companyuuid) { self::fail('namespace'); }
                $existing->threaduuid = $threaduuid;
                $existing->revision = (int)$existing->revision + 1;
                $existing->status = 'active';
                $existing->policyversion = $policyversion;
                $existing->effectivefrom = $effectivefrom;
                $existing->approvedby = $actor;
                $existing->timeapproved = $now;
                $existing->timemodified = $now;
                $DB->update_record('local_kbacompany_inbgroup', $existing);
                self::audit($companyuuid, $connectionuuid, $actor, 'binding_rebind', (string)$existing->uuid,
                    (int)$existing->revision);
                return (string)$existing->uuid;
            }
            $uuid = max_inbound_service::uuid();
            $DB->insert_record('local_kbacompany_inbgroup', (object)['uuid' => $uuid, 'connectionuuid' => $connectionuuid,
                'namespaceuuid' => (string)$conn->namespaceuuid, 'companyuuid' => $companyuuid,
                'externalchatid' => $externalchatid, 'threaduuid' => $threaduuid, 'revision' => 1, 'status' => 'active',
                'policyversion' => $policyversion, 'effectivefrom' => $effectivefrom, 'approvedby' => $actor,
                'timeapproved' => $now, 'timecreated' => $now, 'timemodified' => $now]);
            self::audit($companyuuid, $connectionuuid, $actor, 'binding_create', $uuid, 1);
            return $uuid;
        });
    }

    /** active / suspended / revoked; suspension pauses imports (bot removed, permissions lost, audience change). */
    public static function set_binding_status(string $companyuuid, int $actor, string $bindinguuid, string $status): void {
        global $DB;
        self::require_manager($companyuuid, $actor);
        if (!in_array($status, ['active', 'suspended', 'revoked'], true)) { self::fail('invalid'); }
        self::write($companyuuid, function() use ($DB, $companyuuid, $actor, $bindinguuid, $status): void {
            $binding = $DB->get_record('local_kbacompany_inbgroup', ['uuid' => $bindinguuid, 'companyuuid' => $companyuuid]);
            if (!$binding) { self::fail('reference'); }
            if ((string)$binding->status === 'revoked') { self::fail('state'); }
            $binding->status = $status;
            $binding->timemodified = time();
            $DB->update_record('local_kbacompany_inbgroup', $binding);
            self::audit($companyuuid, (string)$binding->connectionuuid, $actor, 'binding_' . $status, $bindinguuid,
                (int)$binding->revision);
        });
    }

    /**
     * Record a verified sender mapping with the author's consent for one group binding and its policy version.
     * Only synthetic evidence is accepted in this slice; names, phones or matching IDs are never evidence.
     */
    public static function bind_sender(string $companyuuid, int $actor, string $connectionuuid, string $externalsenderid,
            int $userid, string $bindinguuid, string $consentversion, string $evidenceref, int $effectivefrom,
            int $expiresat = 0): string {
        global $DB;
        self::require_manager($companyuuid, $actor);
        if (!preg_match('/^-?(?:0|[1-9][0-9]{0,18})$/D', $externalsenderid) || $externalsenderid === '-0' ||
                strpos($evidenceref, self::EVIDENCE_PREFIX) !== 0 || strlen($evidenceref) > 64 ||
                !preg_match('/^[A-Za-z0-9._:-]{1,32}$/D', $consentversion) || $effectivefrom < 0 || $expiresat < 0) {
            self::fail('invalid');
        }
        if (!$DB->record_exists('user', ['id' => $userid, 'deleted' => 0, 'suspended' => 0])) { self::fail('user'); }
        try {
            company_context_service::context($companyuuid, $userid);
        } catch (\moodle_exception $e) {
            self::fail('user');
        }
        return self::write($companyuuid, function() use ($DB, $companyuuid, $actor, $connectionuuid, $externalsenderid,
                $userid, $bindinguuid, $consentversion, $evidenceref, $effectivefrom, $expiresat): string {
            $conn = self::connection($companyuuid, $connectionuuid);
            if (!$DB->record_exists('local_kbacompany_inbgroup', ['uuid' => $bindinguuid,
                    'connectionuuid' => $connectionuuid, 'companyuuid' => $companyuuid])) {
                self::fail('reference');
            }
            $membership = $DB->get_record('local_kbacompany_member', ['companyuuid' => $companyuuid,
                'userid' => $userid, 'active' => 1], 'id, uuid', IGNORE_MULTIPLE);
            $now = time();
            $row = (object)['connectionuuid' => (string)$conn->uuid, 'companyuuid' => $companyuuid,
                'externalsenderid' => $externalsenderid, 'userid' => $userid,
                'membershipuuid' => $membership ? (string)$membership->uuid : '', 'bindinguuid' => $bindinguuid,
                'consentversion' => $consentversion, 'evidenceref' => $evidenceref, 'verifiedby' => $actor,
                'effectivefrom' => $effectivefrom, 'expiresat' => $expiresat, 'revokedat' => 0, 'timemodified' => $now];
            $existing = $DB->get_record('local_kbacompany_inbsender',
                ['connectionuuid' => (string)$conn->uuid, 'externalsenderid' => $externalsenderid]);
            if ($existing) {
                $row->id = $existing->id;
                $row->uuid = $existing->uuid;
                $DB->update_record('local_kbacompany_inbsender', $row);
            } else {
                $row->uuid = max_inbound_service::uuid();
                $row->timecreated = $now;
                $row->id = $DB->insert_record('local_kbacompany_inbsender', $row);
            }
            self::audit($companyuuid, $connectionuuid, $actor, 'sender_bind', (string)$row->uuid);
            return (string)$row->uuid;
        });
    }

    /** Withdraw a sender mapping/consent; later imports of this sender go to review. */
    public static function revoke_sender(string $companyuuid, int $actor, string $senderbindinguuid): void {
        global $DB;
        self::require_manager($companyuuid, $actor);
        self::write($companyuuid, function() use ($DB, $companyuuid, $actor, $senderbindinguuid): void {
            $sender = $DB->get_record('local_kbacompany_inbsender', ['uuid' => $senderbindinguuid,
                'companyuuid' => $companyuuid]);
            if (!$sender) { self::fail('reference'); }
            if ((int)$sender->revokedat === 0) {
                $sender->revokedat = time();
                $sender->timemodified = $sender->revokedat;
                $DB->update_record('local_kbacompany_inbsender', $sender);
            }
            self::audit($companyuuid, (string)$sender->connectionuuid, $actor, 'sender_revoke', $senderbindinguuid);
        });
    }

    /** Fixed audience (contract §4): a thread with a live import binding admits no new readers. */
    public static function thread_has_import_binding(string $companyuuid, string $threaduuid): bool {
        global $DB;
        if (!$DB->get_manager()->table_exists('local_kbacompany_inbgroup')) {
            return false;
        }
        return $DB->record_exists_select('local_kbacompany_inbgroup',
            "companyuuid = :c AND threaduuid = :t AND status <> 'revoked'", ['c' => $companyuuid, 't' => $threaduuid]);
    }
}
