<?php
namespace local_kbacompany\local;

defined('MOODLE_INTERNAL') || die();

use local_kbacompany\local\task_provider\task_provider_interface;
use local_kbacompany\local\task_provider\bms_task_provider;
use local_kbacompany\local\task_provider\legacy_workflow_task_provider;
use local_kbacompany\local\task_provider\kbaelement_task_provider;
use local_kbacompany\local\task_provider\kbacorporate_impl_task_provider;

/** Aggregates task-like work without transferring ownership from source subsystems. */
class task_provider_service {
    public const CONTRACT_VERSION = '0.4.7';
    public const PROVIDERS = ['bms', 'legacy', 'kbaelement', 'kbacorporate_impl'];
    public const SCOPES = ['my', 'created', 'review', 'overdue', 'all'];

    public static function contract(): array {
        $contract = [
            'component' => 'local_kbacompany',
            'contract' => 'bms_unified_task_sources',
            'version' => self::CONTRACT_VERSION,
            'providers' => self::PROVIDERS,
            'scopes' => self::SCOPES,
            'objects' => ['TaskProjection', 'TaskSourceBinding'],
            'rules' => [
                'task_center_aggregates_without_stealing_source_ownership',
                'canonical_bms_task_is_editable_only_in_bms',
                'legacy_workflow_is_read_only_projection',
                'kbaelement_is_learning_source_of_truth',
                'kbaelement_uses_explicit_company_course_bindings',
                'kbaelement_completion_is_projected_from_moodle_completion',
                'corporate_implementation_tasks_are_read_only_via_bridge_1_22',
                'corporate_source_binding_requires_verified_company_identity',
                'external_tasks_may_be_referenced_by_battle_plan_without_copying',
                'provider_failure_must_not_break_other_providers',
                'provider_projection_is_company_scoped',
            ],
        ];
        $contract['schema_hash_algorithm'] = 'sha256';
        $contract['schema_hash'] = hash('sha256', json_encode($contract,
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        return $contract;
    }

    private static function fail(string $key): void {
        throw new \moodle_exception('taskprovider_' . $key, 'local_kbacompany');
    }

    /** @return array<string,task_provider_interface> */
    public static function providers(): array {
        return [
            'bms' => new bms_task_provider(),
            'legacy' => new legacy_workflow_task_provider(),
            'kbaelement' => new kbaelement_task_provider(),
            'kbacorporate_impl' => new kbacorporate_impl_task_provider(),
        ];
    }

    public static function provider(string $key): task_provider_interface {
        $providers = self::providers();
        if (!isset($providers[$key])) { self::fail('providererror'); }
        return $providers[$key];
    }

    private static function employee_ids(string $companyuuid, int $actor): array {
        $org = organization_service::organization_context($companyuuid, $actor);
        $ids = [];
        foreach ($org['employees'] as $employee) { $ids[(int)$employee['user_id']] = true; }
        $ids[$actor] = true;
        return $ids;
    }

    private static function require_target(string $companyuuid, int $actor, int $targetuserid): void {
        if ($targetuserid === $actor) { return; }
        if (!task_service::can_manage($companyuuid, $actor)) { self::fail('permission'); }
        if (!isset(self::employee_ids($companyuuid, $actor)[$targetuserid])) { self::fail('employee'); }
    }

    public static function bindings(string $companyuuid, int $actor, string $provider = ''): array {
        global $DB;
        company_context_service::context($companyuuid, $actor);
        $conditions = ['companyuuid' => $companyuuid];
        if ($provider !== '') { $conditions['provider'] = $provider; }
        $result = [];
        foreach ($DB->get_records('local_kbacompany_tasksrc', $conditions, 'provider ASC,label ASC,id ASC') as $row) {
            $result[] = ['provider' => (string)$row->provider, 'source_key' => (string)$row->sourcekey,
                'label' => (string)$row->label, 'active' => !empty($row->active),
                'config' => json_decode((string)$row->config, true) ?: [], 'created_by' => (int)$row->createdby,
                'time_created' => (int)$row->timecreated, 'time_modified' => (int)$row->timemodified];
        }
        return $result;
    }

    public static function bind_source(string $companyuuid, int $actor, string $providerkey, string $sourcekey,
            string $label = '', array $config = []): void {
        global $DB;
        company_context_service::context($companyuuid, $actor);
        if (!task_service::can_manage($companyuuid, $actor)) { self::fail('permission'); }
        $provider = self::provider($providerkey);
        if (!$provider->supports_company_sources() || !$provider->available()) { self::fail('providererror'); }
        $sourcekey = trim($sourcekey);
        if ($sourcekey === '' || \core_text::strlen($sourcekey) > 100) { self::fail('sourceerror'); }
        $available = [];
        foreach ($provider->available_sources($companyuuid, $actor) as $source) {
            $available[(string)$source['source_key']] = $source;
        }
        if (!isset($available[$sourcekey])) { self::fail('sourceerror'); }
        if ($label === '') { $label = (string)$available[$sourcekey]['label']; }
        $label = \core_text::substr(trim($label), 0, 255);
        $now = time();
        $existing = $DB->get_record('local_kbacompany_tasksrc', [
            'companyuuid' => $companyuuid, 'provider' => $providerkey, 'sourcekey' => $sourcekey,
        ]);
        if ($existing) {
            $existing->label = $label; $existing->config = json_encode($config, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            $existing->active = 1; $existing->timemodified = $now; $DB->update_record('local_kbacompany_tasksrc', $existing);
            return;
        }
        $DB->insert_record('local_kbacompany_tasksrc', (object)[
            'companyuuid' => $companyuuid, 'provider' => $providerkey, 'sourcekey' => $sourcekey,
            'label' => $label, 'config' => json_encode($config, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'active' => 1, 'createdby' => $actor, 'timecreated' => $now, 'timemodified' => $now,
        ]);
    }

    public static function unbind_source(string $companyuuid, int $actor, string $providerkey, string $sourcekey): void {
        global $DB;
        company_context_service::context($companyuuid, $actor);
        if (!task_service::can_manage($companyuuid, $actor)) { self::fail('permission'); }
        $row = $DB->get_record('local_kbacompany_tasksrc', [
            'companyuuid' => $companyuuid, 'provider' => $providerkey, 'sourcekey' => $sourcekey,
        ]);
        if (!$row) { self::fail('sourceerror'); }
        $row->active = 0; $row->timemodified = time(); $DB->update_record('local_kbacompany_tasksrc', $row);
    }

    public static function get_task(string $companyuuid, int $actor, string $providerkey, string $externalid): ?array {
        company_context_service::context($companyuuid, $actor);
        try { return self::provider($providerkey)->get_task($companyuuid, $actor, $externalid); }
        catch (\Throwable $e) { return null; }
    }

    public static function context(string $companyuuid, int $actor, string $scope = 'my', string $providerfilter = 'all',
            int $targetuserid = 0): array {
        company_context_service::context($companyuuid, $actor);
        if (!in_array($scope, self::SCOPES, true)) { $scope = 'my'; }
        $canmanage = task_service::can_manage($companyuuid, $actor);
        // target_user = 0 has a deliberate meaning only for a manager's company-wide 'all' projection.
        if ($targetuserid <= 0 && !($scope === 'all' && $canmanage)) { $targetuserid = $actor; }
        if ($targetuserid > 0) { self::require_target($companyuuid, $actor, $targetuserid); }
        $providers = self::providers();
        if (!in_array($providerfilter, ['all','external'], true) && !isset($providers[$providerfilter])) { $providerfilter = 'all'; }
        $tasks = []; $diagnostics = []; $counts = ['total' => 0, 'overdue' => 0, 'review' => 0, 'develop' => 0];
        foreach ($providers as $key => $provider) {
            $enabled = $provider->available();
            $diagnostic = ['key' => $key, 'label' => $provider->label(), 'available' => $enabled,
                'supports_sources' => $provider->supports_company_sources(), 'count' => 0, 'error' => ''];
            if (!$enabled || ($providerfilter === 'external' && $key === 'bms') || (!in_array($providerfilter, ['all','external'], true) && $providerfilter !== $key)) { $diagnostics[$key] = $diagnostic; continue; }
            try {
                $items = $provider->list_tasks($companyuuid, $actor, in_array($scope, ['created','review'], true) ? 0 : $targetuserid, $scope);
                foreach ($items as $item) {
                    $item['is_overdue'] = $item['due_date'] !== '' && $item['due_date'] < gmdate('Y-m-d') &&
                        !in_array($item['status'], ['done', 'cancelled'], true);
                    $tasks[] = $item; $diagnostic['count']++; $counts['total']++;
                    if ($item['is_overdue']) { $counts['overdue']++; }
                    if ($item['status'] === 'review') { $counts['review']++; }
                    if ($item['stream'] === 'develop') { $counts['develop']++; }
                }
            } catch (\Throwable $e) {
                $diagnostic['error'] = get_string('taskprovider_error', 'local_kbacompany');
            }
            $diagnostics[$key] = $diagnostic;
        }
        usort($tasks, static function(array $a, array $b): int {
            $adue = $a['due_date'] === '' ? '9999-12-31' : $a['due_date'];
            $bdue = $b['due_date'] === '' ? '9999-12-31' : $b['due_date'];
            return [$adue, $a['title'], $a['provider']] <=> [$bdue, $b['title'], $b['provider']];
        });
        $available = [];
        foreach ($providers as $key => $provider) {
            if ($canmanage && $provider->supports_company_sources() && $provider->available()) {
                try { $available[$key] = $provider->available_sources($companyuuid, $actor); }
                catch (\Throwable $e) { $available[$key] = []; }
            }
        }
        return ['company_uuid' => $companyuuid, 'scope' => $scope, 'provider_filter' => $providerfilter,
            'target_user' => $targetuserid, 'tasks' => $tasks, 'counts' => $counts,
            'providers' => array_values($diagnostics), 'bindings' => self::bindings($companyuuid, $actor),
            'available_sources' => $available, 'can_manage' => $canmanage];
    }
}
