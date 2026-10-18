<?php
namespace local_bitrixsync\services;

use local_bitrixsync\adapters\corporate\api_client;

defined('MOODLE_INTERNAL') || die();

/** Safe MERGE-only Bitrix Contact roles -> Corporate Dashboard handoff. */
class corporate_role_sync_service {
    /** @var corporate_role_schema_service */
    private $schema;
    /** @var api_client */
    private $corporate;

    public function __construct(?corporate_role_schema_service $schema = null, ?api_client $corporate = null) {
        $this->schema = $schema ?? new corporate_role_schema_service();
        $this->corporate = $corporate ?? new api_client();
    }

    public function merge(int $userid, int $companyid, int $bitrixcontactid): array {
        if ($userid <= 0 || $companyid <= 0 || $bitrixcontactid <= 0) {
            return ['status' => 'not_applicable', 'roles' => []];
        }

        $read = $this->schema->read_contact_roles($bitrixcontactid);
        if (empty($read['success'])) {
            return [
                'status' => 'skipped',
                'roles' => [],
                'reason' => (string)($read['code'] ?? 'role_read_failed'),
                'detail' => $read,
            ];
        }

        $roles = is_array($read['roles'] ?? null) ? $read['roles'] : [];
        if (!$roles) {
            return ['status' => 'no_roles', 'roles' => []];
        }

        $result = $this->corporate->merge_employee_roles($userid, $companyid, $roles);
        if (!empty($result['skipped'])) {
            return [
                'status' => 'skipped',
                'roles' => $roles,
                'reason' => (string)($result['reason'] ?? 'corporate_role_merge_unavailable'),
                'result' => $result,
            ];
        }

        return [
            'status' => !empty($result['success']) ? 'merged' : 'error',
            'strategy' => 'merge',
            'roles' => $roles,
            'result' => $result,
        ];
    }
}
