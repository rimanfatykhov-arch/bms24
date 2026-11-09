<?php
namespace local_kbacorporate\local;

defined('MOODLE_INTERNAL') || die();

/** BMS tool library, implementation audits and verified BMS Index. */
class implementation_audits {
    public const STATUS_DRAFT = 'draft';
    public const STATUS_REVIEW = 'review';
    public const STATUS_CONFIRMED = 'confirmed';
    public const STATUS_CANCELLED = 'cancelled';
    public const EVIDENCE_MISSING = 'missing';
    public const EVIDENCE_SUBMITTED = 'submitted';
    public const EVIDENCE_VERIFIED = 'verified';
    public const EVIDENCE_REJECTED = 'rejected';
    public const EVIDENCE_NEEDS_DATA = 'needsdata';
    public const EVIDENCE_NOT_APPLICABLE = 'notapplicable';
    public const INDEX_WEIGHTED_VERIFIED = 'weighted_verified';
    public const INDEX_AREA_AVERAGE = 'area_average';
    public const VERSION_PUBLISHED = 'published';
    public const VERSION_ARCHIVED = 'archived';
    public const TASK_PLANNED = 'planned';
    public const TASK_IN_PROGRESS = 'inprogress';
    public const TASK_REVIEW = 'review';
    public const TASK_COMPLETED = 'completed';
    public const TASK_CANCELLED = 'cancelled';

    public static function available(): bool {
        global $DB;
        $dbman = $DB->get_manager();
        foreach (['local_kbacorp_bms_tool', 'local_kbacorp_bms_toolver', 'local_kbacorp_bms_crit',
                'local_kbacorp_bms_comp',
                'local_kbacorp_impl_audit', 'local_kbacorp_impl_item'] as $name) {
            if (!$dbman->table_exists(new \xmldb_table($name))) {
                return false;
            }
        }
        return true;
    }

    public static function get_tools(int $companyid, bool $activeonly = false): array {
        global $DB;
        $params = ['companyid' => $companyid];
        $active = $activeonly ? ' AND active = 1' : '';
        return array_values($DB->get_records_sql(
            "SELECT * FROM {local_kbacorp_bms_tool}
              WHERE companyid = :companyid{$active}
           ORDER BY name ASC", $params
        ));
    }

    /** Return tools that may be selected for a company audit: company-owned plus global templates. */
    public static function get_selectable_tools(int $companyid, bool $activeonly = true): array {
        global $DB;
        $params = ['companyid' => $companyid];
        $active = $activeonly ? ' AND active = 1' : '';
        return array_values($DB->get_records_sql(
            "SELECT * FROM {local_kbacorp_bms_tool}
              WHERE (companyid = 0 OR companyid = :companyid){$active}
           ORDER BY companyid DESC, name ASC", $params
        ));
    }

    /** Return global and company tools with usage counters for the library UI. */
    public static function get_tools_overview(int $companyid): array {
        global $DB;
        return array_values($DB->get_records_sql(
            "SELECT t.*,
                    (SELECT COUNT(1) FROM {local_kbacorp_bms_crit} c
                      WHERE c.toolid = t.id) AS criteriacount,
                    (SELECT COUNT(1) FROM {local_kbacorp_bms_crit} ac
                      WHERE ac.toolid = t.id AND ac.active = 1) AS activecriteriacount,
                    (SELECT COUNT(1) FROM {local_kbacorp_impl_audit} a
                      WHERE a.toolid = t.id AND a.companyid = :auditcompanyid) AS auditcount
               FROM {local_kbacorp_bms_tool} t
              WHERE t.companyid = 0 OR t.companyid = :scopecompanyid
           ORDER BY t.companyid DESC, t.name ASC",
            ['auditcompanyid' => $companyid, 'scopecompanyid' => $companyid]
        ));
    }

    public static function tool_has_audits(int $toolid): bool {
        global $DB;
        return $DB->record_exists('local_kbacorp_impl_audit', ['toolid' => $toolid]);
    }

    public static function save_tool(\stdClass $data): int {
        global $DB;
        $now = time();
        $scalemax = max(1, min(10, (int)($data->scalemax ?? 5)));
        $areas = ['strategy', 'finance', 'marketing', 'operations', 'people', 'management'];
        $area = in_array((string)($data->area ?? ''), $areas, true) ? (string)$data->area : 'management';
        $indexmethod = (string)($data->indexmethod ?? self::INDEX_WEIGHTED_VERIFIED);
        if (!in_array($indexmethod, [self::INDEX_WEIGHTED_VERIFIED, self::INDEX_AREA_AVERAGE], true)) {
            $indexmethod = self::INDEX_WEIGHTED_VERIFIED;
        }
        $record = (object)[
            'companyid' => (int)$data->companyid,
            'code' => strtoupper(trim($data->code)),
            'name' => trim($data->name),
            'area' => $area,
            'templatecode' => !empty($data->templatecode) ? trim($data->templatecode) : null,
            'sourceversion' => !empty($data->sourceversion) ? trim($data->sourceversion) : null,
            'scalemax' => $scalemax,
            'attentionthreshold' => min($scalemax, max(0, (int)($data->attentionthreshold ?? 3))),
            'indexmethod' => $indexmethod,
            'description' => trim((string)$data->description) ?: null,
            'active' => empty($data->active) ? 0 : 1,
            'timemodified' => $now,
        ];
        if (!empty($data->id)) {
            $existing = $DB->get_record('local_kbacorp_bms_tool', ['id' => (int)$data->id], '*', MUST_EXIST);
            if ((int)$existing->companyid !== (int)$record->companyid) {
                throw new \moodle_exception('companynotallowed', 'local_kbacorporate');
            }
            foreach (['templatecode', 'sourceversion', 'scalemax', 'attentionthreshold', 'indexmethod'] as $field) {
                if (!property_exists($data, $field)) {
                    $record->{$field} = $existing->{$field};
                }
            }
            if (self::tool_has_audits((int)$existing->id)) {
                $record->code = $existing->code;
            }
            $record->id = (int)$data->id;
            $DB->update_record('local_kbacorp_bms_tool', $record);
            self::mark_tool_draft($record->id);
            return $record->id;
        }
        $record->timecreated = $now;
        return (int)$DB->insert_record('local_kbacorp_bms_tool', $record);
    }

    public static function get_criteria(int $toolid, bool $activeonly = false): array {
        global $DB;
        $conditions = ['toolid' => $toolid];
        if ($activeonly) {
            $conditions['active'] = 1;
        }
        return array_values($DB->get_records('local_kbacorp_bms_crit', $conditions, 'sortorder, id'));
    }

    public static function save_criterion(\stdClass $data): int {
        global $DB;
        $now = time();
        $tool = $DB->get_record('local_kbacorp_bms_tool', ['id' => (int)$data->toolid], '*', MUST_EXIST);
        if (property_exists($data, 'companyid') && (int)$tool->companyid !== (int)$data->companyid) {
            throw new \moodle_exception('bmstoolreadonly', 'local_kbacorporate');
        }
        // Section "0" is a valid Point A section, not an absent classification.
        $sectioncode = trim((string)($data->sectioncode ?? ''));
        $record = (object)[
            'toolid' => (int)$data->toolid,
            'code' => strtoupper(trim($data->code)),
            'name' => trim($data->name),
            'sectioncode' => $sectioncode !== '' ? $sectioncode : null,
            'sectionname' => !empty($data->sectionname) ? trim($data->sectionname) : null,
            'minlabel' => !empty($data->minlabel) ? trim($data->minlabel) : null,
            'maxlabel' => !empty($data->maxlabel) ? trim($data->maxlabel) : null,
            'targetscore' => max(-1, (int)($data->targetscore ?? -1)),
            'description' => trim((string)$data->description) ?: null,
            'evidencehint' => trim((string)$data->evidencehint) ?: null,
            'weight' => max(1, (int)$data->weight),
            'required' => empty($data->required) ? 0 : 1,
            'sortorder' => (int)$data->sortorder,
            'active' => empty($data->active) ? 0 : 1,
            'timemodified' => $now,
        ];
        if (!empty($data->id)) {
            $existing = $DB->get_record('local_kbacorp_bms_crit', ['id' => (int)$data->id], '*', MUST_EXIST);
            foreach (['sectioncode', 'sectionname', 'minlabel', 'maxlabel', 'targetscore'] as $field) {
                if (!property_exists($data, $field)) {
                    $record->{$field} = $existing->{$field};
                }
            }
            $record->id = (int)$data->id;
            $DB->update_record('local_kbacorp_bms_crit', $record);
            self::mark_tool_draft((int)$record->toolid);
            return $record->id;
        }
        $record->timecreated = $now;
        $id = (int)$DB->insert_record('local_kbacorp_bms_crit', $record);
        self::mark_tool_draft((int)$record->toolid);
        return $id;
    }

    /** Return competency requirements attached to one BMS criterion. */
    public static function get_criterion_competencies(int $criterionid): array {
        global $DB;
        if (!$DB->get_manager()->table_exists(new \xmldb_table('local_kbacorp_bms_comp'))) {
            return [];
        }
        return array_values($DB->get_records_sql(
            "SELECT bc.*, c.shortname, c.idnumber, f.shortname AS frameworkname
               FROM {local_kbacorp_bms_comp} bc
               JOIN {competency} c ON c.id = bc.competencyid
          LEFT JOIN {competency_framework} f ON f.id = c.competencyframeworkid
              WHERE bc.criterionid = :criterionid
           ORDER BY bc.required DESC, bc.priority ASC, f.shortname ASC, c.shortname ASC",
            ['criterionid' => $criterionid]
        ));
    }

    /** Return all competency requirements for a tool, grouped by criterion id. */
    public static function get_tool_competencies(int $toolid): array {
        global $DB;
        if (!$DB->get_manager()->table_exists(new \xmldb_table('local_kbacorp_bms_comp'))) {
            return [];
        }
        $grouped = [];
        $records = $DB->get_records_sql(
            "SELECT bc.*, c.shortname, c.idnumber, f.shortname AS frameworkname
               FROM {local_kbacorp_bms_comp} bc
               JOIN {local_kbacorp_bms_crit} criterion ON criterion.id = bc.criterionid
               JOIN {competency} c ON c.id = bc.competencyid
          LEFT JOIN {competency_framework} f ON f.id = c.competencyframeworkid
              WHERE criterion.toolid = :toolid
           ORDER BY bc.criterionid, bc.required DESC, bc.priority ASC, f.shortname ASC, c.shortname ASC",
            ['toolid' => $toolid]
        );
        foreach ($records as $record) {
            $grouped[(int)$record->criterionid][] = $record;
        }
        return $grouped;
    }

    /** Add or update one competency requirement and mark the owning tool draft. */
    public static function save_criterion_competency(\stdClass $data): int {
        global $DB;
        $criterion = $DB->get_record_sql(
            "SELECT c.id, c.toolid, t.companyid
               FROM {local_kbacorp_bms_crit} c
               JOIN {local_kbacorp_bms_tool} t ON t.id = c.toolid
              WHERE c.id = :id", ['id' => (int)$data->criterionid], MUST_EXIST);
        if ((int)$criterion->companyid !== (int)$data->companyid) {
            throw new \moodle_exception('companynotallowed', 'local_kbacorporate');
        }
        $competencyid = (int)$data->competencyid;
        $competency = $DB->get_record('competency', ['id' => $competencyid], 'id,idnumber', MUST_EXIST);
        if (preg_match('/^BMS-D\d+$/i', (string)$competency->idnumber)) {
            throw new \invalid_parameter_exception(get_string('bmsdomainnodeblocked', 'local_kbacorporate'));
        }
        $priority = in_array((string)$data->priority, ['critical', 'core', 'additional'], true)
            ? (string)$data->priority : 'core';
        $now = time();
        $existing = $DB->get_record('local_kbacorp_bms_comp', [
            'criterionid' => (int)$criterion->id, 'competencyid' => $competencyid,
        ]);
        $record = (object)[
            'criterionid' => (int)$criterion->id,
            'competencyid' => $competencyid,
            'targetlevel' => max(0, min(9, (int)$data->targetlevel)),
            'priority' => $priority,
            'required' => empty($data->required) ? 0 : 1,
            'timemodified' => $now,
        ];
        if ($existing) {
            $record->id = (int)$existing->id;
            $DB->update_record('local_kbacorp_bms_comp', $record);
            $id = $record->id;
        } else {
            $record->timecreated = $now;
            $id = (int)$DB->insert_record('local_kbacorp_bms_comp', $record);
        }
        self::mark_tool_draft((int)$criterion->toolid);
        return $id;
    }

    /** Remove one competency requirement from a criterion. */
    public static function remove_criterion_competency(int $criterionid, int $mappingid, int $companyid): void {
        global $DB;
        $mapping = $DB->get_record_sql(
            "SELECT bc.id, c.toolid, t.companyid
               FROM {local_kbacorp_bms_comp} bc
               JOIN {local_kbacorp_bms_crit} c ON c.id = bc.criterionid
               JOIN {local_kbacorp_bms_tool} t ON t.id = c.toolid
              WHERE bc.id = :mappingid AND bc.criterionid = :criterionid",
            ['mappingid' => $mappingid, 'criterionid' => $criterionid], MUST_EXIST);
        if ((int)$mapping->companyid !== $companyid) {
            throw new \moodle_exception('companynotallowed', 'local_kbacorporate');
        }
        $DB->delete_records('local_kbacorp_bms_comp', ['id' => $mappingid]);
        self::mark_tool_draft((int)$mapping->toolid);
    }

    /** Decode the immutable competency requirements stored on an audit item. */
    public static function get_item_competencies(\stdClass $item): array {
        $rows = json_decode((string)($item->competenciesjson ?? ''), true);
        return is_array($rows) ? $rows : [];
    }

    /** Build the canonical working-copy snapshot used by immutable published versions. */
    public static function build_tool_snapshot(int $toolid): array {
        global $DB;
        $tool = $DB->get_record('local_kbacorp_bms_tool', ['id' => $toolid], '*', MUST_EXIST);
        $toolfields = ['code', 'name', 'area', 'templatecode', 'sourceversion', 'scalemax',
            'attentionthreshold', 'indexmethod', 'description', 'active'];
        $toolsnapshot = [];
        foreach ($toolfields as $field) {
            $toolsnapshot[$field] = $tool->{$field};
        }
        $criteria = [];
        $criterionfields = ['id', 'code', 'name', 'sectioncode', 'sectionname', 'minlabel', 'maxlabel',
            'targetscore', 'description', 'evidencehint', 'weight', 'required', 'sortorder', 'active'];
        $competenciesbycriterion = self::get_tool_competencies($toolid);
        foreach (self::get_criteria($toolid) as $criterion) {
            $row = [];
            foreach ($criterionfields as $field) {
                $row[$field] = $criterion->{$field};
            }
            $row['competencies'] = array_map(static function($mapping) {
                return [
                    'competencyid' => (int)$mapping->competencyid,
                    'shortname' => (string)$mapping->shortname,
                    'idnumber' => (string)$mapping->idnumber,
                    'frameworkname' => (string)$mapping->frameworkname,
                    'targetlevel' => (int)$mapping->targetlevel,
                    'priority' => (string)$mapping->priority,
                    'required' => (int)$mapping->required,
                ];
            }, $competenciesbycriterion[(int)$criterion->id] ?? []);
            $criteria[] = $row;
        }
        return ['schema' => 2, 'tool' => $toolsnapshot, 'criteria' => $criteria];
    }

    /** Decode a stored version snapshot into an associative array. */
    public static function get_version_snapshot(\stdClass $version): array {
        $snapshot = json_decode((string)$version->snapshotjson, true);
        if (!is_array($snapshot) || empty($snapshot['tool']) || !isset($snapshot['criteria'])) {
            throw new \moodle_exception('bmstoolversioninvalid', 'local_kbacorporate');
        }
        return $snapshot;
    }

    /** Return the immutable version history for a tool, newest first. */
    public static function get_versions(int $toolid): array {
        global $DB;
        return array_values($DB->get_records('local_kbacorp_bms_toolver', ['toolid' => $toolid],
            'versionnum DESC, id DESC'));
    }

    /** Return the currently published version, or null for a never-published tool. */
    public static function get_current_version(int $toolid): ?\stdClass {
        global $DB;
        $tool = $DB->get_record('local_kbacorp_bms_tool', ['id' => $toolid], '*', MUST_EXIST);
        if (empty($tool->currentversionid)) {
            return null;
        }
        return $DB->get_record('local_kbacorp_bms_toolver', ['id' => (int)$tool->currentversionid,
            'toolid' => $toolid]) ?: null;
    }

    /** Flag a working copy as changed without altering a published version. */
    public static function mark_tool_draft(int $toolid): void {
        global $DB;
        $tool = $DB->get_record('local_kbacorp_bms_tool', ['id' => $toolid], 'id,currentversionid,hasdraft');
        if ($tool && !empty($tool->currentversionid) && empty($tool->hasdraft)) {
            $DB->set_field('local_kbacorp_bms_tool', 'hasdraft', 1, ['id' => $toolid]);
        }
    }

    /** Ensure a legacy tool has an initial immutable published version. */
    public static function ensure_versioned(int $toolid, int $actorid): \stdClass {
        $current = self::get_current_version($toolid);
        if ($current) {
            return $current;
        }
        return self::publish_version($toolid, $actorid,
            get_string('bmstoolversioninitialnote', 'local_kbacorporate'));
    }

    /** Publish the current working copy as the next immutable version. */
    public static function publish_version(int $toolid, int $actorid, string $changenote = ''): \stdClass {
        global $DB;
        $transaction = $DB->start_delegated_transaction();
        $tool = $DB->get_record('local_kbacorp_bms_tool', ['id' => $toolid], '*', MUST_EXIST);
        $current = self::get_current_version($toolid);
        if ($current && empty($tool->hasdraft)) {
            $transaction->allow_commit();
            return $current;
        }
        $snapshot = self::build_tool_snapshot($toolid);
        $json = json_encode($snapshot, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($json === false) {
            throw new \moodle_exception('bmstoolversioninvalid', 'local_kbacorporate');
        }
        if ($current) {
            $DB->set_field('local_kbacorp_bms_toolver', 'status', self::VERSION_ARCHIVED,
                ['id' => (int)$current->id]);
        }
        $versionnum = max(1, (int)$tool->currentversion + 1);
        $now = time();
        $versionid = (int)$DB->insert_record('local_kbacorp_bms_toolver', (object)[
            'toolid' => $toolid,
            'versionnum' => $versionnum,
            'versionlabel' => 'v' . $versionnum,
            'status' => self::VERSION_PUBLISHED,
            'snapshotjson' => $json,
            'contenthash' => hash('sha256', $json),
            'changenote' => trim($changenote) ?: null,
            'publishedby' => $actorid,
            'publishedat' => $now,
            'timecreated' => $now,
        ]);
        $DB->update_record('local_kbacorp_bms_tool', (object)[
            'id' => $toolid,
            'currentversionid' => $versionid,
            'currentversion' => $versionnum,
            'hasdraft' => 0,
            'timemodified' => $now,
        ]);
        $transaction->allow_commit();
        return $DB->get_record('local_kbacorp_bms_toolver', ['id' => $versionid], '*', MUST_EXIST);
    }

    /** Compare the working copy with the current published version. */
    public static function compare_current_to_draft(int $toolid): \stdClass {
        $current = self::get_current_version($toolid);
        $working = self::build_tool_snapshot($toolid);
        $result = (object)['toolchanges' => [], 'criteriaadded' => [], 'criteriaremoved' => [],
            'criteriachanged' => []];
        if (!$current) {
            $result->criteriaadded = $working['criteria'];
            return $result;
        }
        $published = self::get_version_snapshot($current);
        foreach ($working['tool'] as $field => $value) {
            if (($published['tool'][$field] ?? null) != $value) {
                $result->toolchanges[$field] = ['before' => $published['tool'][$field] ?? null, 'after' => $value];
            }
        }
        $old = [];
        foreach ($published['criteria'] as $criterion) {
            if (!isset($criterion['competencies'])) {
                $criterion['competencies'] = [];
            }
            $old[(string)$criterion['code']] = $criterion;
        }
        $new = [];
        foreach ($working['criteria'] as $criterion) {
            $new[(string)$criterion['code']] = $criterion;
        }
        foreach ($new as $code => $criterion) {
            if (!isset($old[$code])) {
                $result->criteriaadded[] = $criterion;
            } else if ($old[$code] != $criterion) {
                $result->criteriachanged[] = ['before' => $old[$code], 'after' => $criterion];
            }
        }
        foreach ($old as $code => $criterion) {
            if (!isset($new[$code])) {
                $result->criteriaremoved[] = $criterion;
            }
        }
        return $result;
    }

    public static function create_audit(\stdClass $data, int $actorid): int {
        global $DB;
        $tool = $DB->get_record('local_kbacorp_bms_tool', ['id' => (int)$data->toolid], '*', MUST_EXIST);
        if ((int)$tool->companyid !== 0 && (int)$tool->companyid !== (int)$data->companyid) {
            throw new \moodle_exception('companynotallowed', 'local_kbacorporate');
        }
        if (empty($tool->currentversionid) && !self::get_criteria((int)$tool->id, true)) {
            throw new \moodle_exception('bmsauditnocriteria', 'local_kbacorporate');
        }
        $version = self::ensure_versioned((int)$tool->id, $actorid);
        $snapshot = self::get_version_snapshot($version);
        $criteria = array_values(array_filter($snapshot['criteria'], static function($criterion) {
            return !empty($criterion['active']);
        }));
        if (!$criteria) {
            throw new \moodle_exception('bmsauditnocriteria', 'local_kbacorporate');
        }
        $now = time();
        $transaction = $DB->start_delegated_transaction();
        $auditid = (int)$DB->insert_record('local_kbacorp_impl_audit', (object)[
            'companyid' => (int)$data->companyid, 'toolid' => (int)$tool->id,
            'toolversionid' => (int)$version->id, 'toolversionlabel' => (string)$version->versionlabel,
            'title' => trim($data->title), 'audittype' => (string)$data->audittype,
            'status' => self::STATUS_DRAFT, 'auditdate' => (int)$data->auditdate,
            'auditorid' => $actorid, 'confirmedby' => 0, 'confirmedat' => 0,
            'scalemax' => max(1, (int)$snapshot['tool']['scalemax']),
            'attentionthreshold' => max(0, (int)$snapshot['tool']['attentionthreshold']),
            'indexmethod' => (string)$snapshot['tool']['indexmethod'],
            'indexvalue' => null, 'coverage' => 0, 'conclusion' => null,
            'timecreated' => $now, 'timemodified' => $now,
        ]);
        foreach ($criteria as $criterion) {
            $DB->insert_record('local_kbacorp_impl_item', (object)[
                'auditid' => $auditid, 'criterionid' => (int)$criterion['id'],
                'criterioncode' => (string)$criterion['code'],
                'criterionname' => (string)$criterion['name'],
                'sectioncode' => $criterion['sectioncode'],
                'sectionname' => $criterion['sectionname'],
                'minlabel' => $criterion['minlabel'],
                'maxlabel' => $criterion['maxlabel'],
                'targetscore' => (int)$criterion['targetscore'],
                'evidencehint' => $criterion['evidencehint'],
                'competenciesjson' => json_encode($criterion['competencies'] ?? [],
                    JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                'weight' => max(1, (int)$criterion['weight']),
                'required' => empty($criterion['required']) ? 0 : 1, 'score' => -1,
                'evidencestatus' => self::EVIDENCE_MISSING, 'evidenceurl' => null,
                'comment' => null, 'assessorid' => 0, 'verifierid' => 0, 'verifiedat' => 0,
                'timecreated' => $now, 'timemodified' => $now,
            ]);
        }
        $transaction->allow_commit();
        return $auditid;
    }

    public static function get_audits(int $companyid): array {
        global $DB;
        return array_values($DB->get_records_sql(
            "SELECT a.*, t.name AS toolname, t.code AS toolcode,
                    u.firstname, u.lastname
               FROM {local_kbacorp_impl_audit} a
               JOIN {local_kbacorp_bms_tool} t ON t.id = a.toolid
          LEFT JOIN {user} u ON u.id = a.auditorid
              WHERE a.companyid = :companyid
           ORDER BY a.auditdate DESC, a.id DESC", ['companyid' => $companyid]
        ));
    }

    public static function get_audit(int $auditid): \stdClass {
        global $DB;
        return $DB->get_record_sql(
            "SELECT a.*, t.name AS toolname, t.code AS toolcode, c.name AS companyname
               FROM {local_kbacorp_impl_audit} a
               JOIN {local_kbacorp_bms_tool} t ON t.id = a.toolid
               JOIN {local_kbacorp_company} c ON c.id = a.companyid
              WHERE a.id = :id", ['id' => $auditid], MUST_EXIST
        );
    }

    public static function get_items(int $auditid): array {
        global $DB;
        return array_values($DB->get_records('local_kbacorp_impl_item',
            ['auditid' => $auditid], 'id ASC'));
    }

    public static function save_item(int $itemid, \stdClass $data, int $actorid): void {
        global $DB;
        $item = $DB->get_record('local_kbacorp_impl_item', ['id' => $itemid], '*', MUST_EXIST);
        $audit = $DB->get_record('local_kbacorp_impl_audit', ['id' => $item->auditid], '*', MUST_EXIST);
        if ($audit->status === self::STATUS_CONFIRMED) {
            throw new \moodle_exception('bmsauditimmutable', 'local_kbacorporate');
        }
        $score = (int)$data->score;
        if ($score < -1 || $score > (int)$audit->scalemax) {
            throw new \invalid_parameter_exception('Score is outside the audit scale.');
        }
        $status = (string)$data->evidencestatus;
        if (!in_array($status, [self::EVIDENCE_MISSING, self::EVIDENCE_SUBMITTED,
                self::EVIDENCE_VERIFIED, self::EVIDENCE_REJECTED, self::EVIDENCE_NEEDS_DATA,
                self::EVIDENCE_NOT_APPLICABLE], true)) {
            throw new \invalid_parameter_exception('Unknown evidence status.');
        }
        if ($status === self::EVIDENCE_NOT_APPLICABLE || $status === self::EVIDENCE_NEEDS_DATA) {
            $score = -1;
        } else if ($score >= 0 && $status === self::EVIDENCE_MISSING
                && $audit->indexmethod === self::INDEX_AREA_AVERAGE) {
            $status = self::EVIDENCE_SUBMITTED;
        }
        if ($status === self::EVIDENCE_VERIFIED && trim((string)$data->evidenceurl) === '') {
            $files = get_file_storage()->get_area_files(\context_system::instance()->id,
                'local_kbacorporate', 'implementation_evidence', $itemid, 'id', false);
            if (!$files) {
                throw new \moodle_exception('bmsevidencerequired', 'local_kbacorporate');
            }
        }
        $item->score = $score;
        $item->evidencestatus = $status;
        $item->evidenceurl = trim((string)$data->evidenceurl) ?: null;
        $item->comment = trim((string)$data->comment) ?: null;
        $item->assessorid = $actorid;
        $item->verifierid = $status === self::EVIDENCE_VERIFIED ? $actorid : 0;
        $item->verifiedat = $status === self::EVIDENCE_VERIFIED ? time() : 0;
        $item->timemodified = time();
        $DB->update_record('local_kbacorp_impl_item', $item);
        self::refresh_metrics((int)$audit->id);
    }

    public static function calculate_metrics(int $auditid): \stdClass {
        $audit = self::get_audit($auditid);
        if ($audit->indexmethod === self::INDEX_AREA_AVERAGE) {
            $sections = self::calculate_sections($auditid);
            $averages = array_values(array_filter(array_column($sections, 'average'), static function($value) {
                return $value !== null;
            }));
            $applicable = array_sum(array_column($sections, 'total'));
            $assessed = array_sum(array_column($sections, 'assessed'));
            return (object)[
                'indexvalue' => $averages ? round(array_sum($averages) / count($averages)
                    / max(1, (int)$audit->scalemax) * 100, 2) : null,
                'coverage' => $applicable ? round($assessed / $applicable * 100, 2) : 0.0,
            ];
        }
        $items = self::get_items($auditid);
        $totalweight = 0;
        $verifiedweight = 0;
        $weightedscore = 0;
        foreach ($items as $item) {
            $weight = max(1, (int)$item->weight);
            $totalweight += $weight;
            if ($item->evidencestatus === self::EVIDENCE_VERIFIED && (int)$item->score >= 0) {
                $verifiedweight += $weight;
                $weightedscore += (int)$item->score * $weight;
            }
        }
        return (object)[
            'indexvalue' => $verifiedweight ? round($weightedscore
                / ($verifiedweight * max(1, (int)$audit->scalemax)) * 100, 2) : null,
            'coverage' => $totalweight ? round($verifiedweight / $totalweight * 100, 2) : 0.0,
        ];
    }

    /** Return Point A-style summaries, keeping every area equally weighted. */
    public static function calculate_sections(int $auditid): array {
        $audit = self::get_audit($auditid);
        $sections = [];
        foreach (self::get_items($auditid) as $item) {
            $code = $item->sectioncode !== null && $item->sectioncode !== '' ? $item->sectioncode : 'general';
            if (!isset($sections[$code])) {
                $sections[$code] = [
                    'code' => $code,
                    'name' => $item->sectionname ?: get_string('bmsgeneralsection', 'local_kbacorporate'),
                    'total' => 0,
                    'assessed' => 0,
                    'attention' => 0,
                    'scores' => [],
                ];
            }
            if ($item->evidencestatus === self::EVIDENCE_NOT_APPLICABLE) {
                continue;
            }
            $sections[$code]['total']++;
            if ((int)$item->score >= 0 && !in_array($item->evidencestatus,
                    [self::EVIDENCE_MISSING, self::EVIDENCE_NEEDS_DATA], true)) {
                $sections[$code]['assessed']++;
                $sections[$code]['scores'][] = (int)$item->score;
                if ((int)$item->score < (int)$audit->attentionthreshold) {
                    $sections[$code]['attention']++;
                }
            }
        }
        foreach ($sections as &$section) {
            $section['average'] = $section['scores']
                ? round(array_sum($section['scores']) / count($section['scores']), 2) : null;
            unset($section['scores']);
        }
        unset($section);
        return array_values($sections);
    }

    public static function refresh_metrics(int $auditid): \stdClass {
        global $DB;
        $metrics = self::calculate_metrics($auditid);
        $DB->set_field('local_kbacorp_impl_audit', 'indexvalue', $metrics->indexvalue, ['id' => $auditid]);
        $DB->set_field('local_kbacorp_impl_audit', 'coverage', $metrics->coverage, ['id' => $auditid]);
        $DB->set_field('local_kbacorp_impl_audit', 'timemodified', time(), ['id' => $auditid]);
        return $metrics;
    }

    public static function confirm_audit(int $auditid, int $actorid): void {
        global $DB;
        $audit = self::get_audit($auditid);
        if ($audit->status === self::STATUS_CONFIRMED) {
            return;
        }
        foreach (self::get_items($auditid) as $item) {
            $complete = $audit->indexmethod === self::INDEX_AREA_AVERAGE
                ? ($item->evidencestatus === self::EVIDENCE_NOT_APPLICABLE
                    || ((int)$item->score >= 0 && !in_array($item->evidencestatus,
                        [self::EVIDENCE_MISSING, self::EVIDENCE_NEEDS_DATA], true)))
                : ($item->evidencestatus === self::EVIDENCE_VERIFIED && (int)$item->score >= 0);
            if (!empty($item->required) && !$complete) {
                throw new \moodle_exception($audit->indexmethod === self::INDEX_AREA_AVERAGE
                    ? 'bmsauditincomplete' : 'bmsauditrequirednotverified', 'local_kbacorporate');
            }
        }
        $metrics = self::refresh_metrics($auditid);
        $DB->update_record('local_kbacorp_impl_audit', (object)[
            'id' => $auditid, 'status' => self::STATUS_CONFIRMED,
            'confirmedby' => $actorid, 'confirmedat' => time(),
            'indexvalue' => $metrics->indexvalue, 'coverage' => $metrics->coverage,
            'timemodified' => time(),
        ]);
    }

    /** Return gaps and configured development routes for an audit snapshot. */
    public static function get_gap_rows(int $auditid): array {
        $rows = [];
        foreach (self::get_items($auditid) as $item) {
            $assessed = (int)$item->score >= 0 && !in_array($item->evidencestatus,
                [self::EVIDENCE_MISSING, self::EVIDENCE_NEEDS_DATA, self::EVIDENCE_NOT_APPLICABLE], true);
            $target = (int)$item->targetscore;
            $gap = $assessed && $target >= 0 ? max(0, $target - (int)$item->score) : null;
            $item->gap = $gap;
            $item->gappriority = self::gap_priority($gap);
            $item->routes = self::get_routes((int)$item->criterionid, true);
            $rows[] = $item;
        }
        return $rows;
    }

    public static function gap_priority(?int $gap): string {
        if ($gap === null) { return 'unassessed'; }
        if ($gap >= 5) { return 'critical'; }
        if ($gap >= 3) { return 'core'; }
        if ($gap > 0) { return 'additional'; }
        return 'achieved';
    }

    public static function get_routes(int $criterionid, bool $activeonly = false): array {
        global $DB;
        if (!$DB->get_manager()->table_exists(new \xmldb_table('local_kbacorp_bms_route'))) { return []; }
        $active = $activeonly ? ' AND r.active = 1' : '';
        return array_values($DB->get_records_sql(
            "SELECT r.*, t.name AS actiontoolname, c.fullname AS coursename
               FROM {local_kbacorp_bms_route} r
          LEFT JOIN {local_kbacorp_bms_tool} t ON t.id = r.actiontoolid
          LEFT JOIN {course} c ON c.id = r.courseid
              WHERE r.criterionid = :criterionid{$active}
           ORDER BY r.priority, r.id", ['criterionid' => $criterionid]));
    }

    public static function save_route(\stdClass $data): int {
        global $DB;
        $criterion = $DB->get_record_sql(
            "SELECT c.*, t.companyid FROM {local_kbacorp_bms_crit} c
               JOIN {local_kbacorp_bms_tool} t ON t.id = c.toolid WHERE c.id = :id",
            ['id' => (int)$data->criterionid], MUST_EXIST);
        $companyid = (int)$data->companyid;
        if ((int)$criterion->companyid !== $companyid) {
            throw new \moodle_exception('companynotallowed', 'local_kbacorporate');
        }
        $actiontoolid = (int)($data->actiontoolid ?? 0);
        $courseid = (int)($data->courseid ?? 0);
        if (!$actiontoolid) {
            throw new \moodle_exception('implementationtoolrequired', 'local_kbacorporate');
        }
        if ($actiontoolid && !$DB->record_exists('local_kbacorp_bms_tool',
                ['id' => $actiontoolid, 'companyid' => $companyid])) {
            throw new \moodle_exception('companynotallowed', 'local_kbacorporate');
        }
        if ($courseid && !$DB->record_exists('local_kbacorp_course',
                ['companyid' => $companyid, 'courseid' => $courseid, 'active' => 1])) {
            throw new \moodle_exception('bmsroutecourseinvalid', 'local_kbacorporate');
        }
        $now = time();
        $record = (object)['companyid' => $companyid, 'criterionid' => (int)$criterion->id,
            'actiontoolid' => $actiontoolid, 'courseid' => $courseid,
            'expectedresult' => trim((string)($data->expectedresult ?? '')) ?: null,
            'evidencecriteria' => trim((string)($data->evidencecriteria ?? '')) ?: null,
            'priority' => in_array(($data->priority ?? ''), ['critical', 'core', 'additional'], true)
                ? $data->priority : 'core',
            'note' => trim((string)($data->note ?? '')) ?: null,
            'active' => empty($data->active) ? 0 : 1, 'timemodified' => $now];
        if (!empty($data->id)) {
            $existing = $DB->get_record('local_kbacorp_bms_route', ['id' => (int)$data->id,
                'companyid' => $companyid, 'criterionid' => (int)$criterion->id], '*', MUST_EXIST);
            $record->id = $existing->id; $DB->update_record('local_kbacorp_bms_route', $record);
            return (int)$record->id;
        }
        $record->timecreated = $now;
        return (int)$DB->insert_record('local_kbacorp_bms_route', $record);
    }

    /** Find the preceding confirmed audit of the same tool and compare matching criterion codes. */
    public static function compare_previous(int $auditid): ?\stdClass {
        global $DB;
        $audit = self::get_audit($auditid);
        $previous = $DB->get_record_sql(
            "SELECT * FROM {local_kbacorp_impl_audit}
              WHERE companyid = :companyid AND toolid = :toolid AND status = :status
                AND (auditdate < :auditdate OR (auditdate = :auditdate2 AND id < :id))
           ORDER BY auditdate DESC, id DESC", ['companyid' => $audit->companyid,
                'toolid' => $audit->toolid, 'status' => self::STATUS_CONFIRMED,
                'auditdate' => $audit->auditdate, 'auditdate2' => $audit->auditdate, 'id' => $auditid], IGNORE_MULTIPLE);
        if (!$previous) { return null; }
        $old = [];
        foreach (self::get_items((int)$previous->id) as $item) { $old[$item->criterioncode] = $item; }
        $changes = [];
        foreach (self::get_items($auditid) as $item) {
            if (isset($old[$item->criterioncode]) && (int)$item->score >= 0 && (int)$old[$item->criterioncode]->score >= 0) {
                $changes[] = (int)$item->score - (int)$old[$item->criterioncode]->score;
            }
        }
        return (object)['audit' => $previous, 'matched' => count($changes),
            'averagechange' => $changes ? round(array_sum($changes) / count($changes), 2) : null];
    }

    public static function get_company_owners(int $companyid): array {
        global $DB;
        return array_values($DB->get_records_sql(
            "SELECT DISTINCT u.id, u.firstname, u.lastname, u.email
               FROM {local_kbacorp_member} m JOIN {user} u ON u.id = m.userid
              WHERE m.companyid = :companyid AND m.isemployee = 1 AND u.deleted = 0
           ORDER BY u.lastname, u.firstname", ['companyid' => $companyid]));
    }

    public static function save_task(\stdClass $data, int $actorid): int {
        global $DB;
        $item = $DB->get_record_sql(
            "SELECT i.*, a.companyid FROM {local_kbacorp_impl_item} i
               JOIN {local_kbacorp_impl_audit} a ON a.id = i.auditid
              WHERE i.id = :itemid AND a.id = :auditid",
            ['itemid' => (int)$data->itemid, 'auditid' => (int)$data->auditid], MUST_EXIST);
        if ((int)$item->companyid !== (int)$data->companyid) {
            throw new \moodle_exception('companynotallowed', 'local_kbacorporate');
        }
        $ownerid = (int)($data->ownerid ?? 0);
        if ($ownerid && !$DB->record_exists('local_kbacorp_member',
                ['companyid' => (int)$data->companyid, 'userid' => $ownerid, 'isemployee' => 1])) {
            throw new \moodle_exception('implementationtaskownerinvalid', 'local_kbacorporate');
        }
        $routeid = (int)($data->routeid ?? 0); $route = null;
        if ($routeid) {
            $route = $DB->get_record('local_kbacorp_bms_route', ['id' => $routeid,
                'companyid' => (int)$data->companyid, 'criterionid' => (int)$item->criterionid], '*', MUST_EXIST);
        }
        $statuses = [self::TASK_PLANNED, self::TASK_IN_PROGRESS, self::TASK_REVIEW,
            self::TASK_COMPLETED, self::TASK_CANCELLED];
        $status = in_array((string)$data->status, $statuses, true) ? (string)$data->status : self::TASK_PLANNED;
        $now = time(); $gap = ((int)$item->score >= 0 && (int)$item->targetscore >= 0)
            ? max(0, (int)$item->targetscore - (int)$item->score) : -1;
        $record = (object)[
            'companyid' => (int)$data->companyid, 'auditid' => (int)$item->auditid,
            'itemid' => (int)$item->id, 'routeid' => $routeid,
            'title' => trim((string)$data->title), 'sectionname' => $item->sectionname,
            'criterionname' => $item->criterionname, 'currentscore' => (int)$item->score,
            'targetscore' => (int)$item->targetscore, 'gapvalue' => $gap,
            'actiontoolid' => $route ? (int)$route->actiontoolid : 0,
            'courseid' => $route ? (int)$route->courseid : 0,
            'ownerid' => $ownerid,
            'priority' => in_array((string)$data->priority, ['critical', 'core', 'additional'], true)
                ? (string)$data->priority : 'core',
            'status' => $status, 'expectedresult' => trim((string)$data->expectedresult) ?: null,
            'completioncomment' => trim((string)$data->completioncomment) ?: null,
            'resulturl' => clean_param((string)($data->resulturl ?? ''), PARAM_URL) ?: null,
            'startdate' => (int)$data->startdate, 'duedate' => (int)$data->duedate,
            'completedat' => $status === self::TASK_COMPLETED ? $now : 0,
            'lastremindersent' => 0, 'lastreminderdue' => 0, 'lastremindertype' => null,
            'modifiedby' => $actorid, 'timemodified' => $now,
        ];
        if (!empty($data->id)) {
            $existing = $DB->get_record('local_kbacorp_impl_task', ['id' => (int)$data->id,
                'companyid' => (int)$data->companyid, 'deleted' => 0], '*', MUST_EXIST);
            $record->id = $existing->id; $record->createdby = $existing->createdby;
            $record->timecreated = $existing->timecreated;
            if ($status === self::TASK_COMPLETED && (int)$existing->completedat > 0) {
                $record->completedat = (int)$existing->completedat;
            }
            if ((int)$existing->duedate === (int)$record->duedate &&
                    in_array((string)$existing->status,
                        [self::TASK_PLANNED, self::TASK_IN_PROGRESS, self::TASK_REVIEW], true)) {
                $record->lastremindersent = (int)($existing->lastremindersent ?? 0);
                $record->lastreminderdue = (int)($existing->lastreminderdue ?? 0);
                $record->lastremindertype = $existing->lastremindertype ?? null;
            }
            $DB->update_record('local_kbacorp_impl_task', $record); return (int)$record->id;
        }
        if ($existing = $DB->get_record('local_kbacorp_impl_task',
                ['itemid' => (int)$item->id, 'routeid' => $routeid, 'deleted' => 0])) {
            return (int)$existing->id;
        }
        $record->createdby = $actorid; $record->timecreated = $now;
        return (int)$DB->insert_record('local_kbacorp_impl_task', $record);
    }

    public static function get_tasks(int $companyid, array $filters = []): array {
        global $DB;
        $where = ['t.companyid = :companyid', 't.deleted = 0']; $params = ['companyid' => $companyid];
        foreach (['auditid', 'ownerid'] as $field) {
            if (!empty($filters[$field])) { $where[] = "t.{$field} = :{$field}"; $params[$field] = (int)$filters[$field]; }
        }
        if (!empty($filters['status'])) { $where[] = 't.status = :status'; $params['status'] = $filters['status']; }
        if (!empty($filters['overdue'])) { $where[] = 't.duedate > 0 AND t.duedate < :today AND t.status NOT IN (:completed, :cancelled)';
            $params += ['today' => usergetmidnight(time()), 'completed' => self::TASK_COMPLETED, 'cancelled' => self::TASK_CANCELLED]; }
        return array_values($DB->get_records_sql(
            "SELECT t.*, u.firstname, u.lastname, bt.name AS actiontoolname, c.fullname AS coursename,
                    (SELECT COUNT(1) FROM {local_kbacorp_impl_comment} dc WHERE dc.taskid = t.id) AS discussioncount
               FROM {local_kbacorp_impl_task} t LEFT JOIN {user} u ON u.id = t.ownerid
          LEFT JOIN {local_kbacorp_bms_tool} bt ON bt.id = t.actiontoolid
          LEFT JOIN {course} c ON c.id = t.courseid WHERE " . implode(' AND ', $where) .
            " ORDER BY t.status, t.duedate, t.id", $params));
    }

    /** Convert a locale-friendly decimal input to a database value. */
    public static function parse_decimal(string $value): ?float {
        $normalised = str_replace(["\xc2\xa0", ' '], '', trim($value));
        $normalised = str_replace(',', '.', $normalised);
        return $normalised !== '' && is_numeric($normalised) ? (float)$normalised : null;
    }

    public static function get_economic_snapshot(int $auditid): ?\stdClass {
        global $DB;
        if (!$DB->get_manager()->table_exists(new \xmldb_table('local_kbacorp_impl_econ'))) {
            return null;
        }
        return $DB->get_record('local_kbacorp_impl_econ', ['auditid' => $auditid]) ?: null;
    }

    /** Save the single economic snapshot belonging to an editable audit. */
    public static function save_economic_snapshot(\stdClass $data, int $actorid): int {
        global $DB;
        $audit = self::get_audit((int)$data->auditid);
        if ((int)$audit->companyid !== (int)$data->companyid) {
            throw new \moodle_exception('companynotallowed', 'local_kbacorporate');
        }
        if ($audit->status === self::STATUS_CONFIRMED) {
            throw new \moodle_exception('bmsauditimmutable', 'local_kbacorporate');
        }
        $now = time();
        $record = (object)[
            'auditid' => (int)$audit->id, 'companyid' => (int)$audit->companyid,
            'periodtype' => in_array((string)$data->periodtype,
                ['month', 'quarter', 'year', 'custom'], true) ? (string)$data->periodtype : 'year',
            'periodstart' => (int)$data->periodstart, 'periodend' => (int)$data->periodend,
            'currency' => in_array((string)$data->currency, ['RUB', 'USD', 'EUR', 'KZT'], true)
                ? (string)$data->currency : 'RUB',
            'employees' => trim((string)($data->employees ?? '')) === '' ? null : max(0, (int)$data->employees),
            'source' => in_array((string)$data->source, ['manual', 'file', '1c', 'odoo', 'other'], true)
                ? (string)$data->source : 'manual',
            'comment' => trim((string)($data->comment ?? '')) ?: null,
            'verified' => empty($data->verified) ? 0 : 1,
            'verifiedby' => empty($data->verified) ? 0 : $actorid,
            'verifiedat' => empty($data->verified) ? 0 : $now,
            'modifiedby' => $actorid, 'timemodified' => $now,
        ];
        foreach (['revenue', 'grossprofit', 'operatingprofit', 'netprofit', 'profittax', 'vat',
                'socialfunds', 'othertaxes', 'payroll', 'averagesalary'] as $field) {
            $record->{$field} = self::parse_decimal((string)($data->{$field} ?? ''));
        }
        $existing = self::get_economic_snapshot((int)$audit->id);
        if ($existing) {
            $record->id = (int)$existing->id;
            $record->createdby = (int)$existing->createdby;
            $record->timecreated = (int)$existing->timecreated;
            $DB->update_record('local_kbacorp_impl_econ', $record);
            $snapshotid = (int)$record->id;
        } else {
            $record->createdby = $actorid;
            $record->timecreated = $now;
            $snapshotid = (int)$DB->insert_record('local_kbacorp_impl_econ', $record);
        }

        // Keep the v0.33.4 registry in sync while preserving the legacy snapshot
        // as the compatibility source for existing audit screens.
        \local_kbacorporate\local\economic_indicators::sync_legacy_snapshot($snapshotid, $actorid);
        return $snapshotid;
    }

    /** Derived ratios remain separate from the BMS maturity score. */
    public static function calculate_economic_metrics(\stdClass $record): \stdClass {
        $revenue = $record->revenue === null ? null : (float)$record->revenue;
        $employees = $record->employees === null ? null : (int)$record->employees;
        $taxes = null;
        foreach (['profittax', 'vat', 'socialfunds', 'othertaxes'] as $field) {
            if ($record->{$field} !== null) { $taxes = ($taxes ?? 0) + (float)$record->{$field}; }
        }
        $ratio = static function($numerator, $denominator): ?float {
            return $numerator !== null && $denominator !== null && (float)$denominator != 0.0
                ? round((float)$numerator / (float)$denominator * 100, 2) : null;
        };
        return (object)[
            'totaltaxes' => $taxes,
            'grossmargin' => $ratio($record->grossprofit, $revenue),
            'operatingmargin' => $ratio($record->operatingprofit, $revenue),
            'netmargin' => $ratio($record->netprofit, $revenue),
            'taxburden' => $ratio($taxes, $revenue),
            'payrollshare' => $ratio($record->payroll, $revenue),
            'revenueperemployee' => $employees && $revenue !== null ? round($revenue / $employees, 2) : null,
            'operatingprofitperemployee' => $employees && $record->operatingprofit !== null
                ? round((float)$record->operatingprofit / $employees, 2) : null,
        ];
    }

    /** Compare the snapshot with the preceding confirmed company audit carrying economic data. */
    public static function compare_economic_previous(int $auditid): ?\stdClass {
        global $DB;
        $audit = self::get_audit($auditid);
        $current = self::get_economic_snapshot($auditid);
        if (!$current) { return null; }
        $previous = $DB->get_record_sql(
            "SELECT e.*, a.title AS audittitle, a.auditdate
               FROM {local_kbacorp_impl_econ} e
               JOIN {local_kbacorp_impl_audit} a ON a.id = e.auditid
              WHERE e.companyid = :companyid AND e.auditid <> :auditid
                AND a.status = :status AND e.periodend < :periodend
           ORDER BY e.periodend DESC, e.id DESC",
            ['companyid' => $audit->companyid, 'auditid' => $auditid,
                'status' => self::STATUS_CONFIRMED, 'periodend' => $current->periodend], IGNORE_MULTIPLE);
        return $previous ? (object)['snapshot' => $previous,
            'metrics' => self::calculate_economic_metrics($previous)] : null;
    }
}
