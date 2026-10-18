<?php
namespace local_kbacompany\local;

defined('MOODLE_INTERNAL') || die();

/** Versioned Position Competency Profile built on Role DNA and Moodle competencies. */
class position_competency_profile_service {
    public const CONTRACT_VERSION = '0.7.1';
    private const PRIORITIES = ['low', 'normal', 'high', 'critical'];

    public static function contract(): array {
        return [
            'component' => 'local_kbacompany',
            'contract' => 'bms_position_competency_profile',
            'version' => self::CONTRACT_VERSION,
            'company_scope' => 'required_before_fetch',
            'root' => 'RoleDNA',
            'competency_engine' => 'moodle_core_competencies',
            'published_immutable' => true,
            'rules' => [
                'position_profile_is_versioned_role_dna',
                'requirements_reference_authorised_moodle_competencies',
                'target_requirement_does_not_change_employee_assessment',
                'course_completion_does_not_confirm_competency_attainment',
                'published_revision_is_immutable',
                'editing_published_profile_creates_new_draft_revision',
            ],
        ];
    }

    public static function context(string $companyuuid, int $userid, string $positionuuid): array {
        global $DB;
        $company = company_context_service::context($companyuuid, $userid);
        $position = self::position($companyuuid, $positionuuid);
        $competencies = competency_catalog_service::competencies($companyuuid, $userid);
        $allowed = array_fill_keys(array_map('intval', array_column($competencies, 'id')), true);
        $rows = $DB->get_records('local_kbacompany_roledna', [
            'companyuuid' => $companyuuid, 'positionuuid' => $positionuuid,
        ], 'revision DESC, id DESC');
        $profile = $rows ? reset($rows) : null;
        $requirements = [];
        if ($profile) {
            $records = $DB->get_records('local_kbacompany_rolednacomp', [
                'companyuuid' => $companyuuid,
                'rolednauuid' => (string)$profile->uuid,
                'rolednarevision' => (int)$profile->revision,
            ], 'required DESC, priority DESC, id ASC');
            $catalog = [];
            foreach ($competencies as $competency) {
                $catalog[(int)$competency['id']] = $competency;
            }
            foreach ($records as $record) {
                $cid = (int)$record->competencyid;
                if (!isset($allowed[$cid]) || !isset($catalog[$cid])) {
                    continue;
                }
                $requirements[] = [
                    'id' => (int)$record->id,
                    'competency_id' => $cid,
                    'code' => (string)$catalog[$cid]['idnumber'],
                    'name' => (string)$catalog[$cid]['shortname'],
                    'target_level' => (int)$record->targetlevel > 0 ? (int)$record->targetlevel : null,
                    'priority' => (string)$record->priority,
                    'required' => !empty($record->required),
                ];
            }
        }
        return [
            'company' => $company,
            'position' => [
                'uuid' => (string)$position->uuid,
                'code' => (string)$position->code,
                'name' => (string)$position->name,
                'purpose' => (string)$position->purpose,
            ],
            'profile' => $profile ? [
                'uuid' => (string)$profile->uuid,
                'revision' => (int)$profile->revision,
                'stable_code' => (string)$profile->stablecode,
                'status' => (string)$profile->status,
                'title' => (string)$profile->title,
                'purpose' => (string)$profile->purpose,
            ] : null,
            'requirements' => $requirements,
            'competencies' => $competencies,
            'can_manage' => permission_service::can_manage_people($companyuuid, $userid),
            'summary' => [
                'requirements' => count($requirements),
                'required' => count(array_filter($requirements, static function(array $r): bool { return $r['required']; })),
                'high_priority' => count(array_filter($requirements, static function(array $r): bool {
                    return in_array($r['priority'], ['high', 'critical'], true);
                })),
            ],
        ];
    }

    public static function ensure_draft(string $companyuuid, int $userid, string $positionuuid): array {
        global $DB;
        self::require_manage($companyuuid, $userid);
        $position = self::position($companyuuid, $positionuuid);
        $rows = $DB->get_records('local_kbacompany_roledna', [
            'companyuuid' => $companyuuid, 'positionuuid' => $positionuuid,
        ], 'revision DESC, id DESC');
        $latest = $rows ? reset($rows) : null;
        if ($latest && (string)$latest->status === 'draft') {
            return ['uuid' => (string)$latest->uuid, 'revision' => (int)$latest->revision];
        }
        if (!$latest) {
            $uuid = role_dna_service::create_draft($companyuuid, $userid,
                (string)$position->name, $positionuuid, '', (string)$position->purpose);
            return ['uuid' => $uuid, 'revision' => 1];
        }
        $now = time();
        $revision = (int)$latest->revision + 1;
        $DB->insert_record('local_kbacompany_roledna', (object)[
            'companyuuid' => $companyuuid,
            'uuid' => (string)$latest->uuid,
            'revision' => $revision,
            'stablecode' => (string)$latest->stablecode,
            'status' => 'draft',
            'positionuuid' => $positionuuid,
            'businessroleuuid' => (string)$latest->businessroleuuid,
            'title' => (string)$latest->title,
            'purpose' => (string)$latest->purpose,
            'methodologyversion' => 'people-v0.2',
            'createdby' => $userid,
            'modifiedby' => $userid,
            'timecreated' => $now,
            'timemodified' => $now,
        ]);
        $oldreqs = $DB->get_records('local_kbacompany_rolednacomp', [
            'companyuuid' => $companyuuid,
            'rolednauuid' => (string)$latest->uuid,
            'rolednarevision' => (int)$latest->revision,
        ]);
        foreach ($oldreqs as $req) {
            $DB->insert_record('local_kbacompany_rolednacomp', (object)[
                'companyuuid' => $companyuuid,
                'rolednauuid' => (string)$latest->uuid,
                'rolednarevision' => $revision,
                'competencyid' => (int)$req->competencyid,
                'targetlevel' => (int)$req->targetlevel,
                'priority' => (string)$req->priority,
                'required' => (int)$req->required,
                'createdby' => $userid,
                'timecreated' => $now,
                'timemodified' => $now,
            ]);
        }
        return ['uuid' => (string)$latest->uuid, 'revision' => $revision];
    }

    public static function save_requirement(string $companyuuid, int $userid, string $positionuuid,
            int $competencyid, int $targetlevel, string $priority, bool $required): int {
        global $DB;
        self::require_manage($companyuuid, $userid);
        if (!in_array($priority, self::PRIORITIES, true)) {
            throw new \invalid_parameter_exception('Invalid priority');
        }
        if ($targetlevel < 0 || $targetlevel > 100) {
            throw new \invalid_parameter_exception('Invalid target level');
        }
        $allowed = array_map('intval', array_column(competency_catalog_service::competencies($companyuuid, $userid), 'id'));
        if (!in_array($competencyid, $allowed, true)) {
            throw new \moodle_exception('invalidrecord', 'error');
        }
        $draft = self::ensure_draft($companyuuid, $userid, $positionuuid);
        $conditions = [
            'companyuuid' => $companyuuid,
            'rolednauuid' => $draft['uuid'],
            'rolednarevision' => $draft['revision'],
            'competencyid' => $competencyid,
        ];
        $record = $DB->get_record('local_kbacompany_rolednacomp', $conditions);
        $now = time();
        if ($record) {
            $record->targetlevel = $targetlevel;
            $record->priority = $priority;
            $record->required = $required ? 1 : 0;
            $record->timemodified = $now;
            $DB->update_record('local_kbacompany_rolednacomp', $record);
            return (int)$record->id;
        }
        return (int)$DB->insert_record('local_kbacompany_rolednacomp', (object)($conditions + [
            'targetlevel' => $targetlevel,
            'priority' => $priority,
            'required' => $required ? 1 : 0,
            'createdby' => $userid,
            'timecreated' => $now,
            'timemodified' => $now,
        ]));
    }

    public static function remove_requirement(string $companyuuid, int $userid, string $positionuuid, int $id): void {
        global $DB;
        self::require_manage($companyuuid, $userid);
        $draft = self::ensure_draft($companyuuid, $userid, $positionuuid);
        $DB->delete_records('local_kbacompany_rolednacomp', [
            'id' => $id,
            'companyuuid' => $companyuuid,
            'rolednauuid' => $draft['uuid'],
            'rolednarevision' => $draft['revision'],
        ]);
    }

    public static function publish(string $companyuuid, int $userid, string $positionuuid): void {
        global $DB;
        self::require_manage($companyuuid, $userid);
        self::position($companyuuid, $positionuuid);
        $draft = self::ensure_draft($companyuuid, $userid, $positionuuid);
        $profile = $DB->get_record('local_kbacompany_roledna', [
            'companyuuid' => $companyuuid, 'uuid' => $draft['uuid'], 'revision' => $draft['revision'],
        ], '*', MUST_EXIST);
        $count = $DB->count_records('local_kbacompany_rolednacomp', [
            'companyuuid' => $companyuuid, 'rolednauuid' => $draft['uuid'], 'rolednarevision' => $draft['revision'],
        ]);
        if ($count < 1) {
            throw new \moodle_exception('positioncompetency_publish_empty', 'local_kbacompany');
        }
        $profile->status = 'published';
        $profile->methodologyversion = 'people-v0.2';
        $profile->modifiedby = $userid;
        $profile->timemodified = time();
        $DB->update_record('local_kbacompany_roledna', $profile);
    }

    private static function require_manage(string $companyuuid, int $userid): void {
        company_context_service::context($companyuuid, $userid);
        if (!permission_service::can_manage_people($companyuuid, $userid)) {
            throw new \required_capability_exception(\context_system::instance(),
                'local/kbacompany:managepeople', 'nopermissions', '');
        }
    }

    private static function position(string $companyuuid, string $positionuuid): \stdClass {
        global $DB;
        return $DB->get_record('local_kbacompany_position', [
            'companyuuid' => $companyuuid, 'uuid' => $positionuuid, 'active' => 1,
        ], '*', MUST_EXIST);
    }
}
