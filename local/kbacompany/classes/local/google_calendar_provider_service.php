<?php
namespace local_kbacompany\local;

defined('MOODLE_INTERNAL') || die();

/**
 * Google Calendar provider over Moodle core OAuth2.
 *
 * Google is an external provider. BMS Meetings remain canonical management records.
 * Imported Google events are read-only projections. Outbound meeting sync is explicit
 * and idempotent through the existing provider-neutral external mapping registry.
 */
class google_calendar_provider_service {
    public const PROVIDER = 'google_calendar';
    public const ENTITY_KIND = 'calendar_event';
    public const SCOPE = 'https://www.googleapis.com/auth/calendar.events';
    public const PREF_ISSUER = 'local_kbacompany_google_calendar_issuer';
    public const PREF_CALENDAR = 'local_kbacompany_google_calendar_id';
    public const PREF_IMPORT = 'local_kbacompany_google_calendar_import';
    public const PREF_ENABLED = 'local_kbacompany_google_calendar_enabled';

    public static function contract(): array {
        return [
            'version' => '1.1.0-alpha19',
            'provider' => self::PROVIDER,
            'oauth_owner' => 'moodle_core_oauth2',
            'canonical_management_owner' => 'bms24',
            'external_calendar_owner' => 'google_calendar',
            'rules' => [
                'google_events_import_as_read_only_projection',
                'bms_meeting_export_is_explicit_user_action',
                'bms_meeting_identity_remains_canonical',
                'event_mapping_uses_existing_provider_neutral_extmap',
                'no_attendee_email_export',
                'no_hidden_background_sync',
                'no_google_credentials_stored_by_local_kbacompany',
                'other_users_google_calendars_are_never_read',
            ],
        ];
    }

    public static function issuer_options(): array {
        $out = [];
        foreach (\core\oauth2\api::get_all_issuers(false) as $issuer) {
            try {
                $type = strtolower((string)$issuer->get('servicetype'));
                $name = (string)$issuer->get('name');
                if ($type !== 'google' && strpos(strtolower($name), 'google') === false) {
                    continue;
                }
                $out[(int)$issuer->get('id')] = $name;
            } catch (\Throwable $e) {
                // Ignore malformed/unavailable issuers.
            }
        }
        return $out;
    }

    public static function config(int $userid): array {
        $issuerid = (int)get_user_preferences(self::PREF_ISSUER, 0, $userid);
        $calendarid = trim((string)get_user_preferences(self::PREF_CALENDAR, 'primary', $userid));
        if ($calendarid === '') { $calendarid = 'primary'; }
        return [
            'issuer_id' => $issuerid,
            'calendar_id' => $calendarid,
            'import_enabled' => (bool)get_user_preferences(self::PREF_IMPORT, 1, $userid),
            'enabled' => (bool)get_user_preferences(self::PREF_ENABLED, 0, $userid),
        ];
    }

    public static function save_config(int $userid, int $issuerid, string $calendarid, bool $importenabled, bool $enabled): void {
        global $USER;
        if ((int)$USER->id !== $userid) {
            throw new \required_capability_exception(\context_system::instance(), 'local/kbacompany:viewowncompany', 'nopermissions', '');
        }
        $options = self::issuer_options();
        if ($issuerid > 0 && !isset($options[$issuerid])) {
            throw new \invalid_parameter_exception('Google OAuth issuer is not available.');
        }
        $calendarid = trim($calendarid);
        if ($calendarid === '') { $calendarid = 'primary'; }
        if (strlen($calendarid) > 250 || preg_match('/[\x00-\x1F]/', $calendarid)) {
            throw new \invalid_parameter_exception('Invalid Google Calendar ID.');
        }
        set_user_preference(self::PREF_ISSUER, $issuerid, $userid);
        set_user_preference(self::PREF_CALENDAR, $calendarid, $userid);
        set_user_preference(self::PREF_IMPORT, $importenabled ? 1 : 0, $userid);
        set_user_preference(self::PREF_ENABLED, $enabled ? 1 : 0, $userid);
    }

    /** Return OAuth client or null. This method never redirects. */
    public static function client(int $userid, \moodle_url $returnurl) {
        global $USER;
        if ((int)$USER->id !== $userid) { return null; }
        $cfg = self::config($userid);
        if ($cfg['issuer_id'] <= 0) { return null; }
        try {
            $issuer = \core\oauth2\api::get_issuer($cfg['issuer_id']);
            if (!$issuer) { return null; }
            $client = \core\oauth2\api::get_user_oauth_client($issuer, $returnurl, self::SCOPE, true);
            return $client->is_logged_in() ? $client : null;
        } catch (\Throwable $e) {
            return null;
        }
    }

    /** Get an OAuth client for explicit Connect flow. Caller may redirect to get_login_url(). */
    public static function connect_client(int $userid, int $issuerid, \moodle_url $returnurl) {
        global $USER;
        if ((int)$USER->id !== $userid) {
            throw new \moodle_exception('nopermissions');
        }
        $options = self::issuer_options();
        if (!isset($options[$issuerid])) {
            throw new \invalid_parameter_exception('Google OAuth issuer is not available.');
        }
        $issuer = \core\oauth2\api::get_issuer($issuerid);
        return \core\oauth2\api::get_user_oauth_client($issuer, $returnurl, self::SCOPE, true);
    }

    public static function diagnostics(string $companyuuid, int $userid): array {
        company_context_service::context($companyuuid, $userid);
        $cfg = self::config($userid);
        $return = new \moodle_url('/local/kbacompany/calendar_google.php', ['uuid' => $companyuuid]);
        $client = self::client($userid, $return);
        return [
            'config' => $cfg,
            'issuer_options' => self::issuer_options(),
            'connected' => $client !== null,
            'contract' => self::contract(),
        ];
    }

    /** Read-only Google events visible to the signed-in user's connected account. */
    public static function calendar_projection(string $companyuuid, int $userid, int $start, int $end): array {
        company_context_service::context($companyuuid, $userid);
        $cfg = self::config($userid);
        if (!$cfg['enabled'] || !$cfg['import_enabled']) { return []; }
        $return = new \moodle_url('/local/kbacompany/calendar_google.php', ['uuid' => $companyuuid]);
        $client = self::client($userid, $return);
        if (!$client) { return []; }
        $tz = \core_date::get_user_timezone_object();
        $params = [
            'timeMin' => (new \DateTimeImmutable('@'.$start))->setTimezone($tz)->format(DATE_RFC3339),
            'timeMax' => (new \DateTimeImmutable('@'.$end))->setTimezone($tz)->format(DATE_RFC3339),
            'singleEvents' => 'true',
            'orderBy' => 'startTime',
            'maxResults' => 500,
        ];
        $url = self::events_url($cfg['calendar_id']);
        $payload = self::request_json($client, 'GET', $url, $params);
        if (!$payload || empty($payload['items']) || !is_array($payload['items'])) { return []; }
        $rows = [];
        foreach ($payload['items'] as $event) {
            if (!is_array($event) || !empty($event['status']) && $event['status'] === 'cancelled') { continue; }
            $private = $event['extendedProperties']['private'] ?? [];
            if (is_array($private) && !empty($private['bmsLocalRef'])) {
                // BMS-exported event is already represented by its canonical BMS object.
                continue;
            }
            $startdata = isset($event['start']) && is_array($event['start']) ? $event['start'] : [];
            $enddata = isset($event['end']) && is_array($event['end']) ? $event['end'] : [];
            $allday = !empty($startdata['date']) && empty($startdata['dateTime']);
            $ts = self::google_time_to_timestamp($startdata, $tz, $allday ? 9 : 0);
            if ($ts <= 0) { continue; }
            $endts = self::google_time_to_timestamp($enddata, $tz, $allday ? 9 : 0);
            $duration = $endts > $ts ? $endts - $ts : 0;
            $summary = trim((string)($event['summary'] ?? ''));
            if ($summary === '') { $summary = get_string('bms_google_untitled', 'local_kbacompany'); }
            $rows[] = [
                'id' => 0,
                'name' => $summary,
                'course_id' => 0,
                'course_name' => get_string('bms_google_source_label', 'local_kbacompany'),
                'group_id' => 0,
                'module' => '',
                'event_type' => 'google',
                'time_start' => $ts,
                'duration' => $duration,
                'day_key' => userdate($ts, '%Y-%m-%d'),
                'time_label' => $allday ? get_string('bmscalendar_all_day', 'local_kbacompany') : userdate($ts, get_string('strftimetime')),
                'date_label' => userdate($ts, get_string('strftimedaydate')),
                'generated' => false,
                'source_kind' => 'google',
                'source_label' => get_string('bms_google_source_label', 'local_kbacompany'),
                'all_day' => $allday,
                'kind_label' => get_string('bms_google_external_event', 'local_kbacompany'),
                'url' => !empty($event['htmlLink']) ? (string)$event['htmlLink'] : '',
                'moodle_url' => '',
                'can_manage' => false,
                'status' => (string)($event['status'] ?? 'confirmed'),
                'owner_user_ids' => [$userid],
            ];
        }
        return $rows;
    }

    /** Explicitly upsert BMS meetings visible to this user into their selected Google calendar. */
    public static function sync_upcoming_meetings(string $companyuuid, int $userid, int $days = 90): array {
        company_context_service::context($companyuuid, $userid);
        $days = max(1, min(365, $days));
        $cfg = self::config($userid);
        if (!$cfg['enabled'] || $cfg['issuer_id'] <= 0) {
            throw new \moodle_exception('bms_google_not_enabled', 'local_kbacompany');
        }
        $return = new \moodle_url('/local/kbacompany/calendar_google.php', ['uuid' => $companyuuid]);
        $client = self::client($userid, $return);
        if (!$client) {
            throw new \moodle_exception('bms_google_not_connected', 'local_kbacompany');
        }
        $start = time() - 86400;
        $end = time() + ($days * 86400);
        $meetings = meeting_service::calendar_projection($companyuuid, $userid, $start, $end);
        $created = 0; $updated = 0; $failed = 0;
        $lock = self::lock($companyuuid, $userid, $cfg['calendar_id']);
        try {
            foreach ($meetings as $meeting) {
                try {
                    $localref = 'meeting:' . (string)$meeting['uuid'];
                    $mapping = self::mapping_by_local($companyuuid, $userid, $cfg['calendar_id'], $localref);
                    $body = self::meeting_body($companyuuid, $meeting, $localref);
                    if ($mapping) {
                        self::request_json($client, 'PATCH', self::event_url($cfg['calendar_id'], (string)$mapping->externalid), [], $body, true);
                        $updated++;
                    } else {
                        $createdevent = self::request_json($client, 'POST', self::events_url($cfg['calendar_id']), [], $body, true);
                        $externalid = trim((string)($createdevent['id'] ?? ''));
                        if ($externalid === '' || strlen($externalid) > 100) {
                            throw new \moodle_exception('bms_google_sync_failed', 'local_kbacompany');
                        }
                        self::bind_mapping($companyuuid, $userid, $cfg['calendar_id'], $localref, $externalid);
                        $created++;
                    }
                } catch (\Throwable $e) {
                    $failed++;
                }
            }
        } finally {
            $lock->release();
        }
        return ['total' => count($meetings), 'created' => $created, 'updated' => $updated, 'failed' => $failed];
    }

    private static function meeting_body(string $companyuuid, array $meeting, string $localref): array {
        $tz = \core_date::get_user_timezone_object();
        $start = (new \DateTimeImmutable('@'.(int)$meeting['starts_at']))->setTimezone($tz);
        $end = (new \DateTimeImmutable('@'.(int)$meeting['ends_at']))->setTimezone($tz);
        $sourceurl = (new \moodle_url('/local/kbacompany/meetings.php', [
            'uuid' => $companyuuid, 'meeting' => (string)$meeting['uuid'],
        ]))->out(false);
        return [
            'summary' => (string)$meeting['title'],
            'description' => get_string('bms_google_bms_meeting_description', 'local_kbacompany') . "\n" . $sourceurl,
            'start' => ['dateTime' => $start->format(DATE_RFC3339), 'timeZone' => $tz->getName()],
            'end' => ['dateTime' => $end->format(DATE_RFC3339), 'timeZone' => $tz->getName()],
            'extendedProperties' => ['private' => [
                'bmsLocalRef' => $localref,
                'bmsCompanyUuid' => $companyuuid,
                'bmsProvider' => 'bms24',
            ]],
        ];
    }

    private static function google_time_to_timestamp(array $data, \DateTimeZone $tz, int $hour): int {
        try {
            if (!empty($data['dateTime'])) {
                return (new \DateTimeImmutable((string)$data['dateTime']))->getTimestamp();
            }
            if (!empty($data['date'])) {
                return (new \DateTimeImmutable((string)$data['date'].' 00:00:00', $tz))->setTime($hour, 0)->getTimestamp();
            }
        } catch (\Throwable $e) {
            return 0;
        }
        return 0;
    }

    private static function events_url(string $calendarid): string {
        return 'https://www.googleapis.com/calendar/v3/calendars/' . rawurlencode($calendarid) . '/events';
    }

    private static function event_url(string $calendarid, string $eventid): string {
        return self::events_url($calendarid) . '/' . rawurlencode($eventid);
    }

    private static function request_json($client, string $method, string $url, array $params = [], array $body = [], bool $throw = false): array {
        $method = strtoupper($method);
        $response = false;
        if ($method === 'GET') {
            $response = $client->get($url, $params);
        } else {
            $json = json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            if ($json === false) { throw new \invalid_parameter_exception('Invalid Google Calendar payload.'); }
            $opts = [CURLOPT_HTTPHEADER => ['Content-Type: application/json']];
            if ($method === 'POST') { $response = $client->post($url, $json, $opts); }
            else if ($method === 'PATCH') { $response = $client->patch($url, $json, $opts); }
            else if ($method === 'DELETE') { $response = $client->delete($url, [], $opts); }
            else { throw new \invalid_parameter_exception('Unsupported HTTP method.'); }
        }
        $info = $client->get_info();
        $code = isset($info['http_code']) ? (int)$info['http_code'] : 0;
        $decoded = is_string($response) && $response !== '' ? json_decode($response, true) : [];
        if (!is_array($decoded)) { $decoded = []; }
        if ($code >= 400 || $response === false) {
            if ($throw) { throw new \moodle_exception('bms_google_sync_failed', 'local_kbacompany'); }
            return [];
        }
        return $decoded;
    }

    private static function namespace_key(int $userid, string $calendarid): string {
        return hash('sha256', json_encode([self::PROVIDER, 'user', $userid, $calendarid]));
    }

    private static function mapping_by_local(string $companyuuid, int $userid, string $calendarid, string $localref): ?\stdClass {
        global $DB;
        $row = $DB->get_record('local_kbacompany_extmap', [
            'companyuuid' => $companyuuid,
            'provider' => self::PROVIDER,
            'namespacekey' => self::namespace_key($userid, $calendarid),
            'entitykind' => self::ENTITY_KIND,
            'localref' => $localref,
            'active' => 1,
        ]);
        return $row ?: null;
    }

    private static function bind_mapping(string $companyuuid, int $userid, string $calendarid, string $localref, string $externalid): void {
        global $DB;
        $namespace = self::namespace_key($userid, $calendarid);
        $externalkey = hash('sha256', json_encode([self::PROVIDER, $namespace, self::ENTITY_KIND, $externalid]));
        $existing = self::mapping_by_local($companyuuid, $userid, $calendarid, $localref);
        if ($existing) {
            if ((string)$existing->externalid !== $externalid) {
                throw new \moodle_exception('bms_int_conflict', 'local_kbacompany');
            }
            return;
        }
        $conflict = $DB->get_record('local_kbacompany_extmap', [
            'companyuuid' => $companyuuid, 'externalkey' => $externalkey,
        ]);
        if ($conflict) { throw new \moodle_exception('bms_int_conflict', 'local_kbacompany'); }
        $now = time();
        $DB->insert_record('local_kbacompany_extmap', (object)[
            'provider' => self::PROVIDER,
            'entitykind' => self::ENTITY_KIND,
            'localref' => $localref,
            'externalid' => $externalid,
            'active' => 1,
            'timecreated' => $now,
            'timemodified' => $now,
            'companyuuid' => $companyuuid,
            'environment' => 'user:' . $userid,
            'externalmodel' => 'calendar.event',
            'namespacekey' => $namespace,
            'mappinguuid' => self::uuid_v4(),
            'externalkey' => $externalkey,
        ]);
    }

    private static function lock(string $companyuuid, int $userid, string $calendarid) {
        $factory = \core\lock\lock_config::get_lock_factory('local_kbacompany_google_calendar');
        $lock = $factory->get_lock(hash('sha256', json_encode([$companyuuid, $userid, $calendarid])), 10);
        if (!$lock) { throw new \moodle_exception('bms_int_busy', 'local_kbacompany'); }
        return $lock;
    }

    private static function uuid_v4(): string {
        $bytes = random_bytes(16);
        $bytes[6] = chr((ord($bytes[6]) & 0x0f) | 0x40);
        $bytes[8] = chr((ord($bytes[8]) & 0x3f) | 0x80);
        $hex = bin2hex($bytes);
        return substr($hex,0,8).'-'.substr($hex,8,4).'-'.substr($hex,12,4).'-'.substr($hex,16,4).'-'.substr($hex,20,12);
    }
}
