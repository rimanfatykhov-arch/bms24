<?php
namespace local_kbacompany\local;

defined('MOODLE_INTERNAL') || die();

/** Generated STP + RACI projection from the versioned SIPOC/BPMN source model. */
class stp_service {
    public const CONTRACT_VERSION = '0.3.1';

    public static function contract(): array {
        $contract = [
            'component' => 'local_kbacompany',
            'contract' => 'bms_stp_raci',
            'version' => self::CONTRACT_VERSION,
            'source' => 'process_model_service',
            'storage_policy' => 'generated_projection_not_duplicate_document',
            'raci' => ['R', 'A', 'C', 'I'],
            'rules' => [
                'responsible_projects_from_task_executor',
                'additional_raci_is_version_scoped',
                'stp_uses_sipoc_and_bpmn_source_model',
                'metrics_project_from_process_architecture',
                'records_controls_resources_may_be_process_or_activity_scoped',
                'controlled_document_lifecycle_begins_in_0.3.3',
            ],
        ];
        $contract['schema_hash_algorithm'] = 'sha256';
        $contract['schema_hash'] = hash('sha256', json_encode($contract,
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        return $contract;
    }

    private static function index_annotations(array $items): array {
        $result = ['process' => []];
        foreach ($items as $item) {
            $key = $item['activity_uuid'] !== '' ? $item['activity_uuid'] : 'process';
            $result[$key][] = $item;
        }
        return $result;
    }

    private static function unique_names(array $items): array {
        $seen = [];
        $out = [];
        foreach ($items as $item) {
            $name = is_array($item) ? (string)($item['party_name'] ?? $item['name'] ?? '') : (string)$item;
            if ($name !== '' && !isset($seen[$name])) {
                $seen[$name] = true;
                $out[] = $name;
            }
        }
        return $out;
    }

    public static function context(string $companyuuid, int $userid, string $processuuid): array {
        $source = process_model_service::context($companyuuid, $userid, $processuuid);
        if (!$source['model']) {
            throw new \moodle_exception('stp_nomodel', 'local_kbacompany');
        }
        $raci = [];
        foreach ($source['raci'] as $assignment) {
            $raci[$assignment['activity_uuid']][$assignment['role']][] = $assignment;
        }
        $resources = self::index_annotations($source['bpmn']['resources']);
        $records = self::index_annotations($source['bpmn']['records']);
        $controls = self::index_annotations($source['bpmn']['controls']);
        $documents = self::index_annotations($source['bpmn']['documents']);
        $systems = self::index_annotations($source['bpmn']['systems']);

        $steps = [];
        $number = 0;
        foreach ($source['bpmn']['nodes'] as $node) {
            if ($node['type'] !== 'task') { continue; }
            $number++;
            $roles = ['R' => [], 'A' => [], 'C' => [], 'I' => []];
            if ($node['executor_name'] !== '') {
                $roles['R'][] = $node['executor_name'];
            }
            foreach ($roles as $role => $ignore) {
                foreach ($raci[$node['uuid']][$role] ?? [] as $assignment) {
                    $roles[$role][] = $assignment['party_name'];
                }
                $roles[$role] = self::unique_names($roles[$role]);
            }
            $product = $node['product_name'] !== '' ? $node['product_name'] : $node['output'];
            $steps[] = [
                'number' => $number,
                'activity_uuid' => $node['uuid'],
                'activity' => $node['name'],
                'action' => $node['description'],
                'product' => $product,
                'raci' => $roles,
                'resources' => $resources[$node['uuid']] ?? [],
                'records' => $records[$node['uuid']] ?? [],
                'controls' => $controls[$node['uuid']] ?? [],
                'documents' => $documents[$node['uuid']] ?? [],
                'systems' => $systems[$node['uuid']] ?? [],
            ];
        }
        return [
            'company' => $source['company'],
            'process' => $source['process'],
            'model' => $source['model'],
            'document' => [
                'code' => 'STP-' . $source['process']['code'],
                'title' => $source['process']['name'],
                'revision' => $source['model']['revision'],
                'status' => $source['model']['status'],
                'generated' => true,
                'controlled_document' => false,
            ],
            'purpose' => $source['process']['purpose'],
            'scope' => $source['model']['scope'],
            'trigger' => $source['model']['trigger'],
            'start_condition' => $source['model']['start_condition'],
            'end_condition' => $source['model']['end_condition'],
            'sipoc' => $source['sipoc'],
            'steps' => $steps,
            'metrics' => $source['process']['links']['metric'],
            'process_resources' => $resources['process'] ?? [],
            'process_records' => $records['process'] ?? [],
            'process_controls' => $controls['process'] ?? [],
            'process_documents' => $documents['process'] ?? [],
            'process_systems' => $systems['process'] ?? [],
            'explicit_raci' => $source['raci'],
            'can_manage' => $source['can_manage'],
            'source_change_version' => $source['model']['change_version'],
        ];
    }
}
