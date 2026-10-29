<?php
namespace local_kbacompany\local;

defined('MOODLE_INTERNAL') || die();

/**
 * Record-oriented Business Model Canvas 2.0 service.
 *
 * BMC root revisions remain in local_kbacompany_bizmodel. This service owns
 * record-level Canvas items, pinned owner requirements, comments and snapshot
 * projection for a specific BMC revision.
 */
class business_model_crud_service {
    public const CONTRACT_VERSION = '0.1.0';
    public const BLOCKS = [
        'segments', 'valueprop', 'relationships', 'channels', 'activities',
        'resources', 'partners', 'revenue', 'costs',
    ];
    public const STATES = ['asis', 'target'];
    public const ASIS_MARKERS = ['fact', 'data', 'hyp', 'open'];
    public const TARGET_MARKERS = ['target', 'open'];
    public const INTENTS = ['', 'yellow', 'orange', 'pink', 'red', 'green', 'blue'];
    public const HORIZONS = ['', '0_12', '1_3', '3_5'];

    public static function contract(): array {
        $contract = [
            'component' => 'local_kbacompany',
            'contract' => 'bms_business_model_crud',
            'version' => self::CONTRACT_VERSION,
            'company_scope' => 'required',
            'root' => 'local_kbacompany_bizmodel',
            'objects' => ['BusinessModelCanvas', 'BusinessModelItem', 'PinnedOwnerRequirement', 'BusinessModelComment'],
            'rules' => [
                'canvas_revision_is_snapshot_boundary',
                'canvas_item_is_one_business_statement',
                'asis_and_target_are_separate_records',
                'asis_requires_fact_data_hyp_or_open_marker',
                'target_is_state_not_action',
                'owner_requirements_are_revision_pinned',
                'owner_confidential_content_does_not_cross_into_team_canvas',
                'position_first_accountability',
                'published_snapshot_is_immutable',
                'gap_is_not_created_implicitly',
                'no_parallel_kpi_or_project_engine',
            ],
        ];
        $contract['schema_hash_algorithm'] = 'sha256';
        $contract['schema_hash'] = hash('sha256', json_encode($contract,
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        return $contract;
    }

    private static function fail(string $key): void {
        throw new \moodle_exception('bmc_' . $key, 'local_kbacompany');
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
        $value = trim((string)($data[$key] ?? ''));
        if (($required && $value === '') || \core_text::strlen($value) > $max) {
            self::fail('invalid');
        }
        return $value;
    }

    private static function can_manage(string $companyuuid, int $userid): bool {
        return market_model_service::can_manage($companyuuid, $userid);
    }

    private static function authorise_view(string $companyuuid, int $userid): void {
        company_context_service::context($companyuuid, $userid);
    }

    private static function authorise_manage(string $companyuuid, int $userid): void {
        self::authorise_view($companyuuid, $userid);
        if (!self::can_manage($companyuuid, $userid)) {
            throw new \required_capability_exception(\context_system::instance(),
                'local/kbacompany:manageorganization', 'nopermissions', '');
        }
    }

    /** Return exact or latest visible BMC root revision. */
    public static function model(string $companyuuid, int $userid, string $uuid, int $revision = 0): array {
        global $DB;
        self::authorise_view($companyuuid, $userid);
        $canmanage = self::can_manage($companyuuid, $userid);
        $conditions = ['companyuuid' => $companyuuid, 'uuid' => $uuid];
        if ($revision > 0) {
            $conditions['revision'] = $revision;
            $row = $DB->get_record('local_kbacompany_bizmodel', $conditions);
            if (!$row || (!$canmanage && (string)$row->status !== 'published')) {
                self::fail('reference');
            }
            return self::model_dto($row);
        }
        $rows = $DB->get_records('local_kbacompany_bizmodel', $conditions, 'revision DESC');
        foreach ($rows as $row) {
            if ((string)$row->status === 'archived') {
                if ($canmanage) {
                    return self::model_dto($row);
                }
                continue;
            }
            if ($canmanage || (string)$row->status === 'published') {
                return self::model_dto($row);
            }
        }
        self::fail('reference');
    }

    /** Latest root revision regardless of visibility; caller must already be authorised. */
    private static function latest_model_row(string $companyuuid, string $uuid): \stdClass {
        global $DB;
        $rows = $DB->get_records('local_kbacompany_bizmodel', [
            'companyuuid' => $companyuuid, 'uuid' => $uuid,
        ], 'revision DESC', '*', 0, 1);
        if (!$rows) {
            self::fail('reference');
        }
        return reset($rows);
    }

    private static function model_dto(\stdClass $row): array {
        $canvas = [];
        if (!empty($row->canvaspayload)) {
            $decoded = json_decode((string)$row->canvaspayload, true);
            if (is_array($decoded)) {
                $canvas = $decoded;
            }
        }
        return [
            'uuid' => (string)$row->uuid,
            'revision' => (int)$row->revision,
            'status' => (string)$row->status,
            'stablecode' => (string)($row->stablecode ?? ''),
            'name' => (string)$row->name,
            'ownerframeuuid' => (string)($row->ownerframeuuid ?? ''),
            'ownerframerevision' => (int)($row->ownerframerevision ?? 0),
            'summary' => (string)($canvas['summary'] ?? ''),
            'horizon' => (string)($canvas['horizon'] ?? ''),
            'canvas' => $canvas,
            'createdby' => (int)$row->createdby,
            'modifiedby' => (int)$row->modifiedby,
            'timecreated' => (int)$row->timecreated,
            'timemodified' => (int)$row->timemodified,
        ];
    }

    /** Ensure a root revision is the current editable draft. */
    private static function editable_model(string $companyuuid, int $userid, string $uuid, int $revision): array {
        self::authorise_manage($companyuuid, $userid);
        $latest = self::latest_model_row($companyuuid, $uuid);
        if ((int)$latest->revision !== $revision || (string)$latest->status !== 'draft') {
            self::fail('modelnoteditable');
        }
        return self::model_dto($latest);
    }

    /**
     * Clone the latest item state from one root revision to another.
     * Called by market_model_service whenever a new BMC root revision is created.
     */
    public static function clone_snapshot(string $companyuuid, string $canvasuuid, int $fromrevision,
            int $torevision, string $snapshotstatus): void {
        global $DB;
        if ($fromrevision <= 0 || $torevision <= 0 || $fromrevision === $torevision) {
            return;
        }
        $items = self::snapshot_rows($companyuuid, $canvasuuid, $fromrevision, true);
        $now = time();
        foreach ($items as $item) {
            $row = clone $item;
            unset($row->id);
            $row->canvasrevision = $torevision;
            if ((string)$item->status === 'archived') {
                // Item tombstones stay tombstones in all descendant snapshots.
                $row->status = 'archived';
            } else if ($snapshotstatus === 'published') {
                $row->status = 'published';
            } else if ($snapshotstatus === 'archived') {
                // Root archival is handled by the root record; active item content stays visible in historical snapshots.
                $row->status = 'published';
            } else {
                // A new working root revision is editable even when inherited from a published snapshot.
                $row->status = 'draft';
            }
            $row->timemodified = $now;
            $DB->insert_record('local_kbacompany_bmcitem', $row);
        }
    }

    /** Current item row for each item UUID inside one root revision. */
    private static function snapshot_rows(string $companyuuid, string $canvasuuid, int $canvasrevision,
            bool $includehidden = false): array {
        global $DB;
        $records = $DB->get_records('local_kbacompany_bmcitem', [
            'companyuuid' => $companyuuid,
            'canvasuuid' => $canvasuuid,
            'canvasrevision' => $canvasrevision,
        ], 'itemuuid ASC, itemrevision ASC');
        $latest = [];
        foreach ($records as $row) {
            $latest[(string)$row->itemuuid] = $row;
        }
        if ($includehidden) {
            return array_values($latest);
        }
        return array_values(array_filter($latest, static function($row): bool {
            return (string)$row->status !== 'archived';
        }));
    }

    public static function items(string $companyuuid, int $userid, string $canvasuuid, int $canvasrevision,
            string $block = ''): array {
        self::model($companyuuid, $userid, $canvasuuid, $canvasrevision);
        if ($block !== '' && !in_array($block, self::BLOCKS, true)) {
            self::fail('invalid');
        }
        $out = [];
        foreach (self::snapshot_rows($companyuuid, $canvasuuid, $canvasrevision) as $row) {
            if ($block !== '' && (string)$row->blockkey !== $block) {
                continue;
            }
            $out[] = self::item_dto($row, self::requirements_for_item($companyuuid,
                (string)$row->itemuuid, (int)$row->itemrevision));
        }
        usort($out, static function(array $a, array $b): int {
            if ($a['sortorder'] === $b['sortorder']) {
                return strcmp($a['stablecode'], $b['stablecode']);
            }
            return $a['sortorder'] < $b['sortorder'] ? -1 : 1;
        });
        return $out;
    }

    public static function item(string $companyuuid, int $userid, string $canvasuuid, int $canvasrevision,
            string $itemuuid, int $itemrevision = 0): array {
        global $DB;
        self::model($companyuuid, $userid, $canvasuuid, $canvasrevision);
        $conditions = [
            'companyuuid' => $companyuuid,
            'canvasuuid' => $canvasuuid,
            'canvasrevision' => $canvasrevision,
            'itemuuid' => $itemuuid,
        ];
        if ($itemrevision > 0) {
            $conditions['itemrevision'] = $itemrevision;
            $row = $DB->get_record('local_kbacompany_bmcitem', $conditions);
        } else {
            $rows = $DB->get_records('local_kbacompany_bmcitem', $conditions, 'itemrevision DESC', '*', 0, 1);
            $row = $rows ? reset($rows) : false;
        }
        if (!$row) {
            self::fail('reference');
        }
        return self::item_dto($row, self::requirements_for_item($companyuuid, $itemuuid,
            (int)$row->itemrevision));
    }

    private static function item_dto(\stdClass $row, array $requirements): array {
        return [
            'canvasuuid' => (string)$row->canvasuuid,
            'canvasrevision' => (int)$row->canvasrevision,
            'uuid' => (string)$row->itemuuid,
            'revision' => (int)$row->itemrevision,
            'stablecode' => (string)$row->stablecode,
            'status' => (string)$row->status,
            'block' => (string)$row->blockkey,
            'state' => (string)$row->statekey,
            'marker' => (string)$row->marker,
            'intent' => (string)$row->intent,
            'horizon' => (string)$row->horizon,
            'title' => (string)$row->title,
            'formulation' => (string)$row->formulation,
            'evidence' => (string)$row->evidencenote,
            'source' => (string)$row->evidencesource,
            'testcriteria' => (string)$row->testcriteria,
            'ownerpositionuuid' => (string)$row->ownerpositionuuid,
            'sortorder' => (int)$row->sortorder,
            'requirements' => $requirements,
            'createdby' => (int)$row->createdby,
            'modifiedby' => (int)$row->modifiedby,
            'timecreated' => (int)$row->timecreated,
            'timemodified' => (int)$row->timemodified,
        ];
    }

    /** Save one Canvas item as a new item revision inside the current draft root snapshot. */
    public static function save_item(string $companyuuid, int $userid, string $canvasuuid, int $canvasrevision,
            array $data, string $itemuuid = '', int $expecteditemrevision = 0): string {
        global $DB;
        $model = self::editable_model($companyuuid, $userid, $canvasuuid, $canvasrevision);
        $block = self::text($data, 'block', 24, true);
        $state = self::text($data, 'state', 12, true);
        $marker = self::text($data, 'marker', 12, true);
        $intent = self::text($data, 'intent', 12, false);
        $horizon = self::text($data, 'horizon', 12, false);
        if (!in_array($block, self::BLOCKS, true) || !in_array($state, self::STATES, true) ||
                !in_array($intent, self::INTENTS, true) || !in_array($horizon, self::HORIZONS, true)) {
            self::fail('invalid');
        }
        $allowedmarkers = $state === 'asis' ? self::ASIS_MARKERS : self::TARGET_MARKERS;
        if (!in_array($marker, $allowedmarkers, true)) {
            self::fail('invalidmarker');
        }
        $title = self::text($data, 'title', 255, true);
        $formulation = self::text($data, 'formulation', 12000, true);
        $evidence = self::text($data, 'evidence', 8000, false);
        $source = self::text($data, 'source', 2000, false);
        $testcriteria = self::text($data, 'testcriteria', 4000, false);
        if ($state === 'asis' && $marker === 'fact' && $source === '' && $evidence === '') {
            self::fail('factsource');
        }
        if ($state === 'asis' && $marker === 'hyp' && $testcriteria === '') {
            self::fail('hypcriteria');
        }
        $positionuuid = self::text($data, 'ownerpositionuuid', 36, false);
        if ($positionuuid !== '' && !$DB->record_exists('local_kbacompany_position', [
                'companyuuid' => $companyuuid, 'uuid' => $positionuuid, 'active' => 1])) {
            self::fail('positioninvalid');
        }
        $reqcodes = isset($data['requirements']) && is_array($data['requirements']) ? $data['requirements'] : [];
        $validatedreqs = self::validate_requirements($companyuuid, $userid, $model, $reqcodes);
        $sortorder = max(0, min(999999, (int)($data['sortorder'] ?? 0)));

        $lock = \core\lock\lock_config::get_lock_factory('local_kbacompany')->get_lock(
            'bmcitem:' . sha1($companyuuid . '|' . $canvasuuid . '|' . $canvasrevision), 10);
        if (!$lock) {
            self::fail('lock');
        }
        try {
            $old = null;
            if ($itemuuid !== '') {
                $rows = $DB->get_records('local_kbacompany_bmcitem', [
                    'companyuuid' => $companyuuid,
                    'canvasuuid' => $canvasuuid,
                    'canvasrevision' => $canvasrevision,
                    'itemuuid' => $itemuuid,
                ], 'itemrevision DESC', '*', 0, 1);
                $old = $rows ? reset($rows) : null;
                if (!$old || (int)$old->itemrevision !== $expecteditemrevision ||
                        (string)$old->status === 'archived') {
                    self::fail('conflict');
                }
            } else if ($expecteditemrevision !== 0) {
                self::fail('conflict');
            }

            $stableuuid = $old ? (string)$old->itemuuid : self::uuid();
            // Allocate before the business transaction so a failed insert consumes the code rather than reusing it.
            $stablecode = $old ? (string)$old->stablecode : self::allocate_item_code($companyuuid);
            $newrevision = $old ? ((int)$old->itemrevision + 1) : 1;
            $tx = $DB->start_delegated_transaction();
            $now = time();
            $row = (object)[
                'companyuuid' => $companyuuid,
                'canvasuuid' => $canvasuuid,
                'canvasrevision' => $canvasrevision,
                'itemuuid' => $stableuuid,
                'itemrevision' => $newrevision,
                'stablecode' => $stablecode,
                'status' => 'draft',
                'blockkey' => $block,
                'statekey' => $state,
                'marker' => $marker,
                'intent' => $intent,
                'horizon' => $horizon,
                'title' => $title,
                'formulation' => $formulation,
                'evidencenote' => $evidence,
                'evidencesource' => $source,
                'testcriteria' => $testcriteria,
                'ownerpositionuuid' => $positionuuid,
                'sortorder' => $sortorder,
                'createdby' => $old ? (int)$old->createdby : $userid,
                'modifiedby' => $userid,
                'timecreated' => $old ? (int)$old->timecreated : $now,
                'timemodified' => $now,
            ];
            $DB->insert_record('local_kbacompany_bmcitem', $row);
            self::write_requirement_links($companyuuid, $stableuuid, $newrevision, $model, $validatedreqs);
            $tx->allow_commit();
            return $stableuuid;
        } catch (\Throwable $e) {
            if (isset($tx)) {
                $tx->rollback($e);
            }
            throw $e;
        } finally {
            $lock->release();
        }
    }

    public static function archive_item(string $companyuuid, int $userid, string $canvasuuid, int $canvasrevision,
            string $itemuuid, int $expecteditemrevision): void {
        global $DB;
        self::editable_model($companyuuid, $userid, $canvasuuid, $canvasrevision);
        $lock = \core\lock\lock_config::get_lock_factory('local_kbacompany')->get_lock(
            'bmcitem:' . sha1($companyuuid . '|' . $canvasuuid . '|' . $canvasrevision), 10);
        if (!$lock) {
            self::fail('lock');
        }
        try {
            $tx = $DB->start_delegated_transaction();
            $rows = $DB->get_records('local_kbacompany_bmcitem', [
                'companyuuid' => $companyuuid,
                'canvasuuid' => $canvasuuid,
                'canvasrevision' => $canvasrevision,
                'itemuuid' => $itemuuid,
            ], 'itemrevision DESC', '*', 0, 1);
            $old = $rows ? reset($rows) : null;
            if (!$old || (int)$old->itemrevision !== $expecteditemrevision || (string)$old->status === 'archived') {
                self::fail('conflict');
            }
            $row = clone $old;
            unset($row->id);
            $row->itemrevision = $expecteditemrevision + 1;
            $row->status = 'archived';
            $row->modifiedby = $userid;
            $row->timemodified = time();
            $DB->insert_record('local_kbacompany_bmcitem', $row);
            self::copy_requirement_links($companyuuid, $itemuuid, $expecteditemrevision, $row->itemrevision);
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

    private static function allocate_item_code(string $companyuuid): string {
        global $DB;
        $minimum = 0;
        $codes = $DB->get_fieldset_select('local_kbacompany_bmcitem', 'stablecode',
            'companyuuid = :companyuuid AND stablecode <> :empty', ['companyuuid' => $companyuuid, 'empty' => '']);
        foreach ($codes as $code) {
            if (preg_match('/^BMCI-(\d+)$/', (string)$code, $m)) {
                $minimum = max($minimum, (int)$m[1]);
            }
        }
        return stable_code_service::next($companyuuid, 'businessmodel:item', 'BMCI',
            static function(string $candidate) use ($DB, $companyuuid): bool {
                return !$DB->record_exists('local_kbacompany_bmcitem', [
                    'companyuuid' => $companyuuid, 'stablecode' => $candidate,
                ]);
            }, $minimum);
    }

    private static function validate_requirements(string $companyuuid, int $userid, array $model, array $reqcodes): array {
        $reqcodes = array_values(array_unique(array_filter(array_map(static function($v): string {
            return strtoupper(trim((string)$v));
        }, $reqcodes))));
        if (!$reqcodes) {
            return [];
        }
        if ($model['ownerframeuuid'] === '' || $model['ownerframerevision'] <= 0) {
            self::fail('ownerreq');
        }
        $set = owner_service::requirement_set($companyuuid, $userid, $model['ownerframeuuid'],
            $model['ownerframerevision']);
        if (!$set) {
            self::fail('ownerreq');
        }
        $allowed = [];
        foreach ($set['requirements'] as $requirement) {
            if (!empty($requirement['id'])) {
                $allowed[(string)$requirement['id']] = true;
            }
        }
        foreach ($reqcodes as $code) {
            if (!isset($allowed[$code])) {
                self::fail('ownerreq');
            }
        }
        return $reqcodes;
    }

    private static function write_requirement_links(string $companyuuid, string $itemuuid, int $itemrevision,
            array $model, array $reqcodes): void {
        global $DB;
        foreach ($reqcodes as $code) {
            $DB->insert_record('local_kbacompany_bmcitemreq', (object)[
                'companyuuid' => $companyuuid,
                'itemuuid' => $itemuuid,
                'itemrevision' => $itemrevision,
                'ownerframeuuid' => (string)$model['ownerframeuuid'],
                'ownerframerevision' => (int)$model['ownerframerevision'],
                'requirementcode' => $code,
                'timecreated' => time(),
            ]);
        }
    }

    private static function copy_requirement_links(string $companyuuid, string $itemuuid, int $fromrevision,
            int $torevision): void {
        global $DB;
        foreach ($DB->get_records('local_kbacompany_bmcitemreq', [
            'companyuuid' => $companyuuid, 'itemuuid' => $itemuuid, 'itemrevision' => $fromrevision,
        ], 'id ASC') as $old) {
            $row = clone $old;
            unset($row->id);
            $row->itemrevision = $torevision;
            $row->timecreated = time();
            $DB->insert_record('local_kbacompany_bmcitemreq', $row);
        }
    }

    private static function requirements_for_item(string $companyuuid, string $itemuuid, int $itemrevision): array {
        global $DB;
        $out = [];
        foreach ($DB->get_records('local_kbacompany_bmcitemreq', [
            'companyuuid' => $companyuuid, 'itemuuid' => $itemuuid, 'itemrevision' => $itemrevision,
        ], 'requirementcode ASC') as $row) {
            $out[] = [
                'code' => (string)$row->requirementcode,
                'ownerframeuuid' => (string)$row->ownerframeuuid,
                'ownerframerevision' => (int)$row->ownerframerevision,
            ];
        }
        return $out;
    }

    public static function item_history(string $companyuuid, int $userid, string $itemuuid): array {
        global $DB;
        self::authorise_view($companyuuid, $userid);
        $records = $DB->get_records('local_kbacompany_bmcitem', [
            'companyuuid' => $companyuuid, 'itemuuid' => $itemuuid,
        ], 'itemrevision DESC, canvasrevision DESC');
        $seen = [];
        $out = [];
        foreach ($records as $row) {
            $revision = (int)$row->itemrevision;
            if (isset($seen[$revision])) {
                continue;
            }
            $seen[$revision] = true;
            $out[] = self::item_dto($row, self::requirements_for_item($companyuuid, $itemuuid, $revision));
        }
        return $out;
    }

    public static function add_comment(string $companyuuid, int $userid, string $canvasuuid, int $canvasrevision,
            string $itemuuid, int $itemrevision, string $comment): void {
        global $DB;
        self::authorise_manage($companyuuid, $userid);
        self::model($companyuuid, $userid, $canvasuuid, $canvasrevision);
        $item = self::item($companyuuid, $userid, $canvasuuid, $canvasrevision, $itemuuid, $itemrevision);
        unset($item);
        $comment = trim($comment);
        if ($comment === '' || \core_text::strlen($comment) > 10000) {
            self::fail('invalid');
        }
        $DB->insert_record('local_kbacompany_bmccomment', (object)[
            'companyuuid' => $companyuuid,
            'itemuuid' => $itemuuid,
            'itemrevision' => $itemrevision,
            'authoruserid' => $userid,
            'comment' => $comment,
            'timecreated' => time(),
        ]);
    }

    public static function comments(string $companyuuid, int $userid, string $itemuuid, int $itemrevision): array {
        global $DB;
        self::authorise_view($companyuuid, $userid);
        $sql = 'SELECT c.*, u.firstname, u.lastname
                  FROM {local_kbacompany_bmccomment} c
             LEFT JOIN {user} u ON u.id = c.authoruserid
                 WHERE c.companyuuid = :companyuuid
                   AND c.itemuuid = :itemuuid
                   AND c.itemrevision = :itemrevision
              ORDER BY c.id ASC';
        $records = $DB->get_records_sql($sql, [
            'companyuuid' => $companyuuid,
            'itemuuid' => $itemuuid,
            'itemrevision' => $itemrevision,
        ]);
        $out = [];
        foreach ($records as $row) {
            $name = trim((string)$row->firstname . ' ' . (string)$row->lastname);
            $out[] = [
                'id' => (int)$row->id,
                'authoruserid' => (int)$row->authoruserid,
                'authorname' => $name !== '' ? $name : get_string('bmc_deleteduser', 'local_kbacompany'),
                'comment' => (string)$row->comment,
                'timecreated' => (int)$row->timecreated,
            ];
        }
        return $out;
    }

    /** Approved requirement options pinned to this BMC root revision. */
    public static function requirement_options(string $companyuuid, int $userid, array $model): array {
        if ($model['ownerframeuuid'] === '' || $model['ownerframerevision'] <= 0) {
            return [];
        }
        $set = owner_service::requirement_set($companyuuid, $userid, $model['ownerframeuuid'],
            $model['ownerframerevision']);
        if (!$set) {
            return [];
        }
        $out = [];
        foreach ($set['requirements'] as $requirement) {
            $code = trim((string)($requirement['id'] ?? ''));
            if ($code === '') {
                continue;
            }
            $text = trim((string)($requirement['requirement'] ?? $requirement['text'] ?? $requirement['title'] ?? ''));
            $out[$code] = $text !== '' ? $code . ' — ' . $text : $code;
        }
        return $out;
    }

    /** Position options with current assignee projection. */
    public static function position_options(string $companyuuid, int $userid): array {
        $context = position_service::positions_context($companyuuid, $userid);
        $out = [];
        foreach ($context['positions'] as $position) {
            $label = trim((string)$position['code'] . ' — ' . (string)$position['name'], ' —');
            $current = [];
            foreach ($position['assignments'] as $assignment) {
                if (!empty($assignment['current']) && !empty($assignment['employee_name'])) {
                    $current[] = (string)$assignment['employee_name'];
                }
            }
            if ($current) {
                $label .= ' (' . implode(', ', $current) . ')';
            }
            $out[(string)$position['uuid']] = $label;
        }
        return $out;
    }

    /** Readiness counters for one snapshot. No evaluative approval is inferred. */
    public static function readiness(string $companyuuid, int $userid, array $model): array {
        $items = self::items($companyuuid, $userid, $model['uuid'], $model['revision']);
        $byblock = [];
        foreach (self::BLOCKS as $block) {
            $byblock[$block] = ['asis' => 0, 'target' => 0, 'fact' => 0, 'data' => 0, 'hyp' => 0, 'open' => 0];
        }
        $reqused = [];
        foreach ($items as $item) {
            $byblock[$item['block']][$item['state']]++;
            if (isset($byblock[$item['block']][$item['marker']])) {
                $byblock[$item['block']][$item['marker']]++;
            }
            if ($item['state'] === 'target') {
                foreach ($item['requirements'] as $req) {
                    $reqused[$req['code']] = true;
                }
            }
        }
        $reqoptions = self::requirement_options($companyuuid, $userid, $model);
        return [
            'items' => count($items),
            'asis_blocks' => count(array_filter($byblock, static function(array $b): bool { return $b['asis'] > 0; })),
            'target_blocks' => count(array_filter($byblock, static function(array $b): bool { return $b['target'] > 0; })),
            'requirements_total' => count($reqoptions),
            'requirements_linked' => count($reqused),
            'byblock' => $byblock,
            'fact' => array_sum(array_column($byblock, 'fact')),
            'data' => array_sum(array_column($byblock, 'data')),
            'hyp' => array_sum(array_column($byblock, 'hyp')),
            'open' => array_sum(array_column($byblock, 'open')),
        ];
    }
}
