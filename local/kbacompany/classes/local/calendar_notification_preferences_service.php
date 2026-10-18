<?php
namespace local_kbacompany\local;

defined('MOODLE_INTERNAL') || die();

/** Company defaults + per-user overrides for BMS Calendar notifications. */
class calendar_notification_preferences_service {
    public const CONTRACT_VERSION = '1.1.0-alpha13';
    private const MEETING_LEADS = [15, 30, 60, 120];
    private const MORNING_HOURS = [6, 7, 8, 9, 10, 11];

    public static function contract(): array {
        return [
            'version' => self::CONTRACT_VERSION,
            'owner' => 'calendar_notification_preferences',
            'storage' => [
                'company_defaults' => 'config_plugins scoped by Company UUID hash',
                'personal_override' => 'Moodle user_preferences scoped by Company UUID hash',
            ],
            'rules' => [
                'company_defaults_require_manage_organization',
                'users_may_edit_only_their_own_personal_preferences',
                'personal_preferences_never_grant_access_to_calendar_objects',
                'quiet_hours_suppress_delivery_without_business_object_mutation',
                'moodle_processor_channels_remain_owned_by_moodle_notification_preferences',
                'no_new_calendar_or_reminder_table',
            ],
        ];
    }

    public static function defaults(): array {
        return [
            'enabled' => true,
            'meeting_enabled' => true,
            'meeting_lead' => 60,
            'morning_digest' => true,
            'morning_hour' => 8,
            'task_due' => true,
            'checkpoint_tomorrow' => true,
            'quiet_enabled' => false,
            'quiet_start' => '22:00',
            'quiet_end' => '07:00',
        ];
    }

    private static function company_key(string $companyuuid): string {
        return 'calendar_notify_' . substr(hash('sha256', $companyuuid), 0, 32);
    }

    private static function user_key(string $companyuuid): string {
        return 'local_kbacompany_calnotify_' . substr(hash('sha256', $companyuuid), 0, 24);
    }

    private static function decode($raw): array {
        if (!is_string($raw) || trim($raw) === '') { return []; }
        $data = json_decode($raw, true);
        return is_array($data) ? $data : [];
    }

    private static function validate(array $input): array {
        $lead = (int)($input['meeting_lead'] ?? 60);
        if (!in_array($lead, self::MEETING_LEADS, true)) { $lead = 60; }
        $hour = (int)($input['morning_hour'] ?? 8);
        if (!in_array($hour, self::MORNING_HOURS, true)) { $hour = 8; }
        $start = self::time_value((string)($input['quiet_start'] ?? '22:00'), '22:00');
        $end = self::time_value((string)($input['quiet_end'] ?? '07:00'), '07:00');
        return [
            'enabled' => !empty($input['enabled']),
            'meeting_enabled' => !empty($input['meeting_enabled']),
            'meeting_lead' => $lead,
            'morning_digest' => !empty($input['morning_digest']),
            'morning_hour' => $hour,
            'task_due' => !empty($input['task_due']),
            'checkpoint_tomorrow' => !empty($input['checkpoint_tomorrow']),
            'quiet_enabled' => !empty($input['quiet_enabled']),
            'quiet_start' => $start,
            'quiet_end' => $end,
        ];
    }

    private static function time_value(string $value, string $fallback): string {
        $value = trim($value);
        return preg_match('/^(?:[01]\d|2[0-3]):[0-5]\d$/D', $value) ? $value : $fallback;
    }

    public static function company_defaults(string $companyuuid, int $actor): array {
        $companyuuid = company_context_service::normalise_uuid($companyuuid);
        company_context_service::context($companyuuid, $actor);
        $stored = self::decode(get_config('local_kbacompany', self::company_key($companyuuid)));
        return self::validate(array_merge(self::defaults(), $stored));
    }

    public static function personal_state(string $companyuuid, int $userid): array {
        $companyuuid = company_context_service::normalise_uuid($companyuuid);
        company_context_service::context($companyuuid, $userid);
        $raw = get_user_preferences(self::user_key($companyuuid), '', $userid);
        $stored = self::decode($raw);
        $usecompany = !array_key_exists('use_company', $stored) || !empty($stored['use_company']);
        $settings = self::validate(array_merge(self::defaults(), $stored['settings'] ?? []));
        return ['use_company' => $usecompany, 'settings' => $settings];
    }

    public static function effective(string $companyuuid, int $userid): array {
        $company = self::company_defaults($companyuuid, $userid);
        $personal = self::personal_state($companyuuid, $userid);
        $effective = $personal['use_company'] ? $company : self::validate(array_merge($company, $personal['settings']));
        $effective['source'] = $personal['use_company'] ? 'company' : 'personal';
        return $effective;
    }

    public static function save_personal(string $companyuuid, int $userid, bool $usecompany, array $settings): void {
        $companyuuid = company_context_service::normalise_uuid($companyuuid);
        company_context_service::context($companyuuid, $userid);
        $payload = ['use_company' => $usecompany, 'settings' => self::validate($settings)];
        set_user_preference(self::user_key($companyuuid), json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), $userid);
    }

    public static function reset_personal(string $companyuuid, int $userid): void {
        $companyuuid = company_context_service::normalise_uuid($companyuuid);
        company_context_service::context($companyuuid, $userid);
        unset_user_preference(self::user_key($companyuuid), $userid);
    }

    public static function save_company_defaults(string $companyuuid, int $actor, array $settings): void {
        $companyuuid = company_context_service::normalise_uuid($companyuuid);
        company_context_service::context($companyuuid, $actor);
        if (!permission_service::can_manage_organization($companyuuid, $actor)) {
            throw new \moodle_exception('nopermissions', 'error');
        }
        set_config(self::company_key($companyuuid),
            json_encode(self::validate($settings), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'local_kbacompany');
    }

    public static function is_quiet(array $settings, \DateTimeImmutable $localnow): bool {
        if (empty($settings['quiet_enabled'])) { return false; }
        $now = ((int)$localnow->format('G')) * 60 + (int)$localnow->format('i');
        $start = self::minutes((string)$settings['quiet_start']);
        $end = self::minutes((string)$settings['quiet_end']);
        if ($start === $end) { return false; }
        return $start < $end ? ($now >= $start && $now < $end) : ($now >= $start || $now < $end);
    }

    private static function minutes(string $time): int {
        list($h, $m) = array_map('intval', explode(':', $time));
        return $h * 60 + $m;
    }

    public static function meeting_leads(): array { return self::MEETING_LEADS; }
    public static function morning_hours(): array { return self::MORNING_HOURS; }
}
