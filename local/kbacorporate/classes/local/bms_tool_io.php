<?php
namespace local_kbacorporate\local;

defined('MOODLE_INTERNAL') || die();

/** Safe JSON import service for BMS tools and their criteria. */
class bms_tool_io {
    public const SCHEMA = 'kba-bms-tools';
    public const VERSIONS = ['1.0', '1.1'];

    public static function decode(string $json): array {
        $data = json_decode($json, true);
        if (!is_array($data)) {
            throw new \invalid_parameter_exception(get_string('bmstooljsoninvalid', 'local_kbacorporate'));
        }
        $schema = trim((string)($data['schema'] ?? ''));
        $version = trim((string)($data['version'] ?? ''));
        if ($schema !== self::SCHEMA || !in_array($version, self::VERSIONS, true)) {
            throw new \invalid_parameter_exception(get_string('bmstooljsonschemaunsupported', 'local_kbacorporate'));
        }
        if (empty($data['tools']) || !is_array($data['tools'])) {
            throw new \invalid_parameter_exception(get_string('bmstooljsontoolsmissing', 'local_kbacorporate'));
        }
        return $data;
    }

    public static function preview(int $companyid, array $data): array {
        global $DB;
        $rows = [];
        $summary = ['tools' => 0, 'criteria' => 0, 'create' => 0, 'update' => 0, 'unchanged' => 0, 'error' => 0];
        $seen = [];

        foreach ($data['tools'] as $toolindex => $tooldata) {
            $toolcode = strtoupper(trim((string)($tooldata['tool_code'] ?? '')));
            if ($toolcode === '') {
                $rows[] = self::error_row($toolindex + 1, '', '', get_string('bmstooljsonmissingtoolcode', 'local_kbacorporate'));
                $summary['error']++;
                continue;
            }
            $summary['tools']++;
            $tools = $DB->get_records('local_kbacorp_bms_tool', ['code' => $toolcode, 'companyid' => $companyid], 'id DESC');
            $toolrecord = $tools ? reset($tools) : null;
            $toolid = $toolrecord ? (int)$toolrecord->id : 0;
            $criteria = $tooldata['criteria'] ?? [];
            if (!is_array($criteria)) {
                $rows[] = self::error_row($toolindex + 1, $toolcode, '', get_string('bmstooljsoncriteriamissing', 'local_kbacorporate'));
                $summary['error']++;
                continue;
            }

            foreach ($criteria as $i => $criteriondata) {
                $summary['criteria']++;
                $code = trim((string)($criteriondata['criterion_code'] ?? ''));
                $row = (object)[
                    'row' => $summary['criteria'], 'tool_code' => $toolcode, 'criterion_code' => $code,
                    'name' => trim((string)($criteriondata['name'] ?? '')), 'sectioncode' => trim((string)($criteriondata['section_code'] ?? '')),
                    'sectionname' => trim((string)($criteriondata['section_name'] ?? '')), 'description' => trim((string)($criteriondata['description'] ?? '')),
                    'minlabel' => trim((string)($criteriondata['min_level_description'] ?? $criteriondata['minlabel'] ?? '')),
                    'maxlabel' => trim((string)($criteriondata['max_level_description'] ?? $criteriondata['maxlabel'] ?? '')),
                    'evidencehint' => trim((string)($criteriondata['evidence_required'] ?? $criteriondata['evidencehint'] ?? '')),
                    'targetscore' => array_key_exists('target_score', $criteriondata) ? (int)$criteriondata['target_score'] : -1,
                    'weight' => max(1, (int)($criteriondata['weight'] ?? 1)),
                    'required' => array_key_exists('mandatory', $criteriondata) ? (!empty($criteriondata['mandatory']) ? 1 : 0) : 1,
                    'active' => array_key_exists('active', $criteriondata) ? (!empty($criteriondata['active']) ? 1 : 0) : 1,
                    'sortorder' => array_key_exists('sortorder', $criteriondata) ? (int)$criteriondata['sortorder'] : (($i + 1) * 10),
                    'status' => 'error', 'message' => '', 'toolid' => $toolid, 'criterionid' => 0,
                    'tool_name' => trim((string)($tooldata['name'] ?? $toolcode)),
                    'tool_area' => trim((string)($tooldata['area'] ?? 'management')),
                    'tool_sourceversion' => trim((string)($tooldata['source_version'] ?? '')),
                    'tool_scalemax' => max(1, (int)($tooldata['scale']['max'] ?? 9)),
                    'tool_active' => array_key_exists('active', $tooldata) ? (!empty($tooldata['active']) ? 1 : 0) : 1,
                ];

                if ($code === '' || $row->name === '') {
                    $row->message = get_string('bmstooljsonmissingcriterionfields', 'local_kbacorporate');
                    $rows[] = $row; $summary['error']++; continue;
                }
                $key = $toolcode . "\n" . $code;
                if (isset($seen[$key])) {
                    $row->message = get_string('bmstooljsonduplicatecriterion', 'local_kbacorporate', $seen[$key]);
                    $rows[] = $row; $summary['error']++; continue;
                }
                $seen[$key] = $row->row;
                if ($row->targetscore < -1 || $row->targetscore > $row->tool_scalemax) {
                    $row->message = get_string('bmstooljsoninvalidtarget', 'local_kbacorporate');
                    $rows[] = $row; $summary['error']++; continue;
                }

                if (!$toolrecord) {
                    $row->status = 'create';
                    $row->message = get_string('bmstooljsonwillcreatetoolcriterion', 'local_kbacorporate');
                    $summary['create']++;
                    $rows[] = $row;
                    continue;
                }

                $existing = $DB->get_record('local_kbacorp_bms_crit', ['toolid' => $toolid, 'code' => $code]);
                if (!$existing) {
                    $row->status = 'create';
                    $row->message = get_string('bmstooljsonwillcreate', 'local_kbacorporate');
                    $summary['create']++;
                } else {
                    $row->criterionid = (int)$existing->id;
                    $changed = false;
                    foreach ([
                        'name' => $row->name, 'sectioncode' => $row->sectioncode, 'sectionname' => $row->sectionname,
                        'description' => $row->description, 'minlabel' => $row->minlabel, 'maxlabel' => $row->maxlabel,
                        'evidencehint' => $row->evidencehint, 'targetscore' => $row->targetscore, 'weight' => $row->weight,
                        'required' => $row->required, 'active' => $row->active, 'sortorder' => $row->sortorder,
                    ] as $field => $value) {
                        $dbvalue = $existing->{$field};
                        if (in_array($field, ['targetscore', 'weight', 'required', 'active', 'sortorder'], true)) {
                            if ((int)$dbvalue !== (int)$value) { $changed = true; break; }
                        } else if (trim((string)$dbvalue) !== trim((string)$value)) { $changed = true; break; }
                    }
                    $row->status = $changed ? 'update' : 'unchanged';
                    $row->message = get_string($changed ? 'bmstooljsonwillupdate' : 'bmstooljsonunchanged', 'local_kbacorporate');
                    $summary[$row->status]++;
                }
                $rows[] = $row;
            }
        }
        return ['rows' => $rows, 'summary' => $summary];
    }

    public static function apply(int $companyid, array $rows): array {
        global $DB;
        $stats = ['create' => 0, 'update' => 0, 'unchanged' => 0, 'skipped' => 0, 'tools' => 0];
        $transaction = $DB->start_delegated_transaction();
        $toolcache = [];
        $changedtools = [];
        $now = time();
        try {
            foreach ($rows as $row) {
                if (!in_array($row->status, ['create', 'update', 'unchanged'], true)) { $stats['skipped']++; continue; }
                if ($row->status === 'unchanged') { $stats['unchanged']++; continue; }

                if (empty($toolcache[$row->tool_code])) {
                    $tool = $DB->get_record('local_kbacorp_bms_tool', ['code' => $row->tool_code, 'companyid' => $companyid]);
                    if (!$tool) {
                        $tool = (object)[
                            'companyid' => $companyid, 'code' => $row->tool_code, 'name' => $row->tool_name,
                            'area' => $row->tool_area ?: 'management', 'description' => null,
                            'sourceversion' => $row->tool_sourceversion ?: null, 'scalemax' => $row->tool_scalemax,
                            'active' => $row->tool_active, 'currentversion' => 0, 'hasdraft' => 1,
                            'timecreated' => $now, 'timemodified' => $now,
                        ];
                        $tool->id = $DB->insert_record('local_kbacorp_bms_tool', $tool);
                    }
                    $toolcache[$row->tool_code] = $tool;
                }
                $tool = $toolcache[$row->tool_code];
                $record = (object)[
                    'toolid' => (int)$tool->id, 'code' => $row->criterion_code, 'name' => $row->name,
                    'sectioncode' => $row->sectioncode !== '' ? $row->sectioncode : null,
                    'sectionname' => $row->sectionname !== '' ? $row->sectionname : null,
                    'description' => $row->description !== '' ? $row->description : null,
                    'minlabel' => $row->minlabel !== '' ? $row->minlabel : null,
                    'maxlabel' => $row->maxlabel !== '' ? $row->maxlabel : null,
                    'evidencehint' => $row->evidencehint !== '' ? $row->evidencehint : null,
                    'targetscore' => $row->targetscore, 'weight' => $row->weight, 'required' => $row->required,
                    'sortorder' => $row->sortorder, 'active' => $row->active, 'timemodified' => $now,
                ];
                $existing = $DB->get_record('local_kbacorp_bms_crit', ['toolid' => (int)$tool->id, 'code' => $row->criterion_code]);
                if ($existing) {
                    $record->id = (int)$existing->id;
                    $DB->update_record('local_kbacorp_bms_crit', $record);
                    $stats['update']++;
                } else {
                    $record->timecreated = $now;
                    $DB->insert_record('local_kbacorp_bms_crit', $record);
                    $stats['create']++;
                }
                $changedtools[(int)$tool->id] = true;
            }
            foreach (array_keys($changedtools) as $toolid) {
                implementation_audits::mark_tool_draft((int)$toolid);
            }
            $stats['tools'] = count($changedtools);
            $transaction->allow_commit();
            return $stats;
        } catch (\Throwable $e) {
            $transaction->rollback($e);
            throw $e;
        }
    }

    private static function error_row(int $row, string $toolcode, string $criterioncode, string $message): \stdClass {
        return (object)['row' => $row, 'tool_code' => $toolcode, 'criterion_code' => $criterioncode,
            'name' => '', 'status' => 'error', 'message' => $message];
    }
}
