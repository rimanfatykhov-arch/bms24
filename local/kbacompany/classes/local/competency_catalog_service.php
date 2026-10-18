<?php
namespace local_kbacompany\local;

defined('MOODLE_INTERNAL') || die();

/** Company-scoped governance facade over Moodle Core Competencies. */
class competency_catalog_service {
    public const CONTRACT_VERSION = '0.1.0';

    public static function contract(): array {
        return [
            'component' => 'local_kbacompany',
            'contract' => 'bms_competency_catalog',
            'version' => self::CONTRACT_VERSION,
            'company_scope' => 'required_before_fetch',
            'technical_engine' => 'moodle_core_competencies',
            'domain_owner' => 'bms_management',
            'write_policy' => 'governance_binding_only',
            'rules' => [
                'no_parallel_competency_registry',
                'allowed_frameworks_resolved_before_competency_fetch',
                'course_completion_does_not_confirm_competency_attainment',
                'site_admin_mapping_is_explicit',
            ],
        ];
    }

    /** Return only frameworks explicitly bound to the authorised company. */
    public static function frameworks(string $companyuuid, int $userid): array {
        global $DB;
        company_context_service::context($companyuuid, $userid);
        if (!$DB->get_manager()->table_exists(new \xmldb_table('local_kbacompany_compfw'))) {
            return [];
        }
        $bindings = $DB->get_records('local_kbacompany_compfw', [
            'companyuuid' => $companyuuid, 'active' => 1,
        ], 'frameworkid ASC');
        if (!$bindings) {
            return [];
        }
        $ids = array_values(array_unique(array_map(static function($row): int {
            return (int)$row->frameworkid;
        }, $bindings)));
        list($insql, $params) = $DB->get_in_or_equal($ids, SQL_PARAMS_NAMED, 'fw');
        $frameworks = $DB->get_records_select('competency_framework', "id {$insql}", $params, 'id ASC');
        $result = [];
        foreach ($bindings as $binding) {
            $id = (int)$binding->frameworkid;
            if (!isset($frameworks[$id])) {
                continue;
            }
            $framework = $frameworks[$id];
            $result[] = [
                'id' => $id,
                'shortname' => (string)$framework->shortname,
                'idnumber' => (string)$framework->idnumber,
                'scope' => (string)$binding->scope,
                'visible' => isset($framework->visible) ? (bool)$framework->visible : true,
            ];
        }
        return $result;
    }

    /** Return competency records only from already-authorised framework IDs. */
    public static function competencies(string $companyuuid, int $userid): array {
        global $DB;
        $frameworks = self::frameworks($companyuuid, $userid);
        $ids = array_column($frameworks, 'id');
        if (!$ids) {
            return [];
        }
        list($insql, $params) = $DB->get_in_or_equal($ids, SQL_PARAMS_NAMED, 'cfw');
        $records = $DB->get_records_select('competency', "competencyframeworkid {$insql}", $params,
            'competencyframeworkid ASC, sortorder ASC, id ASC');
        $result = [];
        foreach ($records as $record) {
            $result[] = [
                'id' => (int)$record->id,
                'framework_id' => (int)$record->competencyframeworkid,
                'shortname' => (string)$record->shortname,
                'idnumber' => (string)$record->idnumber,
            ];
        }
        return $result;
    }

    /** Explicit governance binding. Does not create or copy Moodle competencies. */
    public static function bind_framework(string $companyuuid, int $userid, int $frameworkid,
            string $scope = 'company'): int {
        global $DB;
        company_context_service::context($companyuuid, $userid);
        if (!permission_service::can_manage_people($companyuuid, $userid)) {
            throw new \required_capability_exception(\context_system::instance(),
                'local/kbacompany:managepeople', 'nopermissions', '');
        }
        if (!in_array($scope, ['company', 'reference'], true)) {
            throw new \invalid_parameter_exception('Invalid competency framework scope');
        }
        if (!$DB->record_exists('competency_framework', ['id' => $frameworkid])) {
            throw new \moodle_exception('invalidrecord', 'error');
        }
        $existing = $DB->get_record('local_kbacompany_compfw', [
            'companyuuid' => $companyuuid, 'frameworkid' => $frameworkid,
        ]);
        $now = time();
        if ($existing) {
            $existing->scope = $scope;
            $existing->active = 1;
            $existing->timemodified = $now;
            $DB->update_record('local_kbacompany_compfw', $existing);
            return (int)$existing->id;
        }
        return (int)$DB->insert_record('local_kbacompany_compfw', (object)[
            'companyuuid' => $companyuuid,
            'frameworkid' => $frameworkid,
            'scope' => $scope,
            'active' => 1,
            'createdby' => $userid,
            'timecreated' => $now,
            'timemodified' => $now,
        ]);
    }
}
