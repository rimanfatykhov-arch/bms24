<?php
namespace local_kbacompany\local;

defined('MOODLE_INTERNAL') || die();

use local_kbacompany\local\implementation_project_link_service as links;
use local_kbacompany\local\task_provider\kbacorporate_impl_task_provider;

/** Read-only Corporate baseline sections in the already-linked BMS Project context. */
final class implementation_project_stages_service {
    public static function model(string $uuid, int $actor, int $programid): array {
        global $DB;
        company_context_service::context($uuid, $actor);
        if (!administrative_scale_service::can_manage($uuid, $actor) || $programid <= 0) {
            throw new \moodle_exception('implproj_permission', 'local_kbacompany');
        }
        $provider = new kbacorporate_impl_task_provider();
        $bridge = '\\local_kbacorporate\local\bridge';
        if (!$provider->available() || !class_exists($bridge) ||
                !method_exists($bridge, 'get_implementation_program_sections') ||
                version_compare((string)$bridge::API_RELEASE, '1.24', '<')) {
            throw new \moodle_exception('implstage_unavailable', 'local_kbacompany');
        }
        $companyid = $provider->linked_company_id($uuid, $actor);
        if ($companyid <= 0) {
            throw new \moodle_exception('implproj_source', 'local_kbacompany');
        }
        // Don't permit reading stages for programs that have not been explicitly linked.
        $link = $DB->get_record(links::TABLE, [
            'companyuuid' => $uuid, 'corporatecompanyid' => $companyid, 'programid' => $programid,
        ]);
        if (!$link) {
            throw new \moodle_exception('implproj_program', 'local_kbacompany');
        }
        $admin = administrative_scale_service::context($uuid, $actor);
        $projects = links::eligible_projects($admin['items']);
        if (!isset($projects[(string)$link->projectuuid])) {
            throw new \moodle_exception('implproj_project', 'local_kbacompany');
        }
        $data = $bridge::get_implementation_program_sections($companyid, $actor, $programid);
        if (!$data || (int)($data['company_id'] ?? 0) !== $companyid ||
                (int)($data['program_id'] ?? 0) !== $programid) {
            throw new \moodle_exception('implproj_program', 'local_kbacompany');
        }
        return [
            'projectuuid' => (string)$link->projectuuid,
            'projecttitle' => (string)$projects[(string)$link->projectuuid]['title'],
            'programid' => $programid,
            'companyid' => $companyid,
            'stages' => $data,
        ];
    }
}
