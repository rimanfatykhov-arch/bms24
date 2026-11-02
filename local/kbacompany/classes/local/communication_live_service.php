<?php
namespace local_kbacompany\local;

defined('MOODLE_INTERNAL') || die();

/**
 * Read-only low-bandwidth change detector for the Communication Center.
 *
 * Returns no conversation contents or identifiers. Every monitored object
 * conversation is checked against its CURRENT underlying object ACL.
 * The canonical communication service still owns message rendering and reads.
 */
class communication_live_service {
    public const MAX_THREADS = 60;
    public const MAX_NOTIFICATIONS = 20;

    /** Stable opaque token: never expose individual message IDs or private titles. */
    public static function fingerprint(array $parts): string {
        return hash('sha256', implode('|', $parts));
    }

    public static function snapshot(string $companyuuid, int $actor, string $selected = ''): array {
        global $DB;
        company_context_service::context($companyuuid, $actor);
        $params = ['company' => $companyuuid, 'actor' => $actor, 'reader' => $actor];
        $sql = "SELECT t.id, t.uuid, t.threadtype, t.contextkind, t.contextuuid,
                       t.contextrevision, t.lastmessageat, t.timemodified,
                       COALESCE(rd.lastmessageid, 0) AS lastread
                  FROM {local_kbacompany_commthread} t
                  JOIN {local_kbacompany_commpart} p
                    ON p.companyuuid=t.companyuuid AND p.threaduuid=t.uuid
                   AND p.userid=:actor AND p.active=1
             LEFT JOIN {local_kbacompany_commread} rd
                    ON rd.companyuuid=t.companyuuid AND rd.threaduuid=t.uuid
                   AND rd.userid=:reader
                 WHERE t.companyuuid=:company
              ORDER BY t.lastmessageat DESC, t.id DESC";
        $rows = array_values($DB->get_records_sql($sql, $params, 0, self::MAX_THREADS + 1));
        $partial = count($rows) > self::MAX_THREADS;
        $rows = array_slice($rows, 0, self::MAX_THREADS);
        $allowed = [];
        $parts = ['bmscomm-live-v1'];
        foreach ($rows as $row) {
            if ((string)$row->threadtype === 'object') {
                try {
                    communication_object_service::describe($companyuuid, $actor,
                        (string)$row->contextkind, (string)$row->contextuuid, (int)$row->contextrevision);
                } catch (\moodle_exception $e) {
                    continue; // Revoked object permissions never appear in the fingerprint.
                }
            }
            $allowed[(string)$row->uuid] = true;
            $parts[] = 't:' . (string)$row->uuid . ':' . (int)$row->lastmessageat . ':' .
                (int)$row->timemodified . ':' . (int)$row->lastread;
        }

        // The active conversation may fall outside the bounded recently active set.
        if ($selected !== '') {
            // An in-window selected thread has ALREADY passed active participant and object ACL
            // checks in the bounded list. Avoid repeating two lookups and a possible expensive
            // object permission check on every 25-second refresh.
            if (!isset($allowed[$selected])) {
                if (!$DB->record_exists('local_kbacompany_commpart', [
                        'companyuuid' => $companyuuid, 'threaduuid' => $selected, 'userid' => $actor, 'active' => 1])) {
                    throw new \moodle_exception('communication_permission', 'local_kbacompany');
                }
                $thread = $DB->get_record('local_kbacompany_commthread', [
                    'companyuuid' => $companyuuid, 'uuid' => $selected]);
                if (!$thread) {
                    throw new \moodle_exception('communication_permission', 'local_kbacompany');
                }
                if ((string)$thread->threadtype === 'object') {
                    try {
                        communication_object_service::describe($companyuuid, $actor,
                            (string)$thread->contextkind, (string)$thread->contextuuid, (int)$thread->contextrevision);
                    } catch (\moodle_exception $e) {
                        throw new \moodle_exception('communication_permission', 'local_kbacompany');
                    }
                }
                $allowed[$selected] = true;
            }
            $lastid = (int)$DB->get_field_sql(
                'SELECT MAX(id) FROM {local_kbacompany_commmsg} WHERE companyuuid=:msgcompany AND threaduuid=:msgthread',
                ['msgcompany' => $companyuuid, 'msgthread' => $selected]);
            $parts[] = 's:' . $selected . ':' . $lastid;
        }

        // Signals only from authorized visible threads; never surface private notification contents.
        $notifications = $DB->get_records('local_kbacompany_commnotif', [
            'companyuuid' => $companyuuid, 'userid' => $actor], 'id DESC',
            'id,threaduuid,isread', 0, self::MAX_NOTIFICATIONS);
        foreach ($notifications as $notification) {
            if ((string)$notification->threaduuid !== '' &&
                    !isset($allowed[(string)$notification->threaduuid])) {
                continue;
            }
            $parts[] = 'n:' . (int)$notification->id . ':' . (int)$notification->isread;
        }
        return ['revision' => self::fingerprint($parts), 'partial' => $partial];
    }
}
