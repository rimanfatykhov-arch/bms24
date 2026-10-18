<?php
namespace local_kbacompany\local;

defined('MOODLE_INTERNAL') || die();

/**
 * Read-only generated Process Documentation projection.
 *
 * The document is never stored as a second process model. It is assembled from the
 * currently visible canonical Process, Process Model, IDEF0/SIPOC/BPMN and Semantic Core data.
 */
class process_document_service {
    public const CONTRACT_VERSION = '0.9.16';

    public static function contract(): array {
        $contract = [
            'component' => 'local_kbacompany',
            'contract' => 'bms_process_documentation',
            'version' => self::CONTRACT_VERSION,
            'storage_policy' => 'generated_projection_not_duplicate_document',
            'sources' => [
                'process_service', 'process_model_service', 'semantic_core',
                'idef0_decomposition', 'sipoc_projection', 'bpmn_projection',
            ],
            'exports' => ['screen', 'print_html', 'download_html'],
            'rules' => [
                'company_scope_before_fetch',
                'manager_draft_first_reader_published_only',
                'no_write_no_hidden_draft',
                'canonical_uuid_not_name_matching',
                'export_is_snapshot_not_source_of_truth',
            ],
        ];
        $contract['schema_hash_algorithm'] = 'sha256';
        $contract['schema_hash'] = hash('sha256', json_encode($contract,
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        return $contract;
    }

    /** Unique non-empty names from a collection. */
    private static function names(array $items, string $preferred = 'name'): array {
        $seen = [];
        $out = [];
        foreach ($items as $item) {
            $name = trim((string)($item[$preferred] ?? $item['target_name'] ?? $item['name'] ?? ''));
            if ($name === '' || isset($seen[$name])) { continue; }
            $seen[$name] = true;
            $out[] = $name;
        }
        return $out;
    }

    /** Task procedure with explicit RACI projected from the current visible Process Model. */
    private static function procedure(array $source): array {
        $raci = [];
        foreach ($source['raci'] as $assignment) {
            $activity = (string)$assignment['activity_uuid'];
            $role = (string)$assignment['role'];
            if (!isset($raci[$activity][$role])) { $raci[$activity][$role] = []; }
            $name = trim((string)$assignment['party_name']);
            if ($name !== '' && !in_array($name, $raci[$activity][$role], true)) {
                $raci[$activity][$role][] = $name;
            }
        }
        $steps = [];
        $number = 0;
        foreach ($source['bpmn']['nodes'] as $node) {
            if (($node['type'] ?? '') !== 'task') { continue; }
            $number++;
            $roles = ['R'=>[], 'A'=>[], 'C'=>[], 'I'=>[]];
            $executor = trim((string)($node['executor_name'] ?? ''));
            if ($executor !== '') { $roles['R'][] = $executor; }
            foreach ($roles as $role => $ignore) {
                foreach ($raci[(string)$node['uuid']][$role] ?? [] as $name) {
                    if (!in_array($name, $roles[$role], true)) { $roles[$role][] = $name; }
                }
            }
            $product = trim((string)($node['product_name'] ?? ''));
            if ($product === '') { $product = trim((string)($node['output'] ?? '')); }
            $steps[] = [
                'number'=>$number,
                'uuid'=>(string)$node['uuid'],
                'name'=>(string)$node['name'],
                'description'=>(string)$node['description'],
                'product'=>$product,
                'raci'=>$roles,
            ];
        }
        return $steps;
    }

    /** Process-model annotations grouped for the human-readable passport. */
    private static function annotations(array $source, array $decomposition): array {
        $cm = $decomposition['controls_mechanisms'] ?? ['controls'=>[], 'mechanisms'=>[]];
        $canonicalcontrols = [];
        $canonicalmechanisms = [];
        foreach ($cm['controls'] ?? [] as $item) {
            $canonicalcontrols[] = [
                'type'=>(string)($item['type'] ?? ''),
                'code'=>(string)($item['code'] ?? ''),
                'name'=>(string)($item['name'] ?? ''),
                'status'=>(string)($item['status'] ?? ''),
            ];
        }
        foreach ($cm['mechanisms'] ?? [] as $item) {
            $canonicalmechanisms[] = [
                'type'=>(string)($item['type'] ?? ''),
                'code'=>(string)($item['code'] ?? ''),
                'name'=>(string)($item['name'] ?? ''),
                'status'=>(string)($item['status'] ?? ''),
            ];
        }
        return [
            'controls'=>self::names($source['bpmn']['controls']),
            'resources'=>self::names($source['bpmn']['resources']),
            'documents'=>self::names($source['bpmn']['documents']),
            'systems'=>self::names($source['bpmn']['systems']),
            'records'=>self::names($source['bpmn']['records']),
            'canonical_controls'=>$canonicalcontrols,
            'canonical_mechanisms'=>$canonicalmechanisms,
            'metrics'=>self::names($source['process']['links']['metric'], 'target_name'),
        ];
    }

    /**
     * Build one generated process passport from the caller-visible canonical revision set.
     */
    public static function context(string $companyuuid, int $userid, string $processuuid): array {
        $source = process_model_service::context($companyuuid, $userid, $processuuid);
        $decomposition = process_service::idef0_decomposition($companyuuid, $userid, $processuuid);
        $process = $source['process'];
        $model = $source['model'];
        $consistency = $source['idef0_sipoc_consistency'] ?? ['applicable'=>false];
        $boundary = $decomposition['boundary_balance'] ?? ['applicable'=>false];
        $balance = $decomposition['balance'] ?? ['total'=>0, 'balanced'=>0, 'issues'=>0];

        $inputs = [];
        foreach ($source['sipoc']['inputs'] as $item) {
            $inputs[] = [
                'uuid'=>(string)($item['uuid'] ?? ''),
                'name'=>(string)($item['product_name'] !== '' ? $item['product_name'] : ($item['name'] ?? '')),
                'description'=>(string)($item['description'] ?? ''),
                'product_uuid'=>(string)($item['product_uuid'] ?? ''),
                'product_name'=>(string)($item['product_name'] ?? ''),
            ];
        }
        $outputs = [];
        foreach ($source['sipoc']['outputs'] as $item) {
            $outputs[] = [
                'uuid'=>(string)($item['uuid'] ?? ''),
                'name'=>(string)($item['name'] ?? ''),
                'description'=>(string)($item['description'] ?? ''),
                'product_uuid'=>(string)($item['product_uuid'] ?? ''),
                'product_name'=>(string)($item['product_name'] ?? ''),
                'primary'=>!empty($item['primary']),
            ];
        }
        $children = [];
        foreach ($decomposition['children'] ?? [] as $child) {
            $children[] = [
                'uuid'=>(string)$child['uuid'], 'code'=>(string)$child['code'], 'name'=>(string)$child['name'],
                'level'=>(string)$child['level'], 'status'=>(string)$child['status'],
                'notation'=>(string)($child['idef0_notation'] ?? 'A?'),
                'owner'=>(string)($child['owner_name'] ?? ''), 'product'=>(string)($child['product_name'] ?? ''),
            ];
        }
        $interconnections = [];
        foreach ($decomposition['interconnections'] ?? [] as $edge) {
            $interconnections[] = [
                'from'=>(string)($edge['from_name'] ?? ''), 'from_code'=>(string)($edge['from_code'] ?? ''),
                'from_notation'=>(string)($edge['from_notation'] ?? ''),
                'to'=>(string)($edge['to_name'] ?? ''), 'to_code'=>(string)($edge['to_code'] ?? ''),
                'to_notation'=>(string)($edge['to_notation'] ?? ''),
                'product'=>(string)($edge['product_name'] ?? ''),
                'balance_status'=>(string)($edge['balance_status'] ?? ''),
            ];
        }
        $fingerprint = hash('sha256', implode('|', [
            $companyuuid, $processuuid, (string)$process['revision'],
            $model ? (string)$model['id'] : 'none', $model ? (string)$model['revision'] : '0',
            $model ? (string)$model['change_version'] : '0',
        ]));

        return [
            'company'=>$source['company'],
            'process'=>$process,
            'model'=>$model,
            'document'=>[
                'code'=>'PROC-' . $process['code'],
                'title'=>$process['name'],
                'process_revision'=>(int)$process['revision'],
                'model_revision'=>$model ? (int)$model['revision'] : 0,
                'model_status'=>$model ? (string)$model['status'] : 'none',
                'generated_at'=>time(),
                'generated'=>true,
                'controlled_document'=>false,
                'source_fingerprint'=>$fingerprint,
            ],
            'idef0'=>[
                'notation'=>(string)($decomposition['notation'] ?? 'A?'),
                'inputs'=>$inputs,
                'controls'=>$source['bpmn']['controls'],
                'outputs'=>$outputs,
                'mechanisms'=>$source['bpmn']['resources'],
                'canonical_controls'=>$decomposition['controls_mechanisms']['controls'] ?? [],
                'canonical_mechanisms'=>$decomposition['controls_mechanisms']['mechanisms'] ?? [],
                'children'=>$children,
                'interconnections'=>$interconnections,
                'balance'=>$balance,
                'boundary'=>$boundary,
                'consistency'=>$consistency,
            ],
            'sipoc'=>[
                'suppliers'=>self::names($source['sipoc']['suppliers'], 'target_name'),
                'inputs'=>self::names($inputs),
                'outputs'=>self::names($outputs),
                'customers'=>self::names($source['sipoc']['customers'], 'target_name'),
            ],
            'procedure'=>self::procedure($source),
            'annotations'=>self::annotations($source, $decomposition),
            'model_context'=>[
                'scope'=>$model ? (string)$model['scope'] : '',
                'trigger'=>$model ? (string)$model['trigger'] : '',
                'start_condition'=>$model ? (string)$model['start_condition'] : '',
                'end_condition'=>$model ? (string)$model['end_condition'] : '',
            ],
            'can_manage'=>$source['can_manage'],
        ];
    }
}
