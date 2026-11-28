<?php
namespace local_kbacompany\local;

defined('MOODLE_INTERNAL') || die();

/**
 * One canonical discussion per weekly Battle Plan (Communication Center stage E2, owner decision Q9 2026-11-16).
 *
 * Messages live only in communication_service (object thread, contextkind 'battleplan'); this service never stores a
 * second history. Reading follows the plan read rule; writing requires the plan write-to-discussion right and joins the
 * thread explicitly. A message never approves, returns or otherwise changes the plan.
 */
final class battle_plan_discussion_service {
    public const KIND = 'battleplan';

    private static function fail(string $key): void {
        throw new \moodle_exception('communication_' . $key, 'local_kbacompany');
    }

    /** The active canonical thread of a plan, if any (company-scoped). */
    public static function thread(string $companyuuid, string $planuuid): ?\stdClass {
        global $DB;
        $rows = $DB->get_records('local_kbacompany_commthread', [
            'companyuuid' => $companyuuid, 'threadtype' => 'object', 'contextkind' => self::KIND,
            'contextuuid' => $planuuid, 'status' => 'active',
        ], 'id ASC', '*', 0, 1);
        return $rows ? reset($rows) : null;
    }

    /** Read projection for the plan card; throws for anyone who may not open the plan. */
    public static function context(string $companyuuid, int $actor, string $planuuid): array {
        $target = battle_plan_service::discussion_target($companyuuid, $actor, $planuuid);
        $thread = self::thread($companyuuid, $planuuid);
        if (!$thread) {
            return ['thread_uuid' => '', 'messages' => [], 'is_participant' => false,
                'can_post' => $target['can_post'], 'plan' => $target];
        }
        // Readers are not joined: communication_service re-checks the plan through communication_object_service.
        $ctx = communication_service::context($companyuuid, $actor, (string)$thread->uuid, 'all');
        return ['thread_uuid' => (string)$thread->uuid, 'messages' => $ctx['messages'],
            'is_participant' => !empty($ctx['selected']['is_participant']),
            'can_post' => $target['can_post'], 'plan' => $target];
    }

    /** Whether the actor may open the plan and write to its discussion (checked before joining from the Center). */
    public static function can_post_for(string $companyuuid, int $actor, string $planuuid): bool {
        try {
            return (bool)battle_plan_service::discussion_target($companyuuid, $actor, $planuuid)['can_post'];
        } catch (\moodle_exception $e) {
            return false;
        }
    }

    /** Plan people who can currently read the plan become participants when the thread is created. */
    private static function initial_participants(string $companyuuid, int $actor, array $target): array {
        $employees = [];
        foreach (organization_service::organization_context($companyuuid, $actor)['employees'] as $employee) {
            $employees[(int)$employee['user_id']] = true;
        }
        $result = [];
        foreach (array_unique(array_filter([$target['user_id'], $target['reviewer_id'], $target['observer_id'],
                $target['created_by']])) as $userid) {
            $userid = (int)$userid;
            if ($userid === $actor || !isset($employees[$userid])) { continue; }
            try {
                communication_object_service::describe($companyuuid, $userid, self::KIND, $target['uuid']);
            } catch (\moodle_exception $e) {
                continue;
            }
            $result[] = $userid;
        }
        return $result;
    }

    /** Post one message (optionally with files / as a reply). Posting explicitly joins the actor. */
    public static function post(string $companyuuid, int $actor, string $planuuid, string $body,
            ?array $upload = null, string $replytouuid = ''): string {
        global $DB;
        $target = battle_plan_service::discussion_target($companyuuid, $actor, $planuuid);
        if (empty($target['can_post'])) { self::fail('permission'); }
        $body = trim($body);
        $uploads = communication_service::normalise_uploads($upload);
        if (($body === '' && !$uploads) || \core_text::strlen($body) > 20000) { self::fail('invalid'); }
        $lock = \core\lock\lock_config::get_lock_factory('local_kbacompany')
            ->get_lock('bplandiscussion:' . $companyuuid . ':' . $planuuid, 10);
        if (!$lock) { self::fail('lock'); }
        try {
            // create_thread is the idempotent create-or-join for the one canonical object thread.
            $threaduuid = communication_service::create_thread($companyuuid, $actor, [
                'threadtype' => 'object', 'title' => '', 'contextkind' => self::KIND, 'contextuuid' => $planuuid,
                'contextrevision' => 0, 'participants' => self::initial_participants($companyuuid, $actor, $target),
            ]);
            // Hold the communication gate through this outer commit (lock order: bplandiscussion -> gate).
            $gate = communication_write_gate::acquire($companyuuid);
            $tx = $DB->start_delegated_transaction();
            $messageuuid = communication_service::send_message_with_attachments($companyuuid, $actor, $threaduuid,
                $body, $uploads, [], trim($replytouuid));
            $tx->allow_commit();
            return $messageuuid;
        } catch (\Throwable $e) {
            if (isset($tx)) { $tx->rollback($e); }
            throw $e;
        } finally {
            if (isset($gate)) { $gate->release(); }
            $lock->release();
        }
    }
}
