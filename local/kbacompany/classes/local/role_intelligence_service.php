<?php
namespace local_kbacompany\local;

defined('MOODLE_INTERNAL') || die();

/**
 * Role Intelligence R1: company-scoped, read-only coverage of position design.
 *
 * Reuses canonical Position, Function and Role DNA revisions. It does not
 * create another job/role catalogue, alter assignments or infer competencies.
 */
final class role_intelligence_service {
    public const CONTRACT_VERSION = '0.1.0';

    public static function contract(): array {
        return [
            'component' => 'local_kbacompany',
            'contract' => 'bms_role_intelligence_readiness',
            'version' => self::CONTRACT_VERSION,
            'company_scope' => 'required_before_fetch',
            'read_only' => true,
            'source_tables' => [
                'local_kbacompany_position',
                'local_kbacompany_function',
                'local_kbacompany_roledna',
            ],
            'rules' => [
                'no_duplicate_role_catalogue',
                'only_active_positions',
                'only_published_function_and_role_dna_revisions',
                'latest_archived_revision_excludes_object',
                'draft_revision_does_not_replace_published_revision',
                'no_employee_or_competency_mutation',
                'no_ai_autonomous_writes',
            ],
        ];
    }

    /**
     * Return design gaps for one company, authorised to manage people.
     *
     * This is intentionally a service read-model rather than a public API:
     * UI routes must still require login and must not expose cross-company data.
     *
     * @param string $companyuuid Canonical company UUID.
     * @param int $userid Actor ID from authenticated Moodle session.
     * @return array Safe position-design summary (no employee data).
     */
    public static function snapshot(string $companyuuid, int $userid): array {
        global $DB;

        $companyuuid = company_context_service::normalise_uuid($companyuuid);
        company_context_service::context($companyuuid, $userid);
        if (!permission_service::can_manage_people($companyuuid, $userid)) {
            throw new \required_capability_exception(\context_system::instance(),
                'local/kbacompany:managepeople', 'nopermissions', '');
        }

        // Query boundaries, not PHP filtering, enforce company isolation.
        $positions = $DB->get_records('local_kbacompany_position',
            ['companyuuid' => $companyuuid, 'active' => 1],
            'sortorder ASC, id ASC', 'id, uuid, code, name, purpose');
        $functionrevisions = $DB->get_records('local_kbacompany_function',
            ['companyuuid' => $companyuuid],
            'uuid ASC, revision ASC, id ASC',
            'id, uuid, revision, status, positionuuid');
        $profilerevisions = $DB->get_records('local_kbacompany_roledna',
            ['companyuuid' => $companyuuid],
            'uuid ASC, revision ASC, id ASC',
            'id, uuid, revision, status, positionuuid');

        $result = self::summarise($positions, $functionrevisions, $profilerevisions);
        $result['company_uuid'] = $companyuuid;
        return $result;
    }

    /**
     * Pure deterministic reduction for the authorised, already-scoped rows.
     * Does not query the database; also used for focused revision tests.
     */
    public static function summarise(array $positions, array $functionrevisions,
            array $profilerevisions): array {
        $functioncounts = [];
        $profilecounts = [];
        foreach (self::effective_published($functionrevisions) as $row) {
            $positionuuid = (string)$row->positionuuid;
            if ($positionuuid !== '') {
                $functioncounts[$positionuuid] = ($functioncounts[$positionuuid] ?? 0) + 1;
            }
        }
        foreach (self::effective_published($profilerevisions) as $row) {
            $positionuuid = (string)$row->positionuuid;
            if ($positionuuid !== '') {
                $profilecounts[$positionuuid] = ($profilecounts[$positionuuid] ?? 0) + 1;
            }
        }

        $items = [];
        $ready = 0;
        $missingpurpose = 0;
        $missingfunctions = 0;
        $missingprofiles = 0;
        foreach ($positions as $position) {
            $uuid = (string)$position->uuid;
            $purpose = trim((string)($position->purpose ?? ''));
            $functions = $functioncounts[$uuid] ?? 0;
            $profiles = $profilecounts[$uuid] ?? 0;
            $gaps = [];
            if ($purpose === '') {
                $gaps[] = 'purpose_missing';
                $missingpurpose++;
            }
            if ($functions === 0) {
                $gaps[] = 'published_functions_missing';
                $missingfunctions++;
            }
            if ($profiles === 0) {
                $gaps[] = 'published_role_dna_missing';
                $missingprofiles++;
            }
            if ($gaps === []) {
                $ready++;
            }
            $items[] = [
                'uuid' => $uuid,
                'code' => (string)$position->code,
                'name' => (string)$position->name,
                'has_purpose' => $purpose !== '',
                'published_function_count' => $functions,
                'published_role_dna_count' => $profiles,
                'design_status' => $gaps === [] ? 'basic_links_present' : 'needs_description',
                'gaps' => $gaps,
            ];
        }

        return [
            'summary' => [
                'positions' => count($items),
                'basic_links_present' => $ready,
                'needs_description' => count($items) - $ready,
                'missing_purpose' => $missingpurpose,
                'missing_published_functions' => $missingfunctions,
                'missing_published_role_dna' => $missingprofiles,
            ],
            'positions' => $items,
        ];
    }

    /**
     * A newer draft leaves the last published revision effective. A latest
     * archived revision removes the object from the read-model entirely.
     * Distinct UUIDs, not revision records, are counted.
     */
    private static function effective_published(array $revisions): array {
        $latest = [];
        $published = [];
        foreach ($revisions as $row) {
            $uuid = (string)$row->uuid;
            $revision = (int)$row->revision;
            if (!isset($latest[$uuid]) || $revision > (int)$latest[$uuid]->revision) {
                $latest[$uuid] = $row;
            }
            if ((string)$row->status === 'published' &&
                    (!isset($published[$uuid]) || $revision > (int)$published[$uuid]->revision)) {
                $published[$uuid] = $row;
            }
        }
        foreach ($latest as $uuid => $row) {
            if ((string)$row->status === 'archived') {
                unset($published[$uuid]);
            }
        }
        return array_values($published);
    }
}
