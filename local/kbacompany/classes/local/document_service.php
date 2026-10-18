<?php
namespace local_kbacompany\local;

defined('MOODLE_INTERNAL') || die();

/** ISO-style controlled documented information, acknowledgements and immutable records. */
class document_service {
    public const CONTRACT_VERSION = '0.3.4';
    public const STATES = ['draft', 'review', 'agreed', 'approved', 'published', 'effective', 'superseded', 'cancelled', 'archived'];
    public const SOURCE_KINDS = ['manual', 'stp'];
    public const TYPE_LEVELS = [
        'strategy' => 1, 'policy' => 1, 'goals' => 1, 'rsm' => 1, 'ofs' => 1,
        'procedure' => 2, 'stp' => 2, 'process_regulation' => 2,
        'sop' => 3, 'work_instruction' => 3, 'method_instruction' => 3, 'external_norm' => 3,
        'department_regulation' => 4, 'job_description' => 4,
        'form' => 5, 'other' => 3,
    ];

    public static function contract(): array {
        $contract = [
            'component' => 'local_kbacompany',
            'contract' => 'bms_document_control',
            'version' => self::CONTRACT_VERSION,
            'company_scope' => 'required',
            'states' => self::STATES,
            'document_levels' => [1, 2, 3, 4, 5],
            'source_kinds' => self::SOURCE_KINDS,
            'rules' => [
                'document_and_record_are_different_objects',
                'controlled_document_revisions_are_traceable',
                'draft_content_is_editable_but_released_content_is_immutable',
                'master_list_is_generated_from_controlled_objects',
                'obsolete_revisions_are_not_current_at_point_of_use',
                'effective_revision_supersedes_previous_effective_revision',
                'acknowledgement_is_revision_specific',
                'primary_file_belongs_to_exact_document_revision',
                'released_file_bytes_are_immutable',
                'completed_form_record_is_immutable_evidence',
                'stp_controlled_document_snapshots_exact_published_process_model_revision',
                'stp_business_truth_remains_process_model',
            ],
        ];
        $contract['schema_hash_algorithm'] = 'sha256';
        $contract['schema_hash'] = hash('sha256', json_encode($contract,
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        return $contract;
    }

    public static function can_manage(string $companyuuid, int $userid): bool {
        return permission_service::can_manage_management($companyuuid, $userid);
    }

    private static function authorise_manage(string $companyuuid, int $userid): void {
        company_context_service::context($companyuuid, $userid);
        if (!self::can_manage($companyuuid, $userid)) {
            throw new \required_capability_exception(\context_system::instance(),
                'local/kbacompany:manageorganization', 'nopermissions', '');
        }
    }

    private static function fail(string $key): void {
        throw new \moodle_exception('document_' . $key, 'local_kbacompany');
    }

    private static function uuid(): string {
        $bytes = random_bytes(16);
        $bytes[6] = chr((ord($bytes[6]) & 0x0f) | 0x40);
        $bytes[8] = chr((ord($bytes[8]) & 0x3f) | 0x80);
        $hex = bin2hex($bytes);
        return substr($hex, 0, 8) . '-' . substr($hex, 8, 4) . '-' . substr($hex, 12, 4) .
            '-' . substr($hex, 16, 4) . '-' . substr($hex, 20);
    }

    private static function text(array $data, string $field, int $max, bool $required = false): string {
        $value = trim((string)($data[$field] ?? ''));
        if (($required && $value === '') || \core_text::strlen($value) > $max) {
            self::fail('invalid');
        }
        return $value;
    }

    private static function latest(string $companyuuid, string $uuid): \stdClass {
        global $DB;
        $rows = $DB->get_records('local_kbacompany_doc',
            ['companyuuid' => $companyuuid, 'uuid' => $uuid], 'revision DESC', '*', 0, 1);
        if (!$rows) { self::fail('reference'); }
        return reset($rows);
    }

    private static function revision(string $companyuuid, string $uuid, int $revision): \stdClass {
        global $DB;
        $row = $DB->get_record('local_kbacompany_doc', [
            'companyuuid' => $companyuuid, 'uuid' => $uuid, 'revision' => $revision,
        ]);
        if (!$row) { self::fail('reference'); }
        return $row;
    }

    private static function effective(string $companyuuid, string $uuid): ?\stdClass {
        global $DB;
        $rows = $DB->get_records('local_kbacompany_doc', [
            'companyuuid' => $companyuuid, 'uuid' => $uuid, 'status' => 'effective',
        ], 'revision DESC', '*', 0, 1);
        return $rows ? reset($rows) : null;
    }

    private static function code_available(string $companyuuid, string $code, string $uuid = ''): void {
        global $DB;
        if ($DB->record_exists_sql('SELECT id FROM {local_kbacompany_doc} WHERE companyuuid = :c AND code = :code AND uuid <> :uuid',
                ['c'=>$companyuuid,'code'=>$code,'uuid'=>$uuid])) { self::fail('duplicatecode'); }
    }

    private static function validate_type(string $doctype, int $level): void {
        if (!array_key_exists($doctype, self::TYPE_LEVELS) || $level < 1 || $level > 5) {
            self::fail('invalid');
        }
        if ($doctype !== 'other' && self::TYPE_LEVELS[$doctype] !== $level) {
            self::fail('invalidlevel');
        }
    }

    private static function validate_position(string $companyuuid, string $positionuuid): void {
        global $DB;
        if ($positionuuid !== '' && !$DB->record_exists('local_kbacompany_position', [
                'companyuuid' => $companyuuid, 'uuid' => $positionuuid, 'active' => 1])) {
            self::fail('invalidowner');
        }
    }

    private static function company_member(string $companyuuid, int $userid): bool {
        global $DB;
        return $DB->record_exists_sql("SELECT m.id FROM {local_kbacompany_member} m
            JOIN {user} u ON u.id = m.userid WHERE m.companyuuid = :c AND m.userid = :u
            AND m.active = 1 AND u.deleted = 0 AND u.suspended = 0", ['c'=>$companyuuid, 'u'=>$userid]);
    }

    private static function company_members(string $companyuuid, int $actor): array {
        global $DB;
        self::authorise_manage($companyuuid, $actor);
        $rows = $DB->get_records_sql("SELECT DISTINCT u.id,u.firstname,u.lastname,u.firstnamephonetic,
            u.lastnamephonetic,u.middlename,u.alternatename
            FROM {local_kbacompany_member} m JOIN {user} u ON u.id = m.userid
            WHERE m.companyuuid = :c AND m.active = 1 AND u.deleted = 0 AND u.suspended = 0
            ORDER BY u.lastname,u.firstname,u.id", ['c'=>$companyuuid]);
        $out = [];
        foreach ($rows as $user) { $out[] = ['userid'=>(int)$user->id, 'name'=>fullname($user)]; }
        return $out;
    }

    private static function log(string $companyuuid, string $uuid, int $actor, string $action, $old, $new): void {
        global $DB;
        $DB->insert_record('local_kbacompany_aclog', (object)[
            'companyuuid' => $companyuuid, 'objectuuid' => $uuid, 'action' => 'doc_' . $action,
            'actorid' => $actor,
            'oldvalue' => $old === null ? null : json_encode($old, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'newvalue' => $new === null ? null : json_encode($new, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'timecreated' => time(),
        ]);
    }

    /** Exact JSON snapshot of a generated STP source; the process model remains business truth. */
    private static function stp_snapshot(string $companyuuid, int $userid, string $processuuid): array {
        $stp = stp_service::context($companyuuid, $userid, $processuuid);
        if (($stp['model']['status'] ?? '') !== 'published') {
            self::fail('sourceunpublished');
        }
        unset($stp['can_manage'], $stp['source_change_version']);
        $json = json_encode($stp, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($json === false) { self::fail('invalidsource'); }
        return [
            'content' => $json,
            'hash' => hash('sha256', $json),
            'revision' => (int)$stp['model']['revision'],
            'code' => (string)$stp['document']['code'],
            'title' => (string)$stp['document']['title'],
        ];
    }

    /** Source preview is an exact published snapshot, with no creation side effect. */
    public static function source_preview(string $companyuuid, int $actor, string $processuuid): array {
        self::authorise_manage($companyuuid, $actor);
        return self::stp_snapshot($companyuuid, $actor, $processuuid);
    }

    private static function base_row(string $companyuuid, int $actor, string $uuid, int $revision,
            array $data): \stdClass {
        $doctype = self::text($data, 'doctype', 24, true);
        $level = (int)($data['doclevel'] ?? (self::TYPE_LEVELS[$doctype] ?? 0));
        self::validate_type($doctype, $level);
        $ownerpositionuuid = self::text($data, 'ownerpositionuuid', 36);
        self::validate_position($companyuuid, $ownerpositionuuid);
        $sourcekind = self::text($data, 'sourcekind', 24);
        if ($sourcekind === '') { $sourcekind = 'manual'; }
        if (!in_array($sourcekind, self::SOURCE_KINDS, true)) { self::fail('invalidsource'); }
        $now = time();
        return (object)[
            'companyuuid' => $companyuuid,
            'uuid' => $uuid,
            'revision' => $revision,
            'status' => 'draft',
            'reviewround' => 1,
            'code' => self::text($data, 'code', 80, true),
            'title' => self::text($data, 'title', 255, true),
            'doctype' => $doctype,
            'doclevel' => $level,
            'ownerpositionuuid' => $ownerpositionuuid,
            'sourcekind' => $sourcekind,
            'sourceuuid' => self::text($data, 'sourceuuid', 36),
            'sourcerevision' => (int)($data['sourcerevision'] ?? 0),
            'sourcehash' => self::text($data, 'sourcehash', 64),
            'contentformat' => self::text($data, 'contentformat', 16) ?: 'text',
            'content' => (string)($data['content'] ?? ''),
            'filemanifest' => null,
            'changereason' => self::text($data, 'changereason', 4000),
            'supersedesrevision' => (int)($data['supersedesrevision'] ?? 0),
            'effectivefrom' => 0,
            'effectiveto' => 0,
            'createdby' => $actor,
            'modifiedby' => $actor,
            'timecreated' => $now,
            'timemodified' => $now,
        ];
    }

    public static function create_manual(string $companyuuid, int $actor, array $data): string {
        global $DB;
        self::authorise_manage($companyuuid, $actor);
        $upload = document_upload::inspect((array)($data['upload'] ?? []), true);
        $data['sourcekind'] = 'manual';
        $data['contentformat'] = 'text';
        if (\core_text::strlen((string)($data['content'] ?? '')) > 100000) { self::fail('invalid'); }
        $code = self::text($data, 'code', 80);
        if ($code === '') {
            $code = self::allocate_code($companyuuid);
            $data['code'] = $code;
        }
        self::code_available($companyuuid, $code);
        $uuid = self::uuid();
        $row = self::base_row($companyuuid, $actor, $uuid, 1, $data);
        $lock = \core\lock\lock_config::get_lock_factory('local_kbacompany')
            ->get_lock('document:' . $companyuuid, 10);
        if (!$lock) { self::fail('lock'); }
        try {
            $tx = $DB->start_delegated_transaction();
            self::code_available($companyuuid, $code);
            $row->id = $DB->insert_record('local_kbacompany_doc', $row);
            if ($upload) {
                self::store_uploaded_file($row, $actor, $upload);
                $DB->update_record('local_kbacompany_doc', $row);
            }
            self::log($companyuuid, $uuid, $actor, 'create', null, $row);
            $tx->allow_commit();
        } catch (\Throwable $e) {
            if (isset($tx)) { $tx->rollback($e); }
            throw $e;
        } finally {
            $lock->release();
        }
        return $uuid;
    }

    public static function create_from_stp(string $companyuuid, int $actor, string $processuuid,
            string $changereason = '', string $expectedsource = ''): string {
        global $DB;
        self::authorise_manage($companyuuid, $actor);
        if ($DB->record_exists('local_kbacompany_doc', [
                'companyuuid' => $companyuuid, 'sourcekind' => 'stp', 'sourceuuid' => $processuuid])) {
            self::fail('duplicatesource');
        }
        $snapshot = self::stp_snapshot($companyuuid, $actor, $processuuid);
        if ($expectedsource !== '' && !hash_equals($expectedsource, $snapshot['hash'])) { self::fail('conflict'); }
        self::code_available($companyuuid, $snapshot['code']);
        $uuid = self::uuid();
        $row = self::base_row($companyuuid, $actor, $uuid, 1, [
            'code' => $snapshot['code'], 'title' => $snapshot['title'], 'doctype' => 'stp', 'doclevel' => 2,
            'sourcekind' => 'stp', 'sourceuuid' => $processuuid, 'sourcerevision' => $snapshot['revision'],
            'sourcehash' => $snapshot['hash'], 'contentformat' => 'json', 'content' => $snapshot['content'],
            'changereason' => $changereason,
        ]);
        $lock = \core\lock\lock_config::get_lock_factory('local_kbacompany')
            ->get_lock('document:' . $companyuuid, 10);
        if (!$lock) { self::fail('lock'); }
        try {
            $tx = $DB->start_delegated_transaction();
            if ($DB->record_exists('local_kbacompany_doc', [
                    'companyuuid' => $companyuuid, 'sourcekind' => 'stp', 'sourceuuid' => $processuuid])) {
                self::fail('duplicatesource');
            }
            self::code_available($companyuuid, $row->code);
            $DB->insert_record('local_kbacompany_doc', $row);
            self::log($companyuuid, $uuid, $actor, 'create_stp', null, $row);
            $tx->allow_commit();
        } catch (\Throwable $e) {
            if (isset($tx)) { $tx->rollback($e); }
            throw $e;
        } finally {
            $lock->release();
        }
        return $uuid;
    }

    /** Save editable metadata/content. Released source snapshots cannot be edited in place. */
    public static function save(string $companyuuid, int $actor, string $uuid, int $revision, array $data): void {
        global $DB;
        self::authorise_manage($companyuuid, $actor);
        $lock = \core\lock\lock_config::get_lock_factory('local_kbacompany')
            ->get_lock('document:' . $companyuuid . ':' . $uuid, 10);
        if (!$lock) { self::fail('lock'); }
        try {
            $tx = $DB->start_delegated_transaction();
            $old = self::latest($companyuuid, $uuid);
            if ((int)$old->revision !== $revision) { self::fail('conflict'); }
            if ($old->status !== 'draft') { self::fail('immutable'); }
            $row = clone $old;
            if (isset($data['code']) && trim((string)$data['code']) !== (string)$old->code) {
                self::fail('codeimmutable');
            }
            $row->code = $old->code;
            if (!empty($data['expected']) && !hash_equals(self::edit_token($old), (string)$data['expected'])) {
                self::fail('conflict');
            }
            self::code_available($companyuuid, $row->code, $uuid);
            $row->title = self::text($data, 'title', 255, true);
            $row->ownerpositionuuid = self::text($data, 'ownerpositionuuid', 36);
            if ($row->ownerpositionuuid !== $old->ownerpositionuuid) { self::validate_position($companyuuid, $row->ownerpositionuuid); }
            $row->changereason = self::text($data, 'changereason', 4000);
            if ($row->sourcekind === 'manual') {
                $doctype = self::text($data, 'doctype', 24, true);
                $level = (int)($data['doclevel'] ?? 0);
                self::validate_type($doctype, $level);
                $row->doctype = $doctype;
                $row->doclevel = $level;
                $row->content = (string)($data['content'] ?? '');
                if (\core_text::strlen($row->content) > 100000) { self::fail('invalid'); }
                $row->sourcehash = hash('sha256', $row->content);
            }
            $upload = document_upload::inspect((array)($data['upload'] ?? []), true);
            if ($upload) {
                $previous = document_upload::manifest($old->filemanifest ?? null);
                $changed = !$previous || $previous['sha256'] !== $upload['sha256'] || $previous['filename'] !== $upload['filename'];
                if ($previous && $changed && empty($data['confirmfile'])) { self::fail('fileconfirmation'); }
                if ($changed) { self::store_uploaded_file($row, $actor, $upload); }
            }
            $row->modifiedby = $actor;
            $row->timemodified = time();
            $DB->update_record('local_kbacompany_doc', $row);
            self::log($companyuuid, $uuid, $actor, 'save', $old, $row);
            $tx->allow_commit();
        } catch (\Throwable $e) {
            if (isset($tx)) { $tx->rollback($e); }
            throw $e;
        } finally {
            $lock->release();
        }
    }

    /** Start a new draft revision. For STP, re-snapshot the current published source model explicitly. */
    public static function new_revision(string $companyuuid, int $actor, string $uuid, int $expectedrevision,
            string $reason, string $expected = ''): int {
        global $DB;
        self::authorise_manage($companyuuid, $actor);
        $reason = trim($reason);
        if ($reason === '' || \core_text::strlen($reason) > 4000) { self::fail('reasonrequired'); }
        $lock = \core\lock\lock_config::get_lock_factory('local_kbacompany')
            ->get_lock('document:' . $companyuuid . ':' . $uuid, 10);
        if (!$lock) { self::fail('lock'); }
        try {
            $tx = $DB->start_delegated_transaction();
            $old = self::latest($companyuuid, $uuid);
            self::check_token($old, $expected);
            if ((int)$old->revision !== $expectedrevision) { self::fail('conflict'); }
            if (in_array($old->status, ['draft', 'review', 'agreed', 'approved'], true)) { self::fail('state'); }
            $row = clone $old;
            unset($row->id);
            $row->revision = (int)$old->revision + 1;
            $row->status = 'draft';
            $row->reviewround = 1;
            $row->changereason = $reason;
            $row->supersedesrevision = (int)$old->revision;
            $row->effectivefrom = 0;
            $row->effectiveto = 0;
            if ($old->sourcekind === 'stp') {
                $snapshot = self::stp_snapshot($companyuuid, $actor, $old->sourceuuid);
                $row->sourceuuid = $old->sourceuuid;
                $row->sourcerevision = $snapshot['revision'];
                $row->sourcehash = $snapshot['hash'];
                $row->content = $snapshot['content'];
                $row->contentformat = 'json';
            }
            $row->createdby = $actor;
            $row->modifiedby = $actor;
            $row->timecreated = time();
            $row->timemodified = time();
            // A refreshed STP snapshot must not silently inherit an attachment of the old source.
            if ($old->sourcekind === 'stp') { $row->filemanifest = null; }
            $row->id = $DB->insert_record('local_kbacompany_doc', $row);
            if (!empty($row->filemanifest)) { self::copy_revision_file($old, $row); }
            self::log($companyuuid, $uuid, $actor, 'new_revision', $old, $row);
            $tx->allow_commit();
            return (int)$row->revision;
        } catch (\Throwable $e) {
            if (isset($tx)) { $tx->rollback($e); }
            throw $e;
        } finally {
            $lock->release();
        }
    }

    public static function add_reviewer(string $companyuuid, int $actor, string $uuid, int $revision,
            int $reviewerid, string $expected = ''): void {
        company_context_service::context($companyuuid, $actor);
        self::authorise_manage($companyuuid, $actor);
        self::mutate($companyuuid, $actor, $uuid, $revision, $expected,
            static function() use ($companyuuid, $actor, $uuid, $revision, $reviewerid) {
                self::add_reviewer_locked($companyuuid, $actor, $uuid, $revision, $reviewerid);
            });
    }

    private static function add_reviewer_locked(string $companyuuid, int $actor, string $uuid, int $revision,
            int $reviewerid): void {
        global $DB;
        self::authorise_manage($companyuuid, $actor);
        if (!self::company_member($companyuuid, $reviewerid)) { self::fail('invalidreviewer'); }
        $doc = self::latest($companyuuid, $uuid);
        if ((int)$doc->revision !== $revision || $doc->status !== 'draft') { self::fail('state'); }
        if ($DB->record_exists('local_kbacompany_docreview', [
                'companyuuid' => $companyuuid, 'documentuuid' => $uuid, 'docrevision' => $revision,
                'reviewround' => $doc->reviewround, 'stage' => 'review', 'reviewerid' => $reviewerid])) {
            self::fail('duplicatereviewer');
        }
        $DB->insert_record('local_kbacompany_docreview', (object)[
            'companyuuid' => $companyuuid, 'documentuuid' => $uuid, 'docrevision' => $revision,
            'reviewround' => $doc->reviewround, 'stage' => 'review', 'reviewerid' => $reviewerid,
            'decision' => 'pending', 'comment' => '', 'timecreated' => time(), 'timedecided' => 0,
        ]);
        self::log($companyuuid, $uuid, $actor, 'reviewer_add', null,
            ['revision' => $revision, 'reviewerid' => $reviewerid, 'round' => $doc->reviewround]);
    }

    public static function submit_review(string $companyuuid, int $actor, string $uuid, int $revision, string $expected = ''): void {
        company_context_service::context($companyuuid, $actor);
        self::authorise_manage($companyuuid, $actor);
        self::mutate($companyuuid, $actor, $uuid, $revision, $expected,
            static function() use ($companyuuid, $actor, $uuid, $revision) {
                self::submit_review_locked($companyuuid, $actor, $uuid, $revision);
            });
    }

    private static function submit_review_locked(string $companyuuid, int $actor, string $uuid, int $revision): void {
        global $DB;
        self::authorise_manage($companyuuid, $actor);
        $doc = self::latest($companyuuid, $uuid);
        if ((int)$doc->revision !== $revision || $doc->status !== 'draft') { self::fail('state'); }
        if (!$DB->record_exists('local_kbacompany_docreview', [
                'companyuuid' => $companyuuid, 'documentuuid' => $uuid, 'docrevision' => $revision,
                'reviewround' => $doc->reviewround, 'stage' => 'review'])) {
            self::fail('reviewerrequired');
        }
        self::assert_complete_payload($doc);
        $old = clone $doc;
        $doc->status = 'review';
        $doc->modifiedby = $actor;
        $doc->timemodified = time();
        $DB->update_record('local_kbacompany_doc', $doc);
        self::log($companyuuid, $uuid, $actor, 'submit_review', $old, $doc);
    }

    /** Review decision. All current-round approvals automatically advance REVIEW -> AGREED. */
    public static function review_decision(string $companyuuid, int $actor, string $uuid, int $revision,
            string $decision, string $comment = '', string $expected = ''): void {
        company_context_service::context($companyuuid, $actor);
        self::mutate($companyuuid, $actor, $uuid, $revision, $expected,
            static function() use ($companyuuid, $actor, $uuid, $revision, $decision, $comment) {
                self::review_decision_locked($companyuuid, $actor, $uuid, $revision, $decision, $comment);
            });
    }

    private static function review_decision_locked(string $companyuuid, int $actor, string $uuid, int $revision,
            string $decision, string $comment = ''): void {
        global $DB;
        require_capability('local/kbacompany:viewowncompany', \context_system::instance(), $actor);
        company_context_service::context($companyuuid, $actor);
        $doc = self::latest($companyuuid, $uuid);
        if ((int)$doc->revision !== $revision || $doc->status !== 'review') { self::fail('state'); }
        if (!in_array($decision, ['approved', 'rejected'], true) || \core_text::strlen($comment) > 4000) { self::fail('invalid'); }
        if ($decision === 'rejected' && trim($comment) === '') { self::fail('reasonrequired'); }
        $review = $DB->get_record('local_kbacompany_docreview', [
            'companyuuid' => $companyuuid, 'documentuuid' => $uuid, 'docrevision' => $revision,
            'reviewround' => $doc->reviewround, 'stage' => 'review', 'reviewerid' => $actor,
        ]);
        if (!$review || $review->decision !== 'pending') { self::fail('notreviewer'); }
        $review->decision = $decision;
        $review->comment = trim($comment);
        $review->timedecided = time();
        $DB->update_record('local_kbacompany_docreview', $review);
        $pending = $DB->record_exists('local_kbacompany_docreview', [
            'companyuuid' => $companyuuid, 'documentuuid' => $uuid, 'docrevision' => $revision,
            'reviewround' => $doc->reviewround, 'stage' => 'review', 'decision' => 'pending',
        ]);
        $rejected = $DB->record_exists('local_kbacompany_docreview', [
            'companyuuid' => $companyuuid, 'documentuuid' => $uuid, 'docrevision' => $revision,
            'reviewround' => $doc->reviewround, 'stage' => 'review', 'decision' => 'rejected',
        ]);
        if (!$pending && !$rejected) {
            $doc->status = 'agreed';
            $doc->modifiedby = $actor;
            $doc->timemodified = time();
            $DB->update_record('local_kbacompany_doc', $doc);
        }
        self::log($companyuuid, $uuid, $actor, 'review_decision', null,
            ['revision' => $revision, 'decision' => $decision, 'comment' => $review->comment]);
    }

    /** Return a rejected/unfinished review to draft while preserving the old review round as history. */
    public static function return_to_draft(string $companyuuid, int $actor, string $uuid, int $revision, string $expected = ''): void {
        company_context_service::context($companyuuid, $actor);
        self::authorise_manage($companyuuid, $actor);
        self::mutate($companyuuid, $actor, $uuid, $revision, $expected,
            static function() use ($companyuuid, $actor, $uuid, $revision) {
                self::return_to_draft_locked($companyuuid, $actor, $uuid, $revision);
            });
    }

    private static function return_to_draft_locked(string $companyuuid, int $actor, string $uuid, int $revision): void {
        global $DB;
        self::authorise_manage($companyuuid, $actor);
        $doc = self::latest($companyuuid, $uuid);
        if ((int)$doc->revision !== $revision || !in_array($doc->status, ['review', 'agreed'], true)) {
            self::fail('state');
        }
        $old = clone $doc;
        $doc->status = 'draft';
        $doc->reviewround = (int)$doc->reviewround + 1;
        $doc->modifiedby = $actor;
        $doc->timemodified = time();
        $DB->update_record('local_kbacompany_doc', $doc);
        self::log($companyuuid, $uuid, $actor, 'return_draft', $old, $doc);
    }

    public static function approve(string $companyuuid, int $actor, string $uuid, int $revision,
            string $comment = '', string $expected = ''): void {
        company_context_service::context($companyuuid, $actor);
        self::authorise_manage($companyuuid, $actor);
        self::mutate($companyuuid, $actor, $uuid, $revision, $expected,
            static function() use ($companyuuid, $actor, $uuid, $revision, $comment) {
                self::approve_locked($companyuuid, $actor, $uuid, $revision, $comment);
            });
    }

    private static function approve_locked(string $companyuuid, int $actor, string $uuid, int $revision,
            string $comment = ''): void {
        global $DB;
        self::authorise_manage($companyuuid, $actor);
        $doc = self::latest($companyuuid, $uuid);
        if ((int)$doc->revision !== $revision || $doc->status !== 'agreed') { self::fail('state'); }
        self::assert_complete_payload($doc);
        if (\core_text::strlen($comment) > 4000) { self::fail('invalid'); }
        $old = clone $doc;
        $DB->insert_record('local_kbacompany_docreview', (object)[
            'companyuuid' => $companyuuid, 'documentuuid' => $uuid, 'docrevision' => $revision,
            'reviewround' => $doc->reviewround, 'stage' => 'approval', 'reviewerid' => $actor,
            'decision' => 'approved', 'comment' => trim($comment), 'timecreated' => time(), 'timedecided' => time(),
        ]);
        $doc->status = 'approved';
        $doc->modifiedby = $actor;
        $doc->timemodified = time();
        $DB->update_record('local_kbacompany_doc', $doc);
        self::log($companyuuid, $uuid, $actor, 'approve', $old, $doc);
    }

    public static function publish(string $companyuuid, int $actor, string $uuid, int $revision,
            int $effectivefrom = 0, string $expected = ''): void {
        company_context_service::context($companyuuid, $actor);
        self::authorise_manage($companyuuid, $actor);
        self::mutate($companyuuid, $actor, $uuid, $revision, $expected,
            static function() use ($companyuuid, $actor, $uuid, $revision, $effectivefrom) {
                self::publish_locked($companyuuid, $actor, $uuid, $revision, $effectivefrom);
            });
    }

    private static function publish_locked(string $companyuuid, int $actor, string $uuid, int $revision,
            int $effectivefrom = 0): void {
        global $DB;
        self::authorise_manage($companyuuid, $actor);
        $doc = self::latest($companyuuid, $uuid);
        if ((int)$doc->revision !== $revision || $doc->status !== 'approved') { self::fail('state'); }
        self::assert_complete_payload($doc);
        $old = clone $doc;
        $doc->status = 'published';
        $doc->effectivefrom = max(0, $effectivefrom);
        $doc->modifiedby = $actor;
        $doc->timemodified = time();
        $DB->update_record('local_kbacompany_doc', $doc);
        self::log($companyuuid, $uuid, $actor, 'publish', $old, $doc);
    }

    /** Make the published revision current and supersede any previous effective revision. */
    public static function activate(string $companyuuid, int $actor, string $uuid, int $revision, string $expected = ''): void {
        global $DB;
        self::authorise_manage($companyuuid, $actor);
        $lock = \core\lock\lock_config::get_lock_factory('local_kbacompany')
            ->get_lock('document:' . $companyuuid . ':' . $uuid, 10);
        if (!$lock) { self::fail('lock'); }
        try {
            $tx = $DB->start_delegated_transaction();
            $doc = self::latest($companyuuid, $uuid);
            self::check_token($doc, $expected);
            if ((int)$doc->revision !== $revision || $doc->status !== 'published') { self::fail('state'); }
            if ((int)$doc->effectivefrom > time()) { self::fail('notyet'); }
            self::assert_complete_payload($doc);
            $old = clone $doc;
            $previous = self::effective($companyuuid, $uuid);
            $now = time();
            if ($previous && (int)$previous->revision !== $revision) {
                $previousold = clone $previous;
                $previous->status = 'superseded';
                $previous->effectiveto = $now;
                $previous->modifiedby = $actor;
                $previous->timemodified = $now;
                $DB->update_record('local_kbacompany_doc', $previous);
                self::log($companyuuid, $uuid, $actor, 'supersede', $previousold, $previous);
            }
            $doc->status = 'effective';
            if ((int)$doc->effectivefrom === 0) { $doc->effectivefrom = $now; }
            $doc->effectiveto = 0;
            $doc->modifiedby = $actor;
            $doc->timemodified = $now;
            $DB->update_record('local_kbacompany_doc', $doc);
            self::log($companyuuid, $uuid, $actor, 'activate', $old, $doc);
            $tx->allow_commit();
        } catch (\Throwable $e) {
            if (isset($tx)) { $tx->rollback($e); }
            throw $e;
        } finally {
            $lock->release();
        }
    }

    public static function cancel(string $companyuuid, int $actor, string $uuid, int $revision,
            string $reason, string $expected = ''): void {
        company_context_service::context($companyuuid, $actor);
        self::authorise_manage($companyuuid, $actor);
        self::mutate($companyuuid, $actor, $uuid, $revision, $expected,
            static function() use ($companyuuid, $actor, $uuid, $revision, $reason) {
                self::cancel_locked($companyuuid, $actor, $uuid, $revision, $reason);
            });
    }

    private static function cancel_locked(string $companyuuid, int $actor, string $uuid, int $revision,
            string $reason): void {
        global $DB;
        self::authorise_manage($companyuuid, $actor);
        $doc = self::latest($companyuuid, $uuid);
        if ((int)$doc->revision !== $revision || in_array($doc->status, ['effective', 'superseded', 'archived', 'cancelled'], true)) {
            self::fail('state');
        }
        $reason = trim($reason);
        if ($reason === '') { self::fail('reasonrequired'); }
        $old = clone $doc;
        $doc->status = 'cancelled';
        $doc->changereason = $reason;
        $doc->modifiedby = $actor;
        $doc->timemodified = time();
        $DB->update_record('local_kbacompany_doc', $doc);
        self::log($companyuuid, $uuid, $actor, 'cancel', $old, $doc);
    }

    public static function archive(string $companyuuid, int $actor, string $uuid, int $revision, string $expected = ''): void {
        company_context_service::context($companyuuid, $actor);
        self::authorise_manage($companyuuid, $actor);
        self::mutate($companyuuid, $actor, $uuid, $revision, $expected,
            static function() use ($companyuuid, $actor, $uuid, $revision) {
                self::archive_locked($companyuuid, $actor, $uuid, $revision);
            });
    }

    private static function archive_locked(string $companyuuid, int $actor, string $uuid, int $revision): void {
        global $DB;
        self::authorise_manage($companyuuid, $actor);
        $doc = self::latest($companyuuid, $uuid);
        if ((int)$doc->revision !== $revision || !in_array($doc->status, ['superseded', 'cancelled'], true)) {
            self::fail('state');
        }
        $old = clone $doc;
        $doc->status = 'archived';
        $doc->modifiedby = $actor;
        $doc->timemodified = time();
        $DB->update_record('local_kbacompany_doc', $doc);
        self::log($companyuuid, $uuid, $actor, 'archive', $old, $doc);
    }

    public static function require_acknowledgement(string $companyuuid, int $actor, string $uuid, int $revision,
            int $userid, string $expected = ''): void {
        company_context_service::context($companyuuid, $actor);
        self::authorise_manage($companyuuid, $actor);
        self::mutate($companyuuid, $actor, $uuid, $revision, $expected,
            static function() use ($companyuuid, $actor, $uuid, $revision, $userid) {
                self::require_acknowledgement_locked($companyuuid, $actor, $uuid, $revision, $userid);
            });
    }

    private static function require_acknowledgement_locked(string $companyuuid, int $actor, string $uuid, int $revision,
            int $userid): void {
        global $DB;
        self::authorise_manage($companyuuid, $actor);
        if (!self::company_member($companyuuid, $userid)) { self::fail('invalidreviewer'); }
        $doc = self::revision($companyuuid, $uuid, $revision);
        if ($doc->status !== 'effective') { self::fail('state'); }
        if ($DB->record_exists('local_kbacompany_docack', [
                'companyuuid' => $companyuuid, 'documentuuid' => $uuid, 'docrevision' => $revision, 'userid' => $userid])) {
            return;
        }
        $DB->insert_record('local_kbacompany_docack', (object)[
            'companyuuid' => $companyuuid, 'documentuuid' => $uuid, 'docrevision' => $revision,
            'userid' => $userid, 'status' => 'required', 'requiredby' => $actor,
            'timecreated' => time(), 'timeacknowledged' => 0,
        ]);
        self::log($companyuuid, $uuid, $actor, 'ack_require', null,
            ['revision' => $revision, 'userid' => $userid]);
    }

    public static function acknowledge(string $companyuuid, int $userid, string $uuid, int $revision, string $expected = ''): void {
        company_context_service::context($companyuuid, $userid);
        self::mutate($companyuuid, $userid, $uuid, $revision, $expected,
            static function() use ($companyuuid, $userid, $uuid, $revision) {
                self::acknowledge_locked($companyuuid, $userid, $uuid, $revision);
            });
    }

    private static function acknowledge_locked(string $companyuuid, int $userid, string $uuid, int $revision): void {
        global $DB;
        require_capability('local/kbacompany:viewowncompany', \context_system::instance(), $userid);
        company_context_service::context($companyuuid, $userid);
        $doc = self::revision($companyuuid, $uuid, $revision);
        if ($doc->status !== 'effective') { self::fail('state'); }
        $ack = $DB->get_record('local_kbacompany_docack', [
            'companyuuid' => $companyuuid, 'documentuuid' => $uuid, 'docrevision' => $revision, 'userid' => $userid,
        ]);
        if (!$ack) { self::fail('acknotrequired'); }
        if ($ack->status === 'acknowledged') { return; }
        $ack->status = 'acknowledged';
        $ack->timeacknowledged = time();
        $DB->update_record('local_kbacompany_docack', $ack);
        self::log($companyuuid, $uuid, $userid, 'acknowledge', null,
            ['revision' => $revision, 'userid' => $userid]);
    }

    /** Create immutable evidence from an effective controlled Form. There is intentionally no update method. */
    public static function create_record(string $companyuuid, int $userid, string $formuuid, int $formrevision,
            string $title, string $payload, string $subjecttype = '', string $subjectuuid = '', string $expected = ''): string {
        company_context_service::context($companyuuid, $userid);
        return self::mutate($companyuuid, $userid, $formuuid, $formrevision, $expected,
            static function() use ($companyuuid, $userid, $formuuid, $formrevision, $title, $payload, $subjecttype, $subjectuuid) {
                return self::create_record_locked($companyuuid, $userid, $formuuid, $formrevision, $title, $payload, $subjecttype, $subjectuuid);
            });
    }

    private static function create_record_locked(string $companyuuid, int $userid, string $formuuid, int $formrevision,
            string $title, string $payload, string $subjecttype = '', string $subjectuuid = ''): string {
        global $DB;
        require_capability('local/kbacompany:viewowncompany', \context_system::instance(), $userid);
        company_context_service::context($companyuuid, $userid);
        $form = self::revision($companyuuid, $formuuid, $formrevision);
        if ($form->status !== 'effective' || $form->doctype !== 'form') { self::fail('invalidform'); }
        $title = trim($title);
        $payload = trim($payload);
        if ($title === '' || \core_text::strlen($title) > 255 || $payload === '' || \core_text::strlen($payload) > 100000) {
            self::fail('invalidrecord');
        }
        if (\core_text::strlen($subjecttype) > 24 || \core_text::strlen($subjectuuid) > 36) { self::fail('invalidrecord'); }
        $uuid = self::uuid();
        $code = 'REC-' . gmdate('Ymd-His') . '-' . strtoupper(substr(str_replace('-', '', $uuid), 0, 6));
        $row = (object)[
            'companyuuid' => $companyuuid, 'uuid' => $uuid, 'code' => $code, 'title' => $title,
            'recordtype' => 'form_record', 'sourceformuuid' => $formuuid, 'sourceformrevision' => $formrevision,
            'subjecttype' => $subjecttype, 'subjectuuid' => $subjectuuid,
            'payloadhash' => hash('sha256', $payload), 'payload' => $payload,
            'createdby' => $userid, 'timecreated' => time(),
        ];
        $DB->insert_record('local_kbacompany_record', $row);
        self::log($companyuuid, $uuid, $userid, 'record_create', null,
            ['code' => $code, 'sourceformuuid' => $formuuid, 'sourceformrevision' => $formrevision,
                'payloadhash' => $row->payloadhash]);
        return $uuid;
    }

    private static function dto(\stdClass $row, int $actor = 0): array {
        $sourceoutdated = false;
        if ($row->sourcekind === 'stp') {
            try {
                $snapshot = self::stp_snapshot($row->companyuuid, $actor > 0 ? $actor : ((int)$row->modifiedby ?: (int)$row->createdby), $row->sourceuuid);
                $sourceoutdated = $snapshot['hash'] !== $row->sourcehash;
            } catch (\Throwable $e) {
                $sourceoutdated = false;
            }
        }
        return [
            'uuid' => $row->uuid, 'revision' => (int)$row->revision, 'status' => $row->status,
            'review_round' => (int)$row->reviewround, 'code' => $row->code, 'title' => $row->title,
            'type' => $row->doctype, 'level' => (int)$row->doclevel,
            'owner_position_uuid' => $row->ownerpositionuuid, 'source_kind' => $row->sourcekind,
            'source_uuid' => $row->sourceuuid, 'source_revision' => (int)$row->sourcerevision,
            'source_hash' => $row->sourcehash, 'source_outdated' => $sourceoutdated,
            'content_format' => $row->contentformat, 'content' => $row->content,
            'change_reason' => $row->changereason, 'supersedes_revision' => (int)$row->supersedesrevision,
            'effective_from' => (int)$row->effectivefrom, 'effective_to' => (int)$row->effectiveto,
            'created_by' => (int)$row->createdby, 'modified_by' => (int)$row->modifiedby,
            'time_created' => (int)$row->timecreated, 'time_modified' => (int)$row->timemodified,
        ];
    }

    /** Automatically generated master list. Managers see working+effective; users see only effective documents. */
    public static function master_list(string $companyuuid, int $userid): array {
        global $DB;
        company_context_service::context($companyuuid, $userid);
        $manager = self::can_manage($companyuuid, $userid);
        $where = "d.companyuuid = :c AND " . ($manager ?
            "(d.status = 'effective' OR NOT EXISTS (SELECT newer.id FROM {local_kbacompany_doc} newer
              WHERE newer.companyuuid = d.companyuuid AND newer.uuid = d.uuid AND newer.revision > d.revision))
              AND (d.status <> 'archived' OR EXISTS (SELECT currentdoc.id FROM {local_kbacompany_doc} currentdoc
              WHERE currentdoc.companyuuid = d.companyuuid AND currentdoc.uuid = d.uuid AND currentdoc.status = 'effective'))" :
            "d.status = 'effective'");
        $rows = $DB->get_records_sql("SELECT d.* FROM {local_kbacompany_doc} d WHERE $where ORDER BY d.code,d.revision", ['c'=>$companyuuid]);
        $out = [];
        foreach ($rows as $row) {
            if (!isset($out[$row->uuid])) { $out[$row->uuid] = ['uuid'=>$row->uuid,'effective'=>null,'working'=>null]; }
            if ($row->status === 'effective') { $out[$row->uuid]['effective'] = self::dto($row, $userid); }
            if ($manager) { $out[$row->uuid]['working'] = self::dto($row, $userid); }
        }
        return array_values($out);
    }

    private static function selected_for_user(string $companyuuid, int $userid, string $uuid): \stdClass {
        return self::readable_record($companyuuid, $userid, $uuid);
    }

    public static function context(string $companyuuid, int $userid, string $documentuuid = ''): array {
        global $DB;
        company_context_service::context($companyuuid, $userid);
        $result = [
            'company' => company_context_service::context($companyuuid, $userid),
            'can_manage' => self::can_manage($companyuuid, $userid),
            'master_list' => self::master_list($companyuuid, $userid),
            'types' => self::TYPE_LEVELS,
            'states' => self::STATES,
            'members' => self::can_manage($companyuuid, $userid) ? self::company_members($companyuuid, $userid) : [],
            'positions' => self::can_manage($companyuuid, $userid) ? position_service::positions_context($companyuuid, $userid)['positions'] : [],
            'selected' => null, 'revisions' => [], 'reviews' => [], 'acknowledgements' => [], 'records' => [],
            'can_acknowledge' => false,
        ];
        if ($documentuuid === '') { return $result; }
        $selected = self::selected_for_user($companyuuid, $userid, $documentuuid);
        $result['selected'] = self::dto($selected, $userid);
        $canmanage = $result['can_manage'];
        $rows = $DB->get_records_select('local_kbacompany_doc',
            "companyuuid = :c AND uuid = :d" . ($canmanage ? '' : " AND status IN ('effective','superseded')"),
            ['c'=>$companyuuid,'d'=>$documentuuid], 'revision DESC');
        foreach ($rows as $row) {
            $result['revisions'][] = self::dto($row, $userid);
        }
        if ($canmanage || $selected->status === 'review') {
            $reviews = $DB->get_records('local_kbacompany_docreview', [
                'companyuuid' => $companyuuid, 'documentuuid' => $documentuuid,
                'docrevision' => $selected->revision,
            ], 'reviewround ASC, stage ASC, id ASC');
            foreach ($reviews as $review) {
                $user = \core_user::get_user((int)$review->reviewerid, '*', MUST_EXIST);
                $result['reviews'][] = [
                    'id' => (int)$review->id, 'round' => (int)$review->reviewround,
                    'stage' => $review->stage, 'reviewer_id' => (int)$review->reviewerid,
                    'reviewer_name' => fullname($user), 'decision' => $review->decision,
                    'comment' => $review->comment, 'time_decided' => (int)$review->timedecided,
                ];
            }
        }
        if ($selected->status === 'effective') {
            $acks = $DB->get_records('local_kbacompany_docack', [
                'companyuuid' => $companyuuid, 'documentuuid' => $documentuuid,
                'docrevision' => $selected->revision,
            ] + ($canmanage ? [] : ['userid'=>$userid]), 'id ASC');
            foreach ($acks as $ack) {
                $user = \core_user::get_user((int)$ack->userid, '*', MUST_EXIST);
                $result['acknowledgements'][] = [
                    'userid' => (int)$ack->userid, 'name' => fullname($user), 'status' => $ack->status,
                    'required_by' => (int)$ack->requiredby, 'time_acknowledged' => (int)$ack->timeacknowledged,
                ];
                if ((int)$ack->userid === $userid && $ack->status === 'required') {
                    $result['can_acknowledge'] = true;
                }
            }
        }
        $records = $DB->get_records('local_kbacompany_record', [
            'companyuuid' => $companyuuid, 'sourceformuuid' => $documentuuid,
        ] + ($canmanage ? [] : ['createdby'=>$userid]), 'timecreated DESC');
        foreach ($records as $record) {
            $result['records'][] = [
                'uuid' => $record->uuid, 'code' => $record->code, 'title' => $record->title,
                'source_revision' => (int)$record->sourceformrevision, 'payload_hash' => $record->payloadhash,
                'payload' => $record->payload, 'created_by' => (int)$record->createdby,
                'time_created' => (int)$record->timecreated,
            ];
        }
        return $result;
    }

    public static function semantic_document(string $companyuuid, int $userid, string $uuid): array {
        company_context_service::context($companyuuid, $userid);
        $row = self::effective($companyuuid, $uuid);
        if (!$row) { self::fail('notavailable'); }
        return ['uuid' => $row->uuid, 'code' => $row->code, 'name' => $row->title,
            'status' => $row->status, 'revision' => (int)$row->revision];
    }

    public static function semantic_documents(string $companyuuid, int $userid): array {
        $docs = [];
        foreach (self::master_list($companyuuid, $userid) as $entry) {
            if (!$entry['effective']) { continue; }
            $row = $entry['effective'];
            $docs[] = ['uuid' => $row['uuid'], 'code' => $row['code'], 'name' => $row['title'],
                'status' => $row['status'], 'revision' => $row['revision']];
        }
        return $docs;
    }

    /** Re-check availability before releasing a revision; missing content is never an empty fact. */
    private static function assert_complete_payload(\stdClass $doc): void {
        if (trim((string)$doc->content) === '' && empty($doc->filemanifest)) { self::fail('contentrequired'); }
        if (!empty($doc->filemanifest) && !self::stored_revision_file($doc)) { self::fail('filemissing'); }
    }

    /** Store an immutable blob inside the caller's document lock and delegated DB transaction. */
    private static function store_uploaded_file(\stdClass $row, int $actor, array $upload): void {
        $context = \context_system::instance();
        $fs = get_file_storage();
        $path = '/' . $row->companyuuid . '/' . $upload['sha256'] . '/';
        $file = $fs->get_file($context->id, 'local_kbacompany', 'document_revision', (int)$row->id, $path, $upload['filename']);
        if (!$file) {
            $file = $fs->create_file_from_pathname([
                'contextid'=>$context->id, 'component'=>'local_kbacompany', 'filearea'=>'document_revision',
                'itemid'=>(int)$row->id, 'filepath'=>$path, 'filename'=>$upload['filename'],
                'mimetype'=>$upload['mimetype'], 'userid'=>$actor, 'source'=>$upload['filename'],
            ], $upload['tmp']);
        }
        if ($file->is_directory() || (int)$file->get_filesize() !== $upload['bytes'] ||
                !hash_equals($file->get_contenthash(), hash_file('sha1', $upload['tmp']))) { self::fail('filemissing'); }
        // Old draft blobs are not overwritten. Only this manifest is downloadable by ordinary users.
        $row->filemanifest = json_encode(['version'=>1, 'filename'=>$upload['filename'], 'sha256'=>$upload['sha256'],
            'bytes'=>$upload['bytes'], 'mimetype'=>$upload['mimetype'], 'uploadedat'=>(int)$file->get_timecreated()],
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    private static function stored_revision_file(\stdClass $row) {
        $m = document_upload::manifest($row->filemanifest ?? null);
        if (!$m) { return false; }
        $file = get_file_storage()->get_file(\context_system::instance()->id, 'local_kbacompany', 'document_revision',
            (int)$row->id, '/' . $row->companyuuid . '/' . $m['sha256'] . '/', $m['filename']);
        return $file && !$file->is_directory() && (int)$file->get_filesize() === $m['bytes'] ? $file : false;
    }

    /** New revision is a new File API item; the previous revision's file is never moved or edited. */
    private static function copy_revision_file(\stdClass $old, \stdClass $row): void {
        if ($old->companyuuid !== $row->companyuuid || $old->uuid !== $row->uuid) { self::fail('reference'); }
        $file = self::stored_revision_file($old);
        if (!$file) { self::fail('filemissing'); }
        get_file_storage()->create_file_from_storedfile(['itemid'=>(int)$row->id], $file);
    }

    /** Caller has already authorized this exact company/revision before retrieving file metadata. */
    private static function file_metadata(\stdClass $row): ?array {
        $m = document_upload::manifest($row->filemanifest ?? null);
        if (!$m) { return null; }
        $m['available'] = (bool)self::stored_revision_file($row);
        $m['url'] = $m['available'] ? \moodle_url::make_pluginfile_url(\context_system::instance()->id,
            'local_kbacompany', 'document_revision', (int)$row->id,
            '/' . $row->companyuuid . '/' . $m['sha256'] . '/', $m['filename'], true)->out(false) : '';
        return $m;
    }

    /** Download authorization is company scoped BEFORE obtaining either revision content or File API data. */
    public static function file_for_download(string $companyuuid, int $actor, int $id, string $hash, string $filename) {
        global $DB;
        company_context_service::context($companyuuid, $actor);
        $ref = $DB->get_record('local_kbacompany_doc', ['companyuuid'=>$companyuuid,'id'=>$id], 'id,uuid,revision');
        if (!$ref) { self::fail('notavailable'); }
        $row = self::readable_record($companyuuid, $actor, $ref->uuid, (int)$ref->revision);
        $m = document_upload::manifest($row->filemanifest ?? null);
        if (!$m || !hash_equals($m['sha256'], $hash) || $m['filename'] !== $filename) { self::fail('notavailable'); }
        $file = self::stored_revision_file($row);
        if (!$file) { self::fail('filemissing'); }
        return $file;
    }

    /** Opaque edit stamp; no timestamp precision assumptions and no additional storage. */
    public static function edit_token(\stdClass $row): string {
        $data = (array)$row;
        ksort($data);
        return hash('sha256', json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }

    private static function check_token(\stdClass $row, string $expected): void {
        if ($expected !== '' && !hash_equals(self::edit_token($row), $expected)) { self::fail('conflict'); }
    }

    /** Shared lock for review, release, acknowledgements and record evidence. */
    private static function mutate(string $companyuuid, int $actor, string $uuid, int $revision,
            string $expected, callable $callback) {
        $lock = \core\lock\lock_config::get_lock_factory('local_kbacompany')
            ->get_lock('document:' . $companyuuid . ':' . $uuid, 10);
        if (!$lock) { self::fail('lock'); }
        global $DB;
        try {
            $tx = $DB->start_delegated_transaction();
            $row = self::readable_record($companyuuid, $actor, $uuid, $revision);
            self::check_token($row, $expected);
            $result = $callback();
            $tx->allow_commit();
            return $result;
        } catch (\Throwable $e) {
            if (isset($tx)) { $tx->rollback($e); }
            throw $e;
        } finally {
            $lock->release();
        }
    }

    private static function allocate_code(string $companyuuid): string {
        global $DB;
        $minimum = 0;
        $codes = $DB->get_records_select('local_kbacompany_doc',
            'companyuuid = :c AND ' . $DB->sql_like('code', ':prefix', false),
            ['c'=>$companyuuid,'prefix'=>'DOC-%'], '', 'id,code');
        foreach ($codes as $row) {
            if (preg_match('/^DOC-([0-9]+)$/i', $row->code, $match)) { $minimum = max($minimum, (int)$match[1]); }
        }
        return stable_code_service::next($companyuuid, 'document', 'DOC',
            static function(string $candidate) use ($companyuuid): bool {
                global $DB;
                return !$DB->record_exists('local_kbacompany_doc', ['companyuuid'=>$companyuuid,'code'=>$candidate]);
            }, $minimum);
    }

    /** Read authorization is expressed in SQL, before any content is obtained. */
    private static function readable_record(string $companyuuid, int $actor, string $uuid, int $revision = 0): \stdClass {
        global $DB;
        company_context_service::context($companyuuid, $actor);
        $params = ['c'=>$companyuuid,'d'=>$uuid];
        $where = 'd.companyuuid = :c AND d.uuid = :d';
        if ($revision > 0) { $where .= ' AND d.revision = :revision'; $params['revision'] = $revision; }
        if (!self::can_manage($companyuuid, $actor)) {
            $visibility = "d.status = 'effective' OR (d.status = 'review' AND EXISTS (
                SELECT r.id FROM {local_kbacompany_docreview} r WHERE r.companyuuid = d.companyuuid
                AND r.documentuuid = d.uuid AND r.docrevision = d.revision AND r.reviewround = d.reviewround
                AND r.stage = 'review' AND r.reviewerid = :actor))";
            $params['actor'] = $actor;
            if ($revision > 0) {
                $visibility .= " OR (d.status = 'superseded' AND EXISTS (
                    SELECT e.id FROM {local_kbacompany_doc} e WHERE e.companyuuid = d.companyuuid
                    AND e.uuid = d.uuid AND e.status = 'effective'))";
            }
            $where .= ' AND (' . $visibility . ')';
            $sort = "CASE WHEN d.status = 'review' THEN 0 ELSE 1 END, d.revision DESC";
        } else { $sort = 'd.revision DESC'; }
        $rows = $DB->get_records_sql("SELECT d.* FROM {local_kbacompany_doc} d WHERE $where ORDER BY $sort", $params, 0, 1);
        if (!$rows) { self::fail('notavailable'); }
        return reset($rows);
    }

    /** One row per accessible document; metadata only, no full document bodies on ListView. */
    public static function browse(string $companyuuid, int $actor, array $filters = [], int $page = 0): array {
        global $DB;
        company_context_service::context($companyuuid, $actor);
        $manager = self::can_manage($companyuuid, $actor);
        $params = ['c'=>$companyuuid];
        $where = 'd.companyuuid = :c';
        if ($manager) {
            $where .= " AND NOT EXISTS (SELECT n.id FROM {local_kbacompany_doc} n
                WHERE n.companyuuid = d.companyuuid AND n.uuid = d.uuid AND n.revision > d.revision)";
        } else {
            // The list includes an assigned review, or otherwise the current effective revision.
            $params['reviewer'] = $actor;
            $params['otherreviewer'] = $actor;
            $where .= " AND ((d.status = 'review' AND EXISTS (
                SELECT r.id FROM {local_kbacompany_docreview} r WHERE r.companyuuid = d.companyuuid
                AND r.documentuuid = d.uuid AND r.docrevision = d.revision AND r.reviewround = d.reviewround
                AND r.stage = 'review' AND r.reviewerid = :reviewer)) OR (d.status = 'effective' AND NOT EXISTS (
                SELECT pending.id FROM {local_kbacompany_doc} pending
                JOIN {local_kbacompany_docreview} pr ON pr.companyuuid = pending.companyuuid
                    AND pr.documentuuid = pending.uuid AND pr.docrevision = pending.revision AND pr.reviewround = pending.reviewround
                WHERE pending.companyuuid = d.companyuuid AND pending.uuid = d.uuid AND pending.status = 'review'
                    AND pr.stage = 'review' AND pr.reviewerid = :otherreviewer)))";
        }
        $status = (string)($filters['status'] ?? '');
        if ($status !== '' && !in_array($status, self::STATES, true)) { self::fail('invalid'); }
        if ($status !== '') { $where .= ' AND d.status = :status'; $params['status'] = $status; }
        else { $where .= " AND (d.status <> 'archived' OR EXISTS (SELECT currentdoc.id FROM {local_kbacompany_doc} currentdoc
            WHERE currentdoc.companyuuid = d.companyuuid AND currentdoc.uuid = d.uuid AND currentdoc.status = 'effective'))"; }
        $type = (string)($filters['doctype'] ?? '');
        if ($type !== '' && !isset(self::TYPE_LEVELS[$type])) { self::fail('invalid'); }
        if ($type !== '') { $where .= ' AND d.doctype = :type'; $params['type'] = $type; }
        $owner = trim((string)($filters['owner'] ?? ''));
        if ($owner !== '') { $where .= ' AND d.ownerpositionuuid = :owner'; $params['owner'] = $owner; }
        $query = trim((string)($filters['q'] ?? ''));
        if (\core_text::strlen($query) > 200) { self::fail('invalid'); }
        if ($query !== '') {
            $where .= ' AND (' . $DB->sql_like('d.code', ':qcode', false) . ' OR ' . $DB->sql_like('d.title', ':qtitle', false) . ')';
            $params['qcode'] = '%' . $DB->sql_like_escape($query) . '%'; $params['qtitle'] = $params['qcode'];
        }
        $total = $DB->count_records_sql("SELECT COUNT(1) FROM {local_kbacompany_doc} d WHERE $where", $params);
        $size = 25; $page = max(0, min($page, max(0, (int)ceil($total / $size) - 1)));
        $rows = $DB->get_records_sql("SELECT d.id,d.uuid,d.code,d.title,d.doctype,d.doclevel,d.revision,d.status,
            d.ownerpositionuuid,d.timemodified,p.name AS ownername,
            (SELECT MAX(e.revision) FROM {local_kbacompany_doc} e
             WHERE e.companyuuid = d.companyuuid AND e.uuid = d.uuid AND e.status = 'effective') AS effectiverevision
            FROM {local_kbacompany_doc} d LEFT JOIN {local_kbacompany_position} p
              ON p.companyuuid = d.companyuuid AND p.uuid = d.ownerpositionuuid
            WHERE $where ORDER BY d.timemodified DESC,d.code,d.id", $params, $page * $size, $size);
        return ['rows'=>array_values($rows),'total'=>$total,'page'=>$page,'pagesize'=>$size,'can_manage'=>$manager];
    }

    /** Company-scoped reference pickers; no projections of other people's business history. */
    public static function form_options(string $companyuuid, int $actor, bool $people = false): array {
        global $DB;
        company_context_service::context($companyuuid, $actor);
        $positions = $DB->get_records('local_kbacompany_position', ['companyuuid'=>$companyuuid], 'name ASC,id ASC', 'id,uuid,code,name,active');
        $members = $people && self::can_manage($companyuuid, $actor) ? self::company_members($companyuuid, $actor) : [];
        return ['positions'=>array_values($positions),'members'=>$members];
    }

    /** Canonical card for one exact accessible revision. */
    public static function detail(string $companyuuid, int $actor, string $uuid, int $revision = 0): array {
        global $DB;
        $row = self::readable_record($companyuuid, $actor, $uuid, $revision);
        $manager = self::can_manage($companyuuid, $actor);
        $latest = !$DB->record_exists_select('local_kbacompany_doc',
            'companyuuid = :c AND uuid = :u AND revision > :r', ['c'=>$companyuuid,'u'=>$uuid,'r'=>$row->revision]);
        $position = $row->ownerpositionuuid !== '' ? $DB->get_record('local_kbacompany_position',
            ['companyuuid'=>$companyuuid,'uuid'=>$row->ownerpositionuuid], 'id,name,active') : false;
        $pending = $row->status === 'review' && $DB->record_exists('local_kbacompany_docreview', [
            'companyuuid'=>$companyuuid,'documentuuid'=>$uuid,'docrevision'=>$row->revision,'reviewround'=>$row->reviewround,
            'stage'=>'review','reviewerid'=>$actor,'decision'=>'pending']);
        $ack = $row->status === 'effective' && $DB->record_exists('local_kbacompany_docack', [
            'companyuuid'=>$companyuuid,'documentuuid'=>$uuid,'docrevision'=>$row->revision,'userid'=>$actor,'status'=>'required']);
        $actions = [];
        if ($manager && $latest) {
            if ($row->status === 'draft') {
                $actions[] = 'addreviewer';
                if ((trim((string)$row->content) !== '' || !empty($row->filemanifest)) && $DB->record_exists('local_kbacompany_docreview', [
                    'companyuuid'=>$companyuuid,'documentuuid'=>$uuid,'docrevision'=>$row->revision,
                    'reviewround'=>$row->reviewround,'stage'=>'review'])) { $actions[] = 'submitreview'; }
            }
            if (in_array($row->status, ['review','agreed'], true)) { $actions[] = 'returndraft'; }
            if ($row->status === 'agreed') { $actions[] = 'approve'; }
            if ($row->status === 'approved') { $actions[] = 'publish'; }
            if ($row->status === 'published' && (int)$row->effectivefrom <= time()) { $actions[] = 'activate'; }
            if (in_array($row->status, ['published','effective','superseded','cancelled'], true)) { $actions[] = 'newrevision'; }
            if (in_array($row->status, ['draft','review','agreed','approved','published'], true)) { $actions[] = 'cancel'; }
            if (in_array($row->status, ['cancelled','superseded'], true)) { $actions[] = 'archive'; }
        }
        if ($pending) { $actions[] = 'reviewapprove'; $actions[] = 'reviewreject'; }
        if ($manager && $row->status === 'effective') { $actions[] = 'requireack'; }
        if ($ack) { $actions[] = 'acknowledge'; }
        if ($row->status === 'effective' && $row->doctype === 'form') { $actions[] = 'createrecord'; }
        $effective = self::effective($companyuuid, $uuid);
        return ['selected'=>self::dto($row, $actor), 'can_manage'=>$manager,'is_latest'=>$latest,
            'can_edit'=>$manager && $latest && $row->status === 'draft', 'actions'=>$actions,
            'edit_token'=>self::edit_token($row),'owner_name'=>$position ? $position->name : '',
            'effective_revision'=>$effective ? (int)$effective->revision : 0,
            'file'=>self::file_metadata($row)];
    }

    /** Bounded subpanels, including historical evidence; no PHP access filtering. */
    public static function panel(string $companyuuid, int $actor, string $uuid, int $revision,
            string $panel, int $page = 0): array {
        global $DB;
        $doc = self::readable_record($companyuuid, $actor, $uuid, $revision);
        $manager = self::can_manage($companyuuid, $actor);
        $params = ['c'=>$companyuuid,'d'=>$uuid];
        $where = 'r.companyuuid = :c'; $join = '';
        if ($panel === 'revisions') {
            $table = 'local_kbacompany_doc'; $where .= ' AND r.uuid = :d';
            if (!$manager) { $where .= " AND r.status IN ('effective','superseded')"; }
            $fields = 'r.id,r.companyuuid,r.uuid,r.revision,r.status,r.title,r.changereason,r.timemodified,r.filemanifest'; $sort = 'r.revision DESC';
        } else if ($panel === 'history') {
            if (!$manager) { return ['rows'=>[],'total'=>0,'page'=>0,'pagesize'=>25]; }
            $table = 'local_kbacompany_aclog'; $where .= " AND r.objectuuid = :d AND " . $DB->sql_like('r.action', ':prefix');
            $params['prefix'] = 'doc\\_%';
            $fields = 'r.id,r.action,r.timecreated,u.firstname,u.lastname,u.firstnamephonetic,u.lastnamephonetic,u.middlename,u.alternatename';
            $join = ' LEFT JOIN {user} u ON u.id = r.actorid'; $sort = 'r.id DESC';
        } else if ($panel === 'reviews') {
            if (!$manager && $doc->status !== 'review') { return ['rows'=>[],'total'=>0,'page'=>0,'pagesize'=>25]; }
            $table = 'local_kbacompany_docreview'; $where .= ' AND r.documentuuid = :d AND r.docrevision = :rev';
            $params['rev'] = $revision;
            $fields = 'r.id,r.reviewround,r.stage,r.decision,r.comment,r.timedecided,u.firstname,u.lastname,u.firstnamephonetic,u.lastnamephonetic,u.middlename,u.alternatename';
            $join = ' LEFT JOIN {user} u ON u.id = r.reviewerid'; $sort = 'r.reviewround DESC,r.id ASC';
        } else if ($panel === 'acknowledgements') {
            $table = 'local_kbacompany_docack'; $where .= ' AND r.documentuuid = :d AND r.docrevision = :rev';
            $params['rev'] = $revision;
            if (!$manager) { $where .= ' AND r.userid = :actor'; $params['actor'] = $actor; }
            $fields = 'r.id,r.status,r.timeacknowledged,u.firstname,u.lastname,u.firstnamephonetic,u.lastnamephonetic,u.middlename,u.alternatename';
            $join = ' LEFT JOIN {user} u ON u.id = r.userid'; $sort = 'r.id ASC';
        } else if ($panel === 'records') {
            $table = 'local_kbacompany_record'; $where .= ' AND r.sourceformuuid = :d AND r.sourceformrevision = :rev';
            $params['rev'] = $revision;
            if (!$manager) { $where .= ' AND r.createdby = :actor'; $params['actor'] = $actor; }
            $fields = 'r.id,r.uuid,r.code,r.title,r.payload,r.timecreated,u.firstname,u.lastname,u.firstnamephonetic,u.lastnamephonetic,u.middlename,u.alternatename';
            $join = ' LEFT JOIN {user} u ON u.id = r.createdby'; $sort = 'r.id DESC';
        } else { self::fail('invalid'); }
        $total = $DB->count_records_sql("SELECT COUNT(1) FROM {{$table}} r WHERE $where", $params);
        $size = 25; $page = max(0, min($page, max(0, (int)ceil($total / $size) - 1)));
        $rows = $DB->get_records_sql("SELECT $fields FROM {{$table}} r $join WHERE $where ORDER BY $sort", $params, $page*$size, $size);
        if ($panel === 'revisions') {
            foreach ($rows as $row) { $row->file = self::file_metadata($row); }
        }
        return ['rows'=>array_values($rows),'total'=>$total,'page'=>$page,'pagesize'=>$size];
    }

    /** UI field validation reuses the domain's constraints; no mutation on an invalid form. */
    public static function form_errors(string $companyuuid, int $actor, array $values, bool $manual = true, string $document = '', int $revision = 0): array {
        self::authorise_manage($companyuuid, $actor);
        $errors = [];
        foreach (['title'=>255,'changereason'=>4000,'content'=>100000] as $key=>$max) {
            $value = (string)($values[$key] ?? '');
            if (($key === 'title' && trim($value) === '') || \core_text::strlen($value) > $max) {
                $errors[$key] = get_string($key === 'title' && trim($value) === '' ? 'dcu_required' : 'dcu_toolong', 'local_kbacompany', $max);
            }
        }
        try {
            $old = $document !== '' ? self::readable_record($companyuuid,$actor,$document,$revision) : null;
            $owner = (string)($values['ownerpositionuuid'] ?? '');
            if (!$old || $owner !== $old->ownerpositionuuid) { self::validate_position($companyuuid,$owner); }
        }
        catch (\moodle_exception $e) { $errors['ownerpositionuuid'] = $e->getMessage(); }
        if ($manual) {
            try { self::validate_type((string)($values['doctype'] ?? ''), (int)($values['doclevel'] ?? 0)); }
            catch (\moodle_exception $e) { $errors['doctype'] = $e->getMessage(); }
        }
        return $errors;
    }

    /** A calendar date in the actor's Moodle timezone, not a raw Unix field. */
    public static function effective_date(string $date): int {
        if ($date === '') { return 0; }
        $value = \DateTimeImmutable::createFromFormat('!Y-m-d', $date, \core_date::get_user_timezone_object());
        if (!$value || $value->format('Y-m-d') !== $date) { self::fail('invaliddate'); }
        return $value->getTimestamp();
    }
}
