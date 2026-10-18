<?php
namespace local_kbacompany\local;

defined('MOODLE_INTERNAL') || die();

/**
 * Private owner intent / LPF workflow.
 *
 * Owner answers are versioned in ownerframe. Consultant review and comments are
 * append-only feedback events scoped by company UUID + logical frame UUID.
 */
class owner_service {
    public const CONTRACT_VERSION = '0.6.9.4';
    public const STATES = ['draft', 'published', 'archived'];
    public const REVIEW_ACTIONS = ['comment', 'submitted', 'changes', 'agreed'];
    public const SECTION_KEYS = [
        'personal',
        'professional',
        'financial',
        'income',
        'alignment',
        'ownership',
        'businessrole',
        'financialrequirements',
        'ownerrole',
        'reserved',
        'price',
        'coowners',
        'strategy',
        'review',
    ];
    public const SECTION_FIELDS = ['statement', 'horizonmetric', 'constraintsprice', 'businessevidence'];

    private const PAYLOAD_KEYS = [
        'main_intent','lpf1','lpf3','lpf5','priorities','professional_role','keep_functions','delegate_functions',
        'stop_functions','personal_income','target_capital','liquidity','income_need','income_breakdown','support_links',
        'conflicts','conflict_priority','ownership_goals','business_role','profit_requirement','dividend_requirement',
        'capital_requirement','risk_requirement','owner_role','reserved_decisions','noninterference','intervention_triggers',
        'acceptable_price','unacceptable_price','protected_constraints','coowners','agreements','disagreements',
        'private_questions','review_horizon','review_triggers','client_confirmation','notes'
    ];

    public static function contract(): array {
        $contract = [
            'component' => 'local_kbacompany',
            'contract' => 'bms_owner_lpf_model',
            'version' => self::CONTRACT_VERSION,
            'company_scope' => 'required',
            'objects' => ['OwnerFrame', 'OwnerSection', 'OwnerFeedback', 'OwnerRequirement'],
            'rules' => [
                'owner_answers_are_private_and_versioned',
                'explicitly_authorised_consultants_can_review_but_not_edit_owner_answers',
                'site_admin_bypass_remains_explicit',
                'review_feedback_is_append_only_and_section_scoped',
                'review_state_is_bound_to_section_content_hash',
                'published_revisions_are_immutable',
                'approved_requirements_are_the_only_owner_data_exposed_to_business_architecture',
                'business_model_references_exact_owner_frame_revision',
                'delete_is_audited_archive_revision',
                'google_drive_is_provenance_not_runtime_dependency',
            ],
        ];
        $contract['schema_hash_algorithm'] = 'sha256';
        $contract['schema_hash'] = hash('sha256', json_encode($contract, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        return $contract;
    }

    public static function can_manage(string $companyuuid, int $userid): bool {
        return permission_service::can_manage_owner_private($companyuuid, $userid);
    }

    public static function can_review(string $companyuuid, int $userid): bool {
        return permission_service::can_review_owner_private($companyuuid, $userid);
    }

    private static function authorise_view(string $companyuuid, int $userid): void {
        company_context_service::context($companyuuid, $userid);
        if (!self::can_manage($companyuuid, $userid) && !self::can_review($companyuuid, $userid)) {
            throw new \required_capability_exception(\context_system::instance(),
                'local/kbacompany:viewowncompany', 'nopermissions', '');
        }
    }

    private static function authorise_edit(string $companyuuid, int $userid): void {
        company_context_service::context($companyuuid, $userid);
        if (!self::can_manage($companyuuid, $userid)) {
            throw new \required_capability_exception(\context_system::instance(),
                'local/kbacompany:manageorganization', 'nopermissions', '');
        }
    }

    private static function authorise_review(string $companyuuid, int $userid): void {
        company_context_service::context($companyuuid, $userid);
        if (!self::can_review($companyuuid, $userid)) {
            throw new \required_capability_exception(\context_system::instance(),
                'local/kbacompany:reviewownerprivate', 'nopermissions', '');
        }
    }

    private static function fail(string $key): void {
        throw new \moodle_exception('ownermodel_' . $key, 'local_kbacompany');
    }

    private static function uuid(): string {
        $b = random_bytes(16);
        $b[6] = chr((ord($b[6]) & 0x0f) | 0x40);
        $b[8] = chr((ord($b[8]) & 0x3f) | 0x80);
        $h = bin2hex($b);
        return substr($h, 0, 8) . '-' . substr($h, 8, 4) . '-' . substr($h, 12, 4) . '-' .
            substr($h, 16, 4) . '-' . substr($h, 20);
    }

    private static function text(array $data, string $key, int $max, bool $required = false): string {
        $v = trim((string)($data[$key] ?? ''));
        if (($required && $v === '') || \core_text::strlen($v) > $max) {
            self::fail('invalid');
        }
        return $v;
    }

    private static function latest(string $companyuuid, string $uuid): \stdClass {
        global $DB;
        $rows = $DB->get_records('local_kbacompany_ownerframe', [
            'companyuuid' => $companyuuid,
            'uuid' => $uuid,
        ], 'revision DESC', '*', 0, 1);
        if (!$rows) {
            self::fail('reference');
        }
        return reset($rows);
    }

    private static function assert_frame_visible(\stdClass $row, int $userid): void {
        if (is_siteadmin($userid)) {
            return;
        }
        if ((int)$row->owneruserid === $userid) {
            return;
        }
        if (self::can_review((string)$row->companyuuid, $userid)) {
            return;
        }
        self::fail('permission');
    }

    private static function assert_frame_editable(\stdClass $row, int $userid): void {
        if (is_siteadmin($userid)) {
            return;
        }
        if ((int)$row->owneruserid !== $userid) {
            self::fail('permission');
        }
    }

    private static function decode(string $json): array {
        if ($json === '') {
            return [];
        }
        $data = json_decode($json, true);
        return is_array($data) ? $data : [];
    }

    private static function payload(array $data): array {
        $payload = [];
        foreach (self::PAYLOAD_KEYS as $key) {
            $payload[$key] = self::text($data, $key, 20000, false);
        }
        $requirements = [];
        for ($i = 1; $i <= 7; $i++) {
            $key = 'req' . sprintf('%02d', $i);
            $value = self::text($data, $key, 4000, false);
            if ($value !== '') {
                $requirements[] = ['id' => 'REQ-' . sprintf('%02d', $i), 'text' => $value];
            }
        }
        $payload['requirements'] = $requirements;
        if (isset($data['sections']) && is_array($data['sections'])) {
            $payload['sections'] = self::clean_sections($data['sections']);
        }
        return $payload;
    }

    private static function clean_sections(array $sections): array {
        $out = [];
        foreach (self::SECTION_KEYS as $sectionkey) {
            $section = isset($sections[$sectionkey]) && is_array($sections[$sectionkey]) ? $sections[$sectionkey] : [];
            $out[$sectionkey] = [];
            foreach (self::SECTION_FIELDS as $field) {
                $value = trim((string)($section[$field] ?? ''));
                if (\core_text::strlen($value) > 20000) {
                    self::fail('invalid');
                }
                $out[$sectionkey][$field] = $value;
            }
        }
        return $out;
    }

    private static function dto(\stdClass $row): array {
        $out = (array)$row;
        unset($out['id']);
        $payload = self::decode((string)$row->payload);
        $payload['sections'] = self::sections_from_payload($payload);
        $out['payload_data'] = $payload;
        return $out;
    }

    /**
     * Convert legacy owner payloads into the 14 methodological sections without deleting legacy keys.
     */
    public static function sections_from_payload(array $payload): array {
        if (isset($payload['sections']) && is_array($payload['sections'])) {
            return self::clean_sections($payload['sections']);
        }
        $sections = [];
        foreach (self::SECTION_KEYS as $key) {
            $sections[$key] = array_fill_keys(self::SECTION_FIELDS, '');
        }
        $join = static function(array $parts): string {
            $out = [];
            foreach ($parts as $label => $value) {
                $value = trim((string)$value);
                if ($value !== '') {
                    $out[] = $label !== '' ? $label . ': ' . $value : $value;
                }
            }
            return implode("\n", $out);
        };
        $sections['personal']['statement'] = $join([
            '1Y' => $payload['lpf1'] ?? '',
            '3Y' => $payload['lpf3'] ?? '',
            '5Y' => $payload['lpf5'] ?? '',
        ]);
        $sections['personal']['horizonmetric'] = (string)($payload['priorities'] ?? '');
        $sections['professional']['statement'] = (string)($payload['professional_role'] ?? '');
        $sections['professional']['constraintsprice'] = $join([
            'KEEP' => $payload['keep_functions'] ?? '',
            'DELEGATE' => $payload['delegate_functions'] ?? '',
            'STOP' => $payload['stop_functions'] ?? '',
        ]);
        $sections['financial']['statement'] = $join([
            'INCOME' => $payload['personal_income'] ?? '',
            'CAPITAL' => $payload['target_capital'] ?? '',
            'LIQUIDITY' => $payload['liquidity'] ?? '',
        ]);
        $sections['income']['statement'] = (string)($payload['income_need'] ?? '');
        $sections['income']['businessevidence'] = (string)($payload['income_breakdown'] ?? '');
        $sections['alignment']['statement'] = (string)($payload['support_links'] ?? '');
        $sections['alignment']['constraintsprice'] = $join([
            'CONFLICTS' => $payload['conflicts'] ?? '',
            'PRIORITY' => $payload['conflict_priority'] ?? '',
        ]);
        $sections['ownership']['statement'] = (string)($payload['ownership_goals'] ?? '');
        $sections['businessrole']['statement'] = (string)($payload['business_role'] ?? '');
        $sections['financialrequirements']['statement'] = $join([
            'PROFIT' => $payload['profit_requirement'] ?? '',
            'DIVIDENDS' => $payload['dividend_requirement'] ?? '',
            'CAPITAL' => $payload['capital_requirement'] ?? '',
            'RISK' => $payload['risk_requirement'] ?? '',
        ]);
        $sections['ownerrole']['statement'] = (string)($payload['owner_role'] ?? '');
        $sections['reserved']['statement'] = (string)($payload['reserved_decisions'] ?? '');
        $sections['reserved']['constraintsprice'] = $join([
            'NONINTERFERENCE' => $payload['noninterference'] ?? '',
            'TRIGGERS' => $payload['intervention_triggers'] ?? '',
        ]);
        $sections['price']['statement'] = $join([
            'ACCEPTABLE' => $payload['acceptable_price'] ?? '',
            'UNACCEPTABLE' => $payload['unacceptable_price'] ?? '',
        ]);
        $sections['price']['constraintsprice'] = (string)($payload['protected_constraints'] ?? '');
        $sections['coowners']['statement'] = (string)($payload['coowners'] ?? '');
        $sections['coowners']['horizonmetric'] = (string)($payload['agreements'] ?? '');
        $sections['coowners']['constraintsprice'] = (string)($payload['disagreements'] ?? '');
        $sections['review']['statement'] = (string)($payload['client_confirmation'] ?? '');
        $sections['review']['horizonmetric'] = (string)($payload['review_horizon'] ?? '');
        $sections['review']['constraintsprice'] = (string)($payload['review_triggers'] ?? '');
        $sections['review']['businessevidence'] = (string)($payload['private_questions'] ?? '');
        return $sections;
    }

    public static function section_is_filled(array $section): bool {
        foreach (self::SECTION_FIELDS as $field) {
            if (trim((string)($section[$field] ?? '')) !== '') {
                return true;
            }
        }
        return false;
    }

    public static function section_hash(array $section): string {
        $clean = [];
        foreach (self::SECTION_FIELDS as $field) {
            $clean[$field] = trim((string)($section[$field] ?? ''));
        }
        return hash('sha256', json_encode($clean, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }

    private static function insert_revision(\stdClass $old, int $userid, string $title, array $payload): \stdClass {
        global $DB;
        $now = time();
        $row = (object)[
            'companyuuid' => (string)$old->companyuuid,
            'uuid' => (string)$old->uuid,
            'revision' => (int)$old->revision + 1,
            'status' => 'draft',
            'owneruserid' => (int)$old->owneruserid,
            'title' => $title,
            'payload' => json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'createdby' => $userid,
            'modifiedby' => $userid,
            'timecreated' => $now,
            'timemodified' => $now,
        ];
        $DB->insert_record('local_kbacompany_ownerframe', $row);
        return $row;
    }

    public static function create_frame(string $companyuuid, int $userid, string $title): string {
        global $DB;
        self::authorise_edit($companyuuid, $userid);
        $title = trim($title);
        if ($title === '' || \core_text::strlen($title) > 255) {
            self::fail('invalid');
        }
        $now = time();
        $uuid = self::uuid();
        $payload = [
            'main_intent' => '',
            'notes' => '',
            'requirements' => [],
            'sections' => self::clean_sections([]),
        ];
        $DB->insert_record('local_kbacompany_ownerframe', (object)[
            'companyuuid' => $companyuuid,
            'uuid' => $uuid,
            'revision' => 1,
            'status' => 'draft',
            'owneruserid' => $userid,
            'title' => $title,
            'payload' => json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'createdby' => $userid,
            'modifiedby' => $userid,
            'timecreated' => $now,
            'timemodified' => $now,
        ]);
        return $uuid;
    }

    /** Legacy full-frame save kept for compatibility with existing callers/tests. */
    public static function save_frame(string $companyuuid, int $userid, array $data,
            string $uuid = '', int $expected = 0): string {
        global $DB;
        self::authorise_edit($companyuuid, $userid);
        if ($uuid === '') {
            $uuid = self::create_frame($companyuuid, $userid, self::text($data, 'title', 255, true));
            $expected = 1;
        }
        $lock = \core\lock\lock_config::get_lock_factory('local_kbacompany')->get_lock('owner_model:' . $companyuuid, 10);
        if (!$lock) {
            self::fail('lock');
        }
        try {
            $tx = $DB->start_delegated_transaction();
            $old = self::latest($companyuuid, $uuid);
            if ((int)$old->revision !== $expected) {
                self::fail('conflict');
            }
            if ($old->status === 'archived') {
                self::fail('state');
            }
            self::assert_frame_editable($old, $userid);
            $payload = self::payload($data);
            self::insert_revision($old, $userid, self::text($data, 'title', 255, true), $payload);
            $tx->allow_commit();
            return $uuid;
        } catch (\Throwable $e) {
            if (isset($tx)) {
                $tx->rollback($e);
            }
            throw $e;
        } finally {
            $lock->release();
        }
    }

    public static function save_section(string $companyuuid, int $userid, string $uuid, int $expected,
            string $sectionkey, array $section): int {
        global $DB;
        self::authorise_edit($companyuuid, $userid);
        if (!in_array($sectionkey, self::SECTION_KEYS, true)) {
            self::fail('invalid');
        }
        $lock = \core\lock\lock_config::get_lock_factory('local_kbacompany')->get_lock('owner_model:' . $companyuuid, 10);
        if (!$lock) {
            self::fail('lock');
        }
        try {
            $tx = $DB->start_delegated_transaction();
            $old = self::latest($companyuuid, $uuid);
            if ((int)$old->revision !== $expected || $old->status === 'archived') {
                self::fail('conflict');
            }
            self::assert_frame_editable($old, $userid);
            $payload = self::decode((string)$old->payload);
            $sections = self::sections_from_payload($payload);
            $sections[$sectionkey] = self::clean_sections([$sectionkey => $section])[$sectionkey];
            $payload['sections'] = $sections;
            $row = self::insert_revision($old, $userid, (string)$old->title, $payload);
            $tx->allow_commit();
            return (int)$row->revision;
        } catch (\Throwable $e) {
            if (isset($tx)) {
                $tx->rollback($e);
            }
            throw $e;
        } finally {
            $lock->release();
        }
    }

    public static function save_summary(string $companyuuid, int $userid, string $uuid, int $expected,
            string $title, string $mainintent, string $notes, array $requirements): int {
        global $DB;
        self::authorise_edit($companyuuid, $userid);
        $title = trim($title);
        $mainintent = trim($mainintent);
        $notes = trim($notes);
        if ($title === '' || \core_text::strlen($title) > 255 || \core_text::strlen($mainintent) > 20000 ||
                \core_text::strlen($notes) > 20000) {
            self::fail('invalid');
        }
        $cleanrequirements = [];
        for ($i = 1; $i <= 7; $i++) {
            $value = trim((string)($requirements[$i] ?? ''));
            if (\core_text::strlen($value) > 4000) {
                self::fail('invalid');
            }
            if ($value !== '') {
                $cleanrequirements[] = ['id' => 'REQ-' . sprintf('%02d', $i), 'text' => $value];
            }
        }
        $lock = \core\lock\lock_config::get_lock_factory('local_kbacompany')->get_lock('owner_model:' . $companyuuid, 10);
        if (!$lock) {
            self::fail('lock');
        }
        try {
            $tx = $DB->start_delegated_transaction();
            $old = self::latest($companyuuid, $uuid);
            if ((int)$old->revision !== $expected || $old->status === 'archived') {
                self::fail('conflict');
            }
            self::assert_frame_editable($old, $userid);
            $payload = self::decode((string)$old->payload);
            $payload['sections'] = self::sections_from_payload($payload);
            $payload['main_intent'] = $mainintent;
            $payload['notes'] = $notes;
            $payload['requirements'] = $cleanrequirements;
            $row = self::insert_revision($old, $userid, $title, $payload);
            $tx->allow_commit();
            return (int)$row->revision;
        } catch (\Throwable $e) {
            if (isset($tx)) {
                $tx->rollback($e);
            }
            throw $e;
        } finally {
            $lock->release();
        }
    }

    private static function feedback_event(string $companyuuid, string $frameuuid, int $revision, string $sectionkey,
            string $action, string $contenthash, int $authoruserid, string $comment): void {
        global $DB;
        if (!in_array($sectionkey, self::SECTION_KEYS, true) || !in_array($action, self::REVIEW_ACTIONS, true)) {
            self::fail('invalid');
        }
        $DB->insert_record('local_kbacompany_ownerfb', (object)[
            'companyuuid' => $companyuuid,
            'frameuuid' => $frameuuid,
            'framerevision' => $revision,
            'sectionkey' => $sectionkey,
            'action' => $action,
            'contenthash' => $contenthash,
            'authoruserid' => $authoruserid,
            'comment' => $comment,
            'timecreated' => time(),
        ]);
    }

    public static function submit_for_review(string $companyuuid, int $userid, string $uuid, int $expected,
            string $sectionkey = ''): void {
        self::authorise_edit($companyuuid, $userid);
        $row = self::latest($companyuuid, $uuid);
        if ((int)$row->revision !== $expected || $row->status !== 'draft') {
            self::fail('state');
        }
        self::assert_frame_editable($row, $userid);
        $payload = self::decode((string)$row->payload);
        $sections = self::sections_from_payload($payload);
        $keys = $sectionkey === '' ? self::SECTION_KEYS : [$sectionkey];
        foreach ($keys as $key) {
            if (!in_array($key, self::SECTION_KEYS, true)) {
                self::fail('invalid');
            }
            if (!self::section_is_filled($sections[$key])) {
                continue;
            }
            self::feedback_event($companyuuid, $uuid, (int)$row->revision, $key, 'submitted',
                self::section_hash($sections[$key]), $userid, '');
        }
    }

    public static function review_section(string $companyuuid, int $userid, string $uuid, int $expected,
            string $sectionkey, string $decision, string $comment): void {
        self::authorise_review($companyuuid, $userid);
        if (!in_array($decision, ['changes', 'agreed'], true) || !in_array($sectionkey, self::SECTION_KEYS, true)) {
            self::fail('invalid');
        }
        $row = self::latest($companyuuid, $uuid);
        if ((int)$row->revision !== $expected || $row->status !== 'draft') {
            self::fail('state');
        }
        self::assert_frame_visible($row, $userid);
        $payload = self::decode((string)$row->payload);
        $sections = self::sections_from_payload($payload);
        if (!self::section_is_filled($sections[$sectionkey])) {
            self::fail('invalid');
        }
        $comment = trim($comment);
        if ($decision === 'changes' && $comment === '') {
            self::fail('reviewcommentrequired');
        }
        if (\core_text::strlen($comment) > 10000) {
            self::fail('invalid');
        }
        self::feedback_event($companyuuid, $uuid, (int)$row->revision, $sectionkey, $decision,
            self::section_hash($sections[$sectionkey]), $userid, $comment);
    }

    public static function add_comment(string $companyuuid, int $userid, string $uuid, int $expected,
            string $sectionkey, string $comment): void {
        self::authorise_view($companyuuid, $userid);
        if (!in_array($sectionkey, self::SECTION_KEYS, true)) {
            self::fail('invalid');
        }
        $row = self::latest($companyuuid, $uuid);
        if ((int)$row->revision !== $expected || $row->status === 'archived') {
            self::fail('conflict');
        }
        self::assert_frame_visible($row, $userid);
        $comment = trim($comment);
        if ($comment === '' || \core_text::strlen($comment) > 10000) {
            self::fail('invalid');
        }
        $payload = self::decode((string)$row->payload);
        $sections = self::sections_from_payload($payload);
        self::feedback_event($companyuuid, $uuid, (int)$row->revision, $sectionkey, 'comment',
            self::section_hash($sections[$sectionkey]), $userid, $comment);
    }

    public static function section_statuses(string $companyuuid, int $userid, array $frame): array {
        global $DB;
        self::authorise_view($companyuuid, $userid);
        $uuid = (string)$frame['uuid'];
        $sections = self::sections_from_payload($frame['payload_data'] ?? []);
        $events = $DB->get_records('local_kbacompany_ownerfb', [
            'companyuuid' => $companyuuid,
            'frameuuid' => $uuid,
        ], 'id ASC');
        $latest = [];
        foreach ($events as $event) {
            if (!in_array((string)$event->action, ['submitted', 'changes', 'agreed'], true)) {
                continue;
            }
            $latest[(string)$event->sectionkey . ':' . (string)$event->contenthash] = (string)$event->action;
        }
        $out = [];
        foreach (self::SECTION_KEYS as $key) {
            $section = $sections[$key];
            if (!self::section_is_filled($section)) {
                $out[$key] = 'empty';
                continue;
            }
            $hash = self::section_hash($section);
            $action = $latest[$key . ':' . $hash] ?? '';
            $out[$key] = $action === 'submitted' ? 'review' : ($action === 'changes' ? 'changes' :
                ($action === 'agreed' ? 'agreed' : 'draft'));
        }
        return $out;
    }

    public static function feedback(string $companyuuid, int $userid, string $uuid, string $sectionkey): array {
        global $DB;
        self::authorise_view($companyuuid, $userid);
        if (!in_array($sectionkey, self::SECTION_KEYS, true)) {
            self::fail('invalid');
        }
        $row = self::latest($companyuuid, $uuid);
        self::assert_frame_visible($row, $userid);
        $sql = 'SELECT f.*, u.firstname, u.lastname
                  FROM {local_kbacompany_ownerfb} f
             LEFT JOIN {user} u ON u.id = f.authoruserid
                 WHERE f.companyuuid = :companyuuid
                   AND f.frameuuid = :frameuuid
                   AND f.sectionkey = :sectionkey
              ORDER BY f.id ASC';
        $records = $DB->get_records_sql($sql, [
            'companyuuid' => $companyuuid,
            'frameuuid' => $uuid,
            'sectionkey' => $sectionkey,
        ]);
        $out = [];
        foreach ($records as $record) {
            $name = trim((string)$record->firstname . ' ' . (string)$record->lastname);
            $out[] = [
                'id' => (int)$record->id,
                'revision' => (int)$record->framerevision,
                'action' => (string)$record->action,
                'comment' => (string)$record->comment,
                'authoruserid' => (int)$record->authoruserid,
                'authorname' => $name !== '' ? $name : get_string('ownermodel_deleteduser', 'local_kbacompany'),
                'timecreated' => (int)$record->timecreated,
            ];
        }
        return $out;
    }

    public static function progress(array $statuses): array {
        $out = ['total' => count(self::SECTION_KEYS), 'filled' => 0, 'agreed' => 0, 'changes' => 0, 'review' => 0];
        foreach (self::SECTION_KEYS as $key) {
            $status = $statuses[$key] ?? 'empty';
            if ($status !== 'empty') {
                $out['filled']++;
            }
            if ($status === 'agreed') {
                $out['agreed']++;
            } else if ($status === 'changes') {
                $out['changes']++;
            } else if ($status === 'review') {
                $out['review']++;
            }
        }
        return $out;
    }

    public static function ready_to_publish(string $companyuuid, int $userid, array $frame): bool {
        $statuses = self::section_statuses($companyuuid, $userid, $frame);
        foreach (self::SECTION_KEYS as $key) {
            if (($statuses[$key] ?? '') !== 'agreed') {
                return false;
            }
        }
        return true;
    }

    public static function publish_frame(string $companyuuid, int $userid, string $uuid, int $expected): void {
        global $DB;
        self::authorise_edit($companyuuid, $userid);
        $lock = \core\lock\lock_config::get_lock_factory('local_kbacompany')->get_lock('owner_model:' . $companyuuid, 10);
        if (!$lock) {
            self::fail('lock');
        }
        try {
            $tx = $DB->start_delegated_transaction();
            $old = self::latest($companyuuid, $uuid);
            if ((int)$old->revision !== $expected || $old->status !== 'draft') {
                self::fail('conflict');
            }
            self::assert_frame_editable($old, $userid);
            $frame = self::dto($old);
            if (!self::ready_to_publish($companyuuid, $userid, $frame)) {
                self::fail('notready');
            }
            $row = clone $old;
            unset($row->id);
            $row->revision = $expected + 1;
            $row->status = 'published';
            $row->modifiedby = $userid;
            $row->timemodified = time();
            $DB->insert_record('local_kbacompany_ownerframe', $row);
            $tx->allow_commit();
        } catch (\Throwable $e) {
            if (isset($tx)) {
                $tx->rollback($e);
            }
            throw $e;
        } finally {
            $lock->release();
        }
    }

    public static function archive_frame(string $companyuuid, int $userid, string $uuid, int $expected): void {
        global $DB;
        self::authorise_edit($companyuuid, $userid);
        $lock = \core\lock\lock_config::get_lock_factory('local_kbacompany')->get_lock('owner_model:' . $companyuuid, 10);
        if (!$lock) {
            self::fail('lock');
        }
        try {
            $tx = $DB->start_delegated_transaction();
            $old = self::latest($companyuuid, $uuid);
            if ((int)$old->revision !== $expected || $old->status === 'archived') {
                self::fail('conflict');
            }
            self::assert_frame_editable($old, $userid);
            $row = clone $old;
            unset($row->id);
            $row->revision = $expected + 1;
            $row->status = 'archived';
            $row->modifiedby = $userid;
            $row->timemodified = time();
            $DB->insert_record('local_kbacompany_ownerframe', $row);
            $tx->allow_commit();
        } catch (\Throwable $e) {
            if (isset($tx)) {
                $tx->rollback($e);
            }
            throw $e;
        } finally {
            $lock->release();
        }
    }

    public static function history(string $companyuuid, int $userid, string $uuid): array {
        global $DB;
        self::authorise_view($companyuuid, $userid);
        $rows = $DB->get_records('local_kbacompany_ownerframe', [
            'companyuuid' => $companyuuid,
            'uuid' => $uuid,
        ], 'revision DESC');
        $out = [];
        foreach ($rows as $row) {
            self::assert_frame_visible($row, $userid);
            $out[] = self::dto($row);
        }
        return $out;
    }

    /** Only explicitly approved REQ items cross from private owner model into corporate architecture. */
    public static function published_requirement_sets(string $companyuuid, int $viewerid): array {
        global $DB;
        company_context_service::context($companyuuid, $viewerid);
        if (!permission_service::can_manage_management($companyuuid, $viewerid) && !self::can_manage($companyuuid, $viewerid)) {
            return [];
        }
        $groups = [];
        foreach ($DB->get_records('local_kbacompany_ownerframe', ['companyuuid' => $companyuuid], 'uuid ASC, revision ASC') as $row) {
            $groups[$row->uuid][] = $row;
        }
        $out = [];
        foreach ($groups as $uuid => $versions) {
            $latest = end($versions);
            if ($latest->status === 'archived') {
                continue;
            }
            $published = null;
            foreach (array_reverse($versions) as $candidate) {
                if ($candidate->status === 'published') {
                    $published = $candidate;
                    break;
                }
            }
            if (!$published) {
                continue;
            }
            $payload = self::decode((string)$published->payload);
            $requirements = isset($payload['requirements']) && is_array($payload['requirements']) ? $payload['requirements'] : [];
            if (!$requirements) {
                continue;
            }
            $out[] = [
                'uuid' => (string)$published->uuid,
                'revision' => (int)$published->revision,
                'requirements' => $requirements,
                'label' => get_string('ownermodel_reqpackage', 'local_kbacompany', (object)['revision' => (int)$published->revision]),
            ];
        }
        return $out;
    }

    public static function requirement_set(string $companyuuid, int $viewerid, string $uuid, int $revision = 0): ?array {
        global $DB;
        company_context_service::context($companyuuid, $viewerid);
        if (!permission_service::can_manage_management($companyuuid, $viewerid) && !self::can_manage($companyuuid, $viewerid)) {
            return null;
        }
        if ($revision > 0) {
            $row = $DB->get_record('local_kbacompany_ownerframe', [
                'companyuuid' => $companyuuid,
                'uuid' => $uuid,
                'revision' => $revision,
                'status' => 'published',
            ]);
            if (!$row) {
                return null;
            }
            $payload = self::decode((string)$row->payload);
            $requirements = isset($payload['requirements']) && is_array($payload['requirements']) ? $payload['requirements'] : [];
            return $requirements ? [
                'uuid' => (string)$row->uuid,
                'revision' => (int)$row->revision,
                'requirements' => $requirements,
                'label' => get_string('ownermodel_reqpackage', 'local_kbacompany', (object)['revision' => (int)$row->revision]),
            ] : null;
        }
        foreach (self::published_requirement_sets($companyuuid, $viewerid) as $set) {
            if ($set['uuid'] === $uuid) {
                return $set;
            }
        }
        return null;
    }

    public static function context(string $companyuuid, int $userid): array {
        global $DB;
        $company = company_context_service::context($companyuuid, $userid);
        self::authorise_view($companyuuid, $userid);
        $canedit = self::can_manage($companyuuid, $userid);
        $canreview = self::can_review($companyuuid, $userid);
        $groups = [];
        foreach ($DB->get_records('local_kbacompany_ownerframe', ['companyuuid' => $companyuuid], 'uuid ASC, revision ASC') as $row) {
            if (!is_siteadmin($userid) && !$canreview && (int)$row->owneruserid !== $userid) {
                continue;
            }
            if (!is_siteadmin($userid) && $canedit && !$canreview && (int)$row->owneruserid !== $userid) {
                continue;
            }
            $groups[$row->uuid][] = $row;
        }
        $frames = [];
        foreach ($groups as $versions) {
            $latest = end($versions);
            if ($latest->status !== 'archived') {
                $frame = self::dto($latest);
                $frame['editable'] = is_siteadmin($userid) || (int)$latest->owneruserid === $userid;
                $frames[] = $frame;
            }
        }
        usort($frames, static function(array $a, array $b): int {
            return (int)$b['timemodified'] <=> (int)$a['timemodified'];
        });
        return [
            'company' => $company,
            'frames' => $frames,
            'can_manage' => $canedit,
            'can_review' => $canreview,
            'contract' => self::contract(),
        ];
    }
}
