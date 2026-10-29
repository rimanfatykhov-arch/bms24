<?php
namespace local_kbacompany\local;

defined('MOODLE_INTERNAL') || die();

/** Versioned SIPOC + controlled BPMN subset for one canonical BMS Process. */
class process_model_service {
    public const CONTRACT_VERSION = '0.4.4';
    public const MODEL_STATES = ['draft', 'published'];
    public const ELEMENT_TYPES = ['input', 'output', 'lane', 'start', 'end', 'task', 'gateway', 'event', 'document', 'system', 'resource', 'record', 'control'];
    public const ICOM_TYPES = ['input', 'control', 'output', 'resource'];
    public const BPMN_NODE_TYPES = ['start', 'end', 'task', 'gateway', 'event'];
    public const EXECUTOR_TYPES = ['orgunit', 'position', 'role'];
    private const BPMN_FILEAREA = 'bpmnxml';
    private const BPMN_FILENAME = 'diagram.bpmn';
    private const BPMN_XML_MAX = 3000000;

    public static function contract(): array {
        $contract = [
            'component' => 'local_kbacompany',
            'contract' => 'bms_process_model',
            'version' => self::CONTRACT_VERSION,
            'company_scope' => 'required',
            'standard' => 'SIPOC + BPMN -> STP',
            'model_states' => self::MODEL_STATES,
            'element_types' => self::ELEMENT_TYPES,
            'idef0_icom_types' => self::ICOM_TYPES,
            'bpmn_nodes' => self::BPMN_NODE_TYPES,
            'executor_types' => self::EXECUTOR_TYPES,
            'canonical_projection' => [
                'supplier' => 'semantic relation: orgunit supplies process',
                'customer' => 'semantic relation: process supplies orgunit',
                'primary_output' => 'process.productuuid',
                'owner' => 'process.ownerorgunituuid',
                'metrics' => 'semantic relation: metric measures process',
            ],
            'rules' => [
                'one_draft_per_process',
                'published_model_is_immutable',
                'new_draft_clones_last_published_snapshot',
                'task_requires_action_executor_and_output',
                'flow_endpoints_are_bpmn_nodes_in_same_model',
                'sipoc_requires_supplier_input_output_customer_on_publish',
                'bpmn_requires_start_task_end_on_publish',
                'all_bpmn_nodes_must_be_reachable_from_start_and_reach_end',
                'activity_is_semantic_task_not_event_or_gateway',
                'documents_and_systems_are_process_references_until_document_control_release',
                'stp_is_generated_from_process_model',
                'raci_is_version_scoped_to_process_model',
                'bpmn_xml_is_version_scoped_layout_over_canonical_structural_model',
                'visual_bpmn_editor_may_change_di_layout_only',
                'external_bpmn_roundtrip_requires_exact_canonical_semantics',
                'bpmn_layout_reset_regenerates_from_canonical_structure',
                'idef0_icom_crud_reuses_canonical_procelem_types_only',
                'idef0_primary_output_remains_process_product',
                'idef0_input_may_reference_canonical_product_for_balancing',
                'idef0_sipoc_consistency_is_read_only_over_same_canonical_process_model',
                'responsible_is_derived_from_task_executor_and_may_be_extended_explicitly',
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

    private static function authorise(string $companyuuid, int $userid): void {
        company_context_service::context($companyuuid, $userid);
        require_capability('local/kbacompany:viewowncompany', \context_system::instance(), $userid);
        if (!self::can_manage($companyuuid, $userid)) {
            throw new \required_capability_exception(\context_system::instance(),
                'local/kbacompany:manageorganization', 'nopermissions', '');
        }
    }

    private static function fail(string $key): void {
        throw new \moodle_exception('procmodel_' . $key, 'local_kbacompany');
    }

    private static function uuid(): string {
        $bytes = random_bytes(16);
        $bytes[6] = chr((ord($bytes[6]) & 0x0f) | 0x40);
        $bytes[8] = chr((ord($bytes[8]) & 0x3f) | 0x80);
        $hex = bin2hex($bytes);
        return substr($hex, 0, 8) . '-' . substr($hex, 8, 4) . '-' . substr($hex, 12, 4) .
            '-' . substr($hex, 16, 4) . '-' . substr($hex, 20);
    }

    private static function clean(array $data, string $field, int $max, bool $required = false): string {
        $value = trim((string)($data[$field] ?? ''));
        if (($required && $value === '') || \core_text::strlen($value) > $max) {
            self::fail('invalid');
        }
        return $value;
    }

    private static function process(string $companyuuid, int $userid, string $processuuid, bool $requirepublished = false): \stdClass {
        global $DB;
        $canmanage = self::can_manage($companyuuid, $userid);
        // A reader never fetches draft content, even as an intermediate latest-version check.
        if (!$canmanage && !$requirepublished) {
            $visible = $DB->get_records_sql("SELECT p.* FROM {local_kbacompany_process} p
                WHERE p.companyuuid=:company AND p.uuid=:process AND p.status='published'
                AND NOT EXISTS (SELECT 1 FROM {local_kbacompany_process} newer
                    WHERE newer.companyuuid=p.companyuuid AND newer.uuid=p.uuid
                    AND newer.revision>p.revision AND newer.status IN ('published','archived'))",
                ['company'=>$companyuuid,'process'=>$processuuid],0,1);
            if (!$visible) { self::fail('process'); }
            return reset($visible);
        }
        $latest=$DB->get_records('local_kbacompany_process',['companyuuid'=>$companyuuid,'uuid'=>$processuuid],'revision DESC','*',0,1);
        if (!$latest || reset($latest)->status==='archived') { self::fail('process'); }
        $row=reset($latest);
        if ($requirepublished && $row->status!=='published') { self::fail('processnotpublished'); }
        return $row;
    }

    private static function latest_models(string $companyuuid, string $processuuid): array {
        global $DB;
        return array_values($DB->get_records('local_kbacompany_procmodel',
            ['companyuuid' => $companyuuid, 'processuuid' => $processuuid], 'revision DESC'));
    }

    private static function visible_model(string $companyuuid, string $processuuid, bool $canmanage): ?\stdClass {
        global $DB;
        if ($canmanage) { $draft=self::draft($companyuuid,$processuuid); if ($draft) { return $draft; } }
        $rows=$DB->get_records('local_kbacompany_procmodel',['companyuuid'=>$companyuuid,'processuuid'=>$processuuid,'status'=>'published'],'revision DESC','*',0,1);
        return $rows?reset($rows):null;
    }

    private static function draft(string $companyuuid, string $processuuid): ?\stdClass {
        global $DB;
        $rows = $DB->get_records('local_kbacompany_procmodel',
            ['companyuuid' => $companyuuid, 'processuuid' => $processuuid, 'status' => 'draft'],
            'revision DESC', '*', 0, 1);
        return $rows ? reset($rows) : null;
    }

    private static function model_by_id(string $companyuuid, string $processuuid, int $modelid): \stdClass {
        global $DB;
        $model = $DB->get_record('local_kbacompany_procmodel', [
            'id' => $modelid, 'companyuuid' => $companyuuid, 'processuuid' => $processuuid,
        ]);
        if (!$model) { self::fail('model'); }
        return $model;
    }

    private static function require_draft(string $companyuuid, string $processuuid, int $modelid,
            int $expectedchangeversion): \stdClass {
        $model = self::model_by_id($companyuuid, $processuuid, $modelid);
        if ($model->status !== 'draft') { self::fail('immutable'); }
        if ((int)$model->changeversion !== $expectedchangeversion) { self::fail('conflict'); }
        return $model;
    }

    private static function bump(\stdClass $model, int $actor): void {
        global $DB;
        $model->changeversion = (int)$model->changeversion + 1;
        $model->timemodified = time();
        $model->modifiedby = $actor;
        $DB->update_record('local_kbacompany_procmodel', $model);
    }

    private static function bpmn_node_id(string $uuid): string {
        return 'BMS_' . preg_replace('/[^A-Za-z0-9_]/', '', str_replace('-', '', $uuid));
    }

    private static function bpmn_flow_id(string $uuid): string {
        return 'BMSF_' . preg_replace('/[^A-Za-z0-9_]/', '', str_replace('-', '', $uuid));
    }

    private static function bpmn_lane_id(string $uuid): string {
        return 'BMSL_' . preg_replace('/[^A-Za-z0-9_]/', '', str_replace('-', '', $uuid));
    }

    private static function xml(string $value): string {
        return htmlspecialchars($value, ENT_QUOTES | ENT_XML1, 'UTF-8');
    }

    private static function bpmn_tag(string $type): string {
        $map = ['start'=>'startEvent','end'=>'endEvent','task'=>'task','gateway'=>'exclusiveGateway','event'=>'intermediateCatchEvent'];
        if (!isset($map[$type])) { self::fail('bpmnsemantic'); }
        return $map[$type];
    }

    private static function bpmn_file(int $modelid) {
        $fs = get_file_storage();
        return $fs->get_file(\context_system::instance()->id, 'local_kbacompany', self::BPMN_FILEAREA,
            $modelid, '/', self::BPMN_FILENAME);
    }

    private static function clear_bpmn_file(int $modelid): void {
        $file = self::bpmn_file($modelid);
        if ($file) { $file->delete(); }
    }

    private static function store_bpmn_file(int $modelid, int $userid, string $xml): void {
        self::clear_bpmn_file($modelid);
        $record = [
            'contextid' => \context_system::instance()->id,
            'component' => 'local_kbacompany', 'filearea' => self::BPMN_FILEAREA,
            'itemid' => $modelid, 'filepath' => '/', 'filename' => self::BPMN_FILENAME,
            'userid' => $userid, 'timecreated' => time(), 'timemodified' => time(),
        ];
        get_file_storage()->create_file_from_string($record, $xml);
    }

    private static function clone_bpmn_file(int $frommodelid, int $tomodelid, int $userid): void {
        $source = self::bpmn_file($frommodelid);
        if (!$source) { return; }
        $record = [
            'contextid' => \context_system::instance()->id,
            'component' => 'local_kbacompany', 'filearea' => self::BPMN_FILEAREA,
            'itemid' => $tomodelid, 'filepath' => '/', 'filename' => self::BPMN_FILENAME,
            'userid' => $userid, 'timecreated' => time(), 'timemodified' => time(),
        ];
        get_file_storage()->create_file_from_storedfile($record, $source);
    }

    /** Generate deterministic BPMN 2.0 XML from the existing structural model. Layout is derived, semantics stay canonical. */
    private static function generate_bpmn_xml(string $companyuuid, int $modelid, string $processname): string {
        global $DB;
        $nodes = array_values($DB->get_records_select('local_kbacompany_procelem',
            'companyuuid = :company AND modelid = :model AND active = 1 AND elementtype IN (\'start\',\'end\',\'task\',\'gateway\',\'event\')',
            ['company'=>$companyuuid,'model'=>$modelid], 'sortorder ASC, id ASC'));
        $lanes = array_values($DB->get_records('local_kbacompany_procelem',
            ['companyuuid'=>$companyuuid,'modelid'=>$modelid,'elementtype'=>'lane','active'=>1], 'sortorder ASC, id ASC'));
        $flows = array_values($DB->get_records('local_kbacompany_procflow',
            ['companyuuid'=>$companyuuid,'modelid'=>$modelid,'active'=>1], 'sortorder ASC, id ASC'));
        $processid = 'BMS_Process_' . $modelid;
        $xml = '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
        $xml .= '<bpmn:definitions xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance" xmlns:bpmn="http://www.omg.org/spec/BPMN/20100524/MODEL" xmlns:bpmndi="http://www.omg.org/spec/BPMN/20100524/DI" xmlns:dc="http://www.omg.org/spec/DD/20100524/DC" xmlns:di="http://www.omg.org/spec/DD/20100524/DI" id="Definitions_' . $modelid . '" targetNamespace="https://bms24.local/bpmn">' . "\n";
        $xml .= '  <bpmn:process id="' . $processid . '" name="' . self::xml($processname) . '" isExecutable="false">' . "\n";
        if ($lanes) {
            $xml .= '    <bpmn:laneSet id="LaneSet_' . $modelid . '">' . "\n";
            foreach ($lanes as $lane) {
                $xml .= '      <bpmn:lane id="' . self::bpmn_lane_id((string)$lane->uuid) . '" name="' . self::xml((string)$lane->name) . '">' . "\n";
                foreach ($nodes as $node) {
                    if ((string)$node->laneuuid === (string)$lane->uuid) {
                        $xml .= '        <bpmn:flowNodeRef>' . self::bpmn_node_id((string)$node->uuid) . '</bpmn:flowNodeRef>' . "\n";
                    }
                }
                $xml .= "      </bpmn:lane>\n";
            }
            $xml .= "    </bpmn:laneSet>\n";
        }
        foreach ($nodes as $node) {
            $tag = self::bpmn_tag((string)$node->elementtype);
            $xml .= '    <bpmn:' . $tag . ' id="' . self::bpmn_node_id((string)$node->uuid) . '" name="' . self::xml((string)$node->name) . '" />' . "\n";
        }
        foreach ($flows as $flow) {
            $attrs = ' id="' . self::bpmn_flow_id((string)$flow->uuid) . '" sourceRef="' . self::bpmn_node_id((string)$flow->fromuuid) . '" targetRef="' . self::bpmn_node_id((string)$flow->touuid) . '"';
            if ((string)$flow->label !== '') { $attrs .= ' name="' . self::xml((string)$flow->label) . '"'; }
            if ((string)$flow->conditiontext !== '') {
                $xml .= '    <bpmn:sequenceFlow' . $attrs . '><bpmn:conditionExpression xsi:type="bpmn:tFormalExpression"><![CDATA[' . str_replace(']]>', ']]]]><![CDATA[>', (string)$flow->conditiontext) . ']]></bpmn:conditionExpression></bpmn:sequenceFlow>' . "\n";
            } else {
                $xml .= '    <bpmn:sequenceFlow' . $attrs . ' />' . "\n";
            }
        }
        $xml .= "  </bpmn:process>\n";
        $xml .= '  <bpmndi:BPMNDiagram id="BPMNDiagram_' . $modelid . '"><bpmndi:BPMNPlane id="BPMNPlane_' . $modelid . '" bpmnElement="' . $processid . '">' . "\n";
        $laneindex = []; foreach ($lanes as $i=>$lane) { $laneindex[(string)$lane->uuid] = $i; }
        $canvaswidth = max(900, 260 + count($nodes) * 170);
        foreach ($lanes as $i=>$lane) {
            $y = 70 + $i * 180;
            $xml .= '    <bpmndi:BPMNShape id="' . self::bpmn_lane_id((string)$lane->uuid) . '_di" bpmnElement="' . self::bpmn_lane_id((string)$lane->uuid) . '" isHorizontal="true"><dc:Bounds x="40" y="' . $y . '" width="' . $canvaswidth . '" height="160" /></bpmndi:BPMNShape>' . "\n";
        }
        $positions = [];
        foreach ($nodes as $i=>$node) {
            $type = (string)$node->elementtype;
            $w = in_array($type,['start','end','event'],true) ? 36 : ($type==='gateway' ? 50 : 120);
            $h = in_array($type,['start','end','event'],true) ? 36 : ($type==='gateway' ? 50 : 80);
            $x = 100 + $i * 170;
            if ((string)$node->laneuuid !== '' && isset($laneindex[(string)$node->laneuuid])) {
                $y = 110 + $laneindex[(string)$node->laneuuid] * 180;
            } else {
                $y = $lanes ? (100 + count($lanes) * 180) : 150;
            }
            $positions[(string)$node->uuid] = [$x,$y,$w,$h];
            $xml .= '    <bpmndi:BPMNShape id="' . self::bpmn_node_id((string)$node->uuid) . '_di" bpmnElement="' . self::bpmn_node_id((string)$node->uuid) . '"><dc:Bounds x="' . $x . '" y="' . $y . '" width="' . $w . '" height="' . $h . '" /></bpmndi:BPMNShape>' . "\n";
        }
        foreach ($flows as $flow) {
            if (!isset($positions[(string)$flow->fromuuid], $positions[(string)$flow->touuid])) { continue; }
            $a=$positions[(string)$flow->fromuuid]; $b=$positions[(string)$flow->touuid];
            $x1=$a[0]+$a[2]; $y1=$a[1]+($a[3]/2); $x2=$b[0]; $y2=$b[1]+($b[3]/2);
            $xml .= '    <bpmndi:BPMNEdge id="' . self::bpmn_flow_id((string)$flow->uuid) . '_di" bpmnElement="' . self::bpmn_flow_id((string)$flow->uuid) . '"><di:waypoint x="' . $x1 . '" y="' . $y1 . '" /><di:waypoint x="' . $x2 . '" y="' . $y2 . '" /></bpmndi:BPMNEdge>' . "\n";
        }
        $xml .= "  </bpmndi:BPMNPlane></bpmndi:BPMNDiagram>\n</bpmn:definitions>\n";
        return $xml;
    }

    private static function expected_bpmn_semantics(string $companyuuid, int $modelid): array {
        global $DB;
        $nodes=[];
        foreach ($DB->get_records_select('local_kbacompany_procelem',
                'companyuuid = :company AND modelid = :model AND active = 1 AND elementtype IN (\'start\',\'end\',\'task\',\'gateway\',\'event\')',
                ['company'=>$companyuuid,'model'=>$modelid], 'uuid ASC') as $row) {
            $nodes[self::bpmn_node_id((string)$row->uuid)] = [self::bpmn_tag((string)$row->elementtype), (string)$row->name];
        }
        $flows=[];
        foreach ($DB->get_records('local_kbacompany_procflow',['companyuuid'=>$companyuuid,'modelid'=>$modelid,'active'=>1],'uuid ASC') as $row) {
            $flows[self::bpmn_flow_id((string)$row->uuid)] = [self::bpmn_node_id((string)$row->fromuuid), self::bpmn_node_id((string)$row->touuid), (string)$row->label];
        }
        $lanes=[];
        foreach ($DB->get_records('local_kbacompany_procelem',['companyuuid'=>$companyuuid,'modelid'=>$modelid,'elementtype'=>'lane','active'=>1],'uuid ASC') as $lane) {
            $refs=[];
            foreach ($DB->get_records('local_kbacompany_procelem',['companyuuid'=>$companyuuid,'modelid'=>$modelid,'laneuuid'=>$lane->uuid,'active'=>1],'uuid ASC') as $node) {
                if (in_array((string)$node->elementtype,self::BPMN_NODE_TYPES,true)) { $refs[]=self::bpmn_node_id((string)$node->uuid); }
            }
            sort($refs);
            $lanes[self::bpmn_lane_id((string)$lane->uuid)] = [(string)$lane->name,$refs];
        }
        ksort($nodes); ksort($flows); ksort($lanes);
        return ['nodes'=>$nodes,'flows'=>$flows,'lanes'=>$lanes];
    }

    private static function bpmn_xml_attrs(string $raw): array {
        $out=[];
        if (preg_match_all('/([A-Za-z_:][A-Za-z0-9_.:-]*)\\s*=\\s*"([^"]*)"/u',$raw,$matches,PREG_SET_ORDER)) {
            foreach ($matches as $m) { $out[$m[1]]=html_entity_decode($m[2],ENT_QUOTES | ENT_XML1,'UTF-8'); }
        }
        return $out;
    }

    /** Restricted fallback for hosts without ext-dom. Generated bpmn-js XML uses the bpmn: prefix. */
    private static function validate_bpmn_layout_xml_fallback(string $companyuuid, int $modelid, string $xml): void {
        $allowed=['definitions','process','laneSet','lane','flowNodeRef','incoming','outgoing','startEvent','endEvent','task','exclusiveGateway','intermediateCatchEvent','sequenceFlow','conditionExpression','documentation','extensionElements'];
        if (!preg_match('/<bpmn:definitions\\b/u',$xml) || !preg_match('/<\\/bpmn:definitions>\\s*$/u',$xml)) { self::fail('bpmnxml'); }
        preg_match_all('/<bpmn:([A-Za-z][A-Za-z0-9]*)\\b/u',$xml,$tagmatches);
        foreach (array_unique($tagmatches[1]??[]) as $tag) { if (!in_array($tag,$allowed,true)) { self::fail('bpmnsemantic'); } }
        $actualnodes=[];$actualflows=[];$actuallanes=[];
        if (preg_match_all('/<bpmn:(startEvent|endEvent|task|exclusiveGateway|intermediateCatchEvent)\\b([^>]*)>/u',$xml,$matches,PREG_SET_ORDER)) {
            foreach ($matches as $m) { $a=self::bpmn_xml_attrs($m[2]); if (empty($a['id'])) { self::fail('bpmnxml'); } $actualnodes[(string)$a['id']]=[$m[1],(string)($a['name']??'')]; }
        }
        if (preg_match_all('/<bpmn:sequenceFlow\\b([^>]*)>/u',$xml,$matches,PREG_SET_ORDER)) {
            foreach ($matches as $m) { $a=self::bpmn_xml_attrs($m[1]); if (empty($a['id'])||empty($a['sourceRef'])||empty($a['targetRef'])) { self::fail('bpmnxml'); } $actualflows[(string)$a['id']]=[(string)$a['sourceRef'],(string)$a['targetRef'],(string)($a['name']??'')]; }
        }
        if (preg_match_all('/<bpmn:lane\\b([^>]*)>(.*?)<\\/bpmn:lane>/us',$xml,$matches,PREG_SET_ORDER)) {
            foreach ($matches as $m) {
                $a=self::bpmn_xml_attrs($m[1]); if (empty($a['id'])) { self::fail('bpmnxml'); }
                preg_match_all('/<bpmn:flowNodeRef>([^<]+)<\\/bpmn:flowNodeRef>/u',$m[2],$refs);
                $r=array_map('trim',$refs[1]??[]); sort($r);
                $actuallanes[(string)$a['id']]=[(string)($a['name']??''),$r];
            }
        }
        ksort($actualnodes);ksort($actualflows);ksort($actuallanes);
        $expected=self::expected_bpmn_semantics($companyuuid,$modelid);
        if ($actualnodes!==$expected['nodes']||$actualflows!==$expected['flows']||$actuallanes!==$expected['lanes']) { self::fail('bpmnsemantic'); }
    }

    /** Only BPMN DI/layout may change in the visual editor. Canonical nodes and flows remain structural BMS records. */
    private static function validate_bpmn_layout_xml(string $companyuuid, int $modelid, string $xml): void {
        if ($xml === '' || strlen($xml) > self::BPMN_XML_MAX) { self::fail('bpmnxml'); }
        if (stripos($xml, '<!DOCTYPE') !== false || stripos($xml, '<!ENTITY') !== false) { self::fail('bpmnxml'); }
        if (!class_exists('DOMDocument')) { self::validate_bpmn_layout_xml_fallback($companyuuid,$modelid,$xml); return; }
        $old = libxml_use_internal_errors(true);
        $dom = new \DOMDocument();
        $ok = $dom->loadXML($xml, LIBXML_NONET | LIBXML_NOBLANKS);
        libxml_clear_errors(); libxml_use_internal_errors($old);
        if (!$ok) { self::fail('bpmnxml'); }
        $ns='http://www.omg.org/spec/BPMN/20100524/MODEL';
        $actualnodes=[]; $actualflows=[]; $actuallanes=[];
        $allowed=['definitions','process','laneSet','lane','flowNodeRef','incoming','outgoing','startEvent','endEvent','task','exclusiveGateway','intermediateCatchEvent','sequenceFlow','conditionExpression','documentation','extensionElements'];
        foreach ($dom->getElementsByTagNameNS($ns,'*') as $el) {
            $local=$el->localName;
            if (!in_array($local,$allowed,true)) { self::fail('bpmnsemantic'); }
            if (in_array($local,['startEvent','endEvent','task','exclusiveGateway','intermediateCatchEvent'],true)) {
                $actualnodes[(string)$el->getAttribute('id')] = [$local,(string)$el->getAttribute('name')];
            } else if ($local==='sequenceFlow') {
                $actualflows[(string)$el->getAttribute('id')] = [(string)$el->getAttribute('sourceRef'),(string)$el->getAttribute('targetRef'),(string)$el->getAttribute('name')];
            } else if ($local==='lane') {
                $refs=[];
                foreach ($el->childNodes as $child) {
                    if ($child instanceof \DOMElement && $child->namespaceURI===$ns && $child->localName==='flowNodeRef') { $refs[]=trim((string)$child->textContent); }
                }
                sort($refs);
                $actuallanes[(string)$el->getAttribute('id')] = [(string)$el->getAttribute('name'),$refs];
            }
        }
        ksort($actualnodes); ksort($actualflows); ksort($actuallanes);
        $expected=self::expected_bpmn_semantics($companyuuid,$modelid);
        if ($actualnodes !== $expected['nodes'] || $actualflows !== $expected['flows'] || $actuallanes !== $expected['lanes']) { self::fail('bpmnsemantic'); }
    }

    private static function current_bpmn_xml(string $companyuuid, int $modelid, string $processname): array {
        $file=self::bpmn_file($modelid);
        if ($file) {
            $xml=$file->get_content();
            try {
                self::validate_bpmn_layout_xml($companyuuid,$modelid,$xml);
                return ['xml'=>$xml,'stored'=>true,'hash'=>hash('sha256',$xml)];
            } catch (\Throwable $e) {
                // Structural records remain canonical; stale/corrupt layout falls back to regenerated XML.
            }
        }
        $xml=self::generate_bpmn_xml($companyuuid,$modelid,$processname);
        return ['xml'=>$xml,'stored'=>false,'hash'=>hash('sha256',$xml)];
    }

    public static function save_bpmn_layout(string $companyuuid, int $userid, string $processuuid, int $modelid,
            int $expectedchangeversion, string $xml): void {
        global $DB;
        self::authorise($companyuuid,$userid);
        $lock=\core\lock\lock_config::get_lock_factory('local_kbacompany')
            ->get_lock('process_model:'.$companyuuid.':'.$processuuid,10);
        if (!$lock) { self::fail('lock'); }
        try {
            self::authorise($companyuuid,$userid);
            self::process($companyuuid,$userid,$processuuid);
            $tx=$DB->start_delegated_transaction();
            $model=self::require_draft($companyuuid,$processuuid,$modelid,$expectedchangeversion);
            self::validate_bpmn_layout_xml($companyuuid,$modelid,$xml);
            $oldfile=self::bpmn_file($modelid);
            $oldhash=$oldfile?hash('sha256',$oldfile->get_content()):'';
            $newhash=hash('sha256',$xml);
            if ($oldhash !== $newhash) {
                self::store_bpmn_file($modelid,$userid,$xml);
                self::bump($model,$userid);
                self::log($companyuuid,$processuuid,$userid,'procmodel_bpmn_layout',
                    ['hash'=>$oldhash],['hash'=>$newhash,'bytes'=>strlen($xml)]);
            }
            $tx->allow_commit();
        } catch (\Throwable $e) {
            if (isset($tx)) { $tx->rollback($e); }
            throw $e;
        } finally { $lock->release(); }
    }

    /** Discard only the stored visual layout. Canonical BPMN semantics remain unchanged. */
    public static function reset_bpmn_layout(string $companyuuid, int $userid, string $processuuid, int $modelid,
            int $expectedchangeversion): void {
        global $DB;
        self::authorise($companyuuid,$userid);
        $lock=\core\lock\lock_config::get_lock_factory('local_kbacompany')
            ->get_lock('process_model:'.$companyuuid.':'.$processuuid,10);
        if (!$lock) { self::fail('lock'); }
        try {
            self::authorise($companyuuid,$userid);
            self::process($companyuuid,$userid,$processuuid);
            $tx=$DB->start_delegated_transaction();
            $model=self::require_draft($companyuuid,$processuuid,$modelid,$expectedchangeversion);
            $file=self::bpmn_file($modelid);
            if ($file) {
                $oldhash=hash('sha256',$file->get_content());
                $file->delete();
                self::bump($model,$userid);
                self::log($companyuuid,$processuuid,$userid,'procmodel_bpmn_layout_reset',['hash'=>$oldhash],['stored'=>false]);
            }
            $tx->allow_commit();
        } catch (\Throwable $e) {
            if (isset($tx)) { $tx->rollback($e); }
            throw $e;
        } finally { $lock->release(); }
    }

    private static function log(string $companyuuid, string $objectuuid, int $actor, string $action, $old, $new): void {
        global $DB;
        $DB->insert_record('local_kbacompany_aclog', (object)[
            'companyuuid' => $companyuuid, 'objectuuid' => $objectuuid, 'action' => $action,
            'actorid' => $actor,
            'oldvalue' => $old === null ? null : json_encode($old, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'newvalue' => $new === null ? null : json_encode($new, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'timecreated' => time(),
        ]);
    }

    /** Create a draft, or return the existing draft. A new draft clones the latest published snapshot. */
    public static function ensure_draft(string $companyuuid, int $userid, string $processuuid): int {
        global $DB;
        self::authorise($companyuuid, $userid);
        self::process($companyuuid, $userid, $processuuid);
        $lock = \core\lock\lock_config::get_lock_factory('local_kbacompany')
            ->get_lock('process_model:' . $companyuuid . ':' . $processuuid, 10);
        if (!$lock) { self::fail('lock'); }
        try {
            self::authorise($companyuuid,$userid);
            self::process($companyuuid,$userid,$processuuid);
            $existing = self::draft($companyuuid, $processuuid);
            if ($existing) { return (int)$existing->id; }
            $tx = $DB->start_delegated_transaction();
            $published = null;
            foreach (self::latest_models($companyuuid, $processuuid) as $candidate) {
                if ($candidate->status === 'published') { $published = $candidate; break; }
            }
            $now = time();
            $model = (object)[
                'companyuuid' => $companyuuid, 'processuuid' => $processuuid,
                'revision' => $published ? ((int)$published->revision + 1) : 1,
                'processrevision' => 0, 'changeversion' => 1, 'status' => 'draft',
                'scope' => $published ? (string)$published->scope : '',
                'triggertext' => $published ? (string)$published->triggertext : '',
                'startcondition' => $published ? (string)$published->startcondition : '',
                'endcondition' => $published ? (string)$published->endcondition : '',
                'createdby' => $userid, 'modifiedby' => $userid,
                'timecreated' => $now, 'timemodified' => $now,
            ];
            $modelid = (int)$DB->insert_record('local_kbacompany_procmodel', $model);
            if ($published) {
                foreach ($DB->get_records('local_kbacompany_procelem',
                        ['companyuuid' => $companyuuid, 'modelid' => $published->id], 'id ASC') as $element) {
                    unset($element->id);
                    $element->modelid = $modelid;
                    $element->timecreated = $now;
                    $element->timemodified = $now;
                    $DB->insert_record('local_kbacompany_procelem', $element);
                }
                foreach ($DB->get_records('local_kbacompany_procflow',
                        ['companyuuid' => $companyuuid, 'modelid' => $published->id], 'id ASC') as $flow) {
                    unset($flow->id);
                    $flow->modelid = $modelid;
                    $flow->timecreated = $now;
                    $flow->timemodified = $now;
                    $DB->insert_record('local_kbacompany_procflow', $flow);
                }
                foreach ($DB->get_records('local_kbacompany_procraci',
                        ['companyuuid' => $companyuuid, 'modelid' => $published->id], 'id ASC') as $raci) {
                    unset($raci->id);
                    $raci->modelid = $modelid;
                    $raci->timecreated = $now;
                    $raci->timemodified = $now;
                    $DB->insert_record('local_kbacompany_procraci', $raci);
                }
                self::clone_bpmn_file((int)$published->id, $modelid, $userid);
            }
            self::log($companyuuid, $processuuid, $userid, 'procmodel_draft',
                $published ? ['revision' => (int)$published->revision] : null,
                ['modelid' => $modelid, 'revision' => (int)$model->revision]);
            $tx->allow_commit();
            return $modelid;
        } catch (\Throwable $e) {
            if (isset($tx)) { $tx->rollback($e); }
            throw $e;
        } finally {
            $lock->release();
        }
    }

    public static function save_metadata(string $companyuuid, int $userid, string $processuuid, int $modelid,
            int $expectedchangeversion, array $data): void {
        global $DB;
        self::authorise($companyuuid, $userid);
        $lock = \core\lock\lock_config::get_lock_factory('local_kbacompany')
            ->get_lock('process_model:' . $companyuuid . ':' . $processuuid, 10);
        if (!$lock) { self::fail('lock'); }
        try {
            self::authorise($companyuuid, $userid);
            self::process($companyuuid, $userid, $processuuid);
            $tx = $DB->start_delegated_transaction();
            $model = self::require_draft($companyuuid, $processuuid, $modelid, $expectedchangeversion);
            $old = clone $model;
            $model->scope = self::clean($data, 'scope', 4000);
            $model->triggertext = self::clean($data, 'triggertext', 2000);
            $model->startcondition = self::clean($data, 'startcondition', 2000);
            $model->endcondition = self::clean($data, 'endcondition', 2000);
            self::bump($model, $userid);
            self::log($companyuuid, $processuuid, $userid, 'procmodel_metadata', $old, $model);
            $tx->allow_commit();
        } catch (\Throwable $e) {
            if (isset($tx)) { $tx->rollback($e); }
            throw $e;
        } finally { $lock->release(); }
    }

    private static function validate_executor(string $companyuuid, int $userid, string $type, string $uuid): void {
        global $DB;
        if (!in_array($type, self::EXECUTOR_TYPES, true) || $uuid === '') { self::fail('invalidexecutor'); }
        if ($type === 'orgunit') {
            if (!$DB->record_exists('local_kbacompany_orgnode',
                    ['companyuuid' => $companyuuid, 'uuid' => $uuid, 'active' => 1])) { self::fail('invalidexecutor'); }
            return;
        }
        if ($type === 'position') {
            if (!$DB->record_exists('local_kbacompany_position',
                    ['companyuuid' => $companyuuid, 'uuid' => $uuid, 'active' => 1])) { self::fail('invalidexecutor'); }
            return;
        }
        $roles = business_role_service::business_roles_context($companyuuid, $userid);
        foreach ($roles['roles'] as $role) {
            if ($role['uuid'] === $uuid) { return; }
        }
        self::fail('invalidexecutor');
    }

    private static function validate_product(string $companyuuid, string $uuid): void {
        global $DB;
        if ($uuid !== '' && !$DB->record_exists('local_kbacompany_product',
                ['companyuuid' => $companyuuid, 'uuid' => $uuid])) { self::fail('invalidproduct'); }
    }

    private static function validate_lane(string $companyuuid, int $modelid, string $laneuuid): void {
        global $DB;
        if ($laneuuid === '') { return; }
        if (!$DB->record_exists('local_kbacompany_procelem', [
                'companyuuid' => $companyuuid, 'modelid' => $modelid,
                'uuid' => $laneuuid, 'elementtype' => 'lane', 'active' => 1])) { self::fail('invalidlane'); }
    }

    private static function validate_activity(string $companyuuid, int $modelid, string $activityuuid): void {
        global $DB;
        if ($activityuuid === '') { return; }
        if (!$DB->record_exists('local_kbacompany_procelem', [
                'companyuuid' => $companyuuid, 'modelid' => $modelid,
                'uuid' => $activityuuid, 'elementtype' => 'task', 'active' => 1])) {
            self::fail('invalidactivity');
        }
    }

    /** Add or update a draft SIPOC/BPMN element. */
    public static function save_element(string $companyuuid, int $userid, string $processuuid, int $modelid,
            int $expectedchangeversion, array $data, string $elementuuid = ''): string {
        global $DB;
        self::authorise($companyuuid, $userid);
        $lock = \core\lock\lock_config::get_lock_factory('local_kbacompany')
            ->get_lock('process_model:' . $companyuuid . ':' . $processuuid, 10);
        if (!$lock) { self::fail('lock'); }
        try {
            self::authorise($companyuuid, $userid);
            self::process($companyuuid, $userid, $processuuid);
            $tx = $DB->start_delegated_transaction();
            $model = self::require_draft($companyuuid, $processuuid, $modelid, $expectedchangeversion);
            $type = self::clean($data, 'elementtype', 20, true);
            if (!in_array($type, self::ELEMENT_TYPES, true)) { self::fail('elementtype'); }
            $old = null;
            if ($elementuuid !== '') {
                $old = $DB->get_record('local_kbacompany_procelem', [
                    'companyuuid' => $companyuuid, 'modelid' => $modelid, 'uuid' => $elementuuid, 'active' => 1,
                ]);
                if (!$old) { self::fail('element'); }
                if ($old->elementtype !== $type) { self::fail('elementtype'); }
            }
            $uuid = $old ? (string)$old->uuid : self::uuid();
            $name = self::clean($data, 'name', 255, true);
            $description = self::clean($data, 'description', 4000);
            $executortype = self::clean($data, 'executortype', 20);
            $executoruuid = self::clean($data, 'executoruuid', 36);
            $laneuuid = self::clean($data, 'laneuuid', 36);
            $productuuid = self::clean($data, 'productuuid', 36);
            $outputtext = self::clean($data, 'outputtext', 2000);
            $activityuuid = self::clean($data, 'activityuuid', 36);
            self::validate_lane($companyuuid, $modelid, $laneuuid);
            self::validate_activity($companyuuid, $modelid, $activityuuid);
            self::validate_product($companyuuid, $productuuid);
            if ($type === 'task') {
                if ($description === '' || $executortype === '' || $executoruuid === '' ||
                        ($productuuid === '' && $outputtext === '')) { self::fail('taskincomplete'); }
                self::validate_executor($companyuuid, $userid, $executortype, $executoruuid);
            } else if ($type === 'lane') {
                if (($executortype === '') xor ($executoruuid === '')) { self::fail('invalidexecutor'); }
                if ($executortype !== '') { self::validate_executor($companyuuid, $userid, $executortype, $executoruuid); }
                $laneuuid = ''; $productuuid = ''; $outputtext = ''; $activityuuid = '';
            } else {
                $executortype = ''; $executoruuid = '';
                if (!in_array($type, ['input', 'output'], true)) { $productuuid = ''; }
                if (!in_array($type, ['input', 'output'], true)) { $outputtext = ''; }
                if (!in_array($type, ['document', 'system', 'resource', 'record', 'control'], true)) {
                    $activityuuid = '';
                }
            }
            $now = time();
            $record = (object)[
                'companyuuid' => $companyuuid, 'modelid' => $modelid, 'uuid' => $uuid,
                'elementtype' => $type, 'code' => self::clean($data, 'code', 50), 'name' => $name,
                'description' => $description, 'executortype' => $executortype, 'executoruuid' => $executoruuid,
                'laneuuid' => $laneuuid, 'productuuid' => $productuuid, 'outputtext' => $outputtext,
                'activityuuid' => $activityuuid, 'sortorder' => max(0, min(99999, (int)($data['sortorder'] ?? 0))), 'active' => 1,
                'timecreated' => $old ? (int)$old->timecreated : $now, 'timemodified' => $now,
            ];
            if ($old) {
                $record->id = (int)$old->id;
                $DB->update_record('local_kbacompany_procelem', $record);
            } else {
                $DB->insert_record('local_kbacompany_procelem', $record);
            }
            if ($type === 'lane' || in_array($type, self::BPMN_NODE_TYPES, true)) {
                self::clear_bpmn_file($modelid);
            }
            self::bump($model, $userid);
            self::log($companyuuid, $uuid, $userid, 'procmodel_element_save', $old, $record);
            $tx->allow_commit();
            return $uuid;
        } catch (\Throwable $e) {
            if (isset($tx)) { $tx->rollback($e); }
            throw $e;
        } finally { $lock->release(); }
    }

    public static function remove_element(string $companyuuid, int $userid, string $processuuid, int $modelid,
            int $expectedchangeversion, string $elementuuid): void {
        global $DB;
        self::authorise($companyuuid, $userid);
        $lock = \core\lock\lock_config::get_lock_factory('local_kbacompany')
            ->get_lock('process_model:' . $companyuuid . ':' . $processuuid, 10);
        if (!$lock) { self::fail('lock'); }
        try {
            self::authorise($companyuuid, $userid);
            self::process($companyuuid, $userid, $processuuid);
            $tx = $DB->start_delegated_transaction();
            $model = self::require_draft($companyuuid, $processuuid, $modelid, $expectedchangeversion);
            $element = $DB->get_record('local_kbacompany_procelem', [
                'companyuuid' => $companyuuid, 'modelid' => $modelid, 'uuid' => $elementuuid, 'active' => 1,
            ]);
            if (!$element) { self::fail('element'); }
            if ($element->elementtype === 'lane' && $DB->record_exists('local_kbacompany_procelem', [
                    'companyuuid' => $companyuuid, 'modelid' => $modelid, 'laneuuid' => $elementuuid, 'active' => 1])) {
                self::fail('referenced');
            }
            if (in_array($element->elementtype, self::BPMN_NODE_TYPES, true)) {
                if ($element->elementtype === 'task' && ($DB->record_exists('local_kbacompany_procraci', [
                        'companyuuid' => $companyuuid, 'modelid' => $modelid,
                        'activityuuid' => $elementuuid, 'active' => 1]) ||
                        $DB->record_exists('local_kbacompany_procelem', [
                            'companyuuid' => $companyuuid, 'modelid' => $modelid,
                            'activityuuid' => $elementuuid, 'active' => 1]))) {
                    self::fail('referenced');
                }
                if ($DB->record_exists_select('local_kbacompany_procflow',
                        'companyuuid = :company AND modelid = :model AND active = 1 AND (fromuuid = :fromuuid OR touuid = :touuid)', [
                            'company' => $companyuuid, 'model' => $modelid,
                            'fromuuid' => $elementuuid, 'touuid' => $elementuuid,
                        ])) { self::fail('referenced'); }
            }
            $old = clone $element;
            $element->active = 0;
            $element->timemodified = time();
            $DB->update_record('local_kbacompany_procelem', $element);
            if ($element->elementtype === 'lane' || in_array($element->elementtype, self::BPMN_NODE_TYPES, true)) {
                self::clear_bpmn_file($modelid);
            }
            self::bump($model, $userid);
            self::log($companyuuid, $elementuuid, $userid, 'procmodel_element_remove', $old, $element);
            $tx->allow_commit();
        } catch (\Throwable $e) {
            if (isset($tx)) { $tx->rollback($e); }
            throw $e;
        } finally { $lock->release(); }
    }

    /** Reorder the visual SIPOC cards without creating a second process model. */
    public static function reorder_sipoc(string $companyuuid, int $userid, string $processuuid, int $modelid,
            int $expectedchangeversion, array $ordering): void {
        global $DB;
        self::authorise($companyuuid, $userid);
        $lock = \core\lock\lock_config::get_lock_factory('local_kbacompany')
            ->get_lock('process_model:' . $companyuuid . ':' . $processuuid, 10);
        if (!$lock) { self::fail('lock'); }
        try {
            self::authorise($companyuuid, $userid);
            self::process($companyuuid, $userid, $processuuid);
            $tx = $DB->start_delegated_transaction();
            $model = self::require_draft($companyuuid, $processuuid, $modelid, $expectedchangeversion);
            $types = ['input' => 1000, 'task' => 2000, 'output' => 3000];
            $before = [];
            $after = [];
            $changed = false;
            foreach ($types as $type => $base) {
                $rows = array_values($DB->get_records('local_kbacompany_procelem', [
                    'companyuuid' => $companyuuid, 'modelid' => $modelid,
                    'elementtype' => $type, 'active' => 1,
                ], 'sortorder ASC, id ASC'));
                $expected = array_map(static function(\stdClass $row): string { return (string)$row->uuid; }, $rows);
                $submitted = $ordering[$type] ?? [];
                if (!is_array($submitted)) { self::fail('invalid'); }
                $submitted = array_values(array_map('strval', $submitted));
                if (count($submitted) !== count(array_unique($submitted))) { self::fail('invalid'); }
                $a = $expected; $b = $submitted;
                sort($a); sort($b);
                if ($a !== $b) { self::fail('invalid'); }
                $before[$type] = $expected;
                $after[$type] = $submitted;
                if ($expected !== $submitted) { $changed = true; }
                $map = [];
                foreach ($rows as $row) { $map[(string)$row->uuid] = $row; }
                foreach ($submitted as $index => $uuid) {
                    $row = $map[$uuid];
                    $neworder = $base + (($index + 1) * 10);
                    if ((int)$row->sortorder !== $neworder) {
                        $row->sortorder = $neworder;
                        $row->timemodified = time();
                        $DB->update_record('local_kbacompany_procelem', $row);
                    }
                }
            }
            if ($changed) {
                self::bump($model, $userid);
                self::log($companyuuid, $processuuid, $userid, 'procmodel_sipoc_reorder', $before, $after);
            }
            $tx->allow_commit();
        } catch (\Throwable $e) {
            if (isset($tx)) { $tx->rollback($e); }
            throw $e;
        } finally { $lock->release(); }
    }

    public static function save_flow(string $companyuuid, int $userid, string $processuuid, int $modelid,
            int $expectedchangeversion, array $data, string $flowuuid = ''): string {
        global $DB;
        self::authorise($companyuuid, $userid);
        $lock = \core\lock\lock_config::get_lock_factory('local_kbacompany')
            ->get_lock('process_model:' . $companyuuid . ':' . $processuuid, 10);
        if (!$lock) { self::fail('lock'); }
        try {
            self::authorise($companyuuid, $userid);
            self::process($companyuuid, $userid, $processuuid);
            $tx = $DB->start_delegated_transaction();
            $model = self::require_draft($companyuuid, $processuuid, $modelid, $expectedchangeversion);
            $from = self::clean($data, 'fromuuid', 36, true);
            $to = self::clean($data, 'touuid', 36, true);
            foreach ([$from, $to] as $nodeuuid) {
                $node = $DB->get_record('local_kbacompany_procelem', [
                    'companyuuid' => $companyuuid, 'modelid' => $modelid, 'uuid' => $nodeuuid, 'active' => 1,
                ]);
                if (!$node || !in_array($node->elementtype, self::BPMN_NODE_TYPES, true)) { self::fail('flowendpoint'); }
            }
            $old = null;
            if ($flowuuid !== '') {
                $old = $DB->get_record('local_kbacompany_procflow', [
                    'companyuuid' => $companyuuid, 'modelid' => $modelid, 'uuid' => $flowuuid, 'active' => 1,
                ]);
                if (!$old) { self::fail('flow'); }
            }
            foreach ($DB->get_records('local_kbacompany_procflow', [
                    'companyuuid' => $companyuuid, 'modelid' => $modelid,
                    'fromuuid' => $from, 'touuid' => $to, 'active' => 1,
                ]) as $duplicate) {
                if (!$old || $duplicate->uuid !== $old->uuid) { self::fail('duplicateflow'); }
            }
            $now = time();
            $record = (object)[
                'companyuuid' => $companyuuid, 'modelid' => $modelid,
                'uuid' => $old ? (string)$old->uuid : self::uuid(),
                'fromuuid' => $from, 'touuid' => $to,
                'label' => self::clean($data, 'label', 255),
                'conditiontext' => self::clean($data, 'conditiontext', 2000),
                'sortorder' => max(0, min(99999, (int)($data['sortorder'] ?? 0))), 'active' => 1,
                'timecreated' => $old ? (int)$old->timecreated : $now, 'timemodified' => $now,
            ];
            if ($old) {
                $record->id = (int)$old->id;
                $DB->update_record('local_kbacompany_procflow', $record);
            } else {
                $DB->insert_record('local_kbacompany_procflow', $record);
            }
            self::clear_bpmn_file($modelid);
            self::bump($model, $userid);
            self::log($companyuuid, $record->uuid, $userid, 'procmodel_flow_save', $old, $record);
            $tx->allow_commit();
            return (string)$record->uuid;
        } catch (\Throwable $e) {
            if (isset($tx)) { $tx->rollback($e); }
            throw $e;
        } finally { $lock->release(); }
    }

    public static function remove_flow(string $companyuuid, int $userid, string $processuuid, int $modelid,
            int $expectedchangeversion, string $flowuuid): void {
        global $DB;
        self::authorise($companyuuid, $userid);
        $lock = \core\lock\lock_config::get_lock_factory('local_kbacompany')
            ->get_lock('process_model:' . $companyuuid . ':' . $processuuid, 10);
        if (!$lock) { self::fail('lock'); }
        try {
            self::authorise($companyuuid, $userid);
            self::process($companyuuid, $userid, $processuuid);
            $tx = $DB->start_delegated_transaction();
            $model = self::require_draft($companyuuid, $processuuid, $modelid, $expectedchangeversion);
            $flow = $DB->get_record('local_kbacompany_procflow', [
                'companyuuid' => $companyuuid, 'modelid' => $modelid, 'uuid' => $flowuuid, 'active' => 1,
            ]);
            if (!$flow) { self::fail('flow'); }
            $old = clone $flow;
            $flow->active = 0;
            $flow->timemodified = time();
            $DB->update_record('local_kbacompany_procflow', $flow);
            self::clear_bpmn_file($modelid);
            self::bump($model, $userid);
            self::log($companyuuid, $flowuuid, $userid, 'procmodel_flow_remove', $old, $flow);
            $tx->allow_commit();
        } catch (\Throwable $e) {
            if (isset($tx)) { $tx->rollback($e); }
            throw $e;
        } finally { $lock->release(); }
    }

    public static function save_raci(string $companyuuid, int $userid, string $processuuid, int $modelid,
            int $expectedchangeversion, string $activityuuid, string $partytype, string $partyuuid,
            string $racirole): string {
        global $DB;
        self::authorise($companyuuid, $userid);
        $racirole = strtoupper(trim($racirole));
        if (!in_array($racirole, ['R', 'A', 'C', 'I'], true)) { self::fail('raci'); }
        self::validate_executor($companyuuid, $userid, $partytype, $partyuuid);
        $lock = \core\lock\lock_config::get_lock_factory('local_kbacompany')
            ->get_lock('process_model:' . $companyuuid . ':' . $processuuid, 10);
        if (!$lock) { self::fail('lock'); }
        try {
            self::authorise($companyuuid, $userid);
            self::process($companyuuid, $userid, $processuuid);
            $tx = $DB->start_delegated_transaction();
            $model = self::require_draft($companyuuid, $processuuid, $modelid, $expectedchangeversion);
            self::validate_activity($companyuuid, $modelid, $activityuuid);
            if ($DB->record_exists('local_kbacompany_procraci', [
                    'companyuuid' => $companyuuid, 'modelid' => $modelid, 'activityuuid' => $activityuuid,
                    'partytype' => $partytype, 'partyuuid' => $partyuuid, 'racirole' => $racirole, 'active' => 1])) {
                self::fail('raciduplicate');
            }
            $now = time();
            $record = (object)[
                'companyuuid' => $companyuuid, 'modelid' => $modelid, 'uuid' => self::uuid(),
                'activityuuid' => $activityuuid, 'partytype' => $partytype, 'partyuuid' => $partyuuid,
                'racirole' => $racirole, 'active' => 1, 'timecreated' => $now, 'timemodified' => $now,
            ];
            $DB->insert_record('local_kbacompany_procraci', $record);
            self::bump($model, $userid);
            self::log($companyuuid, $record->uuid, $userid, 'procmodel_raci_save', null, $record);
            $tx->allow_commit();
            return (string)$record->uuid;
        } catch (\Throwable $e) {
            if (isset($tx)) { $tx->rollback($e); }
            throw $e;
        } finally { $lock->release(); }
    }

    public static function remove_raci(string $companyuuid, int $userid, string $processuuid, int $modelid,
            int $expectedchangeversion, string $raciuuid): void {
        global $DB;
        self::authorise($companyuuid, $userid);
        $lock = \core\lock\lock_config::get_lock_factory('local_kbacompany')
            ->get_lock('process_model:' . $companyuuid . ':' . $processuuid, 10);
        if (!$lock) { self::fail('lock'); }
        try {
            self::authorise($companyuuid, $userid);
            self::process($companyuuid, $userid, $processuuid);
            $tx = $DB->start_delegated_transaction();
            $model = self::require_draft($companyuuid, $processuuid, $modelid, $expectedchangeversion);
            $record = $DB->get_record('local_kbacompany_procraci', [
                'companyuuid' => $companyuuid, 'modelid' => $modelid, 'uuid' => $raciuuid, 'active' => 1,
            ]);
            if (!$record) { self::fail('raci'); }
            $old = clone $record;
            $record->active = 0;
            $record->timemodified = time();
            $DB->update_record('local_kbacompany_procraci', $record);
            self::bump($model, $userid);
            self::log($companyuuid, $raciuuid, $userid, 'procmodel_raci_remove', $old, $record);
            $tx->allow_commit();
        } catch (\Throwable $e) {
            if (isset($tx)) { $tx->rollback($e); }
            throw $e;
        } finally { $lock->release(); }
    }

    private static function publication_issues(string $companyuuid, int $userid, \stdClass $model): array {
        global $DB;
        $issues = [];
        $processcontext = process_service::context($companyuuid, $userid, $model->processuuid, true);
        $process = $processcontext['selected'];
        if (!$process) { return ['process']; }
        if (!$process['links']['supplier']) { $issues[] = 'supplier'; }
        if (!$process['links']['customer']) { $issues[] = 'customer'; }
        if ($process['product_uuid'] === '') { $issues[] = 'output'; }
        $elements = $DB->get_records('local_kbacompany_procelem',
            ['companyuuid' => $companyuuid, 'modelid' => $model->id, 'active' => 1], 'sortorder ASC, id ASC');
        $inputs = []; $nodes = []; $starts = []; $ends = []; $tasks = [];
        foreach ($elements as $element) {
            if ($element->elementtype === 'input') { $inputs[] = $element; }
            if (in_array($element->elementtype, self::BPMN_NODE_TYPES, true)) {
                $nodes[$element->uuid] = $element;
                if ($element->elementtype === 'start') { $starts[] = $element->uuid; }
                if ($element->elementtype === 'end') { $ends[] = $element->uuid; }
                if ($element->elementtype === 'task') {
                    $tasks[] = $element->uuid;
                    if (trim((string)$element->description) === '' || trim((string)$element->executortype) === '' ||
                            trim((string)$element->executoruuid) === '' ||
                            (trim((string)$element->productuuid) === '' && trim((string)$element->outputtext) === '')) {
                        $issues[] = 'task';
                    }
                }
            }
        }
        if (!$inputs) { $issues[] = 'input'; }
        if (!$starts) { $issues[] = 'start'; }
        if (!$tasks) { $issues[] = 'task'; }
        if (!$ends) { $issues[] = 'end'; }
        if ($nodes && $starts && $ends) {
            $adj = []; $reverse = [];
            foreach ($DB->get_records('local_kbacompany_procflow',
                    ['companyuuid' => $companyuuid, 'modelid' => $model->id, 'active' => 1]) as $flow) {
                if (!isset($nodes[$flow->fromuuid]) || !isset($nodes[$flow->touuid])) {
                    $issues[] = 'flow'; continue;
                }
                $adj[$flow->fromuuid][] = $flow->touuid;
                $reverse[$flow->touuid][] = $flow->fromuuid;
            }
            $walk = static function(array $seeds, array $graph): array {
                $seen = []; $stack = $seeds;
                while ($stack) {
                    $current = array_pop($stack);
                    if (isset($seen[$current])) { continue; }
                    $seen[$current] = true;
                    foreach ($graph[$current] ?? [] as $next) {
                        if (!isset($seen[$next])) { $stack[] = $next; }
                    }
                }
                return $seen;
            };
            $fromstart = $walk($starts, $adj);
            $toend = $walk($ends, $reverse);
            foreach (array_keys($nodes) as $nodeuuid) {
                if (!isset($fromstart[$nodeuuid]) || !isset($toend[$nodeuuid])) {
                    $issues[] = 'reachability'; break;
                }
            }
        }
        return array_values(array_unique($issues));
    }

    public static function publish(string $companyuuid, int $userid, string $processuuid, int $modelid,
            int $expectedchangeversion): void {
        global $DB;
        self::authorise($companyuuid, $userid);
        $lock = \core\lock\lock_config::get_lock_factory('local_kbacompany')
            ->get_lock('process_model:' . $companyuuid . ':' . $processuuid, 10);
        if (!$lock) { self::fail('lock'); }
        try {
            self::authorise($companyuuid, $userid);
            self::process($companyuuid, $userid, $processuuid);
            $tx = $DB->start_delegated_transaction();
            $model = self::require_draft($companyuuid, $processuuid, $modelid, $expectedchangeversion);
            $publishedprocess = self::process($companyuuid, $userid, $processuuid, true);
            $issues = self::publication_issues($companyuuid, $userid, $model);
            if ($issues) {
                throw new \moodle_exception('procmodel_incomplete', 'local_kbacompany', '', implode(', ', $issues));
            }
            $old = clone $model;
            if (!self::bpmn_file($modelid)) {
                $xml=self::generate_bpmn_xml($companyuuid,$modelid,(string)$publishedprocess->name);
                self::store_bpmn_file($modelid,$userid,$xml);
            }
            $model->processrevision = (int)$publishedprocess->revision;
            $model->status = 'published';
            $model->changeversion = (int)$model->changeversion + 1;
            $model->modifiedby = $userid;
            $model->timemodified = time();
            $DB->update_record('local_kbacompany_procmodel', $model);
            self::log($companyuuid, $processuuid, $userid, 'procmodel_publish', $old, $model);
            $tx->allow_commit();
        } catch (\Throwable $e) {
            if (isset($tx)) { $tx->rollback($e); }
            throw $e;
        } finally { $lock->release(); }
    }

    private static function executor_label(string $type, string $uuid, array $orgunits, array $positions, array $roles): string {
        if ($type === 'orgunit') { return $orgunits[$uuid] ?? ''; }
        if ($type === 'position') { return $positions[$uuid] ?? ''; }
        if ($type === 'role') { return $roles[$uuid] ?? ''; }
        return '';
    }

    /**
     * Read-only integrity check between the IDEF0 I/O projection and the SIPOC I/O projection.
     * Both views intentionally reuse the same canonical Process Model; mismatches therefore indicate projection drift,
     * while missing product bindings are reported separately as formalisation issues.
     */
    private static function idef0_sipoc_consistency(array $process, array $elements, array $bytype,
            array $sipocoutputs): array {
        $idef0inputs=[];
        $idef0outputs=[];
        foreach ($elements as $element) {
            if (($element['type'] ?? '') === 'input') { $idef0inputs[(string)$element['uuid']]=$element; }
            if (($element['type'] ?? '') === 'output') { $idef0outputs[(string)$element['uuid']]=$element; }
        }
        $sipocinputs=[];
        foreach (($bytype['input'] ?? []) as $element) { $sipocinputs[(string)$element['uuid']]=$element; }
        $sipocsecondary=[];
        $sipocprimary='';
        foreach ($sipocoutputs as $output) {
            if (!empty($output['primary'])) { $sipocprimary=(string)($output['product_uuid'] ?? ''); continue; }
            $sipocsecondary[(string)$output['uuid']]=$output;
        }
        $inputsignature=static function(array $items): array {
            $result=[];foreach ($items as $uuid=>$item) { $result[$uuid]=(string)($item['product_uuid']??''); }
            ksort($result);return $result;
        };
        $outputsignature=$inputsignature;
        $inputsmatch=$inputsignature($idef0inputs)===$inputsignature($sipocinputs);
        $outputsmatch=$outputsignature($idef0outputs)===$outputsignature($sipocsecondary);
        $primaryuuid=(string)($process['product_uuid'] ?? '');
        $primarymatch=$primaryuuid===$sipocprimary;

        $issues=[];
        if (!$inputsmatch) { $issues[]=['type'=>'input_projection_mismatch']; }
        if (!$outputsmatch) { $issues[]=['type'=>'output_projection_mismatch']; }
        if (!$primarymatch) { $issues[]=['type'=>'primary_projection_mismatch']; }
        if ($primaryuuid==='') { $issues[]=['type'=>'primary_product_missing']; }
        foreach ($sipocinputs as $element) {
            if (trim((string)($element['product_uuid'] ?? ''))==='') {
                $issues[]=['type'=>'input_unbound','element_uuid'=>(string)$element['uuid'],
                    'element_name'=>(string)($element['name'] ?? '')];
            }
        }
        foreach ($sipocsecondary as $element) {
            if (trim((string)($element['product_uuid'] ?? ''))==='') {
                $issues[]=['type'=>'output_unbound','element_uuid'=>(string)$element['uuid'],
                    'element_name'=>(string)($element['name'] ?? '')];
            }
        }

        $structuralissues=0;$formalisationissues=0;
        foreach ($issues as $issue) {
            if (in_array($issue['type'],['input_projection_mismatch','output_projection_mismatch','primary_projection_mismatch'],true)) {
                $structuralissues++;
            } else { $formalisationissues++; }
        }
        $checks=3+count($sipocinputs)+count($sipocsecondary)+1;
        $passed=($inputsmatch?1:0)+($outputsmatch?1:0)+($primarymatch?1:0)+($primaryuuid!==''?1:0);
        foreach ($sipocinputs as $element) { if (trim((string)($element['product_uuid'] ?? ''))!=='') { $passed++; } }
        foreach ($sipocsecondary as $element) { if (trim((string)($element['product_uuid'] ?? ''))!=='') { $passed++; } }
        $status=$structuralissues>0?'inconsistent':($formalisationissues>0?'needs_formalisation':'consistent');
        return [
            'applicable'=>true,
            'status'=>$status,
            'score'=>$checks>0?(int)round($passed*100/$checks):100,
            'shared_canonical_core'=>true,
            'inputs'=>['idef0'=>count($idef0inputs),'sipoc'=>count($sipocinputs),'match'=>$inputsmatch],
            'outputs'=>['idef0'=>count($idef0outputs)+($primaryuuid!==''?1:0),
                'sipoc'=>count($sipocsecondary)+($sipocprimary!==''?1:0),'match'=>$outputsmatch && $primarymatch],
            'primary'=>['uuid'=>$primaryuuid,'match'=>$primarymatch,'present'=>$primaryuuid!==''],
            'canonical'=>['total'=>count($sipocinputs)+count($sipocsecondary)+1,
                'bound'=>$passed-(($inputsmatch?1:0)+($outputsmatch?1:0)+($primarymatch?1:0))],
            'structural_issues'=>$structuralissues,
            'formalisation_issues'=>$formalisationissues,
            'issue_count'=>count($issues),
            'items'=>$issues,
        ];
    }

    /**
     * Bulk read-only diagnostics for the Process Architecture workspace.
     * Visibility mirrors context(): managers prefer the current draft, readers see the latest published model.
     * All model and element reads are scoped to the authorised Company before any projection is built.
     */
    public static function architecture_diagnostics(string $companyuuid, int $userid, array $processes): array {
        global $DB;
        company_context_service::context($companyuuid, $userid);
        require_capability('local/kbacompany:viewowncompany', \context_system::instance(), $userid);
        if (!$processes) { return []; }
        $canmanage=self::can_manage($companyuuid,$userid);
        $processids=[];$processmap=[];
        foreach ($processes as $process) {
            $uuid=(string)($process['uuid']??'');
            if ($uuid==='') { continue; }
            $processids[]=$uuid;$processmap[$uuid]=$process;
        }
        if (!$processids) { return []; }
        [$processsql,$processparams]=$DB->get_in_or_equal($processids,SQL_PARAMS_NAMED,'archproc');
        $models=$DB->get_records_sql("SELECT m.* FROM {local_kbacompany_procmodel} m
            WHERE m.companyuuid=:company AND m.processuuid $processsql
            AND m.status IN ('draft','published') ORDER BY m.processuuid ASC,m.revision DESC,m.id DESC",
            ['company'=>$companyuuid]+$processparams);
        $drafts=[];$published=[];
        foreach ($models as $model) {
            $key=(string)$model->processuuid;
            if ($model->status==='draft' && (!isset($drafts[$key]) || (int)$model->revision>(int)$drafts[$key]->revision)) {
                $drafts[$key]=$model;
            }
            if ($model->status==='published' && (!isset($published[$key]) || (int)$model->revision>(int)$published[$key]->revision)) {
                $published[$key]=$model;
            }
        }
        $selected=[];$modeltoprocess=[];$modelids=[];
        foreach ($processmap as $uuid=>$process) {
            $model=$canmanage?($drafts[$uuid]??($published[$uuid]??null)):($published[$uuid]??null);
            if ($model) {
                $selected[$uuid]=$model;$modelids[]=(int)$model->id;$modeltoprocess[(int)$model->id]=$uuid;
            }
        }
        $elements=[];
        if ($modelids) {
            [$modelsql,$modelparams]=$DB->get_in_or_equal($modelids,SQL_PARAMS_NAMED,'archmodel');
            $rows=$DB->get_records_sql("SELECT e.* FROM {local_kbacompany_procelem} e
                WHERE e.companyuuid=:company AND e.modelid $modelsql AND e.active=1
                AND e.elementtype IN ('input','output') ORDER BY e.modelid ASC,e.sortorder ASC,e.id ASC",
                ['company'=>$companyuuid]+$modelparams);
            foreach ($rows as $row) {
                $processuuid=$modeltoprocess[(int)$row->modelid]??'';
                if ($processuuid==='') { continue; }
                $elements[$processuuid][]= [
                    'uuid'=>(string)$row->uuid,'type'=>(string)$row->elementtype,'code'=>(string)$row->code,
                    'name'=>(string)$row->name,'description'=>(string)$row->description,
                    'product_uuid'=>(string)$row->productuuid,'product_name'=>'','output'=>(string)$row->outputtext,
                ];
            }
        }
        $result=[];
        foreach ($processmap as $uuid=>$process) {
            $model=$selected[$uuid]??null;$items=$elements[$uuid]??[];$bytype=['input'=>[],'output'=>[]];
            foreach ($items as $item) { $bytype[$item['type']][]=$item; }
            $outputs=[['uuid'=>(string)($process['product_uuid']??''),'name'=>(string)($process['product_name']??''),
                'description'=>'','product_uuid'=>(string)($process['product_uuid']??''),
                'product_name'=>(string)($process['product_name']??''),'output'=>'','primary'=>true]];
            foreach ($bytype['output'] as $output) {
                $outputs[]=['uuid'=>$output['uuid'],'name'=>$output['name'],'description'=>$output['description'],
                    'product_uuid'=>$output['product_uuid'],'product_name'=>'','output'=>$output['output'],'primary'=>false];
            }
            $consistency=$model?self::idef0_sipoc_consistency($process,$items,$bytype,$outputs):['applicable'=>false,'status'=>'not_available','score'=>0,'issue_count'=>0];
            $result[$uuid]=[
                'model'=>$model?['id'=>(int)$model->id,'revision'=>(int)$model->revision,
                    'status'=>(string)$model->status,'change_version'=>(int)$model->changeversion]:null,
                'consistency'=>$consistency,
                'inputs'=>$bytype['input'],'outputs'=>$bytype['output'],
            ];
        }
        return $result;
    }

    /** Read one process model including SIPOC and BPMN views. */
    public static function context(string $companyuuid, int $userid, string $processuuid): array {
        global $DB;
        $company = company_context_service::context($companyuuid, $userid);
        require_capability('local/kbacompany:viewowncompany', \context_system::instance(), $userid);
        $processcontext = process_service::context($companyuuid, $userid, $processuuid, true);
        $process = $processcontext['selected'];
        if (!$process) { self::fail('process'); }
        $canmanage = self::can_manage($companyuuid, $userid);
        $model = self::visible_model($companyuuid, $processuuid, $canmanage);

        $positions = [];
        foreach ($DB->get_records('local_kbacompany_position', ['companyuuid' => $companyuuid, 'active' => 1], 'code ASC') as $row) {
            $positions[$row->uuid] = (string)$row->name;
        }
        $orgunits = [];
        foreach ($DB->get_records('local_kbacompany_orgnode', ['companyuuid' => $companyuuid, 'active' => 1], 'code ASC') as $row) {
            $orgunits[$row->uuid] = (string)$row->name;
        }
        $roles = [];
        $rolecontext = business_role_service::business_roles_context($companyuuid, $userid);
        foreach ($rolecontext['roles'] as $role) { $roles[$role['uuid']] = $role['name']; }
        $products = [];
        foreach ($DB->get_records('local_kbacompany_product', ['companyuuid' => $companyuuid], 'code ASC') as $row) {
            $products[$row->uuid] = (string)$row->name;
        }

        $elements = []; $flows = []; $raci = [];
        if ($model) {
            foreach ($DB->get_records('local_kbacompany_procelem',
                    ['companyuuid' => $companyuuid, 'modelid' => $model->id, 'active' => 1], 'sortorder ASC, id ASC') as $row) {
                $elements[] = [
                    'uuid' => (string)$row->uuid, 'type' => (string)$row->elementtype,
                    'code' => (string)$row->code, 'name' => (string)$row->name,
                    'description' => (string)$row->description, 'executor_type' => (string)$row->executortype,
                    'executor_uuid' => (string)$row->executoruuid,
                    'executor_name' => self::executor_label((string)$row->executortype, (string)$row->executoruuid,
                        $orgunits, $positions, $roles),
                    'lane_uuid' => (string)$row->laneuuid, 'product_uuid' => (string)$row->productuuid,
                    'product_name' => $products[$row->productuuid] ?? '', 'output' => (string)$row->outputtext,
                    'activity_uuid' => (string)$row->activityuuid, 'sort_order' => (int)$row->sortorder,
                ];
            }
            foreach ($DB->get_records('local_kbacompany_procflow',
                    ['companyuuid' => $companyuuid, 'modelid' => $model->id, 'active' => 1], 'sortorder ASC, id ASC') as $row) {
                $flows[] = [
                    'uuid' => (string)$row->uuid, 'from_uuid' => (string)$row->fromuuid,
                    'to_uuid' => (string)$row->touuid, 'label' => (string)$row->label,
                    'condition' => (string)$row->conditiontext, 'sort_order' => (int)$row->sortorder,
                ];
            }
            foreach ($DB->get_records('local_kbacompany_procraci',
                    ['companyuuid' => $companyuuid, 'modelid' => $model->id, 'active' => 1], 'activityuuid ASC, id ASC') as $row) {
                $raci[] = [
                    'uuid' => (string)$row->uuid, 'activity_uuid' => (string)$row->activityuuid,
                    'party_type' => (string)$row->partytype, 'party_uuid' => (string)$row->partyuuid,
                    'party_name' => self::executor_label((string)$row->partytype, (string)$row->partyuuid,
                        $orgunits, $positions, $roles), 'role' => (string)$row->racirole,
                ];
            }
        }

        $bytype = [];
        foreach (self::ELEMENT_TYPES as $type) { $bytype[$type] = []; }
        foreach ($elements as $element) { $bytype[$element['type']][] = $element; }
        $outputs = [['uuid' => $process['product_uuid'], 'name' => $process['product_name'], 'description' => '',
            'product_uuid' => $process['product_uuid'], 'product_name' => $process['product_name'], 'output' => '', 'primary' => true]];
        foreach ($bytype['output'] as $output) {
            $outputs[] = ['uuid' => $output['uuid'],
                'name' => $output['product_name'] !== '' ? $output['product_name'] : $output['name'],
                'description' => $output['description'], 'product_uuid' => $output['product_uuid'],
                'product_name' => $output['product_name'], 'output' => $output['output'], 'primary' => false];
        }

        $history = [];
        $historyparams=['companyuuid'=>$companyuuid,'processuuid'=>$processuuid];
        if (!$canmanage) { $historyparams['status']='published'; }
        foreach ($DB->get_records('local_kbacompany_procmodel',$historyparams,'revision DESC','*',0,25) as $row) {
            $history[] = [
                'id' => (int)$row->id, 'revision' => (int)$row->revision,
                'process_revision' => (int)$row->processrevision, 'status' => (string)$row->status,
                'change_version' => (int)$row->changeversion, 'created_by' => (int)$row->createdby,
                'modified_by' => (int)$row->modifiedby, 'time_created' => (int)$row->timecreated,
                'time_modified' => (int)$row->timemodified,
            ];
        }

        $bpmnxml = $model ? self::current_bpmn_xml($companyuuid,(int)$model->id,(string)$process['name']) : ['xml'=>'','stored'=>false,'hash'=>''];
        $idef0sipoc=$model?self::idef0_sipoc_consistency($process,$elements,$bytype,$outputs):['applicable'=>false];

        return [
            'company' => $company, 'process' => $process,
            'model' => $model ? [
                'id' => (int)$model->id, 'revision' => (int)$model->revision,
                'process_revision' => (int)$model->processrevision, 'change_version' => (int)$model->changeversion,
                'status' => (string)$model->status, 'scope' => (string)$model->scope,
                'trigger' => (string)$model->triggertext, 'start_condition' => (string)$model->startcondition,
                'end_condition' => (string)$model->endcondition,
            ] : null,
            'sipoc' => [
                'suppliers' => $process['links']['supplier'], 'inputs' => $bytype['input'],
                'outputs' => $outputs, 'customers' => $process['links']['customer'],
            ],
            'idef0_sipoc_consistency' => $idef0sipoc,
            'bpmn' => [
                'lanes' => $bytype['lane'],
                'nodes' => array_values(array_filter($elements, static function(array $element): bool {
                    return in_array($element['type'], self::BPMN_NODE_TYPES, true);
                })),
                'flows' => $flows, 'documents' => $bytype['document'], 'systems' => $bytype['system'],
                'resources' => $bytype['resource'], 'records' => $bytype['record'], 'controls' => $bytype['control'],
                'xml' => $bpmnxml['xml'], 'xml_stored' => $bpmnxml['stored'], 'xml_hash' => $bpmnxml['hash'],
            ],
            'raci' => $raci, 'elements' => $elements,
            'products' => array_map(static function(string $uuid, string $name): array {
                return ['uuid' => $uuid, 'name' => $name];
            }, array_keys($products), array_values($products)),
            'orgunits' => array_map(static function(string $uuid, string $name): array {
                return ['uuid' => $uuid, 'name' => $name];
            }, array_keys($orgunits), array_values($orgunits)),
            'positions' => array_map(static function(string $uuid, string $name): array {
                return ['uuid' => $uuid, 'name' => $name];
            }, array_keys($positions), array_values($positions)),
            'roles' => array_map(static function(string $uuid, string $name): array {
                return ['uuid' => $uuid, 'name' => $name];
            }, array_keys($roles), array_values($roles)),
            'history' => $history, 'can_manage' => $canmanage,
            'publication_issues' => $model && $model->status === 'draft'
                ? self::publication_issues($companyuuid, $userid, $model) : [],
        ];
    }

    /** Canonical semantic activities visible to the reader. Activity means a BPMN Task. */
    public static function semantic_activities(string $companyuuid, int $userid): array {
        global $DB;
        company_context_service::context($companyuuid, $userid);
        $canmanage = self::can_manage($companyuuid, $userid);
        $processes = [];
        foreach ($DB->get_records('local_kbacompany_process', ['companyuuid' => $companyuuid], 'uuid ASC, revision ASC') as $row) {
            $processes[$row->uuid][] = $row;
        }
        $result = [];
        foreach ($processes as $processuuid => $versions) {
            $latest = end($versions);
            if ($latest->status === 'archived') { continue; }
            $model = self::visible_model($companyuuid, $processuuid, $canmanage);
            if (!$model) { continue; }
            foreach ($DB->get_records('local_kbacompany_procelem', [
                    'companyuuid' => $companyuuid, 'modelid' => $model->id,
                    'elementtype' => 'task', 'active' => 1,
                ], 'sortorder ASC, id ASC') as $row) {
                $result[$row->uuid] = [
                    'type' => 'activity', 'uuid' => (string)$row->uuid, 'code' => (string)$row->code,
                    'name' => (string)$row->name, 'status' => (string)$model->status,
                    'process_uuid' => $processuuid, 'model_revision' => (int)$model->revision,
                ];
            }
        }
        return array_values($result);
    }

    public static function semantic_activity(string $companyuuid, int $userid, string $activityuuid): array {
        foreach (self::semantic_activities($companyuuid, $userid) as $activity) {
            if ($activity['uuid'] === $activityuuid) { return $activity; }
        }
        self::fail('element');
    }
}
