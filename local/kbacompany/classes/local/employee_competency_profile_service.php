<?php
namespace local_kbacompany\local;

defined('MOODLE_INTERNAL') || die();

/** Employee competency evidence profile compared with published position requirements. */
class employee_competency_profile_service {
    public const CONTRACT_VERSION = '0.7.2';
    private const SOURCES = ['manual', 'self_reported', 'manager_observation', 'moodle', 'assessment'];

    public static function contract(): array {
        return [
            'component' => 'local_kbacompany',
            'contract' => 'bms_employee_competency_profile',
            'version' => self::CONTRACT_VERSION,
            'company_scope' => 'required_before_fetch',
            'rules' => [
                'employee_profile_compares_evidence_with_published_position_profile',
                'employee_competency_record_is_evidence_snapshot_not_assessment_engine',
                'course_completion_does_not_confirm_competency_attainment',
                'missing_evidence_is_unknown_not_zero_competence',
                'position_change_recomputes_gap_without_rewriting_evidence',
                'formal_assessment_projection_is_owned_by_assessment_engine_0_7_3',
            ],
        ];
    }

    public static function context(string $companyuuid, int $employeeuserid, int $actorid): array {
        global $DB;
        $employeecontext = employee_service::employee_context($companyuuid, $employeeuserid, $actorid, true);
        $employee = $employeecontext['employee'];
        $catalogrows = competency_catalog_service::competencies($companyuuid, $actorid);
        $catalog = [];
        foreach ($catalogrows as $row) {
            $catalog[(int)$row['id']] = $row;
        }
        $evidence = $DB->get_records('local_kbacompany_empcomp', [
            'companyuuid' => $companyuuid, 'userid' => $employeeuserid,
        ]);
        $evidencemap = [];
        foreach ($evidence as $row) {
            $evidencemap[(int)$row->competencyid] = $row;
        }

        $position = $employee['position'] ?? null;
        $profile = null;
        $requirements = [];
        if ($position && !empty($position['uuid'])) {
            $profiles = $DB->get_records('local_kbacompany_roledna', [
                'companyuuid' => $companyuuid,
                'positionuuid' => (string)$position['uuid'],
                'status' => 'published',
            ], 'revision DESC, id DESC');
            $profile = $profiles ? reset($profiles) : null;
            if ($profile) {
                $requirements = $DB->get_records('local_kbacompany_rolednacomp', [
                    'companyuuid' => $companyuuid,
                    'rolednauuid' => (string)$profile->uuid,
                    'rolednarevision' => (int)$profile->revision,
                ], 'required DESC, priority DESC, id ASC');
            }
        }

        $rows = [];
        $summary = ['total' => 0, 'met' => 0, 'gap' => 0, 'unknown' => 0, 'required_gap' => 0];
        foreach ($requirements as $req) {
            $cid = (int)$req->competencyid;
            if (!isset($catalog[$cid])) {
                continue;
            }
            $fact = $evidencemap[$cid] ?? null;
            $current = $fact ? (int)$fact->currentlevel : null;
            $target = (int)$req->targetlevel > 0 ? (int)$req->targetlevel : null;
            if ($current === null) {
                $state = 'unknown';
                $gap = null;
            } else if ($target === null || $current >= $target) {
                $state = 'met';
                $gap = 0;
            } else {
                $state = 'gap';
                $gap = $target - $current;
            }
            $summary['total']++;
            $summary[$state]++;
            if ($state === 'gap' && !empty($req->required)) {
                $summary['required_gap']++;
            }
            $rows[] = [
                'competency_id' => $cid,
                'code' => (string)$catalog[$cid]['idnumber'],
                'name' => (string)$catalog[$cid]['shortname'],
                'target_level' => $target,
                'current_level' => $current,
                'gap' => $gap,
                'state' => $state,
                'priority' => (string)$req->priority,
                'required' => !empty($req->required),
                'source' => $fact ? (string)$fact->sourcekind : '',
                'evidence' => $fact ? (string)$fact->evidence : '',
                'evidence_url' => $fact ? (string)$fact->evidenceurl : '',
                'recorded_at' => $fact ? (int)$fact->recordedat : 0,
            ];
        }

        return [
            'company' => $employeecontext['company'],
            'employee' => $employee,
            'position' => $position,
            'position_profile' => $profile ? [
                'uuid' => (string)$profile->uuid,
                'revision' => (int)$profile->revision,
                'stable_code' => (string)$profile->stablecode,
            ] : null,
            'requirements' => $rows,
            'summary' => $summary,
            'can_manage' => permission_service::can_manage_people($companyuuid, $actorid),
        ];
    }

    public static function save_evidence(string $companyuuid, int $employeeuserid, int $actorid,
            int $competencyid, int $currentlevel, string $sourcekind, string $evidence, string $evidenceurl): int {
        global $DB;
        company_context_service::context($companyuuid, $actorid);
        employee_service::employee_context($companyuuid, $employeeuserid, $actorid, true);
        if (!permission_service::can_manage_people($companyuuid, $actorid) && !is_siteadmin($actorid)) {
            throw new \required_capability_exception(\context_system::instance(),
                'local/kbacompany:managepeople', 'nopermissions', '');
        }
        if ($currentlevel < 0 || $currentlevel > 100) {
            throw new \invalid_parameter_exception('Invalid competency level');
        }
        if (!in_array($sourcekind, self::SOURCES, true)) {
            throw new \invalid_parameter_exception('Invalid evidence source');
        }
        $allowed = array_map('intval', array_column(competency_catalog_service::competencies($companyuuid, $actorid), 'id'));
        if (!in_array($competencyid, $allowed, true)) {
            throw new \moodle_exception('invalidrecord', 'error');
        }
        $conditions = ['companyuuid' => $companyuuid, 'userid' => $employeeuserid, 'competencyid' => $competencyid];
        $record = $DB->get_record('local_kbacompany_empcomp', $conditions);
        $now = time();
        $values = [
            'currentlevel' => $currentlevel,
            'sourcekind' => $sourcekind,
            'evidence' => trim($evidence),
            'evidenceurl' => trim($evidenceurl),
            'recordedby' => $actorid,
            'recordedat' => $now,
            'timemodified' => $now,
        ];
        if ($record) {
            foreach ($values as $key => $value) {
                $record->{$key} = $value;
            }
            $DB->update_record('local_kbacompany_empcomp', $record);
            return (int)$record->id;
        }
        return (int)$DB->insert_record('local_kbacompany_empcomp', (object)($conditions + $values + ['timecreated' => $now]));
    }
}
