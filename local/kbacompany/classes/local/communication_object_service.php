<?php
namespace local_kbacompany\local;

defined('MOODLE_INTERNAL') || die();

/**
 * Access-aware resolver for BMS24 objects used as communication contexts.
 *
 * It never creates a parallel business object. It only validates visibility of
 * an existing canonical object and returns safe, user-facing navigation data.
 */
class communication_object_service {
    /** Resolve an object the current actor is allowed to know exists. */
    public static function describe(string $companyuuid, int $actor, string $kind, string $objectuuid,
            int $revision = 0): array {
        global $DB;
        $company = company_context_service::context($companyuuid, $actor);
        if (!in_array($kind, communication_service::CONTEXT_KINDS, true) || trim($objectuuid) === '') {
            self::fail();
        }
        $label = self::label($kind);
        $title = '';
        $url = null;

        if ($kind === 'company') {
            if ($objectuuid !== $companyuuid) { self::fail(); }
            $row = $DB->get_record('local_kbacompany_company', ['uuid' => $companyuuid], 'uuid,name');
            $title = $row ? (string)$row->name : (string)($company['name'] ?? $label);
            $url = new \moodle_url('/local/kbacompany/bms_workspace.php', ['uuid' => $companyuuid]);
        } else if ($kind === 'task') {
            try {
                if (!task_service::can_view_task($companyuuid, $actor, $objectuuid)) { self::fail(); }
            } catch (\moodle_exception $e) {
                self::fail();
            }
            $row = $DB->get_record('local_kbacompany_task', ['companyuuid' => $companyuuid, 'uuid' => $objectuuid],
                'uuid,title,revision,status');
            if (!$row || (string)$row->status === 'deleted') { self::fail(); }
            $title = (string)$row->title;
            $revision = (int)$row->revision;
            $url = new \moodle_url('/local/kbacompany/tasks.php', ['uuid' => $companyuuid, 'task' => $objectuuid]);
        } else if ($kind === 'meeting') {
            try {
                $ctx = meeting_service::context($companyuuid, $actor, $objectuuid);
            } catch (\moodle_exception $e) {
                self::fail();
            }
            if (empty($ctx['selected'])) { self::fail(); }
            $title = (string)$ctx['selected']['title'];
            $url = new \moodle_url('/local/kbacompany/meetings.php', ['uuid' => $companyuuid, 'meeting' => $objectuuid]);
        } else if ($kind === 'document') {
            try {
                $ctx = document_service::context($companyuuid, $actor, $objectuuid);
            } catch (\moodle_exception $e) {
                self::fail();
            }
            if (empty($ctx['selected'])) { self::fail(); }
            $title = (string)$ctx['selected']['title'];
            $revision = (int)($ctx['selected']['revision'] ?? $revision);
            $url = new \moodle_url('/local/kbacompany/documents.php', ['uuid' => $companyuuid, 'document' => $objectuuid]);
        } else if ($kind === 'process') {
            try {
                $ctx = process_service::context($companyuuid, $actor, $objectuuid, true);
            } catch (\moodle_exception $e) {
                self::fail();
            }
            if (empty($ctx['selected'])) { self::fail(); }
            $title = (string)$ctx['selected']['name'];
            $revision = (int)($ctx['selected']['revision'] ?? $revision);
            $url = new \moodle_url('/local/kbacompany/processes.php', ['uuid' => $companyuuid, 'process' => $objectuuid]);
        } else if ($kind === 'statistic') {
            $row = $DB->get_record('local_kbacompany_stat', ['companyuuid' => $companyuuid, 'uuid' => $objectuuid],
                'uuid,name,code');
            if (!$row) { self::fail(); }
            $title = (string)$row->name;
            $url = new \moodle_url('/local/kbacompany/indicators.php', ['uuid' => $companyuuid, 'stat' => $objectuuid, 'tab' => 'all']);
        } else if ($kind === 'project' || $kind === 'goal') {
            $ctx = administrative_scale_service::context($companyuuid, $actor);
            $candidate = null;
            foreach (($ctx['by_kind'][$kind] ?? []) as $item) {
                if ((string)$item['uuid'] === $objectuuid) { $candidate = $item; break; }
            }
            if (!$candidate) { self::fail(); }
            $title = (string)$candidate['name'];
            $revision = (int)($candidate['revision'] ?? $revision);
            $url = new \moodle_url('/local/kbacompany/administrative_scale.php', [
                'uuid' => $companyuuid, 'viewkind' => $kind,
            ]);
        } else if ($kind === 'improvement') {
            try {
                $row = deviation_service::read_record($companyuuid, $actor, $objectuuid);
            } catch (\moodle_exception $e) {
                self::fail();
            }
            $title = (string)$row->title;
            if ($title === '') { $title = (string)$row->code; }
            $url = new \moodle_url('/local/kbacompany/deviations.php', ['uuid' => $companyuuid, 'deviation' => $objectuuid]);
        } else if ($kind === 'kbaelement') {
            // Learning elements are resolved by their existing task provider. No raw DB access is exposed here.
            try {
                $provider = new task_provider\kbaelement_task_provider();
                if (!$provider->available()) { self::fail(); }
                $projection = $provider->get_task($companyuuid, $actor, $objectuuid);
            } catch (\moodle_exception $e) {
                self::fail();
            }
            if (!$projection) { self::fail(); }
            $title = (string)$projection['title'];
            if (!empty($projection['source_url'])) { $url = new \moodle_url($projection['source_url']); }
        } else {
            self::fail();
        }

        return [
            'kind' => $kind,
            'uuid' => $objectuuid,
            'revision' => max(0, $revision),
            'label' => $label,
            'title' => $title !== '' ? $title : $label,
            'url' => $url ? $url->out(false) : '',
        ];
    }

    /** Human-facing BMS24 type name. Technical identifiers never need to be shown in the UI. */
    public static function label(string $kind): string {
        $key = 'communication_object_' . $kind;
        if (get_string_manager()->string_exists($key, 'local_kbacompany')) {
            return get_string($key, 'local_kbacompany');
        }
        return get_string('communication_context', 'local_kbacompany');
    }

    private static function fail(): void {
        // Deliberately indistinguishable: inaccessible and non-existent objects must look the same.
        throw new \moodle_exception('communication_contextunavailable', 'local_kbacompany');
    }
}
