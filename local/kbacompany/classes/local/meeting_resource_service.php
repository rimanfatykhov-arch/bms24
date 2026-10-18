<?php
namespace local_kbacompany\local;

defined('MOODLE_INTERNAL') || die();

/** Company-scoped meeting resources and availability. */
class meeting_resource_service {
    public const CONTRACT_VERSION = '1.1.0-alpha18';
    public const TYPES = ['room', 'place'];

    private static function fail(string $key): void {
        throw new \moodle_exception('meeting_resource_' . $key, 'local_kbacompany');
    }

    private static function uuid(): string {
        $bytes = random_bytes(16);
        $bytes[6] = chr((ord($bytes[6]) & 0x0f) | 0x40);
        $bytes[8] = chr((ord($bytes[8]) & 0x3f) | 0x80);
        $hex = bin2hex($bytes);
        return substr($hex,0,8).'-'.substr($hex,8,4).'-'.substr($hex,12,4).'-'.substr($hex,16,4).'-'.substr($hex,20);
    }

    public static function can_manage(string $companyuuid, int $actor): bool {
        company_context_service::context($companyuuid, $actor);
        return permission_service::can_manage_management($companyuuid, $actor);
    }

    private static function require_manage(string $companyuuid, int $actor): void {
        if (!self::can_manage($companyuuid, $actor)) { self::fail('permission'); }
    }

    public static function list(string $companyuuid, int $actor, bool $includeinactive = false): array {
        global $DB;
        company_context_service::context($companyuuid, $actor);
        $params = ['company' => $companyuuid];
        $where = 'companyuuid = :company';
        if (!$includeinactive) { $where .= ' AND active = 1'; }
        $rows = $DB->get_records_select('local_kbacompany_meetres', $where, $params, 'name ASC, id ASC');
        $out = [];
        foreach ($rows as $row) {
            $out[] = [
                'uuid'=>(string)$row->uuid, 'code'=>(string)$row->code, 'name'=>(string)$row->name,
                'type'=>(string)$row->resourcetype, 'location'=>(string)$row->location,
                'capacity'=>(int)$row->capacity, 'active'=>!empty($row->active),
            ];
        }
        return $out;
    }

    public static function get(string $companyuuid, int $actor, string $resourceuuid): ?array {
        global $DB;
        company_context_service::context($companyuuid, $actor);
        $row = $DB->get_record('local_kbacompany_meetres', ['companyuuid'=>$companyuuid,'uuid'=>$resourceuuid]);
        if (!$row) { return null; }
        return ['uuid'=>(string)$row->uuid,'code'=>(string)$row->code,'name'=>(string)$row->name,
            'type'=>(string)$row->resourcetype,'location'=>(string)$row->location,'capacity'=>(int)$row->capacity,
            'active'=>!empty($row->active)];
    }

    public static function save(string $companyuuid, int $actor, string $resourceuuid, array $data): string {
        global $DB;
        self::require_manage($companyuuid, $actor);
        $code = strtoupper(trim((string)($data['code'] ?? '')));
        $name = trim((string)($data['name'] ?? ''));
        $type = trim((string)($data['type'] ?? 'room'));
        $location = trim((string)($data['location'] ?? ''));
        $capacity = max(0, (int)($data['capacity'] ?? 0));
        if ($name === '' || \core_text::strlen($name) > 255 || \core_text::strlen($code) > 40 ||
                \core_text::strlen($location) > 255 || !in_array($type, self::TYPES, true)) { self::fail('invalid'); }
        if ($code === '') { $code = 'RES-' . strtoupper(substr(str_replace('-', '', self::uuid()), 0, 8)); }
        $existingcode = $DB->get_record('local_kbacompany_meetres', ['companyuuid'=>$companyuuid,'code'=>$code]);
        $now = time();
        if ($resourceuuid === '') {
            if ($existingcode) { self::fail('code'); }
            $resourceuuid = self::uuid();
            $DB->insert_record('local_kbacompany_meetres', (object)[
                'companyuuid'=>$companyuuid,'uuid'=>$resourceuuid,'code'=>$code,'name'=>$name,
                'resourcetype'=>$type,'location'=>$location,'capacity'=>$capacity,'active'=>1,
                'timecreated'=>$now,'timemodified'=>$now,
            ]);
        } else {
            $row = $DB->get_record('local_kbacompany_meetres', ['companyuuid'=>$companyuuid,'uuid'=>$resourceuuid]);
            if (!$row) { self::fail('reference'); }
            if ($existingcode && (int)$existingcode->id !== (int)$row->id) { self::fail('code'); }
            $row->code=$code; $row->name=$name; $row->resourcetype=$type; $row->location=$location;
            $row->capacity=$capacity; $row->timemodified=$now;
            $DB->update_record('local_kbacompany_meetres', $row);
        }
        return $resourceuuid;
    }

    public static function archive(string $companyuuid, int $actor, string $resourceuuid): void {
        global $DB;
        self::require_manage($companyuuid, $actor);
        $row = $DB->get_record('local_kbacompany_meetres', ['companyuuid'=>$companyuuid,'uuid'=>$resourceuuid]);
        if (!$row) { self::fail('reference'); }
        $row->active = 0; $row->timemodified = time();
        $DB->update_record('local_kbacompany_meetres', $row);
    }

    public static function validate_online_url(string $url): string {
        $url = trim($url);
        if ($url === '') { return ''; }
        if (\core_text::strlen($url) > 255 || !preg_match('~^https?://~i', $url) || !filter_var($url, FILTER_VALIDATE_URL)) {
            self::fail('url');
        }
        return $url;
    }

    public static function assert_available(string $companyuuid, int $actor, string $resourceuuid,
            int $startsat, int $endsat, string $excludeuuid = ''): void {
        global $DB;
        company_context_service::context($companyuuid, $actor);
        if ($resourceuuid === '') { return; }
        $resource = $DB->get_record('local_kbacompany_meetres', ['companyuuid'=>$companyuuid,'uuid'=>$resourceuuid,'active'=>1]);
        if (!$resource) { self::fail('reference'); }
        if ($startsat <= 0 || $endsat <= $startsat) { return; }
        $params = ['company'=>$companyuuid,'resource'=>$resourceuuid,'cancelled'=>'cancelled','end'=>$endsat,'start'=>$startsat];
        $sql = "SELECT m.id FROM {local_kbacompany_meeting} m
                 WHERE m.companyuuid=:company AND m.resourceuuid=:resource AND m.status<>:cancelled
                   AND m.startsat < :end AND m.endsat > :start";
        if ($excludeuuid !== '') { $sql .= ' AND m.uuid <> :exclude'; $params['exclude']=$excludeuuid; }
        if ($DB->record_exists_sql($sql, $params)) { self::fail('busy'); }
    }


    /** Enforce declared resource capacity; 0 means unspecified/unlimited. */
    public static function assert_capacity(string $companyuuid, int $actor, string $resourceuuid, int $participantcount): void {
        global $DB;
        company_context_service::context($companyuuid, $actor);
        if ($resourceuuid === '' || $participantcount <= 0) { return; }
        $resource = $DB->get_record('local_kbacompany_meetres', [
            'companyuuid'=>$companyuuid, 'uuid'=>$resourceuuid, 'active'=>1,
        ]);
        if (!$resource) { self::fail('reference'); }
        $capacity = (int)$resource->capacity;
        if ($capacity > 0 && $participantcount > $capacity) { self::fail('capacityfull'); }
    }

    /** Acquire the resource booking lock used by meeting create/update operations. */
    public static function acquire_booking_lock(string $companyuuid, string $resourceuuid) {
        if ($resourceuuid === '') { return null; }
        $factory = \core\lock\lock_config::get_lock_factory('local_kbacompany');
        $lock = $factory->get_lock('meetingresource:' . $companyuuid . ':' . $resourceuuid, 10);
        if (!$lock) { self::fail('lock'); }
        return $lock;
    }

    public static function availability(string $companyuuid, int $actor, int $fromts, int $tots): array {
        global $DB;
        company_context_service::context($companyuuid, $actor);
        $resources = self::list($companyuuid, $actor, false);
        $out = [];
        foreach ($resources as $resource) {
            $params = [
                'company'=>$companyuuid, 'resource'=>$resource['uuid'], 'cancelled'=>'cancelled',
                'to'=>$tots, 'from'=>$fromts, 'actor'=>$actor,
            ];
            $sql = "SELECT m.uuid,m.title,m.startsat,m.endsat,p.id AS participantid
                      FROM {local_kbacompany_meeting} m
                 LEFT JOIN {local_kbacompany_meetpart} p
                        ON p.companyuuid=m.companyuuid AND p.meetinguuid=m.uuid
                       AND p.userid=:actor AND p.active=1
                     WHERE m.companyuuid=:company AND m.resourceuuid=:resource AND m.status<>:cancelled
                       AND m.startsat < :to AND m.endsat > :from
                  ORDER BY m.startsat ASC";
            $busy = [];
            foreach ($DB->get_records_sql($sql, $params) as $m) {
                $busy[] = [
                    'meeting_uuid'=>(string)$m->uuid,
                    'title'=>!empty($m->participantid) ? (string)$m->title : '',
                    'can_view'=>!empty($m->participantid),
                    'starts_at'=>(int)$m->startsat, 'ends_at'=>(int)$m->endsat,
                ];
            }
            $resource['busy']=$busy; $out[]=$resource;
        }
        return $out;
    }
}
