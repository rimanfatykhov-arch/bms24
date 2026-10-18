<?php
namespace local_kbacompany\local;

defined('MOODLE_INTERNAL') || die();

/** Stable UUID registry without duplicating source business records. */
class identity_registry {
    public const SOURCE_COMPONENT = 'local_kbacorporate';
    public const COMPANY_ENTITY = 'company';
    public const ORG_UNIT_ENTITY = 'org_unit';
    public const BUSINESS_ROLE_ENTITY = 'business_role';

    /** Return or create the stable UUID for one source object. */
    public static function ensure(string $sourcecomponent, string $sourceentity, int $sourceid): string {
        global $DB;

        self::validate_source($sourcecomponent, $sourceentity, $sourceid);
        $conditions = compact('sourcecomponent', 'sourceentity', 'sourceid');
        $existing = $DB->get_record('local_kbacompany_idreg', $conditions);
        if ($existing) {
            return (string)$existing->uuid;
        }

        $factory = \core\lock\lock_config::get_lock_factory('local_kbacompany');
        $lockkey = 'identity_' . sha1($sourcecomponent . "\n" . $sourceentity . "\n" . $sourceid);
        $lock = $factory->get_lock($lockkey, 10);
        if (!$lock) {
            throw new \moodle_exception('identitylockfailed', 'local_kbacompany');
        }
        try {
            $existing = $DB->get_record('local_kbacompany_idreg', $conditions);
            if ($existing) {
                return (string)$existing->uuid;
            }
            $now = time();
            $uuid = self::uuid_v4();
            $DB->insert_record('local_kbacompany_idreg', (object)[
                'sourcecomponent' => $sourcecomponent,
                'sourceentity' => $sourceentity,
                'sourceid' => $sourceid,
                'uuid' => $uuid,
                'timecreated' => $now,
                'timemodified' => $now,
            ]);
            return $uuid;
        } finally {
            $lock->release();
        }
    }

    /** Resolve a stable UUID to its source registry record. */
    public static function resolve(string $uuid): ?\stdClass {
        global $DB;

        $uuid = strtolower(trim($uuid));
        if (!preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/', $uuid)) {
            return null;
        }
        $record = $DB->get_record('local_kbacompany_idreg', ['uuid' => $uuid]);
        return $record ?: null;
    }

    /** Stable company UUID backed by the current Corporate Dashboard record. */
    public static function company_uuid(int $companyid): string {
        return self::ensure(self::SOURCE_COMPONENT, self::COMPANY_ENTITY, $companyid);
    }

    /** Stable organisation-unit UUID backed by the current source record. */
    public static function org_unit_uuid(int $departmentid): string {
        return self::ensure(self::SOURCE_COMPONENT, self::ORG_UNIT_ENTITY, $departmentid);
    }

    /** Stable business-role UUID backed by the current source record. */
    public static function business_role_uuid(int $roleid): string {
        return self::ensure(self::SOURCE_COMPONENT, self::BUSINESS_ROLE_ENTITY, $roleid);
    }

    private static function validate_source(string $sourcecomponent, string $sourceentity, int $sourceid): void {
        if ($sourceid <= 0 || !preg_match('/^[a-z][a-z0-9_]{1,99}$/', $sourcecomponent) ||
                !preg_match('/^[a-z][a-z0-9_]{1,49}$/', $sourceentity)) {
            throw new \invalid_parameter_exception('Invalid Company Core identity source.');
        }
    }

    private static function uuid_v4(): string {
        $data = random_bytes(16);
        $data[6] = chr((ord($data[6]) & 0x0f) | 0x40);
        $data[8] = chr((ord($data[8]) & 0x3f) | 0x80);
        $hex = bin2hex($data);
        return substr($hex, 0, 8) . '-' . substr($hex, 8, 4) . '-' . substr($hex, 12, 4) . '-' .
            substr($hex, 16, 4) . '-' . substr($hex, 20, 12);
    }
}
