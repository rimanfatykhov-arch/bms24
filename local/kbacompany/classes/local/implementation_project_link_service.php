<?php
namespace local_kbacompany\local;

defined('MOODLE_INTERNAL') || die();

use local_kbacompany\local\task_provider\kbacorporate_impl_task_provider;

/** Explicit, one-to-one Corporate program <-> existing BMS Administrative Scale Project link. */
final class implementation_project_link_service {
    public const TABLE = 'local_kbacompany_implproj';
    public const BRIDGE = '\\local_kbacorporate\local\bridge';

    public static function contract(): array {
        return [
            'version' => '1.0', 'read_only_corporate' => true,
            'project_source' => 'administrative_scale', 'company_identity' => 'verified_bound_provider',
            'rules' => ['no_implicit_project_creation', 'one_program_one_project',
                'one_project_one_program', 'no_task_copy', 'corporate_owns_task_lifecycle',
                'permission_checked_on_both_sides', 'link_by_explicit_post'],
        ];
    }

    /** Separate BMS AND Corporate administration required; never infer from title. */
    private static function corporate_id(string $uuid, int $actor): int {
        company_context_service::context($uuid, $actor);
        if (!administrative_scale_service::can_manage($uuid, $actor)) {
            throw new \moodle_exception('implproj_permission', 'local_kbacompany');
        }
        $provider = new kbacorporate_impl_task_provider();
        $bridge = self::BRIDGE;
        if (!$provider->available() || !class_exists($bridge) ||
                !method_exists($bridge, 'list_implementation_program_projections') ||
                !method_exists($bridge, 'get_implementation_program_projection') ||
                version_compare((string)$bridge::API_RELEASE, '1.23', '<')) {
            throw new \moodle_exception('implproj_unavailable', 'local_kbacompany');
        }
        $id = $provider->linked_company_id($uuid, $actor);
        if ($id <= 0) {
            throw new \moodle_exception('implproj_source', 'local_kbacompany');
        }
        return $id;
    }

    /** Only currently published projects are linkable; drafts and archived never qualify. */
    public static function eligible_projects(array $adminitems): array {
        $out = [];
        foreach ($adminitems as $item) {
            if (($item['kind'] ?? '') === 'project' && ($item['status'] ?? '') === 'published' &&
                    empty($item['stale']) && !empty($item['uuid'])) {
                $out[(string)$item['uuid']] = ['uuid' => (string)$item['uuid'],
                    'title' => (string)($item['name'] ?? '')];
            }
        }
        return $out;
    }

    public static function overview(string $uuid, int $actor, int $page = 0): array {
        global $DB;
        $companyid = self::corporate_id($uuid, $actor);
        $page = max(0, min(200, $page));
        $bridge = self::BRIDGE;
        $programs = $bridge::list_implementation_program_projections($companyid, $actor, 50, 50 * $page);
        $admin = administrative_scale_service::context($uuid, $actor);
        $projects = self::eligible_projects($admin['items']);
        $links = $DB->get_records(self::TABLE, ['companyuuid' => $uuid]);
        $linked = [];
        $reservedprojects = [];
        foreach ($links as $link) {
            // Do not expose records if source company identity has changed.
            if ((int)$link->corporatecompanyid !== $companyid) { continue; }
            $reservedprojects[(string)$link->projectuuid] = true;
            $linked[(int)$link->programid] = [
                'projectuuid' => (string)$link->projectuuid,
                'project' => $projects[(string)$link->projectuuid]['title'] ?? '',
                'valid_project' => isset($projects[(string)$link->projectuuid]),
            ];
        }
        foreach ($programs as &$program) {
            if ((int)($program['company_id'] ?? 0) !== $companyid) {
                throw new \moodle_exception('implproj_source', 'local_kbacompany');
            }
            $program['linked'] = $linked[(int)$program['program_id']] ?? null;
        }
        unset($program);
        $availableprojects = array_diff_key($projects, $reservedprojects);
        return ['programs' => $programs, 'projects' => $availableprojects,
            'page' => $page, 'has_next' => count($programs) === 50];
    }

    /** Returns one stable link; ID checked against both authorised source APIs. */
    public static function link(string $uuid, int $actor, int $programid, string $projectuuid): void {
        global $DB;
        $companyid = self::corporate_id($uuid, $actor);
        if ($programid <= 0 || !preg_match('/^[0-9a-f-]{36}$/D', $projectuuid)) {
            throw new \invalid_parameter_exception('Invalid project link input');
        }
        $bridge = self::BRIDGE;
        $program = $bridge::get_implementation_program_projection($companyid, $actor, $programid);
        if (!$program || (int)$program['company_id'] !== $companyid) {
            throw new \moodle_exception('implproj_program', 'local_kbacompany');
        }
        $admin = administrative_scale_service::context($uuid, $actor);
        $projects = self::eligible_projects($admin['items']);
        if (!isset($projects[$projectuuid])) {
            throw new \moodle_exception('implproj_project', 'local_kbacompany');
        }
        // In-flight competing updates are serialised at company granularity.
        $lock = \core\lock\lock_config::get_lock_factory('local_kbacompany')->get_lock('implproj:' . $uuid, 10);
        if (!$lock) { throw new \moodle_exception('implproj_conflict', 'local_kbacompany'); }
        try {
            $existing = $DB->get_record(self::TABLE, ['companyuuid' => $uuid, 'programid' => $programid]);
            if ($existing) {
                if ($existing->projectuuid === $projectuuid && (int)$existing->corporatecompanyid === $companyid) {
                    return;
                }
                throw new \moodle_exception('implproj_conflict', 'local_kbacompany');
            }
            if ($DB->record_exists(self::TABLE, ['companyuuid' => $uuid, 'projectuuid' => $projectuuid])) {
                throw new \moodle_exception('implproj_conflict', 'local_kbacompany');
            }
            $now = time();
            $DB->insert_record(self::TABLE, (object)[
                'companyuuid' => $uuid, 'corporatecompanyid' => $companyid,
                'programid' => $programid, 'projectuuid' => $projectuuid,
                'createdby' => $actor, 'timecreated' => $now,
            ]);
        } finally {
            $lock->release();
        }
    }

    /** Explicit unlink leaves Corporate and Administrative Scale objects unchanged. */
    public static function unlink(string $uuid, int $actor, int $programid): void {
        global $DB;
        $companyid = self::corporate_id($uuid, $actor);
        $old = $DB->get_record(self::TABLE, ['companyuuid' => $uuid, 'programid' => $programid,
            'corporatecompanyid' => $companyid]);
        if (!$old) { throw new \moodle_exception('implproj_program', 'local_kbacompany'); }
        $DB->delete_records(self::TABLE, ['id' => (int)$old->id, 'companyuuid' => $uuid,
            'corporatecompanyid' => $companyid]);
    }
}
