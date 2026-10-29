<?php
namespace local_kbacompany\local;

defined('MOODLE_INTERNAL') || die();

/**
 * Safe importer and review workflow for legacy diagrams.net / Draw.io OFS sources.
 *
 * Legacy diagrams are treated as read-only methodology inputs. Parsing produces an
 * analysis snapshot and semantic suggestions; a human mapping is required before a
 * normalized company-scoped template draft is persisted. Imported templates never
 * modify or depend on the original Drive file after import.
 */
class legacy_drawio_import_service {
    public const CONTRACT_VERSION = '0.4.6.15';
    public const PARSER_VERSION = '1.2';
    public const MAPPING_VERSION = '1.1';

    private const SESSION_KEY = 'local_kbacompany_legacy_ofs_import';
    private const MAX_BYTES = 2097152; // 2 MiB.
    private const TOKEN_TTL = 3600;

    private const SEMANTIC_TYPES = [
        'company', 'office', 'orgunit', 'department', 'function', 'position',
        'product', 'ckp', 'result', 'label', 'unknown', 'ignore',
    ];

    private const STRUCTURAL_TYPES = ['office', 'orgunit', 'department'];

    /** Known immutable source-archive metadata. No network/runtime dependency. */
    private const KNOWN_SOURCES = [
        'Оргсхема_Дистрибуция.drawio' => [
            'driveid' => '1q0E2uAr1fx5ThKCKdjmR0JsWmVe6y-p1',
            'modified' => '2021-12-02T12:45:42Z',
        ],
        'Оргсхема_Стоматологическая клиника .drawio' => [
            'driveid' => '1NKiQ56EZyvkTRy_zUbR7SHyhDndgAtRR',
            'modified' => '2021-12-02T13:39:24Z',
        ],
        'Оргсхема_Стоматологическая клиника.drawio' => [
            'driveid' => '1NKiQ56EZyvkTRy_zUbR7SHyhDndgAtRR',
            'modified' => '2021-12-02T13:39:24Z',
        ],
        '19.2_Оргсхема_Строительство по индивидуальным проектам.drawio' => [
            'driveid' => '1Dhez-YJXWuZhwi7jGAwvGxAuSLq5ls56',
            'modified' => '2021-12-01T06:18:49Z',
        ],
    ];

    public static function contract(): array {
        return [
            'component' => 'local_kbacompany',
            'contract' => 'bms_legacy_drawio_import',
            'version' => self::CONTRACT_VERSION,
            'parser_version' => self::PARSER_VERSION,
            'mapping_version' => self::MAPPING_VERSION,
            'source_of_truth' => 'human-reviewed normalized BMS template draft',
            'rules' => [
                'source_library_is_never_modified',
                'xml_external_entities_are_disabled',
                'compressed_and_plain_drawio_supported',
                'automatic_mapping_is_a_suggestion_only',
                'color_is_not_business_semantics',
                'human_confirmation_required_before_draft_creation',
                'import_creates_normalized_template_draft_not_company_project',
                'template_must_be_reviewed_before_available',
                'only_available_template_can_create_orgdesign_project',
                'drawio_does_not_become_source_of_truth',
                'compound_legacy_blocks_are_guidance_not_silent_objects',
                'ckp_parenting_prefers_semantic_band_and_column_alignment',
                'preview_exposes_normalization_readiness_and_review_flags',
            ],
        ];
    }

    public static function semantic_types(): array {
        return self::SEMANTIC_TYPES;
    }

    /** Parse an uploaded draw.io file and put the review snapshot in the user session. */
    public static function preview_upload(string $companyuuid, int $userid, array $upload, array $provenance = []): array {
        self::manage_access($companyuuid, $userid);
        if (empty($upload['tmp_name']) || !is_readable($upload['tmp_name'])) {
            throw new \moodle_exception('ofslegacyimport_fileerror', 'local_kbacompany');
        }
        $size = isset($upload['size']) ? (int)$upload['size'] : (int)filesize($upload['tmp_name']);
        if ($size <= 0 || $size > self::MAX_BYTES) {
            throw new \moodle_exception('ofslegacyimport_filesize', 'local_kbacompany');
        }
        $filename = clean_param((string)($upload['name'] ?? 'legacy.drawio'), PARAM_FILE);
        if ($filename === '') {
            $filename = 'legacy.drawio';
        }
        $xml = file_get_contents($upload['tmp_name']);
        if ($xml === false || $xml === '') {
            throw new \moodle_exception('ofslegacyimport_fileerror', 'local_kbacompany');
        }
        return self::preview_xml($companyuuid, $userid, $filename, $xml, $provenance);
    }

    /** Parse supplied XML (also used by regression tests and offline verification). */
    public static function preview_xml(string $companyuuid, int $userid, string $filename, string $xml,
            array $provenance = []): array {
        self::manage_access($companyuuid, $userid);
        if (strlen($xml) > self::MAX_BYTES) {
            throw new \moodle_exception('ofslegacyimport_filesize', 'local_kbacompany');
        }
        $parsed = self::parse($xml);
        if (empty($parsed['candidates'])) {
            throw new \moodle_exception('ofslegacyimport_nocandidates', 'local_kbacompany');
        }

        $known = self::known_source($filename);
        $driveid = self::safe_text($provenance['driveid'] ?? ($known['driveid'] ?? ''), 128);
        $modified = self::safe_text($provenance['modified'] ?? ($known['modified'] ?? ''), 40);
        $digest = hash('sha256', $xml);

        global $SESSION;
        self::cleanup_session();
        $token = bin2hex(random_bytes(16));
        if (!isset($SESSION->{self::SESSION_KEY}) || !is_array($SESSION->{self::SESSION_KEY})) {
            $SESSION->{self::SESSION_KEY} = [];
        }
        $SESSION->{self::SESSION_KEY}[$token] = [
            'companyuuid' => $companyuuid,
            'userid' => $userid,
            'filename' => $filename,
            'driveid' => $driveid,
            'modified' => $modified,
            'digest' => $digest,
            'timecreated' => time(),
            'analysis' => $parsed,
        ];
        return self::review($companyuuid, $userid, $token);
    }

    /** Presentation-safe review data. */
    public static function review(string $companyuuid, int $userid, string $token): array {
        $snapshot = self::snapshot($companyuuid, $userid, $token);
        $parsed = $snapshot['analysis'];
        return [
            'token' => $token,
            'filename' => $snapshot['filename'],
            'driveid' => $snapshot['driveid'],
            'modified' => $snapshot['modified'],
            'digest' => $snapshot['digest'],
            'diagram' => $parsed['diagram'],
            'page' => $parsed['page'],
            'vertexcount' => $parsed['vertexcount'],
            'edgecount' => $parsed['edgecount'],
            'cellcount' => $parsed['cellcount'],
            'ignored' => $parsed['ignored'],
            'bounds' => $parsed['bounds'],
            'companyproduct' => $parsed['companyproduct'],
            'normalization' => $parsed['normalization'],
            'candidates' => array_values($parsed['candidates']),
            'cells' => array_values($parsed['cells']),
            'contract' => self::contract(),
        ];
    }

    /**
     * Build a normalized template envelope from a reviewed mapping.
     * This method does not persist a company OrgDesign project.
     */
    public static function normalized_template(string $companyuuid, int $userid, string $token, array $mapping): array {
        $snapshot = self::snapshot($companyuuid, $userid, $token);
        $analysis = $snapshot['analysis'];
        $candidates = array_values($analysis['candidates']);

        $maptypes = isset($mapping['maptype']) && is_array($mapping['maptype']) ? $mapping['maptype'] : [];
        $names = isset($mapping['name']) && is_array($mapping['name']) ? $mapping['name'] : [];
        $products = isset($mapping['product']) && is_array($mapping['product']) ? $mapping['product'] : [];
        $parents = isset($mapping['parent']) && is_array($mapping['parent']) ? $mapping['parent'] : [];
        $companyproduct = isset($mapping['companyproduct']) ? self::safe_text($mapping['companyproduct'], 4000) :
            self::safe_text($analysis['companyproduct'], 4000);

        $mapped = [];
        foreach ($candidates as $index => $candidate) {
            $type = isset($maptypes[$index]) ? clean_param((string)$maptypes[$index], PARAM_ALPHANUMEXT) :
                (string)$candidate['suggestedtype'];
            if ($type === '' || $type === 'label' || $type === 'unknown') {
                $type = 'ignore';
            }
            if (!in_array($type, self::SEMANTIC_TYPES, true)) {
                throw new \moodle_exception('design_invalid', 'local_kbacompany');
            }
            if ($type === 'ignore') {
                continue;
            }
            $name = isset($names[$index]) ? self::safe_text($names[$index], 255) : self::safe_text($candidate['name'], 255);
            if ($name === '') {
                throw new \moodle_exception('ofslegacyimport_requiredname', 'local_kbacompany');
            }
            $product = isset($products[$index]) ? self::safe_text($products[$index], 4000) :
                self::safe_text($candidate['suggestedproduct'], 4000);
            $parent = isset($parents[$index]) ? self::safe_text($parents[$index], 255) :
                self::safe_text($candidate['suggestedparent'], 255);
            $candidate['maptype'] = $type;
            $candidate['name'] = $name;
            $candidate['product'] = $product;
            $candidate['mappedparent'] = $parent;
            $mapped[$candidate['cellid']] = $candidate;
        }
        if (!$mapped) {
            throw new \moodle_exception('ofslegacyimport_selectone', 'local_kbacompany');
        }

        $validation = self::validate_mapping($mapped, $companyproduct);
        if (!empty($validation['errors'])) {
            throw new \moodle_exception('ofslegacyimport_validationfailed', 'local_kbacompany', '',
                implode('; ', $validation['errors']));
        }

        $companycell = '';
        foreach ($mapped as $cellid => $item) {
            if ($item['maptype'] === 'company') {
                $companycell = $cellid;
                break;
            }
        }

        $nodeids = [];
        $positionids = [];
        foreach ($mapped as $cellid => $item) {
            if (in_array($item['maptype'], self::STRUCTURAL_TYPES, true)) {
                $nodeids[$cellid] = self::uuid();
            } else if ($item['maptype'] === 'position') {
                $positionids[$cellid] = self::uuid();
            }
        }

        $nodes = [];
        $positions = [];
        $functions = [];
        $productsbyparent = [];
        foreach ($mapped as $cellid => $item) {
            if (in_array($item['maptype'], ['product', 'ckp', 'result'], true)) {
                $target = self::nearest_structural_parent($item, $mapped, $nodeids, $companycell);
                $text = $item['product'] !== '' ? $item['product'] : self::product_text($item['rawlabel']);
                if ($text === '') {
                    $text = $item['name'];
                }
                if ($target === $companycell || $target === '') {
                    if ($companyproduct === '') {
                        $companyproduct = $text;
                    }
                } else {
                    $productsbyparent[$target] = $text;
                }
            }
        }

        $sort = 100;
        foreach ($mapped as $cellid => $item) {
            if (!in_array($item['maptype'], self::STRUCTURAL_TYPES, true)) {
                continue;
            }
            $parentcell = self::mapped_structural_parent($item, $mapped, $nodeids, $companycell);
            $parentuuid = isset($nodeids[$parentcell]) ? $nodeids[$parentcell] : '';
            $tier = self::tier_for_mapping($item['maptype'], $item['suggestedtier']);
            $product = $item['product'] !== '' ? $item['product'] : (string)($productsbyparent[$cellid] ?? '');
            $nodes[] = [
                'uuid' => $nodeids[$cellid],
                'sourceuuid' => '',
                'code' => 'LEG-' . str_pad((string)(count($nodes) + 1), 3, '0', STR_PAD_LEFT),
                'name' => $item['name'],
                'type' => self::node_type_from_mapping($item['maptype'], $tier),
                'layout_parent' => $parentuuid,
                'reporting_parent' => $parentuuid,
                'branch' => '',
                'sourcehash' => '',
                'visualtier' => $tier,
                'product' => $product,
                'sortorder' => $sort,
                'color' => self::tier_color($tier),
                'legacycellid' => $cellid,
                'legacygeometry' => $item['geometry'],
            ];
            $sort += 10;
        }

        foreach ($mapped as $cellid => $item) {
            if ($item['maptype'] === 'position') {
                $parentcell = self::nearest_structural_parent($item, $mapped, $nodeids, $companycell);
                if ($parentcell === '' || !isset($nodeids[$parentcell])) {
                    continue;
                }
                $positions[] = [
                    'uuid' => $positionids[$cellid],
                    'nodeuuid' => $nodeids[$parentcell],
                    'code' => 'LEG-POS-' . str_pad((string)(count($positions) + 1), 3, '0', STR_PAD_LEFT),
                    'name' => $item['name'],
                    'product' => $item['product'],
                    'sortorder' => 100 + count($positions) * 10,
                ];
            }
        }

        foreach ($mapped as $cellid => $item) {
            if ($item['maptype'] !== 'function') {
                continue;
            }
            $parentcell = self::nearest_structural_parent($item, $mapped, $nodeids, $companycell);
            if ($parentcell === '' || !isset($nodeids[$parentcell])) {
                continue;
            }
            $positionuuid = '';
            $positioncell = self::nearest_position_parent($item, $mapped, $positionids, $parentcell);
            if ($positioncell !== '' && isset($positionids[$positioncell])) {
                $positionuuid = $positionids[$positioncell];
            }
            $functions[] = [
                'uuid' => self::uuid(),
                'nodeuuid' => $nodeids[$parentcell],
                'positionuuid' => $positionuuid,
                'code' => 'LEG-FN-' . str_pad((string)(count($functions) + 1), 3, '0', STR_PAD_LEFT),
                'name' => $item['name'],
                'result' => $item['product'],
                'sortorder' => 100 + count($functions) * 10,
            ];
        }

        $company = $mapped[$companycell];
        $orgdesign = [
            'schema' => 2,
            'mission' => '',
            'intent' => '',
            'companyproduct' => $companyproduct,
            'showmission' => 1,
            'showintent' => 1,
            'nodes' => $nodes,
            'positions' => $positions,
            'functions' => $functions,
        ];
        return [
            'schema' => 1,
            'kind' => 'normalized_organization_template',
            'status' => 'draft',
            'companyroot' => [
                'legacycellid' => $companycell,
                'name' => $company['name'],
                'rawlabel' => $company['rawlabel'],
            ],
            'orgdesign' => $orgdesign,
            'provenance' => [
                'source_type' => 'legacy_drawio',
                'source_filename' => $snapshot['filename'],
                'source_drive_id' => $snapshot['driveid'],
                'source_modified' => $snapshot['modified'],
                'source_checksum' => $snapshot['digest'],
                'import_date' => time(),
                'parser_version' => self::PARSER_VERSION,
                'mapping_version' => self::MAPPING_VERSION,
            ],
            'normalization' => [
                'source_summary' => $analysis['normalization'],
                'guidance_version' => self::MAPPING_VERSION,
                'human_review_required' => 1,
            ],
            'validation' => $validation,
            'mapping' => self::mapping_snapshot($mapped),
        ];
    }

    /** Persist a normalized template DRAFT and immutable version. */
    public static function save_draft(string $companyuuid, int $userid, string $token, string $name,
            array $mapping): string {
        global $DB;
        self::manage_access($companyuuid, $userid);
        $name = self::safe_text($name, 255);
        if ($name === '') {
            throw new \moodle_exception('ofslegacyimport_requiredname', 'local_kbacompany');
        }
        $snapshot = self::snapshot($companyuuid, $userid, $token);
        $template = self::normalized_template($companyuuid, $userid, $token, $mapping);
        $payload = self::json($template);
        $analysis = self::json(self::analysis_for_storage($snapshot['analysis']));
        $mappingjson = self::json($template['mapping']);
        $now = time();
        $uuid = self::uuid();
        $versionuuid = self::uuid();

        $tx = $DB->start_delegated_transaction();
        $DB->insert_record('local_kbacompany_ofstpl', (object)[
            'companyuuid' => $companyuuid,
            'uuid' => $uuid,
            'name' => $name,
            'status' => 'draft',
            'revision' => 1,
            'sourcetype' => 'legacy_drawio',
            'sourcefilename' => self::safe_text($snapshot['filename'], 255),
            'sourcedriveid' => self::safe_text($snapshot['driveid'], 128),
            'sourcemodified' => self::safe_text($snapshot['modified'], 40),
            'sourcechecksum' => $snapshot['digest'],
            'parserversion' => self::PARSER_VERSION,
            'mappingversion' => self::MAPPING_VERSION,
            'reviewer' => 0,
            'createdby' => $userid,
            'timecreated' => $now,
            'timemodified' => $now,
        ]);
        $DB->insert_record('local_kbacompany_ofstplver', (object)[
            'companyuuid' => $companyuuid,
            'uuid' => $versionuuid,
            'templateuuid' => $uuid,
            'revision' => 1,
            'payload' => $payload,
            'analysis' => $analysis,
            'digest' => hash('sha256', $payload),
            'mappingdigest' => hash('sha256', $mappingjson),
            'createdby' => $userid,
            'timecreated' => $now,
        ]);
        $DB->insert_record('local_kbacompany_aclog', (object)[
            'companyuuid' => $companyuuid, 'objectuuid' => $uuid,
            'action' => 'legacytpl_create', 'actorid' => $userid, 'oldvalue' => null,
            'newvalue' => self::json(['status' => 'draft', 'revision' => 1, 'sourcechecksum' => $snapshot['digest']]),
            'timecreated' => $now,
        ]);
        $tx->allow_commit();
        self::consume($companyuuid, $userid, $token);
        return $uuid;
    }

    /** List company-scoped persisted imported templates. */
    public static function list_templates(string $companyuuid, int $userid, string $status = ''): array {
        global $DB;
        self::manage_access($companyuuid, $userid);
        $params = ['companyuuid' => $companyuuid];
        if ($status !== '') {
            if (!in_array($status, ['draft', 'reviewed', 'available'], true)) {
                throw new \moodle_exception('design_invalid', 'local_kbacompany');
            }
            $params['status'] = $status;
        }
        return array_values($DB->get_records('local_kbacompany_ofstpl', $params, 'timemodified DESC, name ASC'));
    }

    public static function template(string $companyuuid, int $userid, string $templateuuid): \stdClass {
        global $DB;
        self::manage_access($companyuuid, $userid);
        $record = $DB->get_record('local_kbacompany_ofstpl', [
            'companyuuid' => $companyuuid, 'uuid' => $templateuuid,
        ]);
        if (!$record) {
            throw new \moodle_exception('design_reference', 'local_kbacompany');
        }
        return $record;
    }

    public static function template_data(string $companyuuid, int $userid, string $templateuuid): array {
        global $DB;
        $template = self::template($companyuuid, $userid, $templateuuid);
        $version = $DB->get_record('local_kbacompany_ofstplver', [
            'companyuuid' => $companyuuid,
            'templateuuid' => $templateuuid,
            'revision' => (int)$template->revision,
        ]);
        if (!$version || !hash_equals((string)$version->digest, hash('sha256', (string)$version->payload))) {
            throw new \moodle_exception('design_reference', 'local_kbacompany');
        }
        $data = json_decode((string)$version->payload, true);
        if (!is_array($data) || empty($data['orgdesign'])) {
            throw new \moodle_exception('design_reference', 'local_kbacompany');
        }
        return ['record' => $template, 'version' => $version, 'data' => $data];
    }

    /** Controlled status transition. Status changes never mutate immutable payload versions. */
    public static function transition(string $companyuuid, int $userid, string $templateuuid, string $action): string {
        global $DB;
        self::manage_access($companyuuid, $userid);
        $template = self::template($companyuuid, $userid, $templateuuid);
        $next = '';
        if ($action === 'review' && $template->status === 'draft') {
            $next = 'reviewed';
        } else if ($action === 'publish' && $template->status === 'reviewed') {
            $next = 'available';
        } else if ($action === 'return' && $template->status === 'reviewed') {
            $next = 'draft';
        } else if ($action === 'withdraw' && $template->status === 'available') {
            $next = 'reviewed';
        } else {
            throw new \moodle_exception('ofslegacyimport_badtransition', 'local_kbacompany');
        }
        $oldstatus = (string)$template->status;
        $now = time();
        $tx = $DB->start_delegated_transaction();
        $template->status = $next;
        $template->reviewer = $next === 'draft' ? 0 : $userid;
        $template->timemodified = $now;
        $DB->update_record('local_kbacompany_ofstpl', $template);
        $DB->insert_record('local_kbacompany_aclog', (object)[
            'companyuuid' => $companyuuid, 'objectuuid' => $templateuuid,
            'action' => 'legacytpl_status', 'actorid' => $userid,
            'oldvalue' => self::json(['status' => $oldstatus]),
            'newvalue' => self::json(['status' => $next]), 'timecreated' => $now,
        ]);
        $tx->allow_commit();
        return $next;
    }

    /** Return AVAILABLE template data for Template Library / OrgDesign cloning. */
    public static function available_info(string $companyuuid, int $userid, string $templateuuid): array {
        $bundle = self::template_data($companyuuid, $userid, $templateuuid);
        if ($bundle['record']->status !== 'available') {
            throw new \moodle_exception('design_reference', 'local_kbacompany');
        }
        $orgdesign = $bundle['data']['orgdesign'];
        $departments = 0;
        $units = 0;
        $structural = 0;
        $withproduct = 0;
        $levels = [];
        foreach ($orgdesign['nodes'] as $node) {
            $tier = (string)($node['visualtier'] ?? '');
            if ($tier === 'department') { $departments++; }
            if (in_array($tier, ['management_unit', 'unit'], true)) { $units++; }
            if ($tier !== '') { $levels[$tier] = true; }
            $structural++;
            if (trim((string)($node['product'] ?? '')) !== '') { $withproduct++; }
        }
        return [
            'key' => 'import:' . $bundle['record']->uuid,
            'uuid' => $bundle['record']->uuid,
            'name' => $bundle['record']->name,
            'description' => get_string('ofslegacyimport_availabledescription', 'local_kbacompany'),
            'category' => get_string('ofslegacyimport_importedcategory', 'local_kbacompany'),
            'departments' => $departments,
            'management_units' => $units,
            'positions' => count((array)($orgdesign['positions'] ?? [])),
            'functions' => count((array)($orgdesign['functions'] ?? [])),
            'structure_levels' => count($levels),
            'product_coverage' => $structural > 0 ? (int)round(($withproduct / $structural) * 100) : 0,
            'warnings' => count((array)($bundle['data']['validation']['warnings'] ?? [])),
            'revision' => (int)$bundle['record']->revision,
            'companyproduct' => (string)($orgdesign['companyproduct'] ?? ''),
            'provenance' => $bundle['data']['provenance'],
            'orgdesign' => $orgdesign,
        ];
    }

    public static function available_payload(string $companyuuid, int $userid, string $templateuuid): array {
        $info = self::available_info($companyuuid, $userid, $templateuuid);
        return ['payload' => $info['orgdesign'], 'revision' => $info['revision']];
    }

    public static function consume(string $companyuuid, int $userid, string $token): void {
        global $SESSION;
        self::snapshot($companyuuid, $userid, $token);
        unset($SESSION->{self::SESSION_KEY}[$token]);
    }

    /**
     * Parse mxfile/diagram/mxGraphModel/mxCell/mxGeometry into a complete analysis graph.
     */
    public static function parse(string $xml): array {
        if (!class_exists('\\DOMDocument')) {
            throw new \moodle_exception('ofslegacyimport_xmlsupport', 'local_kbacompany');
        }
        self::reject_unsafe_xml($xml);
        $dom = self::load_xml($xml, 'mxfile');
        $diagrams = $dom->getElementsByTagName('diagram');
        if ($diagrams->length < 1) {
            throw new \moodle_exception('ofslegacyimport_invalidxml', 'local_kbacompany');
        }
        /** @var \DOMElement $diagram */
        $diagram = $diagrams->item(0);
        $page = (string)$diagram->getAttribute('name');
        $graphxml = self::diagram_graph_xml($diagram);
        self::reject_unsafe_xml($graphxml);
        $graph = self::load_xml($graphxml, 'mxGraphModel');

        $cells = [];
        foreach ($graph->getElementsByTagName('mxCell') as $cell) {
            if (!($cell instanceof \DOMElement)) {
                continue;
            }
            $cellid = (string)$cell->getAttribute('id');
            if ($cellid === '') {
                continue;
            }
            if (isset($cells[$cellid])) {
                throw new \moodle_exception('ofslegacyimport_duplicatecell', 'local_kbacompany', '', $cellid);
            }
            $geometry = self::geometry($cell);
            $rawlabel = (string)$cell->getAttribute('value');
            $text = self::clean_label($rawlabel);
            $style = (string)$cell->getAttribute('style');
            $cells[$cellid] = [
                'cellid' => $cellid,
                'parent' => (string)$cell->getAttribute('parent'),
                'rawlabel' => $rawlabel,
                'text' => $text,
                'style' => $style,
                'stylemeta' => self::style_meta($style),
                'geometry' => $geometry,
                'x' => $geometry['x'], 'y' => $geometry['y'],
                'w' => $geometry['width'], 'h' => $geometry['height'],
                'vertex' => $cell->getAttribute('vertex') === '1' ? 1 : 0,
                'edge' => $cell->getAttribute('edge') === '1' ? 1 : 0,
                'source' => (string)$cell->getAttribute('source'),
                'target' => (string)$cell->getAttribute('target'),
                'ancestors' => [],
                'incoming' => [],
                'outgoing' => [],
                'suggestedtype' => 'unknown',
                'suggestedtier' => 'unit',
                'confidence' => 0.0,
                'suggestedparent' => '',
                'suggestedproduct' => '',
                'name' => self::short_name($text),
            ];
        }

        // Resolve graph relationships and absolute positions without trusting presentation colors as semantics.
        foreach ($cells as $cellid => $cell) {
            if (!empty($cell['edge'])) {
                if ($cell['source'] !== '' && isset($cells[$cell['source']])) {
                    $cells[$cell['source']]['outgoing'][] = $cellid;
                }
                if ($cell['target'] !== '' && isset($cells[$cell['target']])) {
                    $cells[$cell['target']]['incoming'][] = $cellid;
                }
            }
        }
        foreach (array_keys($cells) as $cellid) {
            $cells[$cellid]['ancestors'] = self::ancestor_ids($cellid, $cells);
            $absolute = self::absolute_geometry($cellid, $cells);
            $cells[$cellid]['x'] = $absolute['x'];
            $cells[$cellid]['y'] = $absolute['y'];
        }

        $vertexcount = 0;
        $edgecount = 0;
        $candidates = [];
        foreach ($cells as $cellid => &$cell) {
            if (!empty($cell['edge'])) {
                $edgecount++;
            }
            if (empty($cell['vertex'])) {
                continue;
            }
            $vertexcount++;
            $suggestion = self::classify($cell);
            $cell['suggestedtype'] = $suggestion['type'];
            $cell['suggestedtier'] = $suggestion['tier'];
            $cell['confidence'] = $suggestion['confidence'];
            $cell['name'] = $suggestion['name'];
            $cell['suggestedproduct'] = $suggestion['product'];
            if ($cell['text'] !== '') {
                $candidates[$cellid] = $cell;
            }
        }
        unset($cell);

        // Infer parents after every cell has a semantic suggestion.
        foreach ($candidates as $cellid => &$candidate) {
            if (in_array($candidate['suggestedtype'], ['ckp', 'product', 'result'], true)) {
                $candidate['suggestedparent'] = self::suggest_product_parent($candidate, $candidates, $cells);
            } else {
                $candidate['suggestedparent'] = self::suggest_parent($candidate, $candidates, $cells);
            }
        }
        unset($candidate);

        $companyproduct = self::infer_company_product($candidates);
        self::attach_product_suggestions($candidates);
        foreach ($candidates as &$candidate) {
            $candidate['normalizationhints'] = self::normalization_hints($candidate);
            $candidate['needsreview'] = self::needs_review($candidate) ? 1 : 0;
        }
        unset($candidate);
        uasort($candidates, static function(array $a, array $b): int {
            $ay = (float)$a['y']; $by = (float)$b['y'];
            if ($ay === $by) {
                $ax = (float)$a['x']; $bx = (float)$b['x'];
                if ($ax === $bx) {
                    return strcmp((string)$a['cellid'], (string)$b['cellid']);
                }
                return $ax < $bx ? -1 : 1;
            }
            return $ay < $by ? -1 : 1;
        });
        $bounds = self::bounds($cells);
        $ignored = 0;
        foreach ($candidates as $candidate) {
            if (in_array($candidate['suggestedtype'], ['label', 'unknown'], true)) {
                $ignored++;
            }
        }
        return [
            'diagram' => (string)$dom->documentElement->getAttribute('version'),
            'page' => $page,
            'cellcount' => count($cells),
            'vertexcount' => $vertexcount,
            'edgecount' => $edgecount,
            'ignored' => $ignored,
            'companyproduct' => $companyproduct,
            'bounds' => $bounds,
            'normalization' => self::normalization_summary($candidates, $companyproduct),
            'cells' => $cells,
            'candidates' => $candidates,
        ];
    }

    private static function classify(array $cell): array {
        $text = trim((string)$cell['text']);
        $upper = \core_text::strtoupper($text);
        $lines = preg_split('/\n+/u', $text);
        $first = trim((string)($lines[0] ?? $text));
        $firstupper = \core_text::strtoupper($first);
        $w = (float)$cell['w'];
        $h = (float)$cell['h'];
        $y = (float)$cell['y'];
        $type = 'unknown';
        $tier = 'unit';
        $confidence = 0.25;
        $product = '';
        $name = self::short_name($text);

        if ($text === '') {
            return ['type' => 'unknown', 'tier' => 'unit', 'confidence' => 0.0, 'name' => '', 'product' => ''];
        }
        if (preg_match('/(?:©|ВСЕ ПРАВА ЗАЩИЩЕНЫ|\bVER\.?\s*\d)/u', $upper)) {
            $type = 'label'; $tier = 'unit'; $confidence = 0.99;
        } else if (preg_match('/ОРГАНИЗУЮЩАЯ\s+СХЕМА/u', $upper)) {
            $type = 'company'; $tier = 'investment'; $confidence = 0.99;
            $name = trim((string)preg_replace('/^.*?ОРГАНИЗУЮЩАЯ\s+СХЕМА\.?\s*/ui', '', $text));
            if ($name === '') { $name = self::short_name($text); }
        } else if (preg_match('/^ЦКП\s+ОТДЕЛЕНИЯ\s*:/u', $upper)) {
            $type = 'ckp'; $tier = 'department'; $confidence = 0.995; $product = self::product_text($text);
            $name = $product !== '' ? $product : get_string('ofslegacyimport_ckp', 'local_kbacompany');
        } else if (preg_match('/^(?:ЦКП|ЦЕННЫЙ\s+КОНЕЧНЫЙ\s+ПРОДУКТ)\s*:/u', $upper)) {
            $type = 'ckp'; $tier = 'unit'; $confidence = 0.99; $product = self::product_text($text);
            $name = $product !== '' ? $product : get_string('ofslegacyimport_ckp', 'local_kbacompany');
        } else if (preg_match('/^(УЧРЕДИТ|ИНВЕСТОР|СОБСТВЕННИК)/u', $firstupper)) {
            $type = 'office'; $tier = 'investment'; $confidence = 0.97;
        } else if (preg_match('/СТРАТЕГИЧЕСК.{0,18}ОФИС/u', $upper)) {
            $type = 'office'; $tier = 'strategic_office'; $confidence = 0.97;
        } else if (preg_match('/УПРАВЛЯЮЩ.{0,18}КОМПАН/u', $upper)) {
            $type = 'office'; $tier = 'management'; $confidence = 0.96;
        } else if (preg_match('/^ИСПОЛНИТЕЛЬН.{0,18}(?:ДИРЕКТОР|СОВЕТ)/u', $firstupper)) {
            $type = 'office'; $tier = 'executive'; $confidence = 0.95;
        } else if (preg_match('/^\d*[\.\s-]*(?:ОТДЕЛЕНИЕ|ДЕПАРТАМЕНТ)(?:\s|$)/u', $firstupper)) {
            $type = 'department'; $tier = 'department'; $confidence = 0.97;
        } else if (preg_match('/^\d*[\.\s-]*ОФИС(?:\s|$)/u', $firstupper)) {
            $type = 'office';
            $tier = preg_match('/УЧРЕДИТ|ИНВЕСТОР|СОБСТВЕННИК/u', $firstupper) ? 'investment' : 'management_unit';
            $confidence = 0.91;
        } else if (preg_match('/^\d*[\.\s-]*(?:ОТДЕЛ|СЛУЖБА|СЕКТОР|СЕКЦИЯ|ФИЛИАЛ|РЕГИОН)(?:\s|$)/u', $firstupper)
                && $w >= 120 && $h >= 30) {
            $type = 'orgunit'; $tier = 'unit';
            $confidence = preg_match('/^\d*[\.\s-]*ОТДЕЛ(?:\s|$)/u', $firstupper) ? 0.93 : 0.88;
        } else if (preg_match('/^(?:НАЧАЛЬНИК|ДИРЕКТОР|РУКОВОДИТЕЛЬ|ПРЕДСЕДАТЕЛЬ|МЕНЕДЖЕР|СПЕЦИАЛИСТ|АДМИНИСТРАТОР|ВРАЧ|ИНЖЕНЕР|БУХГАЛТЕР|МАСТЕР|ПРОРАБ|КООРДИНАТОР)/u', $firstupper)
                && $w <= 900) {
            $type = 'position'; $tier = 'unit'; $confidence = 0.9;
        } else if (preg_match('/(?:НАЧАЛЬНИК|ДИРЕКТОР|РУКОВОДИТЕЛЬ|МЕНЕДЖЕР|СПЕЦИАЛИСТ|АДМИНИСТРАТОР|ВРАЧ|ИНЖЕНЕР|БУХГАЛТЕР|МАСТЕР|ПРОРАБ|КООРДИНАТОР)/u', $upper)
                && $w <= 900 && count($lines) <= 2) {
            $type = 'position'; $tier = 'unit'; $confidence = 0.78;
        } else if (preg_match('/(?:ФУНКЦИ|ОБЕСПЕЧИВАЕТ|ОРГАНИЗУЕТ|КОНТРОЛИРУЕТ|ПРОИЗВОДИТ|УПРАВЛЯЕТ)/u', $upper)
                && \core_text::strlen($text) <= 350) {
            $type = 'function'; $tier = 'unit'; $confidence = 0.68;
        } else if ($w >= 1200 && $h <= 190 && $y > 500) {
            $type = 'result'; $tier = 'unit'; $confidence = 0.62;
        } else if ($w < 130 || $h < 25 || \core_text::strlen($text) < 3) {
            $type = 'label'; $tier = 'unit'; $confidence = 0.75;
        } else if ($w >= 450 && $h >= 60 && \core_text::strlen($text) <= 220) {
            $type = 'orgunit'; $tier = $y < 600 ? 'management_unit' : 'unit'; $confidence = 0.54;
        } else {
            $type = 'label'; $tier = 'unit'; $confidence = 0.44;
        }
        return ['type' => $type, 'tier' => $tier, 'confidence' => $confidence, 'name' => $name, 'product' => $product];
    }

    private static function validate_mapping(array $mapped, string $companyproduct): array {
        $errors = [];
        $warnings = [];
        $companyroots = [];
        foreach ($mapped as $cellid => $item) {
            if ($item['maptype'] === 'company') {
                $companyroots[] = $cellid;
            }
            if ($item['name'] === '') {
                $errors[] = 'Required name missing for cell ' . $cellid;
            }
        }
        if (count($companyroots) !== 1) {
            $errors[] = 'Exactly one company root is required; found ' . count($companyroots);
        }
        $companycell = count($companyroots) === 1 ? $companyroots[0] : '';

        foreach ($mapped as $cellid => $item) {
            if ($item['maptype'] === 'company') {
                continue;
            }
            $parent = (string)$item['mappedparent'];
            if ($parent !== '' && !isset($mapped[$parent])) {
                $errors[] = 'Parent ' . $parent . ' does not exist for cell ' . $cellid;
            }
            if ($parent !== '' && isset($mapped[$parent])) {
                if (in_array($item['maptype'], ['function', 'position'], true) &&
                        !in_array($mapped[$parent]['maptype'], self::STRUCTURAL_TYPES, true)) {
                    $warnings[] = 'Function/position ' . $cellid . ' will be attached to the nearest structural parent.';
                }
            }
        }
        // Detect cycles in explicit mapped-parent graph.
        foreach ($mapped as $cellid => $item) {
            $seen = [];
            $cursor = $cellid;
            while ($cursor !== '' && isset($mapped[$cursor])) {
                if (isset($seen[$cursor])) {
                    $errors[] = 'Parent cycle detected at cell ' . $cursor;
                    break;
                }
                $seen[$cursor] = true;
                $next = (string)$mapped[$cursor]['mappedparent'];
                if ($next === $cursor) {
                    $errors[] = 'Cell ' . $cursor . ' cannot be its own parent.';
                    break;
                }
                $cursor = $next;
            }
        }
        $structuralcount = 0;
        foreach ($mapped as $cellid => $item) {
            if (in_array($item['maptype'], self::STRUCTURAL_TYPES, true)) {
                $structuralcount++;
            }
            if ($item['maptype'] === 'department' && $item['product'] === '') {
                $warnings[] = 'Department ' . $item['name'] . ' has no confirmed CKP/product.';
            }
            if ($item['maptype'] === 'function' && $item['product'] === '') {
                $errors[] = 'Function ' . $item['name'] . ' requires a product/result before normalization.';
            }
        }
        if ($structuralcount === 0) {
            $errors[] = 'At least one office, organizational unit or department is required.';
        }
        $compoundcount = 0;
        foreach ($mapped as $item) {
            if (!empty($item['normalizationhints']['compound'])) { $compoundcount++; }
        }
        if ($compoundcount > 0) {
            $warnings[] = $compoundcount . ' compound legacy blocks contain embedded roles/responsibilities; review splitting in BMS after template creation.';
        }
        if ($companyproduct === '') {
            $warnings[] = 'Company Valuable Final Product is not confirmed.';
        }
        if ($companycell !== '') {
            foreach ($mapped as $cellid => $item) {
                if (in_array($item['maptype'], self::STRUCTURAL_TYPES, true) && $item['mappedparent'] === '') {
                    $item['mappedparent'] = $companycell;
                }
            }
        }
        return ['errors' => array_values(array_unique($errors)), 'warnings' => array_values(array_unique($warnings))];
    }

    private static function mapped_structural_parent(array $item, array $mapped, array $nodeids, string $companycell): string {
        $parent = (string)$item['mappedparent'];
        $guard = 0;
        while ($parent !== '' && isset($mapped[$parent]) && $guard < 100) {
            if (isset($nodeids[$parent])) {
                return $parent;
            }
            if ($parent === $companycell) {
                return '';
            }
            $parent = (string)$mapped[$parent]['mappedparent'];
            $guard++;
        }
        return self::nearest_structural_parent($item, $mapped, $nodeids, $companycell);
    }

    private static function nearest_structural_parent(array $item, array $mapped, array $nodeids, string $companycell): string {
        $explicit = (string)$item['mappedparent'];
        $guard = 0;
        while ($explicit !== '' && isset($mapped[$explicit]) && $guard < 100) {
            if (isset($nodeids[$explicit])) { return $explicit; }
            if ($explicit === $companycell) { return $companycell; }
            $explicit = (string)$mapped[$explicit]['mappedparent'];
            $guard++;
        }
        $best = '';
        $bestscore = null;
        $cx = (float)$item['x'] + ((float)$item['w'] / 2);
        foreach ($mapped as $cellid => $candidate) {
            if (!isset($nodeids[$cellid]) || $cellid === $item['cellid']) { continue; }
            if ((float)$candidate['y'] > (float)$item['y'] + 5) { continue; }
            $ccx = (float)$candidate['x'] + ((float)$candidate['w'] / 2);
            $vertical = max(0.0, (float)$item['y'] - ((float)$candidate['y'] + (float)$candidate['h']));
            $horizontal = abs($cx - $ccx);
            $score = $vertical + ($horizontal * 0.45);
            if ($bestscore === null || $score < $bestscore) {
                $bestscore = $score; $best = $cellid;
            }
        }
        return $best !== '' ? $best : $companycell;
    }

    private static function nearest_position_parent(array $item, array $mapped, array $positionids, string $structuralparent): string {
        $best = '';
        $bestscore = null;
        $cx = (float)$item['x'] + ((float)$item['w'] / 2);
        foreach ($positionids as $cellid => $uuid) {
            if (!isset($mapped[$cellid])) { continue; }
            $candidate = $mapped[$cellid];
            if ((string)$candidate['mappedparent'] !== $structuralparent) { continue; }
            $ccx = (float)$candidate['x'] + ((float)$candidate['w'] / 2);
            $score = abs((float)$item['y'] - (float)$candidate['y']) + abs($cx - $ccx);
            if ($bestscore === null || $score < $bestscore) { $bestscore = $score; $best = $cellid; }
        }
        return $best;
    }

    private static function mapping_snapshot(array $mapped): array {
        $result = [];
        foreach ($mapped as $cellid => $item) {
            $result[] = [
                'legacycellid' => $cellid,
                'type' => $item['maptype'],
                'name' => $item['name'],
                'product' => $item['product'],
                'parent' => $item['mappedparent'],
                'suggested_type' => $item['suggestedtype'],
                'confidence' => $item['confidence'],
                'normalization_hints' => $item['normalizationhints'] ?? [],
            ];
        }
        return $result;
    }

    private static function analysis_for_storage(array $analysis): array {
        return [
            'diagram' => $analysis['diagram'], 'page' => $analysis['page'],
            'cellcount' => $analysis['cellcount'], 'vertexcount' => $analysis['vertexcount'],
            'edgecount' => $analysis['edgecount'], 'bounds' => $analysis['bounds'],
            'companyproduct' => $analysis['companyproduct'], 'normalization' => $analysis['normalization'],
            'cells' => array_values($analysis['cells']), 'candidates' => array_values($analysis['candidates']),
        ];
    }

    /** Prefer semantic band + horizontal alignment for CKP/product cells. */
    private static function suggest_product_parent(array $item, array $candidates, array $cells): string {
        $companycell = '';
        foreach ($candidates as $cellid => $candidate) {
            if ($candidate['suggestedtype'] === 'company') { $companycell = $cellid; break; }
        }
        $upper = \core_text::strtoupper((string)$item['text']);
        $isdepartmentckp = preg_match('/^ЦКП\s+ОТДЕЛЕНИЯ\s*:/u', $upper) === 1;
        $iswidecompany = preg_match('/^(?:ЦКП|ЦЕННЫЙ\s+КОНЕЧНЫЙ\s+ПРОДУКТ)\s*:/u', $upper) === 1 &&
            (float)$item['w'] >= 1000.0;
        if ($iswidecompany && $companycell !== '') { return $companycell; }

        // A connector is strong evidence, but it still has to land on a plausible structural object.
        foreach ($item['incoming'] as $edgeid) {
            if (!isset($cells[$edgeid])) { continue; }
            $source = (string)$cells[$edgeid]['source'];
            if ($source === '' || !isset($candidates[$source])) { continue; }
            $stype = (string)$candidates[$source]['suggestedtype'];
            if ($isdepartmentckp && $stype === 'department') { return $source; }
            if (!$isdepartmentckp && in_array($stype, self::STRUCTURAL_TYPES, true)) { return $source; }
        }

        $best = '';
        $bestscore = null;
        $ix1 = (float)$item['x'];
        $ix2 = $ix1 + max(1.0, (float)$item['w']);
        $icx = ($ix1 + $ix2) / 2.0;
        foreach ($candidates as $cellid => $candidate) {
            if ((float)$candidate['y'] >= (float)$item['y']) { continue; }
            $ctype = (string)$candidate['suggestedtype'];
            if ($isdepartmentckp) {
                if ($ctype !== 'department') { continue; }
            } else if (!in_array($ctype, self::STRUCTURAL_TYPES, true)) {
                continue;
            }
            $cx1 = (float)$candidate['x'];
            $cx2 = $cx1 + max(1.0, (float)$candidate['w']);
            $ccx = ($cx1 + $cx2) / 2.0;
            $overlap = max(0.0, min($ix2, $cx2) - max($ix1, $cx1));
            $ratio = $overlap / max(1.0, min($ix2 - $ix1, $cx2 - $cx1));
            $vertical = max(0.0, (float)$item['y'] - ((float)$candidate['y'] + (float)$candidate['h']));
            $horizontal = abs($icx - $ccx);
            $firstline = trim((string)(preg_split('/\n+/u', (string)$candidate['text'])[0] ?? ''));
            $firstupper = \core_text::strtoupper($firstline);
            $bandbonus = 0.0;
            if ($isdepartmentckp) {
                $bandbonus = -5000.0;
            } else if (preg_match('/^\d*[\.\s-]*ОТДЕЛ(?:\s|$)/u', $firstupper)) {
                $bandbonus = -2400.0;
            } else if (preg_match('/^\d*[\.\s-]*ОФИС(?:\s|$)/u', $firstupper)) {
                $bandbonus = -1800.0;
            } else if (preg_match('/^\d*[\.\s-]*СЛУЖБА(?:\s|$)/u', $firstupper)) {
                $bandbonus = -900.0;
            } else if (preg_match('/^\d*[\.\s-]*СЕКЦИЯ(?:\s|$)/u', $firstupper)) {
                $bandbonus = 250.0;
            }
            $score = $vertical + ($horizontal * 0.35) - ($ratio * 1800.0) + $bandbonus;
            if ($bestscore === null || $score < $bestscore) { $bestscore = $score; $best = $cellid; }
        }
        if ($best !== '') { return $best; }
        return self::suggest_parent($item, $candidates, $cells);
    }

    /** Diagnostics for cells that contain more business meaning than one legacy rectangle can safely represent. */
    private static function normalization_hints(array $candidate): array {
        $lines = preg_split('/\n+/u', trim((string)$candidate['text']));
        $clean = [];
        foreach ($lines as $line) {
            $line = trim((string)$line);
            if ($line !== '') { $clean[] = $line; }
        }
        $roles = [];
        $responsibilities = [];
        foreach (array_slice($clean, 1) as $line) {
            $upper = \core_text::strtoupper($line);
            if (preg_match('/^(?:АДМИНИСТРАТОР|НАЧАЛЬНИК|ДИРЕКТОР|РУКОВОДИТЕЛЬ|ПРЕДСЕДАТЕЛЬ|СПЕЦИАЛИСТ|МЕНЕДЖЕР|СЕКРЕТАРЬ|ИНСТРУКТОР|БУХГАЛТЕР|ЮРИСТ|ИНЖЕНЕР|ПРОРАБ|ВРАЧ|ДИЗАЙНЕР|ФОТОГРАФ|ВИДЕООПЕРАТОР|ВОДИТЕЛЬ|ГРУЗЧИК|РАБОЧИЙ|САДОВНИК|СИСТЕМНЫЙ\s+АДМИНИСТРАТОР)/u', $upper)) {
                $roles[] = self::safe_text($line, 255);
            } else if (preg_match('/^(?:ОТВ\.?\s*ЗА|ОТВЕТСТВЕНН)/u', $upper)) {
                $responsibilities[] = self::safe_text($line, 255);
            }
        }
        $compound = in_array($candidate['suggestedtype'], self::STRUCTURAL_TYPES, true) &&
            count($clean) > 1 && (count($roles) > 0 || count($responsibilities) > 0);
        $codes = [];
        if ($compound) { $codes[] = 'compound_block'; }
        if ($roles) { $codes[] = 'embedded_roles'; }
        if ($responsibilities) { $codes[] = 'embedded_responsibilities'; }
        if (in_array($candidate['suggestedtype'], self::STRUCTURAL_TYPES, true) && $candidate['suggestedproduct'] === '') {
            $codes[] = 'product_missing';
        }
        if ((float)$candidate['confidence'] < 0.75) { $codes[] = 'low_confidence'; }
        return [
            'compound' => $compound ? 1 : 0,
            'linecount' => count($clean),
            'rolelines' => array_values(array_unique($roles)),
            'responsibilitylines' => array_values(array_unique($responsibilities)),
            'codes' => array_values(array_unique($codes)),
        ];
    }

    private static function needs_review(array $candidate): bool {
        $hints = isset($candidate['normalizationhints']) && is_array($candidate['normalizationhints']) ?
            $candidate['normalizationhints'] : [];
        if (in_array($candidate['suggestedtype'], ['label', 'unknown'], true)) { return true; }
        if ((float)$candidate['confidence'] < 0.80) { return true; }
        if (!empty($hints['compound'])) { return true; }
        if (in_array('product_missing', (array)($hints['codes'] ?? []), true)) { return true; }
        return false;
    }

    private static function normalization_summary(array $candidates, string $companyproduct): array {
        $types = [];
        $businessobjects = 0; $review = 0; $compound = 0; $products = 0;
        $structural = 0; $withproduct = 0;
        foreach ($candidates as $candidate) {
            $type = (string)$candidate['suggestedtype'];
            $types[$type] = isset($types[$type]) ? $types[$type] + 1 : 1;
            if (!in_array($type, ['label', 'unknown'], true)) { $businessobjects++; }
            if (!empty($candidate['needsreview'])) { $review++; }
            if (!empty($candidate['normalizationhints']['compound'])) { $compound++; }
            if (in_array($type, ['product', 'ckp', 'result'], true)) { $products++; }
            if (in_array($type, self::STRUCTURAL_TYPES, true)) {
                $structural++;
                if ((string)$candidate['suggestedproduct'] !== '') { $withproduct++; }
            }
        }
        ksort($types);
        return [
            'businessobjects' => $businessobjects,
            'needsreview' => $review,
            'compoundblocks' => $compound,
            'productcells' => $products,
            'structuralobjects' => $structural,
            'structuralwithproduct' => $withproduct,
            'productcoverage' => $structural > 0 ? (int)round(($withproduct / $structural) * 100) : 0,
            'companyproduct' => $companyproduct !== '' ? 1 : 0,
            'types' => $types,
        ];
    }

    private static function suggest_parent(array $item, array $candidates, array $cells): string {
        // Prefer an explicit Draw.io ancestor with a meaningful semantic suggestion.
        foreach (array_reverse($item['ancestors']) as $ancestor) {
            if (isset($candidates[$ancestor]) && !in_array($candidates[$ancestor]['suggestedtype'], ['label', 'unknown'], true)) {
                return $ancestor;
            }
        }
        // Then prefer connected source nodes above the current object.
        foreach ($item['incoming'] as $edgeid) {
            if (!isset($cells[$edgeid])) { continue; }
            $source = (string)$cells[$edgeid]['source'];
            if ($source !== '' && isset($candidates[$source])) { return $source; }
        }
        // Finally infer by layout, avoiding product/result/label nodes as parents.
        $best = '';
        $bestscore = null;
        $cx = (float)$item['x'] + ((float)$item['w'] / 2);
        foreach ($candidates as $cellid => $candidate) {
            if ($cellid === $item['cellid'] || (float)$candidate['y'] >= (float)$item['y']) { continue; }
            if (!in_array($candidate['suggestedtype'], ['company', 'office', 'orgunit', 'department', 'position'], true)) { continue; }
            $ccx = (float)$candidate['x'] + ((float)$candidate['w'] / 2);
            $vertical = max(0.0, (float)$item['y'] - ((float)$candidate['y'] + (float)$candidate['h']));
            $horizontal = abs($cx - $ccx);
            $score = $vertical + $horizontal * 0.5;
            if ($bestscore === null || $score < $bestscore) { $bestscore = $score; $best = $cellid; }
        }
        return $best;
    }

    private static function attach_product_suggestions(array &$candidates): void {
        foreach ($candidates as $productid => $product) {
            if (!in_array($product['suggestedtype'], ['ckp', 'product', 'result'], true)) { continue; }
            $text = $product['suggestedproduct'] !== '' ? $product['suggestedproduct'] : self::product_text($product['rawlabel']);
            if ($text === '') { continue; }
            $target = (string)$product['suggestedparent'];
            $guard = 0;
            while ($target !== '' && isset($candidates[$target]) && $guard < 50) {
                if (in_array($candidates[$target]['suggestedtype'], ['department', 'orgunit', 'office'], true)) {
                    if ($candidates[$target]['suggestedproduct'] === '') {
                        $candidates[$target]['suggestedproduct'] = $text;
                    }
                    break;
                }
                $target = (string)$candidates[$target]['suggestedparent'];
                $guard++;
            }
        }
    }

    private static function infer_company_product(array $candidates): string {
        $best = '';
        $bestwidth = -1.0;
        $besty = -1.0;
        foreach ($candidates as $candidate) {
            if ($candidate['suggestedtype'] !== 'ckp' || !preg_match('/^ЦКП\s*:/ui', $candidate['text'])) { continue; }
            $w = (float)$candidate['w']; $y = (float)$candidate['y'];
            if ($w > $bestwidth || ($w === $bestwidth && $y > $besty)) {
                $best = $candidate['suggestedproduct'] !== '' ? $candidate['suggestedproduct'] : self::product_text($candidate['text']);
                $bestwidth = $w; $besty = $y;
            }
        }
        return $best;
    }

    private static function bounds(array $cells): array {
        $minx = null; $miny = null; $maxx = 0.0; $maxy = 0.0;
        foreach ($cells as $cell) {
            if (empty($cell['vertex'])) { continue; }
            $x = (float)$cell['x']; $y = (float)$cell['y']; $w = (float)$cell['w']; $h = (float)$cell['h'];
            if ($minx === null || $x < $minx) { $minx = $x; }
            if ($miny === null || $y < $miny) { $miny = $y; }
            $maxx = max($maxx, $x + $w); $maxy = max($maxy, $y + $h);
        }
        return ['x' => $minx === null ? 0.0 : $minx, 'y' => $miny === null ? 0.0 : $miny,
            'width' => max(1.0, $maxx - ($minx === null ? 0.0 : $minx)),
            'height' => max(1.0, $maxy - ($miny === null ? 0.0 : $miny))];
    }

    private static function style_meta(string $style): array {
        $wanted = ['fillColor', 'strokeColor', 'fontColor', 'fontSize', 'fontStyle', 'align', 'verticalAlign', 'rounded', 'shape'];
        $meta = [];
        foreach (explode(';', $style) as $part) {
            if (strpos($part, '=') === false) { continue; }
            list($key, $value) = array_pad(explode('=', $part, 2), 2, '');
            if (in_array($key, $wanted, true)) { $meta[$key] = $value; }
        }
        return $meta;
    }

    private static function geometry(\DOMElement $cell): array {
        foreach ($cell->childNodes as $child) {
            if ($child instanceof \DOMElement && $child->nodeName === 'mxGeometry') {
                return [
                    'x' => self::float_attr($child, 'x'), 'y' => self::float_attr($child, 'y'),
                    'width' => self::float_attr($child, 'width'), 'height' => self::float_attr($child, 'height'),
                    'relative' => $child->getAttribute('relative') === '1' ? 1 : 0,
                ];
            }
        }
        return ['x' => 0.0, 'y' => 0.0, 'width' => 0.0, 'height' => 0.0, 'relative' => 0];
    }

    private static function float_attr(\DOMElement $element, string $name): float {
        $value = (string)$element->getAttribute($name);
        return is_numeric($value) ? (float)$value : 0.0;
    }

    private static function absolute_geometry(string $cellid, array $cells): array {
        if (!isset($cells[$cellid])) { return ['x' => 0.0, 'y' => 0.0]; }
        $x = (float)$cells[$cellid]['geometry']['x'];
        $y = (float)$cells[$cellid]['geometry']['y'];
        $parent = (string)$cells[$cellid]['parent'];
        $seen = [$cellid => true];
        $guard = 0;
        while ($parent !== '' && isset($cells[$parent]) && $guard < 100) {
            if (isset($seen[$parent])) { break; }
            $seen[$parent] = true;
            if (!empty($cells[$parent]['vertex'])) {
                $x += (float)$cells[$parent]['geometry']['x'];
                $y += (float)$cells[$parent]['geometry']['y'];
            }
            $parent = (string)$cells[$parent]['parent'];
            $guard++;
        }
        return ['x' => $x, 'y' => $y];
    }

    private static function ancestor_ids(string $cellid, array $cells): array {
        $ids = [];
        if (!isset($cells[$cellid])) { return $ids; }
        $parent = (string)$cells[$cellid]['parent'];
        $seen = [$cellid => true];
        $guard = 0;
        while ($parent !== '' && isset($cells[$parent]) && $guard < 100) {
            if (isset($seen[$parent])) { break; }
            $ids[] = $parent; $seen[$parent] = true;
            $parent = (string)$cells[$parent]['parent']; $guard++;
        }
        return $ids;
    }

    private static function diagram_graph_xml(\DOMElement $diagram): string {
        foreach ($diagram->childNodes as $child) {
            if ($child instanceof \DOMElement && $child->nodeName === 'mxGraphModel') {
                return (string)$diagram->ownerDocument->saveXML($child);
            }
        }
        return self::decode_diagram((string)$diagram->textContent);
    }

    private static function decode_diagram(string $content): string {
        $content = trim($content);
        if ($content === '') {
            throw new \moodle_exception('ofslegacyimport_invalidxml', 'local_kbacompany');
        }
        if (strpos($content, '<mxGraphModel') === 0) { return $content; }
        $decoded = base64_decode($content, true);
        if ($decoded === false) { throw new \moodle_exception('ofslegacyimport_invalidxml', 'local_kbacompany'); }
        $inflated = @gzinflate($decoded, 8388608);
        if ($inflated === false) { throw new \moodle_exception('ofslegacyimport_invalidxml', 'local_kbacompany'); }
        return rawurldecode($inflated);
    }

    private static function load_xml(string $xml, string $root): \DOMDocument {
        $dom = new \DOMDocument();
        $previous = libxml_use_internal_errors(true);
        $ok = $dom->loadXML($xml, LIBXML_NONET | LIBXML_NOBLANKS | LIBXML_NOCDATA);
        libxml_clear_errors(); libxml_use_internal_errors($previous);
        if (!$ok || !$dom->documentElement || $dom->documentElement->nodeName !== $root) {
            throw new \moodle_exception('ofslegacyimport_invalidxml', 'local_kbacompany');
        }
        return $dom;
    }

    private static function reject_unsafe_xml(string $xml): void {
        if (stripos($xml, '<!DOCTYPE') !== false || stripos($xml, '<!ENTITY') !== false) {
            throw new \moodle_exception('ofslegacyimport_invalidxml', 'local_kbacompany');
        }
    }

    private static function clean_label(string $value): string {
        $value = str_ireplace(['<br>', '<br/>', '<br />', '</div>', '</p>', '</li>'], "\n", $value);
        $value = html_entity_decode(strip_tags($value), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $value = preg_replace('/[\x{00A0}\t\r]+/u', ' ', $value);
        $value = preg_replace('/ *\n */u', "\n", (string)$value);
        $value = preg_replace('/ {2,}/u', ' ', (string)$value);
        $value = preg_replace('/\n{2,}/u', "\n", (string)$value);
        return trim((string)$value);
    }

    private static function product_text(string $label): string {
        $text = preg_replace('/^(?:ЦКП(?:\s+ОТДЕЛЕНИЯ)?|ЦЕННЫЙ\s+КОНЕЧНЫЙ\s+ПРОДУКТ)\s*:\s*/ui', '', self::clean_label($label));
        $text = preg_replace('/\s+/u', ' ', trim((string)$text));
        return self::safe_text($text, 4000);
    }

    private static function short_name(string $label): string {
        $parts = preg_split('/\n+/u', $label);
        $name = trim((string)($parts[0] ?? $label));
        $name = preg_replace('/^\d+[\.\s-]*/u', '', (string)$name);
        $name = preg_replace('/\s+РУКОВОДИТЕЛЬ.*$/ui', '', (string)$name);
        return self::safe_text($name === '' ? $label : $name, 255);
    }

    private static function tier_for_mapping(string $type, string $suggested): string {
        if ($type === 'department') { return 'department'; }
        if ($type === 'office') {
            return in_array($suggested, ['investment', 'strategic_office', 'management', 'executive'], true) ? $suggested : 'management_unit';
        }
        return in_array($suggested, ['management_unit', 'unit'], true) ? $suggested : 'unit';
    }

    private static function node_type_from_mapping(string $type, string $tier): string {
        if ($type === 'department') { return 'department'; }
        if ($type === 'office') { return 'office'; }
        return $tier === 'unit' ? 'section' : 'office';
    }

    private static function tier_color(string $tier): string {
        return in_array($tier, ['investment', 'strategic_office', 'management', 'executive'], true) ? '#E8B632' : '#DCE6EE';
    }

    private static function known_source(string $filename): array {
        return self::KNOWN_SOURCES[$filename] ?? [];
    }

    private static function manage_access(string $companyuuid, int $userid): void {
        if (!product_service::can_manage($companyuuid, $userid)) {
            throw new \required_capability_exception(\context_system::instance(),
                'local/kbacompany:manageorganization', 'nopermissions', '');
        }
    }

    private static function snapshot(string $companyuuid, int $userid, string $token): array {
        global $SESSION;
        self::cleanup_session();
        if (!preg_match('/^[a-f0-9]{32}$/', $token) || empty($SESSION->{self::SESSION_KEY}[$token])) {
            throw new \moodle_exception('ofslegacyimport_expired', 'local_kbacompany');
        }
        $snapshot = $SESSION->{self::SESSION_KEY}[$token];
        if ((string)$snapshot['companyuuid'] !== $companyuuid || (int)$snapshot['userid'] !== $userid) {
            throw new \moodle_exception('ofslegacyimport_expired', 'local_kbacompany');
        }
        return $snapshot;
    }

    private static function cleanup_session(): void {
        global $SESSION;
        if (!isset($SESSION->{self::SESSION_KEY}) || !is_array($SESSION->{self::SESSION_KEY})) { return; }
        foreach ($SESSION->{self::SESSION_KEY} as $token => $snapshot) {
            if (empty($snapshot['timecreated']) || ((int)$snapshot['timecreated'] + self::TOKEN_TTL) < time()) {
                unset($SESSION->{self::SESSION_KEY}[$token]);
            }
        }
    }

    private static function safe_text($value, int $max): string {
        $text = trim(clean_param((string)$value, PARAM_TEXT));
        if (\core_text::strlen($text) > $max) { $text = \core_text::substr($text, 0, $max); }
        return $text;
    }

    private static function json(array $data): string {
        $json = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($json === false) { throw new \moodle_exception('design_invalid', 'local_kbacompany'); }
        return $json;
    }

    private static function uuid(): string {
        $h = bin2hex(random_bytes(16));
        return substr($h, 0, 8) . '-' . substr($h, 8, 4) . '-4' . substr($h, 13, 3) . '-8' . substr($h, 17, 3) . '-' . substr($h, 20);
    }
}
