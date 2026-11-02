<?php
namespace local_kbacompany\local\task_provider;

defined('MOODLE_INTERNAL') || die();

use local_kbacompany\local\company_context_service;
use local_kbacompany\local\task_service;

/**
 * Read-only implementation tasks owned by Corporate 0.40.3 (bridge API 1.22).
 *
 * The immutable identity chain is BMS UUID -> legacy Corporate ID and/or
 * BMS Bitrix24 company mapping -> Corporate public bridge company ID.
 * Never resolve a Corporate company from a name or the current UI selection.
 */
final class kbacorporate_impl_task_provider implements task_provider_interface {
    private const BRIDGE_CLASS = '\\local_kbacorporate\local\bridge';
    private const PAGE_SIZE = 100;
    private const MAX_ITEMS = 500;

    public function key(): string { return 'kbacorporate_impl'; }
    public function label(): string { return get_string('taskprovider_kbacorporate_impl', 'local_kbacompany'); }
    public function supports_company_sources(): bool { return true; }

    public function available(): bool {
        $bridge = self::BRIDGE_CLASS;
        if (!class_exists($bridge) || !method_exists($bridge, 'list_implementation_task_projections') ||
                !method_exists($bridge, 'get_implementation_task_projection') ||
                !method_exists($bridge, 'get_company_by_bitrix_id')) {
            return false;
        }
        return defined($bridge . '::API_RELEASE') && version_compare((string)$bridge::API_RELEASE, '1.22', '>=');
    }

    /**
     * Verify a company via Corporate's public identity API. An independently
     * mapped Bitrix company must agree with the preserved legacy Corporate ID.
     * Fail closed if mappings disagree or neither stable identity exists.
     */
    private function company_id(string $uuid, int $actor): int {
        $company = company_context_service::context($uuid, $actor);
        if (empty($company['active']) || !$this->available()) { return 0; }
        $legacyid = (int)($company['compatibility']['source_id'] ?? 0);
        if (($company['compatibility']['source_component'] ?? '') !== 'local_kbacorporate') {
            $legacyid = 0;
        }
        $bitrixid = (int)($company['external_company_id'] ?? 0);
        // A legacy Corporate company may already know its Bitrix identity.
        if ($legacyid > 0 && $bitrixid <= 0) { return $legacyid; }
        if ($bitrixid <= 0) { return 0; }
        $bridge = self::BRIDGE_CLASS;
        $mapped = $bridge::get_company_by_bitrix_id($bitrixid);
        if (empty($mapped['found']) || empty($mapped['active']) || (int)($mapped['bitrixcompanyid'] ?? 0) !== $bitrixid) {
            return 0;
        }
        $id = (int)($mapped['companyid'] ?? 0);
        if ($legacyid > 0 && $id !== $legacyid) { return 0; }
        return $id;
    }

    /** @return int Positive Corporate company ID only when the source is bound in this BMS company. */
    private function bound_company_id(string $uuid, int $actor): int {
        global $DB;
        $id = $this->company_id($uuid, $actor);
        if ($id <= 0) { return 0; }
        return $DB->record_exists('local_kbacompany_tasksrc', [
            'companyuuid' => $uuid, 'provider' => $this->key(),
            'sourcekey' => 'company:' . $id, 'active' => 1,
        ]) ? $id : 0;
    }

    /** Public narrow identity boundary: resolves only an actively bound, verified source. */
    public function linked_company_id(string $companyuuid, int $actor): int {
        return $this->bound_company_id($companyuuid, $actor);
    }

    public function available_sources(string $companyuuid, int $actor): array {
        if (!$this->available() || !task_service::can_manage($companyuuid, $actor)) { return []; }
        $companyid = $this->company_id($companyuuid, $actor);
        if ($companyid <= 0) { return []; }
        // Corporate must independently authorise the manager; empty task sets are fine.
        $bridge = self::BRIDGE_CLASS;
        $bridge::list_implementation_task_projections($companyid, $actor, 0, 'company', 1, 0);
        return [[
            'source_key' => 'company:' . $companyid,
            'label' => get_string('taskprovider_kbacorporate_impl', 'local_kbacompany'),
            'company_id' => $companyid,
        ]];
    }

    private static function date_value(int $timestamp): string {
        return $timestamp > 0 ? gmdate('Y-m-d', $timestamp) : '';
    }

    /** Translate Corporate's business statuses without changing source state. */
    public static function status_value(string $status): string {
        switch ($status) {
            case 'planned': return 'open';
            case 'inprogress': return 'doing';
            case 'review': return 'review';
            case 'completed': return 'done';
            case 'cancelled': return 'cancelled';
            default: return 'unknown';
        }
    }

    /** Public pure normalisation for deterministic contract tests. */
    public static function project(array $row, int $corporatecompanyid): ?array {
        $companyid = (int)($row['company_id'] ?? 0);
        $externalid = (string)($row['external_id'] ?? '');
        if ($corporatecompanyid <= 0 || $companyid !== $corporatecompanyid ||
                !preg_match('/^impltask:([1-9][0-9]*)$/D', $externalid, $matches)) {
            return null;
        }
        $taskid = (int)$matches[1];
        if ($taskid <= 0 || (string)$taskid !== $matches[1]) { return null; }
        $status = self::status_value((string)($row['status'] ?? ''));
        $assigneeid = (int)($row['assignee_id'] ?? 0);
        $due = self::date_value((int)($row['due_at'] ?? 0));
        $priority = (string)($row['priority'] ?? 'normal');
        if (!in_array($priority, ['low', 'normal', 'high', 'critical'], true)) { $priority = 'normal'; }
        return [
            'provider' => 'kbacorporate_impl',
            'provider_label' => get_string('taskprovider_kbacorporate_impl', 'local_kbacompany'),
            'external_id' => $externalid, 'corporate_company_id' => $corporatecompanyid, 'canonical_task_uuid' => '',
            'source_kind' => 'implementation',
            'source_label' => (string)($row['program_title'] ?? ''),
            'stream' => 'develop', 'title' => (string)($row['title'] ?? ''),
            'description' => '', 'product' => (string)($row['expected_result'] ?? ''),
            'assignee_id' => $assigneeid, 'assignee_name' => '',
            'creator_id' => (int)($row['creator_id'] ?? 0), 'creator_name' => '',
            'reviewer_id' => (int)($row['reviewer_id'] ?? 0), 'reviewer_name' => '',
            'priority' => $priority, 'status' => $status,
            'start_date' => self::date_value((int)($row['start_at'] ?? 0)),
            'due_date' => $due, 'plan_minutes' => 0, 'fact_minutes' => 0,
            'result' => '', 'revision' => 0, 'started_at' => 0,
            'completed_at' => (int)($row['completed_at'] ?? 0),
            'time_created' => 0, 'time_modified' => (int)($row['time_modified'] ?? 0),
            'editable' => false, 'progress' => $status === 'done' ? 100 : 0,
            'attachable' => !empty($row['attachable']) && in_array($status, ['open', 'doing'], true) && $assigneeid > 0,
            'source_url' => (new \moodle_url('/local/kbacorporate/implementation_task.php', ['id'=>$taskid]))->out(false),
        ];
    }

    /** Read a bounded set through Corporate only; never read its tables. */
    public function list_tasks(string $companyuuid, int $actor, int $targetuserid, string $scope = 'my'): array {
        if (!$this->available()) { return []; }
        $companyid = $this->bound_company_id($companyuuid, $actor);
        if ($companyid <= 0) { return []; }
        $canmanage = task_service::can_manage($companyuuid, $actor);
        $targetuserid = max(0, $targetuserid);
        $scopekey = 'mine';
        $corporatetarget = $actor;
        if (in_array($scope, ['created', 'review'], true) || ($scope === 'all' && $targetuserid === 0)) {
            if (!$canmanage) { return []; }
            $scopekey = 'company'; $corporatetarget = 0;
        } else if ($targetuserid > 0 && $targetuserid !== $actor) {
            if (!$canmanage) { return []; }
            $scopekey = 'employee'; $corporatetarget = $targetuserid;
        }
        $bridge = self::BRIDGE_CLASS;
        $tasks = [];
        for ($offset = 0; $offset < self::MAX_ITEMS; $offset += self::PAGE_SIZE) {
            $page = $bridge::list_implementation_task_projections($companyid, $actor,
                $corporatetarget, $scopekey, self::PAGE_SIZE, $offset);
            foreach ($page as $source) {
                $row = self::project($source, $companyid);
                if (!$row) { continue; }
                if ($scope === 'review' && ($row['reviewer_id'] !== $actor || $row['status'] !== 'review')) { continue; }
                if ($scope === 'created' && $row['creator_id'] !== $actor) { continue; }
                if ($scope === 'overdue' && ($row['due_date'] === '' || $row['due_date'] >= gmdate('Y-m-d') ||
                        in_array($row['status'], ['done','cancelled'], true))) { continue; }
                $tasks[] = $row;
            }
            if (count($page) < self::PAGE_SIZE) { break; }
        }
        return $tasks;
    }

    public function get_task(string $companyuuid, int $actor, string $externalid): ?array {
        if (!$this->available() || !preg_match('/^impltask:([1-9][0-9]*)$/D', $externalid, $m)) { return null; }
        $companyid = $this->bound_company_id($companyuuid, $actor);
        if ($companyid <= 0 || (int)$m[1] <= 0) { return null; }
        $bridge = self::BRIDGE_CLASS;
        $row = $bridge::get_implementation_task_projection($companyid, $actor, (int)$m[1]);
        return $row ? self::project($row, $companyid) : null;
    }
}
