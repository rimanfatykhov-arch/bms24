<?php
namespace local_kbacorporate\local;

defined('MOODLE_INTERNAL') || die();

/** Import/export service for stable-code BMS ↔ Moodle competency mappings. */
class bms_competency_io {
    /** Legacy interchange schema used by v0.28.x / v0.29.x. */
    public const LEGACY_SCHEMA = 'kba-bms-competency-links';
    /** Canonical BMS JSON library schema. */
    public const LIBRARY_SCHEMA = 'kba-bms-mapping-library';
    public const LEGACY_VERSION = '1.0';
    public const LIBRARY_VERSIONS = ['1.0', '1.1'];

    /**
     * Decode and normalize any supported mapping JSON into the internal links shape.
     *
     * Supported input:
     * - kba-bms-competency-links v1.0 + links[]
     * - kba-bms-mapping-library v1.0/v1.1 + mappings[]
     */
    public static function decode(string $json): array {
        $data = json_decode($json, true);
        if (!is_array($data)) {
            throw new \invalid_parameter_exception(get_string('bmsjsoninvalid', 'local_kbacorporate'));
        }

        $schema = trim((string)($data['schema'] ?? ''));
        $version = trim((string)($data['version'] ?? ''));

        if ($schema === self::LEGACY_SCHEMA && $version === self::LEGACY_VERSION) {
            if (!isset($data['links']) || !is_array($data['links'])) {
                throw new \invalid_parameter_exception(get_string('bmsjsonlinksmissing', 'local_kbacorporate'));
            }
            return [
                'schema' => self::LEGACY_SCHEMA,
                'version' => self::LEGACY_VERSION,
                'source_schema' => $schema,
                'source_version' => $version,
                'framework' => $data['framework'] ?? null,
                'links' => array_values($data['links']),
            ];
        }

        if ($schema === self::LIBRARY_SCHEMA && in_array($version, self::LIBRARY_VERSIONS, true)) {
            if (!isset($data['mappings']) || !is_array($data['mappings'])) {
                throw new \invalid_parameter_exception(get_string('bmsjsonmappingsmissing', 'local_kbacorporate'));
            }
            return [
                'schema' => self::LEGACY_SCHEMA,
                'version' => self::LEGACY_VERSION,
                'source_schema' => $schema,
                'source_version' => $version,
                'framework' => $data['framework'] ?? null,
                'links' => array_values($data['mappings']),
            ];
        }

        throw new \invalid_parameter_exception(get_string('bmsjsonschemaunsupported', 'local_kbacorporate'));
    }

    public static function preview(int $companyid, array $data): array {
        global $DB;
        $rows = [];
        $seen = [];

        foreach (($data['links'] ?? []) as $i => $link) {
            $row = (object)[
                'row' => $i + 1,
                'tool_code' => trim((string)($link['tool_code'] ?? '')),
                'criterion_code' => trim((string)($link['criterion_code'] ?? '')),
                'competency_code' => trim((string)($link['competency_code'] ?? '')),
                'targetlevel' => isset($link['target_level']) ? (int)$link['target_level'] : 0,
                'priority' => trim((string)($link['priority'] ?? 'core')),
                'required' => !empty($link['mandatory']) ? 1 : 0,
                'status' => 'error', 'message' => '', 'toolid' => 0, 'criterionid' => 0, 'competencyid' => 0,
            ];

            try {
                if ($row->tool_code === '' || $row->criterion_code === '' || $row->competency_code === '') {
                    $row->message = get_string('bmsjsonmissingcodes', 'local_kbacorporate');
                    $rows[] = $row;
                    continue;
                }

                $key = $row->tool_code . "\n" . $row->criterion_code . "\n" . $row->competency_code;
                if (isset($seen[$key])) {
                    $row->message = get_string('bmsjsonduplicatelink', 'local_kbacorporate', $seen[$key]);
                    $rows[] = $row;
                    continue;
                }
                $seen[$key] = $row->row;

                if ($row->targetlevel < 0 || $row->targetlevel > 9 ||
                        !in_array($row->priority, ['critical', 'core', 'additional'], true)) {
                    $row->message = get_string('bmsjsoninvalidvalues', 'local_kbacorporate');
                    $rows[] = $row;
                    continue;
                }

                if (preg_match('/^BMS-D\d+$/i', $row->competency_code)) {
                    $row->status = 'domainnode';
                    $row->message = get_string('bmsjsondomainnodeblocked', 'local_kbacorporate');
                    $rows[] = $row;
                    continue;
                }

                // Do not use get_record() for stable codes here. Historical imports can leave
                // more than one matching row; get_records() lets us resolve that deterministically.
                $tools = $DB->get_records('local_kbacorp_bms_tool', [
                    'code' => $row->tool_code, 'companyid' => $companyid,
                ], 'id DESC');
                $tool = $tools ? reset($tools) : false;
                if (!$tool) {
                    $tools = $DB->get_records('local_kbacorp_bms_tool', [
                        'code' => $row->tool_code, 'companyid' => 0,
                    ], 'id DESC');
                    $tool = $tools ? reset($tools) : false;
                }
                if (!$tool) {
                    $row->status = 'toolmissing';
                    $row->message = get_string('bmsjsontoolmissing', 'local_kbacorporate');
                    $rows[] = $row;
                    continue;
                }
                if ((int)$tool->companyid === 0) {
                    $row->status = 'readonly';
                    $row->message = get_string('bmsjsonglobalreadonly', 'local_kbacorporate');
                    $rows[] = $row;
                    continue;
                }

                $criteria = $DB->get_records('local_kbacorp_bms_crit', [
                    'toolid' => (int)$tool->id, 'code' => $row->criterion_code,
                ], 'id DESC');
                $criterion = $criteria ? reset($criteria) : false;
                if (!$criterion) {
                    $row->status = 'criterionmissing';
                    $row->message = get_string('bmsjsoncriterionmissing', 'local_kbacorporate');
                    $rows[] = $row;
                    continue;
                }

                // Moodle competency idnumber is the stable integration key.
                $competencies = $DB->get_records('competency', [
                    'idnumber' => $row->competency_code,
                ], 'id ASC', 'id,idnumber');
                if (!$competencies) {
                    $row->status = 'competencymissing';
                    $row->message = get_string('bmsjsoncompetencymissing', 'local_kbacorporate');
                    $rows[] = $row;
                    continue;
                }
                if (count($competencies) > 1) {
                    $row->message = get_string('bmsjsoncompetencyduplicate', 'local_kbacorporate');
                    $rows[] = $row;
                    continue;
                }

                $competency = reset($competencies);
                $row->toolid = (int)$tool->id;
                $row->criterionid = (int)$criterion->id;
                $row->competencyid = (int)$competency->id;

                $existingrows = $DB->get_records('local_kbacorp_bms_comp', [
                    'criterionid' => $row->criterionid,
                    'competencyid' => $row->competencyid,
                ], 'id DESC');
                $existing = $existingrows ? reset($existingrows) : false;
                if (!$existing) {
                    $row->status = 'create';
                    $row->message = get_string('bmsjsonwillcreate', 'local_kbacorporate');
                } else if ((int)$existing->targetlevel === $row->targetlevel &&
                        (string)$existing->priority === $row->priority &&
                        (int)$existing->required === $row->required) {
                    $row->status = 'unchanged';
                    $row->message = get_string('bmsjsonunchanged', 'local_kbacorporate');
                } else {
                    $row->status = 'update';
                    $row->message = get_string('bmsjsonwillupdate', 'local_kbacorporate');
                }
            } catch (\Throwable $e) {
                // A single legacy/duplicate DB row must never abort the whole 137-row Dry Run.
                $row->status = 'error';
                $row->message = get_string('bmsjsonrowerror', 'local_kbacorporate', (object)[
                    'row' => $row->row,
                    'message' => $e->getMessage(),
                ]);
            }
            $rows[] = $row;
        }
        return $rows;
    }

    public static function apply(int $companyid, array $rows): array {
        global $DB;
        $transaction = $DB->start_delegated_transaction();
        $stats = ['create' => 0, 'update' => 0, 'unchanged' => 0, 'skipped' => 0];
        $touched = [];
        foreach ($rows as $row) {
            if (!in_array($row->status, ['create', 'update', 'unchanged'], true)) {
                $stats['skipped']++;
                continue;
            }
            if ($row->status === 'unchanged') {
                $stats['unchanged']++;
                continue;
            }
            $record = (object)[
                'companyid' => $companyid,
                'criterionid' => $row->criterionid,
                'competencyid' => $row->competencyid,
                'targetlevel' => $row->targetlevel,
                'priority' => $row->priority,
                'required' => $row->required,
            ];
            implementation_audits::save_criterion_competency($record);
            $stats[$row->status]++;
            $touched[$row->toolid] = true;
        }
        $transaction->allow_commit();
        $stats['tools'] = count($touched);
        return $stats;
    }

    /**
     * Export in the canonical BMS mapping-library format.
     */
    public static function export_company(int $companyid): array {
        global $DB;
        $mappings = [];
        $sql = "SELECT bc.id, t.code AS tool_code, c.code AS criterion_code, comp.idnumber AS competency_code,
                       bc.targetlevel, bc.priority, bc.required
                  FROM {local_kbacorp_bms_comp} bc
                  JOIN {local_kbacorp_bms_crit} c ON c.id = bc.criterionid
                  JOIN {local_kbacorp_bms_tool} t ON t.id = c.toolid
                  JOIN {competency} comp ON comp.id = bc.competencyid
                 WHERE t.companyid = :companyid
              ORDER BY t.code, c.code, comp.idnumber";
        foreach ($DB->get_records_sql($sql, ['companyid' => $companyid]) as $row) {
            $mappings[] = [
                'tool_code' => (string)$row->tool_code,
                'criterion_code' => (string)$row->criterion_code,
                'competency_code' => (string)$row->competency_code,
                'target_level' => (int)$row->targetlevel,
                'priority' => (string)$row->priority,
                'mandatory' => !empty($row->required),
            ];
        }

        return [
            'schema' => self::LIBRARY_SCHEMA,
            'version' => '1.1',
            'company_id' => $companyid,
            'exported_at' => gmdate('c'),
            'statistics' => [
                'mapping_records' => count($mappings),
            ],
            'mappings' => $mappings,
        ];
    }
}
