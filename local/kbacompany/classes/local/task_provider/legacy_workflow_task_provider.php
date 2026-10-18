<?php
namespace local_kbacompany\local\task_provider;

defined('MOODLE_INTERNAL') || die();

use local_kbacompany\local\company_context_service;
use local_kbacompany\local\task_service;

/** Compatibility provider for the pre-Task-Center KBACorporate execution workflow. */
class legacy_workflow_task_provider implements task_provider_interface {
    public function key(): string { return 'legacy'; }
    public function label(): string { return get_string('taskprovider_legacy', 'local_kbacompany'); }
    public function available(): bool { global $DB; return $DB->get_manager()->table_exists(new \xmldb_table('local_kbacompany_wfwork')); }
    public function supports_company_sources(): bool { return false; }
    public function available_sources(string $companyuuid, int $actor): array { return []; }

    private function status(string $status): string {
        switch ($status) {
            case 'accepted':
            case 'closed':
                return 'done';
            case 'draft':
            case 'open':
                return 'open';
            case 'doing':
                return 'doing';
            case 'review':
                return 'review';
            case 'returned':
                return 'returned';
            case 'cancelled':
                return 'cancelled';
            default:
                return 'open';
        }
    }

    private function projection(string $companyuuid, \stdClass $row, array $names): array {
        $status = $this->status((string)$row->status);
        return [
            'provider' => $this->key(), 'provider_label' => $this->label(), 'external_id' => (string)$row->uuid,
            'canonical_task_uuid' => '', 'source_kind' => 'legacy', 'source_label' => get_string('taskprovider_legacy', 'local_kbacompany'),
            'stream' => 'operate', 'title' => (string)$row->name, 'description' => (string)$row->description,
            'product' => (string)$row->acceptance, 'assignee_id' => (int)$row->userid,
            'assignee_name' => $names[(int)$row->userid] ?? '', 'creator_id' => 0, 'creator_name' => '',
            'reviewer_id' => (int)$row->reviewerid, 'reviewer_name' => $names[(int)$row->reviewerid] ?? '',
            'priority' => 'normal', 'status' => $status, 'start_date' => '', 'due_date' => (string)$row->deadline,
            'plan_minutes' => 0, 'fact_minutes' => 0, 'result' => '', 'revision' => (int)$row->revision,
            'started_at' => 0, 'completed_at' => 0, 'time_created' => (int)$row->timecreated,
            'time_modified' => (int)$row->timemodified, 'editable' => false,
            'progress' => $status === 'done' ? 100 : 0,
            'source_url' => (new \moodle_url('/local/kbacompany/execution.php', [
                'uuid' => $companyuuid, 'work' => $row->uuid,
            ]))->out(false),
        ];
    }

    private function names(string $companyuuid, int $actor): array {
        $org = \local_kbacompany\local\organization_service::organization_context($companyuuid, $actor);
        $names = [];
        foreach ($org['employees'] as $employee) { $names[(int)$employee['user_id']] = (string)$employee['full_name']; }
        return $names;
    }

    private function visible(\stdClass $row, int $actor, bool $canmanage): bool {
        return $canmanage || (int)$row->userid === $actor || (int)$row->reviewerid === $actor;
    }

    public function list_tasks(string $companyuuid, int $actor, int $targetuserid, string $scope = 'my'): array {
        global $DB;
        company_context_service::context($companyuuid, $actor);
        if (!$this->available()) { return []; }
        $canmanage = task_service::can_manage($companyuuid, $actor);
        if ($scope === 'created') { return []; } // The legacy schema has no canonical creator.
        $names = $this->names($companyuuid, $actor); $result = [];
        $where = "w.companyuuid=:lc AND w.kind='task'"; $params = ['lc'=>$companyuuid];
        if (!$canmanage) { $where .= ' AND (w.userid=:la OR w.reviewerid=:lr)'; $params += ['la'=>$actor,'lr'=>$actor]; }
        if ($targetuserid > 0) { $where .= ' AND w.userid=:lt'; $params['lt']=$targetuserid; }
        if ($scope === 'review') { $where .= " AND w.reviewerid=:reviewactor AND w.status='review'"; $params['reviewactor']=$actor; }
        if ($scope === 'overdue') { $where .= " AND w.deadline<>'' AND w.deadline<:today AND w.status NOT IN ('accepted','closed','cancelled')"; $params['today']=gmdate('Y-m-d'); }
        foreach ($DB->get_records_sql("SELECT w.* FROM {local_kbacompany_wfwork} w WHERE $where ORDER BY w.deadline,w.id", $params) as $row) {
            $result[] = $this->projection($companyuuid, $row, $names);
        }
        return $result;
    }

    public function get_task(string $companyuuid, int $actor, string $externalid): ?array {
        global $DB;
        company_context_service::context($companyuuid, $actor);
        if (!$this->available()) { return null; }
        $manager = task_service::can_manage($companyuuid,$actor);
        $where = "w.companyuuid=:lc AND w.kind='task' AND w.uuid=:lu"; $params = ['lc'=>$companyuuid,'lu'=>$externalid];
        if (!$manager) { $where .= ' AND (w.userid=:la OR w.reviewerid=:lr)'; $params += ['la'=>$actor,'lr'=>$actor]; }
        $row = $DB->get_record_sql("SELECT w.* FROM {local_kbacompany_wfwork} w WHERE $where",$params);
        if (!$row) { return null; }
        return $this->projection($companyuuid, $row, $this->names($companyuuid, $actor));
    }
}
