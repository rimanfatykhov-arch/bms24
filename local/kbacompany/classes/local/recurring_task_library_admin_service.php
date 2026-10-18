<?php
namespace local_kbacompany\local;

defined('MOODLE_INTERNAL') || die();

/** Company-scoped CRUD overlays for the recurring-task methodology library. */
class recurring_task_library_admin_service {
    public const CONTRACT_VERSION = '0.4.11.1';
    public const STATUSES = ['active', 'archived'];

    public static function contract(): array {
        return [
            'contract' => 'bms_recurring_task_library_admin',
            'version' => self::CONTRACT_VERSION,
            'objects' => ['TaskLibraryCategory', 'TaskLibraryTemplate'],
            'states' => self::STATUSES,
            'rules' => [
                'company_scope_required',
                'owner_or_manager_manage_library',
                'built_in_methodology_is_overlaid_not_destroyed',
                'edits_create_immutable_revision',
                'delete_means_archive',
                'archived_category_hides_its_templates_without_destroying_them',
                'template_key_and_source_uuid_are_stable_across_revisions',
            ],
        ];
    }

    private static function fail(string $key): void {
        throw new \moodle_exception('tasklibrary_' . $key, 'local_kbacompany');
    }

    /** Library administration belongs to company owners/managers. The page already requires company membership. */
    public static function can_manage(string $companyuuid, int $actor): bool {
        $company = company_context_service::context($companyuuid, $actor);
        if (is_siteadmin($actor)) { return true; }
        return (bool)array_intersect(['owner', 'manager'], (array)($company['roles'] ?? []));
    }

    private static function authorise(string $companyuuid, int $actor): void {
        if (!self::can_manage($companyuuid, $actor)) { self::fail('permission'); }
    }

    private static function uuid(): string {
        $bytes = random_bytes(16);
        $bytes[6] = chr((ord($bytes[6]) & 0x0f) | 0x40);
        $bytes[8] = chr((ord($bytes[8]) & 0x3f) | 0x80);
        $hex = bin2hex($bytes);
        return substr($hex, 0, 8) . '-' . substr($hex, 8, 4) . '-' . substr($hex, 12, 4) .
            '-' . substr($hex, 16, 4) . '-' . substr($hex, 20);
    }

    private static function key(string $prefix, string $uuid): string {
        return $prefix . '_' . substr(str_replace('-', '', $uuid), 0, 16);
    }

    private static function text(array $data, string $key, int $max, bool $required = false): string {
        $value = trim((string)($data[$key] ?? ''));
        if (($required && $value === '') || \core_text::strlen($value) > $max) { self::fail('invalid'); }
        return $value;
    }

    /** @return array<string,\stdClass> */
    private static function latest_rows(string $table, string $companyuuid): array {
        global $DB;
        $out = [];
        foreach ($DB->get_records($table, ['companyuuid' => $companyuuid], 'libkey ASC, revision ASC') as $row) {
            $out[(string)$row->libkey] = $row;
        }
        return $out;
    }

    private static function decode_payload(?\stdClass $row): array {
        if (!$row || trim((string)$row->payload) === '') { return []; }
        $decoded = json_decode((string)$row->payload, true);
        return is_array($decoded) ? $decoded : [];
    }

    /** @return array<string,string> */
    private static function builtin_category_options(): array {
        $out = [];
        foreach (recurring_task_library_service::builtin_categories() as $key) {
            $out[$key] = get_string('recurringtask_category_' . $key, 'local_kbacompany');
        }
        return $out;
    }

    /** Active category options after applying company rename/hide overlays. */
    public static function category_options(string $companyuuid): array {
        $options = self::builtin_category_options();
        foreach (self::latest_rows('local_kbacompany_tasklibcat', $companyuuid) as $key => $row) {
            if ((string)$row->status === 'archived') {
                unset($options[$key]);
                continue;
            }
            $options[$key] = (string)$row->title;
        }
        return $options;
    }

    /** @return string[] */
    public static function category_keys(string $companyuuid): array {
        return array_keys(self::category_options($companyuuid));
    }

    /** Category management rows including archived entries and conceptual revision 0 built-ins. */
    public static function category_admin_rows(string $companyuuid): array {
        $builtins = self::builtin_category_options();
        $latest = self::latest_rows('local_kbacompany_tasklibcat', $companyuuid);
        $keys = array_values(array_unique(array_merge(array_keys($builtins), array_keys($latest))));
        $out = [];
        foreach ($keys as $key) {
            $row = $latest[$key] ?? null;
            $out[$key] = [
                'key' => $key,
                'title' => $row ? (string)$row->title : (string)($builtins[$key] ?? $key),
                'revision' => $row ? (int)$row->revision : 0,
                'status' => $row ? (string)$row->status : 'active',
                'builtin' => array_key_exists($key, $builtins),
                'uuid' => $row ? (string)$row->uuid : '',
            ];
        }
        return $out;
    }

    public static function create_category(string $companyuuid, int $actor, string $title): string {
        global $DB;
        self::authorise($companyuuid, $actor);
        $title = trim($title);
        if ($title === '' || \core_text::strlen($title) > 255) { self::fail('invalid'); }
        $uuid = self::uuid();
        $key = self::key('cat', $uuid);
        $now = time();
        $DB->insert_record('local_kbacompany_tasklibcat', (object)[
            'companyuuid' => $companyuuid, 'uuid' => $uuid, 'revision' => 1, 'status' => 'active',
            'libkey' => $key, 'title' => $title, 'sortorder' => 1000, 'creatorid' => $actor, 'timecreated' => $now,
        ]);
        return $key;
    }

    public static function save_category(string $companyuuid, int $actor, string $key, int $expectedrevision, string $title): void {
        global $DB;
        self::authorise($companyuuid, $actor);
        $title = trim($title);
        if ($title === '' || \core_text::strlen($title) > 255) { self::fail('invalid'); }
        $rows = self::category_admin_rows($companyuuid);
        if (!isset($rows[$key])) { self::fail('reference'); }
        $current = $rows[$key];
        if ((int)$current['revision'] !== $expectedrevision) { self::fail('conflict'); }
        if ((string)$current['status'] === 'archived') { self::fail('state'); }
        $uuid = (string)$current['uuid'];
        if ($uuid === '') { $uuid = self::uuid(); }
        $DB->insert_record('local_kbacompany_tasklibcat', (object)[
            'companyuuid' => $companyuuid, 'uuid' => $uuid, 'revision' => $expectedrevision + 1, 'status' => 'active',
            'libkey' => $key, 'title' => $title, 'sortorder' => 1000, 'creatorid' => $actor, 'timecreated' => time(),
        ]);
    }

    public static function transition_category(string $companyuuid, int $actor, string $key, int $expectedrevision, string $status): void {
        global $DB;
        self::authorise($companyuuid, $actor);
        if (!in_array($status, self::STATUSES, true)) { self::fail('state'); }
        $rows = self::category_admin_rows($companyuuid);
        if (!isset($rows[$key])) { self::fail('reference'); }
        $current = $rows[$key];
        if ((int)$current['revision'] !== $expectedrevision) { self::fail('conflict'); }
        if ((string)$current['status'] === $status) { return; }
        $uuid = (string)$current['uuid'];
        if ($uuid === '') { $uuid = self::uuid(); }
        $DB->insert_record('local_kbacompany_tasklibcat', (object)[
            'companyuuid' => $companyuuid, 'uuid' => $uuid, 'revision' => $expectedrevision + 1, 'status' => $status,
            'libkey' => $key, 'title' => (string)$current['title'], 'sortorder' => 1000,
            'creatorid' => $actor, 'timecreated' => time(),
        ]);
    }

    private static function normalize_template(string $companyuuid, array $data, array $base = []): array {
        $categories = self::category_options($companyuuid);
        $category = self::text($data, 'category', 64, true);
        if (!isset($categories[$category])) { self::fail('reference'); }
        $cadence = self::text($data, 'cadence', 20, true);
        if (!in_array($cadence, recurring_task_service::CADENCES, true)) { self::fail('invalid'); }
        $stream = self::text($data, 'stream', 16, true);
        if (!in_array($stream, task_service::STREAMS, true)) { self::fail('invalid'); }
        $priority = self::text($data, 'priority', 10, true);
        if (!in_array($priority, task_service::PRIORITIES, true)) { self::fail('invalid'); }
        $weekdaymask = max(0, min(127, (int)($data['weekdaymask'] ?? 0)));
        $monthweek = max(0, min(5, (int)($data['monthweek'] ?? 0)));
        $planminutes = max(0, min(10080, (int)($data['planminutes'] ?? 0)));
        $requiresreview = !empty($data['requires_review']) || $cadence === 'review';
        $title = self::text($data, 'title', 255, true);
        $description = self::text($data, 'description', 12000, false);
        $product = self::text($data, 'product', 12000, false);
        $sourcecadence = ((string)($base['source_key'] ?? '') === 'company') ?
            get_string('recurringtask_cadence_' . $cadence, 'local_kbacompany') :
            (string)($base['source_cadence'] ?? get_string('recurringtask_cadence_' . $cadence, 'local_kbacompany'));
        return array_merge($base, [
            'category' => $category,
            'title' => $title,
            'description' => $description,
            'product' => $product,
            'cadence' => $cadence,
            'source_cadence' => $sourcecadence,
            'requires_review' => $requiresreview,
            'weekdaymask' => $weekdaymask,
            'monthweek' => $monthweek,
            'stream' => $stream,
            'priority' => $priority,
            'planminutes' => $planminutes,
        ]);
    }

    /** Merge static methodology templates with latest company edits/custom templates. */
    public static function merged_templates(string $companyuuid, array $builtins): array {
        $out = [];
        foreach ($builtins as $key => $template) {
            $template['_library_revision'] = 0;
            $template['_library_status'] = 'active';
            $template['_library_builtin'] = true;
            $template['description'] = (string)($template['description'] ?? '');
            $template['product'] = (string)($template['product'] ?? '');
            $template['stream'] = (string)($template['stream'] ?? 'operate');
            $template['priority'] = (string)($template['priority'] ?? 'normal');
            $template['planminutes'] = (int)($template['planminutes'] ?? 0);
            $out[$key] = $template;
        }
        foreach (self::latest_rows('local_kbacompany_tasklibtpl', $companyuuid) as $key => $row) {
            if ((string)$row->status === 'archived') {
                unset($out[$key]);
                continue;
            }
            $payload = self::decode_payload($row);
            if (!$payload) { continue; }
            $payload['key'] = $key;
            $payload['source_uuid'] = recurring_task_library_service::template_source_uuid($key);
            $payload['revision'] = isset($payload['revision']) ? (int)$payload['revision'] : 1;
            $payload['_library_revision'] = (int)$row->revision;
            $payload['_library_status'] = (string)$row->status;
            $payload['_library_builtin'] = isset($builtins[$key]);
            $out[$key] = $payload;
        }
        $activecats = array_flip(self::category_keys($companyuuid));
        foreach ($out as $key => $template) {
            if (!isset($activecats[(string)$template['category']])) { unset($out[$key]); }
        }
        return $out;
    }

    /** Templates for management, including archived and conceptual revision-0 built-ins. */
    public static function template_admin_rows(string $companyuuid): array {
        $builtins = recurring_task_library_service::builtin_templates();
        $latest = self::latest_rows('local_kbacompany_tasklibtpl', $companyuuid);
        $keys = array_values(array_unique(array_merge(array_keys($builtins), array_keys($latest))));
        $out = [];
        foreach ($keys as $key) {
            $row = $latest[$key] ?? null;
            $payload = $row ? self::decode_payload($row) : ($builtins[$key] ?? []);
            if (!$payload) { continue; }
            $payload['key'] = $key;
            $payload['source_uuid'] = recurring_task_library_service::template_source_uuid($key);
            $payload['description'] = (string)($payload['description'] ?? '');
            $payload['product'] = (string)($payload['product'] ?? '');
            $payload['stream'] = (string)($payload['stream'] ?? 'operate');
            $payload['priority'] = (string)($payload['priority'] ?? 'normal');
            $payload['planminutes'] = (int)($payload['planminutes'] ?? 0);
            $payload['_library_revision'] = $row ? (int)$row->revision : 0;
            $payload['_library_status'] = $row ? (string)$row->status : 'active';
            $payload['_library_builtin'] = isset($builtins[$key]);
            $payload['_library_uuid'] = $row ? (string)$row->uuid : '';
            $out[$key] = $payload;
        }
        return $out;
    }

    /** Resolve an active or archived template by stable source UUID for historical traceability. */
    public static function template_by_source_uuid(string $companyuuid, string $sourceuuid): ?array {
        foreach (self::template_admin_rows($companyuuid) as $row) {
            if ((string)$row['source_uuid'] === $sourceuuid) { return $row; }
        }
        return null;
    }

    public static function create_template(string $companyuuid, int $actor, array $data): string {
        global $DB;
        self::authorise($companyuuid, $actor);
        $uuid = self::uuid();
        $key = self::key('tpl', $uuid);
        $base = [
            'key' => $key, 'source_uuid' => recurring_task_library_service::template_source_uuid($key), 'revision' => 1,
            'source_key' => 'company', 'source_type' => 'company_task_library',
            'source_label' => get_string('tasklibrary_company_source', 'local_kbacompany'),
            'source_hash' => '', 'source_file_id' => '', 'source_revision' => '', 'source_score' => 0, 'source_time' => '',
        ];
        $payload = self::normalize_template($companyuuid, $data, $base);
        $DB->insert_record('local_kbacompany_tasklibtpl', (object)[
            'companyuuid' => $companyuuid, 'uuid' => $uuid, 'revision' => 1, 'status' => 'active',
            'libkey' => $key, 'categorykey' => (string)$payload['category'],
            'payload' => json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'creatorid' => $actor, 'timecreated' => time(),
        ]);
        return $key;
    }

    public static function save_template(string $companyuuid, int $actor, string $key, int $expectedrevision, array $data): void {
        global $DB;
        self::authorise($companyuuid, $actor);
        $rows = self::template_admin_rows($companyuuid);
        if (!isset($rows[$key])) { self::fail('reference'); }
        $current = $rows[$key];
        if ((int)$current['_library_revision'] !== $expectedrevision) { self::fail('conflict'); }
        if ((string)$current['_library_status'] === 'archived') { self::fail('state'); }
        $uuid = (string)$current['_library_uuid'];
        if ($uuid === '') { $uuid = self::uuid(); }
        $base = $current;
        foreach (array_keys($base) as $name) { if (strpos($name, '_library_') === 0) { unset($base[$name]); } }
        $payload = self::normalize_template($companyuuid, $data, $base);
        $payload['key'] = $key;
        $payload['source_uuid'] = recurring_task_library_service::template_source_uuid($key);
        $DB->insert_record('local_kbacompany_tasklibtpl', (object)[
            'companyuuid' => $companyuuid, 'uuid' => $uuid, 'revision' => $expectedrevision + 1, 'status' => 'active',
            'libkey' => $key, 'categorykey' => (string)$payload['category'],
            'payload' => json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'creatorid' => $actor, 'timecreated' => time(),
        ]);
    }

    public static function transition_template(string $companyuuid, int $actor, string $key, int $expectedrevision, string $status): void {
        global $DB;
        self::authorise($companyuuid, $actor);
        if (!in_array($status, self::STATUSES, true)) { self::fail('state'); }
        $rows = self::template_admin_rows($companyuuid);
        if (!isset($rows[$key])) { self::fail('reference'); }
        $current = $rows[$key];
        if ((int)$current['_library_revision'] !== $expectedrevision) { self::fail('conflict'); }
        if ((string)$current['_library_status'] === $status) { return; }
        $uuid = (string)$current['_library_uuid'];
        if ($uuid === '') { $uuid = self::uuid(); }
        $payload = $current;
        foreach (array_keys($payload) as $name) { if (strpos($name, '_library_') === 0) { unset($payload[$name]); } }
        $DB->insert_record('local_kbacompany_tasklibtpl', (object)[
            'companyuuid' => $companyuuid, 'uuid' => $uuid, 'revision' => $expectedrevision + 1, 'status' => $status,
            'libkey' => $key, 'categorykey' => (string)$payload['category'],
            'payload' => json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'creatorid' => $actor, 'timecreated' => time(),
        ]);
    }
}
