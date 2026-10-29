<?php
namespace local_kbacompany\local;

defined('MOODLE_INTERNAL') || die();

/** Versioned high-level process architecture for BMS. */
class process_service {
    public const CONTRACT_VERSION = '0.4.0';
    public const TYPES = ['management', 'development', 'core', 'support'];
    public const LEVELS = ['L0', 'L1', 'L2'];

    public static function contract(): array {
        $contract = [
            'component' => 'local_kbacompany',
            'contract' => 'bms_process_architecture',
            'version' => self::CONTRACT_VERSION,
            'company_scope' => 'required',
            'types' => self::TYPES,
            'levels' => self::LEVELS,
            'states' => ['draft', 'published', 'archived'],
            'interfaces' => [
                'supplier' => 'orgunit supplies process',
                'customer' => 'process supplies orgunit',
                'participant' => 'orgunit executes process',
                'metric' => 'metric measures process',
            ],
            'projections' => [
                'architecture_workspace',
                'architecture_analytics',
                'idef0_decomposition',
            ],
            'rules' => [
                'one_canonical_process_model',
                'append_only_revisions',
                'l0_has_no_parent',
                'l1_parent_is_l0',
                'l2_parent_is_l1',
                'published_process_requires_owner_and_product',
                'cross_functional_process_may_span_orgunits',
                'department_is_not_forced_to_equal_process',
                'sipoc_bpmn_model_available',
                'process_remains_canonical_parent_of_versioned_process_model',
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

    private static function require_view(string $companyuuid, int $userid): array {
        $company = company_context_service::context($companyuuid, $userid);
        require_capability('local/kbacompany:viewowncompany', \context_system::instance(), $userid);
        return $company;
    }

    private static function authorise(string $companyuuid, int $userid): void {
        self::require_view($companyuuid, $userid);
        if (!self::can_manage($companyuuid, $userid)) {
            throw new \required_capability_exception(\context_system::instance(),
                'local/kbacompany:manageorganization', 'nopermissions', '');
        }
    }

    private static function fail(string $key): void {
        throw new \moodle_exception('process_' . $key, 'local_kbacompany');
    }

    private static function uuid(): string {
        $bytes = random_bytes(16);
        $bytes[6] = chr((ord($bytes[6]) & 0x0f) | 0x40);
        $bytes[8] = chr((ord($bytes[8]) & 0x3f) | 0x80);
        $hex = bin2hex($bytes);
        return substr($hex, 0, 8) . '-' . substr($hex, 8, 4) . '-' . substr($hex, 12, 4) .
            '-' . substr($hex, 16, 4) . '-' . substr($hex, 20);
    }

    private static function text(array $data, string $field, int $max, bool $required = true): string {
        $value = trim((string)($data[$field] ?? ''));
        if (($required && $value === '') || \core_text::strlen($value) > $max) {
            self::fail('invalid');
        }
        return $value;
    }

    private static function latest_all(string $companyuuid): array {
        global $DB;
        $rows = $DB->get_records_sql('SELECT p.* FROM {local_kbacompany_process} p WHERE p.companyuuid=:company AND ' . self::visible_sql('p', true, true), ['company'=>$companyuuid]);
        $latest=[]; foreach ($rows as $row) { $latest[$row->uuid]=$row; }
        return $latest;
    }

    private static function latest(string $companyuuid, string $uuid): \stdClass {
        global $DB;
        $rows = $DB->get_records('local_kbacompany_process',
            ['companyuuid' => $companyuuid, 'uuid' => $uuid], 'revision DESC', '*', 0, 1);
        if (!$rows) {
            self::fail('reference');
        }
        return reset($rows);
    }

    private static function validate_parent(string $companyuuid, string $uuid, string $level, string $parentuuid): void {
        if ($level === 'L0') {
            if ($parentuuid !== '') {
                self::fail('parent');
            }
            return;
        }
        if ($parentuuid === '') {
            self::fail('parent');
        }
        $parent = self::latest($companyuuid, $parentuuid);
        if ($parent->status === 'archived') {
            self::fail('parent');
        }
        $expected = $level === 'L1' ? 'L0' : 'L1';
        if ($parent->processlevel !== $expected) {
            self::fail('parent');
        }
        $seen = [];
        $cursor = $parent;
        while ($cursor) {
            if ($cursor->uuid === $uuid || isset($seen[$cursor->uuid])) {
                self::fail('cycle');
            }
            $seen[$cursor->uuid] = true;
            if ($cursor->parentuuid === '') {
                break;
            }
            $cursor = self::latest($companyuuid, $cursor->parentuuid);
        }
    }

    private static function validate_references(string $companyuuid, string $ownerorgunituuid, string $productuuid): void {
        global $DB;
        if ($ownerorgunituuid !== '' && !$DB->record_exists('local_kbacompany_orgnode', [
                'companyuuid' => $companyuuid, 'uuid' => $ownerorgunituuid, 'active' => 1])) {
            self::fail('reference');
        }
        if ($productuuid !== '' && !$DB->record_exists('local_kbacompany_product', [
                'companyuuid' => $companyuuid, 'uuid' => $productuuid])) {
            self::fail('reference');
        }
    }

    private static function code_available(string $companyuuid, string $code, string $uuid): void {
        global $DB;
        if ($DB->record_exists_sql("SELECT p.id FROM {local_kbacompany_process} p WHERE p.companyuuid=:company AND p.code=:code AND p.uuid<>:uuid AND " . self::visible_sql('p',true), ['company'=>$companyuuid,'code'=>$code,'uuid'=>$uuid])) { self::fail('duplicatecode'); }
    }

    private static function log(string $companyuuid, string $uuid, int $actor, string $action, $old, $new): void {
        global $DB;
        $DB->insert_record('local_kbacompany_aclog', (object)[
            'companyuuid' => $companyuuid,
            'objectuuid' => $uuid,
            'action' => $action,
            'actorid' => $actor,
            'oldvalue' => $old ? json_encode($old, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : null,
            'newvalue' => $new ? json_encode($new, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : null,
            'timecreated' => time(),
        ]);
    }

    /** Create a new process or append a draft revision to an existing process. */
    public static function save(string $companyuuid, int $userid, array $data, string $uuid = '',
            int $expectedrevision = 0): string {
        global $DB;
        self::authorise($companyuuid, $userid);
        $lock = \core\lock\lock_config::get_lock_factory('local_kbacompany')
            ->get_lock('process_architecture:' . $companyuuid, 10);
        if (!$lock) {
            self::fail('lock');
        }
        try {
            self::authorise($companyuuid, $userid);
            $tx = $DB->start_delegated_transaction();
            $old = null;
            if ($uuid !== '') {
                $old = self::latest($companyuuid, $uuid);
                if ((int)$old->revision !== $expectedrevision) {
                    self::fail('conflict');
                }
                if ($old->status === 'archived') {
                    self::fail('state');
                }
            } else if ($expectedrevision !== 0) {
                self::fail('conflict');
            }
            $code = self::text($data, 'code', 50);
            $name = self::text($data, 'name', 255);
            $purpose = self::text($data, 'purpose', 4000, false);
            $type = self::text($data, 'processtype', 12);
            $level = self::text($data, 'processlevel', 2);
            $parentuuid = self::text($data, 'parentuuid', 36, false);
            $ownerorgunituuid = self::text($data, 'ownerorgunituuid', 36, false);
            $productuuid = self::text($data, 'productuuid', 36, false);
            if (!in_array($type, self::TYPES, true) || !in_array($level, self::LEVELS, true)) {
                self::fail('invalid');
            }
            $stableuuid = $old ? (string)$old->uuid : self::uuid();
            self::code_available($companyuuid, $code, $stableuuid);
            self::validate_parent($companyuuid, $stableuuid, $level, $parentuuid);
            self::validate_references($companyuuid, $ownerorgunituuid, $productuuid);
            if ($old && $old->processlevel !== $level) {
                $childlevel = $level === 'L0' ? 'L1' : ($level === 'L1' ? 'L2' : '');
                if ($DB->record_exists_sql("SELECT child.id FROM {local_kbacompany_process} child WHERE child.companyuuid=:company AND child.parentuuid=:parent AND child.processlevel<>:level AND " . self::visible_sql('child',true), ['company'=>$companyuuid,'parent'=>$stableuuid,'level'=>$childlevel])) { self::fail('parent'); }
            }

            $row = (object)[
                'companyuuid' => $companyuuid,
                'uuid' => $stableuuid,
                'revision' => $old ? ((int)$old->revision + 1) : 1,
                'code' => $code,
                'name' => $name,
                'processtype' => $type,
                'processlevel' => $level,
                'parentuuid' => $parentuuid,
                'ownerorgunituuid' => $ownerorgunituuid,
                'productuuid' => $productuuid,
                'purpose' => $purpose,
                'status' => 'draft',
                'createdby' => $userid,
                'timecreated' => time(),
            ];
            $DB->insert_record('local_kbacompany_process', $row);
            self::log($companyuuid, $stableuuid, $userid, 'proc_save', $old, $row);
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

    /** Publish the latest complete draft as a new immutable revision. */
    public static function publish(string $companyuuid, int $userid, string $uuid, int $expectedrevision,
            string $reason = ''): void {
        global $DB;
        self::authorise($companyuuid, $userid);
        if (\core_text::strlen($reason) > 4000) { self::fail('reason'); }
        $lock = \core\lock\lock_config::get_lock_factory('local_kbacompany')
            ->get_lock('process_architecture:' . $companyuuid, 10);
        if (!$lock) { self::fail('lock'); }
        try {
            self::authorise($companyuuid, $userid);
            $tx = $DB->start_delegated_transaction();
            $old = self::latest($companyuuid, $uuid);
            if ((int)$old->revision !== $expectedrevision) { self::fail('conflict'); }
            if ($old->status !== 'draft') { self::fail('state'); }
            if ($old->ownerorgunituuid === '' || $old->productuuid === '') { self::fail('incomplete'); }
            self::validate_parent($companyuuid, $uuid, $old->processlevel, $old->parentuuid);
            if ($old->parentuuid !== '') {
                $publishedparent = $DB->get_records('local_kbacompany_process', [
                    'companyuuid' => $companyuuid, 'uuid' => $old->parentuuid, 'status' => 'published',
                ], 'revision DESC', '*', 0, 1);
                if (!$publishedparent) { self::fail('parentnotpublished'); }
            }
            self::validate_references($companyuuid, $old->ownerorgunituuid, $old->productuuid);
            $row = clone $old;
            unset($row->id);
            $row->revision = (int)$old->revision + 1;
            $row->status = 'published';
            $row->createdby = $userid;
            $row->timecreated = time();
            $DB->insert_record('local_kbacompany_process', $row);
            self::log($companyuuid, $uuid, $userid, 'proc_publish', $old,
                trim($reason) === '' ? $row : ['record' => $row, 'reason' => trim($reason)]);
            $tx->allow_commit();
        } catch (\Throwable $e) {
            if (isset($tx)) { $tx->rollback($e); }
            throw $e;
        } finally {
            $lock->release();
        }
    }

    /** Archive the process with a retained revision and explicit reason. */
    public static function archive(string $companyuuid, int $userid, string $uuid, int $expectedrevision,
            string $reason): void {
        global $DB;
        self::authorise($companyuuid, $userid);
        if (trim($reason) === '' || \core_text::strlen($reason) > 4000) { self::fail('reason'); }
        $lock = \core\lock\lock_config::get_lock_factory('local_kbacompany')
            ->get_lock('process_architecture:' . $companyuuid, 10);
        if (!$lock) { self::fail('lock'); }
        $modellock = null;
        try {
            self::authorise($companyuuid, $userid);
            $modellock = \core\lock\lock_config::get_lock_factory('local_kbacompany')->get_lock('process_model:' . $companyuuid . ':' . $uuid, 10);
            if (!$modellock) { self::fail('lock'); }
            $tx = $DB->start_delegated_transaction();
            $old = self::latest($companyuuid, $uuid);
            if ((int)$old->revision !== $expectedrevision) { self::fail('conflict'); }
            if ($old->status === 'archived') { self::fail('state'); }
            if (self::crud_dependencies($companyuuid,$userid,$uuid)) { self::fail('referenced'); }
            $row = clone $old;
            unset($row->id);
            $row->revision = (int)$old->revision + 1;
            $row->status = 'archived';
            $row->createdby = $userid;
            $row->timecreated = time();
            $DB->insert_record('local_kbacompany_process', $row);
            self::log($companyuuid, $uuid, $userid, 'proc_archive', $old, ['record' => $row, 'reason' => trim($reason)]);
            $tx->allow_commit();
        } catch (\Throwable $e) {
            if (isset($tx)) { $tx->rollback($e); }
            throw $e;
        } finally {
            if ($modellock) { $modellock->release(); }
            $lock->release();
        }
    }

    /** Add one cross-domain process relation through Semantic Core. */
    public static function link(string $companyuuid, int $userid, string $processuuid, string $linktype,
            string $targetuuid, string $reason = ''): string {
        self::authorise($companyuuid, $userid);
        $process = self::latest($companyuuid, $processuuid);
        if ($process->status === 'archived') { self::fail('state'); }
        $map = [
            'supplier' => ['orgunit', $targetuuid, 'supplies', 'process', $processuuid],
            'customer' => ['process', $processuuid, 'supplies', 'orgunit', $targetuuid],
            'participant' => ['orgunit', $targetuuid, 'executes', 'process', $processuuid],
            'metric' => ['metric', $targetuuid, 'measures', 'process', $processuuid],
        ];
        if (!isset($map[$linktype])) { self::fail('invalid'); }
        [$fromtype, $fromuuid, $relation, $totype, $touuid] = $map[$linktype];
        return semantic_service::command_relation($companyuuid, $userid, 'create', [
            'fromtype' => $fromtype, 'fromuuid' => $fromuuid, 'relation' => $relation,
            'totype' => $totype, 'touuid' => $touuid, 'reason' => trim($reason),
        ]);
    }

    public static function unlink(string $companyuuid, int $userid, string $relationuuid, int $revision,
            string $reason): void {
        self::authorise($companyuuid, $userid);
        semantic_service::command_relation($companyuuid, $userid, 'archive', [
            'object' => $relationuuid, 'revision' => $revision, 'reason' => $reason,
        ]);
    }

    /** Current context: managers see latest draft; other users see only effective published process. */
    public static function context(string $companyuuid, int $userid, string $selecteduuid = '', bool $selectedonly = false): array {
        global $DB;
        $company = self::require_view($companyuuid, $userid);
        $canmanage = self::can_manage($companyuuid, $userid);
        $params=['company'=>$companyuuid];
        $where='p.companyuuid=:company AND '.self::visible_sql('p',$canmanage);
        if ($selectedonly) { $where.=' AND p.uuid=:selected'; $params['selected']=$selecteduuid; }
        $visible = $DB->get_records_sql('SELECT p.* FROM {local_kbacompany_process} p WHERE '.$where, $params);
        $processes=[]; foreach ($visible as $row) { $processes[$row->uuid]=$row; }

        $nodes = $DB->get_records('local_kbacompany_orgnode', ['companyuuid' => $companyuuid, 'active' => 1], 'code ASC');
        $nodebyuuid = [];
        foreach ($nodes as $node) { $nodebyuuid[$node->uuid] = $node; }
        $products = $DB->get_records('local_kbacompany_product', ['companyuuid' => $companyuuid], 'code ASC');
        $productbyuuid = [];
        foreach ($products as $product) { $productbyuuid[$product->uuid] = $product; }
        $metrics = $DB->get_records('local_kbacompany_stat', ['companyuuid' => $companyuuid], 'code ASC');
        $metricbyuuid = [];
        foreach ($metrics as $metric) { $metricbyuuid[$metric->uuid] = $metric; }

        $relparams=['company'=>$companyuuid];
        $relwhere="r.companyuuid=:company AND r.status='active' AND NOT EXISTS (SELECT 1 FROM {local_kbacompany_semrel} newer WHERE newer.companyuuid=r.companyuuid AND newer.uuid=r.uuid AND newer.revision>r.revision)
            AND ((r.totype='process' AND ((r.fromtype='orgunit' AND r.relation IN ('supplies','executes')) OR (r.fromtype='metric' AND r.relation='measures')))
              OR (r.fromtype='process' AND r.totype='orgunit' AND r.relation='supplies'))";
        if ($selectedonly) { $relwhere.=" AND ((r.fromtype='process' AND r.fromuuid=:fromproc) OR (r.totype='process' AND r.touuid=:toproc))"; $relparams['fromproc']=$selecteduuid; $relparams['toproc']=$selecteduuid; }
        $latestrelations=$DB->get_records_sql('SELECT r.* FROM {local_kbacompany_semrel} r WHERE '.$relwhere,$relparams);
        $links = [];
        foreach ($latestrelations as $relation) {
            if ($relation->status !== 'active') { continue; }
            $processuuid = '';
            $kind = '';
            $targetuuid = '';
            if ($relation->totype === 'process' && isset($processes[$relation->touuid])) {
                $processuuid = $relation->touuid;
                if ($relation->fromtype === 'orgunit' && $relation->relation === 'supplies') {
                    $kind = 'supplier'; $targetuuid = $relation->fromuuid;
                } else if ($relation->fromtype === 'orgunit' && $relation->relation === 'executes') {
                    $kind = 'participant'; $targetuuid = $relation->fromuuid;
                } else if ($relation->fromtype === 'metric' && $relation->relation === 'measures') {
                    $kind = 'metric'; $targetuuid = $relation->fromuuid;
                }
            } else if ($relation->fromtype === 'process' && isset($processes[$relation->fromuuid]) &&
                    $relation->relation === 'supplies' && $relation->totype === 'orgunit') {
                $processuuid = $relation->fromuuid; $kind = 'customer'; $targetuuid = $relation->touuid;
            }
            if ($processuuid !== '' && $kind !== '') {
                $links[$processuuid][] = [
                    'relation_uuid' => (string)$relation->uuid,
                    'revision' => (int)$relation->revision,
                    'kind' => $kind,
                    'target_uuid' => (string)$targetuuid,
                ];
            }
        }

        $dtos = [];
        foreach ($processes as $row) {
            $dto = [
                'uuid' => (string)$row->uuid,
                'revision' => (int)$row->revision,
                'code' => (string)$row->code,
                'name' => (string)$row->name,
                'type' => (string)$row->processtype,
                'level' => (string)$row->processlevel,
                'parent_uuid' => (string)$row->parentuuid,
                'owner_orgunit_uuid' => (string)$row->ownerorgunituuid,
                'owner_name' => isset($nodebyuuid[$row->ownerorgunituuid]) ? (string)$nodebyuuid[$row->ownerorgunituuid]->name : '',
                'product_uuid' => (string)$row->productuuid,
                'product_name' => isset($productbyuuid[$row->productuuid]) ? (string)$productbyuuid[$row->productuuid]->name : '',
                'purpose' => (string)$row->purpose,
                'status' => (string)$row->status,
                'links' => ['supplier' => [], 'customer' => [], 'participant' => [], 'metric' => []],
            ];
            foreach ($links[$row->uuid] ?? [] as $link) {
                if ($link['kind'] === 'metric') {
                    $target = $metricbyuuid[$link['target_uuid']] ?? null;
                } else {
                    $target = $nodebyuuid[$link['target_uuid']] ?? null;
                }
                $link['target_name'] = $target ? (string)$target->name : '';
                $dto['links'][$link['kind']][] = $link;
            }
            $dtos[] = $dto;
        }
        usort($dtos, static function(array $a, array $b): int {
            return [array_search($a['type'], self::TYPES, true), array_search($a['level'], self::LEVELS, true), $a['code']]
                <=> [array_search($b['type'], self::TYPES, true), array_search($b['level'], self::LEVELS, true), $b['code']];
        });
        $selected = null;
        foreach ($dtos as $dto) {
            if ($selecteduuid !== '' && $dto['uuid'] === $selecteduuid) { $selected = $dto; break; }
        }
        $summary = array_fill_keys(self::TYPES, 0);
        foreach ($dtos as $dto) { $summary[$dto['type']]++; }
        return [
            'company' => $company,
            'processes' => $dtos,
            'selected' => $selected,
            'orgunits' => array_values(array_map(static function(\stdClass $node): array {
                return ['uuid' => (string)$node->uuid, 'code' => (string)$node->code, 'name' => (string)$node->name];
            }, $nodes)),
            'products' => array_values(array_map(static function(\stdClass $product): array {
                return ['uuid' => (string)$product->uuid, 'code' => (string)$product->code, 'name' => (string)$product->name];
            }, $products)),
            'metrics' => array_values(array_map(static function(\stdClass $metric): array {
                return ['uuid' => (string)$metric->uuid, 'code' => (string)$metric->code, 'name' => (string)$metric->name];
            }, $metrics)),
            'can_manage' => $canmanage,
            'summary' => ['total' => count($dtos)] + $summary,
        ];
    }

    /** Pure boundary summary for the overview workspace over already company-scoped canonical data. */
    private static function architecture_boundary_summary(array $parent, array $children, array $edges,
            array $diagnostics): array {
        if (!$children) { return ['applicable'=>false,'score'=>100,'issues'=>0,'total'=>0,'balanced'=>0]; }
        $parentdiag=$diagnostics[$parent['uuid']]??['inputs'=>[],'outputs'=>[]];
        $childinputs=[];
        foreach ($children as $child) {
            $products=[];
            foreach (($diagnostics[$child['uuid']]['inputs']??[]) as $input) {
                $product=(string)($input['product_uuid']??''); if ($product!=='') { $products[$product]=true; }
            }
            $childinputs[$child['uuid']]=$products;
        }
        $total=0;$balanced=0;$issues=0;
        foreach (($parentdiag['inputs']??[]) as $input) {
            $total++;$product=(string)($input['product_uuid']??'');$covered=false;
            if ($product!=='') {
                foreach ($childinputs as $products) { if (isset($products[$product])) { $covered=true;break; } }
            }
            if ($covered) { $balanced++; } else { $issues++; }
        }
        $outgoing=[];
        foreach ($edges as $edge) {
            $total++;$outgoing[$edge['from_uuid']]=true;
            $source='';foreach ($children as $child) { if ($child['uuid']===$edge['from_uuid']) { $source=(string)$child['product_uuid'];break; } }
            if ($source!=='' && isset($childinputs[$edge['to_uuid']][$source])) { $balanced++; } else { $issues++; }
        }
        $parentoutputs=[];$parentproduct=(string)($parent['product_uuid']??'');
        if ($parentproduct!=='') { $parentoutputs[$parentproduct]=true; }
        foreach (($parentdiag['outputs']??[]) as $output) {
            $product=(string)($output['product_uuid']??'');
            if ($product!=='') { $parentoutputs[$product]=true; } else { $total++;$issues++; }
        }
        $terminalproducts=[];
        foreach ($children as $child) {
            if (!empty($outgoing[$child['uuid']])) { continue; }
            $total++;$product=(string)($child['product_uuid']??'');
            if ($product!=='') { $terminalproducts[$product]=true; }
            if ($product!=='' && isset($parentoutputs[$product])) { $balanced++; } else { $issues++; }
        }
        $total++;
        if ($parentproduct!=='' && isset($terminalproducts[$parentproduct])) { $balanced++; } else { $issues++; }
        return ['applicable'=>true,'score'=>$total>0?(int)round(($balanced*100)/$total):100,
            'issues'=>$issues,'total'=>$total,'balanced'=>$balanced];
    }

    /** Assign presentation-only IDEF0 notation over one visible Process hierarchy. */
    private static function architecture_notation(array &$nodes, array $children, string $uuid, string $notation): void {
        if (!isset($nodes[$uuid])) { return; }
        $nodes[$uuid]['idef0_notation']=$notation;
        $index=0;
        foreach ($children[$uuid]??[] as $childuuid) {
            $index++;
            if (!isset($nodes[$childuuid])) { continue; }
            $childnotation='A?';
            if ($nodes[$childuuid]['level']==='L1' && $nodes[$uuid]['level']==='L0') { $childnotation='A'.$index; }
            else if ($nodes[$childuuid]['level']==='L2' && $nodes[$uuid]['level']==='L1' && $notation!=='A?') { $childnotation=$notation.$index; }
            self::architecture_notation($nodes,$children,$childuuid,$childnotation);
        }
    }

    /**
     * Full read-only Process Architecture workspace. Unlike registry browse it is not paginated, because ancestors and
     * descendants must remain coherent on one map. Filtering happens only after the Company-scoped visible set is read.
     */
    public static function architecture_workspace(string $companyuuid, int $userid, array $filters=[]): array {
        global $DB;
        $company=self::require_view($companyuuid,$userid);$manager=self::can_manage($companyuuid,$userid);
        $f=self::crud_filters($filters);$f['layout']='architecture';
        $rows=$DB->get_records_sql(self::record_select($manager).' WHERE p.companyuuid=:company AND '.self::visible_sql('p',$manager).
            " ORDER BY CASE p.processtype WHEN 'management' THEN 0 WHEN 'development' THEN 1 WHEN 'core' THEN 2 ELSE 3 END,
            p.processlevel ASC,p.code ASC,p.uuid ASC",['company'=>$companyuuid]);
        $nodes=[];$summary=array_fill_keys(self::TYPES,0);
        foreach ($rows as $row) { $dto=self::record_dto($row);$nodes[$dto['uuid']]=$dto;$summary[$dto['type']]++; }
        $children=[];
        foreach ($nodes as $uuid=>$node) { if ($node['parent_uuid']!=='' && isset($nodes[$node['parent_uuid']])) { $children[$node['parent_uuid']][]=$uuid; } }
        foreach ($children as &$ids) {
            usort($ids,static function(string $a,string $b) use (&$nodes): int { return [$nodes[$a]['code'],$a]<=>[$nodes[$b]['code'],$b]; });
        } unset($ids);
        foreach ($nodes as $uuid=>$node) {
            if ($node['parent_uuid']==='' || !isset($nodes[$node['parent_uuid']])) {
                self::architecture_notation($nodes,$children,$uuid,$node['level']==='L0'?'A-0':'A?');
            }
        }
        $diagnostics=process_model_service::architecture_diagnostics($companyuuid,$userid,array_values($nodes));
        $edgesbyparent=[];
        if ($nodes) {
            $ids=array_keys($nodes);
            [$fromsql,$fromparams]=$DB->get_in_or_equal($ids,SQL_PARAMS_NAMED,'archfrom');
            [$tosql,$toparams]=$DB->get_in_or_equal($ids,SQL_PARAMS_NAMED,'archto');
            $relations=$DB->get_records_sql("SELECT r.* FROM {local_kbacompany_semrel} r
                WHERE r.companyuuid=:company AND r.status='active' AND r.fromtype='process' AND r.totype='process'
                AND r.relation='supplies' AND r.fromuuid $fromsql AND r.touuid $tosql AND r.fromuuid<>r.touuid
                AND NOT EXISTS (SELECT 1 FROM {local_kbacompany_semrel} newer
                    WHERE newer.companyuuid=r.companyuuid AND newer.uuid=r.uuid AND newer.revision>r.revision)",
                ['company'=>$companyuuid]+$fromparams+$toparams);
            foreach ($relations as $relation) {
                if (!isset($nodes[$relation->fromuuid],$nodes[$relation->touuid])) { continue; }
                $fromparent=(string)$nodes[$relation->fromuuid]['parent_uuid'];$toparent=(string)$nodes[$relation->touuid]['parent_uuid'];
                if ($fromparent==='' || $fromparent!==$toparent || !isset($nodes[$fromparent])) { continue; }
                $edgesbyparent[$fromparent][]=['from_uuid'=>(string)$relation->fromuuid,'to_uuid'=>(string)$relation->touuid];
            }
        }
        $workspace=['total'=>count($nodes),'published'=>0,'modelled'=>0,'ready'=>0,'warning'=>0,'critical'=>0,'empty'=>0];
        foreach ($nodes as $uuid=>&$node) {
            $diag=$diagnostics[$uuid]??['model'=>null,'consistency'=>['applicable'=>false,'status'=>'not_available','score'=>0]];
            $modelstatus=$diag['model']?(string)$diag['model']['status']:'none';
            $consistency=$diag['consistency']??['applicable'=>false,'status'=>'not_available','score'=>0];
            $direct=[];foreach ($children[$uuid]??[] as $childuuid) { $direct[]=$nodes[$childuuid]; }
            $boundary=self::architecture_boundary_summary($node,$direct,$edgesbyparent[$uuid]??[],$diagnostics);
            $checks=[
                $node['status']==='published', $node['owner_orgunit_uuid']!=='', $node['product_uuid']!=='', $modelstatus==='published',
            ];
            if (!empty($consistency['applicable'])) { $checks[]=(string)$consistency['status']==='consistent'; }
            if (!empty($boundary['applicable'])) { $checks[]=(int)$boundary['issues']===0; }
            $passed=0;foreach ($checks as $check) { if ($check) { $passed++; } }
            $score=$checks?(int)round(($passed*100)/count($checks)):100;
            $health='warning';
            if ($modelstatus==='none' && $node['owner_orgunit_uuid']==='' && $node['product_uuid']==='') { $health='empty'; }
            else if ((!empty($boundary['applicable']) && (int)$boundary['issues']>0) || (string)($consistency['status']??'')==='inconsistent') { $health='critical'; }
            else if ($score===100) { $health='ready'; }
            $node['architecture']=[
                'health'=>$health,'score'=>$score,'model_status'=>$modelstatus,
                'model_revision'=>$diag['model']?(int)$diag['model']['revision']:0,
                'consistency_status'=>(string)($consistency['status']??'not_available'),
                'consistency_score'=>(int)($consistency['score']??0),
                'boundary'=>$boundary,
            ];
            if ($node['status']==='published') { $workspace['published']++; }
            if ($modelstatus!=='none') { $workspace['modelled']++; }
            $workspace[$health]++;
        } unset($node);
        $workspace['attention']=$workspace['warning']+$workspace['critical']+$workspace['empty'];

        $filteractive=$f['q']!==''||$f['kind']!==''||$f['owner']!==''||$f['level']!==''||$f['parentfilter']!=='';
        $matched=[];$matches=array_fill_keys(self::TYPES,0);
        foreach ($nodes as $uuid=>$node) {
            $ok=true;
            if ($f['kind']!=='' && $node['type']!==$f['kind']) { $ok=false; }
            if ($f['owner']!=='' && $node['owner_orgunit_uuid']!==$f['owner']) { $ok=false; }
            if ($f['level']!=='' && $node['level']!==$f['level']) { $ok=false; }
            if ($f['parentfilter']!=='' && $node['parent_uuid']!==$f['parentfilter']) { $ok=false; }
            if ($f['q']!=='') {
                $hay=\core_text::strtolower($node['code'].' '.$node['name']);$q=\core_text::strtolower($f['q']);
                if (\core_text::strpos($hay,$q)===false) { $ok=false; }
            }
            if ($ok) { $matched[$uuid]=true;$matches[$node['type']]++; }
        }
        if (!$filteractive) { foreach ($nodes as $uuid=>$node) { $matched[$uuid]=true; } $matches=$summary; }
        $display=$matched;
        foreach (array_keys($matched) as $uuid) {
            $cursor=$nodes[$uuid]['parent_uuid']??'';$guard=[];
            while ($cursor!=='' && isset($nodes[$cursor]) && !isset($guard[$cursor])) {
                $display[$cursor]=true;$guard[$cursor]=true;$cursor=$nodes[$cursor]['parent_uuid'];
            }
        }
        $displayrows=[];
        foreach ($nodes as $uuid=>$node) { if (isset($display[$uuid])) { $node['matched']=isset($matched[$uuid]);$displayrows[$uuid]=$node; } }
        return [
            'company'=>$company,'can_manage'=>$manager,'filters'=>$f,'summary'=>$summary,'matches'=>$matches,
            'total'=>count($matched),'page'=>0,'pagesize'=>max(1,count($matched)),'rows'=>array_values($displayrows),
            'architecture'=>['summary'=>$workspace,'nodes'=>$displayrows,'children'=>$children,'filter_active'=>$filteractive],
        ];
    }

    /** Pure analytics projection over the already Company-scoped visible architecture nodes. */
    private static function architecture_analytics_projection(array $nodes): array {
        $visible=[];
        foreach ($nodes as $node) {
            if (array_key_exists('matched',$node) && !$node['matched']) { continue; }
            $visible[]=$node;
        }
        $total=count($visible);
        $summary=['total'=>$total,'maturity_score'=>0,'ready'=>0,'attention'=>0,'warning'=>0,'critical'=>0,'empty'=>0,
            'published'=>0,'owner'=>0,'product'=>0,'modelled'=>0];
        $coverage=[
            'published'=>['covered'=>0,'total'=>$total,'percent'=>0],
            'owner'=>['covered'=>0,'total'=>$total,'percent'=>0],
            'product'=>['covered'=>0,'total'=>$total,'percent'=>0],
            'model'=>['covered'=>0,'total'=>$total,'percent'=>0],
            'consistency'=>['covered'=>0,'total'=>0,'percent'=>0],
            'boundary'=>['covered'=>0,'total'=>0,'percent'=>0],
        ];
        $bytype=[];foreach (self::TYPES as $type) { $bytype[$type]=['total'=>0,'ready'=>0,'attention'=>0,'score_sum'=>0,'score'=>0]; }
        $bylevel=[];foreach (self::LEVELS as $level) { $bylevel[$level]=['total'=>0,'ready'=>0,'attention'=>0,'score_sum'=>0,'score'=>0]; }
        $issuecounts=['unpublished'=>0,'owner_missing'=>0,'product_missing'=>0,'model_missing'=>0,'model_draft'=>0,
            'consistency_formalisation'=>0,'consistency_mismatch'=>0,'boundary_issues'=>0];
        $priorities=[];$scoresum=0;
        foreach ($visible as $node) {
            $a=$node['architecture']??[];$score=(int)($a['score']??0);$health=(string)($a['health']??'warning');
            $scoresum+=$score;
            if (isset($summary[$health])) { $summary[$health]++; }
            if ($health!=='ready') { $summary['attention']++; }
            if (($node['status']??'')==='published') { $summary['published']++;$coverage['published']['covered']++; }
            if (($node['owner_orgunit_uuid']??'')!=='') { $summary['owner']++;$coverage['owner']['covered']++; }
            if (($node['product_uuid']??'')!=='') { $summary['product']++;$coverage['product']['covered']++; }
            $modelstatus=(string)($a['model_status']??'none');
            if ($modelstatus!=='none') { $summary['modelled']++;$coverage['model']['covered']++; }
            $consistency=(string)($a['consistency_status']??'not_available');
            if (in_array($consistency,['consistent','needs_formalisation','inconsistent'],true)) {
                $coverage['consistency']['total']++;
                if ($consistency==='consistent') { $coverage['consistency']['covered']++; }
            }
            $boundary=$a['boundary']??['applicable'=>false,'issues'=>0];
            if (!empty($boundary['applicable'])) {
                $coverage['boundary']['total']++;
                if ((int)($boundary['issues']??0)===0) { $coverage['boundary']['covered']++; }
            }
            $type=(string)($node['type']??'');$level=(string)($node['level']??'');
            if (isset($bytype[$type])) {
                $bytype[$type]['total']++;$bytype[$type]['score_sum']+=$score;
                if ($health==='ready') { $bytype[$type]['ready']++; } else { $bytype[$type]['attention']++; }
            }
            if (isset($bylevel[$level])) {
                $bylevel[$level]['total']++;$bylevel[$level]['score_sum']+=$score;
                if ($health==='ready') { $bylevel[$level]['ready']++; } else { $bylevel[$level]['attention']++; }
            }
            $issues=[];
            if (($node['status']??'')!=='published') { $issues[]='unpublished'; }
            if (($node['owner_orgunit_uuid']??'')==='') { $issues[]='owner_missing'; }
            if (($node['product_uuid']??'')==='') { $issues[]='product_missing'; }
            if ($modelstatus==='none') { $issues[]='model_missing'; }
            else if ($modelstatus==='draft') { $issues[]='model_draft'; }
            if ($consistency==='needs_formalisation') { $issues[]='consistency_formalisation'; }
            if ($consistency==='inconsistent') { $issues[]='consistency_mismatch'; }
            if (!empty($boundary['applicable']) && (int)($boundary['issues']??0)>0) { $issues[]='boundary_issues'; }
            foreach ($issues as $issue) { $issuecounts[$issue]++; }
            if ($issues) {
                $priorities[]=['uuid'=>(string)$node['uuid'],'code'=>(string)$node['code'],'name'=>(string)$node['name'],
                    'notation'=>(string)($node['idef0_notation']??'A?'),'type'=>$type,'level'=>$level,'health'=>$health,'score'=>$score,
                    'issues'=>$issues,'status'=>(string)($node['status']??''),'owner_name'=>(string)($node['owner_name']??''),
                    'product_name'=>(string)($node['product_name']??'')];
            }
        }
        $summary['maturity_score']=$total?(int)round($scoresum/$total):0;
        foreach ($coverage as &$item) { $item['percent']=$item['total']?(int)round(($item['covered']*100)/$item['total']):100; } unset($item);
        foreach ([$bytype,$bylevel] as $index=>$groups) {
            foreach ($groups as $key=>$item) { $groups[$key]['score']=$item['total']?(int)round($item['score_sum']/$item['total']):0;unset($groups[$key]['score_sum']); }
            if ($index===0) { $bytype=$groups; } else { $bylevel=$groups; }
        }
        $rank=['critical'=>0,'empty'=>1,'warning'=>2,'ready'=>3];
        usort($priorities,static function(array $a,array $b) use ($rank): int {
            return [$rank[$a['health']]??9,$a['score'],$a['code'],$a['uuid']]<=>[$rank[$b['health']]??9,$b['score'],$b['code'],$b['uuid']];
        });
        return ['summary'=>$summary,'coverage'=>$coverage,'by_type'=>$bytype,'by_level'=>$bylevel,
            'issue_counts'=>$issuecounts,'priorities'=>$priorities];
    }

    /** Read-only architecture analytics reusing the exact same scoped workspace projection. */
    public static function architecture_analytics(string $companyuuid, int $userid, array $filters=[]): array {
        $workspace=self::architecture_workspace($companyuuid,$userid,$filters);
        $workspace['filters']['layout']='analytics';
        $workspace['analytics']=self::architecture_analytics_projection(array_values($workspace['architecture']['nodes']));
        return $workspace;
    }

    public static function history(string $companyuuid, int $userid, string $uuid): array {
        global $DB;
        self::crud_record($companyuuid,$userid,$uuid);
        $params=['companyuuid'=>$companyuuid,'uuid'=>$uuid];
        if (!self::can_manage($companyuuid,$userid)) { $params['status']='published'; }
        return array_values($DB->get_records('local_kbacompany_process',$params,'revision DESC','*',0,25));
    }

    /** SQL visibility predicate. All callers additionally scope companyuuid before fetching. */
    private static function visible_sql(string $alias, bool $manager, bool $archived = false): string {
        $newer = $alias . 'n';
        $sql = $manager ? ($archived ? '1=1' : "$alias.status <> 'archived'") : "$alias.status = 'published'";
        $sql .= " AND NOT EXISTS (SELECT 1 FROM {local_kbacompany_process} $newer
            WHERE $newer.companyuuid=$alias.companyuuid AND $newer.uuid=$alias.uuid
              AND $newer.revision>$alias.revision" . ($manager ? '' : " AND $newer.status IN ('published','archived')") . ')';
        return $sql;
    }

    /** Whitelisted navigation state, never an arbitrary redirect URL or action payload. */
    public static function crud_filters(array $input): array {
        $f = ['q' => trim((string)($input['q'] ?? '')), 'kind' => (string)($input['kind'] ?? ''),
            'owner' => trim((string)($input['owner'] ?? '')), 'level' => (string)($input['level'] ?? ''),
            'parentfilter' => trim((string)($input['parentfilter'] ?? '')),
            'layout' => (string)($input['layout'] ?? 'architecture'),
            'ownerq' => trim((string)($input['ownerq'] ?? ''))];
        foreach (['q', 'ownerq'] as $key) { $f[$key] = \core_text::substr($f[$key], 0, 200); }
        foreach (['owner','parentfilter'] as $key) { if (strlen($f[$key]) > 36) { self::fail('reference'); } }
        if (!in_array($f['kind'], array_merge([''], self::TYPES), true) ||
                !in_array($f['level'], array_merge([''], self::LEVELS), true) ||
                !in_array($f['layout'], ['architecture','analytics','registry'], true)) { self::fail('invalid'); }
        return $f;
    }

    /** Reusable SQL selection for both architecture and registry; draft ACL is in SQL. */
    public static function crud_browse(string $companyuuid, int $userid, array $filters = [], int $page = 0): array {
        global $DB;
        $company = self::require_view($companyuuid, $userid);
        $manager = self::can_manage($companyuuid, $userid);
        $f = self::crud_filters($filters);
        $params = ['company' => $companyuuid];
        $base = 'p.companyuuid=:company AND ' . self::visible_sql('p', $manager);
        $where = $base;
        foreach (['kind'=>'processtype','owner'=>'ownerorgunituuid','level'=>'processlevel','parentfilter'=>'parentuuid'] as $k=>$col) {
            if ($f[$k] !== '') { $where .= ' AND p.' . $col . '=:' . $k; $params[$k] = $f[$k]; }
        }
        if ($f['q'] !== '') {
            $where .= ' AND (' . $DB->sql_like('p.code', ':codeq', false) . ' OR ' . $DB->sql_like('p.name', ':nameq', false) . ')';
            $params['codeq'] = '%' . $DB->sql_like_escape($f['q']) . '%'; $params['nameq'] = $params['codeq'];
        }
        $summary = array_fill_keys(self::TYPES, 0);
        foreach ($DB->get_records_sql('SELECT p.processtype,COUNT(1) AS n FROM {local_kbacompany_process} p WHERE ' . $base . ' GROUP BY p.processtype', ['company'=>$companyuuid]) as $row) {
            $summary[$row->processtype] = (int)$row->n;
        }
        $matches = array_fill_keys(self::TYPES, 0);
        foreach ($DB->get_records_sql('SELECT p.processtype,COUNT(1) AS n FROM {local_kbacompany_process} p WHERE ' . $where . ' GROUP BY p.processtype', $params) as $row) {
            $matches[$row->processtype] = (int)$row->n;
        }
        $total = array_sum($matches); $size = 25;
        $page = max(0, min($page, max(0, (int)ceil($total / $size) - 1)));
        $rows = $DB->get_records_sql(self::record_select($manager) . ' WHERE ' . $where . " ORDER BY CASE p.processtype
            WHEN 'management' THEN 0 WHEN 'development' THEN 1 WHEN 'core' THEN 2 ELSE 3 END,
            p.processlevel ASC,p.code ASC,p.uuid ASC", $params, $page*$size, $size);
        return ['company'=>$company, 'can_manage'=>$manager, 'filters'=>$f, 'summary'=>$summary, 'matches'=>$matches,
            'total'=>$total, 'page'=>$page, 'pagesize'=>$size, 'rows'=>array_values(array_map([self::class,'record_dto'], $rows))];
    }

    private static function record_select(bool $manager): string {
        return "SELECT p.*,o.name AS ownername,o.code AS ownercode,v.name AS productname,v.code AS productcode,
            parent.name AS parentname,parent.code AS parentcode,
            (SELECT COUNT(1) FROM {local_kbacompany_process} child WHERE child.companyuuid=p.companyuuid
                AND child.parentuuid=p.uuid AND " . self::visible_sql('child', $manager) . ") AS childcount
            FROM {local_kbacompany_process} p
            LEFT JOIN {local_kbacompany_orgnode} o ON o.companyuuid=p.companyuuid AND o.uuid=p.ownerorgunituuid AND o.active=1
            LEFT JOIN {local_kbacompany_product} v ON v.companyuuid=p.companyuuid AND v.uuid=p.productuuid
            LEFT JOIN {local_kbacompany_process} parent ON parent.companyuuid=p.companyuuid AND parent.uuid=p.parentuuid AND " . self::visible_sql('parent', $manager);
    }

    private static function record_dto(\stdClass $row): array {
        return ['uuid'=>(string)$row->uuid,'revision'=>(int)$row->revision,'code'=>(string)$row->code,'name'=>(string)$row->name,
            'type'=>(string)$row->processtype,'level'=>(string)$row->processlevel,'purpose'=>(string)$row->purpose,
            'status'=>(string)$row->status,'parent_uuid'=>(string)$row->parentuuid,
            'parent_name'=>(string)($row->parentname ?? ''),'parent_code'=>(string)($row->parentcode ?? ''),
            'owner_orgunit_uuid'=>(string)$row->ownerorgunituuid,'owner_name'=>(string)($row->ownername ?? ''),
            'owner_code'=>(string)($row->ownercode ?? ''),'product_uuid'=>(string)$row->productuuid,
            'product_name'=>(string)($row->productname ?? ''),'product_code'=>(string)($row->productcode ?? ''),
            'children'=>(int)($row->childcount ?? 0),'createdby'=>(int)$row->createdby,'timecreated'=>(int)$row->timecreated,
            'links'=>['supplier'=>[],'customer'=>[],'participant'=>[],'metric'=>[]]];
    }

    /** A specific authorised record, not an all-company fetch followed by PHP filtering. */
    public static function crud_record(string $companyuuid, int $userid, string $uuid): array {
        global $DB;
        $company = self::require_view($companyuuid, $userid);
        $manager = self::can_manage($companyuuid, $userid);
        if ($uuid === '' || strlen($uuid) > 36) { self::fail('reference'); }
        $rows = $DB->get_records_sql(self::record_select($manager) . ' WHERE p.companyuuid=:company AND p.uuid=:object AND ' .
            self::visible_sql('p', $manager, $manager), ['company'=>$companyuuid,'object'=>$uuid], 0, 1);
        if (!$rows) { self::fail('reference'); }
        return ['company'=>$company,'can_manage'=>$manager,'process'=>self::record_dto(reset($rows))];
    }

    /** Direct visible children of a canonical Process. Company scope is applied before fetch. */
    private static function idef0_children(string $companyuuid, bool $manager, string $parentuuid, string $level): array {
        global $DB;
        $rows = $DB->get_records_sql(self::record_select($manager) . ' WHERE p.companyuuid=:company
            AND p.parentuuid=:parent AND p.processlevel=:level AND ' . self::visible_sql('p', $manager) . '
            ORDER BY p.code ASC,p.uuid ASC', [
                'company'=>$companyuuid, 'parent'=>$parentuuid, 'level'=>$level,
            ]);
        return array_values(array_map([self::class, 'record_dto'], $rows));
    }

    /** 1-based representation position among visible siblings; never a persistent identity. */
    private static function idef0_position(array $rows, string $uuid): int {
        foreach ($rows as $index=>$row) { if ($row['uuid'] === $uuid) { return $index + 1; } }
        return 0;
    }

    /**
     * IDEF0 decomposition projection over the existing Process hierarchy.
     * A-0/A0/A1/A11 notation is calculated for the visible revision set and is not stored as an ID.
     */
    public static function idef0_decomposition(string $companyuuid, int $userid, string $uuid): array {
        $record = self::crud_record($companyuuid, $userid, $uuid);
        $manager = (bool)$record['can_manage'];
        $process = $record['process'];
        $notation = 'A?';
        $diagram = 'A?';
        $parentnotation = '';

        if ($process['level'] === 'L0') {
            $notation = 'A-0';
            $diagram = 'A0';
        } else if ($process['level'] === 'L1') {
            $parent = self::crud_record($companyuuid, $userid, $process['parent_uuid'])['process'];
            $siblings = self::idef0_children($companyuuid, $manager, $parent['uuid'], 'L1');
            $position = self::idef0_position($siblings, $process['uuid']);
            $notation = $position ? 'A' . $position : 'A?';
            $diagram = $notation;
            $parentnotation = 'A-0';
        } else if ($process['level'] === 'L2') {
            $parent = self::crud_record($companyuuid, $userid, $process['parent_uuid'])['process'];
            $grandparent = $parent['parent_uuid'] !== '' ? self::crud_record($companyuuid, $userid, $parent['parent_uuid'])['process'] : null;
            $parentsiblings = $grandparent ? self::idef0_children($companyuuid, $manager, $grandparent['uuid'], 'L1') : [];
            $parentposition = self::idef0_position($parentsiblings, $parent['uuid']);
            $siblings = self::idef0_children($companyuuid, $manager, $parent['uuid'], 'L2');
            $position = self::idef0_position($siblings, $process['uuid']);
            if ($parentposition && $position) {
                $parentnotation = 'A' . $parentposition;
                $notation = $parentnotation . $position;
                $diagram = $notation;
            }
        }

        $childlevel = $process['level'] === 'L0' ? 'L1' : ($process['level'] === 'L1' ? 'L2' : '');
        $children = $childlevel === '' ? [] : self::idef0_children($companyuuid, $manager, $process['uuid'], $childlevel);
        foreach ($children as $index=>$child) {
            if ($process['level'] === 'L0') { $children[$index]['idef0_notation'] = 'A' . ($index + 1); }
            else { $children[$index]['idef0_notation'] = $notation === 'A?' ? 'A?' : $notation . ($index + 1); }
        }

        $interconnections = self::idef0_interconnections_for_children($companyuuid, $children, $manager);
        $balance = ['total'=>count($interconnections), 'balanced'=>0, 'issues'=>0];
        foreach ($interconnections as $edge) {
            if (($edge['balance_status'] ?? '') === 'balanced') { $balance['balanced']++; }
            else { $balance['issues']++; }
        }
        $boundarybalance = self::idef0_boundary_balance($companyuuid, $process, $children, $interconnections, $manager);
        $controlmechanisms = self::idef0_control_mechanism_links($companyuuid, $userid, $process['uuid'], $manager);

        return [
            'notation'=>$notation,
            'diagram_notation'=>$diagram,
            'parent_notation'=>$parentnotation,
            'child_level'=>$childlevel,
            'children'=>$children,
            'interconnections'=>$interconnections,
            'balance'=>$balance,
            'boundary_balance'=>$boundarybalance,
            'controls_mechanisms'=>$controlmechanisms,
            'can_add_child'=>$manager && $process['status'] !== 'archived' && $childlevel !== '',
            'can_add_interconnection'=>$manager && $process['status'] !== 'archived' && count($children) > 1,
            'can_link_control_mechanism'=>$manager && $process['status'] !== 'archived',
        ];
    }

    /** Resolve one active Semantic Core endpoint for the IDEF0 Control/Mechanism projection. */
    private static function idef0_cm_endpoint(string $companyuuid, int $userid, bool $manager, string $type,
            string $uuid): ?array {
        global $DB;
        if ($type === 'metric') {
            $row=$DB->get_record('local_kbacompany_stat',['companyuuid'=>$companyuuid,'uuid'=>$uuid]);
            return $row?['type'=>'metric','uuid'=>(string)$row->uuid,'code'=>(string)$row->code,
                'name'=>(string)$row->name,'status'=>'active']:null;
        }
        if ($type === 'orgunit') {
            $row=$DB->get_record('local_kbacompany_orgnode',['companyuuid'=>$companyuuid,'uuid'=>$uuid,'active'=>1]);
            return $row?['type'=>'orgunit','uuid'=>(string)$row->uuid,'code'=>(string)$row->code,
                'name'=>(string)$row->name,'status'=>'active']:null;
        }
        if ($type === 'position') {
            $row=$DB->get_record('local_kbacompany_position',['companyuuid'=>$companyuuid,'uuid'=>$uuid,'active'=>1]);
            return $row?['type'=>'position','uuid'=>(string)$row->uuid,'code'=>(string)$row->code,
                'name'=>(string)$row->name,'status'=>'active']:null;
        }
        if ($type === 'policy') {
            $rows=$DB->get_records('local_kbacompany_policy',['companyuuid'=>$companyuuid,'uuid'=>$uuid],
                'revision DESC','*',0,1);
            if (!$rows) { return null; }
            $row=reset($rows);
            if ($row->status === 'withdrawn' || (!$manager && $row->status !== 'published')) { return null; }
            return ['type'=>'policy','uuid'=>(string)$row->uuid,'code'=>(string)$row->code,
                'name'=>(string)$row->title,'status'=>(string)$row->status];
        }
        if ($type === 'document') {
            try {
                $document=document_service::semantic_document($companyuuid,$userid,$uuid);
                return ['type'=>'document','uuid'=>(string)$document['uuid'],'code'=>(string)$document['code'],
                    'name'=>(string)$document['name'],'status'=>(string)$document['status']];
            } catch (\moodle_exception $e) {
                return null;
            }
        }
        return null;
    }

    /**
     * Canonical IDEF0 Controls/Mechanisms projection over Semantic Core relations.
     * Free-form procelem control/resource rows remain descriptive ICOM; these rows are explicit BMS-object bindings.
     */
    private static function idef0_control_mechanism_links(string $companyuuid, int $userid, string $processuuid,
            bool $manager): array {
        global $DB;
        $params=['company'=>$companyuuid,'processcontrol'=>$processuuid,'processmetric'=>$processuuid,'processmechanism'=>$processuuid];
        $rows=$DB->get_records_sql("SELECT r.* FROM {local_kbacompany_semrel} r
            WHERE r.companyuuid=:company AND r.status='active'
            AND NOT EXISTS (SELECT 1 FROM {local_kbacompany_semrel} newer
                WHERE newer.companyuuid=r.companyuuid AND newer.uuid=r.uuid AND newer.revision>r.revision)
            AND (
                (r.fromtype='process' AND r.fromuuid=:processcontrol AND r.relation='governed_by'
                    AND r.totype IN ('policy','document'))
                OR (r.totype='process' AND r.touuid=:processmetric AND r.relation='measures' AND r.fromtype='metric')
                OR (r.totype='process' AND r.touuid=:processmechanism AND r.relation='executes'
                    AND r.fromtype IN ('orgunit','position'))
            ) ORDER BY r.id ASC",$params);
        $result=['controls'=>[],'mechanisms'=>[]];
        foreach ($rows as $row) {
            $kind='';$targettype='';$targetuuid='';
            if ($row->fromtype==='process' && $row->relation==='governed_by') {
                $kind='control';$targettype=(string)$row->totype;$targetuuid=(string)$row->touuid;
            } else if ($row->fromtype==='metric' && $row->relation==='measures') {
                $kind='control';$targettype='metric';$targetuuid=(string)$row->fromuuid;
            } else if (in_array($row->fromtype,['orgunit','position'],true) && $row->relation==='executes') {
                $kind='mechanism';$targettype=(string)$row->fromtype;$targetuuid=(string)$row->fromuuid;
            }
            if ($kind==='') { continue; }
            $target=self::idef0_cm_endpoint($companyuuid,$userid,$manager,$targettype,$targetuuid);
            if (!$target) { continue; }
            $target['relation_uuid']=(string)$row->uuid;
            $target['revision']=(int)$row->revision;
            $target['kind']=$kind;
            $result[$kind==='control'?'controls':'mechanisms'][]=$target;
        }
        return $result;
    }

    /** Bounded UI options for explicit canonical Control/Mechanism bindings; all queries are company scoped. */
    public static function idef0_control_mechanism_options(string $companyuuid, int $userid, string $processuuid,
            string $kind): array {
        global $DB;
        self::authorise($companyuuid,$userid);
        $process=self::latest($companyuuid,$processuuid);
        if ($process->status==='archived' || !in_array($kind,['control','mechanism'],true)) { self::fail('state'); }
        $current=self::idef0_control_mechanism_links($companyuuid,$userid,$processuuid,true);
        $used=[];
        foreach ($current[$kind==='control'?'controls':'mechanisms'] as $row) {
            $used[$row['type'].'|'.$row['uuid']]=true;
        }
        $options=[];
        if ($kind==='control') {
            $policyrows=$DB->get_records_sql("SELECT p.* FROM {local_kbacompany_policy} p
                WHERE p.companyuuid=:company AND p.status<>'withdrawn'
                AND NOT EXISTS (SELECT 1 FROM {local_kbacompany_policy} newer
                    WHERE newer.companyuuid=p.companyuuid AND newer.uuid=p.uuid AND newer.revision>p.revision)
                ORDER BY p.code ASC,p.id ASC",['company'=>$companyuuid],0,200);
            foreach ($policyrows as $row) {
                $key='policy|'.$row->uuid;if (!isset($used[$key])) {
                    $options[$key]=['type'=>'policy','uuid'=>(string)$row->uuid,'code'=>(string)$row->code,
                        'name'=>(string)$row->title,'status'=>(string)$row->status];
                }
            }
            foreach (document_service::semantic_documents($companyuuid,$userid) as $row) {
                $key='document|'.$row['uuid'];if (!isset($used[$key])) {
                    $options[$key]=['type'=>'document','uuid'=>(string)$row['uuid'],'code'=>(string)$row['code'],
                        'name'=>(string)$row['name'],'status'=>(string)$row['status']];
                }
            }
            foreach ($DB->get_records('local_kbacompany_stat',['companyuuid'=>$companyuuid],'code ASC','*',0,200) as $row) {
                $key='metric|'.$row->uuid;if (!isset($used[$key])) {
                    $options[$key]=['type'=>'metric','uuid'=>(string)$row->uuid,'code'=>(string)$row->code,
                        'name'=>(string)$row->name,'status'=>'active'];
                }
            }
        } else {
            foreach ($DB->get_records('local_kbacompany_orgnode',['companyuuid'=>$companyuuid,'active'=>1],
                    'code ASC','*',0,200) as $row) {
                $key='orgunit|'.$row->uuid;if (!isset($used[$key])) {
                    $options[$key]=['type'=>'orgunit','uuid'=>(string)$row->uuid,'code'=>(string)$row->code,
                        'name'=>(string)$row->name,'status'=>'active'];
                }
            }
            foreach ($DB->get_records('local_kbacompany_position',['companyuuid'=>$companyuuid,'active'=>1],
                    'code ASC','*',0,200) as $row) {
                $key='position|'.$row->uuid;if (!isset($used[$key])) {
                    $options[$key]=['type'=>'position','uuid'=>(string)$row->uuid,'code'=>(string)$row->code,
                        'name'=>(string)$row->name,'status'=>'active'];
                }
            }
        }
        uasort($options,static function(array $a,array $b): int {
            return [$a['type'],$a['code'],$a['uuid']] <=> [$b['type'],$b['code'],$b['uuid']];
        });
        return ['kind'=>$kind,'options'=>$options,'limit_per_type'=>200];
    }

    /** Create one explicit canonical Control/Mechanism relation without mutating the Process Model. */
    public static function idef0_bind_control_mechanism(string $companyuuid, int $userid, string $processuuid,
            int $expectedrevision, string $kind, string $target, string $reason): string {
        self::authorise($companyuuid,$userid);
        $reason=trim($reason);
        $parts=explode('|',$target,2);
        if (!in_array($kind,['control','mechanism'],true) || count($parts)!==2 || trim($parts[1])==='' ||
                strlen($parts[1])>36 || $reason==='' || \core_text::strlen($reason)>4000) { self::fail('invalid'); }
        $type=(string)$parts[0];$uuid=(string)$parts[1];
        $allowed=$kind==='control'?['policy','document','metric']:['orgunit','position'];
        if (!in_array($type,$allowed,true)) { self::fail('reference'); }
        $lock=\core\lock\lock_config::get_lock_factory('local_kbacompany')->get_lock('process_architecture:'.$companyuuid,10);
        if (!$lock) { self::fail('lock'); }
        try {
            self::authorise($companyuuid,$userid);
            $process=self::latest($companyuuid,$processuuid);
            if ((int)$process->revision!==$expectedrevision) { self::fail('conflict'); }
            if ($process->status==='archived') { self::fail('state'); }
            $options=self::idef0_control_mechanism_options($companyuuid,$userid,$processuuid,$kind);
            if (!isset($options['options'][$type.'|'.$uuid])) { self::fail('reference'); }
            if ($kind==='control' && in_array($type,['policy','document'],true)) {
                $edge=['fromtype'=>'process','fromuuid'=>$processuuid,'relation'=>'governed_by','totype'=>$type,'touuid'=>$uuid];
            } else if ($kind==='control' && $type==='metric') {
                $edge=['fromtype'=>'metric','fromuuid'=>$uuid,'relation'=>'measures','totype'=>'process','touuid'=>$processuuid];
            } else {
                $edge=['fromtype'=>$type,'fromuuid'=>$uuid,'relation'=>'executes','totype'=>'process','touuid'=>$processuuid];
            }
            return semantic_service::command_relation($companyuuid,$userid,'create',$edge+['reason'=>$reason]);
        } finally { $lock->release(); }
    }

    /** Archive only a relation that is currently an IDEF0 Control/Mechanism binding of this Process. */
    public static function idef0_unbind_control_mechanism(string $companyuuid, int $userid, string $processuuid,
            int $expectedrevision, string $relationuuid, int $relationrevision, string $reason): void {
        global $DB;
        self::authorise($companyuuid,$userid);
        $reason=trim($reason);
        if ($relationuuid==='' || $reason==='' || \core_text::strlen($reason)>4000) { self::fail('invalid'); }
        $lock=\core\lock\lock_config::get_lock_factory('local_kbacompany')->get_lock('process_architecture:'.$companyuuid,10);
        if (!$lock) { self::fail('lock'); }
        try {
            self::authorise($companyuuid,$userid);
            $process=self::latest($companyuuid,$processuuid);
            if ((int)$process->revision!==$expectedrevision) { self::fail('conflict'); }
            if ($process->status==='archived') { self::fail('state'); }
            $rows=$DB->get_records_sql("SELECT r.* FROM {local_kbacompany_semrel} r
                WHERE r.companyuuid=:company AND r.uuid=:relationuuid AND r.revision=:relationrevision
                AND r.status='active'
                AND NOT EXISTS (SELECT 1 FROM {local_kbacompany_semrel} newer
                    WHERE newer.companyuuid=r.companyuuid AND newer.uuid=r.uuid AND newer.revision>r.revision)
                AND (
                    (r.fromtype='process' AND r.fromuuid=:processcontrol AND r.relation='governed_by'
                        AND r.totype IN ('policy','document'))
                    OR (r.totype='process' AND r.touuid=:processmetric AND r.relation='measures' AND r.fromtype='metric')
                    OR (r.totype='process' AND r.touuid=:processmechanism AND r.relation='executes'
                        AND r.fromtype IN ('orgunit','position'))
                )",['company'=>$companyuuid,'relationuuid'=>$relationuuid,'relationrevision'=>$relationrevision,
                    'processcontrol'=>$processuuid,'processmetric'=>$processuuid,'processmechanism'=>$processuuid],0,1);
            if (!$rows) { self::fail('reference'); }
            semantic_service::command_relation($companyuuid,$userid,'archive',[
                'object'=>$relationuuid,'revision'=>$relationrevision,'reason'=>$reason,
            ]);
        } finally { $lock->release(); }
    }

    /** Draft-first for managers, published-only for readers; always company/process scoped before fetch. */
    private static function idef0_visible_model(string $companyuuid, bool $manager, string $processuuid): ?\stdClass {
        global $DB;
        if ($manager) {
            $rows=$DB->get_records('local_kbacompany_procmodel', [
                'companyuuid'=>$companyuuid, 'processuuid'=>$processuuid, 'status'=>'draft',
            ], 'revision DESC', '*', 0, 1);
            if ($rows) { return reset($rows); }
        }
        $rows=$DB->get_records('local_kbacompany_procmodel', [
            'companyuuid'=>$companyuuid, 'processuuid'=>$processuuid, 'status'=>'published',
        ], 'revision DESC', '*', 0, 1);
        return $rows ? reset($rows) : null;
    }

    /** Visible I/O elements for one canonical Process model, including unbound rows for diagnostics. */
    private static function idef0_visible_io(string $companyuuid, bool $manager, string $processuuid): array {
        global $DB;
        $model=self::idef0_visible_model($companyuuid,$manager,$processuuid);
        $result=['model_status'=>$model?(string)$model->status:'none','model_id'=>$model?(int)$model->id:0,'inputs'=>[],'outputs'=>[]];
        if (!$model) { return $result; }
        $rows=$DB->get_records_sql("SELECT e.*,p.name AS productname FROM {local_kbacompany_procelem} e
            LEFT JOIN {local_kbacompany_product} p ON p.companyuuid=e.companyuuid AND p.uuid=e.productuuid
            WHERE e.companyuuid=:company AND e.modelid=:model AND e.active=1
            AND e.elementtype IN ('input','output') ORDER BY e.sortorder ASC,e.id ASC", [
                'company'=>$companyuuid,'model'=>(int)$model->id,
            ]);
        foreach ($rows as $row) {
            $item=[
                'uuid'=>(string)$row->uuid,
                'name'=>(string)$row->name,
                'product_uuid'=>(string)$row->productuuid,
                'product_name'=>(string)($row->productname??''),
            ];
            if ($row->elementtype==='input') { $result['inputs'][]=$item; }
            else if ($row->elementtype==='output') { $result['outputs'][]=$item; }
        }
        return $result;
    }

    /** Visible target Input product bindings for direct child processes only. */
    private static function idef0_input_bindings_for_children(string $companyuuid, bool $manager, array $children): array {
        $result=[];
        foreach ($children as $child) {
            $visible=self::idef0_visible_io($companyuuid,$manager,(string)$child['uuid']);
            $bindings=[];
            foreach ($visible['inputs'] as $row) {
                if ($row['product_uuid']==='') { continue; }
                $bindings[$row['product_uuid']]=['uuid'=>$row['uuid'],'name'=>$row['name']];
            }
            $result[(string)$child['uuid']]=[
                'model_status'=>(string)$visible['model_status'],
                'products'=>$bindings,
            ];
        }
        return $result;
    }

    /**
     * Balance the IDEF0 decomposition boundary using canonical Product UUIDs only.
     * No names are matched and no Process/ICOM/SIPOC/BPMN data is mutated.
     */
    private static function idef0_boundary_balance(string $companyuuid, array $process, array $children,
            array $interconnections, bool $manager): array {
        $empty=[
            'applicable'=>false,'score'=>0,'total'=>0,'balanced'=>0,'issue_count'=>0,'items'=>[],
            'inputs'=>['total'=>0,'balanced'=>0,'issues'=>0],
            'internal'=>['total'=>0,'balanced'=>0,'issues'=>0],
            'outputs'=>['total'=>0,'balanced'=>0,'issues'=>0],
            'primary'=>['total'=>0,'balanced'=>0,'issues'=>0,'status'=>'not_applicable'],
            'parent_model_status'=>'none',
        ];
        if (!$children) { return $empty; }

        $parentio=self::idef0_visible_io($companyuuid,$manager,(string)$process['uuid']);
        $childio=[];
        foreach ($children as $child) {
            $childio[$child['uuid']]=self::idef0_visible_io($companyuuid,$manager,(string)$child['uuid']);
        }
        $issues=[];
        $inputsummary=['total'=>0,'balanced'=>0,'issues'=>0];
        foreach ($parentio['inputs'] as $input) {
            $inputsummary['total']++;
            $productuuid=(string)$input['product_uuid'];
            if ($productuuid==='') {
                $inputsummary['issues']++;
                $issues[]=['type'=>'parent_input_unbound','process_uuid'=>(string)$process['uuid'],
                    'process_notation'=>'','process_name'=>(string)$process['name'],'element_uuid'=>$input['uuid'],
                    'element_name'=>$input['name'],'product_uuid'=>'','product_name'=>''];
                continue;
            }
            $used=[];
            foreach ($children as $child) {
                foreach ($childio[$child['uuid']]['inputs'] as $childinput) {
                    if ($childinput['product_uuid']===$productuuid) { $used[]=(string)$child['idef0_notation']; break; }
                }
            }
            if ($used) { $inputsummary['balanced']++; }
            else {
                $inputsummary['issues']++;
                $issues[]=['type'=>'parent_input_unused','process_uuid'=>(string)$process['uuid'],
                    'process_notation'=>'','process_name'=>(string)$process['name'],'element_uuid'=>$input['uuid'],
                    'element_name'=>$input['name'],'product_uuid'=>$productuuid,'product_name'=>$input['product_name']];
            }
        }

        $internalsummary=['total'=>count($interconnections),'balanced'=>0,'issues'=>0];
        $outgoing=[];
        foreach ($interconnections as $edge) {
            $outgoing[$edge['from_uuid']]=true;
            if (($edge['balance_status']??'')==='balanced') { $internalsummary['balanced']++; }
            else {
                $internalsummary['issues']++;
                $issues[]=['type'=>'internal_unbalanced','process_uuid'=>(string)$edge['to_uuid'],
                    'process_notation'=>(string)$edge['to_notation'],'process_name'=>(string)$edge['to_name'],
                    'element_uuid'=>'','element_name'=>'','product_uuid'=>(string)$edge['product_uuid'],
                    'product_name'=>(string)$edge['product_name']];
            }
        }

        $parentoutputs=[];
        $parentproduct=(string)($process['product_uuid']??'');
        if ($parentproduct!=='') { $parentoutputs[$parentproduct]=true; }
        foreach ($parentio['outputs'] as $output) {
            if ($output['product_uuid']!=='') { $parentoutputs[$output['product_uuid']]=true; }
            else {
                $issues[]=['type'=>'parent_output_unbound','process_uuid'=>(string)$process['uuid'],
                    'process_notation'=>'','process_name'=>(string)$process['name'],'element_uuid'=>$output['uuid'],
                    'element_name'=>$output['name'],'product_uuid'=>'','product_name'=>''];
            }
        }

        $outputsummary=['total'=>0,'balanced'=>0,'issues'=>0];
        $terminalproducts=[];
        foreach ($children as $child) {
            if (!empty($outgoing[$child['uuid']])) { continue; }
            $outputsummary['total']++;
            $productuuid=(string)($child['product_uuid']??'');
            if ($productuuid==='') {
                $outputsummary['issues']++;
                $issues[]=['type'=>'terminal_product_missing','process_uuid'=>(string)$child['uuid'],
                    'process_notation'=>(string)$child['idef0_notation'],'process_name'=>(string)$child['name'],
                    'element_uuid'=>'','element_name'=>'','product_uuid'=>'','product_name'=>''];
                continue;
            }
            $terminalproducts[$productuuid]=true;
            if (isset($parentoutputs[$productuuid])) { $outputsummary['balanced']++; }
            else {
                $outputsummary['issues']++;
                $issues[]=['type'=>'terminal_output_missing','process_uuid'=>(string)$child['uuid'],
                    'process_notation'=>(string)$child['idef0_notation'],'process_name'=>(string)$child['name'],
                    'element_uuid'=>'','element_name'=>'','product_uuid'=>$productuuid,
                    'product_name'=>(string)($child['product_name']??'')];
            }
        }
        foreach ($parentio['outputs'] as $output) {
            if ($output['product_uuid']==='') { $outputsummary['total']++; $outputsummary['issues']++; }
        }

        $primary=['total'=>1,'balanced'=>0,'issues'=>0,'status'=>'balanced'];
        if ($parentproduct==='') {
            $primary['issues']=1;$primary['status']='parent_product_missing';
            $issues[]=['type'=>'parent_product_missing','process_uuid'=>(string)$process['uuid'],
                'process_notation'=>'','process_name'=>(string)$process['name'],'element_uuid'=>'','element_name'=>'',
                'product_uuid'=>'','product_name'=>''];
        } else if (!isset($terminalproducts[$parentproduct])) {
            $primary['issues']=1;$primary['status']='primary_output_unproduced';
            $issues[]=['type'=>'primary_output_unproduced','process_uuid'=>(string)$process['uuid'],
                'process_notation'=>'','process_name'=>(string)$process['name'],'element_uuid'=>'','element_name'=>'',
                'product_uuid'=>$parentproduct,'product_name'=>(string)($process['product_name']??'')];
        } else { $primary['balanced']=1; }

        $total=$inputsummary['total']+$internalsummary['total']+$outputsummary['total']+$primary['total'];
        $balanced=$inputsummary['balanced']+$internalsummary['balanced']+$outputsummary['balanced']+$primary['balanced'];
        return [
            'applicable'=>true,
            'score'=>$total>0?(int)round(($balanced*100)/$total):100,
            'total'=>$total,'balanced'=>$balanced,'issue_count'=>count($issues),'items'=>$issues,
            'inputs'=>$inputsummary,'internal'=>$internalsummary,'outputs'=>$outputsummary,'primary'=>$primary,
            'parent_model_status'=>(string)$parentio['model_status'],
        ];
    }

    /** Active Process -> supplies -> Process edges among the direct visible children only. */
    private static function idef0_interconnections_for_children(string $companyuuid, array $children, bool $manager): array {
        global $DB;
        if (count($children) < 2) { return []; }
        $ids = array_values(array_map(static function(array $row): string { return (string)$row['uuid']; }, $children));
        [$fromsql, $fromparams] = $DB->get_in_or_equal($ids, SQL_PARAMS_NAMED, 'idef0from');
        [$tosql, $toparams] = $DB->get_in_or_equal($ids, SQL_PARAMS_NAMED, 'idef0to');
        $params = ['company'=>$companyuuid] + $fromparams + $toparams;
        $rows = $DB->get_records_sql("SELECT r.* FROM {local_kbacompany_semrel} r
            WHERE r.companyuuid=:company AND r.status='active'
            AND r.fromtype='process' AND r.totype='process' AND r.relation='supplies'
            AND r.fromuuid $fromsql AND r.touuid $tosql AND r.fromuuid<>r.touuid
            AND NOT EXISTS (SELECT 1 FROM {local_kbacompany_semrel} newer
                WHERE newer.companyuuid=r.companyuuid AND newer.uuid=r.uuid AND newer.revision>r.revision)
            ORDER BY r.id ASC", $params);
        $childmap=[];
        foreach ($children as $child) { $childmap[$child['uuid']]=$child; }
        $bindings=self::idef0_input_bindings_for_children($companyuuid,$manager,$children);
        $result=[];
        foreach ($rows as $row) {
            if (!isset($childmap[$row->fromuuid], $childmap[$row->touuid])) { continue; }
            $from=$childmap[$row->fromuuid];$to=$childmap[$row->touuid];
            $productuuid=(string)($from['product_uuid']??'');
            $targetbinding=$bindings[$row->touuid]??['model_status'=>'none','products'=>[]];
            $matched=$productuuid!==''?($targetbinding['products'][$productuuid]??null):null;
            $balancestatus=$productuuid===''?'source_product_missing':($matched?'balanced':'target_input_missing');
            $result[]=[
                'relation_uuid'=>(string)$row->uuid,
                'revision'=>(int)$row->revision,
                'from_uuid'=>(string)$row->fromuuid,
                'to_uuid'=>(string)$row->touuid,
                'from_notation'=>(string)($from['idef0_notation']??''),
                'to_notation'=>(string)($to['idef0_notation']??''),
                'from_code'=>(string)$from['code'],
                'to_code'=>(string)$to['code'],
                'from_name'=>(string)$from['name'],
                'to_name'=>(string)$to['name'],
                'product_uuid'=>$productuuid,
                'product_name'=>(string)($from['product_name']??''),
                'balance_status'=>$balancestatus,
                'balance_input_uuid'=>$matched?(string)$matched['uuid']:'',
                'balance_input_name'=>$matched?(string)$matched['name']:'',
                'target_model_status'=>(string)$targetbinding['model_status'],
                'can_balance'=>$manager && $productuuid!=='' && $targetbinding['model_status']==='draft',
            ];
        }
        return $result;
    }

    /** Create a sibling IDEF0 interconnection through Semantic Core; no process-model data is mutated. */
    public static function idef0_connect(string $companyuuid, int $userid, string $parentuuid, int $expectedrevision,
            string $fromuuid, string $touuid, string $reason): string {
        self::authorise($companyuuid,$userid);
        $reason=trim($reason);
        if ($reason==='' || \core_text::strlen($reason)>4000 || $fromuuid==='' || $touuid==='' || $fromuuid===$touuid) {
            self::fail('invalid');
        }
        $lock=\core\lock\lock_config::get_lock_factory('local_kbacompany')->get_lock('process_architecture:'.$companyuuid,10);
        if (!$lock) { self::fail('lock'); }
        try {
            self::authorise($companyuuid,$userid);
            $parent=self::latest($companyuuid,$parentuuid);
            if ((int)$parent->revision!==$expectedrevision) { self::fail('conflict'); }
            if ($parent->status==='archived' || $parent->processlevel==='L2') { self::fail('state'); }
            $level=$parent->processlevel==='L0'?'L1':'L2';
            $children=self::idef0_children($companyuuid,true,$parentuuid,$level);
            $allowed=[];foreach ($children as $child) { $allowed[$child['uuid']]=true; }
            if (!isset($allowed[$fromuuid],$allowed[$touuid])) { self::fail('reference'); }
            return semantic_service::command_relation($companyuuid,$userid,'create',[
                'fromtype'=>'process','fromuuid'=>$fromuuid,'relation'=>'supplies',
                'totype'=>'process','touuid'=>$touuid,'reason'=>$reason,
            ]);
        } finally { $lock->release(); }
    }

    /** Archive only an active supplies edge whose endpoints are still direct children of this decomposition. */
    public static function idef0_disconnect(string $companyuuid, int $userid, string $parentuuid, int $expectedrevision,
            string $relationuuid, int $relationrevision, string $reason): void {
        global $DB;
        self::authorise($companyuuid,$userid);
        $reason=trim($reason);
        if ($reason==='' || \core_text::strlen($reason)>4000 || $relationuuid==='') { self::fail('invalid'); }
        $lock=\core\lock\lock_config::get_lock_factory('local_kbacompany')->get_lock('process_architecture:'.$companyuuid,10);
        if (!$lock) { self::fail('lock'); }
        try {
            self::authorise($companyuuid,$userid);
            $parent=self::latest($companyuuid,$parentuuid);
            if ((int)$parent->revision!==$expectedrevision) { self::fail('conflict'); }
            if ($parent->status==='archived' || $parent->processlevel==='L2') { self::fail('state'); }
            $level=$parent->processlevel==='L0'?'L1':'L2';
            $children=self::idef0_children($companyuuid,true,$parentuuid,$level);
            if (count($children)<2) { self::fail('reference'); }
            $ids=array_values(array_map(static function(array $row): string { return (string)$row['uuid']; },$children));
            [$fromsql,$fromparams]=$DB->get_in_or_equal($ids,SQL_PARAMS_NAMED,'idef0dfrom');
            [$tosql,$toparams]=$DB->get_in_or_equal($ids,SQL_PARAMS_NAMED,'idef0dto');
            $params=['company'=>$companyuuid,'relationuuid'=>$relationuuid,'relationrevision'=>$relationrevision]+$fromparams+$toparams;
            $rows=$DB->get_records_sql("SELECT r.* FROM {local_kbacompany_semrel} r
                WHERE r.companyuuid=:company AND r.uuid=:relationuuid AND r.revision=:relationrevision
                AND r.status='active' AND r.fromtype='process' AND r.totype='process' AND r.relation='supplies'
                AND r.fromuuid $fromsql AND r.touuid $tosql
                AND NOT EXISTS (SELECT 1 FROM {local_kbacompany_semrel} newer
                    WHERE newer.companyuuid=r.companyuuid AND newer.uuid=r.uuid AND newer.revision>r.revision)", $params,0,1);
            if (!$rows) { self::fail('reference'); }
            semantic_service::command_relation($companyuuid,$userid,'archive',[
                'object'=>$relationuuid,'revision'=>$relationrevision,'reason'=>$reason,
            ]);
        } finally { $lock->release(); }
    }

    /** Bounded related-record picker with search, selected value retained only within the allowed scope. */
    public static function crud_lookup(string $companyuuid, int $userid, string $kind, string $q = '', string $selected = '', string $exclude = ''): array {
        global $DB;
        self::require_view($companyuuid, $userid);
        $manager = self::can_manage($companyuuid, $userid);
        $tables = ['owner'=>'local_kbacompany_orgnode','product'=>'local_kbacompany_product','parent'=>'local_kbacompany_process','metric'=>'local_kbacompany_stat'];
        if (!isset($tables[$kind])) { self::fail('invalid'); }
        $table = $tables[$kind]; $p = ['company'=>$companyuuid];
        $base = 'r.companyuuid=:company';
        if ($kind === 'owner') { $base .= ' AND r.active=1'; }
        if ($kind === 'parent') {
            $base .= " AND r.processlevel<>'L2' AND " . self::visible_sql('r', $manager);
            if ($exclude !== '') {
                $base .= ' AND r.uuid<>:excluded AND r.parentuuid<>:excludedchild';
                $p['excluded']=$exclude; $p['excludedchild']=$exclude;
            }
        }
        $q = \core_text::substr(trim($q), 0, 200); $where=$base; $params=$p;
        if ($q !== '') {
            $where .= ' AND (' . $DB->sql_like('r.code', ':lookupcode', false) . ' OR ' . $DB->sql_like('r.name', ':lookupname', false) . ')';
            $params['lookupcode']='%'.$DB->sql_like_escape($q).'%'; $params['lookupname']=$params['lookupcode'];
        }
        $total = $DB->count_records_sql("SELECT COUNT(1) FROM {{$table}} r WHERE $where", $params);
        $rows = $DB->get_records_sql("SELECT r.id,r.uuid,r.code,r.name" . ($kind==='parent'?',r.processlevel':'') . " FROM {{$table}} r WHERE $where ORDER BY r.code,r.id", $params, 0, 50);
        $options=[];
        foreach ($rows as $row) { $options[$row->uuid]=($kind==='parent'?$row->processlevel.' · ':'').$row->code.' — '.$row->name; }
        if ($selected !== '' && !isset($options[$selected])) {
            $p['selected']=$selected;
            $rows=$DB->get_records_sql("SELECT r.id,r.uuid,r.code,r.name FROM {{$table}} r WHERE $base AND r.uuid=:selected", $p, 0, 1);
            if ($rows) { $row=reset($rows); $options[$row->uuid]=$row->code.' — '.$row->name; }
        }
        return ['options'=>$options,'query'=>$q,'total'=>$total,'limited'=>$total>50];
    }

    /** Inline validation mirrors existing domain rules; save() repeats authoritative checks under lock. */
    public static function crud_errors(string $companyuuid, int $userid, array $v, string $uuid = ''): array {
        self::authorise($companyuuid, $userid); $errors=[];
        foreach (['name'=>255,'purpose'=>4000,'parentuuid'=>36,'ownerorgunituuid'=>36,'productuuid'=>36] as $key=>$max) {
            if (\core_text::strlen(trim((string)($v[$key] ?? ''))) > $max || ($key==='name' && trim((string)($v[$key] ?? ''))==='')) {
                $errors[$key]=get_string('pru_invalidfield','local_kbacompany');
            }
        }
        if (isset($v['code']) && (string)$v['code'] !== '') { $errors['code']=get_string('pru_codereadonly','local_kbacompany'); }
        if (!in_array($v['processtype'] ?? '', self::TYPES, true)) { $errors['processtype']=get_string('process_invalid','local_kbacompany'); }
        if (!in_array($v['processlevel'] ?? '', self::LEVELS, true)) { $errors['processlevel']=get_string('process_invalid','local_kbacompany'); }
        if (!isset($errors['processlevel']) && !isset($errors['parentuuid'])) {
            try { self::validate_parent($companyuuid,$uuid,(string)$v['processlevel'],(string)($v['parentuuid'] ?? '')); }
            catch (\moodle_exception $e) { $errors['parentuuid']=$e->getMessage(); }
        }
        foreach (['ownerorgunituuid','productuuid'] as $key) {
            try { self::validate_references($companyuuid, $key==='ownerorgunituuid'?(string)($v[$key] ?? ''):'', $key==='productuuid'?(string)($v[$key] ?? ''):''); }
            catch (\moodle_exception $e) { $errors[$key]=$e->getMessage(); }
        }
        return $errors;
    }

    /** Stable codes for this UI; existing codes and original service contract are preserved. */
    public static function crud_save(string $companyuuid, int $userid, array $values, string $uuid, int $expectedrevision): string {
        global $DB;
        self::authorise($companyuuid,$userid);
        if (self::crud_errors($companyuuid,$userid,$values,$uuid)) { self::fail('invalid'); }
        if ($uuid !== '') {
            $row=self::latest($companyuuid,$uuid);
            if ((int)$row->revision!==$expectedrevision) { self::fail('conflict'); }
            $values['code']=$row->code;
        } else {
            $minimum=0;
            $rs=$DB->get_recordset_select('local_kbacompany_process','companyuuid=:c AND '.$DB->sql_like('code',':pattern'),['c'=>$companyuuid,'pattern'=>'PRC-%']);
            foreach ($rs as $row) { if (preg_match('/^PRC-(\d+)$/D',(string)$row->code,$m)) { $minimum=max($minimum,(int)$m[1]); } }
            $rs->close();
            $values['code']=stable_code_service::next($companyuuid,'process','PRC',static function(string $code) use ($DB,$companyuuid): bool {
                return !$DB->record_exists('local_kbacompany_process',['companyuuid'=>$companyuuid,'code'=>$code]);
            },$minimum);
        }
        return self::save($companyuuid,$userid,$values,$uuid,$expectedrevision);
    }

    /** Current canonical semantic links, per process and company; capped with explicit pagination. */
    public static function crud_links(string $companyuuid, int $userid, string $uuid, int $page = 0): array {
        global $DB;
        self::crud_record($companyuuid,$userid,$uuid);
        $where="r.companyuuid=:company AND r.status='active' AND NOT EXISTS (SELECT 1 FROM {local_kbacompany_semrel} newer
            WHERE newer.companyuuid=r.companyuuid AND newer.uuid=r.uuid AND newer.revision>r.revision)
            AND ((r.totype='process' AND r.touuid=:incoming AND ((r.fromtype='orgunit' AND r.relation IN ('supplies','executes'))
                  OR (r.fromtype='metric' AND r.relation='measures')))
              OR (r.fromtype='process' AND r.fromuuid=:outgoing AND r.totype='orgunit' AND r.relation='supplies'))";
        $params=['company'=>$companyuuid,'incoming'=>$uuid,'outgoing'=>$uuid];
        $total=$DB->count_records_sql('SELECT COUNT(1) FROM {local_kbacompany_semrel} r WHERE '.$where,$params);
        $page=max(0,min($page,max(0,(int)ceil($total/25)-1)));
        $rows=$DB->get_records_sql("SELECT r.*,o.name AS incomingname,customer.name AS outgoingname,m.name AS metricname
            FROM {local_kbacompany_semrel} r
            LEFT JOIN {local_kbacompany_orgnode} o ON o.companyuuid=r.companyuuid AND o.uuid=r.fromuuid AND r.fromtype='orgunit' AND o.active=1
            LEFT JOIN {local_kbacompany_orgnode} customer ON customer.companyuuid=r.companyuuid AND customer.uuid=r.touuid AND r.totype='orgunit' AND customer.active=1
            LEFT JOIN {local_kbacompany_stat} m ON m.companyuuid=r.companyuuid AND m.uuid=r.fromuuid AND r.fromtype='metric'
            WHERE $where ORDER BY r.id",$params,$page*25,25);
        $result=[];
        foreach ($rows as $row) {
            $kind=$row->fromtype==='metric'?'metric':($row->fromtype==='process'?'customer':($row->relation==='executes'?'participant':'supplier'));
            $result[]=['relation_uuid'=>$row->uuid,'revision'=>(int)$row->revision,'kind'=>$kind,
                'target_uuid'=>$kind==='customer'?$row->touuid:$row->fromuuid,
                'target_name'=>(string)($kind==='metric'?$row->metricname:($kind==='customer'?$row->outgoingname:$row->incomingname))];
        }
        return ['rows'=>$result,'total'=>$total,'page'=>$page,'pagesize'=>25];
    }

    /** Unlink cannot be used to mutate an unrelated semantic relation by replacing its UUID. */
    public static function crud_unlink(string $companyuuid, int $userid, string $processuuid, string $relationuuid, int $revision, string $reason): void {
        global $DB;
        self::authorise($companyuuid,$userid);
        $row=$DB->get_record_select('local_kbacompany_semrel', "companyuuid=:company AND uuid=:relation AND revision=:revision
            AND status='active' AND ((fromtype='process' AND fromuuid=:source AND totype='orgunit' AND relation='supplies')
            OR (totype='process' AND touuid=:target AND ((fromtype='orgunit' AND relation IN ('supplies','executes')) OR (fromtype='metric' AND relation='measures'))))",
            ['company'=>$companyuuid,'relation'=>$relationuuid,'revision'=>$revision,'source'=>$processuuid,'target'=>$processuuid]);
        if (!$row) { self::fail('reference'); }
        self::unlink($companyuuid,$userid,$relationuuid,$revision,$reason);
    }

    /** Conservative deletion dependencies from actual schema; no payload or object rewrites. */
    public static function crud_dependencies(string $companyuuid, int $userid, string $uuid): array {
        global $DB;
        self::authorise($companyuuid,$userid); self::latest($companyuuid,$uuid);
        $defs=[
            ['children','local_kbacompany_process',"r.parentuuid=:object AND ".self::visible_sql('r',true),'processes',['parentfilter'=>$uuid]],
            ['models','local_kbacompany_procmodel','r.processuuid=:object','processes',['process'=>$uuid,'tab'=>'bpmn']],
            ['functions','local_kbacompany_function','r.sourceprocessuuid=:object','functions',[]],
            ['offerings','local_kbacompany_offering','r.coreprocessuuid=:object','market_assessment',[]],
            ['strategy','local_kbacompany_stratgoal','r.processuuid=:object','strategy',[]],
            ['documents','local_kbacompany_doc',"r.sourcekind IN ('stp','process') AND r.sourceuuid=:object",'documents',[]],
            ['policies','local_kbacompany_policy',"((r.scopetype='process' AND r.scopeuuid=:object) OR (r.ownertype='process' AND r.owneruuid=:object2))",'policies',[]],
            ['standardization','local_kbacompany_a3std',"r.targetkind='process' AND r.targetref=:object",'management_cycles',[]],
            ['tasks','local_kbacompany_task',"((r.contextkind='process' AND r.contextuuid=:object) OR (r.sourcekind='process' AND r.sourceuuid=:object2))",'tasks',[]],
            ['adminitems','local_kbacompany_adminitem',"r.sourcekind='process' AND r.sourceuuid=:object",'administrative_scale',[]],
            ['finance','local_kbacompany_fincenter',"r.targettype='process' AND r.targetuuid=:object",'financial_model',[]],
            ['resources','local_kbacompany_resgate',"r.targettype='process' AND r.targetuuid=:object",'resource_gate',[]],
            ['relations','local_kbacompany_semrel',"r.status='active' AND NOT EXISTS (SELECT 1 FROM {local_kbacompany_semrel} newer
                WHERE newer.companyuuid=r.companyuuid AND newer.uuid=r.uuid AND newer.revision>r.revision)
                AND ((r.fromtype='process' AND r.fromuuid=:object) OR (r.totype='process' AND r.touuid=:object2))",'processes',['process'=>$uuid,'view'=>'links']],
        ];
        $result=[];
        foreach ($defs as [$key,$table,$condition,$route,$params]) {
            $p=['company'=>$companyuuid,'object'=>$uuid];
            if (strpos($condition,':object2')!==false) { $p['object2']=$uuid; }
            $count=$DB->count_records_sql("SELECT COUNT(1) FROM {{$table}} r WHERE r.companyuuid=:company AND ($condition)",$p);
            if ($count) { $result[]=['key'=>$key,'count'=>$count,'route'=>$route,'params'=>$params]; }
        }
        return $result;
    }

    /** Canonical relationship mutation under the same architecture lock as process lifecycle. */
    public static function crud_relation_command(string $companyuuid, int $userid, string $uuid, int $expectedrevision, string $operation, array $data): void {
        self::authorise($companyuuid,$userid);
        $lock=\core\lock\lock_config::get_lock_factory('local_kbacompany')->get_lock('process_architecture:'.$companyuuid,10);
        if (!$lock) { self::fail('lock'); }
        try {
            self::authorise($companyuuid,$userid);
            $process=self::latest($companyuuid,$uuid);
            if ((int)$process->revision!==$expectedrevision) { self::fail('conflict'); }
            if ($process->status==='archived') { self::fail('state'); }
            $reason=trim((string)($data['reason']??''));
            if ($reason==='' || \core_text::strlen($reason)>4000) { self::fail('reason'); }
            if ($operation==='link') {
                $target=explode('|',(string)($data['target']??''),2);
                $kind=(string)($data['linktype']??'');
                if (!in_array($kind,['supplier','customer','participant','metric'],true) || count($target)!==2 ||
                        $target[0]!==($kind==='metric'?'metric':'owner') || $target[1]==='' || strlen($target[1])>36) { self::fail('reference'); }
                // Validate the actual reference under the existing permission and company boundary.
                $options=self::crud_lookup($companyuuid,$userid,$target[0],'',$target[1]);
                if (!isset($options['options'][$target[1]])) { self::fail('reference'); }
                self::link($companyuuid,$userid,$uuid,$kind,$target[1],$reason);
            } else if ($operation==='unlink') {
                self::crud_unlink($companyuuid,$userid,$uuid,(string)$data['relation'],(int)$data['relationrevision'],$reason);
            } else { self::fail('invalid'); }
        } finally { $lock->release(); }
    }

    /** Describe the exact allowed relation before confirmation; no technical target IDs in the label. */
    public static function crud_relation_label(string $companyuuid, int $userid, string $uuid, string $relation, int $revision): string {
        global $DB;
        self::authorise($companyuuid,$userid); self::crud_record($companyuuid,$userid,$uuid);
        $rows=$DB->get_records_sql("SELECT r.* FROM {local_kbacompany_semrel} r WHERE r.companyuuid=:company AND r.uuid=:relation AND r.revision=:revision
            AND r.status='active' AND NOT EXISTS (SELECT 1 FROM {local_kbacompany_semrel} newer WHERE newer.companyuuid=r.companyuuid AND newer.uuid=r.uuid AND newer.revision>r.revision)
            AND ((r.fromtype='process' AND r.fromuuid=:source AND r.totype='orgunit' AND r.relation='supplies')
            OR (r.totype='process' AND r.touuid=:target AND ((r.fromtype='orgunit' AND r.relation IN ('supplies','executes')) OR (r.fromtype='metric' AND r.relation='measures'))))",
            ['company'=>$companyuuid,'relation'=>$relation,'revision'=>$revision,'source'=>$uuid,'target'=>$uuid],0,1);
        if (!$rows) { self::fail('reference'); }
        $row=reset($rows);$kind=$row->fromtype==='metric'?'metric':($row->fromtype==='process'?'customer':($row->relation==='executes'?'participant':'supplier'));
        $target=$DB->get_record($kind==='metric'?'local_kbacompany_stat':'local_kbacompany_orgnode',['companyuuid'=>$companyuuid,'uuid'=>$kind==='customer'?$row->touuid:$row->fromuuid],'name');
        return get_string('process_link_'.$kind,'local_kbacompany').' — '.($target?(string)$target->name:get_string('pru_unavailable','local_kbacompany'));
    }
}
