<?php
namespace local_kbacompany\local\task_provider;

defined('MOODLE_INTERNAL') || die();

use local_kbacompany\local\task_service;

/** Canonical BMS task provider. */
class bms_task_provider implements task_provider_interface {
    public function key(): string { return 'bms'; }
    public function label(): string { return get_string('taskprovider_bms', 'local_kbacompany'); }
    public function available(): bool { return true; }
    public function supports_company_sources(): bool { return false; }
    public function available_sources(string $companyuuid, int $actor): array { return []; }

    private function projection(array $task): array {
        return array_merge($task, [
            'provider' => $this->key(),
            'provider_label' => $this->label(),
            'external_id' => (string)$task['uuid'],
            'canonical_task_uuid' => (string)$task['uuid'],
            'source_url' => (new \moodle_url('/local/kbacompany/tasks.php', [
                'uuid' => $task['company_uuid'] ?? '', 'task' => $task['uuid'],
            ]))->out(false),
            'editable' => true,
            'progress' => in_array($task['status'], ['done', 'cancelled'], true) ? 100 : 0,
        ]);
    }

    public function list_tasks(string $companyuuid, int $actor, int $targetuserid, string $scope = 'my'): array {
        $result = [];
        foreach (task_service::provider_rows($companyuuid, $actor, $targetuserid, $scope) as $task) {
            $task['company_uuid'] = $companyuuid; $result[] = $this->projection($task);
        }
        return $result;
    }

    public function get_task(string $companyuuid, int $actor, string $externalid): ?array {
        $task = task_service::readable_projection($companyuuid, $actor, $externalid);
        if (!$task) { return null; }
        $task['company_uuid'] = $companyuuid;
        return $this->projection($task);
    }
}
