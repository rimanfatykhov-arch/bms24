<?php
namespace local_kbacompany\local;

defined('MOODLE_INTERNAL') || die();

/** Minimal versioned Role DNA root service for People Foundation. */
class role_dna_service {
    public const CONTRACT_VERSION = '0.1.0';
    private const STATUSES = ['draft', 'published', 'superseded', 'archived'];

    public static function contract(): array {
        return [
            'component' => 'local_kbacompany',
            'contract' => 'bms_role_dna_root',
            'version' => self::CONTRACT_VERSION,
            'company_scope' => 'required',
            'canonical_identity' => 'uuid_plus_revision',
            'human_reference' => 'stable_code_RLD',
            'published_immutable' => true,
            'full_semantic_blocks_release' => '0.7.1',
        ];
    }

    public static function list_current(string $companyuuid, int $userid): array {
        global $DB;
        company_context_service::context($companyuuid, $userid);
        if (!$DB->get_manager()->table_exists(new \xmldb_table('local_kbacompany_roledna'))) {
            return [];
        }
        $sql = "SELECT r.*
                  FROM {local_kbacompany_roledna} r
                 WHERE r.companyuuid = :companyuuid
                   AND r.revision = (SELECT MAX(r2.revision)
                                       FROM {local_kbacompany_roledna} r2
                                      WHERE r2.companyuuid = r.companyuuid AND r2.uuid = r.uuid)
              ORDER BY r.title ASC, r.uuid ASC";
        return array_values($DB->get_records_sql($sql, ['companyuuid' => $companyuuid]));
    }

    /** Create only the Role DNA root. Semantic blocks are intentionally deferred to 0.7.1. */
    public static function create_draft(string $companyuuid, int $userid, string $title,
            string $positionuuid = '', string $businessroleuuid = '', string $purpose = ''): string {
        global $DB;
        company_context_service::context($companyuuid, $userid);
        if (!permission_service::can_manage_people($companyuuid, $userid)) {
            throw new \required_capability_exception(\context_system::instance(),
                'local/kbacompany:managepeople', 'nopermissions', '');
        }
        $title = trim($title);
        if ($title === '') {
            throw new \invalid_parameter_exception('Role DNA title is required');
        }
        if ($positionuuid !== '' && !$DB->record_exists('local_kbacompany_position', [
                'companyuuid' => $companyuuid, 'uuid' => $positionuuid, 'active' => 1])) {
            throw new \moodle_exception('invalidrecord', 'error');
        }
        $uuid = self::uuid_v4();
        $code = stable_code_service::next($companyuuid, 'people:roledna', 'RLD',
            static function(string $candidate) use ($DB, $companyuuid): bool {
                return !$DB->record_exists('local_kbacompany_roledna', [
                    'companyuuid' => $companyuuid, 'stablecode' => $candidate,
                ]);
            });
        $now = time();
        $DB->insert_record('local_kbacompany_roledna', (object)[
            'companyuuid' => $companyuuid,
            'uuid' => $uuid,
            'revision' => 1,
            'stablecode' => $code,
            'status' => 'draft',
            'positionuuid' => trim($positionuuid),
            'businessroleuuid' => trim($businessroleuuid),
            'title' => $title,
            'purpose' => trim($purpose),
            'methodologyversion' => 'people-v0.1',
            'createdby' => $userid,
            'modifiedby' => $userid,
            'timecreated' => $now,
            'timemodified' => $now,
        ]);
        return $uuid;
    }

    private static function uuid_v4(): string {
        $bytes = random_bytes(16);
        $bytes[6] = chr((ord($bytes[6]) & 0x0f) | 0x40);
        $bytes[8] = chr((ord($bytes[8]) & 0x3f) | 0x80);
        $hex = bin2hex($bytes);
        return substr($hex, 0, 8) . '-' . substr($hex, 8, 4) . '-' .
            substr($hex, 12, 4) . '-' . substr($hex, 16, 4) . '-' . substr($hex, 20);
    }
}
